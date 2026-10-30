# PKG-06B v1 — Asset Profile mockup for Stephan

Status: **Design candidate ready for exact mockup approval. Implementation is not released.**

Open [the frozen local preview](http://127.0.0.1:8896/#view=overview&section=summary&scenario=normal). The starting record is synthetic **AS-104 — Transfer hoist**, assigned to Kōwhai House / Equipment room, with an outstanding receipt, a brake-assessment hold and an unconfirmed charger. Use the bottom design-preview controls to inspect alternate scenarios and receiving actors. All state is temporary in the current browser tab. Refresh resets simulated changes.

## Candidate and provenance

- Owner: this pinned chat `01a0dd46-af7e-77d0-8943-21f8df2bae5b`, DESIGNER ASTRA. Own rollout `turn_context` verified actual `gpt-6-astra`, `xhigh`, before substantive writes.
- Checkout: `C:/Users/steph/.codex/worktrees/8821/oblivionfindings`, initially clean and detached at `4ea64c547ed85a5b7504e59599db351f6eba7deb`. A later read-only `git ls-remote origin refs/heads/main` confirmed the same published head. No newer relevant published baseline was found.
- Authority: Main's absolute-path `handoffs/PKG-06B-DESIGNER.md` revision 1, paired PKG-06A handoff, WF-05/WF-06 and R1, current ownership/navigation/approval context, and the canonical Revision 10 master with SHA-256 `C4837AB675F9DFFDB6A8597636F49D5761DA114E6C155DC08E6BB8A209D63FD0`.
- `reference-hashes.json` records Main's uncommitted sources as read initially. `reference-refresh.json` records the later header refresh: 00/03/05/09 advanced to record another package's design release and Assets launch evidence. PKG-06B scope/gate, its handoff, master and protected design references did not change.
- `manifest.json` freezes preview source, bundled files, evidence, screenshots and this packet. The static response is tied to this checkout with `X-PKG-06B-Source: 8821-v1`; `server.json` records the port/process and served-file checksums.

## Review walkthrough

1. **Overview:** identity, serial/tag/category, ownership, condition and lifecycle; intended site/room, accountable custodian, receipt and hold are distinct. Location & observations separates assigned placement, last manual verification, last tracker report and last tag observation, with actor/source/time. No tracker is required.
2. **Custody:** inspect the pending receipt. All arrival checkboxes start unconfirmed even when dispatch recorded an item. Incomplete/disputed receipt retains an owned exception; complete receipt requires every item. Failed-save/retry and duplicate-result behavior are demonstrable. The acknowledged scenario allows assignment/release, transfer, loan and return review. Searchable destinations and recipients validate the sample site relationship. Change the preview actor to the intended recipient to demonstrate their receipt. A loan end date is planning context, not returned custody.
3. **Maintenance:** original check snapshots preserve their own wording, version, actor, time and evidence. Failed create/link retries return the same MW-271 work reference. Open work exposes collapsible notes, a retained unsent draft, Next action followed immediately by Progress, and a visible guarded Complete action. Work progress, hold, custody and Finance remain independent. Service/calibration applicability and recurrence stay unconfigured.
4. **Documents:** scoped search, private source-linked library, photos, original evidence, unavailable/quarantined files, versions and archive. The actual shared dropzone supports browse/drag and per-file validation. The source's 20 MB single-file contract is shown; replacement requires a reason, retains the prior version and marks the new simulation unverified. Progress/failure/retry is explicitly simulated. No selected file bytes leave the browser.
5. **Components & kit:** parent, independently maintained battery and removable charger; separate component history; original battery remains in replacement history; moving a parent does not confirm removable items. No additional financial totals are summed.
6. **Lifecycle:** attributable source history, purchase/receipt/invoice/fixed-asset context and separate pending Finance review. Retirement/loss/write-off/disposal review reveals outstanding assignment, receipt, Maintenance/evidence and Finance decisions. There is no delete or automatic disposal/posting shortcut.

Register, stocktake, Site, Device, Finance and Vehicle Profile links open deliberately labelled boundary/context viewers. They retain the source owner and explain the return context; they do not pretend to be those separately owned workspaces. PKG-06A retains registration/import/stocktake. Existing Vehicle Profile is reused conceptually through its canonical Asset destination, not rebuilt here.

## Browser evidence

Actual Chromium interactions were exercised against the exact checkout's static server. `browser-results.json` retains 34 passed checks, two earlier test-harness failures and their successful follow-up checks, and one unverified genuine-zoom check. The two retained failures were a document-name locator that omitted whitespace and an immediate dialog-count assertion during the picker exit transition. The corrected selector and settled parent-dialog checks passed. No application page errors were recorded. Network requests from the preview were limited to its local HTML, JS and CSS.

All 16 profile subviews were checked and captured from their exact URL after a fresh reload, with an assertion for their distinctive body content, in `frozen-view-evidence.json`. This is the candidate gallery. Final desktop widths 1600×1000, 1280×900 and 1024×768 were inspected; no horizontal document overflow was found. Narrow-width label wrapping and sidebar-footer overlap were corrected. The smaller desktop stacks custody ahead of the longer identity content. Content below the fold is intentionally scrollable. Full-page captures include the preview toolbar at the original viewport boundary; viewport captures show its ordinary fixed position.

Verified paths include incomplete→complete receipt, repeated confirmation, actor/site denial, loan→pending receipt→recipient acknowledgement, actual return handoff, guarded assignment release/reassignment, concurrent draft reload, edit cancel/failure/retry, original-check source distinction, work progress/notes/terminal guard, unavailable/quarantined evidence, document replace/archive, file validation/progress/failure/retry, and blocked retirement. Keyboard selector search/selection, picker-first Escape and draft-close behavior were exercised. The rapid subsection-navigation race was fixed and followed by all-section URL/selection and reload checks.

Representative frozen candidate screenshots:

- `frozen-17-summary-viewport.png` and `frozen-01-summary.png`
- `frozen-02-observations.png`
- `frozen-04-custody.png` and `frozen-19-receipt-dialog.png`
- `frozen-06-maintenance.png` and `frozen-18-work-dialog.png`
- `frozen-09-documents.png`
- `frozen-12-kit.png`
- `frozen-15-finance.png`
- `frozen-16-retirement.png`
- `frozen-20-desktop-1280.png` and `frozen-21-desktop-1024.png`
- `frozen-22-denied.png`

Other screenshots and `view-evidence.json` preserve development and interaction/recovery attempts. Earlier `final-*` filenames are not the frozen gallery: some captures appeared stale on inspection, so fresh uniquely named captures replaced them for review. The manifest retains all files, including those earlier attempts; only the `frozen-*` gallery above and in `frozen-view-evidence.json` identifies this candidate.

## Honest limits and unresolved inputs

- **Genuine browser zoom is unverified.** The browser shortcut did not change CSS viewport size or device-pixel ratio; internal browser settings surfaces were unavailable. Desktop resizing is verified and is not represented as zoom.
- This is a local synthetic React prototype, not production UI integration. UI guards do not prove server authorization, direct-object protection, transaction locks, idempotency, upload scanning/storage, real custody or Finance posting.
- The seven labels come from the published navigation module. The local static shell and some list/picker/date body composition illustrate Rory's patterns; they are not a claim that the full authenticated application shell or every production list/date component is mounted. Actual shared PageHeader, tabs, Button, StatusBadge, Dialog, WizardShell, Popover/Command and FileDropzone primitives are bundled read-only.
- Cross-workspace handoffs are bounded context viewers with synthetic identifiers, not live API routes or runtime integration tests. File covers are illustrative; no real manuals, clinical instructions, supplier records or photographs are supplied. The asset image is an icon placeholder.
- Static-asset transfers/loans/receipt/kit outcomes are proposed interactions beyond the inspected assignment/release service; implementation contracts remain a later task. No new enums, tables or transport decisions are authorised by this preview.
- Actual check templates/intervals, service/calibration applicability, release authority, custody applicability, loan scope, catalogue governance, retention and disposal authority remain approved-configuration inputs. Fixtures do not supply live policy. Client-owned equipment does not disclose care or personal location.
- No full production build, app test suite, backend migration or operational acceptance was attempted. No application/shared component/route/schema/guide file was changed. No sibling contact, worker, additional chat, notification, paid service, merge or push occurred.

## Stop

Present this exact v1 to Stephan and wait for explicit mockup approval. That decision does not itself authorise implementation or activation. Preserve v1; any requested revision gets a new version folder.
