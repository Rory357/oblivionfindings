# eMAR safe-stop checkpoint — 4 October 2026

Historical checkpoint: the user requested a safe stop to play games, then explicitly asked to continue on 4 October. Work has resumed from this checkpoint. Its unfinished-run statements describe the stop; use the current completion re-audit for subsequent results. No production-readiness claim, merge or deployment is implied.

## Saved state

- Integration checkout: `C:/Users/steph/.codex/worktrees/emar-completion-integration/oblivionfindings`.
- Branch: `codex/emar-completion-20261003`; last committed/pushed revision: `bbd132e19f1d921c5934848cc38b47faad2a41e6`.
- Draft PR: <https://github.com/Rory357/oblivionfindings/pull/16>.
- This pass remains saved as uncommitted tracked changes and new files. Do not reset, clean, or discard them. Primary Herd checkout was not changed.
- Main owns frontend; bounded backend work was delegated to GPT-6.1 Sol Extra high. All workers have stopped. No partial file writes were reported.
- No production clinical writes, deployment, merge, or operational legacy import occurred. Local preview uses only `oblivion_emar_preview_20261003` on port 8765.

## Current implementation

Weekly controlled counts now have explicit NZ day/time settings, shared due projections, prospective publication validation, and NOWAIT reads. Legacy outstanding effect checks now appear in person and handover consumers without GET writes; focus refresh preserves drafts and clears private cached data on access loss. P02 is the default person medication record, retaining explicit legacy fallback. Phone menu/navigation targets are 44px; My Day has a labelled keyboard-accessible care-record link. Simple signed paper refused/withheld recovery is implemented with canonical authority, locks, replay and audit checks; broader recovery remains held.

The last completed backend repair filters the legacy Medications person picker through the existing readable-person IDs. The new rollout test revealed a real unassigned-person name leak; the repair and strengthened picker assertions are saved but have not yet passed a runtime rerun.

## Verification preserved

All logs below are under ignored `storage/logs/` in this checkout. Do not add overlapping runs together.

| Run                                                            | Result                                                                                                                          |
| -------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `emar-production-final-frontend-suite.log`                     | 528 files / 3,597 tests passed. Later small copy/link changes were checked separately.                                          |
| `emar-cross-module-final-ui-tests.log`                         | 20 focused cases passed after the My Day care-link changes.                                                                     |
| `emar-post-crash-types.log`, `emar-post-crash-final-build.log` | TypeScript passed; final build passed in 3m54s. Browser loaded manifest asset `app-FX2Pj2st.js`.                                |
| `emar-weekly-final-nowait-policy-settings-alert-tests.xml`     | 120 tests / 970 assertions passed.                                                                                              |
| `emar-weekly-final-pure-projection-tests.xml`                  | Later overlapping pure suite: 22 / 172 passed, including Monday-midnight next occurrence.                                       |
| `emar-legacy-consumer-parity-tests.xml`                        | 132 / 2,741 passed.                                                                                                             |
| `emar-final-fixture-ci-tests.xml`                              | 25 / 277 passed.                                                                                                                |
| `emar-final-paper-ci-tests.xml`                                | Before later paper repairs: 99 passed, 5 failed / 1,130 assertions. Six surrounding files passed; paper contained the failures. |
| `emar-paper-recovery-pure-tests.xml`                           | 16 / 29 passed.                                                                                                                 |
| `emar-paper-readiness-finish-tests.log`                        | Interrupted at the user's stop during cold database import. Empty log, no final JUnit: NOT a passing run.                       |
| `emar-person-record-rollout-tests.xml`                         | 166 passed, 1 failed / 3,264 assertions. The legacy picker privacy failure was subsequently repaired; rerun NOT started.        |

The local browser verified the new default person record at actual 390 × 844 CSS pixels, no horizontal document overflow, contained chart scrolling, fixed wizard actions (44px high), and 44px mobile menu/link targets. The final build was visibly loaded. The new My Day care link and complete clinical recording journey still need fresh browser acceptance. Temporary viewport override was reset; preview server stopped for the user.

## Resume order

1. Read this checkpoint, `git status`, the pending diffs, and `remaining-recovery-contracts-2026-10-04.md`. Preserve all current edits.
2. Run the repaired paper/readiness suite in an isolated MySQL test database, allowing `MYSQL_TEST_SCHEMA_TIMEOUT=1200` for cold import. Prior owned test session 51463 was stopped; no remaining owned MySQL import child was found. Run no more than two database imports concurrently.
3. Verify the saved picker repair with rollout 4, scope 8, database 3, four governance reader cases and two RBAC reader cases (21 focused cases proposed). The previous full 167-case run retained all existing assertions and finished before the stop.
4. Investigate the unproved weekly concurrency concern before classifying it: outer medicine and eager stock locks may not make the nested retained-stock membership subquery a current read. Historical void/resolve can restore positive stock on a ceased medicine. Reproduce with two isolated REPEATABLE READ connections and distinct publisher/register actors; do not label it a confirmed defect without evidence.
5. Restart the exact integration preview if needed. `storage/logs/emar-preview-readiness.php` is a guarded local helper, not a production tool. Its last preflight stopped before writes because it named the nonexistent restricted fixture. The helper now checks the actual `sw-meds-no-record@demo.test` fixture, exact name/role/HR identity and absence of recording permission. Syntax passed; the corrected helper has NOT been rerun. Preserve every database, URL and five-order guard.
6. After guarded fictional fixture refresh, verify scheduled recording, PRN/witness and guided journeys and the new care-record link. Save current browser evidence. Run TypeScript and Vite sequentially if UI changes; route generation can conflict with parallel type checking.
7. Update CURRENT-STATUS and completion re-audit. Their earlier statements that weekly timing is unavailable and person/handover consumers require import are superseded by current saved work, but final runtime/CI checks remain outstanding. Do not mark those docs current without the exact results.
8. Review and commit/push only after the relevant checks pass. Run fresh complete CI for that commit. Prior pushed revision had failed backend and visual checks; local subsets do not prove release-wide success. Do not blindly rebaseline visual snapshots or weaken meaningful privacy/access assertions.

## Remaining explicit boundaries

Given paper recovery, controlled/PRN paper recovery, and queued doses after emergency grant expiry remain held. The remaining-contracts document explains required quantity/pack/disposition, historical evidence and capture provenance. Stock-lot writers and forgotten-witness fallback remain disabled by default. Neither source review nor passing focused tests certifies supported-living clinical acceptance or deployment readiness.
