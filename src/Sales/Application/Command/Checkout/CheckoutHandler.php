<?php

declare(strict_types=1);

namespace Siroko\Sales\Application\Command\Checkout;

use Siroko\Catalog\Domain\Product;
use Siroko\Catalog\Domain\ProductRepository;
use Siroko\Sales\Application\DTO\OrderView;
use Siroko\Sales\Domain\Cart\Cart;
use Siroko\Sales\Domain\Cart\CartRepository;
use Siroko\Sales\Domain\Exception\CartNotFound;
use Siroko\Sales\Domain\Exception\CheckoutCoherenceFailed;
use Siroko\Sales\Domain\Exception\EmptyCartCannotCheckout;
use Siroko\Sales\Domain\Order\Order;
use Siroko\Sales\Domain\Order\OrderRepository;
use Siroko\Sales\Domain\Service\CheckoutCoherenceChecker;
use Siroko\Sales\Domain\ValueObject\CartId;
use Siroko\Sales\Domain\ValueObject\CustomerId;
use Siroko\Sales\Domain\ValueObject\OrderId;
use Siroko\Sales\Domain\ValueObject\ShippingAddress;
use Siroko\Shared\Application\Event\EventBus;
use Siroko\Shared\Application\Identity\IdGenerator;
use Siroko\Shared\Application\Transaction\TransactionManager;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class CheckoutHandler
{
    public function __construct(
        private readonly CartRepository $cartRepository,
        private readonly ProductRepository $productRepository,
        private readonly OrderRepository $orderRepository,
        private readonly CheckoutCoherenceChecker $coherenceChecker,
        private readonly TransactionManager $transactionManager,
        private readonly EventBus $eventBus,
        private readonly IdGenerator $idGenerator,
    ) {
    }

    public function __invoke(CheckoutCommand $command): OrderView
    {
        // Build (and validate) the shipping address up front — no transaction needed.
        $shippingAddress = new ShippingAddress(
            firstName: $command->firstName,
            lastName:  $command->lastName,
            vatNumber: $command->vatNumber,
            street:    $command->street,
            city:      $command->city,
            state:     $command->state,
            zipCode:   $command->zipCode,
            country:   $command->country,
        );

        $customerId = null !== $command->customerId ? new CustomerId($command->customerId) : null;

        $cart  = null;
        $order = null;

        try {
            /** @var Order $order */
            $order = $this->transactionManager->wrapInTransaction(
                function () use ($command, $customerId, $shippingAddress, &$cart): Order {
                    $cart = $this->loadCart($command->cartId);
                    $cart->assertOwnedBy($customerId);
                    $cart->assertModifiable();

                    if ($cart->isEmpty()) {
                        throw EmptyCartCannotCheckout::withId($cart->id());
                    }

                    $products = $this->loadProducts($cart);

                    // Refreshes stale snapshots in memory and lists incoherent lines.
                    $issues = $this->coherenceChecker->check($cart, $products);
                    if ([] !== $issues) {
                        throw new CheckoutCoherenceFailed($issues);
                    }

                    foreach ($cart->items() as $item) {
                        $product = $products[$item->productId()->value()];
                        $product->decreaseStock($item->quantity());
                        $this->productRepository->save($product);
                    }

                    $cart->markCheckedOut();
                    $this->cartRepository->save($cart);

                    $order = Order::fromCart(new OrderId($this->idGenerator->generate()), $cart, $shippingAddress);
                    $this->orderRepository->save($order);

                    return $order;
                },
            );
        } catch (CheckoutCoherenceFailed $e) {
            // Transaction rolled back. Persist only the refreshed snapshots so the
            // customer sees up-to-date prices on their next request.
            if (null !== $cart) {
                $this->cartRepository->save($cart);
            }

            throw $e;
        }

        // Events are dispatched only after the transaction has committed.
        $this->eventBus->dispatch(...$cart->releaseEvents());
        $this->eventBus->dispatch(...$order->releaseEvents());

        return OrderView::fromOrder($order);
    }

    private function loadCart(string $cartId): Cart
    {
        $id   = new CartId($cartId);
        $cart = $this->cartRepository->findById($id);

        if (null === $cart) {
            throw CartNotFound::withId($id);
        }

        return $cart;
    }

    /** @return array<string, Product> */
    private function loadProducts(Cart $cart): array
    {
        $products = [];

        foreach ($cart->items() as $item) {
            $product = $this->productRepository->findById($item->productId());

            if (null !== $product) {
                $products[$item->productId()->value()] = $product;
            }
        }

        return $products;
    }
}
