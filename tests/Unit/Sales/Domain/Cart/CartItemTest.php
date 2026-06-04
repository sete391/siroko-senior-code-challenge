<?php

declare(strict_types=1);

namespace Siroko\Tests\Unit\Sales\Domain\Cart;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siroko\Catalog\Domain\ProductId;
use Siroko\Sales\Domain\Cart\CartItem;
use Siroko\Shared\Domain\ValueObject\Money;
use Siroko\Shared\Domain\ValueObject\Quantity;

final class CartItemTest extends TestCase
{
    private const PRODUCT_ID = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';

    // ------------------------------------------------------------------ construction / accessors

    #[Test]
    public function it_exposes_its_snapshot_fields(): void
    {
        $price = new Money(4500);
        $item  = $this->item($price, tax: 945, qty: 2);

        self::assertSame(self::PRODUCT_ID, $item->productId()->value());
        self::assertTrue($item->unitPrice()->equals($price));
        self::assertSame(945, $item->taxAmount());
    }

    #[Test]
    public function quantity_returns_the_int_value_of_the_quantity_vo(): void
    {
        $item = $this->item(qty: 3);

        self::assertSame(3, $item->quantity());
    }

    #[Test]
    public function quantity_value_returns_the_quantity_value_object(): void
    {
        $item = $this->item(qty: 3);

        self::assertInstanceOf(Quantity::class, $item->quantityValue());
        self::assertSame(3, $item->quantityValue()->value);
    }

    // ------------------------------------------------------------------ snapshot isolation

    #[Test]
    public function update_quantity_changes_only_the_quantity(): void
    {
        $price = new Money(4500);
        $item  = $this->item($price, tax: 945, qty: 2);

        $item->updateQuantity(new Quantity(5));

        self::assertSame(5, $item->quantity());
        // Price/tax snapshot untouched
        self::assertTrue($item->unitPrice()->equals($price));
        self::assertSame(945, $item->taxAmount());
    }

    #[Test]
    public function refresh_snapshot_changes_only_price_and_tax(): void
    {
        $item     = $this->item(new Money(4500), tax: 945, qty: 2);
        $newPrice = new Money(5000);

        $item->refreshSnapshot($newPrice, 1050);

        self::assertTrue($item->unitPrice()->equals($newPrice));
        self::assertSame(1050, $item->taxAmount());
        // Quantity untouched
        self::assertSame(2, $item->quantity());
    }

    // ------------------------------------------------------------------ helpers

    private function item(Money $unitPrice = new Money(4500), int $tax = 945, int $qty = 1): CartItem
    {
        return new CartItem(
            productId: new ProductId(self::PRODUCT_ID),
            unitPrice: $unitPrice,
            taxAmount: $tax,
            quantity:  new Quantity($qty),
        );
    }
}
