#!/usr/bin/env python3
"""Materialize the fresh three-part RUN-125 Finance page-gap review.

Three account pages are explicit Chart-of-Accounts owners. The journal-create
page is an explicit Manual-Journal owner and does not inherit RUN-121's stale
Chart-of-Accounts route-name projection. This receipt authorizes a later
owner-only overlay but awards no ownership or downstream credit itself.
"""

from __future__ import annotations

import hashlib
import json
import subprocess
from collections import Counter
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_PATH = AUDIT_DIR / "evidence/source/raw-run-125r-independent-outcome-neutral-finance-page-gap-review-wave-19.json"
COHORT_PATH = AUDIT_DIR / "evidence/source/root-run-125-outcome-neutral-finance-page-gap-cohort-wave-19.json"

AUDIT_HEAD = "08a699248e09f3533b44f5b46cc2ad2d09755741"
AUDIT_TREE = "ff508e9a13f891d40d1bed053d3dfcfe7eb7a25e"
COHORT_CHECKPOINT = "a6c14f0f8354df409b4695c4cd3cb7bcf416f8ba"
COHORT_CHECKPOINT_TREE = "8e9be24776dd76d0cbc185bfa145f209215ee351"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
COHORT_SHA256 = "7d0df6edfacb63a9a7ab64140d47b2570a617db0147e4b0be6d5317fe38e3d92"
COHORT_GENERATOR_SHA256 = "e27ba0b1c7cc4e0fdeeea67272efe628700e9b70dffdc9ef3210b449c7d2ca84"

EXPECTED_COHORT_IDENTITY = {
    "page_record_id_list_sha256": "7736ff160732728ccca2ff900b181c13ef631dc19c605984848ee3a56e11c75b",
    "page_file_list_sha256": "a8ac99a97f1b156e69ac0f141d81b5bd6487d4f296b4277eafe46229b28168f2",
    "page_record_id_file_pair_list_sha256": "db44435fc97bd9e950a8f06d3f49ab6d0e967fdd01fee29f7bfa769129d74da8",
    "render_anchor_list_sha256": "ffe44d1d3fc25d3bcb7501818a5d0cebe3cb0e0e3de8cfb1424c8e29eb0e37b2",
    "parent_candidate_id_list_sha256": "84b9d3db82af0fc132562359e2d58f60ba1e287b8615244b63d5a856adf5533b",
    "parent_decision_record_sha256_list_sha256": "940ae89aa5f69d7062bc9064a6d69b8b82adc3354be40ceeafd3250739ca513d",
    "page_feature_key_list_sha256": "4479c933cc3608994b660ccbf3d6f55de094a855a3dbf6e6ed5b1845f5697c8a",
    "candidate_record_sha256_list_sha256": "53fe8b95bd3ad0a05acadb64a4d8105c701ab892c17eb9a83d20e69a0cf5aa73",
    "records_sha256": "323e800d57520b3525635eba41f0ad61b1cef5e7080c896d871e2c8dd73d997f",
}

DECISIONS: dict[str, dict[str, Any]] = {
    "RUN125-FINANCE-PAGE-GAP-01": {
        "outcome": "OWNER_PAGE",
        "canonical_feature_ids": ["CAP-FIN-CHART-OF-ACCOUNTS"],
        "rationale": "The dedicated account-create page owns the Chart-of-Accounts creation form, account hierarchy and classification fields, reference selections, and submission to the accepted account-store action.",
        "source_loci": [
            "resources/js/pages/finance/accounts/Create.tsx:39-118,146-399",
            "resources/js/pages/finance/accounts/Index.tsx:343-350,424-431",
            "resources/js/components/finance/new-account-dialog.tsx:83-87,125-130",
            "routes/finance.php:62,137-145",
            "app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:83-138",
            "app/Domain/Finance/Services/ChartOfAccountsService.php:174-188",
            "app/Domain/Finance/Policies/FinAccountPolicy.php:20-23",
            "app/Domain/Finance/Models/FinAccount.php:80-83",
            "database/migrations/2026_03_28_000200_create_fin_accounts_table.php:17-22,35",
            "database/migrations/2026_04_24_000100_add_organization_scope_to_users_and_clients.php:12-18,32-34",
            "03-feature-to-benchmark-matrix.csv:61",
        ],
        "uncertainty": "The account index now opens an inline NewAccountDialog and no current production link to the standalone page was found; the registered route still performs a substantive literal render, so navigation uncertainty is not dead-page proof.",
        "readiness_risks": [
            "The Chart is legacy-operating-organisation scoped and has no Site field; global-ledger intent and approved-Site correctness remain unproved.",
            "parent_id, default_tax_rate_id, and funding_stream_id use unrestricted exists validation rather than contextual ownership constraints.",
            "The page exposes more subtype values than the pinned database enum, while backend validation accepts any bounded string.",
            "Nullable legacy organisational scope can fail open; concurrency and permission-bundle correctness remain unproved.",
        ],
    },
    "RUN125-FINANCE-PAGE-GAP-02": {
        "outcome": "OWNER_PAGE",
        "canonical_feature_ids": ["CAP-FIN-CHART-OF-ACCOUNTS"],
        "rationale": "The dedicated account-detail page exclusively presents one ledger account's identity, state, balance, date filters, and account ledger; outbound journal links are dependencies, not shared ownership.",
        "source_loci": [
            "resources/js/pages/finance/accounts/Show.tsx:79-140,178-268",
            "resources/js/pages/finance/accounts/Index.tsx:91-109,306-315",
            "routes/finance.php:62,146-148",
            "app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:140-168",
            "app/Domain/Finance/Services/ChartOfAccountsService.php:102-170",
            "app/Domain/Finance/Policies/FinAccountPolicy.php:15-18",
        ],
        "uncertainty": "None for static semantic ownership; journal-detail links are outbound navigation to a separately canonical job.",
        "readiness_risks": [
            "Permission-only policy and implicit binding do not establish contextual ownership, approved-Site scope, or direct-object concealment.",
            "The ledger service re-fetches by account ID without an explicit contextual scope.",
            "Displayed account balance includes all journal lines while the visible ledger filters to posted journals; ledger correctness remains unproved.",
        ],
    },
    "RUN125-FINANCE-PAGE-GAP-03": {
        "outcome": "OWNER_PAGE",
        "canonical_feature_ids": ["CAP-FIN-CHART-OF-ACCOUNTS"],
        "rationale": "The dedicated account-maintenance page owns editing the account identity, hierarchy, classification, tax, funding, GST, and active-state contract; reference lists and journal-line immutability are dependencies.",
        "source_loci": [
            "resources/js/pages/finance/accounts/Edit.tsx:20-61,109-199,201-460",
            "routes/finance.php:149-154",
            "app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:170-229",
            "app/Domain/Finance/Services/ChartOfAccountsService.php:191-223",
            "app/Domain/Finance/Policies/FinAccountPolicy.php:25-28",
        ],
        "uncertainty": "No current incoming production frontend link was found; the registered route performs a substantive literal render, so navigation absence is not dead/noncanonical proof.",
        "readiness_risks": [
            "The bound account is not converged with the user's legacy organisational reference-list context.",
            "Submitted parent, default-tax-rate, and funding-stream IDs use global exists checks rather than contextual constraints.",
            "Site authorization, contextual ownership, direct-object concealment, and lifecycle correctness remain unproved.",
        ],
    },
    "RUN125-FINANCE-PAGE-GAP-04": {
        "outcome": "OWNER_PAGE",
        "canonical_feature_ids": ["CAP-FIN-MANUAL-JOURNAL-LIFECYCLE"],
        "rationale": "The dedicated journal-create page owns manual-journal header and line entry, balancing feedback, draft saving, and immediate-post intent for the canonical Manual Journal lifecycle.",
        "source_loci": [
            "resources/js/pages/finance/journals/Create.tsx:85-118,142-148,180-574",
            "routes/finance.php:62,163-168",
            "app/Domain/Finance/Http/Controllers/JournalController.php:191-242",
            "app/Domain/Finance/Http/Requests/StoreJournalRequest.php:10-32",
            "app/Domain/Finance/Services/JournalPostingService.php:21-24,49-120,261-266,343-378",
            "task-scripts/cap-fin-manual-journal-lifecycle.md:1-18",
        ],
        "uncertainty": "The index now opens an inline NewJournalDialog and no current production link to the standalone page was found; registered substantive rendering keeps this a reachability gap rather than dead-page proof.",
        "readiness_risks": [
            "Related account, cost-centre, funding-stream, and tax-rate IDs use global exists validation; same-context ownership is not established before draft persistence.",
            "Immediate-post flow can persist a draft before later posting validation fails, and tax-rate context is not validated during posting.",
            "Cost-centre Site scope and approved-Site correctness are unproved; journal creation defaults Site to null.",
            "Nullable legacy organisational scopes can fail open, and backend balance rules differ from the positive-balance UI rule.",
        ],
    },
}

PARTITION_METADATA = {
    "A": {"reviewer_task_paths": ["/root/run125_accounts_create"], "verdict": "GO_ONE_CHART_OWNER_PAGE"},
    "B": {"reviewer_task_paths": ["/root/run125_accounts_show_edit"], "verdict": "GO_TWO_CHART_OWNER_PAGES"},
    "C": {"reviewer_task_paths": ["/root/run125_journal_reporting"], "verdict": "GO_ONE_MANUAL_JOURNAL_OWNER_PAGE"},
}

EXPECTED_REVIEW_IDENTITY = {
    **EXPECTED_COHORT_IDENTITY,
    "owner_candidate_id_list_sha256": "ff4c1c639cc7519bdea3ef29ea4ff6592d0f8acc293bc07c4048f78b124262e7",
    "owner_page_record_id_list_sha256": "7736ff160732728ccca2ff900b181c13ef631dc19c605984848ee3a56e11c75b",
    "owner_page_feature_key_list_sha256": "4479c933cc3608994b660ccbf3d6f55de094a855a3dbf6e6ed5b1845f5697c8a",
    "owner_candidate_record_sha256_list_sha256": "53fe8b95bd3ad0a05acadb64a4d8105c701ab892c17eb9a83d20e69a0cf5aa73",
    "decision_records_sha256": "d3888ebcdbc9d7aeedcb11a691b6707e47306ebb8bb8ed194b255004cb900f8e",
    "decision_record_sha256_list_sha256": "eb75142c8e79a442bfdb02e75611971f8e71442c8106554b91f0fabcdd293f4a",
    "partition_reviews_sha256": "f1669a490dc0367cf2f4770a86a50f6de89d22e36d810f7d4b7c024059433824",
}


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def canonical_json_sha256(value: Any) -> str:
    raw = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(raw).hexdigest()


def canonical_list_sha256(values: list[str]) -> str:
    return canonical_json_sha256(values)


def load_json(path: Path) -> dict[str, Any]:
    value = json.loads(path.read_text(encoding="utf-8"))
    assert isinstance(value, dict)
    return value


def git(*args: str) -> str:
    return subprocess.run(["git", *args], cwd=REPO, check=True, capture_output=True, text=True, encoding="utf-8").stdout.strip()


def assert_review_slice(source_file: str, review_slice: dict[str, Any]) -> None:
    assert review_slice["text_sha256"] == hashlib.sha256(review_slice["text"].encode("utf-8")).hexdigest()
    assert review_slice["line_count"] == review_slice["end_line"] - review_slice["start_line"] + 1
    lines = (REPO / source_file).read_text(encoding="utf-8").splitlines(keepends=True)
    actual = "".join(lines[review_slice["start_line"] - 1:review_slice["end_line"]])
    assert actual.rstrip("\n") == review_slice["text"].rstrip("\n")


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
    for relative, digest in cohort["pins"]["inputs"].items():
        assert sha256_file(AUDIT_DIR / relative) == digest, relative


def assert_candidate(candidate: dict[str, Any]) -> None:
    without_digest = {key: value for key, value in candidate.items() if key != "candidate_record_sha256"}
    assert candidate["candidate_record_sha256"] == canonical_json_sha256(without_digest)
    evidence = dict(candidate["evidence_digests"])
    joined = evidence.pop("joined_candidate_evidence_sha256")
    assert joined == canonical_json_sha256(evidence)
    page = candidate["page_source"]
    parent = candidate["reviewed_parent_action_provenance"]
    page_path = REPO / page["page_file"]
    controller_path = REPO / parent["controller_file"]
    assert sha256_file(page_path) == page["page_file_sha256"]
    assert len(page_path.read_text(encoding="utf-8").splitlines()) == page["page_line_count"]
    assert git("rev-parse", f"{APPLICATION_COMMIT}:{page['page_file']}") == page["page_file_blob_id"]
    assert sha256_file(controller_path) == parent["controller_file_sha256"]
    assert git("rev-parse", f"{APPLICATION_COMMIT}:{parent['controller_file']}") == parent["controller_file_blob_id"]
    assert_review_slice(parent["controller_file"], parent["method_review_slice"])
    selected = parent["selected_render_callsite"]
    assert selected["page_file"] == page["page_file"]
    assert selected["source_file"] == parent["controller_file"]
    assert selected["source_file_sha256"] == parent["controller_file_sha256"]
    assert selected["source_file_blob_id"] == parent["controller_file_blob_id"]
    assert page["render_call_count"] == 1
    assert parent["page_ownership_inheritance_prohibited"] is True
    assert parent["parent_route_outcome_may_determine_page_outcome"] is False
    assert candidate["collision_checks"] == {
        "current_page_owner_collision": False,
        "direct_queue_overlap": False,
        "page_candidate_lane_convergence": False,
        "source_record_key_collision": False,
    }


def build() -> dict[str, Any]:
    cohort = load_json(COHORT_PATH)
    assert_workspace_and_inputs(cohort)
    records = sorted(cohort["records"], key=lambda row: row["candidate_id"])
    assert len(records) == 4
    assert set(DECISIONS) == {row["candidate_id"] for row in records}
    assert cohort["identity"] == EXPECTED_COHORT_IDENTITY
    assert cohort["identity"]["records_sha256"] == canonical_json_sha256(records)
    assert cohort["counts"]["ownership_credit_awarded"] == 0
    assert cohort["audit_completion_test_met"] is False
    assert [cohort["review_partitions"][key]["assigned_candidates"] for key in "ABC"] == [1, 2, 1]

    decision_rows: list[dict[str, Any]] = []
    for candidate in records:
        assert_candidate(candidate)
        assert candidate["fresh_review_state"]["status"] == "PENDING"
        page = candidate["page_source"]
        parent = candidate["reviewed_parent_action_provenance"]
        review = DECISIONS[candidate["candidate_id"]]
        outcome = review["outcome"]
        assert outcome in cohort["fresh_review_contract"]["allowed_outcomes"]
        assert review["canonical_feature_ids"] == [candidate["candidate_feature_id"]]
        row = {
            "candidate_id": candidate["candidate_id"],
            "partition_id": candidate["review_partition"],
            "page_feature_key": candidate["page_feature_key"],
            "page_record_id": page["page_record_id"],
            "page_file": page["page_file"],
            "candidate_feature_id": candidate["candidate_feature_id"],
            "canonical_feature_ids": review["canonical_feature_ids"],
            "parent_candidate_id": parent["parent_candidate_id"],
            "parent_route_record_id": parent["route_record_id"],
            "parent_projected_feature_id": parent["parent_projected_feature_id"],
            "parent_outcome": parent["parent_outcome"],
            "semantic_feature_differs_from_parent_projection": parent["semantic_feature_differs_from_parent_projection"],
            "render_source_anchor": parent["selected_render_callsite"]["source_anchor"],
            "candidate_record_sha256": candidate["candidate_record_sha256"],
            "joined_candidate_evidence_sha256": candidate["evidence_digests"]["joined_candidate_evidence_sha256"],
            "outcome": outcome,
            "source_loci": review["source_loci"],
            "rationale": review["rationale"],
            "uncertainty": review["uncertainty"],
            "static_readiness_risks": review["readiness_risks"],
            "page_ownership_authorized": outcome == "OWNER_PAGE",
            "route_ownership_authorized": False,
            "controller_action_bridge_authorized": False,
            "downstream_credit_authorized": False,
        }
        row["decision_record_sha256"] = canonical_json_sha256(row)
        decision_rows.append(row)

    counts = Counter(row["outcome"] for row in decision_rows)
    assert counts == {"OWNER_PAGE": 4}
    assert Counter(row["candidate_feature_id"] for row in decision_rows) == {
        "CAP-FIN-CHART-OF-ACCOUNTS": 3,
        "CAP-FIN-MANUAL-JOURNAL-LIFECYCLE": 1,
    }
    journal = decision_rows[-1]
    assert journal["parent_outcome"] == "EVIDENCE_GAP"
    assert journal["parent_projected_feature_id"] == "CAP-FIN-CHART-OF-ACCOUNTS"
    assert journal["candidate_feature_id"] == "CAP-FIN-MANUAL-JOURNAL-LIFECYCLE"
    assert journal["semantic_feature_differs_from_parent_projection"] is True

    partition_reviews = []
    for partition_id in "ABC":
        rows = [row for row in decision_rows if row["partition_id"] == partition_id]
        metadata = PARTITION_METADATA[partition_id]
        partition = {
            "partition_id": partition_id,
            "reviewer_task_paths": metadata["reviewer_task_paths"],
            "synthesis_task_path": "/root",
            "verdict": metadata["verdict"],
            "candidate_count": len(rows),
            "owner_pages": len(rows),
            "shared_relations": 0,
            "alias_or_redirect": 0,
            "dead_or_noncanonical": 0,
            "evidence_gaps": 0,
            "page_feature_key_list_sha256": cohort["review_partitions"][partition_id]["page_feature_key_list_sha256"],
            "mechanical_discrepancies": 0,
            "reviewer_wrote_files": False,
        }
        partition["partition_review_sha256"] = canonical_json_sha256(partition)
        partition_reviews.append(partition)

    outcome_conservation = {
        "reviewed_candidates": 4,
        "owner_pages": 4,
        "shared_relations": 0,
        "alias_or_redirect": 0,
        "dead_or_noncanonical": 0,
        "evidence_gaps": 0,
        "equation": "4 = 4 OWNER_PAGE + 0 SHARED_RELATION + 0 ALIAS_OR_REDIRECT + 0 DEAD_OR_NONCANONICAL + 0 EVIDENCE_GAP",
        "all_candidates_conserved": True,
    }
    review_identity = {
        **EXPECTED_COHORT_IDENTITY,
        "owner_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in decision_rows]),
        "owner_page_record_id_list_sha256": canonical_list_sha256([row["page_record_id"] for row in decision_rows]),
        "owner_page_feature_key_list_sha256": canonical_list_sha256([row["page_feature_key"] for row in decision_rows]),
        "owner_candidate_record_sha256_list_sha256": canonical_list_sha256([row["candidate_record_sha256"] for row in decision_rows]),
        "decision_records_sha256": canonical_json_sha256(decision_rows),
        "decision_record_sha256_list_sha256": canonical_list_sha256([row["decision_record_sha256"] for row in decision_rows]),
        "partition_reviews_sha256": canonical_json_sha256(partition_reviews),
    }
    if EXPECTED_REVIEW_IDENTITY:
        assert review_identity == EXPECTED_REVIEW_IDENTITY

    return {
        "schema_version": "1.0.0",
        "run_id": "RUN-125R-INDEPENDENT-OUTCOME-NEUTRAL-FINANCE-PAGE-GAP-REVIEW-WAVE-19",
        "status": "FRESH_REVIEW_GO_FOUR_FINANCE_OWNER_PAGES_ZERO_CURRENT_CREDIT",
        "generated_on": "2026-08-26",
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
        "architecture_rule": "Oblivion Findings is one operating organisation across multiple Sites. Page ownership is separate from approved-Site access, roles and permissions, canonical ownership, direct-object concealment, privacy, and ledger correctness.",
        "review_method": {
            "fresh_partition_review": True,
            "complete_page_source_reviewed": True,
            "exact_render_method_reviewed": True,
            "material_dependencies_reviewed": True,
            "canonical_matrix_user_job_reviewed": True,
            "parent_route_outcome_inherited": False,
            "journal_feature_projection_repaired_for_review": True,
            "reviewer_writes": 0,
        },
        "partition_reviews": partition_reviews,
        "page_decisions": decision_rows,
        "outcome_conservation": outcome_conservation,
        "decision": {
            "verdict": "GO_4_EXPLICIT_OWNER_PAGE",
            "mechanical_discrepancies": 0,
            "owner_pages": 4,
            "shared_relations": 0,
            "alias_or_redirect": 0,
            "dead_or_noncanonical": 0,
            "evidence_gaps": 0,
            "chart_of_accounts_owner_pages": 3,
            "manual_journal_owner_pages": 1,
            "static_page_owner_records_authorized": 4,
            "static_route_owner_records_authorized": 0,
            "static_controller_action_bridges_authorized": 0,
            "owner_only_overlay_authorized": True,
            "matrix_mutation_authorized": False,
            "gate_4_complete": False,
        },
        "identity": review_identity,
        "projected_integration_only": {
            "projection_is_current_credit": False,
            "current_bounded_source_records": "3929 = 648 owner + 3281 non-owner residual",
            "current_owner_surfaces": "648 = 295 route + 353 page",
            "authorized_projection": "652 = 295 route + 357 page; 3277 residual",
            "authorized_page_projection": "711 = 357 owner + 9 shared + 0 alias + 0 dead + 345 residual",
            "existing_evidence_gap_tagged_within_page_residual": 1,
            "static_controller_action_bridges": 83,
            "direct_exact_queue": "507 = 106 reviewed + 401 pending",
            "direct_exact_queue_without_current_static_ownership": 423,
            "distinct_feature_ids": 256,
            "projected_bounded_static_source_ownership_percent": "16.594553",
        },
        "credit_boundary": {
            "PAGE_REVIEW_DECISIONS_FOR_LATER_OVERLAY": True,
            "page_ownership": False,
            "route_ownership": False,
            "static_controller_action_bridge": False,
            "framework_route_reachability": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "direct_object_concealment": False,
            "privacy_correctness": False,
            "ledger_or_lifecycle_correctness": False,
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
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-independent-outcome-neutral-finance-page-gap-review-wave-19.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/raw-run-125r-independent-outcome-neutral-finance-page-gap-review-wave-19.json",
        ],
    }


def main() -> None:
    payload = build()
    encoded = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    if not OUTPUT_PATH.exists() or OUTPUT_PATH.read_bytes() != encoded:
        OUTPUT_PATH.write_bytes(encoded)
    print(json.dumps({
        "status": payload["status"],
        "output": OUTPUT_PATH.relative_to(AUDIT_DIR).as_posix(),
        "sha256": hashlib.sha256(encoded).hexdigest(),
        "owner_pages": payload["decision"]["owner_pages"],
        "identity": payload["identity"],
        "page_ownership_credit_awarded": 0,
    }, indent=2))


if __name__ == "__main__":
    main()
