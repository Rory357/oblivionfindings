# PKG-01 v5 — consistent appointment date selection

Owner: DESIGNER ASTRA. Design-only delta from frozen v4; v1–v4 preserved. User explicitly requested Plan appointment use the same Report a problem date picker and asked Main to add the pattern to Rory's design rules. Two supplied screenshots identify the exact field and reference interaction. Main was sent that request; Main's D2 in 09-amendments.md records its independent verification and guide-write ownership. No source-guide or application edit is made in this worktree.

Actual continuing turn verified again before v5 writes: `01a0bb6c-3623-7f40-bc0c-763dd960a365`, `gpt-6-astra/xhigh`. HEAD remains `e62b569ff42ab471300fb6713a68758b647b2c32`. Master remains Revision 10 + A1/A2/A3, SHA-256 `A61469ED0C48B0A1F179D873A43E73B1254FFB9754733C3556CE6BC88AFFD990`. Frozen v4 identity `b21faacaa98a7bed46a3ac3c78e4801655fe33ed6bb34dcb89a32cbffc04abe6` and all 35 entries rehashed unchanged after v5 changes. V4 already verified the earlier v1–v3 entries and protected guide hashes.

## D2 receipt after exact v5 freeze

Main subsequently delivered manifest revision 7, SHA-256 `5330DF491E39F3775BB6B29DD36573748143B52D8E418A2C86389DDB45C260C0`; all 16 entries were independently rehashed and matched. Read the changed 00 rev7, 02 rev6 appointment refinement, 04 rev5, 09 rev3, handoff rev5 and live register rev17, plus the exact DESIGN.md Calendar date and range selection / conformance probe20 and popup calendar-selection section/checklist. Main DESIGN.md is `C99AF63B7E011B678DCE58260977F7F8952FCDD25A5AE79261295604A7EAA988`; popup guide is `2B3F16A3ED4AFD102631B96626E03917D053206F16BEAF72337C567FCE193DD8`. Main has completed the user's requested rule additions; they are read-only supplements to this worktree's newer guides, not replacements. V5 executable identity and frozen screenshots are unchanged by this documentation receipt.

V5 demonstrates the calendar/summary/required-state/separate-times and recovery presentation requested by D2. It does not provide full production D2 conformance: specifically, its local date/time strings and lexical interval comparison do not resolve or reject ambiguous/nonexistent Auckland DST times. Integrating established timezone conversion and explicit DST validation remains required implementation work, alongside the shared optional-label/accessibility adapter previously documented. The prototype performs no operational scheduling, and no DST correctness is claimed.

## Design delta

- The existing maintenance-range adapter now has an appointment variant, directly reusing shared LeaveCalendarRange. Calendar, navigation, selected range/day-count summary and keyboard date buttons remain the same as Report a problem. Required appointment dates show the required marker; optional Report a problem retains Not known yet and no required marker. No HR working hours, leave entitlements or holiday policy is imported.
- Appointment times are separate time controls below the calendar, explicitly labelled Pacific/Auckland. Start/end date strings and wall-clock time strings stay separate in form state. The current planned appointment prefills; a newly selected single endpoint must be completed. End must be after start. Shared local-date formatting supplies labels; no date-only UTC conversion or due-date repurposing is used. DST ambiguity/nonexistent local-time rules and server timezone conversion remain implementation contracts, not tested claims.
- Review displays the same dates and start/end times. Back, dirty-close Keep editing and save failure/retry retain them. Reopening a successfully recorded appointment reads the saved dates/times; the work overview and attributable history show them. Unchanged default values do not incorrectly trigger discard confirmation. The appointment remains an internal plan awaiting provider confirmation; estimates, confirmation, restrictions, booking eligibility and Finance remain distinct. No additional calendar event projection or operational request is created by this delta.
- All v4 notes/progress/completion, searchable handover and consolidated release features remain in v5. No unrelated redesign or policy change.

## Observed changed-flow browser verification

Separate tab at http://127.0.0.1:4321/ verified title/banner v5 and the new calendar. User's v4 tabs/drafts were not reloaded. Existing runtime build succeeded with 2486 source dependencies; no installation.

- Default 21 September 2026, 09:00–11:00 plan rendered with one calendar day. Keyboard Enter selected dates. A partial 30 September range failed recording, focused `maintenance-window-dates` and showed the required endpoint error.
- Cross-month 30 September–2 October with 10:00/15:00 displayed identically in Review. Simulated failure retained three calendar days and both times. Retry recorded one internal plan, and work overview displayed both endpoint dates/times with Pacific/Auckland. Reopening retained the saved plan.
- Same-day 23 September selected by choosing the day twice. 09:30 start/09:00 end failed with `appt-end-time` focused and an associated error. Correcting to 11:30 saved and reopening retained 23 September, 09:30/11:30.
- Dirty-close dialog offered Keep editing/Discard; Keep editing retained the time entry. Opening an unchanged saved appointment and pressing Cancel closed directly. Report a problem regression retained its optional Not known yet default/calendar and locked resource context.
- Desktop viewports 1440×1000 and 1280×900 inspected/captured. At 1280 page scrollWidth was 1280, modal width 1100 and scrollWidth 1098; the calendar/form scroll inside the standard wizard frame. The latest inspected console had no warning/error entries.

V4's full accumulated QA is linked from the package page; it was not all re-executed for this small delta. Real persistence/search/uploads, server concurrency/idempotency, booking/calendar transport, authority/custody policy and Finance integration remain unverified. Alternate timezones, DST transitions, screen readers, zoom and reduced-motion rendering remain unverified. No application feature-completeness claim.

Return the exact v5 artifact to Main for independent review and Stephan's unchanged mockup/scope decision. No Implementer, agent, commit, migration, push, deployment, live message or operational assignment was performed.
