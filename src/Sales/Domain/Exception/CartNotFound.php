<?php

declare(strict_types=1);

namespace Siroko\Sales\Domain\Exception;

use Siroko\Sales\Domain\ValueObject\CartId;
use Siroko\Shared\Domain\Exception\DomainException;

final class CartNotFound extends DomainException
{
    public static function withId(CartId $id): self
    {
        return new self(sprintf('Cart "%s" not found.', $id->value()));
    }
}
