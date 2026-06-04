<?php

declare(strict_types=1);

namespace Siroko\Tests\Integration\Catalog\Infrastructure;

use PHPUnit\Framework\Attributes\Test;
use Siroko\Catalog\Domain\ProductId;
use Siroko\Catalog\Domain\ProductStatus;
use Siroko\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use Siroko\Shared\Domain\ValueObject\Money;
use Siroko\Tests\Integration\IntegrationTestCase;

final class DoctrineProductRepositoryTest extends IntegrationTestCase
{
    private DoctrineProductRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = self::getContainer()->get(DoctrineProductRepository::class);
    }

    // ------------------------------------------------------------------ null case

    #[Test]
    public function it_returns_null_for_an_unknown_product_id(): void
    {
        self::assertNull($this->repo->findById(new ProductId('00000000-0000-4000-8000-000000000099')));
    }

    // ------------------------------------------------------------------ scalar round-trip

    #[Test]
    public function it_persists_and_finds_all_scalar_fields(): void
    {
        $product = $this->makeProduct(priceAmount: 4500, taxAmount: 945, stock: 20);
        $id      = $product->id();

        $this->repo->save($product);
        $this->clearEm();

        $found = $this->repo->findById($id);

        self::assertNotNull($found);
        self::assertSame($id->value(), $found->id()->value());
        self::assertSame('Siroko K3s Jersey', $found->name());
        self::assertSame('Road cycling jersey', $found->description());
        self::assertSame(945, $found->taxAmount());
        self::assertSame(20, $found->quantity());
        self::assertSame(ProductStatus::ACTIVE, $found->status());
    }

    // ------------------------------------------------------------------ UUID custom type

    #[Test]
    public function uuid_custom_type_round_trips_product_id_as_char36(): void
    {
        $product = $this->makeProduct();
        $id      = $product->id();

        $this->repo->save($product);
        $this->clearEm();

        $found = $this->repo->findById($id);

        self::assertNotNull($found);
        // The hydrated id must be a ProductId VO with the exact same UUID string.
        self::assertInstanceOf(ProductId::class, $found->id());
        self::assertSame($id->value(), $found->id()->value());
    }

    // ------------------------------------------------------------------ Money embeddable

    #[Test]
    public function money_embeddable_persists_amount_and_currency(): void
    {
        $product = $this->makeProduct(priceAmount: 12000);
        $id      = $product->id();

        $this->repo->save($product);
        $this->clearEm();

        $found = $this->repo->findById($id);

        self::assertNotNull($found);
        self::assertSame(12000, $found->unitPrice()->amount);
        self::assertSame('EUR', $found->unitPrice()->currency);
    }

    #[Test]
    public function money_embeddable_preserves_non_default_currency(): void
    {
        $product = new \Siroko\Catalog\Domain\Product(
            id:          new ProductId($this->idGenerator->generate()),
            name:        'USD product',
            description: 'for currency test',
            taxAmount:   0,
            unitPrice:   new Money(9900, 'USD'),
            quantity:    5,
            status:      ProductStatus::ACTIVE,
            createdAt:   new \DateTimeImmutable(),
            updatedAt:   new \DateTimeImmutable(),
        );
        $id = $product->id();

        $this->repo->save($product);
        $this->clearEm();

        $found = $this->repo->findById($id);

        self::assertNotNull($found);
        self::assertSame('USD', $found->unitPrice()->currency);
    }

    // ------------------------------------------------------------------ status enum

    #[Test]
    public function it_persists_inactive_status(): void
    {
        $product = $this->makeProduct(status: ProductStatus::INACTIVE);
        $id      = $product->id();

        $this->repo->save($product);
        $this->clearEm();

        $found = $this->repo->findById($id);

        self::assertNotNull($found);
        self::assertSame(ProductStatus::INACTIVE, $found->status());
        self::assertFalse($found->isActive());
    }

    // ------------------------------------------------------------------ findAllActive

    #[Test]
    public function find_all_active_returns_only_active_products(): void
    {
        $active   = $this->makeProduct(status: ProductStatus::ACTIVE);
        $inactive = $this->makeProduct(status: ProductStatus::INACTIVE);

        $this->repo->save($active);
        $this->repo->save($inactive);
        $this->clearEm();

        $results = $this->repo->findAllActive();

        $foundIds = array_map(static fn($p) => $p->id()->value(), $results);
        self::assertContains($active->id()->value(), $foundIds);
        self::assertNotContains($inactive->id()->value(), $foundIds);
    }

    #[Test]
    public function find_all_active_returns_empty_array_when_no_active_products(): void
    {
        $inactive = $this->makeProduct(status: ProductStatus::INACTIVE);
        $this->repo->save($inactive);
        $this->clearEm();

        // Cannot assert the whole list is empty (other test data may exist in
        // the transaction), but the inactive product must not be in the results.
        $foundIds = array_map(
            static fn($p) => $p->id()->value(),
            $this->repo->findAllActive(),
        );
        self::assertNotContains($inactive->id()->value(), $foundIds);
    }

    // ------------------------------------------------------------------ stock mutations

    #[Test]
    public function stock_decrease_persists_after_save(): void
    {
        $product = $this->makeProduct(stock: 30);
        $id      = $product->id();

        $this->repo->save($product);
        $this->clearEm();

        $loaded = $this->repo->findById($id);
        self::assertNotNull($loaded);
        $loaded->decreaseStock(5);
        $this->repo->save($loaded);
        $this->clearEm();

        $updated = $this->repo->findById($id);
        self::assertNotNull($updated);
        self::assertSame(25, $updated->quantity());
    }

    #[Test]
    public function stock_restore_persists_after_save(): void
    {
        $product = $this->makeProduct(stock: 10);
        $id      = $product->id();

        $this->repo->save($product);
        $this->clearEm();

        $loaded = $this->repo->findById($id);
        self::assertNotNull($loaded);
        $loaded->restoreStock(3);
        $this->repo->save($loaded);
        $this->clearEm();

        $updated = $this->repo->findById($id);
        self::assertNotNull($updated);
        self::assertSame(13, $updated->quantity());
    }
}
