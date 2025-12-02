# Poynt Service

## Tenant schema provisioning
The database migration `2025120201_create_tenant_schema_procedures.sql` adds helper procedures for tenant-scoped tables. Provision a tenant by calling `provision_tenant_schema` with the tenant identifier, which expands the `_template` base tables into `<tenant_id>_<base>` copies in dependency order, applies indexes and constraints, grants the `poynt_app_rw`/`poynt_app_ro` roles, and records the version in `tenant_schema_version`.

```sql
CALL provision_tenant_schema('demo_tenant');
```

To deprovision, use `drop_tenant_schema` to remove all tenant-prefixed tables and clear the version entry:

```sql
CALL drop_tenant_schema('demo_tenant');
```
