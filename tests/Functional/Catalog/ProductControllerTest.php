<?php

declare(strict_types=1);

namespace Siroko\Tests\Functional\Catalog;

use PHPUnit\Framework\Attributes\Test;
use Siroko\Catalog\Domain\ProductStatus;
use Siroko\Tests\Functional\FunctionalTestCase;

final class ProductControllerTest extends FunctionalTestCase
{
    // ------------------------------------------------------------------ GET /api/products

    #[Test]
    public function list_products_returns_200_with_active_products(): void
    {
        $product = $this->seedProduct(priceAmount: 4500, taxAmount: 945, stock: 50);

        $body = $this->json('GET', '/api/products');

        self::assertSame(200, $this->statusCode());
        self::assertIsArray($body);

        $ids = array_column($body, 'id');
        self::assertContains($product->id()->value(), $ids);
    }

    #[Test]
    public function list_products_does_not_include_inactive_products(): void
    {
        $inactive = $this->seedProduct(status: ProductStatus::INACTIVE);

        $body = $this->json('GET', '/api/products');

        self::assertSame(200, $this->statusCode());
        $ids = array_column($body, 'id');
        self::assertNotContains($inactive->id()->value(), $ids);
    }

    #[Test]
    public function list_products_response_contains_expected_fields(): void
    {
        $product = $this->seedProduct(priceAmount: 6000, taxAmount: 1260, stock: 10);

        $body = $this->json('GET', '/api/products');

        $found = null;
        foreach ($body as $item) {
            if ($item['id'] === $product->id()->value()) {
                $found = $item;
                break;
            }
        }

        self::assertNotNull($found);
        self::assertArrayHasKey('id',                $found);
        self::assertArrayHasKey('name',              $found);
        self::assertArrayHasKey('description',       $found);
        self::assertArrayHasKey('taxAmount',         $found);
        self::assertArrayHasKey('unitPriceAmount',   $found);
        self::assertArrayHasKey('unitPriceCurrency', $found);
        self::assertArrayHasKey('quantity',          $found);
        self::assertArrayHasKey('status',            $found);
        self::assertSame(6000,   $found['unitPriceAmount']);
        self::assertSame('EUR',  $found['unitPriceCurrency']);
        self::assertSame('ACTIVE', $found['status']);
    }

    // ------------------------------------------------------------------ GET /api/products/{productId}

    #[Test]
    public function get_product_returns_200_with_correct_fields(): void
    {
        $product = $this->seedProduct(priceAmount: 4500, taxAmount: 945, stock: 25);

        $body = $this->json('GET', '/api/products/' . $product->id()->value());

        self::assertSame(200, $this->statusCode());
        self::assertSame($product->id()->value(), $body['id']);
        self::assertSame(4500,     $body['unitPriceAmount']);
        self::assertSame('EUR',    $body['unitPriceCurrency']);
        self::assertSame(945,      $body['taxAmount']);
        self::assertSame(25,       $body['quantity']);
        self::assertSame('ACTIVE', $body['status']);
    }

    #[Test]
    public function get_product_returns_404_for_unknown_id(): void
    {
        $body = $this->json('GET', '/api/products/00000000-0000-4000-8000-000000000099');

        self::assertSame(404, $this->statusCode());
        self::assertSame('product_not_found', $body['error']);
        self::assertArrayHasKey('message', $body);
    }

    #[Test]
    public function get_product_returns_404_for_inactive_product(): void
    {
        $inactive = $this->seedProduct(status: ProductStatus::INACTIVE);

        $body = $this->json('GET', '/api/products/' . $inactive->id()->value());

        self::assertSame(404, $this->statusCode());
        self::assertSame('product_not_active', $body['error']);
    }
}
