# W06 isolated browser bootstrap recovery

The failed environment `472da947a1b04395` imported the reviewed dump (784 tables / 905 migrations) but did not initialize fixtures or start a server. Read-only diagnosis found all 22 effective isolation checks true. The early post-LoadConfiguration hook called `Application::routesAreCached()` before Laravel registered its `files` binding; this raised a BindingResolutionException in the framework container. Evidence: `w06-owned-browser-diagnosis.txt`.

Root authorized the exact original helper's StopAndRemove operation with fingerprint `2611e3b7314aacdc894aa59bead93069f34c399fc80780efcd2d79b525da2bb0`. The original source hashes were still unchanged. Guarded cleanup exited 0; the separate read-only `w06-draft-browser-cleanup-postflight.php` independently observed both the exact schema and token directory absent. No other database or Herd environment was changed.

Failure and original source provenance are preserved in:

- `w06-draft-browser-failure-472da947a1b04395.json`
- `w06-draft-browser-runtime-failed-472da947a1b04395.txt` (original runtime SHA256 `34051732fd075a5318f3656c5bc2e375b3079008f763c159f28f33055efad085`)
- `w06-draft-browser-cleanup-472da947a1b04395.json`
- `w06-draft-browser-failed-cleanup-472da947a1b04395.txt`
- `w06-draft-browser-failed-cleanup-postflight-472da947a1b04395.txt`

After cleanup, the reviewed one-line runtime fix changed `! $app->routesAreCached()` to `! is_file($app->getCachedRoutesPath())`. This retains early route-cache denial without resolving an unregistered provider. PHP syntax validation passed. New runtime SHA256: `a96b259e83536a58472c3ed23f180bbcbcfb2a79c4abd59fb0e6c19dcd2ec1db`. The other five manifested helper sources remain unchanged; no broad formatting was run.

Fresh preview `w06-draft-browser-preview-after-guard-fix.json` binds migration 000008 (`1ae010edf3ea84c468a76ffd1b0ff1cfb8b649a95c6353cf1ecc1440c772dc74`) and the existing Build6 asset manifest (`8c3b27e61afb799da8f4ee80d5d0c89363b260f2be8fd4964f3a08b4d93db604`). Reviewed fingerprint: `18d3aa7e2acfcd916b320c2d8bd83208c433bcdf2a2428dec12d7f839c75f859`.

Fresh token `2379f5391c4c48c2` was then launched through CreateAndStart. Its log is `w06-draft-browser-launch-2379f5391c4c48c2.txt`. Migration discovery remained stable throughout bootstrap. The 2/3-day settings remain explicit synthetic fixture values confined to this process and owned schema/storage.

## Actual fresh readiness

CreateAndStart exited **0** and wrote readiness at `2026-09-09T06:51:00Z`; schema registry count is **1000**, including migration 000008. The schema contains only the new synthetic fixtures (Sites A/B/C IDs 1/2/3; requester/other/technician/restricted actor IDs 1/2/3/4; public/sensitive/unapproved/resolve ticket IDs 1/2/3/4). Mailbox and app-settings absence checks passed; configured provider services were cleared in this process only.

Server PID **4920** was independently observed listening on **127.0.0.1:8766**. A read-only request to `/__it-draft-verification` verified the exact token/schema/checkout/owned storage and Build6 asset hash; APP_ENV is local, CSRF testing bypass is false, mail is array, queue is sync, session cookie is unique to this token, and the isolated upload limits are 16M/64M/20. Evidence: `w06-draft-browser-http-readiness-2379f5391c4c48c2.json`.

The environment is **ready for browser verification**, not browser-verified. It remains running for root's authorized desktop journeys. Migration discovery was released after readiness; any later migration is absent from this schema until a separately reviewed additive operation or fresh snapshot. Helpers and asset manifest remain bound by the running server guard. Do not alter the manifested helper bytes while it is running, and retain this exact fingerprint for its eventual guarded cleanup.
