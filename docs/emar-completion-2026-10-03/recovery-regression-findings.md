# Combined medication recovery regressions

Requested integration base: `905363086` (`feat(emar): add witnessed controlled checks register and dose overrides`).
Runtime prerequisite base: `65bad49ef460037b9b4be3e9a3d7703ff423e4fd` (Main's descendant ref with the required MySQL foreign-key name repairs). The fast-forward completed while the suite was still queued; no tests had started. Correction approval remains unaudited at this ref.
Owned branch: `codex/emar-recovery-regressions`.
Owned test: `tests/Feature/Emar/MedicationRecoveryIntegrationRegressionTest.php`.
Application files are unchanged.

## Coverage

- Correction requests use `clients.mar.administrations.corrections.store` with an assigned, clocked-in support worker holding `medications.administer.correct`. Requests carry the original person/administration route bindings, `status`, `reason` and `correction_reason`. Approval uses `emar.corrections.approve` with an independent, current same-house team lead holding that permission.
- A given PRN source owns an effect check and an outstanding partial-dose review. Approval must retire both old sources once, preserve original clinical evidence and append-only history, create only the accepted correction's sources and expose only those sources in Tasks. A separate not-given approval must retire its old effect check without inventing new work.
- The audit-failure test replaces both public recorder entry points with a failure. The actual approval route must reach the recorder and roll back correction status, source work, follow-up history, medication events and event heads. Restoring the recorder and retrying must create one accepted replacement and one retirement per source.
- Error and investigation-action Tasks independently deny (1) an own report for an unreadable person in the same house and (2) another reporter's record for a readable person. Each case asserts the opposite axis is satisfied. An action assigned to the denied reader still cannot bypass its parent's privacy. Lists and direct provider ID lookups preserve permitted manager access; forbidden register detail returns 404.
- Forgotten-PIN cases prove ordinary fallback can nominate and confirm a smaller dose, then deny fallback for controlled witnessing and explicit order witnessing. Explicit requirements are tested while both organisation and house witness settings are off. Denials preserve stock, register entries, nominations, follow-up history and medication events; valid witness PINs must still record the same dose successfully.

## Known application gap on the frozen base

`app/Http/Controllers/MedicationAdministrationCorrectionController.php::approve()` updates correction evidence and synchronizes follow-ups, then returns without appending a medication event. `MedicationGovernanceScopeService::forClient()` supplies the transaction but no final recorder call. The rollback regression deliberately fails if that route succeeds without reaching the recorder. Main confirmed this gap and assigned correction lifecycle auditing to P02; this branch does not repair it.

## Validation

- Dependencies were physically copied into the managed worktree. Reflection resolved `EnhancedMarService` from this worktree, not the primary checkout.
- PHP syntax validation passed. The owned file was formatted with Pint.
- One focused suite was queued through `C:/Users/steph/.claude/heavy-lock.sh`, using absolute PHP `C:/Users/steph/.config/herd/bin/php84/php.exe` and the repository's per-process isolated test database.
- Runtime result: pending. Log: `storage/logs/emar-recovery-regressions.log`.
