<?php

declare(strict_types=1);

namespace Siroko\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Siroko\Catalog\Domain\Product;
use Siroko\Catalog\Domain\ProductId;
use Siroko\Catalog\Domain\ProductRepository;
use Siroko\Catalog\Domain\ProductStatus;
use Siroko\Sales\Domain\Cart\Cart;
use Siroko\Sales\Domain\Cart\CartRepository;
use Siroko\Sales\Domain\Order\Order;
use Siroko\Sales\Domain\Order\OrderRepository;
use Siroko\Sales\Domain\ValueObject\CartId;
use Siroko\Sales\Domain\ValueObject\CustomerId;
use Siroko\Sales\Domain\ValueObject\OrderId;
use Siroko\Sales\Domain\ValueObject\ShippingAddress;
use Siroko\Shared\Application\Identity\IdGenerator;
use Siroko\Shared\Domain\ValueObject\Money;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base class for all functional (HTTP) tests.
 *
 * Data seeded via repositories is committed immediately (no transaction
 * wrapping at the test level). Tests use unique UUIDs so rows from different
 * test methods never clash. The test database is expected to be migrated
 * before the suite runs.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected IdGenerator $idGenerator;
    protected ProductRepository $products;
    protected CartRepository $carts;
    protected OrderRepository $orders;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        $container           = self::getContainer();
        $this->idGenerator   = $container->get(IdGenerator::class);
        $this->products      = $container->get(ProductRepository::class);
        $this->carts         = $container->get(CartRepository::class);
        $this->orders        = $container->get(OrderRepository::class);
        $this->em            = $container->get(EntityManagerInterface::class);
    }

    // ------------------------------------------------------------------ HTTP helpers

    /** Sends a JSON request and returns the decoded response body. */
    protected function json(
        string $method,
        string $uri,
        array $body = [],
        array $headers = [],
    ): mixed {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
        ];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $this->client->request(
            $method,
            $uri,
            [],
            [],
            $server,
            $body !== [] ? (string) json_encode($body) : '',
        );

        $content = $this->client->getResponse()->getContent();

        return $content !== false && $content !== '' ? json_decode($content, true) : null;
    }

    protected function statusCode(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    // ------------------------------------------------------------------ domain factories

    protected function seedProduct(
        int $priceAmount = 4500,
        int $taxAmount   = 945,
        int $stock       = 50,
        ProductStatus $status = ProductStatus::ACTIVE,
    ): Product {
        $product = new Product(
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
        $this->products->save($product);
        $this->em->clear();

        return $product;
    }

    protected function seedCartWithItem(
        ProductId $productId,
        int $priceAmount = 4500,
        int $taxAmount   = 945,
        int $quantity    = 2,
        ?CustomerId $customerId = null,
    ): Cart {
        $cart = Cart::create(new CartId($this->idGenerator->generate()), $customerId);
        $cart->addItem($productId, new Money($priceAmount, 'EUR'), $taxAmount, $quantity, 100);
        $this->carts->save($cart);
        $this->em->clear();

        return $cart;
    }

    protected function seedCheckedOutOrder(
        ProductId $productId,
        int $priceAmount = 4500,
        int $taxAmount   = 945,
        int $quantity    = 2,
    ): array {
        $cart = $this->seedCartWithItem($productId, $priceAmount, $taxAmount, $quantity);

        // Decrement product stock to mirror what the real checkout endpoint does.
        $product = $this->products->findById($productId);
        assert($product !== null);
        $product->decreaseStock($quantity);
        $this->products->save($product);

        // Reload after clear
        $cart = $this->carts->findById($cart->id());
        assert($cart !== null);
        $cart->markCheckedOut();
        $this->carts->save($cart);

        $order = Order::fromCart(
            new OrderId($this->idGenerator->generate()),
            $cart,
            $this->defaultAddress(),
        );
        $order->releaseEvents();
        $this->orders->save($order);
        $this->em->clear();

        return ['cart' => $cart, 'order' => $order];
    }

    protected function defaultAddress(): ShippingAddress
    {
        return new ShippingAddress(
            'Ada', 'Lovelace', 'ES12345678Z',
            'Calle Mayor 1', 'Madrid', 'Madrid', '28013', 'ES',
        );
    }

    protected function addressPayload(): array
    {
        return [
            'firstName' => 'Ada',
            'lastName'  => 'Lovelace',
            'vatNumber' => 'ES12345678Z',
            'street'    => 'Calle Mayor 1',
            'city'      => 'Madrid',
            'state'     => 'Madrid',
            'zipCode'   => '28013',
            'country'   => 'ES',
        ];
    }

    // ------------------------------------------------------------------ assertion helpers

    /** Reloads a Cart from DB, bypassing the identity map. */
    protected function reloadCart(CartId $id): ?Cart
    {
        $this->em->clear();

        return $this->carts->findById($id);
    }

    /** Reloads an Order from DB, bypassing the identity map. */
    protected function reloadOrder(OrderId $id): ?Order
    {
        $this->em->clear();

        return $this->orders->findById($id);
    }

    /** Reloads a Product from DB, bypassing the identity map. */
    protected function reloadProduct(ProductId $id): ?Product
    {
        $this->em->clear();

        return $this->products->findById($id);
    }
}
