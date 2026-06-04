<?php

declare(strict_types=1);

namespace Siroko\Tests\Functional\Sales;

use PHPUnit\Framework\Attributes\Test;
use Siroko\Catalog\Domain\ProductId;
use Siroko\Catalog\Domain\ProductStatus;
use Siroko\Sales\Domain\Cart\CartStatus;
use Siroko\Sales\Domain\ValueObject\CartId;
use Siroko\Sales\Domain\ValueObject\CustomerId;
use Siroko\Tests\Functional\FunctionalTestCase;
use Siroko\Shared\Domain\ValueObject\Money;

final class CartControllerTest extends FunctionalTestCase
{
    // ------------------------------------------------------------------ GET /api/carts/{cartId}

    #[Test]
    public function get_cart_returns_200_with_cart_view(): void
    {
        $product = $this->seedProduct();
        $cart    = $this->seedCartWithItem($product->id());

        $body = $this->json('GET', '/api/carts/' . $cart->id()->value());

        self::assertSame(200, $this->statusCode());
        self::assertSame($cart->id()->value(), $body['cartId']);
        self::assertSame('OPEN', $body['status']);
        self::assertNull($body['customerId']);
        self::assertCount(1, $body['items']);
        self::assertSame($product->id()->value(), $body['items'][0]['productId']);
        self::assertSame(4500, $body['items'][0]['unitPriceAmount']);
        self::assertSame('EUR', $body['items'][0]['unitPriceCurrency']);
    }

    #[Test]
    public function get_cart_returns_404_for_unknown_cart(): void
    {
        $body = $this->json('GET', '/api/carts/00000000-0000-4000-8000-000000000001');

        self::assertSame(404, $this->statusCode());
        self::assertSame('cart_not_found', $body['error']);
        self::assertArrayHasKey('message', $body);
    }

    #[Test]
    public function get_cart_returns_403_on_ownership_mismatch(): void
    {
        $product    = $this->seedProduct();
        $customerId = new CustomerId($this->idGenerator->generate());
        $cart       = $this->seedCartWithItem($product->id(), customerId: $customerId);

        $wrongCustomer = $this->idGenerator->generate(); // different UUID

        $body = $this->json(
            'GET',
            '/api/carts/' . $cart->id()->value(),
            headers: ['X-Customer-Id' => $wrongCustomer],
        );

        self::assertSame(403, $this->statusCode());
        self::assertSame('cart_ownership_mismatch', $body['error']);
    }

    // ------------------------------------------------------------------ POST /api/carts/{cartId}/items

    #[Test]
    public function add_item_to_non_existent_cart_creates_it(): void
    {
        $product = $this->seedProduct();
        $cartId  = $this->idGenerator->generate();

        $body = $this->json(
            'POST',
            '/api/carts/' . $cartId . '/items',
            ['productId' => $product->id()->value(), 'quantity' => 1],
        );

        self::assertSame(200, $this->statusCode());
        self::assertSame($cartId, $body['cartId']);
        self::assertSame('OPEN', $body['status']);
        self::assertCount(1, $body['items']);
        self::assertSame($product->id()->value(), $body['items'][0]['productId']);
        self::assertSame(1, $body['items'][0]['quantity']);
    }

    #[Test]
    public function add_item_returns_correct_totals(): void
    {
        $product = $this->seedProduct(priceAmount: 4500, taxAmount: 945);
        $cartId  = $this->idGenerator->generate();

        $body = $this->json(
            'POST',
            '/api/carts/' . $cartId . '/items',
            ['productId' => $product->id()->value(), 'quantity' => 2],
        );

        self::assertSame(200, $this->statusCode());
        self::assertSame(9000,  $body['totalProductsAmount']); // 4500 × 2
        self::assertSame(1890,  $body['totalTaxAmount']);      // 945 × 2
        self::assertSame(10890, $body['totalOrderAmount']);
        self::assertSame('EUR', $body['currency']);
    }

    #[Test]
    public function add_item_to_existing_cart_merges_quantity(): void
    {
        $product = $this->seedProduct(stock: 20);
        $cart    = $this->seedCartWithItem($product->id(), quantity: 2);

        $body = $this->json(
            'POST',
            '/api/carts/' . $cart->id()->value() . '/items',
            ['productId' => $product->id()->value(), 'quantity' => 3],
        );

        self::assertSame(200, $this->statusCode());
        self::assertCount(1, $body['items']);
        self::assertSame(5, $body['items'][0]['quantity']); // merged 2 + 3
    }

    #[Test]
    public function add_item_returns_409_when_quantity_exceeds_stock(): void
    {
        $product = $this->seedProduct(stock: 3);
        $cartId  = $this->idGenerator->generate();

        $body = $this->json(
            'POST',
            '/api/carts/' . $cartId . '/items',
            ['productId' => $product->id()->value(), 'quantity' => 10],
        );

        self::assertSame(409, $this->statusCode());
        self::assertSame('insufficient_stock', $body['error']);
        self::assertArrayHasKey('message', $body);
    }

    #[Test]
    public function add_item_returns_422_for_zero_quantity(): void
    {
        $product = $this->seedProduct();
        $cartId  = $this->idGenerator->generate();

        $body = $this->json(
            'POST',
            '/api/carts/' . $cartId . '/items',
            ['productId' => $product->id()->value(), 'quantity' => 0],
        );

        self::assertSame(422, $this->statusCode());
        self::assertSame('invalid_quantity', $body['error']);
    }

    #[Test]
    public function add_item_returns_404_for_unknown_product(): void
    {
        $cartId = $this->idGenerator->generate();

        $body = $this->json(
            'POST',
            '/api/carts/' . $cartId . '/items',
            ['productId' => '00000000-0000-4000-8000-000000000088', 'quantity' => 1],
        );

        self::assertSame(404, $this->statusCode());
        self::assertSame('product_not_found', $body['error']);
    }

    #[Test]
    public function add_item_returns_403_on_ownership_mismatch(): void
    {
        $product    = $this->seedProduct();
        $customerId = new CustomerId($this->idGenerator->generate());
        $cart       = $this->seedCartWithItem($product->id(), customerId: $customerId);

        $body = $this->json(
            'POST',
            '/api/carts/' . $cart->id()->value() . '/items',
            ['productId' => $product->id()->value(), 'quantity' => 1],
            ['X-Customer-Id' => $this->idGenerator->generate()], // different customer
        );

        self::assertSame(403, $this->statusCode());
        self::assertSame('cart_ownership_mismatch', $body['error']);
    }

    // ------------------------------------------------------------------ PUT /api/carts/{cartId}/items/{productId}

    #[Test]
    public function update_item_changes_quantity(): void
    {
        $product = $this->seedProduct(stock: 20);
        $cart    = $this->seedCartWithItem($product->id(), quantity: 2);

        $body = $this->json(
            'PUT',
            '/api/carts/' . $cart->id()->value() . '/items/' . $product->id()->value(),
            ['quantity' => 5],
        );

        self::assertSame(200, $this->statusCode());
        self::assertSame(5, $body['items'][0]['quantity']);
    }

    #[Test]
    public function update_item_with_quantity_zero_removes_the_line(): void
    {
        $product = $this->seedProduct();
        $cart    = $this->seedCartWithItem($product->id(), quantity: 2);

        $body = $this->json(
            'PUT',
            '/api/carts/' . $cart->id()->value() . '/items/' . $product->id()->value(),
            ['quantity' => 0],
        );

        self::assertSame(200, $this->statusCode());
        self::assertCount(0, $body['items']);
        self::assertSame(0, $body['totalProductsAmount']);
    }

    #[Test]
    public function update_item_returns_409_when_quantity_exceeds_stock(): void
    {
        $product = $this->seedProduct(stock: 3);
        $cart    = $this->seedCartWithItem($product->id(), quantity: 2);

        $body = $this->json(
            'PUT',
            '/api/carts/' . $cart->id()->value() . '/items/' . $product->id()->value(),
            ['quantity' => 10],
        );

        self::assertSame(409, $this->statusCode());
        self::assertSame('insufficient_stock', $body['error']);
    }

    // ------------------------------------------------------------------ DELETE /api/carts/{cartId}/items/{productId}

    #[Test]
    public function remove_item_returns_200_with_empty_cart(): void
    {
        $product = $this->seedProduct();
        $cart    = $this->seedCartWithItem($product->id(), quantity: 1);

        $body = $this->json(
            'DELETE',
            '/api/carts/' . $cart->id()->value() . '/items/' . $product->id()->value(),
        );

        self::assertSame(200, $this->statusCode());
        self::assertCount(0, $body['items']);
    }

    #[Test]
    public function remove_item_returns_404_when_product_not_in_cart(): void
    {
        $product      = $this->seedProduct();
        $cart         = $this->seedCartWithItem($product->id(), quantity: 1);
        $otherProduct = $this->seedProduct();

        $body = $this->json(
            'DELETE',
            '/api/carts/' . $cart->id()->value() . '/items/' . $otherProduct->id()->value(),
        );

        self::assertSame(404, $this->statusCode());
        self::assertSame('cart_item_not_found', $body['error']);
    }

    // ------------------------------------------------------------------ POST /api/carts/{cartId}/checkout

    #[Test]
    public function checkout_returns_201_with_order_view(): void
    {
        $product = $this->seedProduct(priceAmount: 4500, taxAmount: 945, stock: 10);
        $cart    = $this->seedCartWithItem($product->id(), quantity: 2);

        $body = $this->json(
            'POST',
            '/api/carts/' . $cart->id()->value() . '/checkout',
            ['shippingAddress' => $this->addressPayload()],
        );

        self::assertSame(201, $this->statusCode());
        self::assertArrayHasKey('orderId', $body);
        self::assertSame('PENDING', $body['status']);
        self::assertCount(1, $body['items']);
        self::assertSame(9000,  $body['totalProductsAmount']); // 4500 × 2
        self::assertSame(1890,  $body['totalTaxAmount']);
        self::assertSame(10890, $body['totalOrderAmount']);
        self::assertSame('Ada',  $body['shippingFirstName']);
    }

    #[Test]
    public function checkout_decrements_product_stock(): void
    {
        $product = $this->seedProduct(stock: 10);
        $cart    = $this->seedCartWithItem($product->id(), quantity: 3);

        $this->json(
            'POST',
            '/api/carts/' . $cart->id()->value() . '/checkout',
            ['shippingAddress' => $this->addressPayload()],
        );

        self::assertSame(201, $this->statusCode());

        $updated = $this->reloadProduct($product->id());
        self::assertNotNull($updated);
        self::assertSame(7, $updated->quantity()); // 10 - 3
    }

    #[Test]
    public function checkout_marks_cart_as_checked_out(): void
    {
        $product = $this->seedProduct(stock: 10);
        $cart    = $this->seedCartWithItem($product->id(), quantity: 1);
        $cartId  = $cart->id();

        $this->json(
            'POST',
            '/api/carts/' . $cartId->value() . '/checkout',
            ['shippingAddress' => $this->addressPayload()],
        );

        self::assertSame(201, $this->statusCode());

        $reloaded = $this->reloadCart($cartId);
        self::assertNotNull($reloaded);
        self::assertSame(CartStatus::CHECKED_OUT, $reloaded->status());
    }

    #[Test]
    public function checkout_returns_409_for_empty_cart(): void
    {
        $cartId = new CartId($this->idGenerator->generate());
        // Seed an empty cart directly
        $emptyCart = \Siroko\Sales\Domain\Cart\Cart::create($cartId, null);
        $this->carts->save($emptyCart);
        $this->em->clear();

        $body = $this->json(
            'POST',
            '/api/carts/' . $cartId->value() . '/checkout',
            ['shippingAddress' => $this->addressPayload()],
        );

        self::assertSame(409, $this->statusCode());
        self::assertSame('empty_cart_cannot_checkout', $body['error']);
    }

    #[Test]
    public function checkout_returns_422_for_invalid_shipping_address(): void
    {
        $product = $this->seedProduct(stock: 10);
        $cart    = $this->seedCartWithItem($product->id(), quantity: 1);

        $body = $this->json(
            'POST',
            '/api/carts/' . $cart->id()->value() . '/checkout',
            ['shippingAddress' => ['firstName' => '', 'lastName' => 'L',
                'vatNumber' => 'V', 'street' => 'S', 'city' => 'C',
                'state' => 'S', 'zipCode' => 'Z', 'country' => 'ES']],
        );

        self::assertSame(422, $this->statusCode());
        self::assertSame('invalid_shipping_address', $body['error']);
    }

    #[Test]
    public function checkout_returns_409_with_affected_items_on_price_coherence_failure(): void
    {
        $product = $this->seedProduct(priceAmount: 4500, stock: 10);
        $cart    = $this->seedCartWithItem($product->id(), priceAmount: 4500, quantity: 1);

        // Change the product price directly in DB to trigger coherence failure.
        $this->em->getConnection()->update(
            'products',
            ['unit_price_amount' => 5500],
            ['id' => $product->id()->value()],
        );
        $this->em->clear();

        $body = $this->json(
            'POST',
            '/api/carts/' . $cart->id()->value() . '/checkout',
            ['shippingAddress' => $this->addressPayload()],
        );

        self::assertSame(409, $this->statusCode());
        self::assertSame('checkout_coherence_failed', $body['error']);
        self::assertArrayHasKey('message', $body);
        self::assertArrayHasKey('affectedItems', $body);
        self::assertCount(1, $body['affectedItems']);
        self::assertSame($product->id()->value(), $body['affectedItems'][0]['productId']);
        self::assertSame('price_changed', $body['affectedItems'][0]['reason']);
    }

    #[Test]
    public function coherence_failure_refreshes_cart_snapshot_and_creates_no_order(): void
    {
        $product = $this->seedProduct(priceAmount: 4500, stock: 10);
        $cart    = $this->seedCartWithItem($product->id(), priceAmount: 4500, quantity: 1);

        $this->em->getConnection()->update(
            'products',
            ['unit_price_amount' => 5500],
            ['id' => $product->id()->value()],
        );
        $this->em->clear();

        $this->json(
            'POST',
            '/api/carts/' . $cart->id()->value() . '/checkout',
            ['shippingAddress' => $this->addressPayload()],
        );
        self::assertSame(409, $this->statusCode());

        // Cart snapshot must be updated to the new price.
        $reloaded = $this->reloadCart($cart->id());
        self::assertNotNull($reloaded);
        self::assertSame(5500, $reloaded->items()[0]->unitPrice()->amount);

        // No order must have been created.
        $orderCount = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM orders WHERE cart_id = ?',
            [$cart->id()->value()],
        );
        self::assertSame('0', (string) $orderCount);
    }

    #[Test]
    public function checkout_returns_409_on_already_checked_out_cart(): void
    {
        $product = $this->seedProduct(stock: 5);
        ['cart' => $cart] = $this->seedCheckedOutOrder($product->id());

        $body = $this->json(
            'POST',
            '/api/carts/' . $cart->id()->value() . '/checkout',
            ['shippingAddress' => $this->addressPayload()],
        );

        self::assertSame(409, $this->statusCode());
        self::assertSame('cart_not_modifiable', $body['error']);
    }
}
