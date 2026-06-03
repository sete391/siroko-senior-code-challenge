<?php

declare(strict_types=1);

namespace Siroko\Sales\Application\DTO;

use Siroko\Sales\Domain\Cart\CartItem;

final class CartItemView
{
    public function __construct(
        public readonly string $productId,
        public readonly int $unitPriceAmount,
        public readonly string $unitPriceCurrency,
        public readonly int $taxAmount,
        public readonly int $quantity,
    ) {
    }

    public static function fromCartItem(CartItem $item): self
    {
        return new self(
            productId:         $item->productId()->value(),
            unitPriceAmount:   $item->unitPrice()->amount,
            unitPriceCurrency: $item->unitPrice()->currency,
            taxAmount:         $item->taxAmount(),
            quantity:          $item->quantity(),
        );
    }
}
