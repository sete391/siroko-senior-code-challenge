<?php

declare(strict_types=1);

namespace Siroko\Tests\Unit\Sales\Application;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Siroko\Catalog\Domain\Exception\ProductNotActive;
use Siroko\Catalog\Domain\Exception\ProductNotFound;
use Siroko\Catalog\Domain\Product;
use Siroko\Catalog\Domain\ProductRepository;
use Siroko\Sales\Application\Command\Checkout\CheckoutCommand;
use Siroko\Sales\Application\Command\Checkout\CheckoutHandler;
use Siroko\Sales\Application\DTO\OrderView;
use Siroko\Sales\Domain\Cart\Cart;
use Siroko\Sales\Domain\Cart\CartRepository;
use Siroko\Sales\Domain\Exception\CartNotFound;
use Siroko\Sales\Domain\Exception\CartNotModifiable;
use Siroko\Sales\Domain\Exception\CartOwnershipMismatch;
use Siroko\Sales\Domain\Exception\CheckoutCoherenceFailed;
use Siroko\Sales\Domain\Exception\EmptyCartCannotCheckout;
use Siroko\Sales\Domain\Exception\InvalidShippingAddress;
use Siroko\Sales\Domain\Order\Event\OrderPlaced;
use Siroko\Sales\Domain\Order\Order;
use Siroko\Sales\Domain\Order\OrderRepository;
use Siroko\Sales\Domain\Service\CheckoutCoherenceChecker;
use Siroko\Sales\Domain\ValueObject\CartId;
use Siroko\Sales\Domain\ValueObject\CustomerId;
use Siroko\Shared\Application\Event\EventBus;
use Siroko\Shared\Application\Identity\IdGenerator;
use Siroko\Shared\Application\Transaction\TransactionManager;
use Siroko\Shared\Domain\Event\DomainEvent;
use Siroko\Shared\Domain\ValueObject\Money;

final class CheckoutHandlerTest extends TestCase
{
    use SalesApplicationTestHelpers;

    private const CART_ID    = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
    private const PRODUCT_ID = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
    private const ORDER_ID   = 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
    private const CUSTOMER   = 'c47ac10b-58cc-4372-a567-0e02b2c3d479';

    private CartRepository&MockObject $cartRepo;
    private ProductRepository&MockObject $productRepo;
    private OrderRepository&MockObject $orderRepo;
    private EventBus&MockObject $eventBus;

    protected function setUp(): void
    {
        $this->cartRepo    = $this->createMock(CartRepository::class);
        $this->productRepo = $this->createMock(ProductRepository::class);
        $this->orderRepo   = $this->createMock(OrderRepository::class);
        $this->eventBus    = $this->createMock(EventBus::class);
    }

    // ------------------------------------------------------------------ happy path

    #[Test]
    public function it_creates_a_pending_order_from_a_coherent_cart(): void
    {
        $this->cartRepo->method('findById')->willReturn($this->cartWithItem());
        $this->productRepo->method('findById')->willReturn($this->activeProduct(stock: 10));

        $view = $this->handle();

        self::assertInstanceOf(OrderView::class, $view);
        self::assertSame(self::ORDER_ID, $view->orderId);
        self::assertSame('PENDING', $view->status);
    }

    #[Test]
    public function it_decreases_product_stock_and_saves_the_product(): void
    {
        $product = $this->activeProduct(stock: 10);
        $this->cartRepo->method('findById')->willReturn($this->cartWithItem(qty: 2));
        $this->productRepo->method('findById')->willReturn($product);
        $this->productRepo->expects($this->once())->method('save')->with($product);

        $this->handle();

        self::assertSame(8, $product->quantity()); // 10 − 2
    }

    #[Test]
    public function it_marks_the_cart_checked_out_and_saves_it(): void
    {
        $cart = $this->cartWithItem();
        $this->cartRepo->method('findById')->willReturn($cart);
        $this->productRepo->method('findById')->willReturn($this->activeProduct(stock: 10));
        $this->cartRepo->expects($this->once())->method('save')->with($cart);

        $this->handle();

        self::assertSame('CHECKED_OUT', $cart->status()->value);
    }

    #[Test]
    public function it_saves_the_order(): void
    {
        $this->cartRepo->method('findById')->willReturn($this->cartWithItem());
        $this->productRepo->method('findById')->willReturn($this->activeProduct(stock: 10));
        $this->orderRepo->expects($this->once())->method('save')
            ->with($this->isInstanceOf(Order::class));

        $this->handle();
    }

    #[Test]
    public function it_dispatches_domain_events_after_commit(): void
    {
        $this->cartRepo->method('findById')->willReturn($this->cartWithItem());
        $this->productRepo->method('findById')->willReturn($this->activeProduct(stock: 10));

        $dispatched = [];
        $this->eventBus->method('dispatch')->willReturnCallback(
            function (DomainEvent ...$events) use (&$dispatched): void {
                foreach ($events as $e) {
                    $dispatched[] = $e;
                }
            },
        );

        $this->handle();

        $classes = array_map(static fn(DomainEvent $e) => $e::class, $dispatched);
        self::assertContains(OrderPlaced::class, $classes);
    }

    // ------------------------------------------------------------------ sad paths

    #[Test]
    public function it_throws_cart_not_found_when_cart_is_missing(): void
    {
        $this->expectException(CartNotFound::class);

        $this->cartRepo->method('findById')->willReturn(null);

        $this->handle();
    }

    #[Test]
    public function it_throws_cart_not_modifiable_when_cart_is_checked_out(): void
    {
        $this->expectException(CartNotModifiable::class);

        $cart = $this->cartWithItem();
        $cart->markCheckedOut();
        $this->cartRepo->method('findById')->willReturn($cart);

        $this->handle();
    }

    #[Test]
    public function it_throws_empty_cart_when_cart_has_no_items(): void
    {
        $this->expectException(EmptyCartCannotCheckout::class);

        $this->cartRepo->method('findById')->willReturn($this->openCart());

        $this->handle();
    }

    #[Test]
    public function it_throws_ownership_mismatch_for_wrong_customer(): void
    {
        $this->expectException(CartOwnershipMismatch::class);

        $this->cartRepo->method('findById')->willReturn($this->cartWithItem()); // guest cart

        $this->handle(customerId: self::CUSTOMER);
    }

    #[Test]
    public function it_throws_invalid_shipping_address_for_empty_field(): void
    {
        $this->expectException(InvalidShippingAddress::class);

        $this->cartRepo->method('findById')->willReturn($this->cartWithItem());
        $this->productRepo->method('findById')->willReturn($this->activeProduct(stock: 10));

        // empty city
        $this->handler()(new CheckoutCommand(
            self::CART_ID, null, 'Ada', 'Lovelace', 'ES123', 'Calle Mayor 1', '', 'Madrid', '28013', 'ES',
        ));
    }

    #[Test]
    public function it_throws_product_not_found_during_coherence_check(): void
    {
        $this->expectException(ProductNotFound::class);

        $this->cartRepo->method('findById')->willReturn($this->cartWithItem());
        $this->productRepo->method('findById')->willReturn(null);

        $this->handle();
    }

    #[Test]
    public function it_throws_product_not_active_during_coherence_check(): void
    {
        $this->expectException(ProductNotActive::class);

        $this->cartRepo->method('findById')->willReturn($this->cartWithItem());
        $this->productRepo->method('findById')->willReturn($this->inactiveProduct());

        $this->handle();
    }

    // ------------------------------------------------------------------ coherence failure

    #[Test]
    public function it_rolls_back_and_saves_refreshed_cart_on_price_change(): void
    {
        $cart = $this->cartWithItem(price: 4500, tax: 945, qty: 2);
        // Product now costs more → coherence failure
        $product = $this->activeProduct(price: 5000, tax: 945, stock: 10);

        $this->cartRepo->method('findById')->willReturn($cart);
        $this->productRepo->method('findById')->willReturn($product);

        // Order is never created; stock is never decreased; no events dispatched.
        $this->orderRepo->expects($this->never())->method('save');
        $this->productRepo->expects($this->never())->method('save');
        $this->eventBus->expects($this->never())->method('dispatch');
        // The refreshed cart is persisted exactly once.
        $this->cartRepo->expects($this->once())->method('save')->with($cart);

        try {
            $this->handle();
            self::fail('Expected CheckoutCoherenceFailed');
        } catch (CheckoutCoherenceFailed $e) {
            self::assertCount(1, $e->issues());
            self::assertSame(self::PRODUCT_ID, $e->issues()[0]->productId->value());
            // Snapshot refreshed in memory
            self::assertSame(5000, $cart->items()[0]->unitPrice()->amount);
            self::assertSame(10, $product->quantity()); // unchanged
        }
    }

    #[Test]
    public function it_reports_insufficient_stock_as_a_coherence_failure(): void
    {
        $cart    = $this->cartWithItem(price: 4500, tax: 945, qty: 5, stock: 100);
        $product = $this->activeProduct(price: 4500, tax: 945, stock: 2);

        $this->cartRepo->method('findById')->willReturn($cart);
        $this->productRepo->method('findById')->willReturn($product);
        $this->orderRepo->expects($this->never())->method('save');

        try {
            $this->handle();
            self::fail('Expected CheckoutCoherenceFailed');
        } catch (CheckoutCoherenceFailed $e) {
            self::assertCount(1, $e->issues());
            self::assertSame('insufficient_stock', $e->issues()[0]->reason);
        }
    }

    // ------------------------------------------------------------------ helpers

    private function handler(): CheckoutHandler
    {
        $tx = $this->createMock(TransactionManager::class);
        $tx->method('wrapInTransaction')->willReturnCallback(
            static fn(callable $op): mixed => $op(),
        );

        $idGen = $this->createMock(IdGenerator::class);
        $idGen->method('generate')->willReturn(self::ORDER_ID);

        return new CheckoutHandler(
            $this->cartRepo,
            $this->productRepo,
            $this->orderRepo,
            new CheckoutCoherenceChecker(),
            $tx,
            $this->eventBus,
            $idGen,
        );
    }

    private function handle(?string $customerId = null): OrderView
    {
        return $this->handler()(new CheckoutCommand(
            self::CART_ID,
            $customerId,
            'Ada',
            'Lovelace',
            'ES123',
            'Calle Mayor 1',
            'Madrid',
            'Madrid',
            '28013',
            'ES',
        ));
    }
}
