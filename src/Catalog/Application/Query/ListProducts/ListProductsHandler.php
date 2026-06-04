<?php

declare(strict_types=1);

namespace Siroko\Catalog\Application\Query\ListProducts;

use Siroko\Catalog\Application\DTO\ProductView;
use Siroko\Catalog\Domain\Product;
use Siroko\Catalog\Domain\ProductRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class ListProductsHandler
{
    public function __construct(
        private readonly ProductRepository $repository,
    ) {
    }

    /** @return list<ProductView> */
    public function __invoke(ListProductsQuery $query): array
    {
        return array_map(
            static fn(Product $p) => ProductView::fromProduct($p),
            $this->repository->findAllActive(),
        );
    }
}
