<?php

declare(strict_types=1);

namespace Siroko\Sales\Application\Command\AddItemToCart;

use Siroko\Shared\Application\Command\Command;

final class AddItemToCartCommand implements Command
{
    public function __construct(
        public readonly string $cartId,
        public readonly ?string $customerId,
        public readonly string $productId,
        public readonly int $quantity,
    ) {
    }
}
