<?php

declare(strict_types=1);

namespace Siroko\Catalog\Domain;

use Siroko\Shared\Domain\ValueObject\Money;

final class Product
{
    public function __construct(
        private readonly ProductId $id,
        private readonly string $name,
        private readonly string $description,
        private readonly int $taxAmount,
        private readonly Money $unitPrice,
        private int $quantity,
        private ProductStatus $status,
        private readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
    ) {
        if ($unitPrice->amount <= 0) {
            throw new \InvalidArgumentException(
                sprintf('Product price must be greater than zero, %d given.', $unitPrice->amount),
            );
        }

        if ($quantity < 0) {
            throw new \InvalidArgumentException(
                sprintf('Product stock cannot be negative, %d given.', $quantity),
            );
        }
    }

    public function id(): ProductId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function taxAmount(): int
    {
        return $this->taxAmount;
    }

    public function unitPrice(): Money
    {
        return $this->unitPrice;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function status(): ProductStatus
    {
        return $this->status;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isActive(): bool
    {
        return ProductStatus::ACTIVE === $this->status;
    }

    public function isAvailableFor(int $qty): bool
    {
        return $this->quantity >= $qty;
    }

    public function decreaseStock(int $amount): void
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException(
                sprintf('Decrease amount cannot be negative, %d given.', $amount),
            );
        }

        $result = $this->quantity - $amount;

        if ($result < 0) {
            throw new \InvalidArgumentException(
                sprintf('Cannot decrease stock by %d, only %d available.', $amount, $this->quantity),
            );
        }

        $this->quantity  = $result;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function restoreStock(int $amount): void
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException(
                sprintf('Restore amount cannot be negative, %d given.', $amount),
            );
        }

        $this->quantity += $amount;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
