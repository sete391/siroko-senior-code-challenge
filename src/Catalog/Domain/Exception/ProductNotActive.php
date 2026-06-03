<?php

declare(strict_types=1);

namespace Siroko\Catalog\Domain\Exception;

use Siroko\Catalog\Domain\ProductId;
use Siroko\Shared\Domain\Exception\DomainException;

final class ProductNotActive extends DomainException
{
    public static function withId(ProductId $id): self
    {
        return new self(sprintf('Product "%s" is not active.', $id->value()));
    }
}
