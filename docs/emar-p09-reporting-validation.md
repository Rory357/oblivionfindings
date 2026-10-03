# P09 reporting validation

Owned checkout: `codex/emar-p09-reporting`. This receipt is not a production readiness claim.

## Initial focused run

The frozen PHP source was `9bfb5f988` plus the pending governance/reached-filter/SAC pairing follow-up, before the PDF test-double repair. The run included:

- `tests/Feature/Emar/UnifiedMedicationReportsTest.php`
- `tests/Unit/MedicationEventFingerprintTest.php`
- `tests/Feature/Emar/MedicationEventRecorderTest.php`
- `tests/Concurrency/Emar/MedicationEventConcurrencyTest.php`

Actual result: **1 failed, 16 warning-marked, 2 passed; 522 assertions; 668.15 seconds**. All warnings were the missing checkout `.env` file reported by phpdotenv. The failure was a generic PDF test double returned from a method requiring `Barryvdh\DomPDF\PDF`, which prevented the multi-person rendering race check from reaching its assertion. The fixture now uses that concrete mock type. A local ignored `.env.testing` contains only `APP_ENV=testing`; it copies no operational environment or credentials. A rerun has not yet verified these repairs.

The initial recorder-only run was **2 passed, 8 warning-marked; 362 assertions; 305.77 seconds**, with no failed/skipped tests. Warning-marked tests are not reported as clean passes.

All clinical fixtures use the existing isolated per-process MySQL test database guard. The shared heavy-command lock had an owner-label parsing defect; Main requested no new heavy runs while the overlapping runs finish. No further command is queued by this worker.

## Integration still to verify

Combined-source reconciliation was performed after owned merge `5a35a606c`: P05 actual `happened_at`, P06 usable open/non-expired packs with unknown cost preserved, P07 real `balance_check` ledger evidence and P08b occurrence time/recorded workflow stage. Historical dose instructions use only versions checked by the due time, and equal medicine names do not merge different orders in MAR charts. The existing report-builder logger now shares the export transaction so a logger failure rolls back the export event.

Main owns the consolidated backend suite, full frontend checks and browser/PDF acceptance. No further database suite is queued by this worker. Required focused paths are the four above plus `tests/Feature/Emar/ReportDoseNumbersTest.php` and `tests/Feature/Governance/ClinicalGovernanceAutomationTest.php`. The unified report file adds actual source, governance comparison privacy, historical version and round-window checks. The ignored local `.env` and `.env.testing` contain only `APP_ENV=testing`, with no copied environment/secrets.

The reusable export gate contract is committed in `3e8c6bd06` and documented in `docs/emar-p09-export-release-contract.md`. The proposed Downtime pack hub entry was rejected by automatic approval review while the existing P10 controller remains unguarded; no connection was added. P10 owns the guard and pack event. The entry can only be completed after that guarded source contract is available.

Remaining release checks: P08b must provide its actual retained in-error marker and SAC confirmation wiring; the current combined error writer has neither. Existing generic `ModuleReportController::export` and `CombinedReportController::export` can still output medication data outside the seven guarded hub exports using generic reporting authority without a medication export purpose. These wider reporting seams need explicit final integration treatment and are not claimed safe by the P09 hub receipt.
