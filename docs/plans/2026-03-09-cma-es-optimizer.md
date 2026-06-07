# CMA-ES Optimizer Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the `GeneticAlgorithm` with a native PHP CMA-ES (Covariance Matrix Adaptation Evolution Strategy) optimizer that learns gene correlations and adapts its step size automatically.

**Architecture:** Three new classes in `App\Domain\Genetic\CmaEs\`: `CmaEsState` (immutable snapshot of all optimizer state), `CmaEsNormalizer` (maps gene arrays ↔ normalized float vectors), and `CmaEsOptimizer` (core algorithm: Jacobi eigendecomposition + ask/tell interface). A new `CmaEsOptimizeCommand` in `App\Infrastructure\Console\` replaces the GA-based command. The existing `GenomeFactory`, `ConstraintRepair`, `GeneCatalog`, `SimulationRunner`, and `StrategyConfig` are all reused unchanged.

**Tech Stack:** PHP 8.4, Symfony 6.4 console, Codeception 5.0 (PHPUnit 10), no new dependencies.

---

## Background: CMA-ES in 60 seconds

CMA-ES is an evolution strategy that maintains a multivariate Gaussian distribution `N(mean, σ²·C)` over the search space and adapts it generation by generation.

**Per generation:**
1. **Ask:** sample λ candidate vectors from `N(mean, σ²·C)` using `x_k = mean + σ·B·(D ⊙ z_k)` where `z_k ~ N(0, I)`, `C = B·D²·Bᵀ` is the eigendecomposition.
2. **Evaluate:** run simulation for each candidate, get fitness.
3. **Tell:** update the distribution using the μ best results:
   - New mean = weighted sum of best μ solutions
   - Update evolution paths `p_σ` and `p_c` (cumulation)
   - Update `σ` via CSA (Cumulative Step-size Adaptation)
   - Update `C` via rank-1 + rank-μ update
   - Re-eigendecompose `C` every ~22 generations (periodic for efficiency)

**Key parameters for n=67 genes:**
- λ (population) = 20 (configurable, default: `4 + floor(3·ln(67))` ≈ 16, use 20)
- μ = floor(λ/2) = 10
- σ₀ = 0.3 (initial step size in normalized [0,1] space)
- Weights: `w_i = ln(μ+0.5) - ln(i)` for i=1..μ, normalized to sum=1
- μ_eff = 1/Σwᵢ²
- c_σ = (μ_eff+2)/(n+μ_eff+5)
- d_σ = 1 + 2·max(0, √((μ_eff-1)/(n+1))-1) + c_σ
- c_c = (4+μ_eff/n)/(n+4+2·μ_eff/n)
- c_1 = 2/((n+1.3)²+μ_eff)
- c_μ = min(1-c_1, 2·(μ_eff-2+1/μ_eff)/((n+2)²+μ_eff))
- χ_n = √n·(1 - 1/(4n) + 1/(21n²))

**Gene normalization:** all genes mapped to [0,1] before CMA-ES operates on them. CMA-ES works in this normalized space internally. Denormalization: `v_gene = min + v_normalized·(max-min)`, then integer genes are rounded, then `ConstraintRepair` is applied (MacdConstraint, WeightNormalizationConstraint, RegimeMaConstraint).

---

## Task 1: CmaEsState value object

**Files:**
- Create: `app/src/Domain/Genetic/CmaEs/CmaEsState.php`
- Create: `app/tests/Unit/Domain/Genetic/CmaEs/CmaEsStateTest.php`

### Step 1: Write the failing test

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Genetic\CmaEs;

use App\Domain\Genetic\CmaEs\CmaEsState;
use Codeception\Test\Unit;

class CmaEsStateTest extends Unit
{
    public function testConstructionAndAccessors(): void
    {
        $n    = 3;
        $mean = [0.5, 0.3, 0.7];
        $eye  = [[1,0,0],[0,1,0],[0,0,1]];
        $ones = [1.0, 1.0, 1.0];
        $zero = [0.0, 0.0, 0.0];

        $state = new CmaEsState(
            n: $n,
            lambda: 6,
            mu: 3,
            weights: [0.5, 0.3, 0.2],
            muEff: 4.35,
            cSigma: 0.3,
            dSigma: 1.3,
            cc: 0.2,
            c1: 0.01,
            cmu: 0.05,
            chiN: 1.7,
            mean: $mean,
            sigma: 0.3,
            covariance: $eye,
            eigenVectors: $eye,
            eigenValues: $ones,
            pathSigma: $zero,
            pathC: $zero,
            generation: 0,
            eigenLastUpdate: 0,
        );

        $this->assertSame($n, $state->n);
        $this->assertSame($mean, $state->mean);
        $this->assertSame(0.3, $state->sigma);
        $this->assertSame(0, $state->generation);
    }
}
```

### Step 2: Run test to verify it fails

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/codecept run Unit Domain/Genetic/CmaEs/CmaEsStateTest --no-ansi 2>&1 | tail -5"
```
Expected: `Class "App\Domain\Genetic\CmaEs\CmaEsState" not found`

### Step 3: Write the implementation

```php
<?php

declare(strict_types=1);

namespace App\Domain\Genetic\CmaEs;

/**
 * Immutable snapshot of all CMA-ES optimizer state.
 *
 * Vectors and matrices use 0-indexed float arrays.
 * All normalized-space values are in approximately [0,1] (CMA-ES is not bounded).
 *
 * Strategy parameters (muEff, cSigma, …) are computed once during initialState()
 * and stored here so tell() doesn't need to recompute them.
 */
final class CmaEsState
{
    public function __construct(
        // Problem dimension
        public readonly int $n,

        // Population parameters
        public readonly int $lambda,
        public readonly int $mu,

        // Pre-computed strategy parameters (invariant across generations)
        /** @var float[] positive weights summing to 1, length = mu */
        public readonly array $weights,
        public readonly float $muEff,
        public readonly float $cSigma,
        public readonly float $dSigma,
        public readonly float $cc,
        public readonly float $c1,
        public readonly float $cmu,
        public readonly float $chiN,

        // Distribution state (updated each generation)
        /** @var float[] mean vector, length = n */
        public readonly array $mean,
        public readonly float $sigma,
        /** @var float[][] covariance matrix C, n×n row-major */
        public readonly array $covariance,
        /** @var float[][] eigenvectors of C (columns), n×n */
        public readonly array $eigenVectors,
        /** @var float[] sqrt of eigenvalues of C (= D diagonal), length = n */
        public readonly array $eigenValues,
        /** @var float[] evolution path for sigma, length = n */
        public readonly array $pathSigma,
        /** @var float[] evolution path for C, length = n */
        public readonly array $pathC,

        // Generation bookkeeping
        public readonly int $generation,
        public readonly int $eigenLastUpdate,
    ) {
    }
}
```

### Step 4: Run test to verify it passes

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/codecept run Unit Domain/Genetic/CmaEs/CmaEsStateTest --no-ansi 2>&1 | tail -5"
```
Expected: `OK (1 test, 5 assertions)`

### Step 5: Commit

```bash
git add app/src/Domain/Genetic/CmaEs/CmaEsState.php app/tests/Unit/Domain/Genetic/CmaEs/CmaEsStateTest.php
git commit -m "feat: add CmaEsState value object"
```

---

## Task 2: CmaEsNormalizer

Maps between gene value arrays (strategy space) and float vectors (normalized [0,1] CMA-ES space). Uses `GenomeFactory::fromArray()` for denormalization so that clamping and all constraint repairs are applied automatically.

**Files:**
- Create: `app/src/Domain/Genetic/CmaEs/CmaEsNormalizer.php`
- Create: `app/tests/Unit/Domain/Genetic/CmaEs/CmaEsNormalizerTest.php`

### Step 1: Write the failing test

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Genetic\CmaEs;

use App\Domain\Genetic\CmaEs\CmaEsNormalizer;
use App\Domain\Genetic\Constraint\MacdConstraint;
use App\Domain\Genetic\Constraint\RegimeMaConstraint;
use App\Domain\Genetic\Constraint\WeightNormalizationConstraint;
use App\Domain\Genetic\ConstraintRepair;
use App\Domain\Genetic\GenomeFactory;
use App\Domain\Genetic\Genome\GeneCatalog;
use App\Application\Config\StrategyConfig;
use Codeception\Test\Unit;

class CmaEsNormalizerTest extends Unit
{
    private CmaEsNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $catalog = new GeneCatalog();
        $repair  = new ConstraintRepair([
            new MacdConstraint(),
            new WeightNormalizationConstraint(),
            new RegimeMaConstraint(),
        ]);
        $this->normalizer = new CmaEsNormalizer($catalog, new GenomeFactory($catalog, $repair));
    }

    public function testDimensionCountMatchesCatalog(): void
    {
        $this->assertSame(count((new GeneCatalog())->all()), $this->normalizer->dimensionCount());
    }

    public function testNormalizeBoundaryValues(): void
    {
        $catalog = new GeneCatalog();
        $genes   = [];
        foreach ($catalog->all() as $name => $gene) {
            $genes[$name] = $gene->min(); // set every gene to its minimum
        }

        $vector = $this->normalizer->normalize($genes);

        foreach ($vector as $v) {
            $this->assertEqualsWithDelta(0.0, $v, 1e-9, 'min value must normalize to 0.0');
        }

        // max values should normalize to 1.0
        foreach ($catalog->all() as $name => $gene) {
            $genes[$name] = $gene->max();
        }
        $vector = $this->normalizer->normalize($genes);
        foreach ($vector as $v) {
            $this->assertEqualsWithDelta(1.0, $v, 1e-9, 'max value must normalize to 1.0');
        }
    }

    public function testNormalizeDenormalizeRoundTrip(): void
    {
        // Use the default genome (constraints already applied) as the test input
        $config = StrategyConfig::createDefault();
        $genes  = $config->geneArray();

        $vector     = $this->normalizer->normalize($genes);
        $decoded    = $this->normalizer->denormalize($vector);

        foreach ($genes as $name => $original) {
            $this->assertEqualsWithDelta(
                (float)$original,
                (float)$decoded[$name],
                0.02,  // small tolerance due to constraint repair potentially shifting values
                "Gene '$name' round-trip failed"
            );
        }
    }

    public function testDenormalizeAppliesMacdConstraint(): void
    {
        // Construct a vector that would produce bsMacdSignal >= bsMacdFast + bsMacdSpread
        $catalog = new GeneCatalog();
        $genes   = StrategyConfig::createDefault()->geneArray();

        // Force MACD constraint violation: fast=3, spread=5 → slow=8; signal=9 ≥ slow=8
        $genes['bsMacdFast']   = 3;
        $genes['bsMacdSpread'] = 5;
        $genes['bsMacdSignal'] = 9;

        $vector  = $this->normalizer->normalize($genes);
        $decoded = $this->normalizer->denormalize($vector);

        $fast   = (int)$decoded['bsMacdFast'];
        $spread = (int)$decoded['bsMacdSpread'];
        $signal = (int)$decoded['bsMacdSignal'];

        $this->assertLessThan($fast + $spread, $signal, 'MacdConstraint must be enforced after denormalize');
    }

    public function testDenormalizeClampsOutOfBoundsVector(): void
    {
        // A vector entirely outside [0,1] should clamp to valid gene values
        $n      = $this->normalizer->dimensionCount();
        $vector = array_fill(0, $n, 1.5); // all at 150% — should clamp to max

        $decoded = $this->normalizer->denormalize($vector);
        $catalog = new GeneCatalog();

        foreach ($catalog->all() as $name => $gene) {
            $this->assertLessThanOrEqual(
                (float)$gene->max(),
                (float)$decoded[$name],
                "Gene '$name' must not exceed max"
            );
        }
    }
}
```

### Step 2: Run test to verify it fails

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/codecept run Unit Domain/Genetic/CmaEs/CmaEsNormalizerTest --no-ansi 2>&1 | tail -5"
```
Expected: `Class "App\Domain\Genetic\CmaEs\CmaEsNormalizer" not found`

### Step 3: Write the implementation

```php
<?php

declare(strict_types=1);

namespace App\Domain\Genetic\CmaEs;

use App\Domain\Genetic\GeneDefinition\IntGene;
use App\Domain\Genetic\GenomeFactory;
use App\Domain\Genetic\Genome\GeneCatalog;

/**
 * Converts between gene value arrays and normalized float vectors for CMA-ES.
 *
 * Normalization: v_normalized = (v - min) / (max - min) → approximately [0, 1]
 * Denormalization: v_raw = min + v_normalized * (max - min), then:
 *   - integer genes are rounded
 *   - GenomeFactory::fromArray() clamps each gene to [min, max]
 *   - ConstraintRepair is applied (MacdConstraint, WeightNormalizationConstraint, RegimeMaConstraint)
 */
final class CmaEsNormalizer
{
    /** @var string[] */
    private array $geneNames;
    /** @var float[] */
    private array $mins;
    /** @var float[] */
    private array $ranges;
    /** @var bool[] */
    private array $isInt;

    public function __construct(
        private readonly GeneCatalog $catalog,
        private readonly GenomeFactory $factory,
    ) {
        $this->geneNames = [];
        $this->mins      = [];
        $this->ranges    = [];
        $this->isInt     = [];

        foreach ($catalog->all() as $name => $gene) {
            $this->geneNames[] = $name;
            $this->mins[]      = (float)$gene->min();
            $this->ranges[]    = (float)$gene->max() - (float)$gene->min();
            $this->isInt[]     = ($gene instanceof IntGene);
        }
    }

    public function dimensionCount(): int
    {
        return count($this->geneNames);
    }

    /**
     * Map gene values to normalized [0,1] float vector.
     *
     * @param array<string, int|float> $geneValues
     * @return float[]
     */
    public function normalize(array $geneValues): array
    {
        $vector = [];
        foreach ($this->geneNames as $i => $name) {
            $range    = $this->ranges[$i];
            $vector[] = $range > 0.0
                ? ((float)$geneValues[$name] - $this->mins[$i]) / $range
                : 0.0;
        }
        return $vector;
    }

    /**
     * Map normalized float vector back to gene values.
     * Clamps out-of-range values, rounds integers, applies constraint repair.
     *
     * @param float[] $vector
     * @return array<string, int|float>
     */
    public function denormalize(array $vector): array
    {
        $raw = [];
        foreach ($this->geneNames as $i => $name) {
            $v         = $this->mins[$i] + $vector[$i] * $this->ranges[$i];
            $raw[$name] = $this->isInt[$i] ? (int)round($v) : $v;
        }

        // GenomeFactory::fromArray() clamps each value to [min,max] via gene->withValue()
        // and applies full ConstraintRepair (Macd, WeightNormalization, RegimeMa).
        return $this->factory->fromArray($raw)->toArray();
    }
}
```

### Step 4: Run test to verify it passes

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/codecept run Unit Domain/Genetic/CmaEs/CmaEsNormalizerTest --no-ansi 2>&1 | tail -5"
```
Expected: `OK (5 tests, ...assertions)`

### Step 5: Commit

```bash
git add app/src/Domain/Genetic/CmaEs/CmaEsNormalizer.php app/tests/Unit/Domain/Genetic/CmaEs/CmaEsNormalizerTest.php
git commit -m "feat: add CmaEsNormalizer (gene ↔ normalized vector)"
```

---

## Task 3: CmaEsOptimizer — math utilities and core algorithm

This is the largest task. Split into sub-tasks for clarity.

**Files:**
- Create: `app/src/Domain/Genetic/CmaEs/CmaEsOptimizer.php`
- Create: `app/tests/Unit/Domain/Genetic/CmaEs/CmaEsOptimizerTest.php`

### Step 1: Write the failing tests

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Genetic\CmaEs;

use App\Domain\Genetic\CmaEs\CmaEsOptimizer;
use App\Domain\Genetic\CmaEs\CmaEsState;
use Codeception\Test\Unit;

class CmaEsOptimizerTest extends Unit
{
    private CmaEsOptimizer $optimizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->optimizer = new CmaEsOptimizer();
    }

    // -----------------------------------------------------------------------
    // Jacobi eigendecomposition tests
    // -----------------------------------------------------------------------

    public function testJacobiIdentityMatrix(): void
    {
        // Eigenvalues of I are all 1, eigenvectors are columns of I
        $n    = 3;
        $I    = [[1,0,0],[0,1,0],[0,0,1]];
        [$evs, $evecs] = $this->optimizer->eigenDecompose($I, $n);

        // Verify C ≈ B * diag(evs) * B^T  (reconstruction test)
        $C_reconstructed = $this->_reconstruct($evecs, $evs, $n);
        for ($p = 0; $p < $n; $p++) {
            for ($q = 0; $q < $n; $q++) {
                $this->assertEqualsWithDelta($I[$p][$q], $C_reconstructed[$p][$q], 1e-9);
            }
        }
    }

    public function testJacobiKnownMatrix(): void
    {
        // Known symmetric 3×3: eigenvalues are 1, 2, 3
        // C = [[2, -1, 0], [-1, 3, -1], [0, -1, 2]]  (tridiagonal)
        $n = 3;
        $C = [[2, -1, 0], [-1, 3, -1], [0, -1, 2]];

        [$evs, $evecs] = $this->optimizer->eigenDecompose($C, $n);

        // All eigenvalues must be non-negative
        foreach ($evs as $ev) {
            $this->assertGreaterThanOrEqual(0.0, $ev);
        }

        // Reconstruction: B * D * B^T ≈ C  where D = diag(evs)
        $C_reconstructed = $this->_reconstruct($evecs, $evs, $n);
        for ($p = 0; $p < $n; $p++) {
            for ($q = 0; $q < $n; $q++) {
                $this->assertEqualsWithDelta(
                    $C[$p][$q],
                    $C_reconstructed[$p][$q],
                    1e-8,
                    "Reconstruction error at [$p][$q]"
                );
            }
        }
    }

    // -----------------------------------------------------------------------
    // initialState tests
    // -----------------------------------------------------------------------

    public function testInitialStateHasCorrectDimensions(): void
    {
        $n     = 5;
        $mean  = [0.2, 0.4, 0.6, 0.3, 0.5];
        $state = $this->optimizer->initialState($n, $mean, sigma: 0.3, lambda: 10);

        $this->assertSame($n, $state->n);
        $this->assertCount($n, $state->mean);
        $this->assertCount($n, $state->eigenValues);
        $this->assertCount($n, $state->pathSigma);
        $this->assertCount($n, $state->pathC);
        $this->assertCount($n, $state->covariance);
        $this->assertCount($n, $state->eigenVectors);
        $this->assertSame(0, $state->generation);
    }

    public function testInitialStateMuIsHalfLambda(): void
    {
        $state = $this->optimizer->initialState(5, [0.5, 0.5, 0.5, 0.5, 0.5], 0.3, lambda: 12);
        $this->assertSame(6, $state->mu);
    }

    public function testInitialStateWeightsSumToOne(): void
    {
        $state = $this->optimizer->initialState(5, [0.5, 0.5, 0.5, 0.5, 0.5], 0.3, lambda: 10);
        $sum   = array_sum($state->weights);
        $this->assertEqualsWithDelta(1.0, $sum, 1e-9, 'Weights must sum to 1');
    }

    // -----------------------------------------------------------------------
    // ask tests
    // -----------------------------------------------------------------------

    public function testAskReturnsLambdaCandidates(): void
    {
        $n     = 5;
        $mean  = [0.5, 0.5, 0.5, 0.5, 0.5];
        $state = $this->optimizer->initialState($n, $mean, 0.3, lambda: 8);
        $candidates = $this->optimizer->ask($state);

        $this->assertCount(8, $candidates);
        foreach ($candidates as $c) {
            $this->assertArrayHasKey('vector', $c);
            $this->assertArrayHasKey('z', $c);
            $this->assertCount($n, $c['vector']);
            $this->assertCount($n, $c['z']);
        }
    }

    public function testAskProducesDifferentCandidates(): void
    {
        $n    = 10;
        $mean = array_fill(0, $n, 0.5);
        $state      = $this->optimizer->initialState($n, $mean, 0.3, lambda: 20);
        $candidates = $this->optimizer->ask($state);

        // At least some candidates should differ from each other
        $first  = $candidates[0]['vector'];
        $second = $candidates[1]['vector'];
        $different = false;
        for ($i = 0; $i < $n; $i++) {
            if (abs($first[$i] - $second[$i]) > 1e-10) {
                $different = true;
                break;
            }
        }
        $this->assertTrue($different, 'Candidates should not all be identical');
    }

    // -----------------------------------------------------------------------
    // tell tests — convergence on a sphere function
    // -----------------------------------------------------------------------

    public function testTellMovesMeanTowardSphereOptimum(): void
    {
        // Sphere function: minimise ||x - optimum||², optimum at (0.3, 0.7, 0.5)
        $n       = 3;
        $optimum = [0.3, 0.7, 0.5];
        $mean    = [0.5, 0.5, 0.5]; // start at center

        $state  = $this->optimizer->initialState($n, $mean, sigma: 0.3, lambda: 20);
        $initDist = $this->_sphereDist($state->mean, $optimum, $n);

        // Run 30 generations
        for ($gen = 0; $gen < 30; $gen++) {
            $candidates = $this->optimizer->ask($state);

            // Evaluate: sphere function, negate for "fitness" (CMA-ES minimizes internally)
            // We pass rankedCandidates sorted best-first (lowest sphere value = best)
            usort($candidates, fn($a, $b) =>
                $this->_sphereDist($a['vector'], $optimum, $n)
                <=> $this->_sphereDist($b['vector'], $optimum, $n)
            );

            $state = $this->optimizer->tell($state, $candidates);
        }

        $finalDist = $this->_sphereDist($state->mean, $optimum, $n);
        $this->assertLessThan($initDist * 0.1, $finalDist,
            "Mean should converge toward optimum (initDist=$initDist, finalDist=$finalDist)");
    }

    public function testTellIncreasesGenerationCounter(): void
    {
        $n          = 3;
        $mean       = [0.5, 0.5, 0.5];
        $state      = $this->optimizer->initialState($n, $mean, 0.3, lambda: 6);
        $candidates = $this->optimizer->ask($state);
        $newState   = $this->optimizer->tell($state, $candidates);

        $this->assertSame(1, $newState->generation);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** Reconstruct C = B * diag(eigenvalues) * B^T */
    private function _reconstruct(array $B, array $eigenvalues, int $n): array
    {
        $C = [];
        for ($p = 0; $p < $n; $p++) {
            $C[$p] = array_fill(0, $n, 0.0);
            for ($q = 0; $q < $n; $q++) {
                for ($k = 0; $k < $n; $k++) {
                    $C[$p][$q] += $B[$p][$k] * $eigenvalues[$k] * $B[$q][$k];
                }
            }
        }
        return $C;
    }

    private function _sphereDist(array $x, array $optimum, int $n): float
    {
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $d    = $x[$i] - $optimum[$i];
            $sum += $d * $d;
        }
        return sqrt($sum);
    }
}
```

### Step 2: Run test to verify it fails

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/codecept run Unit Domain/Genetic/CmaEs/CmaEsOptimizerTest --no-ansi 2>&1 | tail -5"
```
Expected: `Class "App\Domain\Genetic\CmaEs\CmaEsOptimizer" not found`

### Step 3: Write the implementation

```php
<?php

declare(strict_types=1);

namespace App\Domain\Genetic\CmaEs;

/**
 * Core CMA-ES optimizer (Hansen & Ostermeier 2001).
 *
 * Stateless service — all state lives in CmaEsState.
 * Use the ask/tell interface:
 *
 *   $state = $optimizer->initialState($n, $mean, $sigma, $lambda);
 *   for ($gen = 0; $gen < $maxGen; $gen++) {
 *       $candidates = $optimizer->ask($state);           // sample λ candidates
 *       // evaluate each $candidate['vector'] externally
 *       usort($candidates, fn($a, $b) => $b['fitness'] <=> $a['fitness']); // best first
 *       $state = $optimizer->tell($state, $candidates);  // update distribution
 *   }
 */
final class CmaEsOptimizer
{
    // Box-Muller spare value for Gaussian sampling
    private static bool  $_spareReady = false;
    private static float $_spare      = 0.0;

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Create an initial CMA-ES state.
     *
     * @param float[] $mean   Initial mean in normalized [0,1] space (length = n)
     * @param float   $sigma  Initial step size (0.3 recommended for [0,1] space)
     * @param int     $lambda Population size (default: 4+floor(3*ln(n)))
     */
    public function initialState(int $n, array $mean, float $sigma, int $lambda = 0): CmaEsState
    {
        if ($lambda <= 0) {
            $lambda = 4 + (int)floor(3 * log($n));
        }

        $mu = (int)floor($lambda / 2);

        // Weights: w'_i = ln(μ+0.5) - ln(i),  i = 1..μ
        $weightsRaw = [];
        for ($i = 1; $i <= $mu; $i++) {
            $weightsRaw[] = log($mu + 0.5) - log($i);
        }
        $wSum    = array_sum($weightsRaw);
        $weights = array_map(fn($w) => $w / $wSum, $weightsRaw);
        $muEff   = 1.0 / array_sum(array_map(fn($w) => $w * $w, $weights));

        // Strategy parameters
        $cSigma = ($muEff + 2) / ($n + $muEff + 5);
        $dSigma = 1 + 2 * max(0, sqrt(($muEff - 1) / ($n + 1)) - 1) + $cSigma;
        $cc     = (4 + $muEff / $n) / ($n + 4 + 2 * $muEff / $n);
        $c1     = 2 / (($n + 1.3) ** 2 + $muEff);
        $cmu    = min(1 - $c1, 2 * ($muEff - 2 + 1 / $muEff) / (($n + 2) ** 2 + $muEff));
        $chiN   = sqrt($n) * (1 - 1 / (4 * $n) + 1 / (21 * $n * $n));

        // Initial covariance = identity
        $eye = [];
        for ($i = 0; $i < $n; $i++) {
            $eye[$i] = array_fill(0, $n, 0.0);
            $eye[$i][$i] = 1.0;
        }
        $ones = array_fill(0, $n, 1.0);
        $zero = array_fill(0, $n, 0.0);

        return new CmaEsState(
            n: $n,
            lambda: $lambda,
            mu: $mu,
            weights: $weights,
            muEff: $muEff,
            cSigma: $cSigma,
            dSigma: $dSigma,
            cc: $cc,
            c1: $c1,
            cmu: $cmu,
            chiN: $chiN,
            mean: array_values($mean),
            sigma: $sigma,
            covariance: $eye,
            eigenVectors: $eye,
            eigenValues: $ones,   // sqrt(eigenvalues) of identity = 1
            pathSigma: $zero,
            pathC: $zero,
            generation: 0,
            eigenLastUpdate: 0,
        );
    }

    /**
     * Sample λ candidate solutions from the current distribution.
     *
     * Returns an array of λ elements, each with:
     *   'vector' => float[]  candidate in normalized space
     *   'z'      => float[]  raw Gaussian noise used (needed by tell())
     *
     * @return array{vector: float[], z: float[]}[]
     */
    public function ask(CmaEsState $state): array
    {
        $n          = $state->n;
        $candidates = [];

        for ($k = 0; $k < $state->lambda; $k++) {
            // z ~ N(0, I_n)
            $z = $this->_gaussianVector($n);

            // y = B * (D ⊙ z)   where C = B * D² * Bᵀ
            $Dz = $this->_elemMul($state->eigenValues, $z);
            $y  = $this->_matVecMul($state->eigenVectors, $Dz, $n);

            // x = mean + σ * y
            $x = [];
            for ($i = 0; $i < $n; $i++) {
                $x[$i] = $state->mean[$i] + $state->sigma * $y[$i];
            }

            $candidates[] = ['vector' => $x, 'z' => $z];
        }

        return $candidates;
    }

    /**
     * Update the CMA-ES distribution using the evaluated, ranked candidates.
     *
     * @param array{vector: float[], z: float[]}[] $rankedCandidates
     *   Must be sorted best-first (highest fitness first).
     *   Only the first $state->mu candidates are used.
     */
    public function tell(CmaEsState $state, array $rankedCandidates): CmaEsState
    {
        $n   = $state->n;
        $mu  = $state->mu;
        $gen = $state->generation + 1;
        $w   = $state->weights;

        $mOld = $state->mean;

        // --- 1. Update mean: weighted combination of best μ solutions ---
        $mNew = array_fill(0, $n, 0.0);
        for ($i = 0; $i < $mu; $i++) {
            $x = $rankedCandidates[$i]['vector'];
            for ($j = 0; $j < $n; $j++) {
                $mNew[$j] += $w[$i] * $x[$j];
            }
        }

        // step = (m_new - m_old) / σ  (normalized movement)
        $step = [];
        for ($j = 0; $j < $n; $j++) {
            $step[$j] = ($mNew[$j] - $mOld[$j]) / $state->sigma;
        }

        // invsqrtC * step = B * ((1/D) ⊙ (Bᵀ * step))
        $BtStep      = $this->_matTVecMul($state->eigenVectors, $step, $n);
        $invDBtStep  = [];
        for ($j = 0; $j < $n; $j++) {
            $invDBtStep[$j] = ($state->eigenValues[$j] > 1e-20)
                ? $BtStep[$j] / $state->eigenValues[$j]
                : 0.0;
        }
        $invsqrtCStep = $this->_matVecMul($state->eigenVectors, $invDBtStep, $n);

        // --- 2. Update evolution path for σ (p_σ) ---
        $sqrtCSigma = sqrt($state->cSigma * (2 - $state->cSigma) * $state->muEff);
        $pSigmaNew  = [];
        for ($j = 0; $j < $n; $j++) {
            $pSigmaNew[$j] = (1 - $state->cSigma) * $state->pathSigma[$j]
                + $sqrtCSigma * $invsqrtCStep[$j];
        }
        $normPSigma = $this->_norm($pSigmaNew);

        // --- 3. Update σ (CSA: Cumulative Step-size Adaptation) ---
        $sigmaPower = ($state->cSigma / $state->dSigma) * ($normPSigma / $state->chiN - 1);
        $sigmaNew   = $state->sigma * exp($sigmaPower);
        // Clamp σ to [1e-12, 10] to prevent degeneration
        $sigmaNew = max(1e-12, min($sigmaNew, 10.0));

        // --- 4. h_σ indicator (stall flag for rank-1 path) ---
        $normalizationDenominator = sqrt(1 - (1 - $state->cSigma) ** (2 * $gen));
        $hSigmaThreshold          = (1.4 + 2.0 / ($n + 1)) * $state->chiN;
        $hSigma = ($normPSigma / max($normalizationDenominator, 1e-20) < $hSigmaThreshold) ? 1 : 0;

        // --- 5. Update evolution path for C (p_c) ---
        $sqrtCC = sqrt($state->cc * (2 - $state->cc) * $state->muEff);
        $pCNew  = [];
        for ($j = 0; $j < $n; $j++) {
            $pCNew[$j] = (1 - $state->cc) * $state->pathC[$j]
                + $hSigma * $sqrtCC * $step[$j];
        }

        // --- 6. Update covariance matrix C ---
        $deltaH = (1 - $hSigma) * $state->cc * (2 - $state->cc);

        // artmp[i] = (x_{i:λ} - m_old) / σ  for best μ candidates
        $artmp = [];
        for ($i = 0; $i < $mu; $i++) {
            $x        = $rankedCandidates[$i]['vector'];
            $artmp[$i] = [];
            for ($j = 0; $j < $n; $j++) {
                $artmp[$i][$j] = ($x[$j] - $mOld[$j]) / $state->sigma;
            }
        }

        // C_new = (1-c1-cμ)*C + c1*(p_c*p_cᵀ + δ_h*C) + cμ*Σ wᵢ*artmp_i*artmp_iᵀ
        $CNew = [];
        for ($p = 0; $p < $n; $p++) {
            $CNew[$p] = [];
            for ($q = 0; $q < $n; $q++) {
                $val  = (1 - $state->c1 - $state->cmu) * $state->covariance[$p][$q];
                $val += $state->c1 * ($pCNew[$p] * $pCNew[$q] + $deltaH * $state->covariance[$p][$q]);
                for ($i = 0; $i < $mu; $i++) {
                    $val += $state->cmu * $w[$i] * $artmp[$i][$p] * $artmp[$i][$q];
                }
                $CNew[$p][$q] = $val;
            }
        }

        // --- 7. Eigendecompose C periodically ---
        $eigenLastUpdate = $state->eigenLastUpdate;
        $eigenVectors    = $state->eigenVectors;
        $eigenValues     = $state->eigenValues;

        $eigenEvery = max(1, (int)floor(1.0 / (10.0 * $n * ($state->c1 + $state->cmu))));

        if ($gen - $eigenLastUpdate >= $eigenEvery) {
            [$rawEigenvalues, $evecs] = $this->eigenDecompose($CNew, $n);
            // eigenValues = D = sqrt(eigenvalues)
            $eigenValues     = array_map(fn($v) => sqrt(max(0.0, $v)), $rawEigenvalues);
            $eigenVectors    = $evecs;
            $eigenLastUpdate = $gen;
        }

        return new CmaEsState(
            n: $n,
            lambda: $state->lambda,
            mu: $mu,
            weights: $w,
            muEff: $state->muEff,
            cSigma: $state->cSigma,
            dSigma: $state->dSigma,
            cc: $state->cc,
            c1: $state->c1,
            cmu: $state->cmu,
            chiN: $state->chiN,
            mean: $mNew,
            sigma: $sigmaNew,
            covariance: $CNew,
            eigenVectors: $eigenVectors,
            eigenValues: $eigenValues,
            pathSigma: $pSigmaNew,
            pathC: $pCNew,
            generation: $gen,
            eigenLastUpdate: $eigenLastUpdate,
        );
    }

    // -----------------------------------------------------------------------
    // Public math helpers (public for testability)
    // -----------------------------------------------------------------------

    /**
     * Jacobi cyclic eigendecomposition for symmetric positive semi-definite matrices.
     * Returns [eigenvalues[], eigenvectors[][]] where eigenvectors are COLUMNS of the
     * returned matrix (i.e., $evecs[$row][$col] is component $row of eigenvector $col).
     *
     * @param float[][] $C  symmetric n×n matrix
     * @return array{float[], float[][]}  [eigenvalues, eigenvectors]
     */
    public function eigenDecompose(array $C, int $n): array
    {
        // Working copy of C (will become diagonal)
        $A = $C;

        // B accumulates eigenvectors, starts as identity
        $B = [];
        for ($i = 0; $i < $n; $i++) {
            $B[$i] = array_fill(0, $n, 0.0);
            $B[$i][$i] = 1.0;
        }

        for ($sweep = 0; $sweep < 100; $sweep++) {
            // Check off-diagonal Frobenius norm for convergence
            $offNorm = 0.0;
            for ($p = 0; $p < $n - 1; $p++) {
                for ($q = $p + 1; $q < $n; $q++) {
                    $offNorm += $A[$p][$q] * $A[$p][$q];
                }
            }
            if ($offNorm < 1e-20) {
                break;
            }

            // Cyclic sweep: process all off-diagonal pairs
            for ($p = 0; $p < $n - 1; $p++) {
                for ($q = $p + 1; $q < $n; $q++) {
                    if (abs($A[$p][$q]) < 1e-15) {
                        continue;
                    }

                    // Givens rotation angle
                    $theta = 0.5 * atan2(2.0 * $A[$p][$q], $A[$p][$p] - $A[$q][$q]);
                    $c     = cos($theta);
                    $s     = sin($theta);

                    // New diagonal elements
                    $app = $c * $c * $A[$p][$p] - 2 * $s * $c * $A[$p][$q] + $s * $s * $A[$q][$q];
                    $aqq = $s * $s * $A[$p][$p] + 2 * $s * $c * $A[$p][$q] + $c * $c * $A[$q][$q];

                    // Update off-diagonal rows/cols
                    for ($k = 0; $k < $n; $k++) {
                        if ($k === $p || $k === $q) {
                            continue;
                        }
                        $apk = $c * $A[$p][$k] - $s * $A[$q][$k];
                        $aqk = $s * $A[$p][$k] + $c * $A[$q][$k];
                        $A[$p][$k] = $A[$k][$p] = $apk;
                        $A[$q][$k] = $A[$k][$q] = $aqk;
                    }

                    $A[$p][$p] = $app;
                    $A[$q][$q] = $aqq;
                    $A[$p][$q] = $A[$q][$p] = 0.0;

                    // Accumulate eigenvectors
                    for ($k = 0; $k < $n; $k++) {
                        $bkp    = $c * $B[$k][$p] - $s * $B[$k][$q];
                        $bkq    = $s * $B[$k][$p] + $c * $B[$k][$q];
                        $B[$k][$p] = $bkp;
                        $B[$k][$q] = $bkq;
                    }
                }
            }
        }

        // Extract eigenvalues from diagonal, clamp to ≥0
        $eigenvalues = [];
        for ($i = 0; $i < $n; $i++) {
            $eigenvalues[$i] = max(0.0, $A[$i][$i]);
        }

        return [$eigenvalues, $B];
    }

    // -----------------------------------------------------------------------
    // Private math helpers
    // -----------------------------------------------------------------------

    /** Element-wise multiplication of two vectors */
    private function _elemMul(array $a, array $b): array
    {
        $n      = count($a);
        $result = [];
        for ($i = 0; $i < $n; $i++) {
            $result[$i] = $a[$i] * $b[$i];
        }
        return $result;
    }

    /** Matrix-vector multiplication: M * v (M is n×n row-major, v is n-vector) */
    private function _matVecMul(array $M, array $v, int $n): array
    {
        $result = array_fill(0, $n, 0.0);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $result[$i] += $M[$i][$j] * $v[$j];
            }
        }
        return $result;
    }

    /** Transpose matrix-vector multiplication: Mᵀ * v */
    private function _matTVecMul(array $M, array $v, int $n): array
    {
        $result = array_fill(0, $n, 0.0);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $result[$i] += $M[$j][$i] * $v[$j];
            }
        }
        return $result;
    }

    /** Euclidean norm of a vector */
    private function _norm(array $v): float
    {
        $sum = 0.0;
        foreach ($v as $x) {
            $sum += $x * $x;
        }
        return sqrt($sum);
    }

    /**
     * Sample an n-dimensional vector from N(0, I) using Box-Muller transform.
     *
     * @return float[]
     */
    private function _gaussianVector(int $n): array
    {
        $v = [];
        for ($i = 0; $i < $n; $i++) {
            $v[] = $this->_gaussianScalar();
        }
        return $v;
    }

    private function _gaussianScalar(): float
    {
        if (self::$_spareReady) {
            self::$_spareReady = false;
            return self::$_spare;
        }
        do {
            $u = mt_rand() / mt_getrandmax() * 2.0 - 1.0;
            $v = mt_rand() / mt_getrandmax() * 2.0 - 1.0;
            $s = $u * $u + $v * $v;
        } while ($s >= 1.0 || $s === 0.0);
        $mul              = sqrt(-2.0 * log($s) / $s);
        self::$_spare     = $v * $mul;
        self::$_spareReady = true;
        return $u * $mul;
    }
}
```

### Step 4: Run test to verify it passes

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/codecept run Unit Domain/Genetic/CmaEs/CmaEsOptimizerTest --no-ansi 2>&1 | tail -10"
```
Expected: `OK (9 tests, ...assertions)`

### Step 5: Run all unit tests to ensure nothing is broken

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/codecept run Unit --no-ansi 2>&1 | tail -5"
```

### Step 6: Commit

```bash
git add app/src/Domain/Genetic/CmaEs/CmaEsOptimizer.php app/tests/Unit/Domain/Genetic/CmaEs/CmaEsOptimizerTest.php
git commit -m "feat: add CmaEsOptimizer with Jacobi eigendecomposition and ask/tell interface"
```

---

## Task 4: CmaEsOptimizeCommand

Console command that replaces the GA-based `GeneticOptimizeCommand` with the CMA-ES optimizer. Same CLI options, same output format, same gene file output.

**Files:**
- Create: `app/src/Infrastructure/Console/CmaEsOptimizeCommand.php`

No unit test for this task (it requires full simulation infrastructure). The integration smoke test in Task 5 covers it.

### Step 1: Write the implementation

Key differences from `GeneticOptimizeCommand`:
- Uses `CmaEsOptimizer` (ask/tell) instead of `GeneticAlgorithm` (initPopulation/nextGeneration)
- Normalizes candidates before simulation, denormalizes after
- Tracks global best across all generations (elitism implicit)

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Application\Config\StrategyConfig;
use App\Application\DTO\Individual;
use App\Application\Service\SimulationDataEnsurer;
use App\Domain\Genetic\CmaEs\CmaEsNormalizer;
use App\Domain\Genetic\CmaEs\CmaEsOptimizer;
use App\Domain\Genetic\CmaEs\CmaEsState;
use App\Domain\Genetic\Genome\GeneCatalog;
use App\Domain\Simulation\SimulationRunner;
use App\Infrastructure\MarketData\CandleProviderFactory;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

// php bin/console app:cmaes-optimize
#[AsCommand(
    name: 'app:cmaes-optimize',
    description: 'Finds optimal strategy parameters using CMA-ES (Covariance Matrix Adaptation).'
)]
class CmaEsOptimizeCommand extends Command
{
    private const int   DEFAULT_LAMBDA     = 20;  // population size
    private const int   DEFAULT_GENERATIONS = 200;
    private const float DEFAULT_SIGMA       = 0.3;

    public const string DEFAULT_GENES_FILE = '/app/src/Application/Config/cmaes_genes.json';

    private StrategyConfig $baseConfig;

    public function __construct(
        private readonly CandleProviderFactory  $candleProviderFactory,
        private readonly SimulationDataEnsurer  $dataEnsurer,
        private readonly SimulationRunner       $simulationRunner,
        private readonly CmaEsOptimizer         $cmaEsOptimizer,
        private readonly CmaEsNormalizer        $normalizer,
        private readonly GeneCatalog            $geneCatalog,
    ) {
        parent::__construct();
        $this->baseConfig = StrategyConfig::createDefault();
    }

    protected function configure(): void
    {
        $this
            ->addOption('lambda', null, InputOption::VALUE_OPTIONAL, 'Population size (λ)', self::DEFAULT_LAMBDA)
            ->addOption('generations', null, InputOption::VALUE_OPTIONAL, 'Number of generations', self::DEFAULT_GENERATIONS)
            ->addOption('sigma', null, InputOption::VALUE_OPTIONAL, 'Initial step size σ₀ (0.3 = 30% of each gene range)', self::DEFAULT_SIGMA)
            ->addOption('genes-file', null, InputOption::VALUE_OPTIONAL, 'Path to write best genes JSON', self::DEFAULT_GENES_FILE)
            ->addOption('sim-start-date', null, InputOption::VALUE_OPTIONAL, 'Sim start date (PHP-parseable, e.g. "2025-01-01", "-6 months")')
            ->addOption('sim-end-date', null, InputOption::VALUE_OPTIONAL, 'Sim end date (PHP-parseable, e.g. "2025-09-21", "now")');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '3G');
        ini_set('trader.real_precision', 5);

        $lambda      = (int)$input->getOption('lambda');
        $generations = (int)$input->getOption('generations');
        $sigma       = (float)$input->getOption('sigma');
        $genesFile   = (string)$input->getOption('genes-file');

        $config       = $this->baseConfig;
        $symbol       = $config->get('symbol');
        $simStartDate = new \DateTimeImmutable($input->getOption('sim-start-date') ?? $config->get('simStartDate'));
        $simEndDate   = new \DateTimeImmutable($input->getOption('sim-end-date') ?? $config->get('simEndDate'));

        // --- Load candles ONCE ---
        $output->writeln("Loading candles for $symbol ...");
        $candleProvider     = $this->candleProviderFactory->create('db');
        $dataFetchStartDate = $simStartDate->modify('-50 days');
        $allCandles         = [];

        foreach ($config->get('interval_configs') as $interval => $intervalConfig) {
            $this->dataEnsurer->ensureDataIsComplete($symbol, $interval, $dataFetchStartDate, $simEndDate, new NullLogger());
            $candles               = $candleProvider->getCandles($symbol, $interval, 0, $dataFetchStartDate, $simEndDate);
            $allCandles[$interval] = array_values(iterator_to_array($candles));
        }

        $n = $this->normalizer->dimensionCount();
        $output->writeln("Candles loaded. Starting CMA-ES: n=$n, λ=$lambda, σ₀=$sigma, generations=$generations");
        $output->writeln('');

        // --- Warm-start from current best genome ---
        $x0    = $this->normalizer->normalize($config->geneArray());
        $state = $this->cmaEsOptimizer->initialState($n, $x0, $sigma, $lambda);

        /** @var Individual|null $globalBest */
        $globalBest = null;

        // --- CMA-ES loop ---
        for ($gen = 1; $gen <= $generations; $gen++) {
            $candidates = $this->cmaEsOptimizer->ask($state);

            $evaluated = [];
            foreach ($candidates as $candidate) {
                $geneValues = $this->normalizer->denormalize($candidate['vector']);
                $config     = $this->baseConfig->withOverrides($geneValues);
                $result     = $this->simulationRunner->run($config, $allCandles, $simStartDate, $simEndDate, null);
                $individual = new Individual($config, $result);

                $evaluated[] = array_merge($candidate, ['fitness' => $individual->fitness(), 'individual' => $individual]);

                if ($globalBest === null || $individual->fitness() > $globalBest->fitness()) {
                    $globalBest = $individual;
                }
            }

            // Sort best-first (highest fitness first) before tell()
            usort($evaluated, fn($a, $b) => $b['fitness'] <=> $a['fitness']);

            $state = $this->cmaEsOptimizer->tell($state, $evaluated);

            $best = $evaluated[0]['individual'];
            $output->write("\r\033[K");
            $output->write(sprintf(
                "Gen %3d/%d | σ=%.4f | Best: fitness=%5.2f PF=%4.2f buys=%2d | Global: fitness=%5.2f | %s",
                $gen,
                $generations,
                $state->sigma,
                $best->fitness(),
                $best->result->profitFactor,
                $best->result->buys,
                $globalBest->fitness(),
                $this->_formatGenomeInline($best->config),
            ));
        }

        $output->writeln('');
        $output->writeln('');
        $this->_printFinalResults($output, $globalBest, $state);

        $this->_saveGenes($genesFile, $globalBest, $simStartDate, $simEndDate);
        $output->writeln('<info>Best genome saved to ' . $genesFile . '</info>');

        return Command::SUCCESS;
    }

    private function _printFinalResults(OutputInterface $output, Individual $best, CmaEsState $state): void
    {
        $sep      = str_repeat('═', 72);
        $geneNames = array_keys($this->geneCatalog->all());
        $maxLen   = max(array_map('strlen', $geneNames));

        $output->writeln($sep);
        $output->writeln(' CMA-ES OPTIMISATION COMPLETE');
        $output->writeln($sep);
        $output->writeln(sprintf(' Fitness:          %.2f', $best->fitness()));
        $output->writeln(sprintf(' Profit Factor:    %.2f', $best->result->profitFactor));
        $output->writeln(sprintf(' PnL (incl open):  %+.2f', $best->result->pnlInclOpen));
        $output->writeln(sprintf(
            ' Buys: %d  |  Drawdown: %.2f  |  Max consec. losses: %d',
            $best->result->buys,
            $best->result->maxDrawdown,
            $best->result->maxConsecutiveLosses,
        ));
        $output->writeln(sprintf(' Final σ (step size): %.6f', $state->sigma));
        $output->writeln('');
        $output->writeln(' Best genome:');
        $bestGenes = $best->config->geneArray();
        foreach ($geneNames as $gene) {
            $output->writeln(sprintf('   %-' . $maxLen . 's = %s', $gene, $this->_formatGeneValue($bestGenes[$gene])));
        }
        $output->writeln($sep);
    }

    private function _formatGenomeInline(StrategyConfig $config): string
    {
        $parts = [];
        $genes = $config->geneArray();
        foreach (array_keys($this->geneCatalog->all()) as $gene) {
            $parts[] = $gene . '=' . $this->_formatGeneValue($genes[$gene]);
        }
        return implode(' ', $parts);
    }

    private function _formatGeneValue(mixed $value): string
    {
        return is_int($value) ? (string)$value : sprintf('%g', $value);
    }

    private function _saveGenes(
        string $path,
        Individual $best,
        \DateTimeImmutable $simStartDate,
        \DateTimeImmutable $simEndDate,
    ): void {
        $data = [
            'updatedAt'    => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'simStartDate' => $simStartDate->format('Y-m-d'),
            'simEndDate'   => $simEndDate->format('Y-m-d'),
            'fitness'      => $best->fitness(),
            'profitFactor' => $best->result->profitFactor,
            'buys'         => $best->result->buys,
            'genes'        => $best->config->geneArray(),
        ];

        $written = file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($written === false) {
            throw new \RuntimeException('Failed to write genes file: ' . $path);
        }
    }
}
```

### Step 2: Run CS check

```bash
docker exec -it paybis-app bash -c "cd /app && composer cs-check 2>&1 | tail -10"
```

Fix any PSR-12 issues with:

```bash
docker exec -it paybis-app bash -c "cd /app && composer cs-fix 2>&1"
```

### Step 3: Commit

```bash
git add app/src/Infrastructure/Console/CmaEsOptimizeCommand.php
git commit -m "feat: add CmaEsOptimizeCommand (ask/tell CMA-ES loop)"
```

---

## Task 5: Wire services + smoke test

The command needs `CmaEsOptimizer` and `CmaEsNormalizer` injected. Symfony autowires by type — check if explicit service registration is required.

**Files to check:**
- `app/config/services.yaml` — see if there is manual wiring needed

### Step 1: Check services.yaml

```bash
docker exec -it paybis-app bash -c "grep -n 'GeneticAlgorithm\|GeneCatalog\|ConstraintRepair' /app/config/services.yaml 2>/dev/null | head -20"
```

If `GeneCatalog` and `ConstraintRepair` are not autowired (they have multiple constructor args or require specific instances), you need to add explicit bindings. Typical pattern if needed:

```yaml
# In app/config/services.yaml, under services:
App\Domain\Genetic\CmaEs\CmaEsNormalizer:
    arguments:
        $catalog: '@App\Domain\Genetic\Genome\GeneCatalog'
        $factory: '@App\Domain\Genetic\GenomeFactory'
```

But first check if autowiring already works by running the smoke test below.

### Step 2: Run PHPStan to catch type errors

```bash
docker exec -it paybis-app bash -c "cd /app && php -d xdebug.mode=off vendor/bin/phpstan analyse --memory-limit=512M 2>&1 | tail -20"
```

Fix any level-1 issues found.

### Step 3: Smoke test — run 2 generations

```bash
docker exec -it paybis-app bash -c "cd /app && php -d xdebug.mode=off bin/console app:cmaes-optimize --lambda=6 --generations=2 --sim-start-date='2025-01-01' --sim-end-date='2025-03-01' 2>&1"
```

Expected: Command starts, loads candles, runs 2 generations, prints results, saves genes file. No exceptions.

### Step 4: Run full unit test suite

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/codecept run Unit --no-ansi 2>&1 | tail -5"
```

Expected: All tests pass.

### Step 5: Commit

```bash
git add -A
git commit -m "feat: wire CmaEsOptimizeCommand services; all tests pass"
```

---

## Done

The CMA-ES optimizer is now available as `app:cmaes-optimize`. Recommended first run:

```bash
# 200 generations, population 20, warm-start from current best genome, last 6 months
php -d xdebug.mode=off bin/console app:cmaes-optimize \
    --lambda=20 \
    --generations=200 \
    --sigma=0.3 \
    --sim-start-date='-6 months' \
    --sim-end-date='now'
```

The `app:genetic-optimize` command remains intact for comparison.
