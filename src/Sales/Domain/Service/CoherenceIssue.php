<?php

declare(strict_types=1);

namespace Siroko\Sales\Domain\Service;

use Siroko\Catalog\Domain\ProductId;

final class CoherenceIssue
{
    public const REASON_PRODUCT_NOT_FOUND   = 'product_not_found';
    public const REASON_PRODUCT_INACTIVE    = 'product_inactive';
    public const REASON_PRICE_CHANGED       = 'price_changed';
    public const REASON_INSUFFICIENT_STOCK  = 'insufficient_stock';

    public function __construct(
        public readonly ProductId $productId,
        public readonly string $reason,
    ) {
    }
}
