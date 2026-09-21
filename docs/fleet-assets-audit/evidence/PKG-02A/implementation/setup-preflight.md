# PKG-02A isolated setup checkpoint

21 September 2026. A6 is recorded in Main's canonical evidence. Sole application owner remains the existing Astra Designer in worktree 2b9f. Setup only: no application edits, migrations, Laravel/TestCase bootstrap, test execution, DDL or build yet.

Offline Composer install completed exit 0: 158 locked packages, `COMPOSER_DISABLE_NETWORK=1`, `--no-scripts --no-plugins --prefer-dist --no-interaction --no-progress`. Windows optimized autoload generation took several minutes and completed without intervention. Existing non-PSR test helper warnings were reported. No scripts/plugins ran; both dependency locks remain unchanged.

`verify-isolation.php` executed read-only through PHP 8.4.16. `isolation-proof.json` records exact paths and hashes. App Models Client and Tests TestCase resolve under this worktree's local vendor autoloader. PHPUnit XML passed the installed XSD. Every XML env value is forced. APP_ENV testing, mysql 127.0.0.1:3306, unique base `oblivion_findings_pkg02a_2b9f_test`; DB_URL/socket empty and timezone UTC are also forced. No .env/.env.testing/cached config or inherited process token/connection URL was present. Read-only PDO selected no database and found zero schemas with this exact base/prefix.

Existing TestCase appends TEST_TOKEN/PARALLEL_PROCESS/PROCESS_TOKEN or actual PID. These variables must remain absent; the executed read-only example resolved to `oblivion_findings_pkg02a_2b9f_test_12136`, not a fixed name to reuse. TestCase drops/creates only its resolved schema, cleans it on shutdown and prunes at most five dead numeric PID siblings of this exact base (10 second cap, unknown liveness means no prune). Its source hash is recorded. Re-run the proof before each backend test command; always supply `--configuration phpunit.pkg02a.xml`. No generic/shared/PKG-01 namespace may be used.

Mail array, broadcast null, queue null, cache/session array; no queue worker, collector or provider process is started. No operational environment/provider credentials were imported. The first slice has no command/provider calls; feature tests must prevent stray HTTP and fake notifications/queue as applicable. Later command testing requires explicit fake transport before exercising dispatch paths. Null queue is not claimed as a universal guard against synchronous transport calls.

JavaScript dependencies remain the existing read-only node_modules junction; no npm install or shared dependency writes occurred. Frontend runs must use a worktree-local cache/config loader. Browser QA will have separate `oblivion_findings_pkg02a_2b9f_browser` and a free loopback port, with effective runtime identity checked before synthetic writes. It has not been created.

Canonical lock-order source reconciliation: consent → Device → Client → Site (DeviceCustodySiteResolver) → assignment → selected geometry/rule. Private draft geometry remains only in immutable client rule versions, with minimal transactional audit and zero canonical geometry/evaluator/alert/transport effects. No new operational policy or activation is included.

Main must verify this executed setup proof before the reviewed first protected read/history/private-draft slice and isolated tests/bootstrap are released. This engineering checkpoint requires no new user approval.
