# PKG-02B v1 — exact candidate review

21 September 2026. Vehicle profile/readiness desktop mockup. **Design review pending; not approved for implementation.** Main and Stephan review the version and candidateId in manifest.json. Preserve this version; any later changes need a new version.

Preview: http://127.0.0.1:4336/PKG-02B/v1/

Source: docs/fleet-assets-audit/previews/PKG-02B/v1. Final bundle: `index--V2KTTov.js` and `index-DGCZXgZj.css`. The browser was reloaded after the final build and its loaded script URL was recorded. `/__preview` independently identifies PKG-02B-v1, worktree 5b0a, base 5307692ec59be84f3503c06354419b7da95be805 and synthetic=true. Manifest hashes cover the exact sources, dist, reference files and evidence.

## Authority and boundaries

Main-local handoff revision 3 and setup release revision 1 released one bounded design candidate. Designer task 01a0c2bb-fcff-7cb1-8bab-882d84477c6c remains on codex/pkg-02b-vehicle-profile-design at the verified base. Initial turn 01a0c2bb-ff65-7772-8369-725cfb24888a and post-crash continuation 01a0c2ea-bdb7-7860-97ba-086397152432 were verified as gpt-6-astra/xhigh in both actual metadata fields. Main independently reconfirmed the continuation, branch/base and unchanged application/guides after the crash. No setup gate was reopened.

One operating organisation. Roles, approved sites, ownership and privacy remain the boundary. All fixtures are fictional. No application/backend/schema/config/routes/tests were edited, no operational database was used, no notifications/tracker actions were taken and no implementation worker was launched. Git tracked diff remained empty; only the two PKG-02B documentation/preview directories were untracked. The existing dependency directory was reused by junction; no packages were installed. No commit, merge, push or public deployment was performed.

Read-only protected SHA256 values remained unchanged:

- DESIGN.md: C3D733AC6C5ECF12AC9E21E746C52109AD35EBF0FEF8D25675B35D16B7AC05AD
- design_styles/POPUP_STYLE_GUIDE.md: 3D41375AA9AAD71CFF5CFA58D2A2A60933BCE18EC957E74C0F7E7E6DEF28C7E1
- design_styles/WORK_RECORD_STYLE_GUIDE.md: 66908EF279682E2C9EB67412150B39FDBB403B2C8DE2C41E25260E07FCD5AA29
- Revision 10 master in Downloads: C4837AB675F9DFFDB6A8597636F49D5761DA114E6C155DC08E6BB8A209D63FD0

## Design and source mapping

The prior reuse-contract-v1.md is the detailed pre-design contract. Main reviewed its direction without approving the mockup.

- Asset/VehicleController identity and canonical vehicle route → one vehicle shell, home site, odometer and existing work links. No parallel resource identity.
- FleetServiceSchedule → separate next-due date/km, last completed date/km and service history. Source-linked work retains schedule ownership; completion never releases a restriction.
- Asset compliance fields/ComplianceController → visible WoF and registration dates, missing evidence and applicability states. Inspected defaults/hard-coded bands are not adopted as approved readiness policy. RUC source was not found in the inspected scope, so the primary fixture stays unknown.
- FleetChecklistTemplate/Run and MaintenanceCheckService → searchable template version, fictional original answers, observed/submitted dates, exact run reference and needs-assessment result when rule mapping is unknown.
- MaintenanceReportService → fixed vehicle/check source, Coordinator/backup route, manager-only existing-work link choice, original check preservation and retry identity. A manual report has no fabricated check source.
- Existing Maintenance profile/work routes and MaintenanceAttachmentController → bounded work destination, original references, notes, progress and local premium file staging/recovery. JPEG/PNG/PDF and 10 MiB are existing Maintenance attachment limits, not newly invented compliance policy.
- FleetVehicleStateSnapshot and FleetVehicleBooking → telemetry separated from readiness; source-owned upcoming dates and busy-only disclosure. No map/tracker command or booking implementation. Full calendar remains PKG-04.
- Current PageHeader/meters/rail/Find, TierTwoTabs, StatusBadge, Dialog, WizardShell/ReviewCard, searchable Command/Popover, DateTimeField/LeaveCalendarRange and FileDropzone/StagedFileCard → shared interaction and visual vocabulary. Shared sources remain unchanged at the base; only preview CSS clips decorative header overflow.

## Verification actually performed

Final TypeScript project check passed (exit 0). Final Vite build passed: 2,459 modules, 313.09 kB CSS and 626.25 kB JS. The sole build warning is the standalone bundle exceeding 500 kB; this is not an application production bundle. The post-crash browser console returned no warnings or errors through the final checks.

Final candidate checks, recorded in browser-observations.json:

- 1280×900, 1440×1000 and 1920×1080: document widths 1265, 1425 and 1905 respectively, with no horizontal document overflow. Header top 144, title top 162 and header scrollTop 0 after navigation. Screenshots were saved on separate stable calls and visually inspected.
- Empty evidence shows no fabricated odometer observation date or schedule source. Completing the first synthetic check retains it in the overview and check history.
- Two “No issue recorded” answers with unconfigured rules produce **Needs assessment**. Success, header and retained history agree; no pass or safety release is implied.
- Corrected Back to vehicle closes the check result to its source vehicle context.
- Routine service WO-0268 navigates to work-orders/268, with SCH-DEMO-07 as its source. Historical WO-0188 remains completed and its completion action is unavailable.
- Ready fixture shows reviewed illustrative evidence without active restriction or the unrelated condition estimate.
- Source detail Escape closes the dialog and restores focus to View RUC.
- Final overview, compliance, calendar and checklist review renders were inspected. Temporary viewport overrides were reset and the candidate tab was left open.

Earlier development checks, preserved in conversation/tool history and interim screenshots, were completed before the final bounded fixture corrections. They are supporting interaction evidence, not a claim that every flow was rerun against the final hash:

- All ten scenarios: active restriction; ready; unknown applicability/missing evidence; overdue service/check; completed work awaiting release; stale evidence/no tracker; empty; view-only; report-only; record denied. The scenario loop was also repeated after crash on the immediate prior bundle; final targeted checks above cover changed branches.
- Template search by keyboard, alternate version selection, date picker selection/Escape, required-answer validation/focus, retained notes and original answers in review, failed submission and exact run detail.
- Failed check into Maintenance review with original source/version, advisory date range and existing-work search/link. Report-only omits the privileged link selector. Manual reports do not attach a check source.
- Interrupted save retains draft and recovers one synthetic reference on retry. Search failure retains selection, Retry search works by Enter, Escape returns to the picker and the parent wizard stays open.
- Dirty close/discard confirmation retains data on Keep editing/Escape and clears only on discard. Wizard Tab remains within the dialog.
- Partial two-file upload produces one saved demo attachment and one failed file; retry preserves the saved reference and only retries the failure. No real upload was performed.
- Busy-only detail contains time and busy status without passenger, requester, destination or purpose. Denied view removes vehicle identity and actions. These are UI demonstrations, not server authorisation tests.

## Screenshot provenance

Only these files show the exact final bundle; each was visually opened after saving:

- 21-candidate-check-review-1440.png — empty fixture, original DEMO-3 answers before needs-assessment submission.
- 22-candidate-overview-1280.png — active restriction, narrow desktop.
- 23-candidate-overview-1440.png — primary review image.
- 24-candidate-calendar-1920.png — wide desktop contextual calendar.
- 25-candidate-compliance-1440.png — original evidence and unknown applicability.

Files 01–20 are retained pre-freeze development evidence, even where their filenames contain “final” or “frozen”. They do not identify the final candidate. In particular 02 was captured on validation rather than review, 09 shows the header clipping defect before correction, and 11 is a stale/misframed capture. Do not use them as final-layout acceptance evidence. Browser observations likewise preserve chronological interim bundle checks with their provenance.

## Limits and outstanding decisions

**Genuine 200% browser zoom is unverified.** The earlier Ctrl-plus attempt changed neither DPR, visualViewport scale nor layout width; no native zoom-control surface was available. Desktop viewport resizing and PKG-02A's user check are not counted as PKG-02B zoom proof. Phone/tablet are outside this scope. Dark theme is available in the preview but not claimed as a completed visual acceptance pass.

Native file chooser/real upload, durable storage, real HTTP retry/idempotency, production authorisation, backend policy and operational acceptance were not tested. Demo storage is in memory; some nested work/upload demonstrations reset on close. Bounded destinations explain unavailable operational actions rather than performing them. Full calendar and adjacent packages remain unreleased.

The remaining operating decisions are unchanged from reuse-contract-v1.md: approved compliance applicability/provenance/RUC source and freshness bands; approved check templates and rule/hold/retest mappings; consolidated readiness projection and odometer authority; actual Coordinator/backup/release grants and operating configuration; reconciliation of existing compliance/daily-check/service access/provenance gaps before implementation. Existing Maintenance authority policy is already adopted and is not being reopened.

Main must review this exact frozen candidate and Stephan must approve the exact mockup before any separately gated implementation. No earlier package is declared operationally accepted by this delivery.
