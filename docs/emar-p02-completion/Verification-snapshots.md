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

Post-freeze scoped verification: the repaired shared queue serialized the targeted Pint run and its syntax continuation. Pint formatted 14 candidate PHP files. All 15 candidate PHP files pass `php -l`, and `git diff --check` passes. Frontend formatting/lint stopped before execution because the primary node_modules junction target disappeared; this worker did not repair or install shared dependencies. Main now has private frontend dependencies for its combined checks. No new backend suite, full type check, build or browser server was launched here. The formatting follow-on commit has no domain behaviour changes.

## Bounded independent-review follow-on

The legacy ClientMedicalController profile writer now takes the canonical Client lock followed by the health-profile lock, reauthorizes against the locked current Client, checks the locked profile for canonical adoption, and saves within the same transaction. If the legacy save wins first, the copy reads its latest allergy labels. If the copy wins first, incompatible legacy labels are rejected. Omitted allergy input no longer clears a canonical projection; a matching legacy projection is retained rather than rewritten. Notifications use the locked Client snapshot.

Legacy INR create/disable routes now delegate to PersonMedicationClinicalController. They require the same request UUID, recorded instruction/source, explicit unlinked reason, NZ date checks, same-person/controlled privacy and error reason. The route-bound INR ID overrides body IDs. The existing alert refresh executes in the canonical transaction before the receipt and final event, so event failure rolls back the record, receipt and derived writes. The existing legacy INR dialog now supplies the new required evidence fields and a stable UUID; no dose is calculated. Existing OneChart governance fixtures supply recorded instructions and reasons.

Main's four reported UI errors are also fixed: correction approval uses a disabled-reason string, ordinary meter tone uses the supported brand/default style, and the hub date field supplies id/label with a void-returning callback. Main's date component may be named NzDateField rather than this source branch's DatePicker: keep Main's component name when resolving that small date-field hunk.

Verification for this follow-on: all six changed PHP files pass syntax checks, and diff whitespace checks pass. No backend suite, frontend dependency work, full type check, build or browser run was launched. Main owns functional acceptance on the combined snapshot.

Exact affected backend regression paths:

- `tests/Feature/Emar/PersonMedicationCommandsTest.php`: both serial allergy-save/copy outcomes, preserved projection on general-note save, legacy INR evidence/date validation, retry/event uniqueness, foreign/controlled denial, route-bound disable target, and final-event rollback for INR create/disable.
- `tests/Feature/Emar/OneChartGovernanceWorkflowTest.php`: retained INR result and reasoned disable through the legacy adapters.
- `tests/Feature/ClientMedicalControllerTest.php`: existing health-profile/medical route regression.
- `tests/Feature/Emar/ProfileAllergySafetyTest.php`: allergy evidence reaches dose-time safety.

The new source commit follows `8fadce3d8`; no prerequisite cherry-picks are included in it. The serial allergy cases do not claim an executed concurrent-database stress test; the shared lock order supplies the serialization guarantee, pending Main's independent review and combined tests.

## F6 historical prescription wording — separate reader repair

The frontend polish is committed separately as `628371aaa511fdfab665b117ad685cef09adfe3b`. This follow-up addresses the remaining F6 case identified in Main's `ui-closure-checkpoint.md` at integrated snapshot `92466adc2dd321db5c3514ffa738db4a379a334b`.

Inspected P09's integrated `MedicationReportDataset::doseRows` before changing the reader. P09 selects the canonical immutable prescription whose revision was checked by the dose's due time; it has no separate reusable historical-selector service. The person-day reader applies that same checked-at rule. Pending/sent-back versions and versions checked after the held time do not supply historical wording. Missing immutable wording is labelled "Not recorded" rather than borrowed from the current order.

Scheduled rows retain canonical order IDs and separate distinct checked versions within a day. PRN rows retain checked wording over each part of the selected NZ day, with given counts confined to that part. Current PRN/start/end fields no longer overwrite the historical prescription. Current or historical controlled classification remains concealed without controlled view. The day/week views retain distinct clinical headings; the day footer still counts distinct medicines. No prescribing, recording, lifecycle, verification or permission-grant command was changed.

Six new endpoint regressions in `tests/Feature/Emar/ClientMedicationDayTest.php` cover a later checked in-place change; two versions on one day and uncounted proposals; PRN-to-scheduled change; cessation/replacement; missing/foreign immutable evidence; and historical controlled concealment. The earlier stopped/superseded-order case now supplies synthetic checked-version evidence. Fixtures are synthetic and do not invoke prescribing commands.

Verification: scoped Pint, PHP syntax on the two changed readers and their regression file, and `git diff --check` passed. Database tests, TypeScript/build and browser checks were not run under Main's frozen heavy-test pass. The regression file and reader require P04's `MedicationOrderRevision`/`canonicalVersion` foundation, already present in Main's `92466adc2` snapshot; that foundation was not copied into this older worker checkout. Main must run the combined `ClientMedicationDayTest` and verify day/week headings and PRN counts before claiming F6 runtime closure. No shared dependencies, operational database, migration command, deployment or push were touched.

Automatic review initially rejected the backend patch because it could not establish human authorization from the delegation alone. Read Main's human instructions directly: message `01a100f5-87e5-7e71-b847-5d137b7acd90` authorizes completing the eMAR work, and `01a100fa-33b1-7dd1-8d21-21355ef79055` says to handle approvals. The same isolated reader scope was accepted after that authority check; no workaround was used.

## P02 mobile chart containment — separate UI follow-up

The historical reader repair is committed separately as `19d34d77d37c3a99ccbe54b9888217a764fc430f`. Main then supplied browser evidence that the person-day chart at CSS width 390 expanded the document to 974 pixels with the older `e520` assets.

This UI-only follow-up constrains the record panel, chart wrapper, profile MAR cards/content, day grid and week table to their parent's available width (`min-w-0`/`max-w-full`). The wide day table retains its local horizontal scroller as a labelled, focusable region. Its sticky medicine column narrows on small screens, with full medicine/dose/route text wrapping rather than being clamped there; the established desktop column widths and all dose-time headers/cells remain. Day navigation can wrap, and its previous/next controls retain 44-pixel hit areas. The week chart keeps EntityTable's existing inner scroll container and forwards Left/Right/Home/End when its labelled region itself has focus, without intercepting child buttons or changing a shared primitive.

Source/diff verification only. No tests, TypeScript/build, dependency changes or browser session were run while Main's PHP checks continued. Main owns CSS-width 390, 1280, 1440 and reflow verification, including document width, day/week local scrolling, sticky medication wording, keyboard access and reachable controls. No backend or clinical data/recording policy is included in this UI commit.
