# Poynt Service

## Tenant schema provisioning
Tenant schemas are now managed directly in PHP via `App\Services\Tenant\Provisioner`. The service reads `SQL/poynt-tenant-templates.sql`, expands each `_template` table/index definition into `<tenant>_...` statements for the requested tenant, replays them through the Doctrine connection provided by `Context`, and upserts the current version into `tenant_schema_version`. Templates no longer carry `business_id` columns because every tenant receives its own physical tables; the only shared lookup record is the `business` row that registers a tenant. 【F:SQL/poynt-tenant-templates.sql†L1-L22】

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
