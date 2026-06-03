<?php

declare(strict_types=1);

namespace Siroko\Tests\Unit\Sales\Application;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siroko\Sales\Application\DTO\CartView;
use Siroko\Sales\Application\Query\GetCart\GetCartHandler;
use Siroko\Sales\Application\Query\GetCart\GetCartQuery;
use Siroko\Sales\Domain\Cart\CartRepository;
use Siroko\Sales\Domain\Exception\CartNotFound;
use Siroko\Sales\Domain\Exception\CartOwnershipMismatch;

final class GetCartHandlerTest extends TestCase
{
    use SalesApplicationTestHelpers;

    private const CART_ID    = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
    private const PRODUCT_ID = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
    private const ORDER_ID   = 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';

    #[Test]
    public function it_returns_cart_view_for_an_existing_cart(): void
    {
        $repo = $this->createMock(CartRepository::class);
        $repo->method('findById')->willReturn($this->openCart());

        $view = (new GetCartHandler($repo))(new GetCartQuery(self::CART_ID, null));

        self::assertInstanceOf(CartView::class, $view);
        self::assertSame(self::CART_ID, $view->cartId);
        self::assertSame('OPEN', $view->status);
    }

    #[Test]
    public function it_includes_items_and_computed_totals(): void
    {
        $repo = $this->createMock(CartRepository::class);
        $repo->method('findById')->willReturn($this->cartWithItem());

        $view = (new GetCartHandler($repo))(new GetCartQuery(self::CART_ID, null));

        self::assertCount(1, $view->items);
        self::assertSame(9000, $view->totalProductsAmount);   // 2 × 4500
        self::assertSame(1890, $view->totalTaxAmount);        // 2 × 945
        self::assertSame(10890, $view->totalOrderAmount);
    }

    #[Test]
    public function it_throws_cart_not_found_when_cart_is_missing(): void
    {
        $this->expectException(CartNotFound::class);

        $repo = $this->createMock(CartRepository::class);
        $repo->method('findById')->willReturn(null);

        (new GetCartHandler($repo))(new GetCartQuery(self::CART_ID, null));
    }

    #[Test]
    public function it_throws_ownership_mismatch_for_wrong_customer(): void
    {
        $this->expectException(CartOwnershipMismatch::class);

        $repo = $this->createMock(CartRepository::class);
        $repo->method('findById')->willReturn($this->openCart());

        // Guest cart, but caller passes a customerId
        (new GetCartHandler($repo))(
            new GetCartQuery(self::CART_ID, 'c47ac10b-58cc-4372-a567-0e02b2c3d479'),
        );
    }
}
