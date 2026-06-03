<?php

declare(strict_types=1);

namespace Siroko\Sales\Domain\Exception;

use Siroko\Catalog\Domain\ProductId;
use Siroko\Sales\Domain\ValueObject\CartId;
use Siroko\Shared\Domain\Exception\DomainException;

final class CartItemNotFound extends DomainException
{
    public static function forProduct(CartId $cartId, ProductId $productId): self
    {
        return new self(sprintf(
            'Product "%s" is not in cart "%s".',
            $productId->value(),
            $cartId->value(),
        ));
    }
}
