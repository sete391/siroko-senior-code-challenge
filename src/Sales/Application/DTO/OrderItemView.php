<?php

declare(strict_types=1);

namespace Siroko\Sales\Application\DTO;

use Siroko\Sales\Domain\Order\OrderItem;

final class OrderItemView
{
    public function __construct(
        public readonly string $productId,
        public readonly int $unitPriceAmount,
        public readonly string $unitPriceCurrency,
        public readonly int $taxAmount,
        public readonly int $quantity,
        public readonly int $lineTotalAmount,
        public readonly int $lineTaxAmount,
    ) {
    }

    public static function fromOrderItem(OrderItem $item): self
    {
        return new self(
            productId:         $item->productId()->value(),
            unitPriceAmount:   $item->unitPrice()->amount,
            unitPriceCurrency: $item->unitPrice()->currency,
            taxAmount:         $item->taxAmount(),
            quantity:          $item->quantity(),
            lineTotalAmount:   $item->lineTotal()->amount,
            lineTaxAmount:     $item->lineTax(),
        );
    }
}
