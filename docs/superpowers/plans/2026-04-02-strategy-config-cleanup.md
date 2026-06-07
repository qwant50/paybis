# StrategyConfig Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Flatten `riskManagement` nesting, rename `buyCount` → `baseQuantity`, and deduplicate `GenomeFactory` instantiation in `StrategyConfig`.

**Architecture:** All changes are renames/structural — no logic change. Core edit is `StrategyConfig.php`; four caller files follow. Tests are updated to match new key names.

**Tech Stack:** PHP 8.4, Codeception 5 (PHPUnit 10), Docker (`paybis-app` container).

---

## Files

| File | Change |
|---|---|
| `app/src/Application/Config/StrategyConfig.php` | Rename keys, flatten `riskManagement`, extract `makeGenomeFactory()` |
| `app/tests/Unit/Application/DTO/StrategyConfigTest.php` | Update assertions to new key names |
| `app/src/Infrastructure/Console/ShortStrategySimulationCommand.php` | Update 2 array accesses |
| `app/tests/Unit/Application/Service/PaperTradingEngineTest.php` | Update 1 `withParams` fixture |
| `app/tests/Unit/Application/Service/ExperimentMemoryServiceTest.php` | Rename 1 key in fixture array |

---

### Task 1: Update `StrategyConfig.php`

**Files:**
- Modify: `app/src/Application/Config/StrategyConfig.php`

- [ ] **Step 1: Replace `defaults()`**

Replace the existing `defaults()` method body entirely:

```php
private static function defaults(): array
{
    return [
        'symbol'                => 'DOGEUSDT',
        'interval'              => '1m',
        'baseQuantity'          => 1000.0,
        'feePercent'            => 0.075,
        'maxOpenPositions'      => 3,
        'accountBalance'        => 10_000,
        'maxRiskPercent'        => 1.5,
        'baseRR'                => 2.0,
        'tierMultipliers'       => [0.6, 1.0, 1.4],
        'tierSplits'            => [0.33, 0.33, 0.34],
        'exitMode'              => ExitMode::AtrTiers->value,
        'sizingMethod'          => SizingMethod::Linear->value,
        'minStopRR'             => null,
        'tradingIntervals'      => ['1m', '1h'],
        'trailingMode'          => TrailingMode::Percent,
        'trailingAtrMultiplier' => null,
        'breakevenAfterR'       => null,
        'minSizeFactor'         => 0.5,
        'maxSizeFactor'         => 2.0,
        'strategyId'            => 'default',
    ];
}
```

- [ ] **Step 2: Add `makeGenomeFactory()` and simplify `createDefault()`**

Replace the existing `createDefault()` and add the private helper directly below it:

```php
public static function createDefault(): self
{
    return new self(self::defaults(), self::makeGenomeFactory()->fromDefaults());
}

private static function makeGenomeFactory(): GenomeFactory
{
    return new GenomeFactory(
        new GeneCatalog(),
        new ConstraintRepair([new MacdConstraint(), new WeightNormalizationConstraint(), new RegimeMaConstraint()])
    );
}
```

- [ ] **Step 3: Simplify `withOverrides()`**

Replace the `$factory = new GenomeFactory(...)` block inside `withOverrides()` with a call to the helper. The method becomes:

```php
public function withOverrides(array $geneValues): self
{
    $knownKeys   = array_keys($this->genes->toArray());
    $unknownKeys = array_diff(array_keys($geneValues), $knownKeys);
    if (!empty($unknownKeys)) {
        throw new \InvalidArgumentException(
            'withOverrides() only accepts gene keys. Unknown keys: ' . implode(', ', $unknownKeys)
        );
    }

    $merged = array_merge($this->genes->toArray(), $geneValues);

    return new self($this->params, self::makeGenomeFactory()->fromArray($merged));
}
```

- [ ] **Step 4: Update `tradingAccount()`**

Replace the existing `tradingAccount()` method body:

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

---

### Task 2: Update `StrategyConfigTest.php`

**Files:**
- Modify: `app/tests/Unit/Application/DTO/StrategyConfigTest.php`

- [ ] **Step 1: Fix default-value assertions in `testDefaultConfigMatchesExpectedValues()`**

Line 49 — replace `buyCount` assertion:
```php
// before
$this->assertSame(1000.0, $defaults->get('buyCount'));
// after
$this->assertSame(1000.0, $defaults->get('baseQuantity'));
```

Line 59 — replace the single `riskManagement` assertion with two flat ones:
```php
// before
$this->assertSame(['accountBalance' => 10_000, 'maxRiskPercent' => 1.5], $defaults->get('riskManagement'));
// after
$this->assertSame(10_000, $defaults->get('accountBalance'));
$this->assertSame(1.5, $defaults->get('maxRiskPercent'));
```

- [ ] **Step 2: Fix `$expectedKeys` in `testToArrayReturnsAllKeys()`**

Replace the array literal in that test:
```php
$expectedKeys = [
    'symbol', 'interval',
    'baseQuantity', 'feePercent', 'maxOpenPositions',
    'accountBalance', 'maxRiskPercent',
    'minStopPercent',
    'baseRR', 'tierMultipliers', 'tierSplits', 'exitMode',
    'sizingMethod',
    'minStopRR',
    'tradingIntervals',
    'trailingMode',
    'trailingAtrMultiplier', 'breakevenAfterR',
];
```

Also update the stable-value assertion at the bottom of the same test:
```php
// before
$this->assertSame(1000.0, $array['buyCount']);
// after
$this->assertSame(1000.0, $array['baseQuantity']);
```

- [ ] **Step 3: Fix `testWithOverridesPreservesUnchangedValues()`**

Replace the `withParams` call and assertion:
```php
// before
$modified = $original->withParams(['symbol' => 'ETHUSDT', 'buyCount' => 500.0]);
...
$this->assertSame(500.0, $modified->get('buyCount'));
// after
$modified = $original->withParams(['symbol' => 'ETHUSDT', 'baseQuantity' => 500.0]);
...
$this->assertSame(500.0, $modified->get('baseQuantity'));
```

- [ ] **Step 4: Rename and rewrite `testWithOverridesAcceptsNestedRiskManagement()`**

Replace the entire test method:
```php
public function testWithParamsAcceptsFlatAccountSettings(): void
{
    $config   = StrategyConfig::createDefault();
    $modified = $config->withParams([
        'accountBalance' => 20_000,
        'maxRiskPercent' => 2.0,
    ]);

    $this->assertSame(20_000, $modified->get('accountBalance'));
    $this->assertSame(2.0, $modified->get('maxRiskPercent'));

    // Original should be unchanged
    $this->assertSame(10_000, $config->get('accountBalance'));
}
```

- [ ] **Step 5: Fix `testValidateThrowsOnInvalidBuyCount()`**

Rename the method and update the key:
```php
public function testValidateThrowsOnInvalidBaseQuantity(): void
{
    $config = StrategyConfig::createDefault();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('baseQuantity');

    $config->withParams(['baseQuantity' => 0]);
}
```

- [ ] **Step 6: Run unit tests to verify StrategyConfigTest passes**

```bash
docker exec -it paybis-app composer test-unit -- --filter StrategyConfigTest
```

Expected: all tests in `StrategyConfigTest` pass (green).

---

### Task 3: Update remaining callers

**Files:**
- Modify: `app/src/Infrastructure/Console/ShortStrategySimulationCommand.php`
- Modify: `app/tests/Unit/Application/Service/PaperTradingEngineTest.php`
- Modify: `app/tests/Unit/Application/Service/ExperimentMemoryServiceTest.php`

- [ ] **Step 1: Fix `ShortStrategySimulationCommand.php`**

Around line 111–123, in the `$experimentConfig` array:
```php
// before
'buyCount'       => $config['buyCount'],
'feePercent'     => $config['feePercent'],
'maxRiskPercent' => $config['riskManagement']['maxRiskPercent'],

// after
'baseQuantity'   => $config['baseQuantity'],
'feePercent'     => $config['feePercent'],
'maxRiskPercent' => $config['maxRiskPercent'],
```

- [ ] **Step 2: Fix `PaperTradingEngineTest.php` fixture**

Around line 496–498:
```php
// before
$lowBalanceConfig = $this->defaultConfig()->withParams([
    'riskManagement' => ['accountBalance' => 10, 'maxRiskPercent' => 1.5],
]);

// after
$lowBalanceConfig = $this->defaultConfig()->withParams([
    'accountBalance' => 10,
    'maxRiskPercent' => 1.5,
]);
```

- [ ] **Step 3: Fix `ExperimentMemoryServiceTest.php` fixture**

Around line 41 — rename the key in the `makeConfig()` helper array:
```php
// before
'buyCount' => 1000,

// after
'baseQuantity' => 1000,
```

- [ ] **Step 4: Run the full unit suite**

```bash
docker exec -it paybis-app composer test-unit
```

Expected: all tests pass (green). No failures or errors.

---

### Task 4: Commit

- [ ] **Step 1: Stage and commit all changes**

```bash
git add \
  app/src/Application/Config/StrategyConfig.php \
  app/src/Infrastructure/Console/ShortStrategySimulationCommand.php \
  app/tests/Unit/Application/DTO/StrategyConfigTest.php \
  app/tests/Unit/Application/Service/PaperTradingEngineTest.php \
  app/tests/Unit/Application/Service/ExperimentMemoryServiceTest.php

git commit -m "refactor: flatten riskManagement, rename buyCount→baseQuantity, dedup GenomeFactory"
```
