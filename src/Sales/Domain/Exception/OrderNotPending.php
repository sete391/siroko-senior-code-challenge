<?php

declare(strict_types=1);

namespace Siroko\Sales\Domain\Exception;

use Siroko\Sales\Domain\ValueObject\OrderId;
use Siroko\Shared\Domain\Exception\DomainException;

final class OrderNotPending extends DomainException
{
    public static function withId(OrderId $id): self
    {
        return new self(sprintf('Order "%s" is not in PENDING status.', $id->value()));
    }
}
