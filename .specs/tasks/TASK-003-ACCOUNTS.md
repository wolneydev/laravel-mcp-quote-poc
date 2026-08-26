# Accounts Module

## Why

The application needs one authentication table for both sellers and customer users. Each account must have a public code that clearly identifies its role, and quotes must reference these account IDs directly.

`accounts` is the user/authentication table for this project. There is no `user_id` relationship and no separate `users` dependency for this flow.

## What

Create an `accounts` authentication module with two account types:

- `seller` — public code starts with `VEN-`.
- `customer` — public code starts with `CLI-` and is linked to one `customers.id`.

Create an `accounts` table with these fields:

- `id` — primary key.
- `code` — unique public account code, for example `VEN-000001` or `CLI-000001`.
- `type` — controlled value: `seller` or `customer`.
- `customer_id` — nullable unique foreign key to `customers.id`.
- `name` — account holder name.
- `email` — unique login email.
- `password` — hashed password.
- `active` — boolean.
- timestamps.

Use `created_at` as the account start date. Expose a calculated `account_age_days` value from `created_at`; do not persist account age in the database.

Expose these REST endpoints in `routes/api.php`:

- `GET /api/accounts`
- `POST /api/accounts`
- `GET /api/accounts/{account}`
- `PUT/PATCH /api/accounts/{account}`
- `DELETE /api/accounts/{account}`

The index endpoint must support:

- `search` — partial search by `name`, `email`, or `code`.
- `code` — exact code.
- `type` — `seller|customer`.
- `customer_id` — exact customer relationship.
- `active` — boolean.
- pagination.

## Constraints

### Must

- `Account` must be the authenticatable model used for seller/customer credentials in this domain.
- Do not introduce or require `user_id`.
- `code` must be unique.
- `email` must be unique and normalized to lowercase.
- Passwords must always be hashed using Laravel's password hashing facilities.
- API responses must never expose the password hash.
- For `type=customer`:
  - `customer_id` is required.
  - `customer_id` must exist in `customers`.
  - `customer_id` must be unique so one Customer has one Account in this simplified model.
  - `code` must start with `CLI-`.
- For `type=seller`:
  - `customer_id` must be null.
  - `code` must start with `VEN-`.
- `active` must be boolean.
- Prefer server-side code generation. If the existing application accepts account codes from the request, validate prefix and uniqueness strictly.
- `account_age_days` must be derived from `created_at` and the current time.
- Configure the existing authentication provider/guard to use `Account` if the application still points to a different user model.
- Seeders must use `upsert` by `code`.
- Account seed data must resolve customer relationships by `customers.code`, never by hardcoded database IDs.
- Seed at least:
  - 2 seller accounts.
  - 3 customer accounts.

### Must Not

- Do not create a `user_id` column.
- Do not create a second `users` table for this feature.
- Keep the table limited to the scalar authentication and relationship fields defined in this specification.
- Do not persist `account_age_days`.
- Do not allow a seller account to have `customer_id`.
- Do not allow a customer account without `customer_id`.
- Do not return passwords or password hashes from the API.
- Do not allow changing an existing account from `seller` to `customer` or vice versa after it is referenced by a quote. The simplest implementation is to make `type` immutable after creation.

### Out of Scope

- Roles beyond `seller` and `customer`.
- Teams and sales hierarchy.
- RBAC/permissions beyond the authorization necessary for these modules.
- Account balance or billing.
- Password reset UI.

## Current State

- The `customers` table already exists.
- `accounts` becomes the identity/authentication table used by the quote flow.
- There is no `user_id` dependency.

## Tasks

### T1: Create the accounts migration

What: Create `accounts` with a nullable unique `customer_id` foreign key and the authentication fields described above.

Files:

- `database/migrations/*_create_accounts_table.php`

Recommended database relationship:

```text
customers.id
    1 ───── 0..1 accounts.customer_id

seller account
    accounts.type = seller
    accounts.customer_id = NULL

customer account
    accounts.type = customer
    accounts.customer_id = customers.id
```

Verify:

- migration and rollback succeed.
- `code` and `email` are unique.
- `customer_id` references `customers.id`.

### T2: Create the Account authentication model

What: Create `Account` as an authenticatable Laravel model.

Files:

- `app/Models/Account.php`
- authentication configuration files only if the current project still references another authenticatable model.

The model should:

- hide `password`.
- cast `active` to boolean.
- expose `customer()` as `belongsTo(Customer::class)`.
- expose `account_age_days` as a calculated accessor/resource field.

Verify:

- authentication can resolve an Account.
- serialized Account responses do not contain password data.

### T3: Add account type validation

Minimum create rules:

- `name`: `required|string|max:255`
- `email`: `required|email|max:255|unique:accounts,email`
- `password`: `required|string|min:<project-policy>`
- `type`: `required|in:seller,customer`
- `customer_id`: conditionally required for `customer`, prohibited/null for `seller`
- `active`: `boolean`
- `code`: generated by the server or strictly validated if accepted

Add application-level validation so:

- `seller` requires `VEN-` code.
- `customer` requires `CLI-` code.
- customer accounts reference an active/valid Customer according to the project's policy.
- a Customer cannot receive a second Account in this simplified model.

Files:

- `app/Http/Requests/StoreAccountRequest.php`
- `app/Http/Requests/UpdateAccountRequest.php`
- custom Rule classes only if necessary.

Verify:

- invalid type/customer combinations return `422`.

### T4: Generate account codes

What: Generate unique public account codes.

Examples:

- `VEN-000001`
- `CLI-000001`

Generation must be concurrency-safe. Do not rely on an unprotected `max(id) + 1` implementation.

Files:

- service/action/model observer according to the existing architecture.

Verify:

- seller codes always use `VEN-`.
- customer codes always use `CLI-`.
- duplicate codes cannot be produced.

### T5: Create the account CRUD

What: Implement account endpoints and filters.

Files:

- `app/Http/Controllers/Api/AccountController.php`
- `app/Http/Resources/AccountResource.php` when applicable
- `routes/api.php`

The resource should return, at minimum:

- `id`
- `code`
- `type`
- `name`
- `email`
- `customer_id`
- `active`
- `account_age_days`
- timestamps

It must not return authentication secrets.

Verify:

- `GET /api/accounts?type=seller`
- `GET /api/accounts?type=customer`
- `GET /api/accounts?search=<name-or-code>`

work as expected.

### T6: Add an idempotent AccountSeeder

What: Create seller and customer accounts with `upsert`.

For customer accounts:

1. resolve `Customer` by its public `code`.
2. use the resolved `customers.id` as `customer_id`.
3. never hardcode that ID.

For seller accounts:

- set `customer_id` to `null`.

Use hashed mock passwords appropriate for the local/test environment.

Files:

- `database/seeders/AccountSeeder.php`
- `database/seeders/DatabaseSeeder.php`

Seeder execution order inside `DatabaseSeeder` must ensure:

```php
$this->call([
    ProductSeeder::class,
    CustomerSeeder::class,
    AccountSeeder::class,
]);
```

Verify:

- running AccountSeeder twice creates no duplicate account codes or emails.
- customer account relationships still point to the correct Customer.

### T7: Add tests

Cover:

- authentication model behavior.
- code prefixes.
- unique code/email.
- customer relationship.
- seller without customer relationship.
- password hashing.
- hidden password fields.
- calculated account age.
- CRUD and filters.
- idempotent seed.

Verify:

```bash
php artisan test --filter=Account
```

passes.

## Validation

The task is complete when:

1. No `user_id` exists in this module.
2. `accounts` is the authentication/user table for this flow.
3. A seller account has `type=seller`, a `VEN-*` code, and `customer_id=null`.
4. A customer account has `type=customer`, a `CLI-*` code, and a valid unique `customer_id`.
5. A customer cannot have two Accounts in this simplified model.
6. Passwords are hashed and never returned by the API.
7. `account_age_days` is calculated from `created_at`.
8. Seeder execution is idempotent.
9. Account tests pass.
