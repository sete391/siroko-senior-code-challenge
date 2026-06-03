<?php

declare(strict_types=1);

namespace Siroko\Sales\Domain\Cart\Event;

use Siroko\Sales\Domain\ValueObject\CartId;
use Siroko\Shared\Domain\Event\DomainEvent;

final class CartCheckedOut implements DomainEvent
{
    public function __construct(
        private readonly CartId $cartId,
        private readonly \DateTimeImmutable $occurredOn,
    ) {
    }

    public function occurredOn(): \DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public function cartId(): CartId
    {
        return $this->cartId;
    }
}
