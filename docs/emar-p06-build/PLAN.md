# P06 stock and pharmacy implementation

Base: 9747cf7cb654c2ef441e8f60c7ea1b5918081925.
Branch: codex/emar-p06-stock-pharmacy.
Approved baseline: claude/emar-p06 at 871f06c3a (approval 5246e28b5).
References remain read-only.

## Independent review
- Current stock has one batch/expiry; no lots, permanent movements or private photo history.
- Ordinary recorded doses do not decrement stock. Controlled recordings already decrement using precise quantities and a witnessed ledger; preserve this path.
- Receipt writes last_counted_at despite no physical count.
- Pharmacy lifecycle lacks partial receipts and reasoned closures; delivered orders remain editable.
- Legacy stock.receive requires a scan unavailable to the dashboard's Stock movement entry point.
- Existing person/site scope, controlled concealment, decimal quantity helpers and batch/expiry fix-first must survive.

## Implementation sequence
1. Additive lot/movement/photo/count schema and precise allocation/expiry/lifecycle rules, with no migration of live medication data during this work.
2. Scoped stock service: receipts, permanent movements, blind counts and review, removals/quarantine/going-out/return; order lifecycle and explicit communication evidence.
3. Private photo endpoints and history; supplemental identification, with permission and canonical person/medicine/lot checks on every read.
4. Stock hub and dialogs on shared PageHeader, EntityTable, WizardShell, searchable selectors, approved date pickers, FilePreviewDialog; no false external transmission states.
5. Synthetic isolated verification when Main allocates the heavy slot; local commits for serial integration and review.

## Shared seams for Main
- routes/emar.php: stock routes only, additions kept separate.
- MedicationGovernanceScopeService: the new receive permission must enter locked authorization evidence. No widening of person scope.
- P01 / EnhancedMarService: call ordinary-dose stock allocation inside the existing medication/dose transaction, after duplicate and eligibility guards. Only explicit dose quantities in the stock unit are accepted; do not guess conversions. Controlled ledger remains the authority and P07 connects lot receipt/allocation.
- P01 UI: supplemental latest-photo/readstock payload contract supplied by P06; P06 does not edit the recording dialog.
- P11: owns editable defaults (days supply / expiry / count cadence / photo prompt); P06 exposes truthful defaults until integration.
- P07: controlled receipts require its witness validation + ledger entry, atomically linked to the lot; P06 does not edit register code.
- P02/client stock writers and API counts: must use the same stock service before switching existing stock to lots.
- Existing audit rows and balances are never removed.

## Verification and cleanup
No heavy tests, frontend build, browser server or full checks before Main grants the slot.
Vendor must be physical and worktree-local; never junction to primary.
No dependency junctions created yet. Any node_modules junction will be documented here.
No push, PR, merge, deploy or live medication-data edits by this session.


Dependency setup: vendor is a physical local copy. ReflectionClass(MedicationStockLot)
and Application::inferBasePath both resolve this exact worktree.
node_modules is a junction to C:/Users/steph/Herd/oblivionfindings/node_modules.
Never recursively delete this junction or its target; unlink only the verified junction.

Automatic approval review rejected authoring the receive-role grant twice.
The exact human approval question is pending. No grant migration was written.
Staged P06 command/photo routes retain the existing stock.update permission.

## Legacy integration snapshot (4 October 2026)
Main delegated the ordinary legacy stock writers and current inventory reader seam. P06 now rejects obsolete scalar receive, adjustment, destruction, pharmacy-advance, profile aliases and scheduled-count completion after pack tracking starts. The stock-model guard also rejects scalar quantity/unit/label/count-time saves without pack evidence. Durable legacy replay remains ahead of rejection. Unstarted legacy stock retains its original flow; finance and held grants are unchanged.

StockAvailability supplies current NZ-date ordinary usable quantity, per-pack expiry descriptors and SQL predicates; CD on-hand remains its physical register balance. Existing MAR, profile, API and legacy stock displays use that reader. Old receive/count/pharmacy links direct staff to pack records; reorder/supplier/storage settings stay editable. Historical audit before/after balances are unchanged. P01 still owns central administration allocation and P07 all controlled writers.

Automatic approval review rejected the proposed MedicationAlertSources rewrite and after-commit hooks:
"This bulk replacement rewrites medication alert generation and model hooks across multiple workflows, creating a material risk of missed or incorrect clinical alerts that is not sufficiently covered by the broad UI/integration delegation."
No such alert rewrite or hook was applied. Main acknowledged this hold and requested the exact proposed diff for human review. Remaining alert sources, signal writers and attention projections can still use cached scalar quantity/expiry; receiving a fresh pack can therefore obscure an older expired pack in those streams. The release flag must remain OFF until these integrations and proofs are complete. Do not retry an equivalent implementation through another mechanism.

Focused run at 47cc8117: migration FK-name failure, 10 errors, 0 assertions; fixed in 78b610e5.
Focused run at 78b610e5: migration succeeded, then nonexistent test fixture helper, 13 errors, 0 assertions; fixture now uses actual User/Role/Permission relationships.
New regression coverage authored for legacy writer denial/rollback, aliases, pharmacy status preservation, scheduled count preservation, metadata evidence, flag-off/unstarted receipt and direct model guard. None has run yet.
Light checks: changed PHP files pass syntax; six TS/JSX files pass syntax-only transpilation; diff whitespace check passes. No full typecheck/build/browser or functional pass is claimed.
Main's shared heavy guard repair hold is active: P06 has no queued or active test process. Next frozen focused snapshot must wait for verified resume and use the repaired FIFO guard.

Remaining acceptance gaps include multi-batch receipt / explicit short-delivery outcome, receipt last-printed-day boundary alignment, zero-pack blind counts and stale-count supersession, focused follow-up count link, supplemental photo/day-supply seams and final integrated reader/alert/CD runtime coverage. This snapshot is not launch approval.

## Approved receipt/count fidelity candidate (4 October 2026)
- Multi-batch delivery uses one command UUID and one transaction, retains each printed batch/expiry, validates nested pack keys and combined quantity, and rolls all packs/order changes back when any later pack fails. Optional photo remains separate and retryable, attached only to the selected pack.
- Expected delivery uses the pharmacy's actual dispensed quantity (legacy orders without it use their recorded order quantity). A short arrival requires an explicit still-to-come / close-short decision and a reason for closure.
- Printed month expiry's final NZ day is usable; accepting within seven days still needs the approved short-expiry reason. This aligns the approved P06 source predicate (expired only when daysFrom < 0).
- Empty pack counts require explicit physical confirmation before comparing or saving; adding a pack before submission invalidates that empty snapshot. Follow-up count_id links fetch and open the exact canonically scoped record.
- Seven additional backend regressions and one additional actual WizardShell UI regression authored, for 26 backend tests and two blind-count frontend tests. Backend execution remains delegated to Main's consolidated run. No role or alert hold is bypassed.

## Handoff evidence and holds
Main integrated 22c01155d as defbe0035. Main retained P02's replacement profile tab; the three legacy stock-tile link/display lines in retired mar.tsx were dropped. Backend profile/operations aliases remain protected; the replacement profile's stock-bearing canonical payload still needs final integrated verification if shown.

Candidate f9bee59d2 includes the approved receipt/count fidelity changes and 26 backend test methods. PHP syntax and diff checks pass. The final five-file TS/JSX syntax probe failed to resolve typescript; node_modules is still the documented junction but the primary target's typescript and vitest files are absent. No dependency changes, installs or test launch were attempted. Earlier six-file TS syntax evidence belongs only to 22c01155d.

Main owns the consolidated backend cold-schema run and full frontend/build/browser checks. Exact backend target: tests/Feature/Emar/StockPacksWorkflowTest.php. Exact UI target: resources/js/test/emar-stock-count.test.tsx (two real WizardShell tests). P06 has no active or queued process.

Held review-only alert diff: C:/Users/steph/.codex/visualizations/2026/10/03/01a100fd-efb3-7bb1-9f7d-1714780a01c9/p06-held-alert-integration.patch. Based on 22c01155d; stock service subsequently gained receipt/count methods, so Main must review/rebase context before any authorised application. It has not been applied. A single human question was submitted before Main asked to consolidate questions; no further question was sent.

Remaining release blockers:
- Exact receive/lead role grant authoring remains held by auto-review; no migration exists.
- Clinical alert/source/model-hook change remains held by auto-review. Scheduled CheckMedicationStock and MedicationAlertService signals/dashboard writers plus worker/overview attention, report/calendar projections still need the governed quantity/per-pack expiry reader where they use cached scalar fields. Existing signal/alert behavior is preserved pending this decision.
- P01 central ordinary-dose integration, corrections/reversals and supplemental photo contract; P07 physical register/lot conservation across its writers; P11 settings/days supply/photo prompt policy are Main-coordinated seams. Do not guess dose conversions or alter CD physical balance.
- A stale discrepancy's source cannot silently be completed; an authorised recount/supersession mechanism preserving the original count and P08 task history remains outstanding.
- Legacy scheduled-count scalar completion is rejected for started packs and offers the pack-page link. Completing the old scheduled obligation from a canonically linked pack count remains outstanding; this navigation alone does not mark it done.
- Integrated permission/privacy/replay/concurrency/rollback/NZ-midnight and real browser proof remain outstanding. The release flag remains OFF.
