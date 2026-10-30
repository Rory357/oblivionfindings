# OPS-PL01 — mockup alignment and mobile History correction

Successor to `419e4c890c84a322ec031cd07cc06f61acf80d39`, prepared 28 September 2026 in the existing implementation worktree. The user asked to continue aligning the implementation with frozen v5 and to include missed UI gaps. Four module source files change; shared branding and shared shell components are untouched.

## Result

The header now displays Clients / Staff / Both, the selected site, the evidence filter and sort order, plus the Cards / List selector on People. Site, evidence and sort choices use the existing searchable RecordPicker with the shared header trigger. Combined analytics cohorts retain their human-readable label. The current view is included in the breadcrumb, the workspace uses the people icon, and the permission-scope label remains legible on the coloured header. Controls are disabled while current access is checked.

Rory's shared header and EntityCard geometry and typography remain canonical. The desktop header measures 250.5 CSS pixels in this environment. Frozen v5's incidental header height and card-font differences are not copied into shared components. Current permissions, observation times, operational navigation and synthetic database records differ from the static prototype; this is visual alignment, not an assertion of identical pixels or data.

Responsive verification found two additional History gaps: a fixed 330-pixel timeline collapsed the adjacent map on mobile, and the outing action row overflowed the page. The map and timeline now stack below the desktop breakpoint, the report-window selector can shrink, and outing actions wrap. The refresh notice and source/day controls also wrap within the page.

## Verification

- Existing frontend selection/privacy/report-lifecycle tests: 16 passed across 3 files. Full TypeScript passed. Those runs preceded the final class-only contrast and mobile History corrections; scoped lint was rerun after them.
- Final production build passed in 4m 40s. The emitted People Locations asset contains all final layout and contrast corrections. Build emits the repository's existing large-chunk warning.
- Browser interaction checks cover Site search with Enter selection, site reset, battery sort, unavailable-position filtering, Cards / List with a local name search, combined cohort labels, and Escape returning focus to the filter trigger.
- Final inspected screenshots cover People at 1440 CSS pixels and the History map at 390. DOM bounds also verify People at 1280, Analytics at 1440, and the wrapped mobile outing actions. At 390, History document width falls from 410 to 378 pixels and the map grows from 1.6 to 299.8 pixels. Other resized screenshots timed out or retained stale composition; they are excluded. The browser JSON retains the labelled pre-fix observations and final results. No console errors occurred, and the normal viewport was restored. Query filtering is checked against rendered rows; the existing local search does not update the URL on each keystroke.
- Backend authorization, report generation and shared brand logic were not changed or rerun in this bounded successor. Prior backend and PDF verification remains historical evidence.

Source manifest: 46 paths; digest `d0fd136de600f8746a9e950b77e0aaf30cf94dd1b7ddf7ec128ff8a777098d79`. All 41 frozen v5 entries, six protected references and the Revision 10 master match their recorded hashes. The Page Header guide also remains unchanged.

## Review boundary

Main closed T01, T02, T03 and T05 on parent `419e4c89`. This visual successor requires renewed review. T04 remains the previously requested actual 125% browser-zoom check. Responsive viewport sizes and baseline devicePixelRatio 1.25 are not evidence of browser zoom. The existing manual setup question was not repeated and no browser-policy workaround was attempted.

The local synthetic preview was restored at `http://127.0.0.1:8776`. The user explicitly approved one sign-in code from the existing synthetic account secret; credentials and permissions were not changed. The preview remains local. No Main write, integration, permission grant, operational migration, provider change, guide edit or remote push occurred.
