# Review of two old Claude workspaces - 28 September 2026

User authority: review the leftover changes, determine whether they are needed, and complete cleanup and necessary integration. Main baseline: `121b11253257032dd6848a765f9b331364419ae4`.

## Accepted change

The funding delivery migration `2026_08_23_000140` revoked role grants through `permission_role`, which does not exist. Its `up()` method and the canonical Role relation use `role_permission`. The accepted correction uses that same pivot in `down()`.

The accompanying regression exercises rollback and reapplication, verifies removal of the two funding permissions and their role/user grants, preserves an unrelated grant, and checks the intended admin/finance grants after reapplication. Only this migration correction and its regression are accepted from the drafts.

## Review and preservation

The two workspaces contained 45 pending file entries across 44 unique paths: 41 tests, two factories and the migration. Existing main versions still matched the original bases; these drafts were not already integrated. The duplicate migration correction is included once.

The remaining 42 unique paths are test/factory maintenance drafts. A combined run covering the changed tests and backend factory consumers encountered multiple failures. It was deliberately stopped and is neither a complete run nor a passing suite. These drafts are not accepted as a batch and remain available for a separately scoped test-maintenance review.

- `agent-a2168914f0f139453`: `codex/archive/claude-tests-a-20260928` at `061889f2f59b5c6945f35fb177122a70fb2563bf`; 21 pending files preserved. Original branch `worktree-agent-a2168914f0f139453` remains at `fa7b5291988cebfb6beaa6e6e10c6c660fb2a959`.
- `agent-aa6a31c7eaaa419d7`: `codex/archive/claude-tests-b-20260928` at `4e655664c197c86f8d92eae8e035504055cd6e0e`; 24 pending files preserved. Original branch `worktree-agent-aa6a31c7eaaa419d7` remains at `2fc4176e96868e843058fe5e3b207874da8b4c4c`.

Both source workspaces are clean. Their original working-file SHA-256 hashes and original branch heads were rechecked unchanged after preservation. Raw ZIP copies and manifests are retained locally under `C:/Users/steph/.codex/visualizations/2026/09/19/01a0b8c5-186f-7681-83fc-40229d94ef87/claude-leftovers-review-20260928`. The preservation branches are local recovery copies, not changes to merge wholesale into main.

## Verification

- The regression against the original migration fails as expected with SQLSTATE 42S02 for the nonexistent `permission_role` table.
- The final accepted-only run passes: 1 test, 11 assertions, process exit 0. Completed `2026-09-28T07:23:27.828835+00:00`. See local `accepted.log`, `accepted-junit.xml` and `accepted-receipt.json`.
- Scoped Pint verification passes, exit 0 (`accepted-style-receipt.json`).
- Tests use disposable process-specific `of_claude_review_test_*` databases. No operating database migration was executed.
- Full application-suite and hosted-CI success are not claimed by this review.

No factory default, frontend, protected design reference, product requirement or single-organisation authorization boundary is changed by the accepted patch.
