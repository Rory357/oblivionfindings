# Additional audit round 3 — overall gaps, clarity and usability

Gaps corrected:
- Canonical source checkout/return is management-only. The proposed driver handoff is now explicitly identified in preview controls, with an unconfigured-authority state that blocks actions and transition guards. This remains an implementation decision, not a claimed existing capability.
- Approval-not-required authority was incorrectly subjected to independent self-approval denial. The model now mirrors the canonical distinction: independent required bookings cannot self-approve; source-authority confirmation is separate.
- Client/Site request entry could prefill Alex/Aurora for another record. The source person/site now carries into the new draft.
- My Day accidentally inherited the Transport date filter. It now uses the actor’s permitted current-day records while preserving the Transport return context.
- Role switching could retain an inaccessible site selection. The filter now resets to the newly permitted scope without exposing the denied object.
- Cancelled/completed record wording could still imply preparation. These outcomes now have explicit labels.
- Form errors and hash navigation lacked intentional focus placement. Validation errors receive focus and record navigation focuses the main region.
- The overview’s assessment/missing-information list was called a decisions list. Renamed to Requests needing attention.

Final verification includes source-state/count handling, search/filter/back context, conflict/expired/unknown readiness, My Day and Client entries, named-recipient handover, desktop sizes, keyboard navigation, overlays, zoom measurement, browser console/network and all affected regression tests.

- Nested view component identities were recreated on parent updates, risking opener focus and expanded notes. Converted hook-free view functions to direct render helpers, keeping shared component identities and form state stable.

Final visual/source sweep in this round also corrected in-progress next-action copy, returned-passenger labels, seeded actual-journey history and total occupancy. Added unallocated request editing with original identity/history retained and reassessment required; linked reservations continue through their own change flow.

The final fixture consistency sweep separated overlapping example drivers/vehicles, aligned actual trip/check/receipt chronology and odometers, and removed hardcoded driver identity from My Day and journey ownership.

A final selector-scope check removed out-of-site passenger names entirely rather than showing them as disabled options. The requester browser probe confirms the name is absent from searchable results.
