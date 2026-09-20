# PKG-01 Designer setup recovery

Owner: MAIN ASTRA. Revision: 1. Recorded: 2026-09-19 10:01:16 UTC.
Original status: one replacement setup requested after verified checkout failure. Resolved checkpoint appended below; no implementation claimed.

- Initial host request returned client-new-thread:b5a29152-4fec-47f1-b6d8-4030c823e852.
- Desktop logs show worktree checkout exit 128 at 09:56:06 UTC for the allocated 491a path and another host/UI retry exit 128 at 09:57:02 for 3336. No actual child thread ID appeared; Git worktree listing contained neither failed target. Host cleanup was observed; Main did not delete any worktree.
- Log scope: local Codex desktop logs for 2026-09-19, worktree-create entries only. Logs expose the failed git command and stderr byte count, not its full error text. No production data or credentials copied.
- Repository core.longpaths was unset. Longest tracked path at verified remote main is 208 characters; the managed worktree prefix takes it beyond Windows' legacy 260-character limit. This is the diagnosed likely cause, pending successful recovery, not a quoted stderr.
- Reversible setup adjustment: `git config --local core.longpaths true`; verified source .git/config and value true. No global Windows policy or global Git configuration changed. This metadata adjustment permits long checkout paths and does not alter application behaviour.
- Main authorised one replacement creation after the prior setup was confirmed failed, using the same PKG-01 scope, model and verified remote base. Replacement setup ID: client-new-thread:90ae87a4-b22a-4084-ba2a-ed58803f214c.
- Do not retry the old failed placeholder or start a second package. No worker, issue correction, implementation attempt or retry budget was consumed. Await actual task/worktree/model/pin evidence.

## Resolved checkpoint — 2026-09-19 10:35 UTC

Replacement setup succeeded as actual Designer task `01a0b91c-8ddd-7e83-af84-725da96ca56a`, worktree `C:/Users/steph/.codex/worktrees/8424/oblivionfindings`, HEAD `e62b569ff42ab471300fb6713a68758b647b2c32`, pinned index 7 as verified at 10:03 UTC. This supports the long-path diagnosis; the original stderr remains unavailable. No old placeholder was retried and no second actual Designer assignment exists.

The first turn inherited Medium. Under Stephan's explicit A3 instruction its writes stopped, and the SAME Designer resumed with verified `gpt-6-astra / xhigh` at 10:08:20.796 UTC. Detailed configuration and preserved lineage are in [the live register](../05-page-register.md) and [amendments](../09-amendments.md). This recovery is complete; the package continues at its design/mockup gate with no Implementer.
