# PKG-04 independent implementation audit

27 September 2026. Worktree: `C:/Users/steph/.codex/worktrees/1eb2/oblivionfindings`, baseline `4ea64c547ed85a5b7504e59599db351f6eba7deb`.

## Delivery and coordination

The requested GPT-6 Sol Extra High implementation chat (`01a0dfe3-023c-7661-8f06-423f263f7f9c`) finished its handoff and was archived before the final audit. Root sent no follow-up messages to that implementer. Main received the authorised implementation-start notification. The audit and corrections were completed in this worktree; Main and sibling application checkouts were not edited. No commit, push, merge or deployment was performed.

The approved v9 packet remains unchanged: all 38 member hashes match. Its manifest SHA256 is `215147643BBAFAF092AF0E823A727866232043D08AE0229F9A941B05FE66B80E`. The frozen packet's old approval status is historical; the subsequent user instruction and IMPLEMENTATION-RELEASE.md authorised implementation.

## Audit corrections

- Connected hero filters to the complete map scope, bounded hover styling, aligned the selected detail card and legend, and equalised desktop map/list height. At 1152px the measured map is 653px high, panel bottoms differ by 1px, card and legend widths match, and there is no horizontal overflow.
- Map and calendar requests cancel superseded requests and clear inaccessible/error data. Stale calendar snapshots block move/create. Source summaries drive map restriction/due/current-booking actions. Vehicle-calendar navigation retains Fleet context.
- Protected calendar entries keep their lock and accessible cannot-move explanation. Short release and Enter/Space inspect; down, hold, movement away/back, outside release and Escape do not open details. Existing edit authority is preserved.
- Added empty-range selection with quarter-hour boundaries; both endpoints survive vehicle selection. Moves remain proposals in the canonical booking wizard. A changed source version requires a calendar recheck.
- Corrected Auckland wall-clock rendering and drag proposals in browsers using another timezone. Six local calendar weeks now remain valid across the 25-hour autumn day. Booking forms distinguish repeated autumn times, reject missing spring times, and send explicit offsets to the existing source API. Readiness acknowledgment resets when relevant details change.
- Corrected Fleet source labels in the Today sidebar and hid that sidebar when today is outside the loaded range, avoiding unsupported empty-schedule claims.
- Replaced misleading compliance “Current” labels with recorded expiry-alert counts. Availability remains unknown until an exact assessment. Bulk site assignment uses approved destination sites separately from fleet-wide filtering sites.

## Verification

- Production Vite build: passed, `implementation/audit-final-build.log`. Existing large-chunk advisory remains.
- Focused frontend tests: 17 passed across six files (15 Fleet/press/time tests plus two shared work-schedule tests).
- Scoped ESLint, changed PHP syntax, and `git diff --check`: passed.
- Full TypeScript check: six existing diagnostics in the unchanged `resources/js/components/fleet-assets/fleet-workspace-navigation.test.tsx`, all unsupported `ByRoleOptions.exact`. No diagnostics in changed implementation files; see `implementation/audit-types.log`.
- Live Chromium against the actual Laravel preview, in UTC: map scope/layout/hover, marker right-click, keyboard More actions, source calendar return context, exact one-hour move proposal/cancel, quarter-hour range handoff, and six-week autumn endpoint passed. A deliberately simulated stale response blocks editing and requesting. No browser runtime errors. See `implementation/browser/check-audited-app.mjs` and `audit-results.json`.
- Protected gestures were checked against the actual built app with a deliberately simulated protected feed, without changing a booking. All ten checks passed; see `check-live-protected.mjs` and `audit-protected-results.json`. Earlier isolated Vite-harness attempts could not load and are not counted as passes.
- Backend final verification: **11 tests passed, 227 assertions**, in `implementation/audit-phpunit-current-schema.log`. This includes all-permitted fleet scope, restricted/zero positions, private busy projections, approved assignment sites, Auckland midnight boundaries, six-week DST range and source versions. The initial run exceeded the repository test harness's 300-second schema-import limit before any assertion. A first retry progressed through the schema import but spent many minutes replaying historical migrations and was stopped. The final retry imports only schema and migration bookkeeping from the dedicated PKG-04 preview (916 tables, 1103 migrations, zero missing worktree migrations), never application rows, into a fresh per-process database. It uses `audit-phpunit-bootstrap.php`, which raises that setup timeout to 1200 seconds and skips pruning unrelated orphan databases in a temporary test-base copy; tests, application classes, own per-process database isolation and own shutdown cleanup are unchanged. The final result supersedes the implementer's earlier nine tests/219 assertions.

## Preview and practical limits

`http://127.0.0.1:8767/fleet-assets/vehicles?view=map` serves the actual worktree build and canonical Laravel endpoints, using the dedicated local `oblivion_findings_pkg04_1eb2` database. The response header `X-PKG04-Worktree: 1eb2-4ea64c547` was verified. Test credentials and setup are recorded in `implementation/VERIFICATION.md`. No operational database was used.

Location freshness and permitted-site privacy remain with the canonical location service. Bookings, reservations, HR readiness and Maintenance retain their source ownership. The aggregate calendar calls existing source projections for each permitted vehicle; this audit verifies functional scope, not large-fleet load capacity. Full CI and deployment were not run.

## Final mockup-parity follow-up

The user's subsequent full-mockup question prompted a further root-only comparison with the frozen reference. Sol stayed archived; no new implementer or subagent was started. The following gaps were corrected in the real application:

- Entry and blank-time calendar context menus, including keyboard access, use the clicked record or time. Editing retains current-source guards. Reset sources clears the source/search selection, and Month overflow opens the correct Day.
- Register counts no longer show accidental dollar signs. Current/next record evidence comes from the permitted canonical calendar projection, labelled with its check time and 14-day look-ahead. Missing/stale evidence makes no availability claim.
- The vehicle picker uses the shared accessible dialog. Canonical booking success remains visible after save instead of immediately closing the wizard.
- Undo time change proposes the prior times in the canonical change wizard with the newly saved version. It requires review, a reason and current conflict/permission checks; it does not restore approval, custody, driver or purpose state automatically.
- Booking inspection retrieves requester/driver/purpose only through the authorised source-record endpoint. Busy-only entries never make that request. Booking and work-order source pages accept a constrained Fleet return link; the booking return was browser verified.

Together with the preceding audit, the approved production scope is implemented: useful hero/register and retained bulk/export actions; five fleet calendar views; canonical request/change/checkout/return handoffs; all-permitted map scope and location privacy; large map with compact hover and aligned inside details/legend; map menus and focused source navigation; protected calendar gestures. Synthetic scenario controls and fixture resets in the reference are preview tooling, not production features. Existing canonical source workflows remain responsible for their domain commands.

### Final verification after the parity corrections

- **21 frontend tests across eight files passed** (`implementation/parity-vitest.log`). Scoped ESLint passed without warnings (`parity-eslint.log`); `git diff --check` passed.
- Production Vite build passed in 5m 44s (`parity-build.log`). Only the existing large-chunk advisory remains.
- Full TypeScript checking again reports exactly the six baseline navigation-test diagnostics and no changed-source diagnostics (`parity-types.log`).
- Actual Laravel browser verification passed (`browser/check-parity.mjs`, `browser/parity-results.json`): register evidence; right-click and keyboard menus; source metadata/return; reset; accessible picker dismissal; real canonical save and visible success; reviewed Undo; Day resize/cancel; Agenda and Timeline. Month overflow uses an explicitly simulated read-only feed.
- The real local preview booking was moved by one hour and then restored to its original start/end through a second canonical reviewed save. Its source version incremented twice. Required reapproval was preserved: the synthetic booking now remains pending, rather than regaining approval as a side effect of Undo. No operational database was changed.
- Map/layout/hover/menu, exact Week move proposal, quarter-hour range, autumn six-week range and stale guards passed again on the final build (`parity-map-regression.log`). At 1152px the map remains 653px high with aligned 440px card/legend and no horizontal overflow.
- Real Month cross-date move preserved Auckland clock/duration and cancelled without saving. Protected press/down/short-release/hold/move/outside/Escape/Enter/Space and no-resize checks passed on the final build with an explicitly simulated protected feed (`parity-protected.log`, `browser/audit-protected-results.json`).
- The earlier **11 backend tests / 227 assertions** remain the backend verification; this follow-up changed only frontend source and evidence. All 38 frozen v9 member hashes and the manifest hash were reverified unchanged.

The new browser script's preliminary failures were assertion assumptions about the canonical title, query ordering and the fixture's original approved state; those assumptions were corrected before the passing run. A separate optional layer-control inspection was inconclusive after an automation timeout and is not counted as a passing check. The successful application suites above reported zero browser runtime errors. Final source hashes are recorded in `implementation/audited-source-hashes.json`.

Main's chat, **Follow Revision 10 approval gates** (`01a0b8c5-186f-7681-83fc-40229d94ef87`), was sent one final completion handoff after these checks. Delivery succeeded through `send_message_to_thread`; the message includes the worktree, implemented scope, exact verification/limitations, preview, immutable packet status and Sol's archived state. No acknowledgment or merge is implied by that delivery. The real app preview was queued for opening in this chat.

## Visual completion and corrected final verification

27 September 2026. The earlier full-parity wording overstated the visual match. Root acknowledged that the hero summaries, calendar toolbar placement and map controls/details still differed, then completed this visual pass in the implementation. Sol remained archived and was neither restarted nor messaged. This section supersedes the earlier visual-completion claim and TypeScript limitation.

- Fleet now uses the approved title, site chip and hero composition. Map-specific meters display actual permitted scope and position state, and their filters work. Register and Calendar evidence meters distinguish unassessed readiness from recorded source state; synthetic fixture counts are not copied into production claims.
- Calendar search, export, request, vehicle and date controls now sit within the hero, with one request action and a compact source row below. Carried-over vehicle filters are explicitly shown and clearable. Compare slots opens the existing Timeline view. Export contains the currently filtered permitted projection, preserves busy-only privacy and protects spreadsheet formula cells.
- The map has stacked labelled position/sort filters, separate selection controls, an accented selected row and a compact two-column inspector above the aligned legend. The layers control now has a visible icon. The measured map height is 748px at a 1440px viewport and 713px at 1152px; card/legend widths match, their gap is 10.5px at the app's root font size, and neither viewport overflows horizontally. The permitted total remains the full scope while list filters reduce the shown count.
- The local preview uses an isolated session cookie to avoid collisions with sibling localhost previews. The signed-in browser was verified against the real app. Both localhost and 127.0.0.1 reach the same dedicated worktree preview; authentication cookies are host-specific.

### Final evidence

- **50 frontend tests across 10 files passed**, `implementation/visual-final-vitest.log`.
- **Full TypeScript check passed with zero diagnostics**, `implementation/visual-final-types.log`. The six unsupported `ByRoleOptions.exact` test options were removed; string role names already match exactly.
- **Production build passed**, `implementation/visual-complete-build.log` (6m 46s). The existing large-chunk advisory remains.
- Scoped ESLint exited successfully with **zero errors and one panel-component style advisory**, `implementation/visual-eslint.log`. `git diff --check` passed.
- Final real-app layout, header actions, filter counts, CSV download, inherited criteria clearing and Timeline handoff passed at 1152px and 1440px with zero runtime errors: `implementation/visual-browser.log`, `implementation/browser/completed-visual-results.json`. Root visually inspected the final Map and Week screenshots; `completed-map-1440.png` and `completed-week-1152.png` show the delivered layout.
- Map menus/hover/context, Week proposals, quarter-hour range handoff, Auckland rendering, six-week autumn range and deliberately simulated stale-feed guards passed again: `implementation/visual-map-regression.log`.
- Month move/cancel and protected pointer/keyboard gestures passed: `implementation/visual-protected.log`. The browser harness now scrolls both drag endpoints into view before moving the pointer; its earlier offscreen attempt is not an application failure. Protected-feed checks remain explicitly simulated.
- Canonical booking save, visible confirmation and reviewed Undo passed on the final build, restoring the synthetic booking's original times while retaining required pending approval and advancing its source version. Context menus, source metadata/return, picker dismissal, Day resize/cancel, simulated Month overflow and Agenda/Timeline also passed with zero runtime errors: `implementation/visual-workflows.log`, `implementation/browser/parity-results.json`.
- Backend verification remains **11 tests / 227 assertions** from the preceding audit; this completion pass changed frontend source and local preview/evidence only. All **38 frozen v9 members** and its manifest hash were reverified unchanged. `implementation/audited-source-hashes.json` now records all 36 changed/new application and test files.

The approved Fleet implementation and visual completion are finished in this worktree. Different real source values and standard application navigation chrome are intentional; pixel identity with synthetic preview data is not claimed. No Main checkout edit, operational database change, commit, push, merge, deployment or full-CI run occurred. Main received the corrective completion handoff through send_message_to_thread after verification; delivery succeeded. Message delivery does not imply integration or deployment.
