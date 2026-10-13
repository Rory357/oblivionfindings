# Main preservation checkpoint — 13 October 2026

The user requested that all work coordinated in this chat be saved on local
`main` and GitHub `main` to conserve usage. The three implementation owners
provided frozen handoffs. This checkpoint preserves their work; it is not a
completed integration or production-readiness release.

## What is in main

- The existing combined Workforce/Rostering, leave, qualifications, handover,
  Shift notes and Timesheet edits already present in the main working directory,
  including their new helpers and tests. Unmounted helpers remain unmounted.
- The three audit/coordination documentation directories and their histories.
- Complete changed-file overlays for the frozen Control Room and H&S candidates,
  including current untracked source, migrations, tests, plans and held patches.
- The ignored Workforce proposal/review families and relevant Timesheet runner
  evidence, including predecessor payloads required by later proposals.
- H&S-owned saved source checks and execution evidence under `storage/logs`.

The other worktrees are unchanged. Unrelated Fleet, eMAR and Security & Devices
untracked material is not swept into this commit. Generated dependencies,
databases, environment/private-key files, caches and redundant Workforce test
source mirrors are excluded. The manifests identify every archived file and
hash, excluded paths, source location and candidate base commit.

## What is not activated

The Control Room and H&S candidate overlays are stored as source packages, not
applied over the running application. Both overlap current main and each other;
the owners identify unresolved source/privacy and workflow integration.

- Control Room R44 and its R41/canonical-source dependencies remain proposals;
  the writer is unwired. Do not apply the obsolete dashboard/notification
  composition or overwrite the H&S permanent response record.
- Workforce restriction R5, settings, atomic correction, handover and navigation
  proposals retain their original integration holds and unrun checks. The
  Operations handover backend is incomplete; do not globally replace its base
  service or activate a partial schema/route/permission workflow.
- H&S's latest approved inbox connection is present inside the frozen candidate.
  Its committee/meeting command-save controls remain rejected, unapplied data.
  Earlier incident-write and Client response-wrapper holds remain in force.

All three full scopes remain unfinished. Rory's design rules, current access,
native tests, complete workflows, integration and browser acceptance remain
required. Saving source does not turn prepared test counts into passing tests.

## Recovering a candidate

`ARCHIVE-VERIFICATION.json` lists archive SHA-256 hashes and verification results.
Each archive contains a `manifest.json` and a `files/` tree with original paths.
Control Room and H&S additionally contain `tracked-changes.patch`, a full-index
binary Git diff against their recorded base commits. Both bases were confirmed
to be ancestors of GitHub main before preservation.

To resume safely, extract an archive into a separate empty staging directory.
Create an isolated worktree from the recorded base, apply its tracked patch, and
restore only the manifest's untracked files from `files/`. Preserve the extra
evidence separately. Compare all restored bytes against the manifest before
editing. Do not overlay `files/` wholesale onto current main, and never execute
or install files labelled REJECTED, UNAPPLIED or held merely because they are
present in an archive.

The Workforce archive restores to the original `test-results/...` relative paths
in an isolated workspace. It is proposal/evidence data, not an application patch.

Every archive member was hashed after compression and compared again with the
frozen source. Main's existing tracked diff passed `git diff --check`. No new
application test, build, migration, browser or provider run was claimed during
this preservation task.

The work was committed as `01fc068a69007980f750d80b1ea3d64051641720` and pushed
successfully to GitHub main. The three verified archives contain 11,348 files.
The coordinated application changes and documentation are committed; unrelated
untracked work in the shared checkout remains untouched. Two historical notes
retain their original extra EOF blank line so their original evidence bytes are
not deliberately rewritten.

The user explicitly requested that hourly coordination checks be paused after
the save. Automation `coordinate-control-room-workforce-and-h-s` is now PAUSED.
The implementation candidates remain frozen at their saved checkpoints;
future work must begin from these saved checkpoints and current main.
