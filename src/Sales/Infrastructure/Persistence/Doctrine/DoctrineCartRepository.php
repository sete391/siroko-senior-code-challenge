<?php

declare(strict_types=1);

namespace Siroko\Sales\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Siroko\Catalog\Domain\ProductId;
use Siroko\Sales\Domain\Cart\Cart;
use Siroko\Sales\Domain\Cart\CartItem;
use Siroko\Sales\Domain\Cart\CartRepository;
use Siroko\Sales\Domain\ValueObject\CartId;
use Siroko\Shared\Application\Identity\IdGenerator;
use Siroko\Shared\Domain\ValueObject\Money;
use Siroko\Shared\Domain\ValueObject\Quantity;

final class DoctrineCartRepository implements CartRepository
{
    private Connection $conn;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IdGenerator $idGenerator,
    ) {
        $this->conn = $em->getConnection();
    }

    public function findById(CartId $id): ?Cart
    {
        $cart = $this->em->find(Cart::class, $id->value());

        if (null === $cart) {
            return null;
        }

        $this->hydrateItems($cart);

        return $cart;
    }

    public function save(Cart $cart): void
    {
        if (!$this->em->isOpen()) {
            // EntityManager was closed after a transaction rollback (e.g. coherence
            // failure in CheckoutHandler). Persist only the refreshed item snapshots
            // via DBAL — the cart scalar fields are unchanged in this path.
            $this->syncItems($cart);
            return;
        }

        $this->em->wrapInTransaction(function () use ($cart): void {
            $this->em->persist($cart);
            $this->em->flush();   // parent row committed first
            $this->syncItems($cart);
        });
    }

    private function hydrateItems(Cart $cart): void
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT product_id, unit_price_amount, unit_price_currency, tax_amount, quantity
               FROM cart_items
              WHERE cart_id = :cartId',
            ['cartId' => $cart->id()->value()],
        );

        $items = array_map(
            static fn(array $row) => new CartItem(
                new ProductId($row['product_id']),
                new Money((int) $row['unit_price_amount'], $row['unit_price_currency']),
                (int) $row['tax_amount'],
                new Quantity((int) $row['quantity']),
            ),
            $rows,
        );

        $this->setPrivateProperty($cart, 'items', $items);
    }

    private function syncItems(Cart $cart): void
    {
        $cartId = $cart->id()->value();

        $existing = $this->conn->fetchAllAssociative(
            'SELECT product_id FROM cart_items WHERE cart_id = :cartId',
            ['cartId' => $cartId],
        );
        $existingProductIds = array_column($existing, 'product_id');
        $desiredProductIds  = array_map(
            static fn(CartItem $item) => $item->productId()->value(),
            $cart->items(),
        );

        // Delete removed items
        $toDelete = array_diff($existingProductIds, $desiredProductIds);
        foreach ($toDelete as $productId) {
            $this->conn->delete('cart_items', ['cart_id' => $cartId, 'product_id' => $productId]);
        }

        foreach ($cart->items() as $item) {
            $data = [
                'unit_price_amount'   => $item->unitPrice()->amount,
                'unit_price_currency' => $item->unitPrice()->currency,
                'tax_amount'          => $item->taxAmount(),
                'quantity'            => $item->quantity(),
            ];

            if (in_array($item->productId()->value(), $existingProductIds, true)) {
                // Update existing
                $this->conn->update(
                    'cart_items',
                    $data,
                    ['cart_id' => $cartId, 'product_id' => $item->productId()->value()],
                );
            } else {
                // Insert new
                $this->conn->insert('cart_items', array_merge($data, [
                    'id'         => $this->idGenerator->generate(),
                    'cart_id'    => $cartId,
                    'product_id' => $item->productId()->value(),
                ]));
            }
        }
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $prop = new \ReflectionProperty($object, $property);
        $prop->setValue($object, $value);
    }
}
