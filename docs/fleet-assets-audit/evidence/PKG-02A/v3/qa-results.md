# PKG-02A v3 browser review

2026-09-21 NZST. Isolated static preview at 127.0.0.1:4334, identity PKG-02A-v3-2b9f, baseline 2302ca3. Synthetic local fixtures only.

## Completed checks

- Isolated TypeScript no-emit check and Vite build pass. Final build: 2459 modules, index-ZbjsAw3h.js and index-C_FCh2DF.css. The standalone >500 kB bundle advisory remains; this is not a production performance result.
- Forty review-state checks at 1280×800 and 1440×900: no horizontal document overflow, one access summary throughout, workspace absent in all ten blocked/missing/loading/failure states at each size. View-only has no Draw or Locate now; its map menu exposes inspection only.
- Actual right-click opens map actions at the selected boundary; Draw custom safe zone here opens Boundary first with its starting point. The visible Map actions button supplies the same keyboard-accessible choices; Escape restores its focus.
- Drew four polygon corners with pointer clicks and finished the boundary. Keyboard ArrowRight adjusted a corner and required re-completion. Circle handle drag changed radius from 70 to 112 illustrative units; Undo returned 70 and Redo returned 112. Completed a circle proposal with no linked place, name/purpose, Monday 09:00 AM–03:00 PM, 21–30 September, leaving-zone response and review. Saving showed the draft on the map/inspector and list as not monitoring; the operational weekly rows were unchanged.
- Actual DOM focus checks: dirty Cancel → Keep editing returns focus to Cancel and preserves entries; Cancel → Discard draft returns to Draw safe zone. Pristine Escape returns to Draw safe zone. See focus-checks.json.
- Locate now validates reason and preview identity. Acknowledgement-only leaves the 2:32 pm observation unchanged. Timeout and offline provide distinct feedback and retry. Closing and reopening retained the reason/identity; pending source action opens the request and Send is disabled. A received report showed 2:36 pm and appended alongside the original 2:32 pm history entry. Battery stayed sampled at 2:32 pm. Access withdrawal during the request removed the modal and entire workspace.
- Restored From/To calendars: 19–20 September showed three observations; Observations excluded plans and responses. A real 18 September range produced an empty result. From 20 September / To 18 September produced a validation message. Forced loading, error/retry, empty and access-ended examples were exercised; retry retained the selected dates. Planned events are explicitly distinct from recorded observations/responses.
- 640×400 profile metric reflow inspected visually and in DOM: all five card contents fit and no horizontal overflow. Evidence is reduced-viewport.json plus screenshot. This is a reduced viewport check, not an actual 200% zoom result.
- Final tab warning/error console capture is empty. Static server identity, no-store/CSP headers, prior-version preservation and baseline source hashes are recorded separately.

## Corrections during review

Moved geometry to the first wizard step, made linked place optional, added explicit completion and local undo/redo, and rendered inactive draft shapes. Corrected nested-dialog focus recovery and reduced-width metric layout. Preserved Locate request context across closing/reopening and kept battery samples independent of refreshed position timestamps. Restored the missing date-range history workflow and cleared protected state on withdrawal.

## Limits

Screenshots are actual viewport captures, not full-page exports. Circle/review/draft, initial history/acknowledgement and reduced-width captures precede the final battery-only copy correction; their rendered flows are unchanged. Final state matrix, context/polygon/focus, timeout/success and main workspace captures use the final build. Browser locator mismatches were resolved from fresh DOM; no application console errors were observed.

Actual 200% browser zoom and full keyboard/screen-reader acceptance remain unverified. No production test, genuine GPS/map, GIS topology acceptance, backend command delivery, privacy enforcement, schedule/DST/overlap semantics, offline synchronisation, notification delivery or operational alert acceptance is claimed. Existing outing/response behaviour was retained from v2; v2's full outing/alert journey was not repeated in this bounded v3 correction pass. Exact-version and implementation gates remain pending.
