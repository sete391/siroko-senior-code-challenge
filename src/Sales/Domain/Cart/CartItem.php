<?php

declare(strict_types=1);

namespace Siroko\Sales\Domain\Cart;

use Siroko\Catalog\Domain\ProductId;
use Siroko\Shared\Domain\ValueObject\Money;
use Siroko\Shared\Domain\ValueObject\Quantity;

final class CartItem
{
    public function __construct(
        private readonly ProductId $productId,
        private Money $unitPrice,
        private int $taxAmount,
        private Quantity $quantity,
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

    public function quantityValue(): Quantity
    {
        return $this->quantity;
    }

    public function updateQuantity(Quantity $quantity): void
    {
        $this->quantity = $quantity;
    }

    public function refreshSnapshot(Money $unitPrice, int $taxAmount): void
    {
        $this->unitPrice = $unitPrice;
        $this->taxAmount = $taxAmount;
    }
}
