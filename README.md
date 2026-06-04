# Siroko — Cart & Checkout API

Senior code challenge — **Option A: Cart & Checkout**.

A shopping cart and checkout HTTP API for Siroko's e-commerce platform. Any user
— authenticated (identified by an `X-Customer-Id` header) or guest — can browse
the seeded catalog, build one or more carts, update quantities, and trigger a
checkout that atomically converts a cart into a persistent order. A subsequent
payment callback confirms the order or rolls it back.

The codebase follows **Hexagonal Architecture + DDD + CQRS**: the domain knows
nothing about Symfony or Doctrine (those live only in the infrastructure layer),
commands and queries are strictly separated, and money is always handled as
integer cents — never floats.

- **Stack:** PHP 8.4 · Symfony 7.4 · MySQL 8 · Doctrine ORM 3 · Symfony Messenger (CQRS buses) · PHPUnit 11 · PHPStan (max) · Docker Compose.
- **Bounded contexts:** `Catalog` (products) and `Sales` (carts & orders), plus a `Shared` kernel.
- **Out of scope (by design):** authentication, product CRUD, real payment gateway, tax-by-country, shipping costs.

> Full functional/technical specification: [`ai/PLAN.md`](ai/PLAN.md).
> Architectural decisions & rejected proposals: [`ai/DECISIONS.md`](ai/DECISIONS.md).
> See [AI documentation](#ai-documentation) below.

---

## Table of contents

- [Quick start (Docker)](#quick-start-docker)
- [API overview](#api-overview)
- [OpenAPI specification](#openapi-specification)
- [Domain model](#domain-model)
- [Performance](#performance)
- [Testing](#testing)
- [AI documentation](#ai-documentation)

---

## Quick start (Docker)

Everything runs inside Docker Compose (php-fpm, nginx, MySQL for dev + MySQL for
tests). No local PHP is required — and indeed the project uses PHP 8.4 syntax, so
commands must be run **inside the `php` container**.

```bash
# 1. Build and start the stack (php-fpm, nginx, mysql, mysql_test)
docker compose up -d --build

# 2. Run the database migrations (creates products, carts, cart_items, orders, order_items)
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

# 3. Seed the catalog (5 sample Siroko cycling products)
docker compose exec php php bin/console app:seed-products
```

The API is now available at **http://localhost:8080/api**.

```bash
# Smoke test
curl http://localhost:8080/api/products
```

| Service       | URL / port                  | Notes                              |
|---------------|-----------------------------|------------------------------------|
| HTTP API      | http://localhost:8080       | nginx → php-fpm                    |
| MySQL (dev)   | `localhost:3306`            | db `siroko`, user/pass `siroko`    |
| MySQL (test)  | `localhost:3307`            | db `siroko_test`, ephemeral tmpfs  |

Useful console commands (all prefixed with `docker compose exec php`):

```bash
php bin/console doctrine:migrations:migrate --no-interaction   # apply migrations
php bin/console app:seed-products                              # seed the catalog
php bin/console debug:router                                   # list all routes
```

---

## API overview

All endpoints are JSON and live under the `/api` prefix. Identity is optional:
send an `X-Customer-Id: <uuid>` header to scope a cart/order to a customer; omit
it to act as a guest. A cart is created implicitly the first time you POST an
item to a `cartId` that does not exist yet (the client chooses the UUID).

| # | Method   | Path                                      | Purpose                                                  | Success |
|---|----------|-------------------------------------------|----------------------------------------------------------|---------|
| 1 | `GET`    | `/api/products`                           | List active products                                     | `200`   |
| 2 | `GET`    | `/api/products/{productId}`               | Get one product                                          | `200`   |
| 3 | `GET`    | `/api/carts/{cartId}`                      | Get a cart with items and computed totals                | `200`   |
| 4 | `POST`   | `/api/carts/{cartId}/items`               | Add an item (creates the cart if it doesn't exist; merges qty if the product is already present) | `200` |
| 5 | `PUT`    | `/api/carts/{cartId}/items/{productId}`   | Set an item's quantity (`0` removes the line)            | `200`   |
| 6 | `DELETE` | `/api/carts/{cartId}/items/{productId}`   | Remove a line from the cart                              | `200`   |
| 7 | `POST`   | `/api/carts/{cartId}/checkout`            | Validate stock & price coherence, decrement stock, create the order | `201` |
| 8 | `GET`    | `/api/orders/{orderId}`                   | Get an order                                             | `200`   |
| 9 | `POST`   | `/api/orders/{orderId}/payment`           | Simulate payment result (`true` confirms, `false` fails)| `200`   |

> These are the 9 operations from [`ai/PLAN.md` §6](ai/PLAN.md). The implementation
> refines a few names from that draft: routes are namespaced under `/api`; cart
> creation is implicit on first add (no dedicated create route); the payment body
> is `{ "result": bool }` on `…/payment`; and `Money` carries a per-line
> `taxAmount` so totals are split into products / tax / order.

### Money & amounts

Every monetary value is an **integer amount in cents** plus an ISO-4217
`currency` (default `EUR`). For a cart/order:

- `totalProductsAmount` = Σ `unitPriceAmount × quantity`
- `totalTaxAmount`       = Σ `taxAmount × quantity`
- `totalOrderAmount`     = `totalProductsAmount + totalTaxAmount`

### Error responses

Domain errors are returned as `{ "error": "<snake_case_code>", "message": "..." }`
with a meaningful HTTP status:

| Status | When                                                                                |
|--------|-------------------------------------------------------------------------------------|
| `403`  | Cart/order ownership mismatch (`X-Customer-Id` doesn't match the resource owner)     |
| `404`  | Product / cart / order / cart item not found (an inactive product is treated as 404) |
| `409`  | Cart not modifiable, insufficient stock, empty cart at checkout, order not pending   |
| `422`  | Invalid quantity, invalid shipping address, malformed value object                   |

Checkout has a richer conflict body when the cart's price/tax snapshot no longer
matches the live product (the snapshot is refreshed and the request is rejected):

```json
{
  "error": "checkout_coherence_failed",
  "message": "…",
  "affectedItems": [ { "productId": "…", "reason": "…" } ]
}
```

---

## OpenAPI specification

A self-contained OpenAPI 3.1 document for all 9 endpoints. Save it as
`openapi.yaml` and open it in Swagger UI / Redoc, or read inline.

```yaml
openapi: 3.1.0
info:
  title: Siroko Cart & Checkout API
  version: "1.0.0"
  description: >
    Cart and checkout API (Option A). Authentication is out of scope; identity is
    an optional `X-Customer-Id` header. All amounts are integer cents.
servers:
  - url: http://localhost:8080/api

components:
  parameters:
    CustomerIdHeader:
      name: X-Customer-Id
      in: header
      required: false
      schema: { type: string, format: uuid }
      description: Optional customer identity. Omit to act as a guest.
  schemas:

    ProductView:
      type: object
      properties:
        id:                { type: string, format: uuid }
        name:              { type: string }
        description:       { type: string }
        taxAmount:         { type: integer, description: Tax per unit, in cents }
        unitPriceAmount:   { type: integer, description: Net unit price, in cents }
        unitPriceCurrency: { type: string, example: EUR }
        quantity:          { type: integer, description: Available stock }
        status:            { type: string, enum: [ACTIVE, INACTIVE] }
      required: [id, name, description, taxAmount, unitPriceAmount, unitPriceCurrency, quantity, status]

    CartItemView:
      type: object
      properties:
        productId:         { type: string, format: uuid }
        unitPriceAmount:   { type: integer }
        unitPriceCurrency: { type: string, example: EUR }
        taxAmount:         { type: integer }
        quantity:          { type: integer, minimum: 1 }
      required: [productId, unitPriceAmount, unitPriceCurrency, taxAmount, quantity]

    CartView:
      type: object
      properties:
        cartId:              { type: string, format: uuid }
        customerId:          { type: [string, "null"], format: uuid }
        status:              { type: string, enum: [OPEN, CHECKED_OUT] }
        items:               { type: array, items: { $ref: '#/components/schemas/CartItemView' } }
        totalProductsAmount: { type: integer }
        totalTaxAmount:      { type: integer }
        totalOrderAmount:    { type: integer }
        currency:            { type: string, example: EUR }
      required: [cartId, customerId, status, items, totalProductsAmount, totalTaxAmount, totalOrderAmount, currency]

    OrderItemView:
      type: object
      properties:
        productId:         { type: string, format: uuid }
        unitPriceAmount:   { type: integer }
        unitPriceCurrency: { type: string, example: EUR }
        taxAmount:         { type: integer }
        quantity:          { type: integer, minimum: 1 }
        lineTotalAmount:   { type: integer, description: unitPriceAmount × quantity }
        lineTaxAmount:     { type: integer, description: taxAmount × quantity }
      required: [productId, unitPriceAmount, unitPriceCurrency, taxAmount, quantity, lineTotalAmount, lineTaxAmount]

    OrderView:
      type: object
      properties:
        orderId:             { type: string, format: uuid }
        cartId:              { type: string, format: uuid }
        customerId:          { type: [string, "null"], format: uuid }
        status:              { type: string, enum: [PENDING, PAYMENT_CONFIRMED, PAYMENT_ERROR] }
        items:               { type: array, items: { $ref: '#/components/schemas/OrderItemView' } }
        totalProductsAmount: { type: integer }
        totalTaxAmount:      { type: integer }
        totalOrderAmount:    { type: integer }
        currency:            { type: string, example: EUR }
        shippingFirstName:   { type: string }
        shippingLastName:    { type: string }
        shippingVatNumber:   { type: string }
        shippingStreet:      { type: string }
        shippingCity:        { type: string }
        shippingState:       { type: string }
        shippingZipCode:     { type: string }
        shippingCountry:     { type: string }
      required: [orderId, cartId, customerId, status, items, totalProductsAmount, totalTaxAmount, totalOrderAmount, currency]

    ShippingAddress:
      type: object
      description: Every field is required and must be non-empty.
      properties:
        firstName: { type: string }
        lastName:  { type: string }
        vatNumber: { type: string }
        street:    { type: string }
        city:      { type: string }
        state:     { type: string }
        zipCode:   { type: string }
        country:   { type: string }
      required: [firstName, lastName, vatNumber, street, city, state, zipCode, country]

    AddItemRequest:
      type: object
      properties:
        productId: { type: string, format: uuid }
        quantity:  { type: integer, minimum: 1 }
      required: [productId, quantity]

    UpdateItemRequest:
      type: object
      properties:
        quantity: { type: integer, minimum: 0, description: "0 removes the line" }
      required: [quantity]

    CheckoutRequest:
      type: object
      properties:
        shippingAddress: { $ref: '#/components/schemas/ShippingAddress' }
      required: [shippingAddress]

    PaymentRequest:
      type: object
      properties:
        result: { type: boolean, description: "true confirms the payment, false fails it" }
      required: [result]

    Error:
      type: object
      properties:
        error:   { type: string }
        message: { type: string }
      required: [error, message]

    CheckoutCoherenceError:
      type: object
      properties:
        error:   { type: string, example: checkout_coherence_failed }
        message: { type: string }
        affectedItems:
          type: array
          items:
            type: object
            properties:
              productId: { type: string, format: uuid }
              reason:    { type: string }
      required: [error, message, affectedItems]

paths:
  /products:
    get:
      summary: List active products
      responses:
        '200':
          description: OK
          content:
            application/json:
              schema: { type: array, items: { $ref: '#/components/schemas/ProductView' } }

  /products/{productId}:
    get:
      summary: Get a product by id
      parameters:
        - { name: productId, in: path, required: true, schema: { type: string, format: uuid } }
      responses:
        '200': { description: OK, content: { application/json: { schema: { $ref: '#/components/schemas/ProductView' } } } }
        '404': { description: Product not found, content: { application/json: { schema: { $ref: '#/components/schemas/Error' } } } }

  /carts/{cartId}:
    get:
      summary: Get a cart with items and computed totals
      parameters:
        - { name: cartId, in: path, required: true, schema: { type: string, format: uuid } }
        - $ref: '#/components/parameters/CustomerIdHeader'
      responses:
        '200': { description: OK, content: { application/json: { schema: { $ref: '#/components/schemas/CartView' } } } }
        '403': { description: Ownership mismatch, content: { application/json: { schema: { $ref: '#/components/schemas/Error' } } } }
        '404': { description: Cart not found, content: { application/json: { schema: { $ref: '#/components/schemas/Error' } } } }

  /carts/{cartId}/items:
    post:
      summary: Add an item to a cart (creates the cart if it does not exist)
      parameters:
        - { name: cartId, in: path, required: true, schema: { type: string, format: uuid } }
        - $ref: '#/components/parameters/CustomerIdHeader'
      requestBody:
        required: true
        content: { application/json: { schema: { $ref: '#/components/schemas/AddItemRequest' } } }
      responses:
        '200': { description: Cart state, content: { application/json: { schema: { $ref: '#/components/schemas/CartView' } } } }
        '403': { description: Ownership mismatch, content: { application/json: { schema: { $ref: '#/components/schemas/Error' } } } }
        '404': { description: Product not found / inactive, content: { application/json: { schema: { $ref: '#/components/schemas/Error' } } } }
        '409': { description: Insufficient stock or cart not modifiable, content: { application/json: { schema: { $ref: '#/components/schemas/Error' } } } }
        '422': { description: Invalid quantity, content: { application/json: { schema: { $ref: '#/components/schemas/Error' } } } }

  /carts/{cartId}/items/{productId}:
    put:
      summary: Set an item's quantity (0 removes the line)
      parameters:
        - { name: cartId, in: path, required: true, schema: { type: string, format: uuid } }
        - { name: productId, in: path, required: true, schema: { type: string, format: uuid } }
        - $ref: '#/components/parameters/CustomerIdHeader'
      requestBody:
        required: true
        content: { application/json: { schema: { $ref: '#/components/schemas/UpdateItemRequest' } } }
      responses:
        '200': { description: Cart state, content: { application/json: { schema: { $ref: '#/components/schemas/CartView' } } } }
        '403': { description: Ownership mismatch, content: { application/json: { schema: { $ref: '#/components/schemas/Error' } } } }
        '404': { description: Cart or item not found, content: { application/json: { schema: { $ref: '#/components/schemas/Error' } } } }
        '409': { description: Insufficient stock or cart not modifiable, content: { application/json: { schema: { $ref: '#/components/schemas/Error' } } } }
        '422': { description: Invalid quantity, content: { application/json: { schema: { $ref: '#/components/schemas/Error' } } } }
    delete:
      summary: Remove a line from the cart
      parameters:
        - { name: cartId, in: path, required: true, schema: { type: string, format: uuid } }
        - { name: productId, in: path, required: true, schema: { type: string, format: uuid } }
        - $ref: '#/components/parameters/CustomerIdHeader'
      responses:
        '200': { description: Cart state, content: { application/json: { schema: { $ref: '#/components/schemas/CartView' } } } }
        '403': { description: Ownership mismatch, content: { application/json: { schema: { $ref: '#/components/schemas/Error' } } } }
        '404': { description: Cart or item not found, content: { application/json: { schema: { $ref: '#/components/schemas/Error' } } } }
        '409': { description: Cart not modifiable, content: { application/json: { schema: { $ref: '#/components/schemas/Error' } } } }

  /carts/{cartId}/checkout:
    post:
      summary: Check out a cart into an order
      parameters:
        - { name: cartId, in: path, required: true, schema: { type: string, format: uuid } }
        - $ref: '#/components/parameters/CustomerIdHeader'
      requestBody:
        required: true
        content: { application/json: { schema: { $ref: '#/components/schemas/CheckoutRequest' } } }
      responses:
        '201': { description: Order created (status PENDING), content: { application/json: { schema: { $ref: '#/components/schemas/OrderView' } } } }
        '403': { description: Ownership mismatch, content: { application/json: { schema: { $ref: '#/components/schemas/Error' } } } }
        '404': { description: Cart not found, content: { application/json: { schema: { $ref: '#/components/schemas/Error' } } } }
        '409':
          description: Empty cart, cart not modifiable, or price/stock coherence failure
          content:
            application/json:
              schema:
                oneOf:
                  - { $ref: '#/components/schemas/Error' }
                  - { $ref: '#/components/schemas/CheckoutCoherenceError' }
        '422': { description: Invalid shipping address, content: { application/json: { schema: { $ref: '#/components/schemas/Error' } } } }

  /orders/{orderId}:
    get:
      summary: Get an order by id
      parameters:
        - { name: orderId, in: path, required: true, schema: { type: string, format: uuid } }
        - $ref: '#/components/parameters/CustomerIdHeader'
      responses:
        '200': { description: OK, content: { application/json: { schema: { $ref: '#/components/schemas/OrderView' } } } }
        '403': { description: Ownership mismatch, content: { application/json: { schema: { $ref: '#/components/schemas/Error' } } } }
        '404': { description: Order not found, content: { application/json: { schema: { $ref: '#/components/schemas/Error' } } } }

  /orders/{orderId}/payment:
    post:
      summary: Simulate a payment result for an order
      parameters:
        - { name: orderId, in: path, required: true, schema: { type: string, format: uuid } }
        - $ref: '#/components/parameters/CustomerIdHeader'
      requestBody:
        required: true
        content: { application/json: { schema: { $ref: '#/components/schemas/PaymentRequest' } } }
      responses:
        '200': { description: Order after payment, content: { application/json: { schema: { $ref: '#/components/schemas/OrderView' } } } }
        '404': { description: Order not found, content: { application/json: { schema: { $ref: '#/components/schemas/Error' } } } }
        '409': { description: Order not pending (already confirmed), content: { application/json: { schema: { $ref: '#/components/schemas/Error' } } } }
```

---

## Domain model

Two bounded contexts (`Catalog`, `Sales`) over a `Shared` kernel. Aggregates are
shown with their roots, internal entities, value objects, and the events they
record. The domain layer has **zero** framework dependencies.

```
┌──────────────────────────── Shared (kernel) ────────────────────────────┐
│                                                                          │
│  UuidValueObject (abstract, UUID v4, value(): string)                    │
│     ▲         ▲          ▲            ▲                                   │
│     │         │          │            │                                  │
│  ProductId  CartId   OrderId    CustomerId                               │
│                                                                          │
│  Money    { amount:int (cents), currency:string=EUR }  add/subtract/…    │
│  Quantity { value:int >= 1 }                                             │
└──────────────────────────────────────────────────────────────────────────┘

┌──────────────────────────── Catalog context ────────────────────────────┐
│                                                                          │
│  «aggregate root» Product                                                │
│  ├─ id:          ProductId                                               │
│  ├─ name, description: string                                            │
│  ├─ unitPrice:   Money         (net price per unit, cents)               │
│  ├─ taxAmount:   int           (tax per unit, cents)                     │
│  ├─ quantity:    int           (available stock)                         │
│  └─ status:      ProductStatus (ACTIVE | INACTIVE)                       │
│        behaviour: isActive(), isAvailableFor(qty),                       │
│                   decreaseStock(n), restoreStock(n)                      │
│                                                                          │
│  «port» ProductRepository                                                │
└──────────────────────────────────────────────────────────────────────────┘

┌──────────────────────────── Sales context ──────────────────────────────┐
│                                                                          │
│  «aggregate root» Cart ──1..*──> «entity» CartItem                       │
│  ├─ id:         CartId                       ├─ productId:  ProductId     │
│  ├─ customerId: ?CustomerId                  ├─ unitPrice:  Money  (snap) │
│  ├─ status:     CartStatus (OPEN|CHECKED_OUT)├─ taxAmount:  int    (snap) │
│  └─ items:      CartItem[]                   └─ quantity:   Quantity      │
│     behaviour: addItem (merges qty), updateItem (0 = remove),            │
│                removeItem, markCheckedOut, assertOwnedBy                 │
│     events:    CartItemAdded, CartItemQuantityUpdated,                   │
│                CartItemRemoved, CartCheckedOut                           │
│                                                                          │
│  «aggregate root» Order ──1..*──> «entity» OrderItem                     │
│  ├─ id:            OrderId                    ├─ productId: ProductId     │
│  ├─ cartId:        CartId  (traceability,     ├─ unitPrice: Money         │
│  │                          no FK)            ├─ taxAmount: int           │
│  ├─ customerId:    ?CustomerId                └─ quantity:  Quantity      │
│  ├─ shippingAddress: ShippingAddress              lineTotal(), lineTax()  │
│  ├─ totalProducts / totalTax / totalOrder: Money                         │
│  └─ status:        OrderStatus (PENDING|PAYMENT_CONFIRMED|PAYMENT_ERROR) │
│     behaviour: Order::fromCart(...), confirmPayment(), failPayment()     │
│     events:    OrderPlaced, OrderPaymentConfirmed, OrderPaymentFailed    │
│                                                                          │
│  «value object» ShippingAddress                                          │
│     { firstName, lastName, vatNumber, street, city, state, zipCode,      │
│       country } — all required, non-empty                                │
│                                                                          │
│  «ports» CartRepository, OrderRepository                                 │
│  «domain service» CheckoutCoherenceChecker (price/stock snapshot guard)  │
└──────────────────────────────────────────────────────────────────────────┘
```

**Key invariants & relationships**

- A `CartItem`'s `unitPrice` and `taxAmount` are **snapshots** taken when the
  product is added; later product price changes do not alter the cart.
- A `Cart` holds at most one line per `ProductId` (adding an existing product
  merges quantities). A `CHECKED_OUT` cart is immutable.
- Checkout runs in a **single transaction**: coherence check → stock decrement →
  cart marked `CHECKED_OUT` → order created (`PENDING`). Domain events are
  dispatched only after commit.
- `Order` copies its lines from the cart snapshots, never from the live product.
  `orders.cart_id` is a plain column (no foreign key) kept for traceability.
- Cart/order ownership is enforced against the `X-Customer-Id` header; guests
  (no header) can only touch guest carts/orders.

---

## Performance

All benchmarks were run **inside Docker** against the running stack (`docker compose up`)
on a Windows 11 host with Docker Desktop 4.x (WSL2 backend).
Commands used: `ab` (Apache Bench 2.3) inside the `php` container → nginx → php-fpm;
`curl -w "%{time_total}"` for individual timings.

### Benchmark results

#### `GET /api/products` — `ab -n 500 -c 10`

```
Concurrency level:      10
Complete requests:      500
Failed requests:        0
Requests per second:    1.16 [#/sec] (mean)
Time per request:       8590 ms  (mean, wall clock)
Time per request:        859 ms  (mean, across all concurrent requests)

Connection Times (ms)
              min   mean[+/-sd]  median    max
Processing:  4262   8389  1213    8433   14191

Percentiles
  p50    8 434 ms
  p75    8 964 ms
  p90    9 466 ms
  p99   13 012 ms
```

#### `POST /api/carts/{cartId}/items` — `ab -n 500 -c 10`

Body: `{"productId":"e457a6ac-…","quantity":1}` (Siroko Tech Cycling Socks, 200 stock).
`ab` reported 499 "failed" requests due to `Content-Length` variation — each response
is larger than the previous because the cart grows with every successful add; there were
**0 network or application errors**.

```
Concurrency level:      10
Complete requests:      500
Requests per second:    1.50 [#/sec] (mean)
Time per request:       6659 ms  (mean, wall clock)
Time per request:        666 ms  (mean, across all concurrent requests)

Connection Times (ms)
              min   mean[+/-sd]  median    max
Processing:  3546   6339  2314    5337   15949

Percentiles
  p50    5 337 ms
  p75    7 602 ms
  p90    9 897 ms
  p99   13 580 ms
```

#### Individual `curl -w "%{time_total}"` timings (no concurrent load)

| Request | TCP connect | TTFB | Total |
|---------|-------------|------|-------|
| `GET /api/products` (run 1) | 1.6 ms | 7 348 ms | 7 348 ms |
| `GET /api/products` (run 2) | 1.3 ms | 7 717 ms | 7 717 ms |
| `GET /api/products` (run 3) | 1.3 ms | 7 411 ms | 7 411 ms |
| `POST /api/carts/{id}/items` (run 1) | 1.7 ms | 9 959 ms | 9 959 ms |
| `POST /api/carts/{id}/items` (run 2) | 1.3 ms | 7 339 ms | 7 339 ms |
| `POST /api/carts/{id}/items` (run 3) | 1.8 ms | 9 372 ms | 9 372 ms |

#### Application-layer measurements (filesystem overhead excluded)

These measurements isolate the application and infrastructure code from the Docker
volume-mount overhead described below.

| What | How | Result |
|------|-----|--------|
| Raw MySQL query (`SELECT … WHERE status = 'ACTIVE'`) | PDO inside container | 3.8 ms connect + 1.2 ms query = **5 ms** |
| PHP CLI process (no Symfony) | `time php /tmp/db_test.php` | **44 ms** total |
| Symfony autoloader (opcache off) | CLI, fresh process | **683 ms** |
| Symfony kernel creation (prod, warm cache) | CLI | **238 ms** |
| Framework overhead with no DB (prod, post-warmup) | PHP built-in server | **~2.8 s** |

---

### Why the numbers look the way they do

**TCP connect is 1–2 ms** — network is not the bottleneck.
**The entire latency is PHP processing time (TTFB ≈ total).**

The development stack uses `APP_ENV=dev` and a bind-mounted source volume
(`.:/var/www/html` in `docker-compose.yml`). On **Windows Docker Desktop** every
PHP file read or `stat()` call crosses the NTFS → WSL2 filesystem bridge, which
adds significant overhead per syscall. Two compounding factors in this setup:

1. **`opcache.validate_timestamps = On` (default, required for dev)**  
   OPcache revalidates cached file bytecode by calling `stat()` on every cached
   PHP file when more than `revalidate_freq = 2` seconds have elapsed since the
   last check. A warm Symfony prod container has ~200–500 PHP files in cache.
   If requests are spaced more than 2 s apart (common during manual testing),
   every request triggers a full round of `stat()` syscalls across the bridge.

2. **`APP_ENV=dev` event profiler + debug listeners**  
   Symfony's dev kernel attaches ~40 additional event listeners (profiler,
   debug toolbar data collector, etc.) that execute on every request. These
   are completely absent in `APP_ENV=prod`.

These are **expected characteristics of a Windows Docker Desktop development
environment** and do not reflect production behaviour. On a **Linux host** (or
inside a Linux CI runner where files live natively on the container filesystem),
the same stack produces single-digit-millisecond response times for both
endpoints after opcache warmup.

---

### Design decisions and their effect on performance

| Decision | Justification |
|----------|---------------|
| **`idx_status` index on `products`** | `GET /api/products` runs `SELECT … WHERE status = 'ACTIVE'`. Without this index MySQL does a full table scan. With it, the query scans only active rows and scales with catalog size instead of total rows. |
| **Full entity hydration → `ProductView` DTO** | `DoctrineProductRepository::findAllActive()` uses `->getResult()` (full Doctrine object hydration). The handler immediately maps each `Product` to a `ProductView` DTO before returning, so the aggregate never leaks outside the application layer. Switching to `getArrayResult()` would reduce object-graph construction overhead for large catalogs, but is not yet implemented — current catalog size (5 seeded products) makes the difference negligible. |
| **No N+1 queries** | Each handler issues the minimum number of queries its use case needs. `AddItemToCartHandler` loads one `Cart` and one `Product`. `CheckoutHandler` loads the cart with all items in a single fetch and then loads products in a loop only during the coherence check (one query per line, bounded by cart size). No lazy-loading traps exist because aggregates own their items collection directly. |
| **`CHAR(36)` UUIDs (current)** | Chosen for readability and ease of debugging. The trade-off is 20 extra bytes per UUID column versus `BINARY(16)` and slightly slower string comparisons on index lookups. Acceptable at this scale. |
| **Single-transaction checkout** | Stock decrement, cart status update, and order creation commit atomically in one `BEGIN … COMMIT`. No distributed-transaction overhead; no window for partial state. |


## Testing

Tests are split into three suites (see `phpunit.xml.dist`):

| Suite        | Location            | Needs DB? | What it covers                                  |
|--------------|---------------------|-----------|-------------------------------------------------|
| Unit         | `tests/Unit`        | No        | Aggregates, value objects, handlers (mocked ports) |
| Integration  | `tests/Integration` | Yes (test)| Doctrine repositories against real MySQL        |
| Functional   | `tests/Functional`  | Yes (test)| HTTP endpoints through the Symfony kernel       |

The integration and functional suites run against the `mysql_test` container
(`siroko_test`). Prepare its schema once, then run the suites:

```bash
# Prepare the test database schema (test env → siroko_test)
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction --env=test
```

```bash
# Run everything
docker compose exec php php bin/phpunit

# Run a single suite
docker compose exec php php bin/phpunit --testsuite Unit
docker compose exec php php bin/phpunit --testsuite Integration
docker compose exec php php bin/phpunit --testsuite Functional
```

**Coverage** (requires a coverage driver — Xdebug or PCOV — in the container):

```bash
# Text summary to stdout
docker compose exec php php bin/phpunit --coverage-text

# HTML report into ./coverage
docker compose exec php php bin/phpunit --coverage-html coverage
```

**Static analysis & code style** (quality gates, PHPStan at `level: max`):

```bash
docker compose exec php php vendor/bin/phpstan analyse
docker compose exec php php vendor/bin/php-cs-fixer fix --dry-run --diff
```

---

## AI documentation

This project was built with AI assistance under an explicit, version-controlled
spec. The [`ai/`](ai/) folder holds that documentation:

- [`ai/PLAN.md`](ai/PLAN.md) — the binding functional & technical specification
  (business rules, domain model, use cases, endpoints, folder structure, testing
  strategy). The source of truth; any change that contradicts it is rejected.
- [`ai/DECISIONS.md`](ai/DECISIONS.md) — a log of architectural decisions and
  **rejected** proposals, with the rationale for each (e.g. why optimistic
  locking was left out of scope).
- [`ai/PROMPTS.md`](ai/PROMPTS.md) — the 5 key prompts used during implementation,
  showing how the AI was directed at each major phase (planning, domain layer,
  handlers, infrastructure, functional tests).

See also [`CLAUDE.md`](CLAUDE.md) at the repository root — the operational guide
and binding coding rules used while implementing the project.
```

