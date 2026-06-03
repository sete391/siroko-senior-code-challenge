<?php

declare(strict_types=1);

namespace Siroko\Shared\Domain\ValueObject;

final class Quantity
{
    public function __construct(public readonly int $value)
    {
        if ($value < 1) {
            throw new \InvalidArgumentException(
                sprintf('Quantity must be at least 1, %d given.', $value),
            );
        }
    }

    public function add(self $other): self
    {
        return new self($this->value + $other->value);
    }

    public function isGreaterThan(int $stock): bool
    {
        return $this->value > $stock;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
