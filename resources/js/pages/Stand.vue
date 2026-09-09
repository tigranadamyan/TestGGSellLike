<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import Button from '@/components/ui/button/Button.vue';
import { connectionState, subscribeToCatalog } from '@/composables/useBroadcast';
import { api } from '@/lib/http';

interface StandProduct {
    sku: string;
    name: string;
    price: string;
    currency: string;
    available: number;
}

interface RaceResult {
    buyer: string;
    status: number;
    body: Record<string, unknown>;
}

const props = defineProps<{
    skus: { live: string; race: string; price: string };
    products: StandProduct[];
}>();

const products = ref<StandProduct[]>([...props.products]);
const connection = connectionState;

const busy = ref<string | null>(null);
const buyLog = ref<{ status: number; body: Record<string, unknown> }[]>([]);
const raceResults = ref<RaceResult[]>([]);
const notice = ref<string | null>(null);
const newPrice = ref('2490');

/** SKUs whose numbers moved since the page opened — highlighted for a moment. */
const justChanged = ref<Set<string>>(new Set());

const formatPrice = (price: string, currency: string) =>
    new Intl.NumberFormat('ru-RU', { style: 'currency', currency, maximumFractionDigits: 0 }).format(Number(price));

const find = (sku: string) => products.value.find((p) => p.sku === sku);

const liveProduct = computed(() => find(props.skus.live));
const raceProduct = computed(() => find(props.skus.race));
const priceProduct = computed(() => find(props.skus.price));

function applyProducts(next: StandProduct[] | undefined) {
    if (next) {
        products.value = next;
    }
}

function flash(sku: string) {
    justChanged.value = new Set([...justChanged.value, sku]);
    setTimeout(() => {
        const copy = new Set(justChanged.value);
        copy.delete(sku);
        justChanged.value = copy;
    }, 1400);
}

async function call(action: string, url: string, body?: unknown) {
    if (busy.value) return null;

    busy.value = action;
    notice.value = null;

    try {
        const response = await api(url, { method: 'POST', body: body ? JSON.stringify(body) : undefined });
        const data = await response.json();
        applyProducts(data.products);
        return data;
    } catch {
        notice.value = 'Запрос не прошёл. Проверьте, что стенд запущен.';
        return null;
    } finally {
        busy.value = null;
    }
}

async function resetAll() {
    const data = await call('reset', '/stand/reset');
    if (data) {
        notice.value = data.message;
        buyLog.value = [];
        raceResults.value = [];
    }
}

async function buyOne() {
    const data = await call('buy', '/stand/buy', { sku: props.skus.live });
    if (data) {
        buyLog.value = [{ status: data.status, body: data.body }, ...buyLog.value].slice(0, 4);
    }
}

async function runRace() {
    const data = await call('race', '/stand/race');
    if (data) {
        raceResults.value = data.results;
    }
}

async function changePrice() {
    const data = await call('price', '/stand/price', { price: newPrice.value });
    if (data) {
        notice.value = data.message;
    }
}

/** Everything the buttons do arrives back here over the socket, not in the reply. */
let unsubscribe: (() => void) | null = null;

onMounted(() => {
    unsubscribe = subscribeToCatalog(({ product }) => {
        const index = products.value.findIndex((p) => p.sku === product.sku);

        if (index === -1) return;

        products.value[index] = {
            ...products.value[index],
            price: product.price,
            available: product.available,
        };

        flash(product.sku);
    });
});

onUnmounted(() => unsubscribe?.());
</script>

<template>
    <Head title="Стенд для проверки" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header class="sticky top-0 z-20 border-b border-border bg-background/80 backdrop-blur">
            <nav class="mx-auto flex max-w-4xl items-center justify-between px-6 py-4">
                <a href="/" class="flex items-center gap-2">
                    <div class="flex size-8 items-center justify-center rounded-lg bg-primary">
                        <svg class="size-4 text-primary-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                    </div>
                    <span class="hidden text-lg font-semibold whitespace-nowrap sm:inline">Digital Goods Store</span>
                </a>
                <div class="flex items-center gap-4 text-sm">
                    <span class="flex items-center gap-1.5 text-xs">
                        <span
                            class="size-1.5 rounded-full"
                            :class="connection === 'connected' ? 'bg-emerald-500' : 'bg-muted-foreground/40'"
                        />
                        <span :class="connection === 'connected' ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground'">
                            {{ connection === 'connected' ? 'WebSocket подключён' : 'Нет соединения' }}
                        </span>
                    </span>
                    <a href="/" class="text-muted-foreground transition-colors hover:text-foreground">Каталог</a>
                    <a href="/api/documentation" class="hidden text-muted-foreground transition-colors hover:text-foreground sm:inline">API</a>
                </div>
            </nav>
        </header>

        <main class="flex-1">
            <div class="mx-auto max-w-4xl px-6 py-12">

                <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">Стенд для проверки</h1>
                <p class="mt-3 max-w-2xl text-muted-foreground">
                    Здесь можно проверить живую витрину и гонку за последней единицей, не открывая
                    терминал. Кнопки выполняют ровно те же запросы, что описаны в README, — а
                    результат прилетает обратно по WebSocket, а не в ответе на нажатие.
                </p>

                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <Button variant="outline" size="sm" :disabled="busy !== null" @click="resetAll">
                        <span v-if="busy === 'reset'">Сброс...</span>
                        <span v-else>Сбросить стенд в исходное состояние</span>
                    </Button>
                    <a href="/" target="_blank" class="text-sm text-muted-foreground underline underline-offset-4 transition-colors hover:text-foreground">
                        Открыть витрину в соседней вкладке ↗
                    </a>
                </div>

                <p v-if="notice" class="mt-4 rounded-lg border border-border bg-muted/40 px-4 py-2.5 text-sm">
                    {{ notice }}
                </p>

                <!-- Live state -->
                <section class="mt-10">
                    <h2 class="text-sm font-semibold tracking-wide uppercase text-muted-foreground">Текущее состояние склада</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Эти цифры обновляются по WebSocket. Ничего не нажимая, они меняются, когда
                        товар покупает кто-то другой.
                    </p>

                    <div class="mt-4 grid gap-3 sm:grid-cols-3">
                        <div
                            v-for="p in products"
                            :key="p.sku"
                            class="rounded-xl border p-4 transition-colors duration-500"
                            :class="justChanged.has(p.sku)
                                ? 'border-emerald-400 bg-emerald-50 dark:border-emerald-700 dark:bg-emerald-900/20'
                                : 'border-border bg-card'"
                        >
                            <code class="text-xs text-muted-foreground">{{ p.sku }}</code>
                            <p class="mt-1 text-sm leading-snug font-medium">{{ p.name }}</p>
                            <div class="mt-3 flex items-baseline justify-between gap-2">
                                <span class="text-lg font-semibold tabular-nums">{{ formatPrice(p.price, p.currency) }}</span>
                                <span
                                    class="text-sm tabular-nums"
                                    :class="p.available > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground'"
                                >{{ p.available > 0 ? `${p.available} шт.` : 'распродан' }}</span>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Check 1 -->
                <section class="mt-12 border-t border-border pt-8">
                    <div class="flex items-baseline gap-3">
                        <span class="font-mono text-xs font-semibold text-muted-foreground">ПРОВЕРКА 1</span>
                        <h2 class="text-xl font-semibold">Живая витрина</h2>
                    </div>

                    <p class="mt-3 max-w-2xl text-sm text-muted-foreground">
                        Откройте витрину в соседней вкладке и оставьте её открытой. Нажатие кнопки
                        ниже покупает одну единицу <code class="rounded bg-muted px-1 py-0.5 text-xs">{{ skus.live }}</code>
                        <b> на сервере</b> — как если бы это сделал другой покупатель. Счётчик
                        изменится и здесь, и в той вкладке, без перезагрузки.
                    </p>

                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <Button :disabled="busy !== null" @click="buyOne">
                            <span v-if="busy === 'buy'">Покупка...</span>
                            <span v-else>Купить одну единицу</span>
                        </Button>
                        <span class="text-sm text-muted-foreground">
                            Осталось: <b class="tabular-nums">{{ liveProduct?.available ?? '—' }}</b>
                        </span>
                    </div>

                    <p class="mt-3 text-sm text-muted-foreground">
                        Нажмите ещё раз, пока не станет 0 — тогда карточка на витрине переключится
                        на «нет в наличии», а кнопка «Купить» станет неактивной у всех одновременно.
                    </p>

                    <div v-if="buyLog.length" class="mt-4 overflow-hidden rounded-lg border border-border">
                        <div
                            v-for="(entry, i) in buyLog"
                            :key="i"
                            class="flex items-start gap-3 border-b border-border px-4 py-2.5 text-sm last:border-b-0"
                        >
                            <span
                                class="mt-0.5 rounded px-1.5 py-0.5 font-mono text-xs font-semibold"
                                :class="entry.status === 201
                                    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300'
                                    : 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300'"
                            >HTTP {{ entry.status }}</span>
                            <code class="min-w-0 flex-1 break-all text-xs text-muted-foreground">{{ JSON.stringify(entry.body) }}</code>
                        </div>
                    </div>
                </section>

                <!-- Check 2 -->
                <section class="mt-12 border-t border-border pt-8">
                    <div class="flex items-baseline gap-3">
                        <span class="font-mono text-xs font-semibold text-muted-foreground">ПРОВЕРКА 2</span>
                        <h2 class="text-xl font-semibold">Гонка за последней единицей</h2>
                    </div>

                    <p class="mt-3 max-w-2xl text-sm text-muted-foreground">
                        У <code class="rounded bg-muted px-1 py-0.5 text-xs">{{ skus.race }}</code> остаётся ровно один ключ,
                        после чего два запроса уходят <b>одновременно</b> — настоящими параллельными
                        HTTP-вызовами, а не по очереди внутри одного процесса. Ключ выбирается под
                        <code class="rounded bg-muted px-1 py-0.5 text-xs">FOR UPDATE SKIP LOCKED</code>
                        и сразу закрепляется за заказом, поэтому выиграть может только один.
                    </p>

                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <Button :disabled="busy !== null" @click="runRace">
                            <span v-if="busy === 'race'">Запуск...</span>
                            <span v-else>Запустить гонку двух покупателей</span>
                        </Button>
                        <span class="text-sm text-muted-foreground">
                            Ключей у товара: <b class="tabular-nums">{{ raceProduct?.available ?? '—' }}</b>
                        </span>
                    </div>

                    <div v-if="raceResults.length" class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div
                            v-for="result in raceResults"
                            :key="result.buyer"
                            class="rounded-xl border p-4"
                            :class="result.status === 201
                                ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-900/20'
                                : 'border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-900/20'"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-medium">{{ result.buyer }}</span>
                                <span
                                    class="rounded px-1.5 py-0.5 font-mono text-xs font-semibold"
                                    :class="result.status === 201
                                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300'
                                        : 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300'"
                                >HTTP {{ result.status }}</span>
                            </div>
                            <p class="mt-2 text-sm">
                                <span v-if="result.status === 201">Получил товар — заказ создан.</span>
                                <span v-else>Товар только что раскупили. Заказа нет — платить не за что.</span>
                            </p>
                            <code class="mt-2 block break-all text-xs text-muted-foreground">{{ JSON.stringify(result.body) }}</code>
                        </div>
                    </div>

                    <p class="mt-4 text-sm text-muted-foreground">
                        Ожидаемо: один ответ <b>201</b>, другой <b>409</b> с сообщением
                        «Этот товар только что раскупили». Заказ создаётся ровно один — у проигравшего
                        не остаётся ничего, что можно было бы оплатить.
                    </p>
                </section>

                <!-- Check 3 -->
                <section class="mt-12 border-t border-border pt-8">
                    <div class="flex items-baseline gap-3">
                        <span class="font-mono text-xs font-semibold text-muted-foreground">ПРОВЕРКА 3</span>
                        <h2 class="text-xl font-semibold">Цена изменилась, пока товар в корзине</h2>
                    </div>

                    <p class="mt-3 max-w-2xl text-sm text-muted-foreground">
                        Положите <code class="rounded bg-muted px-1 py-0.5 text-xs">{{ skus.price }}</code> в корзину на витрине,
                        откройте <a href="/cart" target="_blank" class="underline underline-offset-4">корзину</a> в соседней вкладке
                        и поменяйте цену отсюда. Новая цена появится в корзине сразу, старая будет
                        зачёркнута, а оформление заказа потребует подтвердить изменившуюся сумму.
                    </p>

                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <label class="text-sm text-muted-foreground" for="price">Новая цена</label>
                        <input
                            id="price"
                            v-model="newPrice"
                            type="number"
                            min="1"
                            class="w-32 rounded-lg border border-border bg-background px-3 py-2 text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-ring"
                        />
                        <Button variant="outline" :disabled="busy !== null" @click="changePrice">
                            <span v-if="busy === 'price'">Меняю...</span>
                            <span v-else>Изменить цену</span>
                        </Button>
                        <span class="text-sm text-muted-foreground">
                            Сейчас: <b class="tabular-nums">{{ priceProduct ? formatPrice(priceProduct.price, priceProduct.currency) : '—' }}</b>
                        </span>
                    </div>

                    <p class="mt-3 text-sm text-muted-foreground">
                        Защита не только визуальная: <code class="rounded bg-muted px-1 py-0.5 text-xs">POST /cart/checkout</code>
                        сверяет цену на сервере и отвечает <b>409</b>, если она разошлась с той, что
                        покупатель видел. Оплатить старую цену нельзя, даже если соединение оборвалось.
                    </p>
                </section>

                <!-- Where else to look -->
                <section class="mt-12 border-t border-border pt-8">
                    <h2 class="text-sm font-semibold tracking-wide uppercase text-muted-foreground">Что ещё посмотреть</h2>

                    <dl class="mt-4 grid gap-x-8 gap-y-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium">Бронь с таймером</dt>
                            <dd class="mt-0.5 text-sm text-muted-foreground">
                                Купите что-нибудь на витрине — на странице заказа идёт обратный отсчёт.
                                Через пять минут ключ вернётся в продажу всем.
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium">Живая выдача ключа</dt>
                            <dd class="mt-0.5 text-sm text-muted-foreground">
                                На странице заказа нажмите «Оплатить» — статус сменится на «Выдан»
                                и появится ключ, без перезагрузки.
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium">Поиск</dt>
                            <dd class="mt-0.5 text-sm text-muted-foreground">
                                Полнотекстовый по PostgreSQL, с русской морфологией. Запрос и фильтр
                                сохраняются в адресе: <code class="text-xs">/?q=ключ&amp;type=key</code>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium">Документация API</dt>
                            <dd class="mt-0.5 text-sm text-muted-foreground">
                                <a href="/api/documentation" class="underline underline-offset-4">Swagger UI</a> —
                                можно выполнять запросы прямо из браузера.
                            </dd>
                        </div>
                    </dl>
                </section>

                <p class="mt-12 border-t border-border pt-6 text-xs text-muted-foreground">
                    Кнопки на этой странице меняют данные каталога, поэтому маршруты
                    <code>/stand/*</code> отключены в production. Стек: Laravel 13 · PostgreSQL 16 ·
                    Redis 7 · Horizon · Reverb · Vue 3 + Inertia.
                </p>
            </div>
        </main>
    </div>
</template>
