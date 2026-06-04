# Siroko Cart & Checkout — Implementation Guide

> This document is the single source of truth for implementing **Option A** of the
> Siroko Senior Code Challenge (Cart & Checkout). It is both the domain
> specification and the engineering blueprint. Read it end to end before writing
> any code. Section 11 ("Constraints") is binding.

---

## 1. Context and Objective

Siroko is a sports e-commerce brand focused on cycling and fitness products.
This project implements a **shopping cart and checkout** flow that lets any user
(authenticated or guest) add products to a cart quickly, and then complete a
checkout that produces a persistent order ready for payment.

The solution must demonstrate:

- A domain decoupled from the framework (Symfony is an infrastructure detail).
- Sound use of **Hexagonal Architecture** and **DDD** (aggregates, entities,
  value objects, domain services and domain events where they earn their place).
- Fluent application of **CQRS** (commands mutate, queries read, separate buses).
- Exhaustive testing with maximum use-case coverage.
- Justified, measured performance — no premature optimization, but no obvious
  N+1 queries or unbounded loads either.

### Scope boundaries

| In scope | Out of scope |
|----------|--------------|
| Cart lifecycle (create, add, update, remove, read) | Customer / account management |
| Checkout → Order creation | Product CRUD (products are seeded) |
| Payment result handling (confirm / error) | Real payment gateway integration |
| Guest checkout (optional `CustomerId`) | Tax calculation by country |
| Stock reservation & restoration | Shipping cost / carrier integration |

---

## 2. Architectural Decisions

### 2.1 Tech stack

| Concern | Choice | Rationale |
|---------|--------|-----------|
| Language | **PHP 8.4** | Readonly classes, enums, named args, property hooks, asymmetric visibility. |
| Framework | **Symfony 7.x** | DI container, Messenger (CQRS buses), validation, HTTP kernel. |
| Persistence | **MySQL 8** via **Doctrine ORM 3** | Relational integrity for orders; transactional checkout. |
| Messaging | **Symfony Messenger** | Two buses: `command.bus` and `query.bus`. |
| IDs | **UUID v4** (`symfony/uid`) | Client- or server-generated, no DB round-trip to obtain an ID. |
| Testing | **PHPUnit 11** | Unit + integration + functional layers. |
| Quality | **PHPStan (max)** + **PHP-CS-Fixer** | Static analysis and style gate in CI. |
| Runtime | **Docker Compose** (php-fpm, nginx, mysql) | Reproducible local environment. |

### 2.2 Hexagonal Architecture (Ports & Adapters)

Three concentric layers per bounded context. Dependencies point **inward only**:

```
Infrastructure  ──▶  Application  ──▶  Domain
   (adapters)         (use cases)      (pure model)
```

- **Domain** — entities, value objects, aggregates, domain events, domain
  services, and repository *interfaces* (ports). Zero framework imports. No
  Doctrine annotations inside aggregates (mapping lives in XML/PHP outside the
  entity — see §8.2). Pure PHP.
- **Application** — command/query handlers (use cases), command/query DTOs,
  application services, ports for outbound concerns (e.g. `Clock`,
  `EventBus`). Depends on Domain only.
- **Infrastructure** — Doctrine repository implementations (adapters), HTTP
  controllers, Messenger config, fixtures/seeders, the Symfony kernel. Depends
  on Application and Domain.

### 2.3 CQRS

- **Commands** change state and return either nothing or a minimal identifier
  (e.g. the created `OrderId`). Dispatched on `command.bus`.
- **Queries** never mutate and return read DTOs (not aggregates). Dispatched on
  `query.bus`.
- One handler per command/query. Handlers are the use cases.
- We use a **single shared model** for reads and writes (no separate read store).
  Read sides return flat DTOs assembled from the same Doctrine repositories. This
  is "CQRS at the application boundary" — enough to demonstrate the pattern
  without the overhead of eventual consistency, which the challenge does not need.

### 2.4 Domain events

Events are recorded on aggregates and dispatched **after** the command
transaction commits, via Messenger. They are used for decoupled side effects
(logging, future projections), not for the core transactional flow — the
checkout transaction itself is explicit and synchronous (see §4.7). Events are
nice-to-have for this challenge; record them, dispatch them, but do not build
critical behavior on top of asynchronous delivery.

---

## 3. Domain Model

Bounded contexts: **Catalog** (Product) and **Sales** (Cart, Order). They share
value objects via a small **Shared Kernel**.

### 3.1 Aggregates

#### Product (Catalog) — aggregate root
| Field | Type | Notes |
|-------|------|-------|
| `id` | `ProductId` (UUID v4) | |
| `name` | `string` | |
| `description` | `string` | |
| `taxAmount` | `int` | Final tax per unit, in cents. |
| `unitPrice` | `Money` | |
| `quantity` | `int` | Available stock, `>= 0`. |
| `status` | `ProductStatus` enum | `ACTIVE` / `INACTIVE`. |
| `createdAt` / `updatedAt` | `DateTimeImmutable` | |

Behavior: `decreaseStock(int)`, `restoreStock(int)`, `isActive()`,
`isAvailableFor(int qty)`.

#### Cart (Sales) — aggregate root
| Field | Type | Notes |
|-------|------|-------|
| `id` | `CartId` (UUID v4) | |
| `customerId` | `?CustomerId` | Nullable → guest cart. |
| `items` | `CartItem[]` | Internal entities. |
| `status` | `CartStatus` enum | `OPEN` / `CHECKED_OUT`. |
| `createdAt` / `updatedAt` | `DateTimeImmutable` | |

Behavior: `addItem(...)`, `updateItem(...)`, `removeItem(ProductId)`,
`isEmpty()`, `reopen()`, `markCheckedOut()`, `assertOwnedBy(?CustomerId)`,
`assertModifiable()`.

#### Order (Sales) — aggregate root
| Field | Type | Notes |
|-------|------|-------|
| `id` | `OrderId` (UUID v4) | |
| `cartId` | `CartId` | Traceability only — **no FK** (see §10). |
| `customerId` | `?CustomerId` | |
| `items` | `OrderItem[]` | Internal entities. |
| `totalTax` | `Money` | |
| `totalProducts` | `Money` | |
| `totalOrder` | `Money` | `totalProducts + totalTax`. |
| `shippingAddress` | `ShippingAddress` | |
| `status` | `OrderStatus` enum | `PENDING` / `PAYMENT_CONFIRMED` / `PAYMENT_ERROR`. |
| `createdAt` / `updatedAt` | `DateTimeImmutable` | |

Behavior: `confirmPayment()`, `failPayment()`, static `fromCart(...)`.

### 3.2 Internal entities (not aggregate roots)

- **CartItem** — `productId`, `unitPrice: Money`, `taxAmount: int`,
  `quantity: int`. Price and tax are a **snapshot** taken when the item is added.
  Only reachable through the `Cart` root.
- **OrderItem** — `productId`, `unitPrice: Money`, `taxAmount: int`,
  `quantity: int`, plus computed `lineTotal()` and `lineTax()`. Copied from the
  matching `CartItem` at checkout. Only reachable through the `Order` root.

### 3.3 Value objects (Shared Kernel)

- **Money** — `amount: int` (cents, `>= 0`), `currency: string` (ISO 4217).
  Immutable. Operations: `add`, `subtract`, `multiply(int)`. Mixing currencies
  throws. Default currency `EUR`.
- **ShippingAddress** — `firstName`, `lastName`, `vatNumber`, `street`, `city`,
  `state`, `zipCode`, `country`. **All fields required and non-empty.**
- **ProductId / CartId / OrderId / CustomerId** — UUID v4 wrappers. Construction
  validates format; expose `value(): string` and `equals(self)`.
- **Quantity** — positive integer VO (`>= 1`) used on cart/order lines to make
  the "minimum 1" rule self-enforcing.

### 3.4 Enums

- `ProductStatus`: `ACTIVE`, `INACTIVE`.
- `CartStatus`: `OPEN`, `CHECKED_OUT`.
- `OrderStatus`: `PENDING`, `PAYMENT_CONFIRMED`, `PAYMENT_ERROR`.

### 3.5 Domain events

| Event | Raised when |
|-------|-------------|
| `CartItemAdded` | A line is added. |
| `CartItemQuantityUpdated` | A line quantity changes. |
| `CartItemRemoved` | A line is removed. |
| `CartCheckedOut` | Cart transitions to `CHECKED_OUT`. |
| `OrderPlaced` | Order created in `PENDING`. |
| `OrderPaymentConfirmed` | Payment succeeds. |
| `OrderPaymentFailed` | Payment fails (stock restored, cart reopened). |

### 3.6 Business rules (invariants)

**Product**
- Price must be `> 0`.
- Stock quantity must be `>= 0`.

**Cart**
- Cannot hold two lines for the same product (a repeated add merges quantities).
- Minimum quantity per line is `1`; adding quantity `0` is rejected.
- A line's total quantity cannot exceed the product's available stock.
- `unitPrice` and `taxAmount` are captured as a **snapshot** when the product is
  added — later product price changes do not retroactively change the line.
- An empty cart is valid and may persist.
- A `CHECKED_OUT` cart is immutable (until reopened by a payment failure).

**Order**
- Cannot be created from an empty cart.
- Line quantities cannot exceed available product stock at checkout time.
- `totalOrder` must equal the sum of line totals; `totalTax` the sum of line
  taxes; `totalProducts` the sum of `unitPrice × quantity`.
- `OrderItem.unitPrice` / `taxAmount` are copied from the `CartItem`, **not** from
  the current product. Coherence between `CartItem` and `Product` (price + stock)
  is validated *before* creating the order (see §4.7).

---

## 4. Use Cases

Each use case is a CQRS command or query with one handler. Format below:
**input → output**, then happy path and sad paths (each sad path is the precise
domain exception thrown / HTTP status returned).

### 4.1 List products (Query)
- **In:** —
- **Out:** `ProductView[]` (active products only).
- **Happy:** Returns all `ACTIVE` products.
- **Sad:** none (empty list is valid → `200 []`).

### 4.2 Get product (Query)
- **In:** `ProductId`
- **Out:** `ProductView` (active only).
- **Happy:** Returns the active product.
- **Sad:**
  1. Product does not exist → `ProductNotFound` → `404`.
  2. Product exists but is `INACTIVE` → treated as not found → `404`.

### 4.3 Add item to cart (Command)
- **In:** `CartId`, `?CustomerId`, `ProductId`, `int quantity`
- **Out:** `CartView`
- **Happy:**
  1. If `CartId` is provided and exists → add to it
  2. If `CartId` is not provided → create a new `OPEN` cart, then add.
  3. If the product is already a line → sum the quantities into that line.
  4. Capture `unitPrice` + `taxAmount` snapshot from the product.
- **Sad:**
  1. `CartId` provided but does not exist → `CartNotFound` → `404`.
  2. Cart exists but is `CHECKED_OUT` → `CartNotModifiable` → `409`.
  3. `ProductId` does not exist → `ProductNotFound` → `404`.
  4. Product is not `ACTIVE` → `ProductNotActive` → `409`.
  5. Resulting line quantity > stock → `InsufficientStock` → `409`.
  6. `quantity < 1` → `InvalidQuantity` → `422`.
  7. `CustomerId` provided ≠ cart's `customerId` → `CartOwnershipMismatch` → `403`.

### 4.4 Update cart item (Command)
- **In:** `CartId`, `?CustomerId`, `ProductId`, `int quantity`
- **Out:** `CartView`
- **Happy:**
  1. `quantity == 0` → remove the line.
  2. `quantity > 0` → set the line to that quantity (absolute, not additive).
  3. Line absent and `quantity > 0` → add it as a new line.
- **Sad:**
  1. `CartId` does not exist → `CartNotFound` → `404`.
  2. Cart is `CHECKED_OUT` → `CartNotModifiable` → `409`.
  3. `ProductId` does not exist → `ProductNotFound` → `404`.
  4. Product not `ACTIVE` → `ProductNotActive` → `409`.
  5. Requested quantity > stock → `InsufficientStock` → `409`.
  6. `CustomerId` mismatch → `CartOwnershipMismatch` → `403`.
  7. `quantity < 0` → `InvalidQuantity` → `422`.

### 4.5 Remove cart item (Command)
- **In:** `CartId`, `?CustomerId`, `ProductId`
- **Out:** `CartView`
- **Happy:** Removes the line for that product.
- **Sad:**
  1. `CartId` does not exist → `CartNotFound` → `404`.
  2. Cart is `CHECKED_OUT` → `CartNotModifiable` → `409`.
  3. `ProductId` is not a line in the cart → `CartItemNotFound` → `404`.
  4. `CustomerId` mismatch → `CartOwnershipMismatch` → `403`.

### 4.6 Get cart (Query)
- **In:** `CartId`, `?CustomerId`
- **Out:** `CartView` (items + computed totals).
- **Happy:** Returns the cart and its lines.
- **Sad:**
  1. `CartId` does not exist → `CartNotFound` → `404`.
  2. `CustomerId` mismatch → `CartOwnershipMismatch` → `403`.

### 4.7 Checkout (Command)
- **In:** `CartId`, `?CustomerId`, `ShippingAddress`
- **Out:** `OrderView` (includes `OrderId` for payment).
- **Happy:** All steps run inside a single transaction:
  1. Load cart; assert ownership and `OPEN` status; assert not empty.
  2. For each line, load the product and **validate coherence**: product exists,
     is active, has enough stock, and its current `unitPrice`/`taxAmount` match
     the line snapshot.
  3. Decrease product stock for each line.
  4. Mark cart `CHECKED_OUT`.
  5. Create the order in `PENDING`, copying lines into `OrderItem`s and computing
     totals.
  6. Commit. Return `OrderId`.
- **Coherence-failure behavior:** if a product's price has changed or stock is
  insufficient, **refresh the offending `CartItem` snapshot**, **roll back** the
  checkout, and return a **warning** (`409` with a structured body listing the
  affected lines). No order is created and no stock is decremented.
- **Sad:**
  1. `CartId` does not exist → `CartNotFound` → `404`.
  2. Cart is `CHECKED_OUT` → `CartNotModifiable` → `409`.
  3. A product no longer exists → `ProductNotFound` → `409` (checkout context).
  4. A product is not active → `ProductNotActive` → `409`.
  5. Insufficient stock → `InsufficientStock` (+ snapshot refresh) → `409`.
  6. `ShippingAddress` invalid → `InvalidShippingAddress` → `422`.
  7. Cart is empty → `EmptyCartCannotCheckout` → `409`.
  8. `CustomerId` mismatch → `CartOwnershipMismatch` → `403`.

> **Transactionality:** steps 1–6 run inside one Doctrine transaction
> (`wrapInTransaction`). Domain events are dispatched only after commit.

### 4.8 Get order (Query)
- **In:** `OrderId`, `?CustomerId`
- **Out:** `OrderView` (items + totals + status).
- **Happy:** Returns the order.
- **Sad:**
  1. `OrderId` does not exist → `OrderNotFound` → `404`.
  2. `CustomerId` mismatch → `OrderOwnershipMismatch` → `403`.

### 4.9 Process payment (Command)
- **In:** `OrderId`, `bool result`
- **Out:** —
- **Happy:**
  1. `result == true` → order → `PAYMENT_CONFIRMED`.
  2. `result == false` →
    1. order → `PAYMENT_ERROR`,
    2. **restore** product stock for each line,
    3. reopen the cart (`CHECKED_OUT` → `OPEN`) so a new order can be generated.
- **Sad:**
  1. `OrderId` does not exist → `OrderNotFound` → `404`.
  2. Order is not `PENDING` → `OrderNotPending` → `409`.
- **Note:** On `PAYMENT_ERROR`, the cart or any product may have been deleted
  → **ignore silently** in both cases (order still moves to `PAYMENT_ERROR`;
  stock is restored for the products that still exist).

---

## 5. Project Structure

Source organized by bounded context, each split into the three hexagonal layers.

```
src/
├── Shared/
│   ├── Domain/
│   │   ├── ValueObject/
│   │   │   ├── Money.php
│   │   │   ├── UuidValueObject.php
│   │   │   └── Quantity.php
│   │   ├── Event/
│   │   │   └── DomainEvent.php
│   │   └── Exception/
│   │       └── DomainException.php
│   ├── Application/
│   │   ├── Command/
│   │   │   ├── CommandBus.php
│   │   │   └── Command.php
│   │   └── Query/
│   │       ├── QueryBus.php
│   │       └── Query.php
│   └── Infrastructure/
│       ├── Bus/
│       │   ├── MessengerCommandBus.php
│       │   └── MessengerQueryBus.php
│       └── Http/
│           ├── ApiController.php
│           └── ExceptionListener.php
│
├── Catalog/
│   ├── Domain/
│   │   ├── Product.php
│   │   ├── ProductId.php
│   │   ├── ProductStatus.php
│   │   ├── ProductRepository.php
│   │   └── Exception/
│   │       ├── ProductNotFound.php
│   │       └── ProductNotActive.php
│   ├── Application/
│   │   ├── DTO/
│   │   │   └── ProductView.php
│   │   └── Query/
│   │       ├── ListProducts/
│   │       │   ├── ListProductsQuery.php
│   │       │   └── ListProductsHandler.php
│   │       └── GetProduct/
│   │           ├── GetProductQuery.php
│   │           └── GetProductHandler.php
│   └── Infrastructure/
│       ├── Persistence/
│       │   └── Doctrine/
│       │       ├── DoctrineProductRepository.php
│       │       └── Product.orm.xml
│       └── Http/
│           └── ProductController.php
│
└── Sales/
    ├── Domain/
    │   ├── Cart/
    │   │   ├── Cart.php
    │   │   ├── CartItem.php
    │   │   ├── CartStatus.php
    │   │   └── CartRepository.php
    │   ├── Order/
    │   │   ├── Order.php
    │   │   ├── OrderItem.php
    │   │   ├── OrderStatus.php
    │   │   └── OrderRepository.php
    │   ├── ValueObject/
    │   │   ├── ShippingAddress.php
    │   │   └── CustomerId.php
    │   ├── Service/
    │   │   └── CheckoutCoherenceChecker.php
    │   └── Exception/
    │       ├── CartNotFound.php
    │       ├── CartNotModifiable.php
    │       ├── CartOwnershipMismatch.php
    │       ├── EmptyCartCannotCheckout.php
    │       ├── InsufficientStock.php
    │       ├── InvalidQuantity.php
    │       ├── InvalidShippingAddress.php
    │       ├── OrderNotFound.php
    │       ├── OrderNotPending.php
    │       └── OrderOwnershipMismatch.php
    ├── Application/
    │   ├── Command/
    │   │   ├── AddItemToCart/
    │   │   │   ├── AddItemToCartCommand.php
    │   │   │   └── AddItemToCartHandler.php
    │   │   ├── UpdateCartItem/
    │   │   │   ├── UpdateCartItemCommand.php
    │   │   │   └── UpdateCartItemHandler.php
    │   │   ├── RemoveCartItem/
    │   │   │   ├── RemoveCartItemCommand.php
    │   │   │   └── RemoveCartItemHandler.php
    │   │   ├── Checkout/
    │   │   │   ├── CheckoutCommand.php
    │   │   │   └── CheckoutHandler.php
    │   │   └── ProcessPayment/
    │   │       ├── ProcessPaymentCommand.php
    │   │       └── ProcessPaymentHandler.php
    │   ├── DTO/
    │   │   ├── CartView.php
    │   │   └── OrderView.php
    │   └── Query/
    │       ├── GetCart/
    │       │   ├── GetCartQuery.php
    │       │   └── GetCartHandler.php
    │       └── GetOrder/
    │           ├── GetOrderQuery.php
    │           └── GetOrderHandler.php
    └── Infrastructure/
        ├── Persistence/
        │   └── Doctrine/
        │       ├── DoctrineCartRepository.php
        │       ├── DoctrineOrderRepository.php
        │       ├── Cart.orm.xml
        │       └── Order.orm.xml
        └── Http/
            ├── CartController.php
            └── OrderController.php

tests/
├── Unit/
│   ├── Shared/
│   │   └── Domain/
│   │       └── ValueObject/
│   ├── Catalog/
│   │   └── Domain/
│   └── Sales/
│       └── Domain/
│           ├── Cart/
│           └── Order/
├── Integration/
│   ├── Catalog/
│   │   └── Infrastructure/
│   └── Sales/
│       └── Infrastructure/
└── Functional/
    ├── Catalog/
    └── Sales/

config/, migrations/, docker/, bin/   # Symfony, Doctrine migrations, compose
```

Each use-case folder contains its `Command`/`Query`, its `Handler`, and any
dedicated DTO, keeping vertical slices cohesive.

---

## 6. API Endpoints

Base path: `/api`. Content type `application/json`. UUIDs in the path are v4.
`CustomerId`, when present, is supplied via the `X-Customer-Id` header (guest
when absent).

| # | Method | Route | Use case | Success |
|---|--------|-------|----------|---------|
| 1 | `GET` | `/api/products` | List products | `200` |
| 2 | `GET` | `/api/products/{productId}` | Get product | `200` |
| 3 | `POST` | `/api/carts/{cartId}/items` | Add item to cart | `200` |
| 4 | `PUT` | `/api/carts/{cartId}/items/{productId}` | Update cart item | `200` |
| 5 | `DELETE` | `/api/carts/{cartId}/items/{productId}` | Remove cart item | `200` |
| 6 | `GET` | `/api/carts/{cartId}` | Get cart | `200` |
| 7 | `POST` | `/api/carts/{cartId}/checkout` | Checkout | `201` |
| 8 | `GET` | `/api/orders/{orderId}` | Get order | `200` |
| 9 | `POST` | `/api/orders/{orderId}/payment` | Process payment | `200` |

### 6.1 Representative request/response shapes

**Add item to cart** — `POST /api/carts/{cartId}/items`
```jsonc
// Request
{ "productId": "a3f1...uuid", "quantity": 2 }
// Response 200 (CartView)
{
  "cartId": "c1...uuid",
  "customerId": null,
  "status": "OPEN",
  "items": [
    { "productId": "a3f1...uuid", "quantity": 2,
      "unitPrice": { "amount": 4500, "currency": "EUR" }, "taxAmount": 945 }
  ],
  "totals": { "products": 9000, "tax": 1890, "total": 10890, "currency": "EUR" }
}
```

**Checkout** — `POST /api/carts/{cartId}/checkout`
```jsonc
// Request
{ "shippingAddress": {
    "firstName": "Ada", "lastName": "Lovelace", "vatNumber": "ES12345678Z",
    "street": "Calle Mayor 1", "city": "Madrid", "state": "Madrid",
    "zipCode": "28013", "country": "ES" } }
// Response 201 (OrderView)
{ "orderId": "o9...uuid", "status": "PENDING",
  "totals": { "products": 9000, "tax": 1890, "total": 10890, "currency": "EUR" },
  "items": [ /* OrderItemView[] */ ] }
// Response 409 (coherence warning — no order created)
{ "error": "checkout_coherence_failed",
  "message": "Some items changed and were refreshed. Review your cart.",
  "affectedItems": [ { "productId": "a3f1...uuid", "reason": "price_changed" } ] }
```

**Process payment** — `POST /api/orders/{orderId}/payment`
```jsonc
// Request
{ "result": true }
// Response 200
{ "orderId": "o9...uuid", "status": "PAYMENT_CONFIRMED" }
```

### 6.2 Error envelope

All domain errors map to a consistent JSON body via a single exception listener:
```jsonc
{ "error": "insufficient_stock", "message": "Product stock is lower than requested quantity." }
```
Status mapping summary: not-found → `404`, ownership mismatch → `403`,
state/stock/active conflicts → `409`, validation → `422`.

---

## 7. Persistence Schema

MySQL 8, InnoDB, `utf8mb4`. Money stored as integer cents + currency char(3).
UUIDs stored as `CHAR(36)` (readable; `BINARY(16)` is a possible perf
optimization noted in §9).

### 7.1 Tables

**`products`**
| Column | Type | Notes |
|--------|------|-------|
| `id` | CHAR(36) PK | |
| `name` | VARCHAR(255) | |
| `description` | TEXT | |
| `tax_amount` | INT UNSIGNED | cents |
| `unit_price_amount` | INT UNSIGNED | cents |
| `unit_price_currency` | CHAR(3) | |
| `quantity` | INT UNSIGNED | stock |
| `status` | VARCHAR(16) | enum string |
| `created_at` / `updated_at` | DATETIME | |
| index | `idx_status` on `status` | speeds active listing |

**`carts`**
| Column | Type | Notes |
|--------|------|-------|
| `id` | CHAR(36) PK | |
| `customer_id` | CHAR(36) NULL | guest when null |
| `status` | VARCHAR(16) | |
| `created_at` / `updated_at` | DATETIME | |
| index | `idx_customer` on `customer_id` | |

**`cart_items`**
| Column | Type | Notes |
|--------|------|-------|
| `id` | CHAR(36) PK | surrogate |
| `cart_id` | CHAR(36) FK → carts.id (ON DELETE CASCADE) | |
| `product_id` | CHAR(36) | snapshot reference, no FK |
| `unit_price_amount` | INT UNSIGNED | snapshot |
| `unit_price_currency` | CHAR(3) | |
| `tax_amount` | INT UNSIGNED | snapshot |
| `quantity` | INT UNSIGNED | `>= 1` |
| unique | `uniq_cart_product` on (`cart_id`,`product_id`) | enforces "no duplicate line" |

**`orders`**
| Column | Type | Notes |
|--------|------|-------|
| `id` | CHAR(36) PK | |
| `cart_id` | CHAR(36) | traceability only, **no FK** |
| `customer_id` | CHAR(36) NULL | |
| `total_tax_amount` / `total_products_amount` / `total_order_amount` | INT UNSIGNED | cents |
| `currency` | CHAR(3) | |
| `shipping_first_name` … `shipping_country` | VARCHAR | embedded VO columns |
| `status` | VARCHAR(24) | |
| `created_at` / `updated_at` | DATETIME | |

**`order_items`**
| Column | Type | Notes |
|--------|------|-------|
| `id` | CHAR(36) PK | |
| `order_id` | CHAR(36) FK → orders.id (ON DELETE CASCADE) | |
| `product_id` | CHAR(36) | copied snapshot, no FK |
| `unit_price_amount` / `unit_price_currency` / `tax_amount` / `quantity` | | copied from cart_item |

### 7.2 Doctrine mapping considerations

- **Mapping lives outside the domain.** Use XML mapping
  (`*.orm.xml`) under each context's `Infrastructure/Persistence/Doctrine` so the
  aggregates stay annotation-free and framework-agnostic.
- **Value objects as Embeddables.** `Money` and `ShippingAddress` map to
  `<embedded>`; UUID VOs map via custom Doctrine **types**
  (`product_id`, `cart_id`, `order_id`, `customer_id`) that convert to/from
  `CHAR(36)`.
- **Collections.** `cart_items` and `order_items` map as `one-to-many` with
  `cascade={persist,remove}` and `orphanRemoval=true`, so removing a line from
  the aggregate deletes the row.
- **No lazy surprises in handlers.** Load aggregates fully where the use case
  mutates them; for queries, prefer DQL/array hydration into view DTOs to avoid
  hydrating full object graphs.
- **The `cartId` on `orders` is a plain column, not an association** — deliberately
  no relation/FK, matching the "carts may be deleted" decision.

---

## 8. Testing Strategy

Three layers, in order of count (a wide unit base, a focused functional top).

### 8.1 Unit tests (Domain) — the bulk
- Value objects: `Money` arithmetic, currency-mismatch rejection, non-negative
  amount; `ShippingAddress` required fields; UUID format validation; `Quantity`
  minimum.
- Aggregates: every invariant in §3.6 as a test —
  - Cart: merge on duplicate add, reject quantity 0, reject over-stock, snapshot
    capture, immutability when `CHECKED_OUT`, reopen on payment failure.
  - Order: `fromCart` totals correctness, empty-cart rejection, line copying.
  - Product: stock decrease/restore, active check, price `> 0`.
- No container, no database — pure, fast.

### 8.2 Integration tests (Infrastructure)
- Doctrine repositories against a real MySQL (test DB): save/find round-trips for
  Product, Cart (with items), Order (with items); unique constraint on
  (`cart_id`,`product_id`); embeddables persist/hydrate correctly; UUID custom
  types round-trip.
- Checkout transaction: assert atomicity — a forced failure mid-checkout leaves
  stock and cart untouched.

### 8.3 Functional tests (HTTP)
- One happy-path test per endpoint in §6, plus the key sad paths from §4:
  - Add to non-existent cart creates it; add over stock → `409`; ownership
    mismatch → `403`.
  - Update to `0` removes line; checkout of empty cart → `409`; checkout
    coherence warning → `409` with `affectedItems` and no order created.
  - Payment `false` restores stock and reopens cart; payment on non-`PENDING`
    order → `409`.
- Assert both status codes and response body shape (error envelope included).

### 8.4 Coverage target & gates
- Aim for **near-100% domain coverage** and full use-case coverage; overall
  target ≥ 85% lines.
- CI gate: PHPUnit (all suites) + PHPStan max + PHP-CS-Fixer dry-run must pass.
- Fixtures/seeders provide deterministic product data for functional/integration
  runs.

---

## 9. Performance Considerations (justified, not premature)

- `idx_status` on `products` for the active-listing query.
- Unique index doubles as the lookup index for cart-line operations.
- Read queries hydrate into DTOs (array hydration) to skip full object graphs.
- `BINARY(16)` UUID storage is noted as a future optimization; we ship `CHAR(36)`
  first for readability and only switch with a measured reason.
- No caching layer in v1 — measure before adding one.

---

## 10. Domain Notes & Assumptions

- `CustomerId` is optional to allow **guest checkout**; customer management is out
  of scope.
- **Product management is out of scope** — products are seeded via an
  infrastructure seeder command (`bin/console app:seed-products` or fixtures).
- **Tax by country is out of scope** — `taxAmount` is a final per-product value.
- **Real payment processing is out of scope** — the system only receives a
  boolean payment result to set the final order status.
- `Order` stores `cartId` for traceability **without an FK**, since carts may be
  deleted over time and an FK would create inconsistencies.
- `vatNumber` in `ShippingAddress` is assumed mandatory for all countries, for
  simplicity.
- Currency is assumed a single currency (`EUR`) across the system in v1;
  `Money` still carries currency to keep the model honest.

---

## 11. Constraints — what the AI must NOT do during implementation

These are binding. Violating any of them is a defect, not a style preference.

1. **Do not leak the framework into the domain.** No Symfony, Doctrine, or
   Messenger imports inside `*/Domain`. No ORM attributes on aggregates — mapping
   is XML in Infrastructure.
2. **Do not let dependencies point outward.** Domain must not depend on
   Application or Infrastructure; Application must not depend on Infrastructure.
3. **Do not bypass the buses.** Controllers dispatch commands/queries; they never
   call repositories or handlers directly, and never contain business logic.
4. **Do not return aggregates from queries** or from controllers. Queries return
   view DTOs.
5. **Do not use primitive obsession** for identifiers, money, or addresses — use
   the value objects defined in §3.3. No raw UUID strings or int prices crossing
   layer boundaries.
6. **Do not make checkout non-transactional.** Stock decrement, cart status, and
   order creation commit together or not at all. Dispatch domain events only
   after commit.
7. **Do not compute prices/tax from the live product at order time** — copy the
   `CartItem` snapshot, after validating coherence (§4.7).
8. **Do not add a real payment gateway, customer/auth system, product CRUD, or
   per-country tax logic** — they are explicitly out of scope.
9. **Do not introduce new aggregates, tables, endpoints, or statuses** beyond
   those specified here without recording the decision in this document first.
10. **Do not skip tests** for any use case or invariant, and do not weaken the
    PHPStan/CS gates to make code pass.
11. **Do not store money as floats.** Integer cents only.
12. **Do not add an FK from `orders.cart_id` to `carts`** (see §10).
13. **Do not silently swallow errors** other than the two explicitly allowed
    during payment-failure stock restoration (§4.9): a missing cart and a
    missing product are both ignored silently, since either may have been
    deleted after the order was placed.
14. **Do not over-engineer.** No event sourcing, no separate read database, no
    microservices, no speculative abstractions. Keep CQRS at the application
    boundary as described in §2.3.
```

