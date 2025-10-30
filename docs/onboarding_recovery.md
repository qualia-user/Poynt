# Onboarding recovery workflow

When a merchant install fails part-way through onboarding you can purge the local footprint and re-run the initial resource sync for targeted entities. This document outlines the recommended recovery flow using both the bundled CLI tooling and the HTTP endpoint exposed for browser-based triggering.

## Prerequisites

* Database connectivity details must be available in `App\Config\ConfigDatabase` and the environment should be able to reach the Poynt APIs.
* The merchant's business identifier (and optionally store identifiers) should be known ahead of time.

## CLI recovery walkthrough

Follow these steps end-to-end to purge a broken install and immediately trigger the initial gather again.

1. **Purge the existing installation footprint**

   ```bash
   php scripts/purge_business.php --business=<BUSINESS_ID>
   ```

   * Add `--drop-tokens` if the OAuth credentials for the business should be removed as well.
   * The command prints a short status message to STDOUT and exits with `0` on success.

2. **Re-run the onboarding gather for targeted resources**

   ```bash
   php scripts/gather_resources.php --business=<BUSINESS_ID> --resources=business,store
   ```

   * `--resources` accepts a comma-separated list of resource keys (e.g. `business`, `store`, `subscription`).
   * The script emits a JSON summary showing which resources matched, their outcome (`success`, `skipped`, or `failed`), and an error string when anything fails.
   * A non-zero exit code indicates that at least one resource failed. Repeat this step once issues are addressed to reattempt the gather.

3. **Repeat as needed**

   Any time you need to refresh the data, run the gather command again with the same `--business` identifier. You do **not** need to purge again unless you want to wipe all local data first.

4. **Verify logs**
   * The script returns a JSON summary describing which resources were matched and whether their synchronization succeeded. A non-zero exit code indicates that at least one resource failed.

3. **Verify logs**

   Inspect the application logs for additional context if any resource reports a failure. The gatherer logs each fetch/upsert attempt along with detailed error messages.

## Browser / HTTP recovery walkthrough

The recovery endpoint is available at `/internal/onboarding/resources` and can be invoked via a GET request from a browser or a POST request from a tool such as `curl` or Postman.

### Quick browser invocation

1. Purge the business via the CLI command shown above. (A browser endpoint is not exposed for purging because it requires elevated access.)

2. In a browser window, navigate to:

   ```
   https://<your-host>/internal/onboarding/resources?businessId=<BUSINESS_ID>&resources=business,store
   ```

   The server responds with a JSON payload summarizing the gather operation, mirroring the CLI output.

3. Reload the page whenever you need to repeat the gather. Update the `resources` query string if you want to target a different subset.
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

Run the CLI purge first, then use this POST request to gather without needing a browser. Issue the same POST call multiple times to repeat the gather.

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
