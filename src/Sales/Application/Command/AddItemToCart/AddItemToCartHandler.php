<?php

declare(strict_types=1);

namespace Siroko\Sales\Application\Command\AddItemToCart;

use Siroko\Catalog\Domain\Exception\ProductNotActive;
use Siroko\Catalog\Domain\Exception\ProductNotFound;
use Siroko\Catalog\Domain\ProductId;
use Siroko\Catalog\Domain\ProductRepository;
use Siroko\Sales\Application\DTO\CartView;
use Siroko\Sales\Domain\Cart\Cart;
use Siroko\Sales\Domain\Cart\CartRepository;
use Siroko\Sales\Domain\ValueObject\CartId;
use Siroko\Sales\Domain\ValueObject\CustomerId;

final class AddItemToCartHandler
{
    public function __construct(
        private readonly CartRepository $cartRepository,
        private readonly ProductRepository $productRepository,
    ) {
    }

    public function __invoke(AddItemToCartCommand $command): CartView
    {
        $productId = new ProductId($command->productId);
        $product   = $this->productRepository->findById($productId);

        if (null === $product) {
            throw ProductNotFound::withId($productId);
        }

        if (!$product->isActive()) {
            throw ProductNotActive::withId($productId);
        }

        $cartId     = new CartId($command->cartId);
        $customerId = null !== $command->customerId ? new CustomerId($command->customerId) : null;

        $cart = $this->cartRepository->findById($cartId)
            ?? Cart::create($cartId, $customerId);

        $cart->assertOwnedBy($customerId);

        $cart->addItem(
            $productId,
            $product->unitPrice(),
            $product->taxAmount(),
            $command->quantity,
            $product->quantity(),
        );

        $this->cartRepository->save($cart);

        return CartView::fromCart($cart);
    }
}
