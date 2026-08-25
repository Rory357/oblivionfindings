#!/usr/bin/env python3
"""Materialize the fresh three-part RUN-105 page semantic review.

Only the twenty explicit OWNER_PAGE decisions authorize a later owner-only
overlay. Three shared pages and one evidence gap remain reviewed non-owners.
This receipt itself grants no ownership, runtime, browser, benchmark, Pass,
finding, or completion credit.
"""

from __future__ import annotations

import hashlib
import json
import os
import subprocess
from collections import Counter
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_PATH = AUDIT_DIR / "evidence/source/raw-run-105r-independent-outcome-neutral-page-render-owner-review-wave-14.json"
COHORT_PATH = AUDIT_DIR / "evidence/source/root-run-105-outcome-neutral-page-render-owner-cohort-wave-14.json"

AUDIT_HEAD = "a5919b5fe738606314f3757363a6741868a6fb1a"
AUDIT_TREE = "f325346946835b43f6c783a2ecca335e1087df99"
COHORT_CHECKPOINT = "ed561394411fff4caaffd8b24290bf06bae9bd22"
COHORT_CHECKPOINT_TREE = "b57fc91fb81e2a12e8e830104603a5fdf9b1546b"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
COHORT_SHA256 = "4d6868c06a4c94c708e0934682e0c9724b71fc104c3751d02d0acfd3a95370bc"
COHORT_GENERATOR_SHA256 = "564c37de4525a4587c99d455fa08c6a4a4557441551c6ac5628bd8ae7ca1d31a"

EXPECTED_COHORT_IDENTITY = {
    "page_record_id_list_sha256": "a2417deef667069b3ac51252f508a973b54ed14470a5dea69259016ebe9aae20",
    "page_path_list_sha256": "9c453b6cc7303ef76523f32f2ec3f18828f5e0a9e0198109b129a969d09f5192",
    "feature_id_list_sha256": "87364e111d2b2d4d117c1202b6e8a667aefe7287511b8119ef2428992efb3f0c",
    "new_feature_id_list_sha256": "e00b769ff8f50fc42f093fc0c373720b1c98aca6738118d5d668e08401aa82a2",
    "render_anchor_list_sha256": "eb6bba04cc4d01c13f072007f1ad83a9ddd2a8aa03518252d0e02a2b28638ad4",
    "page_feature_key_list_sha256": "d466f13daa2c1419f9d10ac9e25951ca9bff92c1b09fe4e1b22ca7efd161856c",
    "candidate_record_sha256_list_sha256": "975397e85756417e66be3a451366961e32c4d7cc24792f8863a14c6db42c116b",
    "records_sha256": "5dd86da895cefa7478299f0a37fe2e372f2121b685cb684095abf132223a0839",
}

DECISIONS: dict[str, dict[str, Any]] = {
    "RUN105-PAGE-RENDER-01": {
        "outcome": "OWNER_PAGE",
        "rationale": "The complete page only reviews, updates, and releases one legal hold, and the exact edit action renders that same lifecycle surface.",
        "source_loci": ["resources/js/pages/privacy/legal-holds/edit.tsx:1-286", "app/Http/Controllers/LegalHoldController.php:106-152"],
        "discrepancies": [],
    },
    "RUN105-PAGE-RENDER-02": {
        "outcome": "SHARED_RELATION",
        "rationale": "The thin event-detail shell imports a shared EventDetailDialog and the canonical matrix assigns this exact page to event closure, handover, investigation, WorkSafe, and register-related jobs.",
        "source_loci": ["resources/js/pages/health-safety/events/show.tsx:1-76", "resources/js/components/health-safety/event-detail-dialog.tsx:717-3903", "app/Http/Controllers/HealthSafety/HsEventController.php:309-316", "app/Http/Controllers/HealthSafety/HsEventController.php:605-1055", "03-feature-to-benchmark-matrix.csv:166-180"],
        "discrepancies": ["Complete-page and matrix review rejects the packet's singleton projection as a multi-feature shared relation."],
    },
    "RUN105-PAGE-RENDER-03": {
        "outcome": "OWNER_PAGE",
        "rationale": "The page and controller exclusively create the authenticated user's personal calendar connection metadata, distinct from approved-Site resource integration.",
        "source_loci": ["resources/js/pages/operations/calendar-sync/Create.tsx:1-166", "app/Http/Controllers/Operations/CalendarSyncController.php:27-57", "03-feature-to-benchmark-matrix.csv:230"],
        "discrepancies": [],
    },
    "RUN105-PAGE-RENDER-04": {
        "outcome": "EVIDENCE_GAP",
        "rationale": "The ActionItem list is semantically plausible, but its material generated showAction import is absent at the pinned application commit and build resolution was not executed, so sole page ownership cannot close.",
        "source_loci": ["resources/js/pages/Governance/Actions/Index.tsx:1-177", "resources/js/pages/Governance/Actions/Index.tsx:8", "app/Domain/Governance/Http/Controllers/ActionItemController.php:13-54", "evidence/source/root-run-084-full-inertia-page-graph-wave-09.json:337-346", ".gitignore:10", "03-feature-to-benchmark-matrix.csv:110"],
        "discrepancies": ["RUN-105's condensed page graph omits the pinned unresolved generated import; the record remains tagged within the residual."],
    },
    "RUN105-PAGE-RENDER-05": {
        "outcome": "OWNER_PAGE",
        "rationale": "The dedicated page reviews and filters overdue Site corrective-action reporting and has one exact SiteReportingController render owner.",
        "source_loci": ["resources/js/pages/sites/reports/overdue-actions.tsx:54-282", "app/Http/Controllers/Sites/SiteReportingController.php:277-304", "routes/sites.php:595-597"],
        "discrepancies": ["The page covers review rather than export, and assigned-to filtering supplied by the controller is not exposed by the page."],
    },
    "RUN105-PAGE-RENDER-06": {
        "outcome": "OWNER_PAGE",
        "rationale": "The complete page gathers candidate and application data and the controller creates both records for the candidate-application lifecycle.",
        "source_loci": ["resources/js/pages/hr/candidates/create.tsx:42-412", "app/Http/Controllers/Hr/CandidateController.php:56-172", "app/Domain/Hr/Services/RecruitmentService.php:48-152"],
        "discrepancies": ["The supplied sources prop and target-Site requirement diverge from parts of the page UI, without creating competing ownership."],
    },
    "RUN105-PAGE-RENDER-07": {
        "outcome": "OWNER_PAGE",
        "rationale": "The page generates and exports the dated mileage-reimbursement report and depends on the matching Fleet report data action.",
        "source_loci": ["resources/js/pages/fleet-assets/reports/reimbursement.tsx:35-346", "app/Http/Controllers/FleetAssets/ReportController.php:456-507", "routes/fleet-assets.php:333-342"],
        "discrepancies": ["The packet render slice omits the material reimbursementData endpoint; no Site, CSV-quality, or runtime credit follows."],
    },
    "RUN105-PAGE-RENDER-08": {
        "outcome": "OWNER_PAGE",
        "rationale": "The dedicated create-protocol page and its material ProtocolForm implement the create portion of the clinical protocol lifecycle.",
        "source_loci": ["resources/js/pages/health-clinical/protocols/Create.tsx:26-49", "resources/js/components/clinical/protocol-form.tsx:63-399", "app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:63-89", "app/Domain/Clinical/Services/ClinicalProtocolService.php:41-95"],
        "discrepancies": ["The packet graph omits the material ProtocolForm import, resolved by complete-source review."],
    },
    "RUN105-PAGE-RENDER-09": {
        "outcome": "OWNER_PAGE",
        "rationale": "The page is the authorised Control Room device-signal projection and explicitly separates canonical inventory ownership from this live-map journey.",
        "source_loci": ["resources/js/pages/control-room/devices/index.tsx:35-388", "app/Http/Controllers/ControlRoom/ControlRoomDeviceController.php:28-128", "03-feature-to-benchmark-matrix.csv:35"],
        "discrepancies": ["The matrix names the map page as representative, while its user job and backend anchors include this device projection."],
    },
    "RUN105-PAGE-RENDER-10": {
        "outcome": "SHARED_RELATION",
        "rationale": "The conflict workspace directly implements roster planning, shift cover, shift lifecycle, and staff-assignment actions, so it cannot be a one-feature page owner.",
        "source_loci": ["resources/js/pages/operations/rostering/conflicts.tsx:104-511", "resources/js/components/rostering/conflict-queue/build-queue.ts:82-300", "app/Http/Controllers/RosteringController.php:881-1051", "03-feature-to-benchmark-matrix.csv:234-241"],
        "discrepancies": ["Complete-page review rejects the packet's singleton projection as a multi-feature shared relation."],
    },
    "RUN105-PAGE-RENDER-11": {
        "outcome": "OWNER_PAGE",
        "rationale": "The dedicated checklist-failure report and its controller aggregation own the checklist-report portion of Site reporting.",
        "source_loci": ["resources/js/pages/sites/reports/checklist-trends.tsx:30-171", "app/Http/Controllers/Sites/SiteReportingController.php:305-337", "03-feature-to-benchmark-matrix.csv:337"],
        "discrepancies": ["The matrix lists only the generic report module as its representative page."],
    },
    "RUN105-PAGE-RENDER-12": {
        "outcome": "OWNER_PAGE",
        "rationale": "The page is the stay-lifecycle register for active and completed respite stays and opens the canonical stay record.",
        "source_loci": ["resources/js/pages/respite/stays/index.tsx:14-108", "app/Http/Controllers/Respite/RespiteStayController.php:39-47", "03-feature-to-benchmark-matrix.csv:284"],
        "discrepancies": ["The matrix representative page is the stay detail page; this is its matching lifecycle register."],
    },
    "RUN105-PAGE-RENDER-13": {
        "outcome": "OWNER_PAGE",
        "rationale": "The playbook detail/editor page reviews lifecycle state, evidence configuration, steps, and run history, with its wizard persisting create and update semantics.",
        "source_loci": ["resources/js/pages/control-room/playbooks/show.tsx:187-531", "resources/js/components/control-room/playbook-wizard.tsx:113-217", "app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:79-121", "03-feature-to-benchmark-matrix.csv:37"],
        "discrepancies": ["The matrix representative page is the playbook index rather than this lifecycle detail/editor."],
    },
    "RUN105-PAGE-RENDER-14": {
        "outcome": "OWNER_PAGE",
        "rationale": "The prescriptions workbench and material dialogs implement prescriber, countersign, dispense, link, confirm, and cancel pharmacy-order actions.",
        "source_loci": ["resources/js/pages/emar/Prescriptions.tsx:204-996", "resources/js/pages/emar/_prescription-dialogs.tsx:69-757", "app/Http/Controllers/Emar/EmarController.php:2199-2279", "03-feature-to-benchmark-matrix.csv:213"],
        "discrepancies": ["The matrix representative page is Medications.tsx rather than this direct order-action workbench."],
    },
    "RUN105-PAGE-RENDER-15": {
        "outcome": "SHARED_RELATION",
        "rationale": "The same ticket page intentionally implements requester self-service and internal agent triage, assignment, resolution, merge, close, watch, reopen, and rating workflows.",
        "source_loci": ["resources/js/pages/it/tickets/show.tsx:207-1173", "app/Http/Controllers/It/ItTicketController.php:51-88", "03-feature-to-benchmark-matrix.csv:195-202"],
        "discrepancies": ["The canonical matrix explicitly assigns this page to both self-service and agent-ticket features."],
    },
    "RUN105-PAGE-RENDER-16": {
        "outcome": "OWNER_PAGE",
        "rationale": "The complete cross-provider All Tasks workbench owns search, filtering, export, saved views, assignment, preview, statistics, and canonical deep links.",
        "source_loci": ["resources/js/pages/tasks/index.tsx:266-778", "app/Http/Controllers/AllTasksController.php:39-110", "03-feature-to-benchmark-matrix.csv:44"],
        "discrepancies": [],
    },
    "RUN105-PAGE-RENDER-17": {
        "outcome": "OWNER_PAGE",
        "rationale": "The confidential HR case detail page is the substantive facts, people, timeline, incident, disciplinary, stage, and closure workspace.",
        "source_loci": ["resources/js/pages/hr/cases/show.tsx:235-962", "app/Http/Controllers/Hr/HrCaseController.php:247-358", "app/Http/Controllers/Hr/HrCaseController.php:607-903"],
        "discrepancies": ["The matrix names only the case index page despite exact backend and user-job convergence."],
    },
    "RUN105-PAGE-RENDER-18": {
        "outcome": "OWNER_PAGE",
        "rationale": "The governance action detail page directly presents and completes one action item under the canonical action-item workflow.",
        "source_loci": ["resources/js/pages/Governance/Actions/Show.tsx:54-286", "app/Domain/Governance/Http/Controllers/ActionItemController.php:46-54"],
        "discrepancies": ["The matrix page_files field is not established; this complete review supplies the exact page identity."],
    },
    "RUN105-PAGE-RENDER-19": {
        "outcome": "OWNER_PAGE",
        "rationale": "The page owns the Control Room signal-source/device projection while explicitly preserving canonical device inventory in Security and Devices.",
        "source_loci": ["resources/js/pages/control-room/devices/show.tsx:242-610", "app/Http/Controllers/ControlRoom/ControlRoomDeviceController.php:155-311"],
        "discrepancies": ["The matrix omits this detail page and truncates the containing backend action at its render line."],
    },
    "RUN105-PAGE-RENDER-20": {
        "outcome": "OWNER_PAGE",
        "rationale": "The complete roster-planning workspace owns Site, staff, client, week, grid, conflict, coverage, leave, capacity, template, series, publish, and scheduling operations.",
        "source_loci": ["resources/js/pages/operations/rostering/index.tsx:1-3233", "app/Http/Controllers/RosteringController.php:50-880", "app/Http/Controllers/RosteringController.php:1276-1534"],
        "discrepancies": [],
    },
    "RUN105-PAGE-RENDER-21": {
        "outcome": "OWNER_PAGE",
        "rationale": "The dedicated Fleet by-house page substantively reports vehicle usage, trips, distance, fuel, transport, and cross-house comparisons.",
        "source_loci": ["resources/js/pages/fleet-assets/reports/by-house.tsx:58-428", "app/Http/Controllers/FleetAssets/ReportController.php:648-764"],
        "discrepancies": ["The matrix names only the generic report page; this variant has no export control, so no export-behaviour credit follows."],
    },
    "RUN105-PAGE-RENDER-22": {
        "outcome": "OWNER_PAGE",
        "rationale": "The DPIA edit page owns the assessment and editing portion of the privacy-impact lifecycle, including purpose, legal basis, subjects, risks, mitigations, levels, and review date.",
        "source_loci": ["resources/js/pages/privacy/dpia/edit.tsx:51-395", "app/Http/Controllers/DPIAController.php:119-127", "app/Http/Controllers/DPIAController.php:191-194"],
        "discrepancies": ["The matrix names only the DPIA show page; an unused staff prop does not create competing ownership."],
    },
    "RUN105-PAGE-RENDER-23": {
        "outcome": "OWNER_PAGE",
        "rationale": "The dedicated Site-detail report aggregates hazard, checklist, inspection, credential, and audit-log reporting under the Site reporting job.",
        "source_loci": ["resources/js/pages/sites/reports/site-detail.tsx:58-293", "app/Http/Controllers/Sites/SiteReportingController.php:187-275"],
        "discrepancies": ["The matrix names only the generic report page; no export control exists on this variant."],
    },
    "RUN105-PAGE-RENDER-24": {
        "outcome": "OWNER_PAGE",
        "rationale": "The dedicated Site asset-condition report owns Site filtering, condition groups, warranty summaries, and asset detail reporting.",
        "source_loci": ["resources/js/pages/sites/reports/asset-condition.tsx:64-297", "app/Http/Controllers/Sites/SiteReportingController.php:338-381"],
        "discrepancies": ["The matrix names only the generic report page; no export control exists on this variant."],
    },
}

PARTITION_METADATA = {
    "A": {
        "reviewer_task_paths": [
            "/root/run104_receipt_verify/run105_page_review_a/semantic_01_04",
            "/root/run104_receipt_verify/run105_page_review_a/identity_05_08",
        ],
        "synthesis_task_path": "/root/run104_receipt_verify/run105_page_review_a",
        "verdict": "GO_REVIEW_COMPLETE_SIX_OWNER_ONE_SHARED_ONE_EVIDENCE_GAP",
    },
    "B": {
        "reviewer_task_paths": ["/root/run102r_reporting_contract/run105_page_review_b"],
        "synthesis_task_path": None,
        "verdict": "GO_REVIEW_COMPLETE_SIX_OWNER_TWO_SHARED",
    },
    "C": {
        "reviewer_task_paths": ["/root/wave13_checkpoint_hygiene/run105_page_review_c"],
        "synthesis_task_path": None,
        "verdict": "GO_REVIEW_COMPLETE_EIGHT_OWNER",
    },
}


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def canonical_json_sha256(value: Any) -> str:
    raw = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return sha256_bytes(raw.encode("utf-8"))


def canonical_list_sha256(values: list[str] | set[str]) -> str:
    return sha256_bytes("\n".join(sorted(values)).encode("utf-8"))


def load_json(path: Path) -> dict[str, Any]:
    value = json.loads(path.read_text(encoding="utf-8"))
    assert isinstance(value, dict), path
    return value


def git(*args: str) -> str:
    completed = subprocess.run(
        ["git", *args], cwd=REPO, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True
    )
    return completed.stdout.strip()


def assert_workspace_and_inputs(cohort: dict[str, Any]) -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == AUDIT_HEAD
    assert git("rev-parse", "HEAD^{tree}") == AUDIT_TREE
    assert git("rev-parse", "HEAD^") == COHORT_CHECKPOINT
    assert git("rev-parse", f"{COHORT_CHECKPOINT}^{{tree}}") == COHORT_CHECKPOINT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "HEAD:app") == APP_TREE
    assert git("rev-parse", "HEAD:routes") == ROUTES_TREE
    assert git("rev-parse", "HEAD:resources/js") == RESOURCES_JS_TREE
    assert git("rev-parse", "HEAD:resources/js/pages") == RESOURCES_JS_PAGES_TREE
    assert git("status", "--porcelain", "--", "app", "routes", "resources/js") == ""
    assert sha256_file(COHORT_PATH) == COHORT_SHA256
    cohort_generator = AUDIT_DIR / cohort["pins"]["generator"]
    assert sha256_file(cohort_generator) == COHORT_GENERATOR_SHA256
    assert cohort["pins"]["generator_sha256"] == COHORT_GENERATOR_SHA256
    assert cohort["pins"]["checkpoint_commit"] == COHORT_CHECKPOINT
    assert cohort["pins"]["checkpoint_tree"] == COHORT_CHECKPOINT_TREE
    for relative_path, expected_sha in cohort["pins"]["inputs"].items():
        path = AUDIT_DIR / relative_path
        assert path.is_file(), path
        assert sha256_file(path) == expected_sha, (relative_path, sha256_file(path), expected_sha)


def build() -> dict[str, Any]:
    cohort = load_json(COHORT_PATH)
    assert_workspace_and_inputs(cohort)
    records = sorted(cohort["records"], key=lambda row: row["candidate_id"])
    assert len(records) == 24
    assert set(DECISIONS) == {row["candidate_id"] for row in records}
    assert cohort["identity"] == EXPECTED_COHORT_IDENTITY
    assert cohort["identity"]["records_sha256"] == canonical_json_sha256(records)
    assert cohort["counts"]["page_ownership_credit_awarded"] == 0
    assert cohort["audit_completion_test_met"] is False
    assert [cohort["review_partitions"][key]["assigned_candidates"] for key in "ABC"] == [8, 8, 8]

    decision_rows: list[dict[str, Any]] = []
    for candidate in records:
        candidate_without_digest = {key: value for key, value in candidate.items() if key != "candidate_record_sha256"}
        assert candidate["candidate_record_sha256"] == canonical_json_sha256(candidate_without_digest)
        assert candidate["fresh_review_state"]["status"] == "PENDING"
        assert not any(candidate["collision_checks"].values())
        page = candidate["page_source"]
        render = candidate["render_owner"]
        page_path = REPO / page["page_file"]
        controller_path = REPO / render["controller_file"]
        assert sha256_file(page_path) == page["page_file_sha256"]
        assert sha256_file(controller_path) == render["controller_file_sha256"]
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{page['page_file']}") == page["page_file_blob_id"]
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{render['controller_file']}") == render["controller_file_blob_id"]
        assert render["method_review_slice"]["text_sha256"] == sha256_bytes(render["method_review_slice"]["text"].encode("utf-8"))
        review = DECISIONS[candidate["candidate_id"]]
        outcome = review["outcome"]
        assert outcome in cohort["fresh_review_contract"]["allowed_outcomes"]
        row = {
            "candidate_id": candidate["candidate_id"],
            "partition_id": candidate["review_partition"],
            "page_feature_key": candidate["page_feature_key"],
            "page_record_id": page["page_record_id"],
            "page_file": page["page_file"],
            "candidate_feature_id": candidate["candidate_feature_id"],
            "render_source_anchor": render["selected_render_callsite"]["source_anchor"],
            "candidate_record_sha256": candidate["candidate_record_sha256"],
            "joined_candidate_evidence_sha256": candidate["evidence_digests"]["joined_candidate_evidence_sha256"],
            "outcome": outcome,
            "source_loci": review["source_loci"],
            "rationale": review["rationale"],
            "review_discrepancies": review["discrepancies"],
            "page_ownership_authorized": outcome == "OWNER_PAGE",
            "route_ownership_authorized": False,
            "controller_action_bridge_authorized": False,
            "downstream_credit_authorized": False,
        }
        row["decision_record_sha256"] = canonical_json_sha256(row)
        decision_rows.append(row)

    counts = Counter(row["outcome"] for row in decision_rows)
    assert counts == {"OWNER_PAGE": 20, "SHARED_RELATION": 3, "EVIDENCE_GAP": 1}
    owners = [row for row in decision_rows if row["outcome"] == "OWNER_PAGE"]
    non_owners = [row for row in decision_rows if row["outcome"] != "OWNER_PAGE"]
    assert {row["candidate_id"] for row in non_owners} == {
        "RUN105-PAGE-RENDER-02", "RUN105-PAGE-RENDER-04", "RUN105-PAGE-RENDER-10", "RUN105-PAGE-RENDER-15"
    }

    partition_reviews = []
    for partition_id in "ABC":
        rows = [row for row in decision_rows if row["partition_id"] == partition_id]
        partition_counts = Counter(row["outcome"] for row in rows)
        metadata = PARTITION_METADATA[partition_id]
        partition = {
            "partition_id": partition_id,
            "reviewer_task_paths": metadata["reviewer_task_paths"],
            "synthesis_task_path": metadata["synthesis_task_path"],
            "verdict": metadata["verdict"],
            "candidate_count": len(rows),
            "owner_pages": partition_counts["OWNER_PAGE"],
            "shared_relations": partition_counts["SHARED_RELATION"],
            "alias_or_redirect": partition_counts["ALIAS_OR_REDIRECT"],
            "dead_or_noncanonical": partition_counts["DEAD_OR_NONCANONICAL"],
            "evidence_gaps": partition_counts["EVIDENCE_GAP"],
            "page_feature_key_list_sha256": cohort["review_partitions"][partition_id]["page_feature_key_list_sha256"],
            "mechanical_discrepancies": 0,
            "reviewer_wrote_files": False,
        }
        partition["partition_review_sha256"] = canonical_json_sha256(partition)
        partition_reviews.append(partition)

    outcome_conservation = {
        "reviewed_candidates": 24,
        "owner_pages": 20,
        "shared_relations": 3,
        "alias_or_redirect": 0,
        "dead_or_noncanonical": 0,
        "evidence_gaps": 1,
        "equation": "24 = 20 OWNER_PAGE + 3 SHARED_RELATION + 0 ALIAS_OR_REDIRECT + 0 DEAD_OR_NONCANONICAL + 1 EVIDENCE_GAP",
        "all_candidates_conserved": True,
    }

    return {
        "schema_version": "1.0.0",
        "run_id": "RUN-105R-INDEPENDENT-OUTCOME-NEUTRAL-PAGE-RENDER-OWNER-REVIEW-WAVE-14",
        "status": "FRESH_REVIEW_GO_TWENTY_OWNER_PAGES_THREE_SHARED_ONE_EVIDENCE_GAP_ZERO_CURRENT_CREDIT",
        "generated_on": "2026-08-25",
        "pins": {
            "publication_checkpoint_commit": AUDIT_HEAD,
            "publication_checkpoint_tree": AUDIT_TREE,
            "cohort_checkpoint_commit": COHORT_CHECKPOINT,
            "cohort_checkpoint_tree": COHORT_CHECKPOINT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "app_tree": APP_TREE,
            "routes_tree": ROUTES_TREE,
            "resources_js_tree": RESOURCES_JS_TREE,
            "resources_js_pages_tree": RESOURCES_JS_PAGES_TREE,
            "cohort": COHORT_PATH.relative_to(AUDIT_DIR).as_posix(),
            "cohort_sha256": COHORT_SHA256,
            "cohort_generator": cohort["pins"]["generator"],
            "cohort_generator_sha256": COHORT_GENERATOR_SHA256,
            "materializer": Path(__file__).relative_to(AUDIT_DIR).as_posix(),
            "materializer_sha256": sha256_file(Path(__file__)),
            "prompt_path": cohort["pins"]["prompt_path"],
            "prompt_sha256": cohort["pins"]["prompt_sha256"],
            "inputs": cohort["pins"]["inputs"],
        },
        "architecture_rule": "Oblivion Findings is one operating organisation with multiple Sites. Page ownership is separate from Site access, roles and permissions, canonical ownership, direct-object concealment, privacy, and lifecycle correctness.",
        "review_method": {
            "fresh_partition_review": True,
            "complete_page_source_reviewed": True,
            "exact_render_method_reviewed": True,
            "material_imports_reviewed": True,
            "canonical_matrix_user_job_reviewed": True,
            "render_containment_alone_accepted_as_ownership": False,
            "reviewer_writes": 0,
        },
        "partition_reviews": partition_reviews,
        "page_decisions": decision_rows,
        "outcome_conservation": outcome_conservation,
        "decision": {
            "verdict": "GO_20_EXPLICIT_OWNER_PAGE_3_SHARED_RELATION_1_EVIDENCE_GAP",
            "mechanical_discrepancies": 0,
            "owner_pages": 20,
            "shared_relations": 3,
            "alias_or_redirect": 0,
            "dead_or_noncanonical": 0,
            "evidence_gaps": 1,
            "static_page_owner_records_authorized": 20,
            "static_route_owner_records_authorized": 0,
            "static_controller_action_bridges_authorized": 0,
            "owner_only_overlay_authorized": True,
            "non_owner_records_must_be_preserved": 4,
            "matrix_mutation_authorized": False,
            "gate_4_complete": False,
        },
        "identity": {
            **EXPECTED_COHORT_IDENTITY,
            "owner_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in owners]),
            "owner_page_record_id_list_sha256": canonical_list_sha256([row["page_record_id"] for row in owners]),
            "owner_page_feature_key_list_sha256": canonical_list_sha256([row["page_feature_key"] for row in owners]),
            "owner_candidate_record_sha256_list_sha256": canonical_list_sha256([row["candidate_record_sha256"] for row in owners]),
            "shared_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in decision_rows if row["outcome"] == "SHARED_RELATION"]),
            "evidence_gap_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in decision_rows if row["outcome"] == "EVIDENCE_GAP"]),
            "decision_records_sha256": canonical_json_sha256(decision_rows),
            "decision_record_sha256_list_sha256": canonical_list_sha256([row["decision_record_sha256"] for row in decision_rows]),
            "partition_reviews_sha256": canonical_json_sha256(partition_reviews),
        },
        "projected_integration_only": {
            "projection_is_current_credit": False,
            "bounded_source_records": "3929 = 612 owner + 3317 non-owner residual",
            "owner_surfaces": "612 = 265 route + 347 page",
            "page_universe": "711 = 347 owner + 5 shared + 0 alias + 0 dead + 359 residual",
            "evidence_gap_tagged_within_page_residual": 1,
            "static_controller_action_bridges": 53,
            "bounded_static_source_ownership_percent": "15.576483",
        },
        "credit_boundary": {
            "PAGE_REVIEW_DECISIONS_FOR_LATER_OWNER_ONLY_OVERLAY": True,
            "page_ownership": False,
            "route_ownership": False,
            "static_controller_action_bridge": False,
            "framework_route_reachability": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "direct_object_concealment": False,
            "privacy_correctness": False,
            "lifecycle_correctness": False,
            "runtime": False,
            "database": False,
            "build": False,
            "application_browser": False,
            "executed_tests": False,
            "benchmark": False,
            "ease": False,
            "pass": False,
            "final_finding": False,
            "completion": False,
            "audit_complete": False,
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-independent-outcome-neutral-page-render-owner-review-wave-14.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/raw-run-105r-independent-outcome-neutral-page-render-owner-review-wave-14.json",
        ],
    }


def main() -> None:
    payload = build()
    encoded = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    output_sha256 = sha256_bytes(encoded)
    OUTPUT_PATH.parent.mkdir(parents=True, exist_ok=True)
    if OUTPUT_PATH.exists():
        assert OUTPUT_PATH.read_bytes() == encoded, f"Refusing to overwrite different bytes: {OUTPUT_PATH}"
    else:
        temporary = OUTPUT_PATH.with_suffix(OUTPUT_PATH.suffix + ".tmp")
        temporary.write_bytes(encoded)
        assert sha256_file(temporary) == output_sha256
        os.replace(temporary, OUTPUT_PATH)
    assert sha256_file(OUTPUT_PATH) == output_sha256
    print(json.dumps({
        "status": payload["status"],
        "output": OUTPUT_PATH.relative_to(REPO).as_posix(),
        "sha256": output_sha256,
        "owner_pages": payload["decision"]["owner_pages"],
        "shared_relations": payload["decision"]["shared_relations"],
        "evidence_gaps": payload["decision"]["evidence_gaps"],
        "page_ownership_credit_awarded": 0,
    }, indent=2))


if __name__ == "__main__":
    main()
