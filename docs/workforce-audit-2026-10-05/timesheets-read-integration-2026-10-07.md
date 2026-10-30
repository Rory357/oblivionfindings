# Timesheets read and navigation integration — 7 October 2026

This package connects the Timesheets header, filters, status counts, record pages and profile links to the complete permitted cohort. It preserves the existing writer, payroll, attendance, HR and billing behavior. Control Room/operator rostering and independent eMAR work remain unchanged.

## Behavior

- The default worklist still includes all weeks. The separate weekly-hours caption explicitly identifies its week, before-status scope and planned-duty comparison. Genuine zero values remain zero; unavailable comparisons are disclosed without invented payroll dates or live-sync claims.
- Literal saved-name/location search, person/staff/date/status filters and stable 50-record pagination use the server-authorized cohort. Counts reflect the destination tab's actual owner/reviewer scope. A permitted deep-linked record outside the page is separate from page totals.
- Shared page header and responsive status rail replace the legacy hero and duplicate cards. Desktop comparison and mobile cards retain dates, worker, person/Site, hours, break, mileage, shift type, tasks, return reasons and functioning actions. Worker-zone time comes from server evidence.
- Search choices survive navigation; failed reads retain choices for explicit retry. Unknown filter identities remain generic and clearable. Only permitted canonical HR profile links are shown.
- Attendance-backed records do not offer manual editing. Retained edit drafts and review reasons survive refreshed or withdrawn permission, while commands are disabled. Inert menu promises with no handlers are removed; implemented actions remain.

## Verification

- **38 native MySQL cases / 693 assertions pass**: new cohort tests 27/599, unified index 8/47 and weekly summary 3/47. No unsuccessful cases. All 32 unowned controller writer/access/picker methods remain byte-preserved; independent dependency review found no required unfinished-module or generated-file change.
- **27 focused interface cases across five files pass**. The final header/rail adjustment reran its eight-case subset successfully; it is not counted twice. Changed-source lint, full TypeScript and final production build pass. Vite retains its advisory large-chunk warning.
- Actual isolated development browser: three existing rows; recorded-name search finds two, clear restores three, week navigation retains the search, unknown Client filters do not disclose identity, and a permitted deep-link remains separate from empty list totals. Final mobile More navigation selects Drafts and preserves all three records. The attendance-backed menu retains View, permitted staff link, Submit and Copy link, with no manual Edit.
- Final desktop 1440 and mobile 390 screenshots were inspected. Document width is 390 at 390; final browser asset is `app-B5WID3L4.js`, and its fresh console has zero errors/warnings. An earlier asset load hit ERR_NO_BUFFER_SPACE and the actual Reload control recovered; this is not a session-wide zero-error claim. Preview has only three rows, so multi-page acceptance comes from native/interface cases rather than a populated browser page two.
- Primary-checkout interface rerun could not start because its installed dependencies lack Vitest. No primary runtime pass is claimed. Evidence transfers through exact delivered Git blobs and independently reviewed dependency closure.
- Missing-environment read warnings remain documented in the isolated native run. Actual PHP tests exited zero; the launcher reported a transient owned console-process guard, followed by independent confirmation that owned processes/schema were removed and frozen dependencies remained unchanged. No external worker or scheduler was started.

## Remaining programme work

This read package does not complete Timesheets. Current writer authority, atomic create with optional submit, physical-commit receipts and unknown-result recovery, catalogue actions, scoped payroll adjustments, source-free owner draft recovery, picker completeness and broader cross-module journeys remain separate increments. Existing writer/wizard date conversion also needs its own verification. Payment must remain owned by accepted external settlement; approval and processing markers must not imply payment. No new respite-purpose policy is introduced.

Evidence remains in the integration checkout: `test-results/workforce-main-timesheets-final.xml`, its receipt/cleanup JSON, `timesheets-header-final-{ui,lint,types,build}.log`, and `output/playwright/timesheets-header-final-{desktop,mobile}.png`. Whole-repository CI and the full Workforce programme are not declared complete.
