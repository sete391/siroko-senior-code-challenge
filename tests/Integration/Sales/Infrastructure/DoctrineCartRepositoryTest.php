<?php

declare(strict_types=1);

namespace Siroko\Tests\Integration\Sales\Infrastructure;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Test;
use Siroko\Catalog\Domain\ProductId;
use Siroko\Sales\Domain\Cart\CartStatus;
use Siroko\Sales\Domain\ValueObject\CartId;
use Siroko\Sales\Domain\ValueObject\CustomerId;
use Siroko\Sales\Infrastructure\Persistence\Doctrine\DoctrineCartRepository;
use Siroko\Shared\Domain\ValueObject\Money;
use Siroko\Tests\Integration\IntegrationTestCase;

final class DoctrineCartRepositoryTest extends IntegrationTestCase
{
    private DoctrineCartRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = self::getContainer()->get(DoctrineCartRepository::class);
    }

    // ------------------------------------------------------------------ null case

    #[Test]
    public function it_returns_null_for_an_unknown_cart_id(): void
    {
        self::assertNull($this->repo->findById(new CartId('00000000-0000-4000-8000-000000000001')));
    }

    // ------------------------------------------------------------------ guest cart

    #[Test]
    public function it_persists_and_finds_a_guest_cart(): void
    {
        $cart = $this->makeCart(customerId: null);
        $id   = $cart->id();

        $this->repo->save($cart);
        $this->clearEm();

        $found = $this->repo->findById($id);

        self::assertNotNull($found);
        self::assertNull($found->customerId());
        self::assertSame(CartStatus::OPEN, $found->status());
        self::assertEmpty($found->items());
    }

    // ------------------------------------------------------------------ UUID custom types

    #[Test]
    public function cart_id_uuid_type_round_trips(): void
    {
        $cart = $this->makeCart();
        $id   = $cart->id();

        $this->repo->save($cart);
        $this->clearEm();

        $found = $this->repo->findById($id);

        self::assertNotNull($found);
        self::assertInstanceOf(CartId::class, $found->id());
        self::assertSame($id->value(), $found->id()->value());
    }

    #[Test]
    public function customer_id_uuid_type_round_trips(): void
    {
        $customerId = new CustomerId($this->idGenerator->generate());
        $cart       = $this->makeCart(customerId: $customerId);
        $id         = $cart->id();

        $this->repo->save($cart);
        $this->clearEm();

        $found = $this->repo->findById($id);

        self::assertNotNull($found);
        self::assertNotNull($found->customerId());
        self::assertInstanceOf(CustomerId::class, $found->customerId());
        self::assertSame($customerId->value(), $found->customerId()->value());
    }

    // ------------------------------------------------------------------ status enum

    #[Test]
    public function it_persists_checked_out_status(): void
    {
        $productId = new ProductId($this->idGenerator->generate());
        $cart      = $this->makeCart();
        $cart->addItem($productId, new Money(4500), 945, 1, 100);
        $cart->markCheckedOut();
        $id = $cart->id();

        $this->repo->save($cart);
        $this->clearEm();

        $found = $this->repo->findById($id);

        self::assertNotNull($found);
        self::assertSame(CartStatus::CHECKED_OUT, $found->status());
    }

    // ------------------------------------------------------------------ items round-trip

    #[Test]
    public function it_persists_cart_items_and_hydrates_them_via_reflection(): void
    {
        $productId1 = new ProductId($this->idGenerator->generate());
        $productId2 = new ProductId($this->idGenerator->generate());
        $cart       = $this->makeCart();

        $cart->addItem($productId1, new Money(4500, 'EUR'), 945,  2, 100);
        $cart->addItem($productId2, new Money(6000, 'EUR'), 1260, 3, 100);
        $id = $cart->id();

        $this->repo->save($cart);
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

    // ------------------------------------------------------------------ Money embeddable in items

    #[Test]
    public function cart_item_money_snapshot_persists_amount_and_currency(): void
    {
        $productId = new ProductId($this->idGenerator->generate());
        $cart      = $this->makeCart();
        $cart->addItem($productId, new Money(8800, 'EUR'), 1848, 1, 100);
        $id = $cart->id();

        $this->repo->save($cart);
        $this->clearEm();

        $found = $this->repo->findById($id);
        self::assertNotNull($found);
        $item = $found->items()[0];

        self::assertSame(8800, $item->unitPrice()->amount);
        self::assertSame('EUR', $item->unitPrice()->currency);
        self::assertSame(1848, $item->taxAmount());
        self::assertSame(1,    $item->quantity());
    }

    // ------------------------------------------------------------------ unique constraint

    #[Test]
    public function unique_constraint_on_cart_id_and_product_id_is_enforced(): void
    {
        $productId = new ProductId($this->idGenerator->generate());
        $cart      = $this->makeCart();
        $cart->addItem($productId, new Money(4500), 945, 1, 100);

        $this->repo->save($cart);

        // Insert a duplicate row directly via DBAL, bypassing the aggregate.
        $this->expectException(UniqueConstraintViolationException::class);

        $this->em->getConnection()->insert('cart_items', [
            'id'                  => $this->idGenerator->generate(),
            'cart_id'             => $cart->id()->value(),
            'product_id'          => $productId->value(),
            'unit_price_amount'   => 4500,
            'unit_price_currency' => 'EUR',
            'tax_amount'          => 945,
            'quantity'            => 1,
        ]);
    }

    // ------------------------------------------------------------------ update scenarios

    #[Test]
    public function item_quantity_update_is_persisted_on_second_save(): void
    {
        $productId = new ProductId($this->idGenerator->generate());
        $cart      = $this->makeCart();
        $cart->addItem($productId, new Money(4500), 945, 2, 100);
        $id = $cart->id();

        $this->repo->save($cart);
        $this->clearEm();

        $loaded = $this->repo->findById($id);
        self::assertNotNull($loaded);
        $loaded->updateItem($productId, new Money(4500), 945, 7, 100);
        $this->repo->save($loaded);
        $this->clearEm();

        $updated = $this->repo->findById($id);
        self::assertNotNull($updated);
        self::assertSame(7, $updated->items()[0]->quantity());
    }

    #[Test]
    public function removed_item_is_deleted_on_second_save(): void
    {
        $productId1 = new ProductId($this->idGenerator->generate());
        $productId2 = new ProductId($this->idGenerator->generate());
        $cart       = $this->makeCart();
        $cart->addItem($productId1, new Money(4500), 945,  1, 100);
        $cart->addItem($productId2, new Money(6000), 1260, 1, 100);
        $id = $cart->id();

        $this->repo->save($cart);
        $this->clearEm();

        $loaded = $this->repo->findById($id);
        self::assertNotNull($loaded);
        $loaded->removeItem($productId1);
        $this->repo->save($loaded);
        $this->clearEm();

        $updated = $this->repo->findById($id);
        self::assertNotNull($updated);
        self::assertCount(1, $updated->items());
        self::assertSame($productId2->value(), $updated->items()[0]->productId()->value());
    }

    #[Test]
    public function refreshed_snapshot_is_persisted_on_second_save(): void
    {
        $productId = new ProductId($this->idGenerator->generate());
        $cart      = $this->makeCart();
        $cart->addItem($productId, new Money(4500), 945, 1, 100);
        $id = $cart->id();

        $this->repo->save($cart);
        $this->clearEm();

        $loaded = $this->repo->findById($id);
        self::assertNotNull($loaded);
        $loaded->refreshItemSnapshot($productId, new Money(5500), 1155);
        $this->repo->save($loaded);
        $this->clearEm();

        $updated = $this->repo->findById($id);
        self::assertNotNull($updated);
        self::assertSame(5500, $updated->items()[0]->unitPrice()->amount);
        self::assertSame(1155, $updated->items()[0]->taxAmount());
    }

    #[Test]
    public function cart_reopen_status_persists_after_save(): void
    {
        $productId = new ProductId($this->idGenerator->generate());
        $cart      = $this->makeCart();
        $cart->addItem($productId, new Money(4500), 945, 1, 100);
        $cart->markCheckedOut();
        $id = $cart->id();

        $this->repo->save($cart);
        $this->clearEm();

        $loaded = $this->repo->findById($id);
        self::assertNotNull($loaded);
        $loaded->reopen();
        $this->repo->save($loaded);
        $this->clearEm();

        $reopened = $this->repo->findById($id);
        self::assertNotNull($reopened);
        self::assertSame(CartStatus::OPEN, $reopened->status());
    }
}
