# PKG-07 implementation verification

Verified 27 September 2026 in the isolated `806c` checkout, branch `codex/pkg07-maps-boundaries`. This is local implementation evidence, not a production deployment or monitoring activation.

**Subsequent visual alignment:** see `PARITY.md` for the direct v9 comparison, the restored two-column map and history dependency panel, updated header controls, final build/browser evidence, seven enhanced backend tests (141 assertions), and the latest People Locations v5 design inspection. The earlier three-column map screenshot and People Locations v2 references below describe the earlier verification pass, not the final layout or latest design state.

**Boundary visibility follow-up:** see `BOUNDARY-VISIBILITY.md` for the corrected faint default outlines, geometry retained during refresh and selected-boundary links. Ten frontend tests passed, the rebuilt browser rendered all 127 boundaries, and a manual refresh preserved both geometry and map position.

## Automated checks

- **32 distinct backend tests have passing coverage:** 19 unchanged Client Location draft / PKG-02b vehicle-map integration tests passed in `backend-regressions.log`; the final boundary, Site and map-privacy run passed **13 tests / 150 assertions** in `final-focused-backend.log`.
- The initial combined run reported 30 tests, 27 passed and three Site fixture failures. Those fixtures lacked explicit approved-Site assignments under the canonical access service. Corrected the fixtures, retained the access restriction and reran all six Site tests successfully with the final boundary/privacy batch. The initial log is retained rather than represented as a green suite.
- Backend coverage includes approved Site/object denial, retry-safe and stale writes, immutable geometry/copy provenance, retained rule settings, dependency protection, audit failure, private/consent-blocked journeys (including observations without trip IDs), ambiguous device pairings and observations predating an asset pairing.
- **34 frontend tests passed:** seven boundary geometry/map/policy tests (`frontend-tests.log`) and 27 Fleet navigation tests (`navigation-tests.log`).
- Focused TypeScript reports no diagnostics in the changed boundary, Client handoff, Site boundary dialog or vehicle map implementation. The broader imported component graph has existing diagnostics, recorded separately in `typecheck.json`; no claim of a clean repository-wide typecheck is made.
- PHP formatting and whitespace checks passed. Full Vite production builds completed successfully with the existing large-chunk advisory. The final build log is `build.log`.
- The frozen v9 manifest hash remains `573EA5401FE8EC4F2A44E8618795D5DA5B97CEB4B41CE0B142C8A83B181D1D52`. All 68 referenced frozen files matched (`frozen-v9-check.json`). Main and sibling checkouts were not implementation targets.

## Browser and persistence checks

The actual Laravel application is served at `http://127.0.0.1:4426/fleet-assets/geofences`. It uses only the dedicated synthetic database `oblivion_findings_pkg07_preview`, with 126 seeded areas, 110 vehicles and authorised Site fixtures. It is separate from the frozen v9 preview on port 4425. Both additive migrations were applied to this QA database.

- Signed in through the ordinary login and two-factor flow as the synthetic QA reviewer.
- Searched the paged library for boundary 126 and switched between list and cards.
- Created a circle through all four builder steps, using a permitted Site, a 220 m radius, explicit reason and final review. The saved record is BG-130, geometry version 1. IDs skipped by rolled-back tests are expected.
- Renamed that area through the builder and verified the success action opens its detail even when the current library search excludes its new name. The resulting record is `QA reviewed shared area`, revision 2, still geometry version 1.
- Confirmed the created history entry, actor, reason, geometry version and immutable snapshot. Downloaded and inspected the actual `boundary-130-history.json` export. Comparison correctly states when no earlier geometry exists.
- Inspected retained history/comparison at 960 px width. Dialog content scrolls independently and footer actions remain reachable.
- Verified the settled detail dialog at 960 px (`boundary-detail-960.png`) and the three-column map at 1600 px (`map-wide-1600.png`), with no horizontal page overflow. Temporary viewport overrides were reset.
- Rendered actual map tiles and 127 areas after the new save. Loaded beyond the first 100 resources to all 110, searched for and pinned QA van 110, and opened its evidence dialog. It correctly shows no location; no invented map point is rendered.
- Refreshed positions and confirmed the 110 loaded resources and pinned selection were preserved. A stale recorded observation displays the out-of-date state.
- Opened the map's right-click menu on a map point. Verified accessible map actions and builder Shift+F10/Escape behaviour.
- Completed the six-step rule wizard using QA van 110 and BG-130. Saved a Sunday 22:00–06:00 following-day proposal for 27–30 September with response instructions. Reopening shows the retained overnight window, dates, geometry and response, with monitoring inactive and unproposed thresholds still blank.
- Final browser fixes address header internal scrolling, notice contrast, post-save boundary inspection and a complete pre-save policy review. Screenshot evidence is stored beside this document.
- Reopened the final rebuilt rule review and confirmed it includes the saved purpose, response instructions, human-readable direction, all thresholds, dates, weekdays and overnight windows (`rule-review-final.png`). No browser console errors were reported in the final map session.
- Verified the navigation fix on the final build: immediately switching after page load updates the URL, Back restores the matching tab, and refresh reopens that tab. Inertia owns the navigation history. The final complete build finished successfully in 3m 44s.

## Test isolation and limits

The cold combined backend run used an isolated test database. The final focused rerun used rollback-only transactions against the dedicated synthetic QA database; `preview-privacy-bootstrap.php` permits only the three named feature files, validates the final migration and bypasses schema reset, not authentication or application authorization. No Main application data or environment was used. The schema import timeout can be extended through the testing-only `MYSQL_TEST_SCHEMA_TIMEOUT` variable; its default remains 300 seconds.

The original provider-disabled checks have been superseded for submitted address lookup by the user's explicit choice of free OpenStreetMap. Public Nominatim is enabled in this isolated preview for Search/Enter submissions only; type-ahead remains prohibited and blocked. The address follow-up is recorded in `ADDRESS-SEARCH.md`. No production provider account was configured or live customer addresses sent to a provider. The inspected People Locations v2 remains a separate design candidate; this implementation connects to the existing canonical Client Location owner. Client handoff/privacy is covered by backend checks, not an end-to-end browser run of that sibling design.

New purpose proposals deliberately remain inactive under the approved v9 scope. No evaluator, notifications or personal tracking authority are enabled by saving. Production rollout still requires the normal release process, migrations and provider configuration. No commit, push, merge or production deployment was performed.

## Address-search follow-up

User-selected free OpenStreetMap submitted lookup is enabled in the isolated preview. Real Te Papa lookup and selection populated the address, coordinates and map; Site selection also autofills untouched locations. The final build passes, 17 focused frontend checks and six backend tests (111 assertions) pass, and implementation TypeScript diagnostics are empty. Browser verification confirms existing boundary geometry is preserved and the original user draft remains in its original tab. See `ADDRESS-SEARCH.md` for policy, configuration and screenshot evidence.

## Main integration candidate

`MERGE-HANDOFF.md` records the user's local/GitHub main publication request and current-base reconciliation through published `79ea01a561f7d2fa5affa592dc096f516d663635`. This candidate passes 35 focused frontend checks, 14 backend tests / 193 assertions, and scoped ESLint on 34 TS/TSX files with zero errors or warnings. Changed-implementation TypeScript diagnostics are empty; inherited graph diagnostics remain separately recorded. The final build passes in 3m 49s and the rebuilt browser verifies address selection and geometry/camera preservation during refresh. Earlier fixture and lint failures are distinguished in the handoff rather than hidden. Actual main integration and GitHub push remain subject to Main's exact-source technical approval and serial slot.
