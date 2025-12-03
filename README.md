# Poynt Service

## Tenant schema provisioning
Tenant schemas are now managed directly in PHP via `App\Services\Tenant\Provisioner`. The service reads `SQL/poynt-tenant-templates.sql`, expands each `_template` table/index definition into `<tenant>_...` statements for the requested tenant, replays them through the Doctrine connection provided by `Context`, and upserts the current version into `tenant_schema_version`. Templates no longer carry `business_id` columns because every tenant receives its own physical tables; the only shared lookup record is the `business` row that registers a tenant. 【F:SQL/poynt-tenant-templates.sql†L1-L22】

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

- Resolves the platform handler and exchanges tokens, then normalizes optional `planId`/`planName` inputs. 【F:app/Services/CallbackService.php†L35-L73】【F:app/Services/CallbackService.php†L75-L95】
- Syncs the business record through the platform service and provisions per-tenant tables via `TenantProvisioningService::provisionTenant` before continuing. 【F:app/Services/CallbackService.php†L97-L129】
- Runs the transactional onboarding workflow: reactivates existing installations or starts a trial, synchronizes stores and subscriptions (with optional plan lookup), and gathers initial resources. Rollbacks occur on failure and the installation is purged when necessary. 【F:app/Services/CallbackService.php†L137-L233】【F:app/Services/CallbackService.php†L315-L428】

## Business-scoped table templates
`SQL/poynt-business-templates.sql` defines the per-business schema templates that are materialized for each tenant. Each statement uses a `_template` suffix that is replaced with the tenant-specific table name during provisioning. The file includes templates for core entities (stores, tokens, subscriptions, webhooks, logs), customer/user records, products/inventory, catalog configuration, payment artifacts, and loyalty structures. 【F:SQL/poynt-business-templates.sql†L1-L160】【F:SQL/poynt-business-templates.sql†L161-L320】
