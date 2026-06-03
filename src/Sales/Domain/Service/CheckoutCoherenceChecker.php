<?php

declare(strict_types=1);

namespace Siroko\Sales\Domain\Service;

use Siroko\Catalog\Domain\Product;
use Siroko\Catalog\Domain\Exception\ProductNotActive;
use Siroko\Catalog\Domain\Exception\ProductNotFound;
use Siroko\Sales\Domain\Cart\Cart;

final class CheckoutCoherenceChecker
{
    /**
     * Validates that every cart item is coherent with the current product state.
     * For each incoherent item the snapshot is refreshed on the cart so the
     * caller can persist the updated state before rolling back.
     *
     * @param array<string, Product> $productsById  Keyed by ProductId::value()
     * @return list<CoherenceIssue>                 Empty when fully coherent
     */
    public function check(Cart $cart, array $productsById): array
    {
        $issues = [];

        foreach ($cart->items() as $item) {
            $product = $productsById[$item->productId()->value()] ?? null;

            if ($product === null) {
                throw ProductNotFound::withId($item->productId());
            }

            if (!$product->isActive()) {
                throw ProductNotActive::withId($item->productId());
            }

            $priceChanged = !$item->unitPrice()->equals($product->unitPrice())
                || $item->taxAmount() !== $product->taxAmount();

            if ($priceChanged) {
                $cart->refreshItemSnapshot(
                    $item->productId(),
                    $product->unitPrice(),
                    $product->taxAmount(),
                );

                $issues[] = new CoherenceIssue(
                    $item->productId(),
                    CoherenceIssue::REASON_PRICE_CHANGED,
                );

                continue;
            }

            if (!$product->isAvailableFor($item->quantity())) {
                $issues[] = new CoherenceIssue(
                    $item->productId(),
                    CoherenceIssue::REASON_INSUFFICIENT_STOCK,
                );
            }
        }

        return $issues;
    }
}
