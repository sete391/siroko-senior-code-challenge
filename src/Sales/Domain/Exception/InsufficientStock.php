<?php

declare(strict_types=1);

namespace Siroko\Sales\Domain\Exception;

use Siroko\Catalog\Domain\ProductId;
use Siroko\Shared\Domain\Exception\DomainException;

final class InsufficientStock extends DomainException
{
    public static function forProduct(ProductId $productId, int $requested, int $available): self
    {
        return new self(sprintf(
            'Insufficient stock for product "%s": requested %d, available %d.',
            $productId->value(),
            $requested,
            $available,
        ));
    }
}
