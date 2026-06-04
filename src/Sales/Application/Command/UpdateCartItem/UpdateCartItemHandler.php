<?php

declare(strict_types=1);

namespace Siroko\Sales\Application\Command\UpdateCartItem;

use Siroko\Catalog\Domain\Exception\ProductNotActive;
use Siroko\Catalog\Domain\Exception\ProductNotFound;
use Siroko\Catalog\Domain\ProductId;
use Siroko\Catalog\Domain\ProductRepository;
use Siroko\Sales\Application\DTO\CartView;
use Siroko\Sales\Domain\Cart\CartRepository;
use Siroko\Sales\Domain\Exception\CartNotFound;
use Siroko\Sales\Domain\ValueObject\CartId;
use Siroko\Sales\Domain\ValueObject\CustomerId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class UpdateCartItemHandler
{
    public function __construct(
        private readonly CartRepository $cartRepository,
        private readonly ProductRepository $productRepository,
    ) {
    }

    public function __invoke(UpdateCartItemCommand $command): CartView
    {
        $cartId = new CartId($command->cartId);
        $cart   = $this->cartRepository->findById($cartId);

        if (null === $cart) {
            throw CartNotFound::withId($cartId);
        }

        $customerId = null !== $command->customerId ? new CustomerId($command->customerId) : null;
        $cart->assertOwnedBy($customerId);

        $productId = new ProductId($command->productId);
        $product   = $this->productRepository->findById($productId);

        if (null === $product) {
            throw ProductNotFound::withId($productId);
        }

        if (!$product->isActive()) {
            throw ProductNotActive::withId($productId);
        }

        $cart->updateItem(
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
