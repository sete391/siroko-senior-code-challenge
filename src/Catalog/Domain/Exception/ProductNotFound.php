<?php

declare(strict_types=1);

namespace Siroko\Catalog\Domain\Exception;

use Siroko\Catalog\Domain\ProductId;
use Siroko\Shared\Domain\Exception\DomainException;

final class ProductNotFound extends DomainException
{
    public static function withId(ProductId $id): self
    {
        return new self(sprintf('Product "%s" not found.', $id->value()));
    }
}
