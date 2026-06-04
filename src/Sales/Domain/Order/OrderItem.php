<?php

declare(strict_types=1);

namespace Siroko\Sales\Domain\Order;

use Siroko\Catalog\Domain\ProductId;
use Siroko\Shared\Domain\ValueObject\Money;
use Siroko\Shared\Domain\ValueObject\Quantity;

final class OrderItem
{
    public function __construct(
        private readonly ProductId $productId,
        private readonly Money $unitPrice,
        private readonly int $taxAmount,
        private readonly Quantity $quantity,
    ) {
    }

    public function productId(): ProductId
    {
        return $this->productId;
    }

    public function unitPrice(): Money
    {
        return $this->unitPrice;
    }

    public function taxAmount(): int
    {
        return $this->taxAmount;
    }

    public function quantity(): int
    {
        return $this->quantity->value;
    }

    public function lineTotal(): Money
    {
        return $this->unitPrice->multiply($this->quantity->value);
    }

    public function lineTax(): int
    {
        return $this->taxAmount * $this->quantity->value;
    }
}
