<?php

declare(strict_types=1);

namespace Siroko\Sales\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Siroko\Catalog\Domain\ProductId;
use Siroko\Sales\Domain\Order\Order;
use Siroko\Sales\Domain\Order\OrderItem;
use Siroko\Sales\Domain\Order\OrderRepository;
use Siroko\Sales\Domain\ValueObject\OrderId;
use Siroko\Shared\Application\Identity\IdGenerator;
use Siroko\Shared\Domain\ValueObject\Money;
use Siroko\Shared\Domain\ValueObject\Quantity;

final class DoctrineOrderRepository implements OrderRepository
{
    private Connection $conn;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IdGenerator $idGenerator,
    ) {
        $this->conn = $em->getConnection();
    }

    public function findById(OrderId $id): ?Order
    {
        $order = $this->em->find(Order::class, $id->value());

        if (null === $order) {
            return null;
        }

        $this->hydrateItems($order);

        return $order;
    }

    public function save(Order $order): void
    {
        $this->em->persist($order);
        $this->syncItems($order);
        $this->em->flush();
    }

    private function hydrateItems(Order $order): void
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT product_id, unit_price_amount, unit_price_currency, tax_amount, quantity
               FROM order_items
              WHERE order_id = :orderId',
            ['orderId' => $order->id()->value()],
        );

        $items = array_map(
            static fn(array $row) => new OrderItem(
                new ProductId($row['product_id']),
                new Money((int) $row['unit_price_amount'], $row['unit_price_currency']),
                (int) $row['tax_amount'],
                new Quantity((int) $row['quantity']),
            ),
            $rows,
        );

        $this->setPrivateProperty($order, 'items', $items);
    }

    private function syncItems(Order $order): void
    {
        $orderId = $order->id()->value();

        $existing = $this->conn->fetchAllAssociative(
            'SELECT product_id FROM order_items WHERE order_id = :orderId',
            ['orderId' => $orderId],
        );
        $existingProductIds = array_column($existing, 'product_id');

        foreach ($order->items() as $item) {
            if (in_array($item->productId()->value(), $existingProductIds, true)) {
                continue; // Order items are immutable once placed
            }

            $this->conn->insert('order_items', [
                'id'                  => $this->idGenerator->generate(),
                'order_id'            => $orderId,
                'product_id'          => $item->productId()->value(),
                'unit_price_amount'   => $item->unitPrice()->amount,
                'unit_price_currency' => $item->unitPrice()->currency,
                'tax_amount'          => $item->taxAmount(),
                'quantity'            => $item->quantity(),
            ]);
        }
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $prop = new \ReflectionProperty($object, $property);
        $prop->setValue($object, $value);
    }
}
