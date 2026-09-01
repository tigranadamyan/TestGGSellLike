<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatus: string
{
    case Created = 'created';
    case Paid = 'paid';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case PaymentFailed = 'payment_failed';
    case OutOfStock = 'out_of_stock';
    case DeliveryFailed = 'delivery_failed';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Created => [self::Paid, self::PaymentFailed],
            self::Paid => [self::Delivering],
            self::Delivering => [self::Delivered, self::OutOfStock, self::DeliveryFailed],
            self::Delivered => [],
            self::PaymentFailed => [],
            self::OutOfStock => [self::Delivering],
            self::DeliveryFailed => [self::Delivering],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
