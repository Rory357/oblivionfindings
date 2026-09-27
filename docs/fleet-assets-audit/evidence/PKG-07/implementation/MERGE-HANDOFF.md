# PKG-07 integration candidate

## User authority and Main coordination

The user approved v9 implementation with “approved please implement all of this”, then directed visual parity, real saved boundary verification, and free OpenStreetMap address lookup. On 27 September 2026 the user requested: “please let main know so that we can get this merged and commit to main local and git”. Main independently acknowledged that publication request. User publication authority includes local main and GitHub main; production deployment, live-data migrations and monitoring activation are separate operations.

Main chat `01a0b8c5-186f-7681-83fc-40229d94ef87` coordinates substantive technical approval and the serial integration slot. This owner retains source/integration execution. No actual-main mutation or push occurs before Main's exact-source approval and slot. No further user confirmation is needed for the already authorised Git publication.

## Source and evidence

Worktree: `C:/Users/steph/.codex/worktrees/806c/oblivionfindings`. Branch: `codex/pkg07-maps-boundaries`. Original implementation base: `f7d517359da6ffdf90de2f259111fe5e8a1133f2`. Current published-base reconciliation and final candidate SHA will be recorded after the bounded source review.

The candidate contains intentional application source, two additive migrations, tests and durable non-sensitive implementation evidence. Local `.env`, QA credentials/login helpers, synthetic database setup, server logs and one-off rewrite scripts are excluded. Frozen v1–v9 preview trees remain unchanged and available in this checkout for Main's review; they are not application imports or part of the production-source commit. The approved v9 manifest remains at `docs/fleet-assets-audit/evidence/PKG-07/v9/manifest.json`, SHA256 `573EA5401FE8EC4F2A44E8618795D5DA5B97CEB4B41CE0B142C8A83B181D1D52`.

`IMPLEMENTATION.md` describes scope and canonical owners. `VERIFICATION.md`, `PARITY.md`, `BOUNDARY-VISIBILITY.md` and `ADDRESS-SEARCH.md` distinguish completed checks, successive fixes and limits. `source-hashes.json` records current source bytes. Tests and production build pass as recorded, but the merge-specific lint check found two render-time ref assignments and 24 warnings that are being corrected before final freeze. Broader inherited TypeScript diagnostics remain separately identified.

## Integration considerations

- Single operating organisation; authorization uses approved Sites, roles, record ownership and existing Client Location consent/privacy. No new tenant architecture.
- Reconcile current Asset/Vehicle/Site/Client Location contracts and shared SchemaCache/TestCase changes. Account for Main's upcoming canonical Asset controller room correction at integration. Do not import unapproved sibling candidates or local-only PKG-03 work.
- Apply additive shared-boundary/version/provenance migrations through the normal application release process. Versions preserve original geometry and independent inactive purpose proposals. Existing protected house/resident references block unsafe geometry changes/retirement. No new evaluator or notification authority is created.
- User chose public Nominatim submitted Search/Enter lookup. Set `BOUNDARY_ADDRESS_SEARCH_ENABLED=true`, `ADDRESS_SEARCH_AUTOCOMPLETE=false` in the intended environment to enable it. The preview flags are local only; do not copy its environment or database. Shared provider cache/rate limiting, attribution, permission checks, public type-ahead denial and manual fallback remain enforced.
- Existing personal location ownership stays with Client Location. Shared Fleet views exclude private/consent-blocked trips and ambiguous device pairings. Opaque same-actor handoffs return reviewed geometry without putting person IDs into the Fleet URL.
- The active preview and an unfinished user draft depend on this checkout; do not archive or remove it after integration without accounting for them.
