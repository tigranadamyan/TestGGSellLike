<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What actually happened to an incoming webhook. Distinguishing these lets the
 * endpoint answer honestly instead of collapsing every no-op into "duplicate".
 */
enum PaymentOutcome: string
{
    /** Event applied and the order changed state. */
    case Applied = 'applied';

    /** Same event_id already seen — at-least-once redelivery, no-op. */
    case Duplicate = 'duplicate';

    /** Accepted and stored, but its order does not exist yet. */
    case PendingOrder = 'pending_order';

    /** Valid but no longer relevant (order already past `created`). */
    case Ignored = 'ignored';
}
