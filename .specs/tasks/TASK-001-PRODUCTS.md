# Products Module

## Why

Quotes need a small, reliable product catalog so a seller can identify a product by name or code, use its current price when creating a quote, and keep the quoted price stable after the quote is created.

## What

Create a Products module with REST CRUD endpoints in `routes/api.php` and idempotent mock data using a Laravel seeder with `upsert`.

Create a `products` table with these fields:

- `id` — primary key.
- `code` — unique public product code, for example `PROD-000001`.
- `name` — product name used by sellers and MCP searches.
- `description` — nullable text.
- `unit` — short unit label such as `unit`, `kg`, `box`, or `hour`.
- `price` — current base price.
- `currency` — 3-character currency code, for example `BRL`.
- `active` — boolean.
- timestamps.

Expose these REST endpoints:

- `GET /api/products`
- `POST /api/products`
- `GET /api/products/{product}`
- `PUT/PATCH /api/products/{product}`
- `DELETE /api/products/{product}`

The index endpoint must support:

- `search` — partial search by `name` or `code`.
- `code` — exact code.
- `active` — boolean filter.
- pagination.

## Constraints

### Must

- `code` must be required, unique, stable, and trimmed.
- `name` must be required and trimmed.
- `price` must use a decimal column such as `decimal(15, 2)`.
- `price` must be greater than or equal to zero.
- Never use `float` or `double` for monetary values.
- `currency` must contain exactly 3 letters and be normalized to uppercase.
- `active` must be boolean.
- Product lookup must support both `name` and `code` because the MCP layer will resolve products from seller input.
- Use Form Requests for create/update validation if that matches the existing project structure.
- Use API Resources if the existing API already follows that pattern.
- The product seeder must use `upsert` with `code` as the conflict key.
- Seed at least 5 products with different names, units, and prices.
- Quote items created later must reference `products.id` and copy the quoted `product_code`, `product_name`, `unit`, and `unit_price` into scalar quote item columns.

### Must Not

- Do not add JSON or `metadata` columns.
- Do not create product categories, inventory, or pricing tables in this task.
- Do not accept a negative price.
- Do not silently allow duplicate product codes.
- Do not physically delete a product if it is already referenced by historical quote items and the database would lose referential integrity. Prefer setting `active=false` or use a restrictive foreign key.

### Out of Scope

- Inventory management.
- Product categories.
- Multiple price lists.
- Product variants.
- Taxes.

## Current State

- Existing Laravel application.
- This task has no domain dependency on the other modules in this specification set.

## Tasks

### T1: Create the products migration and model

What: Create the `products` table and `Product` model using only the scalar fields described above.

Files:

- `database/migrations/*_create_products_table.php`
- `app/Models/Product.php`

Verify:

- `php artisan migrate` succeeds.
- rollback succeeds.
- unique indexes exist for `code`.

### T2: Add product validation

What: Create request validation for create and update.

Minimum rules:

- `code`: `required|string|max:64|unique`
- `name`: `required|string|max:255`
- `description`: `nullable|string`
- `unit`: `required|string|max:32`
- `price`: `required|numeric|min:0`
- `currency`: `required|string|size:3`
- `active`: `boolean`

For update requests, the unique `code` rule must ignore the current product.

Files:

- `app/Http/Requests/StoreProductRequest.php`
- `app/Http/Requests/UpdateProductRequest.php`

Verify:

- invalid payloads return `422`.
- duplicate codes are rejected.
- negative prices are rejected.

### T3: Create the product CRUD

What: Implement controller, resource/serializer if used by the project, and API routes.

Files:

- `app/Http/Controllers/Api/ProductController.php`
- `app/Http/Resources/ProductResource.php` when applicable
- `routes/api.php`

Verify:

- index, store, show, update, and delete/inactivate behavior follow the API conventions already used by the application.

### T4: Add product search

What: Add product filtering for MCP-compatible lookup.

Required behavior:

- `search=keyboard` matches partial product names.
- `search=PROD-000001` can match product codes.
- `code=PROD-000001` performs exact filtering.
- `active=true` returns only active products.
- results are paginated.

Verify:

- feature tests cover exact and partial search.

### T5: Add an idempotent ProductSeeder

What: Seed at least 5 mock products using `upsert`.

Example strategy:

```php
Product::upsert(
    $products,
    ['code'],
    ['name', 'description', 'unit', 'price', 'currency', 'active', 'updated_at'],
);
```

Do not rely on fixed database IDs.

Files:

- `database/seeders/ProductSeeder.php`
- `database/seeders/DatabaseSeeder.php`

Verify:

- running the seeder twice does not create duplicates.
- existing rows with matching `code` are updated.

### T6: Add tests

Cover:

- CRUD.
- validation.
- unique code.
- decimal price handling.
- search filters.
- active filter.
- idempotent seeding.

Verify:

```bash
php artisan test --filter=Product
```

passes.

## Validation

The task is complete when:

1. The migration can migrate and roll back.
2. The ProductSeeder can run repeatedly without duplicating products.
3. `GET /api/products?search=<name>` returns matching products.
4. Duplicate `code` returns a validation error.
5. Negative `price` returns a validation error.
6. Product API responses do not expose any JSON metadata field.
7. Product tests pass.
