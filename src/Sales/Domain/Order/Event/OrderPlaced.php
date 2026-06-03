<?php

declare(strict_types=1);

namespace Siroko\Sales\Domain\Order\Event;

use Siroko\Sales\Domain\ValueObject\CartId;
use Siroko\Sales\Domain\ValueObject\OrderId;
use Siroko\Shared\Domain\Event\DomainEvent;

final class OrderPlaced implements DomainEvent
{
    public function __construct(
        private readonly OrderId $orderId,
        private readonly CartId $cartId,
        private readonly \DateTimeImmutable $occurredOn,
    ) {
    }

    public function occurredOn(): \DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public function orderId(): OrderId
    {
        return $this->orderId;
    }

    public function cartId(): CartId
    {
        return $this->cartId;
    }
}
