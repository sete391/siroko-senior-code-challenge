<?php

declare(strict_types=1);

namespace Siroko\Tests\Unit\Catalog\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siroko\Catalog\Domain\Exception\InsufficientStock;
use Siroko\Catalog\Domain\Product;
use Siroko\Catalog\Domain\ProductId;
use Siroko\Catalog\Domain\ProductStatus;
use Siroko\Shared\Domain\ValueObject\Money;

final class ProductTest extends TestCase
{
    private const VALID_ID   = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
    private const VALID_NAME = 'Siroko K38 Sunglasses';

    // ------------------------------------------------------------------ construction

    #[Test]
    public function it_creates_an_active_product_with_valid_data(): void
    {
        $product = $this->makeProduct();

        self::assertTrue($product->isActive());
        self::assertSame(self::VALID_NAME, $product->name());
        self::assertSame(10, $product->quantity());
    }

    #[Test]
    public function it_creates_an_inactive_product(): void
    {
        $product = $this->makeProduct(status: ProductStatus::INACTIVE);

        self::assertFalse($product->isActive());
    }

    #[Test]
    public function it_rejects_a_zero_price(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/price must be greater than zero/i');

        // Money(0) is valid; Product must reject it as its own invariant.
        $this->makeProduct(unitPrice: new Money(0));
    }

    #[Test]
    public function it_rejects_negative_stock(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/stock cannot be negative/i');

        $this->makeProduct(quantity: -1);
    }

    // ------------------------------------------------------------------ isAvailableFor

    #[Test]
    public function it_is_available_when_stock_equals_requested_quantity(): void
    {
        $product = $this->makeProduct(quantity: 5);

        self::assertTrue($product->isAvailableFor(5));
    }

    #[Test]
    public function it_is_available_when_stock_exceeds_requested_quantity(): void
    {
        $product = $this->makeProduct(quantity: 10);

        self::assertTrue($product->isAvailableFor(3));
    }

    #[Test]
    public function it_is_not_available_when_stock_is_lower_than_requested_quantity(): void
    {
        $product = $this->makeProduct(quantity: 2);

        self::assertFalse($product->isAvailableFor(3));
    }

    #[Test]
    public function it_is_not_available_when_stock_is_zero(): void
    {
        $product = $this->makeProduct(quantity: 0);

        self::assertFalse($product->isAvailableFor(1));
    }

    // ------------------------------------------------------------------ decreaseStock

    #[Test]
    public function it_decreases_stock_by_the_given_amount(): void
    {
        $product = $this->makeProduct(quantity: 10);
        $product->decreaseStock(3);

        self::assertSame(7, $product->quantity());
    }

    #[Test]
    public function it_decreases_stock_to_zero(): void
    {
        $product = $this->makeProduct(quantity: 5);
        $product->decreaseStock(5);

        self::assertSame(0, $product->quantity());
    }

    #[Test]
    public function it_updates_updated_at_when_decreasing_stock(): void
    {
        $before  = new \DateTimeImmutable('yesterday');
        $product = $this->makeProduct(quantity: 5, updatedAt: $before);

        $product->decreaseStock(1);

        self::assertGreaterThan($before, $product->updatedAt());
    }

    #[Test]
    public function it_rejects_decrease_that_would_go_below_zero(): void
    {
        $this->expectException(InsufficientStock::class);
        $this->expectExceptionMessageMatches('/insufficient stock/i');

        $product = $this->makeProduct(quantity: 3);
        $product->decreaseStock(4);
    }

    #[Test]
    public function insufficient_stock_exception_carries_product_id_and_amounts(): void
    {
        $product = $this->makeProduct(quantity: 3);

        try {
            $product->decreaseStock(5);
            self::fail('Expected InsufficientStock to be thrown.');
        } catch (InsufficientStock $e) {
            self::assertStringContainsString(self::VALID_ID, $e->getMessage());
            self::assertStringContainsString('5', $e->getMessage());
            self::assertStringContainsString('3', $e->getMessage());
        }
    }

    #[Test]
    public function it_rejects_negative_decrease_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/decrease amount cannot be negative/i');

        $product = $this->makeProduct(quantity: 10);
        $product->decreaseStock(-1);
    }

    // ------------------------------------------------------------------ restoreStock

    #[Test]
    public function it_restores_stock_by_the_given_amount(): void
    {
        $product = $this->makeProduct(quantity: 5);
        $product->restoreStock(3);

        self::assertSame(8, $product->quantity());
    }

    #[Test]
    public function it_updates_updated_at_when_restoring_stock(): void
    {
        $before  = new \DateTimeImmutable('yesterday');
        $product = $this->makeProduct(quantity: 0, updatedAt: $before);

        $product->restoreStock(2);

        self::assertGreaterThan($before, $product->updatedAt());
    }

    #[Test]
    public function it_rejects_negative_restore_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/restore amount cannot be negative/i');

        $product = $this->makeProduct(quantity: 5);
        $product->restoreStock(-1);
    }

    // ------------------------------------------------------------------ exceptions

    #[Test]
    public function product_not_found_carries_the_product_id(): void
    {
        $id        = new ProductId(self::VALID_ID);
        $exception = \Siroko\Catalog\Domain\Exception\ProductNotFound::withId($id);

        self::assertStringContainsString(self::VALID_ID, $exception->getMessage());
    }

    #[Test]
    public function product_not_active_carries_the_product_id(): void
    {
        $id        = new ProductId(self::VALID_ID);
        $exception = \Siroko\Catalog\Domain\Exception\ProductNotActive::withId($id);

        self::assertStringContainsString(self::VALID_ID, $exception->getMessage());
    }

    // ------------------------------------------------------------------ helpers

    private function makeProduct(
        string $name          = self::VALID_NAME,
        Money $unitPrice      = new Money(4500),
        int $quantity         = 10,
        ProductStatus $status = ProductStatus::ACTIVE,
        \DateTimeImmutable $updatedAt = new \DateTimeImmutable(),
    ): Product {
        return new Product(
            id:          new ProductId(self::VALID_ID),
            name:        $name,
            description: 'High-performance cycling sunglasses.',
            taxAmount:   945,
            unitPrice:   $unitPrice,
            quantity:    $quantity,
            status:      $status,
            createdAt:   new \DateTimeImmutable(),
            updatedAt:   $updatedAt,
        );
    }
}
