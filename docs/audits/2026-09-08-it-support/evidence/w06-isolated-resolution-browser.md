# W06 isolated resolution draft browser evidence

9 September 2026, approximately19:04–19:29 NZ. Codex in-app tab4 at `http://127.0.0.1:8766/it/tickets/4`, synthetic technician3, token2379f5391c4c48c2. DOM script verified `app-OFGueOKa.js` (Build6). Observed desktop browser viewport1072×856; no resize or mobile emulation performed. Real CSRF, separate synthetic schema/session/key/storage, array mail and synthetic-only2/3-day retention remain as recorded in readiness evidence. No working Herd configuration changed.

Actual browser journey:

1. Signed in as synthetic technician3 through the normal login form. Opened ticket4 and Ticket actions → Resolve ticket. Existing approved WizardShell displayed correct ticket identity, required-field completeness0%, saved-draft status and public-audience explanation.
2. Entered `W06 isolated persisted resolution draft. Keep the final character Z.` and unchecked Notify the requester. Autosave reported Draft saved; completeness100% reflected the required note. No Resolve action was submitted.
3. Closed the saved form and reloaded the entire document. First reload returned500 with a PHP30-second database execution timeout. A later explicit retry loaded successfully in under one second. This is a confirmed transient failure, not a proven root cause or a claim of acceptable performance. The bounded metadata-only follow-up found no active lock backlog at observation time (`w07-owned-browser-db-threads.json`). No timeout/configuration limit was changed.
4. Reopened Resolve ticket after the successful full reload. The UI showed only the saved-draft availability message and Resume/Discard choices; note input was empty/disabled before explicit Resume, completeness0%. Selected Resume saved draft. The exact final-Z text and unchecked notification choice returned; Draft saved and completeness100% were restored.
5. Selected Discard saved draft and reviewed the explicit confirmation. Selected Discard draft. UI confirmed The draft is discarded, cleared/disabled the note, returned completeness0% and offered Start a new draft. Closed the form. Ticket remained Open with0replies; no resolution/comment/notification submission was made during this journey.

Scope: this verifies the saved resolution text/choice reload/resume/discard journey in the isolated browser. It does not verify all W06 draft purposes, role revocation, multi-editor conflict, expiry, file recovery, keyboard paths or W07's newer composer. Build7 and the final package/release gates remain open.
