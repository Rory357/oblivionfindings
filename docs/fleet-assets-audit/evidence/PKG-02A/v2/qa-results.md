# PKG-02A v2 browser review

2026-09-21 NZST. Actual browser viewports: 1280×800 and 1440×900. Isolated static server 127.0.0.1:4333, identity PKG-02A-v2-2b9f, baseline 2302ca3. Synthetic fixtures only.

## Completed verification

- Isolated TypeScript no-emit check passes using preview-local tsconfig. Vite static build passes (2457 modules); standalone bundle retains the >500 kB advisory, not a production performance claim.
- Forty review-state checks across both desktop sizes: no horizontal document overflow; exactly one current access summary; protected workspace removed for all ten denied/missing/loading/failed states at each size. Detailed observations saved in privacy-matrix.json.
- Five plan examples inspected: routine, outside active zones, arrival not confirmed, tracker unavailable and boundary uncertain. Unknown accuracy renders no accuracy area; the scenario selector prevents contradictory combinations with older/missing/unknown-accuracy review states.
- Weekday selection changes scheduled rows; each rule detail exposes canonical reference, effective dates and exception. New-zone wizard validates required fields and fewer than three polygon points. Search demonstrated no matching permitted records then selected the Gardens record. Circle/custom boundary selection and sample rectangle work.
- Schedule flow exercised Monday 22:00–06:00 next day, 21–30 September and 28 September exception using shared calendar/clock pickers. Proposed response is required. Existing rule review preserves the original fixture and creates an inactive draft. Simulated save failure retained entries; retry reached the draft-only confirmation.
- Outing flow exercised destination/worker search, travel context, three explicit date/times, review and local draft success. Dirty Cancel opened a discard dialog; Keep editing retained all values.
- Alert flow exercised unassigned → Jamie acknowledgement → required outcome validation → recorded outcome. Staff-response Activity filter retained response entries and excluded observations/plans. Acknowledgement did not rewrite the observed point.
- View-only role has no Add zone, rule-change or alert acknowledgement control. Denied state clears location workspace. Escape closed the pristine zone wizard and returned focus to Add agreed zone. Required fields and picker labels are exposed to accessibility APIs.
- Static server reports private no-store, connect-src none, form-action none and only GET/HEAD handling. HTTP identity verified for both v1 and v2. All 29 frozen v1 files rehashed without mismatch; original manifest hash unchanged.

## Corrections found during QA

Added required ReviewCard icons after the initial review step revealed a React render error; isolated typecheck now passes. Corrected inherited profile search callback to onOpen in v2 only. Gave access details and workspace distinct React keys after sequential state review exposed retained duplicate summaries; repeated the full 40-state matrix and confirmed one summary throughout. Corrected unknown accuracy rendering, refreshed observation age, stale-event timestamp and missing-arrival checkpoint time.

## Limits and evidence interpretation

Screenshots capture rendered viewports, not full-page export. Some screenshots were captured before the final metadata/transition corrections; affected flows were subsequently verified and their appearance is unchanged. Final daily captures and final console checks use the final static build. Browser automation locator mismatches were corrected from fresh DOM observations; they are not application failures.

Actual browser 200% zoom remains unverified (the IAB did not apply the v1 zoom keys). A reduced 640×400 viewport had no document overflow but revealed cramped/overlapping inherited profile metric labels; this is a known reflow limitation to resolve before accessibility acceptance. It is not a mobile deliverable or evidence of a passed 200% check. Core approved desktop widths render correctly. Full keyboard traversal, screen-reader acceptance, genuine GPS/maps, DST/overlap policy, geometry topology, backend/privacy enforcement, offline synchronisation and operational alerts remain unverified.

No production tests are claimed for an isolated mockup. Exact v2 and bounded implementation approval are pending.
