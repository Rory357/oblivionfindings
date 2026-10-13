# Connected topology map

Development source, 8 October 2026. This advances SD-08 within the complete single-organisation Security & Devices programme. It is not a claim of UniFi, PRTG or Auvik parity or an operational release.

## Experience

Network & IT → Map displays canonical visible devices joined by retained connection evidence. Operators can inspect a device or exact connection, see port labels, follow canonical device links, search the full permitted inventory by device name, narrow it to an approved Site, browse device pages, trace a connection path, pan, zoom and fit the map. Keyboard controls and an ordinary device directory provide alternatives to pointer interaction. Finding or focusing a device brings it into readable view even when the overall graph is very large.

The graph retains disconnected devices, cycles and separately sourced parallel connections. It does not invent a gateway, uplink or connection. A traced path follows retained relationships without direction; it is not proof of packet routing, reachability or present physical connectivity. Inferred/unconfirmed links are dashed, reviewed links solid, and older or unknown-time evidence is identified.

The snapshot selector provides ten retained snapshots per page, with Newer snapshots, Older snapshots, Latest topology and Refresh view controls. Each snapshot is compared with its preceding completed snapshot from the same Site and source. Added, removed and changed connections show their endpoints and ports. Snapshot membership and connections are historical; displayed names, health, links and access are current. A predecessor can be outside the ten displayed entries. Selecting another server page replaces the current inspection and search; the browser does not accumulate historical pages. Snapshot numbers distinguish captures with identical displayed times.

## Ownership and data limits

The existing topology snapshot/edge store and canonical Device/Site resolver remain authoritative. The read-only TopologyWorkspacePresenter is used by NetworkItWorkspacePresenter, with history included on the canonical map tab. There is no new topology command, parallel inventory or API. Read-only pagination uses the existing Network & IT map GET with topology_cursor.

Current snapshots are selected in SQL as the latest completed snapshot per Site/source, with capture-time ties resolved by ID. Internal native SNMP observation fragments are excluded from this projection. The graph shows up to 100 devices per inventory page, current Site/source snapshots are bounded to 100, and each history page to ten. Device-name search runs against the full currently permitted query before paging; approved Site selection narrows the same canonical scope. Both device and history navigation are implemented. Graph evidence and paths remain limited to the displayed devices, with explicit coverage wording. Device-centered exploration now follows current recorded connections across inventory pages, as described below. Whole-network multi-hop paths, source bounds and realistic workload acceptance remain unfinished.

History uses a strictly validated, bounded seek cursor over captured_at and ID, ordered newest first. The cursor carries a position, never authority. Equal timestamps, newly inserted snapshots and deleted boundary records do not require offset paging or a boundary-record lookup. Navigation links are fixed relative map URLs containing only tab and validated filter/cursor fields. History links retain the exact effective device position; device-page links clear the history position. Changing filters clears both positions. Malformed supplied cursor input on the map returns a generic 400; other tabs ignore it. An exhausted valid position or newly empty scope returns the latest available view with a visible reset notice.

Every page rebuilds current access. Every projected historical endpoint must still be a visible canonical device assigned to that snapshot's Site. Hidden or moved-device names, relationships and change counts are excluded. Historical membership does not grant current access. All devices in the current inventory page are included in the current map, including devices absent from retained snapshots.

Connection freshness uses retained edge last-seen timestamps and the existing monitoring.runtime.site_topology_stale_seconds setting (default 3,600 seconds; minimum 300). Missing or future timestamps are unknown. Legacy records do not distinguish original source observation time from a capture-time fallback, so the projection states retained_edge_timestamp and does not manufacture that distinction.

## Verification

- Frontend: 28 tests across topology map, layout, actual PHP contract, Network & IT workspace and operations workspaces passed. Tests cover exact edges, independent parallel evidence, disconnected nodes, cycles, path selection and hidden-endpoint filtering.
- Backend: nine unit/query tests with 54 assertions passed. Both full application test files passed: TopologySnapshotTest and NetworkItWorkspaceTest, 19 tests and 228 assertions. The sole isolated MySQL schema was removed after terminal exit 0.
- Full TypeScript and scoped ESLint passed. Whitespace checks passed with only the existing routes/console.php line-ending advisory.
- Actual exported PHP current-map and history DTOs passed through the edited React components. Browser checks covered selected paths, exact ports, zoom, keyboard pan, history differences, separate parallel links and a genuinely isolated device without a fabricated path.
- Light, dark and 390px layouts were inspected. A narrow-screen snapshot-selector overlap was corrected. Final clean browser checks had no application errors. The temporary topology browser and port 52214 preview were closed; the user's port 52212 preview was preserved.

Evidence uses fictional devices and isolated fixtures. No network discovery or provider call was performed against live equipment.

## Remaining acceptance

Verify device-centered cross-page exploration and source/graph scale limits with real organisational workloads, verify timely refresh and topology capture/outage behaviour, commission representative physical network equipment and complete the other monitoring/management requirements. Hardware, signed collector installation, production operation and the complete final release gate remain unverified.


## History pagination verification — subsequent increment

Fifteen backend unit/query tests passed with 171 assertions, including cursor validation, equal and fractional timestamps, forward/backward/oldest pages, insertion/deletion and scope changes. Independent backend review found no blocking issue. Thirty-two frontend tests across five files passed, including the unchanged actual PHP paging DTOs; full TypeScript and scoped lint passed. Six real Inertia GET navigations in the browser exercised actual first/older/newer/oldest/reset DTOs, selection replacement, same-time snapshot choice, refresh and light/dark/390px layouts. The initial test locator ambiguity and browser selector timeout were corrected in the verification code; their failed attempts are recorded in CHECKPOINT.md.

The sole full-application pagination follow-up37352 finished exit0:22tests/300assertions/465.82s. Cleanup confirmed its process and isolated database absent. This supersedes the initial19-test application result for the history-pagination increment. The owned browser and temporary52214 preview were closed; the user's52212 preview remains unchanged.

## Scoped inventory navigation — subsequent increment

The map now offers an approved Site selector, a literal device-name search across the full permitted inventory, Previous devices / More devices and Clear view. The existing GET accepts map_site_id, map_search and map_device_cursor only on the map tab. Search is bounded to 200 Unicode characters and escapes SQL wildcard characters. The canonical base64url cursor is bounded to 128 characters and carries only a positive ID and direction. No cursor grants authority. Malformed map input returns a generic 400; an inaccessible Site returns 404; other tabs ignore map parameters.

The existing canonical query is scoped before counting and bounded ID-ordered seek paging. Relationships still require both visible endpoints. Every navigation reevaluates current access. An exhausted position resets visibly to the first permitted page. A short backward page retains its exact position in history links. Coverage also checks known preceding/following pages rather than trusting a separately calculated count during concurrent changes. Site ownership ambiguity forces limited coverage and cannot expose another Site's snapshots.

Controls remain usable when no devices match. Applying a new view replaces local search and inspection. Full-inventory search is separate from “Find within this view,” which selects a displayed node and brings it into readable focus.

Verification to date:
- Independent backend review found no outstanding blocking source findings after cursor-position and coverage corrections. Focused query/validation checks passed 21 tests with 236 assertions.
- Final frontend checks passed 37 tests across six files, including unchanged exported PHP inventory DTOs with 205 visible devices. Scoped lint and final full TypeScript verification passed after a test-only selector typing correction.
- Nine real Inertia GET navigations used those actual PHP projections for paging, Site selection, literal name search beyond device 200, empty recovery and exhausted-position reset. Exact second-page relationships and retained history context passed. Large-graph search and keyboard focus became readable. Light, dark and 390px screenshots were inspected; no browser application errors or horizontal overflow were observed.
- The first full application run passed 24 tests and failed one new test during fixture setup: two active assignments violated the canonical schema constraint before any HTTP assertion. The fixture was corrected to supported Site assignment plus approved Asset link provenance and passed independent review. The corrected sole follow-up 5418 passed 25 application tests with 405 assertions (649.47s), then reached terminal exit 0 and verified isolated process/database cleanup.

This increment does not provide whole-network paths across device pages, operational hardware evidence, signed installation or full programme completion.


## Current connection exploration — subsequent increment

Operators can select a canonical device and choose Explore current connections. The view keeps that device at the centre, shows up to 40 currently visible connected devices per page and lets the operator select another device to continue through its connections. This reaches connections outside the ordinary 100-device inventory page. Site map returns to the approved Site inventory; Refresh connections returns to the first current connection page. Current exploration does not mix with historical snapshots or inventory search.

The existing map GET accepts strictly validated map_anchor_id and map_neighbor_cursor. The anchor must resolve unambiguously to a currently approved Site. Candidate endpoints are filtered through current permissions and canonical same-Site ownership before counting and paging. The query examines at most 1,000 distinct candidate neighbours from the bounded current sources. Exceeding that budget returns the verified Site map with a generic unavailable message. It does not disclose candidate identifiers or counts. The view always states its limited coverage.

The latest completed Site/source snapshots remain authoritative. Manual active canonical relationships are used only when no runtime snapshot exists; an empty runtime snapshot does not trigger manual stitching. Each displayed neighbour retains at least one exact anchor-incident edge before additional parallel edges fill the 500-edge display budget. Source and edge truncation are explicit. No neighbour-to-neighbour connection or whole-network path is fabricated.

Independent backend review found no remaining blocker. Focused backend checks passed 27 tests with 283 assertions. Frontend checks passed 41 tests across seven files using the unchanged exported PHP projections. Eleven actual Inertia GET navigations in the browser verified onward exploration outside the first inventory page, 40-neighbour paging, reset/refresh, return to inventory and empty runtime evidence. Mouse selection and real keyboard Tab focus both work at an overview scale. Light, dark and 390px layouts were inspected. Scoped lint and full TypeScript passed. The sole application regression passed 28 tests with 479 assertions (386.84s), reached terminal exit 0 and confirmed isolated process/database cleanup. Final frontend visual corrections and their verification are recorded in CHECKPOINT.md.

This is bounded current-evidence exploration. Whole-network multi-hop paths, live capture refresh, representative workload/hardware acceptance, signed installation and the full programme release remain unfinished.
