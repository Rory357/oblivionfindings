# PKG-01 v7 — calendar and clock consistency

Owner: DESIGNER ASTRA. Same-package design-only continuation. Stephan requested the clock/manual picker throughout the remaining modals, then the calendar picker. Main independently verified both (live register21/review16). No application/guide edits, implementation or new policy authorised.

## Baseline and D3 receipt

Actual turn `01a0bbc3-f157-7852-8b9a-3b8a1d1643f5`, timestamp `2026-09-19T22:25:53.529Z`, verified gpt-6-astra/xhigh in both fields before writes. Same detached HEAD `e62b569ff42ab471300fb6713a68758b647b2c32`, worktree8424; master10+A1/A2/A3 hash `A61469ED0C48B0A1F179D873A43E73B1254FFB9754733C3556CE6BC88AFFD990`.

Main manifest8 hash `3652C3E9BEC38376C49B01BAB13AA569C4DD19F8F428F0A3872F861771372DA4` and all16 hashes independently matched. Read 00rev8/04rev6/09rev4/handoff6 and live records. Consumed D3 Clock/manual recurring pattern, popup section, probe21, checklist and native-default clarification as supplements to the newer baseline, together with D1/D2. Main guide hashes: DESIGN `371AF1BED25CE3D8C8A49C62ABA3D1C24E78301E7AD927D25E97C5A7E9EE6B1A`; popup `EACC67D770B1433B828B015DF82A2E5BFA789676B277CFCC8AB5AB701CEB13FD`.

Worktree guide hashes unchanged: DESIGN `F9C584456FC87BEB4E9A9B04718EF01593CA78BDACAC0F1446808DD990C29ED9`; popup `AEE723E21491E9BE908554A6F200D73204280A63D9028EA78DE6BB9049F4A477`. No replacement or ownership transfer. V6 all24 entries rehashed unchanged; earlier frozen versions preserved. Tracked/staged diff empty. No installs, agents, Implementer, application writes, commit, migration, integration or deployment.

## Complete scoped field inventory and contracts

- Report Resource: existing optional estimated range via MaintenanceWindow/shared LeaveCalendarRange, including Not known yet.
- Report Problem: Observed at now uses DateTimeField, single-date calendar plus shared TimePicker. Both blank remains allowed; incomplete pair blocked. Review and immutable source show the same local timestamp with Pacific/Auckland.
- Triage: Next action target uses the same pair. Canonical current target prefills/reopens. Incomplete pair blocked, blank keeps current target, and deadline-only change preserves the existing next-action text. It does not schedule an appointment.
- Appointment: required start/end range remains; both times use TimePicker. Existing interval validation/internal planning retained.
- Full v7 TSX sweep found no other editable date/time controls and no remaining native date/time/datetime-local inputs. Recorded source/audit/check/release timestamps stay read-only.

DatePicker composes actual LeaveCalendarRange with shared Popover/Button. A selected day becomes both endpoints internally, adapting one click to one timestamp date. Local draft applies with Use date; month navigation does not select. Cancel/Escape discard locally, return trigger focus and do not dirty the parent. Keyboard open focuses selected/first day. Scoped preview CSS suppresses the HR source's required asterisk for these optional dates. No HR policy/shared source edits.

DateTimeField preserves independent YYYY-MM-DD and HH:mm parts and combines local form values without browser-timezone conversion. Partial values are draft-only. Uses shared formatDateOnly and the same exported TimePicker formatter, with explicit Pacific/Auckland. Each picker retains the other part. Clear date and time clears both draft parts, subject to owning workflow semantics. TimePicker's only v7 changes export its formatter/update its comment; one clock composition serves all callers.

## Observed browser QA

Dedicated IAB tab12 at http://127.0.0.1:4323/; title/banner v7 and worktree8424/e62b569 verified. Prior tabs/drafts not reloaded. Build passed with existing dependencies (2489 source dependencies). Inspected warning/error console empty.

- Triage calendar: Enter focuses selected day. September→October browsing retains21Sep. Selecting2Oct then Escape discards the draft, focuses `triage-target-date` and leaves the parent open. Cancel parent has no dirty prompt. Applying2Oct retains10AM. Empty picker disables Use date. Single-day selection/month navigation observed.
- Triage time: Enter focuses hour10; hour7→minute35→PM renders07:35PM. Cancel retains10AM without dirtying parent. Manual12:07AM applies canonical00:07, retaining date. Review shows2Oct2026/12:07AM/Pacific/Auckland. Save failure retains both/reason; Retry same request saves once. Overview2Oct00:07 and reopened2Oct/12:07AM match.
- Validation: triage date-only can reach Review labelled Incomplete, but Record details returns to Details, shows its own inline error and focuses missing time. Report time-only blocks Continue and focuses missing date. Both observation parts blank accepted. Hour13/minute60 rejected and affected digit focused. No incomplete record saved.
- Report at1280: calendar opens focused19Sep; applying18Sep retains02:20PM. Manual12:23PM retains18Sep and exact minutes. Review shows18Sep2026/12:23PM/Pacific/Auckland. Keep draft and close→queue Resume report retains both. Save failure retains values; Retry report creates one RP-0184 linked to existingWO-0264. Its read-only source shows the same observed timestamp, separate submission time and unchanged owner/hold.
- Final-build deadline regression: changing only target to22Sep00:07 retains “Review repair and arrange retest”. Testing found the inherited fallback replaced that text; corrected before this final check.
- Existing appointment smoke check: required21Sep–21Sep range,09AM/11AM controls retained. End clock opens11AM; Escape retains value. Report estimated range still optional/Not known yet. Earlier v5/v6 exhaustive interval/range tests not repeated; their source remains unchanged.
- Desktop: triage1440×1000/1280×900 and report1280×900/1440×1000 inspected. At1280, triage date picker390px wide,y234→719.5,clientHeight=scrollHeight484; clock346px,y170→783.5,clientHeight=scrollHeight612. At1440 report date y331→816.5/time y268→881.5. Footers visible, no picker inner vertical overflow. Page width=scrollWidth1440. Escape returns `report-observed-time`. Viewport reset for delivery.

An initial native-date composition needed input-event handling; the subsequent explicit calendar request replaced those inputs entirely. Final date selection/state validation was verified after replacement. Native-input results are not presented as final-calendar proof.

## Screenshots

1. 01-triage-clock-1440.jpg — clock with final calendar trigger behind.
2. 02-triage-calendar-1440.jpg — cross-month one-day selection/summary.
3. 03-report-calendar-1280.jpg — observation calendar.
4. 04-report-manual-time-1280.jpg — exact-minute typing/AM-PM.
5. 05-report-review-1280.jpg — selected timestamp in Review.
6. 06-triage-clock-1280.jpg — complete clock/footer at laptop width.
7. 07-report-paired-controls-1440.jpg — paired controls without overlay.

Visually inspected. Exact source/assets/screenshots in artifact-manifest-v7.json; older evidence remains immutable.

## Limits and gate

Known Auckland ambiguous/nonexistent DST handling remains unimplemented: local shape/interval checks do not resolve an instant. Production needs established timezone utilities and server validation. Full cross-browser/screen-reader/zoom/reduced-motion coverage and calendar arrow-grid navigation not certified. Shared calendar uses labelled native buttons with Tab/Enter; no new roving-grid behavior.

Real persistence/search/transport/idempotency, actor/permission enforcement, operational calendars, release authority and Finance remain implementation-only/unverified. No new policy/sibling package, feature-complete production claim or §13B approval. Main reviews exact frozen v7; Stephan's mockup/scope gate and later implementation gates remain required.
