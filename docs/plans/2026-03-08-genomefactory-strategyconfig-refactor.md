# GenomeFactory / StrategyConfig Refactor Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Decouple `StrategyConfig` from gene domain internals by making `Genome` fully immutable and routing all genome creation through `GenomeFactory`.

**Architecture:** `GenomeFactory` becomes the single entry point for building/repairing `Genome` objects. `StrategyConfig` holds a `GenomeFactory` reference (injected at construction) and delegates genome operations to it. `withOverrides()` accepts gene arrays only; static param overrides go through new `withParams()`.

**Tech Stack:** PHP 8.4, Codeception 5 Unit tests (`vendor/bin/codecept run Unit`), PSR-12 (`composer cs-fix`).

---

### Task 1: Make `Genome` immutable — remove `set()`

**Files:**
- Modify: `app/src/Domain/Genetic/Genome/Genome.php`
- Modify: `app/tests/Unit/Domain/Genetic/Genome/GenomeTest.php`

**Step 1: Update `GenomeTest` — replace `testSetUpdatesGene` with immutability assertion**

Replace the existing `testSetUpdatesGene` test with:

```php
public function testWithValuesReturnsNewInstance(): void
{
    $updated = $this->genome->withValues(['atr' => 8.0]);
    $this->assertNotSame($this->genome, $updated);
    $this->assertSame(5.0, $this->genome->get('atr')->value()); // original unchanged
    $this->assertSame(8.0, $updated->get('atr')->value());
}
```

**Step 2: Run test to confirm it fails** (the old `testSetUpdatesGene` will still exist and pass; the new test may already pass since `withValues` already returns a new instance — just verify the test suite compiles)

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/codecept run Unit Domain/Genetic/Genome/GenomeTest -v"
```

Expected: old `testSetUpdatesGene` passes, new test passes too.

**Step 3: Remove `set()` from `Genome`**

Delete lines 28–31 of `app/src/Domain/Genetic/Genome/Genome.php`:

```php
// DELETE this method entirely:
public function set(string $name, Gene $gene): void
{
    $this->genes[$name] = $gene;
}
```

**Step 4: Run tests to confirm no regressions**

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/codecept run Unit -v"
```

Expected: `testSetUpdatesGene` is gone (you deleted it in Step 1), all others pass.

**Step 5: Commit**

```bash
git add app/src/Domain/Genetic/Genome/Genome.php app/tests/Unit/Domain/Genetic/Genome/GenomeTest.php
git commit -m "refactor: make Genome immutable — remove set()"
```

---

### Task 2: Update `GeneConstraint` interface + `MacdConstraint` to return `Genome`

**Files:**
- Modify: `app/src/Domain/Genetic/Constraint/GeneConstraint.php`
- Modify: `app/src/Domain/Genetic/Constraint/MacdConstraint.php`
- Create: `app/tests/Unit/Domain/Genetic/Constraint/MacdConstraintTest.php`

**Step 1: Write the failing test**

Create `app/tests/Unit/Domain/Genetic/Constraint/MacdConstraintTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Genetic\Constraint;

use App\Domain\Genetic\Constraint\MacdConstraint;
use App\Domain\Genetic\GeneDefinition\FloatGene;
use App\Domain\Genetic\GeneDefinition\IntGene;
use App\Domain\Genetic\Genome\Genome;
use Codeception\Test\Unit;

class MacdConstraintTest extends Unit
{
    private function _makeGenome(int $fast, int $spread, int $signal): Genome
    {
        return new Genome([
            'bsMacdFast'   => (new IntGene('bsMacdFast', 3, 15, 1))->withValue($fast),
            'bsMacdSpread' => (new IntGene('bsMacdSpread', 5, 25, 1))->withValue($spread),
            'bsMacdSignal' => (new IntGene('bsMacdSignal', 3, 9, 1))->withValue($signal),
        ]);
    }

    public function testRepairReturnsNewGenomeWhenConstraintViolated(): void
    {
        $genome  = $this->_makeGenome(fast: 5, spread: 5, signal: 10); // slow=10, signal>=slow → violates
        $constraint = new MacdConstraint();
        $repaired = $constraint->repair($genome);

        $this->assertNotSame($genome, $repaired, 'repair() must return a new Genome instance');
        $this->assertSame(10, $genome->get('bsMacdSignal')->value(), 'original genome must be unchanged');
        $this->assertSame(9, $repaired->get('bsMacdSignal')->value(), 'signal must be clamped to slow-1');
    }

    public function testRepairReturnsSameGenomeWhenConstraintSatisfied(): void
    {
        $genome = $this->_makeGenome(fast: 5, spread: 5, signal: 7); // slow=10, signal=7 < 10 → ok
        $constraint = new MacdConstraint();
        $repaired = $constraint->repair($genome);

        $this->assertSame($genome, $repaired, 'repair() must return the same instance when no repair needed');
        $this->assertSame(7, $repaired->get('bsMacdSignal')->value());
    }
}
```

**Step 2: Run test to verify it fails**

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/codecept run Unit Domain/Genetic/Constraint/MacdConstraintTest -v"
```

Expected: FAIL — `repair()` currently returns `void`, not `Genome`.

**Step 3: Update `GeneConstraint` interface**

Replace the body of `app/src/Domain/Genetic/Constraint/GeneConstraint.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Genetic\Constraint;

use App\Domain\Genetic\Genome\Genome;

interface GeneConstraint
{
    public function repair(Genome $genome): Genome;
}
```

**Step 4: Update `MacdConstraint`**

Replace the body of `app/src/Domain/Genetic/Constraint/MacdConstraint.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Genetic\Constraint;

use App\Domain\Genetic\Genome\Genome;

final class MacdConstraint implements GeneConstraint
{
    public function repair(Genome $genome): Genome
    {
        $fast   = $genome->get('bsMacdFast')->value();
        $spread = $genome->get('bsMacdSpread')->value();
        $signal = $genome->get('bsMacdSignal')->value();
        $slow   = $fast + $spread;

        if ($signal >= $slow) {
            return $genome->withValues(['bsMacdSignal' => $slow - 1]);
        }

        return $genome;
    }
}
```

**Step 5: Run tests**

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/codecept run Unit Domain/Genetic/Constraint/MacdConstraintTest -v"
```

Expected: both tests PASS.

**Step 6: Run full suite to check for regressions**

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/codecept run Unit -v"
```

**Step 7: Commit**

```bash
git add app/src/Domain/Genetic/Constraint/GeneConstraint.php \
        app/src/Domain/Genetic/Constraint/MacdConstraint.php \
        app/tests/Unit/Domain/Genetic/Constraint/MacdConstraintTest.php
git commit -m "refactor: GeneConstraint::repair() returns Genome (immutable)"
```

---

### Task 3: Delete `WeightConstraint` + update `ConstraintRepair` to chain immutably

**Files:**
- Delete: `app/src/Domain/Genetic/Constraint/WeightConstraint.php`
- Modify: `app/src/Domain/Genetic/ConstraintRepair.php`
- Create: `app/tests/Unit/Domain/Genetic/ConstraintRepairTest.php`

**Step 1: Delete `WeightConstraint`**

```bash
rm app/src/Domain/Genetic/Constraint/WeightConstraint.php
```

Dead code — references `bsVolumeWeight` gene that does not exist in `GeneCatalog`. No callers, no tests.

**Step 2: Write test for `ConstraintRepair`**

Create `app/tests/Unit/Domain/Genetic/ConstraintRepairTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Genetic;

use App\Domain\Genetic\Constraint\MacdConstraint;
use App\Domain\Genetic\ConstraintRepair;
use App\Domain\Genetic\GeneDefinition\IntGene;
use App\Domain\Genetic\Genome\Genome;
use Codeception\Test\Unit;

class ConstraintRepairTest extends Unit
{
    private function _makeGenome(int $fast, int $spread, int $signal): Genome
    {
        return new Genome([
            'bsMacdFast'   => (new IntGene('bsMacdFast', 3, 15, 1))->withValue($fast),
            'bsMacdSpread' => (new IntGene('bsMacdSpread', 5, 25, 1))->withValue($spread),
            'bsMacdSignal' => (new IntGene('bsMacdSignal', 3, 9, 1))->withValue($signal),
        ]);
    }

    public function testRepairChainsConstraintsImmutably(): void
    {
        $genome = $this->_makeGenome(fast: 5, spread: 5, signal: 10); // violates MacdConstraint
        $repair = new ConstraintRepair([new MacdConstraint()]);

        $repaired = $repair->repair($genome);

        $this->assertNotSame($genome, $repaired);
        $this->assertSame(10, $genome->get('bsMacdSignal')->value(), 'original unchanged');
        $this->assertSame(9, $repaired->get('bsMacdSignal')->value(), 'repaired to slow-1');
    }

    public function testRepairWithNoConstraintsReturnsSameGenome(): void
    {
        $genome = $this->_makeGenome(fast: 5, spread: 5, signal: 7);
        $repair = new ConstraintRepair([]);

        $this->assertSame($genome, $repair->repair($genome));
    }
}
```

**Step 3: Run test to verify it fails**

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/codecept run Unit Domain/Genetic/ConstraintRepairTest -v"
```

Expected: FAIL — `ConstraintRepair::repair()` still calls `void` constraints.

**Step 4: Update `ConstraintRepair`**

Replace `app/src/Domain/Genetic/ConstraintRepair.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Genetic;

use App\Domain\Genetic\Constraint\GeneConstraint;
use App\Domain\Genetic\Genome\Genome;

final class ConstraintRepair
{
    /** @var GeneConstraint[] */
    private array $constraints;

    public function __construct(array $constraints)
    {
        $this->constraints = $constraints;
    }

    public function repair(Genome $genome): Genome
    {
        foreach ($this->constraints as $constraint) {
            $genome = $constraint->repair($genome);
        }

        return $genome;
    }
}
```

**Step 5: Run tests**

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/codecept run Unit -v"
```

Expected: all pass.

**Step 6: Commit**

```bash
git add app/src/Domain/Genetic/ConstraintRepair.php \
        app/tests/Unit/Domain/Genetic/ConstraintRepairTest.php
git rm app/src/Domain/Genetic/Constraint/WeightConstraint.php
git commit -m "refactor: ConstraintRepair chains immutably; delete WeightConstraint (dead code)"
```

---

### Task 4: Add `GenomeFactory::fromArray()` and `fromDefaults()`

**Files:**
- Modify: `app/src/Domain/Genetic/GenomeFactory.php`
- Create: `app/tests/Unit/Domain/Genetic/GenomeFactoryTest.php`

**Step 1: Write the failing tests**

Create `app/tests/Unit/Domain/Genetic/GenomeFactoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Genetic;

use App\Domain\Genetic\Constraint\MacdConstraint;
use App\Domain\Genetic\ConstraintRepair;
use App\Domain\Genetic\GenomeFactory;
use App\Domain\Genetic\Genome\GeneCatalog;
use Codeception\Test\Unit;

class GenomeFactoryTest extends Unit
{
    private GenomeFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new GenomeFactory(
            new GeneCatalog(),
            new ConstraintRepair([new MacdConstraint()])
        );
    }

    public function testFromDefaultsReturnsGenomeWith48Genes(): void
    {
        $genome = $this->factory->fromDefaults();
        $this->assertCount(48, $genome->toArray());
    }

    public function testFromDefaultsAppliesMacdRepair(): void
    {
        $genome = $this->factory->fromDefaults();
        $genes  = $genome->toArray();
        $this->assertLessThan(
            $genes['bsMacdFast'] + $genes['bsMacdSpread'],
            $genes['bsMacdSignal'],
            'MacdConstraint must be satisfied after fromDefaults()'
        );
    }

    public function testFromArrayBuildsGenomeFromValues(): void
    {
        $defaults = $this->factory->fromDefaults()->toArray();
        $defaults['atrStopMultiplier'] = 6.0;

        $genome = $this->factory->fromArray($defaults);
        $this->assertSame(6.0, $genome->toArray()['atrStopMultiplier']);
    }

    public function testFromArrayAppliesMacdRepair(): void
    {
        $defaults                  = $this->factory->fromDefaults()->toArray();
        $defaults['bsMacdFast']    = 5;
        $defaults['bsMacdSpread']  = 5;
        $defaults['bsMacdSignal']  = 99; // violates: slow=10, signal must be < 10

        $genome = $this->factory->fromArray($defaults);
        $genes  = $genome->toArray();
        $this->assertLessThan(
            $genes['bsMacdFast'] + $genes['bsMacdSpread'],
            $genes['bsMacdSignal']
        );
    }

    public function testRandomReturnsGenomeWith48Genes(): void
    {
        $genome = $this->factory->random();
        $this->assertCount(48, $genome->toArray());
    }

    public function testRandomAppliesMacdRepair(): void
    {
        // Run several times to catch probabilistic violations
        for ($i = 0; $i < 20; $i++) {
            $genes = $this->factory->random()->toArray();
            $this->assertLessThan(
                $genes['bsMacdFast'] + $genes['bsMacdSpread'],
                $genes['bsMacdSignal'],
                "MacdConstraint violated on iteration $i"
            );
        }
    }
}
```

**Step 2: Run tests to verify they fail**

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/codecept run Unit Domain/Genetic/GenomeFactoryTest -v"
```

Expected: FAIL — `fromDefaults()` and `fromArray()` don't exist yet.

**Step 3: Implement `fromDefaults()` and `fromArray()` in `GenomeFactory`**

Replace `app/src/Domain/Genetic/GenomeFactory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Genetic;

use App\Domain\Genetic\Genome\GeneCatalog;
use App\Domain\Genetic\Genome\Genome;

final class GenomeFactory
{
    public function __construct(
        private GeneCatalog $catalog,
        private ConstraintRepair $repair
    ) {
    }

    public function fromDefaults(): Genome
    {
        $path   = __DIR__ . '/../../Application/Config/default_genes.json';
        $json   = json_decode(file_get_contents($path), true);
        $genome = $this->catalog->genomeFromArray($json['genes']);

        return $this->repair->repair($genome);
    }

    public function fromArray(array $values): Genome
    {
        $genome = $this->catalog->genomeFromArray($values);

        return $this->repair->repair($genome);
    }

    public function random(): Genome
    {
        $genome = $this->catalog->genomeFromRandom();

        return $this->repair->repair($genome);
    }
}
```

Note: `GenoCatalog::genomeFromRandom()` already exists. The old `GenomeFactory::random()` built genes manually — replace it with the catalog's method which does the same thing cleanly.

**Step 4: Run tests**

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/codecept run Unit Domain/Genetic/GenomeFactoryTest -v"
```

Expected: all 6 tests PASS.

**Step 5: Run full suite**

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/codecept run Unit -v"
```

**Step 6: Commit**

```bash
git add app/src/Domain/Genetic/GenomeFactory.php \
        app/tests/Unit/Domain/Genetic/GenomeFactoryTest.php
git commit -m "feat: GenomeFactory::fromDefaults() and fromArray() with automatic repair"
```

---

### Task 5: Refactor `StrategyConfig` — inject `GenomeFactory`, gene-only `withOverrides`, add `withParams()`

**Files:**
- Modify: `app/src/Application/Config/StrategyConfig.php`
- Modify: `app/tests/Unit/Application/DTO/StrategyConfigTest.php`
- Modify: `app/tests/Unit/Application/Config/GeneticConfigTest.php`

**Step 1: Update `StrategyConfigTest` — migrate static-param calls from `withOverrides` to `withParams`**

The following tests pass static params to `withOverrides`. Update each to use `withParams` instead.

In `testWithOverridesReturnsNewInstance` (line 97–104):
```php
// BEFORE
$modified = $original->withOverrides(['symbol' => 'BTCUSDT']);
// AFTER
$modified = $original->withParams(['symbol' => 'BTCUSDT']);
```

In `testWithOverridesPreservesUnchangedValues` (line 160–180):
```php
// BEFORE
$modified = $original->withOverrides(['symbol' => 'ETHUSDT', 'buyCount' => 500.0]);
// AFTER
$modified = $original->withParams(['symbol' => 'ETHUSDT', 'buyCount' => 500.0]);
```

In `testWithOverridesAcceptsNestedRiskManagement` (line 183–195):
```php
// BEFORE
$modified = $config->withOverrides(['riskManagement' => [...]]);
// AFTER
$modified = $config->withParams(['riskManagement' => [...]]);
```

In `testValidateThrowsOnInvalidMaxOpenPositions`, `testValidateThrowsOnNegativeFeePercent`, `testValidateThrowsOnFeePercentOver100`, `testValidateThrowsOnInvalidBuyCount`, `testValidateThrowsOnInvalidBaseRR`, `testValidateThrowsOnInvalidExitMode` — all use `withOverrides` with non-gene keys. Change each to `withParams`.

Also add a new `testWithParamsReturnsNewInstance` test:

```php
public function testWithParamsReturnsNewInstance(): void
{
    $original = StrategyConfig::createDefault();
    $modified = $original->withParams(['symbol' => 'BTCUSDT']);

    $this->assertNotSame($original, $modified);
    $this->assertSame('DOGEUSDT', $original->get('symbol'));
    $this->assertSame('BTCUSDT', $modified->get('symbol'));
}
```

**Step 2: Run tests to verify they now fail** (since `withParams` doesn't exist yet)

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/codecept run Unit Application/DTO/StrategyConfigTest -v"
```

Expected: FAIL on tests using `withParams`.

**Step 3: Rewrite `StrategyConfig`**

Replace `app/src/Application/Config/StrategyConfig.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Config;

use App\Domain\Genetic\Constraint\MacdConstraint;
use App\Domain\Genetic\ConstraintRepair;
use App\Domain\Genetic\GenomeFactory;
use App\Domain\Genetic\Genome\GeneCatalog;
use App\Domain\Genetic\Genome\Genome;
use App\Domain\Position\ExitMode;

final class StrategyConfig
{
    private array $params;
    private Genome $genes;
    private GenomeFactory $factory;

    private function __construct(array $params, Genome $genes, GenomeFactory $factory)
    {
        $this->params  = $params;
        $this->genes   = $genes;
        $this->factory = $factory;
        $this->validate();
    }

    private static function defaults(): array
    {
        return [
            // ... (keep exactly as-is, no changes to the defaults array)
        ];
    }

    public static function createDefault(): self
    {
        $factory = new GenomeFactory(
            new GeneCatalog(),
            new ConstraintRepair([new MacdConstraint()])
        );

        return new self(self::defaults(), $factory->fromDefaults(), $factory);
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

    /**
     * Returns a new config with gene values overridden and constraints auto-repaired.
     * Only gene keys (defined in GeneCatalog) are accepted.
     *
     * @param array<string, int|float> $geneValues
     */
    public function withOverrides(array $geneValues): self
    {
        $merged = array_merge($this->genes->toArray(), $geneValues);

        return new self($this->params, $this->factory->fromArray($merged), $this->factory);
    }

    /**
     * Returns a new config with static (non-gene) params overridden.
     *
     * @param array<string, mixed> $params
     */
    public function withParams(array $params): self
    {
        return new self(array_merge($this->params, $params), $this->genes, $this->factory);
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

        ExitMode::from($this->params['exitMode']);
    }
}
```

Key changes:
- `withOverrides(array $geneValues)`: merges with current gene values then calls `$this->factory->fromArray()` — no `GeneCatalog` or `MacdConstraint` inline usage
- `withParams(array $params)`: pure static param merge, no gene knowledge
- `createDefault()`: builds its own `GenomeFactory` once — acceptable in a static factory method
- The `defaults()` array body is unchanged (copy from original)

**Step 4: Run tests**

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/codecept run Unit Application/DTO/StrategyConfigTest Application/Config/GeneticConfigTest -v"
```

Expected: all pass.

**Step 5: Run full suite**

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/codecept run Unit -v"
```

**Step 6: Commit**

```bash
git add app/src/Application/Config/StrategyConfig.php \
        app/tests/Unit/Application/DTO/StrategyConfigTest.php \
        app/tests/Unit/Application/Config/GeneticConfigTest.php
git commit -m "refactor: StrategyConfig injects GenomeFactory; withOverrides gene-only; add withParams()"
```

---

### Task 6: Update `StrategyOptimizer` — split mixed `withOverrides` call

**Files:**
- Modify: `app/src/Application/GeneticOptimization/Service/StrategyOptimizer.php`

**Step 1: Update `optimize()` at line 44–53**

```php
// BEFORE
$baseConfig = StrategyConfig::createDefault()->withOverrides(
    array_merge(
        $seedGenes,
        [
            'simStartDate' => $simStartDate->format('Y-m-d'),
            'simEndDate'   => $simEndDate->format('Y-m-d'),
        ]
    )
);

// AFTER
$baseConfig = StrategyConfig::createDefault()
    ->withOverrides($seedGenes)
    ->withParams([
        'simStartDate' => $simStartDate->format('Y-m-d'),
        'simEndDate'   => $simEndDate->format('Y-m-d'),
    ]);
```

**Step 2: Run full suite**

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/codecept run Unit -v"
```

**Step 3: Run static analysis**

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/phpstan analyse --memory-limit=512M"
```

Expected: no errors.

**Step 4: Run code style**

```bash
docker exec -it paybis-app bash -c "cd /app && composer cs-fix"
```

**Step 5: Commit**

```bash
git add app/src/Application/GeneticOptimization/Service/StrategyOptimizer.php
git commit -m "refactor: StrategyOptimizer splits gene/param overrides"
```

---

### Task 7: Final verification

**Step 1: Run full test suite**

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/codecept run Unit -v"
```

Expected: all tests pass, zero failures.

**Step 2: Static analysis**

```bash
docker exec -it paybis-app bash -c "cd /app && vendor/bin/phpstan analyse --memory-limit=512M"
```

**Step 3: Code style**

```bash
docker exec -it paybis-app bash -c "cd /app && composer cs-check"
```

**Step 4: Verify `StrategyConfig` no longer imports `GeneCatalog` or `MacdConstraint` directly in `withOverrides`**

```bash
grep -n "new GeneCatalog\|new MacdConstraint\|array_intersect_key" app/src/Application/Config/StrategyConfig.php
```

Expected: only appears inside `createDefault()` static factory, not in `withOverrides`.
