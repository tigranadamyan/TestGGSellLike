import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { ref, type Ref } from 'vue';

// laravel-echo looks this up on the window.
(window as any).Pusher = Pusher;

export type ConnectionState = 'connecting' | 'connected' | 'disconnected';

/** Real socket state, not a guess — the UI badge is bound straight to this. */
export const connectionState: Ref<ConnectionState> = ref('connecting');

let echo: Echo<'reverb'> | null = null;

/**
 * The WebSocket lives on the same origin as the page: nginx routes /app to
 * Reverb. That keeps it working on localhost and behind the Cloudflare tunnel
 * alike, instead of hardcoding a host that only exists on the developer's
 * machine.
 */
function currentPort(): number {
    if (window.location.port) {
        return Number(window.location.port);
    }
    return window.location.protocol === 'https:' ? 443 : 80;
}

export function getEcho(): Echo<'reverb'> {
    if (echo) {
        return echo;
    }

    const isSecure = window.location.protocol === 'https:';
    const port = currentPort();

    echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: isSecure,
        enabledTransports: isSecure ? ['wss'] : ['ws'],
    });

    const connection = (echo as any).connector?.pusher?.connection;

    if (connection) {
        const sync = () => {
            const state = connection.state as string;
            connectionState.value =
                state === 'connected' ? 'connected' : state === 'connecting' || state === 'initialized' ? 'connecting' : 'disconnected';
        };

        connection.bind('state_change', sync);
        connection.bind('error', () => {
            connectionState.value = 'disconnected';
        });
        sync();
    } else {
        connectionState.value = 'disconnected';
    }

    return echo;
}

export interface CatalogUpdatePayload {
    product: {
        sku: string;
        name: string;
        type: string;
        price: string;
        currency: string;
        in_stock: boolean;
        available: number;
        old_price: string | null;
        old_available: number | null;
    };
    change_type: string;
    timestamp: string;
}

/** Listen for price and availability changes on the shared catalog channel. */
export function subscribeToCatalog(onUpdate: (data: CatalogUpdatePayload) => void): () => void {
    const instance = getEcho();
    instance.channel('catalog').listen('.catalog.updated', onUpdate);

    return () => instance.leave('catalog');
}

export interface OrderStatusPayload {
    order_id: number;
    sku: string;
    old_status: string;
    new_status: string;
    delivery: {
        status: string;
        code: string | null;
        supplier: string | null;
    } | null;
    timestamp: string;
}

/** Listen for one order's progress. */
export function subscribeToOrder(orderId: number, onStatusChange: (data: OrderStatusPayload) => void): () => void {
    const instance = getEcho();
    instance.channel(`order.${orderId}`).listen('.order.status_changed', onStatusChange);

    return () => instance.leave(`order.${orderId}`);
}
