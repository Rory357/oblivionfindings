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

The reusable export gate contract is committed in `3e8c6bd06` and documented in `docs/emar-p09-export-release-contract.md`. Automatic approval review rejected the earlier Downtime pack connection while P10's controller was unguarded; that patch did not land. P10 subsequently supplied `2b9d241c8`, integrated by Main as `f469adc80`, with complete source locks, current account/permission/Site/person/classification checks, buffered release and its existing event last. Main authorized the bounded hub entry against that source. The owned dependency merge `ec854e771` preserves Main's P02 PRN choice/navigation and the newer P09 privacy/release fixes; Main should not cherry-pick that merge.

The Print & exports card now opens a shared wizard for exactly one house and today/tomorrow in New Zealand, shows the actual first-page preview and fixed P10 purpose, and downloads only through `/emar/downtime/pack`. It adds no event writer or permission. Finance has no pack card; audit-only exporters cannot make clinical packs. Error/offline states retain choices. Main still owns full frontend/browser and P10 PDF/backend acceptance.

The generic medication module download routes now deny readers without the exact medication export capability and redirect permitted exporters to the existing Print & exports purpose flow. Their view actions use the same destination. Combined care-quality downloads containing medication data follow that flow; generic-only/finance readers retain the other sections and exports without medication rows/counts. General combined CSVs omit the medication break-glass metric. Other module CSV exports remain unchanged. These changes add regression assertions in the unified and generic-report surface tests; Main must run them against the final integrated snapshot.

P08b final source `2f66414cc` confirms SAC calls `RecordsReportingSettings::confirmation()` inside its locked close command and stores actor/time. The P09 error query now applies that source's reporter/account axis, archived controlled classification and current person/Site policy before rows, builder and governance totals. It uses fresh person reads for release rechecks rather than the request's MAR-link memo. Only `closed` is terminal; the actual error link is `/emar/errors?error=`. Error/review links now replace the default person MAR link rather than losing to PHP array-union precedence.

The P09 release authorizer also rechecks current account approval; withdrawing approval during rendering must suppress bytes and the export event. A concrete-PDF-mock regression covers that gap, pending Main's backend run. The shared guard itself retains caller-owned authorization rather than introducing a new capability.

Remaining release checks: P08b still supplies no retained entered-in-error marker/command, and its separate `/emar/errors/export` remains outside the common final release guard. Main reports the proposed marker writer was automatically held and requires human approval; P09 retains the existing nullable/default behavior and claims no end-to-end marker workflow. The focused frontend syntax command completed successfully for 11 files with zero syntax diagnostics before the generic-report/pack UI edits; these edits still require Main's full frontend/browser checks. No new worker database run or heavy frontend command was launched.
