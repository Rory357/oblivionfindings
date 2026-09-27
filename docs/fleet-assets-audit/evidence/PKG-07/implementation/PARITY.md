# PKG-07 v9 visual and functional alignment

27 September 2026. Compared the running Laravel application on port 4426 directly with the frozen v9 mockup on port 4425. The first implementation did not yet match the approved composition closely enough. This pass corrects those gaps in the application; it does not edit the frozen design.

## Completed alignment

- Restored the four linked header meters, scoped search, Home breadcrumb, Site filter and approved “Rules & uses” tab label. Counts come from permission-scoped backend queries, not mockup fixtures. Source-review and open-follow-up meters open matching filtered results.
- Restored the two-column map anatomy: layers and the searchable, paged vehicle/asset directory on the left; a wide map, boundary finder, summary strip and pinned record/boundary inspector on the right. Added the approved heading and build/fit actions. The inspector retains source evidence, linked areas, geometry versions and profile/rule actions.
- Moved boundary lifecycle, sort, permitted use, page size and list/cards controls into the shared header filter row. No copied mockup controls with inactive handlers.
- Completed the history dependency panel with current linked rules, source-review state, original events, the person/client connection and boundary review. History-to-rule/event links apply an explicit boundary filter with a visible clear action. Snapshot comparisons include name, address and availability as well as retained geometry and permitted uses.
- Matched the main vertical spacing and responsive map/inspector composition using scoped shared theme tokens. The live application's existing dark/light preference remains authoritative; v9's fixed light canvas and synthetic map are not forced over the application theme or real map provider.

## Verification

- The focused boundary and map-privacy tests passed: **7 tests, 141 assertions** (`parity-backend.log`). These extend existing coverage and are not seven additional distinct tests beyond the earlier 32-test record. New assertions cover Site-scoped counts, source-review filtering and follow-up counts/results excluding private journeys and unreadable alerts.
- Focused TypeScript diagnostics for the implementation are empty (`parity-typecheck.log`). Existing diagnostics in unrelated imported components remain separately recorded. PHP formatting and Git whitespace checks passed.
- Compared the map composition visually against frozen v9 at the same normal browser size. Browser search found QA van 110 beyond the first 100 loaded records and pinned its genuine “No location” state without inventing coordinates.
- History for BG-130 showed both retained revisions and the actual QA van 110 dependency. Its link opened exactly one boundary-scoped rule. The rule detail retained Sunday, 27–30 September, 22:00–06:00 following-day timing and the saved response; monitoring remains inactive.
- Inspected the settled rule-detail and four-step builder dialogs at a 960-pixel viewport. Both stayed within the viewport, with scrollable content and reachable footer actions. The temporary viewport was reset. The address field truthfully explains that an approved provider is required and offers Site/manual coordinates.
- Final Vite production build passed in 6m 52s (`parity-final-build.log`), with the existing large-chunk advisory. Verified the final compiled page has the 22px section gap and header filter controls, and no horizontal document overflow.
- Verified list/cards plus debounced header search, both summary filter destinations and their empty states, and the explicit BG-130 rule filter with its clear action. Loaded all 110 records and refreshed while retaining QA van 001. Right-clicking a map boundary opened its view/history/person connection/edit/rule/copy/legacy-review/retirement menu. No final-session browser console errors were reported.
- Final visual evidence: `parity-map-final.png`, `parity-history-final.png`, `parity-cards-final.png`, `parity-rule-details-960.png` and `parity-builder-960.png`. The map remains open in the Codex browser at port 4426.
- All 68 frozen v9 manifest files still match. The manifest SHA-256 remains `573EA5401FE8EC4F2A44E8618795D5DA5B97CEB4B41CE0B142C8A83B181D1D52`. Active preview logs were read with shared file access; no preview process was stopped to perform this check.

## Remaining release dependencies and intentional scope

The application is implemented locally, using the isolated synthetic QA database. Normal release, additive migrations and provider configuration remain deployment work. At this parity check the address provider was disabled; the subsequent user-approved OpenStreetMap submitted-search implementation and verification are recorded in `ADDRESS-SEARCH.md`. No customer address was sent to an external service during this check.

Read the latest **OF | OPS-PL01 People Locations | DESIGNER ASTRA | Mockup** chat. It has advanced to a v5 design candidate. That sibling design is still not a deployed production owner. This implementation connects to the canonical Client Location service and its draft/consent/version checks; it does not create a parallel personal location store or claim an end-to-end integration with an unimplemented design. No cross-chat message or sibling code edit was made.

New purpose rules remain inactive proposals, as in the approved v9 scope. Monitoring activation, new evaluators, bulk import/assignment, polygon holes/multipolygons and historical route replay are not silently added. The normal permission, Site, privacy and canonical-owner boundaries remain intact.
