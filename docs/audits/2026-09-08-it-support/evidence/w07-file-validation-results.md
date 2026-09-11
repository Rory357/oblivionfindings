# W07 attachment selection, content validation and recovery

9 September2026. Root owns this slice; W00 independently reviewed backend entrypoints and corrected the remaining raw-intake selectors. W07 remains incomplete, particularly scanning/quarantine, operational policy and final release acceptance.

## Confirmed defect and implementation

The actual Build9 saved-draft chooser accepted an84-byte plain-text file named `w07-disallowed.exe`. Laravel `mimes` checks detected content; its presence alone did not enforce the displayed filename allowlist. The direct reply selector already rejected that name. This was reproduced, not inferred from the baseline audit.

- `ItAttachment::uploadRules()` now supplies the existing size/content allowlist plus `extensions` validation to both intake/comment FormRequests, draft upload and durable direct reservation/write validation. Detected MIME is persisted by both active writers; browser-provided MIME is not trusted. Uppercase approved extensions are supported. Existing types and10MiB limit are unchanged; this is not a malware-scanning verdict.
- Removed the unused `ItAttachmentStorageService::store/deleteStored` pair after app/test searches found no callers. All active direct writers use durable reservations; the draft writer requires its existing tracked reservation.
- A regression exposed combined staged4+direct2 intake writing direct bytes before the total5 limit was evaluated. Intake now consumes/transfers the draft within its existing transaction and checks the combined count before new reservation/bytes. A rejection rolls back the draft transfer and leaves the original four files available, without creating cleanup work. Comment ordering already had this protection.
- `lib/it-attachments.ts` supplies the approved browser filename/accept contract. The saved-draft hook rejects disallowed names before creating an upload identity, preserves text/prior files and replaces only stale attachment validation errors. The direct reply and staged chooser share it. W00 extended both raw intake handlers to reject whole invalid selections, retain prior selections and explain size/type/count errors; silent `.slice(0,5)` is removed.

## Focused test evidence

- First root UI run:60 passed/8 failed (`w07-file-validation-ui-tests.txt`). All8 failures awaited Save draft before entering any work, contrary to the earlier intentional pristine-draft behavior. The tests now enter their first title before awaiting meaningful draft controls; lifecycle assertions are retained. Recheck69/3files/9.54s, process60309 exit0 (`w07-file-validation-ui-recheck.txt`). It overlapped W00's handler work and is an intermediate checkpoint; a final frozen combined run is still required.
- W00 raw-intake selection tests:13/1file/5.16s and scoped lint0, including drag/drop, correction, count/size and original File identity in submitted FormData. See its separate `ticket-intake-file-selection.test.tsx` and associated evidence logs. No production mock was added.
- First PHP snapshot:17 passed/1 failed, exact token`it_cf38147c577044a7`, wrapper1. New filename/detected-content tests passed. The failure was the combined-file ordering defect above (`ItTicketDraftAttachmentTest.php:287`); its diagnostic log and exact-schema-absence postflight are preserved.
- Final PHP snapshot after the focused ordering fix: **30 passed/357 assertions**, execution11:02:34–11:05:52UTC, process41266/wrapper0, token`it_c99c057593764b79`, exact schema independently absent. Covers DraftAttachment, Attachment and TicketCommand. See `w07-file-validation-php-recheck.txt` and `it_c99c057593764b79.diagnostic.jsonl`. Test output was buffered; counts/assertions come from actual PHPUnit diagnostic events, not an invented console summary. No application/test edits occurred during that final snapshot.
- Scoped PHP Pint and frontend lint passed. The final combined UI/types/build/browser checks for new JavaScript remain pending. Protected design sources are unchanged.

## Actual browser evidence and limits

Using Build9 JavaScript with corrected PHP, normal removal of invalid staged attachment1 retained requester R. Retrying the inert`.exe` returned the actual extension error and no file link. A10MiB+1 selection was rejected locally with R retained. Approved73-byte `w07-approved.TXT` staged as attachment2, then explicit Add reply committed comment4 once; full reload retained public A/B/R and the canonical file link. Another requester2 sharing an approved Site received404 from the copied attachment2 URL. Positive browser file opening returned tool `net::ERR_BLOCKED_BY_CLIENT` and a blank tab; successful native download/preview is not certified by that attempt. Authorized backend download tests passed.

The existing Build9 local oversize error briefly retained the prior server extension error. The new hook clears only that obsolete attachment error; this new frontend correction still needs a built browser recheck. The invalid fixture was never posted. No real provider was contacted. Complete journey and final teardown: `w07-browser-build9-results.md`.
