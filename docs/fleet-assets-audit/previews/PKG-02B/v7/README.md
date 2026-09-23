# PKG-02B v7 — vehicle record, forms and journey review

Open http://127.0.0.1:4342/PKG-02B/v7/#/fleet-assets/vehicles/14/overview/details

Synthetic, isolated clickable mockup. Production application unchanged. Main has not been notified. v6 and earlier candidates remain frozen.

This revision adds persistent searchable catalogues with Add new; structured vehicle specifications; ownership/insurance/warranty uploads; a dedicated document library with versions, archive and renewal reminders; linked Finance records and evidence-based review requests; clearer record reviews; compliance file uploads; searchable trip-driver filtering; and location-aware journey events with map focus. The duplicate trip heading block is removed.

See `../../../evidence/PKG-02B/v7/GAP-AUDIT.md` for all gaps found and the form families audited. Finance requests stay in the mockup queue; approval, payment and accounting remain separate. The confirmed tracker remains GV500CG, with its power-only OBD capability boundary preserved.

## Restart

From the repository root:

`C:/Users/steph/.hermes/node/node.exe --use-system-ca docs/fleet-assets-audit/previews/PKG-02B/v7/serve.mjs`

The included build serves on 127.0.0.1:4342 with GET/HEAD only and no application API access. The bounded report-image proxy uses public OSM tiles for synthetic Wellington routes. TLS verification remains enabled.

Catalogue choices persist in browser local storage on this preview origin. Operational records, uploads, Finance requests and reminders reset on reload. Trip filters use session storage. No actual Finance, Control Room, geocoding or speed-limit service is connected.

## Verification

Targeted TypeScript/build and record workflow checks are recorded in the v7 evidence directory. Browser checks cover persistence through refresh, vehicle upload/save, document history and calendar linkage, Finance evidence reuse/queue, driver search and timeline-to-map interaction, with targeted role and layout checks. Source audit coverage is broader than browser workflow coverage. v6 records the unchanged PDF/Excel generator checks and native-download/rendering limitations.
