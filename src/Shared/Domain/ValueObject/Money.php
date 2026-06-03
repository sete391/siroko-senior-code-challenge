<?php

declare(strict_types=1);

namespace Siroko\Shared\Domain\ValueObject;

final class Money
{
    public const DEFAULT_CURRENCY = 'EUR';

    public function __construct(
        public readonly int $amount,
        public readonly string $currency = self::DEFAULT_CURRENCY,
    ) {
        if ($amount < 0) {
            throw new \InvalidArgumentException(
                sprintf('Money amount cannot be negative, %d given.', $amount),
            );
        }

        if ('' === $currency) {
            throw new \InvalidArgumentException('Money currency cannot be empty.');
        }
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        $result = $this->amount - $other->amount;

        if ($result < 0) {
            throw new \InvalidArgumentException(
                'Money subtraction would result in a negative amount.',
            );
        }

        return new self($result, $this->currency);
    }

    public function multiply(int $factor): self
    {
        if ($factor < 0) {
            throw new \InvalidArgumentException(
                sprintf('Money multiplication factor cannot be negative, %d given.', $factor),
            );
        }

        return new self($this->amount * $factor, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount
            && $this->currency === $other->currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Cannot operate on different currencies: %s and %s.',
                    $this->currency,
                    $other->currency,
                ),
            );
        }
    }
}
