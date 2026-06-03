<?php

declare(strict_types=1);

namespace Siroko\Sales\Application\DTO;

use Siroko\Sales\Domain\Cart\Cart;
use Siroko\Sales\Domain\Cart\CartItem;
use Siroko\Shared\Domain\ValueObject\Money;

final class CartView
{
    /** @param list<CartItemView> $items */
    public function __construct(
        public readonly string $cartId,
        public readonly ?string $customerId,
        public readonly string $status,
        public readonly array $items,
        public readonly int $totalProductsAmount,
        public readonly int $totalTaxAmount,
        public readonly int $totalOrderAmount,
        public readonly string $currency,
    ) {
    }

    public static function fromCart(Cart $cart): self
    {
        $totalProducts = 0;
        $totalTax      = 0;
        $currency      = Money::DEFAULT_CURRENCY;
        $items         = [];

        foreach ($cart->items() as $item) {
            $totalProducts += $item->unitPrice()->amount * $item->quantity();
            $totalTax      += $item->taxAmount() * $item->quantity();
            $currency       = $item->unitPrice()->currency;
            $items[]        = CartItemView::fromCartItem($item);
        }

        return new self(
            cartId:              $cart->id()->value(),
            customerId:          $cart->customerId()?->value(),
            status:              $cart->status()->value,
            items:               $items,
            totalProductsAmount: $totalProducts,
            totalTaxAmount:      $totalTax,
            totalOrderAmount:    $totalProducts + $totalTax,
            currency:            $currency,
        );
    }
}
