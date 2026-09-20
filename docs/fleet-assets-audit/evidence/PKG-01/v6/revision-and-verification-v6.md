# PKG-01 v6 — clock and manual appointment time picker

Owner: DESIGNER ASTRA. Design-only delta. Stephan directly requested a styled Start/End picker with manual entry and supplied a clock/manual/AM-PM example after v5 freeze. Main independently verified that request and recorded it in live register rev18/review rev13. V6 is authorised by this new request, not inferred from D2 receipt. Same package/Designer/worktree; no new agent.

Actual continuing turn again verified before writes: `01a0bb6c-3623-7f40-bc0c-763dd960a365`, `gpt-6-astra/xhigh`. HEAD `e62b569ff42ab471300fb6713a68758b647b2c32` and master SHA-256 `A61469ED0C48B0A1F179D873A43E73B1254FFB9754733C3556CE6BC88AFFD990` unchanged. Context manifest rev7/all16 hashes were consumed/verified in the immediately preceding D2 receipt; Main confirms no hashed context change for this feedback. Read live register rev18. V5 all23 manifest entries rehashed unchanged; earlier versions remain preserved. Worktree DESIGN.md and popup guide hashes remain `F9C584456FC87BEB4E9A9B04718EF01593CA78BDACAC0F1446808DD990C29ED9` / `AEE723E21491E9BE908554A6F200D73204280A63D9028EA78DE6BB9049F4A477`. No further v5 QA/document change after Main's freeze instruction.

## Design and state contract

Search of the component inventory found no existing reusable clock-input picker; the dashboard clock is not an input. `time-picker.tsx` therefore composes actual shared Popover/Button and existing semantic tokens, with an isolated SVG clock hand and keyboard-operable hour/minute buttons. It does not modify application components or guides. Future shared-component adoption is an implementation decision, not silently approved by this preview.

- Both appointment fields show a clear time, clock icon and typing hint. The popover has editable Hour/Minute digits, AM/PM selection, Hours/Minutes faces, Type time/Clock mode, Cancel and Use time. Hour choice advances to minutes. Clock minute marks are five minutes apart; manual entry accepts every exact minute without rounding. SVG is decorative; labelled buttons provide the actual interaction.
- Opening copies the current HH:mm value into a local draft. Only Use time or Enter in a valid digit input applies; Cancel, Escape or closing without Apply discard the local edit. Picker-first Escape returns focus to the launching time field and leaves the appointment open. A cancelled time edit never dirties the parent appointment.
- Hour accepts 1–12 and minute 00–59. Empty/nonnumeric/out-of-range values cannot Apply; the associated error focuses the invalid digit. Arrow keys increment/decrement a single unit. AM/PM maps explicitly to canonical 24-hour HH:mm, including 12 AM → 00 and 12 PM → 12. Parent dates/time ordering, Review, dirty-close and save retry still use those same canonical values.
- Popover positioning uses side placement with collision handling so the full clock and footer fit at the two desktop sizes; it can flip for End time. Report date ranges, internal appointment/confirmation separation, hold/release and Finance are unchanged.

## Observed browser verification

Separate tab at http://127.0.0.1:4322/, v6 title/banner and current build confirmed. No earlier user tab/draft reloaded. Isolated build passed with 2487 dependencies and no installation; latest inspected console returned no warning/error entries.

- Keyboard Enter opened Start time and focused/selected its current hour. Dial Hour7 → Minute35 + PM displayed 07:35 PM locally. Cancel left the parent 09:00 AM unchanged.
- Typed 11:49 then Escape closed only the picker, retained 09:00 AM, focused `appt-start-time` and left one parent dialog. Manual mode rejected hour13 and minute60; nonnumeric `aa` also failed and focused `appt-end-time-hour`. Error text and IDs were associated with the digit field.
- Manual 12:07 AM appeared as 00:07 in appointment Review; changing to PM produced 12:07. Exact noon/midnight were separately exercised: 12:00 AM → 00:00 and 12:00 PM → 12:00 in Review. End 2:23 PM produced 14:23 without five-minute rounding. ArrowUp/ArrowDown on minute23 changed/returned it; switching Clock/Type time retained that draft; Enter applied.
- Same-day start12:07/end11:00 correctly failed the existing parent interval check and focused the new End-time trigger. After correcting End to14:23, Review showed12:07/14:23 Pacific/Auckland. Simulated save failure retained both, Retry saved once, work overview showed the exact endpoints, and reopening End prefilled02:23PM.
- Desktop1440×1000 and1280×900 inspected/captured. The picker measured346×613.5 with clientHeight/scrollHeight612 (no internal vertical overflow), stayed within the viewport and retained its action footer. At1280 the end picker flipped left; page scrollWidth1280. Initial top placement clipped the footer and was corrected before final captures. Five final screenshots cover hour/minute/manual and parent review states.
- Reset restored the clean baseline; final deliverable opens Plan appointment with the Start clock visible. Temporary viewport override reset. All earlier previews remain separate.

## Limits and gate

This demonstrates UI/state behavior, not production scheduling or full D2 conformance. The inherited wall-clock interval comparison still does not resolve or reject ambiguous/nonexistent Auckland DST times; established timezone utilities and explicit DST handling remain a known implementation requirement. No server persistence, permissions, request idempotency, live calendar transport, release policy or Finance integration is certified. Screen-reader/cross-browser/zoom/reduced-motion coverage and pointer dragging of the clock hand are not claimed. No new global time-picker rule was requested or written; Main's already-authorised D2 calendar rule remains intact.

Return exact v6 and the accumulated bounded scope to Main for independent review and Stephan's mockup/scope decision. No app or guide edit, operational message/assignment, install, Implementer, migration, commit, push or deployment.
