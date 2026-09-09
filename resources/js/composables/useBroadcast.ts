import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Make Pusher available globally for Laravel Echo
(window as any).Pusher = Pusher;

let echo: Echo | null = null;

/**
 * Get or create the Echo instance (singleton).
 */
export function getEcho(): Echo {
    if (!echo) {
        echo = new Echo({
            broadcaster: 'reverb',
            key: import.meta.env.VITE_REVERB_APP_KEY,
            wsHost: import.meta.env.VITE_REVERB_HOST || 'localhost',
            wsPort: parseInt(import.meta.env.VITE_REVERB_PORT || '8080', 10),
            wssPort: parseInt(import.meta.env.VITE_REVERB_PORT || '8080', 10),
            forceTLS: (import.meta.env.VITE_REVERB_SCHEME || 'http') === 'https',
            enabledTransports: ['ws', 'wss'],
        });
    }
    return echo;
}

/**
 * Subscribe to the catalog channel and listen for updates.
 *
 * @param onUpdate - Callback fired when a product is updated
 * @returns Cleanup function to unsubscribe
 */
export function subscribeToCatalog(
    onUpdate: (data: {
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
    }) => void,
): () => void {
    const echo = getEcho();

    const channel = echo.channel('catalog');

    channel.listen('.catalog.updated', (data: any) => {
        onUpdate(data);
    });

    // Return cleanup function
    return () => {
        echo.leave('catalog');
    };
}

/**
 * Subscribe to a specific order's status changes.
 *
 * @param orderId - The order ID to listen for
 * @param onStatusChange - Callback fired when order status changes
 * @returns Cleanup function to unsubscribe
 */
export function subscribeToOrder(
    orderId: number,
    onStatusChange: (data: {
        order_id: number;
        sku: string;
        old_status: string;
        new_status: string;
        timestamp: string;
    }) => void,
): () => void {
    const echo = getEcho();

    const channel = echo.channel(`order.${orderId}`);

    channel.listen('.order.status_changed', (data: any) => {
        onStatusChange(data);
    });

    // Return cleanup function
    return () => {
        echo.leave(`order.${orderId}`);
    };
}
