# Regime Detection Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the broken `MacroTrendFilter` with a proper Domain regime detection system that classifies market state (TRENDING_UP / TRENDING_DOWN / SIDEWAYS / HIGH_VOLATILITY) and gates/modulates entry, confidence, position sizing, and stop distance via 18 evolvable GA genes.

**Architecture:** Four pure stateless domain objects in `Domain/Regime/` — `MarketRegime` enum, `RegimeEffect` value object, `RegimeClassifier` service (pure PHP MA computation on existing `tradingHistoryWindow`), `RegimePolicy` service (maps regime → effect using genes). `SimulationRunner` caches regime per 1h window and applies the effect at four points. `MacroTrendFilter` is deleted.

**Tech Stack:** PHP 8.4, Codeception 5.0 (Unit suite), no new dependencies. All commands run inside Docker: `docker exec -it paybis-app bash`.

---

## Task 1: `MarketRegime` enum + `RegimeEffect` value object

**Files:**
- Create: `app/src/Domain/Regime/MarketRegime.php`
- Create: `app/src/Domain/Regime/RegimeEffect.php`

**Step 1: Create the enum**

```php
<?php
// app/src/Domain/Regime/MarketRegime.php
declare(strict_types=1);

namespace App\Domain\Regime;

enum MarketRegime
{
    case TRENDING_UP;
    case TRENDING_DOWN;
    case SIDEWAYS;
    case HIGH_VOLATILITY;
}
```

**Step 2: Create the value object**

```php
<?php
// app/src/Domain/Regime/RegimeEffect.php
declare(strict_types=1);

namespace App\Domain\Regime;

final class RegimeEffect
{
    public function __construct(
        public readonly bool  $allowEntry,
        public readonly float $confidenceMultiplier,
        public readonly float $sizeFactor,
        public readonly float $stopMultiplier,
    ) {
    }

    public static function neutral(): self
    {
        return new self(
            allowEntry:             true,
            confidenceMultiplier:   1.0,
            sizeFactor:             1.0,
            stopMultiplier:         1.0,
        );
    }
}
```

**Step 3: Verify syntax (no test needed for pure data structures)**

```bash
# inside container
php -l app/src/Domain/Regime/MarketRegime.php
php -l app/src/Domain/Regime/RegimeEffect.php
```

Expected: `No syntax errors detected`

**Step 4: Commit**

```bash
git add app/src/Domain/Regime/MarketRegime.php app/src/Domain/Regime/RegimeEffect.php
git commit -m "feat: add MarketRegime enum and RegimeEffect value object"
```

---

## Task 2: `RegimeClassifier` domain service + tests

**Files:**
- Create: `app/src/Domain/Regime/RegimeClassifier.php`
- Create: `app/tests/Unit/Domain/Regime/RegimeClassifierTest.php`

**Step 1: Write failing tests first**

```php
<?php
// app/tests/Unit/Domain/Regime/RegimeClassifierTest.php
declare(strict_types=1);

namespace Tests\Unit\Domain\Regime;

use App\Domain\Regime\MarketRegime;
use App\Domain\Regime\RegimeClassifierAtrNormalized;
use Codeception\Test\Unit;

class RegimeClassifierTest extends Unit
{
    private RegimeClassifierAtrNormalized $classifier;

    protected function setUp(): void
    {
        $this->classifier = new RegimeClassifierAtrNormalized();
    }

    /** Build a minimal candle array with N candles at a given close price */
    private function _makeCandles(int $count, float $close, float $high = 0.0, float $low = 0.0): array
    {
        return array_fill(0, $count, [
            'close' => $close,
            'high'  => $high ?: $close * 1.01,
            'low'   => $low  ?: $close * 0.99,
            'open'  => $close,
        ]);
    }

    /** Build a rising candle array (short MA above long MA) */
    private function _makeRisingCandles(int $count, int $longPeriod): array
    {
        $candles = [];
        // First (count - longPeriod) candles at low price (drives long MA down)
        $earlyCount = $count - $longPeriod;
        for ($i = 0; $i < $earlyCount; $i++) {
            $candles[] = ['close' => 50.0, 'high' => 51.0, 'low' => 49.0, 'open' => 50.0];
        }
        // Last longPeriod candles at high price (drives short MA up)
        for ($i = 0; $i < $longPeriod; $i++) {
            $candles[] = ['close' => 100.0, 'high' => 101.0, 'low' => 99.0, 'open' => 100.0];
        }
        return $candles;
    }

    /** Build a falling candle array (short MA below long MA) */
    private function _makeFallingCandles(int $count, int $longPeriod): array
    {
        $candles = [];
        $earlyCount = $count - $longPeriod;
        for ($i = 0; $i < $earlyCount; $i++) {
            $candles[] = ['close' => 100.0, 'high' => 101.0, 'low' => 99.0, 'open' => 100.0];
        }
        for ($i = 0; $i < $longPeriod; $i++) {
            $candles[] = ['close' => 50.0, 'high' => 51.0, 'low' => 49.0, 'open' => 50.0];
        }
        return $candles;
    }

    private function _genes(array $overrides = []): array
    {
        return array_merge([
            'regimeMaShortPeriod'    => 5,
            'regimeMaLongPeriod'     => 20,
            'regimeTrendStrengthMin' => 0.005,
            'regimeAtrHighPct'       => 3.0,
        ], $overrides);
    }

    public function testReturnsSidewaysWhenInsufficientCandles(): void
    {
        $candles = $this->_makeCandles(10, 100.0); // fewer than longPeriod=20
        $genes   = $this->_genes();

        $result = $this->classifier->classify($candles, 1.0, $genes);

        $this->assertSame(MarketRegime::SIDEWAYS, $result);
    }

    public function testReturnsHighVolatilityWhenAtrPercentExceedsThreshold(): void
    {
        $candles = $this->_makeCandles(30, 100.0);
        $genes   = $this->_genes(['regimeAtrHighPct' => 3.0]);
        // atr = 4.0, close = 100.0 → atrPct = 4% > 3%
        $result = $this->classifier->classify($candles, 4.0, $genes);

        $this->assertSame(MarketRegime::HIGH_VOLATILITY, $result);
    }

    public function testReturnsTrendingUpWhenShortMaAboveLong(): void
    {
        $genes   = $this->_genes(['regimeMaShortPeriod' => 5, 'regimeMaLongPeriod' => 20, 'regimeTrendStrengthMin' => 0.005]);
        $candles = $this->_makeRisingCandles(30, 20);
        // atr small enough to not trigger HIGH_VOLATILITY
        $result  = $this->classifier->classify($candles, 0.5, $genes);

        $this->assertSame(MarketRegime::TRENDING_UP, $result);
    }

    public function testReturnsTrendingDownWhenShortMaBelowLong(): void
    {
        $genes   = $this->_genes(['regimeMaShortPeriod' => 5, 'regimeMaLongPeriod' => 20, 'regimeTrendStrengthMin' => 0.005]);
        $candles = $this->_makeFallingCandles(30, 20);
        $result  = $this->classifier->classify($candles, 0.5, $genes);

        $this->assertSame(MarketRegime::TRENDING_DOWN, $result);
    }

    public function testReturnsSidewaysWhenStrengthBelowMinimum(): void
    {
        // All candles at same price → MA difference = 0 → strength = 0 < threshold
        $candles = $this->_makeCandles(30, 100.0);
        $genes   = $this->_genes(['regimeTrendStrengthMin' => 0.005]);
        $result  = $this->classifier->classify($candles, 1.0, $genes);

        $this->assertSame(MarketRegime::SIDEWAYS, $result);
    }

    public function testHighVolatilityTakesPriorityOverTrend(): void
    {
        $genes   = $this->_genes(['regimeAtrHighPct' => 3.0, 'regimeMaShortPeriod' => 5, 'regimeMaLongPeriod' => 20]);
        $candles = $this->_makeRisingCandles(30, 20);
        // High ATR overrides uptrend
        $result  = $this->classifier->classify($candles, 4.0, $genes);

        $this->assertSame(MarketRegime::HIGH_VOLATILITY, $result);
    }
}
```

**Step 2: Run tests to confirm they fail**

```bash
vendor/bin/codecept run Unit Domain/Regime/RegimeClassifierTest
```

Expected: `Class "App\Domain\Regime\RegimeClassifier" not found`

**Step 3: Implement `RegimeClassifier`**

```php
<?php
// app/src/Domain/Regime/RegimeClassifier.php
declare(strict_types=1);

namespace App\Domain\Regime;

final class RegimeClassifier
{
    /**
     * Classify market regime from rolling candle window.
     *
     * Uses simple MA (not SMMA) for speed — pure PHP, no PECL.
     * Safe default: SIDEWAYS if not enough candles for longPeriod MA.
     *
     * @param array $candles      Rolling hourly candle window (each: ['close', 'high', 'low', 'open'])
     * @param float $atr          Pre-computed ATR(14) — already cached in SimulationRunner
     * @param array $genes        Flat gene array from StrategyConfig::geneArray()
     */
    public function classify(array $candles, float $atr, array $genes): MarketRegime
    {
        $shortPeriod  = (int) $genes['regimeMaShortPeriod'];
        $longPeriod   = (int) $genes['regimeMaLongPeriod'];
        $strengthMin  = (float) $genes['regimeTrendStrengthMin'];
        $atrHighPct   = (float) $genes['regimeAtrHighPct'];

        // Safe default: not enough history
        if (count($candles) < $longPeriod) {
            return MarketRegime::SIDEWAYS;
        }

        // Priority 1: high volatility
        $lastClose = (float) end($candles)['close'];
        $atrPct    = $lastClose > 0 ? ($atr / $lastClose) * 100 : 0.0;
        if ($atrPct > $atrHighPct) {
            return MarketRegime::HIGH_VOLATILITY;
        }

        // Compute simple MAs
        $shortMa = $this->_simpleMa($candles, $shortPeriod);
        $longMa  = $this->_simpleMa($candles, $longPeriod);

        if ($longMa <= 0.0) {
            return MarketRegime::SIDEWAYS;
        }

        $strength = abs($shortMa - $longMa) / $longMa;

        if ($strength < $strengthMin) {
            return MarketRegime::SIDEWAYS;
        }

        return $shortMa > $longMa ? MarketRegime::TRENDING_UP : MarketRegime::TRENDING_DOWN;
    }

    private function _simpleMa(array $candles, int $period): float
    {
        $slice  = array_slice($candles, -$period);
        $closes = array_column($slice, 'close');
        return array_sum($closes) / count($closes);
    }
}
```

**Step 4: Run tests to confirm they pass**

```bash
vendor/bin/codecept run Unit Domain/Regime/RegimeClassifierTest
```

Expected: `OK (6 tests, 6 assertions)`

**Step 5: Commit**

```bash
git add app/src/Domain/Regime/RegimeClassifier.php app/tests/Unit/Domain/Regime/RegimeClassifierTest.php
git commit -m "feat: add RegimeClassifier domain service with tests"
```

---

## Task 3: `RegimePolicy` domain service + tests

**Files:**
- Create: `app/src/Domain/Regime/RegimePolicy.php`
- Create: `app/tests/Unit/Domain/Regime/RegimePolicyTest.php`

**Step 1: Write failing tests**

```php
<?php
// app/tests/Unit/Domain/Regime/RegimePolicyTest.php
declare(strict_types=1);

namespace Tests\Unit\Domain\Regime;

use App\Domain\Regime\MarketRegime;
use App\Domain\Regime\RegimeEffect;
use App\Domain\Regime\RegimePolicy;
use Codeception\Test\Unit;

class RegimePolicyTest extends Unit
{
    private RegimePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new RegimePolicy();
    }

    private function _genes(array $overrides = []): array
    {
        return array_merge([
            'regimeDowntrendBlock'     => 0,
            'regimeHighVolBlock'       => 0,
            'regimeUpConfMult'         => 1.2,
            'regimeSidewaysConfMult'   => 0.8,
            'regimeDownConfMult'       => 0.5,
            'regimeHighVolConfMult'    => 0.6,
            'regimeUpSizeFactor'       => 1.3,
            'regimeSidewaysSizeFactor' => 0.9,
            'regimeDownSizeFactor'     => 0.6,
            'regimeHighVolSizeFactor'  => 0.7,
            'regimeUpStopMult'         => 0.9,
            'regimeSidewaysStopMult'   => 1.0,
            'regimeDownStopMult'       => 1.4,
            'regimeHighVolStopMult'    => 1.8,
        ], $overrides);
    }

    public function testTrendingUpEffect(): void
    {
        $effect = $this->policy->effectFor(MarketRegime::TRENDING_UP, $this->_genes());

        $this->assertTrue($effect->allowEntry);
        $this->assertSame(1.2, $effect->confidenceMultiplier);
        $this->assertSame(1.3, $effect->sizeFactor);
        $this->assertSame(0.9, $effect->stopMultiplier);
    }

    public function testSidewaysEffect(): void
    {
        $effect = $this->policy->effectFor(MarketRegime::SIDEWAYS, $this->_genes());

        $this->assertTrue($effect->allowEntry);
        $this->assertSame(0.8, $effect->confidenceMultiplier);
        $this->assertSame(0.9, $effect->sizeFactor);
        $this->assertSame(1.0, $effect->stopMultiplier);
    }

    public function testTrendingDownAllowsEntryWhenBlockGeneIsZero(): void
    {
        $effect = $this->policy->effectFor(MarketRegime::TRENDING_DOWN, $this->_genes(['regimeDowntrendBlock' => 0]));

        $this->assertTrue($effect->allowEntry);
        $this->assertSame(0.5, $effect->confidenceMultiplier);
        $this->assertSame(0.6, $effect->sizeFactor);
        $this->assertSame(1.4, $effect->stopMultiplier);
    }

    public function testTrendingDownBlocksEntryWhenBlockGeneIsOne(): void
    {
        $effect = $this->policy->effectFor(MarketRegime::TRENDING_DOWN, $this->_genes(['regimeDowntrendBlock' => 1]));

        $this->assertFalse($effect->allowEntry);
    }

    public function testHighVolatilityAllowsEntryWhenBlockGeneIsZero(): void
    {
        $effect = $this->policy->effectFor(MarketRegime::HIGH_VOLATILITY, $this->_genes(['regimeHighVolBlock' => 0]));

        $this->assertTrue($effect->allowEntry);
        $this->assertSame(0.6, $effect->confidenceMultiplier);
        $this->assertSame(0.7, $effect->sizeFactor);
        $this->assertSame(1.8, $effect->stopMultiplier);
    }

    public function testHighVolatilityBlocksEntryWhenBlockGeneIsOne(): void
    {
        $effect = $this->policy->effectFor(MarketRegime::HIGH_VOLATILITY, $this->_genes(['regimeHighVolBlock' => 1]));

        $this->assertFalse($effect->allowEntry);
    }
}
```

**Step 2: Run to confirm failure**

```bash
vendor/bin/codecept run Unit Domain/Regime/RegimePolicyTest
```

Expected: `Class "App\Domain\Regime\RegimePolicy" not found`

**Step 3: Implement `RegimePolicy`**

```php
<?php
// app/src/Domain/Regime/RegimePolicy.php
declare(strict_types=1);

namespace App\Domain\Regime;

final class RegimePolicy
{
    /**
     * Map a classified regime to its trading effect modifiers.
     *
     * @param array $genes Flat gene array from StrategyConfig::geneArray()
     */
    public function effectFor(MarketRegime $regime, array $genes): RegimeEffect
    {
        return match ($regime) {
            MarketRegime::TRENDING_UP => new RegimeEffect(
                allowEntry:           true,
                confidenceMultiplier: (float) $genes['regimeUpConfMult'],
                sizeFactor:           (float) $genes['regimeUpSizeFactor'],
                stopMultiplier:       (float) $genes['regimeUpStopMult'],
            ),
            MarketRegime::SIDEWAYS => new RegimeEffect(
                allowEntry:           true,
                confidenceMultiplier: (float) $genes['regimeSidewaysConfMult'],
                sizeFactor:           (float) $genes['regimeSidewaysSizeFactor'],
                stopMultiplier:       (float) $genes['regimeSidewaysStopMult'],
            ),
            MarketRegime::TRENDING_DOWN => new RegimeEffect(
                allowEntry:           ((int) $genes['regimeDowntrendBlock']) === 0,
                confidenceMultiplier: (float) $genes['regimeDownConfMult'],
                sizeFactor:           (float) $genes['regimeDownSizeFactor'],
                stopMultiplier:       (float) $genes['regimeDownStopMult'],
            ),
            MarketRegime::HIGH_VOLATILITY => new RegimeEffect(
                allowEntry:           ((int) $genes['regimeHighVolBlock']) === 0,
                confidenceMultiplier: (float) $genes['regimeHighVolConfMult'],
                sizeFactor:           (float) $genes['regimeHighVolSizeFactor'],
                stopMultiplier:       (float) $genes['regimeHighVolStopMult'],
            ),
        };
    }
}
```

**Step 4: Run tests to confirm pass**

```bash
vendor/bin/codecept run Unit Domain/Regime/RegimePolicyTest
```

Expected: `OK (6 tests, 10 assertions)`

**Step 5: Commit**

```bash
git add app/src/Domain/Regime/RegimePolicy.php app/tests/Unit/Domain/Regime/RegimePolicyTest.php
git commit -m "feat: add RegimePolicy domain service with tests"
```

---

## Task 4: `RegimeMaConstraint` + tests

**Files:**
- Create: `app/src/Domain/Genetic/Constraint/RegimeMaConstraint.php`
- Create: `app/tests/Unit/Domain/Genetic/Constraint/RegimeMaConstraintTest.php`

**Step 1: Write failing tests**

Pattern mirrors `MacdConstraintTest` exactly — create a minimal genome with only the two MA period genes.

```php
<?php
// app/tests/Unit/Domain/Genetic/Constraint/RegimeMaConstraintTest.php
declare(strict_types=1);

namespace Tests\Unit\Domain\Genetic\Constraint;

use App\Domain\Genetic\Constraint\RegimeMaConstraint;
use App\Domain\Genetic\GeneDefinition\IntGene;
use App\Domain\Genetic\Genome\Genome;
use Codeception\Test\Unit;

class RegimeMaConstraintTest extends Unit
{
    private function _makeGenome(int $short, int $long): Genome
    {
        return new Genome([
            'regimeMaShortPeriod' => (new IntGene('regimeMaShortPeriod', 5, 30, 1))->withValue($short),
            'regimeMaLongPeriod'  => (new IntGene('regimeMaLongPeriod', 20, 100, 1))->withValue($long),
        ]);
    }

    public function testRepairClampsShortPeriodWhenItExceedsLong(): void
    {
        // short=30, long=20 → violates; short must be clamped to long-1=19
        $genome     = $this->_makeGenome(30, 20);
        $constraint = new RegimeMaConstraint();
        $repaired   = $constraint->repair($genome);

        $this->assertNotSame($genome, $repaired);
        $this->assertSame(30, $genome->get('regimeMaShortPeriod')->value(), 'original unchanged');
        $this->assertSame(19, $repaired->get('regimeMaShortPeriod')->value());
    }

    public function testRepairReturnsSameInstanceWhenConstraintSatisfied(): void
    {
        $genome     = $this->_makeGenome(10, 30);
        $constraint = new RegimeMaConstraint();
        $repaired   = $constraint->repair($genome);

        $this->assertSame($genome, $repaired);
    }

    public function testRepairClampsWhenShortEqualsLong(): void
    {
        $genome     = $this->_makeGenome(20, 20);
        $constraint = new RegimeMaConstraint();
        $repaired   = $constraint->repair($genome);

        $this->assertSame(19, $repaired->get('regimeMaShortPeriod')->value());
    }
}
```

**Step 2: Run to confirm failure**

```bash
vendor/bin/codecept run Unit Domain/Genetic/Constraint/RegimeMaConstraintTest
```

Expected: `Class "App\Domain\Genetic\Constraint\RegimeMaConstraint" not found`

**Step 3: Implement**

```php
<?php
// app/src/Domain/Genetic/Constraint/RegimeMaConstraint.php
declare(strict_types=1);

namespace App\Domain\Genetic\Constraint;

use App\Domain\Genetic\Genome\Genome;

final class RegimeMaConstraint implements GeneConstraint
{
    public function repair(Genome $genome): Genome
    {
        $short = $genome->get('regimeMaShortPeriod')->value();
        $long  = $genome->get('regimeMaLongPeriod')->value();

        if ($short >= $long) {
            return $genome->withValues(['regimeMaShortPeriod' => $long - 1]);
        }

        return $genome;
    }
}
```

**Step 4: Run tests to confirm pass**

```bash
vendor/bin/codecept run Unit Domain/Genetic/Constraint/RegimeMaConstraintTest
```

Expected: `OK (3 tests, 5 assertions)`

**Step 5: Commit**

```bash
git add app/src/Domain/Genetic/Constraint/RegimeMaConstraint.php \
        app/tests/Unit/Domain/Genetic/Constraint/RegimeMaConstraintTest.php
git commit -m "feat: add RegimeMaConstraint (short < long period)"
```

---

## Task 5: Add 18 regime genes to `GeneCatalog` and `default_genes.json`

**Files:**
- Modify: `app/src/Domain/Genetic/Genome/GeneCatalog.php`
- Modify: `app/src/Application/Config/default_genes.json`
- Modify: `app/src/Application/Config/adaptive_genes.json`

**Step 1: Add genes to `GeneCatalog`**

In `GeneCatalog::__construct()`, append a new group after the `// OBV` block (after `bsObvWeight`):

```php
            // --- Regime detection ---
            // Classification thresholds
            new IntGene('regimeMaShortPeriod',    5,     30,    1),
            new IntGene('regimeMaLongPeriod',     20,    100,   1),
            new FloatGene('regimeTrendStrengthMin', 0.001, 0.050, 0.001),
            new FloatGene('regimeAtrHighPct',       1.5,   6.0,   0.1),

            // Entry gate (0 = allow, 1 = block)
            new IntGene('regimeDowntrendBlock',   0, 1, 1),
            new IntGene('regimeHighVolBlock',      0, 1, 1),

            // Confidence multipliers
            new FloatGene('regimeUpConfMult',         0.8, 1.5, 0.05),
            new FloatGene('regimeSidewaysConfMult',   0.5, 1.2, 0.05),
            new FloatGene('regimeDownConfMult',        0.2, 0.9, 0.05),
            new FloatGene('regimeHighVolConfMult',     0.3, 1.1, 0.05),

            // Size factors
            new FloatGene('regimeUpSizeFactor',        0.8, 1.5, 0.05),
            new FloatGene('regimeSidewaysSizeFactor',  0.5, 1.2, 0.05),
            new FloatGene('regimeDownSizeFactor',       0.3, 0.9, 0.05),
            new FloatGene('regimeHighVolSizeFactor',    0.3, 1.2, 0.05),

            // Stop multipliers
            new FloatGene('regimeUpStopMult',          0.8, 1.2, 0.05),
            new FloatGene('regimeSidewaysStopMult',    0.9, 1.4, 0.05),
            new FloatGene('regimeDownStopMult',         1.0, 1.8, 0.05),
            new FloatGene('regimeHighVolStopMult',      1.2, 2.5, 0.05),
```

**Step 2: Add neutral defaults to `default_genes.json`**

In the `"genes"` object, append after `"bsObvWeight"`:

```json
    "regimeMaShortPeriod": 20,
    "regimeMaLongPeriod": 50,
    "regimeTrendStrengthMin": 0.005,
    "regimeAtrHighPct": 3.0,
    "regimeDowntrendBlock": 0,
    "regimeHighVolBlock": 0,
    "regimeUpConfMult": 1.0,
    "regimeSidewaysConfMult": 1.0,
    "regimeDownConfMult": 1.0,
    "regimeHighVolConfMult": 1.0,
    "regimeUpSizeFactor": 1.0,
    "regimeSidewaysSizeFactor": 1.0,
    "regimeDownSizeFactor": 1.0,
    "regimeHighVolSizeFactor": 1.0,
    "regimeUpStopMult": 1.0,
    "regimeSidewaysStopMult": 1.0,
    "regimeDownStopMult": 1.0,
    "regimeHighVolStopMult": 1.0
```

**Step 3: Add same neutral defaults to `adaptive_genes.json`**

Same 18 entries appended to `"genes"` object in `adaptive_genes.json`.

**Step 4: Add `RegimeMaConstraint` to `ConstraintRepair` in `StrategyConfig`**

In `app/src/Application/Config/StrategyConfig.php`, find `createDefault()`:

```php
// Before:
$factory = new GenomeFactory(
    new GeneCatalog(),
    new ConstraintRepair([new MacdConstraint(), new WeightNormalizationConstraint()])
);

// After:
$factory = new GenomeFactory(
    new GeneCatalog(),
    new ConstraintRepair([
        new MacdConstraint(),
        new WeightNormalizationConstraint(),
        new RegimeMaConstraint(),
    ])
);
```

Add import: `use App\Domain\Genetic\Constraint\RegimeMaConstraint;`

**Step 5: Run full Unit suite to verify no regressions**

```bash
vendor/bin/codecept run Unit
```

Expected: all tests pass. `GenomeFactoryTest` exercises `fromDefaults()` which loads `default_genes.json` — it must pass, confirming the JSON is valid and all 67 genes are present.

If `GenomeFactoryTest` fails with "Missing gene value: regimeMaShortPeriod", you missed a gene entry in the JSON.

**Step 6: Commit**

```bash
git add app/src/Domain/Genetic/Genome/GeneCatalog.php \
        app/src/Application/Config/default_genes.json \
        app/src/Application/Config/adaptive_genes.json \
        app/src/Application/Config/StrategyConfig.php
git commit -m "feat: add 18 regime genes to GeneCatalog and defaults; wire RegimeMaConstraint"
```

---

## Task 6: Integrate regime into `SimulationRunner`; delete `MacroTrendFilter`

**Files:**
- Modify: `app/src/Domain/Simulation/SimulationRunner.php`
- Modify: `app/src/Application/Config/StrategyConfig.php` (remove 4 config keys)
- Delete: `app/src/Application/Service/MacroTrendFilter.php`

**Step 1: Update `SimulationRunner` constructor**

Remove `MacroTrendFilter`, add `RegimeClassifier` and `RegimePolicy`:

```php
// Remove these imports:
// use App\Application\Service\MacroTrendFilter;

// Add these imports:
use App\Domain\Regime\RegimeClassifierAtrNormalized;
use App\Domain\Regime\RegimeEffect;
use App\Domain\Regime\RegimePolicy;

// Constructor — replace:
// private readonly MacroTrendFilter $macroTrendFilter,
// with:
private readonly RegimeClassifier $regimeClassifier,
private readonly RegimePolicy $regimePolicy,
```

**Step 2: Add regime caching variables**

After the existing cache variable declarations (`$cachedAtr`, `$cachedBuyStrategy`, etc.), add:

```php
$cachedRegimeEffect = RegimeEffect::neutral();
$regimeWindowTime   = -1;
```

**Step 3: Replace MacroTrendFilter calls with regime logic**

Find and remove these two blocks (lines ~172–179 in the original):

```php
// REMOVE:
if ($this->macroTrendFilter->isDowntrend($currentCandle['openTime'])) {
    continue;
}

if ($config->get('requireUptrend') && !$this->macroTrendFilter->isUptrend($currentCandle['openTime'])) {
    $stats->filterRejections['uptrend']++;
    continue;
}
```

In their place, insert the regime computation + gate. Note: `$latestWindowTime` is currently computed a few lines below (line ~182). Move that computation UP to just before the regime block:

```php
// Compute latestWindowTime here (moved up from below)
$latestWindowTime = end($tradingHistoryWindow)['openTime'];

// Compute regime effect once per 1h window
if ($latestWindowTime !== $regimeWindowTime) {
    $regime             = $this->regimeClassifier->classify($tradingHistoryWindow, $atr, $genesArr);
    $cachedRegimeEffect = $this->regimePolicy->effectFor($regime, $genesArr);
    $regimeWindowTime   = $latestWindowTime;
}

// Regime gate (replaces MacroTrendFilter + requireUptrend)
if (!$cachedRegimeEffect->allowEntry) {
    $stats->filterRejections['regime']++;
    continue;
}
```

Then find the existing `$latestWindowTime = end($tradingHistoryWindow)['openTime'];` line (now a duplicate) and remove it, keeping only `$isNewWindow = ($latestWindowTime !== $prevWindowTime);` and the block beneath it.

**Step 4: Apply confidence multiplier**

Find the `trendStrengthBonus` block and replace entirely:

```php
// REMOVE:
$confidence = $signalToBuy['confidence'];
if ($config->get('trendStrengthBonus')) {
    $trendData = $this->macroTrendFilter->getTrendDataForTime($currentCandle['openTime']);
    if ($trendData !== null) {
        $isUp      = ($trendData['short'] ?? 0) > ($trendData['long'] ?? 0);
        $strength  = min(($trendData['trend_strength'] ?? 0) / 0.01, 1.0);
        $confidence += $isUp ? ($strength * 5) : (-$strength * 3);
    }
}

// REPLACE WITH:
$confidence = $signalToBuy['confidence'] * $cachedRegimeEffect->confidenceMultiplier;
```

**Step 5: Apply size factor**

After the existing sizing block (after `$buyQtyBySignal` is computed from whichever sizing branch ran), add:

```php
// Apply regime size factor
$buyQtyBySignal = max(1, (int) round($buyQtyBySignal * $cachedRegimeEffect->sizeFactor));
```

**Step 6: Pass stop multiplier into `executeBuy`**

Add `$cachedRegimeEffect` as parameter to the `executeBuy()` call:

```php
$this->executeBuy(
    $buyQtyBySignal,
    $confidence,
    $currentPrice,
    $atr,
    $tradingHistoryWindow,
    $config,
    $currentCandle,
    $currentDate,
    $feeMultiplier,
    $stats,
    $orders,
    $onEvent,
    $signalToBuy,
    $cachedRegimeEffect,   // ← add this
);
```

Update `executeBuy()` signature to accept `RegimeEffect $regimeEffect` as last parameter.

Inside `executeBuy()`, modify `calculateStopLoss` call:

```php
// Before:
$stopLossPrice = $this->simulationEngine->calculateStopLoss(
    $currentPrice,
    $atr,
    $config->geneArray()['atrStopMultiplier'],
    $config->get('minStopPercent')
);

// After:
$stopLossPrice = $this->simulationEngine->calculateStopLoss(
    $currentPrice,
    $atr,
    $config->geneArray()['atrStopMultiplier'] * $regimeEffect->stopMultiplier,
    $config->get('minStopPercent')
);
```

**Step 7: Remove 4 config keys from `StrategyConfig::defaults()`**

Delete these lines from the `defaults()` array:

```php
'requireUptrend'     => true,        // ← remove
'trendStrengthBonus' => false,       // ← remove
'macro_smma'         => ['shortPeriod' => 20, 'longPeriod' => 50],  // ← remove
'macro_timeframe'    => '4h',        // ← remove
```

Also remove `$config->get('requireUptrend')` reference from `ShortStrategySimulationCommand` experiment config array (line ~182 of the command):
```php
'requireUptrend'     => $config['requireUptrend'],  // ← remove this line
'trendStrengthBonus' => $config['trendStrengthBonus'],  // ← remove this line
```

**Step 8: Delete `MacroTrendFilter`**

```bash
git rm app/src/Application/Service/MacroTrendFilter.php
```

Also remove `MacroTrendFilter` import from `GeneticOptimizeCommand` and its `init()` call (around line 84–94 of that file), and remove the `MacroTrendFilter` constructor injection from `GeneticOptimizeCommand`.

Check for any remaining references:

```bash
grep -r "MacroTrendFilter\|macroTrendFilter\|requireUptrend\|trendStrengthBonus\|macro_smma\|macro_timeframe" app/src app/tests
```

Expected: no output (zero references remaining).

**Step 9: Run full Unit suite**

```bash
vendor/bin/codecept run Unit
```

Expected: all tests pass.

**Step 10: Run static analysis**

```bash
vendor/bin/phpstan analyse --memory-limit=512M
```

Fix any type errors reported before proceeding.

**Step 11: Smoke-test with a quick simulation**

```bash
php -d xdebug.mode=off bin/console app:short-strategy-simulate 2>&1 | head -50
```

Expected: output includes BUY events (not 0 trades). If 0 buys, check that `regimeDowntrendBlock=0` and `regimeHighVolBlock=0` in `default_genes.json` — both must be 0 for neutral behavior.

**Step 12: Commit**

```bash
git add app/src/Domain/Simulation/SimulationRunner.php \
        app/src/Application/Config/StrategyConfig.php \
        app/src/Infrastructure/Console/GeneticOptimizeCommand.php \
        app/src/Infrastructure/Console/ShortStrategySimulationCommand.php
git commit -m "feat: integrate regime detection into SimulationRunner; delete MacroTrendFilter"
```

---

## Task 7: Final verification

**Step 1: Run full test suite**

```bash
vendor/bin/codecept run Unit
```

Expected: all pass, including the 3 new `RegimeClassifierTest`, `RegimePolicyTest`, `RegimeMaConstraintTest` suites.

**Step 2: Run PSR-12 lint**

```bash
composer cs-check
```

Fix any style issues with `composer cs-fix`, then re-run.

**Step 3: Run static analysis**

```bash
vendor/bin/phpstan analyse --memory-limit=512M
```

Expected: 0 errors.

**Step 4: Verify gene count**

```bash
php -r "
require 'vendor/autoload.php';
\$c = new App\Domain\Genetic\Genome\GeneCatalog();
echo count(\$c->all()) . ' genes\n';
"
```

Expected: `67 genes`

**Step 5: Full simulation smoke test**

```bash
php -d xdebug.mode=off bin/console app:short-strategy-simulate 2>&1 | grep '"event"'
```

Expected: events include `INIT`, `BUY` (multiple), `SELL`, `SUMMARY`. Non-zero buys.

**Step 6: Final commit if any fixes were needed**

```bash
git add -p
git commit -m "fix: post-integration cleanup"
```

---

## Summary of Changes

| Action | File |
|---|---|
| Create | `Domain/Regime/MarketRegime.php` |
| Create | `Domain/Regime/RegimeEffect.php` |
| Create | `Domain/Regime/RegimeClassifier.php` |
| Create | `Domain/Regime/RegimePolicy.php` |
| Create | `Domain/Genetic/Constraint/RegimeMaConstraint.php` |
| Create | `tests/Unit/Domain/Regime/RegimeClassifierTest.php` |
| Create | `tests/Unit/Domain/Regime/RegimePolicyTest.php` |
| Create | `tests/Unit/Domain/Genetic/Constraint/RegimeMaConstraintTest.php` |
| Modify | `Domain/Simulation/SimulationRunner.php` |
| Modify | `Domain/Genetic/Genome/GeneCatalog.php` (+18 genes) |
| Modify | `Application/Config/StrategyConfig.php` (add RegimeMaConstraint, remove 4 params) |
| Modify | `Application/Config/default_genes.json` (+18 neutral entries) |
| Modify | `Application/Config/adaptive_genes.json` (+18 neutral entries) |
| Modify | `Infrastructure/Console/GeneticOptimizeCommand.php` (remove MacroTrendFilter) |
| Modify | `Infrastructure/Console/ShortStrategySimulationCommand.php` (remove 2 config refs) |
| Delete | `Application/Service/MacroTrendFilter.php` |
