# eMAR linked-workflow re-audit — 5 October 2026

This continues the supported-living eMAR repair in draft PR #16, on the isolated
`codex/emar-completion-20261003` integration branch. It supersedes the earlier
final-asset, secondary-tab conformance and CI claims where newer evidence is
listed below. The primary checkout and production records were not changed.

## Follow-up after candidate `871482aa`

The following evidence supersedes the earlier frontend totals and asset below.
This remains a single-organisation application: current roles, permissions,
approved Sites, canonical person/record ownership and privacy determine access.

- Medication-error reporting now uses the existing medication wizard, including
  its save lock, field-error focus, dirty-close confirmation and frontline touch
  targets. The confirmation also opts into the existing frontline styling.
  Keep editing retains the entered report; explicit discard closes it without
  submitting. Reopening an explicitly discarded report starts with fresh form
  state and context, including on oversight pages that keep the wrapper mounted.
  Existing error records now protect an unsaved account or command
  when Back, Close or Escape would leave it. Discarding those details does not
  delete the saved error record. Read-only staff eligibility uses the same
  frontline sizing.
- The general report hub remains available to its authorised nonclinical users.
  Medication totals, module badges and combined-report previews are omitted
  unless medication report authority exists, including the existing finance-only
  restriction. Authorised medication queries retain canonical Site/person and
  controlled-drug boundaries. The UI no longer invents a zero medication total
  when that metric was withheld.
- The roster week grid assigns shifts to their New Zealand civil day and shows
  NZ times even when the browser runs in UTC. Both summer and winter Monday
  cases are covered. Shift reports again offer a custom date range using the
  existing calendar; applying a range retains Site and staff filters. The
  Site Compliance subline no longer renders a literal escape sequence.
- Personal-safety tracking cards retain their existing purpose-specific privacy
  decision. Links to the canonical client location and history also require the
  existing personal-tracker consent for that person. The synthetic browser
  fixture uses the published Personal Tracker type and preserves its purpose,
  legal basis and version instead of rewriting a generic asset-tracking type.
- Browser checks follow the current end-shift, person-specific handover, HR
  calendar, IT setup and record-history controls. Medication offline assertions
  distinguish the queue message from another status region and require pending
  messages to disappear before reloading and verifying the saved dose count.
  Monitor actions use an exactly named article; device labels and password
  confirmation are scoped to the actual current form.
- Discovery acceptance now has its actual Redis worker in the isolated CI job.
  Its sole synthetic loopback target is denied by the existing egress guard;
  the journey checks the canonical completed run, denied result and zero
  discovered candidates. It does not force a run status or allow network probes.
  CI architecture checks retain both dedicated Governance sizes and all generic
  projects, with only the reviewed source fingerprint updated.
- Consent readiness setup creates purpose-bound, verified fictional authority
  and capacity evidence. Capacity evidence alone cannot satisfy a consent gate.
  Repeated setup removes only the exact guarded fictional person's requests,
  including archived requests, in restrictive-dependency order. This corrects a
  soft-delete/FK failure without changing production consent deletion rules.
- Incident/handover acceptance setup recognises the exact fictional person
  using plaintext name markers, then checks decrypted email/NHI and exact Site
  identity. SQL comparisons against encrypted email had missed the existing
  generation. Once append-only lifecycle evidence exists, setup creates a new
  fictional Site/person and retains the complete old clinical graph. The
  current fixture's staff remain scoped to its current Site; mismatched
  identities or soft-deleted people stop before any write.

| Latest completed follow-up check | Evidence |
| --- | --- |
| Full frontend suite | **536 files / 3,624 tests passed**, exit 0, 403.02s; `emar-report-reopen-full-frontend-20261005.log`. This includes the added report-reopen regression and ran sequentially after the final build with four workers. An earlier overlapping build temporarily replaced generated routes and caused two import failures; that attempt is superseded. |
| TypeScript and changed frontend lint | Passed; final report reset checks are `emar-report-reopen-types-20261005.log`, `emar-report-reopen-lint-20261005.log` and `emar-report-reopen-format-20261005.log`. Earlier changed-file lint is `emar-browser-followup-lint-20261005.log`. |
| Production build | Passed in 3m54s; `emar-report-reopen-build-20261005.log`. The local browser confirmed **`app-Czgn9PcE.js`** from this worktree. |
| Medication error phone check | Rechecked on the final build at a 320px CSS viewport: document and scroll widths were both 320px, and the settled confirmation was 294.4px wide (12.8–307.2px). Keep editing, Discard changes and Close measured 44px high; Close was also 44px wide. The heading did not overlap Close. Draft retention and explicit discard passed with no submitted report and no console errors. |
| Existing error account recovery | Three regression tests cover Back/Keep/Discard, closing the record, and saved-command locking. The current synthetic browser register has no error records, so no fresh existing-record browser journey is claimed. |
| Discarded report reopening | The final browser build passed `/emar/errors` → Report an error → enter fictional draft → Cancel → Keep editing (text retained) → Cancel → Discard → reopen (empty account). Closing that untouched report required no discard prompt. No report was submitted, and there were no console errors. |
| Shift report custom period | Browser selection of 2–30 October applied `date_from=2026-10-02&date_to=2026-10-30`. Apply remained disabled after selecting only the first date. No export or data write was performed. |
| General report privacy and access | **40 distinct cases / 1,639 assertions passed**: Governance 24/616, corrected GenericReporting 4/580, CanonicalScope 7/307, ControlledReportAuthorization 5/136. Final logs are `emar-report-hub-final-20261005.*` and `emar-report-hub-generic-final-20261005.*`; failed attempts are not counted. Both processes exited, and disposable schemas/processes were independently confirmed absent. |
| CI architecture contracts | **47 cases / 997 assertions passed**, `emar-foundation-architecture-final-20261005.*`. |
| Attendance NZ week fixture | Complete file **11 / 51 passed**; `emar-readiness-attendance-week-fixture-tests-20261005.*`, exit 0 and independent schema/process cleanup confirmed. |
| Compliance canonical Site fixture | Complete file **4 / 87 passed**; `emar-readiness-compliance-date-fixture-tests-20261005.*`, exit 0 and independent schema/process cleanup confirmed. |
| NZ calendar/privacy fixtures | Complete files **27 / 220 passed**: Directory privacy 11/87, current staff eligibility 3/15, person handover 13/118; `emar-readiness-calendar-fixture-tests-20261005.*`. The future profiles now use NZ civil dates, and the current-week handover fixture has an explicit NZ Monday clock. All original denials remain. Exit 0 and independent schema/process cleanup confirmed. |
| Checklist/person fixture and handover | Complete files **5 / 242 passed**: new checklist setup 3/199, existing frontline handover 2/43, in `emar-incident-handover-generation-tests.*`. Two separate new incident cases failed in that batch and are not counted as passing. Process/schema cleanup completed before their retry. The fixture links only the two named fictional workers to their exact person; unrelated person access stays denied. |
| IT setup and incident-draft recovery | Complete files **16 / 236 passed**: IT setup 9/156 and incident draft recovery 7/80, in `emar-consent-it-incident-final-20261005.*`. The new consent suite in that batch failed and is not included. Exact foreign/missing 404 responses and the canonical H&S prerequisite remain asserted. Process/schema cleanup confirmed. |
| Tracking consent and destinations | Complete files **20 / 451 passed**: tracking consent setup 10/122 and workspace 10/329. Generic purpose consent does not unlock client-location destinations; canonical positive and withdrawn cases remain covered. Separate new incident cases in the same batch failed and are not counted. Process/schema cleanup confirmed. |
| Consent readiness and repeat setup | Complete file **10 / 163 passed**, exit 0, 373.48s; `emar-consent-readiness-reset-final-20261005.*`. Actual staff-to-portal approval, exact saved authority/result binding, active and archived request cleanup, five unchanged foreign records, identity collision and six authority denials passed. Final hashes were unchanged, and the disposable schema/process tree was independently confirmed absent. Earlier nine-case failed attempts are superseded. |
| Protected incident-handover replay | Complete file **6 / 358 passed**, exit 0, 298.18s; `emar-incident-handover-verified-tests.*`. Real close and close/reopen journeys reach retained lifecycle signals/outbox, evidence and audit rows, then generate a fresh scoped fixture. Repeated setup preserves exact old and unrelated database rows; current alert access succeeds and old alert access remains denied. Frozen hashes matched, and the exact disposable schema/process tree was independently confirmed absent. Earlier failed diagnostic/setup attempts are superseded. |

Current browser proof is stored in the task's `emar-resume-20261004` directory:
`medication-error-modal-final-verified-20261005.jpg`,
`medication-error-discard-guard-verified-20261005.jpg` and
`shift-report-custom-period-verified-20261005.jpg`. The latest report reset is
shown in `medication-error-reopen-cleared-verified-20261005.jpg`; the final phone
capture is `medication-error-phone-final-build-20261005.jpg`. Older uncorrected phone
captures are not final evidence. The viewport override was reset and the tab
returned to Meds today.

Candidate `871482aa5408e22962a16d077b55e9c714f12c18` is **not green**.
Quality, bootstrap and both Governance browser jobs passed. The quality job's
formatter modifies its checkout; its success does not prove that the submitted
tree was format-clean. Scoped local formatting checks are listed separately.
Generic desktop reported 77 passed,
22 failed, 2 skipped and 19 not run; IT/security 1280 reported 18 passed and
8 failed. All eight backend shards and the foundation job stopped on failures;
later batches were not executed. Twelve legacy screenshot comparisons also
remain failing. The corrected files need new final-head CI, and the clinical
recovery/deployment gates at the end of this ledger remain open.

The bounded follow-up checks above are complete; fresh full CI remains required.
Production denials were not weakened to make browser tests pass.
Independent read-only review found no actionable
issues in the four frozen Tracking files; its boundary was purpose-bound links,
canonical type/version preservation and existing access denials, not a new
browser or concurrency run. Follow-up review also checked the consent reset's
actual soft-delete and incoming foreign-key contracts; the final ten-case run
above supplies its runtime proof. The corrected incident generation lookup
retains all exact decrypted identity guards and was independently reviewed.

A final read-only release-gate check confirmed that ordinary scheduled paper
refused/withheld recovery is implemented for the actual confirmed giver under
exact evidence and current authority checks. Historical given, controlled, PRN
and required-second-person paper posting remains held. Offline timestamps do
not revive expired emergency authority; ordinary queued doses may still use
other valid current authority. The older blanket paper-posting hold in historical
sections is superseded by these bounded contracts.

## Corrected application behavior

- Guided medication rounds wait for the saved chart to refresh before enabling
  the next dose, individual row actions or completion. A failed refresh keeps
  recording blocked with a recovery action and a warning not to give the dose
  again. An offline queued dose stays pending until that exact medicine and
  scheduled slot appears on the chart; another slot cannot acknowledge it.
- The actual Settings secondary tab measured 36px with an 18px circular chip,
  despite the earlier conformance claim. The shared component now follows
  Rory's documented 40px tab, 22px chip with 6px corners, 14px icon and 2px
  underline inset 14px. The bare strip and automatic five-tone cycle remain.
  Matching foreground shades make active text and compounded count tints
  readable on the grey page; the navigation guide records this accessibility
  correction. Device freshness uses the existing semantic foreground/background
  pairs rather than an unpaired green text override.
- Consent evidence entered without an offset uses New Zealand wall time. The
  form explains NZ time, blocks nonexistent spring-forward times, and requires
  choosing the first or second occurrence of a repeated autumn hour. The
  backend uses the existing strict parser extracted from controlled medicines;
  absolute offset/Z timestamps and date objects keep their meaning. Existing
  future, expiry and decision-authority restrictions remain.
- Roster suggestions wait for a saved acceptance before applying it, and guard
  duplicate submissions. The roster page has one main landmark. Control Room
  dialog close controls expose their shared slot marker and measure 44px by
  44px at a 320px CSS viewport, without horizontal dialog overflow. Checklist
  text encoding was repaired.
- Timesheet list eligibility now matches direct access for the linked shift
  and current worker; shift eligibility uses the NZ day. H&S corrective-action
  verification availability excludes the action creator and event reporter,
  matching the existing server rule. Both reader surfaces load the required
  provenance without exposing additional fields. Calendar weekly totals use
  full timestamps so late Sunday work is not silently excluded.

## Acceptance and fixture repairs

Browser journeys now use current field labels, wizard review steps, exact
medicine identities, canonical routes and explicit saved outcomes. The round
offline checks distinguish an unprepared dose, which remains blocked, from a
prepared dose whose save loses connectivity. The incident/H&S relay uses an
independent verifier and checks each saved closure instead of treating any
redirect as success. The shared-boundary test follows the current wizard and
retained boundary identity; creating an area does not activate tracking.

Dedicated synthetic payroll, briefing and tracking-consent fixtures now meet
current canonical prerequisites and preserve unrelated or protected records.
Tracking replay must create no extra audit entries or restart stopped
assignments. The incident-handover fixture clears only its own synthetic
medication-error dependencies before reseeding; restrictive clinical foreign
keys remain. Approved Site, current HR, signed WorkSafe decision and canonical
incident/payroll prerequisites replace stale fixture assumptions. Exact denial,
privacy, replay and no-effect assertions remain in the repaired tests.

Governance browser journeys are assigned their dedicated configuration and two
desktop sizes in CI. They are no longer run with the incompatible generic
desktop configuration; coverage is retained in separate jobs. No visual
baselines were blindly replaced.

## Verification ledger

Results below are bounded whole-file or focused checks. Overlapping retries and
earlier checkpoints must not be added together as a new full-suite total.

| Check | Latest result |
| --- | --- |
| Timesheet, canonical Site access, attendance and linked readiness batch | 66 tests / 721 assertions passed; `emar-attendance-approval-parity-tests.*`. |
| Consent evidence integrity | Whole file 79 / 471 passed; `emar-readiness-consent-nz-time-tests-20261004.*`. |
| Existing controlled local-time parser | 9 / 59 passed after the shared-parser extraction; `emar-readiness-shared-nz-time-unit-tests-20261004.*`. |
| Payroll synthetic fixture and canonical access | 7 / 115 passed; `emar-readiness-hrlive-payroll-fixture-tests-20261004.*`. |
| Pre-shift briefing fixture and payload | 6 / 297 passed; `emar-readiness-myday-briefing-fixture-tests-20261004.*`. |
| H&S investigation assurance and new-bill acceptance | 11 / 132 passed across their latest complete files. |
| IT workspace, shift cancellation and safeguarding | 51 / 367 passed across latest complete files; see `emar-ci-first-five-fixture-handoff-20261005.txt`. |
| Injury ownership, IncidentController, payroll time lock, incident-handover fixture | Respectively 4 / 57, 176 / 971, 2 / 13 and 4 / 94 passed in the grouped follow-up run. |
| Monitoring ingestion and schema integrity | 15 / 128 passed; `emar-ci-shard7-fixtures-tests.*`. |
| MedicationController | Whole file 167 / 792 passed; `emar-ci-medication-controller-final-tests.*`, including both picker implementations and controlled-dose replay. |
| Calendar, tracking fixture and H&S presentation | Complete files 6 / 66, 9 / 67 and 7 / 243 passed; grouped 22 / 376 in `emar-readiness-calendar-tracking-presentation-final-20261005.*`. |
| Existing tracking workspace | 9 / 278 passed. |
| Guided-round sequencing and offline guard source contract | 18 focused frontend tests passed. |
| Shared tab keyboard/navigation behavior | 19 focused frontend tests passed. |
| Full frontend suite | **531 files / 3,612 tests passed** in the final full rerun, 203.49s; `emar-linked-final-frontend-tests-20261005.log`. The initial run's whitespace-sensitive assertion was corrected without changing its offline guard condition. |
| Full TypeScript / changed-file lint | Passed; `emar-linked-final-types.log`, `emar-linked-final-lint.log`. |
| Production build and browser | Final build passed in 3m55s, `emar-final-rory-tabs-build-20261005.log`; browser confirmed `app-whZi_5U1.js`. Settings tabs measured 40px / 22px / 6px corners / 14px icon / 2px underline with 14px insets. At a 320px CSS viewport, document scroll width equals client width (308px). |

The final whole-file reruns exited zero. Their isolated schemas and owning
processes were independently confirmed absent. Imports were capped at two;
earlier failed attempts are superseded only by their complete final-file checks.

Local browser evidence is saved under the task's `emar-resume-20261004` proof
directory: `settings-rory-tabs-final-20261005.jpg`,
`settings-rory-tabs-phone-20261005.jpg`, `control-room-rule-phone-44px.jpg` and
`consent-nz-repeated-hour-options.jpg`. The latter two predate the final tab-only
build. The latest round race has regression coverage; the earlier completed
fictional round was not represented as a fresh recording after the NZ day changed.

## Release status

At pushed head `91e64169d37a8e7891939f898a775b39aa9f48c4`, quality, bootstrap and
foundation CI passed; all eight feature shards stopped on failures. Desktop
browser acceptance reported 84 passed / 33 failed / 2 skipped / 2 not run.
IT/security reported further failures, including navigation/command workflows
and contrast. Revised journeys and fixes require a new complete remote run.
Remaining screenshot baselines require deterministic, reviewed evidence.

Further artifact triage confirmed that the security sidebar's continuous list is
intentional under `APP_SHELL_STYLE_GUIDE.md` section 3. Its browser check now
requires every ordered link and the exact current-page marker instead of obsolete
group captions. Remaining IT setup, device form and monitor selectors require
review. The command journey stopped at password confirmation; discovery Run now
returned a governed-scope error, which is being investigated rather than bypassed.

The clinical recovery boundaries in
[remaining-recovery-contracts-2026-10-04.md](remaining-recovery-contracts-2026-10-04.md)
remain: no inferred given-dose quantities/lots/witnesses, no automatic expired
emergency authority, no unrestricted stock writers or witness fallback, and
required production migrations and clinical acceptance before release. This is
an implementation candidate, not a production-readiness certification. No main
merge or deployment has occurred.

Automatic approval review rejected copying raw Artisan output into browser
reports because of possible sensitive-data disclosure. That proposed logging
change was omitted; bounded synthetic regressions established the fixture
failure instead.
