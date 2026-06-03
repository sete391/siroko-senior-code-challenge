<?php

declare(strict_types=1);

namespace Siroko\Tests\Unit\Sales\Application;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siroko\Catalog\Domain\Exception\ProductNotActive;
use Siroko\Catalog\Domain\Exception\ProductNotFound;
use Siroko\Catalog\Domain\ProductRepository;
use Siroko\Sales\Application\Command\UpdateCartItem\UpdateCartItemCommand;
use Siroko\Sales\Application\Command\UpdateCartItem\UpdateCartItemHandler;
use Siroko\Sales\Application\DTO\CartView;
use Siroko\Sales\Domain\Cart\CartRepository;
use Siroko\Sales\Domain\Exception\CartNotFound;
use Siroko\Sales\Domain\Exception\InsufficientStock;
use Siroko\Sales\Domain\Exception\InvalidQuantity;

final class UpdateCartItemHandlerTest extends TestCase
{
    use SalesApplicationTestHelpers;

    private const CART_ID    = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
    private const PRODUCT_ID = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
    private const ORDER_ID   = 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';

    #[Test]
    public function it_updates_item_quantity_absolutely(): void
    {
        [$cartRepo, $productRepo] = $this->repos();

        $view = $this->handle($cartRepo, $productRepo, quantity: 5);

        self::assertInstanceOf(CartView::class, $view);
        self::assertSame(5, $view->items[0]->quantity);
    }

    #[Test]
    public function it_removes_item_when_quantity_is_zero(): void
    {
        [$cartRepo, $productRepo] = $this->repos();

        $view = $this->handle($cartRepo, $productRepo, quantity: 0);

        self::assertCount(0, $view->items);
    }

    #[Test]
    public function it_throws_cart_not_found_when_cart_is_missing(): void
    {
        $this->expectException(CartNotFound::class);

        [$cartRepo, $productRepo] = $this->repos(cartExists: false);
        $this->handle($cartRepo, $productRepo);
    }

    #[Test]
    public function it_throws_product_not_found_when_product_is_missing(): void
    {
        $this->expectException(ProductNotFound::class);

        [$cartRepo, $productRepo] = $this->repos(productExists: false);
        $this->handle($cartRepo, $productRepo, quantity: 3);
    }

    #[Test]
    public function it_throws_product_not_active_when_product_is_inactive(): void
    {
        $this->expectException(ProductNotActive::class);

        [$cartRepo, $productRepo] = $this->repos(productActive: false);
        $this->handle($cartRepo, $productRepo, quantity: 3);
    }

    #[Test]
    public function it_throws_insufficient_stock_when_quantity_exceeds_stock(): void
    {
        $this->expectException(InsufficientStock::class);

        [$cartRepo, $productRepo] = $this->repos(stock: 2);
        $this->handle($cartRepo, $productRepo, quantity: 5);
    }

    #[Test]
    public function it_throws_invalid_quantity_for_negative_value(): void
    {
        $this->expectException(InvalidQuantity::class);

        [$cartRepo, $productRepo] = $this->repos();
        $this->handle($cartRepo, $productRepo, quantity: -1);
    }

    #[Test]
    public function it_saves_the_cart(): void
    {
        [$cartRepo, $productRepo] = $this->repos();
        $cartRepo->expects($this->once())->method('save');

        $this->handle($cartRepo, $productRepo, quantity: 3);
    }

    // ------------------------------------------------------------------ helpers

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
        int $quantity = 3,
    ): CartView {
        return (new UpdateCartItemHandler($cartRepo, $productRepo))(
            new UpdateCartItemCommand(self::CART_ID, null, self::PRODUCT_ID, $quantity),
        );
    }
}
