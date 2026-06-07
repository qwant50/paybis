# True Peak-to-Trough Equity Drawdown — Design Spec

**Date:** 2026-05-11
**Status:** Approved

---

## Context

Drawdown is currently misreported. `SimulationStats::minProfit` tracks only the running balance vs the initial balance, and `SimulationRunner::trackMinProfit` only updates when running profit is negative. As a result:

1. **Not peak-to-trough.** Equity curve $1000 → $1500 → $1100 reports `minProfit = $0` while real drawdown from peak is $400.
2. **Excludes unrealized PnL.** Open positions sitting at −$2k mark-to-market contribute zero to the metric until they settle. Strategies that hold through deep retracements before closing winners report near-zero drawdown.

`maxDrawdown` is then consumed by `FitnessCalculator::ddPenalty` as `|maxDrawdown| / 100 × 3.0` and by `NsgaIIAlgorithm::objectives()` as `-abs(maxDrawdown)`. Both have been operating on a near-zero signal. Every GA / CMA-ES / NSGA-II run in the experiment log has under-penalized risk.

This spec fixes the metric to a true mark-to-market equity drawdown sampled every stream tick, and recalibrates `ddPenalty` to a fraction-of-peak-equity scale so the penalty is account-size-agnostic.

---

## Changes

### `app/src/Domain/Simulation/SimulationStats.php`

- Remove: `public float $minProfit = 0.0;`
- Add: `public float $peakEquity = 0.0;` — highest equity observed; initialized lazily to `initialBalance` on the first sample.
- Add: `public float $maxDrawdown = 0.0;` — most negative `(equity − peakEquity)`; stays ≤ 0 to preserve the sign convention currently used by `FitnessCalculator` and `NsgaIIAlgorithm`.

### `app/src/Domain/Simulation/SimulationRunner.php`

Rename `trackMinProfit(TradingAccount, SimulationStats)` to `trackEquity(TradingAccount, SimulationStats, Candle)` and rewrite:

```
openOrders  = positionRepository.findBy(status: Open)
unrealized  = Σ over openOrders: (candle.close × order.count − account.tradeFee(candle.close, order.count))
equity      = account.balance() + unrealized

if peakEquity == 0.0:
    peakEquity = account.initialBalance()

peakEquity  = max(peakEquity, equity)
drawdown    = equity − peakEquity                    # ≤ 0
maxDrawdown = min(maxDrawdown, drawdown)             # most negative
```

Called from `processCandle()` at the same point as today's `trackMinProfit()` call. Candle is already in scope.

Sampling cadence: every stream tick (1m). No change to call frequency. Net cost: one cheap `findBy` + O(open_positions) arithmetic per stream tick. Open-position count is small (≤ `maxConcurrentPositions`, typically ≤ 5) so impact is negligible.

### `app/src/Domain/Simulation/SimulationResult.php`

Add `public readonly float $peakEquity = 0.0` so the fitness function can normalize by account size without needing access to `TradingAccount`.

Populate from `SimulationStats::peakEquity` in `SimulationRunner::buildResult()`.

### `app/src/Domain/Genetic/FitnessCalculator.php`

Change `ddPenalty` from absolute-dollar-based to fraction-of-peak-equity:

```
when maxDrawdown < 0 AND peakEquity > 0:
    ddPct      = |maxDrawdown| / peakEquity
    ddPenalty  = ddPct × 4.0
otherwise:
    ddPenalty  = 0
```

`peakEquity` is lazily initialized to `initialBalance` on the first tick (see `trackEquity` above), so post-sim it's always ≥ `initialBalance` > 0. The `peakEquity > 0` guard handles only the degenerate case of a zero-tick sim, where `maxDrawdown` is also 0 and the formula collapses to the zero branch anyway.

**Calibration check:**

| DD % | ddPenalty | exp(−ddPenalty) |
|------|-----------|-----------------|
| 5%   | 0.20      | 0.82            |
| 15%  | 0.60      | 0.55            |
| 30%  | 1.20      | 0.30            |
| 50%  | 2.00      | 0.135           |
| 75%  | 3.00      | 0.050           |

Replaces the existing magic constant `× 3.0 / 100`. Account-size-agnostic: a $10k account and a $1k account get comparable penalties for the same percent drawdown.

### `app/src/Domain/Genetic/NsgaIIAlgorithm.php`

No code change. The objective `-abs($r->maxDrawdown)` works correctly with the new field semantics. Note: the raw-dollar scale of `maxDrawdown` will grow by ~5–10× under MtM accounting, which improves Pareto domination resolution against the `realizedProfit` axis (objectives become closer in magnitude).

---

## Tests

### Unit tests on `SimulationRunner`

1. **No trades, no drawdown.** Run sim with a config that never triggers buys. Assert `result.maxDrawdown == 0.0` and `result.peakEquity == initialBalance`.

2. **Profit then retracement.** Construct a scripted candle sequence and a known-good strategy that drives equity to $1500 then closes a losing trade bringing it to $1100. Assert `result.maxDrawdown == −400.0` (current buggy code reports $0).

3. **Unrealized intra-trade drawdown.** Open one position. Drive candle.close down so unrealized PnL hits −$200, then back up to flat-close. Assert `|result.maxDrawdown| >= 200.0`.

### Property test on `FitnessCalculator`

For any `SimulationResult` where `peakEquity > 0`:
- `0 ≤ |result.maxDrawdown| ≤ result.peakEquity`
- `ddPenalty == 0` when `maxDrawdown == 0`
- `ddPenalty` is monotonically increasing in `|maxDrawdown|` when `peakEquity` is fixed

### Existing tests

Search for references to `minProfit` and `maxDrawdown` across `app/tests/`. Update fixtures and assertions. Most likely sites: `SimulationRunner` integration tests, `FitnessCalculator` unit tests, any test that constructs `SimulationResult` directly.

---

## Migration

- Add a dated entry to `EXPERIMENTS.md` marking the fitness-incompatibility boundary. All prior fitness scores compare to a different metric and should not be ranked against new runs.
- Genome JSONs (`default_genes.json`, `cmaes_genes.json`, regime files) remain valid as starting seeds — they're gene values, not fitness values.
- First GA / CMA-ES / NSGA-II run after this change will produce lower fitness scores for previously-favoured strategies. That's the correction taking effect; do not adjust the new constants to "match" the old numbers.
- `MEMORY.md` references to past best configs and the fitness function should be updated to flag the recalibration.

---

## Out of Scope

- Drawdown duration (time-underwater) — separate metric, not used by current fitness function.
- Per-trade MAE / MFE diagnostics — useful for analysis but not for selection pressure.
- Recalibrating `tradeScore`, `equityEfficiency`, or `profitScore` — those scales are unchanged.
- Re-running historical optimizations to "fix" past results — they stand as a record of past assumptions.
- Slippage / fee-model improvements — independent items from the verification audit.
- Indicator window vs `srLookback` clamp — independent finding (#4 in audit).
- Regime-optimizer walk-forward — independent finding (#3 in audit).

---

## Risks

- **Fitness magnitudes shift downward.** Strategies that "looked great" under the old metric will look worse. GA selection pressure will be redirected toward genuinely lower-DD strategies. Expected and desired; no mitigation needed.
- **First-tick edge case:** `peakEquity == 0.0` sentinel for "never sampled" collides with the legal value of zero equity. Mitigated by initializing on first sample inside `trackEquity`; verified by test #1 (no-trade sim).
- **Fee asymmetry in MtM:** the equity formula subtracts the hypothetical exit fee for open positions but the buy fee is already debited from `balance()` at entry. This is correct (equity == liquidation value) but means equity drops by `2 × fee` at the moment a position is opened. Document this in the trackEquity comment.
