# eMAR linked-workflow re-audit — 5 October 2026

This continues the supported-living eMAR repair in draft PR #16, on the isolated
`codex/emar-completion-20261003` integration branch. It supersedes the earlier
final-asset, secondary-tab conformance and CI claims where newer evidence is
listed below. The primary checkout and production records were not changed.

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
