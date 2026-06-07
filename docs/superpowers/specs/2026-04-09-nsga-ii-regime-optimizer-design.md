# NSGA-II Regime-Conditioned Optimizer — Design Spec

**Date:** 2026-04-09
**Status:** Approved

---

## Context

Three optimizers already exist (GA, CMA-ES, DE/JADE). All treat the fitness landscape as a single objective and optimize one genome for the entire market history. This design adds a fourth optimizer that:

1. **Specialises per market regime** — finds a separate optimal genome for each of the four regimes (Sideways, TrendingUp, TrendingDown, HighVolatility) by evaluating each individual only on candles where its own genes classify the market into that regime.
2. **Uses multi-objective search (NSGA-II)** — instead of a single fitness scalar, optimises three objectives simultaneously: realized profit, trade count, and drawdown. Returns a Pareto front; winner is selected by scalarizing with the existing `FitnessCalculator`.
3. **Uses DE variation** — reuses `DifferentialEvolution::generateTrials()` (`DE/current-to-best/1/bin`) as the mutation/crossover operator (MODE variant), with per-sub-population JADE adaptation of `μF`/`μCR`.

---

## New Files

```
app/src/Domain/Genetic/
  NsgaIIAlgorithm.php

app/src/Domain/Regime/
  RegimeLabeler.php

app/src/Application/GeneticOptimization/
  Command/OptimizeNsgaRegimeStrategyCommand.php
  Handler/OptimizeNsgaRegimeStrategyHandler.php
  Service/NsgaRegimeOptimizer.php

app/src/Application/Config/
  RegimeAwareConfig.php

app/src/Infrastructure/Console/
  NsgaRegimeOptimizeCommand.php
```

---

## Modified Files

### `Domain/Simulation/SimulationRunner::run()`

Add optional parameter:

```php
public function run(
    StrategyConfig $config,
    array $candles,
    \DateTimeImmutable $start,
    \DateTimeImmutable $end,
    array $regimeMask = [],   // NEW: bool[] keyed by candle index
): SimulationResult
```

When `$regimeMask` is non-empty, buy evaluation is skipped on candles where `$regimeMask[$i] === false`. Indicator computation and open-position management still run on every candle. Existing callers pass no mask and are unaffected.

---

## Algorithm: NSGA-II / MODE

### Objectives

| Objective | Source field | Direction |
|---|---|---|
| Realized profit | `SimulationResult::$realizedProfit` | maximize |
| Trade count | `SimulationResult::$buys` | maximize |
| Drawdown score | `−abs(SimulationResult::$maxDrawdown)` | maximize |

Individual A **dominates** B if A ≥ B on all three objectives and A > B on at least one.

### `NsgaIIAlgorithm` interface

```php
// Returns 0-indexed Pareto rank for each individual (0 = non-dominated front)
rankByNonDomination(Individual[] $pop): array<int, int>

// Crowding distance within a front (higher = more isolated = preferred on ties)
crowdingDistance(Individual[] $front): array<int, float>

// Combine parents + offspring, sort by (rank ASC, crowding DESC), return top $size
selectNextGeneration(Individual[] $parents, Individual[] $offspring, int $size): Individual[]
```

### Variation

Reuse `DifferentialEvolution::generateTrials($population, $muF, $muCR, $best)` — the actual variant is `DE/current-to-best/1/bin`. `$best` is the rank-0, highest-fitness individual in the current regime's population (scalarized by `FitnessCalculator`).

Each sub-population maintains its own `μF` and `μCR`, adapted via `DifferentialEvolution::adaptParameters()` after each generation. Since NSGA-II uses population-wide sorting rather than greedy per-individual selection, success is defined as: **any trial that enters the next generation is successful**. `NsgaIIAlgorithm::selectNextGeneration()` therefore accepts `TrialResult[]` (not bare `Individual[]`) for the offspring, and returns an `NsgaSelectionResult` with the new population plus the F/CR values of surviving trials:

```php
selectNextGeneration(Individual[] $parents, TrialResult[] $trials, int $size): NsgaSelectionResult
// NsgaSelectionResult: { population: Individual[], successF: float[], successCR: float[] }
```

This keeps JADE adaptation intact without changing `adaptParameters()`.

### Winner selection

After all generations, take rank-0 members of each regime's population. Scalarize each with `FitnessCalculator::calculate($ind->result)`. The highest scorer is saved to the regime's JSON file.

---

## Per-Individual Regime Labeling

`RegimeLabeler::label(array $candles, StrategyConfig $config): array<int, string>`

Runs `RegimeClassifierAtrNormalized` over all candles using the individual's own regime genes (`regimeAtrHighPct`, `rvolHighThreshold`, `rvolLowThreshold`). Returns one regime name per candle index.

Evaluation flow per individual:

```
1. $labels = $regimeLabeler->label($allCandles, $individual->config)
2. $mask   = array_map(fn($l) => $l === $targetRegime, $labels)
3. $result = $simulationRunner->run($individual->config, $allCandles, $start, $end, regimeMask: $mask)
```

All candles are passed (not filtered), preserving indicator warmup. Buy entries are skipped on non-matching candles; existing open positions are managed normally on all candles.

**HighVolatility candle count** may be low (5–10% of history). The Pareto front naturally handles low trade counts via the trade count objective — no artificial reweighting is needed.

---

## Generation Loop — `NsgaRegimeOptimizer`

```
Load all candles once
Build base config from seed genes

For each regime in [Sideways, TrendingUp, TrendingDown, HighVolatility]:
    populations[regime] = de.initPopulation(size - 1, baseConfig) + [seedIndividual]
    muF[regime] = 0.5, muCR[regime] = 0.8

Evaluate all individuals across all 4 populations (regime-masked simulation)
Sort each population by (rank ASC, crowding DESC)

For each generation 1..N:
    For each regime:
        best = argmax FitnessCalculator::calculate over populations[regime]
        trials = de.generateTrials(populations[regime], muF[regime], muCR[regime], best)
        Evaluate all trials (regime-masked simulation)
        selectionResult = nsga.selectNextGeneration(populations[regime], trials, size)
        populations[regime] = selectionResult.population
        [muF[regime], muCR[regime]] = de.adaptParameters(muF[regime], muCR[regime], selectionResult.successF, selectionResult.successCR)

    Log generation summary (see below)

For each regime:
    front0 = individuals with rank == 0
    winner = argmax FitnessCalculator::calculate over front0
    save winner to {regime_slug}_genes.json
    print final report
```

### Log format (one block per generation)

```
Gen 12/50
  Sideways       | front=8  | fitness=14.32 | PF=3.21 | buys=62 | μF=0.48 | μCR=0.71
  TrendingUp     | front=11 | fitness=18.40 | PF=4.10 | buys=45 | μF=0.52 | μCR=0.79
  TrendingDown   | front=6  | fitness= 9.11 | PF=2.80 | buys=28 | μF=0.44 | μCR=0.83
  HighVolatility | front=4  | fitness= 6.20 | PF=2.10 | buys=14 | μF=0.51 | μCR=0.76
```

`fitness` shown is the scalarized fitness of the current best on rank-0.

---

## Console Command

```bash
php -d xdebug.mode=off bin/console app:nsga-regime-optimize \
  --genes=default_genes \
  --sim-start-date=2024-09-01 \
  --sim-end-date=2026-03-01 \
  --population=60 \
  --generations=50
```

Defaults: `population=60`, `generations=50`, `genes=default_genes`.

Output files (in `app/src/Application/Config/`):
- `sideways_genes.json`
- `trending_up_genes.json`
- `trending_down_genes.json`
- `high_volatility_genes.json`

---

## Live Trading Integration

### `RegimeAwareConfig`

Immutable value object holding four `StrategyConfig` instances keyed by regime name:

```php
final class RegimeAwareConfig
{
    public function __construct(private readonly array $configs) {}

    public function forRegime(string $regime): StrategyConfig
    {
        return $this->configs[$regime] ?? $this->configs['Sideways'];
    }

    public static function fromFiles(JsonGeneStorage $storage, GeneCatalog $catalog): self;
}
```

### `PaperTradingEngine` change

- Gains optional `?RegimeAwareConfig $regimeAwareConfig = null` in constructor.
- Each tick: classify regime using the Sideways config's regime genes (fixed reference; avoids circular dependency).
- Switch `$activeConfig = $regimeAwareConfig->forRegime($regime)` for buy signal + exit decisions.
- Falls back to existing single-config behaviour when `$regimeAwareConfig` is null.
- All four configs loaded once at startup — no disk reads at tick time.

---

## Verification

### Unit tests — `NsgaIIAlgorithmTest`

| Test | Assertion |
|---|---|
| `testNonDominationRankFront0` | A dominates B on all 3 → A gets rank 0, B gets rank 1 |
| `testCrowdingDistanceBoundaryIsInfinity` | First and last on sorted front get ∞ distance |
| `testSelectNextGenerationPrefersLowerRank` | Rank-0 individual always survives over rank-1 |
| `testSelectNextGenerationBreaksTiesByCrowding` | When ranks equal, higher crowding wins |
| `testObjectivesAreMaximized` | drawdownScore = −abs(maxDrawdown); higher maxDrawdown (less negative) preferred |

### Unit tests — `RegimeLabelerTest`

| Test | Assertion |
|---|---|
| `testLabelCountMatchesCandleCount` | Returns one label per candle |
| `testLabelValuesAreValidRegimes` | Each label is one of the four regime names |

### End-to-end

```bash
php bin/console app:nsga-regime-optimize \
  --sim-start-date=2024-09-01 --sim-end-date=2025-03-01 \
  --population=40 --generations=20
```

Pass criteria:
- Four `*_genes.json` files written
- Each generation log shows 4 regime rows
- `front` count grows then stabilises (typically 3–12 members)
- `μF`/`μCR` drift from 0.5/0.8 by generation 10
