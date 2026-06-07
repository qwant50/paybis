# Differential Evolution Optimizer — Design Spec

**Date:** 2026-04-08
**Status:** Approved

---

## Context

The existing GA optimizer (`app:ga-optimize`) exhibits slow, steady improvement throughout all generations without converging. Root causes:

1. **Fixed mutation sigma (10% of original range)** — oversized once the population converges; at generation 80, the sigma is still calibrated to the generation-1 spread.
2. **Weak selection pressure** — tournament size 5 in a 100+ population = 5% sample.
3. **Gene-independent crossover** — uniform crossover breaks co-adapted gene clusters (e.g., MACD triplet, weight hierarchies) each generation.

Differential Evolution addresses all three: step size adapts automatically via direction vectors from the live population spread, and gene correlations are implicitly exploited because mutations follow the population's shape. The JADE (self-adaptive) variant eliminates manual tuning of differential weight and crossover rate.

---

## Algorithm: DE/rand/1/bin + JADE

For each individual `x_i` per generation:

1. **Sample adaptive parameters:**
   - `F_i ~ Cauchy(μF, 0.1)` clamped to [0.1, 1.0]
   - `CR_i ~ Normal(μCR, 0.1)` clamped to [0.0, 1.0]

2. **Mutation (DE/rand/1):** pick 3 distinct random individuals `x_a, x_b, x_c` (≠ i):
   - `v_i = x_a + F_i * (x_b - x_c)`

3. **Snap:** clamp all donor genes to `[min, max]`, snap to gene step.

4. **Crossover (binomial with j_rand guarantee):**
   - `u_i[j] = v_i[j]` if `rand() < CR_i` OR `j == j_rand`
   - `u_i[j] = x_i[j]` otherwise

5. **Constraints:** applied automatically via `StrategyConfig::withOverrides()` → `GenomeFactory::fromArray()` → `ConstraintRepair` (WeightNormalizationConstraint + MacdConstraint + RegimeMaConstraint).

6. **Greedy selection:** `x_i ← u_i` if `fitness(u_i) >= fitness(x_i)`, record `F_i`/`CR_i` as successful.

**JADE parameter adaptation** (after all individuals processed):
- `μF  ← (1 − c) · μF  + c · Lehmer_mean(successF)`
- `μCR ← (1 − c) · μCR + c · mean(successCR)`
- `c = 0.1`; initial `μF = 0.5`, `μCR = 0.8`

---

## Architecture

Three new files alongside existing GA and CMA-ES — no existing code modified.

```
app/src/Domain/Genetic/DifferentialEvolution.php
    — pure DE algorithm (initPopulation, generateTrials, selectSurvivors, adaptParameters)
    — mirrors GeneticAlgorithm.php in Domain/Genetic/

app/src/Domain/Genetic/TrialResult.php
app/src/Domain/Genetic/SurvivorResult.php
    — small readonly DTOs carrying trial + F/CR for adaptation tracking

app/src/Application/GeneticOptimization/Command/OptimizeDEStrategyCommand.php
    — message bus command (implements LockableMessage)

app/src/Application/GeneticOptimization/Service/DeStrategyOptimizer.php
    — orchestrates generation loop, candle loading, logging
    — mirrors GaStrategyOptimizer.php

app/src/Application/GeneticOptimization/Handler/OptimizeDEStrategyHandler.php
    — handles command: loads genes, calls optimizer, saves result

app/src/Infrastructure/Console/DeOptimizeCommand.php
    — Symfony console command `app:de-optimize`
```

---

## DeStrategyOptimizer Generation Loop

```
ini_set('memory_limit', '4G')
Load all candles once
Initialize population (seed at index 0)
Evaluate all (simulate each)
μF = 0.5, μCR = 0.8

For each generation:
  trials = de.generateTrials(population, μF, μCR)          // generate trial vectors
  Evaluate all trial Individuals (simulate each)
  result = de.selectSurvivors(population, evaluatedTrials)  // greedy per-individual
  [μF, μCR] = de.adaptParameters(μF, μCR, result.successF, result.successCR)
  Sort population by fitness descending
  Log: Gen N/M | μF: X | μCR: X | Best fitness: X | PF: X | buys: X | survivors: X/N
```

**Result caching:** survivors carry `SimulationResult` across generations (no re-simulation).
Only winning trial vectors are new evaluations per generation.

---

## Console Command

```bash
php -d xdebug.mode=off bin/console app:de-optimize \
  --genes=default_genes \
  --sim-start-date=2024-09-01 \
  --sim-end-date=2026-03-01 \
  --population=80 \
  --generations=100
```

Defaults: population=65, generations=50, genes=de_genes.

---

## Key Trade-offs vs GA vs CMA-ES

| | GA (existing) | CMA-ES (existing) | DE (new) |
|---|---|---|---|
| Step size adaptation | None (fixed sigma) | Full covariance matrix | Population spread via F*(b-c) |
| Gene correlation | Ignored (uniform crossover) | Learned exactly | Implicitly exploited |
| Multimodal landscape | Poor (converges to one peak) | Poor (single basin) | Good (large population) |
| Integer/constrained genes | Native | Awkward (distorts covariance) | Native (snap + ConstraintRepair) |
| Population size | 50–100 | ~24 (4+3·ln(n)) | 50–100 |
| Suitable for trading | Marginal | Good for smooth fitness | Best for noisy multimodal |

---

## Verification

### Unit tests — `tests/Unit/Application/Service/DifferentialEvolutionTest.php`

| Test | Assertion |
|---|---|
| `testInitPopulationHasCorrectSize` | `count($pop) === $size` |
| `testTrialVectorsStayWithinGeneRanges` | All genes within [min, max] after 100 trial generations |
| `testTrialVectorDiffersFromParent` | At least one gene differs (j_rand guarantee) |
| `testGreedySelectionKeepsBetter` | Trial with higher fitness wins |
| `testGreedySelectionKeepsParentOnTie` | Parent kept when fitness equal |
| `testConstraintsAppliedToTrialVector` | Weights sum to 1.0 ± 0.001 |
| `testParameterAdaptationShiftsOnSuccess` | μF/μCR change when success arrays non-empty |
| `testParameterAdaptationStableWithNoSuccess` | μF/μCR unchanged when no successful trials |

### End-to-end

```bash
php bin/console app:de-optimize --sim-start-date=2024-09-01 --sim-end-date=2025-03-01 --population=50 --generations=30
```

Pass criteria: `survivors` starts 40–60%, settles to 25–45%; `μF`/`μCR` drift from 0.5/0.8.
