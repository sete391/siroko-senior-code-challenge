<?php

declare(strict_types=1);

namespace Siroko\Sales\Domain\Exception;

use Siroko\Sales\Domain\ValueObject\CartId;
use Siroko\Shared\Domain\Exception\DomainException;

final class CartOwnershipMismatch extends DomainException
{
    public static function forCart(CartId $id): self
    {
        return new self(sprintf('Access to cart "%s" is not allowed for the given customer.', $id->value()));
    }
}
