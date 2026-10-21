# Review passes and fixes

15 October 2026 · Isolated Chat UI/UX mockups only. The approved refinement scope is complete for design review. Application implementation remains unapproved.

## First additional pass — behaviour and consistency

Reviewed all 25 groups and the then-current 186 scenarios, followed by complete journeys through group creation, drafts, family work, settings and calls.

Fixed new conversation opening, recipient propagation, per-thread drafts, member-limited search/media, family contact isolation, callback reuse, queue ownership, attachment readiness/captions, voice-note metadata, call history, site override merging and missing family-inbox/Calls navigation. Expanded invitation lifecycle, recovery and contextual scenarios.

Verified that the medication lead remains Rohan through Chat and call preflight, call messages appear in the same original thread, and returning to the source retains unfinished work. Staff coordination remains separate from family messages.

## Second additional pass — responsive and final regression

Reviewed every group at desktop, 820px, 390px and 320px widths: 100 checks and current desktop/phone screenshots for all groups. The final regression sweep covers 191 scenarios with content, no captured browser errors and no outer horizontal overflow.

Reduced the call-note dialog to a compact 460px desktop panel, with smaller phone/narrow variants and one main field per step. Verified fit in a small actual viewport. Checked modal keyboard trapping, Escape/focus restoration, large-text/dark reflow, reduced-motion styling, safe areas and an illustrative mobile keyboard. Full browser zoom and assistive-technology certification are not claimed.

Fixed family role retention during recovery, staff-only post-call records, group leave/end actions, cross-role client labels, nested call dialogs, unavailable-source drawers, archived-conversation recovery and explicit policy-conflict review. Ticket public replies/internal notes have separate drafts; source acknowledgement remains a separate action.

## Evidence

- `revision-2-checks.json`: both sweeps, 100 responsive checks and 29 passing interaction checks.
- `approved-revisions.json`: all 51 approved items mapped to mockups and evidence.
- `coverage.json`: current scenarios, inventory references and screenshot paths.
- `gallery.html`: 25 groups, 50 overview screenshots and focused examples.

All controls operate on local sample state. Authentication, backend authorization, clinical record creation, email delivery, realtime transport, scanning, audio, push and native calling remain unimplemented. Policy values marked as proposals remain implementation-planning decisions.
