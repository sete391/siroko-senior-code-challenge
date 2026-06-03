<?php

declare(strict_types=1);

namespace Siroko\Tests\Unit\Catalog\Application;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siroko\Catalog\Application\DTO\ProductView;
use Siroko\Catalog\Application\Query\GetProduct\GetProductHandler;
use Siroko\Catalog\Application\Query\GetProduct\GetProductQuery;
use Siroko\Catalog\Domain\Exception\ProductNotActive;
use Siroko\Catalog\Domain\Exception\ProductNotFound;
use Siroko\Catalog\Domain\Product;
use Siroko\Catalog\Domain\ProductId;
use Siroko\Catalog\Domain\ProductRepository;
use Siroko\Catalog\Domain\ProductStatus;
use Siroko\Shared\Domain\ValueObject\Money;

final class GetProductHandlerTest extends TestCase
{
    private const PID = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';

    #[Test]
    public function it_returns_a_product_view_for_an_active_product(): void
    {
        $product = $this->product(ProductStatus::ACTIVE);
        $repo    = $this->createMock(ProductRepository::class);
        $repo->method('findById')->willReturn($product);

        $view = (new GetProductHandler($repo))(new GetProductQuery(self::PID));

        self::assertInstanceOf(ProductView::class, $view);
        self::assertSame(self::PID, $view->id);
    }

    #[Test]
    public function it_throws_product_not_found_when_product_is_missing(): void
    {
        $this->expectException(ProductNotFound::class);

        $repo = $this->createMock(ProductRepository::class);
        $repo->method('findById')->willReturn(null);

        (new GetProductHandler($repo))(new GetProductQuery(self::PID));
    }

    #[Test]
    public function it_throws_product_not_active_when_product_is_inactive(): void
    {
        $this->expectException(ProductNotActive::class);

        $repo = $this->createMock(ProductRepository::class);
        $repo->method('findById')->willReturn($this->product(ProductStatus::INACTIVE));

        (new GetProductHandler($repo))(new GetProductQuery(self::PID));
    }

    private function product(ProductStatus $status): Product
    {
        return new Product(
            id:          new ProductId(self::PID),
            name:        'Test',
            description: 'desc',
            taxAmount:   0,
            unitPrice:   new Money(1000),
            quantity:    5,
            status:      $status,
            createdAt:   new \DateTimeImmutable(),
            updatedAt:   new \DateTimeImmutable(),
        );
    }
}
