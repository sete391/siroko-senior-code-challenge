<?php

declare(strict_types=1);

namespace Siroko\Sales\Application\Command\ProcessPayment;

use Siroko\Catalog\Domain\ProductRepository;
use Siroko\Sales\Domain\Cart\CartRepository;
use Siroko\Sales\Domain\Exception\OrderNotFound;
use Siroko\Sales\Domain\Order\Order;
use Siroko\Sales\Domain\Order\OrderRepository;
use Siroko\Sales\Domain\ValueObject\OrderId;
use Siroko\Shared\Application\Event\EventBus;
use Siroko\Shared\Application\Transaction\TransactionManager;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class ProcessPaymentHandler
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly ProductRepository $productRepository,
        private readonly CartRepository $cartRepository,
        private readonly TransactionManager $transactionManager,
        private readonly EventBus $eventBus,
    ) {
    }

    public function __invoke(ProcessPaymentCommand $command): void
    {
        $orderId = new OrderId($command->orderId);
        $order   = null;

        $this->transactionManager->wrapInTransaction(function () use ($command, $orderId, &$order): void {
            $order = $this->orderRepository->findById($orderId);

            if (null === $order) {
                throw OrderNotFound::withId($orderId);
            }

            if ($command->result) {
                $order->confirmPayment();
                $this->orderRepository->save($order);

                return;
            }

            $order->failPayment();
            $this->restoreStock($order);
            $this->reopenCart($order);
            $this->orderRepository->save($order);
        });

        // Events are dispatched only after the transaction has committed.
        $this->eventBus->dispatch(...$order->releaseEvents());
    }

    private function restoreStock(Order $order): void
    {
        foreach ($order->items() as $item) {
            $product = $this->productRepository->findById($item->productId());

            // A product may have been deleted after the order was placed.
            // Like a missing cart, it is ignored silently (§4.9 / constraint 13).
            if (null === $product) {
                continue;
            }

            $product->restoreStock($item->quantity());
            $this->productRepository->save($product);
        }
    }

    private function reopenCart(Order $order): void
    {
        $cart = $this->cartRepository->findById($order->cartId());

        // The cart may have been deleted over time — ignore silently (§4.9).
        if (null === $cart) {
            return;
        }

        $cart->reopen();
        $this->cartRepository->save($cart);
    }
}
