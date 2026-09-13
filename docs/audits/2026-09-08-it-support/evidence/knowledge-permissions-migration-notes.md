# Local capability migration evidence — 2026-09-09

The root agent reviewed and applied only migration `2026_09_09_000006_add_knowledge_and_credential_audit_permissions` through the canonical migrator and the guarded DML transaction. No reapplication was performed by the preparing subtask.

- Executed migration SHA256: `4c8cffdabe1a4878b855d228cb201225cc29f144cca3c168bd6896bbfa55a06d`.
- Executed gate SHA256: `36d53e1099b797743f393b2ee666798856b1a09be689c82e879b0309dc8349b5`.
- Reviewed fingerprint: `1d6a6f7d208bd91f0f196e58402859774e06cb823d8611854dc4d89451a69550`.
- `knowledge-permissions-migration-after.json` records all eight preservation invariants true. Permissions increased from 536 to 539; role grants from 1593 to 1596; registry from 997 to 998. The three added grants belong only to the existing admin role. Existing users, roles, memberships, explicit user overrides, permission definitions, all tickets and protected content/design hashes were preserved.

The canonical `MigrationsEnded` event has an existing listener at `AppServiceProvider.php:430` calling `SchemaCache::flush()`. That method clears its process-local static caches and increments `schema-cache:stamp` only if that key already exists. The gate's SQL allowlist covers database statements during migration; it does not purport to suppress canonical cache events. The root agent explicitly authorized this reversible local cache invalidation.

A subsequent read-only configuration check at `2026-09-09T02:17:07+00:00` found `cache.default=array` and the selected store driver `array`, with configuration uncached. See `knowledge-permissions-cache-metadata.json`. The check did not read, create, invalidate or alter cache entries. No evidence establishes a persistent file/Redis cache write; the observed array backend keeps cache operations in process. The precise presence/value of the optional stamp during the earlier event was not recorded.

An additional gate revision explicitly selecting the array cache was drafted after the already reviewed gate was reported ready. Only Pint ran on that revision; it was never executed. Its bytes are preserved separately in `knowledge-permissions-browser-migration-unexecuted-array-cache.php`. The canonical gate file was restored to the exact executed version and its SHA256 was verified as `36d53e1099b797743f393b2ee666798856b1a09be689c82e879b0309dc8349b5`. Do not interpret the unexecuted revision as the source that produced the applied evidence.

Preparation verification: PHP syntax passed; Pint passed; read-only `--pretend` exited 0. The root owns applied/browser capability evidence and the subsequent synthetic role fixtures. No provider configuration, communications, seed, DDL, existing actor modification or rollback migration occurred in this preparation subtask.
