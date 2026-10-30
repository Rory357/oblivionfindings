# Company Hub event dropdown correction — 16 October 2026

The user identified native-looking dropdowns after the event calendar repair. Audit found eight native select blocks in EventPageEditor (nine field locations because Site and Department share one loop), and two in EventHubFields.

Replaced all eleven with the existing HubSelect component: Site, Department, audience, Team, reminder delivery, Host, Category, Repeats, Publication, wizard Event host and Related company story. Existing HubSelect preserves empty values via its prefixed internal option values; canonical organiser/story values still map to null, and one-event recurrence still saves as null through the existing transform. Saved custom repeat patterns, occurrence locks, busy state and conditional email availability are retained. No new selector component or storage/permission model was added.

The rest of the Company Hub already uses styled HubSelect/HubBrowseSelect/HubSearchSelect. The calendar wizard's other four selectors already use the shared styled SelectInput. No production native `<select>` elements remain in the Company Hub page directory or shared HR calendar components. Radix's inaccessible hidden form selects are expected and are not visible browser dropdowns.

## Checks

- 25 interaction cases passed across event-wizard-dialog.test.tsx and event-hub-fields.test.tsx, actual exit 0. Includes styled host selection/reset, category/recurrence save, related-story linking/unlinking and scheduling via the new Publication menu followed by the calendar/clock. Log: company-hub-event-dropdown-tests-final-20261016.log.
- First test run exposed only test issues: canonical recurrence normalises to null, and the test needed to wait for select-close focus restoration before opening a calendar. Both repaired. Type checking then caught unsupported `exact` options in Testing Library role queries; removed those ignored options, leaving default exact string matching.
- Scoped lint and final TypeScript check passed (including both test files). Temporary scoped config removed. No database changes.
- Local build 89765 finished with actual exit 0 in 5m 51s. Fresh temporary browser tab 21 served app-BG0LE09o.js from the expected Herd host.
- Browser verified the styled repeat menu, Weekly selection and reset to One event, Category options and Escape focus restoration, audience selection revealing Team, the Team menu, the related-story empty choice, and Publication switching to Schedule for later with Rory date/time controls. The current local story list contained no published stories; nonempty story linking/unlinking is covered by the interaction test.
- No horizontal document overflow, no visible native selects in the inspected publication form, and no console errors. Source audit confirms all production selectors in the scoped directories are styled. Screenshot: evidence/event-styled-dropdowns-20261016.png.
- Cancelled the temporary verification form and closed only tab 21; no event was saved or published. Original user tabs 1, 5 and 19 remain untouched. No database, theme, commit, push or deployment changes.
