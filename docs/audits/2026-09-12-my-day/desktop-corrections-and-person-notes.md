# Desktop corrections and separate person notes — 13 September 2026

This follow-up addresses the user's screenshots showing that the implementation did not match the approved desktop mockup, the request for separate notes for each person supported, and the handover review modal's compliance with the popup guide.

## Delivered behaviour

- My Day uses readable shift and care-action cards, consistent shared buttons, direct Handover content, clear My shift actions, and named person/work filter buttons. No-current-roster wording distinguishes clocked attendance from a rostered shift. My Day remains the signed-in user's work, including when that user is an Admin.
- The desktop shift-note editor uses the shared WizardShell: a named section for each permitted person, optional Whole site information, review, draft save, and a success screen. Navigation preserves entered text. Closing with changes opens the shared discard guard. Empty person sections do not imply the worker supported them.
- Notes are stored as an encrypted array on the existing canonical handover. Each person's section is checked against current client and site access when saved and read. The legacy primary-person narrative contains only that person's note. Partial access does not overwrite previously saved inaccessible sections.
- Draft retrieval uses a private, no-store server endpoint and the existing version token. Stale writes are rejected. The clock-out transaction forwards the same structure and version; invalid person notes roll back the combined operation. This form does not infer or overwrite medication completion evidence.
- A saved draft remains discoverable under Handover and My shift after clock-out. The review link opens the correct handover directly.
- The handover review uses WizardShell with named sections, not numbered form-step headings. Shift notes appear first; the next-worker information and historical record links have their own sections. The title is Shift handover rather than a misleading single person's name. Shared footer buttons expose the permitted next action. Unpaired drafts lead to Choose incoming shift; eligible paired drafts can be sent; the incoming worker sees I've read this handover. Medication links follow the existing medication capability.
- Corrected partial eager loading that hid person sections in Operations review, and task eager loading that omitted the client/scope fields needed to keep secondary-person tasks out of the primary-person legacy snapshot.

## Data and environment

Only migration `2026_09_13_160000_add_worker_notes_to_shift_handovers.php` was applied to the verified configured local database and the owned disposable browser database. It adds one nullable encrypted-data storage column; there is no new tenant boundary or role expansion in this follow-up.

Browser work uses Taylor Demo, synthetic person records, and the isolated loopback preview. No real notes, handovers, acknowledgement, or clock-out were submitted. The original HTML mockup remains separate from the running application.

Two byte-identical duplicate method declarations in active IT files prevented PHP and Vite from starting. Only the duplicate copies were removed after exact-content comparison: `ItKbController::restoreRevision/discardRevision` and `ItKbLifecycleService::restoreRevision/discardWorkingCopy`. All other IT/Governance changes were preserved. Subsequent shared build failures also included a transient incomplete IT page and concurrently regenerated route files.

This task did not edit DESIGN.md or design_styles. These files acquired unrelated working-tree changes during verification, so their final repository diff must not be reported as empty.

## Verification

- Earlier canonical handover/Operations suite: 40 passed, 314 assertions (`person-handover-final-backend.txt`). Later clock-out/draft recovery suite: 10 passed, 83 assertions (`person-handover-recovery-backend.txt`). Counts overlap.
- Current UI regression suite: 7 files, 32 passed (`person-handover-modal-ui.txt`), covering the new editor/review as well as person notes, desktop presentation, My Day, finish shift and the existing Operations editor.
- Focused ESLint passed (`person-handover-modal-lint.txt`).
- Final backend regression: 18 passed, 146 assertions (`person-handover-modal-backend.txt`). This includes the corrected task snapshot ownership and the Operations presenter showing all three permitted person sections. PHP formatting passed (`person-handover-modal-pint.txt`).
- Final whole-project TypeScript reports only unrelated IT wizard, IT knowledge-access test and Governance calendar-adapter errors (`person-handover-modal-types-final.txt`). The first modal run also caught two unsupported test-query options in the new test; those were corrected.
- Standard builds were interrupted twice by concurrent regeneration removing imported route modules. A build-only configuration freezes and checks generated route/action files and uses the unchanged shared application source and build output. This does not change the product's standard Vite configuration or route definitions.

No commit, push, deployment, or representative support-worker usability session was performed.

## Final browser and local delivery

- Final build passed in 3m54s (`person-handover-final-build.txt`). Normal local `https://oblivionfindings.test/my-day` and the isolated preview were both observed serving `app-BauJhu55.js`; the My Day chunk is `index-DJALxlo9.js`. No public/hot override is present.
- Browser inspection covered Today, Handover and My shift, the named-person editor, discard/cancel recovery, successful draft save, direct review, separated history, and the Admin no-roster explanation. Three person notes and Whole site restored correctly; saving Casey's follow-up flag retained the other two notes. The primary-person pending-task snapshot then contained only the primary person's task and the site task.
- A browser-only issue appeared during tab switching: focus caused the hero's hidden overflow area to scroll internally. Scoped `overflow: clip` retains decorative clipping without allowing that internal scroll. Final My shift and Handover checks showed hero scrollTop 0, the title fully visible at y=111, no document horizontal overflow, and no captured JavaScript errors at the natural 1869×1216 desktop viewport.
- Light-theme desktop cards and modals were visually reviewed before the CSS-only clipping correction; the final clipping check also covered the system dark theme. No forced viewport override was used.
- The normal local application is open in the in-app browser, with the original mockup retained in the other tab. The disposable preview is stopped. Its automatic database cleanup left the owned schema behind, so an exact-name, identity-checked cleanup helper removed only that fixture; `person-handover-browser-cleanup-verified.txt` confirms zero remaining.
