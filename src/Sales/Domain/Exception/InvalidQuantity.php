<?php

declare(strict_types=1);

namespace Siroko\Sales\Domain\Exception;

use Siroko\Shared\Domain\Exception\DomainException;

final class InvalidQuantity extends DomainException
{
    public static function mustBeAtLeastOne(int $given): self
    {
        return new self(sprintf('Quantity must be at least 1, %d given.', $given));
    }

    public static function mustBeNonNegative(int $given): self
    {
        return new self(sprintf('Quantity must be 0 or greater, %d given.', $given));
    }
}
