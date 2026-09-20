# PKG-01 v1 — proposed scope, reuse and source contracts

Owner: DESIGNER ASTRA. 2026-09-19. Design proposal awaiting exact mockup/scope approval; not an implementation handoff.

## Bounded scope to approve

Maintenance work queue and its report/check/detail/hold-to-release journey, approved WF-01 with minimum WF-02 booking readiness and WF-09 financial safeguards. Findings FA-F06/T01/T02 plus F02/F03/I01 interfaces. Preserve existing operational records and canonical HR/Finance/Client/Devices/Control Room ownership. This is one operating organisation across approved sites.

The implementation outcome represented by v1 is an owned queue with trustworthy filtering/counts; contextual problem reports and duplicate linking; immutable inspection submissions with attributable amendments; restricted work triage, ownership/handover, staff-mediated provider planning/evidence and completion; separate permission-checked release; and minimum booking/task/Finance status and recovery. Each mutation needs meaningful validation, conflict/error handling and attributable history.

Outside this approval candidate: broad fleet calendar redesign, transport, stocktake, client/care or passenger detail, privacy portal, maps, remote/device transport, provider self-service, automated provider requests, invoice/payment UI, global Finance rewrite, a second task system, unrelated pages, mobile/native/offline work, and policy values. A future approved implementation may need focused underlying services/schema and tests, but no migration or file-level implementation plan is authorised by this design record.

## Reuse decisions

- Direct imports from the e62b569 worktree: `PageHeader` family (index/profile, search, actions, meter rail, filters, tabs); `TierTwoTabs`; `EntityTable`, `EntityCard`, entity cells and shared menus; `WizardShell`, `WizardStepPane`, review/success panes; `StatusBadge`; shared buttons/dialogs/tooltips; EmptyState, ErrorState and SkeletonTable. Existing `resources/css/app.css` tokens and Tailwind sources are compiled read-only.
- No new KPI grid or custom table/menu/wizard system. Header metrics derive from the same synthetic site/search scope as the queue. Existing shared status semantics distinguish work, restriction, check outcome, release, delivery and Finance state.
- Preview-only adapters: a static application shell because live Inertia/auth providers are deliberately absent; 256px sidebar, existing ink/primary token treatment, desktop content gutters; domain evidence/fact sections; synthetic in-memory state and scenario harness. The harness is outside the product area. No fixture switch is proposed as an operational control.
- Preview-only `.eh-header { overflow: clip }` avoids the imported header retaining `scrollTop` after focus moves. If relevant in the real app, the implementer must reproduce and resolve it through the approved component path; this mockup does not authorise editing DESIGN.md or another guide.
- Provider appointment fields show the required planning/confirmation distinction. Real implementation should use the current shared calendar/date-time interaction where suitable. No new shared calendar or invitation transport is designed here.
- All reference guides remain read-only. The dependency source in Main's checkout is used only for the existing runtime/packages; application components come from this isolated baseline.

## Required backend contracts

These are behavioral/data boundaries for later review, not invented endpoints or an approved schema.

1. **Authorised queue/read model.** Resolve role, permission and approved site for both list and direct-object access. Records without a valid authorised site cannot escape the boundary. Apply a common scope to records, search, filters and counts; unavailable data is unknown, not zero. Return canonical resource/source IDs, accountable owner, waiting state, next action, explicit target, hold/release projection and freshness/version. Define ownership consistently across fleet/static assets and source relations. No tenant transport or tenant fixture is introduced.
2. **Problem report.** Preserve original observations, observed/submitted time, author, resource/site, private evidence IDs and request identity. A duplicate link retains both report identities and relates only authorised matching resource/work. A retry of the same request resolves to the same outcome. Unknown save results do not cause blind duplicate submission. Draft recovery needs an explicit persistence/expiry/privacy decision; the preview only retains within its tab. Provide reporter acknowledgement/status and owned delivery failure separately from the saved report.
3. **Immutable check.** On submission, validate the exact effective approved template/version, snapshot question/rule text, required and conditional evidence rules, answers, result derivation, author/time/resource/site and attachment provenance. Missing/unknown requirements cannot yield Passed. Freeze the submitted source; later changes are attributable append-only corrections or a new run, never a rewrite against today's template. Legacy runs lacking a full trustworthy snapshot must retain provenance and uncertainty; do not fabricate historical version evidence during a backfill.
4. **Work/restriction transaction.** Link report/check/work to a canonical source-owned restriction with actor/reason/effective time. Completion of repair changes work state but cannot clear the restriction. Unknown, stale or conflicting source data remains blocked/reviewable. All paths, including bulk actions, must execute the same guarded transition rules and audit/effects. Use version-aware conflict handling and retain user entries. Queue statuses must reflect the source state, not a UI toggle.
5. **Ownership/provider/evidence.** Keep current accountable owner until a valid recipient acknowledgement; preserve proposed owner, handover reason and timing. Keep internal appointment/request, external confirmation/cancellation, service completion and evidence as separate facts. Staff record provider response method/reference; no internal event implies provider acceptance. Enforce scoped attachment access and attributable metadata. Any scheduling shared-service use preserves canonical calendar ownership.
6. **Independent release.** Re-read current restriction, repair, required retest/evidence, custody and approved reviewer authority/category rules at commit. Deny unresolved or stale prerequisites atomically. Store reviewer, effective time, reason, restriction/source IDs and source versions with the release decision. Retain original failed evidence and history. No blanket safety override or automatic expiry; no hardcoded invented release role. Permission and any separation-of-duties rule await policy approval.
7. **Booking and task effects.** Apply current readiness checks on request, approval, change and checkout; an available calendar slot is insufficient. A hold/release does not silently move, cancel or approve existing reservations. Return only permitted booking IDs/times/resource/status to maintenance, with owned follow-up. Use the shared task identity/source ownership and notification delivery/retry state. Durable effects and retries must be idempotent and recoverable without pretending the core work mutation failed when only delivery failed.
8. **Finance boundary.** Repair/release events are evidence, not financial approval. Estimate remains planning data and never substitutes for actual approved cost. Finance owns approval, posting, correction/reversal and accounting rules. Handoff/retry uses stable source identity and exposes owned failure; no duplicate expense/journal. The minimum package change must prevent an unapproved maintenance completion path from posting and preserve existing records and Finance authority; any wider Finance redesign requires separate scope.

## Revalidated source basis

Read-only inspection at e62b569 reconfirmed these relevant audit concerns; none was executed as an operational test:

- `app/Models/FleetChecklistRun.php` retains template ID/answers; no demonstrated exact-version immutable snapshot contract.
- `app/Http/Controllers/FleetAssets/InspectionController.php` derives pass from absence of failure and reads the current template for detail, which cannot by itself prove immutable historical rules.
- `app/Http/Controllers/FleetAssets/WorkOrderController.php` has scope paths that skip absent site context, broad asset/related-check fallbacks, and single-update versus raw bulk-update behavior. Every proposed path requires direct-object/scoped revalidation.
- `app/Observers/FleetWorkOrderObserver.php` completion dispatch uses actual cost falling back to estimate.
- `app/Domain/Finance/Services/FinancialEventService.php` provides source/idempotency handling, but the observed completion flow posts without a separate demonstrated approval gate. Reuse existing safe identity mechanisms while preserving Finance ownership; do not infer that duplicate protection equals approval.

## Smallest unresolved policy decisions

1. **Checks and restrictions:** approved inspection templates/categories, required/conditional evidence, failure/unknown consequences and retest requirements. The two sample questions and brake hold are illustrative fixtures, not adopted rules.
2. **Release and custody:** permitted internal release authority by resource/site, any separation of duties, and acceptable custody/retest/evidence prerequisites. Until supplied, release remains blocked; the hypothetical fixture approves none of these.
3. **Accountable response:** owning/covering roles, handover acknowledgement, response/target rules and reporter/escalation delivery routing. v1 uses manually assigned targets without inventing SLAs or sending messages.
4. **Finance handoff:** approved actual-cost evidence, decision authority and posting/correction prerequisites for maintenance expenses. No tax rate, approval threshold or estimate fallback is assumed.

These policy questions should be answered by their operational owners through Main. The mockup/scope approval is a distinct gate and must not be interpreted as approval of these values.

## Review gate

Approve or request a design iteration for the exact v1 identity in [artifact-manifest-v1.json](artifact-manifest-v1.json), and this bounded scope. Do not launch/select an Implementer or begin implementation until Stephan explicitly approves. Main retains subsequent exact-code technical approval and publication/acceptance gates. No approval is recorded by this document.
