# W06 Setup browser recovery contract

Status: **Setup RAM recovery, candidate proof and durable create command recovery implemented; focused PHP/UI and real two-worker concurrency verified. Desktop browser acceptance pending the next build and reviewed additive migration.** No protected design source, persisted ticket draft purpose or runtime policy is changed. See `w06-setup-command-results.md` for the final command contract, verification and precise next step.

## Confirmed lifecycle gap

`resources/js/pages/it/setup/_dialogs.tsx` retains Team/Service forms only in unkeyed `useForm` and React state. Queue uses the same pattern in `setup/index.tsx`. Explicit modal close/Escape, stale configuration review and mounted validation preserve values; native history traversal can unmount them without those close callbacks. Their review `AbortController` cleanup does not retain entered work. The current ticket recovery helper cannot be reused by inventing a ticket purpose for these records.

## Whole-entity field map

Recovery should retain canonical field names, a canonical base snapshot and the proposed snapshot together. UI aliases are reconstructed only after authorization and explicit Resume.

- Team fields: `name`, `description`, `manager_user_id`, `is_active`, `members[{user_id,role}]`. `_dialogs.tsx` maps `person` to `manager_user_id`; empty string becomes null. Member role remains one of `ItTeam::MEMBER_ROLES` and order is not a new permission boundary. The retained initial/base snapshot permits a later explicit reviewed-version adoption to preserve independently changed untouched fields.
- Service fields: `key`, `name`, `description`, `owner_user_id`, `status`, `criticality`, `is_active`. UI `person` maps to `owner_user_id`. Keep the complete supported shape so a later field cannot disappear during recovery. Canonical enum sources remain `ItService::STATUSES` and `CRITICALITIES`.
- Queue fields: `key`, `name`, `description`, `team_id`, `routing_priority`, `is_default`, `work_types`, `categories`, `priorities`, `service_ids`, `site_ids`, `default_assignee_user_id`, `cover_user_id`, `is_active`. Empty picker strings map to null. Arrays retain canonical numeric IDs or enum values. `filter_rules` is not a second independent snapshot: `ItServiceManagementSetupService::filterRules` remains its canonical write adapter.
- Metadata shared by all three: original actor ID, resource discriminator `teams|queues|services`, existing record ID or null, opaque local context UUID, original/currently reviewed `configuration_version` (null for a new create), base fields, proposed fields, step index (Team/Service 0–2; Queue 0–3), latest outcome state and exact submitted snapshot when the result is unknown.
- Privacy history: prior selected person/member/Site/team/service IDs must be retained even when the current proposal clears or changes them. Names, descriptions, input values and history stay only in RAM. Metadata-only notices may contain resource, existing record ID, opaque buffer ID and unknown-outcome status, without entered titles or employee names.

## Canonical authorization seam

Extend the existing Setup service/controller boundary, not ticket draft enums. The existing authenticated `GET /it/setup?review_resource=...` returns `{resource,records}` and current configuration versions; it currently lacks original-actor binding. A read-only candidate operation should accept the original actor, resource, record ID/local context UUID, fresh candidate nonce, original configuration hash, base/proposed fields and prior bindings. It must return an exact nonce/actor/resource/context/record-bound authorization proof plus current version/capability metadata, without echoing private fields.

Use fresh `ItServiceManagementSetupService::guardActor` semantics (approved account and `it.manage`) and the current canonical record policy. Reuse existing staff eligibility and routing Site rules. Do not invent a broader organisation-wide shortcut for arbitrary Site IDs. Check existing and newly selected bindings; recovery of incomplete work must not demand a complete valid create payload or a unique name before allowing the user to correct it. An unavailable binding must conceal and preserve/purge according to the existing authorization policy, rather than silently substituting another employee.

Partial configuration validity and permission are distinct. `guardQueueDefaults` currently also enforces complete active-fallback owner/cover/team rules; a read-only recovery check should reuse its authorization predicates without pretending an unfinished fallback configuration is ready to submit. This requires a focused service extraction or a dedicated read-only authorization method, not calling a mutating save as validation.

## Client ownership and recovery

Retain the latest whole-entity snapshot continuously while mounted, using the same reviewed local resource bounds and explicit capacity behavior as the ticket memory work. A new Setup-specific typed adapter may reuse low-level memory mechanics where appropriate, but it must not construct fake `ItDraftMetadata` or call the persisted ticket draft API. No localStorage/sessionStorage/history payload, beacon write or unmount autosave is permitted.

After leaving and returning, expose only a metadata notice until a fresh canonical proof succeeds and the user chooses Resume. Validate the full shape and context before atomically adopting the candidate. Keep independent same-actor copies distinct. Successful current-form commits and explicit current-form discard remove only that owned copy; access revocation may purge the corresponding scope. Suppress immediate cleanup resurrection after discard until the form resets or genuinely changes.

Restore the original configuration hash and exact proposed/base fields, not a newer hash. Existing Review current setup → explicit Use reviewed version remains the only adoption path; it performs no write. The next Save submits only the deliberately changed fields and current approved expected hash. A pending earlier operation remains unknown until a definitive response or an explicit safe current-record recovery decision; cancelling the wait is not cancellation of the server command.

## Create-outcome gap and its implemented closure

The initial Setup creates lacked a durable command identity. A later row with the same name/key could not prove ownership or completion after a lost response. New Team, Service and Queue forms now use exact UUID command receipts and actor-bound JSON acknowledgements. A missing receipt remains uncertain; the user can retry the exact retained body or explicitly cancel the original command. Cancellation serializes with creation and records a tombstone if no create committed. If creation won, cancellation returns the committed result and does not undo it.

Root approved the dedicated receipt committed with canonical configuration and audit. Migration `2026_09_09_000009_create_it_setup_command_receipts.php` is source-frozen with SHA256 `E002AA6806326EB79458451109D59877762B46DA1B11556EEBEA79AA668AD88F`; root owns the reviewed additive application gate. Old built forms that omit the UUID retain their existing compatibility path; newly built forms always use the receipt path. No name/key match is accepted as the new create acknowledgement.

## Verification to perform

- Team, Service and Queue: latest unsaved last character, selected IDs and current step survive real same-document Back/Forward; Resume is explicit and the original configuration hash remains intact.
- An actor switch, revoked `it.manage`, inactive/removed selected staff or unapproved Site cannot disclose retained private work. Expired-session proof keeps content concealed and can retry after reauthentication.
- Two independent editors retain separate copies; current-form discard/commit cannot erase a sibling copy. A stale current configuration cannot trigger an automatic save or automatic version adoption.
- Known validation rejection retains editable work; cancelled/unknown saves keep an exact pending snapshot and require recovery. Cancellation of a recovery fetch and stale responses cannot hydrate another actor/resource/record.
- Browser evidence must identify the current asset build and desktop viewport. Existing Team/Queue/Service wizard validation, keyboard focus, review, save and explicit dirty-close tests remain required regression coverage.

## Current source checkpoint

- `POST /it/setup/validate-candidate` returns `{candidate}` containing exact nonce, original actor, resource, record ID, context UUID, original/current configuration hashes, authorization, submit capability and a `configuration_changed` blocker. It returns no entered values. Existing `GET /it/setup?review_resource=...` now accepts the original `actor_user_id` and returns `viewer_user_id`.
- The service checks fresh actor approval/permission and current record policy, then unions old/new/historical selected bindings and checks current staff eligibility, approved active Sites and related canonical record availability. Incomplete required values do not prevent authorized recovery. Historical list distinct validation uses per-index rules; a generic nested wildcard also captured Laravel's implicit primary and was removed after the regression demonstrated it.
- `use-setup-memory.ts` retains whole base/proposal/step/unknown submission in bounded RAM, checks a nonce-bound proof before Resume, preserves original hashes, isolates independent owned copies, rejects late/changed-context responses, and warns on full reload while any unsaved copy remains. The installed Team/Service and Queue wizards consume this adapter. Known server rejection remains editable; confirmed session expiry conceals fields until a fresh candidate proof, while confirmed denial removes the current retained copy.
- `w06-setup-memory-host-tests.txt`: **41 tests in three files passed**, 9.75s. Includes real component unmount/remount for all three wizards, expiry/denial concealment, original-version preservation, late JSON review cancellation, beforeunload warning and unknown submitted snapshot protection.
- `w06-setup-memory-host-eslint.txt`: seven-file ESLint exited 0; `w06-setup-memory-host-types.txt`: scoped Setup TypeScript exited 0 using `w06-setup-types.tsconfig.json`.
- First PHP candidate/version run: **20 tests, 167 assertions, 179.55s**, wrapper exit 0 and postflight schema absent (`it_ecf8078e78934d19`). The added repeated-history regression found the remaining nested-wildcard issue (21 passed, 1 failed). The corrected full rerun **passed22 tests177 assertions263.36s**, wrapper exit0 and postflight schema absent (`it_5b8af3351d5c4f44`), recorded in `w06-setup-candidate-final-tests.txt`.
- Final integrated command/RAM/wizard/workspace UI suite: **62 tests across five files passed10.84s** (`w06-setup-create-ui-tests.txt`); ten-file ESLint exit0 and scoped Setup strict TypeScript exit0. This supersedes the earlier41-case UI checkpoint. Backend receipts and concurrency are detailed separately below.
- No browser acceptance claimed for this new slice: root is verifying Build6, which predates these Setup RAM changes. Existing visual/Wizard browser evidence remains valid for the prior slice only.

Precise next step: root reviews/applies only the frozen000009 additive migration through its guarded gate, builds current assets, then verifies desktop Setup Back/Forward, explicit Resume and fresh access denial, plus lost-response create check/retry/cancel and actual saved-register refresh. Imported Setup source is frozen. No bare working migration, operational assignment change, provider call or real communication is authorized by this handoff.
