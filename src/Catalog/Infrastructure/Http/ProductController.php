<?php

declare(strict_types=1);

namespace Siroko\Catalog\Infrastructure\Http;

use Siroko\Catalog\Application\Query\GetProduct\GetProductQuery;
use Siroko\Catalog\Application\Query\ListProducts\ListProductsQuery;
use Siroko\Shared\Infrastructure\Http\ApiController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/products')]
final class ProductController extends ApiController
{
    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $products = $this->ask(new ListProductsQuery());

        return new JsonResponse($products);
    }

    #[Route('/{productId}', methods: ['GET'])]
    public function get(string $productId): JsonResponse
    {
        $product = $this->ask(new GetProductQuery($productId));

        return new JsonResponse($product);
    }
}
