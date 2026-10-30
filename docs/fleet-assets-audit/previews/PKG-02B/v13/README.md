# PKG-02B v13 — consistent evidence gates

Review preview: http://127.0.0.1:4348/PKG-02B/v13/#/fleet-assets/vehicles/14/compliance

Bounded corrections for Main review findings B01–B03. Applicable unresolved compliance now prevents Ready status, automatic booking confirmation, explicit approval, checkout and release. The observation itself remains savable with optional evidence/date. A source-backed Not applicable assessment remains supported.

Recorded RUC coverage uses the retained dashboard observation in all these decisions. A larger checkout observation is checked before checkout; it is identified separately in the error. Tracker estimates remain distinct in Mileage and planning; they do not silently replace the observed dashboard reading. The existing strict-greater upper-bound comparison is retained. This mockup does not establish legal applicability or operating thresholds.

The general calendar booking action defaults to a valid future time against the fixed synthetic clock (22 September 2026, 09:30 Pacific/Auckland). Time validation returns to Vehicle & times and focuses the date/time needing correction. Existing booking edits may retain a historical pickup; new past bookings remain blocked.

The inherited v12 document expiry/reminder flows, v11 collection views, calendar, trip exports and earlier features remain. Earlier versions are frozen. Operational records reset on reload; local catalogue and layout preferences persist.

## Review and limits

Synthetic design candidate for Main review, before exact user design approval. No implementation or integration release. No production/backend/schema/design-guide changes, real vehicle decisions, notifications, payments, tracker actions or activation.

See `docs/fleet-assets-audit/evidence/PKG-02B/v13/AUDIT.md` for directly observed workflows and limitations. Main's broader v12 scope/contract review and Designer handoff revision 4 remain authoritative; these three corrections do not release adjacent packages.

## Run

`C:/Users/steph/.hermes/node/node.exe --use-system-ca docs/fleet-assets-audit/previews/PKG-02B/v13/serve.mjs`

The local server binds to 127.0.0.1:4348 and serves this version's dist. GET/HEAD only; application APIs are disabled. Existing bounded map-image proxy is unchanged.
