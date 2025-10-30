# Onboarding recovery workflow

When a merchant install fails part-way through onboarding you can purge the local footprint and re-run the initial resource sync for targeted entities. This document outlines the recommended recovery flow using both the bundled CLI tooling and the HTTP endpoint exposed for browser-based triggering.

## Prerequisites

* Database connectivity details must be available in `App\Config\ConfigDatabase` and the environment should be able to reach the Poynt APIs.
* The merchant's business identifier (and optionally store identifiers) should be known ahead of time.

## CLI recovery walkthrough

1. **Purge the existing installation footprint**

   ```bash
   php scripts/purge_business.php --business=<BUSINESS_ID>
   ```

   Add `--drop-tokens` if you also need to remove stored OAuth credentials.

2. **Re-run the onboarding gather for targeted resources**

   ```bash
   php scripts/gather_resources.php --business=<BUSINESS_ID> --resources=business,store
   ```

   * `--resources` accepts a comma-separated list of resource keys (e.g. `business`, `store`, `subscription`).
   * The script returns a JSON summary describing which resources were matched and whether their synchronization succeeded. A non-zero exit code indicates that at least one resource failed.

3. **Verify logs**

   Inspect the application logs for additional context if any resource reports a failure. The gatherer logs each fetch/upsert attempt along with detailed error messages.

## Browser / HTTP recovery walkthrough

The recovery endpoint is available at `/internal/onboarding/resources` and can be invoked via a GET request from a browser or a POST request from a tool such as `curl` or Postman.

### Quick browser invocation

Navigate to:

```
https://<your-host>/internal/onboarding/resources?businessId=<BUSINESS_ID>&resources=business,store
```

The response is a JSON payload summarizing the gather operation, mirroring the CLI output.

### POST request example

```bash
curl -X POST \
  https://<your-host>/internal/onboarding/resources \
  -H 'Content-Type: application/json' \
  -d '{
        "businessId": "<BUSINESS_ID>",
        "resources": ["business", "store"]
      }'
```

The endpoint accepts the following fields:

| Field | Type | Description |
| ----- | ---- | ----------- |
| `businessId` | string | **Required.** The merchant business identifier to refresh. |
| `resources` | array\|string | Optional list of resource keys or service class names to target. When omitted the full onboarding set is synchronized. |

The JSON response contains:

* `success` – overall status of the gather.
* `requestedFilters` – the normalized filters supplied in the request.
* `matchedResources` – the resource keys that matched those filters.
* `resources` – per-resource success/skip/failure metadata.
* `error` – only present when no resources matched or a failure occurred.

## Resource filter values

Resource filters can be specified using any of the following formats:

* The keyed name returned by `ServiceFactory::onboardingResources()` (e.g. `business`, `store`, `product`).
* The short service class name (e.g. `StoreService`).
* The fully-qualified service class name (e.g. `App\\Services\\StoreService`).

Filters are case-insensitive and non-alphanumeric characters are ignored when matching resource keys or short class names.

## Automating purge + gather

For scripted recoveries you can chain the purge and gather commands:

```bash
php scripts/purge_business.php --business=<BUSINESS_ID> \
  && php scripts/gather_resources.php --business=<BUSINESS_ID> --resources=business,store
```

Handle the exit codes accordingly to alert operators if either step fails.
