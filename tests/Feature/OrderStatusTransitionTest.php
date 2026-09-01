<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_created_can_transition_to_paid_and_payment_failed(): void
    {
        $this->assertTrue(OrderStatus::Created->canTransitionTo(OrderStatus::Paid));
        $this->assertTrue(OrderStatus::Created->canTransitionTo(OrderStatus::PaymentFailed));
        $this->assertFalse(OrderStatus::Created->canTransitionTo(OrderStatus::Delivering));
        $this->assertFalse(OrderStatus::Created->canTransitionTo(OrderStatus::Delivered));
    }

    public function test_paid_can_only_transition_to_delivering(): void
    {
        $this->assertTrue(OrderStatus::Paid->canTransitionTo(OrderStatus::Delivering));
        $this->assertFalse(OrderStatus::Paid->canTransitionTo(OrderStatus::Created));
        $this->assertFalse(OrderStatus::Paid->canTransitionTo(OrderStatus::Delivered));
        $this->assertFalse(OrderStatus::Paid->canTransitionTo(OrderStatus::PaymentFailed));
    }

    public function test_delivering_can_transition_to_delivered_out_of_stock_or_delivery_failed(): void
    {
        $this->assertTrue(OrderStatus::Delivering->canTransitionTo(OrderStatus::Delivered));
        $this->assertTrue(OrderStatus::Delivering->canTransitionTo(OrderStatus::OutOfStock));
        $this->assertTrue(OrderStatus::Delivering->canTransitionTo(OrderStatus::DeliveryFailed));
        $this->assertFalse(OrderStatus::Delivering->canTransitionTo(OrderStatus::Paid));
    }

    public function test_delivered_is_terminal(): void
    {
        $this->assertEmpty(OrderStatus::Delivered->allowedTransitions());
    }

    public function test_payment_failed_is_terminal(): void
    {
        $this->assertEmpty(OrderStatus::PaymentFailed->allowedTransitions());
    }

    public function test_out_of_stock_can_transition_to_delivering(): void
    {
        $this->assertTrue(OrderStatus::OutOfStock->canTransitionTo(OrderStatus::Delivering));
        $this->assertFalse(OrderStatus::OutOfStock->canTransitionTo(OrderStatus::Delivered));
    }

    public function test_delivery_failed_can_transition_to_delivering(): void
    {
        $this->assertTrue(OrderStatus::DeliveryFailed->canTransitionTo(OrderStatus::Delivering));
        $this->assertFalse(OrderStatus::DeliveryFailed->canTransitionTo(OrderStatus::Delivered));
    }
}
