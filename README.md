# Crypto Exchange Rates API

A small, production-grade Symfony application that periodically fetches **EUR→BTC,
EUR→ETH and EUR→LTC** exchange rates from the [Binance Spot API][binance] and
exposes them as JSON for charting.

- **Periodic ingestion** — every 5 minutes via **Symfony Scheduler** (a Messenger
  worker), one rate sample per pair, taken from the last *closed* 5-minute Binance
  candle so each sample sits exactly on the `:00/:05/:10…` UTC grid.
- **Two read endpoints** — last 24 hours, and a specific day.
- **OpenAPI docs** — interactive Swagger UI at `/api/doc`.

## Tech stack

| Concern            | Choice                                                             |
|--------------------|--------------------------------------------------------------------|
| Language / runtime | PHP 8.4                                                            |
| Framework          | Symfony 8.0 (Console, Messenger, Scheduler, HttpKernel)            |
| Storage            | MySQL 8                                                            |
| ORM / migrations   | Doctrine ORM + Doctrine Migrations                                 |
| Money / precision  | [brick/money] (arbitrary precision, 12 dp)                         |
| Binance client     | `binance/binance-connector-php` (official, OpenAPI-generated)      |
| API documentation  | `nelmio/api-doc-bundle` (OpenAPI 3 + Swagger UI)                   |
| Web server         | nginx → PHP-FPM                                                    |
| Tests / QA         | Codeception (PHPUnit), PHPStan (level 9), PHP_CodeSniffer (PSR-12) |

## How it works

Binance trades the inverse symbols `BTCEUR`, `ETHEUR`, `LTCEUR` — the EUR price of
one unit of crypto. The public pair `EUR/BTC` is mapped to the Binance symbol
`BTCEUR` by a single value object (`App\Domain\ExchangeRate\CurrencyPair`), so the
two representations can never drift apart. The stored price is that EUR-quoted
value, kept as a fixed-scale `DECIMAL(30,12)` and manipulated through a
`brick/money`-backed `Rate` value object so no precision is lost.

Each fetch reads the **last closed 5-minute candle** (`klines`, `interval=5m`) and
stores its close price stamped with the candle's `openTime`. That time is
authoritative and already on the 5-minute UTC grid, so chart points are evenly
spaced; re-storing an already-recorded slot is an idempotent no-op (a closed
candle is immutable history).

```
Scheduler (every 5 min) ─▶ FetchRatesMessage ─▶ RateFetcher
                                                   │  for each supported pair
                                                   ▼
                           BinanceService.latestClosedCandle("BTCEUR")
                                                   ▼
                                       exchange_rate table (MySQL)
                                                   ▲
GET /api/v1/rates/last-24h ─▶ Action ─▶ RateQueryService ─┘
GET /api/v1/rates/day
```

### Project layout (DDD layers)

```
app/src/
├── Domain/ExchangeRate/         # CurrencyPair, Rate, Day, ExchangeRate, RateRepository (pure, no framework)
├── Application/
│   ├── Service/                 # RateFetcher, ClosedCandleProvider (port), ClosedCandle, RateFetchReport
│   └── Query/                   # RateQueryService (read side)
└── Infrastructure/
    ├── Binance/                 # BinanceService (ClosedCandleProvider adapter)
    ├── Scheduler/               # FetchRatesMessage(+Handler), RatesSchedule (every 5 min)
    ├── Console/                 # app:rates:fetch
    ├── Controller/
    │   ├── Api/                 #   ApiResponder, signed envelope DTOs, ResponseSigner
    │   ├── Api/V1/Rate/         #   single-action controllers + mapper + response DTOs + OpenAPI
    │   └── ArgumentResolver/    #   CurrencyPair / Day resolvers (query-param parsing)
    ├── EventListener/           # ApiExceptionListener, RequestIdListener
    └── Doctrine/                # ExchangeRate entity, repository, mapper, migrations
```

## Requirements

- Docker + Docker Compose.

That's it — PHP, Composer, MySQL and all extensions run inside the containers.

## Installation & startup

The Compose stack is split into a minimal tracked base (`docker-compose.yml`) and
a local override that holds the build args, volumes and service config. The
override is **not** committed (it is in `.gitignore`), so copy the provided sample
and adjust it for your machine before the first start:

```bash
# 1. Create your local override from the sample, then edit if needed
cp samples/docker-compose.local.yml docker-compose.override.yml

# 2. Create your local .env from the sample, then edit (secrets, credentials)
cp samples/.env.local .env

# 3. Build images and start the stack (app + nginx + MySQL)
docker compose up -d --build
```

Docker Compose automatically merges `docker-compose.override.yml` on top of
`docker-compose.yml`, so no extra `-f` flags are needed. Both the override and the
root `.env` are **gitignored**; tracked samples live in `samples/`. The override
injects the root `.env` into the containers as real environment variables
(`env_file: .env`) — see [Configuration](#configuration).

On first start the app container automatically runs `composer install` and applies
database migrations (to both the app and test databases). Once the containers are
healthy the API is available at **http://localhost:8090**.

The stack starts three containers:

| Container    | Role                                               | Host port      |
|--------------|----------------------------------------------------|----------------|
| `paybis-web` | nginx, serves the HTTP API (HTTP/1.1) + HTTP/2 TLS | `8090`, `8443` |
| `paybis-app` | PHP-FPM + Supervisor (FPM, cron, scheduler worker) | –              |
| `paybis-db`  | MySQL 8                                             | `3308`         |

HTTP/2 is available over TLS at **https://localhost:8443** (self-signed cert baked
into the `paybis-web` image).

The 5-minute fetch starts automatically: Supervisor runs the
`messenger:consume scheduler_rates` worker inside `paybis-app`.

### Configuration

The app reads its entire configuration from the **environment** — there are no
`.env` files inside `app/`. The root `.env` (gitignored, copied from
`samples/.env.local`) is the single per-machine source; Compose injects it into the
containers as real environment variables. Variables consumed:

| Variable                         | Purpose                                   | Default |
|----------------------------------|-------------------------------------------|---------|
| `APP_ENV`                        | Symfony environment (`dev` / `prod`)      | `dev`   |
| `APP_SECRET`                     | Framework secret (CSRF, signed URIs)      | dev placeholder |
| `APP_DB`, `APP_DB_USER`, …       | MySQL connection                          | `app`   |
| `APP_DB_HOST` / `APP_DB_PORT`    | DB host / port (inside the network)       | `db` / `3306` |
| `BINANCE_API_KEY` / `_SECRET`    | Optional — **not needed** for public market data | empty |
| `API_SIGNING_SECRET`             | HMAC secret for the response signature    | dev placeholder |
| `API_SIGNING_KEY_ID`             | Signing key identifier (supports rotation) | `v1`   |

> The klines (candlestick) endpoint is public, so no Binance credentials are required.
>
> Tests need no `.env`: the `app_test` database name is derived in
> `config/packages/test/doctrine.yaml`, and deterministic test-only values live in
> `tests/bootstrap.php`.

## API

Base URL: `http://localhost:8090`

### `GET /api/v1/rates/last-24h`

Rates for the last 24 hours.

| Query param | Required | Description                          |
|-------------|----------|--------------------------------------|
| `pair`      | yes      | One of `EUR/BTC`, `EUR/ETH`, `EUR/LTC` |

```bash
curl 'http://localhost:8090/api/v1/rates/last-24h?pair=EUR/BTC'
```

Every response — success and error — is wrapped in a consistent, signed envelope
(`id`, `status`, `version`, `datetime`, then `data` or `error`, and `security`):

```json
{
  "id": "01JZ8K3M9QW2T6V0R7Y5N4B8XC",
  "status": "success",
  "version": { "api": "v1", "release": "1.0.0" },
  "datetime": "2026-06-06T15:57:05+00:00",
  "data": {
    "pair": "EUR/BTC",
    "points": [
      { "timestamp": "2026-06-06T15:50:00+00:00", "price": "52878.09" },
      { "timestamp": "2026-06-06T15:55:00+00:00", "price": "52910.42" }
    ]
  },
  "security": {
    "algorithm": "HMAC-SHA256",
    "keyId": "v1",
    "signature": "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08"
  }
}
```

> `price` is a JSON **string** rendered at the pair's display precision (derived
> from the Binance tick size), not the raw 12-decimal stored value. The
> correlation `id` is also returned as the `X-Request-Id` response header, and
> `security.signature` is an HMAC-SHA256 over the canonical JSON of the `data`
> object only — re-encode it with the same rules to verify integrity.

### `GET /api/v1/rates/day`

Rates for a specific UTC day.

| Query param | Required | Description                          |
|-------------|----------|--------------------------------------|
| `pair`      | yes      | One of `EUR/BTC`, `EUR/ETH`, `EUR/LTC` |
| `date`      | yes      | `YYYY-MM-DD` (UTC)                   |

```bash
curl 'http://localhost:8090/api/v1/rates/day?pair=EUR/ETH&date=2026-06-06'
```

### Errors

Invalid input returns `400`; the `error` block carries a human-readable `message`
and a stable machine-readable `code` (`INVALID_PAIR`, `INVALID_DATE`, …):

```bash
curl 'http://localhost:8090/api/v1/rates/last-24h?pair=EUR/DOGE'
```

```json
{
  "id": "01JZ8K3M9QW2T6V0R7Y5N4B8XC",
  "status": "error",
  "version": { "api": "v1", "release": "1.0.0" },
  "datetime": "2026-06-06T15:57:05+00:00",
  "error": {
    "message": "Unsupported currency pair \"EUR/DOGE\". Supported pairs: EUR/BTC, EUR/ETH, EUR/LTC.",
    "code": "INVALID_PAIR"
  },
  "security": { "algorithm": "HMAC-SHA256", "keyId": "v1", "signature": "…" }
}
```

Internal failures never leak details: routing errors keep their status (`404`
`NOT_FOUND`, `405` `METHOD_NOT_ALLOWED`), everything else is a generic `500`
(`INTERNAL_ERROR`, `"Internal server error."`) with the cause logged server-side.

### Interactive docs

Swagger UI: **http://localhost:8090/api/doc** — raw spec at `/api/doc.json`.

A full consumer guide (endpoints, parameters, response/error shapes, precision
notes) lives at [`docs/api/README.md`](docs/api/README.md).

## Useful commands

```bash
# Fetch all pairs once, on demand (also handy as a smoke test)
docker exec paybis-app php bin/console app:rates:fetch

# Apply migrations manually
docker exec paybis-app composer update-db

# Inspect the schedule
docker exec paybis-app php bin/console debug:scheduler
```

## Testing & quality

```bash
docker exec paybis-app composer test        # full Codeception suite (unit + integration)
docker exec paybis-app composer test-unit    # unit suite only
docker exec paybis-app composer phpstan      # static analysis (level 9)
docker exec paybis-app composer cs-check     # PSR-12 lint  (cs-fix to auto-fix)
```

Integration tests run against the `app_test` database (created automatically) and
each test is wrapped in a transaction that is rolled back.

[binance]: https://developers.binance.com/docs/binance-spot-api-docs/rest-api/market-data-endpoints#klinecandlestick-data
[brick/money]: https://github.com/brick/money
