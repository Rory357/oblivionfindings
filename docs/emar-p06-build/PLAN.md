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
