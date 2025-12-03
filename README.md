# Poynt Service

## Tenant schema provisioning
Tenant schemas are now managed directly in PHP via `App\Services\Tenant\Provisioner`. The service reads `SQL/poynt-tenant-templates.sql`, expands each `_template` table/index definition into `<tenant>_...` statements for the requested tenant, replays them through the Doctrine connection provided by `Context`, and upserts the current version into `tenant_schema_version`.

Example provisioning call:

```php
$provisioner = new App\Services\Tenant\Provisioner($context);
$result = $provisioner->provision('demo_tenant');
```

To remove a tenant's tables and clear the schema version entry, call `drop` with the tenant identifier:

```php
$provisioner->drop('demo_tenant');
```
