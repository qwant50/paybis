# CLAUDE.md

This file provides guidance to Claude Code when working with this repository.

## Project Overview

**Crypto Exchange Rates API.** A small Symfony application that fetches **EUR→BTC,
EUR→ETH, EUR→LTC** prices from the Binance Spot API every 5 minutes (Symfony
Scheduler → Messenger worker), stores one sample per pair, and exposes them as
JSON for charting (last 24h, and a specific UTC day) with OpenAPI/Swagger docs.

PHP 8.4 / Symfony 8 (resolves to 8.1). MySQL 8, Doctrine ORM 3 / DBAL 4. Runs in
Docker behind nginx → PHP-FPM. Prices are kept loss-free as `DECIMAL(30,12)` via
`brick/money`.

## Code Principles

**DDD**, **DRY**, **KISS**, **SOLID** — flat file structures, avoid over-abstraction,
single responsibility. The Domain layer must never import from Application or
Infrastructure.

## Development Environment

Everything runs inside the `paybis-app` container. The committed
`docker-compose.yml` is a minimal base; the real per-service config (build args,
volumes, ports) lives in `docker-compose.override.yml`, which is **gitignored**.
Before the first start, copy the tracked sample and configure it for your machine:

```bash
cp samples/docker-compose.local.yml docker-compose.override.yml   # one-time, then edit if needed
cp samples/.env.local .env                                        # one-time, then edit (secrets, credentials)
docker compose up -d --build      # start stack (app + nginx + MySQL); first run installs deps + migrates
docker exec -it paybis-app sh     # enter the container
docker compose build              # rebuild after Dockerfile changes
```

Compose auto-merges `docker-compose.override.yml` over the base, so no `-f` flags
are needed. Both `docker-compose.override.yml` and the root `.env` are **gitignored**;
tracked samples live in `samples/`.

**Configuration is environment-only — there are no `.env` files inside `app/`.** The
root `.env` is the single per-machine config source; `docker-compose.override.yml`
injects it into the containers as real environment variables (`env_file: .env`), and
the app reads everything from the environment. So that PHP-FPM workers see those
variables, the FPM pool sets `clear_env = no` (`docker/app/config/php/www.conf`, copied
to `/usr/local/etc/php-fpm.d/`), and `symfony/runtime`'s own dotenv loading is turned
off (`extra.runtime.disable_dotenv` in `app/composer.json`). The only variables the
app consumes are `APP_ENV`, `APP_SECRET`, `APP_DB`, `APP_DB_HOST`, `APP_DB_PORT`,
`APP_DB_USER`, `APP_DB_PASSWORD`, `BINANCE_API_KEY`, `BINANCE_API_SECRET`,
`API_SIGNING_SECRET`, `API_SIGNING_KEY_ID` (`APP_DEBUG` is derived from `APP_ENV`).

The host `./app` directory is mounted at `/app/` in the container. Containers/ports:

| Container    | Role                                               | Host port |
|--------------|----------------------------------------------------|-----------|
| `paybis-web` | nginx, serves the HTTP API (HTTP/1.1) + HTTP/2 TLS | `8090`, `8443` |
| `paybis-app` | PHP-FPM + Supervisor (FPM, cron, scheduler worker) | –         |
| `paybis-db`  | MySQL 8                                             | `3308`    |

API: <http://localhost:8090> (HTTP/1.1) or <https://localhost:8443> (HTTP/2 over
TLS, self-signed cert) · Swagger UI: `/api/doc` · raw spec: `/api/doc.json`.

HTTP/2 is served by nginx over TLS with a self-signed certificate baked into the
`paybis-web` image (`docker/web/Dockerfile`). Both server blocks (`:80` and
`:443`) share the app/FastCGI config via `docker/web/app.conf`
(`/etc/nginx/snippets/app.conf`); `docker/web/nginx.conf` adds only the TLS +
`http2 on;` listener.

Check the app container is healthy (Supervisor programs all `RUNNING`):

```bash
docker inspect -f '{{.State.Health.Status}}' paybis-app   # -> healthy
docker exec paybis-app supervisorctl status               # php-fpm, cron, scheduler-rates
```

## Commands

Run inside the container (prefix with `docker exec paybis-app` from the host):

```bash
composer install           # install dependencies
composer test              # all tests (Codeception: Unit + Integration)
composer test-unit         # unit suite only
composer cs-check          # PSR-12 lint
composer cs-fix            # auto-fix style
composer phpstan           # static analysis (level 9)
composer update-db         # run migrations
composer update-db-test    # run migrations on the test DB
composer migration-diff    # generate a migration from entity changes

bin/console app:rates:fetch   # fetch all pairs once (manual run / smoke test)
bin/console debug:scheduler   # inspect the recurring schedule
```

## Testing

Codeception 5 (wraps PHPUnit 11). Suites: `Unit`, `Integration` (actors
`UnitTester`, `IntegrationTester`). Integration tests use the `app_test` database
(auto-created) with the `Symfony` + `Doctrine` modules; each test runs in a
transaction that is rolled back. PSR-12; PHPStan level 9 (`app/phpstan.neon`).

With no `.env` files, the test-only config lives in two committed places: the test
database name is derived as `%env(resolve:APP_DB)%_test` in
`config/packages/test/doctrine.yaml` (so both the suite and `--env=test` migrations
hit `app_test`, inheriting host/user/password from the environment), and the
deterministic non-secret signing values (`API_SIGNING_SECRET`, `API_SIGNING_KEY_ID`)
plus `APP_ENV=test` are set in `tests/bootstrap.php` (wired via `codeception.yml`'s
`settings.bootstrap`).

## Architecture

Strict layered DDD under `app/src/`:

### Domain — `Domain/ExchangeRate/` (pure, no framework)
- `CurrencyPair` — immutable VO; **single source of truth** mapping public pairs
  (`EUR/BTC`) ↔ Binance symbols (`BTCEUR`), plus each pair's Binance price
  `tickSize`. `fromString()` validates against the supported set; `all()` /
  `supportedPairs()` enumerate it; `displayScale()` derives the per-pair display
  precision from the tick size (e.g. `0.01` → 2 decimals).
- `Rate` — immutable VO over `brick/money` (storage/arithmetic scale `12`); parses
  a Binance price string, exposes `asString()` for `DECIMAL` storage, `toFloat()`,
  and `format(int $scale)` for rendering at a pair's display precision. Parsing
  raises `PrecisionLossException` if a price has more than 12 decimals (never
  truncates silently).
- `Day` — immutable VO; strict `YYYY-MM-DD` (UTC midnight) parsing.
- `ExchangeRate` — immutable domain model composing `CurrencyPair` + `Rate` +
  UTC `recordedAt`. The type the Application layer reads and writes, so it never
  touches the Doctrine entity (`ExchangeRateDoctrine`). Carries no DB identity
  (a persistence concern).
- `RateRepository` (interface) — persistence **port**, expressed purely in domain
  types (`save(ExchangeRate)`, `findBetween(): list<ExchangeRate>`). The Doctrine
  repository is its adapter; keeping the port here lets Application depend only
  inward. Depend on the interface. (Named `RateRepository`, not
  `ExchangeRateRepository`, to stay distinct from the Doctrine adapter class.)
- `Exception/InvalidPairException`, `Exception/InvalidDateException` — domain input
  errors (extend `\InvalidArgumentException`); their messages are client-safe.

### Application — `Application/`
- `Service/TickerPriceProvider` (interface) — port abstracting market-data access;
  its Binance adapter lives in Infrastructure. Depend on the interface.
- `Service/RateFetcher` — fetches every supported pair, persists one `ExchangeRate`
  each via `RateRepository`, **isolates per-pair failures** (logs, continues),
  returns a `RateFetchReport`.
- `Query/RateQueryService` — read side over `RateRepository`: `lastDay()` (rolling
  24h) and `forDay()` (a UTC calendar day `[00:00, next 00:00)`), each returning
  `list<ExchangeRate>`. All windows are UTC.

The Application layer holds no framework/SDK imports: the Binance adapter and the
Symfony Scheduler/Messenger glue both live in Infrastructure (see below).

### Infrastructure — `Infrastructure/`
- `Binance/BinanceService` — the `TickerPriceProvider` adapter; a thin wrapper
  over the Binance spot REST client (the only place the Binance SDK is imported).
- `Scheduler/` — `RatesSchedule` (`#[AsSchedule('rates')]`, stateful, every 5 min)
  dispatches `FetchRatesMessage`, handled by `FetchRatesMessageHandler` →
  `RateFetcher`. The framework's scheduling/messaging entry points live here so
  the Application layer stays framework-free.
- `Console/FetchRatesCommand` — `app:rates:fetch` (entry point only).
- `Controller/Api/V1/Rate/` — one resource folder, split by type:
  `Action/` (single-action controllers `LastDayAction` →
  `GET /api/v1/rates/last-24h`, `DayAction` → `GET /api/v1/rates/day`),
  `Mapper/` (`RateSeriesMapper`, `ExchangeRate`→DTO shaping), and `Response/` (the
  `RateSeriesResponse`/`RatePoint` wire-contract DTOs, plus the OpenAPI-only
  `RateSeriesEnvelope` that composes the shared envelope with the resource
  payload). Actions type-hint the domain VOs (`CurrencyPair $pair`, `Day $day`)
  directly. JSON responses + OpenAPI attributes. **No try/catch** — invalid input
  is thrown as a domain exception and handled centrally (see below).
- `Controller/ArgumentResolver/` — `CurrencyPairValueResolver` /
  `DayValueResolver` build the `CurrencyPair` / `Day` action arguments from the
  `pair` / `date` query params (calling `::fromString()`), so the parsing — and
  the domain exception it raises — stays out of the actions.
- `Controller/Api/` (cross-resource, all versions) — `ApiResponder` wraps every
  success (`ok()`) and error (`error()`) payload in the one signed `ApiEnvelope`
  and is the single place that stamps the `X-Request-Id` header; `Response/`
  holds the envelope DTOs (`ApiEnvelope`, `ApiError`, `ApiVersion`, `Signature`,
  and the OpenAPI-only `ApiErrorEnvelope`); `Security/ResponseSigner` computes the
  HMAC-SHA256 integrity signature over the canonical payload JSON.
- `EventListener/` — `ApiExceptionListener` (`kernel.exception`; the **single
  place** that turns exceptions into the error envelope) and `RequestIdListener`
  (`kernel.request`; mints/validates the per-request correlation id stored as the
  `_request_id` attribute). `Logging/RequestIdProcessor` tags every log record
  with that id.
- `Doctrine/` — `Entity/ExchangeRateDoctrine`, `Repository/ExchangeRateRepository`
  (the `RateRepository` adapter; persistence only), `Mapper/ExchangeRateMapper`
  (the **single place** that maps `ExchangeRateDoctrine` entity ↔ domain
  `ExchangeRate`, via `domainToDoctrine()` / `doctrineToDomain()`), and
  `Migrations/`. `ExchangeRateRepository.findBetween()` returns chronological
  domain samples.

## Key Patterns

### Controller organization
Controllers live under `Infrastructure/Controller/` and route attributes are
loaded recursively, so subfolders need **no** routing/service config:
- `Api/V<n>/<Resource>/` — JSON endpoints, **one single-action controller per
  endpoint** (a class with `__invoke()`, named `<UseCase>Action`). This keeps
  controllers from ever growing fat — "hundreds of endpoints" becomes many small,
  individually-testable classes grouped by resource. Within a resource, classes
  are **grouped by type** in homogeneous subfolders so the folder stays scannable
  as endpoints multiply: `Action/` (the `<UseCase>Action` controllers), `Mapper/`
  (entity→DTO shaping), `Response/` (wire-contract DTOs). A new endpoint drops a
  class into `Action/`; a new response DTO drops into `Response/`. Cross-resource
  helpers live at the `Api/` root: `ApiResponder`, the envelope DTOs under
  `Response/` (`ApiEnvelope`, `ApiError`, `ApiVersion`, `Signature`), and
  `Security/ResponseSigner`. The
  version (`V1`, `V2`, …) is both the namespace and the URL prefix
  (`/api/v1/...`); a new version is a sibling folder.
- `Web/` — (future) server-rendered/UI controllers. The JSON error envelope and
  Swagger only apply to `/api/v…`, so UI routes stay out of both.

### API error handling (`ApiExceptionListener`)
For requests under `/api/v` (any version), the listener reduces the exception to
an `ApiError` (`message` + stable `code`), which `ApiResponder::error()` wraps in
the signed envelope. Three tiers, in order:
- whitelisted domain **input** exceptions (`InvalidPairException` → `INVALID_PAIR`,
  `InvalidDateException` → `INVALID_DATE`) → `400` with their client-safe message;
- any other `HttpExceptionInterface` (routing `404` → `NOT_FOUND`, `405` →
  `METHOD_NOT_ALLOWED`, …) → its real status + headers preserved, generic message;
- everything else → logged at error level and hidden behind a generic `500`
  (`INTERNAL_ERROR`, `"Internal server error."`), so internals never leak.

- Parsing happens in the argument resolvers (`CurrencyPairValueResolver`,
  `DayValueResolver`), so actions stay thin and just let the exception propagate.
- To expose a new client-facing 400, add the domain exception class (mapped to its
  code) to `ApiExceptionListener::CLIENT_ERRORS` (never broaden to
  `catch (\Throwable)`).

### Response envelope (`ApiResponder` + `ApiEnvelope`)
Every API response — success and error — shares one top-level shape, built in one
place (`ApiResponder`): `id` (correlation id, mirrored in the `X-Request-Id`
header), `status` (`success`|`error`), `version` (`{api, release}` — the URL
contract version from the path + the deployed `app.release`), `datetime` (UTC
ISO-8601), exactly one of `data` (success) or `error` (`{message, code}`), and
`security` (`{algorithm, keyId, signature}`). The signature is an HMAC-SHA256
(`API_SIGNING_SECRET` / `API_SIGNING_KEY_ID`) over the **canonical JSON of the
payload only** (`data`/`error`, not the metadata), so a client can re-encode that
sub-object and verify integrity. Resource-specific shaping of `data` belongs in
that resource's mapper/`Response/` DTO, never in `ApiResponder`.

### Adding a supported currency pair
Add the `'EUR/XXX' => ['symbol' => 'XXXEUR', 'tickSize' => '<binance tick size>']`
entry to `CurrencyPair::MAP` (copy the tick size verbatim from Binance
`exchangeInfo`). Everything else — fetching, querying, API enum docs, and the
per-pair display precision — derives from it.

### Precision (three distinct scales)
Never use floats for prices. The single `scale` is deliberately split into three:
- **Storage scale** — the `DECIMAL(30,12)` column; a fixed, generous ceiling that
  keeps every price loss-free. Independent of per-pair config, so adding a normal
  pair never needs a migration.
- **Arithmetic/parse scale** — `Rate::SCALE` (12), aligned with storage. Parse
  Binance strings via `Rate::fromString()` and store `Rate::asString()`; a price
  with more decimals than 12 raises `PrecisionLossException` rather than being
  silently rounded (the fetch loop isolates and logs it per pair).
- **Display precision** — per pair, derived from its Binance `tickSize`
  (`CurrencyPair::displayScale()`), applied only at read time via `Rate::format()`
  in `RateSeriesMapper`. A tickSize change on Binance over time needs **no**
  per-data-point metadata — stored values stay exact; only this config is updated.

## Doctrine / DB notes (ORM 3 / DBAL 4)

- Single entity `ExchangeRateDoctrine` (attribute mapping). Repository extends
  `ServiceEntityRepository`.
- `config/packages/doctrine.yaml`: `server_version: '8.0.0'` (must be `>= 8.0.0`
  for DBAL 4's platform check — `'8.0'` selects the legacy platform and warns).
- doctrine-bundle 3 removed `use_savepoints`, `auto_generate_proxy_classes`,
  `report_fields_where_declared`; prod cache uses native PSR-6 pools
  (`type: pool`), not the old `DoctrineProvider` bridge.

## Runtime (Supervisor in `paybis-app`)

`docker/app/config/supervisor/programs.conf` runs three programs: `php-fpm`,
`cron` (restarts FATAL programs), and `scheduler-rates`
(`messenger:consume scheduler_rates` — drives the 5-minute fetch). The container
healthcheck (in `docker-compose.override.yml`) passes only when **every**
Supervisor program reports `RUNNING`; if any program drops to a non-RUNNING state
(`FATAL`, `EXITED`, `STOPPED`, …) the container is marked unhealthy.

## Coding Conventions

- PSR-4: `App\` → `src/`, `Tests\` → `tests/`. PSR-12 (`composer cs-fix`).
- Prefer immutable `final readonly` value objects and constructor injection.
- Time is **UTC** everywhere (storage, queries, parsing).
- Unit tests mock collaborators via `$this->createMock(...)` (e.g. `TickerPriceProvider`,
  `LoggerInterface`); integration tests use `haveInRepository(...)`.
