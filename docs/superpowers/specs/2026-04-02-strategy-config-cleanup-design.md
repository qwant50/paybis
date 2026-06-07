# StrategyConfig Cleanup Design

**Date:** 2026-04-02
**Branch:** fix_trailing_pre
**Scope:** Option B — flatten + rename account params to consistent names; deduplicate GenomeFactory

---

## Problem

`StrategyConfig` has two separate issues:

1. **Duplicated `GenomeFactory` instantiation** — identical 4-line block copied verbatim in `createDefault()` and `withOverrides()`.

2. **Account params are scattered and inconsistently named:**
   - `buyCount` (config) ≠ `baseQuantity` (TradingAccount constructor param)
   - `accountBalance` is nested under `riskManagement['accountBalance']` while `feePercent`, `maxOpenPositions`, and `buyCount` are flat top-level keys — no structural reason for this split
   - `maxRiskPercent` is also buried in `riskManagement` even though it is not passed to `TradingAccount`

---

## Design

### 1. `StrategyConfig::defaults()` — flatten and rename

Remove the `riskManagement` nested array. Promote its two keys to the top level with aligned names:

| Old key | New key | Notes |
|---|---|---|
| `buyCount` | `baseQuantity` | matches `TradingAccount::$baseQuantity` |
| `riskManagement['accountBalance']` | `accountBalance` | flat; maps to `TradingAccount::$initialBalance` |
| `riskManagement['maxRiskPercent']` | `maxRiskPercent` | flat; not passed to TradingAccount |

All other params are unchanged.

### 2. `StrategyConfig::tradingAccount()` — trivial update

```php
public function tradingAccount(): TradingAccount
{
    return new TradingAccount(
        initialBalance:   $this->params['accountBalance'],
        baseQuantity:     $this->params['baseQuantity'],
        feePercent:       $this->params['feePercent'],
        maxOpenPositions: $this->params['maxOpenPositions'],
    );
}
```

### 3. Extract `makeGenomeFactory()` private static helper

Eliminates the duplicated instantiation:

```php
private static function makeGenomeFactory(): GenomeFactory
{
    return new GenomeFactory(
        new GeneCatalog(),
        new ConstraintRepair([new MacdConstraint(), new WeightNormalizationConstraint(), new RegimeMaConstraint()])
    );
}
```

Both `createDefault()` and `withOverrides()` call this instead of inlining the block.

---

## Caller Updates

### `ShortStrategySimulationCommand.php` (2 sites)

```php
// before
'buyCount'       => $config['buyCount'],
'maxRiskPercent' => $config['riskManagement']['maxRiskPercent'],

// after
'baseQuantity'   => $config['baseQuantity'],
'maxRiskPercent' => $config['maxRiskPercent'],
```

### `StrategyConfigTest.php` (9 sites)

- `->get('buyCount')` → `->get('baseQuantity')` (×2)
- `$array['buyCount']` → `$array['baseQuantity']`
- `withParams(['buyCount' => ...])` → `withParams(['baseQuantity' => ...])` (×2)
- `'buyCount'` in `$expectedKeys` → `'baseQuantity'`
- `'riskManagement'` in `$expectedKeys` → `'accountBalance', 'maxRiskPercent'`
- `->get('riskManagement')` assertions → split into separate `->get('accountBalance')` and `->get('maxRiskPercent')`
- `testWithOverridesAcceptsNestedRiskManagement` → rename to `testWithParamsAcceptsFlatAccountSettings`, rewrite body using flat keys

### `PaperTradingEngineTest.php` (1 site)

```php
// before
'riskManagement' => ['accountBalance' => 10, 'maxRiskPercent' => 1.5],

// after
'accountBalance' => 10,
'maxRiskPercent' => 1.5,
```

### `ExperimentMemoryServiceTest.php` (1 site)

```php
'buyCount' => 1000  →  'baseQuantity' => 1000
```

---

## Out of Scope

- **`experiment_results.json`** — historical log; stale `buyCount` keys won't break anything as no code reads them back for logic. Leave as-is.
- **Pre-existing test inconsistency** — `StrategyConfig::defaults()` has `'interval' => '1m'` but `StrategyConfigTest` asserts `'1h'`. Separate issue on this branch; not touched here.
- **No structural split** of `StrategyConfig` into account-config vs strategy-config (that would be option C).

---

## Files Changed

| File | Change type |
|---|---|
| `app/src/Application/Config/StrategyConfig.php` | Core: rename, flatten, extract helper |
| `app/src/Infrastructure/Console/ShortStrategySimulationCommand.php` | Caller update |
| `app/tests/Unit/Application/DTO/StrategyConfigTest.php` | Test updates |
| `app/tests/Unit/Application/Service/PaperTradingEngineTest.php` | Test fixture update |
| `app/tests/Unit/Application/Service/ExperimentMemoryServiceTest.php` | Test fixture update |
