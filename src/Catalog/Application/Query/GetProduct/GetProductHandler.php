<?php

declare(strict_types=1);

namespace Siroko\Catalog\Application\Query\GetProduct;

use Siroko\Catalog\Application\DTO\ProductView;
use Siroko\Catalog\Domain\Exception\ProductNotActive;
use Siroko\Catalog\Domain\Exception\ProductNotFound;
use Siroko\Catalog\Domain\ProductId;
use Siroko\Catalog\Domain\ProductRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class GetProductHandler
{
    public function __construct(
        private readonly ProductRepository $repository,
    ) {
    }

    public function __invoke(GetProductQuery $query): ProductView
    {
        $productId = new ProductId($query->productId);
        $product   = $this->repository->findById($productId);

        if (null === $product) {
            throw ProductNotFound::withId($productId);
        }

        if (!$product->isActive()) {
            throw ProductNotActive::withId($productId);
        }

        return ProductView::fromProduct($product);
    }
}
