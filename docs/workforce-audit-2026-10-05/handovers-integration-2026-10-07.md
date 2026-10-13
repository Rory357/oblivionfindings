# Handovers read and navigation integration — 7 October 2026

This package replaces the legacy handover hero with the shared page header and connects its counts, search, people/Site filters and status views to the complete authorized record set. It preserves current worker/manager, incoming-duty, canonical person and controlled-content privacy boundaries. Control Room/operator rostering and eMAR remain independent.

## Behavior

- Counts cover all matching records before the selected status, rather than only the loaded page. Stable pagination exposes records beyond300, with stale pages clamped to the available range.
- Literal search and person/staff/Site filters use authorized content. Current recipient and acknowledger searches remain distinct from immutable recorded history. Unknown or unavailable filter identities stay generic and clearable.
- Worker-local week boundaries, including daylight-saving changes, determine the recorded range. The header discloses scope and the actual read time, without a fabricated live-sync claim.
- Search choices survive week/status navigation; failed loads retain choices for explicit retry. The board deliberately shows all statuses and its selected scope reflects that change.
- Shared responsive header/rail, desktop cards/list/board and narrow layout retain existing entry points and actions. Existing draft/submission/acknowledgement writers are preserved; this package does not claim to complete their separate save-recovery work.

## Verification

- **20 distinct native MySQL cases /408 assertions pass**:19/385 on the initial run, then the exact repaired fixture case1/23. Initial error was before assertions because an acknowledged fixture lacked its mandatory exact incoming duty. The two-line fixture repair gives the same person/Site duty to its acknowledger; all original assertions remain unchanged and two independent reviews cleared it. No product safety rule was weakened.
- **40 focused UI cases pass**, changed-source lint, full TypeScript and production build pass. Backend changes and dependency closure independently reviewed.
- Actual development browser checks cover week/search/status navigation, Board scope, filter clearing and anonymous unavailable-client handling; desktop1440 and mobile390 screenshots use the final header. Mobile document width390 at390, one main landmark and final fresh-page console0errors/warnings.
- The preview has no populated handovers. Positive responsibility-transfer/task/end-of-shift browser journeys and generic writer-outcome recovery remain explicit programme work.
- Missing-environment file-read warnings in the isolated integration native run are recorded. Actual test processes returned0; launcher guard observed a transient owned console process, followed by independently verified full owned-process/schema cleanup and unchanged hashes. No external queue worker/scheduler was started.

Evidence: integration `test-results/workforce-main-handovers-final.xml`, `workforce-main-correctives-final.xml`, corresponding receipt/cleanup JSON; `output/playwright/handovers-a11y-final-desktop.png` and `handovers-a11y-final-mobile.png`. This package makes no claim that whole-repository CI or the full Workforce programme is complete.
