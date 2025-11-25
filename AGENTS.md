# Project guidelines

## Overview
- This is a PHP 8.2 service that uses a lightweight framework built around the `App\Core\Context`.  Application code is grouped under `app/` with feature code split into `Controllers`, `Services`, `Modules`, `Views`, and reusable helpers under `app/Services/Support`.
- HTTP requests are made with Guzzle clients obtained from the `Context`; persistence is handled through Doctrine DBAL connections that also come from the same `Context` object.
- Environment configuration is read from `.env` via `vlucas/phpdotenv`.  Do **not** commit real secrets—update `.env.example` if new settings are introduced.

## Coding conventions
- Follow PSR-12 style: 4-space indentation, opening braces on the same line, and a blank line between namespace/use declarations and code.
- Prefer constructor injection via `Context` for dependencies (e.g., database connections, loggers, HTTP clients).  When creating new services or controllers, mirror the existing constructors and expose setters only when tests need to replace collaborators.
- Always type-hint parameters and return values.  Use union/null types where appropriate and add PHPDoc blocks when the type cannot be expressed in the signature (arrays with specific keys, mixed payloads, etc.).
- Reuse helpers from `App\Services\Support` for data normalization (`PoyntDataFormatter`, `PaginatedRequest`, logging helpers, etc.) instead of duplicating logic.
- Controllers should extend `App\Controllers\Controller` to obtain shared context helpers.  Route handlers should return plain arrays that can be encoded as JSON unless the calling code expects a `Response` instance.
- Log meaningful events and failures through `$this->context->getLog()` using the structured patterns found in existing services.

## Tests & tooling
- Tests live under `tests/` and use PHPUnit.  Install dependencies with `composer install` and run the suite via:
  ```bash
  composer install
  ./vendor/bin/phpunit
  ```
- Add or update tests alongside any behavior change.  For HTTP clients or external services, prefer injecting fakes/mocks rather than hitting real endpoints.
- Keep coding standard checks in mind; if you introduce new tooling (linting, static analysis), document the command in this file.

## Making changes
- New classes should be autoloadable through the existing PSR-4 mapping (`App\\` → `app/`).  Place files in the appropriate directory and keep namespaces aligned with the folder structure.
- When adding a new OAuth platform, follow the naming convention `App\Modules\OAuth\<Platform>OAuthHandler` so the registry can resolve it automatically.
- Be mindful of pagination helpers when dealing with list endpoints; prefer `PaginatedRequest::collect` to roll your own loops.
- Ensure database writes use prepared statements/parameter binding through Doctrine's connection, mirroring patterns in existing services.
- SQL directory is used for SQL client, there should be only initial SQL definitions(not alter definitions, only last definition version). 'database' directory can contain all migrations we had during development. Migrations shouldn't be script, but plain SQL definition.

## Domain entities and relationships
- Business → has multiple Stores (locations). Each Store has one or more Terminals, and only one Catalog can be assigned to a Terminal at any given time (swap as needed).
- Catalog → acts as a bundle of settings for Products, Categories, Taxes, Discounts, and Fees. These can be linked at the order level or the item level. A Catalog assigned to a Store/Terminal determines what is sellable and which rates/discounts apply.
- Category ↔ Product (M:N) → Products can belong to multiple Categories; Categories live inside a Catalog and support organization and item-level rules (e.g., discount only for "Drinks").
- Product → Core entity (sku, name, type, etc.). In Poynt, Products belong to the "Products" domain alongside pricing, Catalogs, and Taxes.
- Taxes / Discounts / Fees → Defined within a Catalog and/or Category and applied at the order level or item level, meaning they are semantically attached to the Catalog (and indirectly to the Store/Terminal where the Catalog is assigned).
- Catalog ↔ Store/Terminal (Assignment) → Operational link that determines which Catalog is active on a Store/Terminal at a given time (typically one active per Terminal).
- Inventory (optional but recommended) → Standard POS pattern ties stock to Product and Store/Location (e.g., available_qty per Store). If adding inventory, link it to (product_id, store_id) to track stock by sales location (similar to Shopify's InventoryItem/Level per location pattern).

### Compact reference
- Business → Stores → Terminals → assigned Catalog
- Catalog → {Categories, Products, Taxes, Discounts, Fees}
- Product ↔ Category (M:N)
- (Optional) InventoryLevel: Product × Store
- Price/Overrides can live on Product or on the Catalog–Product relationship (preferred on the relationship since the Catalog carries the price list).