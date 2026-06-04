<?php

declare(strict_types=1);

namespace Siroko\Sales\Application\Query\GetCart;

use Siroko\Sales\Application\DTO\CartView;
use Siroko\Sales\Domain\Cart\CartRepository;
use Siroko\Sales\Domain\Exception\CartNotFound;
use Siroko\Sales\Domain\ValueObject\CartId;
use Siroko\Sales\Domain\ValueObject\CustomerId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class GetCartHandler
{
    public function __construct(
        private readonly CartRepository $repository,
    ) {
    }

    public function __invoke(GetCartQuery $query): CartView
    {
        $cartId = new CartId($query->cartId);
        $cart   = $this->repository->findById($cartId);

        if (null === $cart) {
            throw CartNotFound::withId($cartId);
        }

        $cart->assertOwnedBy(
            null !== $query->customerId ? new CustomerId($query->customerId) : null,
        );

        return CartView::fromCart($cart);
    }
}
