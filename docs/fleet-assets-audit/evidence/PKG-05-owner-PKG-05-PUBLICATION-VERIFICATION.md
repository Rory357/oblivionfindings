# PKG-05 publication verification

Completed 27 September 2026. Same Transport owner, exclusive slot released by Main's review revision 2. No additional implementer or scope expansion.

## Exact publication

- Approved source: `49e0be5b1c1716aeb4e681529bb71fdce2a7abc0`.
- Approved prior local/remote main: `ba5bff2e8b6c22796369443f1cdac918039950dd`.
- Local main in `C:/Users/steph/Herd/oblivionfindings` was fast-forwarded with `git merge --ff-only` to the exact approved source.
- Ordinary push: `git push origin 49e0be5b1c1716aeb4e681529bb71fdce2a7abc0:refs/heads/main` succeeded. No force push, reset, clean, stash, PR, operational migration or deployment was performed.
- Independently queried local HEAD and GitHub `refs/heads/main` both equal the approved source at **17:25:21 NZDT**. Commit: https://github.com/Rory357/oblivionfindings/commit/49e0be5b1c1716aeb4e681529bb71fdce2a7abc0.
- The Transport checkout remains clean at that commit. Main's application, routes, migrations, tests and Transport TypeScript configuration have no working-tree differences from that commit. No unapproved sibling source was included.

## Protected dirty work

Before fast-forwarding, all 62 pre-existing modified/untracked Main files were recorded with status and SHA256. There were no collisions with the incoming paths. Immediately after fast-forwarding, every status and SHA256 still matched, including unrelated `public/.user.ini`; only then was the push performed.

The post-check snapshot retains exactly the same 62 dirty path/status entries. Two programme files were subsequently updated, timestamped 17:22:28–29: `docs/fleet-assets-audit/05-page-register.md` and `docs/fleet-assets-audit/evidence/OPS-PL01-design-start.md`. This Transport integration did not write either file. They are recorded as concurrent programme edits for Main to verify, not overwritten or reverted. Every other captured file, including `public/.user.ini`, retains its SHA256.

Evidence beside this packet: `pkg05-publication-before.json`, `pkg05-publication-after.json`, `pkg05-main-fast-forward.log`, `pkg05-github-main-push.log`.

## Bounded integrated checks

Executed from the actual Main checkout after publication:

- Focused model, calendar and navigation suite: **38 tests passed**, 4.42 seconds (`pkg05-main-frontend.log`).
- Expanded Transport TypeScript check: exit 0 (`pkg05-main-types.log`).
- PHP syntax: all **18** changed application/migration/route/test PHP files passed (`pkg05-main-php-syntax.log`).
- Integrated application source matches the exact approved commit.

The approved source's final production build and browser verification are already included in its committed `docs/fleet-assets-audit/implementation/PKG-05` packet. They are not represented as a second full build, full backend run or concurrency certification after fast-forwarding.

## Hosted checks

At 17:25 NZDT all **15 hosted check runs were still in progress**, with no completed conclusions yet. This is not a green-CI claim. Raw snapshots: `pkg05-hosted-checks.json` and `pkg05-hosted-runs.json`.

- Tests: https://github.com/Rory357/oblivionfindings/actions/runs/36294111080
- Database bootstrap: https://github.com/Rory357/oblivionfindings/actions/runs/36294111093
- Linter: https://github.com/Rory357/oblivionfindings/actions/runs/36294111128
- Visual regression: https://github.com/Rory357/oblivionfindings/actions/runs/36294111139

Main can now independently verify publication and release the serial slot. Operational migration/deployment and final package acceptance remain separate.
