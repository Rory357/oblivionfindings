# Transport modal and location correction

27 September 2026. User-authorised follow-up to the published Transport implementation. The user reported clipped request content, missing location search, an unhelpful empty Planner on the `.com` test server, and requested checks of the other modals plus a note to Main.

Source commits: `7137053f0`, `1a3a590ea`, and `cf3670714`. Final integrated source: `cf3670714172132344ec7a280b3ed6a214397666`, containing published Main `554425e8dddd1b71bca0e81b6d5ddaf9d8f6459f` (including Maps and the later Fleet booking correction). This packet supplements the earlier PKG-05 review; it does not reset its history or assert full-repository CI is green. `source-manifest.json` binds the changed source files and built asset manifest to that revision.

## Corrections

- Request, assessment and information-response forms now separate Passenger & route, Date & time, Passenger needs, and Review. Dates retain validation and review links; wider desktop content avoids squeezing the date controls beside a long route form.
- Collection and destination use searchable permitted Site choices, explicit OpenStreetMap search, and manual entry. Selection fills the trip's location text; Sites continue to own canonical addresses. The server enforces current actor/client/site access before and after provider lookup and forwards only the entered public query to the existing geocoding service. Results are not browser-cached. The UI aborts stale searches and distinguishes provider failure from no matches.
- Public Nominatim prohibits client-side autocomplete requests per keystroke. Saved Sites filter immediately; the user explicitly chooses **Search OpenStreetMap** for external lookup. Selecting a returned address fills the field. Reference: https://operations.osmfoundation.org/policies/nominatim/ . No new provider or service configuration is introduced.
- Empty Planner now explains Request → Assess → Plan, names the selected scope, and offers Request transport, Requests & approvals, vehicle availability, and filter clearing where relevant. Read-only inspection of `.com` showed no matching requests in both Planner and All requests for the selected week. No `.com` records were created or changed.
- Quick views expose all sections on mobile and use the shared nonsequential section mode. Mobile journey progress, route details and fact rows stack without clipping.
- Rescheduling separates time entry from the reason and confirmation. The review shows the existing booking alongside the proposal. An invalid submitted time returns to the time step.
- Simple Transport notes/information and evidence dialogs constrain their bodies while keeping footer actions visible. Evidence files use a constrained column so long filenames do not widen the dialog or hide removal controls.
- Shared review rows wrap long labels and values. Searchable selectors have accessible input names and viewport-bounded results. Shared Maintenance date/time controls measure space around their trigger and use vertical placement when neither side has enough room; widths are bounded. The prior right-side position clipped phone/tablet pickers, and a later fixed-width rule overrode mobile CSS. Placement is rechecked when the window resizes.

## Verification scope

The application preview uses this worktree, port 8765, and guarded disposable database `oblivion_findings_pkg05_browser_test_54600`. All fixture names and addresses in screenshots are synthetic, except the intentionally searched public Auckland library address. No application `.env` changes, operational data writes, deployment, new schema changes, guide edits or frozen mockup edits belong to this correction.

The browser checks cover filled notes, request/assessment/response forms, cancellation, decline, checkout, departure, return, arrival, missing-item receipt, Site key storage, review and discard confirmation, all five quick-view sections, evidence with ten staged files, rescheduling and nested date/time/location selectors. The longer passenger-accounting and completion actions also received layout-only checks by intercepting a synthetic journey's read response; no source commands were sent. Approval was reviewed in its shared action implementation but was not separately opened because the fixture actor cannot approve their own booking. Evidence was staged and discarded, not uploaded. No booking was changed by the modal audit.

The earlier end-to-end location check used the actual OSM provider for a public library, saved one synthetic request (TR-28), and reread its selected Site collection text and library destination from the source endpoint. No claim of live `.com` deployment follows from a passing local build.

Final check output, browser results, source hashes and screenshots accompany this packet. Intermediate failed measurements led to the mobile progress, file-grid and date-picker corrections; diagnostic style injection was used to identify a grid constraint, but final evidence uses the built application without injected styles.

- Focused frontend: **20 tests passed** across Transport and Main's Fleet booking-request regressions.
- Backend: **3 tests / 20 assertions passed** for permitted Site choices, provider context isolation/failure, and loss of access during lookup.
- Scoped TypeScript, ESLint, PHP formatting and patch whitespace checks passed. The production build passed with its bundle-size warning.
- Action-dialog matrix: **114 checks** across 13 actions at 1366×768, 1280×800 and 390×844, including review, dropdown and discard states.
- Quick-view/evidence matrix: **18 checks**, including all five sections on mobile and ten staged long filenames without a horizontal scrollbar.
- Nested date/time selectors: **16 checks** across 390, 768, 1024 and 1366 pixel widths. Rescheduling: six step/viewport combinations, with a required reason and no booking writes.
- All **105 frozen v6 manifest entries** match their committed hashes.

## Main coordination

The user explicitly requested the note to Main. It was sent to **Follow Revision 10 approval gates**, asking wider module reviews to check realistic filled content at laptop/mobile sizes, fixed visible actions, reachable fields, long files/labels, nested dropdown/calendar bounds, keyboard/Escape behaviour, searchable growing directories and permitted saved locations with explicit address search/manual fallback. Main confirmed these checks were being included in the remaining module briefs.

This candidate is submitted for Main's technical review and the existing serial publication gate. The user already authorised local-main and GitHub-main publication; this correction does not grant `.com` deployment authority or change the programme gates.
