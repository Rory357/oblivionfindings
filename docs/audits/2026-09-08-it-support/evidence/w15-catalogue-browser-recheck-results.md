# W15 catalogue authoring and approval propagation — desktop recheck

12 September 2026. Verified within the following bounded journey. W15/E14 and the complete release gate remain incomplete.

Owned tokenb9d72a1e64c835f0/fingerprint3f603bf408b0a29224bb004a5557c5fe0c3b49c385fc0cd81b5793072234d39a. Bootstrap81045terminal0/server45072/in-app tab23. Identity JSON proves the correct checkout, isolated schema and manifest700811935e586063ecce1d01a51f11285d6179eaaa075fcf22fefc173aebdd79/app-COsBHkoO.js. Normal login/logout between synthetic technician3 and requester1. Existing desktop viewport retained; no resizing or user-tab changes. Communications array/sync and no real provider calls.

## Browser evidence

- New provisioning form shows the explicit Choose provisioning type option. The author chose Equipment, entered a name/description, enabled approval, used Enter to continue and added a required text question.
- Fields Continue stops on Review draft with enabled Save draft. Read-only database snapshot at that point contains exactly the four seeded items, three versions, no submissions and no provisioning. This verifies the native-browser premature-submit correction beyond the JSDOM regression.
- Close opens Discard this catalogue draft?; keyboard Cancel retains the review. Only explicit Save draft creates item5. The success pane appears after persistence; the record has draft v1, no publication pointer, Equipment, approval required, one question. Returning to catalogue restores the New request form opener.
- Review and publish shows correct provisioning/approval/audience/field count. Explicit publication creates immutable version4 for item5 and makes it available to requesters.
- Requester-only actor sees four published forms: the newly published request, approval fixture, ordinary service and the versioned fixture's original public name. The unpublished draft and confidential revised draft name are absent. No administrative or approval controls are exposed in this requester view.
- The published required question is displayed. Empty Submit leaves the form open; after entering a synthetic equipment request, Submit creates exactly submission1 and provisioning1. Native validation text itself was not captured, so do not claim its visual error styling verified.
- Canonical snapshot: requester1, item5, schema v1, published version4, immutable contract/input hash, employee profile1, Equipment, pending status, approval_required=true and approval_status=pending. One created event records catalogue source and approval requirement.
- Technician sees Approval needed. Opening Fulfil shows the approval requirement. Attempting Mark fulfilled leaves the dialog and request pending, no approval/fulfilment actor and only the created event. The transient error toast was not captured; persistent error visibility needs improvement/reverification in the full lifecycle work.
- Cancelling the fulfil dialog, selecting Approve step, and reopening Fulfil shows the approved state. Synthetic manual completion notes explicitly state no actual equipment/account/provider change. Mark fulfilled gives Request fulfilled and Done status.
- Final read-only reconciliation: five items, four versions, one submission, one provisioning; approved_by=3, fulfilled_by=3, done/approved. Exactly three events: created by1, approved by3, fulfilled by3. No duplicates. The fulfil event truthfully records evidence_recorded=false and evidence_summary remains null: this test establishes approval propagation, not completed required-evidence/manual-adapter design. Full W15 must address that remaining lifecycle requirement.
- Console error query returned none. Tab23 closed; cleanup55457terminal0 and independent postflight terminal0 confirm exact schema and owned directory absent. No owned runtime or test remains active.

## Remaining acceptance and implementation

Two-editor stale recovery, browser withdrawal/replay and later-publication audience transitions remain pending; existing backend tests cover bounded contracts but are not substituted for browser evidence. Complete requester intake design, durable draft/create/recovery/cancellation, preview, explicit Site/audience/requested-for/attachment contracts, immutable template versions, eligible approvers/self-approval/rejection/expiry/cover, dependencies and actual evidence/owner/failure/retry/cancel/partial fulfilment/leaver reversal. Independent race and legacy migration/backfill acceptance also remain open. No production integration completion is claimed.
