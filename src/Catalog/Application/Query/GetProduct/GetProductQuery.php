<?php

declare(strict_types=1);

namespace Siroko\Catalog\Application\Query\GetProduct;

use Siroko\Shared\Application\Query\Query;

final class GetProductQuery implements Query
{
    public function __construct(
        public readonly string $productId,
    ) {
    }
}
