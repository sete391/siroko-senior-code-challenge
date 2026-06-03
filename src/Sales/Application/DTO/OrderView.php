<?php

declare(strict_types=1);

namespace Siroko\Sales\Application\DTO;

use Siroko\Sales\Domain\Order\Order;
use Siroko\Sales\Domain\Order\OrderItem;

final class OrderView
{
    /** @param list<OrderItemView> $items */
    public function __construct(
        public readonly string $orderId,
        public readonly string $cartId,
        public readonly ?string $customerId,
        public readonly string $status,
        public readonly array $items,
        public readonly int $totalProductsAmount,
        public readonly int $totalTaxAmount,
        public readonly int $totalOrderAmount,
        public readonly string $currency,
        public readonly string $shippingFirstName,
        public readonly string $shippingLastName,
        public readonly string $shippingVatNumber,
        public readonly string $shippingStreet,
        public readonly string $shippingCity,
        public readonly string $shippingState,
        public readonly string $shippingZipCode,
        public readonly string $shippingCountry,
    ) {
    }

    public static function fromOrder(Order $order): self
    {
        $items   = array_map(
            static fn(OrderItem $item) => OrderItemView::fromOrderItem($item),
            $order->items(),
        );
        $address = $order->shippingAddress();

        return new self(
            orderId:              $order->id()->value(),
            cartId:               $order->cartId()->value(),
            customerId:           $order->customerId()?->value(),
            status:               $order->status()->value,
            items:                $items,
            totalProductsAmount:  $order->totalProducts()->amount,
            totalTaxAmount:       $order->totalTax()->amount,
            totalOrderAmount:     $order->totalOrder()->amount,
            currency:             $order->totalProducts()->currency,
            shippingFirstName:    $address->firstName,
            shippingLastName:     $address->lastName,
            shippingVatNumber:    $address->vatNumber,
            shippingStreet:       $address->street,
            shippingCity:         $address->city,
            shippingState:        $address->state,
            shippingZipCode:      $address->zipCode,
            shippingCountry:      $address->country,
        );
    }
}
