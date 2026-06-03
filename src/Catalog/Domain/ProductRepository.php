<?php

declare(strict_types=1);

namespace Siroko\Catalog\Domain;

interface ProductRepository
{
    public function findById(ProductId $id): ?Product;

    /** @return list<Product> */
    public function findAllActive(): array;

    public function save(Product $product): void;
}
