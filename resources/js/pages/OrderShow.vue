<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import Button from '@/components/ui/button/Button.vue';
import { subscribeToOrder } from '@/composables/useBroadcast';

interface Delivery {
    status: string;
    code: string | null;
    supplier: string | null;
}

interface Order {
    id: number;
    sku: string;
    price: string;
    currency: string;
    status: string;
    created_at: string;
    delivery: Delivery | null;
}

interface Reservation {
    expires_at: string;
    remaining_seconds: number;
    is_active: boolean;
}

const props = defineProps<{
    order: Order;
    reservation: Reservation | null;
}>();

const order = ref<Order>({ ...props.order });
const reservation = ref<Reservation | null>(props.reservation);
const countdown = ref(props.reservation?.remaining_seconds ?? 0);
/** Fixed denominator for the progress bar — the live value shrinks. */
const initialCountdown = Math.max(1, props.reservation?.remaining_seconds ?? 300);
const isPaying = ref(false);
const paymentError = ref<string | null>(null);

const formatPrice = (price: string, currency: string) =>
    new Intl.NumberFormat('ru-RU', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format(Number(price));

const statusLabels: Record<string, { label: string; color: string; description: string }> = {
    created: {
        label: 'Создан',
        color: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400',
        description: 'Ожидает оплату. Оплатите заказ в течение 5 минут, пока бронь активна.',
    },
    paid: {
        label: 'Оплачен',
        color: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
        description: 'Оплата получена. Ключ резервируется и будет выдан в ближайшее время.',
    },
    delivering: {
        label: 'Выдаётся',
        color: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-400',
        description: 'Ключ выдаётся. Пожалуйста, подождите.',
    },
    delivered: {
        label: 'Выдан',
        color: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
        description: 'Ключ успешно выдан!',
    },
    payment_failed: {
        label: 'Ошибка оплаты',
        color: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
        description: 'Оплата не прошла. Попробуйте создать новый заказ.',
    },
    out_of_stock: {
        label: 'Нет в наличии',
        color: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
        description: 'Товар закончился. Попробуйте позже или выберите другой товар.',
    },
    delivery_failed: {
        label: 'Ошибка выдачи',
        color: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
        description: 'Не удалось выдать ключ. Попробуйте позже.',
    },
};

const statusInfo = computed(() => statusLabels[order.value.status] ?? statusLabels.created);
const isTerminal = computed(() => ['delivered', 'payment_failed'].includes(order.value.status));
const isReserving = computed(() => ['created'].includes(order.value.status));
const showCountdown = computed(() => isReserving.value && countdown.value > 0);

/** Timer ran out while the order is still unpaid: the key went back on sale. */
const holdExpired = computed(() => isReserving.value && countdown.value <= 0);

const formatCountdown = (seconds: number) => {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}:${s.toString().padStart(2, '0')}`;
};

let countdownInterval: ReturnType<typeof setInterval> | null = null;
let pollInterval: ReturnType<typeof setInterval> | null = null;
let unsubscribe: (() => void) | null = null;

/** Statuses that are still on their way somewhere. */
const inFlight = (status: string) => ['paid', 'delivering'].includes(status);

onMounted(() => {
    // Start countdown timer
    if (reservation.value?.is_active && countdown.value > 0) {
        countdownInterval = setInterval(() => {
            countdown.value = Math.max(0, countdown.value - 1);
            if (countdown.value <= 0) {
                clearInterval(countdownInterval!);
                countdownInterval = null;
            }
        }, 1000);
    }

    // Subscribe to real-time order status updates
    unsubscribe = subscribeToOrder(order.value.id, (data) => {
        order.value = {
            ...order.value,
            status: data.new_status,
            // The key rides along with the status; without it the page announced
            // "delivered" and still showed the waiting spinner until a reload.
            delivery: data.delivery ?? order.value.delivery,
        };

        if (data.new_status !== 'created') {
            countdown.value = 0;
        }

        // Safety net: if a terminal status arrives without its delivery (an event
        // published before the row landed, or one missed while offline), ask the
        // server once rather than leaving the shopper staring at a spinner.
        if (data.new_status === 'delivered' && !order.value.delivery?.code) {
            void refreshOrder();
        }
    });

    // Belt and braces: issuing happens in a background worker, so if the socket
    // never connected or dropped mid-flight, poll until the order settles.
    pollInterval = setInterval(() => {
        if (inFlight(order.value.status) || (order.value.status === 'delivered' && !order.value.delivery?.code)) {
            void refreshOrder();
        }
    }, 3000);
});

onUnmounted(() => {
    if (countdownInterval) {
        clearInterval(countdownInterval);
    }
    if (pollInterval) {
        clearInterval(pollInterval);
    }
    unsubscribe?.();
});

/** Re-read the order from the API and apply whatever the server has. */
async function refreshOrder(): Promise<void> {
    try {
        const response = await fetch(`/api/orders/${order.value.id}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            return;
        }

        const { data } = await response.json();
        order.value = { ...order.value, status: data.status, delivery: data.delivery };
    } catch {
        // A failed refresh is not worth surfacing; the poll will try again.
    }
}

async function simulatePayment(success = true) {
    if (isPaying.value) return;
    isPaying.value = true;
    paymentError.value = null;

    try {
        const response = await fetch('/api/webhooks/payment', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                event_id: `evt_pay_order_${order.value.id}_${Date.now()}`,
                order_id: order.value.id,
                status: success ? 'paid' : 'failed',
                amount: order.value.price,
                currency: order.value.currency,
                created_at: new Date().toISOString(),
            }),
        });

        if (!response.ok) {
            const data = await response.json();
            throw new Error(data.error || 'Payment failed');
        }

        // Update local state immediately (WebSocket will also update)
        order.value = { ...order.value, status: success ? 'paid' : 'payment_failed' };
        countdown.value = 0;
    } catch (err: any) {
        paymentError.value = err.message || 'Ошибка оплаты';
    } finally {
        isPaying.value = false;
    }
}
</script>

<template>
    <Head :title="`Заказ #${order.id}`" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header class="sticky top-0 z-20 border-b border-border bg-background/80 backdrop-blur">
            <nav class="mx-auto flex max-w-3xl items-center justify-between px-6 py-4">
                <a href="/" class="flex items-center gap-2">
                    <div class="flex size-8 items-center justify-center rounded-lg bg-primary">
                        <svg class="size-4 text-primary-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                    </div>
                    <span class="text-lg font-semibold">Digital Goods Store</span>
                </a>
                <a href="/" class="text-sm text-muted-foreground transition-colors hover:text-foreground">
                    ← Назад в каталог
                </a>
            </nav>
        </header>

        <main class="flex-1">
            <div class="mx-auto max-w-3xl px-6 py-12">
                <!-- Order Header -->
                <div class="mb-8">
                    <h1 class="text-3xl font-bold">Заказ #{{ order.id }}</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ order.sku }} · {{ formatPrice(order.price, order.currency) }}
                    </p>
                </div>

                <!-- Status Card -->
                <div class="mb-8 rounded-xl border border-border bg-card p-6">
                    <div class="flex items-center gap-3 mb-4">
                        <span :class="[statusInfo.color, 'inline-flex items-center rounded-full px-3 py-1 text-sm font-medium']">
                            {{ statusInfo.label }}
                        </span>
                        <span class="text-sm text-muted-foreground">
                            {{ statusInfo.description }}
                        </span>
                    </div>

                    <!-- Countdown Timer -->
                    <div v-if="showCountdown" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
                        <div class="flex items-center gap-3">
                            <svg class="size-5 text-amber-600 dark:text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10" />
                                <polyline points="12,6 12,12 16,14" />
                            </svg>
                            <div>
                                <p class="text-sm font-medium text-amber-800 dark:text-amber-300">
                                    Бронь истекает через {{ formatCountdown(countdown) }}
                                </p>
                                <p class="text-xs text-amber-600 dark:text-amber-400">
                                    Оплатите заказ, пока бронь активна. После истечения времени товар будет возвращён в продажу.
                                </p>
                            </div>
                        </div>
                        <!-- Progress bar -->
                        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-amber-200 dark:bg-amber-800">
                            <div
                                class="h-full rounded-full bg-amber-500 transition-all duration-1000 ease-linear"
                                :style="{ width: `${(countdown / initialCountdown) * 100}%` }"
                            />
                        </div>
                    </div>

                    <!-- Hold expired -->
                    <div v-if="holdExpired" class="mt-4 rounded-lg border border-border bg-muted/40 p-4">
                        <p class="text-sm font-medium">Бронь истекла</p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Товар вернулся в продажу. Оплата всё ещё принимается, но ключ будет
                            выдан только если он ещё остался — иначе заказ уйдёт в разбор.
                        </p>
                        <a href="/" class="mt-2 inline-block text-xs underline underline-offset-4">← Вернуться в каталог</a>
                    </div>

                    <!-- Delivery Info -->
                    <div v-if="order.delivery" class="mt-4 rounded-lg border border-border bg-muted/30 p-4">
                        <h3 class="text-sm font-medium mb-2">Доставка</h3>
                        <dl class="grid grid-cols-2 gap-2 text-sm">
                            <dt class="text-muted-foreground">Поставщик</dt>
                            <dd>{{ order.delivery.supplier ?? '—' }}</dd>
                            <dt class="text-muted-foreground">Статус</dt>
                            <dd>{{ order.delivery.status }}</dd>
                            <template v-if="order.delivery.code">
                                <dt class="text-muted-foreground">Ключ</dt>
                                <dd class="font-mono text-sm bg-muted px-2 py-1 rounded select-all">{{ order.delivery.code }}</dd>
                            </template>
                        </dl>
                    </div>
                </div>

                <!-- Order Details -->
                <div class="rounded-xl border border-border bg-card p-6 mb-8">
                    <h2 class="text-lg font-medium mb-4">Детали заказа</h2>
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <dt class="text-muted-foreground">ID</dt>
                        <dd>{{ order.id }}</dd>
                        <dt class="text-muted-foreground">Товар</dt>
                        <dd>{{ order.sku }}</dd>
                        <dt class="text-muted-foreground">Сумма</dt>
                        <dd class="font-medium">{{ formatPrice(order.price, order.currency) }}</dd>
                        <dt class="text-muted-foreground">Создан</dt>
                        <dd>{{ new Date(order.created_at).toLocaleString('ru-RU') }}</dd>
                    </dl>
                </div>

                <!-- Actions -->
                <div class="flex flex-col gap-3 sm:flex-row">
                    <!-- Created: show pay button -->
                    <template v-if="order.status === 'created'">
                        <Button
                            size="lg"
                            class="flex-1"
                            :disabled="isPaying"
                            @click="simulatePayment(true)"
                        >
                            <span v-if="isPaying">Обработка оплаты...</span>
                            <span v-else>Оплатить {{ formatPrice(order.price, order.currency) }}</span>
                        </Button>
                        <Button
                            size="lg"
                            variant="outline"
                            class="flex-1"
                            :disabled="isPaying"
                            @click="simulatePayment(false)"
                        >
                            Имитировать ошибку
                        </Button>
                    </template>

                    <!-- Paid / delivering: waiting state -->
                    <template v-else-if="order.status === 'paid' || order.status === 'delivering'">
                        <Button size="lg" class="flex-1" disabled>
                            Ожидание выдачи ключа...
                        </Button>
                    </template>

                    <!-- Delivered: success -->
                    <Button v-else-if="order.status === 'delivered'" size="lg" class="flex-1" disabled>
                        Ключ выдан
                    </Button>

                    <!-- Failed: retry link -->
                    <Button v-else-if="order.status === 'payment_failed' || order.status === 'out_of_stock' || order.status === 'delivery_failed'" size="lg" class="flex-1" as-child>
                        <a href="/">Создать новый заказ</a>
                    </Button>

                    <!-- Fallback -->
                    <Button v-else size="lg" variant="outline" class="flex-1" as-child>
                        <a href="/">Вернуться в каталог</a>
                    </Button>
                </div>

                <!-- Payment error -->
                <div v-if="paymentError" class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
                    {{ paymentError }}
                </div>
            </div>
        </main>
    </div>
</template>
