# PKG-01 v1 — observed preview verification

Owner: DESIGNER ASTRA. Completed 2026-09-19 10:47 UTC. Design review only.

## Exact artifact and provenance

- Frozen **PKG-01 desktop mockup v1**, at 2026-09-19T10:47:13.237Z.
- Preview identity SHA-256: `dd29b52ab0480b36a5a40eda040a04d3f092f28a793d6972a566fbd3776a988b`.
- Source `preview.tsx` SHA-256: `e8b64641e7c685296cdaa3446e00cd85efe3873f019c30fb2fb35bb9cea024d2`.
- [Manifest](artifact-manifest-v1.json) hashes all seven build/source/runtime files and 24 screenshots. Its composite identity is defined in the manifest. README and evidence prose are excluded from the executable identity.
- URL: http://127.0.0.1:4317/. Source directory: `C:/Users/steph/.codex/worktrees/8424/oblivionfindings/docs/fleet-assets-audit/previews/PKG-01/v1`.
- Actual worktree HEAD: `e62b569ff42ab471300fb6713a68758b647b2c32`, detached. No tracked diff. Only this package's preview/page/evidence additions appear in git status. The sandbox could not read the user's global Git ignore file; Git's protected-path diff still exited zero.
- Browser was the Codex in-app browser, loopback preview tab, verified through URL and visible worktree/base footer. No original application server was used. Reload after rebuild used no-store responses. Scenarios and reports were synthetic and tab-local.
- CSS viewports: 1440×1000 and 1280×900. Screenshot bytes returned by the browser are JPEG and are stored as `.jpg`, without image editing. Some captures exclude/scale around browser scrollbars; filenames identify the tested CSS viewport, not a guarantee of matching raster dimensions. Full-page work/release captures are taller.

## Observed interactions

These results describe rendered mockup behavior, not backend acceptance or security enforcement. Inventory was created before execution in [qa-inventory-v1.md](qa-inventory-v1.md).

- **Queue:** table and cards render; site filtering, search, clear filters, tabs and meter drill-downs operate. Harbour House produces three records and two active holds; counts use the same site/search scope. Row Enter opens work. Kebab and right-click expose the same three contextual actions. Secondary fixture rows open their own bounded summaries, not WO-0264's evidence.
- **Queue states:** populated, deliberate empty, search-empty, loading, load failure and denied captures inspected. Unavailable counts use a dash. Direct-record denied state conceals record content. Return to permitted work restores the permitted queue. View-only access removes report and release controls; this is a UI demonstration, not an authorisation test.
- **Report:** missing asset focuses `SELECT#report-asset`, has `aria-invalid=true` and `aria-describedby=report-asset-error`; missing title/observation validation also works. Contextual report locks the asset. Kōwhai van offers the same-asset duplicate link; another asset offers a separate assessment. Synthetic attachment, review, linked success and separate-work success were exercised. The original submitted report retains identity, asset, observations, author/time and linked work.
- **Report recovery:** Escape opens the unsent-report guard. Keep editing, keep draft and close, resume, and discard were exercised. Final keep-draft close focuses `BUTTON#resume-report` after the close transition. Draft title/observations survive resume. Save failure retains the review data; retry creates the single confirmed report/work result in the fixture. No durable cross-tab draft or server idempotency is claimed.
- **Check:** missing required answers and a missing conditional warning image produce Incomplete, never Passed. Missing-answer submission focuses `check-tyres` with an associated error. Missing conditional evidence focuses `check-evidence`; completeness is 67%, not 100%. Adding the sample permits an immutable Failed submission. Escape from a dirty check offers continue/discard. A new submission receives a distinct check identity and appears in the evidence register.
- **Original evidence:** CHK-0082 exposes exact sample template TMP-004/v3, original question/rule text, failed answer, missing image, author/time, resource and correction history. Appending a correction leaves the failed original intact. Reporter and workshop evidence open different attributable source content. The old failed check remains visible after later repair/retest/release actions.
- **Triage and stale data:** reason is required. Entered next action persists. In stale mode, save is blocked with retained text; Refresh sources and keep entries preserves it for a deliberate retry. Manually assigned target input uses native input/change handling. No response SLA was inferred.
- **Ownership:** handover stays pending with the current owner accountable; the explicitly labelled recipient-acknowledgement demonstration transfers the owner. No real staff message is sent.
- **Provider:** internal appointment planning retains an explicit time window and is separate from external confirmation. End-before-start is rejected; 21 Sep 10:00–12:00 is retained after correction. Explicit provider confirmation and cancellation were recorded independently. A cancelled provider response does not clear the hold. Sample evidence is attributable; there is no provider portal, invitation or upload.
- **Completion:** missing details/evidence are blocked. A simulated failed completion save retains entries, and Retry same request succeeds. Repair completion leaves the hold active and Finance Awaiting approval. The completion operation is not a release.
- **Release:** normal fixture shows four specific blockers (repair, retest, custody, approved authority/rules), with no usable override. Failed retest remains a blocker. Custody receipt is explicit. Only the separate, conspicuously labelled hypothetical eligible-source fixture supplies the prerequisites needed to demonstrate review. A release reason is required. Confirming it creates RL-0042, retains original failed evidence, leaves both booking identities/states in place and requests fresh booking readiness checks. Finance remains unapproved/unposted.
- **Linked interfaces:** second booking context resolves BK-0112 rather than the first booking. Task identity, owner and next action are projected from source work. Notification failure has an owned retry; after retry it says queued/awaiting delivery. Finance failure retry returns to awaiting approval with the same source identity; it never posts the estimate. These are simulated contracts only.
- **Keyboard/visual:** row Enter, menu Escape, report Escape, field-error focus, report focus return, and Tab/Shift+Tab containment inside an open wizard were observed. 1440 desktop queue/report/work/release screenshots and 1280 queue were visually inspected. At 1280 CSS pixels, document width was 1265 (inside the viewport); no page-wide horizontal overflow. Header title remains visible after tab focus. Final console query returned no warnings/errors.

Main independently retested the report error/draft focus fixes and reviewed original failed evidence and all four normal release blockers. Main's record remains separate: [Main design review](C:/Users/steph/Herd/oblivionfindings/docs/fleet-assets-audit/evidence/PKG-01-main-design-review.md). This statement records the reported independent observations; it does not award Main approval.

## Corrections made during design QA

All were isolated preview corrections: asset error association/focus; reliable retained-draft focus; scoped counts; record-specific evidence and booking context; exact sample question wording; check completeness/error focus; native date-input handling; reset state consistency; view-only action visibility; and header clipping that avoids retaining an internal scroll offset. Application components and design guides remain untouched.

The initial formatter attempt found a missing repository formatter plugin in this dependency-free worktree. The existing formatter was then run with `--no-config` on the two preview files only. No dependency was installed. Final isolated esbuild/Tailwind build succeeded; this is not an application build/typecheck.

## Explicit limits / not verified

- Desktop browser zoom shortcuts produced no change in viewport/device pixel ratio in this browser. Zoom is **not verified**. No browser reduced-motion override was available. The preview CSS has a `prefers-reduced-motion: reduce` rule disabling transitions/animations, verified in source only; rendered reduced-motion behavior is **not verified**.
- No screen-reader session, complete WCAG audit, measured contrast audit, dark-theme acceptance, mobile/tablet work, cross-browser matrix or full long-content stress suite was performed. Actual shared styles provide the baseline; this is not a blanket accessibility certification.
- The complete interaction fixture is WO-0264. Other records have accurate summaries; multi-cycle post-release remediation and all combinations of harness scenarios are outside this demonstration. Reset starts a new review journey. The global shell/search/navigation are contextual surrogates, not additional working modules. Detail search is contextual chrome, not a implemented source-search backend.
- No authentication/direct-object policy, server validation, database immutability, concurrency/locking, durable draft, actual file security, network failure, notification delivery, accounting transaction, provider integration, real clock/DST, migration/backfill or production readiness was tested. The mockup's delay/failure switches are deliberately simulated.
- The eligible fixture grants no real release authority and approves no operational template, rule, cost or threshold. All policy decisions remain open in [contracts-and-scope-v1.md](contracts-and-scope-v1.md).

## Screenshot index

Numbered captures are chronological scenarios within v1; use the manifest for exact bytes. All 1440-labelled shots use the larger CSS viewport, and 14 uses 1280×900.

- [01 queue](v1-01-queue-1440.jpg), [02 cards](v1-02-cards-1440.jpg), [03 search empty](v1-03-search-empty-1440.jpg), [14 smaller desktop](v1-14-queue-1280.jpg).
- [04 report review](v1-04-report-review-1440.jpg), [05 linked success](v1-05-report-linked-success-1440.jpg), [11 unsaved guard](v1-11-unsaved-guard-1440.jpg), [12 save failure](v1-12-report-save-error-1440.jpg).
- [06 full work detail](v1-06-work-detail-1440.jpg), [07 immutable original](v1-07-immutable-check-1440.jpg), [13 required check evidence](v1-13-check-required-evidence-1440.jpg), [15 stale retained form](v1-15-stale-retained-1440.jpg).
- [08 full blocked release](v1-08-release-blocked-1440.jpg), [09 completion retry](v1-09-completion-dialog-1440.jpg), [10 repair still held](v1-10-completed-still-held-1440.jpg), [16 hypothetical decision](v1-16-release-decision-1440.jpg), [17 release recorded](v1-17-release-recorded-1440.jpg).
- [18 booking recheck](v1-18-bookings-recheck-1440.jpg), [19 independent Finance](v1-19-finance-independent-1440.jpg), [20 notification failure](v1-20-notification-failure-1440.jpg).
- [Empty](v1-state-empty-1440.jpg), [loading](v1-state-loading-1440.jpg), [load error](v1-state-error-1440.jpg), [denied](v1-state-denied-1440.jpg).

## Gate

Awaiting Main's scope review and Stephan's explicit approval of this exact v1 and bounded implementation scope. No Implementer selected or created, application writer, correction budget, commit, merge, push, migration, integration, publication or acceptance. The same Designer task stays open/pinned for authorised review iterations.
