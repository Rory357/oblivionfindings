# Ticket workflow implementation

User authorised implementation of the approved ticket-detail mockup on 13 September 2026, including related gap fixes. This supersedes the mockup-only scope for this task. Work stays in the existing single-tenant application: record work permissions, approved sites, current staff eligibility and privacy govern every read and write.

## Delivery checklist

- [x] Add durable technician time, bookings, costs, contact/diagnostic context and correction history.
- [x] Extend the existing comment command and drafts for atomic note/time/status/follow-up saves and exact retry recovery.
- [x] Validate actual periods, breaks, overlaps, overnight dates and explicit after-hours flags; provide a recoverable timer and reviewed split.
- [x] Search eligible people and technicians, expose work contact information and explicit notification recipients.
- [x] Add multiple-technician booking lifecycle with conflict checks, actual work on completion and calendar visibility.
- [x] Add reasoned corrections, real approval capabilities and settlement gates. Do not install the mockup's invented rates as organisation policy.
- [x] Integrate the approved hierarchy and P1–P4 presentation with existing ticket/SLA values.
- [x] Move public/internal note and time entry into an Add note modal; keep separate audience drafts through switches and dismissal, and preserve pending submission recovery.
- [x] Exercise permissions, atomic rollback, retries, stale edits, time calculations, booking conflicts, drafts and browser flows.

## Implementation boundaries

Preserve existing changes in this shared checkout. Reuse canonical ticket/comment/transition/draft/notification writers and the shared calendar presentation. Internal time/cost/contact context is never included in requester payloads. No external messages, calendar invitations, production migration, deployment or billing transactions are implied by local implementation and verification.

Priority mapping: urgent = P1 Critical; high = P2 High; normal = P3 Medium; low = P4 Low. Existing priority assessment, override reasons and SLA clocks remain authoritative.

## Verification checkpoint after crash recovery

- Latest focused UI group: 51 passing tests, including separate modal drafts/time, closing/reopening, pending outcome recovery and matched-save dismissal. `evidence/ticket-workflow-modal-ui.txt`.
- Work backend group: 18 tests / 87 assertions passed; earlier work plus canonical comment command group: 39 tests / 283 assertions passed. `evidence/ticket-workflow-backend-final.txt` and `evidence/ticket-workflow-backend-tests.txt`.
- Four additional database-free time tests / eight assertions passed after fixing fractional ISO timestamps, exact business-hours boundaries and break-preserving split suggestions. `evidence/ticket-workflow-time-unit.txt`.
- Modal-scoped lint is clean. Typecheck reports only the three existing `today-retirement.test.tsx` unsupported `exact` option errors. `evidence/ticket-workflow-modal-lint.txt` and `evidence/ticket-workflow-modal-types.txt`.
- Browser verification and local activation are recorded below. The ambient port 8793 mockup is not evidence of the Laravel implementation.

## Browser verification and local activation

Verified the real Laravel ticket at `http://127.0.0.1:8779/it/tickets/1` and its `time`, `schedule`, `people` and `costs` sections using an owned disposable schema and synthetic users. Identity endpoint confirmed the intended checkout, private asset manifest, real CSRF, isolated storage, array mail and no external provider requests. The synthetic account's default My Day landing returned 403; its authorised ticket opened directly after successful normal sign-in.

Observed in the browser:

- Requester work email/phone, P3 identification and the Add note modal rendered.
- Public/internal audience switching kept independent fields. Closing and reopening retained the internal note, times, breaks and after-hours selection.
- At 390 × 844, the modal was 362 px wide, fit the viewport without horizontal overflow, and scrolled to its controls.
- A note and 18:00–19:00 period with ten-minute break saved atomically as 50 after-hours minutes and remained after refresh.
- Search selected two available additional technicians; both requested bookings saved for the same future hour with location and brief.
- User search selected the requester as the affected user, and the work contact preference/window saved correctly.
- Two parts at NZD 12.50 saved as NZD 25.00. No browser console errors were captured.

Browser-discovered corrections were then completed: terminal work drafts now fetch current capability metadata before starting the next form, and frozen command payloads normalize optional empty strings to Laravel's null representation. The affected six-case regression suite and scoped lint pass (`evidence/ticket-workflow-browser-fixes-ui.txt`, `evidence/ticket-workflow-browser-fixes-lint.txt`). These corrections were tested after the first browser pass, not retrospectively claimed as browser-tested.

The browser tab was closed and the exact owned server, database and directory were removed. `evidence/ticket-workflow-browser-cleanup.txt` confirms cleanup. The 8,442-file source postflight records independent Governance edits only; ticket and shared IT dependencies did not drift (`evidence/ticket-workflow-browser-source-postflight.json`).

Local activation was explicitly constrained to `APP_ENV=local`, configured loopback MySQL, complete canonical dependencies and either all-absent or all-present owned tables. Only `2026_09_13_120000_create_it_ticket_work_records.php` was applied; readiness is true. No existing ticket records, remote environment or unrelated migrations were changed. See `evidence/ticket-workflow-local-preflight.json` and `evidence/ticket-workflow-local-activation.json`.

The final normal shared Vite build passed under the coordinated Vendor task (05:57:04–06:01:19 UTC). Its manifest SHA-256 is `39b9420c6e0aace482bd5b8491aac75e345335644519dfc7ee4222dd876d29f9`; no `public/hot` file is present.

Read-only final verification passed at `https://oblivionfindings.test/it?tab=tickets` and existing ticket `https://oblivionfindings.test/it/tickets/6`. The normal application showed priority identifiers, contact/context/work sections and the new public/internal Add note modal with timer and time entry controls. The existing signed-in session was unchanged, no note or other ticket record was saved, the browser console error list was empty and the verification tab was closed. `evidence/ticket-workflow-herd-final.json` records the served manifest/app/ticket asset identity.

Delivery complete locally. The latest applicable focused UI groups cover 53 cases (39 composer, eight work fields, six secondary-work recovery); the six-case final regression run replaces its earlier four-case subset. Full-project typecheck had unrelated Today-retirement test errors, with independent Governance diagnostics reported during concurrent work; no changed ticket-file type errors were reported. No remote deployment or Git publishing was performed by this task.
