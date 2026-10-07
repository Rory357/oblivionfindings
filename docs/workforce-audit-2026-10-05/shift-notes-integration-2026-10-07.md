# Shift Notes integration — 7 October 2026

This package improves the supported-living Shift Notes worklist and its create, edit, flag and review journeys. It preserves canonical person/shift ownership, current staff approval, approved Site access, private-note visibility and original-author edit limits. Control Room and eMAR are not changed.

## Delivered behavior

- Shared page header with complete filtered counts, explicit worker-week boundaries, server search and page navigation. Search remains literal; exported records use the same authorized filters and protect spreadsheet formula prefixes.
- Scoped person/shift choices, explicit disclosure of the choice limit and visible-note coverage, and generic unavailable-filter labels. A missing visible note is never presented as proof that care was not documented.
- Shared create/edit wizard and detail viewer, private and attention settings, Unicode-aware validation, review step, dirty-close and unload protection, mobile cards and keyboard controls. Editing cannot retarget the original person or shift.
- Current record capabilities reach already-open dialogs separately from retained draft values. Flag and review actions have explicit confirmation.
- Mutations use canonical source locks, current authority, mandatory audit/timeline persistence and physical root-commit receipts bound to the requester. The UI confirms only the matching action, record, source, actual normalized value hash and provenance. Unknown results retain entered values without automatic replay and offer a fresh scoped record search.
- Generic success flashes are suppressed only on Shift Notes so they cannot bypass committed-result confirmation. Other modules retain their existing flash behavior.

## Verification and limits

- Native MySQL: **70 cases, 1,386 assertions**, no failures/errors/skips. Authorization15/188; reads12/285; commands43/913. Exact dependency hashes remained unchanged. Owned test processes ended and disposable schema was removed. Evidence: integration `test-results/workforce-main-notes-final.xml`, receipt and cleanup JSON files.
- Focused UI: **55 cases** for Notes51 and shared flash boundary4; the combined adjacent Handovers/Notes/flash run passed95 across15files. Changed-source lint, full TypeScript and the final Notes production build passed.
- Actual isolated development browser: create private note, recover dirty draft, reopen normalized Unicode text, edit, flag and record review; matching confirmation dialogs observed. Final arrow-key type selection, labelled picker and desktop/mobile views checked on asset `app-CC4hlKyO.js`. Mobile document fits390px; dialog content/footer fit357px. The wizard step strip intentionally scrolls horizontally.
- Development-only Note#1 remains in the isolated Workforce preview: private, reviewed, edited and unflagged, explicitly saying no care event is documented. Removing its flag coincided with an asset-version refresh and one Inertia409 reload; a fresh page confirmed stored state. Do not claim an uninterrupted confirmation dialog for that final unflag or a globally error-free browser session.
- Native runs recorded non-failing missing-environment file-read warnings in the intentionally environment-free integration checkout. No rerun hid those warnings. No external messages, scheduler or queue worker were started.
- This is focused evidence, not a claim that whole-repository CI or the full Workforce programme is complete. Separate CI fixture PR20, Handovers corrective acceptance, Timesheets, other module integrations and pending qualification/modified-duty/respite policy decisions remain open.

## Evidence location

Local verification artifacts remain under the integration checkout `test-results/` and `output/playwright/`; runtime artifacts are not application source. Browser evidence includes `notes-final-a11y-mobile.png`, `notes-final-a11y-desktop.png`, `notes-save-result.txt`, `notes-edit-confirmed.txt`, `notes-flag-confirmed.txt`, `notes-review-confirmed.txt` and the final labelled-picker/keyboard snapshots. No preview database data is included in this commit.
