# PKG-08 Settings implementation candidate

Owner: existing Settings designer, GPT-6 Astra / xhigh. Date: 28 September 2026.

This implements the user-approved v6 Settings workspace in an isolated application checkout. It is a technical-review candidate, not an operating deployment or all-location correctness sign-off. Main's revision 2 implementation release records the explicit “please implement now” approval; no repeat mockup approval is needed.

## Exact review boundary

- Checkout: `C:/Users/steph/.codex/worktrees/pkg08-settings-implementation/oblivionfindings`.
- Branch: `codex/pkg08-settings-implementation`.
- Base: `926b4981b0289da08a20baca0995117fb53e413e`. Actual Main HEAD was reconciled before this packet was prepared; see `source-manifest.json` for the final check and source hashes.
- Actual application preview: `http://127.0.0.1:8794/fleet-assets/settings#notifications`, served by this checkout with its own Vite server on 5194 and a synthetic test database. Old previews 8788–8793 remain separate and intact.
- The final commit SHA is supplied in the consolidated Main handoff. `source-manifest.json` covers every changed application and test source file without a circular self-hash.
- No Main source write, merge, push, operating migration, live notification, Google request, key/billing change or device command was performed.

## Implemented behaviour

**Notifications:** real per-user persistence in the existing `UserNotificationPreference` table, effective role defaults, independent in-app/email overrides, versioned saves, atomic audit, conflict merge, browser draft recovery, guarded navigation, review/discard/default reset and undo. Event rows have shared kebab/right-click actions and draft-aware sample viewers. Required Control Room response and source permissions remain independent. Existing Application settings reads/writes remain compatible and Fleet changes written there enter the same history.

The four supported optional event families are booking approval/decline, scheduled maintenance, handover transitions and the actor's import results. Existing booking/maintenance notifications now read these choices. Minimal new handover/import copies use committed canonical transitions and re-check current source access and preferences when the queue delivers them. They contain no handover notes, import rows, personal locations or coordinates. Optional queue dispatch failure is reported without rolling back the source action. This is not a new durable event outbox or a guarantee of delivery.

**Delivery checks:** a saved-preference dry run with an explicit synthetic-input label, captured revision and the actor's last five checks. It never sends, replays an event, verifies a real recipient, claims provider acceptance or claims acknowledgement. The v6 mockup's simulated acceptance/failure choices are deliberately not presented as operating delivery evidence. Mail transport presence is shown separately from verified delivery; log/array mailers are not labelled external email.

**Maps:** OSM default; optional Google display, Places (New), Geocoding and Routes, stored in the existing `AppSetting` owner. Google configuration requires the existing `fleet.settings.manage` permission, review, separate browser/server credential presence and optimistic revision matching. Secrets cannot be entered through the form or returned in JSON/audit. Configuration drafts have recovery and conflict merge. Browser-key rotation invalidates the revision; a loaded SDK requires a reload after a configuration/authentication failure instead of injecting a second SDK.

The shared `LeafletMap` provider boundary supports Google rendering of already-authorised application markers, circles, polygons and trip lines, including missing-evidence dashes and endpoints. It preserves the viewport on fallback. Google-only search/route results are kept separate from application overlays and cleared on provider failure, revision change, input change, tab change or unmount. Server requests accept explicitly entered input, use minimal field masks, bounded results, timeouts, throttling and quota backoff. No provider-content cache is created. Provider attributions and supplied safe attribution links are retained.

**Tracking & data:** current source configuration values, contact-versus-fix explanation, explicit freshness/unknown/withheld meanings, permitted canonical tracker metadata and handoffs to existing device controls. The directory never returns personal positions or assignment identities. Device model/firmware/profile/command authority remains with Security & Devices; Fleet retains operational interpretation, Client/People owns personal tracking authority, and Control Room owns response. No new threshold, retention policy, profile store or batch-command engine was added.

**Setup and history:** canonical vehicle, asset, Site/room, maintenance, import and boundary workspaces; no duplicate importer or records. Change history shows only the actor's preference changes and, for map managers, shared map configuration saves. Existing import correction and accepted-row preservation remain in the importer.

## Provider coverage and explicit limits

The shared renderer is used by Fleet vehicle location/trip views, Fleet map/dashboard/device views, transport/outing/asset maps, existing resident-tracking/portal maps, shared Security & Devices tracking and Site overview maps. Only the projections already supplied by those source screens are rendered. Their source selection, consent, assignment and visibility rules were not replaced.

Independent map canvases/editors, including the PKG-07 boundary editor and separate Client Location maps, retain their existing renderer and geometry controls. Google is not claimed to replace every map in the application. Address search, reverse lookup and route estimation are available through the explicit Settings map explorer; source workflows have not acquired automatic address enrichment or new route-calculation writes. Live Google billing, quota, browser-key restrictions, current SDK/CSP behaviour and provider availability still require an authorised deployment check with real credentials. No such check was performed.

Google reverse geocoding is now disabled in automatic telemetry ingestion: that path returns no new Google-derived address and does not persist third-party content outside its map context. Explicit Google lookup is handled by the new capability endpoint. Existing Nominatim behaviour remains separately configured; its cache namespace now includes the provider/endpoint. Historical stored addresses and their provenance are not repaired by this change. Main should review this prospective behaviour change explicitly before integration.

**MAIN-TELEM-01 remains open:** the published Queclink normaliser treats HDOP as metre accuracy. The Settings guidance distinguishes dilution of precision from metre accuracy but does not correct the adapter, invent a conversion or rewrite historical evidence. No cross-location accuracy sign-off is claimed.

**People Locations collision:** `resources/views/app.blade.php` was not changed or imported from the unpublished sibling candidate. Main's release revision 2 records the isolated branding repair at `419e4c890` and outstanding genuine 125% verification with that owner. This candidate uses the current published shell and keeps that custody boundary.

## Data, permissions and deployment

- Migration `2026_09_28_000100_add_notification_channel_overrides.php` adds nullable JSON to the existing user preference table. Null preserves legacy full-row meaning; explicit masks permit channel inheritance. Rollback drops only the new field and retains the existing effective channel columns.
- Existing permissions are reused: Fleet/Assets workspace visibility, `fleet.settings.manage`, `settings.access.manage`, canonical device visibility and each source policy. No permission grant or new role/tenant boundary is introduced.
- Browser display key remains `GOOGLE_MAPS_API_KEY` through the existing config owner. Server APIs use a separate `GOOGLE_MAPS_SERVER_API_KEY`. Google remains disabled by default; browser/server keys and provider settings must be deployed deliberately. The optional CSP additions preserve nonce-based scripts and do not introduce unsafe script execution.
- Queue workers must receive the new code before optional source notifications are used. Mail transport/queue health and provider restrictions are deployment concerns, not inferred from a successful settings save.
- Operating migration, permission rollout, provider/device activation and final acceptance remain outside this isolated candidate. Main technical review and its serial integration slot are still required.

The optional browser fixture harness requires `PKG08_DB_USERNAME` and `PKG08_DB_PASSWORD` supplied locally for a disposable MySQL test database. It generates its own application key. Its generated environment and fixture files are excluded from Git; do not rerun bootstrap against the retained review preview. The browser fixture account is synthetic and does not provide access to operating records.

## Verification

- `settings-tests.xml`: 34 tests / 179 assertions, including durable actor-only changes, inheritance, stale revisions, required-response preservation, no-send checks, permission denial, missing/foreign source denial, map credential separation, paid-request backoff, private history, audit rollback, ingestion protection and existing handover mutation/Site-isolation regressions.
- `legacy-notification-tests.xml`: 17 existing tests / 60 assertions. The first run exposed an empty Eloquent collection merge error; conversion to a base collection corrected it and the rerun passed.
- `frontend-tests.json`: 13 tests covering conflict merge/reset inheritance, Google SDK single-load/authentication lifecycle, authorised overlays and cleanup, dashed route meaning, existing popup escaping and device UI contracts. Google SDK/provider responses are synthetic, with no live paid request.
- `eslint.json`: scoped lint including the shared renderer and navigation. `typecheck.txt` and `build.txt` record final full-project compilation after route generation; generation and TypeScript are sequential to avoid reading transiently deleted generated route files.
- `browser-verification.json` and `browser/`: owner-run real browser observations/screenshots against the isolated Laravel/Inertia application, separate from the frozen synthetic v6 tests. They cover persistence, filters, shared row actions, preview focus/Escape, conflict/recovery, dry runs, nested guards, source handoffs, history and short-desktop light/dark layouts. The existing header `overflow-clip!` treatment prevents rail focus from scrolling away the title.
- `device-scope-check.json` records a separate synthetic two-Site check: only the permitted tracker is returned and its projection has no positions or assignment identities. The filled metadata dialog and source handoff were exercised; the source correctly withheld telemetry from the fixture role.
- A pre-existing user-menu Settings link targets `/profile`, while the published route is `/settings/profile`; it returned 404 in this fixture. This unrelated menu link was not changed. The Settings workspace and canonical importer handoff were verified independently.
- `preserved-previews.json`: comparison of v1–v5 against v6's saved preservation snapshot, plus all 96 v6 manifest entries and the approved manifest/bundle hashes. Read-only Rory guide hashes are included in `source-manifest.json`.

## Current primary provider references

- [Google API security](https://developers.google.com/maps/api-security-best-practices), [Maps JavaScript keys](https://developers.google.com/maps/documentation/javascript/get-api-key) and [CSP](https://developers.google.com/maps/documentation/javascript/content-security-policy).
- [Maps JavaScript loading](https://developers.google.com/maps/documentation/javascript/load-maps-js-api) and [deprecations](https://developers.google.com/maps/deprecations). The implementation uses its own overlays and does not depend on the removed Drawing library or deprecated Marker class.
- [Places Text Search](https://developers.google.com/maps/documentation/places/web-service/text-search), [Routes requests](https://developers.google.com/maps/documentation/routes/compute_route_directions) and [Geocoding policies](https://developers.google.com/maps/documentation/geocoding/policies). Provider content is temporary, attributed and kept in its Google map context.
