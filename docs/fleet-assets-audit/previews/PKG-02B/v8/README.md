# PKG-02B v8 — compact trip filters

Open http://127.0.0.1:4343/PKG-02B/v8/#/fleet-assets/vehicles/14/trips

Search, date presets, driver, event type and export now share one compact filter bar. Custom date range reveals the canonical From/To pickers only when selected; switching to a date preset clears the custom bounds. Reset clears every trip filter.

This isolated synthetic mockup inherits the v7 workflows. Production application unchanged. Main has not been notified. Earlier candidates remain frozen. See the v7 GAP-AUDIT.md and PENDING-MAIN-HANDOFF.md in the evidence directory for the broader audit and deferred integration work.

## Restart

From the repository root:

`C:/Users/steph/.hermes/node/node.exe --use-system-ca docs/fleet-assets-audit/previews/PKG-02B/v8/serve.mjs`

The included build serves on 127.0.0.1:4343 with GET/HEAD only and no application API access. The bounded report-image proxy uses public OSM tiles for synthetic Wellington routes. TLS verification remains enabled.

Catalogue choices persist in local storage on this preview origin. Operational records and uploads reset on reload. Trip filters use session storage. No actual Finance, Control Room, geocoding or speed-limit service is connected.

## Verification

TypeScript and Vite build pass. Browser verification covers the compact layout at the current window and 1060px, custom 21–22 September range (two trips), switching to 20 September (one trip; range hidden), Jamie search (one trip), and reset (three trips). No horizontal overflow at 1060px and no new browser console errors. Report generation is unchanged; v6 records its earlier checks and limitations.
