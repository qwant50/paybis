# NSGA-II Regime-Conditioned Optimizer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a multi-objective NSGA-II optimizer that finds separate gene sets per market regime (Sideways, TrendingUp, TrendingDown, HighVolatility), then enables live trading to switch configs based on the current regime.

**Architecture:** Four independent NSGA-II sub-populations (one per regime) evolve concurrently within a single command using `DE/current-to-best/1/bin` variation and per-individual regime labeling. `SimulationRunner` gains an optional `regimeMask` to skip buy entries outside the target regime. Live trading loads all four configs at startup and switches per tick.

**Tech Stack:** PHP 8.4, Symfony 7.3 console + messenger, Codeception 5 unit tests, existing `DifferentialEvolution`, `RegimeClassifierAtrNormalized`, `FitnessCalculator`.

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `Domain/Genetic/NsgaSelectionResult.php` | Create | DTO: next population + successful F/CR from trials |
| `Domain/Genetic/NsgaIIAlgorithm.php` | Create | Pure NSGA-II: non-domination rank, crowding distance, selection |
| `tests/Unit/Domain/Genetic/NsgaIIAlgorithmTest.php` | Create | Unit tests for NSGA-II algorithm |
| `Domain/Regime/RegimeLabeler.php` | Create | Labels each trading candle with a regime using an individual's genes |
| `tests/Unit/Domain/Regime/RegimeLabelerTest.php` | Create | Unit tests for RegimeLabeler |
| `Domain/Simulation/SimulationRunner.php` | Modify | Add optional `regimeMask` param to `run()` |
| `Application/GeneticOptimization/Service/NsgaRegimeOptimizer.php` | Create | 4 sub-population generation loop |
| `Application/GeneticOptimization/Command/OptimizeNsgaRegimeStrategyCommand.php` | Create | Message bus command |
| `Application/GeneticOptimization/Handler/OptimizeNsgaRegimeStrategyHandler.php` | Create | Message bus handler |
| `Infrastructure/Console/NsgaRegimeOptimizeCommand.php` | Create | Symfony console command `app:nsga-regime-optimize` |
| `Application/Config/RegimeAwareConfig.php` | Create | Immutable VO holding 4 StrategyConfigs keyed by regime |
| `Application/Service/PaperTradingEngine.php` | Modify | Switch genes per regime each tick |

---

## Task 1: `NsgaSelectionResult` DTO

**Files:**
- Create: `app/src/Domain/Genetic/NsgaSelectionResult.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Genetic;

use App\Application\DTO\Individual;

final readonly class NsgaSelectionResult
{
    /**
     * @param Individual[] $population  Next generation (sorted by rank ASC, crowding DESC)
     * @param float[]      $successF    F values of trial vectors that entered the next generation
     * @param float[]      $successCR   CR values of trial vectors that entered the next generation
     */
    public function __construct(
        public array $population,
        public array $successF,
        public array $successCR,
    ) {
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/src/Domain/Genetic/NsgaSelectionResult.php
git commit -m "feat: add NsgaSelectionResult DTO for NSGA-II selection tracking"
```

---

## Task 2: `NsgaIIAlgorithm` Domain Class + Tests

**Files:**
- Create: `app/src/Domain/Genetic/NsgaIIAlgorithm.php`
- Create: `app/tests/Unit/Domain/Genetic/NsgaIIAlgorithmTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Genetic;

use App\Application\Config\StrategyConfig;
use App\Application\DTO\Individual;
use App\Domain\Genetic\NsgaIIAlgorithm;
use App\Domain\Genetic\TrialResult;
use App\Domain\Simulation\SimulationResult;
use Codeception\Test\Unit;

class NsgaIIAlgorithmTest extends Unit
{
    private NsgaIIAlgorithm $nsga;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nsga = new NsgaIIAlgorithm();
    }

    public function testNonDominationRankFront0(): void
    {
        // A: profit=500, buys=50, dd=-100  → dominates B on all 3
        // B: profit=100, buys=20, dd=-300
        $a = $this->ind(realizedProfit: 500.0, buys: 50, maxDrawdown: -100.0);
        $b = $this->ind(realizedProfit: 100.0, buys: 20, maxDrawdown: -300.0);

        $result = $this->nsga->selectNextGeneration(
            parents:      [$a, $b],
            trialResults: [
                new TrialResult($b, 0.5, 0.8),
                new TrialResult($a, 0.5, 0.8),
            ],
            size: 2,
        );

        // A should be first (rank 0 = non-dominated)
        $this->assertSame(500.0, $result->population[0]->result->realizedProfit);
    }

    public function testCrowdingDistanceBoundaryIsInfinity(): void
    {
        // Front with 3 members spanning the objective space
        $a = $this->ind(realizedProfit: 10.0, buys: 100, maxDrawdown: -10.0);
        $b = $this->ind(realizedProfit: 50.0, buys: 50,  maxDrawdown: -50.0);
        $c = $this->ind(realizedProfit: 90.0, buys: 10,  maxDrawdown: -90.0);

        $distances = $this->nsga->crowdingDistance([$a, $b, $c]);

        // Boundary elements (sorted extremes) must be INF
        $this->assertTrue(
            in_array(INF, $distances, true),
            'At least two boundary members must have INF crowding distance'
        );
    }

    public function testSelectNextGenerationPrefersLowerRank(): void
    {
        // rank-0 parent vs rank-1 trial: parent must survive when size=1
        $dominant  = $this->ind(realizedProfit: 500.0, buys: 60, maxDrawdown: -50.0);
        $dominated = $this->ind(realizedProfit: 100.0, buys: 10, maxDrawdown: -200.0);

        $result = $this->nsga->selectNextGeneration(
            parents:      [$dominant],
            trialResults: [new TrialResult($dominated, 0.5, 0.8)],
            size: 1,
        );

        $this->assertSame(500.0, $result->population[0]->result->realizedProfit);
    }

    public function testSelectNextGenerationBreaksTiesByCrowding(): void
    {
        // Three individuals all non-dominated (incomparable): A, B, C
        // A: high profit, low buys    → extreme in profit objective
        // B: mid profit, mid buys     → middle (lower crowding)
        // C: low profit, high buys    → extreme in buys objective
        // size=2 → B (middle) should be dropped
        $a = $this->ind(realizedProfit: 900.0, buys: 10,  maxDrawdown: -50.0);
        $b = $this->ind(realizedProfit: 450.0, buys: 50,  maxDrawdown: -50.0);
        $c = $this->ind(realizedProfit: 10.0,  buys: 100, maxDrawdown: -50.0);

        $result = $this->nsga->selectNextGeneration(
            parents:      [$a, $b, $c],
            trialResults: [
                new TrialResult($a, 0.5, 0.8),
                new TrialResult($b, 0.5, 0.8),
                new TrialResult($c, 0.5, 0.8),
            ],
            size: 2,
        );

        $profits = array_map(fn(Individual $i) => $i->result->realizedProfit, $result->population);
        $this->assertContains(900.0, $profits, 'Extreme A should survive (INF crowding)');
        $this->assertContains(10.0,  $profits, 'Extreme C should survive (INF crowding)');
        $this->assertNotContains(450.0, $profits, 'Middle B should be dropped (lower crowding)');
    }

    public function testSuccessfulTrialsFandCRRecorded(): void
    {
        // Trial[0] enters next gen (parent does not dominate trial)
        // Trial[1] is dominated by its parent (parent is strictly better) → not recorded
        $weakParent  = $this->ind(realizedProfit: 100.0, buys: 10, maxDrawdown: -200.0);
        $strongTrial = $this->ind(realizedProfit: 500.0, buys: 50, maxDrawdown: -50.0);

        $strongParent = $this->ind(realizedProfit: 500.0, buys: 50, maxDrawdown: -50.0);
        $weakTrial    = $this->ind(realizedProfit: 100.0, buys: 10, maxDrawdown: -200.0);

        $result = $this->nsga->selectNextGeneration(
            parents:      [$weakParent, $strongParent],
            trialResults: [
                new TrialResult($strongTrial, F: 0.7, CR: 0.9), // enters next gen → recorded
                new TrialResult($weakTrial,   F: 0.3, CR: 0.4), // dominated by parent → parent wins
            ],
            size: 2,
        );

        $this->assertContains(0.7, $result->successF,  'F of surviving trial should be recorded');
        $this->assertContains(0.9, $result->successCR, 'CR of surviving trial should be recorded');
        $this->assertNotContains(0.3, $result->successF, 'F of non-surviving trial must not be recorded');
    }

    public function testObjectivesDrawdownScoreMaximized(): void
    {
        // maxDrawdown=-50 should score better than maxDrawdown=-200
        // (higher drawdownScore = less negative = better)
        $shallow = $this->ind(realizedProfit: 100.0, buys: 10, maxDrawdown: -50.0);
        $deep    = $this->ind(realizedProfit: 100.0, buys: 10, maxDrawdown: -200.0);

        // $shallow dominates $deep on drawdown objective
        // shallow should end up in rank-0 if $deep is the only competitor
        $result = $this->nsga->selectNextGeneration(
            parents:      [$shallow, $deep],
            trialResults: [
                new TrialResult($deep,    0.5, 0.8),
                new TrialResult($shallow, 0.5, 0.8),
            ],
            size: 1,
        );

        $this->assertSame(-50.0, $result->population[0]->result->maxDrawdown);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function ind(float $realizedProfit, int $buys, float $maxDrawdown): Individual
    {
        return new Individual(
            StrategyConfig::createDefault(),
            new SimulationResult(
                buys:           $buys,
                sells:          $buys,
                profitFactor:   $realizedProfit > 0 ? 2.0 : 0.5,
                pnlInclOpen:    $realizedProfit,
                realizedProfit: $realizedProfit,
                maxDrawdown:    $maxDrawdown,
                fitness:        0.0,
            ),
        );
    }
}
```

- [ ] **Step 2: Run tests to confirm they fail**

Inside the Docker container:
```bash
docker exec -it paybis-app sh -c "cd /app && php -d xdebug.mode=off vendor/bin/codecept run Unit Domain/Genetic/NsgaIIAlgorithmTest 2>&1 | tail -20"
```
Expected: FAIL — `NsgaIIAlgorithm` class not found.

- [ ] **Step 3: Implement `NsgaIIAlgorithm`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Genetic;

use App\Application\DTO\Individual;

/**
 * NSGA-II selection with MODE (Multi-Objective DE) variation.
 *
 * Three objectives, all maximized:
 *   [0] realizedProfit
 *   [1] buys (trade count)
 *   [2] −abs(maxDrawdown)   (less negative = better)
 */
final class NsgaIIAlgorithm
{
    /**
     * Combine parents + trial offspring, run non-dominated sort + crowding selection,
     * return next generation of size $size with JADE adaptation tracking.
     *
     * @param Individual[]  $parents
     * @param TrialResult[] $trialResults  One trial per parent (same order)
     */
    public function selectNextGeneration(
        array $parents,
        array $trialResults,
        int $size,
    ): NsgaSelectionResult {
        $n               = count($parents);
        $trialIndividuals = array_map(fn(TrialResult $t) => $t->trial, $trialResults);
        $combined        = array_merge($parents, $trialIndividuals);

        $fronts = $this->nonDominatedSort($combined);

        $nextPop         = [];
        $selectedIndices = [];

        foreach ($fronts as $front) {
            if (empty($front)) {
                continue;
            }

            $frontKeys = array_keys($front);
            $frontVals = array_values($front);

            if (count($nextPop) + count($front) <= $size) {
                // Whole front fits
                foreach ($frontKeys as $k => $originalIdx) {
                    $nextPop[]         = $frontVals[$k];
                    $selectedIndices[] = $originalIdx;
                }
            } else {
                // Partial front: pick by crowding distance (highest first)
                $distances = $this->crowdingDistance($frontVals);
                arsort($distances);
                $remaining = $size - count($nextPop);
                $taken     = 0;

                foreach (array_keys($distances) as $pos) {
                    if ($taken >= $remaining) {
                        break;
                    }

                    $nextPop[]         = $frontVals[$pos];
                    $selectedIndices[] = $frontKeys[$pos];
                    $taken++;
                }

                break;
            }

            if (count($nextPop) >= $size) {
                break;
            }
        }

        // Track F/CR for trial vectors that entered the next generation
        $successF  = [];
        $successCR = [];

        foreach ($selectedIndices as $idx) {
            if ($idx >= $n) {
                // This is a trial vector (indices $n..2n-1)
                $trialIdx    = $idx - $n;
                $successF[]  = $trialResults[$trialIdx]->F;
                $successCR[] = $trialResults[$trialIdx]->CR;
            }
        }

        return new NsgaSelectionResult($nextPop, $successF, $successCR);
    }

    /**
     * Crowding distance for a front (positional array, same order as input).
     * Boundary individuals on any objective get INF distance.
     *
     * @param Individual[] $front  Positional array (index 0..n-1)
     * @return float[]             Crowding distance per position
     */
    public function crowdingDistance(array $front): array
    {
        $n = count($front);

        if ($n <= 2) {
            return array_fill(0, $n, INF);
        }

        $distances = array_fill(0, $n, 0.0);

        for ($m = 0; $m < 3; $m++) {
            $indices = range(0, $n - 1);
            usort($indices, fn($a, $b) => $this->objectives($front[$a])[$m] <=> $this->objectives($front[$b])[$m]);

            $distances[$indices[0]]      = INF;
            $distances[$indices[$n - 1]] = INF;

            $objMin = $this->objectives($front[$indices[0]])[$m];
            $objMax = $this->objectives($front[$indices[$n - 1]])[$m];
            $range  = $objMax - $objMin;

            if ($range == 0.0) {
                continue;
            }

            for ($i = 1; $i < $n - 1; $i++) {
                $distances[$indices[$i]] += (
                    $this->objectives($front[$indices[$i + 1]])[$m] -
                    $this->objectives($front[$indices[$i - 1]])[$m]
                ) / $range;
            }
        }

        return $distances;
    }

    /**
     * Fast non-dominated sort (O(n^2)).
     *
     * @param Individual[] $population
     * @return array<int, array<int, Individual>>  Fronts: each front is array<original_index, Individual>
     */
    private function nonDominatedSort(array $population): array
    {
        $n           = count($population);
        $dominatedBy = array_fill(0, $n, 0);
        $dominates   = array_fill(0, $n, []);

        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if ($i === $j) {
                    continue;
                }

                if ($this->dominates($population[$i], $population[$j])) {
                    $dominates[$i][] = $j;
                } elseif ($this->dominates($population[$j], $population[$i])) {
                    $dominatedBy[$i]++;
                }
            }
        }

        $fronts    = [];
        $front0    = [];

        for ($i = 0; $i < $n; $i++) {
            if ($dominatedBy[$i] === 0) {
                $front0[$i] = $population[$i];
            }
        }

        $fronts[] = $front0;
        $current  = $front0;

        while (!empty($current)) {
            $next = [];

            foreach (array_keys($current) as $i) {
                foreach ($dominates[$i] as $j) {
                    $dominatedBy[$j]--;

                    if ($dominatedBy[$j] === 0) {
                        $next[$j] = $population[$j];
                    }
                }
            }

            if (!empty($next)) {
                $fronts[] = $next;
            }

            $current = $next;
        }

        return $fronts;
    }

    /**
     * Individual A dominates B if A is ≥ B on all objectives and > B on at least one.
     */
    private function dominates(Individual $a, Individual $b): bool
    {
        $objA = $this->objectives($a);
        $objB = $this->objectives($b);

        $strictlyBetter = false;

        for ($m = 0; $m < 3; $m++) {
            if ($objA[$m] < $objB[$m]) {
                return false; // A is strictly worse on this objective → cannot dominate
            }

            if ($objA[$m] > $objB[$m]) {
                $strictlyBetter = true;
            }
        }

        return $strictlyBetter;
    }

    /**
     * Extract the 3 objectives from an individual (all maximized).
     *
     * @return array{float, float, float}
     */
    private function objectives(Individual $ind): array
    {
        $r = $ind->result;

        return [
            $r->realizedProfit,        // maximize profit
            (float) $r->buys,          // maximize trade count
            -abs($r->maxDrawdown),     // maximize (= minimize drawdown magnitude)
        ];
    }
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
docker exec -it paybis-app sh -c "cd /app && php -d xdebug.mode=off vendor/bin/codecept run Unit Domain/Genetic/NsgaIIAlgorithmTest 2>&1 | tail -20"
```
Expected: All 6 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/src/Domain/Genetic/NsgaIIAlgorithm.php app/tests/Unit/Domain/Genetic/NsgaIIAlgorithmTest.php
git commit -m "feat: add NsgaIIAlgorithm domain class with non-dominated sort and crowding distance"
```

---

## Task 3: `RegimeLabeler` Domain Service + Tests

**Files:**
- Create: `app/src/Domain/Regime/RegimeLabeler.php`
- Create: `app/tests/Unit/Domain/Regime/RegimeLabelerTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Regime;

use App\Application\Config\StrategyConfig;
use App\Domain\Regime\MarketRegime;
use App\Domain\Regime\RegimeClassifierAtrNormalized;
use App\Domain\Regime\RegimeLabeler;
use App\Domain\Technical\TechnicalAnalysisService;
use Codeception\Test\Unit;

class RegimeLabelerTest extends Unit
{
    private RegimeLabeler $labeler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->labeler = new RegimeLabeler(
            new TechnicalAnalysisService(),
            new RegimeClassifierAtrNormalized(),
        );
    }

    public function testLabelCountMatchesTradingCandleCount(): void
    {
        $candles    = $this->makeCandles(300);
        $allCandles = ['1h' => $candles];
        $config     = StrategyConfig::createDefault();

        $mask = $this->labeler->buildMask($allCandles, $config, MarketRegime::Sideways);

        $this->assertCount(300, $mask, 'Mask must have one entry per trading candle');
    }

    public function testMaskValuesAreBooleans(): void
    {
        $candles    = $this->makeCandles(300);
        $allCandles = ['1h' => $candles];
        $config     = StrategyConfig::createDefault();

        $mask = $this->labeler->buildMask($allCandles, $config, MarketRegime::Sideways);

        foreach ($mask as $value) {
            $this->assertIsBool($value, 'All mask values must be boolean');
        }
    }

    public function testEarlyCandles_BelowWindowSize_ReturnFalse(): void
    {
        // First 250 candles have no warmup → must all be false
        $candles    = $this->makeCandles(260);
        $allCandles = ['1h' => $candles];
        $config     = StrategyConfig::createDefault();

        $mask = $this->labeler->buildMask($allCandles, $config, MarketRegime::Sideways);

        $falseCount = count(array_filter($mask, fn(bool $v) => !$v));
        $this->assertGreaterThanOrEqual(250, $falseCount, 'First 250 candles must return false (no warmup)');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function makeCandles(int $count): array
    {
        $candles   = [];
        $baseTime  = strtotime('2024-01-01 00:00:00');

        for ($i = 0; $i < $count; $i++) {
            $ts        = ($baseTime + $i * 3600) * 1000; // 1h in ms
            $price     = 90000.0 + ($i % 100) * 10.0;
            $candles[] = [
                'openTime'  => $ts,
                'closeTime' => $ts + 3599999,
                'open'      => $price,
                'high'      => $price + 100.0,
                'low'       => $price - 100.0,
                'close'     => $price,
                'volume'    => 10.0,
            ];
        }

        return $candles;
    }
}
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
docker exec -it paybis-app sh -c "cd /app && php -d xdebug.mode=off vendor/bin/codecept run Unit Domain/Regime/RegimeLabelerTest 2>&1 | tail -20"
```
Expected: FAIL — `RegimeLabeler` class not found.

- [ ] **Step 3: Implement `RegimeLabeler`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Regime;

use App\Application\Config\StrategyConfig;
use App\Domain\Technical\TechnicalAnalysisService;

/**
 * Labels each trading-interval candle with its market regime using the given individual's genes.
 *
 * Used by NsgaRegimeOptimizer to build a boolean mask per individual before simulation,
 * allowing SimulationRunner to skip buy entries on non-matching candles.
 */
final class RegimeLabeler
{
    private const int WINDOW_SIZE = 250;
    private const int ATR_PERIOD  = 14;

    public function __construct(
        private readonly TechnicalAnalysisService $taService,
        private readonly RegimeClassifierAtrNormalized $classifier,
    ) {
    }

    /**
     * Builds a mask: trading-candle unix timestamp → true if candle's regime matches $targetRegime.
     *
     * Candles before the warmup window (index < WINDOW_SIZE) are always false.
     * All candles are iterated (not filtered) so the ATR window is always available.
     *
     * @param array<string, array<int, array<string, mixed>>> $allCandles  All candles keyed by interval
     * @param StrategyConfig $config   Individual's config (uses its regime genes for classification)
     * @param MarketRegime $targetRegime  The regime to select
     * @return array<int, bool>           Unix timestamp (seconds) → in-regime boolean
     */
    public function buildMask(
        array $allCandles,
        StrategyConfig $config,
        MarketRegime $targetRegime,
    ): array {
        $interval = $config->get('interval');
        $candles  = $allCandles[$interval] ?? [];
        $genesArr = $config->geneArray();
        $mask     = [];
        $n        = count($candles);

        for ($i = 0; $i < $n; $i++) {
            $timestamp = (int) ($candles[$i]['openTime'] / 1000);

            if ($i < self::WINDOW_SIZE) {
                $mask[$timestamp] = false;
                continue;
            }

            $window = array_slice($candles, $i - self::WINDOW_SIZE, self::WINDOW_SIZE);

            $atr = $this->taService->calculateAtr(
                array_column($window, 'high'),
                array_column($window, 'low'),
                array_column($window, 'close'),
                self::ATR_PERIOD,
            );

            $regime           = $this->classifier->classify($window, $atr, $genesArr);
            $mask[$timestamp] = $regime === $targetRegime;
        }

        return $mask;
    }
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
docker exec -it paybis-app sh -c "cd /app && php -d xdebug.mode=off vendor/bin/codecept run Unit Domain/Regime/RegimeLabelerTest 2>&1 | tail -20"
```
Expected: All 3 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/src/Domain/Regime/RegimeLabeler.php app/tests/Unit/Domain/Regime/RegimeLabelerTest.php
git commit -m "feat: add RegimeLabeler — per-individual trading candle regime mask builder"
```

---

## Task 4: Modify `SimulationRunner::run()` to Accept `regimeMask`

**Files:**
- Modify: `app/src/Domain/Simulation/SimulationRunner.php`

- [ ] **Step 1: Add `$regimeMask` instance property and update `run()` signature**

Add the property after the existing constants (around line 34):
```php
/** @var array<int, bool> Unix timestamp → in-regime; empty = no filtering */
private array $regimeMask = [];
```

Update the `run()` method signature (around line 58):
```php
public function run(
    StrategyConfig $config,
    array $allCandles,
    \DateTimeImmutable $simStartDate,
    \DateTimeImmutable $simEndDate,
    array $regimeMask = [],
): SimulationResult {
    $this->regimeMask = $regimeMask;
    $this->profiler->reset();
    $this->positionRepository->reset();
    // ... rest of method unchanged
```

- [ ] **Step 2: Add regime mask check in `processCandle`**

In `processCandle`, between `$this->runPreBuyPhase(...)` and `$this->runBuyPhase(...)` (around line 137), add:

```php
[$openOrders, $atr] = $this->runPreBuyPhase($candle, $config, $genesArr, $tickState);

// Regime mask: skip buy evaluation on candles outside the target regime.
// Exits and stop ratcheting (runPreBuyPhase) still run on all candles.
if (!empty($this->regimeMask) && ($this->regimeMask[$candle->openTime->getTimestamp()] ?? false) === false) {
    return;
}

$this->runBuyPhase($candle, $atr, $openOrders, $config, $genesArr, $account, $stats, $tickState);
```

- [ ] **Step 3: Run the full unit suite to confirm no regressions**

```bash
docker exec -it paybis-app sh -c "cd /app && php -d xdebug.mode=off vendor/bin/codecept run Unit 2>&1 | tail -20"
```
Expected: All existing tests PASS. The new `regimeMask = []` default means all existing callers are unaffected.

- [ ] **Step 4: Commit**

```bash
git add app/src/Domain/Simulation/SimulationRunner.php
git commit -m "feat: add optional regimeMask to SimulationRunner::run() for regime-filtered evaluation"
```

---

## Task 5: `NsgaRegimeOptimizer` Application Service

**Files:**
- Create: `app/src/Application/GeneticOptimization/Service/NsgaRegimeOptimizer.php`

No unit tests for this service — its behavior is integration-level (depends on SimulationRunner + candle data).

- [ ] **Step 1: Create the optimizer**

```php
<?php

declare(strict_types=1);

namespace App\Application\GeneticOptimization\Service;

use App\Application\Config\StrategyConfig;
use App\Application\DTO\Individual;
use App\Application\Service\CandleLoader;
use App\Domain\Genetic\DifferentialEvolution;
use App\Domain\Genetic\FitnessCalculator;
use App\Domain\Genetic\Genome\GeneCatalog;
use App\Domain\Genetic\NsgaIIAlgorithm;
use App\Domain\Genetic\NsgaSelectionResult;
use App\Domain\Genetic\TrialResult;
use App\Domain\Regime\MarketRegime;
use App\Domain\Regime\RegimeLabeler;
use App\Domain\Simulation\SimulationRunner;
use App\Infrastructure\Persistence\JsonGeneStorage;
use Psr\Log\LoggerInterface;

final class NsgaRegimeOptimizer
{
    private const float INITIAL_MU_F  = 0.5;
    private const float INITIAL_MU_CR = 0.8;

    /** Maps MarketRegime::name → output JSON file name */
    private const array REGIME_FILES = [
        'Sideways'       => 'sideways_genes',
        'TrendingUp'     => 'trending_up_genes',
        'TrendingDown'   => 'trending_down_genes',
        'HighVolatility' => 'high_volatility_genes',
    ];

    /** All four regimes in iteration order */
    private const array REGIMES = [
        MarketRegime::Sideways,
        MarketRegime::TrendingUp,
        MarketRegime::TrendingDown,
        MarketRegime::HighVolatility,
    ];

    public function __construct(
        private readonly CandleLoader $candleLoader,
        private readonly SimulationRunner $simulationRunner,
        private readonly DifferentialEvolution $de,
        private readonly NsgaIIAlgorithm $nsga,
        private readonly RegimeLabeler $regimeLabeler,
        private readonly GeneCatalog $geneCatalog,
        private readonly LoggerInterface $logger,
        private readonly JsonGeneStorage $geneStorage,
        private readonly FitnessCalculator $fitnessCalculator = new FitnessCalculator(),
    ) {
    }

    /**
     * @param array<string, int|float> $seedGenes
     * @throws \Exception
     */
    public function optimize(
        int $populationSize,
        int $generations,
        \DateTimeImmutable $simStartDate,
        \DateTimeImmutable $simEndDate,
        array $seedGenes,
    ): void {
        ini_set('memory_limit', '4G');

        $baseConfig = StrategyConfig::createDefault()->withOverrides($seedGenes);

        $this->logger->info(sprintf(
            'NSGA-II Regime Optimizer | Period: <info>%s</info> → <info>%s</info>',
            $simStartDate->format('Y-m-d'),
            $simEndDate->format('Y-m-d'),
        ));
        $this->logger->info(sprintf(
            'Population: %d per regime | Generations: %d | Regimes: 4',
            $populationSize,
            $generations,
        ));

        $this->logger->info('Loading candles...');
        $allCandles = $this->candleLoader->load($baseConfig, $simStartDate, $simEndDate);

        // ── Initialise 4 sub-populations ────────────────────────────────────
        /** @var array<string, Individual[]> $populations keyed by MarketRegime::name */
        $populations = [];
        /** @var array<string, float> $muF */
        $muF  = [];
        /** @var array<string, float> $muCR */
        $muCR = [];

        foreach (self::REGIMES as $regime) {
            $name               = $regime->name;
            $random             = $this->de->initPopulation($populationSize - 1, $baseConfig);
            $populations[$name] = [new Individual($baseConfig), ...$random];
            $muF[$name]         = self::INITIAL_MU_F;
            $muCR[$name]        = self::INITIAL_MU_CR;
        }

        // ── Initial evaluation ───────────────────────────────────────────────
        $this->logger->info('Evaluating initial populations...');

        foreach (self::REGIMES as $regime) {
            $name             = $regime->name;
            $populations[$name] = $this->evaluateAll(
                $populations[$name],
                $allCandles,
                $simStartDate,
                $simEndDate,
                $regime,
            );
        }

        // ── Generation loop ──────────────────────────────────────────────────
        for ($gen = 1; $gen <= $generations; $gen++) {
            $logParts = ["Gen {$gen}/{$generations}"];

            foreach (self::REGIMES as $regime) {
                $name = $regime->name;
                $pop  = $populations[$name];

                $best   = $this->scalarizeBest($pop);
                $trials = $this->de->generateTrials($pop, $muF[$name], $muCR[$name], $best);

                $evaluatedTrials = $this->evaluateTrials(
                    $trials,
                    $allCandles,
                    $simStartDate,
                    $simEndDate,
                    $regime,
                );

                $result             = $this->nsga->selectNextGeneration($pop, $evaluatedTrials, $populationSize);
                $populations[$name] = $result->population;

                [$muF[$name], $muCR[$name]] = $this->de->adaptParameters(
                    $muF[$name],
                    $muCR[$name],
                    $result->successF,
                    $result->successCR,
                );

                $frontSize = $this->countFront0($pop);
                $best      = $this->scalarizeBest($populations[$name]);

                $logParts[] = sprintf(
                    '  %-15s| front=%2d | fitness=%5.2f | PF=%4.2f | buys=%2d | μF=%.3f | μCR=%.3f',
                    $name,
                    $frontSize,
                    $best->fitness(),
                    $best->result->profitFactor,
                    $best->result->buys,
                    $muF[$name],
                    $muCR[$name],
                );
            }

            foreach ($logParts as $line) {
                $this->logger->info($line);
            }
            $this->logger->info('');
        }

        // ── Save winners ─────────────────────────────────────────────────────
        $sep = str_repeat('═', 72);
        $this->logger->info($sep);
        $this->logger->info(' NSGA-II REGIME OPTIMIZATION COMPLETE');
        $this->logger->info(sprintf(' Period: %s → %s', $simStartDate->format('Y-m-d'), $simEndDate->format('Y-m-d')));
        $this->logger->info($sep);

        foreach (self::REGIMES as $regime) {
            $name   = $regime->name;
            $winner = $this->scalarizeBest($populations[$name]);
            $file   = self::REGIME_FILES[$name];

            $this->geneStorage->save($file, $winner, $simStartDate, $simEndDate);

            $this->logger->info(sprintf(
                ' [%s] fitness=%.4f | PF=%.2f | profit=%+.2f | buys=%d | dd=%.2f → %s',
                $name,
                $winner->fitness(),
                $winner->result->profitFactor,
                $winner->result->realizedProfit,
                $winner->result->buys,
                $winner->result->maxDrawdown,
                $file . '.json',
            ));
        }

        $this->logger->info($sep);
    }

    /**
     * @param Individual[] $population
     * @return Individual[]
     */
    private function evaluateAll(
        array $population,
        array $allCandles,
        \DateTimeImmutable $simStartDate,
        \DateTimeImmutable $simEndDate,
        MarketRegime $regime,
    ): array {
        $evaluated = [];

        foreach ($population as $individual) {
            $evaluated[] = $this->evaluate($individual, $allCandles, $simStartDate, $simEndDate, $regime);
        }

        return $evaluated;
    }

    /**
     * @param TrialResult[] $trials
     * @return TrialResult[]
     */
    private function evaluateTrials(
        array $trials,
        array $allCandles,
        \DateTimeImmutable $simStartDate,
        \DateTimeImmutable $simEndDate,
        MarketRegime $regime,
    ): array {
        $evaluated = [];

        foreach ($trials as $trialResult) {
            $evaluatedInd = $this->evaluate(
                $trialResult->trial,
                $allCandles,
                $simStartDate,
                $simEndDate,
                $regime,
            );
            $evaluated[] = new TrialResult($evaluatedInd, $trialResult->F, $trialResult->CR);
        }

        return $evaluated;
    }

    private function evaluate(
        Individual $individual,
        array $allCandles,
        \DateTimeImmutable $simStartDate,
        \DateTimeImmutable $simEndDate,
        MarketRegime $regime,
    ): Individual {
        $mask   = $this->regimeLabeler->buildMask($allCandles, $individual->config, $regime);
        $result = $this->simulationRunner->run(
            $individual->config,
            $allCandles,
            $simStartDate,
            $simEndDate,
            regimeMask: $mask,
        );

        return $individual->withResult($result);
    }

    /**
     * Pick the best individual in a population by scalarized fitness.
     */
    private function scalarizeBest(array $population): Individual
    {
        usort($population, fn(Individual $a, Individual $b) => $b->fitness() <=> $a->fitness());

        return $population[0];
    }

    /**
     * Count how many individuals are in the Pareto front (rank 0).
     * An individual is rank-0 if no other individual dominates it.
     */
    private function countFront0(array $population): int
    {
        $count = 0;

        foreach ($population as $candidate) {
            $isDominated = false;

            foreach ($population as $other) {
                if ($candidate === $other) {
                    continue;
                }

                $oProfit = $other->result->realizedProfit;
                $oBuys   = (float) $other->result->buys;
                $oDD     = -abs($other->result->maxDrawdown);
                $cProfit = $candidate->result->realizedProfit;
                $cBuys   = (float) $candidate->result->buys;
                $cDD     = -abs($candidate->result->maxDrawdown);

                if ($oProfit >= $cProfit && $oBuys >= $cBuys && $oDD >= $cDD
                    && ($oProfit > $cProfit || $oBuys > $cBuys || $oDD > $cDD)) {
                    $isDominated = true;
                    break;
                }
            }

            if (!$isDominated) {
                $count++;
            }
        }

        return $count;
    }
}
```

- [ ] **Step 2: Run static analysis**

```bash
docker exec -it paybis-app sh -c "cd /app && php -d xdebug.mode=off vendor/bin/phpstan analyse src/Application/GeneticOptimization/Service/NsgaRegimeOptimizer.php --level=2 --memory-limit=512M 2>&1 | tail -30"
```
Expected: No errors.

- [ ] **Step 3: Commit**

```bash
git add app/src/Application/GeneticOptimization/Service/NsgaRegimeOptimizer.php
git commit -m "feat: add NsgaRegimeOptimizer — 4 regime sub-populations with NSGA-II/MODE"
```

---

## Task 6: Message Bus Command + Handler

**Files:**
- Create: `app/src/Application/GeneticOptimization/Command/OptimizeNsgaRegimeStrategyCommand.php`
- Create: `app/src/Application/GeneticOptimization/Handler/OptimizeNsgaRegimeStrategyHandler.php`

- [ ] **Step 1: Create the command**

```php
<?php

declare(strict_types=1);

namespace App\Application\GeneticOptimization\Command;

use App\Application\Messenger\LockableMessage;
use App\Application\ValueObject\SimulationPeriod;

final class OptimizeNsgaRegimeStrategyCommand implements LockableMessage
{
    public function __construct(
        public readonly string $genesFile,
        public readonly int $populationSize,
        public readonly int $generations,
        public readonly SimulationPeriod $simulationPeriod,
    ) {
        if ($populationSize <= 3) {
            throw new \InvalidArgumentException('NSGA-II requires population > 3');
        }

        if ($generations <= 0) {
            throw new \InvalidArgumentException('Generations must be > 0');
        }
    }

    public function getLockName(): string
    {
        return 'nsga-regime-optimize-lock';
    }
}
```

- [ ] **Step 2: Create the handler**

```php
<?php

declare(strict_types=1);

namespace App\Application\GeneticOptimization\Handler;

use App\Application\GeneticOptimization\Command\OptimizeNsgaRegimeStrategyCommand;
use App\Application\GeneticOptimization\Service\NsgaRegimeOptimizer;
use App\Infrastructure\Persistence\JsonGeneStorage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class OptimizeNsgaRegimeStrategyHandler
{
    public function __construct(
        private readonly NsgaRegimeOptimizer $optimizer,
        private readonly JsonGeneStorage $repository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(OptimizeNsgaRegimeStrategyCommand $command): void
    {
        $seedData = $this->repository->load($command->genesFile);
        $seedGenes = $seedData['genes'] ?? [];

        $this->logger->info('Starting NSGA-II regime optimization from {{file}}', [
            'file' => $command->genesFile,
        ]);

        $this->optimizer->optimize(
            populationSize: $command->populationSize,
            generations:    $command->generations,
            simStartDate:   $command->simulationPeriod->start,
            simEndDate:     $command->simulationPeriod->end,
            seedGenes:      $seedGenes,
        );

        $this->logger->info('NSGA-II regime optimization complete. Four genome files saved.');
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/src/Application/GeneticOptimization/Command/OptimizeNsgaRegimeStrategyCommand.php \
        app/src/Application/GeneticOptimization/Handler/OptimizeNsgaRegimeStrategyHandler.php
git commit -m "feat: add OptimizeNsgaRegimeStrategyCommand and handler"
```

---

## Task 7: Symfony Console Command

**Files:**
- Create: `app/src/Infrastructure/Console/NsgaRegimeOptimizeCommand.php`

- [ ] **Step 1: Create the console command**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Application\GeneticOptimization\Command\OptimizeNsgaRegimeStrategyCommand;
use App\Application\ValueObject\SimulationPeriod;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

// php -d xdebug.mode=off -d opcache.enable_cli=1 bin/console app:nsga-regime-optimize
#[AsCommand(
    name: 'app:nsga-regime-optimize',
    description: 'Optimises strategy parameters per market regime using NSGA-II with DE/current-to-best/1/bin variation.',
)]
final class NsgaRegimeOptimizeCommand extends Command
{
    private const int    DEFAULT_POPULATION  = 60;
    private const int    DEFAULT_GENERATIONS = 50;
    private const string DEFAULT_GENES       = 'default_genes';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('genes', null, InputOption::VALUE_OPTIONAL, 'Name of JSON gene file to seed from', self::DEFAULT_GENES)
            ->addOption('population', null, InputOption::VALUE_OPTIONAL, 'Population size per regime (must be > 3)', self::DEFAULT_POPULATION)
            ->addOption('generations', null, InputOption::VALUE_OPTIONAL, 'Number of generations', self::DEFAULT_GENERATIONS)
            ->addOption('sim-start-date', null, InputOption::VALUE_REQUIRED, 'Simulation start date (e.g. "2024-09-01")')
            ->addOption('sim-end-date', null, InputOption::VALUE_OPTIONAL, 'Simulation end date (default: now)', 'now');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->messageBus->dispatch($this->buildCommand($input));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());
            $this->logger->error($e->getTraceAsString());

            return Command::FAILURE;
        }
    }

    /** @throws \DateMalformedStringException */
    private function buildCommand(InputInterface $input): OptimizeNsgaRegimeStrategyCommand
    {
        return new OptimizeNsgaRegimeStrategyCommand(
            genesFile:        (string) $input->getOption('genes'),
            populationSize:   (int) $input->getOption('population'),
            generations:      (int) $input->getOption('generations'),
            simulationPeriod: new SimulationPeriod(
                new \DateTimeImmutable((string) $input->getOption('sim-start-date')),
                new \DateTimeImmutable((string) $input->getOption('sim-end-date')),
            ),
        );
    }
}
```

- [ ] **Step 2: Verify the command is visible**

```bash
docker exec -it paybis-app sh -c "cd /app && php -d xdebug.mode=off bin/console list app 2>&1 | grep nsga"
```
Expected: `app:nsga-regime-optimize` appears in the list.

- [ ] **Step 3: Commit**

```bash
git add app/src/Infrastructure/Console/NsgaRegimeOptimizeCommand.php
git commit -m "feat: add app:nsga-regime-optimize console command"
```

---

## Task 8: `RegimeAwareConfig` Value Object

**Files:**
- Create: `app/src/Application/Config/RegimeAwareConfig.php`

- [ ] **Step 1: Create the value object**

```php
<?php

declare(strict_types=1);

namespace App\Application\Config;

use App\Domain\Regime\MarketRegime;
use App\Infrastructure\Persistence\JsonGeneStorage;

/**
 * Immutable holder of one StrategyConfig per market regime.
 * Loaded once at startup; zero disk reads at tick time.
 */
final class RegimeAwareConfig
{
    /** @param array<string, StrategyConfig> $configs Keyed by MarketRegime::name */
    public function __construct(private readonly array $configs)
    {
    }

    /**
     * Returns the config for the given regime, falling back to Sideways if not present.
     */
    public function forRegime(MarketRegime $regime): StrategyConfig
    {
        return $this->configs[$regime->name] ?? $this->configs[MarketRegime::Sideways->name];
    }

    /**
     * Loads four regime gene files from disk.
     * Falls back to StrategyConfig::createDefault() for any missing file.
     */
    public static function fromFiles(JsonGeneStorage $storage): self
    {
        $fileMap = [
            MarketRegime::Sideways->name       => 'sideways_genes',
            MarketRegime::TrendingUp->name     => 'trending_up_genes',
            MarketRegime::TrendingDown->name   => 'trending_down_genes',
            MarketRegime::HighVolatility->name => 'high_volatility_genes',
        ];

        $configs = [];

        foreach ($fileMap as $regimeName => $fileName) {
            $data            = $storage->load($fileName);
            $configs[$regimeName] = $data !== null
                ? StrategyConfig::createDefault()->withOverrides($data['genes'])
                : StrategyConfig::createDefault();
        }

        return new self($configs);
    }
}
```

- [ ] **Step 2: Run static analysis**

```bash
docker exec -it paybis-app sh -c "cd /app && php -d xdebug.mode=off vendor/bin/phpstan analyse src/Application/Config/RegimeAwareConfig.php --level=2 --memory-limit=512M 2>&1 | tail -20"
```
Expected: No errors.

- [ ] **Step 3: Commit**

```bash
git add app/src/Application/Config/RegimeAwareConfig.php
git commit -m "feat: add RegimeAwareConfig — loads and serves four per-regime StrategyConfigs"
```

---

## Task 9: Modify `PaperTradingEngine` for Regime-Aware Config

**Files:**
- Modify: `app/src/Application/Service/PaperTradingEngine.php`

- [ ] **Step 1: Add `RegimeAwareConfig` as optional constructor injection**

In the constructor, add after the existing `SellOrderRepositoryInterface` parameter:

```php
private readonly ?RegimeAwareConfig $regimeAwareConfig = null,
```

Add the import at the top:
```php
use App\Application\Config\RegimeAwareConfig;
```

- [ ] **Step 2: Switch genes per tick after regime classification**

In `tick()`, after the regime classification block (around line 190–195):

```php
// ── 7. Regime gate (cheaper check before BuyStrategy computation) ────
$regime       = $this->regimeClassifier->classify($window, $atr, $genesArr);
$regimeEffect = $this->regimePolicy->effectFor($regime, $genesArr);
if (!$regimeEffect->allowEntry) {
    $this->stats->filterRejections['regime']++;
    return $result;
}
```

Replace with:

```php
// ── 7. Regime gate ────────────────────────────────────────────────────
// Classify regime with the default/reference genes (avoids circular dependency).
// If regime-aware configs are loaded, switch to the regime-specific genes for buy evaluation.
$regime = $this->regimeClassifier->classify($window, $atr, $genesArr);

if ($this->regimeAwareConfig !== null) {
    $genesArr = $this->regimeAwareConfig->forRegime($regime)->geneArray();
}

$regimeEffect = $this->regimePolicy->effectFor($regime, $genesArr);
if (!$regimeEffect->allowEntry) {
    $this->stats->filterRejections['regime']++;
    return $result;
}
```

- [ ] **Step 3: Run the full unit suite to confirm no regressions**

```bash
docker exec -it paybis-app sh -c "cd /app && php -d xdebug.mode=off vendor/bin/codecept run Unit 2>&1 | tail -20"
```
Expected: All existing tests PASS. The `?RegimeAwareConfig = null` default leaves existing behavior unchanged.

- [ ] **Step 4: Commit**

```bash
git add app/src/Application/Service/PaperTradingEngine.php
git commit -m "feat: PaperTradingEngine switches gene set per regime when RegimeAwareConfig provided"
```

---

## Task 10: End-to-End Smoke Test

- [ ] **Step 1: Run the optimizer on a short period with a small population**

```bash
docker exec -it paybis-app sh -c "cd /app && php -d xdebug.mode=off bin/console app:nsga-regime-optimize \
  --sim-start-date=2024-09-01 \
  --sim-end-date=2025-03-01 \
  --population=10 \
  --generations=5 \
  2>&1 | tail -60"
```

**Pass criteria:**
- Logs show "Gen 1/5" through "Gen 5/5" each with 4 regime rows
- Log lines include `Sideways`, `TrendingUp`, `TrendingDown`, `HighVolatility`
- `front=` values appear in logs (must be ≥ 1)
- `μF` and `μCR` values appear in logs

- [ ] **Step 2: Confirm four gene files were written**

```bash
docker exec -it paybis-app sh -c "ls -la /app/src/Application/Config/ | grep genes"
```

Expected: At least `sideways_genes.json`, `trending_up_genes.json`, `trending_down_genes.json`, `high_volatility_genes.json` are present.

- [ ] **Step 3: Run PHPStan on all new files**

```bash
docker exec -it paybis-app sh -c "cd /app && php -d xdebug.mode=off vendor/bin/phpstan analyse \
  src/Domain/Genetic/NsgaIIAlgorithm.php \
  src/Domain/Genetic/NsgaSelectionResult.php \
  src/Domain/Regime/RegimeLabeler.php \
  src/Application/GeneticOptimization/Service/NsgaRegimeOptimizer.php \
  src/Application/GeneticOptimization/Command/OptimizeNsgaRegimeStrategyCommand.php \
  src/Application/GeneticOptimization/Handler/OptimizeNsgaRegimeStrategyHandler.php \
  src/Infrastructure/Console/NsgaRegimeOptimizeCommand.php \
  src/Application/Config/RegimeAwareConfig.php \
  --level=2 --memory-limit=512M 2>&1 | tail -20"
```

Expected: No errors.

- [ ] **Step 4: Run PSR-12 style check**

```bash
docker exec -it paybis-app sh -c "cd /app && composer cs-check 2>&1 | tail -20"
```

Fix any issues with `composer cs-fix`, then re-run the check.

- [ ] **Step 5: Commit final state**

```bash
git add -A
git commit -m "feat: complete NSGA-II regime optimizer implementation + smoke test verified"
```
