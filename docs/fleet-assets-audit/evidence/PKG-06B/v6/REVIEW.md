# PKG-06B v6 — modal review

Status: **Design candidate ready for Stephan's mockup review.**

[Open v6](http://127.0.0.1:8901/#view=overview&section=library&scenario=normal). The working in-app tab has been moved to v6 and left on Replace document version. v5 remains unchanged; all 43 files in its manifest were verified.

## Improvements

- Simple dialogs now share a clear icon/title/context header, independently scrolling body and fixed action footer. Width follows the content: compact confirmation, standard record or wide Maintenance detail. Required fields, readable hints and consistent close/back actions are explicit.
- Wizards retain the actual shared WizardShell and now use purpose-specific icons, step descriptions, review headings and action verbs. Misleading step-based completeness was removed. Rail navigation reaches the selected step; Continue validates that step and final submit validates the whole form.
- File/title/replacement reason, asset identity, report summary/observation and receipt discrepancy errors appear earlier. Fields and the upload control expose associated errors; the error summary receives focus. Destination/recipient mismatch is caught before review.
- Viewing an original check, document, completion decision or assignment release preserves the parent draft. Back returns to its entered fields and selected step. Discard clearly distinguishes unsaved entries from saved records. Delayed focus restoration no longer steals focus from a newly opened dialog or picker.
- Busy saves disable navigation, Cancel and repeated submission. Exception/release actions have retained failure recovery and visible confirmation. Unsaved Maintenance notes participate in draft protection, and attribution follows the selected actor.
- Document replacement shows the original file/source and v3 → v4 context. Review includes source lineage, kit-at-dispatch and staged report evidence. Document metadata has a compact file cover and readable facts. Version history remains accessible for unavailable/quarantined files without offering file access.
- New issue and follow-up are distinct choice cards. Completion and cancellation have distinct explanations. Photo wording is specific; rejecting an invalid file alone does not create a dirty draft.
- Preview failure controls are tucked under Preview testing. They remain available for recovery checks without competing with the normal task action.

## Modal coverage

Reviewed document upload/replacement/photo, asset edit, custody/loan/receipt, problem report, retirement review, Maintenance work/notes, original check, completion/cancellation, assignment release, document record/version history/archive, custody exception, borrower departure, source/workspace context, profile find and discard confirmation.

`QA-INVENTORY.md` records the planned coverage. `browser-results.json` records **21 passing keyboard/browser checks**, five retained harness attempts and no captured browser runtime errors. The failed attempts were corrected or checked through the rendered UI: pointer checkbox activation, textarea value-attribute lookup, an unsupported animation inspection helper and two DOM-value evaluation timeouts. They are not presented as passing first attempts.

Visual checks cover the native 2393×1188 viewport, 1600×1000 and 1024×768, including dark mode and scrolling content with reachable footers. Viewport overrides were reset. Pointer automation did not reliably activate controls, so **pointer-driven interaction and genuine browser zoom remain unverified** in this pass. Keyboard interaction was used throughout the functional checks; no claim of a complete accessibility audit is made.

Final post-build screenshots: `final-work-1600.png` and `final-replace-1600.png`. Other numbered images document the audit states; some precede small wording/status refinements. The final bundle builds, with no candidate-file TypeScript diagnostics. The scoped check still reports the existing imported PageHeader `dusk` attribute diagnostic; this is not a full-repository green typecheck.

## Scope and handoff

Changes are confined to v6 preview files and PKG-06B page/evidence artifacts. Shared Dialog, WizardShell, Command, premium upload and date/calendar components are imported unchanged. `source-references.json`, `server.json` and `manifest.json` identify source, served bundle and candidate files.

This remains a single-organisation, synthetic design preview with local tab state. Real permissions, private storage/scanning, concurrency, policy inputs and canonical integrations remain the [previously documented implementation gaps](../v5/REVIEW.md). No operational data, application/backend/schema/route/guide changes, sibling work, commit, merge or push occurred.

Review this exact v6 candidate. The design-only handoff still requires explicit mockup approval before a separate implementation release. Existing versions and their evidence are preserved.
