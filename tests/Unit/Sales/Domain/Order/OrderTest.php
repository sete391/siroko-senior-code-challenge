<?php

declare(strict_types=1);

namespace Siroko\Tests\Unit\Sales\Domain\Order;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siroko\Catalog\Domain\ProductId;
use Siroko\Sales\Domain\Cart\Cart;
use Siroko\Sales\Domain\Exception\EmptyCartCannotCheckout;
use Siroko\Sales\Domain\Exception\OrderNotPending;
use Siroko\Sales\Domain\Exception\OrderOwnershipMismatch;
use Siroko\Sales\Domain\Order\Event\OrderPaymentConfirmed;
use Siroko\Sales\Domain\Order\Event\OrderPaymentFailed;
use Siroko\Sales\Domain\Order\Event\OrderPlaced;
use Siroko\Sales\Domain\Order\Order;
use Siroko\Sales\Domain\Order\OrderStatus;
use Siroko\Sales\Domain\ValueObject\CartId;
use Siroko\Sales\Domain\ValueObject\CustomerId;
use Siroko\Sales\Domain\ValueObject\OrderId;
use Siroko\Sales\Domain\ValueObject\ShippingAddress;
use Siroko\Shared\Domain\ValueObject\Money;

final class OrderTest extends TestCase
{
    private const ORDER_ID    = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
    private const CART_ID     = 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
    private const PRODUCT_ID  = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
    private const PRODUCT_ID2 = 'e47ac10b-58cc-4372-a567-0e02b2c3d479';
    private const CUSTOMER_ID = 'c47ac10b-58cc-4372-a567-0e02b2c3d479';

    // ------------------------------------------------------------------ fromCart

    #[Test]
    public function it_creates_a_pending_order_from_a_cart(): void
    {
        $cart  = $this->cartWithOneItem();
        $order = Order::fromCart(new OrderId(self::ORDER_ID), $cart, $this->address());

        self::assertSame(OrderStatus::PENDING, $order->status());
    }

    #[Test]
    public function it_copies_cart_items_as_order_items(): void
    {
        $cart  = $this->cartWithOneItem();
        $order = Order::fromCart(new OrderId(self::ORDER_ID), $cart, $this->address());

        self::assertCount(1, $order->items());
        self::assertSame(self::PRODUCT_ID, $order->items()[0]->productId()->value());
        self::assertSame(2, $order->items()[0]->quantity());
    }

    #[Test]
    public function it_computes_totals_correctly_for_one_item(): void
    {
        // 2 units × 4500 = 9000 products; 2 × 945 = 1890 tax; total = 10890
        $cart  = $this->cartWithOneItem();
        $order = Order::fromCart(new OrderId(self::ORDER_ID), $cart, $this->address());

        self::assertSame(9000, $order->totalProducts()->amount);
        self::assertSame(1890, $order->totalTax()->amount);
        self::assertSame(10890, $order->totalOrder()->amount);
    }

    #[Test]
    public function it_computes_totals_correctly_for_multiple_items(): void
    {
        $cart = $this->cartWith(
            [self::PRODUCT_ID,  new Money(4500), 945, 2, 10],
            [self::PRODUCT_ID2, new Money(2000), 420, 3, 10],
        );
        $order = Order::fromCart(new OrderId(self::ORDER_ID), $cart, $this->address());

        // products: 2×4500 + 3×2000 = 9000 + 6000 = 15000
        // tax:      2×945  + 3×420  = 1890 + 1260 = 3150
        // total:    15000 + 3150 = 18150
        self::assertSame(15000, $order->totalProducts()->amount);
        self::assertSame(3150, $order->totalTax()->amount);
        self::assertSame(18150, $order->totalOrder()->amount);
    }

    #[Test]
    public function it_stores_the_cart_id_for_traceability(): void
    {
        $cart  = $this->cartWithOneItem();
        $order = Order::fromCart(new OrderId(self::ORDER_ID), $cart, $this->address());

        self::assertSame(self::CART_ID, $order->cartId()->value());
    }

    #[Test]
    public function it_records_order_placed_event(): void
    {
        $cart  = $this->cartWithOneItem();
        $order = Order::fromCart(new OrderId(self::ORDER_ID), $cart, $this->address());

        $events = $order->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(OrderPlaced::class, $events[0]);
    }

    #[Test]
    public function it_rejects_checkout_of_an_empty_cart(): void
    {
        $this->expectException(EmptyCartCannotCheckout::class);

        $cart = Cart::create(new CartId(self::CART_ID), null);
        Order::fromCart(new OrderId(self::ORDER_ID), $cart, $this->address());
    }

    // ------------------------------------------------------------------ confirmPayment

    #[Test]
    public function it_confirms_a_pending_payment(): void
    {
        $order = $this->pendingOrder();
        $order->confirmPayment();

        self::assertSame(OrderStatus::PAYMENT_CONFIRMED, $order->status());
    }

    #[Test]
    public function it_records_payment_confirmed_event(): void
    {
        $order = $this->pendingOrder();
        $order->releaseEvents();

        $order->confirmPayment();
        $events = $order->releaseEvents();

        self::assertInstanceOf(OrderPaymentConfirmed::class, $events[0]);
    }

    #[Test]
    public function it_rejects_confirm_when_already_confirmed(): void
    {
        $this->expectException(OrderNotPending::class);

        $order = $this->pendingOrder();
        $order->confirmPayment();
        $order->confirmPayment();
    }

    // ------------------------------------------------------------------ failPayment

    #[Test]
    public function it_fails_a_pending_payment(): void
    {
        $order = $this->pendingOrder();
        $order->failPayment();

        self::assertSame(OrderStatus::PAYMENT_ERROR, $order->status());
    }

    #[Test]
    public function it_records_payment_failed_event(): void
    {
        $order = $this->pendingOrder();
        $order->releaseEvents();

        $order->failPayment();
        $events = $order->releaseEvents();

        self::assertInstanceOf(OrderPaymentFailed::class, $events[0]);
    }

    #[Test]
    public function it_rejects_fail_when_already_failed(): void
    {
        $this->expectException(OrderNotPending::class);

        $order = $this->pendingOrder();
        $order->failPayment();
        $order->failPayment();
    }

    #[Test]
    public function it_rejects_fail_when_already_confirmed(): void
    {
        $this->expectException(OrderNotPending::class);

        $order = $this->pendingOrder();
        $order->confirmPayment();
        $order->failPayment();
    }

    // ------------------------------------------------------------------ ownership

    #[Test]
    public function the_right_customer_owns_their_order(): void
    {
        $customerId = new CustomerId(self::CUSTOMER_ID);
        $cart       = Cart::create(new CartId(self::CART_ID), $customerId);
        $cart->addItem(new ProductId(self::PRODUCT_ID), new Money(4500), 945, 2, 10);
        $order = Order::fromCart(new OrderId(self::ORDER_ID), $cart, $this->address());

        $order->assertOwnedBy($customerId);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_different_customer_is_rejected_from_an_order(): void
    {
        $this->expectException(OrderOwnershipMismatch::class);

        $cart = Cart::create(new CartId(self::CART_ID), new CustomerId(self::CUSTOMER_ID));
        $cart->addItem(new ProductId(self::PRODUCT_ID), new Money(4500), 945, 2, 10);
        $order = Order::fromCart(new OrderId(self::ORDER_ID), $cart, $this->address());

        $order->assertOwnedBy(new CustomerId('d47ac10b-58cc-4372-a567-0e02b2c3d479'));
    }

    // ------------------------------------------------------------------ helpers

    private function cartWithOneItem(): Cart
    {
        return $this->cartWith(
            [self::PRODUCT_ID, new Money(4500), 945, 2, 10],
        );
    }

    /** @param array{string, Money, int, int, int} ...$items */
    private function cartWith(array ...$items): Cart
    {
        $cart = Cart::create(new CartId(self::CART_ID), null);

        foreach ($items as [$pid, $price, $tax, $qty, $stock]) {
            $cart->addItem(new ProductId($pid), $price, $tax, $qty, $stock);
        }

        return $cart;
    }

    private function pendingOrder(): Order
    {
        $cart = $this->cartWithOneItem();

        return Order::fromCart(new OrderId(self::ORDER_ID), $cart, $this->address());
    }

    private function address(): ShippingAddress
    {
        return new ShippingAddress(
            firstName: 'Ada',
            lastName:  'Lovelace',
            vatNumber: 'ES12345678Z',
            street:    'Calle Mayor 1',
            city:      'Madrid',
            state:     'Madrid',
            zipCode:   '28013',
            country:   'ES',
        );
    }
}
