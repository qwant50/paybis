# Unified Exit Logic & Parallel Strategy Architecture

**Date:** 2026-03-29
**Status:** Approved

---

## Context

`SimulationRunner::processExitsForOrders()` and `PaperTradingEngine::processExits()` implement the same exit loop (check exit → Tier-1 TP breakeven → settle) but have diverged:

| | `SimulationRunner` | `PaperTradingEngine` |
|---|---|---|
| Breakeven siblings | In-memory only | Persisted to repository |
| Closed order | `repository->remove()` | `repository->save()` (closed state) |
| Event callback | Raw `$onEvent` passed through | Wrapped with `id` + `candleTs` |
| Result tracking | None | Populates `TickResult::closures` |

Additionally, paper trading is locked to one strategy instance per symbol. The goal is to support multiple strategies running in parallel as separate processes (e.g., different gene sets on BTCUSDT and ETHUSDT simultaneously), each owning isolated positions.

---

## Part 1: Unified Exit Logic

### New value object: `Domain/Position/ExitResult`

```php
readonly class ExitResult {
    public function __construct(
        public bool $stopFired,
        public array $closedOrders,    // SellOrder[] — settle() already called
        public array $closedExits,     // keyed by order UUID string: ['price','reason','type']
        public array $remainingOrders, // SellOrder[] still open
        public array $breakevenUpdates // SellOrder[] whose stop moved to breakeven (may need persisting)
    ) {}
}
```

`closedExits` is keyed by `$order->getId()->toRfc4122()` — same key as `TickResult::closures` — so callers access exit info by UUID without relying on positional indexing.

### New public method: `PositionService::processExits()`

```php
public function processExits(
    array $openOrders,
    array $preRatchetStops,        // UUID → pre-ratchet stop price
    array $candle,
    \DateTimeImmutable $currentTime,
    \DateTimeImmutable $closeTime,
    Interval $interval,
    int $maxHoldingCandles,
    SimulationStats $stats,
    TradingAccount $account,
    ?callable $onEvent = null,
): ExitResult
```

**Loop internals (shared logic extracted from both callers):**
1. For each open order, call `checkExit()` with `preRatchetStops` override; skip if `null`
2. If Tier-1 TP: call `findGroupSiblings()` on `$remaining` (order is still in it at this point), then `moveToBreakeven()` each sibling; collect them in `$breakevenUpdates`
3. Call `findGroupSiblings()` again on `$remaining` to determine `groupStillOpen`
4. Remove order from `$remaining` (after the `findGroupSiblings()` calls above)
5. Wrap `$onEvent` to inject `orderId` automatically: `fn($t,$d) => ($onEvent)($t, ['id' => $orderId, ...$d])`
6. Call `settle()` with wrapped event
7. Add order to `$closedOrders`; populate `$closedExits[$orderId] = ['price' => $exit['price'], 'reason' => $exit['reason'], 'type' => $exit['type']]`
8. Set `$stopFired = true` on `ExitType::Stop`
9. Return `new ExitResult($stopFired, $closedOrders, $closedExits, $remaining, $breakevenUpdates)`

Note: `ExitResult::closedExits` includes `type` for callers that need it (e.g. stop-cooldown routing). `PaperTradingEngine`'s wrapper intentionally does not copy `type` into `TickResult::closures` — that DTO only exposes `price` and `reason` to upstream consumers.

### SimulationRunner — thin wrapper

`processExitsForOrders()` becomes:

```php
$exitResult = $this->positionService->processExits(
    openOrders: $openOrders, preRatchetStops: $preRatchetStops, candle: $candle,
    currentTime: $currentTime, closeTime: $closeTime, interval: $interval,
    maxHoldingCandles: $maxHoldingCandles, stats: $stats, account: $account, onEvent: $onEvent,
);
foreach ($exitResult->closedOrders as $order) {
    $this->positionRepository->remove($order);
}
return $exitResult->stopFired;
```

### PaperTradingEngine — thin wrapper

`processExits()` injects `candleTs` (paper-trading-specific context) and handles persistence:

```php
$wrappedEvent = $onEvent !== null
    ? fn($t, $d) => ($onEvent)($t, ['candleTs' => date('c', $candleTs), ...$d])
    : null;

$exitResult = $this->positionService->processExits(
    openOrders: $openPositions, preRatchetStops: $preRatchetStops, candle: $candle,
    currentTime: $currentTime, closeTime: $closeTime, interval: $interval,
    maxHoldingCandles: $maxHoldingCandles, stats: $this->stats, account: $account,
    onEvent: $wrappedEvent,
);

foreach ($exitResult->breakevenUpdates as $sib) {
    $this->sellOrderRepository->save($sib);
}
foreach ($exitResult->closedOrders as $order) {
    $orderId = $order->getId()->toRfc4122(); // derive key to look up in closedExits
    $this->sellOrderRepository->save($order);
    $tickResult->closures[$orderId] = [
        'price'  => $exitResult->closedExits[$orderId]['price'],
        'reason' => $exitResult->closedExits[$orderId]['reason'],
        // 'type' intentionally omitted — TickResult::closures only exposes price + reason
    ];
}
// Replace local $remainingOrders so dedup (step 3) and max-positions (step 4) checks
// in tick() operate on the post-exit slice.
$remainingOrders = $exitResult->remainingOrders;
return $exitResult->stopFired;
```

---

## Part 2: Strategy Isolation (Parallel Processes)

### `SellOrder` — add `strategyId`

New `readonly string $strategyId` field, required in constructor and `reconstitute()`. Default `'default'` for simulation (no behaviour change).

```php
public function __construct(
    private readonly string $strategyId,   // new — added first for clarity
    private readonly string $symbol,
    // ... existing fields unchanged
)
```

`getStrategyId(): string` getter added.

### `StrategyConfig` — add `'strategyId'` param

Added to `defaults()` as `'strategyId' => 'default'`. Set via `withParams(['strategyId' => ...])`.

### `SimulationEngine::sellOrdersFromExitPlan()`

Add `string $strategyId` parameter; pass it to every `new SellOrder(strategyId: $strategyId, ...)` call.

### `SimulationRunner::executeBuy()`

`executeBuy()` already receives `StrategyConfig $config`. It reads `$config->get('strategyId')` internally and forwards it to `sellOrdersFromExitPlan()`. No new parameter is added to the `executeBuy()` signature — `$config` already carries everything. Both call sites (`SimulationRunner::run()` and `PaperTradingEngine::tick()`) are unaffected.

### Repository layer

`SellOrderRepositoryInterface::findBy()` already accepts an array filter.

**Paper trading** (`PaperTradingEngine::tick()`): open-position query must include `strategyId`:

```php
$this->sellOrderRepository->findBy([
    'strategyId' => $config->get('strategyId'),
    'symbol'     => $config->get('symbol'),
    'status'     => OrderStatus::Open->value,
]);
```

**Simulation** (`SimulationRunner::run()`): continues using `findBy([])` (no filter). The in-memory repository is `reset()` at the start of each run and holds only one strategy's orders by construction — no `strategyId` filter is needed or appropriate there.

`InMemorySellOrderRepository` — add `strategyId` filtering in `findBy()` so it works correctly when the filter is supplied (paper trading path in integration tests).

### Database migration

New column and index on `sell_order`:

```sql
ALTER TABLE sell_order ADD COLUMN strategy_id TEXT NOT NULL DEFAULT 'default';
CREATE INDEX idx_sell_order_strategy ON sell_order (strategy_id, symbol, status);
```

`SellOrderDoctrine` entity: add `#[ORM\Column(type: 'text', name: 'strategy_id', options: ['default' => 'default'])]`.
`SellOrderDoctrineMapper`: map `strategyId` in both directions.

### `PaperTradeCommand` — new `--strategy-id` option

```
--strategy-id   Unique identifier for this strategy instance.
                Defaults to "{symbol}-{genesFile}" if not specified.
                Used to isolate positions from other parallel instances.
```

`strategyId` must flow through the entire messenger pipeline: `PaperTradeCommand` → `PaperTradingTickCommand` (add `strategyId` field) → `PaperTradingTickHandler` (pass into `StrategyConfig::withParams(['strategyId' => ...])` before calling `tick()`).

### Lock name must be per strategy instance

`PaperTradingTickCommand::getLockName()` currently returns a hardcoded `'paper-trade-lock'`. `SingleInstanceMiddleware` delegates to this method. With parallel instances, each must have its own lock:

```php
// PaperTradingTickCommand::getLockName()
return 'paper-trade-' . $this->strategyId;
```

Without this, two parallel `app:paper-trade` invocations will block each other via the same lock.

Example usage:
```bash
# Two strategies on the same symbol — fully isolated positions
app:paper-trade --symbol=BTCUSDT --genes-file=aggressive --strategy-id=btc-aggressive
app:paper-trade --symbol=BTCUSDT --genes-file=default    --strategy-id=btc-default

# Different symbols
app:paper-trade --symbol=ETHUSDT --genes-file=default    --strategy-id=eth-default
```

---

## Part 3: Pluggable Buy Strategy

### New: `Domain/Strategy/BuyStrategyInterface`

```php
interface BuyStrategyInterface {
    /** @return array{shouldBuy: bool, confidence: float, reasons: array, components: array} */
    public function shouldBuy(array $candles, float $currentPrice, array $genesArr): array;
}
```

`BuyStrategy` adds `implements BuyStrategyInterface` — no logic change.

`SimulationRunner` and `PaperTradingEngine` constructor type-hints change from `BuyStrategy` to `BuyStrategyInterface`.

### Symfony DI wiring

`PaperTradeCommand` accepts `--buy-strategy=default` (default: `'default'`). Resolution uses a Symfony service locator injected into `PaperTradingTickHandler`. A DI alias also allows `SimulationRunner` (and other consumers) to be autowired normally:

```yaml
# services.yaml — alias for autowiring SimulationRunner and other consumers
App\Domain\Strategy\BuyStrategyInterface: '@App\Domain\Strategy\BuyStrategy'
```

```yaml
# services.yaml
App\Domain\Strategy\BuyStrategy:
    tags: [{ name: buy_strategy, key: default }]

App\Application\PaperTrading\Handler\PaperTradingTickHandler:
    arguments:
        $buyStrategies: !tagged_locator { tag: buy_strategy, index_by: key }
```

```php
// PaperTradingTickHandler
public function __construct(
    private readonly ContainerInterface $buyStrategies, // ServiceLocator
    // ... other deps
) {}

// Inside handle():
$strategy = $this->buyStrategies->get($command->buyStrategy); // e.g. 'default'
// Construct PaperTradingEngine with the resolved $strategy
```

`PaperTradingTickCommand` gains a `buyStrategy: string` field (default `'default'`). `PaperTradeCommand` forwards `--buy-strategy` to it.

After this change, `PaperTradingEngine` is **no longer autowired as a constructor argument** of `PaperTradingTickHandler`. The handler constructs it manually inside `handle()` using the resolved `BuyStrategyInterface`. The existing `PaperTradingEngine` entry in `services.yaml` (if any) should be removed or left as-is (Symfony does not complain about unused service definitions). Autowiring of `PaperTradingEngine` into other consumers (if any) remains unaffected.

Adding a second strategy requires only: implement `BuyStrategyInterface`, tag with `key: my-strategy` in `services.yaml`, then pass `--buy-strategy=my-strategy`.

---

## Files Changed

| File | Change |
|---|---|
| `Domain/Position/ExitResult.php` | **New** value object |
| `Domain/Position/PositionService.php` | Add `processExits()` |
| `Domain/Position/SellOrder.php` | Add `strategyId` field + getter |
| `Domain/Strategy/BuyStrategyInterface.php` | **New** interface |
| `Domain/Strategy/BuyStrategy.php` | Add `implements BuyStrategyInterface` |
| `Domain/Simulation/SimulationRunner.php` | Delegate to `processExits()`; use `BuyStrategyInterface`; pass `strategyId` |
| `Domain/Simulation/SimulationEngine.php` | Pass `strategyId` to `SellOrder` constructor |
| `Application/Service/PaperTradingEngine.php` | Delegate to `processExits()`; use `BuyStrategyInterface` |
| `Application/Config/StrategyConfig.php` | Add `strategyId` to defaults |
| `Infrastructure/Doctrine/Entity/SellOrderDoctrine.php` | Add `strategy_id` column |
| `Infrastructure/Doctrine/Mapper/SellOrderDoctrineMapper.php` | Map `strategyId` |
| `Infrastructure/Doctrine/Migrations/Version20260329….php` | **New** migration |
| `Infrastructure/Console/PaperTradeCommand.php` | Add `--strategy-id`, `--buy-strategy` options |
| `Application/PaperTrading/Command/PaperTradingTickCommand.php` | Add `strategyId` + `buyStrategy` fields; update `getLockName()` to return `'paper-trade-' . $this->strategyId` |
| `Application/PaperTrading/Handler/PaperTradingTickHandler.php` | Inject `$buyStrategies` service locator; resolve `BuyStrategyInterface` by alias; pass `strategyId` into `StrategyConfig`; construct `PaperTradingEngine` with resolved strategy instead of autowiring it |
| `Infrastructure/InMemory/Repository/InMemorySellOrderRepository.php` | Filter by `strategyId` in `findBy()` when key present |
| `Infrastructure/Doctrine/Repository/SellOrderDoctrineRepository.php` | Filter by `strategy_id` in open-position queries when key present |
| `config/services.yaml` | Add `BuyStrategyInterface` DI alias + tagged locator wiring for handler |

---

## Verification

```bash
# 1. Unit + integration suites must pass
composer test

# 2. Run migration then simulate — results must match pre-change baseline
composer update-db
php -d xdebug.mode=off bin/console app:short-strategy-simulation

# 3. Single paper trade tick
php -d xdebug.mode=off bin/console app:paper-trade --symbol=DOGEUSDT --strategy-id=doge-default

# 4. Two paper traders on same symbol — verify DB isolation
php -d xdebug.mode=off bin/console app:paper-trade --symbol=DOGEUSDT --strategy-id=doge-a --genes-file=aggressive
php -d xdebug.mode=off bin/console app:paper-trade --symbol=DOGEUSDT --strategy-id=doge-b --genes-file=default
# Confirm isolation:
# SELECT strategy_id, count(*) FROM sell_order WHERE symbol='DOGEUSDT' GROUP BY strategy_id;
```
