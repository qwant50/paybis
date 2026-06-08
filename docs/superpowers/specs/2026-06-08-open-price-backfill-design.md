# Open-price price points + multi-candle backfill

**Date:** 2026-06-08
**Branch:** feature/ASP-3
**Status:** Approved (design)

## Summary

Two coupled changes to how Binance market data is fetched and stored:

1. **Store the open price instead of the close price** of each 5-minute candle.
2. **Store the last `N` candles per run** (not just the latest), so a scheduler/worker
   that misses runs self-heals by backfilling the missed 5-minute slots on its next run.

No database schema change and no migration: we store a different value from the same
kline row, and `recordedAt` is still the candle's grid-aligned open time.

## Motivation

The current fetch stores one sample per pair per run — the latest **closed** candle's
**close** price, stamped with the candle's open time. Two problems:

- **Gaps on downtime.** If the `scheduler-rates` worker is down or a run fails, the
  5-minute slots that elapsed during the outage are lost forever — the next run only
  stores the single latest candle.
- **Closed-candle complexity.** Close price is only final once a candle closes, so the
  adapter carefully filters out the still-forming last candle and guards against
  interval-boundary clock skew.

### Key insight

A candle's **open** price is final the instant the candle opens — including the
still-forming candle. Switching from close to open price therefore:

- dissolves the entire "is this candle closed?" machinery (no `closeTime < now` filter,
  no boundary-skew buffer);
- lets us keep **every** candle Binance returns, including the forming one, whose
  `(openTime, openPrice)` is already immutable and grid-aligned.

Combined with the existing idempotent `save()` (per `(pair, recorded_at)` slot), fetching
the last `N` candles each run means only genuinely missing slots get inserted; re-fetched
slots are cheap no-ops. A run that follows up to ~`(N-1)*5min` of downtime fills the gap.

## Design

### Backfill window

`BACKFILL_CANDLES = 12` (~1 hour). Fixed class constant on the adapter (not env-configured).
Binance returns the newest candle (the forming one) as the last element, so 12 candles
cover the current slot plus the previous 11.

### Application layer — renamed contract

Reflecting that we no longer deal in "closed candles":

- `App\Application\Service\ClosedCandle` → **`PricePoint`**
  ```php
  final readonly class PricePoint
  {
      public function __construct(
          public string $price,            // decimal string, full precision (candle open)
          public \DateTimeImmutable $time, // UTC, aligned to the 5-minute grid (candle open time)
      ) {}
  }
  ```
  Name is deliberately distinct from the existing wire DTO
  `Infrastructure\Controller\Api\V1\Rate\Response\RatePoint`.

- `App\Application\Service\ClosedCandleProvider` → **`PriceHistoryProvider`**
  ```php
  interface PriceHistoryProvider
  {
      /**
       * Recent grid-aligned price points for a Binance symbol (e.g. "BTCEUR"),
       * ascending by time. Includes the current (still-forming) slot — a candle's
       * open price is immutable from the moment it opens.
       *
       * @return list<PricePoint>
       * @throws \RuntimeException when the request fails or yields no usable points
       */
      public function recentPricePoints(string $symbol): array;
  }
  ```

### Infrastructure — `BinanceService` adapter

- Implements `PriceHistoryProvider`.
- `CANDLE_FETCH_LIMIT 3` → `BACKFILL_CANDLES 12`; `klines(..., limit: self::BACKFILL_CANDLES)`.
- Maps **every** returned row to a `PricePoint` using `openTime` (column 0) and
  **open price (column 1)** — no closed-candle / `closeTime` filtering.
- Skips malformed rows defensively (fewer than 2 columns, or empty open price).
- Throws `RuntimeException` only when the request fails (`ApiException`, wrapped with the
  symbol) or when zero usable points result.
- Docblocks rewritten: the old "last closed candle" / "boundary-skew buffer" rationale is
  removed and replaced with the open-price / backfill rationale. The interval must still
  match the scheduler cadence.

### Domain — `RateRepository::save()` returns `bool`

```php
/**
 * Persist a rate sample. Idempotent per (pair, recorded-at slot): historical prices
 * are never overwritten.
 *
 * @return bool true if a new sample was inserted, false if the slot already existed
 */
public function save(ExchangeRate $exchangeRate): bool;
```

`ExchangeRateRepository::save()` returns `false` on the idempotent-skip path and `true`
after a successful `persist()`/`flush()`. This makes backfilling observable instead of
logging a misleading "stored 12" every run.

### Application — `RateFetcher::fetchAll()`

Two-level failure isolation:

- **Per pair:** a `try` around `recentPricePoints()`; a network/API failure logs and skips
  that pair (counts as one failure), never aborting the others.
- **Per point:** a `try` around `Rate::fromString()` + `save()`; a precision-loss or
  malformed point logs and is skipped without killing the rest of the pair's batch.

```php
foreach (CurrencyPair::all() as $pair) {
    try {
        $points = $this->binance->recentPricePoints($pair->binanceSymbol());
    } catch (\Throwable $e) {
        $failed++; // log pair fetch failure
        continue;
    }
    foreach ($points as $point) {
        try {
            $rate = Rate::fromString($point->price);
            $inserted = $this->repository->save(new ExchangeRate($pair, $rate, $point->time));
            $inserted ? $stored++ : $skipped++;
        } catch (\Throwable $e) {
            $failed++; // log per-point failure
        }
    }
}
```

### Report — `RateFetchReport`

```php
final readonly class RateFetchReport
{
    public function __construct(
        public int $stored,   // new slots inserted
        public int $skipped,  // slots already present (idempotent no-op)
        public int $failed,   // pair-fetch errors + per-point errors
    ) {}

    public function total(): int { return $this->stored + $this->skipped + $this->failed; }
    public function hasFailures(): bool { return $this->failed > 0; }
}
```

`FetchRatesCommand` success line → `"Stored %d new, skipped %d, failed %d."`; still returns
`Command::FAILURE` when `hasFailures()`.

## Files touched

| File | Change |
|------|--------|
| `Application/Service/ClosedCandle.php` | rename → `PricePoint.php` (`price`, `time`) |
| `Application/Service/ClosedCandleProvider.php` | rename → `PriceHistoryProvider.php` (`recentPricePoints(): list<PricePoint>`) |
| `Application/Service/RateFetcher.php` | loop points; two-level isolation; new counters |
| `Application/Service/RateFetchReport.php` | `{stored, skipped, failed}` |
| `Domain/ExchangeRate/RateRepository.php` | `save(): bool` |
| `Infrastructure/Doctrine/Repository/ExchangeRateRepository.php` | `save()` returns bool |
| `Infrastructure/Binance/BinanceService.php` | open price, `BACKFILL_CANDLES`, return list |
| `Infrastructure/Console/FetchRatesCommand.php` | new success message |
| `tests/Unit/Infrastructure/Binance/BinanceServiceTest.php` | rewritten for open price + list |
| `tests/Unit/Application/Service/RateFetcherTest.php` | rewritten for list + stored/skipped/failed + bool save |
| `CLAUDE.md` | update open-price / gap-fill / renamed-class docs |

`FetchRatesMessageHandler` references `RateFetcher` only and needs no change.

## Testing

- **`BinanceServiceTest`:** every returned row maps to a `PricePoint` using the open price
  (column 1), including the still-forming last candle; `row()` helper supplies a real open;
  empty/zero-usable response throws; `ApiException` is wrapped with the symbol.
- **`RateFetcherTest`:** mock `PriceHistoryProvider::recentPricePoints` returning a list;
  assert `stored`/`skipped`/`failed` (including a `save()` returning `false` → `skipped`);
  per-pair isolation (one pair throws) and per-point isolation (one bad price) leave the
  rest intact.
- `composer test`, `composer cs-check`, `composer phpstan` (level 9) all green.

## Out of scope / notes

- No DB migration; the `DECIMAL(30,12)` column and `UNIQUE (pair, recorded_at)` index are
  unchanged.
- After deploy, restart the `scheduler-rates` Supervisor program so the long-running worker
  picks up the new fetch path (it runs old in-memory code otherwise).
- Bulk "which slots already exist" prefetch (one query per pair instead of one `count()` per
  point) is a possible later optimization; at 12 candles × 3 pairs per 5 min it is not worth
  the complexity now (YAGNI).
