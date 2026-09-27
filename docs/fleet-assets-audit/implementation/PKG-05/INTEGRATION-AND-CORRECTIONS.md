# PKG-05 integration request and final corrections

On 27 September 2026, Stephan asked whether the implementation matches the mockup and instructed this chat to notify Main and merge to local main and GitHub main. This authorises the publication workflow after Main's existing substantive review gate. It does not authorise deployment, operational database changes or removing source safeguards.

He then identified missing calendar right-click/drag behaviour and modal rule violations. Main (`Follow Revision 10 approval gates`, task `01a0b8c5-186f-7681-83fc-40229d94ef87`) was notified and recorded corrections in progress. No integration approval or publication is claimed by this document.

## Visual comparison

The approved v6 and the real application were compared at 1440 pixels. The purple hero, connected primary tabs, immediate heading, second-row icon tabs, priority cards, stage donut, movement chart and source-linked actions retain the approved composition. The implementation uses the actual application shell, real permission-filtered records, date-range controls and four consolidated hero metrics. It is similar rather than pixel-identical. Synthetic preview counts and its role-switching banner are not production UI.

The implementation adds persistence, source-owned commands, stale/repeated-save protection, scoped search/exports and real Fleet booking/journey/custody integration. These are functional improvements over the synthetic preview. Usability remains subject to Stephan's acceptance; implementation and test counts do not prove that users prefer it.

## Corrections under verification

- Calendar blank dates/times and existing entries use the shared context menu, including Shift+F10 and the visible New entry alternative.
- Permitted future, unstarted pending/approved bookings support Month date dragging and Week/Day time dragging/resizing. A canonical wizard reviews the proposed time and reason before saving through the existing Fleet booking command. Source availability, current versions and approval requirements remain authoritative. Undo opens a review of the previous time against the current record.
- The calendar has the canonical large day/month/year date anchor. Previous, Next, Today and Jump to date govern the displayed period; redundant From/To controls are removed only from Calendar.
- Quick views use WizardShell with named sections, fixed header/footer, scroll-contained content and Open full record. Single-section notes/information use the prescribed simple dialog. Structured forms use shared review cards and success panes, free section navigation and draft-discard protection.
- Overview action ordering now prioritises all outstanding return work, then journey completion, decisions and planning, matching its description.

Final real-browser correction evidence and the exact review commit will be appended after verification. Earlier build/browser evidence in the packet remains historical evidence of the initial implementation, not proof of these later changes.
