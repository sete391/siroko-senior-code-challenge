<?php

declare(strict_types=1);

namespace Siroko\Tests\Functional\Sales;

use PHPUnit\Framework\Attributes\Test;
use Siroko\Sales\Domain\Cart\CartStatus;
use Siroko\Sales\Domain\Order\OrderStatus;
use Siroko\Sales\Domain\ValueObject\CustomerId;
use Siroko\Tests\Functional\FunctionalTestCase;

final class OrderControllerTest extends FunctionalTestCase
{
    // ------------------------------------------------------------------ GET /api/orders/{orderId}

    #[Test]
    public function get_order_returns_200_with_order_view(): void
    {
        $product = $this->seedProduct(priceAmount: 4500, taxAmount: 945, stock: 10);
        ['order' => $order] = $this->seedCheckedOutOrder($product->id(), quantity: 2);

        $body = $this->json('GET', '/api/orders/' . $order->id()->value());

        $items = $this->assertList($body['items']);
        $item  = $this->assertBody($items[0]);

        self::assertSame(200, $this->statusCode());
        self::assertSame($order->id()->value(), $body['orderId']);
        self::assertSame('PENDING', $body['status']);
        self::assertCount(1, $items);

        // Money totals
        self::assertSame(9000,  $body['totalProductsAmount']); // 4500 × 2
        self::assertSame(1890,  $body['totalTaxAmount']);      // 945  × 2
        self::assertSame(10890, $body['totalOrderAmount']);
        self::assertSame('EUR', $body['currency']);

        // Order item snapshot fields
        self::assertSame($product->id()->value(), $item['productId']);
        self::assertSame(4500, $item['unitPriceAmount']);
        self::assertSame('EUR', $item['unitPriceCurrency']);
        self::assertSame(945,  $item['taxAmount']);
        self::assertSame(2,    $item['quantity']);
        self::assertSame(9000, $item['lineTotalAmount']); // 4500 × 2
        self::assertSame(1890, $item['lineTaxAmount']);   // 945  × 2

        // Shipping address echoed back
        self::assertSame('Ada',           $body['shippingFirstName']);
        self::assertSame('Lovelace',      $body['shippingLastName']);
        self::assertSame('ES12345678Z',   $body['shippingVatNumber']);
        self::assertSame('Calle Mayor 1', $body['shippingStreet']);
        self::assertSame('Madrid',        $body['shippingCity']);
        self::assertSame('ES',            $body['shippingCountry']);
    }

    #[Test]
    public function get_order_returns_404_for_unknown_id(): void
    {
        $body = $this->json('GET', '/api/orders/00000000-0000-4000-8000-000000000002');

        self::assertSame(404, $this->statusCode());
        self::assertSame('order_not_found', $body['error']);
        self::assertArrayHasKey('message', $body);
    }

    #[Test]
    public function get_order_returns_403_on_ownership_mismatch(): void
    {
        $customerId = new CustomerId($this->idGenerator->generate());
        $product    = $this->seedProduct(stock: 5);
        ['order' => $order] = $this->seedCheckedOutOrder($product->id(), quantity: 1);

        // Order was created as guest; accessing with any customerId mismatches.
        $body = $this->json(
            'GET',
            '/api/orders/' . $order->id()->value(),
            headers: ['X-Customer-Id' => $customerId->value()],
        );

        self::assertSame(403, $this->statusCode());
        self::assertSame('order_ownership_mismatch', $body['error']);
    }

    // ------------------------------------------------------------------ POST /api/orders/{orderId}/payment  (result: true)

    #[Test]
    public function payment_success_returns_200_with_confirmed_status(): void
    {
        $product = $this->seedProduct(stock: 5);
        ['order' => $order] = $this->seedCheckedOutOrder($product->id(), quantity: 1);

        $body = $this->json(
            'POST',
            '/api/orders/' . $order->id()->value() . '/payment',
            ['result' => true],
        );

        self::assertSame(200, $this->statusCode());
        self::assertSame($order->id()->value(), $body['orderId']);
        self::assertSame('PAYMENT_CONFIRMED', $body['status']);
    }

    #[Test]
    public function payment_success_does_not_change_product_stock(): void
    {
        $product = $this->seedProduct(stock: 10);
        ['order' => $order] = $this->seedCheckedOutOrder($product->id(), quantity: 3);

        // After checkout stock is 7; confirming payment must leave it at 7.
        $this->json(
            'POST',
            '/api/orders/' . $order->id()->value() . '/payment',
            ['result' => true],
        );

        self::assertSame(200, $this->statusCode());

        $updated = $this->reloadProduct($product->id());
        self::assertNotNull($updated);
        self::assertSame(7, $updated->quantity()); // 10 - 3, unchanged
    }

    // ------------------------------------------------------------------ POST /api/orders/{orderId}/payment  (result: false)

    #[Test]
    public function payment_failure_returns_200_with_payment_error_status(): void
    {
        $product = $this->seedProduct(stock: 5);
        ['order' => $order] = $this->seedCheckedOutOrder($product->id(), quantity: 1);

        $body = $this->json(
            'POST',
            '/api/orders/' . $order->id()->value() . '/payment',
            ['result' => false],
        );

        self::assertSame(200, $this->statusCode());
        self::assertSame($order->id()->value(), $body['orderId']);
        self::assertSame('PAYMENT_ERROR', $body['status']);
    }

    #[Test]
    public function payment_failure_restores_product_stock(): void
    {
        $product = $this->seedProduct(stock: 10);
        ['order' => $order] = $this->seedCheckedOutOrder($product->id(), quantity: 4);
        // After checkout: stock = 6

        $this->json(
            'POST',
            '/api/orders/' . $order->id()->value() . '/payment',
            ['result' => false],
        );

        self::assertSame(200, $this->statusCode());

        $updated = $this->reloadProduct($product->id());
        self::assertNotNull($updated);
        self::assertSame(10, $updated->quantity()); // 6 + 4 = 10 restored
    }

    #[Test]
    public function payment_failure_reopens_the_cart(): void
    {
        $product = $this->seedProduct(stock: 5);
        ['cart' => $cart, 'order' => $order] = $this->seedCheckedOutOrder($product->id(), quantity: 1);

        $this->json(
            'POST',
            '/api/orders/' . $order->id()->value() . '/payment',
            ['result' => false],
        );

        self::assertSame(200, $this->statusCode());

        $reloaded = $this->reloadCart($cart->id());
        self::assertNotNull($reloaded);
        self::assertSame(CartStatus::OPEN, $reloaded->status());
    }

    // ------------------------------------------------------------------ sad paths for payment

    #[Test]
    public function payment_returns_404_for_unknown_order(): void
    {
        $body = $this->json(
            'POST',
            '/api/orders/00000000-0000-4000-8000-000000000003/payment',
            ['result' => true],
        );

        self::assertSame(404, $this->statusCode());
        self::assertSame('order_not_found', $body['error']);
    }

    #[Test]
    public function payment_returns_409_when_order_is_not_pending(): void
    {
        $product = $this->seedProduct(stock: 5);
        ['order' => $order] = $this->seedCheckedOutOrder($product->id(), quantity: 1);

        // Confirm payment once to move to PAYMENT_CONFIRMED.
        $this->json('POST', '/api/orders/' . $order->id()->value() . '/payment', ['result' => true]);
        self::assertSame(200, $this->statusCode());

        // Second attempt on the same non-PENDING order must return 409.
        $body = $this->json(
            'POST',
            '/api/orders/' . $order->id()->value() . '/payment',
            ['result' => true],
        );

        self::assertSame(409, $this->statusCode());
        self::assertSame('order_not_pending', $body['error']);
        self::assertArrayHasKey('message', $body);
    }

    #[Test]
    public function payment_failure_returns_409_when_order_is_not_pending(): void
    {
        $product = $this->seedProduct(stock: 5);
        ['order' => $order] = $this->seedCheckedOutOrder($product->id(), quantity: 1);

        // Fail once → PAYMENT_ERROR.
        $this->json('POST', '/api/orders/' . $order->id()->value() . '/payment', ['result' => false]);
        self::assertSame(200, $this->statusCode());

        // Second attempt — order is already PAYMENT_ERROR.
        $body = $this->json(
            'POST',
            '/api/orders/' . $order->id()->value() . '/payment',
            ['result' => false],
        );

        self::assertSame(409, $this->statusCode());
        self::assertSame('order_not_pending', $body['error']);
    }
}
