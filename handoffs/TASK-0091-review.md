# TASK-0091 — Codex verification of the TASK-0090 fixes (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-07 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1b24f-0210-7853-9fdd-0ef87a1350e6 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0091.prompt.md`, branch `feature/intelligence-publication` @ `eedb12b` (fixes in `911393a`; clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `638a88490f1e12f993aab9ceb6244bc11215919d74ea8a748ae77b88435a59b6` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0090 findings

1. **`supersedes_id` immutability — Partly resolved**

   The `booted()` guard correctly rejects changing or clearing an already-set value and rejects setting it on an originally non-draft item (`IntelligenceItem.php:79–82`). The added test covers normal model updates.

   However, the condition checks `getOriginal('status')`, not the item’s resulting/current status. A draft can set both `status = published` and `supersedes_id` in the same `save()`, bypassing the stated “draft only” rule. Bulk query updates also bypass Eloquent model events.

   The database trigger is deferred to P1-52, so the original database-boundary concern is not fully resolved.

2. **Replacement drafting versus retraction — Resolved**

   `draftReplacement()` now performs a `sharedLock()` inside the transaction and rechecks that the source remains published (`IntelligenceItemService.php:123–129`). Retraction obtains `FOR UPDATE`, so the operations serialize consistently. The race test specifically verifies that drafting loses to an in-progress retraction.

3. **Replacement visibility lookup — Resolved**

   Retraction now resolves replacement IDs through `IntelligenceItem::seenBy(...)` (`IntelligenceWorkflowService.php:264–267`), preventing hidden records from being distinguished from missing records. The existing test coverage reportedly checks both cases.

## 2. New finding

### Medium — Draft-only supersession guard can be bypassed during a status transition

File: `modules/Intelligence/src/Models/IntelligenceItem.php:80`

The guard tests:

```php
$item->getOriginal('status') !== 'draft'
```

rather than ensuring the item is still a draft when `supersedes_id` is assigned.

Failure scenario:

```php
$item->forceFill([
    'status' => 'published',
    'supersedes_id' => $original->id,
])->save();
```

If the item was originally a draft, the guard permits both changes in one update. This creates a published replacement without the normal publication workflow, approval, evidence, or supersession checks.

Safe fix: reject the assignment unless the resulting/current status is `draft`, and preferably reject simultaneous status-plus-`supersedes_id` changes except through `draftReplacement()`. Add a regression test for setting both fields in one save. The eventual P1-52 database trigger should enforce the same invariant for direct SQL and bulk updates.

## 3. Answers to the brief

1. **Guard condition:** Partially correct. “Set once” and “cannot clear/change” are enforced for ordinary model saves. The draft-only check should use the resulting state or prohibit combined status transitions.

2. **Deadlocks:** No deadlock is apparent between the new share lock and `publish()`/`retract()`. Drafting locks only the source item; publication and retraction acquire item locks in ascending ID order. Retraction waits for the draft’s shared lock, then observes the committed status.

3. **Tests:** The tests cover the principal fixes and the normal model-update path. They do not cover:
   - setting `status = published` and `supersedes_id` together;
   - bulk query updates bypassing model events;
   - database-level enforcement, which is deferred to P1-52.

## 4. Recommendation

**Yes after fixes.**

The concurrency and visibility fixes are sound, but the supersession guard needs to close the combined-update bypass, and database enforcement remains outstanding in P1-52.

I did not run tests, migrations, static analysis, or database commands. I only read the requested files and used read-only Git/file inspection.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-07)

No credential file was opened (Codex's log). TASK-0090 #2 and #3 resolved; #1 partly resolved.

| # | Finding | Disposition |
|---|---|---|
| 1 | Medium: the draft-only guard checked the original status, so one save could set `status` and `supersedes_id` together | **Fixed.** The guard now also requires the resulting status to be `draft`; regression test in `test_the_replaced_item_is_never_changed` (a draft saved as published with `supersedes_id` in one save is refused, and nothing changes) |
| — | Bulk query updates and direct SQL bypass model events | **Unchanged: P1-52.** Recorded in its docs/11 row (TASK-0090 #1): a database trigger for `supersedes_id`. No application code writes `intelligence_items` through bulk updates. |
