# GitKraken WIP cleanup — 28 September 2026

**Later publication checkpoint:** [Final publication verification](MAIN-publication-verification-20260928.md) records application29a83522d on local/GitHub main and includes Main's audit documentation in the final checkpoint. Local cache/configuration/helper files remain in place and are excluded locally. The cleanup-time Main IDs and pending-push statements below are historical; the preservation branches and retained Vehicle Profile drafts remain as recorded.

Stephan requested cleanup of the accumulated WIP entries. Main preserved 2,492 documentation/mockup/evidence files on five local `codex/archive/` branches. Four checkouts now have no WIP. Old Vehicle Profile documentation is saved separately; its 15 remaining draft/reference/configuration paths remain visible and untouched. This is local preservation, not application integration or publication.

## Completed checkpoints

- PKG-03: 381 files, `codex/archive/pkg-03-wip-20260928`, commit `b0f76aea48cbf2d5fc4631acff923e258c7383dd`, zero remaining WIP.
- PKG-04: 494 files, `codex/archive/pkg-04-wip-20260928`, commit `f5f36ebf43e5a3e51d84e30f7df465c8d4b3e118`, zero remaining WIP.
- PKG-06A: 390 files, `codex/archive/pkg-06a-wip-20260928`, commit `7d5cb4abeab6e3fcdf802bb38ac5f3dda7f1ccd4`, zero remaining WIP.
- PKG-07: 424 files, `codex/archive/pkg-07-wip-20260928`, commit `d387e46977fbc61a8bb17d90035d4031f9c581b6`, zero remaining WIP.
- PKG-02B old Designer: 803 documents, `codex/archive/pkg-02b-docs-20260928`, commit `cc22484f47cfb49cb6e57d41019705ccb0862cd0`, 15 remaining WIP paths. All 818 original changed/untracked files also have a verified ZIP backup. The nine source drafts and three design-reference paths were not staged, rewritten, restored or applied to Main.

Original feature branch tips remain unchanged. Their commits are ancestors of the verified GitHub main. Each checkout stays at the same filesystem path, with its working file bytes unchanged. Existing previews and chat pins remain in place. Twenty-six exact runtime-log paths are retained in place and excluded through local `.git/info/exclude`; no shared ignore rules or log contents changed.

## Recovery and verification

The preservation directory is [fleet-wip-preservation-20260928](C:/Users/steph/.codex/visualizations/2026/09/19/01a0b8c5-186f-7681-83fc-40229d94ef87/fleet-wip-preservation-20260928). It holds each package's receipt, path list, raw SHA256 manifest and ZIP, plus [final verification](C:/Users/steph/.codex/visualizations/2026/09/19/01a0b8c5-186f-7681-83fc-40229d94ef87/fleet-wip-preservation-20260928/final-verification.json). Every ZIP member was hashed against its original. After committing, Main rechecked checkout heads, original refs, ZIP hashes and expected WIP counts.

Vehicle Profile's full backup remains in [its owner preservation directory](C:/Users/steph/.codex/visualizations/2026/09/21/01a0c2bb-fcff-7cb1-8bab-882d84477c6c/pkg02b-preservation), with the original inventory and receipt. Two live-log preflights stopped before Git/working-file mutation; exact runtime paths were then identified and retained in place.

These old worktrees have no attached managed archive identities. None was deleted, manually moved or falsely reported as managed-archived. Snapshot commits preserve local documentation; they are not intended for blind merging into Main. No force push, reset, stash, guide edit, application edit or worktree removal occurred.

## Remaining visible work and Main status

Active Settings, Reports and People Locations integration/preview workspaces remain available. Main's current audit documentation also remains uncommitted while the prepared serial integration must preserve it. Old Vehicle Profile source drafts differ from the published implementation and remain recoverable for explicit review. These remaining WIP entries are deliberate; this cleanup does not claim every checkout is clean.

At 2026-09-28T05:13Z, local Main remains `abc70200dc232317273b028e28181af6191f87b8`, and a fresh GitHub `ls-remote` returns `a4f869fe5e07b2fc5ecb87526596077f9700a584`. This cleanup changes neither. The separate publication execution block and pending Main-push exception are recorded in [the publication decision](MAIN-publication-approval-20260928.md). GitKraken's normal coloured commit history remains; refreshing should reflect the verified WIP reduction.
