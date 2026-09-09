<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import Button from '@/components/ui/button/Button.vue';
import { connectionState, subscribeToCatalog } from '@/composables/useBroadcast';
import { api } from '@/lib/http';

interface CartLine {
    sku: string;
    name: string;
    type: string;
    qty: number;
    price: string;
    price_at_add: string;
    price_changed: boolean;
    currency: string;
    available: number;
    in_stock: boolean;
    line_total: string;
}

interface CreatedOrder {
    id: number;
    sku: string;
    price: string;
    currency: string;
}

const props = defineProps<{
    lines: CartLine[];
    total: string;
    count: number;
}>();

const lines = ref<CartLine[]>([...props.lines]);
const total = ref(props.total);
const busy = ref(false);
const isCheckingOut = ref(false);
const notice = ref<string | null>(null);
const soldOut = ref<{ sku: string; name: string }[]>([]);
const createdOrders = ref<CreatedOrder[]>([]);
const connection = connectionState;

const formatPrice = (price: string, currency: string) =>
    new Intl.NumberFormat('ru-RU', { style: 'currency', currency, maximumFractionDigits: 0 }).format(Number(price));

const typeLabels: Record<string, string> = {
    key: 'Ключ',
    giftcard: 'Гифт-карта',
    subscription: 'Подписка',
    topup: 'Пополнение',
};
const typeLabel = (type: string) => typeLabels[type] ?? type;

const isEmpty = computed(() => lines.value.length === 0);
const changedLines = computed(() => lines.value.filter((l) => l.price_changed));
const currency = computed(() => lines.value[0]?.currency ?? 'RUB');

/** Difference between what the cart showed on arrival and what it costs now. */
const totalAtAdd = computed(() =>
    lines.value.reduce((sum, l) => sum + Number(l.price_at_add) * l.qty, 0),
);
const totalDelta = computed(() => Number(total.value) - totalAtAdd.value);

function applyPayload(payload: { lines: CartLine[]; total: string }) {
    lines.value = payload.lines;
    total.value = payload.total;
}

async function send(url: string, method: string, body?: unknown) {
    if (busy.value) return null;
    busy.value = true;

    try {
        const response = await api(url, {
            method,
            body: body ? JSON.stringify(body) : undefined,
        });
        const data = await response.json();

        if (data.lines) {
            applyPayload(data);
        }

        return { ok: response.ok, status: response.status, data };
    } catch {
        notice.value = 'Не удалось связаться с сервером. Попробуйте ещё раз.';
        return null;
    } finally {
        busy.value = false;
    }
}

const setQty = (sku: string, qty: number) => send(`/cart/${sku}`, 'PATCH', { qty });
const removeLine = (sku: string) => send(`/cart/${sku}`, 'DELETE');

async function checkout() {
    if (isCheckingOut.value) return;

    isCheckingOut.value = true;
    notice.value = null;
    soldOut.value = [];

    const result = await send('/cart/checkout', 'POST');
    isCheckingOut.value = false;

    if (!result) return;

    // The price moved while the cart was open. Checkout stops here so the new
    // figure is on screen before anything is charged; the next click goes through.
    if (result.status === 409 && result.data.error === 'price_changed') {
        notice.value = result.data.message;
        return;
    }

    if (result.data.sold_out?.length) {
        soldOut.value = result.data.sold_out;
    }

    if (result.data.orders?.length) {
        createdOrders.value = result.data.orders;

        if (result.data.orders.length === 1 && !result.data.sold_out?.length) {
            window.location.href = `/orders/${result.data.orders[0].id}`;
        }
    } else if (!result.data.sold_out?.length) {
        notice.value = result.data.message ?? 'Не удалось оформить заказ.';
    }
}

/** Live price and stock changes land straight in the open cart. */
let unsubscribe: (() => void) | null = null;

onMounted(() => {
    unsubscribe = subscribeToCatalog(({ product }) => {
        const index = lines.value.findIndex((l) => l.sku === product.sku);

        if (index === -1) return;

        const line = lines.value[index];
        const changed = Number(product.price) !== Number(line.price_at_add);

        lines.value[index] = {
            ...line,
            price: product.price,
            price_changed: changed,
            available: product.available,
            in_stock: product.in_stock,
            line_total: (Number(product.price) * line.qty).toFixed(2),
        };

        total.value = lines.value.reduce((sum, l) => sum + Number(l.line_total), 0).toFixed(2);

        if (changed) {
            notice.value = 'Цена изменилась, пока товар лежал в корзине. Проверьте сумму перед оплатой.';
        }
    });
});

onUnmounted(() => unsubscribe?.());
</script>

<template>
    <Head title="Корзина" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header class="sticky top-0 z-20 border-b border-border bg-background/80 backdrop-blur">
            <nav class="mx-auto flex max-w-3xl items-center justify-between px-6 py-4">
                <a href="/" class="flex items-center gap-2">
                    <div class="flex size-8 items-center justify-center rounded-lg bg-primary">
                        <svg class="size-4 text-primary-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                    </div>
                    <span class="hidden text-lg font-semibold whitespace-nowrap sm:inline">Digital Goods Store</span>
                </a>
                <div class="flex items-center gap-4 text-sm">
                    <span class="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <span
                            class="size-1.5 rounded-full"
                            :class="connection === 'connected' ? 'bg-emerald-500' : 'bg-muted-foreground/40'"
                        />
                        {{ connection === 'connected' ? 'Live' : 'Не в сети' }}
                    </span>
                    <a href="/" class="text-muted-foreground transition-colors hover:text-foreground">← В каталог</a>
                </div>
            </nav>
        </header>

        <main class="flex-1">
            <div class="mx-auto max-w-3xl px-6 py-12">
                <h1 class="text-3xl font-bold">Корзина</h1>

                <!-- Price moved while the cart was open -->
                <div
                    v-if="notice"
                    class="mt-6 rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20"
                >
                    <p class="text-sm font-medium text-amber-800 dark:text-amber-300">{{ notice }}</p>
                    <ul v-if="changedLines.length" class="mt-2 space-y-1">
                        <li v-for="line in changedLines" :key="line.sku" class="text-xs text-amber-700 dark:text-amber-400">
                            {{ line.name }}:
                            <span class="line-through">{{ formatPrice(line.price_at_add, line.currency) }}</span>
                            →
                            <b>{{ formatPrice(line.price, line.currency) }}</b>
                        </li>
                    </ul>
                </div>

                <div
                    v-if="soldOut.length"
                    class="mt-6 rounded-lg border border-border bg-muted/40 p-4"
                >
                    <p class="text-sm font-medium">Не хватило на складе</p>
                    <ul class="mt-1 space-y-0.5">
                        <li v-for="item in soldOut" :key="item.sku" class="text-xs text-muted-foreground">
                            {{ item.name }} — раскупили, пока вы оформляли
                        </li>
                    </ul>
                </div>

                <div
                    v-if="createdOrders.length"
                    class="mt-6 rounded-lg border border-emerald-300 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-900/20"
                >
                    <p class="text-sm font-medium text-emerald-800 dark:text-emerald-300">Заказы созданы</p>
                    <ul class="mt-2 space-y-1">
                        <li v-for="order in createdOrders" :key="order.id" class="text-sm">
                            <a class="underline underline-offset-4" :href="`/orders/${order.id}`">
                                Заказ #{{ order.id }} · {{ order.sku }}
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Lines -->
                <div v-if="!isEmpty" class="mt-8 overflow-hidden rounded-xl border border-border">
                    <div
                        v-for="line in lines"
                        :key="line.sku"
                        class="flex flex-wrap items-start gap-4 border-b border-border p-5 last:border-b-0"
                    >
                        <div class="min-w-0 flex-1">
                            <span class="mb-2 inline-block rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground">
                                {{ typeLabel(line.type) }}
                            </span>
                            <h2 class="leading-snug font-medium">{{ line.name }}</h2>
                            <code class="text-xs text-muted-foreground">{{ line.sku }}</code>

                            <p v-if="!line.in_stock" class="mt-2 text-xs text-muted-foreground">
                                Нет в наличии
                            </p>
                            <p v-else-if="line.available < line.qty" class="mt-2 text-xs text-amber-600 dark:text-amber-400">
                                Осталось {{ line.available }} шт.
                            </p>
                        </div>

                        <div class="flex items-center gap-1 rounded-lg border border-border">
                            <button
                                class="px-2.5 py-1 text-sm transition-colors hover:bg-accent disabled:opacity-40"
                                :disabled="busy"
                                aria-label="Убрать одну штуку"
                                @click="setQty(line.sku, line.qty - 1)"
                            >−</button>
                            <span class="w-6 text-center text-sm tabular-nums">{{ line.qty }}</span>
                            <button
                                class="px-2.5 py-1 text-sm transition-colors hover:bg-accent disabled:opacity-40"
                                :disabled="busy || line.qty >= line.available"
                                aria-label="Добавить одну штуку"
                                @click="setQty(line.sku, line.qty + 1)"
                            >+</button>
                        </div>

                        <div class="w-32 text-right">
                            <div class="font-semibold tabular-nums">
                                {{ formatPrice(line.line_total, line.currency) }}
                            </div>
                            <div v-if="line.price_changed" class="mt-0.5 text-xs">
                                <span class="text-muted-foreground line-through tabular-nums">
                                    {{ formatPrice(line.price_at_add, line.currency) }}
                                </span>
                                <span class="ml-1 text-amber-600 dark:text-amber-400">за шт. изменилась</span>
                            </div>
                            <button
                                class="mt-1 text-xs text-muted-foreground underline-offset-4 transition-colors hover:text-foreground hover:underline"
                                :disabled="busy"
                                @click="removeLine(line.sku)"
                            >
                                Удалить
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Totals -->
                <div v-if="!isEmpty" class="mt-6 flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p class="text-sm text-muted-foreground">К оплате</p>
                        <p class="text-3xl font-semibold tabular-nums">{{ formatPrice(total, currency) }}</p>
                        <p v-if="Math.abs(totalDelta) >= 0.01" class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                            {{ totalDelta > 0 ? 'Дороже' : 'Дешевле' }} на
                            {{ formatPrice(String(Math.abs(totalDelta)), currency) }},
                            чем когда вы добавляли товар
                        </p>
                    </div>

                    <Button size="lg" :disabled="isCheckingOut || busy" @click="checkout">
                        <span v-if="isCheckingOut">Оформление...</span>
                        <span v-else-if="changedLines.length">Подтвердить новую цену</span>
                        <span v-else>Оформить заказ</span>
                    </Button>
                </div>

                <p v-if="isEmpty" class="mt-8 rounded-xl border border-dashed border-border p-10 text-center text-sm text-muted-foreground">
                    Корзина пуста. <a href="/" class="underline underline-offset-4">Вернуться в каталог</a>
                </p>
            </div>
        </main>
    </div>
</template>
