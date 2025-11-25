# Project guidelines

## Overview
- This is a PHP 8.2 service that uses a lightweight framework built around the `App\Core\Context`.  Application code is grouped under `app/` with feature code split into `Controllers`, `Services`, `Modules`, `Views`, and reusable helpers under `app/Services/Support`.
- HTTP requests are made with Guzzle clients obtained from the `Context`; persistence is handled through Doctrine DBAL connections that also come from the same `Context` object.
- Environment configuration is read from `.env` via `vlucas/phpdotenv`.  Do **not** commit real secrets—update `.env.example` if new settings are introduced.

## Coding conventions
- Follow PSR-12 style: 4-space indentation, opening braces on the same line, and a blank line between namespace/use declarations and code.
- Prefer constructor injection via `Context` for dependencies (e.g., database connections, loggers, HTTP clients).  When creating new services or controllers, mirror the existing constructors and expose setters only when tests need to replace collaborators.
- Always type-hint parameters and return values.  Use union/null types where appropriate and add PHPDoc blocks when the type cannot be expressed in the signature (arrays with specific keys, mixed payloads, etc.).
- Reuse helpers from `App\Services\Support` for data normalization (`PoyntDataFormatter`, `PaginatedRequest`, logging helpers, etc.) instead of duplicating logic.
- Controllers should extend `App\Controllers\Controller` to obtain shared context helpers.  Route handlers should return plain arrays that can be encoded as JSON unless the calling code expects a `Response` instance.
- Log meaningful events and failures through `$this->context->getLog()` using the structured patterns found in existing services.

## Tests & tooling
- Tests live under `tests/` and use PHPUnit.  Install dependencies with `composer install` and run the suite via:
  ```bash
  composer install
  ./vendor/bin/phpunit
  ```
- Add or update tests alongside any behavior change.  For HTTP clients or external services, prefer injecting fakes/mocks rather than hitting real endpoints.
- Keep coding standard checks in mind; if you introduce new tooling (linting, static analysis), document the command in this file.

## Making changes
- New classes should be autoloadable through the existing PSR-4 mapping (`App\\` → `app/`).  Place files in the appropriate directory and keep namespaces aligned with the folder structure.
- When adding a new OAuth platform, follow the naming convention `App\Modules\OAuth\<Platform>OAuthHandler` so the registry can resolve it automatically.
- Be mindful of pagination helpers when dealing with list endpoints; prefer `PaginatedRequest::collect` to roll your own loops.
- Ensure database writes use prepared statements/parameter binding through Doctrine's connection, mirroring patterns in existing services.
- SQL directory is used for SQL client, there should be only initial SQL definitions(not alter definitions, only last definition version). 'database' directory can contain all migrations we had during development. Migrations shouldn't be script, but plain SQL definition. 