<?php

declare(strict_types=1);

namespace Siroko\Sales\Domain\Cart;

use Siroko\Catalog\Domain\ProductId;
use Siroko\Sales\Domain\Cart\Event\CartCheckedOut;
use Siroko\Sales\Domain\Cart\Event\CartItemAdded;
use Siroko\Sales\Domain\Cart\Event\CartItemQuantityUpdated;
use Siroko\Sales\Domain\Cart\Event\CartItemRemoved;
use Siroko\Sales\Domain\Exception\CartItemNotFound;
use Siroko\Sales\Domain\Exception\CartNotModifiable;
use Siroko\Sales\Domain\Exception\CartOwnershipMismatch;
use Siroko\Sales\Domain\Exception\InsufficientStock;
use Siroko\Sales\Domain\Exception\InvalidQuantity;
use Siroko\Sales\Domain\ValueObject\CartId;
use Siroko\Sales\Domain\ValueObject\CustomerId;
use Siroko\Shared\Domain\Event\DomainEventRecorderTrait;
use Siroko\Shared\Domain\ValueObject\Money;
use Siroko\Shared\Domain\ValueObject\Quantity;

final class Cart
{
    use DomainEventRecorderTrait;

    /** @var list<CartItem> */
    private array $items = [];

    public function __construct(
        private readonly CartId $id,
        private readonly ?CustomerId $customerId,
        private CartStatus $status,
        private readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(CartId $id, ?CustomerId $customerId): self
    {
        $now = new \DateTimeImmutable();

        return new self(
            id:         $id,
            customerId: $customerId,
            status:     CartStatus::OPEN,
            createdAt:  $now,
            updatedAt:  $now,
        );
    }

    // ------------------------------------------------------------------ queries

    public function id(): CartId
    {
        return $this->id;
    }

    public function customerId(): ?CustomerId
    {
        return $this->customerId;
    }

    public function status(): CartStatus
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

    /** @return list<CartItem> */
    public function items(): array
    {
        return $this->items;
    }

    public function isEmpty(): bool
    {
        return [] === $this->items;
    }

    // ------------------------------------------------------------------ commands

    public function addItem(
        ProductId $productId,
        Money $unitPrice,
        int $taxAmount,
        int $quantity,
        int $availableStock,
    ): void {
        $this->assertModifiable();

        if ($quantity < 1) {
            throw InvalidQuantity::mustBeAtLeastOne($quantity);
        }

        $existing = $this->findItem($productId);

        if ($existing !== null) {
            $merged = $existing->quantity() + $quantity;

            if ($merged > $availableStock) {
                throw InsufficientStock::forProduct($productId, $merged, $availableStock);
            }

            $existing->updateQuantity(new Quantity($merged));
            $this->record(new CartItemQuantityUpdated($this->id, $productId, $merged, new \DateTimeImmutable()));
        } else {
            if ($quantity > $availableStock) {
                throw InsufficientStock::forProduct($productId, $quantity, $availableStock);
            }

            $this->items[] = new CartItem($productId, $unitPrice, $taxAmount, new Quantity($quantity));
            $this->record(new CartItemAdded($this->id, $productId, $quantity, new \DateTimeImmutable()));
        }

        $this->touch();
    }

    public function updateItem(
        ProductId $productId,
        Money $unitPrice,
        int $taxAmount,
        int $quantity,
        int $availableStock,
    ): void {
        $this->assertModifiable();

        if ($quantity < 0) {
            throw InvalidQuantity::mustBeNonNegative($quantity);
        }

        if ($quantity === 0) {
            $this->doRemoveItem($productId, required: false);
            return;
        }

        if ($quantity > $availableStock) {
            throw InsufficientStock::forProduct($productId, $quantity, $availableStock);
        }

        $existing = $this->findItem($productId);

        if ($existing !== null) {
            $existing->updateQuantity(new Quantity($quantity));
            $this->record(new CartItemQuantityUpdated($this->id, $productId, $quantity, new \DateTimeImmutable()));
        } else {
            $this->items[] = new CartItem($productId, $unitPrice, $taxAmount, new Quantity($quantity));
            $this->record(new CartItemAdded($this->id, $productId, $quantity, new \DateTimeImmutable()));
        }

        $this->touch();
    }

    public function removeItem(ProductId $productId): void
    {
        $this->assertModifiable();
        $this->doRemoveItem($productId, required: true);
    }

    public function markCheckedOut(): void
    {
        $this->assertModifiable();
        $this->status = CartStatus::CHECKED_OUT;
        $this->touch();
        $this->record(new CartCheckedOut($this->id, new \DateTimeImmutable()));
    }

    public function reopen(): void
    {
        $this->status = CartStatus::OPEN;
        $this->touch();
    }

    public function refreshItemSnapshot(ProductId $productId, Money $unitPrice, int $taxAmount): void
    {
        $item = $this->findItem($productId);

        if ($item !== null) {
            $item->refreshSnapshot($unitPrice, $taxAmount);
        }
    }

    // ------------------------------------------------------------------ guards

    public function assertOwnedBy(?CustomerId $customerId): void
    {
        $cartIsGuest   = $this->customerId === null;
        $callerIsGuest = $customerId === null;

        if ($cartIsGuest && $callerIsGuest) {
            return;
        }

        if (!$cartIsGuest && !$callerIsGuest && $this->customerId->equals($customerId)) {
            return;
        }

        throw CartOwnershipMismatch::forCart($this->id);
    }

    public function assertModifiable(): void
    {
        if (CartStatus::CHECKED_OUT === $this->status) {
            throw CartNotModifiable::withId($this->id);
        }
    }

    // ------------------------------------------------------------------ internals

    private function findItem(ProductId $productId): ?CartItem
    {
        foreach ($this->items as $item) {
            if ($item->productId()->equals($productId)) {
                return $item;
            }
        }

        return null;
    }

    private function doRemoveItem(ProductId $productId, bool $required): void
    {
        $found = false;

        $this->items = array_values(array_filter(
            $this->items,
            function (CartItem $item) use ($productId, &$found): bool {
                if ($item->productId()->equals($productId)) {
                    $found = true;
                    return false;
                }
                return true;
            },
        ));

        if (!$found && $required) {
            throw CartItemNotFound::forProduct($this->id, $productId);
        }

        if ($found) {
            $this->record(new CartItemRemoved($this->id, $productId, new \DateTimeImmutable()));
            $this->touch();
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
