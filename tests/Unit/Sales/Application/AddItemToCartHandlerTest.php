<?php

declare(strict_types=1);

namespace Siroko\Tests\Unit\Sales\Application;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Siroko\Catalog\Domain\Exception\ProductNotActive;
use Siroko\Catalog\Domain\Exception\ProductNotFound;
use Siroko\Catalog\Domain\ProductRepository;
use Siroko\Sales\Application\Command\AddItemToCart\AddItemToCartCommand;
use Siroko\Sales\Application\Command\AddItemToCart\AddItemToCartHandler;
use Siroko\Sales\Application\DTO\CartView;
use Siroko\Sales\Domain\Cart\CartRepository;
use Siroko\Sales\Domain\Exception\InsufficientStock;
use Siroko\Sales\Domain\Exception\InvalidQuantity;

final class AddItemToCartHandlerTest extends TestCase
{
    use SalesApplicationTestHelpers;

    private const CART_ID    = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
    private const PRODUCT_ID = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
    private const ORDER_ID   = 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';

    #[Test]
    public function it_creates_cart_and_adds_item_when_cart_does_not_exist(): void
    {
        [$cartRepo, $productRepo] = $this->repos(cartExists: false);

        $view = $this->handle($cartRepo, $productRepo, quantity: 2);

        self::assertInstanceOf(CartView::class, $view);
        self::assertSame(self::CART_ID, $view->cartId);
        self::assertCount(1, $view->items);
    }

    #[Test]
    public function it_adds_item_to_existing_cart(): void
    {
        [$cartRepo, $productRepo] = $this->repos(cartExists: true);

        $view = $this->handle($cartRepo, $productRepo, quantity: 1);

        // cart already had 2 units → now 3 after adding 1
        self::assertSame(3, $view->items[0]->quantity);
    }

    #[Test]
    public function it_computes_totals_in_returned_cart_view(): void
    {
        [$cartRepo, $productRepo] = $this->repos(cartExists: false);

        $view = $this->handle($cartRepo, $productRepo, quantity: 2);

        self::assertSame(9000, $view->totalProductsAmount);   // 2 × 4500
        self::assertSame(1890, $view->totalTaxAmount);        // 2 × 945
        self::assertSame(10890, $view->totalOrderAmount);
    }

    #[Test]
    public function it_throws_product_not_found_when_product_is_missing(): void
    {
        $this->expectException(ProductNotFound::class);

        [$cartRepo, $productRepo] = $this->repos(productExists: false);
        $this->handle($cartRepo, $productRepo);
    }

    #[Test]
    public function it_throws_product_not_active_when_product_is_inactive(): void
    {
        $this->expectException(ProductNotActive::class);

        [$cartRepo, $productRepo] = $this->repos(productActive: false);
        $this->handle($cartRepo, $productRepo);
    }

    #[Test]
    public function it_throws_insufficient_stock_when_quantity_exceeds_stock(): void
    {
        $this->expectException(InsufficientStock::class);

        [$cartRepo, $productRepo] = $this->repos(cartExists: false, stock: 1);
        $this->handle($cartRepo, $productRepo, quantity: 5);
    }

    #[Test]
    public function it_throws_invalid_quantity_for_zero(): void
    {
        $this->expectException(InvalidQuantity::class);

        [$cartRepo, $productRepo] = $this->repos(cartExists: false);
        $this->handle($cartRepo, $productRepo, quantity: 0);
    }

    #[Test]
    public function it_saves_the_cart(): void
    {
        [$cartRepo, $productRepo] = $this->repos(cartExists: false);
        $cartRepo->expects($this->once())->method('save');

        $this->handle($cartRepo, $productRepo);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array{CartRepository&MockObject, ProductRepository&MockObject}
     */
    private function repos(
        bool $cartExists = true,
        bool $productExists = true,
        bool $productActive = true,
        int $stock = 10,
    ): array {
        $cartRepo = $this->createMock(CartRepository::class);
        $cartRepo->method('findById')->willReturn(
            $cartExists ? $this->cartWithItem() : null,
        );

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findById')->willReturn(
            !$productExists ? null :
            ($productActive ? $this->activeProduct(stock: $stock) : $this->inactiveProduct()),
        );

        return [$cartRepo, $productRepo];
    }

    private function handle(
        CartRepository $cartRepo,
        ProductRepository $productRepo,
        int $quantity = 1,
    ): CartView {
        return (new AddItemToCartHandler($cartRepo, $productRepo))(
            new AddItemToCartCommand(self::CART_ID, null, self::PRODUCT_ID, $quantity),
        );
    }
}
