<?php

declare(strict_types=1);

namespace Siroko\Tests\Unit\Sales\Domain\Cart;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siroko\Catalog\Domain\ProductId;
use Siroko\Sales\Domain\Cart\Cart;
use Siroko\Sales\Domain\Cart\CartStatus;
use Siroko\Sales\Domain\Cart\Event\CartCheckedOut;
use Siroko\Sales\Domain\Cart\Event\CartItemAdded;
use Siroko\Sales\Domain\Cart\Event\CartItemQuantityUpdated;
use Siroko\Sales\Domain\Cart\Event\CartItemRemoved;
use Siroko\Sales\Domain\Exception\CartItemNotFound;
use Siroko\Sales\Domain\Exception\CartNotModifiable;
use Siroko\Sales\Domain\Exception\CartOwnershipMismatch;
use Siroko\Sales\Domain\Exception\InsufficientStock;
use Siroko\Sales\Domain\Exception\InvalidQuantity;
use Siroko\Sales\Domain\ValueObject\CartId;
use Siroko\Sales\Domain\ValueObject\CustomerId;
use Siroko\Shared\Domain\ValueObject\Money;

final class CartTest extends TestCase
{
    private const CART_ID     = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
    private const PRODUCT_ID  = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
    private const PRODUCT_ID2 = 'b47ac10b-58cc-4372-a567-0e02b2c3d479';
    private const CUSTOMER_ID = 'c47ac10b-58cc-4372-a567-0e02b2c3d479';

    // ------------------------------------------------------------------ creation

    #[Test]
    public function it_creates_an_open_guest_cart(): void
    {
        $cart = Cart::create(new CartId(self::CART_ID), null);

        self::assertSame(CartStatus::OPEN, $cart->status());
        self::assertNull($cart->customerId());
        self::assertTrue($cart->isEmpty());
    }

    #[Test]
    public function it_creates_a_cart_with_a_customer(): void
    {
        $customerId = new CustomerId(self::CUSTOMER_ID);
        $cart       = Cart::create(new CartId(self::CART_ID), $customerId);

        self::assertNotNull($cart->customerId());
        self::assertTrue($cart->customerId()->equals($customerId));
    }

    // ------------------------------------------------------------------ addItem

    #[Test]
    public function it_adds_a_new_item(): void
    {
        $cart = $this->openCart();
        $cart->addItem($this->pid(), new Money(4500), 945, 2, 10);

        self::assertCount(1, $cart->items());
        self::assertSame(2, $cart->items()[0]->quantity());
        self::assertFalse($cart->isEmpty());
    }

    #[Test]
    public function it_merges_quantity_when_the_same_product_is_added_twice(): void
    {
        $cart = $this->openCart();
        $cart->addItem($this->pid(), new Money(4500), 945, 2, 10);
        $cart->addItem($this->pid(), new Money(4500), 945, 3, 10);

        self::assertCount(1, $cart->items());
        self::assertSame(5, $cart->items()[0]->quantity());
    }

    #[Test]
    public function it_records_cart_item_quantity_updated_on_merge(): void
    {
        $cart = $this->openCart();
        $cart->addItem($this->pid(), new Money(4500), 945, 2, 10);
        $cart->releaseEvents();

        $cart->addItem($this->pid(), new Money(4500), 945, 1, 10);
        $events = $cart->releaseEvents();

        self::assertInstanceOf(CartItemQuantityUpdated::class, $events[0]);
    }

    #[Test]
    public function it_captures_snapshot_when_adding_an_item(): void
    {
        $price = new Money(4500);
        $cart  = $this->openCart();
        $cart->addItem($this->pid(), $price, 945, 1, 10);

        $item = $cart->items()[0];
        self::assertTrue($item->unitPrice()->equals($price));
        self::assertSame(945, $item->taxAmount());
    }

    #[Test]
    public function it_rejects_add_with_quantity_zero(): void
    {
        $this->expectException(InvalidQuantity::class);

        $cart = $this->openCart();
        $cart->addItem($this->pid(), new Money(4500), 945, 0, 10);
    }

    #[Test]
    public function it_rejects_add_with_negative_quantity(): void
    {
        $this->expectException(InvalidQuantity::class);

        $cart = $this->openCart();
        $cart->addItem($this->pid(), new Money(4500), 945, -1, 10);
    }

    #[Test]
    public function it_rejects_add_when_quantity_exceeds_stock(): void
    {
        $this->expectException(InsufficientStock::class);

        $cart = $this->openCart();
        $cart->addItem($this->pid(), new Money(4500), 945, 6, 5);
    }

    #[Test]
    public function it_rejects_add_when_merged_quantity_exceeds_stock(): void
    {
        $this->expectException(InsufficientStock::class);

        $cart = $this->openCart();
        $cart->addItem($this->pid(), new Money(4500), 945, 4, 5);
        $cart->addItem($this->pid(), new Money(4500), 945, 2, 5);
    }

    #[Test]
    public function it_records_cart_item_added_event(): void
    {
        $cart = $this->openCart();
        $cart->addItem($this->pid(), new Money(4500), 945, 1, 10);

        $events = $cart->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(CartItemAdded::class, $events[0]);
    }

    // ------------------------------------------------------------------ updateItem

    #[Test]
    public function it_sets_quantity_absolutely_on_update(): void
    {
        $cart = $this->openCart();
        $cart->addItem($this->pid(), new Money(4500), 945, 2, 10);
        $cart->releaseEvents();

        $cart->updateItem($this->pid(), new Money(4500), 945, 7, 10);

        self::assertSame(7, $cart->items()[0]->quantity());
    }

    #[Test]
    public function it_removes_item_when_update_quantity_is_zero(): void
    {
        $cart = $this->openCart();
        $cart->addItem($this->pid(), new Money(4500), 945, 2, 10);

        $cart->updateItem($this->pid(), new Money(4500), 945, 0, 10);

        self::assertCount(0, $cart->items());
    }

    #[Test]
    public function it_adds_new_item_when_updating_a_non_existent_line(): void
    {
        $cart = $this->openCart();
        $cart->updateItem($this->pid(), new Money(4500), 945, 3, 10);

        self::assertCount(1, $cart->items());
        self::assertSame(3, $cart->items()[0]->quantity());
    }

    #[Test]
    public function it_does_nothing_when_updating_zero_for_non_existent_line(): void
    {
        $cart = $this->openCart();
        $cart->updateItem($this->pid(), new Money(4500), 945, 0, 10);

        self::assertTrue($cart->isEmpty());
    }

    #[Test]
    public function it_rejects_update_with_negative_quantity(): void
    {
        $this->expectException(InvalidQuantity::class);

        $cart = $this->openCart();
        $cart->updateItem($this->pid(), new Money(4500), 945, -1, 10);
    }

    #[Test]
    public function it_rejects_update_when_quantity_exceeds_stock(): void
    {
        $this->expectException(InsufficientStock::class);

        $cart = $this->openCart();
        $cart->updateItem($this->pid(), new Money(4500), 945, 6, 5);
    }

    // ------------------------------------------------------------------ removeItem

    #[Test]
    public function it_removes_an_existing_item(): void
    {
        $cart = $this->openCart();
        $cart->addItem($this->pid(), new Money(4500), 945, 2, 10);
        $cart->releaseEvents();

        $cart->removeItem($this->pid());

        self::assertTrue($cart->isEmpty());
    }

    #[Test]
    public function it_records_cart_item_removed_event(): void
    {
        $cart = $this->openCart();
        $cart->addItem($this->pid(), new Money(4500), 945, 1, 10);
        $cart->releaseEvents();

        $cart->removeItem($this->pid());
        $events = $cart->releaseEvents();

        self::assertInstanceOf(CartItemRemoved::class, $events[0]);
    }

    #[Test]
    public function it_throws_when_removing_a_non_existent_item(): void
    {
        $this->expectException(CartItemNotFound::class);

        $cart = $this->openCart();
        $cart->removeItem($this->pid());
    }

    // ------------------------------------------------------------------ markCheckedOut / reopen

    #[Test]
    public function it_marks_the_cart_as_checked_out(): void
    {
        $cart = $this->openCart();
        $cart->markCheckedOut();

        self::assertSame(CartStatus::CHECKED_OUT, $cart->status());
    }

    #[Test]
    public function it_records_cart_checked_out_event(): void
    {
        $cart = $this->openCart();
        $cart->markCheckedOut();
        $events = $cart->releaseEvents();

        self::assertInstanceOf(CartCheckedOut::class, $events[0]);
    }

    #[Test]
    public function it_reopens_a_checked_out_cart(): void
    {
        $cart = $this->openCart();
        $cart->markCheckedOut();
        $cart->reopen();

        self::assertSame(CartStatus::OPEN, $cart->status());
    }

    #[Test]
    public function a_checked_out_cart_rejects_add_item(): void
    {
        $this->expectException(CartNotModifiable::class);

        $cart = $this->openCart();
        $cart->markCheckedOut();
        $cart->addItem($this->pid(), new Money(4500), 945, 1, 10);
    }

    #[Test]
    public function a_checked_out_cart_rejects_remove_item(): void
    {
        $this->expectException(CartNotModifiable::class);

        $cart = $this->openCart();
        $cart->addItem($this->pid(), new Money(4500), 945, 1, 10);
        $cart->markCheckedOut();
        $cart->removeItem($this->pid());
    }

    #[Test]
    public function a_reopened_cart_accepts_modifications(): void
    {
        $cart = $this->openCart();
        $cart->markCheckedOut();
        $cart->reopen();
        $cart->addItem($this->pid(), new Money(4500), 945, 1, 10);

        self::assertCount(1, $cart->items());
    }

    // ------------------------------------------------------------------ ownership

    #[Test]
    public function two_guests_own_the_same_guest_cart(): void
    {
        $cart = Cart::create(new CartId(self::CART_ID), null);

        $cart->assertOwnedBy(null);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function the_right_customer_owns_their_cart(): void
    {
        $customerId = new CustomerId(self::CUSTOMER_ID);
        $cart       = Cart::create(new CartId(self::CART_ID), $customerId);

        $cart->assertOwnedBy($customerId);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_different_customer_is_rejected(): void
    {
        $this->expectException(CartOwnershipMismatch::class);

        $cart  = Cart::create(new CartId(self::CART_ID), new CustomerId(self::CUSTOMER_ID));
        $other = new CustomerId('d47ac10b-58cc-4372-a567-0e02b2c3d479');
        $cart->assertOwnedBy($other);
    }

    #[Test]
    public function a_guest_is_rejected_from_a_customer_cart(): void
    {
        $this->expectException(CartOwnershipMismatch::class);

        $cart = Cart::create(new CartId(self::CART_ID), new CustomerId(self::CUSTOMER_ID));
        $cart->assertOwnedBy(null);
    }

    #[Test]
    public function a_customer_is_rejected_from_a_guest_cart(): void
    {
        $this->expectException(CartOwnershipMismatch::class);

        $cart = Cart::create(new CartId(self::CART_ID), null);
        $cart->assertOwnedBy(new CustomerId(self::CUSTOMER_ID));
    }

    // ------------------------------------------------------------------ snapshot refresh

    #[Test]
    public function it_refreshes_item_snapshot(): void
    {
        $cart     = $this->openCart();
        $newPrice = new Money(5000);
        $cart->addItem($this->pid(), new Money(4500), 945, 1, 10);

        $cart->refreshItemSnapshot($this->pid(), $newPrice, 1050);

        self::assertTrue($cart->items()[0]->unitPrice()->equals($newPrice));
        self::assertSame(1050, $cart->items()[0]->taxAmount());
    }

    // ------------------------------------------------------------------ multiple items

    #[Test]
    public function it_manages_two_independent_product_lines(): void
    {
        $cart = $this->openCart();
        $cart->addItem($this->pid(), new Money(4500), 945, 2, 10);
        $cart->addItem($this->pid2(), new Money(2000), 420, 1, 5);

        self::assertCount(2, $cart->items());
    }

    // ------------------------------------------------------------------ helpers

    private function openCart(): Cart
    {
        return Cart::create(new CartId(self::CART_ID), null);
    }

    private function pid(): ProductId
    {
        return new ProductId(self::PRODUCT_ID);
    }

    private function pid2(): ProductId
    {
        return new ProductId(self::PRODUCT_ID2);
    }
}
