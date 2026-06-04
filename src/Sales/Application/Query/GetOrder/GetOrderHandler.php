<?php

declare(strict_types=1);

namespace Siroko\Sales\Application\Query\GetOrder;

use Siroko\Sales\Application\DTO\OrderView;
use Siroko\Sales\Domain\Exception\OrderNotFound;
use Siroko\Sales\Domain\Order\OrderRepository;
use Siroko\Sales\Domain\ValueObject\CustomerId;
use Siroko\Sales\Domain\ValueObject\OrderId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class GetOrderHandler
{
    public function __construct(
        private readonly OrderRepository $repository,
    ) {
    }

    public function __invoke(GetOrderQuery $query): OrderView
    {
        $orderId = new OrderId($query->orderId);
        $order   = $this->repository->findById($orderId);

        if (null === $order) {
            throw OrderNotFound::withId($orderId);
        }

        $order->assertOwnedBy(
            null !== $query->customerId ? new CustomerId($query->customerId) : null,
        );

        return OrderView::fromOrder($order);
    }
}
