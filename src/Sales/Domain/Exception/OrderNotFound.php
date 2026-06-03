<?php

declare(strict_types=1);

namespace Siroko\Sales\Domain\Exception;

use Siroko\Sales\Domain\ValueObject\OrderId;
use Siroko\Shared\Domain\Exception\DomainException;

final class OrderNotFound extends DomainException
{
    public static function withId(OrderId $id): self
    {
        return new self(sprintf('Order "%s" not found.', $id->value()));
    }
}
