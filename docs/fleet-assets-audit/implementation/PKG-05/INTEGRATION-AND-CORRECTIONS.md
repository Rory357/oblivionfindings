# PKG-05 integration request and final corrections

On 27 September 2026, Stephan asked whether the implementation matches the mockup and instructed this chat to notify Main and merge to local main and GitHub main. This authorises the publication workflow after Main's existing substantive review gate. It does not authorise deployment, operational database changes or removing source safeguards.

He then identified missing calendar right-click/drag behaviour and modal rule violations. Main (`Follow Revision 10 approval gates`, task `01a0b8c5-186f-7681-83fc-40229d94ef87`) was notified and recorded corrections in progress. No integration approval or publication is claimed by this document.

## Visual comparison

The approved v6 and the real application were compared at 1440 pixels. The purple hero, connected primary tabs, immediate heading, second-row icon tabs, priority cards, stage donut, movement chart and source-linked actions retain the approved composition. The implementation uses the actual application shell, real permission-filtered records, date-range controls and four consolidated hero metrics. It is similar rather than pixel-identical. Synthetic preview counts and its role-switching banner are not production UI.

The implementation adds persistence, source-owned commands, stale/repeated-save protection, scoped search/exports and real Fleet booking/journey/custody integration. These are functional improvements over the synthetic preview. Usability remains subject to Stephan's acceptance; implementation and test counts do not prove that users prefer it.

## Calendar and modal corrections

- Calendar blank dates/times and existing entries use the shared context menu, including Shift+F10 and the visible New entry alternative.
- Permitted future, unstarted pending/approved bookings support Month date dragging and Week/Day time dragging/resizing. A canonical wizard reviews the proposed time and reason before saving through the existing Fleet booking command. Source availability, current versions and approval requirements remain authoritative. Undo opens a review of the previous time against the current record.
- The calendar has the canonical large day/month/year date anchor. Previous, Next, Today and Jump to date govern the displayed period; redundant From/To controls are removed only from Calendar.
- Quick views use WizardShell with named sections, fixed header/footer, scroll-contained content and Open full record. Single-section notes/information use the prescribed simple dialog. Structured forms use shared review cards and success panes, free section navigation and draft-discard protection.
- Overview action ordering now prioritises all outstanding return work, then journey completion, decisions and planning, matching its description.

Earlier build/browser evidence in the packet remains historical evidence of the initial implementation. Final correction evidence follows below.

## Main review findings and corrections

Main reviewed exact candidate `a285d4fe7dd1139e02a9bfb48c227946b754e199` and requested changes. The actual comparison base is **`ba5bff2e8b6c22796369443f1cdac918039950dd`**, not the earlier `d5eff35df` ancestor. Main's exact `tests/TestCase.php` blob is retained.

- **T05-01:** the existing Fleet booking page explicitly hands linked bookings to the authorised current Transport record. Incompatible legacy action buttons are hidden for linked bookings. The new backend regression checks all five booking states and the unlinked source page (71 assertions). Command regressions confirm required UUID/version metadata, key-handover validation, repeated-save handling, conflicting retries, stale versions and unlinked cancellation (14 assertions).
- **T05-02:** time-only proposals copy untouched fields from the locked canonical booking and request. Planner updates carry canonical booking purpose, destination, passengers, pickup arrangement and notes. A source regression with deliberately distinct fields verifies move, exact replay, stale rejection and reviewed Undo without clearing those fields (9 assertions).
- **T05-03:** Month and time-grid moves preserve duration from canonical instants, independent of browser timezone. Resize explicitly changes duration. Auckland gap/fold resolution is explicit, with offset choices in the wizard. Regression cases cover spring/fall clock changes and moves onto/off the clock-change date in UTC, Pacific/Auckland and America/Los_Angeles.

The focused frontend suite passes **38 tests**. Expanded TypeScript, focused lint, PHP formatting and application-scoped `git diff --check` pass. The final production build passes (`build-final-review.log`, approximately seven minutes); only the existing bundle-size warnings remain. It was copied from the isolated build output to the local review server before the final browser checks. No hot file or operational database is involved.

The 105 frozen v6 manifest entries were verified against actual committed Git blob bytes, not only filesystem copies. See `frozen-git-integrity.json`. No frozen visual or guide was edited to implement these corrections.

## Final real-browser verification

Completed at approximately 17:12 NZDT on 27 September against the final production assets from this checkout. The saved structured results and selected visual evidence are in [`qa/browser-results.json`](qa/browser-results.json) and the adjacent PNG files.

- Month native drag saved the proposed Auckland date through the canonical Fleet command. Reviewed Undo fetched the current version, restored the original window and retained booking identity and unrelated fields. Both mutations appeared as new versions.
- Week pointer dragging proposed a one-hour move with unchanged duration. Day resizing changed only the return. A resize overlapping the next booking was rejected with HTTP 422, the reviewed draft remained visible, and cancellation left the persisted booking unchanged. Started journeys had no move/resize affordance.
- The actual Fleet booking page hid incompatible old forms, displayed Manage transport booking, and opened the authorised current Transport record.
- Quick views passed at 1280×800, 1366×768, 1440×1000 and 1920×1080: the modal and fixed footer fit, with no horizontal overflow. Named Journey, Return & keys, Linked records and searchable History sections worked. The single-section note dialog used the simple tier; Keep editing preserved its draft and Discard draft closed without saving.
- All six tabs passed the final 24-combination layout sweep at 1280, 1366, 1440 and 1920 pixels. Each heading used the current canonical hero gap, each second-row navigation followed its heading, and there was no document overflow. Browser errors and console warnings were both empty for this sweep.
- Blank-date right-click, entry right-click/Shift+F10, permission-dependent actions and cancel-before-save were also exercised during the calendar correction pass. The final modal test again opened the shared entry context menu.

The final Overview, simple note, structured Return & keys, reschedule success/error and Fleet handoff screenshots were visually inspected. The earlier PDF verification remains applicable; the final review corrections do not change the PDF layout.

One intermediate browser harness reached successful move/Undo, then failed because its execution sandbox does not provide the global `URL` constructor. The harness used the known application origin instead and the complete run passed (booking versions 3→5). This was a harness failure, not an application error. No fixture reset or hidden operational update was used to obtain passing results.

This packet is ready for renewed exact-commit Main review. Publication remains pending Main's explicit Approved for integration decision and serial integration with other approved packages.
