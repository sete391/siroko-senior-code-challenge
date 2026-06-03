<?php

declare(strict_types=1);

namespace Siroko\Catalog\Application\DTO;

use Siroko\Catalog\Domain\Product;

final class ProductView
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description,
        public readonly int $taxAmount,
        public readonly int $unitPriceAmount,
        public readonly string $unitPriceCurrency,
        public readonly int $quantity,
        public readonly string $status,
    ) {
    }

    public static function fromProduct(Product $product): self
    {
        return new self(
            id:                $product->id()->value(),
            name:              $product->name(),
            description:       $product->description(),
            taxAmount:         $product->taxAmount(),
            unitPriceAmount:   $product->unitPrice()->amount,
            unitPriceCurrency: $product->unitPrice()->currency,
            quantity:          $product->quantity(),
            status:            $product->status()->value,
        );
    }
}
