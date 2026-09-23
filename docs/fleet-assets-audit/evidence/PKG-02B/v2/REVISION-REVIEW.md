# PKG-02B v2 review record

Status: visual verification only; **workflow audit confirms v2 is incomplete**. See [complete tab audit](workflow-audit/AUDIT.md). Not approved, not forwarded to Main, and not an implementation release.

Worktree: 5b0a. Baseline: 5307692ec59be84f3503c06354419b7da95be805. Preview: http://127.0.0.1:4337/PKG-02B/v2/

## Changes checked

Calendar uses the unchanged canonical calendar view components and approved header primitives. Browser verification covered all five views, next/previous navigation, date jump to 8 October, Today returning to 21 September, search with no results and reset. The September month renders the estimate on 24 and 25 September, with no entry on 26 September. Agenda wording distinguishes Active restriction, Advisory dates, Internal plan and Busy only. The busy dialog exposes no requester, passenger, destination or purpose. Closing a timed-entry dialog returns keyboard focus to that entry; keyboard activation and Tab containment were exercised.

Map now renders at a measured 470px height, with 12 loaded image tiles in the final light-state inspection. Tile-pane filter is grayscale(1); the observation overlay retains var(--primary). Site visibility, observation-trail visibility, zoom, reset, no-tracker and simulated basemap failure were exercised. The trail fits within the initial vehicle/site extent. Dark mode was checked separately. Markers, route and coordinates are synthetic Wellington examples; no live telemetry is queried.

Dialogs were measured at 480px for a simple detail, 720px for evidence upload and 1100px for structured details/forms. Original check sections (Check record, Source & timing, Follow-up), Escape dismissal, origin focus restoration and scroll-contained footer were checked. The check wizard permits jumping directly to Review; submitting missing answers returns to Record answers. Pristine cancellation closes directly. A changed observation time triggers the discard guard, and Keep editing preserves it. Cancelling a pending date leaves the original date unchanged; manual minute entry preserves 09:23 exactly. Completing the synthetic check retains its failed outcome and does not release the vehicle.

The maintenance form permits direct Review navigation and returns to Related work when that choice is missing. Selecting only 24 September gives an incomplete-range summary and focuses the date group on failed Continue. Adding 25 September announces both dates as included. Review and Back preserve that same human-readable interval. Date-cell geometry was inspected to confirm seven aligned columns. The standard evidence dialog uses the shared FileDropzone and the approved header/body/footer structure; upload persistence and storage remain simulated as documented in v1.

Desktop layout checked at 1280, 1440 and 1920 pixels; no document-level horizontal overflow in the measured calendar states. Dark calendar and map reviewed. Temporary viewport override was reset. Browser warning/error log was empty at the final check.

## Build and preservation

- TypeScript preview project: passed.
- Vite production build: passed, 2490 modules; existing large-chunk advisory remains (main bundle about 700 kB uncompressed).
- Final bundle: index-BWjVyX_S.js, index-CKLUY3cu.css, leaflet-src-CBnyToZo.js.
- Frozen v1 manifest verification: all 77 source/reference/evidence entries matched, zero mismatches.
- DESIGN.md, POPUP_STYLE_GUIDE.md and WORK_RECORD_STYLE_GUIDE.md hashes matched their recorded protected values.
- Tracked Git diff remains empty. All changes are isolated under the PKG-02B preview/evidence folders.

## Evidence and limits

Final representative screenshots: `12-map-final.png`, `13-map-dark.png`, `15-calendar-final.png`, `14-calendar-agenda.png`, `05-check-record.png`, `09-check-wizard.png`, `10-maintenance-range-summary.png`, `11-evidence-dialog.png`. `07-calendar-dark-1280.png` and `08-calendar-1920.png` record desktop checks. Earlier screenshots 01–04 show intermediate iterations and are not the final visual reference.

This is a synthetic read-only-source design preview. It does not implement live map providers beyond public basemap imagery, booking creation, production storage, permissions or authoritative workflow actions. Genuine browser zoom at 200% remains unverified; resized viewport checks are not represented as zoom validation. Shared map behaviour is retained, including fitting both visible markers; the action is therefore labelled Reset map view. Review here before any further communication to Main.
