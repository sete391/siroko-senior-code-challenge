<?php

declare(strict_types=1);

namespace Siroko\Tests\Unit\Catalog\Application;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siroko\Catalog\Application\DTO\ProductView;
use Siroko\Catalog\Application\Query\ListProducts\ListProductsHandler;
use Siroko\Catalog\Application\Query\ListProducts\ListProductsQuery;
use Siroko\Catalog\Domain\Product;
use Siroko\Catalog\Domain\ProductId;
use Siroko\Catalog\Domain\ProductRepository;
use Siroko\Catalog\Domain\ProductStatus;
use Siroko\Shared\Domain\ValueObject\Money;

final class ListProductsHandlerTest extends TestCase
{
    private const PID_1 = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
    private const PID_2 = 'a47ac10b-58cc-4372-a567-0e02b2c3d479';

    #[Test]
    public function it_returns_all_active_products_as_views(): void
    {
        $p1   = $this->product(self::PID_1, 'Helmet', 8900);
        $p2   = $this->product(self::PID_2, 'Gloves', 2500);
        $repo = $this->createMock(ProductRepository::class);
        $repo->method('findAllActive')->willReturn([$p1, $p2]);

        $result = (new ListProductsHandler($repo))(new ListProductsQuery());

        self::assertCount(2, $result);
        self::assertSame(self::PID_1, $result[0]->id);
        self::assertSame(self::PID_2, $result[1]->id);
    }

    #[Test]
    public function it_returns_empty_array_when_no_active_products(): void
    {
        $repo = $this->createMock(ProductRepository::class);
        $repo->method('findAllActive')->willReturn([]);

        $result = (new ListProductsHandler($repo))(new ListProductsQuery());

        self::assertSame([], $result);
    }

    #[Test]
    public function it_maps_all_product_fields_to_the_view(): void
    {
        $product = $this->product(self::PID_1, 'Siroko K38', 4500, tax: 945, stock: 10);
        $repo    = $this->createMock(ProductRepository::class);
        $repo->method('findAllActive')->willReturn([$product]);

        $view = (new ListProductsHandler($repo))(new ListProductsQuery())[0];

        self::assertSame(self::PID_1, $view->id);
        self::assertSame('Siroko K38', $view->name);
        self::assertSame(4500, $view->unitPriceAmount);
        self::assertSame('EUR', $view->unitPriceCurrency);
        self::assertSame(945, $view->taxAmount);
        self::assertSame(10, $view->quantity);
        self::assertSame('ACTIVE', $view->status);
    }

    private function product(
        string $id,
        string $name,
        int $price,
        int $tax = 0,
        int $stock = 5,
    ): Product {
        return new Product(
            id:          new ProductId($id),
            name:        $name,
            description: 'desc',
            taxAmount:   $tax,
            unitPrice:   new Money($price),
            quantity:    $stock,
            status:      ProductStatus::ACTIVE,
            createdAt:   new \DateTimeImmutable(),
            updatedAt:   new \DateTimeImmutable(),
        );
    }
}
