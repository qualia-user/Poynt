# Poynt Integration Service

A lightweight PHP 8.2 service for managing OAuth flows, subscriptions, and webhook handling for Poynt-integrated businesses. The application is organized around a shared `Context` object that provides database access, logging, HTTP clients, and configuration needed by controllers and services.

## Architecture
- **Entry point**: `public/index.php` bootstraps configuration, opens PostgreSQL connections, wires Monolog logging, and dispatches requests through the Phroute router using dependency injection via `league/container`.
- **Routing**: Routes are defined in `App\Core\Api::loadRouteData()` and mapped to controllers. The router supports OAuth callbacks, webhook listeners, subscription management, and internal maintenance endpoints.
- **Modules & services**:
  - `app/Modules/OAuth` contains platform-specific OAuth handlers (e.g., Poynt, Clover) registered through `PlatformRegistry`.
  - `app/Services` contains domain services for subscriptions, webhooks, orders, customers, products, and background jobs.
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

## Maintenance scripts
- `scripts/purge_business.php` removes local records for a business (and optionally drops stored tokens). Usage:
  ```bash
  php scripts/purge_business.php --business=<BUSINESS_ID> [--drop-tokens]
  ```

## Testing
Run the PHPUnit suite after installing dependencies:
```bash
./vendor/bin/phpunit
```
