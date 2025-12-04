+62-3
# Poynt Service
# Poynt Integration Service

A lightweight PHP 8.2 service for managing OAuth flows, subscriptions, tenant provisioning, and webhook handling for Poynt-integrated businesses. The application is organized around a shared `Context` object that provides database access, logging, HTTP clients, and configuration needed by controllers and services.

## Architecture
- **Entry point**: `public/index.php` bootstraps configuration, opens PostgreSQL connections, wires Monolog logging, and dispatches requests through the Phroute router using dependency injection via `league/container`.
- **Routing**: Routes are defined in `App\Core\Api::loadRouteData()` and mapped to controllers. The router supports OAuth callbacks, webhook listeners, subscription management, tenant provisioning, and internal maintenance endpoints.
- **Modules & services**:
    - `app/Modules/OAuth` contains platform-specific OAuth handlers (e.g., Poynt, Clover) registered through `PlatformRegistry`.
    - `app/Services` contains domain services for subscriptions, webhooks, orders, customers, products, background jobs, and tenant provisioning helpers.
    - Shared utilities live in `app/Services/Support` (logging helpers, data formatters, etc.).
- **Views**: Minimal PHP views under `app/Views` support diagnostic pages such as the sanity check.

## Configuration
Configuration files are loaded from `public/bootstrap.php` and are expected to live under a `config/` directory adjacent to `public/`:
- `ConfigApp.php` should expose static properties such as `$environment`, `$timezone`, `$orgId`, `$appId`, `$platform`, `$webRootUrl`, and `$location` (for platform-specific settings).
- `ConfigDatabase.php` should define PostgreSQL connection settings: `$host`, `$port`, `$database`, `$username`, `$password`, and `$charset`.
- `ConfigClover.php` should provide Clover client credentials keyed by environment and location.
- `config.php` can contain any additional bootstrap configuration required by your deployment.

Create these files before running the service. Do **not** commit real secrets; store environment-specific values outside version control.

## Installation
1. Install PHP 8.2 and Composer.
2. Install dependencies:
   ```bash
   composer install
   ```
3. Create the configuration files listed above with values for your environment.

## Running the service locally
Use PHP's built-in server to serve the `public/` directory:
```bash
php -S 0.0.0.0:8000 -t public
```
Routes are resolved from the `request` query parameter. For example, hit the sanity check endpoint at `http://localhost:8000/index.php?request=/sanity-check`.

## API routes
The router currently exposes:
- `GET /install` – installation placeholder.
- `GET /callback` – OAuth callback handler.
- `POST /webhooks/event-listener` – webhook receiver.
- `GET /webhooks/delete-webhook/{businessId}` – remove webhooks for a business.
- `POST /internal/refresh-tokens` – refresh expiring tokens.
- `GET /subscriptions/status` – check subscription status.
- `POST /subscriptions/start-trial` – start a trial subscription.
- `GET /sanity-check` – diagnostic view.
- `POST /tenants/provision` – manually provision tenant schemas outside the OAuth flow.

## Tenant schema provisioning
Tenant schemas are now managed directly in PHP via `App\Services\Tenant\Provisioner`. The service reads `SQL/poynt-tenant-templates.sql`, expands each `_template` table/index definition into `<tenant>_...` statements for the requested tenant, replays them through the Doctrine connection provided by `Context`, and upserts the current version into `tenant_schema_version`. Templates no longer carry `business_id` columns because every tenant receives its own physical tables; the only shared lookup record is the `business` row that registers a tenant. 【F:SQL/poynt-tenant-templates.sql†L1-L22】
Tenant schemas are managed directly in PHP via `App\Services\Tenant\Provisioner`. The service reads `SQL/poynt-tenant-templates.sql`, expands each `_template` table/index definition into `<tenant>_...` statements for the requested tenant, replays them through the Doctrine connection provided by `Context`, and upserts the current version into `tenant_schema_version`. Templates no longer carry `business_id` columns because every tenant receives its own physical tables; the only shared lookup record is the `business` row that registers a tenant. 【F:SQL/poynt-tenant-templates.sql†L1-L22】

### Fresh database bootstrap and onboarding
1. Apply `SQL/poynt-v4.sql` to create the shared registry tables (`business`, `tenant_schema_version`, `tenant_table_registry`). No business data tables are created by this script. 【F:SQL/poynt-v4.sql†L1-L32】
2. When a merchant completes OAuth and reaches `/callback`, `CallbackService::prepareTenantStorage` syncs the `business` row and invokes `TenantProvisioningService::provisionTenant`, which expands all templates in `SQL/poynt-tenant-templates.sql` for that tenant. This creates the `<tenant>_*` tables and indexes automatically before the onboarding workflow continues. 【F:app/Services/CallbackService.php†L65-L135】【F:SQL/poynt-tenant-templates.sql†L1-L22】
3. Provisioning can also be triggered manually through `POST /tenants/provision` if you need to (re)create tables outside the OAuth flow. 【F:app/Controllers/TenantController.php†L17-L30】

### Per-business table workflow
1. **Register the tenant** by inserting a row into `business` (the global registry). Provisioning calls will fail if the tenant row is missing. 【F:app/Services/Tenant/TenantProvisioningService.php†L45-L54】
2. **Provision tenant tables** by hitting `POST /tenants/provision` with `tenantId` and optional `templates` parameters (defaults to all templates). The controller normalizes the request and forwards it to `TenantProvisioningService`, which defers to `Provisioner` for SQL expansion and execution. 【F:app/Core/Api.php†L296-L305】【F:app/Controllers/TenantController.php†L17-L30】【F:app/Services/Tenant/Provisioner.php†L49-L118】
3. **Track schema metadata**. After rendering and executing the tenant-scoped statements, `Provisioner` writes the fully qualified table names and template version into `tenant_table_registry` and `tenant_schema_version` so we can tell which version was applied to each tenant. 【F:app/Services/Tenant/Provisioner.php†L92-L118】【F:app/Services/Tenant/Provisioner.php†L240-L289】
4. **Resolve table names per tenant**. When building queries, use `App\Services\Support\TableNamer::for($businessId, $baseName)` to pick the correct `<tenant>_<base>` name; the helper consults `tenant_table_registry` to ensure callers use the provisioned version. 【F:app/Services/Support/TableNamer.php†L20-L53】
5. **Drop tenant schemas when needed** by calling `Provisioner::drop($tenantId)`, which removes the prefixed tables in dependency order and clears the schema version entry. 【F:app/Services/Tenant/Provisioner.php†L120-L210】

Example provisioning call from application code:

```php
$provisioner = new App\Services\Tenant\Provisioner($context);
$result = $provisioner->provision('demo_tenant');
```

To remove a tenant's tables and clear the schema version entry, call `drop` with the tenant identifier:

```php
$provisioner->drop('demo_tenant');
```

## Callback onboarding storage preparation
The OAuth callback flow now provisions and validates tenant storage before running the onboarding workflow. The callback service:
The OAuth callback flow provisions and validates tenant storage before running the onboarding workflow. The callback service:

- Resolves the platform handler and exchanges tokens, then normalizes optional `planId`/`planName` inputs. 【F:app/Services/CallbackService.php†L35-L73】【F:app/Services/CallbackService.php†L75-L95】
- Syncs the business record through the platform service and provisions per-tenant tables via `TenantProvisioningService::provisionTenant` before continuing. 【F:app/Services/CallbackService.php†L97-L129】
- Runs the transactional onboarding workflow: reactivates existing installations or starts a trial, synchronizes stores and subscriptions (with optional plan lookup), and gathers initial resources. Rollbacks occur on failure and the installation is purged when necessary. 【F:app/Services/CallbackService.php†L137-L233】【F:app/Services/CallbackService.php†L315-L428】

## Business-scoped table templates
`SQL/poynt-business-templates.sql` defines the per-business schema templates that are materialized for each tenant. Each statement uses a `_template` suffix that is replaced with the tenant-specific table name during provisioning. The file includes templates for core entities (stores, tokens, subscriptions, webhooks, logs), customer/user records, products/inventory, catalog configuration, payment artifacts, and loyalty structures. 【F:SQL/poynt-business-templates.sql†L1-L160】【F:SQL/poynt-business-templates.sql†L161-L320】

## Maintenance scripts
- `scripts/purge_business.php` removes local records for a business (and optionally drops stored tokens). Usage:
  ```bash
  php scripts/purge_business.php --business=<BUSINESS_ID> [--drop-tokens]
  ```
- `scripts/delete_subscriptions.php` removes subscriptions for a business using the merchant token. Add `--dry-run` to list the
  subscriptions that would be removed without deleting them. Usage:
  ```bash
  php scripts/delete_subscriptions.php --business=<BUSINESS_ID> [--dry-run]
  ```

## Testing
Run the PHPUnit suite after installing dependencies:
```bash
./vendor/bin/phpunit
```