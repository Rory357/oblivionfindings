# Additional audit round 3 — access, navigation and desktop consistency

Completed after v2 consistency, v3 workflow and v4 evidence rounds. The final candidate is v5 at port 8900.

## Findings corrected

- Read-only exception and borrower-departure shortcuts could bypass the disabled primary actions. A central preview mutation guard now covers every mutation entry point.
- Browser Back could change the visible scenario without restoring its editing authority. Scenario state and the URL now move together.
- Old Photos and Versions links lost their document scope after reload. They now resolve to the corresponding filters within Overview → Documents.
- Maintenance next-action and progress edits did not mark the draft dirty. Closing now offers retain/discard, and a failed save retains the draft for retry.
- Dialog handoffs from row menus could leave body pointer events locked. The originating menu closes before the dialog opens; closing restores usable focus. Saving blocks duplicate close/submit transitions.
- Exception history could lose the selected exception type and actor. It now records the chosen type, actor, reason and source reference.
- Desktop density and overflow needed another pass. Document search spans the narrower toolbar, navigation overflow remains usable, kit cards fit, and page scroll padding respects the fixed shell.
- The custom expected-return calendar carried the old year into January. It was replaced with the actual shared Maintenance date picker and calendar CSS. Selecting 5 January 2027 reaches custody review as 5 Jan 2027. Cancel preserves the original date; Escape closes the calendar while retaining the parent wizard.
- Added a preview dark-theme control using the existing semantic tokens.

## Verification

Final browser log: 14 passed checks, including a 14-view route/reload sweep, no captured runtime errors. Three failed harness attempts remain in the evidence: two incorrect overflow-menu locator assumptions and one immediate assertion during the calendar's closing animation. Corrected locators and a settled-state wait passed; these are not concealed as successful first attempts.

Desktop views at 1600, 1280 and 1024 pixels; list/cards/context menus; direct-object denial; read-only shortcuts; dirty/failure/retry; profile search/focus; history attribution; receipt discrepancy and empty upload regressions were checked. Light/dark and the settled replacement dialog were visually reviewed. Genuine browser zoom remains unverified.

The scoped TypeScript check reports no candidate-file diagnostics. It reports one imported PageHeader diagnostic for its existing `dusk` HTML attribute; this is not a passing full-repository typecheck. No shared source was changed.

`candidate-review-documents.png`, `candidate-review-summary.png` and `final-shared-calendar-2027.png` were captured after the final calendar build. The other final view images precede that isolated calendar change and show unchanged page layouts. `final-replace-dialog.png` captures a transition; use `final-replace-dialog-settled.png` for the settled visual review.
