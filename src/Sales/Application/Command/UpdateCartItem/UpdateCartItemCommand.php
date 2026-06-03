<?php

declare(strict_types=1);

namespace Siroko\Sales\Application\Command\UpdateCartItem;

use Siroko\Shared\Application\Command\Command;

final class UpdateCartItemCommand implements Command
{
    public function __construct(
        public readonly string $cartId,
        public readonly ?string $customerId,
        public readonly string $productId,
        public readonly int $quantity,
    ) {
    }
}
