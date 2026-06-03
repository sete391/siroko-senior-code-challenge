<?php

declare(strict_types=1);

namespace Siroko\Tests\Unit\Shared\Domain\ValueObject;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siroko\Shared\Domain\ValueObject\Money;

final class MoneyTest extends TestCase
{
    // ------------------------------------------------------------------ construction

    #[Test]
    public function it_creates_money_with_explicit_currency(): void
    {
        $money = new Money(1000, 'USD');

        self::assertSame(1000, $money->amount);
        self::assertSame('USD', $money->currency);
    }

    #[Test]
    public function it_defaults_to_eur(): void
    {
        $money = new Money(500);

        self::assertSame(Money::DEFAULT_CURRENCY, $money->currency);
    }

    #[Test]
    public function it_allows_zero_amount(): void
    {
        $money = new Money(0);

        self::assertSame(0, $money->amount);
    }

    #[Test]
    public function it_rejects_negative_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot be negative/i');

        new Money(-1);
    }

    #[Test]
    public function it_rejects_empty_currency(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/currency cannot be empty/i');

        new Money(100, '');
    }

    // ------------------------------------------------------------------ add

    #[Test]
    public function it_adds_two_amounts_of_the_same_currency(): void
    {
        $result = (new Money(300))->add(new Money(200));

        self::assertSame(500, $result->amount);
        self::assertSame('EUR', $result->currency);
    }

    #[Test]
    public function it_returns_a_new_instance_on_add(): void
    {
        $a = new Money(100);
        $b = new Money(50);

        self::assertNotSame($a, $a->add($b));
    }

    #[Test]
    public function it_rejects_add_with_different_currencies(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/different currencies/i');

        (new Money(100, 'EUR'))->add(new Money(100, 'USD'));
    }

    // ------------------------------------------------------------------ subtract

    #[Test]
    public function it_subtracts_a_smaller_amount(): void
    {
        $result = (new Money(500))->subtract(new Money(200));

        self::assertSame(300, $result->amount);
    }

    #[Test]
    public function it_subtracts_equal_amounts_to_zero(): void
    {
        $result = (new Money(200))->subtract(new Money(200));

        self::assertSame(0, $result->amount);
    }

    #[Test]
    public function it_rejects_subtract_that_would_go_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/negative amount/i');

        (new Money(100))->subtract(new Money(200));
    }

    #[Test]
    public function it_rejects_subtract_with_different_currencies(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/different currencies/i');

        (new Money(500, 'EUR'))->subtract(new Money(100, 'USD'));
    }

    // ------------------------------------------------------------------ multiply

    #[Test]
    #[DataProvider('positiveFactors')]
    public function it_multiplies_by_a_non_negative_factor(int $factor, int $expected): void
    {
        $result = (new Money(100))->multiply($factor);

        self::assertSame($expected, $result->amount);
        self::assertSame('EUR', $result->currency);
    }

    /** @return array<string, array{int, int}> */
    public static function positiveFactors(): array
    {
        return [
            'zero factor yields zero'   => [0, 0],
            'factor of one is identity' => [1, 100],
            'factor of three'           => [3, 300],
        ];
    }

    #[Test]
    public function it_rejects_negative_multiplication_factor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/factor cannot be negative/i');

        (new Money(100))->multiply(-1);
    }

    #[Test]
    public function it_returns_a_new_instance_on_multiply(): void
    {
        $money = new Money(100);

        self::assertNotSame($money, $money->multiply(2));
    }

    // ------------------------------------------------------------------ equals

    #[Test]
    public function it_is_equal_when_amount_and_currency_match(): void
    {
        self::assertTrue((new Money(100, 'EUR'))->equals(new Money(100, 'EUR')));
    }

    #[Test]
    public function it_is_not_equal_when_amounts_differ(): void
    {
        self::assertFalse((new Money(100))->equals(new Money(200)));
    }

    #[Test]
    public function it_is_not_equal_when_currencies_differ(): void
    {
        self::assertFalse((new Money(100, 'EUR'))->equals(new Money(100, 'USD')));
    }
}
