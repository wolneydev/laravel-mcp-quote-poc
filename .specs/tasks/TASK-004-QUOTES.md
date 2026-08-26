# Quotes Module

## Why

The application needs to turn a seller, customer, product, and quantity into a persistent quote that can later be returned as an approval report.

A quote must reference seller and customer Accounts by ID and preserve the product price used when the quote was created.

## What

Create `quotes` and `quote_items`, expose quote operations through `routes/api.php`, and centralize quote calculation in a reusable service/action that can also be called by MCP tools.

Create a `quotes` table with these fields:

- `id` — primary key.
- `number` — unique public quote number, for example `QUO-2026-000001`.
- `seller_account_id` — foreign key to `accounts.id`.
- `customer_account_id` — foreign key to `accounts.id`.
- `status` — controlled value: `draft`, `pending_approval`, `approved`, or `rejected`.
- `currency` — 3-character currency code.
- `total` — sum of all quote item line totals.
- `valid_until` — nullable date.
- `notes` — nullable text.
- `approved_at` — nullable timestamp.
- `rejected_at` — nullable timestamp.
- timestamps.

Create a `quote_items` table with these fields:

- `id` — primary key.
- `quote_id` — foreign key to `quotes.id`.
- `product_id` — foreign key to `products.id`.
- `product_code` — scalar snapshot of the product code.
- `product_name` — scalar snapshot of the product name.
- `unit` — scalar snapshot of the product unit.
- `quantity` — positive decimal or integer according to the project's sales rules.
- `unit_price` — scalar price snapshot copied from `products.price`.
- `line_total` — `quantity * unit_price`.
- timestamps.

Expose these REST endpoints:

- `GET /api/quotes`
- `POST /api/quotes`
- `GET /api/quotes/{quote}`
- `PUT/PATCH /api/quotes/{quote}` while the quote is editable.
- `DELETE /api/quotes/{quote}` while the quote is still `draft`, if deletion is allowed by the existing project.
- `POST /api/quotes/{quote}/submit`
- `POST /api/quotes/{quote}/approve`
- `POST /api/quotes/{quote}/reject`

If the existing project already has a different convention for state transitions, keep that convention while preserving the validation rules in this specification.

Recommended creation payload:

```json
{
  "seller_account_id": 1,
  "customer_account_id": 3,
  "items": [
    {
      "product_id": 5,
      "quantity": 3
    }
  ],
  "valid_until": "2026-09-30",
  "notes": "Optional"
}
```

## Relationships

```text
accounts.id (seller)
      |
      +------ quotes.seller_account_id

customers.id
      |
      +------ accounts.customer_id
                   |
                   +------ quotes.customer_account_id

quotes.id
      |
      +------ quote_items.quote_id
                    |
products.id --------+ via quote_items.product_id
```

Expected Eloquent relationships:

- `Quote::sellerAccount()` -> `belongsTo(Account::class, 'seller_account_id')`
- `Quote::customerAccount()` -> `belongsTo(Account::class, 'customer_account_id')`
- `Quote::items()` -> `hasMany(QuoteItem::class)`
- `QuoteItem::quote()` -> `belongsTo(Quote::class)`
- `QuoteItem::product()` -> `belongsTo(Product::class)`
- `Account::sellerQuotes()` -> quotes where the Account is the seller
- `Account::customerQuotes()` -> quotes where the Account is the customer

The customer profile is reached through:

```text
Quote
  -> customerAccount
  -> customer
```

## Constraints

### Must

- `seller_account_id` must exist in `accounts`.
- The seller Account must have:
  - `type=seller`.
  - `active=true`.
  - `customer_id=null`.
- `customer_account_id` must exist in `accounts`.
- The customer Account must have:
  - `type=customer`.
  - `active=true`.
  - a valid `customer_id`.
- The related Customer must have `active=true` when a new quote is created.
- `seller_account_id` and `customer_account_id` cannot reference the same Account.
- `items` is required and must contain at least one item.
- Every `product_id` must exist and reference an active Product.
- `quantity` must be greater than zero.
- Reject repeated Products in the same request with `422`.
- All Products in one quote must use the same currency.
- `currency` must be derived from the Products, not trusted from the request.
- `unit_price` must be read from Product when the quote is calculated.
- Copy `product_code`, `product_name`, `unit`, and `unit_price` into `quote_items`.
- `line_total = quantity * unit_price`.
- `quote.total = sum(quote_items.line_total)`.
- Monetary calculations must use decimal-safe arithmetic and the scale already used by the application.
- Never accept `unit_price`, `line_total`, or `total` from REST or MCP input as a source of truth.
- Quote creation must run in a database transaction.
- Create one reusable `QuotePricingService` and one `CreateQuoteAction`, or equivalent project abstractions.
- REST controllers and MCP tools must call the same quote creation/calculation code.
- Quote number generation must be concurrency-safe.
- Historical report values must use persisted `quote_items.unit_price` and `quote_items.line_total`.
- `draft` is the only status in which products, quantities, or quote values may be edited.
- `submit` must transition `draft -> pending_approval`.
- `approve` must transition `pending_approval -> approved`.
- `reject` must transition `pending_approval -> rejected`.
- Approval should be authorized for the customer Account associated with the quote, unless the existing application defines another explicit approval policy.
- Seller access should be limited to quotes owned by the authenticated seller Account unless an administrative policy says otherwise.

### Must Not

- Do not add JSON or `metadata` columns.
- Do not accept prices or totals from the caller.
- Do not recalculate historical prices when a Product price changes.
- Do not allow a `CLI-*` Account as `seller_account_id`.
- Do not allow a `VEN-*` Account as `customer_account_id`.
- Do not duplicate calculation logic inside controllers or MCP tools.
- Do not reference `user_id`.

### Out of Scope

- Taxes.
- Shipping.
- Installments.
- Quote versioning.
- Order generation.
- PDF generation.

## Current State

The following modules already exist:

- Products.
- Customers.
- Accounts.

`accounts` is the authentication/user table for this flow. Seller and customer identities are referenced directly through Account IDs.

## Tasks

### T1: Create quote migrations

What: Create `quotes` and `quote_items`.

Files:

- `database/migrations/*_create_quotes_table.php`
- `database/migrations/*_create_quote_items_table.php`

Required foreign keys:

- `quotes.seller_account_id -> accounts.id`
- `quotes.customer_account_id -> accounts.id`
- `quote_items.quote_id -> quotes.id`
- `quote_items.product_id -> products.id`

Verify:

- migrations and rollback work.
- no circular dependency is introduced.

### T2: Create Quote and QuoteItem models

What: Add the relationships described above.

Files:

- `app/Models/Quote.php`
- `app/Models/QuoteItem.php`
- relationship additions in `app/Models/Account.php`

Verify:

- seller/customer Account relationships resolve correctly.
- QuoteItems resolve their Quote and Product.

### T3: Add quote validation

Validate:

- seller Account existence, type, and active state.
- customer Account existence, type, active state, and Customer relationship.
- active Customer.
- at least one item.
- active Products.
- positive quantity.
- repeated Product IDs.
- common currency.
- `valid_until` according to the project's date policy.
- optional `notes` length.

Use Form Requests and custom rules/after-validation hooks only where ordinary Laravel rules are not enough.

Files:

- `app/Http/Requests/StoreQuoteRequest.php`
- `app/Http/Requests/UpdateQuoteRequest.php`
- custom validation rules only when necessary.

Verify:

- invalid account roles return `422`.
- inactive entities return `422`.
- invalid quantities return `422`.

### T4: Create QuotePricingService

What: Implement pure quote calculation.

Input:

- resolved seller Account.
- resolved customer Account.
- resolved Products and requested quantities.

Output:

- currency.
- quote total.
- normalized item values ready to persist:
  - product ID.
  - product code.
  - product name.
  - unit.
  - quantity.
  - unit price.
  - line total.

Files:

- `app/Services/Quotes/QuotePricingService.php`

Verify:

- one-item and multi-item calculations pass.
- mixed currencies are rejected.
- decimal calculations are exact for the application's configured scale.

### T5: Create CreateQuoteAction

What: Use one database transaction to:

1. receive validated/resolved domain objects.
2. generate the quote number.
3. call `QuotePricingService`.
4. create the Quote.
5. create all QuoteItems.
6. return the persisted Quote with required relations.

Files:

- `app/Actions/Quotes/CreateQuoteAction.php`

Verify:

- any item persistence failure rolls back the entire Quote.

### T6: Create quote API endpoints

What: Implement REST endpoints using the shared action/service.

Files:

- `app/Http/Controllers/Api/QuoteController.php`
- `app/Http/Resources/QuoteResource.php`
- `app/Http/Resources/QuoteItemResource.php`
- `routes/api.php`
- policies when the project uses Laravel policies.

The GET representation should include:

- quote ID/number/status/dates.
- seller Account ID/code/name.
- customer Account ID/code/name.
- linked Customer ID/code/name.
- items.
- total.
- currency.
- notes.

Verify:

- create, list, show, edit-draft, submit, approve, and reject tests pass.

### T7: Add quote tests

Cover:

- Account role validation.
- active-state validation.
- item validation.
- repeated Products.
- common currency.
- price snapshot.
- total calculation.
- database rollback.
- `draft` editability.
- state transitions.
- seller ownership.
- customer approval authorization.

Verify:

```bash
php artisan test --filter=Quote
```

passes.

## Validation

The task is complete when:

1. A `CLI-*` Account cannot be used as the seller.
2. A `VEN-*` Account cannot be used as the customer.
3. An inactive Product cannot be quoted.
4. An inactive Customer or Account cannot be quoted.
5. Zero or negative quantity is rejected.
6. Repeated Products are rejected.
7. `quotes.total` exactly equals the sum of persisted item `line_total` values.
8. Changing `products.price` after quote creation does not change the persisted quote amount.
9. A failed QuoteItem creation rolls back the complete transaction.
10. Only `draft` quotes can change products/quantities/prices.
11. Valid status transitions and authorization are enforced.
12. Quote tests pass.
