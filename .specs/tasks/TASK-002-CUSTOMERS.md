# Customers Module

## Why

Every quote must belong to a clearly identified customer. Sellers and MCP tools need a simple customer registry that can be searched by name or public code and later connected to a customer account.

## What

Create a Customers module with REST CRUD endpoints in `routes/api.php` and idempotent mock data using a Laravel seeder with `upsert`.

Create a `customers` table with these fields:

- `id` — primary key.
- `code` — unique public customer code, for example `CUST-000001`.
- `name` — customer or company name.
- `document` — nullable unique tax/company/personal document.
- `email` — nullable contact email.
- `phone` — nullable contact phone.
- `contact_name` — nullable primary contact name.
- `active` — boolean.
- timestamps.

Expose these REST endpoints:

- `GET /api/customers`
- `POST /api/customers`
- `GET /api/customers/{customer}`
- `PUT/PATCH /api/customers/{customer}`
- `DELETE /api/customers/{customer}`

The index endpoint must support:

- `search` — partial search by `name`, `code`, or `contact_name`.
- `code` — exact code.
- `document` — exact normalized document.
- `email` — exact normalized email.
- `active` — boolean.
- pagination.

## Constraints

### Must

- `code` must be required, unique, stable, and trimmed.
- `name` must be required and trimmed.
- `document` may be nullable, but when present it must be unique.
- Normalize `document` to the canonical format already used by the project. If the application has no convention, store digits/alphanumeric characters without formatting punctuation.
- Normalize `email` to lowercase.
- Validate `email` when present.
- `active` must be boolean.
- Customer lookup must support `name`, `code`, and `document` because the MCP layer will use these fields to resolve seller input.
- The customer seeder must use `upsert` with `code` as the conflict key.
- Seed at least 5 customers.
- This table must contain business/customer profile data only. Authentication credentials belong to `accounts`.

### Must Not

- Do not add a password to `customers`.
- Do not add JSON, address JSON, or `metadata` columns.
- Do not add `user_id`.
- Do not make `customers` an authentication model.
- Do not require an Account to create a Customer; Accounts are created in the next task.
- Do not physically remove a Customer that is already used by an Account or Quote if it would break historical relationships. Prefer `active=false` or restrictive foreign keys.

### Out of Scope

- CRM features.
- Multiple contacts.
- Multiple addresses.
- Credit limits.
- Marketing preferences.

## Current State

- Products are already available from the previous task.
- Customers do not depend on Products.
- Accounts do not exist yet and will reference Customers later.

## Tasks

### T1: Create the customers migration and model

What: Create the `customers` table and `Customer` model.

Files:

- `database/migrations/*_create_customers_table.php`
- `app/Models/Customer.php`

Verify:

- migration and rollback succeed.
- `code` is unique.
- `document` is unique when present.

### T2: Add customer validation

Minimum rules:

- `code`: `required|string|max:64|unique`
- `name`: `required|string|max:255`
- `document`: `nullable|string|max:32|unique`
- `email`: `nullable|email|max:255`
- `phone`: `nullable|string|max:32`
- `contact_name`: `nullable|string|max:255`
- `active`: `boolean`

For update requests, unique rules must ignore the current customer.

Files:

- `app/Http/Requests/StoreCustomerRequest.php`
- `app/Http/Requests/UpdateCustomerRequest.php`

Verify:

- invalid email returns `422`.
- duplicate code returns `422`.
- duplicate document returns `422` when the document is present.

### T3: Create the customer CRUD

What: Implement controller, API resource/serializer if used by the application, and API routes.

Files:

- `app/Http/Controllers/Api/CustomerController.php`
- `app/Http/Resources/CustomerResource.php` when applicable
- `routes/api.php`

Verify:

- CRUD behavior follows the existing API response conventions.

### T4: Add customer lookup filters

What: Add search and exact filters.

Required behavior:

- partial `search` over `name`, `code`, and `contact_name`.
- exact `code`.
- exact normalized `document`.
- exact normalized `email`.
- `active` filter.
- pagination.

Verify:

- feature tests cover name and code lookup.

### T5: Add an idempotent CustomerSeeder

What: Seed at least 5 customers using `upsert` by `code`.

Example strategy:

```php
Customer::upsert(
    $customers,
    ['code'],
    ['name', 'document', 'email', 'phone', 'contact_name', 'active', 'updated_at'],
);
```

Do not hardcode primary key IDs.

Files:

- `database/seeders/CustomerSeeder.php`
- `database/seeders/DatabaseSeeder.php`

Verify:

- running the seeder repeatedly preserves the row count.
- mutable fields are updated.

### T6: Add tests

Cover:

- CRUD.
- validation.
- normalization.
- search.
- unique constraints.
- idempotent seeding.

Verify:

```bash
php artisan test --filter=Customer
```

passes.

## Validation

The task is complete when:

1. Migration and rollback work.
2. CustomerSeeder is idempotent.
3. `GET /api/customers?search=<name>` finds the expected customer.
4. Duplicate customer codes are rejected.
5. Invalid email is rejected.
6. Customers have no authentication/password fields.
7. Customers have no JSON or metadata columns.
8. Customer tests pass.
