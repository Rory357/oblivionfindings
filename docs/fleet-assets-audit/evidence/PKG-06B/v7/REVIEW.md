# PKG-06B v7 — Asset Finance review

Status: Design candidate ready for Stephan's mockup review.

[Open Finance v7](http://127.0.0.1:8902/#view=overview&section=finance&scenario=normal).

## Audit findings and changes

The previous Finance view contained two summary cards and generic source links. It omitted usable valuation context, allocation, a record collection, itemised service costs, a review queue, request/link flows, and Finance-specific exception states.

This candidate adopts Vehicle Profile's Finance workspace pattern and imports its actual shared record collection, list/cards toggle and menus. Shared Button, StatusBadge and Dialog remain unchanged. The existing v6 modal composition supplies consistent title/context, body scrolling, fixed actions and keyboard dismissal.

- Separate capitalised cost, accumulated depreciation and net book value with a dated source breakdown. Cost 4,800 less depreciation 1,440 equals book value 3,360. Values are synthetic NZD source records, not proposed accounting policy.
- Posted service costs of 240 include only BILL-208. Pending BILL-271 of 380 is excluded. Purchase invoice BILL-104, fixed asset FA-104 and optional purchase order PO-104 describe the same acquisition and are never summed.
- Fixed-asset recognition, ownership, acquisition and cost-centre context; a dedicated next-decision card identifies the Finance owner.
- Searchable/filterable linked records in list or card form, with source date, status, amounts, details, supporting-evidence metadata and nested review history.
- Two-step local review request with validation, duplicate guard, existing-request link, discard protection, preserved author and a visible sample queue entry. Source linking requires a selection and keeps costs unchanged. Local changes survive profile-section navigation and reset when the sample scenario changes or the page reloads.
- Finance access and Accounts Payable access are separate. Client-owned financial details are hidden. Unlinked recovery, unavailable sources, older snapshots, conflicting fixed-asset links, disposed-Finance/active-Asset and archived-Asset/active-Finance states are explicit. Unknowns are not zero.
- Replacement estimate/budget/date and disposal decisions remain visibly unrecorded where no source exists. Retirement links open the existing dependency review.

## Verification

23 passing focused keyboard/browser checks; one earlier retirement navigation failure was fixed and successfully retested. The retained log includes that failure. No captured browser runtime errors. Checked light/dark dialogs and desktop widths 1600 and 1024; final full overview captured at 1600 × 2040 to show the complete page. The browser viewport was reset and the working tab remains on Finance v7. Pointer interaction and genuine browser zoom were not verified; this is not a full accessibility audit.

The final bundle builds with zero diagnostics in candidate-owned TypeScript. The imported PageHeader still has its pre-existing dusk attribute diagnostic. No full-repository green typecheck is claimed.

Final proof images: final-finance-overview.png, final-review-1600.png and final-review-1024.png. Other numbered images record intermediate audit states. server.json identifies loopback port 8902, PID 27820, 8821-v7 and network-blocking CSP. build-inputs.json and source-references.json identify unchanged shared inputs.

All 46 v6 manifest files remain byte-for-byte unchanged. The v6 manifest SHA256 is 5945331c6c3e8dac3ef05cbb80fc9b776cb09c2161249cf3a8f92edcc7572302.

Final app.js SHA256: 0be1004349f40f57e932dc792d71d6b88e8306f07f5a61fbb24aa701d4d80d28.

## Remaining implementation boundaries

This is an expanded design mockup, not a completed production Finance integration. Request submission, linking and permission states are local simulations. No request, notification, Finance task, journal, payment or accounting disposal is sent. There are no original invoice bytes in the preview.

Production acceptance still requires canonical Finance data contracts, real role/site/object authorization, source availability and freshness, audit/concurrency/idempotency, review and link mutation validation, and authorised document access. Depreciation method/useful life/residual value, GST treatment, payment state, revaluation/impairment and replacement planning require verified source data; none are invented as live defaults. Finance-owned records and authority stay with Finance.

Changes are limited to PKG-06B v7 preview, page and evidence artifacts. No shared/application/backend/schema/route/guide changes, commits, pushes, merges, sibling work or subagents. Single operating organisation across approved sites. Earlier candidates are preserved. Present this exact candidate for Stephan's mockup approval before any implementation handoff.
