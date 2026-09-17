# Vendor, contracts and shared credentials — user-confirmed scope

The user requested this separate session while Knowledge is running. This supersedes earlier sequential work instructions; all other full-plan requirements remain. Existing role/site boundaries and independent work must be preserved. Implement meaningful feature slices first, followed by grouped relevant checks and affected-failure reruns.

## Vendors and commercial records (W23)

- Extend canonical vendor records with a usable directory and vendor details: contacts, support/after-hours contact, supplied services, accountable owner, active/retired state and relationships to Sites, systems/services, assets and Knowledge. Map the existing Finance vendor records before introducing any commercial links; do not duplicate a supplier master, purchasing ledger or asset inventory.
- Support explicit visibility at the record's Site or across the actor's approved Sites without copying vendor records or granting unrestricted access.
- Add contracts and supporting commercial files, including Word/PDF uploads, opening/preview or explicit original download, replacement versions and history. Track supplier agreements, licences, warranties, support contracts, domain/certificate renewals, notice periods, owners and evidence in their canonical records.
- User expressly confirmed contracts/commercial documents are visible to **Finance, organisation management AND site/house managers**, within their approved-site/record scope. Other vendor users, IT users and ordinary house staff do not gain contract visibility merely by accessing a vendor or credential. Restrict contract fields, document names/metadata, pricing/terms, file URLs/previews/downloads, relationship projections, search/export and notifications. Separate view and maintenance capabilities. Finance AP view is also seeded to auditors; it must not automatically become contract access.
- Implement renewal review/reminders with one owner follow-up, date changes updating future reminders, renewal evidence, retirement cancellation and retained audit history. No automatic real supplier communication.

## Shared credentials (W24)

- Reuse the same SiteCredential ID, encrypted storage/service and policy from both Sites and IT; relate masked references to vendors, systems and runbooks. No duplicate vault or secret fields inside Knowledge.
- Add explicit Site-only/all-approved-Sites visibility, separate metadata/reveal/copy/manage/audit permissions and the already user-approved opt-in for house staff assigned to that house to reveal/copy after all authentication/access checks.
- Historical SiteCredential.is_shareable is currently a review marker, not an access grant. Never silently turn existing true values into new staff access.
- Complete reauthentication/step-up, including SSO-only staff, audit before disclosure, truthful copy intent and clipboard outcome, automatic concealment/session/context/permission-loss handling and TOTP safeguards.
- Complete creation/edit/history/concurrent-edit handling, retirement, recovery/backup compatibility and rotation evidence. Distinguish changing an external password from attesting a change or maintaining encryption keys. Do not mark external rotation complete from a date-only button; do not rotate real credentials or rewrite keys as part of UI work.
- No plaintext secrets in list/search/export, screenshots, logs, generic drafts, Knowledge or AI context. Contract access and credential reveal/copy are independent grants.

## Coordination in the saved checkout

Knowledge task `01a09836-ba6c-72f2-8473-a91fa040721d` owns Knowledge implementation files, routes/web.php, shared navigation/integration files and the first build/test/browser window. This vendor session owns vendor/credential-specific models/controllers/services/pages plus NEW commercial records/files/migrations/tests and its own evidence/progress notes. Read existing changes before editing. Do not edit Knowledge files, route registration, shared navigation or aggregate implementation-progress.md concurrently; request integration from the Knowledge task using a precise file/change request and coordinate ownership transfer if needed. New routes may be defined in a separate vendor/credential route file before requesting registration.

Before any build, database-heavy test or guarded browser runtime, coordinate with the Knowledge task. Source edits by BOTH tasks must stop during a shared-checkout browser runtime. Serialize database-intensive imports and shared asset builds. Preserve independent My Day/Governance/Sites/shared work. No worktrees or further agents. The parent is read-only for application source.

Read full authoritative plan/audit, root AGENTS.md, applicable design and single-tenant architecture. W23/W24 are currently Planned in the aggregate ledger; update actual evidence honestly through this session's own notes and send integration updates to the Knowledge task. Existing W01 capability/access fixes are foundations, not completed W24 acceptance. Keep all dependencies and E19/E20/E21 release acceptance in scope.

Working DB oblivion_findings_codex_test must never be reset. Use only guarded isolated backend tests and fresh current-build in-app browser at unchanged dimensions, with exact cleanup/independent postflight. Previous token3060db40e95144d9 is fully cleaned and must not be reused. No deployment, real communications, live providers, production AI, GitHub push/retry, destructive production action or unrelated staging. Apply migrations to the working DB only through its separately reviewed additive migration process.
