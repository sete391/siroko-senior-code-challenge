<?php

declare(strict_types=1);

namespace Siroko\Tests\Unit\Sales\Domain\Cart;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siroko\Catalog\Domain\Exception\ProductNotActive;
use Siroko\Catalog\Domain\Exception\ProductNotFound;
use Siroko\Catalog\Domain\Product;
use Siroko\Catalog\Domain\ProductId;
use Siroko\Catalog\Domain\ProductStatus;
use Siroko\Sales\Domain\Cart\Cart;
use Siroko\Sales\Domain\Service\CheckoutCoherenceChecker;
use Siroko\Sales\Domain\Service\CoherenceIssue;
use Siroko\Sales\Domain\ValueObject\CartId;
use Siroko\Shared\Domain\ValueObject\Money;

final class CheckoutCoherenceCheckerTest extends TestCase
{
    private const CART_ID    = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
    private const PRODUCT_ID = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
    private const PID2       = 'b47ac10b-58cc-4372-a567-0e02b2c3d479';

    private CheckoutCoherenceChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new CheckoutCoherenceChecker();
    }

    // ------------------------------------------------------------------ coherent

    #[Test]
    public function it_returns_no_issues_when_everything_is_coherent(): void
    {
        $cart    = $this->cartWithItem(price: new Money(4500), tax: 945, qty: 2);
        $product = $this->product(price: new Money(4500), tax: 945, stock: 10);

        $issues = $this->checker->check($cart, [self::PRODUCT_ID => $product]);

        self::assertSame([], $issues);
    }

    // ------------------------------------------------------------------ missing product

    #[Test]
    public function it_throws_product_not_found_when_product_is_missing(): void
    {
        $this->expectException(ProductNotFound::class);

        $cart = $this->cartWithItem(price: new Money(4500), tax: 945, qty: 2);

        $this->checker->check($cart, []);
    }

    // ------------------------------------------------------------------ inactive product

    #[Test]
    public function it_throws_product_not_active_when_product_is_inactive(): void
    {
        $this->expectException(ProductNotActive::class);

        $cart    = $this->cartWithItem(price: new Money(4500), tax: 945, qty: 2);
        $product = $this->product(price: new Money(4500), tax: 945, stock: 10, status: ProductStatus::INACTIVE);

        $this->checker->check($cart, [self::PRODUCT_ID => $product]);
    }

    // ------------------------------------------------------------------ price changed

    #[Test]
    public function it_returns_price_changed_issue_and_refreshes_snapshot(): void
    {
        $oldPrice = new Money(4500);
        $newPrice = new Money(5000);

        $cart    = $this->cartWithItem(price: $oldPrice, tax: 945, qty: 2);
        $product = $this->product(price: $newPrice, tax: 945, stock: 10);

        $issues = $this->checker->check($cart, [self::PRODUCT_ID => $product]);

        self::assertCount(1, $issues);
        self::assertSame(CoherenceIssue::REASON_PRICE_CHANGED, $issues[0]->reason);
        // Snapshot must have been refreshed on the cart item
        self::assertTrue($cart->items()[0]->unitPrice()->equals($newPrice));
    }

    #[Test]
    public function it_returns_price_changed_issue_when_tax_differs(): void
    {
        $cart    = $this->cartWithItem(price: new Money(4500), tax: 945, qty: 2);
        $product = $this->product(price: new Money(4500), tax: 1050, stock: 10);

        $issues = $this->checker->check($cart, [self::PRODUCT_ID => $product]);

        self::assertCount(1, $issues);
        self::assertSame(CoherenceIssue::REASON_PRICE_CHANGED, $issues[0]->reason);
        self::assertSame(1050, $cart->items()[0]->taxAmount());
    }

    // ------------------------------------------------------------------ insufficient stock

    #[Test]
    public function it_returns_insufficient_stock_issue_when_stock_is_too_low(): void
    {
        $cart    = $this->cartWithItem(price: new Money(4500), tax: 945, qty: 5);
        $product = $this->product(price: new Money(4500), tax: 945, stock: 3);

        $issues = $this->checker->check($cart, [self::PRODUCT_ID => $product]);

        self::assertCount(1, $issues);
        self::assertSame(CoherenceIssue::REASON_INSUFFICIENT_STOCK, $issues[0]->reason);
    }

    #[Test]
    public function it_is_coherent_when_stock_exactly_equals_quantity(): void
    {
        $cart    = $this->cartWithItem(price: new Money(4500), tax: 945, qty: 5);
        $product = $this->product(price: new Money(4500), tax: 945, stock: 5);

        $issues = $this->checker->check($cart, [self::PRODUCT_ID => $product]);

        self::assertSame([], $issues);
    }

    // ------------------------------------------------------------------ price change takes priority over stock

    #[Test]
    public function price_change_is_reported_even_when_stock_would_also_fail(): void
    {
        $cart    = $this->cartWithItem(price: new Money(4500), tax: 945, qty: 5);
        $product = $this->product(price: new Money(5000), tax: 945, stock: 2);

        $issues = $this->checker->check($cart, [self::PRODUCT_ID => $product]);

        self::assertCount(1, $issues);
        self::assertSame(CoherenceIssue::REASON_PRICE_CHANGED, $issues[0]->reason);
    }

    // ------------------------------------------------------------------ multiple items

    #[Test]
    public function it_checks_all_items_independently(): void
    {
        $cart = Cart::create(new CartId(self::CART_ID), null);
        $cart->addItem(new ProductId(self::PRODUCT_ID), new Money(4500), 945, 2, 10);
        $cart->addItem(new ProductId(self::PID2), new Money(2000), 420, 3, 10);

        $p1 = $this->product(price: new Money(4500), tax: 945, stock: 10);
        $p2 = $this->product(price: new Money(2500), tax: 420, stock: 10, id: self::PID2);

        $issues = $this->checker->check($cart, [
            self::PRODUCT_ID => $p1,
            self::PID2       => $p2,
        ]);

        self::assertCount(1, $issues);
        self::assertSame(self::PID2, $issues[0]->productId->value());
    }

    // ------------------------------------------------------------------ helpers

    private function cartWithItem(Money $price, int $tax, int $qty): Cart
    {
        $cart = Cart::create(new CartId(self::CART_ID), null);
        $cart->addItem(new ProductId(self::PRODUCT_ID), $price, $tax, $qty, 100);

        return $cart;
    }

    private function product(
        Money $price,
        int $tax,
        int $stock,
        ProductStatus $status = ProductStatus::ACTIVE,
        string $id = self::PRODUCT_ID,
    ): Product {
        return new Product(
            id:          new ProductId($id),
            name:        'Test Product',
            description: 'desc',
            taxAmount:   $tax,
            unitPrice:   $price,
            quantity:    $stock,
            status:      $status,
            createdAt:   new \DateTimeImmutable(),
            updatedAt:   new \DateTimeImmutable(),
        );
    }
}
