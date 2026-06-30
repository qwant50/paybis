# Crypto Exchange Rates API — Consumer Guide

EUR→crypto exchange rates sourced from the Binance Spot API, sampled **once every
5 minutes**, exposed as JSON for charting.

- **Base URL:** `http://localhost:8090`
- **Interactive docs (Swagger UI):** [`/api/doc`](http://localhost:8090/api/doc)
- **Raw OpenAPI spec:** [`/api/doc.json`](http://localhost:8090/api/doc.json)

The OpenAPI spec is the source of truth; this guide is a human-readable companion.

## Conventions

- **Versioning** — endpoints are namespaced under `/api/v1/...`. A new major
  version is introduced as a sibling prefix (`/api/v2/...`) without breaking v1.
- **Times** — every timestamp is **UTC**, ISO-8601 (`2026-06-06T15:52:00+00:00`).
  Day queries use a UTC calendar day `[00:00, next 00:00)`.
- **Prices** — JSON **strings** (never floats), rendered at each pair's *display
  precision* derived from the Binance tick size (e.g. `EUR/BTC` → 2 decimals,
  `52878.09`). Values are stored loss-free as `DECIMAL(30,12)`; the display
  precision is applied only on read.
- **Sampling** — one point per pair per ~5-minute tick; series are chronological.

## Supported pairs

| `pair`    | Description     |
|-----------|-----------------|
| `EUR/BTC` | EUR price of BTC |
| `EUR/ETH` | EUR price of ETH |
| `EUR/LTC` | EUR price of LTC |

## Endpoints

### `GET /api/v1/rates/last-24h`

Rolling window of the last 24 hours.

| Query param | Required | Description                              |
|-------------|----------|------------------------------------------|
| `pair`      | yes      | One of `EUR/BTC`, `EUR/ETH`, `EUR/LTC`   |

```bash
curl 'http://localhost:8090/api/v1/rates/last-24h?pair=EUR/BTC'
```

```json
{
  "id": "01JZ8K3M9QW2T6V0R7Y5N4B8XC",
  "status": "success",
  "version": { "api": "v1", "release": "1.0.0" },
  "datetime": "2026-06-06T15:57:05+00:00",
  "data": {
    "pair": "EUR/BTC",
    "points": [
      { "timestamp": "2026-06-06T15:52:00+00:00", "price": "52878.09" },
      { "timestamp": "2026-06-06T15:57:00+00:00", "price": "52910.42" }
    ]
  },
  "security": {
    "algorithm": "HMAC-SHA256",
    "keyId": "v1",
    "signature": "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08"
  }
}
```

The resource payload is under `data`; see [Response envelope](#response-envelope)
for the wrapper fields.

### `GET /api/v1/rates/day`

A single UTC calendar day.

| Query param | Required | Description                              |
|-------------|----------|------------------------------------------|
| `pair`      | yes      | One of `EUR/BTC`, `EUR/ETH`, `EUR/LTC`   |
| `date`      | yes      | `YYYY-MM-DD` (UTC)                       |

```bash
curl 'http://localhost:8090/api/v1/rates/day?pair=EUR/ETH&date=2026-06-06'
```

The `data` payload is identical in shape to `last-24h`. A day with no samples
returns `"points": []`.

## Response envelope

Every response — success **and** error — shares one top-level shape:

| Field        | Type              | Notes                                                          |
|--------------|-------------------|----------------------------------------------------------------|
| `id`         | string            | Correlation id, also returned as the `X-Request-Id` header     |
| `status`     | string            | `success` or `error`                                           |
| `version`    | object            | `{ "api": "v1", "release": "1.0.0" }` — URL contract + build   |
| `datetime`   | string (date-time)| UTC, ISO-8601 — when the response was produced                 |
| `data`       | object            | Present on success only (see resource schema below)            |
| `error`      | object            | Present on error only — `{ "message": "…", "code": "…" }`      |
| `security`   | object            | `{ "algorithm": "HMAC-SHA256", "keyId": "…", "signature": "…" }`|

`security.signature` is an HMAC-SHA256 over the **canonical JSON of the payload
only** (the `data` object on success, the `error` object on failure) — not the
envelope metadata. Re-encode that sub-object with slashes/unicode unescaped and no
extra whitespace to reproduce and verify the signature; `keyId` names the secret
so keys can be rotated.

### Resource payload (`data`)

| Field                | Type               | Notes                                |
|----------------------|--------------------|--------------------------------------|
| `pair`               | string             | Echoes the requested pair            |
| `points`             | array              | Chronological; empty when no samples |
| `points[].timestamp` | string (date-time) | UTC, ISO-8601                        |
| `points[].price`     | string             | Display precision for the pair       |

## Errors

On failure the envelope's `status` is `error` and the `error` block carries a
human-readable `message` and a stable, machine-readable `code`:

| Status | `code`               | When                          | Example message                                                                 |
|--------|----------------------|-------------------------------|---------------------------------------------------------------------------------|
| `400`  | `INVALID_PAIR`       | Unknown / missing `pair`      | `Unsupported currency pair "EUR/DOGE". Supported pairs: EUR/BTC, EUR/ETH, EUR/LTC.` |
| `400`  | `INVALID_DATE`       | Malformed / missing `date`    | `Invalid date "not-a-date". Expected format YYYY-MM-DD.`                         |
| `404`  | `NOT_FOUND`          | Unknown route                 | `Not Found`                                                                     |
| `405`  | `METHOD_NOT_ALLOWED` | Wrong HTTP method             | `Method Not Allowed`                                                            |
| `500`  | `INTERNAL_ERROR`     | Unexpected server error       | `Internal server error.`                                                        |

```bash
curl -i 'http://localhost:8090/api/v1/rates/last-24h?pair=EUR/DOGE'
# HTTP/1.1 400 Bad Request
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

Internal failures never leak details: only the generic `500` message above is
returned (the cause is logged server-side).
