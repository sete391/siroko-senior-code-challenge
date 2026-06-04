<?php

declare(strict_types=1);

namespace Siroko\Sales\Application\Command\RemoveCartItem;

use Siroko\Catalog\Domain\ProductId;
use Siroko\Sales\Application\DTO\CartView;
use Siroko\Sales\Domain\Cart\CartRepository;
use Siroko\Sales\Domain\Exception\CartNotFound;
use Siroko\Sales\Domain\ValueObject\CartId;
use Siroko\Sales\Domain\ValueObject\CustomerId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class RemoveCartItemHandler
{
    public function __construct(
        private readonly CartRepository $cartRepository,
    ) {
    }

    public function __invoke(RemoveCartItemCommand $command): CartView
    {
        $cartId = new CartId($command->cartId);
        $cart   = $this->cartRepository->findById($cartId);

        if (null === $cart) {
            throw CartNotFound::withId($cartId);
        }

        $cart->assertOwnedBy(
            null !== $command->customerId ? new CustomerId($command->customerId) : null,
        );

        $cart->removeItem(new ProductId($command->productId));

        $this->cartRepository->save($cart);

        return CartView::fromCart($cart);
    }
}
