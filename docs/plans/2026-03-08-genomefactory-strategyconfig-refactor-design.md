# Design: GenomeFactory as Single Genome Entry Point + StrategyConfig Decoupling

**Date:** 2026-03-08
**Branch:** 3-stage

## Problem

`StrategyConfig` violates SRP by knowing about domain internals:
- Instantiates `new GeneCatalog()` inline in `withOverrides()` to route gene vs static keys
- Instantiates `new MacdConstraint()->repair()` after gene overrides
- `Genome::set()` allows external mutation, breaking immutability

Additionally, `GenomeFactory`, `ConstraintRepair`, and `WeightConstraint` are dead scaffolded code never wired into active use. `WeightConstraint` is also broken (references `bsVolumeWeight` which does not exist in `GeneCatalog`).

## Goals

1. `StrategyConfig` has zero knowledge of `GeneCatalog`, `MacdConstraint`, or gene routing
2. `Genome` is fully immutable
3. `GenomeFactory` is the single entry point for all genome creation with automatic repair
4. `MacdConstraint` repair is encapsulated inside `GenomeFactory` via `ConstraintRepair`
5. Dead code (`WeightConstraint`) is removed

## Design

### `Genome` — fully immutable

Remove `set(string $name, Gene $gene): void`. The only mutation path is `withValues(array): self`, which already returns a new instance.

### `GeneConstraint` interface — new signature

```php
public function repair(Genome $genome): Genome;
```

Returns a new `Genome` instead of mutating in place. Enables immutable repair chains.

### `MacdConstraint` — immutable repair

```php
public function repair(Genome $genome): Genome
{
    $fast   = $genome->get('bsMacdFast')->value();
    $spread = $genome->get('bsMacdSpread')->value();
    $slow   = $fast + $spread;
    $signal = $genome->get('bsMacdSignal')->value();

    if ($signal >= $slow) {
        return $genome->withValues(['bsMacdSignal' => $slow - 1]);
    }
    return $genome;
}
```

### `ConstraintRepair` — return Genome

```php
public function repair(Genome $genome): Genome
{
    foreach ($this->constraints as $constraint) {
        $genome = $constraint->repair($genome);
    }
    return $genome;
}
```

Already returns `Genome` — only needs the loop updated to chain immutably.

### `WeightConstraint` — deleted

Dead code. References `bsVolumeWeight` which does not exist in `GeneCatalog`. No callers.

### `GenomeFactory` — single entry point

```php
final class GenomeFactory
{
    public function __construct(
        private GeneCatalog $catalog,
        private ConstraintRepair $repair
    ) {}

    public function fromDefaults(): Genome
    {
        $json   = json_decode(file_get_contents(__DIR__ . '/../../Application/Config/default_genes.json'), true);
        $genome = $this->catalog->genomeFromArray($json['genes']);
        return $this->repair->repair($genome);
    }

    public function fromArray(array $values): Genome
    {
        $genome = $this->catalog->genomeFromArray($values);
        return $this->repair->repair($genome);
    }

    public function random(): Genome  // already exists
    {
        $genome = $this->catalog->genomeFromRandom();
        return $this->repair->repair($genome);
    }
}
```

`fromDefaults()` and `fromArray()` are new. `random()` already exists but its internal implementation moves to use `genomeFromRandom()` from the catalog.

### `StrategyConfig` — inject `GenomeFactory`, add `withParams()`

```php
final class StrategyConfig
{
    private function __construct(
        private array $params,
        private Genome $genes,
        private GenomeFactory $factory
    ) {}

    public static function createDefault(GenomeFactory $factory): self
    {
        return new self(self::defaults(), $factory->fromDefaults(), $factory);
    }

    public function withOverrides(array $geneValues): self
    {
        $merged = array_merge($this->genes->toArray(), $geneValues);
        return new self($this->params, $this->factory->fromArray($merged), $this->factory);
    }

    public function withParams(array $params): self
    {
        return new self(array_merge($this->params, $params), $this->genes, $this->factory);
    }
}
```

Removes imports: `GeneCatalog`, `MacdConstraint`. No routing logic. No inline `new`.

### Callers updated

**`StrategyOptimizer`** — split the mixed `withOverrides()` call:

```php
// Before
$baseConfig = StrategyConfig::createDefault()->withOverrides(
    array_merge($seedGenes, ['simStartDate' => ..., 'simEndDate' => ...])
);

// After
$baseConfig = StrategyConfig::createDefault($this->genomeFactory)
    ->withOverrides($seedGenes)
    ->withParams(['simStartDate' => ..., 'simEndDate' => ...]);
```

**`GeneticAlgorithm`** — `initPopulation` uses `GenomeFactory::random()` then wraps in config:

```php
// initPopulation: $baseConfig->withOverrides($factory->random()->toArray())
```

Or keep current pattern — `withOverrides($genes)` works the same since it routes through `fromArray`.

**`ShortStrategySimulationCommand`** — already gene-only, no change needed.

**`PaperTradeCommand`** — already gene-only, no change needed.

## File Change Summary

| File | Change |
|------|--------|
| `Domain/Genetic/Genome/Genome.php` | Remove `set()` |
| `Domain/Genetic/Constraint/GeneConstraint.php` | `repair(): Genome` |
| `Domain/Genetic/Constraint/MacdConstraint.php` | Return new Genome via `withValues()` |
| `Domain/Genetic/Constraint/WeightConstraint.php` | **Delete** |
| `Domain/Genetic/ConstraintRepair.php` | Chain immutably in loop |
| `Domain/Genetic/GenomeFactory.php` | Add `fromDefaults()`, `fromArray()`; update `random()` |
| `Application/Config/StrategyConfig.php` | Inject `GenomeFactory`; `withOverrides()` gene-only; add `withParams()` |
| `Application/GeneticOptimization/Service/StrategyOptimizer.php` | Split mixed `withOverrides()` call |
| Symfony DI config | Wire `GenomeFactory` with `MacdConstraint` in `ConstraintRepair` |
