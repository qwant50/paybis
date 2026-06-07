# Exit Strategy DDD Refactor Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the ad-hoc `exitMode` string flag and scattered sell-target calculations with a proper `ExitPlan` domain value object and `ExitPlanFactory`, eliminating the structural bug class where `atrStopMultiplier` (a trailing stop gene) was being misused to drive take-profit targets to unreachable heights.

**Architecture:** Introduce `Domain/Position/` as a new bounded context. `ExitMode` (enum), `ExitTarget` (value object), and `ExitPlan` (value object) model the domain concept. `ExitPlanFactory` (domain service) is the single place that produces exit plans — replacing `SimulationEngine::calculateScaledExits()` and the dead `calculateAdaptiveSellPrice` call in `PaperTradingEngine`. ATR-tiers mode uses raw ATR (not `atr × atrStopMultiplier`) so targets are always proportionate to actual market volatility. `SellOrder::stopLossPrice` becomes effectively immutable via a `withStop()` factory method.

**Tech Stack:** PHP 8.4, Codeception 5.0 / PHPUnit 10, Symfony 6.4, Docker container `paybis-app`. All commands run inside the container. Tests: `vendor/bin/codecept run Unit`. Style: `composer cs-check` / `composer cs-fix`.

**Root-cause recap (context for the implementer):**
- `atrStopMultiplier=7.5` is a GA-evolved gene for *trailing stop distance*, not for take-profit targets.
- `SimulationEngine::calculateScaledExits()` computed `riskAmount = entryPrice - stopPrice` where `stopPrice = entry - atr×7.5`, then multiplied by `baseRR=2.0` and tier multipliers `[0.6, 1.0, 1.4]`.
- Result: tier-3 target = `entry + atr × 7.5 × 2.0 × 1.4 = entry + 21×ATR` ≈ 20% above entry on 1h DOGE — unreachable.
- `calculateAdaptiveSellPrice()` had a 15% cap but its output was checked for null and then *discarded*; `calculateScaledExits()` ran independently with no cap.
- Fix already applied: `exitMode = 'trailing-only'` (Option A). This plan adds the *structural* fix so Options B–D cannot regress.

---

## Task 1: `ExitMode` Enum

**Why:** Eliminates the magic string `'trailing-only'` / `'tiers'` spread across `StrategyConfig`, `SimulationEngine`, and `PaperTradingEngine`. PHP enum gives exhaustive `match` and compile-time safety.

**Files:**
- Create: `app/src/Domain/Position/ExitMode.php`
- Create: `app/tests/Unit/Domain/Position/ExitModeTest.php`

**Step 1: Write the failing test**

```php
<?php
// app/tests/Unit/Domain/Position/ExitModeTest.php

namespace Tests\Unit\Domain\Position;

use App\Domain\Position\ExitMode;
use Codeception\Test\Unit;

class ExitModeTest extends Unit
{
    public function testFromStringTrailingOnly(): void
    {
        $this->assertSame(ExitMode::TrailingOnly, ExitMode::from('trailing-only'));
    }

    public function testFromStringAtrTiers(): void
    {
        $this->assertSame(ExitMode::ATRTiers, ExitMode::from('atr-tiers'));
    }

    public function testInvalidStringThrows(): void
    {
        $this->expectException(\ValueError::class);
        ExitMode::from('tiers'); // old string — must no longer be valid
    }

    public function testValueReturnsString(): void
    {
        $this->assertSame('trailing-only', ExitMode::TrailingOnly->value);
        $this->assertSame('atr-tiers', ExitMode::ATRTiers->value);
    }
}
```

**Step 2: Run test — verify it fails**

```bash
vendor/bin/codecept run Unit Domain/Position/ExitModeTest
```

Expected: `FAIL — class not found`

**Step 3: Create `ExitMode` enum**

```php
<?php
// app/src/Domain/Position/ExitMode.php

declare(strict_types=1);

namespace App\Domain\Position;

enum ExitMode: string
{
    case TrailingOnly = 'trailing-only';
    case ATRTiers     = 'atr-tiers';
}
```

**Step 4: Run tests — verify they pass**

```bash
vendor/bin/codecept run Unit Domain/Position/ExitModeTest
```

Expected: `OK (4 tests)`

**Step 5: Commit**

```bash
git add app/src/Domain/Position/ExitMode.php \
        app/tests/Unit/Domain/Position/ExitModeTest.php
git commit -m "feat: add ExitMode enum (TrailingOnly, ATRTiers)"
```

---

## Task 2: `ExitTarget` Value Object

**Why:** Models one take-profit tier as an immutable value object with its own validation. Replaces the implicit `[price, rrRatio, tier]` values embedded in `SellOrder` construction inside `calculateScaledExits`.

**Files:**
- Create: `app/src/Domain/Position/ExitTarget.php`
- Create: `app/tests/Unit/Domain/Position/ExitTargetTest.php`

**Step 1: Write the failing tests**

```php
<?php
// app/tests/Unit/Domain/Position/ExitTargetTest.php

namespace Tests\Unit\Domain\Position;

use App\Domain\Position\ExitTarget;
use Codeception\Test\Unit;

class ExitTargetTest extends Unit
{
    public function testConstructsWithValidData(): void
    {
        $t = new ExitTarget(price: 110.0, rrRatio: 2.0, tier: 1, allocation: 0.33);
        $this->assertSame(110.0, $t->price);
        $this->assertSame(2.0,   $t->rrRatio);
        $this->assertSame(1,     $t->tier);
        $this->assertSame(0.33,  $t->allocation);
    }

    public function testRejectsZeroPrice(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ExitTarget(price: 0.0, rrRatio: 2.0, tier: 1, allocation: 0.33);
    }

    public function testRejectsNegativePrice(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ExitTarget(price: -5.0, rrRatio: 2.0, tier: 1, allocation: 0.33);
    }

    public function testRejectsTierZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ExitTarget(price: 110.0, rrRatio: 2.0, tier: 0, allocation: 0.33);
    }

    public function testRejectsAllocationAboveOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ExitTarget(price: 110.0, rrRatio: 2.0, tier: 1, allocation: 1.5);
    }

    public function testRejectsAllocationAtZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ExitTarget(price: 110.0, rrRatio: 2.0, tier: 1, allocation: 0.0);
    }
}
```

**Step 2: Run — verify fails**

```bash
vendor/bin/codecept run Unit Domain/Position/ExitTargetTest
```

**Step 3: Implement `ExitTarget`**

```php
<?php
// app/src/Domain/Position/ExitTarget.php

declare(strict_types=1);

namespace App\Domain\Position;

final readonly class ExitTarget
{
    public function __construct(
        public float $price,
        public float $rrRatio,
        public int   $tier,
        public float $allocation, // fraction of position qty, e.g. 0.33
    ) {
        if ($price <= 0) {
            throw new \InvalidArgumentException('ExitTarget price must be positive.');
        }
        if ($tier < 1) {
            throw new \InvalidArgumentException('ExitTarget tier must be >= 1.');
        }
        if ($allocation <= 0 || $allocation > 1) {
            throw new \InvalidArgumentException('ExitTarget allocation must be in (0, 1].');
        }
    }
}
```

**Step 4: Run — verify passes**

```bash
vendor/bin/codecept run Unit Domain/Position/ExitTargetTest
```

**Step 5: Commit**

```bash
git add app/src/Domain/Position/ExitTarget.php \
        app/tests/Unit/Domain/Position/ExitTargetTest.php
git commit -m "feat: add ExitTarget value object"
```

---

## Task 3: `ExitPlan` Value Object

**Why:** Bundles mode + targets + initial stop into one coherent, self-describing object. Callers (PaperTradingEngine, SimulationEngine) receive an `ExitPlan` instead of a raw array with a magic string key.

**Files:**
- Create: `app/src/Domain/Position/ExitPlan.php`
- Create: `app/tests/Unit/Domain/Position/ExitPlanTest.php`

**Step 1: Write the failing tests**

```php
<?php
// app/tests/Unit/Domain/Position/ExitPlanTest.php

namespace Tests\Unit\Domain\Position;

use App\Domain\Position\ExitMode;
use App\Domain\Position\ExitPlan;
use App\Domain\Position\ExitTarget;
use Codeception\Test\Unit;

class ExitPlanTest extends Unit
{
    public function testTrailingOnlyPlanHasNoTargets(): void
    {
        $plan = new ExitPlan(ExitMode::TrailingOnly, [], 95.0);
        $this->assertTrue($plan->isTrailingOnly());
        $this->assertEmpty($plan->targets);
    }

    public function testATRTiersPlanIsNotTrailingOnly(): void
    {
        $t = new ExitTarget(110.0, 2.0, 1, 1.0);
        $plan = new ExitPlan(ExitMode::ATRTiers, [$t], 95.0);
        $this->assertFalse($plan->isTrailingOnly());
    }

    public function testHighestTargetReturnsMaxPrice(): void
    {
        $t1 = new ExitTarget(105.0, 1.0, 1, 0.33);
        $t2 = new ExitTarget(110.0, 2.0, 2, 0.33);
        $t3 = new ExitTarget(115.0, 3.0, 3, 0.34);
        $plan = new ExitPlan(ExitMode::ATRTiers, [$t1, $t2, $t3], 95.0);
        $this->assertSame(115.0, $plan->highestTarget());
    }

    public function testHighestTargetOnTrailingOnlyReturnsFloatMax(): void
    {
        $plan = new ExitPlan(ExitMode::TrailingOnly, [], 95.0);
        $this->assertSame(PHP_FLOAT_MAX, $plan->highestTarget());
    }

    public function testInitialStopIsReadable(): void
    {
        $plan = new ExitPlan(ExitMode::TrailingOnly, [], 94.5);
        $this->assertSame(94.5, $plan->initialStop);
    }
}
```

**Step 2: Run — verify fails**

```bash
vendor/bin/codecept run Unit Domain/Position/ExitPlanTest
```

**Step 3: Implement `ExitPlan`**

```php
<?php
// app/src/Domain/Position/ExitPlan.php

declare(strict_types=1);

namespace App\Domain\Position;

final readonly class ExitPlan
{
    /**
     * @param ExitTarget[] $targets  Empty for TrailingOnly mode.
     */
    public function __construct(
        public ExitMode $mode,
        public array    $targets,
        public float    $initialStop,
    ) {
    }

    public function isTrailingOnly(): bool
    {
        return $this->mode === ExitMode::TrailingOnly;
    }

    /** Returns PHP_FLOAT_MAX for trailing-only (never fires fixed take-profit). */
    public function highestTarget(): float
    {
        if (empty($this->targets)) {
            return PHP_FLOAT_MAX;
        }

        return max(array_map(static fn(ExitTarget $t) => $t->price, $this->targets));
    }
}
```

**Step 4: Run — verify passes**

```bash
vendor/bin/codecept run Unit Domain/Position/ExitPlanTest
```

**Step 5: Commit**

```bash
git add app/src/Domain/Position/ExitPlan.php \
        app/tests/Unit/Domain/Position/ExitPlanTest.php
git commit -m "feat: add ExitPlan value object"
```

---

## Task 4: `ExitPlanFactory` — `TrailingOnly` Mode

**Why:** This is the single source of truth for exit target calculation. Start with the mode that's already working and currently active (`trailing-only`). ATR-tiers comes in Task 5.

**Files:**
- Create: `app/src/Domain/Position/ExitPlanFactory.php`
- Create: `app/tests/Unit/Domain/Position/ExitPlanFactoryTest.php`

**Step 1: Write the failing tests**

```php
<?php
// app/tests/Unit/Domain/Position/ExitPlanFactoryTest.php

namespace Tests\Unit\Domain\Position;

use App\Domain\Position\ExitMode;
use App\Domain\Position\ExitPlanFactory;
use Codeception\Test\Unit;

class ExitPlanFactoryTest extends Unit
{
    private ExitPlanFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new ExitPlanFactory();
    }

    // ── TrailingOnly ──────────────────────────────────────────────────────────

    public function testTrailingOnlyReturnsEmptyTargets(): void
    {
        $plan = $this->factory->create(ExitMode::TrailingOnly, 100.0, 92.5, 1.0);
        $this->assertTrue($plan->isTrailingOnly());
        $this->assertEmpty($plan->targets);
    }

    public function testTrailingOnlyPreservesInitialStop(): void
    {
        $plan = $this->factory->create(ExitMode::TrailingOnly, 100.0, 92.5, 1.0);
        $this->assertSame(92.5, $plan->initialStop);
    }

    public function testTrailingOnlyHighestTargetIsFloatMax(): void
    {
        $plan = $this->factory->create(ExitMode::TrailingOnly, 100.0, 92.5, 1.0);
        $this->assertSame(PHP_FLOAT_MAX, $plan->highestTarget());
    }
}
```

**Step 2: Run — verify fails**

```bash
vendor/bin/codecept run Unit Domain/Position/ExitPlanFactoryTest
```

**Step 3: Implement `ExitPlanFactory` with trailing-only**

```php
<?php
// app/src/Domain/Position/ExitPlanFactory.php

declare(strict_types=1);

namespace App\Domain\Position;

final class ExitPlanFactory
{
    /**
     * ATR multiples used for ATR-tiers mode.
     *
     * These are INDEPENDENT of atrStopMultiplier.
     * atrStopMultiplier is a trailing stop gene — it must NOT be used here.
     * With ATR ≈ 1% of price on 1h, these produce +2%, +3.5%, +5% targets.
     */
    private const DEFAULT_TIER_ATR_MULTIPLES = [2.0, 3.5, 5.0];
    private const DEFAULT_TIER_ALLOCATIONS   = [0.33, 0.33, 0.34];

    /**
     * Hard cap: no tier target may exceed this % above entry.
     * Prevents unreachable targets even if ATR spikes.
     */
    private const DEFAULT_MAX_TARGET_PERCENT = 10.0;

    public function create(
        ExitMode $mode,
        float    $entryPrice,
        float    $stopPrice,
        float    $atr,
        array    $options = [],
    ): ExitPlan {
        return match ($mode) {
            ExitMode::TrailingOnly => $this->buildTrailingOnly($stopPrice),
            ExitMode::ATRTiers     => $this->buildATRTiers($entryPrice, $stopPrice, $atr, $options),
        };
    }

    private function buildTrailingOnly(float $stopPrice): ExitPlan
    {
        return new ExitPlan(
            mode:        ExitMode::TrailingOnly,
            targets:     [],
            initialStop: $stopPrice,
        );
    }

    private function buildATRTiers(
        float $entryPrice,
        float $stopPrice,
        float $atr,
        array $options,
    ): ExitPlan {
        $atrMultiples   = $options['tierAtrMultiples'] ?? self::DEFAULT_TIER_ATR_MULTIPLES;
        $allocations    = $options['tierAllocations']  ?? self::DEFAULT_TIER_ALLOCATIONS;
        $maxTargetPct   = $options['maxTargetPercent'] ?? self::DEFAULT_MAX_TARGET_PERCENT;
        $maxTargetPrice = $entryPrice * (1 + $maxTargetPct / 100);

        $riskAmount = max($entryPrice - $stopPrice, 0.0001); // avoid division by zero

        $targets = [];
        foreach ($atrMultiples as $i => $multiple) {
            $rawPrice    = $entryPrice + ($atr * $multiple);
            $cappedPrice = min($rawPrice, $maxTargetPrice);
            $targets[]   = new ExitTarget(
                price:      $cappedPrice,
                rrRatio:    ($cappedPrice - $entryPrice) / $riskAmount,
                tier:       $i + 1,
                allocation: $allocations[$i] ?? (1.0 / count($atrMultiples)),
            );
        }

        return new ExitPlan(
            mode:        ExitMode::ATRTiers,
            targets:     $targets,
            initialStop: $stopPrice,
        );
    }
}
```

**Step 4: Run — verify passes**

```bash
vendor/bin/codecept run Unit Domain/Position/ExitPlanFactoryTest
```

**Step 5: Commit**

```bash
git add app/src/Domain/Position/ExitPlanFactory.php \
        app/tests/Unit/Domain/Position/ExitPlanFactoryTest.php
git commit -m "feat: add ExitPlanFactory with TrailingOnly mode"
```

---

## Task 5: `ExitPlanFactory` — `ATRTiers` Mode Tests

**Why:** ATR-tiers is Option C from the analysis — uses raw ATR (not `atr × atrStopMultiplier`) so targets scale correctly with actual volatility. This must be tested independently before being wired in.

**Files:**
- Modify: `app/tests/Unit/Domain/Position/ExitPlanFactoryTest.php`

**Step 1: Add ATR-tiers tests to the existing test file**

Append these test methods to `ExitPlanFactoryTest`:

```php
// ── ATRTiers ──────────────────────────────────────────────────────────────

public function testATRTiersProducesThreeTargets(): void
{
    // entry=100, stop=92.5, atr=1.0
    // Tier targets (DEFAULT multiples 2.0, 3.5, 5.0):
    //   tier1 = 100 + 1.0*2.0 = 102.0
    //   tier2 = 100 + 1.0*3.5 = 103.5
    //   tier3 = 100 + 1.0*5.0 = 105.0
    $plan = $this->factory->create(ExitMode::ATRTiers, 100.0, 92.5, 1.0);
    $this->assertFalse($plan->isTrailingOnly());
    $this->assertCount(3, $plan->targets);
}

public function testATRTiersTargetPricesUseRawATR(): void
{
    // atr=1.0, default multiples [2.0, 3.5, 5.0]
    $plan = $this->factory->create(ExitMode::ATRTiers, 100.0, 92.5, 1.0);
    $this->assertEqualsWithDelta(102.0, $plan->targets[0]->price, 0.001);
    $this->assertEqualsWithDelta(103.5, $plan->targets[1]->price, 0.001);
    $this->assertEqualsWithDelta(105.0, $plan->targets[2]->price, 0.001);
}

public function testATRTiersCapsTargetsAtMaxPercent(): void
{
    // entry=100, atr=5.0 (very high), default multiples [2.0, 3.5, 5.0]
    // raw tier3 = 100 + 5.0*5.0 = 125 — above 10% cap (110)
    $plan = $this->factory->create(ExitMode::ATRTiers, 100.0, 92.5, 5.0);
    foreach ($plan->targets as $target) {
        $this->assertLessThanOrEqual(110.0, $target->price);
    }
}

public function testATRTiersRRRIsComputedFromActualPrices(): void
{
    // entry=100, stop=95, risk=5, atr=1.0
    // tier1 price=102, rrRatio=(102-100)/5 = 0.4
    $plan = $this->factory->create(ExitMode::ATRTiers, 100.0, 95.0, 1.0);
    $this->assertEqualsWithDelta(0.4, $plan->targets[0]->rrRatio, 0.001);
}

public function testATRTiersCustomMultiplesOverrideDefaults(): void
{
    // Custom: single tier at 1.0×ATR
    $plan = $this->factory->create(
        ExitMode::ATRTiers,
        100.0, 95.0, 2.0,
        ['tierAtrMultiples' => [1.0], 'tierAllocations' => [1.0]],
    );
    $this->assertCount(1, $plan->targets);
    $this->assertEqualsWithDelta(102.0, $plan->targets[0]->price, 0.001);
}

public function testATRTiersWithDogeScenarioProducesReachableTargets(): void
{
    // Reproduce the original bug scenario.
    // entry=0.0908, atr=0.000885 (1h DOGE), atrStopMultiplier=7.5 gives stop=0.08416
    // OLD code: targets were +8.8%, +14.6%, +20.5% — unreachable
    // NEW code: targets are +2×ATR, +3.5×ATR, +5×ATR = +1.95%, +3.41%, +4.88%
    $entry = 0.0908;
    $atr   = 0.000885;
    $stop  = $entry - ($atr * 7.5); // ≈ 0.08416 — atrStopMultiplier still used for trailing stop

    $plan = $this->factory->create(ExitMode::ATRTiers, $entry, $stop, $atr);

    foreach ($plan->targets as $i => $target) {
        $pctAboveEntry = (($target->price - $entry) / $entry) * 100;
        // All targets must be under 6% — reachable in a single session
        $this->assertLessThan(6.0, $pctAboveEntry,
            "Tier {$target->tier} target is {$pctAboveEntry}% above entry — still too high");
    }
}
```

**Step 2: Run — verify they pass (implementation already done in Task 4)**

```bash
vendor/bin/codecept run Unit Domain/Position/ExitPlanFactoryTest
```

Expected: `OK (8 tests)`

**Step 3: Commit**

```bash
git add app/tests/Unit/Domain/Position/ExitPlanFactoryTest.php
git commit -m "test: add ATR-tiers coverage to ExitPlanFactoryTest (regression against original bug)"
```

---

## Task 6: `SimulationEngine` — Add `sellOrdersFromExitPlan()`

**Why:** The new translation layer between `ExitPlan` (domain) and `SellOrder[]` (Application DTO, persisted). The old `calculateScaledExits()` is kept temporarily so other callers don't break, but marked `@deprecated`.

**Files:**
- Modify: `app/src/Domain/Simulation/SimulationEngine.php`
- Modify: `app/tests/Unit/Application/Service/SimulationEngineTest.php`

**Step 1: Write the failing tests**

Add to `SimulationEngineTest.php`:

```php
use App\Domain\Position\ExitMode;
use App\Domain\Position\ExitPlan;
use App\Domain\Position\ExitPlanFactory;
use App\Domain\Position\ExitTarget;

// ── sellOrdersFromExitPlan ────────────────────────────────────────────────

public function testSellOrdersFromTrailingOnlyPlanReturnsSingleOrderWithFloatMax(): void
{
    $plan   = new ExitPlan(ExitMode::TrailingOnly, [], 92.5);
    $orders = $this->engine->sellOrdersFromExitPlan($plan, 100.0, 0.00075, 500, 1_000_000);

    $this->assertCount(1, $orders);
    $this->assertSame(PHP_FLOAT_MAX, $orders[0]->sellPrice);
    $this->assertSame(92.5, $orders[0]->stopLossPrice);
    $this->assertSame(500,  $orders[0]->count);
    $this->assertNull($orders[0]->rrRatio);
}

public function testSellOrdersFromATRTiersPlanReturnsOneOrderPerTarget(): void
{
    $targets = [
        new ExitTarget(102.0, 0.4, 1, 0.33),
        new ExitTarget(103.5, 0.7, 2, 0.33),
        new ExitTarget(105.0, 1.0, 3, 0.34),
    ];
    $plan   = new ExitPlan(ExitMode::ATRTiers, $targets, 95.0);
    $orders = $this->engine->sellOrdersFromExitPlan($plan, 100.0, 0.00075, 300, 1_000_000);

    $this->assertCount(3, $orders);
    $this->assertSame(102.0, $orders[0]->sellPrice);
    $this->assertSame(1,     $orders[0]->tier);
    $this->assertSame(103.5, $orders[1]->sellPrice);
    $this->assertSame(105.0, $orders[2]->sellPrice);
}

public function testSellOrdersFromPlanPreservesEntryTime(): void
{
    $plan   = new ExitPlan(ExitMode::TrailingOnly, [], 92.5);
    $orders = $this->engine->sellOrdersFromExitPlan($plan, 100.0, 0.00075, 100, 9_999_999);
    $this->assertSame(9_999_999, $orders[0]->entryTime);
}
```

**Step 2: Run — verify fails**

```bash
vendor/bin/codecept run Unit Application/Service/SimulationEngineTest
```

**Step 3: Add `sellOrdersFromExitPlan()` to `SimulationEngine`**

Add this method to `app/src/Domain/Simulation/SimulationEngine.php` (after `calculateScaledExits`):

```php
use App\Domain\Position\ExitPlan;
use App\Domain\Position\ExitTarget;

/**
 * Translates an ExitPlan (domain) into SellOrder DTOs (Application layer).
 *
 * This replaces calculateScaledExits() as the canonical way to build orders.
 * The ExitPlan is produced by ExitPlanFactory, keeping target calculation
 * independent of this translation step.
 */
public function sellOrdersFromExitPlan(
    ExitPlan $plan,
    float    $entryPrice,
    float    $feeMultiplier,
    int      $buyQty,
    int      $entryTime = 0,
): array {
    if ($plan->isTrailingOnly()) {
        return [
            new SellOrder(
                buyPrice:      $entryPrice,
                count:         $buyQty,
                stopLossPrice: $plan->initialStop,
                investment:    $this->calculateTotalInvestment($entryPrice, $buyQty, $feeMultiplier),
                rrRatio:       null,
                sellPrice:     PHP_FLOAT_MAX,
                tier:          1,
                entryTime:     $entryTime,
            ),
        ];
    }

    $orders = [];
    foreach ($plan->targets as $target) {
        $qty      = max(1, (int)round($buyQty * $target->allocation));
        $orders[] = new SellOrder(
            buyPrice:      $entryPrice,
            count:         $qty,
            stopLossPrice: $plan->initialStop,
            investment:    $this->calculateTotalInvestment($entryPrice, $qty, $feeMultiplier),
            rrRatio:       $target->rrRatio,
            sellPrice:     $target->price,
            tier:          $target->tier,
            entryTime:     $entryTime,
        );
    }

    return $orders;
}
```

Also add `@deprecated` to `calculateScaledExits()` docblock:

```php
/**
 * @deprecated Use ExitPlanFactory::create() + SimulationEngine::sellOrdersFromExitPlan() instead.
 */
public function calculateScaledExits(...): array
```

**Step 4: Run — verify passes**

```bash
vendor/bin/codecept run Unit Application/Service/SimulationEngineTest
```

**Step 5: Commit**

```bash
git add app/src/Domain/Simulation/SimulationEngine.php \
        app/tests/Unit/Application/Service/SimulationEngineTest.php
git commit -m "feat: add SimulationEngine::sellOrdersFromExitPlan(), deprecate calculateScaledExits"
```

---

## Task 7: Wire `ExitPlanFactory` into `PaperTradingEngine`

**Why:** Removes the dead `calculateAdaptiveSellPrice` call and the ad-hoc string check `$config->get('exitMode')`. Now `PaperTradingEngine` asks `ExitPlanFactory` for a plan and hands it to `sellOrdersFromExitPlan`.

**Files:**
- Modify: `app/src/Application/Service/PaperTradingEngine.php`

**Step 1: Read the file** (already done above — lines 198–228 are the target section)

**Step 2: Replace steps 8–9 in `tick()` with the factory**

Replace lines 180–228 of `PaperTradingEngine::tick()` with:

```php
// ── 8. Stop-loss calculation ─────────────────────────────────────────────
$stopLossPrice = $this->simulationEngine->calculateStopLoss(
    $currentPrice,
    $atr,
    $genesArr['atrStopMultiplier'],
    $config->get('minStopPercent'),
);

if ($config->get('minStopRR') !== null) {
    $riskAmount   = $currentPrice - $stopLossPrice;
    $t1Gain       = $riskAmount * $config->get('baseRR') * $config->get('tierMultipliers')[0];
    $roundTripFee = $currentPrice * $feeMultiplier * 2;
    $netT1Gain    = $t1Gain - $roundTripFee;
    if ($riskAmount > 0 && ($netT1Gain / $riskAmount) < $config->get('minStopRR')) {
        return $result;
    }
}

// ── 9. Build exit plan and create orders ────────────────────────────────
$exitMode = ExitMode::from($config->get('exitMode'));
$exitPlan = $this->exitPlanFactory->create(
    $exitMode,
    $currentPrice,
    $stopLossPrice,
    $atr,
);

$result->newPositions = $this->simulationEngine->sellOrdersFromExitPlan(
    $exitPlan,
    $currentPrice,
    $feeMultiplier,
    $buyQty,
    $currentTimestamp,
);
```

Also update the constructor to inject `ExitPlanFactory`:

```php
use App\Domain\Position\ExitMode;
use App\Domain\Position\ExitPlanFactory;

public function __construct(
    private readonly BuyStrategy         $buyStrategy,
    private readonly TechnicalAnalysisService $taService,
    private readonly SimulationEngine    $simulationEngine,
    private readonly ExitPlanFactory     $exitPlanFactory,   // ← replaces SellPriceCalculatorService
    private readonly PositionSizingService $positionSizer,
) {
}
```

Remove the `SellPriceCalculatorService` property and its injection. Remove the now-unused `use` import for it.

**Step 3: Update Symfony DI (services.yaml or autowiring)**

`ExitPlanFactory` has no constructor dependencies — autowiring will create it automatically. If `SellPriceCalculatorService` was explicitly configured, remove it from the PaperTradingEngine service entry in `config/services.yaml` (if it exists; check first).

```bash
grep -r "SellPriceCalculatorService" app/config/
```

If no explicit config found, autowiring handles it — nothing to do.

**Step 4: Run full unit suite**

```bash
vendor/bin/codecept run Unit
```

Expected: all green. Fix any remaining import errors.

**Step 5: Commit**

```bash
git add app/src/Application/Service/PaperTradingEngine.php
git commit -m "refactor: PaperTradingEngine — use ExitPlanFactory, remove dead calculateAdaptiveSellPrice call"
```

---

## Task 8: Update `StrategyConfig` — `exitMode` Default Is Already Fixed

**Why:** `exitMode` default was changed to `'trailing-only'` in the immediate hotfix. Now that we have `ExitMode` enum, the default should be validated against it.

**Files:**
- Modify: `app/src/Application/Config/StrategyConfig.php` (validation block ~line 192)

**Step 1: Add enum validation to `StrategyConfig::validate()`**

Find the existing validation block and add:

```php
// Validate exitMode is a known ExitMode value
\App\Domain\Position\ExitMode::from($this->params['exitMode']); // throws ValueError if invalid
```

This means any typo in `exitMode` will throw immediately at construction time rather than silently producing wrong behavior.

**Step 2: Run the full unit suite**

```bash
vendor/bin/codecept run Unit
```

**Step 3: Commit**

```bash
git add app/src/Application/Config/StrategyConfig.php
git commit -m "refactor: validate exitMode against ExitMode enum in StrategyConfig"
```

---

## Task 9: `SellOrder` Immutability — Add `withStop()`

**Why:** `SellOrder::stopLossPrice` is currently `public float` — it's mutated in-place by `OpenSellOrderCollection::ratchetTrailingStops()`. This creates hidden state changes that are hard to trace (the bug analysis was complicated by the fact that the stored `stop_loss` was the *ratcheted* value, not the *initial* value). Adding `withStop()` makes mutations explicit and auditable.

**Note:** `stopLossPrice` cannot be made `readonly` because PHP readonly properties can't be re-assigned after construction, even in clones. Instead, `withStop()` uses constructor-based cloning. The property stays `public float` but mutation via `withStop()` becomes the *documented* pattern.

**Files:**
- Modify: `app/src/Application/DTO/SellOrder.php`
- Modify: `app/src/Application/Service/OpenSellOrderCollection.php`
- Modify: `app/src/Application/Service/PaperTradingEngine.php`
- Create: `app/tests/Unit/Application/DTO/SellOrderTest.php`

**Step 1: Write failing tests for `withStop()`**

```php
<?php
// app/tests/Unit/Application/DTO/SellOrderTest.php

namespace Tests\Unit\Application\DTO;

use App\Application\DTO\SellOrderDto;
use Codeception\Test\Unit;

class SellOrderTest extends Unit
{
    private function makeOrder(float $stop = 95.0): SellOrderDto
    {
        return new SellOrderDto(
            buyPrice:      100.0,
            count:         100,
            stopLossPrice: $stop,
            investment:    100.0,
            rrRatio:       2.0,
            sellPrice:     110.0,
            tier:          1,
            entryTime:     1_000_000,
        );
    }

    public function testWithStopReturnsNewInstance(): void
    {
        $original = $this->makeOrder(95.0);
        $updated  = $original->withStop(97.0);
        $this->assertNotSame($original, $updated);
    }

    public function testWithStopUpdatesStopLossPrice(): void
    {
        $updated = $this->makeOrder(95.0)->withStop(97.0);
        $this->assertSame(97.0, $updated->stopLossPrice);
    }

    public function testWithStopPreservesAllOtherFields(): void
    {
        $original = $this->makeOrder(95.0);
        $updated  = $original->withStop(97.0);
        $this->assertSame($original->buyPrice,   $updated->buyPrice);
        $this->assertSame($original->count,      $updated->count);
        $this->assertSame($original->investment, $updated->investment);
        $this->assertSame($original->rrRatio,    $updated->rrRatio);
        $this->assertSame($original->sellPrice,  $updated->sellPrice);
        $this->assertSame($original->tier,       $updated->tier);
        $this->assertSame($original->entryTime,  $updated->entryTime);
    }

    public function testOriginalIsUnchangedAfterWithStop(): void
    {
        $original = $this->makeOrder(95.0);
        $original->withStop(97.0); // discard result
        $this->assertSame(95.0, $original->stopLossPrice); // original unchanged
    }
}
```

**Step 2: Run — verify fails**

```bash
vendor/bin/codecept run Unit Application/DTO/SellOrderTest
```

**Step 3: Add `withStop()` to `SellOrder`**

```php
// In app/src/Application/DTO/SellOrder.php — append method:

public function withStop(float $newStop): self
{
    return new self(
        buyPrice:      $this->buyPrice,
        count:         $this->count,
        stopLossPrice: $newStop,
        investment:    $this->investment,
        rrRatio:       $this->rrRatio,
        sellPrice:     $this->sellPrice,
        tier:          $this->tier,
        entryTime:     $this->entryTime,
    );
}
```

**Step 4: Run — verify passes**

```bash
vendor/bin/codecept run Unit Application/DTO/SellOrderTest
```

**Step 5: Update `OpenSellOrderCollection::ratchetTrailingStops` to use `withStop()`**

Replace the mutation in the loop:

```php
// OLD (direct mutation):
$order->stopLossPrice = $newStop;

// NEW (returns new instance — update the collection in-place):
$this->orders[$key] = $order->withStop($newStop);
```

This requires tracking the array key. Refactor the loop in `ratchetTrailingStops` from `foreach` to `foreach with key`:

```php
foreach ($this->orders as $key => $order) {
    // ... activation candle check unchanged ...

    $newStop = $candleHigh - $trailingDistance;
    if ($newStop > $order->stopLossPrice) {
        $this->orders[$key] = $order->withStop($newStop);
    }
}
```

Same pattern for `applyBreakevenLogic` and `moveTierSiblingsToBreakeven`.

**Step 6: Update `PaperTradingEngine` — remove in-place mutation**

In the tier-1 take-profit block (line ~103), replace:

```php
// OLD:
$sib->stopLossPrice          = $sib->buyPrice;
$result->stopUpdates[$sibId] = $sib->buyPrice;

// NEW (update the remaining map and track the change):
$remaining[$sibId]           = $sib->withStop($sib->buyPrice);
$result->stopUpdates[$sibId] = $sib->buyPrice;
```

Also update the stop ratchet section to read back the updated orders from the collection after ratcheting (since the collection now holds new instances, not the original object references).

**Step 7: Run full unit suite**

```bash
vendor/bin/codecept run Unit
```

**Step 8: Commit**

```bash
git add app/src/Application/DTO/SellOrder.php \
        app/src/Application/Service/OpenSellOrderCollection.php \
        app/src/Application/Service/PaperTradingEngine.php \
        app/tests/Unit/Application/DTO/SellOrderTest.php
git commit -m "refactor: SellOrder::withStop() — explicit stop mutation, remove in-place writes"
```

---

## Task 10: Final Cleanup — Style and Verify

**Step 1: Check PSR-12 compliance**

```bash
composer cs-check
```

If violations found:

```bash
composer cs-fix
git add -u
git commit -m "style: cs-fix after exit strategy refactor"
```

**Step 2: Run all tests**

```bash
vendor/bin/codecept run Unit
```

Expected: full green.

**Step 3: Smoke-test the realtime command (analysis mode)**

```bash
php -d xdebug.mode=off bin/console app:strategy:realtime:trading --source=db \
    --start-date="2026-03-01 00:00:00" --end-date="2026-03-07 00:00:00" 2>&1 | head -50
```

Verify: no exceptions, positions open with `sellPrice = PHP_FLOAT_MAX` (trailing-only).

**Step 4: Final commit if needed**

```bash
git commit -m "chore: exit strategy DDD refactor complete"
```

---

## Architecture Summary After This Plan

```
Domain/Position/               ← NEW bounded context
├── ExitMode.php               (enum: TrailingOnly | ATRTiers)
├── ExitTarget.php             (value object: price, rrRatio, tier, allocation)
├── ExitPlan.php               (value object: mode, targets[], initialStop)
└── ExitPlanFactory.php        (domain service: create(ExitMode, entry, stop, atr) → ExitPlan)

Domain/Simulation/
└── SimulationEngine.php       (+ sellOrdersFromExitPlan() | calculateScaledExits @deprecated)

Application/
├── Config/StrategyConfig.php  (exitMode validated via ExitMode::from())
├── DTO/SellOrder.php          (+ withStop(): self)
└── Service/
    ├── PaperTradingEngine.php (injects ExitPlanFactory; dead calculateAdaptiveSellPrice removed)
    └── OpenSellOrderCollection.php (ratchet uses withStop(), no in-place mutation)
```

**What the structural bug can no longer happen:**
- `atrStopMultiplier` is used *only* in `SimulationEngine::calculateStopLoss()` (trailing stop distance)
- Take-profit targets come exclusively from `ExitPlanFactory`, which uses raw `$atr`, not `$atr × atrStopMultiplier`
- Any new exit mode must implement `ExitPlanFactory::create()` matching against `ExitMode` — compiler enforces exhaustiveness
- `SellOrder::stopLossPrice` mutations are explicit, auditable, and immutable at the object level

**What is explicitly NOT in scope:**
- Deleting `SellPriceCalculatorService` (it may be useful for future swing-high–based targets; leave it)
- Deleting `StopLossCalculatorService` (app-layer utility with independent tests; leave it)
- Changing `calculateScaledExits` callers in `SimulationRunner` (different bounded context; migrate separately)
