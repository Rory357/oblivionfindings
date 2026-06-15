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
- ⚠️ **Do NOT run prettier on touched files.** The codebase is hand-formatted (the gate is eslint, not prettier;
  eslint does NOT enforce indentation). `prettier --write` (with `prettier-plugin-organize-imports`) reorganises +
  drops unrelated imports and rewraps JSX across the *whole* file → huge noisy diffs that diverge from sibling
  style and risk conflicts with concurrent loops (esp. the shared `app-sidebar.tsx`). Hand-format new code to match
  the nearest sibling (copy `compliance-tabs.tsx` for any `*-tabs.tsx`). [Learned the hard way in S2.]
- ⚠️ **vitest is flaky under CPU contention** — the catering `MealLogDialog`/`_dialogs` mock-hoist test
  intermittently fails when vitest runs concurrently with `vite build`. Run vitest ALONE for a stable count;
  the true baseline is 5 fails / 156 (the 2 files below). Don't trust a vitest count taken during a build.

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
- [x] **S2 — Leave Reports → Leave & Rosters tab** ✅
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

### S2 — DONE
- The Leave hub was un-standardised (bespoke Radix `Tabs` in `index.tsx`: Dashboard·Requests·Balances·Reports,
  where Balances/Reports were stub "Open X" link-outs and there was no Holidays tab). `/leave/balances`,
  `/leave/holidays`, `/leave/reports` are separate routes/pages, all behind the same `hr.leave.viewAny` gate.
- Followed the canonical multi-page hub pattern (mirrors `ComplianceTabs`): created
  `resources/js/components/hr/leave-tabs.tsx` → `LeaveTabs` (Requests·Balances·Holidays·Reports, `router.visit`
  per-tab URL, gated `hr.leave.viewAny`, active always shown); exported it from the `@/components/hr` barrel;
  rendered `<LeaveTabs active="…" />` on all four leave pages so every hub page carries the strip.
- `index.tsx`: removed the bespoke Radix `Tabs` (golden rule #4); stacked the Dashboard analytics (Overview) +
  the Requests management under `<LeaveTabs active="requests" />` — **all content preserved**, nothing dropped.
  Removed now-unused imports (`Tabs*`, `LayoutDashboard`, `List`).
- `app-sidebar.tsx`: removed the standalone "Leave Reports" sidebar item (Reports is now a hub tab). `FileText`
  import kept (23 other uses). The "Leave & Rosters" hub entry stays.
- **Permissions frozen:** all 4 tabs share the exact same `hr.leave.viewAny` gate the routes already enforce.
- **No routes removed → no orphan grep** (the `/hr/leave/reports` route stays; it's now a tab, not a sidebar item).
- **Decision (documented):** `reports.tsx` keeps its inner Absenteeism/Bradford/Utilisation Radix tabs as
  *content sub-views* (visually distinct from the hub strip). Rule #4 targets hub-navigation strips; stacking a
  second HrTabs chip-strip has no precedent in the codebase (grep: no hub page mixes `*Tabs active=` with
  `@/components/ui/tabs`) and would blur hub-vs-content hierarchy.
- **Files touched:** NEW `components/hr/leave-tabs.tsx`; `components/hr/index.ts`, `components/app-sidebar.tsx`,
  `pages/hr/leave/{index,balances,holidays,reports}.tsx`, `HR_REORG_PROGRESS.md`.
- **Gates:** types 0 · lint 1-err (baseline, no new in touched files) · build exit 0 · vitest 5-fail/156
  (baseline, clean run) · pest 1-fail (baseline RecruitmentJobPostingSyncTest), 336 pass — run once this slice;
  final hand-formatted version has **zero PHP diff** so pest is unchanged.
- **Hand-formatted, no prettier** (see Workbench note). `index.tsx`: the un-wrapped Overview+Requests content sits
  one indent level deep under the new `<div class="space-y-4">` — left as-is so the ~700 content lines stay
  **unchanged in the diff** (easy to verify nothing in them was altered); eslint doesn't enforce indentation.
  Net `index.tsx` diff ≈ 86 lines (deleted bespoke Tabs scaffold + import tweaks), `app-sidebar.tsx` = −5 lines.
- **visual:test deferred:** Playwright screenshot snapshots are platform-specific (Windows renders ≠ Linux/CI
  snapshots — same Win↔Linux divergence as the Vite-hash note) and there's no served app in this headless
  worktree; running it yields false diffs and updating would corrupt cross-platform snapshots. Live visual parity
  is the user's browser check per the loop DoD.

## Discovered (out of scope — do not fix here)
- Leave page breadcrumbs are inconsistent (`Leave` vs `Leave Balances` vs `Leave & Rosters`); not touched.
- `reports.tsx` inner content tabs remain Radix (see S2 decision above) — revisit only if a global "content
  sub-tabs → HrTabs" convention is adopted.
- `RetiredRoutesRedirectTest.php` encodes the *previous* rebuild's "retire → redirect, never 404" philosophy,
  which is the opposite of this reorg's clean-cut rule. Only the slice-relevant cases are removed per slice.
