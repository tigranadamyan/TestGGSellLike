<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { dashboard, login, register } from '@/routes';
import Button from '@/components/ui/button/Button.vue';

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

const hasCatalog = computed(() => props.catalog.length > 0);

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
                            </p>
                        </div>
                        <a href="/api/documentation" class="text-sm text-muted-foreground underline-offset-4 transition-colors hover:text-foreground hover:underline">
                            Открыть в Swagger →
                        </a>
                    </div>

                    <div v-if="hasCatalog" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <article
                            v-for="item in catalog"
                            :key="item.sku"
                            class="flex flex-col rounded-xl border border-border bg-card p-5 transition-shadow hover:shadow-md"
                        >
                            <span class="mb-3 w-fit rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground">
                                {{ typeLabel(item.type) }}
                            </span>
                            <h3 class="mb-1 leading-snug font-medium text-pretty">{{ item.name }}</h3>
                            <code class="mb-4 text-xs text-muted-foreground">{{ item.sku }}</code>

                            <div class="mt-auto flex items-end justify-between gap-2">
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
                        </article>
                    </div>

                    <p v-else class="rounded-xl border border-dashed border-border p-10 text-center text-sm text-muted-foreground">
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
