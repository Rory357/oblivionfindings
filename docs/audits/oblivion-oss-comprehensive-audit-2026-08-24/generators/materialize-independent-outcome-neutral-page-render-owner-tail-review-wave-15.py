#!/usr/bin/env python3
"""Materialize the fresh three-part RUN-109 page-tail semantic review.

Only the two explicit OWNER_PAGE decisions authorize a later owner-only
overlay. Four shared pages remain reviewed non-owners. The medication page
also authorizes later reconciliation of its exact pending RUN-090 queue row as
reviewed shared. This receipt itself grants no ownership, queue, runtime,
browser, benchmark, Pass, finding, or completion credit.
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
OUTPUT_PATH = AUDIT_DIR / "evidence/source/raw-run-109r-independent-outcome-neutral-page-render-owner-tail-review-wave-15.json"
COHORT_PATH = AUDIT_DIR / "evidence/source/root-run-109-outcome-neutral-page-render-owner-tail-cohort-wave-15.json"

AUDIT_HEAD = "e334d3bc9cb16d6d14cf37e091544138449deb9e"
AUDIT_TREE = "608ecf1637fe6d04c38a6f5b16676e2d742ec3eb"
COHORT_CHECKPOINT = "cc3b548179f94e053edfe5146a00d0b6f55bb868"
COHORT_CHECKPOINT_TREE = "db7efc5d43ecdbb99df19de2390cbf995f8abc4d"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
COHORT_SHA256 = "9019306fc317374b673d76fc6023efc11deb1f7f83be67d0df72d196cd076187"
COHORT_GENERATOR_SHA256 = "1005eaad8d3bcecf99f04b40f912e5181f28e33ef5acb044c27ba0201d0c8e0c"

EXPECTED_COHORT_IDENTITY = {
    "page_record_id_list_sha256": "81e520467cea1eecc987ea218f2b3d1804ef0b971ed715b9194829e61866c9c6",
    "page_path_list_sha256": "ab72edfacd8a935eacce9d870a1851006561fd7d3dfef360bdea612a5bc1a6d3",
    "feature_id_list_sha256": "21259b58c042c51163b39d966e9b18cb228a3e2a895abed9ba628bfa19177ed2",
    "new_feature_id_list_sha256": "faf9570f8c5f6dcaaeca6f1e83ab710d09ebe1b34b3b5a622881c683957f9888",
    "render_anchor_list_sha256": "b9caca2f405ed797ac140f4c3869680ef3ad23bf1defbc8c1a118dfa56696dc4",
    "page_feature_key_list_sha256": "9b547f85710db0c5a7929ed3149f3c45cc54bce13bb6f8db04f3a967d603cd89",
    "candidate_record_sha256_list_sha256": "48322e6c003b721f949e407e5faecd4c52dd53f8731713ecbd2f821752289190",
    "records_sha256": "6c6eb98279b4d9a7d4197c165b32d47596777411ba2bad7619884a38b4346c76",
}

DECISIONS: dict[str, dict[str, Any]] = {
    "RUN109-PAGE-TAIL-01": {
        "outcome": "OWNER_PAGE",
        "canonical_feature_ids": ["CAP-CLIN-PROTOCOL-LIFECYCLE"],
        "rationale": "The complete edit page, its ProtocolForm, and the exact controller action exclusively implement the clinical-protocol lifecycle.",
        "source_loci": [
            "resources/js/pages/health-clinical/protocols/Edit.tsx:1-84",
            "resources/js/components/clinical/protocol-form.tsx:63-399",
            "app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:91-114",
            "03-feature-to-benchmark-matrix.csv:24",
        ],
        "discrepancies": [],
    },
    "RUN109-PAGE-TAIL-02": {
        "outcome": "SHARED_RELATION",
        "canonical_feature_ids": [
            "CAP-PRIV-DASHBOARD-WORKLIST",
            "CAP-PRIV-BREACH-LIFECYCLE",
            "CAP-PRIV-COMPLIANCE-REPORT-EXPORT",
            "CAP-PRIV-DSR-DATA-EXPORT",
            "CAP-PRIV-DSR-LIFECYCLE",
            "CAP-PRIV-EVIDENCE-ATTACHMENTS",
            "CAP-PRIV-LEGAL-HOLD",
            "CAP-PRIV-PIA-LIFECYCLE",
            "CAP-PRIV-RETENTION-EXECUTION-EVIDENCE",
            "CAP-PRIV-RETENTION-POLICY-LIFECYCLE",
        ],
        "rationale": "The page is a whole-module privacy command centre spanning requests, breaches, holds, DPIAs, retention, evidence, and exports, not a sole dashboard-worklist owner.",
        "source_loci": [
            "resources/js/pages/privacy/dashboard.tsx:3-24,255-374,389-509,590-594,617-648,822-830",
            "app/Http/Controllers/PrivacyDashboardController.php:35-74",
            "03-feature-to-benchmark-matrix.csv:257-266",
        ],
        "discrepancies": ["Complete-page and matrix review rejects the packet's singleton projection as a ten-feature shared relation."],
    },
    "RUN109-PAGE-TAIL-03": {
        "outcome": "SHARED_RELATION",
        "canonical_feature_ids": [
            "CAP-MED-PHARMACY-ACTIONS",
            "CAP-MED-MEDICATION-ORDER-VERIFICATION",
            "CAP-MED-EMAR-WORKSPACE-ORDER-LIFECYCLE",
        ],
        "rationale": "The Medications workspace performs medication verification and order-lifecycle work, while the exact pharmacy-order actions live in the stock-management workbench.",
        "source_loci": [
            "resources/js/pages/emar/Medications.tsx:302-416,809-856",
            "resources/js/pages/emar/_dialogs.tsx:73-190,590-640,749-885,1141-1313,1383-1435",
            "app/Http/Controllers/Emar/EmarController.php:1844-1964",
            "routes/emar.php:221-223",
            "resources/js/pages/emar/StockManagement.tsx:505-519,644-659,1141-1182",
            "resources/js/pages/emar/_stock-dialogs.tsx:120-190",
            "app/Http/Controllers/Emar/EmarController.php:4295-4385",
            "03-feature-to-benchmark-matrix.csv:209,211,213",
        ],
        "discrepancies": ["The pending RUN090-PAGE-0003 singleton projection is incomplete and must be integrated as reviewed shared, never as owner."],
    },
    "RUN109-PAGE-TAIL-04": {
        "outcome": "OWNER_PAGE",
        "canonical_feature_ids": ["CAP-FLEET-REPORTING-EXPORT"],
        "rationale": "The complete Fleet reports workspace and its one exact ReportController render implement reporting and export; the maps-usage link is navigation only.",
        "source_loci": [
            "resources/js/pages/fleet-assets/reports/index.tsx:1-1085",
            "resources/js/pages/fleet-assets/reports/index.tsx:203-360,388-1081",
            "app/Http/Controllers/FleetAssets/ReportController.php:24-415",
            "routes/fleet-assets.php:333-342",
            "03-feature-to-benchmark-matrix.csv:103",
        ],
        "discrepancies": [],
    },
    "RUN109-PAGE-TAIL-05": {
        "outcome": "SHARED_RELATION",
        "canonical_feature_ids": [
            "CAP-HR-CANDIDATE-APPLICATION-LIFECYCLE",
            "CAP-HR-CANDIDATE-ASSESSMENT",
            "CAP-HR-RECRUITMENT-OFFER-HIRE-LIFECYCLE",
        ],
        "rationale": "The exact matrix assigns the whole page to application lifecycle and assessment, and the page also creates, responds to, approves, sends, and converts offers.",
        "source_loci": [
            "resources/js/pages/hr/candidates/show.tsx:1-13,314-507,552-1029,1525-2055,2062-2397,2490-2511",
            "resources/js/components/hr/offer-wizard-dialog.tsx:65-620",
            "app/Http/Controllers/Hr/CandidateController.php:178-428,1565-1607",
            "03-feature-to-benchmark-matrix.csv:134-135,149",
        ],
        "discrepancies": ["The packet singleton projection is incomplete; the offer/hire matrix row names a create-offer page even though this show page imports and executes that workflow."],
    },
    "RUN109-PAGE-TAIL-06": {
        "outcome": "SHARED_RELATION",
        "canonical_feature_ids": [
            "CAP-HR-EMPLOYEE-PROFILE-LIFECYCLE",
            "CAP-HR-ONBOARDING-LIFECYCLE",
            "CAP-HR-RECRUITMENT-REQUISITION-LIFECYCLE",
        ],
        "rationale": "The employee hub directly starts onboarding and creates a draft requisition from its Position dialog, beyond the employee-profile lifecycle.",
        "source_loci": [
            "resources/js/pages/hr/employees/index.tsx:1-37,88-202,284-346,399-529",
            "resources/js/components/hr/add-employee-dialog.tsx:90-152,827-838",
            "app/Http/Controllers/Hr/EmployeeProfileController.php:61-502,995",
            "resources/js/components/hr/position-dialog.tsx:75-145,444-468",
            "app/Http/Controllers/Hr/PositionController.php:61-114",
            "03-feature-to-benchmark-matrix.csv:141,144,151",
        ],
        "discrepancies": ["The page has no exact matrix page overlap; positions, departments, and org-chart lanes lack canonical matrix IDs, and compliance is triage/link-only rather than a competing owner."],
    },
}

PARTITION_METADATA = {
    "A": {
        "reviewer_task_paths": ["/root/run107_reporting_verify"],
        "verdict": "GO_REVIEW_COMPLETE_ONE_OWNER_ONE_SHARED",
    },
    "B": {
        "reviewer_task_paths": ["/root/wave13_checkpoint_hygiene"],
        "verdict": "GO_REVIEW_COMPLETE_ONE_OWNER_ONE_SHARED",
    },
    "C": {
        "reviewer_task_paths": ["/root/run104_receipt_verify/run105r_receipt_verify"],
        "verdict": "GO_REVIEW_COMPLETE_ZERO_OWNER_TWO_SHARED",
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


def assert_review_slice(source_file: str, review_slice: dict[str, Any]) -> None:
    assert review_slice["text_sha256"] == sha256_bytes(review_slice["text"].encode("utf-8"))
    assert review_slice["line_count"] == review_slice["end_line"] - review_slice["start_line"] + 1
    lines = (REPO / source_file).read_text(encoding="utf-8").splitlines(keepends=True)
    actual = "".join(lines[review_slice["start_line"] - 1 : review_slice["end_line"]])
    # Packet slices intentionally omit trailing blank separator lines even when
    # the recorded inclusive method boundary reaches one.
    assert actual.rstrip("\n") == review_slice["text"].rstrip("\n"), (
        source_file,
        review_slice["start_line"],
        review_slice["end_line"],
    )


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


def assert_candidate_sources(candidate: dict[str, Any]) -> None:
    page = candidate["page_source"]
    render = candidate["render_owner"]
    page_path = REPO / page["page_file"]
    controller_path = REPO / render["controller_file"]
    assert sha256_file(page_path) == page["page_file_sha256"]
    assert len(page_path.read_text(encoding="utf-8").splitlines()) == page["page_line_count"]
    assert git("rev-parse", f"{APPLICATION_COMMIT}:{page['page_file']}") == page["page_file_blob_id"]
    assert sha256_file(controller_path) == render["controller_file_sha256"]
    assert git("rev-parse", f"{APPLICATION_COMMIT}:{render['controller_file']}") == render["controller_file_blob_id"]
    selected = render["selected_render_callsite"]
    assert selected["source_file"] == render["controller_file"]
    assert selected["source_file_sha256"] == render["controller_file_sha256"]
    assert selected["source_file_blob_id"] == render["controller_file_blob_id"]
    assert_review_slice(render["controller_file"], render["method_review_slice"])
    assert render["literal_render_call_count_inside_method_slice"] == len(render["literal_render_calls_inside_method_slice"])
    assert selected in render["literal_render_calls_inside_method_slice"]
    for helper in render["transitive_local_helper_slices"]:
        source_file = helper["source_file"]
        assert sha256_file(REPO / source_file) == helper["source_file_sha256"]
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{source_file}") == helper["source_file_blob_id"]
        assert_review_slice(source_file, helper["review_slice"])


def build() -> dict[str, Any]:
    cohort = load_json(COHORT_PATH)
    assert_workspace_and_inputs(cohort)
    records = sorted(cohort["records"], key=lambda row: row["candidate_id"])
    assert len(records) == 6
    assert set(DECISIONS) == {row["candidate_id"] for row in records}
    assert cohort["identity"] == EXPECTED_COHORT_IDENTITY
    assert cohort["identity"]["records_sha256"] == canonical_json_sha256(records)
    assert cohort["counts"]["page_ownership_credit_awarded"] == 0
    assert cohort["audit_completion_test_met"] is False
    assert [cohort["review_partitions"][key]["assigned_candidates"] for key in "ABC"] == [2, 2, 2]

    decision_rows: list[dict[str, Any]] = []
    for candidate in records:
        candidate_without_digest = {key: value for key, value in candidate.items() if key != "candidate_record_sha256"}
        assert candidate["candidate_record_sha256"] == canonical_json_sha256(candidate_without_digest)
        assert candidate["fresh_review_state"]["status"] == "PENDING"
        collisions = candidate["collision_checks"]
        assert collisions["prior_review_page_collision"] is False
        assert collisions["current_owner_page_collision"] is False
        assert collisions["conflicting_candidate_lane"] is False
        assert collisions["unreconciled_direct_queue_collision"] is False
        queue_overlap = candidate["candidate_id"] == "RUN109-PAGE-TAIL-03"
        assert collisions["direct_queue_pending_overlap_present"] is queue_overlap
        assert collisions["direct_queue_pending_overlap_reconciled"] is queue_overlap
        queue_context = candidate["direct_queue_context"]
        if queue_overlap:
            assert queue_context == {
                "queue_id": "RUN090-PAGE-0003",
                "queue_record_sha256": "82486fbab4968319f65ff7b3b71b7528be21f3df1bd026a165552d0626385ee0",
                "surface": "PAGE_ROOT_SOURCE_RECORD",
                "candidate_feature_id": "CAP-MED-PHARMACY-ACTIONS",
                "review_status_before": "PENDING_FRESH_SEMANTIC_REVIEW",
                "queue_review_credit_awarded": False,
                "integration_must_reconcile_queue_accounting": True,
            }
        else:
            assert queue_context is None
        assert_candidate_sources(candidate)

        page = candidate["page_source"]
        render = candidate["render_owner"]
        review = DECISIONS[candidate["candidate_id"]]
        outcome = review["outcome"]
        assert outcome in cohort["fresh_review_contract"]["allowed_outcomes"]
        assert review["canonical_feature_ids"][0] == candidate["candidate_feature_id"]
        row = {
            "candidate_id": candidate["candidate_id"],
            "partition_id": candidate["review_partition"],
            "page_feature_key": candidate["page_feature_key"],
            "page_record_id": page["page_record_id"],
            "page_file": page["page_file"],
            "candidate_feature_id": candidate["candidate_feature_id"],
            "canonical_feature_ids": review["canonical_feature_ids"],
            "render_source_anchor": render["selected_render_callsite"]["source_anchor"],
            "candidate_record_sha256": candidate["candidate_record_sha256"],
            "joined_candidate_evidence_sha256": candidate["evidence_digests"]["joined_candidate_evidence_sha256"],
            "outcome": outcome,
            "source_loci": review["source_loci"],
            "rationale": review["rationale"],
            "review_discrepancies": review["discrepancies"],
            "direct_queue_context": queue_context,
            "direct_queue_review_authorized": queue_overlap and outcome == "SHARED_RELATION",
            "page_ownership_authorized": outcome == "OWNER_PAGE",
            "route_ownership_authorized": False,
            "controller_action_bridge_authorized": False,
            "downstream_credit_authorized": False,
        }
        row["decision_record_sha256"] = canonical_json_sha256(row)
        decision_rows.append(row)

    counts = Counter(row["outcome"] for row in decision_rows)
    assert counts == {"OWNER_PAGE": 2, "SHARED_RELATION": 4}
    owners = [row for row in decision_rows if row["outcome"] == "OWNER_PAGE"]
    shared = [row for row in decision_rows if row["outcome"] == "SHARED_RELATION"]
    assert {row["candidate_id"] for row in owners} == {"RUN109-PAGE-TAIL-01", "RUN109-PAGE-TAIL-04"}
    assert {row["candidate_id"] for row in shared} == {
        "RUN109-PAGE-TAIL-02", "RUN109-PAGE-TAIL-03", "RUN109-PAGE-TAIL-05", "RUN109-PAGE-TAIL-06"
    }
    assert [row["candidate_id"] for row in decision_rows if row["direct_queue_review_authorized"]] == ["RUN109-PAGE-TAIL-03"]

    partition_reviews = []
    for partition_id in "ABC":
        rows = [row for row in decision_rows if row["partition_id"] == partition_id]
        partition_counts = Counter(row["outcome"] for row in rows)
        metadata = PARTITION_METADATA[partition_id]
        partition = {
            "partition_id": partition_id,
            "reviewer_task_paths": metadata["reviewer_task_paths"],
            "synthesis_task_path": None,
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
        "reviewed_candidates": 6,
        "owner_pages": 2,
        "shared_relations": 4,
        "alias_or_redirect": 0,
        "dead_or_noncanonical": 0,
        "evidence_gaps": 0,
        "equation": "6 = 2 OWNER_PAGE + 4 SHARED_RELATION + 0 ALIAS_OR_REDIRECT + 0 DEAD_OR_NONCANONICAL + 0 EVIDENCE_GAP",
        "all_candidates_conserved": True,
    }

    return {
        "schema_version": "1.0.0",
        "run_id": "RUN-109R-INDEPENDENT-OUTCOME-NEUTRAL-PAGE-RENDER-OWNER-TAIL-REVIEW-WAVE-15",
        "status": "FRESH_REVIEW_GO_TWO_OWNER_PAGES_FOUR_SHARED_ZERO_CURRENT_CREDIT",
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
            "verdict": "GO_2_EXPLICIT_OWNER_PAGE_4_SHARED_RELATION",
            "mechanical_discrepancies": 0,
            "owner_pages": 2,
            "shared_relations": 4,
            "alias_or_redirect": 0,
            "dead_or_noncanonical": 0,
            "evidence_gaps": 0,
            "static_page_owner_records_authorized": 2,
            "static_route_owner_records_authorized": 0,
            "static_controller_action_bridges_authorized": 0,
            "direct_queue_reviewed_shared_records_authorized": 1,
            "owner_only_overlay_authorized": True,
            "direct_queue_review_overlay_authorized": True,
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
            "shared_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in shared]),
            "direct_queue_reviewed_shared_queue_id_list_sha256": canonical_list_sha256(["RUN090-PAGE-0003"]),
            "decision_records_sha256": canonical_json_sha256(decision_rows),
            "decision_record_sha256_list_sha256": canonical_list_sha256([row["decision_record_sha256"] for row in decision_rows]),
            "partition_reviews_sha256": canonical_json_sha256(partition_reviews),
        },
        "projected_integration_only": {
            "projection_is_current_credit": False,
            "bounded_source_records": "3929 = 614 owner + 3315 non-owner residual",
            "owner_surfaces": "614 = 265 route + 349 page",
            "page_universe": "711 = 349 owner + 9 shared + 0 alias + 0 dead + 353 residual",
            "evidence_gap_tagged_within_page_residual": 1,
            "static_controller_action_bridges": 53,
            "direct_exact_queue": "507 = 60 reviewed + 447 pending",
            "direct_exact_queue_reviewed": "60 = 54 owner + 3 shared + 3 alias",
            "direct_exact_queue_without_current_static_ownership": 453,
            "bounded_static_source_ownership_percent": "15.627386",
        },
        "credit_boundary": {
            "PAGE_REVIEW_DECISIONS_FOR_LATER_OVERLAY": True,
            "DIRECT_QUEUE_REVIEW_DECISION_FOR_LATER_OVERLAY": True,
            "page_ownership": False,
            "direct_queue_review": False,
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
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-independent-outcome-neutral-page-render-owner-tail-review-wave-15.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/raw-run-109r-independent-outcome-neutral-page-render-owner-tail-review-wave-15.json",
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
        "direct_queue_reviewed_shared_records_authorized": payload["decision"]["direct_queue_reviewed_shared_records_authorized"],
        "page_ownership_credit_awarded": 0,
    }, indent=2))


if __name__ == "__main__":
    main()
