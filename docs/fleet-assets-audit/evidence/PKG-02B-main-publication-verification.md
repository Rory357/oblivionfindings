# PKG-02B — Main publication and approval consistency check

Owner: MAIN ASTRA. Revision: 2. Updated: 2026-09-27.

**Technical source work complete; publication verified; hosted CI remains failed.** Main independently verified remote `origin/main` at exact approved commit `f7d517359da6ffdf90de2f259111fe5e8a1133f2`. The final Designer packet has been reconciled: all 15 hosted jobs completed, 14 failed and one MySQL bootstrap passed. CI assessment and worktree disposition are recorded below. Operating activation and Stephan's package acceptance remain open; this is not a green-CI, production-readiness or Accepted/Closed claim.

## Independent publication evidence

- Repository: `https://github.com/Rory357/oblivionfindings.git`.
- Approved candidate: [f7d517359da6ffdf90de2f259111fe5e8a1133f2](https://github.com/Rory357/oblivionfindings/commit/f7d517359da6ffdf90de2f259111fe5e8a1133f2), covered by [Main's original substantive review](PKG-02B-main-final-review.md) and [FINAL-01 resolution/integration approval](PKG-02B-main-final01-review.md).
- `git ls-remote --heads origin refs/heads/main` independently returned that exact SHA.
- `git fetch --no-tags origin refs/heads/main` then independently returned the same SHA in `FETCH_HEAD` at **2026-09-27T00:18:02+12:00**. The Designer's reported push time of 00:14:24 is attributed to its receipt; Main's own remote verification is the later observation.
- Published tree: `3cd60a0a7474ff601fbef461e6df89d7c24a9dd7`. Full approved-versus-fetched tree diff is empty.
- Earlier published base `4ea64c547ed85a5b7504e59599db351f6eba7deb` is an ancestor. No intervening application amendment or merge conflict resolution is present in the published candidate.
- Main compared the Git blob identity of all **49 changed application/test files** under `app`, `resources`, `routes`, `database`, `tests` and `config` between the approved candidate and fetched revision: **zero mismatches**. The identical commit/full tree also covers its other committed evidence.
- Local Main checkout remains `fa7b5291988cebfb6beaa6e6e10c6c660fb2a959`; Main did not merge, reset, switch branches, stash, push or edit application files. Its existing dirty context remains preserved.

The earlier independent14-test/401-assertion affected export-suite pass belongs to the exact source now published. Prior223 focused Main checks remain recorded against the reviewed predecessor and were not all rerun or added to overlapping counts. No additional local test run was needed for this identity-only publication check.

## Initial hosted-check observation at publication verification

Main independently queried GitHub Actions using the full approved commit. All four returned that exact head SHA and `in_progress`, with no conclusion:

- [tests — 36241291382](https://github.com/Rory357/oblivionfindings/actions/runs/36241291382).
- [database-bootstrap — 36241291362](https://github.com/Rory357/oblivionfindings/actions/runs/36241291362).
- [linter — 36241291356](https://github.com/Rory357/oblivionfindings/actions/runs/36241291356).
- [visual-regression — 36241291375](https://github.com/Rory357/oblivionfindings/actions/runs/36241291375).

That initial observation was not a green-CI or deployment claim. The completed checks and subsequent comparison below supersede its in-progress status. Main has not launched another worker, automation or recurring coordination loop.

## Final hosted results and completion reconciliation

Main independently read all four runs again from GitHub. Every run reports the exact approved head SHA and a completed status. The tests workflow has nine failed jobs (eight feature shards and foundation), quality has one failed job, browser verification has four failed jobs, and MySQL bootstrap passed. The compact independent response is preserved in [Main's final hosted metadata](PKG-02B-main-final-hosted-checks.json). There is no remaining hosted-job wait for these runs.

Main read the Designer's final supplement and preserved an exact [programme copy](PKG-02B-designer-completion-20260927.md), SHA256 `FD1B85F2A2D1488DAC6A286D95B7DE8E2B021B472AB5753D941748EF731DADAA`, verified identical to `C:/Users/steph/.codex/visualizations/2026/09/21/01a0c2bb-fcff-7cb1-8bab-882d84477c6c/pkg02b-final-audit/PUBLICATION-20260927.md`. Its structured comparisons and raw evidence remain in that directory.

Main inspected the comparison artifacts and diagnostic output, rather than treating an unchanged failure count as proof of equivalence:

- Quality comparison records identical file/severity/message multisets: 366 errors and 652 warnings, no added or removed finding. This preserves failed ESLint status.
- Browser comparisons record the same failed identities and dimensions: 48 Chromium, 15 at each IT/security viewport and 12 screenshot cases. Ordinary Chromium's error-summary equality is explicitly false because of the retained raw versus sanitized escape-token notation; the other three summary comparisons are equal. No pixel-identical or passing-browser claim is made.
- Feature3/4/5/7 failure details match; feature6 matches after timestamp/process database normalization. Feature2 retains the missing-client constraint with generated fixture differences. These comparisons were produced by the Designer and inspected by Main, not regenerated from every raw log by Main.
- Feature1 newly reaches the staff-profile/medication fixture failure already independently documented in the PKG-01 publication review. Main checked that prior record and its matching service frames. Feature0's supplied diagnostic shows ordinary-alert access and denial of an unclassified medication alert, passing 1 test/10 assertions. Main inspected that diagnostic output but did not rerun it or claim to recover the original random CI draw.
- Foundation newly reaches four consent-fixture failures. Main independently rechecked all eight relevant production/test Git blobs against the prior published base with zero differences. This supports the supplied source-based classification; it is not an executed baseline pass or an identical-failure claim. Later unexecuted test batches remain unexecuted.

No additional PKG-02B regression was identified by this reconciliation. The original technical integration approval remains consistent with the scoped evidence. Repository-wide test/fixture/lint/browser debt remains failed and recorded; nothing here waives privacy or consent guards, changes a product requirement, or represents the full suite as passing.

## Recorded worktree disposition

Main read the archive-attempt record: the managed tool refused to archive `pkg02b-final-audit` because it is protected by a pinned task or workspace. It remains retained; no further archive attempt, unpinning or deletion workaround is requested. Main independently observed the audit checkout still clean at the approved SHA and the original `5b0a` checkout still present. The source work is complete despite this protected retention.

The Designer reports preview8774 stopped, no dependent process, and hash-verified private recovery of 83 runtime files plus separate maps/reports/screenshots; Main inspected the preservation/disposition records but did not repeat every private archive-member comparison. Original `5b0a` remains retained with its 817-file recovery archive; later Claude and unrelated worktrees remain retained. The previously retired Claude checkout and recovery hashes are identified in the preserved supplement. Main did not stop processes, alter pins or remove any checkout during reconciliation.

## Remaining boundary

The existing technical approval and publication authority are consistent with the observed remote state. The consolidated Designer completion record and CI assessment are now received and reconciled; routine build/review coordination is finished for this candidate. Protected worktree retention is accounted for and does not require reopening source work or bypassing protection.

Actual deployment/activation still needs the recorded regional map dataset and refresh cadence, roles/Sites/coordinator backups, approved compliance/RUC/check/readiness/scoring sources, queue/scheduler, private scanner/storage and branding. Publication does not establish those prerequisites, a deployed test server, passing hosted checks, live-hardware certification or Stephan's final package acceptance. No new unresolved product decision has been identified. The original recorded limits and read-only Rory reference rule remain in force. No additional product approval is requested by this reconciliation.
