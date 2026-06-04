<?php

declare(strict_types=1);

namespace Siroko\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Siroko\Catalog\Domain\Product;
use Siroko\Catalog\Domain\ProductId;
use Siroko\Catalog\Domain\ProductStatus;
use Siroko\Sales\Domain\Cart\Cart;
use Siroko\Sales\Domain\Order\Order;
use Siroko\Sales\Domain\ValueObject\CartId;
use Siroko\Sales\Domain\ValueObject\CustomerId;
use Siroko\Sales\Domain\ValueObject\OrderId;
use Siroko\Sales\Domain\ValueObject\ShippingAddress;
use Siroko\Shared\Application\Identity\IdGenerator;
use Siroko\Shared\Domain\ValueObject\Money;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base for all integration tests that need a real database.
 *
 * Isolation strategy: each test method runs inside a DBAL transaction that
 * is rolled back in tearDown — nothing is ever committed.
 *
 * Identity-map bypass: call $this->clearEm() after a save so the next
 * findById() hits the database instead of returning the cached in-memory
 * instance.
 */
abstract class IntegrationTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected IdGenerator $idGenerator;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em          = self::getContainer()->get(EntityManagerInterface::class);
        $this->idGenerator = self::getContainer()->get(IdGenerator::class);

        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        $this->em->clear();
        parent::tearDown();
    }

    /** Clears the ORM identity map to force a real DB read on the next find. */
    protected function clearEm(): void
    {
        $this->em->clear();
    }

    // ------------------------------------------------------------------ factories

    protected function makeProduct(
        int $priceAmount = 4500,
        int $taxAmount = 945,
        int $stock = 50,
        ProductStatus $status = ProductStatus::ACTIVE,
    ): Product {
        return new Product(
            id:          new ProductId($this->idGenerator->generate()),
            name:        'Siroko K3s Jersey',
            description: 'Road cycling jersey',
            taxAmount:   $taxAmount,
            unitPrice:   new Money($priceAmount, 'EUR'),
            quantity:    $stock,
            status:      $status,
            createdAt:   new \DateTimeImmutable(),
            updatedAt:   new \DateTimeImmutable(),
        );
    }

    protected function makeCart(?CustomerId $customerId = null): Cart
    {
        return Cart::create(new CartId($this->idGenerator->generate()), $customerId);
    }

    protected function makeOrder(Cart $cart, ?ShippingAddress $address = null): Order
    {
        $order = Order::fromCart(
            new OrderId($this->idGenerator->generate()),
            $cart,
            $address ?? $this->defaultAddress(),
        );
        $order->releaseEvents();

        return $order;
    }

    protected function defaultAddress(): ShippingAddress
    {
        return new ShippingAddress(
            'Ada', 'Lovelace', 'ES12345678Z',
            'Calle Mayor 1', 'Madrid', 'Madrid', '28013', 'ES',
        );
    }
}
