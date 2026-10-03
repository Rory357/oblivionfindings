# P02 verification and integration handoff

Own checkout: `C:/Users/steph/.codex/worktrees/emar-p02-completion/oblivionfindings`, branch `codex/emar-p02-completion`. Primary and Claude checkouts remain untouched. The application stays behind `EMAR_PERSON_RECORD=p02`; the default remains legacy pending Main's combined acceptance.

Main's synthetic database suites, full type check/build and browser checks are the final verification authority. No backend functional pass is claimed by this worker. The original queued PHP run failed before testing because Bash could not resolve PHP. The replacement wrapper was cancelled during Main's queue hold, after verifying its process tree had no PHP child and its log had never reached the running marker. No active or queued worker database test remains. The test database configured in phpunit.xml is synthetic `oblivion_findings_codex_test`. No operational environment file, medication data, migration or allergy-copy command has been run.

Earlier candidate `d683b7652` passed nine focused frontend tests across the day grid, person-switch loader and record JSON loader, 25 PHP syntax checks, scoped record lint and diff checks. Its frozen fixture already has real opening/closing timestamps. Main later identified `record/reading.tsx` using boolean `MenuItem.disabled`; this candidate supplies the required explanatory string.

Explicit prerequisite cherry-picks (Main integrates each dependency once):

| Source | Local copy |
| --- | --- |
| P09 foundation `466ce69df` | `7738f05d2` |
| P08 `8d799fd36` | `0c33c8e10` |
| P08 `a5b12397f` | `f546dbed5` |
| P03 `5212bb330` | `dfb430672` |
| P03 `fe9d1a525` | `6cdf94128` |
| P08 `f525d1c93` | `76c77671e` |
| P08 `9d22f9375` | `221bbaa03` |
| P09 `d0c3d2b19` | `a1b96b7fd` |
| P09 `9bfb5f988` | `fc4df78ef` |

The new P02 follow-on commit should be integrated after those prerequisites and `d683b7652`. It adds the three medication-work hub views, the P03 canonical support reader, canonical P08 effect-check entry, dose-history deep link, merged retained/new change trails, P09 export entry with preserved as-needed choice, canonical allergy PDF wording, explicit dry-run-first allergy-copy command, and transactional correction events on both legacy and person-record routes. Correction events append after domain/follow-up/audit writes; the person route collects those events and appends after its receipt, avoiding duplicates. Notifications defer until commit.

Shared seams: narrow P02 dispatches in EmarController; MedicationAdministrationCorrectionController; P09 MedicationExportButton/ExportDialog/types, MedicationReportsController and reporting datasets; legacy EmarPdfController and its MAR template. The shared P01 recorder and global primitives have not been overwritten. The P03/P08/P09 services are prerequisites, not competing replacements.

Main should run these exact focused backend paths on its combined snapshot:

- `tests/Feature/Emar/ClientMedicationDayTest.php`
- `tests/Feature/Emar/PersonMedicationRecordTest.php`
- `tests/Feature/Emar/PersonMedicationCommandsTest.php`
- `tests/Feature/Emar/OneChartAdministrationSafetyTest.php`
- `tests/Feature/Emar/ClientMedicationReportExportTest.php`
- P09 report/export tests and P08 correction/follow-up tests covering the shared seams.

New regression cases cover ordinary/controlled/unassigned hub rows, the P03 summary shape, combined history privacy, dry-run/no-write and exact-duplicate source provenance, correction-request idempotency, and legacy request/approval/rejection rollback when the final event recorder throws. Legacy CSV tests now include the required export purpose and read the guard's buffered response.

Required integrated browser checks: record/hub at 1440 and 1280, 200% zoom, person switch while a request is pending, Back/Forward, all six sections and support subviews, stopped-order historical day/week, the hub dose-history deep link, canonical PRN effect check, report period/as-needed choice/purpose/review/download, independent correction approval, and no controlled details in concealed rows.

Outstanding release checks for Main: validate stopped/superseded historical PDF contents and controlled classification retained in order versions/held slots; confirm every legacy allergy writer is retired or delegated after canonical adoption (MedicationsApiController::createAllergy still owns a register writer in the prerequisite source); confirm the one shared recorder works with Main's P01 adapter. The dry-run command is a tool for explicit evidence copying, not an executed operational migration. No clinical timing, thresholds, dose calculation or stock correction policy has been invented.
