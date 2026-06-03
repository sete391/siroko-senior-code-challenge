<?php

declare(strict_types=1);

namespace Siroko\Sales\Domain\Exception;

use Siroko\Sales\Domain\ValueObject\OrderId;
use Siroko\Shared\Domain\Exception\DomainException;

final class OrderOwnershipMismatch extends DomainException
{
    public static function forOrder(OrderId $id): self
    {
        return new self(sprintf('Access to order "%s" is not allowed for the given customer.', $id->value()));
    }
}
