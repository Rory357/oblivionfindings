# PKG-02A — verified setup and first protected-slice release

Owner MAIN ASTRA. Revision 1, 2026-09-21. **Isolated setup verified; contract revision 2's first protected read/history/private-draft slice and scoped isolated tests are released.** This is implementation authorization, not a passing implementation result or integration approval.

Authority: verified v3/sole-Astra user assignment and A6 timing in PKG-02A-A6-timing-approval.md. Same Designer task 01a0be31-19ef-7d10-86a9-cbe968989a76, worktree 2b9f, branch codex/pkg-02a-client-location-design, baseline 2302ca33a95616e442a78e957ddce82d8db98669. Main reverified active turn 01a0c0b2-6f35-75e0-93c5-03dd65d8e2d9: gpt-6-astra/xhigh in both fields, latest metadata 2026-09-20T21:25:59.251Z. PKG-01's explicit custody hold remains; no Sol or competing application writer.

## Independent setup verification

Main read the complete setup-preflight.md, verify-isolation.php, isolation-proof.json and phpunit.pkg02a.xml; inspected relevant TestCase bootstrap/namespace/drop/cleanup logic and database configuration. Main independently executed the inspected read-only proof through Herd PHP 8.4.16: **exit 0**. The normal sandbox could not resolve that executable; the approved owner-context read-only execution succeeded. No application bootstrap, migration, test, DDL, server start or application edit occurred in this check.

Observed: App Models Client and Tests TestCase reflection resolve inside 2b9f; XML is valid against installed PHPUnit XSD and all environment entries are forced; no environment files/cached config or inherited process-token/connection-URL overrides; mysql loopback 127.0.0.1:3306; unique base oblivion_findings_pkg02a_2b9f_test; no database selected; zero existing exact-base/prefix schemas. Main's proof process example ended _33764, different from Designer's _12136: these are ephemeral PID examples, never reusable fixed schemas. Queue null, mail array and broadcast null are configured. They do not prove arbitrary synchronous transport cannot run; tests must fake/prevent those paths separately.

Exact reviewed identities:

- phpunit.pkg02a.xml: CAC8963A48816BB625AE8F0DE1ACB7EF999BD6061ED8B47F4B0CDF984CF1B29E.
- tests/TestCase.php: 89872E92712993D42361D9D444A80A92EC5F633EEDFC2179C78447ADF337330F, unchanged.
- verify-isolation.php: 09170CE312B5CBBA2B81D0F35A160AB1431F5D24FB3105B3A84F6A19A243FD1B.
- Designer isolation-proof.json: 910D8399245A92045D674929559C7986D89B945CB7E75A7B1104486940F17170.
- setup-preflight.md: 3708E7FB246AF6626055021916AE77AD4D7C421728541D149B33C51E6378755B.
- Local autoload_psr4.php: 8A0F7D0868C617417771FC936E1021384B80F418FCD0FAD331619FB15DBF9F23.

Both dependency-lock hashes match the previously reviewed source snapshot. Designer reports offline Composer exit 0 with 158 locked packages and scripts/plugins disabled; Main verifies resulting paths/hashes, not a second install. Git status showed the local test XML and programme/preview artifacts untracked, with no tracked application changes at this checkpoint. Do not publish test credentials, the local test profile/proof helper, browser seeds, runtime caches or raw logs as programme artifacts.

Main also read and hash-verified local vitest.pkg02a.config.ts, SHA256 290148F12CBDE879CD08EA06FB5750A712A17EA0ECCECFF876C17F104DC7A8CB. It extends the existing test configuration, directs Vite cache to .pkg02a-cache/vite in the worktree and limits workers to two; run with --configLoader runner to avoid shared node_modules cache writes. This is setup evidence, not a frontend test result. The subsequent compact Designer snapshot reports receipt and implementation underway in the same verified turn.

## Released work and safeguards

The same Designer may implement exactly the initial files/transactions/acceptance checks listed in implementation-contract.md revision 2 (SHA256 659F9DA0C9A87D676F53F6CBDE156B1700EC6C9C103A77570CFFC988E3BBC91B): real current/history presentation and post-read access checks; race-safe privacy clearing; approved drawing/context/history UX; protected persistent inactive client zone drafts with immutable revisions and minimal audit. Save/edit must create no canonical geofence/assignment/evaluator/signal/alert/job/notification/command effects. Preserve the existing consent → Device → Client → Site → assignment lock order before selected geometry/rule locks. New operational activation/promotion, outing mutations, command-dispatch endpoints and recipient grants are outside this first slice.

Scoped backend tests/bootstrap and additive migration/rollback checks may run only through the inspected forced profile and unchanged TestCase boundary. Re-run the isolation proof immediately before each backend command, supply --configuration phpunit.pkg02a.xml, keep process-token overrides absent, and verify the actual resolved base plus numeric current PID. Existing cleanup is limited to the current schema and at most five dead numeric PID siblings of that exact base, skips unknown liveness and never touches shared/PKG-01 schemas. Do not use generic Artisan reset/migrate commands against inherited environment. Use explicit fake outbound HTTP, notifications/queues and provider transports as applicable; start no real collector/worker.

Frontend typecheck, targeted interaction tests/lint/build are authorized for this slice with worktree-local caches and unchanged dependency locks/shared dependencies. A separate synthetic browser schema/port may be prepared only after the Designer verifies its exact worktree/runtime/connection identity and cleanup boundary before fixture writes; record that evidence before browser workflow verification. No operational environment or real tracker/recipient data may be imported. Preserve all frozen mockups, Rory guides, other worktrees and existing previews.

Return the first protected read/draft checkpoint, or a substantive blocker after 45 minutes of active implementation, before expanding to schedule evaluation or command execution. Record exact source/build identity, migrations/rollback, tests actually run with failures/warnings, browser coverage and remaining requirements. No whole-package completion, feature acceptance, merge/push or live activation follows from this engineering release. Existing policy/technical prerequisites and later Designer full QA, Main exact-code review, publication verification and Stephan acceptance remain.
