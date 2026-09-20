# PKG-01 implementation setup checkpoint

20 September 2026 (NZST). Implementer task `01a0bc28-a386-7653-9b29-361f93478222` in `C:/Users/steph/.codex/worktrees/2375/oblivionfindings`.

## Authority and checkout

- Effective first-turn metadata: `gpt-5.6-sol / high` in both `turn_context` and collaboration settings; local host. Designer independently confirmed the turn.
- Initial checkout was clean, detached at `e62b569ff42ab471300fb6713a68758b647b2c32`, exactly the approved source baseline. Created `codex/pkg-01-maintenance` from that commit without resetting another branch. The branch ref required approved access to shared Git metadata; Main's working tree was not edited.
- Repository `AGENTS.md` and `docs/architecture/single-tenant-application.md` require one operating organisation with approved-site, role, canonical-record and privacy boundaries. The released Designer brief is revision 7; its canonical page is revision 20. Frozen Designer v1–v8 artifacts and Main's source guides are read-only.
- This checkpoint is engineering setup only. No application source, migration, app bootstrap, test execution, schema creation/drop, operational data or deployment has been changed.

## Dependencies and autoload

- Herd PHP `C:/Users/steph/.config/herd/bin/php84/php.exe` is PHP 8.4.16; `bcmath`, `fileinfo`, `mbstring`, `openssl` and `pdo_mysql` are available. Composer PHAR is version 2.9.5. Node and npm are installed.
- `composer.lock` SHA-256 matches Main's installed checkout: `B741C4EE36D4B562ADA6E571A57DD0A7DAC1047277B658054E6D74C88DA04EF9`. `package-lock.json` also matches: `2FDEC15606F7D4FAFF29A5DDFE64707FBB2DA2098C334E9FBCBD2D39B85A6140`.
- `COMPOSER_DISABLE_NETWORK=1 php84 composer.phar install --no-scripts --no-plugins --prefer-dist --no-interaction --no-progress` completed, exit 0: 158 locked PHP packages installed, optimized autoload generated. Composer reported existing non-PSR test helper classes skipped from optimized autoload; this is not an application bootstrap. No Composer scripts or plugins ran.
- `npm ci --offline --ignore-scripts --no-audit --no-fund` completed, exit 0: 637 locked JavaScript packages installed. The first sandboxed attempt could not read the npm cache; approved escalation permitted cache access. No package script ran.
- Read-only `ReflectionClass` checks through this worktree's `vendor/autoload.php` resolve `App\\Models\\FleetWorkOrder` to `2375/.../app/Models/FleetWorkOrder.php` and `Tests\\TestCase` to `2375/.../tests/TestCase.php`. Main's generated autoload is not used.

## Destructive test boundary

- Root `phpunit.pkg01.xml` uses `force="true"` for `APP_ENV=testing`, `DB_CONNECTION=mysql`, loopback host/port, and the unique base `DB_DATABASE=oblivion_findings_pkg01_2375_test`. It forces mail to `array`, queue and broadcast to `null`, cache/session to `array`. `vendor/phpunit/phpunit/phpunit.xsd` supports the `force` attribute, and read-only `DOMDocument::schemaValidate` passed.
- No `.env`, `.env.testing` or `bootstrap/cache/config.php` exists in this worktree. `TEST_TOKEN`, `PARALLEL_PROCESS` and `PROCESS_TOKEN` were unset at verification.
- `tests/TestCase.php:121–166` appends a process token, drops and creates only that resolved schema; `:227–264` prunes at most five dead-process numeric siblings matching the exact base plus underscore and PID. The unique base has no other assignment's prefix. A read-only PDO connection through the XML test account selected no default database and found **zero** schemas matching this base or its process suffix. No create/drop or test bootstrap was run.
- Before the first test run, recheck the effective XML, no env/config cache, process-token variables and schema prefix. Run tests with `--configuration phpunit.pkg01.xml` only. If any check differs, stop before PHPUnit's `TestCase` bootstrap.
- A future browser QA server will use a separate `oblivion_findings_pkg01_2375_browser` synthetic schema and process environment rooted in this checkout. Its host/assets and effective connection must be verified before browser writes; it has not been created.

## Pending gate

Main is recording Stephan's explicit staged-start timing amendment. Designer has released engineering setup, and retains the final application-writer handoff. The exact additive migration/transition proposal is in `migration-transition-proposal.md`; no schema work begins before its review.
