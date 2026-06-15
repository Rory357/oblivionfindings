# HR Module Reorganisation — Progress

Self-contained loop tracker. One slice per iteration: implement → verify all gates → commit → exit.
Backlog from §C of the loop prompt. Check a slice off **only** when every gate is green and the orphan
grep is clean (scope: `resources/ app/ tests/ routes/`).

## Workbench (this worktree)
- `node_modules` → **junction** to parent `C:\Users\steph\Herd\oblivionfindings\node_modules`
  (⚠️ delete the LINK before removing this worktree or the parent's node_modules gets wiped).
- `vendor`, `.env`, `resources/js/{routes,actions,wayfinder}` (all gitignored) → **copied** from parent.
- PHP memory: Herd CLI default 128M is too low for `wayfinder:generate`/`vite build` → run with a temp
  `PHP_INI_SCAN_DIR` containing `memory_limit=1024M` in the same shell invocation.

## Gates (run all; must pass before commit)
`php artisan wayfinder:generate` (if routes changed) · `npm run types` · `npm run lint` · `npm run build`
· `npm run test` · `vendor/bin/pest tests/Feature/Hr tests/Unit/Hr`

## ⚠️ Known-red baseline (PRE-EXISTING on base `e97fa742`, NOT caused by this loop)
The base branch is already red on three gates, in modules unrelated to the HR reorg. A slice is "green" when
it adds **no new** failures beyond this baseline (and ideally clears any it legitimately touches). Re-confirmed
on a clean fresh-regenerated workbench (not a stale-TS artifact).
- **types:** clean (0).
- **lint:** 1 error + 532 warnings. Error = `resources/js/components/checklists/primitives.tsx:274:14`
  "Cannot create components during render". Warnings = `no-restricted-syntax` style nags in sites pages.
- **vitest:** 5 failures in 2 files —
  - `app-sidebar.test.ts` › "moves billing navigation into Finance" (stale after the **Finance** hub rebuild —
    test still expects /finance/billing·invoices·price-books; actual nav is Dashboard·Calendar·…). ⚠️ S10 edits
    this file's *HR* section only — do **not** fix the Finance assertion as part of HR work.
  - `my-day/index-audit-fixes.test.tsx` › 4 my-day/attendance wiring tests.
- **pest (tests/Feature/Hr):** 1 failure — `RecruitmentJobPostingSyncTest` › "hr can sync and unpublish external
  posting channels" (L67: `external_posting_status` stays `posted` after unpublish). 336 pass.

## Slices

- [x] **S1 — Webhooks → Settings only** *(half-done before loop)* ✅
- [ ] **S2 — Leave Reports → Leave & Rosters tab**
- [ ] **S3 — Benefits → Compensation & Benefits tab**
- [ ] **S4 — Expenses (admin) → Comp & Benefits tab**
- [ ] **S5 — Skills → Performance ▸ Competencies & Skills**
- [ ] **S6 — Policies → Documents & Policies hub**
- [ ] **S7 — Training hub (pull out of Compliance)**
- [ ] **S8 — Goals consolidation**
- [ ] **S9 — Calendar hub (merge three calendars)**
- [ ] **S10 — Final regroup + group split** *(last)*

## Log

### S1 — DONE
- `reports-tabs.tsx` already had Webhooks + Automations tabs removed (prior commit 184131f1) — no change needed.
- Deleted the two `Route::redirect` lines (automations + webhooks) + their comment block at `routes/hr.php` ~L546-549.
- Removed the two now-dead cases from `tests/Feature/Hr/RetiredRoutesRedirectTest.php` (clean cut → no redirects,
  so the redirect-assertion test would otherwise fail). The remaining 8 retired-route cases still pass.
- **Files touched:** `routes/hr.php`, `tests/Feature/Hr/RetiredRoutesRedirectTest.php`, `HR_REORG_PROGRESS.md`.
- **Orphan grep (scope resources/ app/ tests/ routes/):** `reports/webhooks|reports.webhooks` → 0 matches;
  `reports/automations|reports.automations` → 0 matches. Clean.
- **Gates:** wayfinder regen clean (redirects were unnamed → no helper diff) · types 0 · build exit 0 ·
  `RetiredRoutesRedirectTest` passes · lint/vitest/pest at known-red baseline (no new failures).
- Note: `docs/hr-module-rebuild-plan.md:473` still mentions `reports/webhooks` as historical narrative — that is
  **out of the orphan-check scope** and is accurate history, so left untouched.

## Discovered (out of scope — do not fix here)
- `RetiredRoutesRedirectTest.php` encodes the *previous* rebuild's "retire → redirect, never 404" philosophy,
  which is the opposite of this reorg's clean-cut rule. Only the slice-relevant cases are removed per slice.
