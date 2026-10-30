# PKG-09B — Main technical review

Owner: MAIN ASTRA. Revision: 3. Updated: 2026-09-28. **Privacy, authenticated-shell and stale-fixture findings closed; actual zoom gate remains. Not yet approved for integration.**

## Frozen successor — 50716b9cca0473ee4082e329ce6ff1347833f714

Main independently reviewed the exact nine-file successor diff: the only executable change is one success-fixture consent name, from Asset Location Tracking (Safety) to the production-required Personal Tracker (Wandering Risk). Production privacy guards and all48 previously reviewed application/test files remain unchanged. Main verifies all49 current manifest paths against working files and committed blobs with no mismatches/undeclared source; `PKG-09B-main-fixture-source-verification.json` retains this result.

Main parsed the new owner JUnit: **6 cases/136 assertions,zero failures/errors/skips,257.983seconds**. The four former HTTP failures, generic-consent denial and consent-withdrawal coverage pass. The packet preserves the prior failure evidence and records exact disposable-database cleanup. Main judges the original failures to be stale success fixtures rather than a production guard regression; the bounded correction closes this verification gap without weakening access. Owner's latest deduplicated scoped result is48 passing cases/838 assertions across the recorded runs, not a new whole-repository pass.

Base926b4981 remains the reviewed application baseline. Current local/GitHub985488e27aa99bf2fd9fffd227f34025ba15a296 adds programme documentation only; Main verifies no app/test delta. Reports is now frozen. Integration with the unpublished People Locations candidate has four known shared-file conflicts and must retain both reviewed contracts; see `MAIN-consolidation-integration-preflight.md`. T09B-03 genuine125% remains unverified and the explicit acceptance amendment remains pending. No publication slot or final acceptance is granted by this review.

## Correction review — ba2e0aeec341ff68dedbfafd4fb4d64642e8bd5b

Main independently verifies all48 current application/test hashes against both working files and the exact commit, with no unmanifested source. Base remains926b4981b0289da08a20baca0995117fb53e413e. See `PKG-09B-main-corrected-source-verification.json` and its repeatable verifier.

T09B-01 is closed: Main's unchanged original real-service/HTTP privacy regressions now pass **2 tests/8 assertions**,252.399seconds,exit0. Previously both failed. The isolated database is removed with zero matching schemas/processes; original failure evidence is preserved. New evidence: `PKG-09B-main-corrected-privacy.log/.xml` and `PKG-09B-main-corrected-cleanup.json`. Main reviewed contributor recording before projection/aggregation, separate comparison evidence, conservative whole-result invalidation, legacy-run denial, retained canonical device identity and post-render export checks. Evidence remains encrypted and excluded from public result metadata. Ordinary contributor changes can require regeneration; realistic-volume performance is not certified.

T09B-02 is closed: Main independently reran the unchanged canonical permission probe. Dashboard-only authority now yields canonical=false/client=false/staff=false; a narrow alert reader yields true/true/true. Actual source queries and cached contributor checks apply ControlRoomAlertAccessService's readable scope, including controlled-content rules. Main inspected the submitted real endpoint permission-withdrawal, wrong-site/person and controlled-content cases; their passes remain owner-attributed. No production permission was broadened.

T09B-04 is closed: Main independently used the authenticated Laravel application at `http://127.0.0.1:8974/fleet-assets/reports/builder`, signed in as the existing synthetic Reports QA user. The rendered real AppLayout contains Fleet navigation and the six-step builder. A new synthetic-purpose preview returned20 journeys,570km and one site group. The export dialog fits1280x720 (x280,y202.625,w720,h314.75); Escape closes it and restores focus to Export. Loaded application entry is `/build/assets/app-Blb7C51n.js`. Screenshot: `PKG-09B-main-authenticated-builder.png`. This is ordinary100% browser evidence (DPR1,CSSzoom1), not a125% claim. Owner's actual XLSX/PDF/CSV/JSON downloads remain separately attributed.

Four inherited canonical-history success fixtures use an obsolete consent type. Main inspected the fixture and existing restrictive consent constants; the same Designer is authorized to correct only that fixture, retain production privacy guards and rerun its four HTTP cases with generic-consent denial/withdrawal coverage. The successor commit and results must be reviewed before approval. T09B-03 actual125% remains unverified; an explicit programme acceptance amendment is pending. Stephan's latest request independently authorizes local and GitHub main publication, but no slot or final user acceptance is inferred.

The following original review is retained as historical evidence; its two P1 findings and authenticated-shell gap are superseded by the correction findings above.

Candidate e4bb17b56405a748072bf83e8c30af323822e0bc, branch codex/fleet-personal-reports, base926b4981b0289da08a20baca0995117fb53e413e. Actual implementation and fidelity approvals are independently verified in the existing Designer chat. The owner's final packet records the additional client/staff tracker scope and conditional deferrals. No production migration, merge or publication is approved by this review.

## Integrity and scope

Main independently matches all46 changed application/test files,94 payload files and122 frozen v1/v2 design files. No unmanifested source or content mismatch; seven evidence logs differ only by Git newline normalization. See PKG-09B-main-source-verification.json and its repeatable verifier. The candidate retains single-organisation roles/sites/canonical ownership; Rory references are unchanged.

Read source includes the controller/routes, report access/run/queue/schedule/export services, source and personal evidence readers, supplementary Fleet source calculations, actor/site/session resolution, rendering/state handling, migration/runtime requirements and submitted tests. Good controls include current actor reloading, requester-owned encrypted results, server-side formula validation, site/resource scopes, client consent/assignment checks, post-render export authorization, retained unknown values, source limits and immutable saved versions. These controls do not close the findings below.

## T09B-01 — P1: cached Fleet reports survive source privacy withdrawal

ReportRuns::result rechecks the allowed asset/source scope but not the current privacy eligibility of the canonical source records retained in a generated result. ReportAccess's Fleet fingerprint contains site/asset IDs and available source keys, not the trip's is_personal/consent_blocked state. The generation reader filters those flags correctly; subsequent cached reads and exports reuse the earlier rows and derived totals.

Main's real-service/HTTP disposable-database probe creates one business journey, generates a report, changes the canonical journey to personal or consent-blocked, and verifies a fresh report now has zero rows. The old report's GET still returns200 instead of denying disclosure. Both cases fail: **2 tests,8 assertions,2 failures**,360.740seconds; HTTP and queues isolated/faked, no operating data. Database cleanup independently verified: zero remaining matching schemas. Evidence: PKG09BMainPrivacyReviewTest.php, PKG-09B-main-privacy.log/.xml and metadata-only checker.

Required correction: bind cached results to current source privacy/readability, including affected comparison/aggregate sources, and invalidate or safely rebuild the whole result when protected source evidence changes. Recheck before status/result and after export rendering. Do not remove filtering, preserve stale totals after dropping rows, or treat a24-hour expiry as current authorization. Add regression coverage for permitted control, later personal/consent withdrawal and export denial.

## T09B-02 — P1: alert reports use the wrong canonical capability

config/operational-reports.php requires controlRoom.viewAny for client_alerts/staff_alerts. ReportAccess::sources accepts that capability, and ReportSourceReader reads ControlRoomAlert directly without applying ControlRoomAlertAccessService's readable scope. Canonical canRead requires controlRoom.alerts.view or controlRoom.alerts.manage, with record/site/content policy layered on top. A dashboard-only reader is therefore offered alert sources while an authorized narrow alert reader is denied them.

The database-free probe invokes the actual ReportAccess::sources and ControlRoomAlertAccessService::canRead with controlled actor permissions: dashboard-only canonical=false/report sources=true; narrow alert reader canonical=true/report sources=false. Evidence: PKG-09B-main-alert-permission-probe.php and .json. This proves the capability mismatch; the probe is not a full endpoint/record-disclosure test.

Required correction: reuse canonical readable alert capability and record/site/content scope for client/staff alert reports, retain personal tracking/session authority separately, and include current canonical permission in cached-result/export invalidation. Add real endpoint tests for dashboard-only denial, narrow-reader permitted data, other-site/record denial and permission withdrawal. Do not simply grant broader roles or weaken Control Room policy.

## Remaining verification gates

- T09B-03: genuine browser zoom remains unverified, as candidly reported by owner. Viewport tests are not zoom evidence.
- T09B-04: application browser fidelity currently uses the real component with a synthetic API/layout adapter. The packet explicitly retains authenticated application-shell smoke verification as outstanding. Real PHP endpoint tests are useful but do not establish the stitched rendered application flow.
- Reconcile shared source with People Locations before final combined acceptance, especially IntegrationEventHistoryService, LoneWorkerController and canonical personal authority. Preserve both reviewed policies instead of accepting an automatic merge on faith.

Owner's20 tests/265 assertions, build/types/lint and visual evidence remain owner-attributed; Main has not represented them as independent passes. Main's new failures supersede an unconditional readiness claim. Repository-wide hosted tests/lint/visual are failing on the existing Main baseline; those failures require explicit classification, not a blanket waiver.

Same Astra/xhigh Designer owns correction. At this checkpoint03 holds the isolated application writer slot;09B remains frozen until Main explicitly transfers it. No new worker, guide changes, Main writes, integration or operating activation.
