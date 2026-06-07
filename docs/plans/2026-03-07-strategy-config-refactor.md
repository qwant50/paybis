# StrategyConfig Array-backed Refactor Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace StrategyConfig's 37 named public properties with a private `array $params` + private `Genome $genes`, exposing `get(string $key): mixed` and `geneArray(): array` instead.

**Architecture:** Single class change (`StrategyConfig`) with mechanical consumer updates. No behavioral change. `withOverrides()` shrinks from 40 lines to 10. Adding a static param goes from 4 edits to 1.

**Tech Stack:** PHP 8.4, Codeception 5.0 (`vendor/bin/codecept run Unit` inside the `paybis-app` Docker container)

---

## Task 1: Rewrite `StrategyConfig`

**Files:**
- Modify: `app/src/Application/Config/StrategyConfig.php`

**Step 1: Replace the file contents**

```php
<?php

namespace App\Application\Config;

use App\Domain\Genetic\Constraint\MacdConstraint;
use App\Domain\Genetic\Genome\GeneCatalog;
use App\Domain\Genetic\Genome\Genome;

final class StrategyConfig
{
    private array $params;
    private Genome $genes;

    private function __construct(array $params, Genome $genes)
    {
        $this->params = $params;
        $this->genes  = $genes;
        $this->validate();
    }

    private static function defaults(): array
    {
        return [
            'symbol'                   => 'DOGEUSDT',
            'interval'                 => '1h',
            'simStartDate'             => '2025-01-01',
            'simEndDate'               => '2025-09-21',
            'buyCount'                 => 1000.0,
            'feePercent'               => 0.075,
            'maxOpenPositions'         => 3,
            'signalThreshold'          => -30,
            'minStopPercent'           => 2.0,
            'baseRR'                   => 2.0,
            'tierMultipliers'          => [0.6, 1.0, 1.4],
            'tierSplits'               => [0.33, 0.33, 0.34],
            'exitMode'                 => 'tiers',
            'requireUptrend'           => false,
            'minRvol'                  => null,
            'maxRsiForEntry'           => 45,
            'confidenceSizing'         => true,
            'trendStrengthBonus'       => false,
            'minStopRR'                => null,
            'riskManagement'           => ['accountBalance' => 10_000, 'maxRiskPercent' => 1.5],
            'macro_timeframe'          => '4h',
            'macro_smma'               => ['shortPeriod' => 20, 'longPeriod' => 50],
            'confluence_timeframes'    => ['1h', '15m', '5m', '1m'],
            'primary_timeframes'       => ['4h', '1h'],
            'interval_configs'         => [
                '1m'  => [
                    'adx_period'       => 14,
                    'rsi_period'       => 5,
                    'macd'             => ['fast' => 5, 'slow' => 13, 'signal' => 5],
                    'filters'          => [
                        'adxThreshold'    => 20,
                        'rsiOversold'     => 20,
                        'rsiOverbought'   => 80,
                        'cmfAccumulation' => 0.05,
                        'cmfDistribution' => -0.05,
                    ],
                    'ema'              => ['fast' => 5, 'slow' => 13],
                    'confluenceWeight' => 5,
                ],
                '5m'  => [
                    'adx_period'       => 14,
                    'rsi_period'       => 7,
                    'macd'             => ['fast' => 8, 'slow' => 17, 'signal' => 6],
                    'filters'          => [
                        'adxThreshold'    => 22,
                        'rsiOversold'     => 25,
                        'rsiOverbought'   => 75,
                        'cmfAccumulation' => 0.05,
                        'cmfDistribution' => -0.05,
                    ],
                    'ema'              => ['fast' => 8, 'slow' => 21],
                    'confluenceWeight' => 20,
                ],
                '15m' => [
                    'adx_period'       => 14,
                    'rsi_period'       => 9,
                    'macd'             => ['fast' => 10, 'slow' => 21, 'signal' => 7],
                    'filters'          => [
                        'adxThreshold'    => 23,
                        'rsiOversold'     => 28,
                        'rsiOverbought'   => 72,
                        'cmfAccumulation' => 0.05,
                        'cmfDistribution' => -0.05,
                    ],
                    'ema'              => ['fast' => 10, 'slow' => 30],
                    'confluenceWeight' => 25,
                ],
                '1h'  => [
                    'adx_period'       => 14,
                    'rsi_period'       => 14,
                    'macd'             => ['fast' => 12, 'slow' => 26, 'signal' => 9],
                    'filters'          => [
                        'adxThreshold'    => 25,
                        'rsiOversold'     => 30,
                        'rsiOverbought'   => 70,
                        'cmfAccumulation' => 0.05,
                        'cmfDistribution' => -0.05,
                    ],
                    'ema'              => ['fast' => 20, 'slow' => 50],
                    'confluenceWeight' => 15,
                ],
                '4h'  => [
                    'adx_period'       => 14,
                    'rsi_period'       => 14,
                    'macd'             => ['fast' => 12, 'slow' => 26, 'signal' => 9],
                    'filters'          => [
                        'adxThreshold'    => 25,
                        'rsiOversold'     => 30,
                        'rsiOverbought'   => 70,
                        'cmfAccumulation' => 0.05,
                        'cmfDistribution' => -0.05,
                    ],
                    'ema'              => ['fast' => 20, 'slow' => 50],
                    'confluenceWeight' => 20,
                ],
            ],
            'walkForward'              => ['enabled' => false, 'trainEndDate' => null],
            'minAdxForEntry'           => 16,
            'trailingMode'             => null,
            'trailingAtrMultiplier'    => null,
            'breakevenAfterR'          => null,
            'regimeFilter'             => false,
            'entryTimingFilter'        => null,
            'dynamicSizing'            => false,
            'minSizeFactor'            => 0.5,
            'maxSizeFactor'            => 2.0,
            'signalConsensusThreshold' => 0.70,
            'signalTfWeightRatio'      => 1.0,
        ];
    }

    public static function createDefault(): self
    {
        $catalog = new GeneCatalog();
        $json    = json_decode(file_get_contents(__DIR__ . '/default_genes.json'), true);
        $genome  = $catalog->genomeFromArray($json['genes']);

        return new self(self::defaults(), $genome);
    }

    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->params)) {
            throw new \InvalidArgumentException("Unknown param: {$key}");
        }

        return $this->params[$key];
    }

    public function geneArray(): array
    {
        return $this->genes->toArray();
    }

    public function withOverrides(array $overrides): self
    {
        $geneKeys  = array_flip(array_keys((new GeneCatalog())->all()));
        $geneOvr   = array_intersect_key($overrides, $geneKeys);
        $staticOvr = array_diff_key($overrides, $geneKeys);

        $newGenes = !empty($geneOvr) ? $this->genes->withValues($geneOvr) : $this->genes;
        if (!empty($geneOvr)) {
            (new MacdConstraint())->repair($newGenes);
        }

        return new self(array_merge($this->params, $staticOvr), $newGenes);
    }

    public function toArray(): array
    {
        return array_merge($this->params, $this->genes->toArray());
    }

    private function validate(): void
    {
        if ($this->params['maxOpenPositions'] < 1) {
            throw new \InvalidArgumentException('maxOpenPositions must be at least 1');
        }

        if ($this->params['feePercent'] < 0 || $this->params['feePercent'] >= 100) {
            throw new \InvalidArgumentException('feePercent must be >= 0 and < 100');
        }

        if ($this->params['buyCount'] <= 0) {
            throw new \InvalidArgumentException('buyCount must be greater than 0');
        }

        if ($this->params['baseRR'] <= 0) {
            throw new \InvalidArgumentException('baseRR must be greater than 0');
        }
    }
}
```

**Step 2: Run tests — expect failures on property access**

```bash
docker exec -it paybis-app vendor/bin/codecept run Unit
```

Expected: many failures in `StrategyConfigTest` and `GeneticConfigTest` — those still use `->symbol`, `->genes` etc. This confirms the old API is gone.

**Step 3: Commit the core change**

```bash
git add app/src/Application/Config/StrategyConfig.php
git commit -m "refactor: StrategyConfig — private array params + encapsulated Genome"
```

---

## Task 2: Update `StrategyConfigTest`

**Files:**
- Modify: `app/tests/Unit/Application/DTO/StrategyConfigTest.php`

**Step 1: Replace all direct property accesses with `get()` and `genes->toArray()` with `geneArray()`**

In `testDefaultConfigMatchesExpectedValues()`:
```php
// Before → After for each line:
$defaults->symbol          → $defaults->get('symbol')
$defaults->interval        → $defaults->get('interval')
$defaults->simStartDate    → $defaults->get('simStartDate')
$defaults->simEndDate      → $defaults->get('simEndDate')
$defaults->buyCount        → $defaults->get('buyCount')
$defaults->feePercent      → $defaults->get('feePercent')
$defaults->maxOpenPositions → $defaults->get('maxOpenPositions')
$defaults->minStopPercent  → $defaults->get('minStopPercent')
$defaults->baseRR          → $defaults->get('baseRR')
$defaults->tierMultipliers → $defaults->get('tierMultipliers')
$defaults->tierSplits      → $defaults->get('tierSplits')
$defaults->exitMode        → $defaults->get('exitMode')
$defaults->requireUptrend  → $defaults->get('requireUptrend')
$defaults->minRvol         → $defaults->get('minRvol')
$defaults->maxRsiForEntry  → $defaults->get('maxRsiForEntry')
$defaults->confidenceSizing → $defaults->get('confidenceSizing')
$defaults->trendStrengthBonus → $defaults->get('trendStrengthBonus')
$defaults->minStopRR       → $defaults->get('minStopRR')
$defaults->riskManagement  → $defaults->get('riskManagement')
$defaults->macro_timeframe → $defaults->get('macro_timeframe')
$defaults->macro_smma      → $defaults->get('macro_smma')
$defaults->confluence_timeframes → $defaults->get('confluence_timeframes')
$defaults->primary_timeframes → $defaults->get('primary_timeframes')
$defaults->walkForward     → $defaults->get('walkForward')
$defaults->minAdxForEntry  → $defaults->get('minAdxForEntry')
$defaults->trailingMode    → $defaults->get('trailingMode')
$defaults->trailingAtrMultiplier → $defaults->get('trailingAtrMultiplier')
$defaults->breakevenAfterR → $defaults->get('breakevenAfterR')
$defaults->interval_configs → $defaults->get('interval_configs')

// interval_configs assertion:
$this->assertCount(5, $defaults->interval_configs);
→ $this->assertCount(5, $defaults->get('interval_configs'));

// and:
$this->assertArrayHasKey('1m', $defaults->interval_configs);
→ $this->assertArrayHasKey('1m', $defaults->get('interval_configs'));
// (same for '5m', '15m', '1h', '4h')

// genes access:
$testCfg->genes->toArray() → $testCfg->geneArray()
```

In `testWithOverridesReturnsNewInstance()`:
```php
$this->assertSame('DOGEUSDT', $original->symbol);    → $original->get('symbol')
$this->assertSame('BTCUSDT', $modified->symbol);     → $modified->get('symbol')
```

In `testWithOverridesPreservesUnchangedValues()`:
```php
$modified->symbol       → $modified->get('symbol')
$modified->buyCount     → $modified->get('buyCount')
$original->interval     → $original->get('interval')
$original->feePercent   → $original->get('feePercent')
$original->genes->toArray() → $original->geneArray()
$modified->genes->toArray() → $modified->geneArray()
$original->maxOpenPositions → $original->get('maxOpenPositions')
$original->signalThreshold  → $original->get('signalThreshold')
$original->baseRR       → $original->get('baseRR')
$modified->confidenceSizing → $modified->get('confidenceSizing')
```

In `testWithOverridesAcceptsNestedRiskManagement()`:
```php
$modified->riskManagement['accountBalance'] → $modified->get('riskManagement')['accountBalance']
$modified->riskManagement['maxRiskPercent'] → $modified->get('riskManagement')['maxRiskPercent']
$config->riskManagement['accountBalance']   → $config->get('riskManagement')['accountBalance']
```

In `testNewGenesAreWiredViaConstructor()` and `testWithRepairsMacd*()`:
```php
$config->genes->toArray() → $config->geneArray()
$repaired->genes->toArray() → $repaired->geneArray()
```

**Step 2: Run tests**

```bash
docker exec -it paybis-app vendor/bin/codecept run Unit/Application/DTO/StrategyConfigTest
```

Expected: all pass.

**Step 3: Commit**

```bash
git add app/tests/Unit/Application/DTO/StrategyConfigTest.php
git commit -m "test: update StrategyConfigTest to use get() and geneArray()"
```

---

## Task 3: Update `GeneticConfigTest`

**Files:**
- Modify: `app/tests/Unit/Application/Config/GeneticConfigTest.php`

**Step 1: Replace direct `->genes` accesses**

`GeneticConfigTest` tests some behavior that's now internal to `StrategyConfig`. Since `Genome` is private, tests that call `->genes->toArray()` or `->genes->withValues()` must go through `StrategyConfig`'s public API.

Replace the entire file:

```php
<?php

namespace Tests\Unit\Application\Config;

use App\Application\Config\StrategyConfig;
use App\Domain\Genetic\Genome\GeneCatalog;
use Codeception\Test\Unit;

class GeneticConfigTest extends Unit
{
    private GeneCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = new GeneCatalog();
    }

    public function testGenomeFromArrayRoundTrip(): void
    {
        $defaults = StrategyConfig::createDefault();
        $values   = $defaults->geneArray();
        $genome   = $this->catalog->genomeFromArray($values);

        $this->assertSame($values, $genome->toArray());
    }

    public function testWithOverridesSetsIndividualGene(): void
    {
        $base    = StrategyConfig::createDefault();
        $updated = $base->withOverrides(['atrStopMultiplier' => 5.0]);

        $this->assertSame(5.0, $updated->geneArray()['atrStopMultiplier']);
        // others preserved
        $this->assertSame($base->geneArray()['trailingPercent'], $updated->geneArray()['trailingPercent']);
    }

    public function testWithOverridesPreservesOtherGenes(): void
    {
        $base    = StrategyConfig::createDefault();
        $updated = $base->withOverrides(['trailingPercent' => 4.5]);

        $this->assertSame(4.5, $updated->geneArray()['trailingPercent']);
        $this->assertSame($base->geneArray()['atrStopMultiplier'], $updated->geneArray()['atrStopMultiplier']);
    }

    public function testWithOverridesRepairsMacdConstraint(): void
    {
        $repaired = StrategyConfig::createDefault()->withOverrides([
            'bsMacdFast'   => 7,
            'bsMacdSpread' => 19,
            'bsMacdSignal' => 99,
        ]);

        $genes = $repaired->geneArray();
        $this->assertLessThan($genes['bsMacdFast'] + $genes['bsMacdSpread'], $genes['bsMacdSignal']);
    }

    public function testGenomeFromArrayThrowsOnMissingKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing gene value');

        $this->catalog->genomeFromArray(['atrStopMultiplier' => 5.0]); // missing 47 others
    }

    public function testWithOverridesThrowsOnUnknownGene(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StrategyConfig::createDefault()->withOverrides(['nonExistentGene' => 1.0]);
    }

    public function testGeneArrayContainsAll48Genes(): void
    {
        $config = StrategyConfig::createDefault();
        $this->assertCount(48, $config->geneArray());
    }
}
```

Note: `testWithValuesThrowsOnUnknownGene` now tests via `withOverrides()`. The unknown key will reach `Genome::withValues()` through the gene-routing path, which throws `InvalidArgumentException`. Verify this is the case by checking: `withOverrides(['nonExistentGene' => 1.0])` — the key is not in `GeneCatalog`, so it falls into `$staticOvr`, and `array_merge($this->params, ['nonExistentGene' => 1.0])` will silently add it as a new static param (no exception).

If that's the case, adjust the test — remove `testWithOverridesThrowsOnUnknownGene` and instead add a test for `get()` throwing on an unknown key:

```php
public function testGetThrowsOnUnknownKey(): void
{
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Unknown param');

    StrategyConfig::createDefault()->get('nonExistentParam');
}
```

And keep the original unknown-gene test in `GenomeTest` (it already exists there).

**Step 2: Run tests**

```bash
docker exec -it paybis-app vendor/bin/codecept run Unit/Application/Config/GeneticConfigTest
```

Expected: all pass.

**Step 3: Run all Unit tests**

```bash
docker exec -it paybis-app vendor/bin/codecept run Unit
```

Expected: all pass except tests for consumer files that still use old property syntax.

**Step 4: Commit**

```bash
git add app/tests/Unit/Application/Config/GeneticConfigTest.php
git commit -m "test: update GeneticConfigTest to use geneArray() instead of ->genes"
```

---

## Task 4: Update `SimulationRunner`

**Files:**
- Modify: `app/src/Domain/Simulation/SimulationRunner.php`

**Step 1: Apply substitutions**

Replace all `$config->genes->toArray()` with `$config->geneArray()`:
- Line 71: `$genesArr = $config->genes->toArray();` → `$genesArr = $config->geneArray();`
- Line 302: `$config->genes->toArray()['maxHoldingCandles']` → `$config->geneArray()['maxHoldingCandles']`
- Line 383: `$config->genes->toArray()['atrStopMultiplier']` → `$config->geneArray()['atrStopMultiplier']`

Replace all direct property accesses with `$config->get(...)`:
```
$config->feePercent          → $config->get('feePercent')
$config->buyCount            → $config->get('buyCount')
$config->macro_smma          → $config->get('macro_smma')
$config->interval            → $config->get('interval')
$config->trailingMode        → $config->get('trailingMode')
$config->trailingAtrMultiplier → $config->get('trailingAtrMultiplier')
$config->breakevenAfterR     → $config->get('breakevenAfterR')
$config->maxOpenPositions    → $config->get('maxOpenPositions')
$config->trendStrengthBonus  → $config->get('trendStrengthBonus')
$config->dynamicSizing       → $config->get('dynamicSizing')
$config->minSizeFactor       → $config->get('minSizeFactor')
$config->maxSizeFactor       → $config->get('maxSizeFactor')
$config->confidenceSizing    → $config->get('confidenceSizing')
$config->minStopPercent      → $config->get('minStopPercent')
$config->minStopRR           → $config->get('minStopRR')
$config->baseRR              → $config->get('baseRR')
$config->tierMultipliers     → $config->get('tierMultipliers')
$config->tierSplits          → $config->get('tierSplits')
$config->exitMode            → $config->get('exitMode')
```

**Step 2: Run tests**

```bash
docker exec -it paybis-app vendor/bin/codecept run Unit/Application/Service/SimulationRunnerTest
```

Expected: all pass.

**Step 3: Commit**

```bash
git add app/src/Domain/Simulation/SimulationRunner.php
git commit -m "refactor: SimulationRunner — use config->get() and geneArray()"
```

---

## Task 5: Update `PaperTradingEngine` and `EntryFilterPipeline`

**Files:**
- Modify: `app/src/Application/Service/PaperTradingEngine.php`
- Modify: `app/src/Application/Service/EntryFilterPipeline.php`

**Step 1: PaperTradingEngine substitutions**

```
$config->feePercent          → $config->get('feePercent')
$config->genes->toArray()    → $config->geneArray()
$config->trailingMode        → $config->get('trailingMode')
$config->trailingAtrMultiplier → $config->get('trailingAtrMultiplier')
$config->breakevenAfterR     → $config->get('breakevenAfterR')
$config->interval            → $config->get('interval')
$config->maxOpenPositions    → $config->get('maxOpenPositions')
$config->buyCount            → $config->get('buyCount')
$config->confidenceSizing    → $config->get('confidenceSizing')
$config->minStopPercent      → $config->get('minStopPercent')
$config->minStopRR           → $config->get('minStopRR')
$config->baseRR              → $config->get('baseRR')
$config->tierMultipliers     → $config->get('tierMultipliers')
$config->exitMode            → $config->get('exitMode')
$config->tierSplits          → $config->get('tierSplits')
```

**Step 2: EntryFilterPipeline substitutions**

```
$config->minRvol         → $config->get('minRvol')
$config->maxRsiForEntry  → $config->get('maxRsiForEntry')
$config->minAdxForEntry  → $config->get('minAdxForEntry')
$config->regimeFilter    → $config->get('regimeFilter')
$config->entryTimingFilter → $config->get('entryTimingFilter')
```

**Step 3: Run tests**

```bash
docker exec -it paybis-app vendor/bin/codecept run Unit/Application/Service/PaperTradingEngineTest
docker exec -it paybis-app vendor/bin/codecept run Unit/Application/Service/EntryFilterPipelineTest
```

Expected: all pass.

**Step 4: Commit**

```bash
git add app/src/Application/Service/PaperTradingEngine.php app/src/Application/Service/EntryFilterPipeline.php
git commit -m "refactor: PaperTradingEngine + EntryFilterPipeline — use config->get() and geneArray()"
```

---

## Task 6: Update remaining consumers

**Files:**
- Modify: `app/src/Domain/Genetic/GeneticAlgorithm.php`
- Modify: `app/src/Application/GeneticOptimization/Service/StrategyOptimizer.php`
- Modify: `app/src/Infrastructure/Persistence/JsonGeneStorage.php`
- Modify: `app/src/Infrastructure/Console/PaperTradeCommand.php`
- Modify: `app/src/Infrastructure/Console/GeneticOptimizeCommand.php`

**Step 1: GeneticAlgorithm — line 110**

```php
// Before:
return $config->genes->toArray();
// After:
return $config->geneArray();
```

**Step 2: StrategyOptimizer**

```
$config->genes->toArray()    → $config->geneArray()   (×2, lines ~176 and ~201)
$best->config->genes->toArray() → $best->config->geneArray()
```

**Step 3: JsonGeneStorage — line 61**

```php
// Before:
$genes = $best->config->genes->toArray();
// After:
$genes = $best->config->geneArray();
```

**Step 4: PaperTradeCommand — line 192**

```php
// Before:
$feeMultiplier = $config->feePercent / 100;
// After:
$feeMultiplier = $config->get('feePercent') / 100;
```

**Step 5: GeneticOptimizeCommand**

```
$config->symbol              → $config->get('symbol')
$config->simStartDate        → $config->get('simStartDate')
$config->simEndDate          → $config->get('simEndDate')
$config->interval_configs    → $config->get('interval_configs')
$config->macro_timeframe     → $config->get('macro_timeframe')
$config->macro_smma          → $config->get('macro_smma')
$config->genes->toArray()    → $config->geneArray()   (×3, lines ~169, ~192, ~210)
$best->config->genes->toArray() → $best->config->geneArray()
```

**Step 6: Run all Unit tests**

```bash
docker exec -it paybis-app vendor/bin/codecept run Unit
```

Expected: all pass.

**Step 7: Commit**

```bash
git add \
  app/src/Domain/Genetic/GeneticAlgorithm.php \
  app/src/Application/GeneticOptimization/Service/StrategyOptimizer.php \
  app/src/Infrastructure/Persistence/JsonGeneStorage.php \
  app/src/Infrastructure/Console/PaperTradeCommand.php \
  app/src/Infrastructure/Console/GeneticOptimizeCommand.php
git commit -m "refactor: remaining consumers — use config->get() and geneArray()"
```

---

## Task 7: Final verification

**Step 1: Run full Unit suite**

```bash
docker exec -it paybis-app vendor/bin/codecept run Unit
```

Expected: all green.

**Step 2: Run PSR-12 lint**

```bash
docker exec -it paybis-app composer cs-check
```

Fix any style issues with:

```bash
docker exec -it paybis-app composer cs-fix
```

**Step 3: Smoke-test the simulation command**

```bash
docker exec -it paybis-app php -d xdebug.mode=off bin/console app:short-strategy-simulate 2>&1 | head -20
```

Expected: runs without PHP errors.

**Step 4: Final commit if lint fixes were needed**

```bash
git add -p   # stage only lint-fixed lines
git commit -m "style: cs-fix after StrategyConfig refactor"
```
