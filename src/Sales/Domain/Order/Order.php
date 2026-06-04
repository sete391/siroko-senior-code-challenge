<?php

declare(strict_types=1);

namespace Siroko\Sales\Domain\Order;

use Siroko\Sales\Domain\Cart\Cart;
use Siroko\Sales\Domain\Exception\EmptyCartCannotCheckout;
use Siroko\Sales\Domain\Exception\OrderNotPending;
use Siroko\Sales\Domain\Exception\OrderOwnershipMismatch;
use Siroko\Sales\Domain\Order\Event\OrderPaymentConfirmed;
use Siroko\Sales\Domain\Order\Event\OrderPaymentFailed;
use Siroko\Sales\Domain\Order\Event\OrderPlaced;
use Siroko\Sales\Domain\ValueObject\CartId;
use Siroko\Sales\Domain\ValueObject\CustomerId;
use Siroko\Sales\Domain\ValueObject\OrderId;
use Siroko\Sales\Domain\ValueObject\ShippingAddress;
use Siroko\Shared\Domain\Event\DomainEventRecorderTrait;
use Siroko\Shared\Domain\ValueObject\Money;

final class Order
{
    use DomainEventRecorderTrait;

    /** @var list<OrderItem> */
    private array $items;

    /** @param list<OrderItem> $items */
    public function __construct(
        private readonly OrderId $id,
        private readonly CartId $cartId,
        private readonly ?CustomerId $customerId,
        array $items,
        private readonly Money $totalProducts,
        private readonly Money $totalTax,
        private readonly Money $totalOrder,
        private readonly ShippingAddress $shippingAddress,
        private OrderStatus $status,
        private readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
    ) {
        $this->items = $items;
    }

    public static function fromCart(
        OrderId $orderId,
        Cart $cart,
        ShippingAddress $shippingAddress,
    ): self {
        if ($cart->isEmpty()) {
            throw EmptyCartCannotCheckout::withId($cart->id());
        }

        $now          = new \DateTimeImmutable();
        $orderItems   = [];
        $totalProducts = 0;
        $totalTax      = 0;
        $currency      = Money::DEFAULT_CURRENCY;

        foreach ($cart->items() as $cartItem) {
            $orderItem      = new OrderItem(
                $cartItem->productId(),
                $cartItem->unitPrice(),
                $cartItem->taxAmount(),
                $cartItem->quantityValue(),
            );
            $orderItems[]   = $orderItem;
            $totalProducts += $orderItem->lineTotal()->amount;
            $totalTax      += $orderItem->lineTax();
            $currency       = $cartItem->unitPrice()->currency;
        }

        $totalProductsMoney = new Money($totalProducts, $currency);
        $totalTaxMoney      = new Money($totalTax, $currency);
        $totalOrderMoney    = $totalProductsMoney->add($totalTaxMoney);

        $order = new self(
            id:              $orderId,
            cartId:          $cart->id(),
            customerId:      $cart->customerId(),
            items:           $orderItems,
            totalProducts:   $totalProductsMoney,
            totalTax:        $totalTaxMoney,
            totalOrder:      $totalOrderMoney,
            shippingAddress: $shippingAddress,
            status:          OrderStatus::PENDING,
            createdAt:       $now,
            updatedAt:       $now,
        );

        $order->record(new OrderPlaced($orderId, $cart->id(), $now));

        return $order;
    }

    // ------------------------------------------------------------------ queries

    public function id(): OrderId
    {
        return $this->id;
    }

    public function cartId(): CartId
    {
        return $this->cartId;
    }

    public function customerId(): ?CustomerId
    {
        return $this->customerId;
    }

    /** @return list<OrderItem> */
    public function items(): array
    {
        return $this->items;
    }

    public function totalProducts(): Money
    {
        return $this->totalProducts;
    }

    public function totalTax(): Money
    {
        return $this->totalTax;
    }

    public function totalOrder(): Money
    {
        return $this->totalOrder;
    }

    public function shippingAddress(): ShippingAddress
    {
        return $this->shippingAddress;
    }

    public function status(): OrderStatus
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

    // ------------------------------------------------------------------ commands

    public function confirmPayment(): void
    {
        $this->assertPending();
        $this->status    = OrderStatus::PAYMENT_CONFIRMED;
        $this->updatedAt = new \DateTimeImmutable();
        $this->record(new OrderPaymentConfirmed($this->id, new \DateTimeImmutable()));
    }

    public function failPayment(): void
    {
        $this->assertPending();
        $this->status    = OrderStatus::PAYMENT_ERROR;
        $this->updatedAt = new \DateTimeImmutable();
        $this->record(new OrderPaymentFailed($this->id, new \DateTimeImmutable()));
    }

    public function assertOwnedBy(?CustomerId $customerId): void
    {
        $orderIsGuest  = $this->customerId === null;
        $callerIsGuest = $customerId === null;

        if ($orderIsGuest && $callerIsGuest) {
            return;
        }

        if (!$orderIsGuest && !$callerIsGuest && $this->customerId->equals($customerId)) {
            return;
        }

        throw OrderOwnershipMismatch::forOrder($this->id);
    }

    // ------------------------------------------------------------------ internals

    private function assertPending(): void
    {
        if (OrderStatus::PENDING !== $this->status) {
            throw OrderNotPending::withId($this->id);
        }
    }
}
