<?php

declare(strict_types=1);

namespace Siroko\Sales\Application\Query\GetOrder;

use Siroko\Shared\Application\Query\Query;

final class GetOrderQuery implements Query
{
    public function __construct(
        public readonly string $orderId,
        public readonly ?string $customerId,
    ) {
    }
}
