# PKG-05 v6 — Transport overview and consistent navigation

Exact local design review candidate. This is a synthetic desktop preview, not an application implementation. User-requested corrections continue in the same pinned GPT-6 Astra/xhigh task and isolated checkout. No production record, source route, shared component, schema, guide or sibling package was edited.

Preview: http://127.0.0.1:4400/#/fleet-assets/transports/overview

## Current correction

The user asked for a visual Overview with graphs that makes the operational situation immediately understandable, and repeated that all second-row tabs must follow the shared navigation rules. Their screenshot was still showing frozen v4 on port 4398. v5 was frozen before this new correction; v6 preserves all earlier candidates and serves a new port.

The Overview now has:

- A stage donut derived from the currently permitted, searched, site/date-scoped request set. Each request appears once. Labelled stage buttons open the correct queue with the same search, site and date.
- A grouped chart of scheduled departures and expected returns by local hour. Counts include unallocated demand and explicitly exclude cancelled/unfulfilled demand. Planned movement is not presented as actual travel, an on-time rate or a historical trend.
- Exact chart values available through keyboard-accessible controls. Selecting an hour opens matching records; urgent rows open the existing quick-view modal.
- Priority summaries, named next owners, an attention list and a departure board which distinguishes confirmed, proposed and unallocated plans.
- Scoped search, honest empty/error/denied/stale states and a PDF summary of the values, scope and accountable actions. This is a textual/tabular summary; it does not claim to reproduce the graph artwork.

Both navigation tiers are now consistent across Overview, Requests & approvals, Planner, Calendar, Journeys and Returns & handovers:

- The unchanged shared PageHeaderRail carries the same six main Transport views, including on Calendar.
- The unchanged shared TierTwoTabs component carries every secondary row: bare page ground, no card/border/shadow/padding, neutral inactive chips, positional active tones, icon chips, underline and arrow-key navigation. Planner's All/Ready/Planned strip moved out of its sidebar card into this shared second row. Calendar's Month/Week/Day/Agenda/Timeline views moved into the shared second row while preserving primary workspace navigation.
- Headings use text-section-title directly below the hero at the standard 20px gap. Returns help remains collapsed below navigation. Duplicate result bars are removed.

The user's explicit request for second-row tabs supersedes the generic index-header instruction to place all view controls inside the hero. NAVIGATION_STYLE_GUIDE Rule 2 governs these requested rows; no protected guide was changed and no custom tab styling was introduced.

## Complete candidate retained from earlier corrections

The visual planner guides the user through Journey & time → Vehicle & team → Review & save, with visible resource cards, live plan summary, source availability checks, conflict reasons and keep/discard draft recovery. Saving retains one Fleet booking identity and its source approval state. Changes appear in the same synthetic calendar.

Requests, journeys and vehicle custody retain separate records, statuses, history and next actions. Searchable return quick views open the full record on demand. Site key arrangements show collection/delivery/return storage points; physical receipt and later key storage are distinct. Vehicle trip history uses explicit synthetic leg links and separately checks its source access. Overview graphs consume these records without adding a lifecycle or calendar engine.

## Canonical reuse and ownership

- PageHeader, PageHeaderRail, grouped-profile-nav/TierTwoTabs, PageLayout/PageContent, EntityTable, EntityContextMenu and WizardShell are imported from existing source without edits.
- Calendar imports Site Calendar's Month, Week, Day, Agenda, Timeline, source pills, JumpToDate, TodayRail and context menu. Its entries derive from the one linked Fleet booking per transport request.
- Chart rendering uses the repository's existing Recharts dependency and CSS tokens. Stage data and PDF summaries derive from permitted synthetic records only.
- ClientTransportBooking and ResidentTransportJourneyService retain passenger demand/journey responsibility; FleetVehicleBooking retains reservation/readiness/approval; FleetKeyLog/SiteRoom retain custody/location ownership; vehicle trip history and Maintenance remain linked source concerns. Medication handoff only presents minimum permitted source status.
- Single operating organisation: roles, approved sites, canonical record ownership and source privacy are the boundary. No tenant mechanism added.

## Verification

168 browser assertions cover chart scope, drilldowns, accessible exact values, urgency, quick views, PDF downloads, search, permissions, stale/error/denied data, shared secondary navigation on all six views, keyboard focus, 20px heading placement, all five calendar views and the visual builder's normal/recovery paths.

93 synthetic domain assertions cover base workflow (34), custody (25), planning (22) and chart/export calculations (12). Three local server checks pass. Scoped/imported TypeScript diagnostics are zero. Three real PDF downloads were smoke-tested; no new renderer layout audit is claimed. Successful chart verification reported no browser errors or chart sizing warnings.

Desktop widths 1024, 1280, 1440 and 1920 were checked. No page overflow occurred in the checked Overview, builder and calendar layouts. Actual browser zoom remains unverified. Screenshots prefixed final- capture the final candidate; review- images are intermediate evidence.

420 previously frozen files across v1–v5 and audit-v1 are verified unchanged. Runtime, exact source inputs, references, screenshots and tests are recorded in the manifest. No whole-application CI, backend/security certification or live deployment claim is made.

## Remaining production contracts and honest limits

- All actions use browser-memory synthetic state. Reload or role/access changes clear drafts. No production database, external notification, actual upload, tracking or clinical action runs.
- Resource eligibility, equipment identity, buffers/holds, staff custody permissions, site key-storage mappings and passenger-to-vehicle trip links need approved production contracts. This candidate does not grant those permissions or invent the final policy.
- Graph scope follows the selected request date (scheduled departure date); it is not a complete movement ledger for journeys spanning several dates. All-dates hour charts aggregate selected requests by local hour. No trend comparison or on-time rate is inferred from the one-day fixture.
- Calendar Today/TodayRail use the frozen example 28 September 2026; the reused grid's browser-today highlight follows the actual browser clock. Short timed entries retain the source calendar's compact clipping, with complete information in quick view/agenda.
- The design brief's exact approval gate remains in force before any production implementation. This work only presents the corrected mockup for review.
