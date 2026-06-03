# CONTEXT AND OBJECTIVE

Siroko is a sports e-commerce brand focused on cycling and fitness products.
This project implements Option A of the Siroko Senior Code Challenge:
Cart & Checkout.

The goal is to design a shopping cart that allows any user to purchase
products quickly and efficiently, and then complete the checkout process
generating a persistent order.

The solution must demonstrate:
- Domain design decoupled from the framework (Symfony)
- Solid use of Hexagonal Architecture and DDD (entities, value objects,
  aggregates, domain services and events where applicable)
- Fluent application of CQRS
- Exhaustive testing with maximum use case coverage
- Justified and measured performance

# MAIN FLOWS

## Get products
- Input: -
- Output: Product[] (active only)
- Returns all active products

## Get product
- Input: ProductId
- Output: Product (active only)
- Returns an active product
- Exceptions:
  1. Product does not exist
  2. Product exists but is not active

## Add product to cart
- Input: CartId, ?CustomerId, ProductId, Int quantity
- Output: Cart
- Adds a product to the cart
- If CartId is provided, adds the product to the existing cart
- If CartId is not provided, creates a new cart with status OPEN and then adds the product
- If the product already exists in the cart, adds the quantity to the existing line
- Exceptions:
  1. CartId does not exist
  2. CartId has status CHECKED_OUT
  3. ProductId does not exist
  4. ProductId is not active
  5. ProductId stock is lower than total quantity
  6. Received CustomerId does not match CartId's CustomerId

## Update product in cart
- Input: CartId, ?CustomerId, ProductId, Int quantity
- Output: Cart
- Updates the quantity of a product in the cart
- If quantity is zero, removes the product from the cart
- If quantity is greater than zero, updates the product quantity
- If the product does not exist in the cart, adds it as a new line
- Exceptions:
  1. CartId does not exist
  2. CartId has status CHECKED_OUT
  3. ProductId does not exist
  4. ProductId is not active
  5. ProductId stock is lower than total quantity
  6. Received CustomerId does not match CartId's CustomerId

## Remove product from cart
- Input: CartId, ?CustomerId, ProductId
- Output: Cart
- Removes the product from the cart
- Exceptions:
  1. CartId does not exist
  2. CartId has status CHECKED_OUT
  3. ProductId does not exist in CartId
  4. Received CustomerId does not match CartId's CustomerId

## Get cart
- Input: CartId, ?CustomerId
- Output: Cart
- Returns the cart with its items
- Exceptions:
  1. CartId does not exist
  2. Received CustomerId does not match CartId's CustomerId

## Checkout
- Input: CartId, ?CustomerId, ShippingAddress
- Output: Order
- Converts the cart into an order
- Validates that each CartItem is consistent with Product stock and price.
  If stock is insufficient or price has changed, updates CartItem and returns
  a warning, cancelling the checkout.
- Must be executed in a single transaction as it involves multiple records
  1. Updates CartId status to CHECKED_OUT
  2. Creates the order in PENDING status, converting CartItem into OrderItem
  3. Updates product stock
  4. Returns OrderId to process the payment
- Exceptions:
  1. CartId does not exist
  2. CartId has status CHECKED_OUT
  3. ProductId does not exist
  4. ProductId is not active
  5. ProductId stock is lower than total quantity
  6. ShippingAddress is not valid
  7. Cart is empty

## Get order
- Input: OrderId, ?CustomerId
- Output: Order
- Returns the order with its items
- Exceptions:
  1. OrderId does not exist
  2. Received CustomerId does not match OrderId's CustomerId

## Process payment
- Input: OrderId, bool result
- Output: -
- Receives the payment result and updates the order
- If True: changes order status to PAYMENT_CONFIRMED
- If False:
  1. Changes order status to PAYMENT_ERROR
  2. Restores product stock
  3. Updates CartId status to OPEN so a new order can be generated
- Exceptions:
  1. OrderId does not exist
  2. OrderId does not have PENDING status
  3. On PAYMENT_ERROR, CartId may not exist (cart could have been deleted), ignore silently

# AGGREGATES

## Product
- Fields: ProductId, Name, Description, Int taxAmount, Money unitPrice,
  Int quantity, Status, CreatedDate, UpdatedDate
- Status: ACTIVE / INACTIVE

## Cart
- Fields: CartId, CustomerId, CartItem[], Status, CreatedDate, UpdatedDate
- CartItem: ProductId, Int taxAmount, Money unitPrice, Int quantity
- Status: OPEN / CHECKED_OUT

## Order
- Fields: OrderId, CartId, CustomerId, OrderItem[], Money totalTax,
  Money totalProducts, Money totalOrder, ShippingAddress, Status,
  CreatedDate, UpdatedDate
- OrderItem: ProductId, Int taxAmount, Money unitPrice, Int quantity
- Status: PENDING / PAYMENT_CONFIRMED / PAYMENT_ERROR

# VALUE OBJECTS

## Money:
- Fields: Int amount (cents), String currency (ISO 4217)
- amount >= 0
## ShippingAddress:
- Fields: String firstName, String lastName, String vatNumber, String street, String city, String state, String zipCode, String country
- All fields are required
  ## ProductId, CartId, OrderId, CustomerId: UUID v4
- Must be a valid UUID format

# BUSINESS RULES

## Product:
- Price must be > 0
- Quantity must be >= 0

## Cart:
- Cannot have two lines with the same product
- Minimum quantity per line is 1
- Cannot exceed available product stock
- Cannot add a product with quantity 0
- When a product is added, unitPrice and taxAmount are captured as a snapshot
  at that moment
- An empty cart can exist

## Order:
- Cannot create an order from an empty cart
- Cannot exceed available product stock
- Total must match the sum of order lines
- OrderItem unitPrice and taxAmount are copied from CartItem, not from the
  current Product at the time of order creation. To avoid inconsistencies,
  CartItem and Product coherence is validated before creating the Order.

# NOTES

- CustomerId is optional to allow guest checkout. Customer management is
  out of scope.
- Product management is out of scope. Products are seeded via infrastructure
  seeder command.
- Tax management by country is out of scope. Tax values are assumed to be
  final per product.
- Real payment processing is out of scope. Once the order is created, the
  system only expects to receive the payment result to define the final status.
- Order contains CartId for traceability purposes, but without FK constraint
  to avoid inconsistencies assuming carts can be deleted over time.
- vatNumber in ShippingAddress is assumed mandatory for all countries
  for simplicity.