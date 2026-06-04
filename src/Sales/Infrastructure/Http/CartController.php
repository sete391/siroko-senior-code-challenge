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
            productId:  isset($body['productId']) && is_string($body['productId']) ? $body['productId'] : '',
            quantity:   isset($body['quantity'])  && is_int($body['quantity'])     ? $body['quantity']  : 0,
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
            quantity:   isset($body['quantity']) && is_int($body['quantity']) ? $body['quantity'] : 0,
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
        /** @var array<string, mixed> $address */
        $address = is_array($body['shippingAddress'] ?? null) ? $body['shippingAddress'] : [];

        $order = $this->dispatch(new CheckoutCommand(
            cartId:     $cartId,
            customerId: $request->headers->get('X-Customer-Id'),
            firstName:  isset($address['firstName'])  && is_string($address['firstName'])  ? $address['firstName']  : '',
            lastName:   isset($address['lastName'])   && is_string($address['lastName'])   ? $address['lastName']   : '',
            vatNumber:  isset($address['vatNumber'])  && is_string($address['vatNumber'])  ? $address['vatNumber']  : '',
            street:     isset($address['street'])     && is_string($address['street'])     ? $address['street']     : '',
            city:       isset($address['city'])       && is_string($address['city'])       ? $address['city']       : '',
            state:      isset($address['state'])      && is_string($address['state'])      ? $address['state']      : '',
            zipCode:    isset($address['zipCode'])    && is_string($address['zipCode'])    ? $address['zipCode']    : '',
            country:    isset($address['country'])    && is_string($address['country'])    ? $address['country']    : '',
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

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }
}
