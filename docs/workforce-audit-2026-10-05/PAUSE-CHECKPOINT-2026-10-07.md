# Safe pause — 7 October 2026

User explicitly requested a safe pause to game. Do not resume work until asked. Full goal remains unfinished, roughly 40% remaining as a judgement, not a measured checklist score.

## Delivered

Workforce Settings committed and pushed to local and GitHub main: `2050a789fac5fd5b81aa36e40079ea7c4b487ec8`. Exact remote SHA verified after push. Evidence: 135 distinct backend cases /2232 passing assertions,105 UI cases, lint/types/build, personal settings/roster/restricted/narrow browser journeys. Shared staffing-rule positive browser role remains unavailable; no grants changed. See committed settings-integration report. No further Handovers commit/push was made.

## Saved Handovers work (integration checkout)

`C:/Users/steph/.codex/worktrees/workforce-main-integration/oblivionfindings`, branch `codex/workforce-main-integration-20261006`, HEAD2050a789.

- Backend: HandoverController index only + pure HandoverPresenter search projection + new17-case OperationalSummaryTest. Full scoped counts; half-open worker week; privacy-safe server search/identity/status filters; deterministic300 pagination with stale-page clamp; normalized query preservation. Current detail/writer/eMAR APIs preserved. Both independent reviews and syntax/Pint are clear.
- Exact20 native selection (new17 +existing effective-week1 +controlled visibility2) enumerated and frozen. NEVER launched because unrelated native checks were active, then user paused. No owned Handovers schema/process to clean.
- Resume native only after fresh competing-process inventory and final source freeze: `test-results/run-handovers-native.ps1`, config `phpunit-handovers-integration.xml`, selection `workforce-main-handovers-final-selection.txt`. Roster is sole native runner.
- Parent frontend: shared PageHeader/rail/view controls, explicit Search/Enter, draft retention/loading/error/retry, visible unavailable filter selections, server pagination, clear per-page Board/rail scope. Cards/List/Board and canonical details/wizard preserved.
- Initial UI36/6 files passed and changed-source lint passed before timezone refinement. Initial full types then exposed an eMAR shared rail required-prop incompatibility; corrected with optional callback preserving callers. Test-only `getByRole exact` type option corrected.
- Later Operations read-only timezone refinement passes server timezone through cards/list/board/day rail via optional props; shared eMAR defaults retained. New four date tests cover worker midnight/year, late-written handover, missing date and NZ DST.
- Final40 UI run was37 pass/3 wizard failures/3 errors: a broad text replacement inadvertently passed undefined `timeZone` inside the existing `shiftOptionLabel`. Safe closure restored that one helper's original clock calls. Correction is SAVED but NOT RERUN. Do not claim final40 acceptance. Inspect this diff and run focused UI/types/lint/build on resume.
- The ongoing production build and final lint/type shell were intentionally stopped. No final production build or browser acceptance for Handovers. Existing build assets may be stale; rebuild before browser verification. No detail/action mutation receipt parity claim.

## Full Workforce source backports

`C:/Users/steph/.codex/worktrees/workforce-improvements/oblivionfindings`, branch `codex/workforce-foundation-20261005`, base371014763, many retained unrelated changes. Never reset or copy whole checkout.

- Core5 saved/static-clean, source eMAR permission OR and wider prepared-workload/policy snapshot tests retained. Backup `test-results/workforce-settings-core-backport-20261007-2050a789`.
- Interface8 saved, exact byte backups/hashes `test-results/settings-frontend-before-backport-20261007/manifest.json`, focused36 UI pass.
- Graph/matrix/CI7 saved, both independent reviews clear, syntax/Pint clean, original method/assertion preservation proved. Backup `test-results/backports/settings-graph-matrix-20261007-283776/manifest.json`. Source21 native unrun; actual local.env intentionally fails strict async guard. Use an approved isolated dotenv-free exact-source test environment later; do not weaken guard or remove source.env.
- Manual retry backport PARTIAL: controller retry/private helpers/imports, new dedicated RefreshAccess and new CommandTest19 saved, PHP syntax clean. Existing RefreshTest remains unchanged because an exact anchor guard rejected before writing it; pending one positive retry root-commit fixture conversion preserving all other cases, independent review, static/native acceptance. Manifest/backups `test-results/backports/settings-manual-retry-20261007-346120/manifest.json`. No Git action.

## Processes and preview safety

Stopped only verified task-owned build/type-check descendants and our Workforce preview: IDs40384,102440,231900,273152,299432,357020,389648,409372. Final stop receipt showed no remaining owned IDs. Completed UI runners have exited. Agents stopped; no native launched and no permanent workers/schedulers.

Workforce8768 (PID389648) STOPPED. Retain integration `test-results/settings-preview-router.php`, environment/process/config/schema receipts and unique cache/session/queue settings; database `oblivion_workforce_preview_20261005` remains intact. Restart this same guarded configuration after a completed build and fresh port check.

Protected eMAR8767 PID407636 was verified alive and untouched. Other chats' Pest, Composer, H&S UI and Codex processes are unrelated and were not stopped. Full-source8766 identity must be checked before use. Do not stop unknown processes or alter eMAR checkout/database.

## Resume order

1. Confirm user resume, goal/current Git and agent state. Preserve all saved diffs and untracked audit documents.
2. Inspect/correct any remaining optional-timezone leakage, finish focused Handovers UI/types/lint/build; run exact20 backend gate only when process preflight is clear.
3. Restart only owned8768 preview and verify actual source/build identity, Handovers desktop/narrow/search/week/status/Board/back/bookmark/pagination/restricted journeys. Existing role/data limits must be reported honestly.
4. Finalize report, integrate/push reviewed Handovers when verified; then continue Shift Notes, Timesheets and full outstanding programme.
5. Finish/review bounded source retry fixture; source runtime acceptance needs its own guarded environment. Pending qualification/modified-duty/cross-site provenance decisions stay pending.
