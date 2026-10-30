# Event editor spacing — 16 October 2026

The user reported excessive gaps below the Company Hub hero while creating an event. The visible stack was the general Hub action toolbar, canvas padding, a separate Back row, title/subtitle, then the editor section tabs.

## Change

- Hide the general Hub action toolbar during event and page creation workspaces. Browsing actions remain available on their original browse pages and the hero navigation remains intact.
- Put the labelled Back button beside the event title rather than above it; retain the same navigation and unsaved-change protection.
- Use 12px top padding for the event workspace, 16px between editor sections, and a tighter unsaved-change confirmation strip. The hero itself and its tab dimensions are unchanged.

## Verification

- Before: hero bottom 279px, editor heading top 381px, section tabs top 454.5px. This left 102px before the heading and 175.5px before the section tabs. Recorded in a fresh temporary tab without touching the user's unsaved form.
- Existing event editor suite: 20 tests passed, actual exit 0. Log: storage/logs/company-hub-event-spacing-tests-20261016.log. Scoped lint passed on both edited TSX files.
- Build 67078 finished with actual exit 0 in 6m 20s. Log: storage/logs/company-hub-event-spacing-build-20261016.log. Temporary tab 22 served app-BgqZMAXl.js from the correct Herd host.
- After: hero bottom unchanged at 279px; heading top 291px (12px gap, down from 102px); editor section tabs top 354px (75px after the hero, down from 175.5px). No horizontal document overflow or console errors.
- Browser checked the Back button with a temporary unsaved title: the compact confirmation appeared, Keep editing dismissed it while retaining the title, and resetting the title to empty restored Private event workspace. Closed only temporary tab 22; original tabs 1, 5 and 19 were untouched. No event was saved or published.
- After screenshots: evidence/event-spacing-after-20261016.png and evidence/event-spacing-unsaved-20261016.png.
- Before screenshot: evidence/event-spacing-before-20261016.png.
