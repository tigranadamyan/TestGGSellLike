<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { dashboard, login, register } from '@/routes';
import Button from '@/components/ui/button/Button.vue';
import { connectionState, subscribeToCatalog } from '@/composables/useBroadcast';

interface CatalogItem {
    sku: string;
    name: string;
    type: string;
    price: string;
    currency: string;
    in_stock: boolean;
    available: number;
}

const props = defineProps<{
    catalog: CatalogItem[];
    stats: { products: number; available_keys: number; delivered: number };
}>();

// Local copy of catalog for real-time updates
const localCatalog = ref<CatalogItem[]>([...props.catalog]);

// Bound straight to the socket, so the badge cannot claim a connection we
// do not have.
const connectionStatus = connectionState;

/** Prices that changed under the viewer's feet, keyed by SKU: old -> new. */
const priceChanges = ref<Record<string, string>>({});

// Purchase resilience state
const purchasingSku = ref<string | null>(null);
const purchaseError = ref<string | null>(null);
const purchaseSuccess = ref<string | null>(null);
const lastOrderId = ref<number | null>(null);

// Search state. Seeded from the address bar so a shared link opens the same view.
const initialParams = new URLSearchParams(window.location.search);
const searchQuery = ref(initialParams.get('q') ?? '');
const typeFilter = ref(initialParams.get('type') ?? '');
const searchResults = ref<CatalogItem[]>([]);
const isSearching = ref(false);
const searchTotal = ref(0);
let searchAbortController: AbortController | null = null;

/** Mirror the current query into the URL without adding history entries. */
function syncUrl(): void {
    const params = new URLSearchParams();

    if (searchQuery.value) {
        params.set('q', searchQuery.value);
    }
    if (typeFilter.value) {
        params.set('type', typeFilter.value);
    }

    const query = params.toString();
    window.history.replaceState(null, '', query ? `?${query}` : window.location.pathname);
}

/** `price` arrives as a decimal string — parse only for display, never for maths. */
const formatPrice = (price: string, currency: string) =>
    new Intl.NumberFormat('ru-RU', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format(Number(price));

const formatCount = (value: number) => new Intl.NumberFormat('ru-RU').format(value);

const typeLabels: Record<string, string> = {
    key: 'Ключ',
    giftcard: 'Гифт-карта',
    topup: 'Пополнение',
    subscription: 'Подписка',
};

const typeLabel = (type: string) => typeLabels[type] ?? type;

const hasCatalog = computed(() => localCatalog.value.length > 0);

// Generate idempotency key for purchase
function generateIdempotencyKey(): string {
    return `order_${Date.now()}_${Math.random().toString(36).substring(2, 9)}`;
}

// Debounce function to prevent double-clicks
function debounce<T extends (...args: any[]) => any>(fn: T, delay: number): T {
    let timeoutId: ReturnType<typeof setTimeout>;
    return ((...args: any[]) => {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => fn(...args), delay);
    }) as T;
}

// Purchase handler. Guards against double submits on the client; the server
// enforces the same thing with an idempotency key, so a lost response or a
// reload can never buy twice.
const handlePurchase = debounce(async (sku: string) => {
    if (purchasingSku.value) {
        return;
    }

    purchasingSku.value = sku;
    purchaseError.value = null;
    purchaseSuccess.value = null;

    // One key per attempt, remembered until the server answers. If the reply is
    // lost and the shopper retries, the same key reaches the server and returns
    // the order that already exists rather than creating a second one.
    const pendingKeyName = `pending_key_${sku}`;
    let idempotencyKey = localStorage.getItem(pendingKeyName);

    if (!idempotencyKey) {
        idempotencyKey = generateIdempotencyKey();
        localStorage.setItem(pendingKeyName, idempotencyKey);
    }

    try {
        const response = await fetch('/api/orders', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Idempotency-Key': idempotencyKey,
            },
            body: JSON.stringify({ sku }),
        });

        const data = await response.json();

        if (response.status === 409) {
            // Lost the race for the last key — a normal outcome, not an error.
            purchaseError.value = data.message ?? 'Этот товар только что раскупили.';
            localStorage.removeItem(pendingKeyName);

            const index = localCatalog.value.findIndex((item) => item.sku === sku);
            if (index !== -1) {
                localCatalog.value[index] = { ...localCatalog.value[index], in_stock: false, available: 0 };
            }

            return;
        }

        if (!response.ok) {
            throw new Error(data.message || 'Не удалось оформить заказ');
        }

        // The attempt is over: the key must not survive into the next purchase
        // of the same item, or the shopper could never buy it twice.
        localStorage.removeItem(pendingKeyName);
        lastOrderId.value = data.data.id;

        window.location.href = `/orders/${data.data.id}`;
    } catch (error: any) {
        purchaseError.value = error.message || 'Ошибка при создании заказа';
        localStorage.removeItem(pendingKeyName);
    } finally {
        purchasingSku.value = null;
    }
}, 400);

// Search handler with debounce
const handleSearch = debounce(async (query: string) => {
    if (!query || query.length < 2) {
        searchResults.value = [];
        searchTotal.value = 0;
        return;
    }

    // Cancel previous search if still running
    if (searchAbortController) {
        searchAbortController.abort();
    }

    searchAbortController = new AbortController();
    isSearching.value = true;

    try {
        const params = new URLSearchParams({
            q: query,
            per_page: '24',
        });

        if (typeFilter.value) {
            params.set('type', typeFilter.value);
        }

        const response = await fetch(`/api/search?${params}`, {
            signal: searchAbortController.signal,
        });

        if (!response.ok) {
            throw new Error('Search failed');
        }

        const data = await response.json();
        searchResults.value = data.data;
        searchTotal.value = data.meta.total;
    } catch (error: any) {
        if (error.name !== 'AbortError') {
            console.error('Search error:', error);
        }
    } finally {
        isSearching.value = false;
    }
}, 300);

// Watch search query and trigger search
const onSearchInput = (event: Event) => {
    const target = event.target as HTMLInputElement;
    searchQuery.value = target.value;
    syncUrl();
    handleSearch(target.value);
};

const onTypeChange = (event: Event) => {
    typeFilter.value = (event.target as HTMLSelectElement).value;
    syncUrl();

    if (searchQuery.value) {
        handleSearch(searchQuery.value);
    }
};

/** While a query is active the full catalogue steps aside, so nothing shows twice. */
const isSearchMode = computed(() => searchQuery.value.trim().length > 0);

// Real-time update handler
let unsubscribe: (() => void) | null = null;

onMounted(() => {
    // Connect to WebSocket and subscribe to catalog updates
    unsubscribe = subscribeToCatalog((data) => {
        const { product } = data;

        // Find and update the product in local catalog
        const index = localCatalog.value.findIndex((item) => item.sku === product.sku);

        if (index !== -1) {
            const previous = localCatalog.value[index];

            // Requirement: a price that moved while the item sat on screen must be
            // visible before payment, not after.
            if (previous.price !== product.price) {
                priceChanges.value[product.sku] = previous.price;
            }

            localCatalog.value[index] = {
                ...previous,
                price: product.price,
                in_stock: product.in_stock,
                available: product.available,
            };

            // Keep search results honest too.
            const searchIndex = searchResults.value.findIndex((i) => i.sku === product.sku);
            if (searchIndex !== -1) {
                searchResults.value[searchIndex] = {
                    ...searchResults.value[searchIndex],
                    price: product.price,
                    in_stock: product.in_stock,
                    available: product.available,
                };
            }
        } else if (product.in_stock) {
            // Add new product if it's in stock and not already in catalog
            localCatalog.value.push(product);
        }
    });

    // A query carried in the address bar runs immediately, so a shared link
    // lands on the same results.
    if (searchQuery.value) {
        handleSearch(searchQuery.value);
    }
});

onUnmounted(() => {
    unsubscribe?.();
});

/** Mirrors App\Enums\OrderStatus — the happy path only. */
const lifecycle = [
    { status: 'created', title: 'Заказ создан', body: 'POST /api/orders. Ключ ещё не резервируется.' },
    { status: 'paid', title: 'Оплата принята', body: 'Вебхук с тем же event_id повторно ничего не меняет.' },
    { status: 'delivering', title: 'Выдача', body: 'Фоновый воркер берёт ключ под блокировкой строки.' },
    { status: 'delivered', title: 'Ключ выдан', body: 'Код доступен в GET /api/orders/{id}.' },
];

/** Версии сверены с composer.json, package.json и образами в docker-compose.yml. */
const stack = [
    {
        group: 'Бэкенд',
        items: [
            'PHP 8.4',
            'Laravel 13',
            'Laravel Horizon',
            'Laravel Fortify',
            'Inertia 3',
            'L5-Swagger / OpenAPI 3.0',
        ],
    },
    {
        group: 'Данные и очереди',
        items: [
            'PostgreSQL 16',
            'Redis 7',
            'Очереди на Redis',
            'Кэш и сессии на Redis',
        ],
    },
    {
        group: 'Фронтенд',
        items: [
            'Vue 3.5',
            'TypeScript 5',
            'Tailwind CSS 4',
            'Reka UI',
            'Vite 8',
            'Laravel Wayfinder',
        ],
    },
    {
        group: 'Инфраструктура и качество',
        items: [
            'Docker Compose',
            'Cloudflare Tunnel',
            'PHPUnit 12',
            'Larastan',
            'Laravel Pint',
        ],
    },
];

const endpoints = [
    { method: 'GET', path: '/api/catalog', note: 'Витрина, keyset-пагинация' },
    { method: 'POST', path: '/api/orders', note: 'Создать заказ' },
    { method: 'GET', path: '/api/orders/{id}', note: 'Статус заказа и ключ' },
    { method: 'POST', path: '/api/webhooks/payment', note: 'Вебхук оплаты, идемпотентный' },
    { method: 'POST', path: '/api/internal/reconciliation', note: 'Сверка и авто-починка' },
];
</script>

<template>
    <Head title="Digital Goods Store" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header class="sticky top-0 z-20 border-b border-border bg-background/80 backdrop-blur">
            <nav class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                <a href="#top" class="flex items-center gap-2">
                    <div class="flex size-8 items-center justify-center rounded-lg bg-primary">
                        <svg class="size-4 text-primary-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                    </div>
                    <span class="hidden text-lg font-semibold whitespace-nowrap sm:inline">Digital Goods Store</span>
                </a>

                <div class="flex items-center gap-2 sm:gap-4">
                    <a href="#catalog" class="hidden text-sm text-muted-foreground transition-colors hover:text-foreground sm:inline">Каталог</a>
                    <a href="/api/documentation" class="hidden text-sm text-muted-foreground transition-colors hover:text-foreground sm:inline">API</a>

                    <Link v-if="$page.props.auth.user" :href="dashboard()" class="text-sm text-muted-foreground transition-colors hover:text-foreground">
                        Личный кабинет
                    </Link>
                    <template v-else>
                        <Link :href="login()" class="text-sm text-muted-foreground transition-colors hover:text-foreground">Войти</Link>
                        <Button as-child size="sm">
                            <Link :href="register()">Регистрация</Link>
                        </Button>
                    </template>
                </div>
            </nav>
        </header>

        <main id="top" class="flex-1">
            <!-- Hero -->
            <section class="mx-auto max-w-6xl px-6 py-20 lg:py-28">
                <div class="max-w-3xl">
                    <p class="mb-4 inline-flex items-center gap-2 rounded-full border border-border px-3 py-1 text-xs text-muted-foreground">
                        <span class="size-1.5 rounded-full bg-emerald-500" />
                        API работает
                    </p>
                    <h1 class="mb-6 text-4xl font-bold tracking-tight text-balance sm:text-5xl lg:text-6xl">
                        Цифровые ключи с гарантией
                        <span class="block text-muted-foreground">ровно одной выдачи</span>
                    </h1>
                    <p class="mb-8 max-w-2xl text-lg text-pretty text-muted-foreground">
                        Ключи, гифт-карты, подписки и пополнения. Оплата подтверждается идемпотентным
                        вебхуком, выдача идёт под блокировкой строки — один ключ не может уйти двум
                        покупателям, даже если платёжная система пришлёт событие дважды.
                    </p>
                    <div class="flex flex-col gap-3 sm:flex-row">
                        <Button as-child size="lg">
                            <a href="#catalog">Смотреть каталог</a>
                        </Button>
                        <Button as-child size="lg" variant="outline">
                            <a href="/api/documentation">Документация API</a>
                        </Button>
                    </div>
                </div>

                <dl class="mt-16 grid grid-cols-1 gap-px overflow-hidden rounded-xl border border-border bg-border sm:grid-cols-3">
                    <div class="bg-background p-6">
                        <dt class="text-sm text-muted-foreground">Позиций в каталоге</dt>
                        <dd class="mt-1 text-3xl font-semibold tabular-nums">{{ formatCount(stats.products) }}</dd>
                    </div>
                    <div class="bg-background p-6">
                        <dt class="text-sm text-muted-foreground">Ключей в наличии</dt>
                        <dd class="mt-1 text-3xl font-semibold tabular-nums">{{ formatCount(stats.available_keys) }}</dd>
                    </div>
                    <div class="bg-background p-6">
                        <dt class="text-sm text-muted-foreground">Выдано заказов</dt>
                        <dd class="mt-1 text-3xl font-semibold tabular-nums">{{ formatCount(stats.delivered) }}</dd>
                    </div>
                </dl>
            </section>

            <!-- Live catalog -->
            <section id="catalog" class="scroll-mt-20 border-t border-border bg-muted/30">
                <div class="mx-auto max-w-6xl px-6 py-20">
                    <div class="mb-10 flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <h2 class="text-2xl font-semibold">Каталог</h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Живые данные из <code class="rounded bg-muted px-1.5 py-0.5 text-xs">GET /api/catalog</code>
                                <span
                                    v-if="connectionStatus === 'connected'"
                                    class="ml-2 inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400"
                                >
                                    <span class="size-1.5 rounded-full bg-emerald-500" />
                                    Live
                                </span>
                                <span
                                    v-else-if="connectionStatus === 'connecting'"
                                    class="ml-2 inline-flex items-center gap-1 text-amber-600 dark:text-amber-400"
                                >
                                    <span class="size-1.5 rounded-full bg-amber-500 animate-pulse" />
                                    Подключение...
                                </span>
                            </p>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="relative">
                                <input
                                    type="text"
                                    placeholder="Поиск по каталогу..."
                                    :value="searchQuery"
                                    class="w-64 rounded-lg border border-border bg-background px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                                    @input="onSearchInput"
                                />
                                <span
                                    v-if="isSearching"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground"
                                >
                                    <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                    </svg>
                                </span>
                            </div>
                            <select
                                :value="typeFilter"
                                class="rounded-lg border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                                @change="onTypeChange"
                            >
                                <option value="">Все типы</option>
                                <option value="key">Ключ</option>
                                <option value="giftcard">Гифт-карта</option>
                                <option value="subscription">Подписка</option>
                                <option value="topup">Пополнение</option>
                            </select>
                            <a href="/api/documentation" class="text-sm text-muted-foreground underline-offset-4 transition-colors hover:text-foreground hover:underline">
                                Открыть в Swagger →
                            </a>
                        </div>
                    </div>

                    <!-- Search results -->
                    <div v-if="isSearchMode && searchResults.length > 0" class="mb-8">
                        <h3 class="mb-4 text-lg font-medium">
                            Результаты поиска ({{ searchTotal }})
                        </h3>
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <article
                                v-for="item in searchResults"
                                :key="item.sku"
                                class="flex flex-col rounded-xl border border-border bg-card p-5 transition-shadow hover:shadow-md"
                            >
                                <span class="mb-3 w-fit rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground">
                                    {{ typeLabel(item.type) }}
                                </span>
                                <h3 class="mb-1 leading-snug font-medium text-pretty">{{ item.name }}</h3>
                                <code class="mb-4 text-xs text-muted-foreground">{{ item.sku }}</code>

                                <div class="mt-auto">
                                    <div class="flex items-end justify-between gap-2 mb-3">
                                        <span class="text-xl font-semibold tabular-nums">
                                            {{ formatPrice(item.price, item.currency) }}
                                        </span>
                                        <span
                                            class="text-xs tabular-nums"
                                            :class="item.in_stock ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground'"
                                        >
                                            {{ item.in_stock ? `${item.available} шт.` : 'нет в наличии' }}
                                        </span>
                                    </div>
                                    <Button
                                        v-if="item.in_stock"
                                        size="sm"
                                        class="w-full"
                                        :disabled="purchasingSku === item.sku || !item.in_stock"
                                        @click="handlePurchase(item.sku)"
                                    >
                                        <span v-if="purchasingSku === item.sku">Оформление...</span>
                                        <span v-else>Купить</span>
                                    </Button>
                                    <Button v-else size="sm" variant="outline" class="w-full" disabled>
                                        Нет в наличии
                                    </Button>
                                </div>
                            </article>
                        </div>
                    </div>

                    <!-- No search results -->
                    <div v-else-if="isSearchMode && !isSearching" class="mb-8 rounded-xl border border-dashed border-border p-10 text-center text-sm text-muted-foreground">
                        По запросу "{{ searchQuery }}" ничего не найдено.
                    </div>

                    <div v-if="hasCatalog && !isSearchMode" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <article
                            v-for="item in localCatalog"
                            :key="item.sku"
                            class="flex flex-col rounded-xl border border-border bg-card p-5 transition-shadow hover:shadow-md"
                        >
                            <span class="mb-3 w-fit rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground">
                                {{ typeLabel(item.type) }}
                            </span>
                            <h3 class="mb-1 leading-snug font-medium text-pretty">{{ item.name }}</h3>
                            <code class="mb-4 text-xs text-muted-foreground">{{ item.sku }}</code>

                            <div class="mt-auto">
                                <div class="flex items-end justify-between gap-2 mb-3">
                                    <span class="flex items-baseline gap-2">
                                        <span class="text-xl font-semibold tabular-nums">
                                            {{ formatPrice(item.price, item.currency) }}
                                        </span>
                                        <span
                                            v-if="priceChanges[item.sku]"
                                            class="text-xs text-muted-foreground line-through tabular-nums"
                                            title="Цена изменилась, пока страница была открыта"
                                        >
                                            {{ formatPrice(priceChanges[item.sku], item.currency) }}
                                        </span>
                                    </span>
                                    <span
                                        class="text-xs tabular-nums"
                                        :class="item.in_stock ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground'"
                                    >
                                        {{ item.in_stock ? `${item.available} шт.` : 'нет в наличии' }}
                                    </span>
                                </div>
                                <Button
                                    v-if="item.in_stock"
                                    size="sm"
                                    class="w-full"
                                    :disabled="purchasingSku === item.sku || !item.in_stock"
                                    @click="handlePurchase(item.sku)"
                                >
                                    <span v-if="purchasingSku === item.sku">Оформление...</span>
                                    <span v-else>Купить</span>
                                </Button>
                                <Button v-else size="sm" variant="outline" class="w-full" disabled>
                                    Нет в наличии
                                </Button>
                            </div>
                        </article>
                    </div>

                    <!-- Purchase error message -->
                    <div v-if="purchaseError" class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
                        {{ purchaseError }}
                    </div>

                    <p
                        v-if="!hasCatalog && !isSearchMode"
                        class="rounded-xl border border-dashed border-border p-10 text-center text-sm text-muted-foreground"
                    >
                        Каталог пуст. Заполните его командой
                        <code class="rounded bg-muted px-1.5 py-0.5 text-xs">php artisan db:seed</code>.
                    </p>
                </div>
            </section>

            <!-- Order lifecycle -->
            <section class="border-t border-border">
                <div class="mx-auto max-w-6xl px-6 py-20">
                    <h2 class="mb-2 text-2xl font-semibold">Как проходит заказ</h2>
                    <p class="mb-10 max-w-2xl text-sm text-muted-foreground">
                        Четыре состояния счастливого пути. Тупиковые — <code class="rounded bg-muted px-1.5 py-0.5 text-xs">payment_failed</code>,
                        <code class="rounded bg-muted px-1.5 py-0.5 text-xs">out_of_stock</code>,
                        <code class="rounded bg-muted px-1.5 py-0.5 text-xs">delivery_failed</code> — подбирает сверка и возвращает заказ в работу.
                    </p>

                    <ol class="grid gap-4 md:grid-cols-4">
                        <li
                            v-for="(step, index) in lifecycle"
                            :key="step.status"
                            class="relative rounded-xl border border-border bg-card p-5"
                        >
                            <span class="mb-3 flex size-7 items-center justify-center rounded-full bg-primary text-xs font-semibold text-primary-foreground tabular-nums">
                                {{ index + 1 }}
                            </span>
                            <h3 class="font-medium">{{ step.title }}</h3>
                            <code class="mt-1 mb-2 block text-xs text-muted-foreground">{{ step.status }}</code>
                            <p class="text-sm text-pretty text-muted-foreground">{{ step.body }}</p>
                        </li>
                    </ol>
                </div>
            </section>

            <!-- API -->
            <section class="border-t border-border bg-muted/30">
                <div class="mx-auto max-w-6xl px-6 py-20">
                    <div class="grid gap-10 lg:grid-cols-[1fr_1.3fr]">
                        <div>
                            <h2 class="mb-3 text-2xl font-semibold">REST API</h2>
                            <p class="mb-6 text-sm text-pretty text-muted-foreground">
                                Пять эндпоинтов, без аутентификации — сервис рассчитан на закрытый периметр.
                                Полная спецификация OpenAPI 3.0 с возможностью выполнить запрос прямо из браузера.
                            </p>
                            <Button as-child variant="outline">
                                <a href="/api/documentation">Открыть Swagger UI</a>
                            </Button>
                        </div>

                        <div class="overflow-hidden rounded-xl border border-border bg-card">
                            <div class="divide-y divide-border">
                                <a
                                    v-for="endpoint in endpoints"
                                    :key="endpoint.method + endpoint.path"
                                    href="/api/documentation"
                                    class="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-accent"
                                >
                                    <span
                                        class="w-12 shrink-0 rounded px-1.5 py-0.5 text-center text-[10px] font-bold"
                                        :class="endpoint.method === 'GET'
                                            ? 'bg-muted text-muted-foreground'
                                            : 'bg-primary text-primary-foreground'"
                                    >
                                        {{ endpoint.method }}
                                    </span>
                                    <code class="truncate text-sm">{{ endpoint.path }}</code>
                                    <span class="ml-auto hidden shrink-0 text-xs text-muted-foreground sm:inline">
                                        {{ endpoint.note }}
                                    </span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Tech stack -->
            <section class="border-t border-border">
                <div class="mx-auto max-w-6xl px-6 py-20">
                    <h2 class="mb-2 text-2xl font-semibold">Стек</h2>
                    <p class="mb-10 max-w-2xl text-sm text-muted-foreground">
                        Весь стенд поднимается одной командой <code class="rounded bg-muted px-1.5 py-0.5 text-xs">docker compose up -d</code>:
                        приложение, база, Redis и воркер очередей — отдельными контейнерами.
                    </p>

                    <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                        <div v-for="column in stack" :key="column.group">
                            <h3 class="mb-3 text-sm font-medium">{{ column.group }}</h3>
                            <ul class="space-y-2">
                                <li
                                    v-for="item in column.items"
                                    :key="item"
                                    class="flex items-start gap-2 text-sm text-muted-foreground"
                                >
                                    <span class="mt-1.5 size-1 shrink-0 rounded-full bg-muted-foreground/50" />
                                    {{ item }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-border">
            <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 px-6 py-8 sm:flex-row">
                <span class="text-sm font-medium">Digital Goods Store</span>
                <div class="flex items-center gap-4 text-xs text-muted-foreground">
                    <a href="/api/documentation" class="transition-colors hover:text-foreground">API</a>
                    <span>Laravel · PostgreSQL · Redis · Horizon</span>
                </div>
            </div>
        </footer>
    </div>
</template>
