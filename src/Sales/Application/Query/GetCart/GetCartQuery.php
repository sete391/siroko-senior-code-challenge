<?php

declare(strict_types=1);

namespace Siroko\Sales\Application\Query\GetCart;

use Siroko\Shared\Application\Query\Query;

final class GetCartQuery implements Query
{
    public function __construct(
        public readonly string $cartId,
        public readonly ?string $customerId,
    ) {
    }
}
