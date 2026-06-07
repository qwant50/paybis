# Position Collection Refactor — Design

**Date:** 2026-03-14
**Approach:** Option A / Approach 2 — Bug fixes + collection cleanup. No DDD layer boundary changes.

---

## Problem Statement

`OpenPositionCollection` has four concrete bugs and one structural smell:

1. **Float equality bug** — `moveTierSiblingsToBreakeven` uses `===` on `float $buyPrice` to find tier siblings. Floats rarely match exactly; this silently fails in simulations.
2. **No closed-order guard** — `add()` accepts closed `SellOrder` objects, breaking trailing stop and breakeven logic.
3. **Array holes** — `remove()` calls `unset()` without `array_values()`, leaving gaps in the index.
4. **O(n) removal** — `array_search()` scans the full array on every remove.
5. **8-param method** — `ratchetTrailingStops` mixes mode dispatch, ATR config, percent config, and activation config in one signature. Uses `int $currentTimestamp` instead of `DateTimeImmutable`.

---

## What Is NOT Changing

- `applyBreakevenLogic` — signature and logic unchanged.
- `ExitPlan`, `ExitTarget`, `ExitPlanFactory` — untouched.
- `SimulationStats`, fitness, scoring — untouched.
- DDD layer boundaries — strategy methods stay in the collection (Approach 2, not full DDD extraction).
- `SellOrder` tier model — one `SellOrder` per tier (Option A); no `OrderTier` child entity.

---

## Design

### 1. `OpenPositionCollection` — storage, add, remove

Switch internal storage to UUID-keyed map.

```php
/** @var array<string, SellOrder> */
private array $orders = [];

public function add(SellOrder $order): void
{
    if ($order->isClosed()) {
        throw new \DomainException('Cannot add closed order to open collection.');
    }
    $this->orders[$order->getId()->toRfc4122()] = $order;
}

public function remove(SellOrder $order): void
{
    unset($this->orders[$order->getId()->toRfc4122()]);   // O(1), no holes
}

/** @return list<SellOrder> */
public function all(): array
{
    return array_values($this->orders);
}
```

### 2. `OpenPositionCollection` — trailing stop split

Replace the single 8-param `ratchetTrailingStops` with two focused methods. Mode dispatch moves to the caller (Application layer), which already knows the strategy config. `int $currentTimestamp` becomes `DateTimeImmutable $currentTime` throughout.

```php
public function ratchetTrailingStopsPercent(
    float $candleHigh,
    float $trailingPercent,
    int $activationCandles,
    \DateTimeImmutable $currentTime,
    int $intervalSeconds,
): void

public function ratchetTrailingStopsAtr(
    float $candleHigh,
    float $atr,
    float $atrMultiplier,
    int $activationCandles,
    \DateTimeImmutable $currentTime,
    int $intervalSeconds,
): void

private function isActivated(
    SellOrder $order,
    int $activationCandles,
    \DateTimeImmutable $currentTime,
    int $intervalSeconds,
): bool
```

Caller pattern in `SimulationRunner` / `PaperTradingEngine`:

```php
if ($config->get('trailingMode') === 'atr') {
    $orders->ratchetTrailingStopsAtr($high, $atr, $multiplier, $activation, $currentTime, $interval);
} else {
    $orders->ratchetTrailingStopsPercent($high, $trailingPercent, $activation, $currentTime, $interval);
}
```

### 3. `OpenPositionCollection` — tier sibling fix

Replace float-equality sibling lookup with UUID group lookup.

```php
/** @return list<SellOrder> */
public function findByGroupId(Uuid $groupId): array

public function moveTierSiblingsToBreakeven(SellOrder $exclude, Uuid $groupId): void
// uses findByGroupId internally — no float comparison, no epsilon needed
```

### 4. `SellOrder` — add `positionGroupId`

All tier siblings created from the same entry share one `positionGroupId`. Single-tier (trailing-only) orders default to their own UUID.

```php
// Constructor — new optional param:
?Uuid $positionGroupId = null,
$this->positionGroupId = $positionGroupId ?? Uuid::v7();

// reconstitute() — new required param (explicit rehydration, no defaulting):
Uuid $positionGroupId,

// New getter:
public function getPositionGroupId(): Uuid
```

### 5. `SimulationEngine::sellOrdersFromExitPlan`

Generate one shared group ID before the loop; pass to all `SellOrder` constructors in that call.

```php
$groupId = Uuid::v7();
// foreach $plan->targets:
new SellOrder(..., positionGroupId: $groupId)
```

Same change in deprecated `calculateScaledExits`.

### 6. `SimulationRunner` — three call-site changes

**a) Trailing stop** — mode dispatch added, `DateTimeImmutable` from candle `openTime`:
```php
$currentTime = (new \DateTimeImmutable())->setTimestamp((int)($candle['openTime'] / 1000));

if ($config->get('trailingMode') === 'atr') {
    $orders->ratchetTrailingStopsAtr($high, $atr, $multiplier, $activation, $currentTime, $interval);
} else {
    $orders->ratchetTrailingStopsPercent($high, $trailingPercent, $activation, $currentTime, $interval);
}
```

**b) Tier sibling call** — `positionGroupId` replaces `buyPrice`:
```php
$orders->moveTierSiblingsToBreakeven($order, $order->getPositionGroupId());
```

**c) Trade-settled tracking in `processSell`** — `positionGroupId` replaces `int $entryTime` as accumulator key. Fixes a latent collision bug where two entries at the exact same timestamp would share the same key in `tradeProfitAccum`:
```php
$groupKey = $order->getPositionGroupId()->toRfc4122();
$stats->tradeProfitAccum[$groupKey] = ($stats->tradeProfitAccum[$groupKey] ?? 0.0) + $profit;

// sibling-settled check:
foreach ($orders as $remaining) {
    if ($remaining->getPositionGroupId()->toRfc4122() === $groupKey) {
        $tradeSettled = false;
        break;
    }
}
```

### 7. `PaperTradingEngine`

Same mode-dispatch pattern and `DateTimeImmutable` current time as SimulationRunner. One call site.

### 8. Infrastructure

- `SellOrderDoctrine`: add `position_group_id uuid NOT NULL` column.
- `SellOrderDoctrineRepository`: persist and hydrate `positionGroupId`.
- `SellOrder::reconstitute()`: add `Uuid $positionGroupId` required param.
- Migration generated via `composer migration-diff`.

---

## Files Affected

| File | Change |
|---|---|
| `Domain/Position/SellOrder.php` | Add `positionGroupId` field, getter, constructor param, reconstitute param |
| `Domain/Position/OpenPositionCollection.php` | UUID storage, add guard, O(1) remove, split trailing methods, groupId sibling lookup |
| `Domain/Simulation/SimulationEngine.php` | Generate shared `groupId` in `sellOrdersFromExitPlan` + `calculateScaledExits` |
| `Domain/Simulation/SimulationRunner.php` | Mode dispatch, `DateTimeImmutable`, groupId in sibling call + tradeProfitAccum |
| `Application/Service/PaperTradingEngine.php` | Mode dispatch, `DateTimeImmutable` |
| `Infrastructure/Doctrine/Entity/SellOrderDoctrine.php` | Add `position_group_id` column |
| `Infrastructure/Doctrine/Repository/SellOrderDoctrineRepository.php` | Persist + hydrate |
| `tests/Unit/Domain/Position/OpenPositionCollectionTest.php` | Update for new signatures |
| `tests/Unit/Domain/Position/SellOrderTest.php` | Update for new constructor param |
| Migration (generated) | Add `position_group_id` column to `sell_orders` |

---

## Decisions Deferred

- Moving `applyBreakevenLogic` / trailing methods out of collection into Application services (Approach 3) — not in scope.
- `OrderTier` child entity (Option B) — not in scope.
