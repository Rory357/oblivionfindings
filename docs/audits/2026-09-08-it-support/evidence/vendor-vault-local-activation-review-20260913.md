# Narrow local Vendor/vault activation review

## Current correction checkpoint — 13 September 2026

The user reported two navigation entries for the combined register and an incorrect Add Vendor modal, and explicitly approved the preceding concrete five-role contract-access proposal.

The shared navigation owner consolidated the IT register links into one **Vendors & Credentials** entry, opening the permitted initial tab and remaining active across both tabs and vendor details. The Sites contextual link is consistent. The vendor Add/Edit form now shares the canonical WizardShell with Vendor, Contact, Compliance and Review steps, required-field validation even after free step navigation, server-error return to the owning step, dirty-close confirmation, a create success pane and Add another. Site-context editing retains the site and all untouched compliance fields. Five new workflow tests plus eight existing register tests pass; scoped lint passes. No Vendor TypeScript diagnostics remain; unrelated My Day/Governance diagnostics are tracked separately.

The user-approved local permission operation is **applied**: c89f29 terminal 0, fingerprint a8e182e2df72927d17a036cb89b2d9938f749a4471a4a81dd74413a43b597d62. Exactly ten contract view/manage grants were added to the existing Finance, CEO, COO, CFO and Provider Manager roles, with three permission definitions. Existing definitions/grants, roles, user assignments, personal overrides and migrations were preserved. No credential-copy, personal or house/site-manager grant was added. Backup storage/app/private/vendor-vault-permissions-5e8916a2e9b8efaf.json.enc was encrypted and roundtrip-verified; SHA256 342425ef2e8b285a748ca1c871fd5e7487e317aef33f7f2dc8758dea7a2d0fce. Fresh post-preview f22cda reported no remaining definitions/grants. The prior automatic-review hold below is historical and was resolved by the user's explicit approval; site/house-manager role mapping remains pending.

The coordinated normal shared build passed: session 5318, terminal result 80f144, 05:57:04–06:01:19 UTC (4m15s). Current asset app-1if2QQuE.js; manifest SHA256 39b9420c6e0aace482bd5b8491aac75e345335644519dfc7ee4222dd876d29f9; public/hot absent. Current actual-Herd navigation/wizard browser verification PASSED: one combined IT entry, permission-appropriate initial tab, four-step Add Vendor flow, retained review and discard/reset, no horizontal overflow or console errors at1280x720. No vendor saved or secret revealed. Own tabs closed;7054-source postflight f70418 showed19 independent Governance page changes only, with served manifest and IT dependencies unchanged. Full current proof is in evidence/vendor-vault-browser-20260913.md. This build is local, not a production deployment.

Earlier checkpoints below remain historical; broader W23/W24 acceptance is not declared complete by these corrections.



## Current result — exact local schema activation passed

After the isolated browser cleanup and separately authorized Overview local-main integration at86dee9068, Knowledge activated only its000002/000003 migrations and scanner/upload configuration. Its registry checkpoint was1047. The Vendor gate then refreshed preview3a0500035613d77a5bd95d814c40091b3526b5123ea01e06e31fc05e3475c853 and applied only050/051 in session59765, terminal0.

All seven reconciliation checks passed: original vendor/credential/audit rows and ciphertext unchanged; canonical dependency/access-grant counts unchanged; exactly two registry additions1047→1049; unrelated pending migrations unchanged; reviewed design content unchanged; new house-staff defaults false; all new tables empty. Existing vendor count0, credential count2 and audit count2 are retained. No shared credential was decrypted, rotated or re-encrypted. No permission seeder was run.

Encrypted backup storage/app/private/vendor-vault-activation-aea46aa9d1a112ec/original-schema-and-rows.jsonl.enc passed roundtrip verification; SHA2564cbb898685daf58825f798d657423c36ab606c22d4abd5968eaf05d3e35e1460. Complete activation data is retained alongside it in activation.json and in the task artifact directory as vendor-vault-local-activation-applied.json.

The original11-file browser design baseline remains unchanged as evidence. The gate explicitly permits only the approved integration transitions recorded in mockups/service-desk-overview/implementation/local-sync/protected-change-report.json: DESIGN.md2545b66f…→c7a783f8…, PAGE_HEADER_STYLE_GUIDE.md fc91560e…→33aeeb0e…, and binds the integrated PageHeader component to fbd198fe…. Other protected design content remains at the original hashes; no blanket baseline reset occurred.

Source is frozen after the one-line global breadcrumb correction and focused lint pass. The final shared build and ordinary-Herd navigation verification remain next. Contract permission activation remains pending the user's site/house-manager role clarification; navigation does not imply new commercial or secret-disclosure grants.

## Concrete permission proposal — held for explicit approval

Read-only preview4c9d40 succeeded after schema activation. Saved proposal: vendor-vault-local-permissions-pending-review.json in the task artifact directory, fingerprint91b7eb25ba502fe9603883ce508026eadd67c1df4831b8aabcccfe45c5f95f1a. No permission or account changes were made.

The proposed change creates vendors.contracts.view and vendors.contracts.manage and assigns both to the existing CEO(role4), COO(role5), CFO(role6), Provider Manager(role12) and Finance(role23) roles. This permits their currently authorized members to view and maintain vendor agreements, restricted terms/pricing and private supporting files within existing approved-site, record-sharing, current-site and explicit-denial rules. It creates the independent credentials.copy definition without assigning it to any role or person. Existing role assignments, personal overrides and all other grants are preserved.

House/site-manager role identity remains unresolved. No Team Lead/Coordinator grant and no new role creation is included. General IT/admin/auditor roles receive no contract grant through this proposal.

Automatic approval review rejected Knowledge's proposed coordination message about these grants, citing insufficiently specific trusted user authorization for recipients/resources/scope. Permission mutations are held; no seeder/apply call has been attempted and no indirect workaround will be used. The user must explicitly approve the concrete role/resource/action scope before this step proceeds. Unaffected local navigation verification continues.

## Earlier review history

Read-only preflight passed. No backup, migration, permission grant, external rotation or key rewrite has been performed by this session at this checkpoint. Apply only after exact isolated-browser cleanup and the coordinated exclusive local database window.

The preview found both exact migrations pending, with registry count1045. Existing Vendor rows:0; credential rows:2; credential audit rows:2. The gate recorded hashes of original columns/rows/ciphertext without emitting their values. SQL review contains only the expected new tables, nullable/defaulted columns, indexes/foreign keys and the audit-action type widening. Preview fingerprint:24cfd37626069d2879946e313c2e369005789255bb7c32fb16255d695217843d. Refresh this preview after any other local migration or record change; the fingerprint includes those invariants and must not be reused against changed state.

The unexecuted gate is in the task's visualization directory as vendor-vault-local-activation.php. It targets only oblivion_findings_codex_test in the canonical local checkout on loopback MySQL, with local host identity, uncached configuration, array mail, synchronous queue and disabled broadcasting. The gate uses the same local advisory lock as the established IT additive migration process.

Exact migration sources:

- 2026_09_13_000050_extend_vendor_commercial_and_shared_vault.php: add nullable/defaulted vendor and credential lifecycle columns, agreements/events/private file versions/owner follow-ups and encrypted credential versions. Existing house-staff access defaults false; legacy is_shareable is unchanged. No credential values are read through the cipher service or rewritten.
- 2026_09_13_000051_complete_vendor_vault_boundaries.php: add nullable create idempotency keys and vendor references, widen the audit action ENUM to varchar40, add nullable unique copy-intent correlation and personal MFA replay state. The gate verifies the exact old action type and maximum retained value length first.

The gate refuses missing canonical dependencies, unexpected existing columns/tables, mixed migration registration, changed design/source/schema/registry/data fingerprints, cached or nonlocal configuration, and unreviewed apply arguments. It previews SQL in a read-only transaction. It never runs migrate-all, schema import, reset, rollback or seeds.

Before DDL it writes an exclusive new backup beneath storage/app/private, encrypts each original table-definition/row and migration-registry entry, flushes it, and verifies the encrypted backup by checksum. The backup contains original ciphertext as opaque data, without decrypting shared credentials. After the exact two files run, it checks original columns/rows/ciphertext, canonical dependency and access-grant counts, exactly two migration registrations, unchanged unrelated pending migrations and protected design files, empty new tables and false house-staff defaults. Partial failure stops for forward inspection.

Permission activation remains a distinct reviewed step. VendorVaultPermissionsSeeder creates separate vendors.contracts.view and vendors.contracts.manage capabilities and attaches them only to the agreed exact Finance/organisation-management/site-management role catalogue. It creates credentials.copy without copying reveal grants. No permission seeder is part of the schema gate. Review the actual role/capability difference before any local grant application.

The complete protected-design baseline contains the original ten reviewed Markdown files plus the loader demonstration, whose hash also matches the older saved baseline. No design file was edited.

The separate default-read-only vendor-vault-local-permissions.php gate is now prepared in the same task artifact directory. Its preview found three missing definitions and ten contract grants for the existing CEO/COO/CFO/Provider Manager/Finance roles. It adds no copy grant, user override or role assignment. The house_manager/site_manager aliases do not exist in the actual catalogue; the user has been asked to identify the existing role used for site/house managers. Do not infer that all Coordinators or Team Leads should receive commercial access. No grant has been applied.

The permission gate requires a fresh source/data fingerprint and the exact050/051 migrations already applied, takes the same exclusive local lock, verifies an encrypted permissions/grants backup, and runs only the reviewed seeder inside a transaction. It compares all previous definitions/grants, roles, role assignments, user overrides and the migration registry before commit; only the previewed additions are permitted. Refresh its preview after local integration/migrations and any approved role-map correction.

The isolated browser runtime has now been fully removed (cleanup42424 and independent postflight88355d both exited0). Local activation is still waiting for coordinated Overview Git integration and Knowledge's prior activation window. Source drift in16 ticket-work files during the browser window is recorded separately and must be preserved/reviewed; all11 protected design files remain unchanged.
