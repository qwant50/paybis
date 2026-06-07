# StrategyConfig — Array-backed Design

**Date:** 2026-03-07
**Branch:** 3-stage
**Status:** Approved

## Problem

`StrategyConfig` is a readonly class with 37 individually named constructor properties.
This causes four pain points:

1. `withOverrides()` is 40 lines of manual property enumeration — fragile, must be updated every time a param is added.
2. `_staticToArray()` duplicates all 37 property names again.
3. Adding one static param requires 4 edits: constructor, `withOverrides()`, `_staticToArray()`, and the `new self(...)` call.
4. `public Genome $genes` leaks the domain object; 10+ consumers call `$config->genes->toArray()` directly.

## Design

### StrategyConfig internal structure

Replace the 37 named promoted properties with two private fields:

```php
final class StrategyConfig
{
    private array $params;  // all static config (was: named constructor props)
    private Genome $genes;  // encapsulated gene holder (was: public)

    private function __construct(array $params, Genome $genes) { ... }

    private static function defaults(): array { /* all 37 static defaults */ }

    public static function createDefault(): self
    {
        $catalog = new GeneCatalog();
        $json    = json_decode(file_get_contents(__DIR__ . '/default_genes.json'), true);
        $genome  = $catalog->genomeFromArray($json['genes']);
        return new self(self::defaults(), $genome);
    }

    public function get(string $key): mixed          // static param accessor
    public function geneArray(): array               // all 48 gene values
    public function withOverrides(array $overrides): self
    public function toArray(): array                 // flat merge (backward compat)
    private function validate(): void                // unchanged
}
```

### `withOverrides()` — reduced from ~40 lines to ~10

```php
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
```

`_staticToArray()` is deleted; `toArray()` becomes `array_merge($this->params, $this->genes->toArray())`.

### Adding a static param (after this change)

1 edit only: add a key/default to `self::defaults()`.

## Consumer Migration

**Pattern:**
- `$config->someStaticProp` → `$config->get('someStaticProp')`
- `$config->genes->toArray()` → `$config->geneArray()`

**Files to update (mechanical find-and-replace):**

| File | Changes |
|---|---|
| `Domain/Simulation/SimulationRunner.php` | ~15 property accesses + 3× `->genes->toArray()` |
| `Application/Service/PaperTradingEngine.php` | ~12 property accesses + 1× `->genes->toArray()` |
| `Application/Service/EntryFilterPipeline.php` | 5 property accesses |
| `Infrastructure/Console/GeneticOptimizeCommand.php` | 6 property accesses + 3× `->genes->toArray()` |
| `Application/GeneticOptimization/Service/StrategyOptimizer.php` | 2× `->genes->toArray()` |
| `Infrastructure/Persistence/JsonGeneStorage.php` | 1× `->genes->toArray()` |
| `Domain/Genetic/GeneticAlgorithm.php` | 1× `->genes->toArray()` |
| `Infrastructure/Console/PaperTradeCommand.php` | 1 property access |

No behavioral changes anywhere — purely mechanical substitution.

## Out of Scope

- `Genome` internals — unchanged
- `GeneCatalog` — unchanged
- `default_genes.json` — unchanged
- Console command option parsing — unchanged
- Tests — update any direct `->genes->toArray()` or `->someStaticProp` calls in the same pattern
