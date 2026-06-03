<?php

declare(strict_types=1);

namespace Siroko\Sales\Domain\Exception;

use Siroko\Shared\Domain\Exception\DomainException;

final class InvalidShippingAddress extends DomainException
{
    public static function emptyField(string $fieldName): self
    {
        return new self(sprintf('Shipping address field "%s" cannot be empty.', $fieldName));
    }
}
