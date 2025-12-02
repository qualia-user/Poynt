# Poynt Service

## Business schema provisioning
The database migration `2025120201_create_business_schema_procedures.sql` adds helper procedures for business-scoped tables. Provision a business by calling `provision_business_schema` with the business identifier, which expands the `_template` base tables into `<business_id>_<base>` copies in dependency order, applies indexes and constraints, grants the `poynt_app_rw`/`poynt_app_ro` roles, and records the version in `business_schema_version`.

```sql
CALL provision_business_schema('demo_business');
```

To deprovision, use `drop_business_schema` to remove all business-prefixed tables and clear the version entry:

```sql
CALL drop_business_schema('demo_business');
```
