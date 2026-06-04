<?php

declare(strict_types=1);

namespace Siroko\Tests\Unit\Sales\Application;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Siroko\Catalog\Domain\Product;
use Siroko\Catalog\Domain\ProductRepository;
use Siroko\Sales\Application\Command\ProcessPayment\ProcessPaymentCommand;
use Siroko\Sales\Application\Command\ProcessPayment\ProcessPaymentHandler;
use Siroko\Sales\Domain\Cart\Cart;
use Siroko\Sales\Domain\Cart\CartRepository;
use Siroko\Sales\Domain\Exception\OrderNotFound;
use Siroko\Sales\Domain\Exception\OrderNotPending;
use Siroko\Sales\Domain\Order\Event\OrderPaymentConfirmed;
use Siroko\Sales\Domain\Order\Event\OrderPaymentFailed;
use Siroko\Sales\Domain\Order\Order;
use Siroko\Sales\Domain\Order\OrderRepository;
use Siroko\Sales\Domain\Order\OrderStatus;
use Siroko\Shared\Application\Event\EventBus;
use Siroko\Shared\Application\Transaction\TransactionManager;
use Siroko\Shared\Domain\Event\DomainEvent;

final class ProcessPaymentHandlerTest extends TestCase
{
    use SalesApplicationTestHelpers;

    private const CART_ID    = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
    private const PRODUCT_ID = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
    private const ORDER_ID   = 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';

    private OrderRepository&MockObject $orderRepo;
    private ProductRepository&MockObject $productRepo;
    private CartRepository&MockObject $cartRepo;
    private EventBus&MockObject $eventBus;

    /** @var list<DomainEvent> */
    private array $dispatchedEvents = [];

    protected function setUp(): void
    {
        $this->orderRepo   = $this->createMock(OrderRepository::class);
        $this->productRepo = $this->createMock(ProductRepository::class);
        $this->cartRepo    = $this->createMock(CartRepository::class);
        $this->eventBus    = $this->createMock(EventBus::class);

        $this->eventBus->method('dispatch')->willReturnCallback(
            function (DomainEvent ...$events): void {
                foreach ($events as $e) {
                    $this->dispatchedEvents[] = $e;
                }
            },
        );
    }

    // ------------------------------------------------------------------ success

    #[Test]
    public function it_confirms_payment_on_success(): void
    {
        $order = $this->pendingOrder();
        $this->orderRepo->method('findById')->willReturn($order);
        $this->orderRepo->expects($this->once())->method('save')->with($order);

        $this->handle(result: true);

        self::assertSame(OrderStatus::PAYMENT_CONFIRMED, $order->status());
    }

    #[Test]
    public function it_does_not_restore_stock_or_touch_cart_on_success(): void
    {
        $order = $this->pendingOrder();
        $this->orderRepo->method('findById')->willReturn($order);
        $this->productRepo->expects($this->never())->method('save');
        $this->cartRepo->expects($this->never())->method('findById');

        $this->handle(result: true);
    }

    #[Test]
    public function it_dispatches_payment_confirmed_event_on_success(): void
    {
        $order = $this->pendingOrder();
        $this->orderRepo->method('findById')->willReturn($order);

        $this->handle(result: true);

        self::assertContains(OrderPaymentConfirmed::class, $this->classesOf($this->dispatchedEvents));
    }

    // ------------------------------------------------------------------ failure

    #[Test]
    public function it_marks_order_payment_error_on_failure(): void
    {
        $order = $this->pendingOrder();
        $this->orderRepo->method('findById')->willReturn($order);
        $this->productRepo->method('findById')->willReturn($this->activeProduct(stock: 10));
        $this->cartRepo->method('findById')->willReturn(null);

        $this->handle(result: false);

        self::assertSame(OrderStatus::PAYMENT_ERROR, $order->status());
    }

    #[Test]
    public function it_restores_product_stock_on_failure(): void
    {
        $product = $this->activeProduct(stock: 10);
        $this->orderRepo->method('findById')->willReturn($this->pendingOrder()); // item qty 2
        $this->productRepo->method('findById')->willReturn($product);
        $this->productRepo->expects($this->once())->method('save')->with($product);
        $this->cartRepo->method('findById')->willReturn(null);

        $this->handle(result: false);

        self::assertSame(12, $product->quantity()); // 10 + 2
    }

    #[Test]
    public function it_reopens_the_cart_on_failure(): void
    {
        $cart = $this->cartWithItem();
        $cart->markCheckedOut();

        $this->orderRepo->method('findById')->willReturn($this->pendingOrder());
        $this->productRepo->method('findById')->willReturn($this->activeProduct(stock: 10));
        $this->cartRepo->method('findById')->willReturn($cart);
        $this->cartRepo->expects($this->once())->method('save')->with($cart);

        $this->handle(result: false);

        self::assertSame('OPEN', $cart->status()->value);
    }

    #[Test]
    public function it_silently_ignores_a_missing_cart_on_failure(): void
    {
        $order = $this->pendingOrder();
        $this->orderRepo->method('findById')->willReturn($order);
        $this->productRepo->method('findById')->willReturn($this->activeProduct(stock: 10));
        $this->cartRepo->method('findById')->willReturn(null); // cart deleted
        $this->cartRepo->expects($this->never())->method('save');

        $this->handle(result: false);

        // Order still transitions despite the missing cart.
        self::assertSame(OrderStatus::PAYMENT_ERROR, $order->status());
    }

    #[Test]
    public function it_dispatches_payment_failed_event_on_failure(): void
    {
        $this->orderRepo->method('findById')->willReturn($this->pendingOrder());
        $this->productRepo->method('findById')->willReturn($this->activeProduct(stock: 10));
        $this->cartRepo->method('findById')->willReturn(null);

        $this->handle(result: false);

        self::assertContains(OrderPaymentFailed::class, $this->classesOf($this->dispatchedEvents));
    }

    #[Test]
    public function it_silently_ignores_a_missing_product_during_stock_restore(): void
    {
        // A product may have been deleted after the order was placed.
        // Like a missing cart, it is ignored silently (§4.9 / constraint 13).
        $order = $this->pendingOrder();
        $this->orderRepo->method('findById')->willReturn($order);
        $this->productRepo->method('findById')->willReturn(null);
        $this->cartRepo->method('findById')->willReturn(null);
        $this->productRepo->expects($this->never())->method('save');

        $this->handle(result: false);

        // Order still transitions to PAYMENT_ERROR despite the missing product.
        self::assertSame(OrderStatus::PAYMENT_ERROR, $order->status());
    }

    // ------------------------------------------------------------------ sad paths

    #[Test]
    public function it_throws_order_not_found_when_order_is_missing(): void
    {
        $this->expectException(OrderNotFound::class);

        $this->orderRepo->method('findById')->willReturn(null);

        $this->handle(result: true);
    }

    #[Test]
    public function it_throws_order_not_pending_when_already_confirmed(): void
    {
        $this->expectException(OrderNotPending::class);

        $order = $this->pendingOrder();
        $order->confirmPayment();
        $this->orderRepo->method('findById')->willReturn($order);

        $this->handle(result: true);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param list<DomainEvent> $events
     * @return list<class-string>
     */
    private function classesOf(array $events): array
    {
        return array_map(static fn(DomainEvent $e) => $e::class, $events);
    }

    private function handle(bool $result): void
    {
        $tx = $this->createMock(TransactionManager::class);
        $tx->method('wrapInTransaction')->willReturnCallback(
            static fn(callable $op): mixed => $op(),
        );

        (new ProcessPaymentHandler(
            $this->orderRepo,
            $this->productRepo,
            $this->cartRepo,
            $tx,
            $this->eventBus,
        ))(new ProcessPaymentCommand(self::ORDER_ID, $result));
    }
}
