<?php

declare(strict_types=1);

namespace Siroko\Shared\Infrastructure\Http;

use Siroko\Catalog\Domain\Exception\ProductNotActive;
use Siroko\Catalog\Domain\Exception\ProductNotFound;
use Siroko\Sales\Domain\Exception\CartItemNotFound;
use Siroko\Sales\Domain\Exception\CartNotFound;
use Siroko\Sales\Domain\Exception\CartNotModifiable;
use Siroko\Sales\Domain\Exception\CartOwnershipMismatch;
use Siroko\Sales\Domain\Exception\CheckoutCoherenceFailed;
use Siroko\Sales\Domain\Exception\EmptyCartCannotCheckout;
use Siroko\Sales\Domain\Exception\InsufficientStock;
use Siroko\Sales\Domain\Exception\InvalidQuantity;
use Siroko\Sales\Domain\Exception\InvalidShippingAddress;
use Siroko\Sales\Domain\Exception\OrderNotFound;
use Siroko\Sales\Domain\Exception\OrderNotPending;
use Siroko\Sales\Domain\Exception\OrderOwnershipMismatch;
use Siroko\Sales\Domain\Service\CoherenceIssue;
use Siroko\Shared\Domain\Exception\DomainException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

final class ExceptionListener
{
    public function onKernelException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();

        if ($throwable instanceof CheckoutCoherenceFailed) {
            $event->setResponse($this->coherenceResponse($throwable));
            return;
        }

        $status = $this->statusFor($throwable);

        if (null === $status) {
            return;
        }

        $event->setResponse(new JsonResponse([
            'error'   => $this->errorCode($throwable),
            'message' => $throwable->getMessage(),
        ], $status));
    }

    private function coherenceResponse(CheckoutCoherenceFailed $e): JsonResponse
    {
        $affected = array_map(
            static fn(CoherenceIssue $issue) => [
                'productId' => $issue->productId->value(),
                'reason'    => $issue->reason,
            ],
            $e->issues(),
        );

        return new JsonResponse([
            'error'         => 'checkout_coherence_failed',
            'message'       => $e->getMessage(),
            'affectedItems' => $affected,
        ], Response::HTTP_CONFLICT);
    }

    private function statusFor(\Throwable $e): ?int
    {
        return match (true) {
            $e instanceof ProductNotFound,
            $e instanceof CartNotFound,
            $e instanceof OrderNotFound,
            $e instanceof CartItemNotFound   => Response::HTTP_NOT_FOUND,

            $e instanceof CartOwnershipMismatch,
            $e instanceof OrderOwnershipMismatch => Response::HTTP_FORBIDDEN,

            $e instanceof CartNotModifiable,
            $e instanceof ProductNotActive,
            $e instanceof InsufficientStock,
            $e instanceof EmptyCartCannotCheckout,
            $e instanceof OrderNotPending    => Response::HTTP_CONFLICT,

            $e instanceof InvalidQuantity,
            $e instanceof InvalidShippingAddress => Response::HTTP_UNPROCESSABLE_ENTITY,

            $e instanceof DomainException    => Response::HTTP_BAD_REQUEST,

            $e instanceof \InvalidArgumentException => Response::HTTP_UNPROCESSABLE_ENTITY,

            default => null,
        };
    }

    private function errorCode(\Throwable $e): string
    {
        $class = (new \ReflectionClass($e))->getShortName();

        // CamelCase → snake_case
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $class));
    }
}
