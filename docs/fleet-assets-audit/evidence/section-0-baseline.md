# Section 0 evidence baseline and access record

Owner: MAIN ASTRA. Revision: 1. Updated: 2026-09-19 08:41:24 UTC.
Status: Read-only observations; not an executed workflow audit. Source: Revision 10; local code baseline `19354ecbc70046d12dfdf9c86f888e65fa1879d1`.

## Repository and master

- Observed checkout: C:/Users/steph/Herd/oblivionfindings, current worktree/branch main, HEAD `19354ecbc70046d12dfdf9c86f888e65fa1879d1`.
- Origin: https://github.com/Rory357/oblivionfindings.git.
- Initial `git status --short`: only `?? public/.user.ini`; tracked and staged name-only diffs empty.
- Existing file: public/.user.ini, 132 bytes; SHA-256 `5B89B33882C30A9B7882C456BDA8503F7BD0341187CF7AC0873C5EC512F5C82A`. Contents neither needed nor changed.
- Git warned that the user-global ignore file could not be read in the sandbox. This does not invalidate the reported tracked diff; untracked inventory is subject to that limitation.
- Worktree inventory showed other unrelated/old worktrees, including prunable entries. None was removed, reset, checked out or adopted.
- Supplied master remains C:/Users/steph/Downloads/oblivion-findings-fleet-assets-complete-astra-prompt-v10.md, Revision 10, SHA-256 `86DF7F586D2DD25816A3558053B42818083900AFCB7D5BC5ED48D59BA53298DA`.
- `rg --files` found root AGENTS.md in the inspected checkout. Read AGENTS.md, docs/architecture/single-tenant-application.md, CLAUDE.md and relevant DESIGN.md/linked guides.
- Existing suitable context area: docs/fleet-assets-audit, containing PLAN.md. No 00-project-rules/05-page-register/08 validation or SYSTEM_ORCHESTRATION equivalent was located in the searched docs/.codex/.agents paths before this work.
- Historical context inspected: July PLAN, fleet-assets-security-devices-production-readiness-plan (C1–C9, V1–V6, FA1–FA7), fleet-incidents-redesign/PROGRESS. These are source claims/history, not approvals to execute them.

## Browser evidence

Tool: cua_repl; Chrome connected through extension. Existing production Work Orders tab observed in inventory only; no production application navigation or mutation performed.
Configured local URL established by APP_ENV=local / APP_URL and .claude/launch.json; no secrets copied.
Created a dedicated local inspection tab and opened https://oblivionfindings.test/fleet-assets.
Observed URL: https://oblivionfindings.test/login; title “Log in - Laravel”; email/password fields and Log in button.
No credentials entered, sign-in submitted, fixture seeded, server reconfigured or notification sent.
Missing evidence: authenticated role/site context, Fleet rendered pages, served commit/build fingerprint. Local config does not itself prove synthetic data or server checkout identity.
No screenshots containing operational data were produced.

## Session controls and model

Main task: `01a0b8c5-186f-7681-83fc-40229d94ef87`, local host, title “Follow Revision 10 approval gates”.
Host list_threads confirms pinned index 1 and active status. Other pins left unchanged.
The returned 20 recent non-pinned tasks plus all pins contained no identified active Revision 10 Designer/Implementer. Wider archived/external sessions were not exhaustively audited.
Local current-task turn_context returned `model=gpt-6-astra`, `effort=xhigh`, matching cwd and current turn. Only these metadata fields were extracted; no transcript dump saved.
Host tools expose create_thread model/thinking fields and move_thread_to_sidebar_section for pin/unpin. No child launch or pin mutation was used to test controls.
Tool schemas advertise the specified Astra/Terra/Sol IDs and supported effort options; effective future sessions still require verification.
Task-attributable usage, actual cash/credits and speed tier: not established. Shared allowance percentages are not used as per-task measurements.
Official Terra/Sol model pages were fetched for documentation context, not to infer this account's billing.

## Remote and publication evidence

Initial sandboxed gh API reads failed with a network-access restriction. Approved read-only elevated calls then returned:
- Repository: default_branch=main; allow_merge_commit=true; allow_squash_merge=true; allow_rebase_merge=true.
- Branch main: commit `e62b569ff42ab471300fb6713a68758b647b2c32`, protected=false.
- Branch protection endpoint: 404 Not Found. Applicable rules endpoint `repos/Rory357/oblivionfindings/rules/branches/main`: [].
- Webhook metadata endpoint: 404 and missing admin:repo_hook scope. No auth scope was changed; no hook URL or secret was requested in output.
- Local .github/workflows/{tests,lint,visual,database-bootstrap}.yml declare main/develop CI. Some create/migrate isolated CI databases; none was executed here.
- External production webhook/deployment/migration consequences are **unverified**. Historical incident progress mentions a deploy webhook; this is a lead, not present-state verification.
- No fetch/pull/reset/merge/commit/push, branch setting change, new worktree or release was performed.

## Bounded source searches

Major paths: routes/{web,fleet-assets,fleet,assets,sites}.php; app/Models; app/Domain/{SecurityDevices,Hr,Finance}; app/Services/{Assets,Fleet,Sites,Tasks,ControlRoom}; FleetAssets and relevant Sites/Operations controllers; resources/js/pages/{fleet-assets,sites,operations/clients,my-day}; shared components; config/fleet.php; package/composer manifests; relevant test filenames/source.
Terms included PageHero/PageHeader/TabStrip, calendar/booking/overlap/lockForUpdate, canonical devices and links, geofence/consent/withdrawal, stocktake/stock.take/parent_asset_id/kit_id/recall/template_version/template_snapshot/release_to_service, import/export, map provider/google/OSM, Finance/HR links, Fleet tasks/signals.
Sparse-match terms are reported as bounded “Not found”, not universal absence. Similar terminology in unrelated IT/meal inventory models does not establish an operational-asset workflow.
Existing tests located include VehicleBookingSitePrivacyTest, ResidentTrackingRefactorTest, PersonalTrackingConsentWithdrawalTest, ClientLocationConsentDisclosureTest and ClientLocationAssetSecurityTest. **None was run.**
Detailed file/symbol links and master section mappings are in [08](../08-prompt-codebase-validation.md), avoiding duplicate source excerpts.

## History and ID preservation

July PLAN's waves and multi-agent instructions are superseded for this programme by Revision 10; its text stays unchanged.
Readiness C1/C2/C7/C8/V3/V5 are referenced in the validation report as historical claims, with observed current source separated.
Existing cap-fleet-vehicle-booking-request/decision/checkout-return identifiers occur in the historical audit task-script catalog; new VAL IDs classify assumptions rather than replacing those capability IDs.
No historical acceptance, correction count or unresolved issue has been silently marked passed or reset.

## Verification limits

This checkpoint validates starting assumptions and access. It does not certify legal compliance, UI conformance, permission safety, data integrity, end-to-end integrations or production readiness.
Document authoring is the only repository mutation from Main in this activity.

## Documentation integrity checkpoint

At 2026-09-19 08:41:24 UTC, validated all local file links in the 10 new Markdown records: no missing file targets. This does not validate remote URLs or Markdown heading anchors.
Master SHA-256 and unrelated public/.user.ini SHA-256 match the initial observations. Tracked/staged application diffs remain empty; HEAD remains 19354ecbc70046d12dfdf9c86f888e65fa1879d1.
Git status identifies only this programme's new documentation plus the pre-existing untracked public/.user.ini. No application tests were run for the documentation-only checkpoint.
