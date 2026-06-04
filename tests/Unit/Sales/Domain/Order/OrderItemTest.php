<?php

declare(strict_types=1);

namespace Siroko\Tests\Unit\Sales\Domain\Order;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siroko\Catalog\Domain\ProductId;
use Siroko\Sales\Domain\Order\OrderItem;
use Siroko\Shared\Domain\ValueObject\Money;
use Siroko\Shared\Domain\ValueObject\Quantity;

final class OrderItemTest extends TestCase
{
    private const PRODUCT_ID = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';

    // ------------------------------------------------------------------ lineTotal

    #[Test]
    #[DataProvider('lineTotalCases')]
    public function it_computes_line_total_as_unit_price_times_quantity(
        int $unitPriceAmount,
        int $quantity,
        int $expectedTotal,
    ): void {
        $item = $this->makeItem(unitPrice: new Money($unitPriceAmount), quantity: $quantity);

        self::assertSame($expectedTotal, $item->lineTotal()->amount);
        self::assertSame(Money::DEFAULT_CURRENCY, $item->lineTotal()->currency);
    }

    /** @return array<string, array{int, int, int}> */
    public static function lineTotalCases(): array
    {
        return [
            'single unit'       => [4500, 1, 4500],
            'two units'         => [4500, 2, 9000],
            'fractional cents'  => [333,  3, 999],
            'large quantity'    => [100,  100, 10000],
        ];
    }

    // ------------------------------------------------------------------ lineTax

    #[Test]
    #[DataProvider('lineTaxCases')]
    public function it_computes_line_tax_as_tax_amount_times_quantity(
        int $taxAmount,
        int $quantity,
        int $expectedTax,
    ): void {
        $item = $this->makeItem(taxAmount: $taxAmount, quantity: $quantity);

        self::assertSame($expectedTax, $item->lineTax());
    }

    /** @return array<string, array{int, int, int}> */
    public static function lineTaxCases(): array
    {
        return [
            'single unit'  => [945, 1, 945],
            'two units'    => [945, 2, 1890],
            'zero tax'     => [0,   3, 0],
            'large qty'    => [100, 10, 1000],
        ];
    }

    // ------------------------------------------------------------------ accessors

    #[Test]
    public function it_exposes_all_fields(): void
    {
        $price = new Money(4500);
        $item  = $this->makeItem(unitPrice: $price, taxAmount: 945, quantity: 2);

        self::assertSame(self::PRODUCT_ID, $item->productId()->value());
        self::assertTrue($item->unitPrice()->equals($price));
        self::assertSame(945, $item->taxAmount());
        self::assertSame(2, $item->quantity());
    }

    // ------------------------------------------------------------------ helpers

    private function makeItem(
        Money $unitPrice = new Money(4500),
        int $taxAmount   = 945,
        int $quantity    = 1,
    ): OrderItem {
        return new OrderItem(
            productId: new ProductId(self::PRODUCT_ID),
            unitPrice: $unitPrice,
            taxAmount: $taxAmount,
            quantity:  new Quantity($quantity),
        );
    }
}
