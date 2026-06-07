# Unified Exit Logic & Parallel Strategy Architecture — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract the shared `processExits` loop into `PositionService`, add `strategyId` isolation so multiple trading instances can run in parallel, and introduce `BuyStrategyInterface` for pluggable buy algorithms.

**Architecture:** `PositionService::processExits()` returns an `ExitResult` value object; both `SimulationRunner` and `PaperTradingEngine` become thin wrappers that add only their persistence/event concerns. `SellOrder` gains a `strategyId` field scoped via DB column + repository filter. `BuyStrategyInterface` is extracted; wired via Symfony tagged-locator so `--buy-strategy` selects the implementation at runtime.

**Tech Stack:** PHP 8.4, Symfony 7.3, Doctrine ORM, Codeception 5 (PHPUnit 10), PostgreSQL 17.4. All commands run inside `docker exec -it paybis-app sh`. Tests: `composer test-unit` (fast) and `composer test` (all suites).

**Spec:** `docs/superpowers/specs/2026-03-29-unified-exit-parallel-strategies-design.md`

---

## File Map

| File | Action | Purpose |
|---|---|---|
| `app/src/Domain/Position/ExitResult.php` | **Create** | Value object returned by unified processExits |
| `app/src/Domain/Position/PositionService.php` | **Modify** | Add `processExits()` public method |
| `app/src/Domain/Strategy/BuyStrategyInterface.php` | **Create** | Interface for pluggable buy algorithms |
| `app/src/Domain/Strategy/BuyStrategy.php` | **Modify** | Add `implements BuyStrategyInterface` |
| `app/src/Domain/Simulation/SimulationRunner.php` | **Modify** | Delegate exits; use interface; pass strategyId |
| `app/src/Domain/Simulation/SimulationEngine.php` | **Modify** | Accept + forward `strategyId` to SellOrder |
| `app/src/Application/Service/PaperTradingEngine.php` | **Modify** | Delegate exits; use interface; scope findBy by strategyId |
| `app/src/Application/Config/StrategyConfig.php` | **Modify** | Add `'strategyId' => 'default'` to defaults |
| `app/src/Domain/Position/SellOrder.php` | **Modify** | Add `strategyId` field + getter + reconstitute param |
| `app/src/Infrastructure/InMemory/Repository/InMemorySellOrderRepository.php` | **Modify** | Filter by `strategyId` in `findBy()` |
| `app/src/Infrastructure/Doctrine/Entity/SellOrderDoctrine.php` | **Modify** | Add `strategy_id` ORM column |
| `app/src/Infrastructure/Doctrine/Mapper/SellOrderDoctrineMapper.php` | **Modify** | Map `strategyId` in both directions |
| `app/src/Infrastructure/Doctrine/Repository/SellOrderDoctrineRepository.php` | **Modify** | `findBy()` delegates to Doctrine (already works via parent) |
| `app/src/Infrastructure/Doctrine/Migrations/Version20260329000000.php` | **Create** | Add `strategy_id` column + index |
| `app/src/Application/PaperTrading/Command/PaperTradingTickCommand.php` | **Modify** | Add `strategyId` + `buyStrategy`; fix lock name |
| `app/src/Application/PaperTrading/Handler/PaperTradingTickHandler.php` | **Modify** | Resolve strategy via locator; inject strategyId |
| `app/src/Infrastructure/Console/PaperTradeCommand.php` | **Modify** | Add `--strategy-id` + `--buy-strategy` CLI options |
| `app/config/services.yaml` | **Modify** | BuyStrategyInterface alias + tagged locator |
| `app/tests/Unit/Domain/Position/PositionServiceTest.php` | **Modify** | Add `processExits()` tests |
| `app/tests/Unit/Infrastructure/InMemory/Repository/InMemorySellOrderRepositoryTest.php` | **Modify** | Add `strategyId` filter test |
| `app/tests/Unit/Application/Service/PaperTradingEngineTest.php` | **Modify** | Use interface mock; add strategyId to makeOrder |

---

## Task 1: Create `ExitResult` value object

**Files:**
- Create: `app/src/Domain/Position/ExitResult.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Position;

readonly class ExitResult
{
    /**
     * @param SellOrder[]                                                          $closedOrders
     * @param array<string, array{price: float, reason: string, type: ExitType}>  $closedExits  keyed by UUID string
     * @param SellOrder[]                                                          $remainingOrders
     * @param SellOrder[]                                                          $breakevenUpdates
     */
    public function __construct(
        public bool $stopFired,
        public array $closedOrders,
        public array $closedExits,
        public array $remainingOrders,
        public array $breakevenUpdates,
    ) {
    }
}
```

- [ ] **Step 2: Verify syntax**

```bash
composer cs-check
```

Expected: no errors in `ExitResult.php`

- [ ] **Step 3: Commit**

```bash
git add app/src/Domain/Position/ExitResult.php
git commit -m "feat: add ExitResult value object for unified processExits return"
```

---

## Task 2: Add `PositionService::processExits()` + tests

**Files:**
- Modify: `app/src/Domain/Position/PositionService.php`
- Modify: `app/tests/Unit/Domain/Position/PositionServiceTest.php`

- [ ] **Step 1: Write failing tests** — append to `PositionServiceTest.php`

The existing `makeOrder()` helper in `PositionServiceTest` does not pass `strategyId` (it will default to `'default'` after Task 6). For now, `processExits()` tests can use orders without strategyId.

Add these test methods to the `PositionServiceTest` class:

```php
public function testProcessExitsStopFiredAndOrderClosed(): void
{
    $order    = $this->makeOrder(1.0, 0.95);
    $orderId  = $order->getId()->toRfc4122();
    $candle   = ['low' => 0.90, 'high' => 0.98, 'close' => 0.94];
    $stats    = new SimulationStats();
    $account  = $this->makeAccount();
    $now      = (new \DateTimeImmutable())->setTimestamp(1_003_600);
    $close    = (new \DateTimeImmutable())->setTimestamp(1_007_199);

    $result = $this->service->processExits(
        openOrders:        [$order],
        preRatchetStops:   [$orderId => 0.95],
        candle:            $candle,
        currentTime:       $now,
        closeTime:         $close,
        interval:          Interval::H1,
        maxHoldingCandles: 24,
        stats:             $stats,
        account:           $account,
    );

    $this->assertTrue($result->stopFired);
    $this->assertCount(1, $result->closedOrders);
    $this->assertSame(ExitType::Stop, $result->closedExits[$orderId]['type']);
    $this->assertEqualsWithDelta(0.95, $result->closedExits[$orderId]['price'], 0.001);
    $this->assertSame('Stop-Loss', $result->closedExits[$orderId]['reason']);
    $this->assertEmpty($result->remainingOrders);
    $this->assertSame(1, $stats->sells);
    $this->assertTrue($order->isClosed());
}

public function testProcessExitsNoExitLeavesOrderInRemaining(): void
{
    $order  = $this->makeOrder(1.0, 0.95);
    $candle = ['low' => 0.96, 'high' => 1.05, 'close' => 1.00];
    $stats  = new SimulationStats();
    $now    = (new \DateTimeImmutable())->setTimestamp(1_001_800);
    $close  = (new \DateTimeImmutable())->setTimestamp(1_005_399);

    $result = $this->service->processExits(
        openOrders:        [$order],
        preRatchetStops:   [],
        candle:            $candle,
        currentTime:       $now,
        closeTime:         $close,
        interval:          Interval::H1,
        maxHoldingCandles: 24,
        stats:             $stats,
        account:           $this->makeAccount(),
    );

    $this->assertFalse($result->stopFired);
    $this->assertEmpty($result->closedOrders);
    $this->assertCount(1, $result->remainingOrders);
    $this->assertSame($order, $result->remainingOrders[0]);
    $this->assertSame(0, $stats->sells);
}

public function testProcessExitsTier1TpMovesSimblingsToBreakeven(): void
{
    $groupId = Uuid::v7();
    $tier1   = $this->makeOrder(1.0, 0.90, 1, $groupId); // TP at 1.10, low enough to be hit
    $tier2   = $this->makeOrder(1.0, 0.90, 2, $groupId); // sibling
    $candle  = ['low' => 1.05, 'high' => 1.15, 'close' => 1.12];
    $now     = new \DateTimeImmutable();
    $close   = new \DateTimeImmutable();

    $result = $this->service->processExits(
        openOrders:        [$tier1, $tier2],
        preRatchetStops:   [],
        candle:            $candle,
        currentTime:       $now,
        closeTime:         $close,
        interval:          Interval::H1,
        maxHoldingCandles: 24,
        stats:             new SimulationStats(),
        account:           $this->makeAccount(),
    );

    // tier1 closed, tier2 still open but moved to breakeven
    $this->assertCount(1, $result->closedOrders);
    $this->assertSame($tier1, $result->closedOrders[0]);
    $this->assertCount(1, $result->remainingOrders);
    $this->assertCount(1, $result->breakevenUpdates);
    $this->assertSame($tier2, $result->breakevenUpdates[0]);
    $this->assertEqualsWithDelta(1.0, $tier2->getStopLossPrice(), 0.001); // moved to buyPrice
}

public function testProcessExitsEventInjectedWithOrderId(): void
{
    $order   = $this->makeOrder(1.0, 0.95);
    $orderId = $order->getId()->toRfc4122();
    $candle  = ['low' => 0.90, 'high' => 0.98, 'close' => 0.94];
    $events  = [];
    $onEvent = static function (string $t, array $d) use (&$events): void {
        $events[] = ['type' => $t, 'data' => $d];
    };

    $this->service->processExits(
        openOrders:        [$order],
        preRatchetStops:   [$orderId => 0.95],
        candle:            $candle,
        currentTime:       (new \DateTimeImmutable())->setTimestamp(1_003_600),
        closeTime:         (new \DateTimeImmutable())->setTimestamp(1_007_199),
        interval:          Interval::H1,
        maxHoldingCandles: 24,
        stats:             new SimulationStats(),
        account:           $this->makeAccount(),
        onEvent:           $onEvent,
    );

    $this->assertCount(1, $events);
    $this->assertSame('SELL', $events[0]['type']);
    $this->assertArrayHasKey('id', $events[0]['data']);
    $this->assertSame($orderId, $events[0]['data']['id']);
}
```

Also add the missing `use` statements at the top of `PositionServiceTest.php`:
```php
use App\Domain\Position\ExitType;
use App\Domain\Interval;
```
(`Uuid` and `SimulationStats` are already imported.)

- [ ] **Step 2: Run tests to verify they fail**

```bash
composer test-unit -- --filter PositionServiceTest
```

Expected: 4 new tests fail with `Call to undefined method processExits`

- [ ] **Step 3: Implement `processExits()` in `PositionService`**

Add these `use` statements to `PositionService.php`:
```php
use App\Domain\Interval;   // already present via existing methods
// ExitResult and ExitType are in same namespace — no use needed
```

Add the method to `PositionService`:

```php
/**
 * Unified exit loop. Handles checkExit → Tier-1 TP breakeven → settle for all open orders.
 * Returns ExitResult; callers handle repository persistence and result-DTO population.
 *
 * @param SellOrder[]         $openOrders
 * @param array<string,float> $preRatchetStops  UUID → stop price before trailing ratchet
 */
public function processExits(
    array $openOrders,
    array $preRatchetStops,
    array $candle,
    \DateTimeImmutable $currentTime,
    \DateTimeImmutable $closeTime,
    Interval $interval,
    int $maxHoldingCandles,
    SimulationStats $stats,
    TradingAccount $account,
    ?callable $onEvent = null,
): ExitResult {
    $stopFired        = false;
    $remaining        = $openOrders;
    $closedOrders     = [];
    $closedExits      = [];
    $breakevenUpdates = [];

    foreach ($openOrders as $order) {
        $orderId      = $order->getId()->toRfc4122();
        $stopOverride = $preRatchetStops[$orderId] ?? null;

        $exit = $this->checkExit($order, $candle, $currentTime, $interval, $maxHoldingCandles, $stopOverride);
        if ($exit === null) {
            continue;
        }

        // Tier-1 TP → move siblings to breakeven (findGroupSiblings called before removal from $remaining)
        if ($order->getTier() === 1 && $exit['type'] === ExitType::TakeProfit) {
            foreach ($this->findGroupSiblings($order, $remaining) as $sib) {
                $this->moveToBreakeven($sib);
                $breakevenUpdates[] = $sib;
            }
        }

        $siblings       = $this->findGroupSiblings($order, $remaining);
        $groupStillOpen = count($siblings) > 0;

        $remaining = array_values(array_filter($remaining, fn($o) => $o !== $order));

        // Inject orderId into every SELL event — generic enrichment useful to all callers
        $wrappedEvent = $onEvent !== null
            ? fn(string $t, array $d) => ($onEvent)($t, ['id' => $orderId, ...$d])
            : null;

        $this->settle(
            order:          $order,
            sellPrice:      $exit['price'],
            reason:         $exit['reason'],
            closeTime:      $closeTime,
            stats:          $stats,
            account:        $account,
            onEvent:        $wrappedEvent,
            groupStillOpen: $groupStillOpen,
        );

        $closedOrders[]        = $order;
        $closedExits[$orderId] = [
            'price'  => $exit['price'],
            'reason' => $exit['reason'],
            'type'   => $exit['type'],
        ];

        if ($exit['type'] === ExitType::Stop) {
            $stopFired = true;
        }
    }

    return new ExitResult($stopFired, $closedOrders, $closedExits, $remaining, $breakevenUpdates);
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
composer test-unit -- --filter PositionServiceTest
```

Expected: all `PositionServiceTest` tests pass (including the 4 new ones)

- [ ] **Step 5: Run full unit suite to check for regressions**

```bash
composer test-unit
```

Expected: all green

- [ ] **Step 6: Commit**

```bash
git add app/src/Domain/Position/PositionService.php \
        app/tests/Unit/Domain/Position/PositionServiceTest.php
git commit -m "feat: add PositionService::processExits() — unified exit loop with ExitResult"
```

---

## Task 3: Refactor `SimulationRunner` to delegate to `processExits()`

**Files:**
- Modify: `app/src/Domain/Simulation/SimulationRunner.php`

- [ ] **Step 1: Replace `processExitsForOrders()` body**

In `SimulationRunner.php`, replace the entire body of `processExitsForOrders()` (lines 398–438) with:

```php
private function processExitsForOrders(
    array $openOrders,
    array $preRatchetStops,
    array $candle,
    \DateTimeImmutable $currentTime,
    Interval $interval,
    int $maxHoldingCandles,
    SimulationStats $stats,
    TradingAccount $account,
    ?callable $onEvent,
    \DateTimeImmutable $closeTime,
): bool {
    $exitResult = $this->positionService->processExits(
        openOrders:        $openOrders,
        preRatchetStops:   $preRatchetStops,
        candle:            $candle,
        currentTime:       $currentTime,
        closeTime:         $closeTime,
        interval:          $interval,
        maxHoldingCandles: $maxHoldingCandles,
        stats:             $stats,
        account:           $account,
        onEvent:           $onEvent,
    );

    foreach ($exitResult->closedOrders as $order) {
        $this->positionRepository->remove($order);
    }

    return $exitResult->stopFired;
}
```

- [ ] **Step 2: Run unit suite**

```bash
composer test-unit
```

Expected: all green (SimulationRunner is exercised indirectly through integration tests; unit test for it should still pass)

- [ ] **Step 3: Commit**

```bash
git add app/src/Domain/Simulation/SimulationRunner.php
git commit -m "refactor: SimulationRunner delegates processExitsForOrders to PositionService"
```

---

## Task 4: Refactor `PaperTradingEngine` to delegate to `processExits()`

**Files:**
- Modify: `app/src/Application/Service/PaperTradingEngine.php`

- [ ] **Step 1: Replace `processExits()` body in `PaperTradingEngine`**

Replace the entire `processExits()` method in `PaperTradingEngine.php` (lines 289–359) with:

```php
/**
 * Process all exit conditions for open positions. Returns true if a stop-loss fired.
 *
 * Wraps onEvent to inject candleTs (paper-trading-specific context).
 * Delegates shared logic to PositionService::processExits().
 * Handles persistence and TickResult::closures population.
 *
 * @param SellOrder[]         $openPositions
 * @param array<string,float> $preRatchetStops UUID → stop before ratchet
 * @param SellOrder[]         $remainingOrders Replaced in-place with post-exit slice
 */
private function processExits(
    array $openPositions,
    array $preRatchetStops,
    array &$remainingOrders,
    array $candle,
    \DateTimeImmutable $currentTime,
    \DateTimeImmutable $closeTime,
    Interval $interval,
    int $maxHoldingCandles,
    TradingAccount $account,
    TickResult $result,
    int $candleTs,
    ?callable $onEvent,
): bool {
    $wrappedEvent = $onEvent !== null
        ? fn(string $t, array $d) => ($onEvent)($t, ['candleTs' => date('c', $candleTs), ...$d])
        : null;

    $exitResult = $this->positionService->processExits(
        openOrders:        $openPositions,
        preRatchetStops:   $preRatchetStops,
        candle:            $candle,
        currentTime:       $currentTime,
        closeTime:         $closeTime,
        interval:          $interval,
        maxHoldingCandles: $maxHoldingCandles,
        stats:             $this->stats,
        account:           $account,
        onEvent:           $wrappedEvent,
    );

    foreach ($exitResult->breakevenUpdates as $sib) {
        $this->sellOrderRepository->save($sib);
    }

    foreach ($exitResult->closedOrders as $order) {
        $orderId = $order->getId()->toRfc4122();
        $this->sellOrderRepository->save($order);
        $result->closures[$orderId] = [
            'price'  => $exitResult->closedExits[$orderId]['price'],
            'reason' => $exitResult->closedExits[$orderId]['reason'],
            // 'type' intentionally omitted — TickResult::closures only exposes price + reason
        ];
    }

    // Replace caller's $remainingOrders so dedup (step 3) and max-positions (step 4) in tick()
    // operate on the post-exit slice.
    $remainingOrders = $exitResult->remainingOrders;

    return $exitResult->stopFired;
}
```

- [ ] **Step 2: Run unit suite — `PaperTradingEngineTest` must still pass**

```bash
composer test-unit -- --filter PaperTradingEngineTest
```

Expected: all green

- [ ] **Step 3: Run full unit suite**

```bash
composer test-unit
```

Expected: all green

- [ ] **Step 4: Commit**

```bash
git add app/src/Application/Service/PaperTradingEngine.php
git commit -m "refactor: PaperTradingEngine delegates processExits to PositionService"
```

---

## Task 5: Introduce `BuyStrategyInterface`

**Files:**
- Create: `app/src/Domain/Strategy/BuyStrategyInterface.php`
- Modify: `app/src/Domain/Strategy/BuyStrategy.php`
- Modify: `app/src/Domain/Simulation/SimulationRunner.php`
- Modify: `app/src/Application/Service/PaperTradingEngine.php`
- Modify: `app/config/services.yaml`
- Modify: `app/tests/Unit/Application/Service/PaperTradingEngineTest.php`

- [ ] **Step 1: Create the interface**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Strategy;

interface BuyStrategyInterface
{
    /** @return array{shouldBuy: bool, confidence: float, reasons: array, components: array} */
    public function shouldBuy(array $candles, float $currentPrice, array $genesArr): array;
}
```

- [ ] **Step 2: Update `BuyStrategy` to implement it**

Change the class declaration in `BuyStrategy.php`:
```php
// Before
class BuyStrategy
// After
class BuyStrategy implements BuyStrategyInterface
```

- [ ] **Step 3: Update `SimulationRunner` constructor type-hint**

In `SimulationRunner.php`, change:
```php
// Before
private readonly BuyStrategy $buyStrategy,
// After
private readonly BuyStrategyInterface $buyStrategy,
```

Add import if not already present (it's in same namespace hierarchy — check):
```php
use App\Domain\Strategy\BuyStrategyInterface;
```
(Remove `use App\Domain\Strategy\BuyStrategy;` if present)

- [ ] **Step 4: Update `PaperTradingEngine` constructor type-hint**

In `PaperTradingEngine.php`, change:
```php
// Before
private readonly BuyStrategy $buyStrategy,
// After
private readonly BuyStrategyInterface $buyStrategy,
```

Update imports accordingly.

- [ ] **Step 5: Add DI alias to `services.yaml`**

Add after the existing `SimulationRunner` entry:

```yaml
    # BuyStrategyInterface alias — allows SimulationRunner + other consumers to be autowired
    App\Domain\Strategy\BuyStrategyInterface: '@App\Domain\Strategy\BuyStrategy'
```

- [ ] **Step 6: Update `PaperTradingEngineTest` to mock the interface**

In `PaperTradingEngineTest.php`:

Change the class-level property declaration:
```php
// Before
private BuyStrategy $buyStrategy;
// After
private BuyStrategyInterface $buyStrategy;
```

Add import:
```php
use App\Domain\Strategy\BuyStrategyInterface;
```

Change `createMock` calls for `$this->buyStrategy`:
```php
// Before (in setUp)
$this->buyStrategy = $this->createMock(BuyStrategy::class);
// After
$this->buyStrategy = $this->createMock(BuyStrategyInterface::class);
```

Also update the two inline engines that create `BuyStrategy` mocks directly:
```php
// In testBuySignalProducesNewPositions, testBuyUpdatesStatsTotalInvested, testStopCooldownBlocksReEntry, testResetClearsStats
$buyStrategy = $this->createMock(BuyStrategyInterface::class);
```

- [ ] **Step 7: Run unit suite**

```bash
composer test-unit
```

Expected: all green

- [ ] **Step 8: Commit**

```bash
git add app/src/Domain/Strategy/BuyStrategyInterface.php \
        app/src/Domain/Strategy/BuyStrategy.php \
        app/src/Domain/Simulation/SimulationRunner.php \
        app/src/Application/Service/PaperTradingEngine.php \
        app/config/services.yaml \
        app/tests/Unit/Application/Service/PaperTradingEngineTest.php
git commit -m "feat: introduce BuyStrategyInterface for pluggable buy algorithms"
```

---

## Task 6: Add `strategyId` to `SellOrder`, `StrategyConfig`, and `SimulationEngine`

**Files:**
- Modify: `app/src/Domain/Position/SellOrder.php`
- Modify: `app/src/Application/Config/StrategyConfig.php`
- Modify: `app/src/Domain/Simulation/SimulationEngine.php`
- Modify: `app/src/Domain/Simulation/SimulationRunner.php`
- Modify: `app/tests/Unit/Domain/Position/PositionServiceTest.php`
- Modify: `app/tests/Unit/Application/Service/PaperTradingEngineTest.php`
- Modify: `app/tests/Unit/Infrastructure/InMemory/Repository/InMemorySellOrderRepositoryTest.php`

- [ ] **Step 1: Add `strategyId` to `SellOrder` constructor and `reconstitute()`**

In `SellOrder.php`, add `strategyId` parameter with default `'default'` (keeps existing tests working before updating them):

```php
public function __construct(
    private readonly string $symbol,
    private readonly float $buyPrice,
    private readonly int $count,
    private float $stopLossPrice,
    private readonly float $investment,
    private readonly float $sellPrice,
    private readonly int $tier,
    private readonly \DateTimeImmutable $entryTime,
    ?Uuid $id = null,
    ?Uuid $positionGroupId = null,
    private readonly string $strategyId = 'default',  // add here, after optional params
) {
    $this->id              = $id ?? Uuid::v7();
    $this->positionGroupId = $positionGroupId ?? Uuid::v7();
}
```

Add `getStrategyId()` getter:
```php
public function getStrategyId(): string
{
    return $this->strategyId;
}
```

Update `reconstitute()` — add `strategyId` parameter and pass it through:
```php
public static function reconstitute(
    Uuid $id,
    Uuid $positionGroupId,
    string $symbol,
    float $buyPrice,
    int $count,
    float $stopLossPrice,
    float $investment,
    float $sellPrice,
    int $tier,
    \DateTimeImmutable $entryTime,
    OrderStatus $status,
    ?float $closePrice,
    ?string $closeReason,
    ?\DateTimeImmutable $closedAt,
    string $strategyId = 'default',  // add at end with default
): self {
    $order = new self(
        symbol:          $symbol,
        buyPrice:        $buyPrice,
        count:           $count,
        stopLossPrice:   $stopLossPrice,
        investment:      $investment,
        sellPrice:       $sellPrice,
        tier:            $tier,
        entryTime:       $entryTime,
        id:              $id,
        positionGroupId: $positionGroupId,
        strategyId:      $strategyId,   // add here
    );
    // ... rest unchanged
```

- [ ] **Step 2: Add `'strategyId'` to `StrategyConfig::defaults()`**

In `StrategyConfig.php`, add to `defaults()`:
```php
'strategyId' => 'default',
```

- [ ] **Step 3: Update `SimulationEngine::sellOrdersFromExitPlan()`**

Add `string $strategyId = 'default'` parameter and pass it to each `new SellOrder(...)`:

```php
public function sellOrdersFromExitPlan(
    ExitPlan $plan,
    float $entryPrice,
    float $feeMultiplier,
    int $buyQty,
    \DateTimeImmutable $entryTime = new \DateTimeImmutable(),
    string $symbol = '',
    string $strategyId = 'default',   // add here
): array {
    // ...
    // In the TrailingOnly branch, add strategyId to new SellOrder():
    new SellOrder(
        symbol:          $symbol,
        buyPrice:        $entryPrice,
        count:           $buyQty,
        stopLossPrice:   $plan->initialStop,
        investment:      $this->calculateTotalInvestment($entryPrice, $buyQty, $feeMultiplier),
        sellPrice:       PHP_FLOAT_MAX,
        tier:            1,
        entryTime:       $entryTime,
        positionGroupId: $groupId,
        strategyId:      $strategyId,   // add here
    ),
    // In the AtrTiers branch, add strategyId to each new SellOrder():
    $orders[] = new SellOrder(
        symbol:          $symbol,
        // ... existing params ...
        positionGroupId: $groupId,
        strategyId:      $strategyId,   // add here
    );
```

- [ ] **Step 4: Update `SimulationRunner::executeBuy()` to pass `strategyId`**

In `SimulationRunner.php`, `executeBuy()` already has `StrategyConfig $config`. Update the `sellOrdersFromExitPlan()` call:

```php
return $this->simulationEngine->sellOrdersFromExitPlan(
    $exitPlan,
    $currentPrice,
    $account->feeMultiplier(),
    $buyQtyBySignal,
    $openTime,
    $config->get('symbol'),
    $config->get('strategyId'),   // add this argument
);
```

- [ ] **Step 5: Run unit suite — all tests should still pass**

```bash
composer test-unit
```

Expected: all green (strategyId defaults to `'default'` everywhere, no test breakage)

- [ ] **Step 6: Commit**

```bash
git add app/src/Domain/Position/SellOrder.php \
        app/src/Application/Config/StrategyConfig.php \
        app/src/Domain/Simulation/SimulationEngine.php \
        app/src/Domain/Simulation/SimulationRunner.php
git commit -m "feat: add strategyId to SellOrder and StrategyConfig; forward through SimulationEngine"
```

---

## Task 7: Repository `strategyId` filtering + `PaperTradingEngine::tick()` query

**Files:**
- Modify: `app/src/Infrastructure/InMemory/Repository/InMemorySellOrderRepository.php`
- Modify: `app/src/Application/Service/PaperTradingEngine.php`
- Modify: `app/tests/Unit/Infrastructure/InMemory/Repository/InMemorySellOrderRepositoryTest.php`

- [ ] **Step 1: Write failing test for `strategyId` filter**

Add to `InMemorySellOrderRepositoryTest`:

```php
public function testFindByFiltersOnStrategyId(): void
{
    $repo = new InMemorySellOrderRepository();

    $orderA = new SellOrder(
        symbol: 'BTCUSDT', buyPrice: 90000.0, count: 1,
        stopLossPrice: 88000.0, investment: 90000.0, sellPrice: 95000.0,
        tier: 1, entryTime: (new \DateTimeImmutable())->setTimestamp(1000000),
        strategyId: 'btc-aggressive',
    );
    $orderB = new SellOrder(
        symbol: 'BTCUSDT', buyPrice: 90000.0, count: 1,
        stopLossPrice: 88000.0, investment: 90000.0, sellPrice: 95000.0,
        tier: 1, entryTime: (new \DateTimeImmutable())->setTimestamp(1000000),
        strategyId: 'btc-default',
    );
    $repo->save($orderA);
    $repo->save($orderB);

    $result = $repo->findBy(['strategyId' => 'btc-aggressive']);

    $this->assertCount(1, $result);
    $this->assertSame($orderA, $result[0]);
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
composer test-unit -- --filter InMemorySellOrderRepositoryTest
```

Expected: `testFindByFiltersOnStrategyId` fails — `strategyId` filter not applied

- [ ] **Step 3: Add `strategyId` filtering to `InMemorySellOrderRepository::findBy()`**

```php
public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
{
    $result = [];
    foreach ($this->orders as $order) {
        if (isset($criteria['symbol']) && $order->getSymbol() !== $criteria['symbol']) {
            continue;
        }
        if (isset($criteria['status']) && $order->getStatus()->value !== $criteria['status']) {
            continue;
        }
        if (isset($criteria['strategyId']) && $order->getStrategyId() !== $criteria['strategyId']) {
            continue;
        }
        $result[] = $order;
    }
    return $result;
}
```

- [ ] **Step 4: Update `PaperTradingEngine::tick()` open-position query**

In `PaperTradingEngine.php`, change the `findBy()` call (around line 84):

```php
// Before
$openPositions = $this->sellOrderRepository->findBy([
    'symbol' => $config->get('symbol'),
    'status' => OrderStatus::Open->value,
]);
// After
$openPositions = $this->sellOrderRepository->findBy([
    'strategyId' => $config->get('strategyId'),
    'symbol'     => $config->get('symbol'),
    'status'     => OrderStatus::Open->value,
]);
```

- [ ] **Step 5: Run unit suite**

```bash
composer test-unit
```

Expected: all green

- [ ] **Step 6: Commit**

```bash
git add app/src/Infrastructure/InMemory/Repository/InMemorySellOrderRepository.php \
        app/src/Application/Service/PaperTradingEngine.php \
        app/tests/Unit/Infrastructure/InMemory/Repository/InMemorySellOrderRepositoryTest.php
git commit -m "feat: add strategyId filtering to InMemorySellOrderRepository and PaperTradingEngine"
```

---

## Task 8: Doctrine layer — entity, mapper, repository, migration

**Files:**
- Modify: `app/src/Infrastructure/Doctrine/Entity/SellOrderDoctrine.php`
- Modify: `app/src/Infrastructure/Doctrine/Mapper/SellOrderDoctrineMapper.php`
- Create: `app/src/Infrastructure/Doctrine/Migrations/Version20260329000000.php`

Note: `SellOrderDoctrineRepository::findBy()` delegates to `parent::findBy()` which uses Doctrine field names; Doctrine maps `strategyId` → `strategy_id` column automatically once the entity is updated.

- [ ] **Step 1: Add `strategy_id` column to `SellOrderDoctrine` entity**

Add to `SellOrderDoctrine.php`:
```php
#[ORM\Column(type: 'text', name: 'strategy_id', options: ['default' => 'default'])]
private string $strategyId = 'default';
```

Add getter and setter:
```php
public function getStrategyId(): string
{
    return $this->strategyId;
}

public function setStrategyId(string $strategyId): self
{
    $this->strategyId = $strategyId;
    return $this;
}
```

- [ ] **Step 2: Update `SellOrderDoctrineMapper`**

In `toDoctrineEntity()`, add:
```php
->setStrategyId($order->getStrategyId())
```

In `toDomainEntity()`, add `strategyId` to the `reconstitute()` call:
```php
return SellOrder::reconstitute(
    id:             $doctrine->getId(),
    positionGroupId: $doctrine->getPositionGroupId(),
    symbol:         $doctrine->getSymbol(),
    buyPrice:       $doctrine->getBuyPrice(),
    count:          $doctrine->getCount(),
    stopLossPrice:  $doctrine->getStopLossPrice(),
    investment:     $doctrine->getInvestment(),
    sellPrice:      $doctrine->getSellPrice(),
    tier:           $doctrine->getTier(),
    entryTime:      $doctrine->getEntryTime(),
    status:         OrderStatus::from($doctrine->getStatus()),
    closePrice:     $doctrine->getClosePrice(),
    closeReason:    $doctrine->getCloseReason(),
    closedAt:       $doctrine->getClosedAt(),
    strategyId:     $doctrine->getStrategyId(),   // add here
);
```

- [ ] **Step 3: Create migration**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260329000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add strategy_id column and index to sell_order for parallel strategy isolation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE sell_order ADD COLUMN strategy_id TEXT NOT NULL DEFAULT 'default'");
        $this->addSql('CREATE INDEX idx_sell_order_strategy ON sell_order (strategy_id, symbol, status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_sell_order_strategy');
        $this->addSql('ALTER TABLE sell_order DROP COLUMN strategy_id');
    }
}
```

- [ ] **Step 4: Run migration (inside container)**

```bash
composer update-db
```

Expected: migration runs without error; `strategy_id` column and index created.

- [ ] **Step 5: Run full test suite**

```bash
composer test
```

Expected: all green

- [ ] **Step 6: Commit**

```bash
git add app/src/Infrastructure/Doctrine/Entity/SellOrderDoctrine.php \
        app/src/Infrastructure/Doctrine/Mapper/SellOrderDoctrineMapper.php \
        app/src/Infrastructure/Doctrine/Migrations/Version20260329000000.php
git commit -m "feat: add strategy_id to sell_order Doctrine entity and migration"
```

---

## Task 9: Console command pipeline — `--strategy-id`, `--buy-strategy`, lock isolation

**Files:**
- Modify: `app/src/Application/PaperTrading/Command/PaperTradingTickCommand.php`
- Modify: `app/src/Application/PaperTrading/Handler/PaperTradingTickHandler.php`
- Modify: `app/src/Infrastructure/Console/PaperTradeCommand.php`
- Modify: `app/config/services.yaml`

- [ ] **Step 1: Update `PaperTradingTickCommand`**

Add `strategyId` and `buyStrategy` fields; fix `getLockName()`:

```php
final class PaperTradingTickCommand implements LockableMessage
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $genesFile,
        public readonly string $strategyId = 'default',
        public readonly string $buyStrategy = 'default',
    ) {
        if (trim($symbol) === '') {
            throw new \InvalidArgumentException('symbol must not be empty');
        }
        if (trim($genesFile) === '') {
            throw new \InvalidArgumentException('genesFile must not be empty');
        }
        if (trim($strategyId) === '') {
            throw new \InvalidArgumentException('strategyId must not be empty');
        }
    }

    public function getLockName(): string
    {
        return 'paper-trade-' . $this->strategyId;
    }
}
```

- [ ] **Step 2: Update `PaperTradingTickHandler`**

The handler currently autowires `PaperTradingEngine $engine`. After this change, it injects a service locator for buy strategies and constructs `PaperTradingEngine` manually:

```php
#[AsMessageHandler]
final class PaperTradingTickHandler
{
    private const int CANDLE_FETCH_COUNT = 252;
    private const int REQUIRED_CANDLES   = 251;

    public function __construct(
        private readonly JsonGeneStorage $geneStorage,
        private readonly CandleProviderFactory $candleProviderFactory,
        private readonly \Psr\Container\ContainerInterface $buyStrategies,
        // All PaperTradingEngine dependencies for manual construction:
        private readonly \App\Domain\Technical\TechnicalAnalysisService $taService,
        private readonly \App\Domain\Simulation\SimulationEngine $simulationEngine,
        private readonly \App\Application\Service\PositionSizingService $positionSizer,
        private readonly \App\Domain\Regime\RegimeClassifierAtrNormalized $regimeClassifier,
        private readonly \App\Domain\Regime\RegimePolicy $regimePolicy,
        private readonly \App\Domain\Simulation\SimulationRunner $simulationRunner,
        private readonly \App\Domain\Position\SellOrderRepositoryInterface $sellOrderRepository,
        private readonly \App\Domain\Position\PositionService $positionService,
    ) {
    }

    public function __invoke(PaperTradingTickCommand $command): PaperTradingTickResult
    {
        // A. Load and validate genes
        $genesData = $this->geneStorage->load($command->genesFile);
        if ($genesData === null) {
            return PaperTradingTickResult::skip('genes_file_not_found', ['path' => $command->genesFile]);
        }

        // B. Collect WARN for missing genes
        $collectedEvents = [];
        if (!empty($genesData['missingGenes'])) {
            $collectedEvents[] = ['type' => 'WARN', 'data' => [
                'reason'       => 'genes_file_missing_genes',
                'missingGenes' => $genesData['missingGenes'],
                'note'         => 'default_genes.json defaults will be used for missing genes',
            ]];
        }

        // C. Build StrategyConfig — inject symbol and strategyId as static params
        $config = StrategyConfig::createDefault()
            ->withOverrides($genesData['genes'])
            ->withParams([
                'symbol'     => $command->symbol,
                'strategyId' => $command->strategyId,
            ]);

        // D. Fetch live candles
        $raw = array_values(iterator_to_array(
            $this->candleProviderFactory->create('live')
                ->getCandles($command->symbol, '1h', self::CANDLE_FETCH_COUNT)
        ));

        if (count($raw) < self::REQUIRED_CANDLES) {
            return PaperTradingTickResult::skip('insufficient_candles', [
                'got'      => count($raw),
                'required' => self::REQUIRED_CANDLES,
            ]);
        }

        // E. Resolve buy strategy and construct engine
        /** @var \App\Domain\Strategy\BuyStrategyInterface $buyStrategy */
        $buyStrategy = $this->buyStrategies->get($command->buyStrategy);

        $engine = new \App\Application\Service\PaperTradingEngine(
            $buyStrategy,
            $this->taService,
            $this->simulationEngine,
            $this->positionSizer,
            $this->regimeClassifier,
            $this->regimePolicy,
            $this->simulationRunner,
            $this->sellOrderRepository,
            $this->positionService,
        );

        // F. Slice candle window
        $candles       = array_slice($raw, -self::REQUIRED_CANDLES);
        $currentCandle = $candles[self::REQUIRED_CANDLES - 1];

        // G. Collect engine events
        $onEvent = static function (string $type, array $data) use (&$collectedEvents): void {
            $collectedEvents[] = ['type' => $type, 'data' => $data];
        };

        // H. Execute tick
        $tick = $engine->tick($config, $candles, onEvent: $onEvent);

        return new PaperTradingTickResult(
            tick:            $tick,
            collectedEvents: $collectedEvents,
            currentCandle:   $currentCandle,
            genesUpdatedAt:  $genesData['updatedAt'] ?? null,
            genesFitness:    isset($genesData['fitness']) ? (float)$genesData['fitness'] : null,
            genesPF:         isset($genesData['profitFactor']) ? (float)$genesData['profitFactor'] : null,
        );
    }
}
```

Note: `$config->withParams(['symbol' => $command->symbol])` sets the symbol on the config. The existing handler didn't set `symbol` explicitly (it was using the default `DOGEUSDT`). Confirm this is intentional — the symbol is now explicitly passed from the command.

- [ ] **Step 3: Update `PaperTradeCommand`**

Add `--strategy-id` and `--buy-strategy` options:

```php
protected function configure(): void
{
    $this
        ->addOption('genes-file',   null, InputOption::VALUE_OPTIONAL, 'Path to genes JSON file', self::GENES_FILE)
        ->addOption('symbol',       null, InputOption::VALUE_OPTIONAL, 'Trading symbol', 'DOGEUSDT')
        ->addOption('strategy-id',  null, InputOption::VALUE_OPTIONAL, 'Unique strategy instance ID (default: {symbol}-{genesFile})')
        ->addOption('buy-strategy', null, InputOption::VALUE_OPTIONAL, 'Buy strategy alias (default: default)', 'default');
}

protected function execute(InputInterface $input, OutputInterface $output): int
{
    $genesFile  = (string)$input->getOption('genes-file');
    $symbol     = (string)$input->getOption('symbol');
    $strategyId = (string)($input->getOption('strategy-id') ?? "{$symbol}-{$genesFile}");
    $buyStrategy = (string)$input->getOption('buy-strategy');

    try {
        /** @var PaperTradingTickResult $result */
        $result = $this->handle(new PaperTradingTickCommand($symbol, $genesFile, $strategyId, $buyStrategy));
        $this->renderTickResult($result);
        return Command::SUCCESS;
    } catch (AlreadyRunningException) {
        $this->logger->info('SKIP', ['reason' => 'already_running']);
        return Command::SUCCESS;
    } catch (\Throwable $e) {
        $this->logger->error($e->getMessage());
        return Command::FAILURE;
    }
}
```

- [ ] **Step 4: Update `services.yaml` — add tagged locator for buy strategies**

Add after the existing `BuyStrategyInterface` alias:

```yaml
    # Buy strategy tagged locator — inject into PaperTradingTickHandler
    App\Domain\Strategy\BuyStrategy:
        tags: [{ name: buy_strategy, key: default }]

    App\Application\PaperTrading\Handler\PaperTradingTickHandler:
        arguments:
            $buyStrategies: !tagged_locator { tag: buy_strategy, index_by: key }
```

- [ ] **Step 5: Run full test suite**

```bash
composer test
```

Expected: all green

- [ ] **Step 6: Verify parallel isolation manually (inside container)**

```bash
php -d xdebug.mode=off bin/console app:paper-trade --symbol=DOGEUSDT --strategy-id=doge-a
php -d xdebug.mode=off bin/console app:paper-trade --symbol=DOGEUSDT --strategy-id=doge-b
```

Both should run without interference. Check DB:
```sql
SELECT strategy_id, count(*) FROM sell_order WHERE symbol='DOGEUSDT' GROUP BY strategy_id;
```

- [ ] **Step 7: Run static analysis**

```bash
composer phpstan
```

Expected: no new errors

- [ ] **Step 8: Commit**

```bash
git add app/src/Application/PaperTrading/Command/PaperTradingTickCommand.php \
        app/src/Application/PaperTrading/Handler/PaperTradingTickHandler.php \
        app/src/Infrastructure/Console/PaperTradeCommand.php \
        app/config/services.yaml
git commit -m "feat: add --strategy-id and --buy-strategy to paper-trade; per-instance lock isolation"
```

---

## Final Verification

- [ ] **Run full test suite one last time**

```bash
composer test
```

Expected: all green

- [ ] **Run simulation to confirm GA results unchanged**

```bash
php -d xdebug.mode=off bin/console app:short-strategy-simulation
```

Expected: same results as before (strategyId defaults to `'default'` for simulation)

- [ ] **Run PHPStan**

```bash
composer phpstan
```

Expected: no new errors
