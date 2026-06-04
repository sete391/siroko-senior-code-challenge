<?php

declare(strict_types=1);

namespace Siroko\Sales\Infrastructure\Http;

use Siroko\Sales\Application\Command\AddItemToCart\AddItemToCartCommand;
use Siroko\Sales\Application\Command\Checkout\CheckoutCommand;
use Siroko\Sales\Application\Command\RemoveCartItem\RemoveCartItemCommand;
use Siroko\Sales\Application\Command\UpdateCartItem\UpdateCartItemCommand;
use Siroko\Sales\Application\Query\GetCart\GetCartQuery;
use Siroko\Shared\Infrastructure\Http\ApiController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/carts')]
final class CartController extends ApiController
{
    #[Route('/{cartId}', methods: ['GET'])]
    public function get(string $cartId, Request $request): JsonResponse
    {
        $cart = $this->ask(new GetCartQuery($cartId, $request->headers->get('X-Customer-Id')));

        return new JsonResponse($cart);
    }

    #[Route('/{cartId}/items', methods: ['POST'])]
    public function addItem(string $cartId, Request $request): JsonResponse
    {
        $body = $this->decodeBody($request);

        $cart = $this->dispatch(new AddItemToCartCommand(
            cartId:     $cartId,
            customerId: $request->headers->get('X-Customer-Id'),
            productId:  (string) ($body['productId'] ?? ''),
            quantity:   (int) ($body['quantity'] ?? 0),
        ));

        return new JsonResponse($cart);
    }

    #[Route('/{cartId}/items/{productId}', methods: ['PUT'])]
    public function updateItem(string $cartId, string $productId, Request $request): JsonResponse
    {
        $body = $this->decodeBody($request);

        $cart = $this->dispatch(new UpdateCartItemCommand(
            cartId:     $cartId,
            customerId: $request->headers->get('X-Customer-Id'),
            productId:  $productId,
            quantity:   (int) ($body['quantity'] ?? 0),
        ));

        return new JsonResponse($cart);
    }

    #[Route('/{cartId}/items/{productId}', methods: ['DELETE'])]
    public function removeItem(string $cartId, string $productId, Request $request): JsonResponse
    {
        $cart = $this->dispatch(new RemoveCartItemCommand(
            cartId:     $cartId,
            customerId: $request->headers->get('X-Customer-Id'),
            productId:  $productId,
        ));

        return new JsonResponse($cart);
    }

    #[Route('/{cartId}/checkout', methods: ['POST'])]
    public function checkout(string $cartId, Request $request): JsonResponse
    {
        $body    = $this->decodeBody($request);
        $address = $body['shippingAddress'] ?? [];

        $order = $this->dispatch(new CheckoutCommand(
            cartId:     $cartId,
            customerId: $request->headers->get('X-Customer-Id'),
            firstName:  (string) ($address['firstName'] ?? ''),
            lastName:   (string) ($address['lastName']  ?? ''),
            vatNumber:  (string) ($address['vatNumber'] ?? ''),
            street:     (string) ($address['street']    ?? ''),
            city:       (string) ($address['city']      ?? ''),
            state:      (string) ($address['state']     ?? ''),
            zipCode:    (string) ($address['zipCode']   ?? ''),
            country:    (string) ($address['country']   ?? ''),
        ));

        return new JsonResponse($order, Response::HTTP_CREATED);
    }

    /** @return array<string, mixed> */
    private function decodeBody(Request $request): array
    {
        $content = $request->getContent();
        if ('' === $content) {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }
}
