# Workforce header consistency — 8 October 2026

Status: the header/navigation unit is implemented and verified on main. The wider Workforce programme remains active.

## User-reported issues
- Workforce settings also opens Operations and highlights its Dashboard.
- Workforce pages use different banners, filter control sizes and spacing; changing tabs can change the header size.

## Implementation
- Workforce settings and the standalone Availability route now belong to Workforce in sidebar matching. The generic Operations Dashboard cannot match Workforce URLs even when its group is manually opened.
- Workforce pages compose the existing PageHeader through one small WorkforcePageHeader adapter. The existing header owns the band, meters, rail and tokens. Desktop uses the approved compact controls; the existing mobile hook enables the larger phone controls. Other modules keep their current header anatomy; the scrollbar gutter is shared page layout.
- Shifts, Job Board, Rostering, Handovers, Shift Notes, Timesheets, Attendance, Conflict Queue and Workforce settings use the same adapter. Empty filter slots retain the same row space.
- Job Board replaces the old PageHero, greeting and unconditional live/compliance claims with the shared header. Its existing scope tabs move into the connected rail, retaining their test selectors and permission gating. Search, week selection, filters, alert action and counts remain connected.
- Week controls use filter buttons. Searchable people filters retain their choices and clearing behaviour in a compact frame. Record summaries, loading feedback and attendance warnings remain visible below the header.
- Timesheets now has the same Home breadcrumb spacing. A shared stable scrollbar gutter prevents page widths changing between short and long views. Workforce phone summaries use two columns; Job Board cards and Conflict Queue shrink within narrow screens.
- Template library commands preserve the viewed roster/standalone week when returning after a command. This is separate from the header commit.

## Verification
- Sidebar: 58 tests passed, including both actual sidebar opening and direct matching.
- Header-related UI: 45 tests passed across seven selected suites. A further 36 template tests pass separately (81 combined); the template feature is not part of this header commit. The first 80-case attempt had one obsolete DOM-parent assertion for the Job Board meter; it was changed to assert the labelled meter itself. Raw results are retained.
- Full TypeScript and scoped lint passed after the two compile errors introduced during editing were corrected. Initial logs are retained.
- Production build passed in5m44s, asset `app-DOUxPGnu.js`; the existing chunk-size advisory remains. The user explicitly authorized the seeded development login; Demo Admin successfully signed in at isolated port8768.
- Actual browser: nine pages at1440/1280/390/320px (36 views), all without horizontal overflow. At both desktop widths every band is250.5px high and starts at x248.5/y93; widths are1159/999px respectively. Phone layouts adapt to their controls rather than fixing a height that clips content. Screenshots and geometry are retained under output/playwright/workforce-headers-final-20261008 and test-results/workforce-header-browser-final-second-20261008.json.
- All51 header tabs, including overflow-menu entries, retain the same desktop geometry after activation. A second completed pass waits for network-idle: all51 settled states retain height250.5px, width999px and x248.5/y93, with no overflow. Mobile Settings More entries measure44px, preserve preference fields and keyboard focus.
- Actual sidebar: collapse Operations, follow Workforce settings, then manually expand Operations. Workforce opens and Settings is current; Operations stays collapsed until manually opened, and Dashboard has no current-page marker. Saved user expansion choices remain respected.
- The first full browser loop stopped after30 captures with Chromium ERR_NO_BUFFER_SPACE. The visible reload action recovered it; a second loop loaded each page once and resized it, completing all36 views. The failed log remains retained. The first settled-tab attempt met the same resource error on its initial page; the visible Reload action recovered it and the second settled pass completed.
- Template backend/UI and the earlier five roster checkpoints remain separate from this delivery. This report does not certify the broader programme or the pre-existing Job Board posting gap.

## Existing functional gap discovered
Job Board's Post position button already had no callback. The backend accepts a selected Shift, but posting permission does not grant access to the Shifts list. A proper scoped Shift selection and posting flow remains outstanding; do not connect this button to an inaccessible blanket Shifts link or silently use the different replacement-request permissions.

## Boundaries
Control Room remains independent. No eMAR-specific files, permission grants, database accounts or policies changed for this UI work. The unrelated FleetRealtimePrivacyTest change is excluded. The roster template native corrective run is owned by the roster agent and explicitly excludes concurrent frontend/document changes from its backend freeze.

## Main verification

The22-file header/navigation change is integrated as18fa2dea29181c4f32047ac110b0058007951ced, independently of the five earlier roster checkpoints and pending template library. Main passes103 focused UI cases, the full TypeScript check, and its production build (9m40s, app-cb_PkdU0.js; existing chunk-size advisory). Initial commands could not start because this checkout had empty dependency directories; matching locked development dependencies were connected and main application autoload resolution verified before delivery. The original failed startup logs are retained. Browser acceptance above used the isolated integration preview; this is not a claim that the hosted site has deployed the commit.
