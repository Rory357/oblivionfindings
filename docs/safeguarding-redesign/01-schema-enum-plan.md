# Safeguarding Redesign — Step Plan: 01 — Schema & enum

## 0. Identity
- **Step #/name:** 1 — Schema & enum (W1 explicit `reported`, W2 `no_action_required`, backfill)
- **Routes touched:** none (backend data layer only)
- **Inertia page(s) / component(s):** none this step (index `no_action_required` filter mismatch fixed in Step 3 when the list is rebuilt)
- **Controller(s) / method(s):** `SafeguardingConcernController@store` (W1), `@updateStatus` (W2 validation)
- **Models / observer / policy involved:** `SafeguardingConcern` (status scopes), migration on `safeguarding_concerns`
- **Drop refs read in full:** HANDOFF §3 (state machine) + §7.1; gap A1/A2; loop-prompt Step 1 row
- **One-line goal:** Make `reported` explicit on create and add the terminal `no_action_required` status as a real enum value, with open/closed semantics updated, verified by tests.

## 1. Section / surface map (design → existing component → backend source)
| Design block | Existing component to reuse (or NEW) | Backend field / payload source (or GAP) |
|---|---|---|
| (no UI) status lifecycle terminal branch "No further action" | n/a this step | `safeguarding_concerns.status` enum — ADD `no_action_required` |

## 2. Lifecycle gates / transitions to enforce (§5 — server-side AND UI)
| Rule | Where enforced | UI reflection | Feature test |
|---|---|---|---|
| `store()` always sets `status='reported'` | `SafeguardingConcernController@store` | n/a (default already 'reported'; explicit removes reliance on DB default) | created concern has status reported |
| `no_action_required` is an accepted status value | `@updateStatus` validation `in:...,no_action_required` | n/a this step | updateStatus to no_action_required succeeds; bogus value rejected |
| `no_action_required` is **terminal** (not "open") | `SafeguardingConcern::scopeOpen/scopeClosed/isOpen` | hero "Open work" counts (Step 3) | scopeOpen excludes no_action_required; scopeClosed includes it |
- Auto-advance / parallel-branch: **out of scope for Step 1** (Step 2/7). Transition GUARD (can't skip triage etc.) is **Step 2** — Step 1 only widens the enum + fixes terminality.

## 3. Need-to-know / redaction points (§3b)
- None on this step (no surface rendered). Policy enforcement lands with the list/detail (Steps 3–4).

## 4. Modal map (§4)
- None this step.

## 5. Backend gap list
| # | Gap | Fix | Migration? | Feature test to add |
|---|---|---|---|---|
| A1/W1 | `store()` doesn't set status explicitly | `@store`: `$validated['status']='reported'` before create | no | store → status=reported |
| A2/W2 | `no_action_required` missing from enum + `updateStatus` validation | migration `ALTER TABLE ... MODIFY status ENUM(8 values)`; add to `@updateStatus` `in:` rule | **YES** (raw MySQL enum ALTER; run local autonomously per policy) | updateStatus accepts no_action_required; rejects junk |
| A2 (semantics) | `no_action_required` not treated as terminal | `scopeOpen` exclude it; `scopeClosed` include it; (`isOpen` already `!=closed` → also exclude) | no | scope tests |
| backfill | defensive: no null/'' statuses | migration `UPDATE ... SET status='reported' WHERE status IS NULL OR status=''` (no-op normally; column is NOT NULL default) | (part of above) | n/a |

**Migration shape** (`2026_06_17_140000_add_no_action_required_to_safeguarding_status.php`):
- up(): backfill guard; `ALTER TABLE safeguarding_concerns MODIFY status ENUM('reported','triaged','investigating','action_plan','monitoring','closed','referred_external','no_action_required') NOT NULL DEFAULT 'reported'`.
- down(): `UPDATE ... SET status='closed' WHERE status='no_action_required'`; MODIFY back to the original 7-value enum. Guarded `if (driver==='mysql')` (sqlite test path uses string columns — ALTER skipped, enum is advisory there).

## 6. Incidents-consistency comparison (§7)
- No UI surface this step → nothing to compare. (Incidents stores incident status similarly; the `no_action_required`/terminal pattern mirrors Incidents' closed/no-action handling — confirm visually in Step 3.)

## 7. Cross-module touchpoints to verify (§6)
- None this step. (Observer→HsEvent/alert untouched; enum widening doesn't change observer behaviour.)

## 8. Pages / routes to retire → redirect
- None this step.

## 9. Execution checklist (ordered)
- [x] Investigate model/controller/migration + DB driver (mysql)
- [ ] Write migration (enum widen + backfill) with mysql/sqlite-safe up/down
- [ ] `@store` set `status='reported'`
- [ ] `@updateStatus` add `no_action_required` to validation
- [ ] Model scopes: open excludes / closed includes `no_action_required`
- [ ] Run migration locally (worktree, shared dev DB)
- [ ] Feature test: store status; updateStatus accepts new value + rejects junk; scope terminality
- [ ] §9 gate: pint touched PHP; `php artisan test` (safeguarding-scoped) green; types/lint/build N/A (no TS)
- [ ] Commit `feat(safeguarding): step 1 — schema & enum (no_action_required, explicit reported)` + tick PROGRESS

## 10. Notes / decisions / deferrals
- Keeping Step 1 strictly to enum + create-status + terminality. The full transition guard (W3/W6/W7) and the dedicated triage action (W4) are **Step 2** — not started here, so the existing free-form `updateStatus` still allows any listed value until Step 2 adds the guard.
- `abuse_category` is also a MySQL enum but is out of scope (already complete for the wizard's 10+ categories).
