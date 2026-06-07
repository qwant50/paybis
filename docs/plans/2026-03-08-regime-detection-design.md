# Regime Detection — Full DDD Redesign

**Date:** 2026-03-08
**Branch:** 3-stage
**Status:** Approved, ready for implementation

---

## Problem

`MacroTrendFilter` is an Application Service with mutable state (`$trendValues`) that must be explicitly `init()`-ed before use (temporal coupling). `ShortStrategySimulationCommand` and `AdaptiveOptimizeCommand` never call `init()`, so `isDowntrend()` returns `true` for every candle (safe default), silently blocking all buy signals. Additionally, the class is a Symfony singleton, so stale state persists across GA simulation runs.

Beyond the bug, the design has deeper issues:
- Domain logic (trend classification) buried in an Application Service
- Regime concept has no ubiquitous language (no enum, no value object)
- Not evolvable by the GA — hard-coded periods and thresholds
- Affects entry gate only; sizing and stop adjustments not possible

---

## Solution Overview

Replace `MacroTrendFilter` with a proper Domain concept: a stateless `RegimeClassifier` that classifies the current market into one of four regimes, and a `RegimePolicy` that maps each regime to a set of trading effect multipliers. All classification thresholds and effect values are GA genes. The regime is computed from the existing `tradingHistoryWindow` (1h candles) — no separate macro candles needed.

---

## Section 1: Domain Objects

**Location:** `app/src/Domain/Regime/`

```
Domain/Regime/
├── MarketRegime.php       ← enum
├── RegimeEffect.php       ← value object
├── RegimeClassifier.php   ← domain service (stateless, pure PHP)
└── RegimePolicy.php       ← domain service (stateless, pure lookup)
```

### `MarketRegime` enum

```php
enum MarketRegime {
    case TRENDING_UP;
    case TRENDING_DOWN;
    case SIDEWAYS;
    case HIGH_VOLATILITY;
}
```

### `RegimeEffect` value object

Immutable. Holds the four trading modifiers for a given regime:

```php
final class RegimeEffect {
    public function __construct(
        public readonly bool  $allowEntry,
        public readonly float $confidenceMultiplier, // multiplied into BuyStrategy confidence
        public readonly float $sizeFactor,           // multiplied into position size
        public readonly float $stopMultiplier,       // multiplied into atrStopMultiplier
    ) {}
}
```

### `RegimeClassifier` domain service

**Signature:** `classify(array $candles, float $atr, array $genes): MarketRegime`

Pure PHP, no constructor dependencies, no PECL.

**Classification logic** (evaluated in priority order):

1. `atrPct = $atr / $candles[last]['close']`
   If `atrPct > genes['regimeAtrHighPct']` → `HIGH_VOLATILITY`

2. Compute `shortMA` = simple MA over last `regimeMaShortPeriod` candles
   Compute `longMA` = simple MA over last `regimeMaLongPeriod` candles
   `trendStrength = abs(shortMA - longMA) / longMA`

3. If `trendStrength >= genes['regimeTrendStrengthMin']` and `shortMA > longMA` → `TRENDING_UP`

4. If `trendStrength >= genes['regimeTrendStrengthMin']` and `shortMA < longMA` → `TRENDING_DOWN`

5. Otherwise → `SIDEWAYS`

**Safe default:** if `count($candles) < regimeMaLongPeriod`, return `SIDEWAYS` (neutral — no block, no multiplier distortion).

### `RegimePolicy` domain service

**Signature:** `effectFor(MarketRegime $regime, array $genes): RegimeEffect`

Pure lookup — reads the 14 effect genes and returns a `RegimeEffect` instance:

```php
return match ($regime) {
    MarketRegime::TRENDING_UP   => new RegimeEffect(
        allowEntry:             true,
        confidenceMultiplier:   $genes['regimeUpConfMult'],
        sizeFactor:             $genes['regimeUpSizeFactor'],
        stopMultiplier:         $genes['regimeUpStopMult'],
    ),
    MarketRegime::TRENDING_DOWN => new RegimeEffect(
        allowEntry:             (bool) $genes['regimeDowntrendBlock'] === false,
        confidenceMultiplier:   $genes['regimeDownConfMult'],
        sizeFactor:             $genes['regimeDownSizeFactor'],
        stopMultiplier:         $genes['regimeDownStopMult'],
    ),
    MarketRegime::SIDEWAYS      => new RegimeEffect(...),
    MarketRegime::HIGH_VOLATILITY => new RegimeEffect(
        allowEntry:             (bool) $genes['regimeHighVolBlock'] === false,
        ...
    ),
};
```

---

## Section 2: New Genes (18 total)

Current gene count: 49. New total: 67.

### Classification thresholds (4 genes)

| Gene | Type | Range | Default | Role |
|---|---|---|---|---|
| `regimeMaShortPeriod` | int | 5–30 | 20 | MA short period for regime |
| `regimeMaLongPeriod` | int | 20–100 | 50 | MA long period for regime |
| `regimeTrendStrengthMin` | float | 0.001–0.050 | 0.005 | Min MA separation % to call a trend |
| `regimeAtrHighPct` | float | 1.5–6.0 | 3.0 | ATR% above this → HIGH_VOLATILITY |

### Entry gate (2 genes, int 0/1)

| Gene | Type | Range | Default | Role |
|---|---|---|---|---|
| `regimeDowntrendBlock` | int | 0–1 | 0 | Block entry when TRENDING_DOWN |
| `regimeHighVolBlock` | int | 0–1 | 0 | Block entry when HIGH_VOLATILITY |

### Confidence multipliers (4 genes)

| Gene | Range | Default |
|---|---|---|
| `regimeUpConfMult` | 0.8–1.5 | 1.0 |
| `regimeSidewaysConfMult` | 0.5–1.2 | 1.0 |
| `regimeDownConfMult` | 0.2–0.9 | 1.0 |
| `regimeHighVolConfMult` | 0.3–1.1 | 1.0 |

### Size factors (4 genes)

| Gene | Range | Default |
|---|---|---|
| `regimeUpSizeFactor` | 0.8–1.5 | 1.0 |
| `regimeSidewaysSizeFactor` | 0.5–1.2 | 1.0 |
| `regimeDownSizeFactor` | 0.3–0.9 | 1.0 |
| `regimeHighVolSizeFactor` | 0.3–1.2 | 1.0 |

### Stop multipliers (4 genes)

| Gene | Range | Default |
|---|---|---|
| `regimeUpStopMult` | 0.8–1.2 | 1.0 |
| `regimeSidewaysStopMult` | 0.9–1.4 | 1.0 |
| `regimeDownStopMult` | 1.0–1.8 | 1.0 |
| `regimeHighVolStopMult` | 1.2–2.5 | 1.0 |

All defaults are neutral (multipliers = 1.0, blocks = 0) so existing GA runs are not disrupted.

---

## Section 3: SimulationRunner Integration

### Constructor changes

Remove: `MacroTrendFilter`
Add: `RegimeClassifier`, `RegimePolicy`

### Caching (per 1h window, same cadence as `$cachedBuyStrategy`)

```php
$cachedRegimeEffect = null;
$regimeWindowTime   = -1;

// inside loop, in the per-window block:
if ($latestWindowTime !== $regimeWindowTime) {
    $regime             = $this->regimeClassifier->classify($tradingHistoryWindow, $atr, $genesArr);
    $cachedRegimeEffect = $this->regimePolicy->effectFor($regime, $genesArr);
    $regimeWindowTime   = $latestWindowTime;
}
```

### Application order (replaces all MacroTrendFilter / requireUptrend / trendStrengthBonus logic)

| Step | Code |
|---|---|
| Entry gate | `if (!$cachedRegimeEffect->allowEntry) { $stats->filterRejections['regime']++; continue; }` |
| Confidence | `$confidence = $signalToBuy['confidence'] * $cachedRegimeEffect->confidenceMultiplier;` |
| Sizing | Pass `$cachedRegimeEffect->sizeFactor` as a scale multiplier into existing sizing logic |
| Stop distance | Pass `$genesArr['atrStopMultiplier'] * $cachedRegimeEffect->stopMultiplier` into `calculateStopLoss` |

### `filterRejections` key added: `'regime'`

---

## Section 4: Constraints, Testing & Cleanup

### New constraint

`RegimeMaConstraint` implements `GeneConstraint`:
Enforces `regimeMaLongPeriod > regimeMaShortPeriod` after crossover/mutation. Added to `ConstraintRepair` alongside existing `MacdConstraint` and `WeightNormalizationConstraint`.

### Unit tests

- `RegimeClassifierTest` — synthetic candle arrays; assert correct `MarketRegime` for all 4 classification branches including safe-default (insufficient candles → SIDEWAYS)
- `RegimePolicyTest` — for each `MarketRegime`, assert `RegimeEffect` fields match gene inputs
- `SimulationRunnerTest` — mock `RegimeClassifier` + `RegimePolicy`; assert gate fires, confidence/size/stop modifiers are applied

### Files deleted

- `app/src/Application/Service/MacroTrendFilter.php`

### Config keys removed from `StrategyConfig::defaults()`

- `requireUptrend`
- `trendStrengthBonus`
- `macro_smma`
- `macro_timeframe`

### `default_genes.json` additions

18 new entries with neutral defaults (all multipliers `1.0`, blocks `0`, MA short `20`, MA long `50`, strength min `0.005`, ATR high `3.0`).

---

## File Change Summary

| Action | File |
|---|---|
| Create | `Domain/Regime/MarketRegime.php` |
| Create | `Domain/Regime/RegimeEffect.php` |
| Create | `Domain/Regime/RegimeClassifier.php` |
| Create | `Domain/Regime/RegimePolicy.php` |
| Create | `Domain/Genetic/Constraint/RegimeMaConstraint.php` |
| Create | `tests/Unit/Domain/Regime/RegimeClassifierTest.php` |
| Create | `tests/Unit/Domain/Regime/RegimePolicyTest.php` |
| Modify | `Domain/Simulation/SimulationRunner.php` |
| Modify | `Domain/Genetic/Genome/GeneCatalog.php` (+18 genes) |
| Modify | `Domain/Genetic/ConstraintRepair.php` (add RegimeMaConstraint) |
| Modify | `Application/Config/StrategyConfig.php` (remove 4 config keys) |
| Modify | `Application/Config/default_genes.json` (+18 neutral entries) |
| Delete | `Application/Service/MacroTrendFilter.php` |
