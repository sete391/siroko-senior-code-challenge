<?php

declare(strict_types=1);

namespace Siroko\Tests\Unit\Sales\Application;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siroko\Sales\Application\Command\RemoveCartItem\RemoveCartItemCommand;
use Siroko\Sales\Application\Command\RemoveCartItem\RemoveCartItemHandler;
use Siroko\Sales\Application\DTO\CartView;
use Siroko\Sales\Domain\Cart\CartRepository;
use Siroko\Sales\Domain\Exception\CartItemNotFound;
use Siroko\Sales\Domain\Exception\CartNotFound;

final class RemoveCartItemHandlerTest extends TestCase
{
    use SalesApplicationTestHelpers;

    private const CART_ID    = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
    private const PRODUCT_ID = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
    private const ORDER_ID   = 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';

    #[Test]
    public function it_removes_item_and_returns_updated_cart_view(): void
    {
        $repo = $this->repoWith($this->cartWithItem());

        $view = $this->handle($repo);

        self::assertInstanceOf(CartView::class, $view);
        self::assertCount(0, $view->items);
    }

    #[Test]
    public function it_throws_cart_not_found_when_cart_is_missing(): void
    {
        $this->expectException(CartNotFound::class);

        $repo = $this->repoWith(null);
        $this->handle($repo);
    }

    #[Test]
    public function it_throws_cart_item_not_found_when_product_not_in_cart(): void
    {
        $this->expectException(CartItemNotFound::class);

        $repo = $this->repoWith($this->openCart()); // empty cart
        $this->handle($repo);
    }

    #[Test]
    public function it_saves_the_cart_after_removal(): void
    {
        $repo = $this->repoWith($this->cartWithItem());
        $repo->expects($this->once())->method('save');

        $this->handle($repo);
    }

    // ------------------------------------------------------------------ helpers

    private function repoWith(?object $cart): CartRepository
    {
        $repo = $this->createMock(CartRepository::class);
        $repo->method('findById')->willReturn($cart);

        return $repo;
    }

    private function handle(CartRepository $repo): CartView
    {
        return (new RemoveCartItemHandler($repo))(
            new RemoveCartItemCommand(self::CART_ID, null, self::PRODUCT_ID),
        );
    }
}
