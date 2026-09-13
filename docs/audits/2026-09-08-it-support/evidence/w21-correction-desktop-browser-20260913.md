# Knowledge and specialist correction recheck — 13 September 2026

## Identity and boundary

- Same saved checkout, no worktree; ordinary synthetic technician, Knowledge author and reviewer logins. Desktop in-app browser with unchanged dimensions.
- Guarded WorkspaceFixtures token `3060db40e95144d9`, fingerprint `3877985f66e687cdf139ece4bd14b056f1904f87dcfe0e74454f53e31f0d816f`, port 8766. Bootstrap session86948 exited0; owned server session44256.
- Build session69748 exited0: 5217 modules, 3m55s, asset `app-CcDgQVmz.js`, manifest SHA256 `60291dcf135455e785804f9f9387c141e0d36fa501b5ce675a88f8f86d439b85`.
- Identity, preview and 96 source/asset hashes recorded in the matching `w21-correction-browser-*` JSON files. Postcheck found all96 unchanged. No application source/build/helper edits occurred during the runtime.
- Screenshots were inspected inline; no screenshot files are claimed. Console warn/error log returned an empty list.

## Observed journeys

1. Problems, Changes and Major Incidents: Table to Cards preserved each title, search and actions without the earlier internal header clipping. Major Incident detail header remained visible after saving an update.
2. Major Incident1: declared 13 September2026 at12:24pm, next audience update12:54pm. An internal command note saved at12:26pm; the audience deadline stayed12:54pm. The UI showed the persisted note and updated event count.
3. Knowledge author: metadata row opening fetched the full approved article, structured procedure/verification and canonical service relationship. The separate proposal remained concealed in the reader. Closing article1 and immediately clicking page2 reached `/it/knowledge?page=2`, records25–27, without reopening the reader.
4. Author editing: fresh authorized context loaded the complete proposed runbook. Ownership showed review date `13/10/2026`; review showed `13 Oct 2026`. The proposal body was changed to “Synthetic revised recovery instructions saved during the correction browser recheck.” Moving among Content, Ownership and Review retained it. Save succeeded, then Send for review produced Published with Proposal: In review in the library row.
5. Reviewer: Knowledge-only navigation and review actions were available without author controls. Review history showed the saved proposed body, complete structured content, related service and review due `13 Oct 2026`. Compare with current publication showed the distinct original approved body.
6. Approve & publish opened “Publish this reviewed revision?”. Immediately after confirmation, the same title remained visible, Cancel was disabled and the action read disabled “Saving…”. No Discard identity appeared. Completion returned to the library with Published and no pending proposal. Reopening article1 showed the newly approved body and complete structured sections.

## Remaining observed issues

- Native document title links on page2 still use `/it/knowledge?article=27`, dropping the originating page. Closing that reader returns to records1–24. The row-action close/immediate-pagination race is fixed, but this separate native-link context issue is not. Carry it into the now-requested full-page canonical reader implementation; preserve filters/page in its return navigation and legacy-link compatibility.
- The Knowledge author save produced two identical “Article updated.” toasts: local `KbArticleDialog.afterSave` success plus the shared flash acknowledgement. Consolidate the acknowledgement in the full-page editor work. Source location: `resources/js/components/it/it-wizards.tsx`.
- No Word/PDF file management, in-workspace diagram authoring, full-page document experience or complete W21/W22 acceptance is claimed. Larger scale/search, canonical target navigation, resolution reuse and remaining specialist lifecycle/visibility/notices/concurrency still need implementation/evidence.

## Cleanup

- Owned in-app tab2 closed; unrelated user tab1 on port8767 preserved.
- Exact guarded StopAndRemove session88758 exited0. Evidence `w06-draft-browser-cleanup-3060db40e95144d9.json` records server settled and only the identified schema removed; cleanup log also records owned directory removed and Herd environment unchanged.
- Independent read-only postflight exited0 with `schema_absent=true` and `owned_directory_absent=true`; no database mutations in postflight. See `w21-correction-browser-cleanup-postflight-20260913.json`.
- The server handle was already absent when polled after cleanup. No owned runtime remains. The working database was not reset or migrated; the Knowledge revision migration remains unapplied there.

## User-directed handover

The user explicitly requested a new session after this cleanup, full pages for every Knowledge document and diagram creation inside Knowledge. They said too much effort appeared to be testing and too little building. The next task must implement the requested feature slice before another grouped verification pass, then move to W23 vendors and W24 credentials. The full implementation goal remains unfinished and is not replaced by the Knowledge slice.
