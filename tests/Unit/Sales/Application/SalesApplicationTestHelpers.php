<?php

declare(strict_types=1);

namespace Siroko\Tests\Unit\Sales\Application;

use Siroko\Catalog\Domain\Product;
use Siroko\Catalog\Domain\ProductId;
use Siroko\Catalog\Domain\ProductStatus;
use Siroko\Sales\Domain\Cart\Cart;
use Siroko\Sales\Domain\Order\Order;
use Siroko\Sales\Domain\ValueObject\CartId;
use Siroko\Sales\Domain\ValueObject\OrderId;
use Siroko\Sales\Domain\ValueObject\ShippingAddress;
use Siroko\Shared\Domain\ValueObject\Money;

trait SalesApplicationTestHelpers
{
    protected function openCart(string $cartId = self::CART_ID): Cart
    {
        return Cart::create(new CartId($cartId), null);
    }

    protected function cartWithItem(
        string $cartId = self::CART_ID,
        string $productId = self::PRODUCT_ID,
        int $price = 4500,
        int $tax = 945,
        int $qty = 2,
        int $stock = 10,
    ): Cart {
        $cart = Cart::create(new CartId($cartId), null);
        $cart->addItem(new ProductId($productId), new Money($price), $tax, $qty, $stock);

        return $cart;
    }

    protected function activeProduct(
        string $productId = self::PRODUCT_ID,
        int $price = 4500,
        int $tax = 945,
        int $stock = 10,
    ): Product {
        return new Product(
            id:          new ProductId($productId),
            name:        'Siroko K38',
            description: 'Cycling sunglasses',
            taxAmount:   $tax,
            unitPrice:   new Money($price),
            quantity:    $stock,
            status:      ProductStatus::ACTIVE,
            createdAt:   new \DateTimeImmutable(),
            updatedAt:   new \DateTimeImmutable(),
        );
    }

    protected function inactiveProduct(string $productId = self::PRODUCT_ID): Product
    {
        return new Product(
            id:          new ProductId($productId),
            name:        'Retired',
            description: '',
            taxAmount:   0,
            unitPrice:   new Money(1000),
            quantity:    5,
            status:      ProductStatus::INACTIVE,
            createdAt:   new \DateTimeImmutable(),
            updatedAt:   new \DateTimeImmutable(),
        );
    }

    protected function pendingOrder(): Order
    {
        $cart = $this->cartWithItem();

        return Order::fromCart(
            new OrderId(self::ORDER_ID),
            $cart,
            new ShippingAddress('Ada', 'Lovelace', 'ES123', 'Calle Mayor 1', 'Madrid', 'Madrid', '28013', 'ES'),
        );
    }
}
