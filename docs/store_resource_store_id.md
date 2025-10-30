# Store-Scoped Data Attribution

## Overview
Every table whose data is billed on a per-store basis either carries a `store_id` column directly or can be joined back to a store through a parent relationship. Below is a breakdown of each store-scoped resource and how its schema and ingest logic preserve the store relationship.

## Core store master data
- The `store` table itself persists both the `store_id` and its owning `business_id`, establishing the canonical store-to-business mapping used everywhere else.
- Terminals are written into the `terminal` table with a required `store_id`, and the `TerminalService` upserts each device against that identifier so terminals remain joinable to their store.

## Store-priced subscription data
- Each subscription row stores both `business_id` and `store_id`, and `SubscriptionService::startFreeTrial()` inserts them explicitly, ensuring subscription billing can always be attributed to the correct store.

## Store-scoped inventory
- `inventory` and `variant_inventory` both key on `(business_id, store_id, …)`, and the corresponding upsert paths enforce the presence of `storeId` before writing, guaranteeing stock levels are sliceable per store.

## Orders and their children
- The `order` table persists `store_id` alongside `business_id`, and `OrderService` forwards whatever store the upstream payload supplied. When present, every order row is attributable to a store.
- `order_item`, `order_history`, and `order_shipment` reference `order_id`, so they inherit store attribution by joining through the parent order row.

## Transactions and receipts
- `transaction` stores `store_id` (nullable when Poynt does not send it) with `business_id`, and the service layer upserts that column so any provided store context is retained for billing. Receipts piggy-back via a foreign key to transactions.
- `transaction_receipt` links to `transaction` through `transaction_id`, so receipts can always be traced back to the store through their parent transaction.

## Sanity/reporting utilities
- The sanity checker intentionally fetches every table that has a `store_id`, and for child tables without that column it walks their parent (`order` or `transaction`) so the reporting view still resolves back to store-level records.

## Summary of findings
- All first-class store resources (`store`, `terminal`, `subscription`, `inventory`, `variant_inventory`, `order`, `transaction`) either persist `store_id` themselves or inherit it through a required parent join.
- Secondary tables (`order_item`, `order_history`, `order_shipment`, `transaction_receipt`) join through their parent entities that already include `store_id`, keeping store-level attribution intact.
- When upstream payloads omit `store_id` (occasionally seen in orders/transactions), the schema still allows the row, but attribution then depends on enrichment such as `store_device_id`. Monitoring upstream completeness may be worthwhile if strict per-store billing is required.
