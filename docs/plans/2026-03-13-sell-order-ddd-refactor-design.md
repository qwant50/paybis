# SellOrder DDD Refactor Design

**Date:** 2026-03-13
**Branch:** feature/uuid-v7-sell-order

## Goal

Remove infrastructure-level business logic bypasses from `SellOrderDoctrineRepository`,
simplify `SellOrderDoctrineMapper` by leveraging the domain-owned UUID, and make
`SellOrder` a true aggregate root that owns its identity from birth.

## Problems Being Solved

1. `SellOrderDoctrineRepository::updateStopLoss()` and `::close()` mutate the Doctrine
   entity directly, bypassing domain rules entirely — business logic scattered into infra.
2. `SellOrder::assignId()` means the aggregate root does not own its identity at construction
   time — it depends on the repository to give it one.
3. `SellOrderDoctrineMapper::toDoctrineEntity()` only maps creation fields; updates require
   separate repository methods that duplicate domain state transitions.

## Design

### `SellOrder.php` (Domain)

- Add `private readonly Uuid $id` (not promoted); assigned in constructor body as `$id ?? Uuid::v7()`.
- Constructor signature: all existing business fields + `?Uuid $id = null` as last optional param.
- `getId(): Uuid` replaces `getId(): ?string`.
- Remove `assignId()`.
- Keep `reconstitute(Uuid $id, ..., string $status, ...)` — id param type changes from `string` to `Uuid`.
- `close()` and `updateStopLoss()` unchanged.

### `SellOrderDoctrine.php` (Infrastructure)

- Constructor changes from `__construct()` (auto-generate) to `__construct(Uuid $id)`.
- Domain now owns identity; the Doctrine entity receives it from the mapper.

### `SellOrderDoctrineMapper.php` (Infrastructure)

- `toDoctrineEntity(SellOrder $order, ?SellOrderDoctrine $existing = null): SellOrderDoctrine`
  — if `$existing` provided, syncs ALL fields (including status/closePrice/closeReason/closedAt)
  onto the tracked entity; otherwise creates `new SellOrderDoctrine($order->getId())`.
- `toDomainEntity(SellOrderDoctrine $doctrine): SellOrder`
  — unchanged in structure; calls `SellOrder::reconstitute(id: $doctrine->getId(), ...)`.
  `$doctrine->getId()` returns `Uuid` which passes straight into `reconstitute`.

### `SellOrderDoctrineRepository.php` (Infrastructure)

- `save(SellOrder $order): void` becomes upsert:
  `$existing = $this->find($order->getId())` → pass to mapper → persist → flush.
  Doctrine's `find()` accepts `Uuid` natively.
- Remove `updateStopLoss()` and `close()`.
- `remove()` and `findBy()` unchanged structurally.

### `InMemorySellOrderRepository.php` (Infrastructure)

- `save(SellOrder $order): void` → `$this->orders[$order->getId()->toRfc4122()] = $order;`
  (single-line upsert — no ID assignment needed).
- Remove `updateStopLoss()` and `close()`.

### `SellOrderRepositoryInterface.php` (Domain)

- No change. `updateStopLoss`/`close` were never part of the contract.
- `save()` semantics become upsert but signature is unchanged.

### `PaperTradeCommand.php` (Infrastructure)

- Replace repository bypass calls with domain method + save:
  ```php
  // stop updates
  $positionsById[$id]->updateStopLoss($newStop);
  $this->repository->save($positionsById[$id]);

  // closures
  $positionsById[$id]->close($closeInfo['price'], $closeInfo['reason']);
  $this->repository->save($positionsById[$id]);
  ```
- `$positionsById` key changes from `$o->getId()` to `$o->getId()->toRfc4122()`.

### Tests

- `SellOrderTest::testAssignId()` — remove.
- `SellOrderTest::testReconstituteRestoresAllFields()` — update `id` arg to `Uuid::fromRfc4122(...)`.
- `InMemorySellOrderRepositoryTest` — remove any tests for `updateStopLoss`/`close` on repo.

## Change Surface

| File | Change |
|---|---|
| `Domain/Position/SellOrder.php` | `Uuid $id`, auto-generate, `getId(): Uuid`, remove `assignId()`, `reconstitute` takes `Uuid` |
| `Infrastructure/Doctrine/Entity/SellOrderDoctrine.php` | Constructor accepts `Uuid $id` |
| `Infrastructure/Doctrine/Mapper/SellOrderDoctrineMapper.php` | `toDoctrineEntity` gains `?SellOrderDoctrine $existing`, syncs all fields |
| `Infrastructure/Doctrine/Repository/SellOrderDoctrineRepository.php` | `save()` → upsert, remove `updateStopLoss()`/`close()` |
| `Infrastructure/InMemory/Repository/InMemorySellOrderRepository.php` | `save()` → upsert, remove `updateStopLoss()`/`close()` |
| `Infrastructure/Console/PaperTradeCommand.php` | Domain method + save; key by `->toRfc4122()` |
| `tests/Unit/Domain/Position/SellOrderTest.php` | Remove `testAssignId`, update reconstitute test |
| `tests/Unit/Infrastructure/InMemory/Repository/InMemorySellOrderRepositoryTest.php` | Remove repo-level business method tests |
