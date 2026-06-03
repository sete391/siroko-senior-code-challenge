<?php

declare(strict_types=1);

namespace Siroko\Tests\Unit\Sales\Application;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siroko\Sales\Application\DTO\OrderView;
use Siroko\Sales\Application\Query\GetOrder\GetOrderHandler;
use Siroko\Sales\Application\Query\GetOrder\GetOrderQuery;
use Siroko\Sales\Domain\Exception\OrderNotFound;
use Siroko\Sales\Domain\Exception\OrderOwnershipMismatch;
use Siroko\Sales\Domain\Order\OrderRepository;

final class GetOrderHandlerTest extends TestCase
{
    use SalesApplicationTestHelpers;

    private const CART_ID    = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
    private const PRODUCT_ID = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
    private const ORDER_ID   = 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';

    #[Test]
    public function it_returns_order_view_for_an_existing_order(): void
    {
        $repo = $this->createMock(OrderRepository::class);
        $repo->method('findById')->willReturn($this->pendingOrder());

        $view = (new GetOrderHandler($repo))(new GetOrderQuery(self::ORDER_ID, null));

        self::assertInstanceOf(OrderView::class, $view);
        self::assertSame(self::ORDER_ID, $view->orderId);
        self::assertSame('PENDING', $view->status);
    }

    #[Test]
    public function it_includes_items_and_totals(): void
    {
        $repo = $this->createMock(OrderRepository::class);
        $repo->method('findById')->willReturn($this->pendingOrder());

        $view = (new GetOrderHandler($repo))(new GetOrderQuery(self::ORDER_ID, null));

        self::assertCount(1, $view->items);
        self::assertSame(9000, $view->totalProductsAmount);
        self::assertSame(1890, $view->totalTaxAmount);
        self::assertSame(10890, $view->totalOrderAmount);
    }

    #[Test]
    public function it_throws_order_not_found_when_order_is_missing(): void
    {
        $this->expectException(OrderNotFound::class);

        $repo = $this->createMock(OrderRepository::class);
        $repo->method('findById')->willReturn(null);

        (new GetOrderHandler($repo))(new GetOrderQuery(self::ORDER_ID, null));
    }

    #[Test]
    public function it_throws_ownership_mismatch_for_wrong_customer(): void
    {
        $this->expectException(OrderOwnershipMismatch::class);

        $repo = $this->createMock(OrderRepository::class);
        $repo->method('findById')->willReturn($this->pendingOrder()); // guest order

        (new GetOrderHandler($repo))(
            new GetOrderQuery(self::ORDER_ID, 'c47ac10b-58cc-4372-a567-0e02b2c3d479'),
        );
    }
}
