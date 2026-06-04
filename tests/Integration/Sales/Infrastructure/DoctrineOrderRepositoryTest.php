<?php

declare(strict_types=1);

namespace Siroko\Tests\Integration\Sales\Infrastructure;

use PHPUnit\Framework\Attributes\Test;
use Siroko\Catalog\Domain\ProductId;
use Siroko\Sales\Domain\Order\OrderStatus;
use Siroko\Sales\Domain\ValueObject\CartId;
use Siroko\Sales\Domain\ValueObject\CustomerId;
use Siroko\Sales\Domain\ValueObject\OrderId;
use Siroko\Sales\Domain\ValueObject\ShippingAddress;
use Siroko\Sales\Infrastructure\Persistence\Doctrine\DoctrineOrderRepository;
use Siroko\Shared\Domain\ValueObject\Money;
use Siroko\Tests\Integration\IntegrationTestCase;

final class DoctrineOrderRepositoryTest extends IntegrationTestCase
{
    private DoctrineOrderRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = self::getContainer()->get(DoctrineOrderRepository::class);
    }

    // ------------------------------------------------------------------ null case

    #[Test]
    public function it_returns_null_for_an_unknown_order_id(): void
    {
        self::assertNull($this->repo->findById(new OrderId('00000000-0000-4000-8000-000000000002')));
    }

    // ------------------------------------------------------------------ scalar round-trip

    #[Test]
    public function it_persists_and_finds_all_scalar_fields(): void
    {
        $cart  = $this->makeCart();
        $cart->addItem(new ProductId($this->idGenerator->generate()), new Money(4500), 945, 1, 100);
        $order = $this->makeOrder($cart);
        $id    = $order->id();

        $this->repo->save($order);
        $this->clearEm();

        $found = $this->repo->findById($id);

        self::assertNotNull($found);
        self::assertSame($id->value(), $found->id()->value());
        self::assertSame(OrderStatus::PENDING, $found->status());
        self::assertNull($found->customerId());
    }

    // ------------------------------------------------------------------ UUID custom types

    #[Test]
    public function order_id_uuid_type_round_trips(): void
    {
        $cart = $this->makeCart();
        $cart->addItem(new ProductId($this->idGenerator->generate()), new Money(4500), 945, 1, 100);
        $order = $this->makeOrder($cart);
        $id    = $order->id();

        $this->repo->save($order);
        $this->clearEm();

        $found = $this->repo->findById($id);

        self::assertNotNull($found);
        self::assertInstanceOf(OrderId::class, $found->id());
        self::assertSame($id->value(), $found->id()->value());
    }

    #[Test]
    public function customer_id_uuid_type_round_trips_on_order(): void
    {
        $customerId = new CustomerId($this->idGenerator->generate());
        $cart       = $this->makeCart(customerId: $customerId);
        $cart->addItem(new ProductId($this->idGenerator->generate()), new Money(4500), 945, 1, 100);
        $order = $this->makeOrder($cart);
        $id    = $order->id();

        $this->repo->save($order);
        $this->clearEm();

        $found = $this->repo->findById($id);

        self::assertNotNull($found);
        self::assertNotNull($found->customerId());
        self::assertInstanceOf(CustomerId::class, $found->customerId());
        self::assertSame($customerId->value(), $found->customerId()->value());
    }

    #[Test]
    public function cart_id_is_stored_as_plain_column_without_fk(): void
    {
        // Order.cartId has no FK — an order may reference a deleted cart.
        // This test proves the column stores and retrieves correctly with no
        // referential constraint error even when no matching cart row exists.
        $orphanCartId = new CartId($this->idGenerator->generate());
        $cart         = $this->makeCart();
        $cart->addItem(new ProductId($this->idGenerator->generate()), new Money(4500), 945, 1, 100);
        $order = $this->makeOrder($cart);
        $id    = $order->id();

        // Do NOT save the cart — only save the order.
        $this->repo->save($order);
        $this->clearEm();

        $found = $this->repo->findById($id);
        self::assertNotNull($found);
        self::assertInstanceOf(CartId::class, $found->cartId());
        self::assertSame($cart->id()->value(), $found->cartId()->value());
        // The referenced cart does not exist in the DB — no FK violation.
        $cartRow = $this->em->getConnection()->fetchOne(
            'SELECT id FROM carts WHERE id = ?',
            [$cart->id()->value()],
        );
        self::assertFalse($cartRow);
    }

    // ------------------------------------------------------------------ Money embeddables (totals)

    #[Test]
    public function it_persists_and_hydrates_money_totals(): void
    {
        // 3 × €45.00 = €135.00; 3 × €9.45 = €28.35; total €163.35
        $productId = new ProductId($this->idGenerator->generate());
        $cart      = $this->makeCart();
        $cart->addItem($productId, new Money(4500, 'EUR'), 945, 3, 100);
        $order = $this->makeOrder($cart);
        $id    = $order->id();

        $this->repo->save($order);
        $this->clearEm();

        $found = $this->repo->findById($id);

        self::assertNotNull($found);
        self::assertSame(13500, $found->totalProducts()->amount); // 4500 × 3
        self::assertSame('EUR', $found->totalProducts()->currency);
        self::assertSame(2835,  $found->totalTax()->amount);      // 945 × 3
        self::assertSame('EUR', $found->totalTax()->currency);
        self::assertSame(16335, $found->totalOrder()->amount);    // 13500 + 2835
        self::assertSame('EUR', $found->totalOrder()->currency);
    }

    // ------------------------------------------------------------------ ShippingAddress embeddable

    #[Test]
    public function it_persists_and_hydrates_all_shipping_address_fields(): void
    {
        $address = new ShippingAddress(
            'Ada', 'Lovelace', 'ES12345678Z',
            'Calle Mayor 1', 'Madrid', 'Madrid', '28013', 'ES',
        );
        $cart = $this->makeCart();
        $cart->addItem(new ProductId($this->idGenerator->generate()), new Money(4500), 945, 1, 100);
        $order = $this->makeOrder($cart, $address);
        $id    = $order->id();

        $this->repo->save($order);
        $this->clearEm();

        $found = $this->repo->findById($id);
        self::assertNotNull($found);
        $a = $found->shippingAddress();

        self::assertSame('Ada',           $a->firstName);
        self::assertSame('Lovelace',      $a->lastName);
        self::assertSame('ES12345678Z',   $a->vatNumber);
        self::assertSame('Calle Mayor 1', $a->street);
        self::assertSame('Madrid',        $a->city);
        self::assertSame('Madrid',        $a->state);
        self::assertSame('28013',         $a->zipCode);
        self::assertSame('ES',            $a->country);
    }

    // ------------------------------------------------------------------ items round-trip

    #[Test]
    public function it_persists_order_items_and_hydrates_them_via_reflection(): void
    {
        $productId1 = new ProductId($this->idGenerator->generate());
        $productId2 = new ProductId($this->idGenerator->generate());
        $cart       = $this->makeCart();
        $cart->addItem($productId1, new Money(4500, 'EUR'), 945,  2, 100);
        $cart->addItem($productId2, new Money(6000, 'EUR'), 1260, 3, 100);
        $order = $this->makeOrder($cart);
        $id    = $order->id();

        $this->repo->save($order);
        $this->clearEm();

        $found = $this->repo->findById($id);
        self::assertNotNull($found);
        self::assertCount(2, $found->items());

        $foundProductIds = array_map(
            static fn($i) => $i->productId()->value(),
            $found->items(),
        );
        self::assertContains($productId1->value(), $foundProductIds);
        self::assertContains($productId2->value(), $foundProductIds);
    }

    #[Test]
    public function order_item_snapshot_fields_round_trip(): void
    {
        $productId = new ProductId($this->idGenerator->generate());
        $cart      = $this->makeCart();
        $cart->addItem($productId, new Money(6000, 'EUR'), 1260, 3, 100);
        $order = $this->makeOrder($cart);
        $id    = $order->id();

        $this->repo->save($order);
        $this->clearEm();

        $found = $this->repo->findById($id);
        self::assertNotNull($found);
        $item = $found->items()[0];

        self::assertSame($productId->value(), $item->productId()->value());
        self::assertSame(6000, $item->unitPrice()->amount);
        self::assertSame('EUR', $item->unitPrice()->currency);
        self::assertSame(1260, $item->taxAmount());
        self::assertSame(3,    $item->quantity());
        // Computed accessors
        self::assertSame(18000, $item->lineTotal()->amount); // 6000 × 3
        self::assertSame(3780,  $item->lineTax());           // 1260 × 3
    }

    // ------------------------------------------------------------------ idempotent save

    #[Test]
    public function saving_an_order_twice_does_not_duplicate_items(): void
    {
        $productId = new ProductId($this->idGenerator->generate());
        $cart      = $this->makeCart();
        $cart->addItem($productId, new Money(4500), 945, 1, 100);
        $order = $this->makeOrder($cart);
        $id    = $order->id();

        $this->repo->save($order);
        $this->clearEm();

        $loaded = $this->repo->findById($id);
        self::assertNotNull($loaded);
        $loaded->confirmPayment();
        $this->repo->save($loaded);
        $this->clearEm();

        $final = $this->repo->findById($id);
        self::assertNotNull($final);
        self::assertCount(1, $final->items());
    }

    // ------------------------------------------------------------------ status transitions

    #[Test]
    public function payment_confirmed_status_persists(): void
    {
        $cart = $this->makeCart();
        $cart->addItem(new ProductId($this->idGenerator->generate()), new Money(4500), 945, 1, 100);
        $order = $this->makeOrder($cart);
        $id    = $order->id();

        $this->repo->save($order);
        $this->clearEm();

        $loaded = $this->repo->findById($id);
        self::assertNotNull($loaded);
        $loaded->confirmPayment();
        $this->repo->save($loaded);
        $this->clearEm();

        $found = $this->repo->findById($id);
        self::assertNotNull($found);
        self::assertSame(OrderStatus::PAYMENT_CONFIRMED, $found->status());
    }

    #[Test]
    public function payment_error_status_persists(): void
    {
        $cart = $this->makeCart();
        $cart->addItem(new ProductId($this->idGenerator->generate()), new Money(4500), 945, 1, 100);
        $order = $this->makeOrder($cart);
        $id    = $order->id();

        $this->repo->save($order);
        $this->clearEm();

        $loaded = $this->repo->findById($id);
        self::assertNotNull($loaded);
        $loaded->failPayment();
        $this->repo->save($loaded);
        $this->clearEm();

        $found = $this->repo->findById($id);
        self::assertNotNull($found);
        self::assertSame(OrderStatus::PAYMENT_ERROR, $found->status());
    }
}
