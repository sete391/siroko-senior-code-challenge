<?php

declare(strict_types=1);

namespace Siroko\Sales\Infrastructure\Http;

use Siroko\Sales\Application\Command\ProcessPayment\ProcessPaymentCommand;
use Siroko\Sales\Application\Query\GetOrder\GetOrderQuery;
use Siroko\Shared\Infrastructure\Http\ApiController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/orders')]
final class OrderController extends ApiController
{
    #[Route('/{orderId}', methods: ['GET'])]
    public function get(string $orderId, Request $request): JsonResponse
    {
        $order = $this->ask(new GetOrderQuery($orderId, $request->headers->get('X-Customer-Id')));

        return new JsonResponse($order);
    }

    #[Route('/{orderId}/payment', methods: ['POST'])]
    public function processPayment(string $orderId, Request $request): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body   = json_decode($request->getContent(), true) ?? [];
        $result = isset($body['result']) && is_bool($body['result']) ? $body['result'] : false;

        $this->dispatch(new ProcessPaymentCommand($orderId, $result));

        $order = $this->ask(new GetOrderQuery($orderId, $request->headers->get('X-Customer-Id')));

        return new JsonResponse($order);
    }
}
