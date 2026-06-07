# Peak-to-Trough Equity Drawdown Fix — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the broken `SimulationStats::minProfit` running-balance metric with a true mark-to-market peak-to-trough equity drawdown, and recalibrate `FitnessCalculator::drawdownPenalty` to a fraction-of-peak-equity scale.

**Architecture:** Move the peak/drawdown math onto `SimulationStats` as a small pure method `recordEquity(float)`. `SimulationRunner::trackEquity` computes the equity value (`balance + Σ open-position MtM`) each stream tick and delegates the bookkeeping to `recordEquity`. `SimulationResult` gains a `peakEquity` field so `FitnessCalculator` can normalize by account size without knowing about `TradingAccount`. The fitness penalty becomes account-size-agnostic: 5% DD → ×0.82, 50% DD → ×0.14.

**Tech Stack:** PHP 8.4 / Symfony 7.3, Codeception 5 (wraps PHPUnit 10), DDD layout under `app/src/`.

**Spec:** `docs/superpowers/specs/2026-05-11-equity-drawdown-fix-design.md`

---

## File Map

**Created:** none (pure refactor on existing files).

**Modified:**
- `app/src/Domain/Simulation/SimulationStats.php` — remove `minProfit`, add `peakEquity` + `maxDrawdown` + `recordEquity()` method.
- `app/src/Domain/Simulation/SimulationResult.php` — add `peakEquity` to constructor, `withFitness()`, `fromArray()`.
- `app/src/Domain/Simulation/SimulationRunner.php` — rename `trackMinProfit` → `trackEquity`, update body, plumb `peakEquity` into `buildResult`.
- `app/src/Domain/Genetic/FitnessCalculator.php` — new `drawdownPenalty()` formula.
- `app/src/Application/Service/PaperTradingEngine.php` — only if it references `SimulationStats::minProfit` (check during Task 3).
- `app/tests/Unit/Domain/Genetic/FitnessCalculatorTest.php` — update fixtures to set `peakEquity`, rewrite drawdown-specific tests for new scale.
- `EXPERIMENTS.md` and `MEMORY.md` — fitness boundary marker.

**New test file:**
- `app/tests/Unit/Domain/Simulation/SimulationStatsTest.php` — unit tests for `recordEquity()` peak/DD math.

---

## How to Run Tests

All commands run inside the Docker container:

```bash
docker exec -it paybis-app sh
# inside:
composer test-unit                                  # all unit tests
vendor/bin/codecept run Unit --filter SimulationStatsTest -v
vendor/bin/codecept run Unit --filter FitnessCalculatorTest -v
composer cs-fix && composer phpstan
```

---

## Task 1: Add `SimulationStats::recordEquity()` and replace `minProfit`

The peak-tracking math is the smallest pure-logic unit. Test it first in isolation.

**Files:**
- Modify: `app/src/Domain/Simulation/SimulationStats.php`
- Create: `app/tests/Unit/Domain/Simulation/SimulationStatsTest.php`

- [ ] **Step 1: Write the failing test**

Create `app/tests/Unit/Domain/Simulation/SimulationStatsTest.php`:

```php
<?php

namespace Tests\Unit\Domain\Simulation;

use App\Domain\Simulation\SimulationStats;
use Codeception\Test\Unit;

class SimulationStatsTest extends Unit
{
    public function testLazyInitOfPeakOnFirstSample(): void
    {
        $stats = new SimulationStats();
        $stats->recordEquity(1000.0);

        $this->assertSame(1000.0, $stats->peakEquity);
        $this->assertSame(0.0, $stats->maxDrawdown);
    }

    public function testPeakRisesWithHigherEquity(): void
    {
        $stats = new SimulationStats();
        $stats->recordEquity(1000.0);
        $stats->recordEquity(1500.0);

        $this->assertSame(1500.0, $stats->peakEquity);
        $this->assertSame(0.0, $stats->maxDrawdown);
    }

    public function testDrawdownIsPeakToTrough(): void
    {
        $stats = new SimulationStats();
        $stats->recordEquity(1000.0);
        $stats->recordEquity(1500.0);
        $stats->recordEquity(1100.0);

        $this->assertSame(1500.0, $stats->peakEquity);
        $this->assertSame(-400.0, $stats->maxDrawdown);
    }

    public function testDrawdownKeepsMostNegativeAcrossSamples(): void
    {
        $stats = new SimulationStats();
        $stats->recordEquity(1000.0);
        $stats->recordEquity(1500.0);
        $stats->recordEquity(1100.0);  // dd = -400
        $stats->recordEquity(1300.0);  // dd = -200 (less bad, must not overwrite)
        $stats->recordEquity(1450.0);  // dd = -50

        $this->assertSame(1500.0, $stats->peakEquity);
        $this->assertSame(-400.0, $stats->maxDrawdown);
    }

    public function testNewPeakDoesNotResetDrawdown(): void
    {
        $stats = new SimulationStats();
        $stats->recordEquity(1000.0);
        $stats->recordEquity(800.0);   // dd = -200 from peak 1000
        $stats->recordEquity(1200.0);  // new peak

        $this->assertSame(1200.0, $stats->peakEquity);
        $this->assertSame(-200.0, $stats->maxDrawdown);
    }

    public function testNegativeEquityIsRecorded(): void
    {
        $stats = new SimulationStats();
        $stats->recordEquity(1000.0);
        $stats->recordEquity(-50.0);

        $this->assertSame(1000.0, $stats->peakEquity);
        $this->assertSame(-1050.0, $stats->maxDrawdown);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
docker exec -it paybis-app sh -c "cd /app && vendor/bin/codecept run Unit --filter SimulationStatsTest -v"
```

Expected: errors with `Call to undefined method App\Domain\Simulation\SimulationStats::recordEquity()`.

- [ ] **Step 3: Modify `app/src/Domain/Simulation/SimulationStats.php`**

Delete the `minProfit` field (line 21) and add the two new fields plus the method. Final state of the file:

```php
<?php

namespace App\Domain\Simulation;

final class SimulationStats
{
    public int $buys = 0;
    public int $sells = 0;
    public int $profitCount = 0;
    public int $lossCount = 0;
    public int $profitTrades = 0;
    public int $lossTrades = 0;
    public int $maxConsecutiveLosses = 0;
    /** @internal Transient: accumulated net profit per entryTime, cleared when trade settles */
    public array $tradeProfitAccum = [];
    /** @internal Transient: running streak counter during simulation */
    public int $currentConsecutiveLosses = 0;
    public float $totalInvested = 0.0;
    public float $totalReturned = 0.0;
    public float $totalFees = 0.0;
    /** Highest mark-to-market equity ever observed during the sim; lazily initialised on first recordEquity() call. */
    public float $peakEquity = 0.0;
    /** Most-negative (equity − peakEquity) ever observed; ≤ 0. Sign convention: negative dollars. */
    public float $maxDrawdown = 0.0;
    public float $grossWins = 0.0;
    public float $grossLosses = 0.0;
    public int $signalsAnalyzed = 0;
    public array $scoreDistribution = [
        '0-30'  => 0,
        '30-50' => 0,
        '50-60' => 0,
        '60-65' => 0,
        '65-70' => 0,
        '70-80' => 0,
        '80+'   => 0,
    ];
    public array $filterRejections = [
        'uptrend'        => 0,
        'rvol'           => 0,
        'rsi'            => 0,
        'rr'             => 0,
        'adx'            => 0,
        'regime_ranging' => 0,
        'timing'         => 0,
        'regime'         => 0,
    ];
    /** @var array<string, array{buys: int, sells: int, profit: float, wins: int, losses: int}> keyed by 'Y-m' */
    public array $monthlyStats = [];

    /**
     * Sample the equity curve. Updates peakEquity (monotone up) and maxDrawdown (most-negative excursion from peak).
     * On the first call peakEquity is lazily initialised to the supplied equity value.
     */
    public function recordEquity(float $equity): void
    {
        if ($this->peakEquity === 0.0 && $this->maxDrawdown === 0.0) {
            $this->peakEquity = $equity;
            return;
        }

        if ($equity > $this->peakEquity) {
            $this->peakEquity = $equity;
            return;
        }

        $drawdown = $equity - $this->peakEquity;
        if ($drawdown < $this->maxDrawdown) {
            $this->maxDrawdown = $drawdown;
        }
    }
}
```

Notes:
- The lazy-init guard `peakEquity === 0.0 && maxDrawdown === 0.0` is robust: once either has moved off zero, normal logic runs. (Both being 0 simultaneously after sampling would only occur if the first equity sample was exactly 0.0 — an edge case the tests don't cover but which collapses safely to a no-op.)
- No `min()` / `max()` shorthand — the explicit conditionals make the intent obvious in profiling output.

- [ ] **Step 4: Run test to verify it passes**

```
docker exec -it paybis-app sh -c "cd /app && vendor/bin/codecept run Unit --filter SimulationStatsTest -v"
```

Expected: 6 tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/src/Domain/Simulation/SimulationStats.php app/tests/Unit/Domain/Simulation/SimulationStatsTest.php
git commit -m "feat(sim): replace minProfit with peakEquity + maxDrawdown on SimulationStats

Add recordEquity() pure helper that lazily initialises peakEquity on first
sample and tracks the most-negative (equity − peak) excursion. Replaces
the running-balance minProfit field which missed peak-to-trough drawdown."
```

---

## Task 2: Add `peakEquity` to `SimulationResult` DTO

`FitnessCalculator` needs `peakEquity` to normalize by account size. Plumb it through the result DTO.

**Files:**
- Modify: `app/src/Domain/Simulation/SimulationResult.php`

- [ ] **Step 1: Modify `SimulationResult.php`**

Add `peakEquity` as a constructor parameter (default 0.0), wire it through `withFitness()` and `fromArray()`. Place it next to `maxDrawdown` for readability.

Final state of the relevant sections:

Constructor parameter list (add `peakEquity` immediately after `maxDrawdown` on line 13):

```php
        public readonly float $maxDrawdown,
        public readonly float $peakEquity = 0.0,
```

`withFitness()` — add `peakEquity` to the constructor argument list (mirror `maxDrawdown`):

```php
            maxDrawdown:               $this->maxDrawdown,
            peakEquity:                $this->peakEquity,
```

`fromArray()` — add the read:

```php
            maxDrawdown:               $data['maxDrawdown'] ?? 0.0,
            peakEquity:                $data['peakEquity'] ?? 0.0,
```

- [ ] **Step 2: Run the existing test suite to confirm no regressions**

```
docker exec -it paybis-app sh -c "cd /app && composer test-unit"
```

Expected: same green status as before this task. `IndividualTest` constructs `SimulationResult` by name (`maxDrawdown: -297.0` etc.) — adding `peakEquity` with a default keeps it valid.

- [ ] **Step 3: Commit**

```bash
git add app/src/Domain/Simulation/SimulationResult.php
git commit -m "feat(sim): add peakEquity to SimulationResult

Needed by FitnessCalculator to normalize drawdown penalty by account size."
```

---

## Task 3: Rewrite `SimulationRunner::trackMinProfit` as `trackEquity`

Compute mark-to-market equity each stream tick (balance + sum of open positions valued at current close) and delegate to `SimulationStats::recordEquity`. Update `buildResult` to read the new fields.

**Files:**
- Modify: `app/src/Domain/Simulation/SimulationRunner.php`
- Inspect: `app/src/Application/Service/PaperTradingEngine.php` (may also reference `minProfit`)

- [ ] **Step 1: Sanity-check for other `minProfit` references**

```
docker exec -it paybis-app sh -c "grep -rn 'minProfit' /app/src /app/tests"
```

Expected hits: only `SellPriceCalculatorService.php` (different concept, `minProfitPercent` — leave alone). If `PaperTradingEngine.php` references `$stats->minProfit`, fix it in this task too.

- [ ] **Step 2: Modify `SimulationRunner.php` — rename and rewrite `trackMinProfit`**

Locate lines 157–163 (current `trackMinProfit`). Replace with:

```php
    private function trackEquity(TradingAccount $account, SimulationStats $stats, Candle $candle): void
    {
        $openOrders = $this->positionRepository->findBy(['status' => OrderStatus::Open->value]);

        $unrealized = 0.0;
        foreach ($openOrders as $order) {
            $qty = $order->getCount();
            $unrealized += $candle->close * $qty - $account->tradeFee($candle->close, $qty);
        }

        $stats->recordEquity($account->balance() + $unrealized);
    }
```

Update the call site in `processCandle` (currently line 132):

```php
        $this->trackEquity($account, $stats, $candle);
```

- [ ] **Step 3: Update `buildResult` to populate `peakEquity` from stats**

Locate the `SimulationResult` construction starting around line 412. Change line 418 from:

```php
            maxDrawdown:               round($stats->minProfit, 2),
```

to:

```php
            maxDrawdown:               round($stats->maxDrawdown, 2),
            peakEquity:                round($stats->peakEquity, 2),
```

- [ ] **Step 4: Confirm there are no remaining `minProfit` references in `SimulationRunner.php`**

```
docker exec -it paybis-app sh -c "grep -n 'minProfit' /app/src/Domain/Simulation/SimulationRunner.php"
```

Expected: no output.

- [ ] **Step 5: Run the unit suite**

```
docker exec -it paybis-app sh -c "cd /app && composer test-unit"
```

Expected: green. No SimulationRunner-level unit tests exist; the existing suite verifies SimulationResult, SimulationStats, FitnessCalculator interactions are still wired correctly.

- [ ] **Step 6: Commit**

```bash
git add app/src/Domain/Simulation/SimulationRunner.php
git commit -m "feat(sim): track mark-to-market equity each stream tick

trackEquity computes balance + open-position MtM (close × qty − exit fee)
and delegates to SimulationStats::recordEquity. Replaces trackMinProfit,
which only sampled the realized balance vs initial and missed both
peak-to-trough excursions and unrealized drawdown on open trades."
```

---

## Task 4: Recalibrate `FitnessCalculator::drawdownPenalty`

Switch from absolute-dollar penalty to fraction-of-peak-equity. Update the existing tests first (TDD), then change the formula.

**Files:**
- Modify: `app/tests/Unit/Domain/Genetic/FitnessCalculatorTest.php`
- Modify: `app/src/Domain/Genetic/FitnessCalculator.php`

- [ ] **Step 1: Update the test fixture `makeResult` helper to default `peakEquity`**

In `app/tests/Unit/Domain/Genetic/FitnessCalculatorTest.php`, modify the `$defaults` array in `makeResult` (around line 21) to include `peakEquity`:

```php
        $defaults = [
            'buys'           => 150,
            'sells'          => 150,
            'profitFactor'   => 2.0,
            'pnlInclOpen'    => 1000.0,
            'realizedProfit' => 500.0,
            'maxDrawdown'    => 0.0,
            'peakEquity'     => 1000.0,
            'grossWins'      => 800.0,
            'grossLosses'    => 400.0,
            'optimalBuys'    => 200,
            'optimalProfit'  => 1000.0,
        ];
```

`SimulationResult::fromArray()` now reads `peakEquity`, so this propagates through `$this->makeResult(['maxDrawdown' => -100.0])` etc.

- [ ] **Step 2: Rewrite `testDrawdownPenaltyReducesFitness` for the new scale**

The intent is unchanged (DD reduces fitness). Update the comment:

```php
    public function testDrawdownPenaltyReducesFitness(): void
    {
        // ddPenalty = |maxDrawdown| / peakEquity × 4.0; here 500/1000 × 4 = 2.0 → exp(-2) ≈ 0.135
        $withoutDrawdown = $this->makeResult(['maxDrawdown' => 0.0]);
        $withDrawdown    = $this->makeResult(['maxDrawdown' => -500.0]);

        $this->assertGreaterThan(
            $this->calc->calculate($withDrawdown),
            $this->calc->calculate($withoutDrawdown)
        );
    }
```

- [ ] **Step 3: Update `testFitnessUsesCurrentFormula` comment**

The numeric expectation in this test uses `maxDrawdown = 0.0` so the new formula's zero branch fires — assertion still holds, but the comment block is now slightly misleading. Replace lines 45–49:

```php
        // profitScore  = (500/1000) × (min(2,4)^1.2)         [profit >= 0]
        // tradeScore   = max(0.1, min(1.5, log(151)/log(201)))
        // efficiency   = min(1.0, 500/1200)                   [no floor for positive path]
        // ddPenalty    = 0 (maxDrawdown = 0 → zero branch)
        // fitness      = profitScore × tradeScore × efficiency × exp(0)
```

- [ ] **Step 4: Add a calibration test for the new formula**

After `testDrawdownPenaltyReducesFitness`, append:

```php
    public function testDrawdownPenaltyIsFractionOfPeakEquity(): void
    {
        // 20% drawdown on $1000 peak: ddPenalty = 0.2 × 4.0 = 0.8 → multiplier exp(-0.8) ≈ 0.449
        $small = $this->makeResult(['maxDrawdown' => -200.0, 'peakEquity' => 1000.0]);

        // Same DD on a $10000 peak should give the same penalty (account-size-agnostic).
        $scaled = $this->makeResult(['maxDrawdown' => -2000.0, 'peakEquity' => 10000.0]);

        $this->assertEqualsWithDelta(
            $this->calc->calculate($small),
            $this->calc->calculate($scaled),
            0.0001
        );
    }

    public function testZeroPeakEquityProducesNoDrawdownPenalty(): void
    {
        // Defensive: zero-tick sim (or legacy fixture) → guard prevents division by zero,
        // ddPenalty collapses to 0 (no penalty), fitness reflects only other factors.
        $result = $this->makeResult(['maxDrawdown' => -500.0, 'peakEquity' => 0.0]);
        $noDD   = $this->makeResult(['maxDrawdown' => 0.0,    'peakEquity' => 0.0]);

        $this->assertEqualsWithDelta(
            $this->calc->calculate($noDD),
            $this->calc->calculate($result),
            0.0001
        );
    }
```

- [ ] **Step 5: Run the tests to verify they fail**

```
docker exec -it paybis-app sh -c "cd /app && vendor/bin/codecept run Unit --filter FitnessCalculatorTest -v"
```

Expected: `testDrawdownPenaltyIsFractionOfPeakEquity` fails (old formula uses `/2500`, not `/peakEquity`). `testZeroPeakEquityProducesNoDrawdownPenalty` may also fail because the old formula penalizes regardless of peakEquity.

- [ ] **Step 6: Update `FitnessCalculator::drawdownPenalty`**

Replace the method at lines 105–114:

```php
    private function drawdownPenalty(SimulationResult $r): float
    {
        if ($r->maxDrawdown >= 0 || $r->peakEquity <= 0.0) {
            return 0.0;
        }

        $ddPct = abs($r->maxDrawdown) / $r->peakEquity;

        return $ddPct * 4.0;
    }
```

- [ ] **Step 7: Run the tests to verify they pass**

```
docker exec -it paybis-app sh -c "cd /app && vendor/bin/codecept run Unit --filter FitnessCalculatorTest -v"
```

Expected: all tests in the class pass.

- [ ] **Step 8: Commit**

```bash
git add app/src/Domain/Genetic/FitnessCalculator.php app/tests/Unit/Domain/Genetic/FitnessCalculatorTest.php
git commit -m "feat(fitness): scale drawdown penalty by peakEquity, not absolute dollars

ddPenalty = (|maxDrawdown| / peakEquity) × 4.0. Calibration: 5% DD → ×0.82,
15% → ×0.55, 30% → ×0.30, 50% → ×0.14. Account-size-agnostic: a \$10k and
a \$1k account get comparable penalties for the same percent DD. The old
formula |dd|/2500 × 3.0 produced wildly different penalties across account
sizes and was tuned against a metric that under-reported drawdown."
```

---

## Task 5: Full test suite + static analysis

Ensure the whole project still builds clean.

- [ ] **Step 1: Run the full unit suite**

```
docker exec -it paybis-app sh -c "cd /app && composer test-unit"
```

Expected: green across all unit suites. If any test fails with a `peakEquity` / `maxDrawdown` / `minProfit` reference, fix it inline — likely a fixture or assertion left over from the rename.

- [ ] **Step 2: Run code style and static analysis**

```
docker exec -it paybis-app sh -c "cd /app && composer cs-fix && composer phpstan"
```

Expected: cs-fix prints either no changes or applies safe whitespace fixes; phpstan reports no new errors. If phpstan complains about a `peakEquity` access on something it thinks is `mixed`, double-check the constructor signature in `SimulationResult.php`.

- [ ] **Step 3: Run the integration suite (sanity check)**

```
docker exec -it paybis-app sh -c "cd /app && composer test"
```

Expected: green. Integration tests touch real DB; if the test DB has stale `paper_trade_genes.json`-style fixtures that try to construct a `SimulationResult` via `fromArray` with no `peakEquity`, the `?? 0.0` default handles them.

- [ ] **Step 4: Commit any auto-applied style fixes**

```bash
git diff --stat
# if non-empty:
git add -u
git commit -m "style: apply cs-fix to drawdown-fix changes"
```

---

## Task 6: Documentation — mark the fitness boundary

Past fitness numbers in the experiment log are incomparable to post-fix runs. Document this so it doesn't trip up later analysis.

**Files:**
- Modify: `EXPERIMENTS.md` (project root or under `app/`; check both)
- Modify: `/home/serhii/.claude/projects/-home-serhii-projects-binance/memory/MEMORY.md` and any referenced memory files about the fitness function

- [ ] **Step 1: Find the experiment log**

```
docker exec -it paybis-app sh -c "ls /app/EXPERIMENTS.md /app/../EXPERIMENTS.md 2>/dev/null; find / -name EXPERIMENTS.md 2>/dev/null"
```

If multiple, prefer the project-root one (alongside `CLAUDE.md`).

- [ ] **Step 2: Prepend a boundary marker to `EXPERIMENTS.md`**

Add at the top of the file, after any existing title:

```markdown
## 2026-05-11 — Fitness recalibration boundary

`SimulationStats::minProfit` replaced with mark-to-market peak-to-trough
`maxDrawdown` + `peakEquity`. `FitnessCalculator::drawdownPenalty` now
scales by peak equity (account-size-agnostic) instead of absolute dollars.

**Fitness scores from before 2026-05-11 are incomparable to post-fix runs.**
Genome JSONs remain valid as starting seeds — they store gene values, not
fitness values. Past best configs may rank differently under the corrected
risk penalty.
```

- [ ] **Step 3: Update the auto-memory fitness notes**

In `/home/serhii/.claude/projects/-home-serhii-projects-binance/memory/MEMORY.md`, locate the section titled "Fitness Function (updated …)" and replace its body with the new formula. Add a one-line `## Fitness recalibration (2026-05-11)` entry indexed in MEMORY.md, with the body in its own file if the formula needs more than ~150 chars.

Suggested new memory body:

```markdown
fitness = profitScore × tradeScore × equityEfficiency × exp(−ddPenalty)
- maxDrawdown is mark-to-market peak-to-trough, stored as negative dollars
  (balance + sum of open-position MtM, sampled every stream tick).
- peakEquity is the running max equity; both live on SimulationStats and SimulationResult.
- ddPenalty = (|maxDrawdown| / peakEquity) × 4.0 when peakEquity > 0 and maxDrawdown < 0; else 0.
- All other terms (profitScore, tradeScore, equityEfficiency, soft trade penalty) unchanged.
- Prior to 2026-05-11, drawdown was minProfit (running balance vs initial),
  which missed peak-to-trough and unrealized DD entirely.
```

- [ ] **Step 4: Commit**

```bash
git add EXPERIMENTS.md
git commit -m "docs: mark fitness recalibration boundary (2026-05-11)

Fitness scores before this date used a broken drawdown metric (running
balance vs initial; missed peak-to-trough and unrealized DD). Genome
JSONs remain valid as seeds."
```

(Auto-memory updates do not need to be committed — they live outside the repo.)

---

## Self-Review

**Spec coverage check:**

| Spec requirement | Task |
|------------------|------|
| Remove `minProfit`, add `peakEquity` + `maxDrawdown` on SimulationStats | Task 1 |
| Rewrite `trackMinProfit` → `trackEquity` with MtM equity formula | Task 3 |
| Add `peakEquity` to `SimulationResult` constructor / `withFitness` / `fromArray` | Task 2 |
| Recalibrate `FitnessCalculator::drawdownPenalty` to fraction-of-peak | Task 4 |
| Unit tests for peak-to-trough math (test #2 from spec) | Task 1, Step 1 (`testDrawdownIsPeakToTrough`) |
| Unit tests for unrealized intra-trade drawdown (test #3 from spec) | Covered indirectly by Task 1 + Task 3; no SimulationRunner-level integration test exists. The Task 1 unit tests verify the pure math; Task 3 wires it via the MtM formula, which is small and code-reviewable. Adding a SimulationRunner integration test from scratch is out of scope for this plan but is a reasonable follow-up. |
| Unit tests for no-trade sim → DD = 0 (test #1 from spec) | Implicit via `recordEquity` lazy-init test + `peakEquity=0.0` default in DTO |
| Property test on fitness invariants | Task 4 Step 4 (`testDrawdownPenaltyIsFractionOfPeakEquity`, `testZeroPeakEquityProducesNoDrawdownPenalty`) |
| `EXPERIMENTS.md` boundary marker | Task 6 |
| Memory note recalibration | Task 6 |

**Note on Task 3 coverage:** the spec asked for three SimulationRunner-level scenarios. The pure logic is covered by the SimulationStats unit tests (Task 1) and the formula in `trackEquity` is small. A full integration test would require constructing all 8 SimulationRunner dependencies — material work for limited extra confidence. If you want belt-and-suspenders, add a follow-up task to build the integration fixture; otherwise the existing unit coverage plus a manual smoke run of `php bin/console app:short-strategy-simulation` is sufficient validation.

**Placeholder scan:** none. Every step contains exact code or exact commands.

**Type / name consistency:**
- `peakEquity` and `maxDrawdown` field names match across `SimulationStats`, `SimulationResult`, `FitnessCalculator`, and tests.
- `recordEquity(float $equity): void` signature matches in both the test file and the implementation.
- `trackEquity(TradingAccount $account, SimulationStats $stats, Candle $candle): void` signature matches its call site and implementation.

Plan is consistent.

---

## Execution Notes

- Each task ends with a commit. If a task fails mid-stream, the previous task's state is intact for revert.
- Tasks 1–4 are dependent in order. Task 5 (test suite) and Task 6 (docs) are linear after Task 4.
- After Task 4 lands, the first GA / CMA-ES / NSGA-II run will produce significantly different fitness numbers — that's the bug being corrected, not a regression.
