# PKG-02B v11 — readable record collections

Open http://127.0.0.1:4346/PKG-02B/v11/#/fleet-assets/vehicles/14/compliance

List and Cards are available for compliance requirements, service schedules, recent checks, checklist templates, document requirements and uploaded files, mileage readings, follow-ups and obligation reminders, open Maintenance work, service/work history and linked Finance records/review requests. Lists are the default. Each section remembers its choice locally; switching keeps the current filters and selected mileage detail.

The collections compose the existing EntityCard, EntityTable and EntityMenu components. Their kebab and right-click menus share permitted actions. Narrow lists stack labelled fields without dropping status, due dates, source or evidence. The next-check requirement is a compact full-width strip; document empty states include an upload action. Responsive fixes cover collection toolbars, profile summaries and speed-limit date/time fields.

Maps, calendar views, live-state metrics and charts retain their specialised layouts. The existing trip timeline still offers List, Card and Table. Those views communicate location, time or trends more clearly than generic record cards.

Synthetic isolated mockup only. Main has not been notified. v10 and earlier candidates remain frozen. No application or production changes, provider messages or live integrations.

## Restart

`C:/Users/steph/.hermes/node/node.exe --use-system-ca docs/fleet-assets-audit/previews/PKG-02B/v11/serve.mjs`

Localhost port 4346. GET/HEAD only; no application APIs. The bounded report-image proxy uses public OSM tiles for synthetic Wellington routes with TLS verification. Operational records/files reset on reload. Catalogue choices and layout preferences remain local to this preview origin.

## Verification

TypeScript and Vite pass, with existing Leaflet import and large-chunk warnings. Browser review covers all vehicle tabs, all five calendar modes, work-record sections, driving subviews, list/card switches, retained filters/details, shared actions, view-only access, empty states and narrow record layouts. A synthetic evidence record appears in both document views and returns to its source. A reminder created from a calendar date appears in both layouts and links to that date. Native file-chooser automation timed out in this browser; the existing built-in synthetic evidence path was used for populated-library verification. Unchanged production integrations and PDF/Excel export internals were not rerun.
