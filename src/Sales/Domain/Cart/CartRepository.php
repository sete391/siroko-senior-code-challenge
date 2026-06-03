<?php

declare(strict_types=1);

namespace Siroko\Sales\Domain\Cart;

use Siroko\Sales\Domain\ValueObject\CartId;

interface CartRepository
{
    public function findById(CartId $id): ?Cart;

    public function save(Cart $cart): void;
}
