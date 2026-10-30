# PKG-02B v9 — journey timeline views

Open http://127.0.0.1:4344/PKG-02B/v9/#/fleet-assets/vehicles/14/trips

Journey timeline defaults to a compact List view, with Card and Table alternatives. The chosen view is retained for the browser session. Each view shares event filtering and selected-event map focus. List and Table show detailed evidence and coordinates for the selected event below the rows; Cards show details inline. Unknown event times remain explicitly unavailable.

Synthetic, isolated mockup. Main has not been notified. No production changes or external integration. v8 and earlier candidates remain frozen. The v7 audit and pending handoff remain applicable.

## Restart

`C:/Users/steph/.hermes/node/node.exe --use-system-ca docs/fleet-assets-audit/previews/PKG-02B/v9/serve.mjs`

Localhost port 4344. GET/HEAD only; no application APIs. The bounded report-image proxy uses public OSM tiles for synthetic Wellington routes. TLS remains verified.

## Verification

TypeScript and Vite build pass. Browser checks cover all three views, map selection from each, shared filtering/selection, empty-state recovery, session view persistence, and switching to the partial trip. Desktop list rows measure 57px. At 760px the table fits; at 540px the list wraps without page overflow. No new browser console errors. Earlier workflows and exports are inherited and were not exhaustively retested in this layout revision.
