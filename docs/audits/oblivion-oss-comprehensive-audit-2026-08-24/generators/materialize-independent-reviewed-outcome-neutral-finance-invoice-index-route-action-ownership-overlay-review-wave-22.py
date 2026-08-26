#!/usr/bin/env python3
"""Materialize the independent post-commit review of the RUN-138 overlay.

The receipt records three fresh read-only reviews. It verifies the exact one
route owner and controller-action bridge, cumulative bounded accounting,
source-packet and finding preservation, and the zero-credit boundary. It adds
no source ownership or downstream readiness credit itself.
"""

from __future__ import annotations

import ast
import csv
import hashlib
import json
import os
import runpy
import subprocess
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_PATH = (
    AUDIT_DIR
    / "evidence/source/raw-run-138r-independent-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json"
)
PRODUCER_PATH = (
    AUDIT_DIR
    / "evidence/source/current-run-138-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json"
)
GENERATOR_PATH = (
    AUDIT_DIR
    / "generators/integrate-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.py"
)
COHORT_PATH = (
    AUDIT_DIR
    / "evidence/source/root-run-137-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.json"
)
COHORT_REVIEW_PATH = (
    AUDIT_DIR
    / "evidence/source/raw-run-137r-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22.json"
)
MATRIX_PATH = AUDIT_DIR / "03-feature-to-benchmark-matrix.csv"

AUDIT_HEAD = "d6ebbfcacb96b292248208175db633288ab5a21c"
AUDIT_TREE = "266bc43ad6ee51c858c2624d14aaccbb082d00a6"
PRODUCER_CHECKPOINT_HEAD = "97c1813915da6122dd6cc3485d3c5338797c34d1"
PRODUCER_CHECKPOINT_TREE = "1ba2cecf1f091724cdaf45757f01029cc87d5e13"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
TESTS_TREE = "fef0122b31fdccbe2f9f805f7515666c74e2880a"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
GENERATOR_SHA256 = "76f9f34a249b901e4448166155eb0e5a314390bebfc90c6d28f5df08c1cb6baf"
PRODUCER_SHA256 = "005a55c952ec3f3b2a5bac9f3c99000fa4eae65a488764dfd1f4662063431701"
COHORT_SHA256 = "e2a6a346365ada6013b82f4e29aa955ffcedf7f3b53ab88279c700407d3012bc"
COHORT_REVIEW_SHA256 = "a3659294a8d2f9c203968a885da7b48f928d5341dbcb2b177eb85b40a058411f"
COHORT_GENERATOR_SHA256 = "93766689117c88173a08f8548a04d7e62f00eadf71fb7fefa302936e540c9bd9"
COHORT_REVIEW_MATERIALIZER_SHA256 = "4a4eb33dd34832b2182bfe27bf13f90f3a30e7406b74552e82dda2f0c73b99c5"
GENERATOR_BLOB_ID = "fe5e15df811aae227c0884adf86e1006625c4dea"
PRODUCER_BLOB_ID = "ce96719e3a0773e675aec42d3642fbfb9787c86e"
SOURCE_PACKET_SHA256 = "d357634ecafca5373cc7141d4d604599431ac836ed2e7274bd5b508d2fadf81e"

EXPECTED_COUNTS = {
    "source_owner_records": 661,
    "route_owner_records": 304,
    "page_owner_records": 357,
    "distinct_feature_ids": 256,
    "distinct_H_feature_ids": 234,
    "distinct_D_feature_ids": 22,
    "route_distinct_feature_ids": 63,
    "page_distinct_feature_ids": 242,
    "route_page_feature_overlap": 49,
    "static_controller_action_bridges": 92,
    "bounded_static_source_denominator": 3929,
    "bounded_static_source_ownership_percent": "16.823619",
    "bounded_static_source_residual_records": 3268,
    "residual_explicit_unmapped_routes": 2897,
    "semantic_shared_routes": 12,
    "reviewed_alias_routes": 5,
    "reviewed_dead_routes": 0,
    "evidence_gap_routes_tagged_within_residual": 7,
    "residual_unadjudicated_page_roots": 345,
    "semantic_shared_page_roots": 9,
    "reviewed_alias_page_roots": 0,
    "reviewed_dead_page_roots": 0,
    "evidence_gap_page_roots_tagged_within_residual": 1,
}

EXPECTED_QUEUE = {
    "direct_exact_queue_records": 507,
    "reviewed_queue_surface_rows": 115,
    "owner_queue_surface_rows": 93,
    "shared_queue_surface_rows": 10,
    "alias_queue_surface_rows": 5,
    "dead_queue_surface_rows": 0,
    "evidence_gap_queue_surface_rows": 7,
    "pending_unreviewed_queue_surface_rows": 392,
    "queue_surfaces_without_ownership": 414,
    "new_reviewed_route_surface_rows": 1,
    "new_owner_route_surface_rows": 1,
    "new_shared_route_surface_rows": 0,
    "new_alias_route_surface_rows": 0,
    "new_dead_route_surface_rows": 0,
    "new_evidence_gap_route_surface_rows": 0,
    "wholesale_queue_ownership_authorized": False,
}

EXPECTED_IDENTITY = {
    "owner_candidate_id_list_sha256": "fa7a0f3b58b92c921488067dc8cabcef0c6c9ac53e32bf370044aac0ea0abca3",
    "owner_route_record_id_list_sha256": "53bdc83541b806c06969b4cb77afcad8c70c458eaee9f68105b282eaecf327f7",
    "owner_source_record_key_list_sha256": "a785bed63533ecda887b3a89d3ffd29d03992b5335852d18f87ee1624d6bf4e9",
    "owner_action_key_list_sha256": "3745b3df2eb3a9d812b62f81495a1f8b313d4ac8002361a185e46dd034f0213b",
    "owner_bridge_key_list_sha256": "0d8f3141c6558559b169e4c8b04f90f2d1fecde685c701e648a602c799c38f58",
    "owner_candidate_record_sha256_list_sha256": "3e551db02de22e0601929700a71f54f54926a893ce4a718c312436d56a81d131",
    "owner_decision_record_sha256_list_sha256": "b942160fc2197522018cf04de8fd9929e71ac9166d2891cb499c5154f78fed06",
    "owner_queue_id_list_sha256": "31ccc591157abc1b8c87291619db9382a0bbc0516826c9a13bf4e68afe26f7e3",
    "owner_queue_key_list_sha256": "3182d1c35ad750953e38bd8808659357d56c74a85c7070e9e354c3639a5ebd83",
    "new_union_feature_id_list_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
    "new_route_feature_id_list_sha256": "7bc53a139456c100c3b52608228bf9f2a5776a4ad429372404c05663b5dc021d",
    "new_page_feature_id_list_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
    "prior_source_record_key_list_sha256": "3e6fadc773711705aeb62da073934a05e4404edf6b9a174dc2a2465f8091b0bd",
    "prior_source_record_id_list_sha256": "ce32de5f14093c8125a96e25b97912b10e48bf1cce1b9be5841e70739d76001d",
    "combined_source_record_key_list_sha256": "06d83edf37cd73f331dcb3866e7fdde9451ee83e098d63e0a34bad89fc27c36e",
    "combined_source_record_id_list_sha256": "239389f8390a920606207e71e7a61e98b57d2a1c56895d1a9e0e6fcec5065ced",
    "combined_feature_id_list_sha256": "874e55340e6abb600c50a64a147b81b9b5467587ee77f9e45eace4eb85ac55ca",
    "combined_route_feature_id_list_sha256": "fc618328f72dfa1375407b15b00617afb7f3ec971aa1d8d128fc8fd22e25b0b9",
    "combined_page_feature_id_list_sha256": "2fa6a11a5c66a016366db84ce89931db906b26cc911dfa88490cb6561ff261e8",
    "combined_route_page_overlap_feature_id_list_sha256": "2c57b6c12c3117e85e3db885e789788885d2de92d4c1b080b5953cfeec0977be",
    "prior_bridge_key_list_sha256": "9f5cc86fc8c9527685bf1f3cce4801b9ee8c974e30f5fdb424b3cd8e793c0834",
    "combined_bridge_key_list_sha256": "464f1ff258206d9246a6d34376878901e6e85c32ed584c63c85c9eb08f2d163b",
    "new_reviewed_queue_key_list_sha256": "3182d1c35ad750953e38bd8808659357d56c74a85c7070e9e354c3639a5ebd83",
    "prior_reviewed_queue_key_list_sha256": "464bde3341bea712d04a0b14e05863439937c842fd86e8d95767ec5ca7e2d591",
    "combined_reviewed_queue_key_list_sha256": "25200b6fef377defdaa3ff5ba5f36915c0a962460e92b440af8f1f4c1e9dce49",
    "combined_route_record_id_list_sha256": "7576468148a8f01cd57e853d4d080768517079708435f5d2bd76bd89af7611f3",
    "combined_route_source_record_key_list_sha256": "ef3eed351791aed84576c4f11530abb91bae9546f5abeb5f38842e7aa8e090f8",
    "combined_page_record_id_list_sha256": "abef0c7e0e1ac503e06eb563aee6b19718f1192d7716ea214ee5d4859dce162e",
    "combined_page_source_record_key_list_sha256": "652acf45451422bfd45af9ea1f12bffffef6f1e386511ec0dbda6d520e0bdd9b",
    "new_overlay_source_records_sha256": "8fd147510181d1241d0b9b659efbf600bde02d2875dc6df969c8acd760a432ac",
    "new_overlay_row_sha256_list_sha256": "339114b107b4ec257cdd07f3fdb2b58fbfd09e41f7f36f3f3ff6184deeb87d36",
    "new_action_bridges_sha256": "dce3711e933a1e53bdd531daed4c467af6beda12e465d397ed6983bfc63070ba",
    "new_action_bridge_row_sha256_list_sha256": "b7c7dd183565e7ab16d4bea3a522c1d31ffc57a699d3c9ab6e52fd98a6422c39",
    "reviewed_decision_record_sha256_list_sha256": "b942160fc2197522018cf04de8fd9929e71ac9166d2891cb499c5154f78fed06",
    "reviewed_decisions_sha256": "9ed9e01043ad5299d7a75a6df4f39c4c3937b638c5c644aec9cc08cdc0ac385b",
    "synthesis_record_sha256": "c13d4987fc3f7d3423038dd521efe64912f9eb32ae1d9d53e20cd8d2c087ca50",
    "source_packet_expansions_sha256": "4473a0e032f32c790215b0565d9de361a71f282a821ab164cb525c63cf02f99a",
    "assurance_findings_sha256": "2513c9a99a955694a13fb49bddaeced702b59adb50622940064b1c910e5a791c",
    "assurance_finding_id_list_sha256": "901ba3ac9459e450f7d7835bcb566f603e8ed9e482f5ca13d72ef3fee3c39135",
    "independent_reviews_sha256": "90fa53e6e91b4ae45ab88878bf4b3599eefabadcd4515b49783ae199457d1dfd",
    "independent_review_record_sha256_list_sha256": "f3763abf1e9ef7f4eec6160c33f743376d07afd98da8f8ed6b1aa7062d74a49a",
}

OWNER_ROW_KEYS = {
    "overlay_mapping_id",
    "candidate_id",
    "candidate_record_sha256",
    "decision_record_sha256",
    "surface",
    "source_record_id",
    "source_record_key",
    "feature_id",
    "feature_class",
    "module",
    "user_job",
    "source",
    "review_outcome",
    "review_rationale",
    "static_source_feature_ownership_credit",
    "credit_boundary",
    "overlay_row_sha256",
}
EXPECTED_SOURCE_RECORD_IDS = {"RUN077-ROUTE-0634"}
EXPECTED_METHODS = {"index"}
EXPECTED_OWNER_ROW_HASHES = [
    "8c2c5d6685c30813b0267f0e6b47d819629d504329c274c7b8a36fb432e4be6c",
]
EXPECTED_BRIDGE_HASHES = [
    "c27cc365453b1e0cf99f63396683c8cb913a9fac5752b69b6eaefc40a4b35bf9",
]
EXPECTED_TRUE_CREDIT = {
    "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD",
    "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION",
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
    def reject_duplicate_keys(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, (path, key)
            result[key] = value
        return result

    value = json.loads(path.read_text(encoding="utf-8"), object_pairs_hook=reject_duplicate_keys)
    assert isinstance(value, dict), path
    return value


def git(*args: str) -> str:
    return subprocess.run(
        ["git", *args],
        cwd=REPO,
        check=True,
        capture_output=True,
        text=True,
        encoding="utf-8",
    ).stdout.strip()


def assert_row_digest(row: dict[str, Any], digest_key: str) -> None:
    body = {key: value for key, value in row.items() if key != digest_key}
    assert row[digest_key] == canonical_json_sha256(body)


def assert_no_duplicate_literal_dict_keys(path: Path) -> int:
    tree = ast.parse(path.read_text(encoding="utf-8"), filename=str(path))
    dictionaries = 0
    for node in ast.walk(tree):
        if not isinstance(node, ast.Dict):
            continue
        dictionaries += 1
        keys = [key.value for key in node.keys if isinstance(key, ast.Constant) and isinstance(key.value, str)]
        assert len(keys) == len(set(keys)), (path, keys)
    return dictionaries


def assert_workspace() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == AUDIT_HEAD
    assert git("rev-parse", "HEAD^{tree}") == AUDIT_TREE
    assert git("rev-parse", "HEAD^") == PRODUCER_CHECKPOINT_HEAD
    assert git("rev-parse", "HEAD^^{tree}") == PRODUCER_CHECKPOINT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "HEAD:app") == APP_TREE
    assert git("rev-parse", "HEAD:routes") == ROUTES_TREE
    assert git("rev-parse", "HEAD:resources/js") == RESOURCES_JS_TREE
    assert git("rev-parse", "HEAD:resources/js/pages") == RESOURCES_JS_PAGES_TREE
    assert git("rev-parse", "HEAD:tests") == TESTS_TREE
    committed = set(git("diff-tree", "--no-commit-id", "--name-only", "-r", "HEAD").splitlines())
    assert committed == {
        GENERATOR_PATH.relative_to(REPO).as_posix(),
        PRODUCER_PATH.relative_to(REPO).as_posix(),
    }
    assert git("hash-object", str(GENERATOR_PATH)) == git(
        "rev-parse", f"HEAD:{GENERATOR_PATH.relative_to(REPO).as_posix()}"
    ) == GENERATOR_BLOB_ID
    assert git("hash-object", str(PRODUCER_PATH)) == git(
        "rev-parse", f"HEAD:{PRODUCER_PATH.relative_to(REPO).as_posix()}"
    ) == PRODUCER_BLOB_ID
    allowed = {
        f"?? {Path(__file__).relative_to(REPO).as_posix()}",
        f"?? {OUTPUT_PATH.relative_to(REPO).as_posix()}",
    }
    status = set(git("status", "--porcelain").splitlines())
    assert status <= allowed, status
    assert f"?? {Path(__file__).relative_to(REPO).as_posix()}" in status
    assert not git("status", "--porcelain", "--", "app", "routes", "resources/js", "tests")
    assert not list(AUDIT_DIR.rglob("__pycache__"))


def exact_replay() -> tuple[dict[str, Any], int]:
    producer_dict_literals = assert_no_duplicate_literal_dict_keys(GENERATOR_PATH)
    namespace = runpy.run_path(str(GENERATOR_PATH))
    producer_build = namespace["build"]
    live_git = producer_build.__globals__["git"]
    expected_status = "\n".join(
        [
            f"?? {GENERATOR_PATH.relative_to(REPO).as_posix()}",
            f"?? {PRODUCER_PATH.relative_to(REPO).as_posix()}",
        ]
    )

    def replay_checkpoint_git(*args: str) -> str:
        if args == ("rev-parse", "HEAD"):
            return PRODUCER_CHECKPOINT_HEAD
        if args == ("rev-parse", "HEAD^{tree}"):
            return PRODUCER_CHECKPOINT_TREE
        if args == ("status", "--porcelain"):
            return expected_status
        return live_git(*args)

    producer_build.__globals__["git"] = replay_checkpoint_git
    try:
        rebuilt = producer_build()
    finally:
        producer_build.__globals__["git"] = live_git
    rebuilt_bytes = (json.dumps(rebuilt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    assert rebuilt_bytes == PRODUCER_PATH.read_bytes()
    assert sha256_bytes(rebuilt_bytes) == PRODUCER_SHA256
    return rebuilt, producer_dict_literals


def build() -> dict[str, Any]:
    assert_workspace()
    assert sha256_file(GENERATOR_PATH) == GENERATOR_SHA256
    assert sha256_file(PRODUCER_PATH) == PRODUCER_SHA256
    assert sha256_file(COHORT_PATH) == COHORT_SHA256
    assert sha256_file(COHORT_REVIEW_PATH) == COHORT_REVIEW_SHA256
    assert sha256_file(MATRIX_PATH) == MATRIX_SHA256
    overlay, producer_dict_literals = exact_replay()
    cohort = load_json(COHORT_PATH)
    review = load_json(COHORT_REVIEW_PATH)

    assert overlay["pins"]["generator_sha256"] == GENERATOR_SHA256
    assert overlay["pins"]["cohort_generator_sha256"] == COHORT_GENERATOR_SHA256
    assert overlay["pins"]["review_materializer_sha256"] == COHORT_REVIEW_MATERIALIZER_SHA256
    assert overlay["pins"]["cohort_source_review_packet_sha256"] == SOURCE_PACKET_SHA256
    assert overlay["pins"]["matrix_sha256"] == MATRIX_SHA256
    assert overlay["pins"]["checkpoint_commit"] == PRODUCER_CHECKPOINT_HEAD
    assert overlay["pins"]["checkpoint_tree"] == PRODUCER_CHECKPOINT_TREE
    assert len(overlay["pins"]["inputs"]) == 23
    for relative, expected in overlay["pins"]["inputs"].items():
        assert sha256_file(AUDIT_DIR / relative) == expected, relative

    candidates = {row["candidate_id"]: row for row in cohort["records"]}
    decisions = {row["candidate_id"]: row for row in review["action_decisions"]}
    owner_rows = overlay["overlay_source_records"]
    bridges = overlay["new_static_controller_action_bridges"]
    assert len(candidates) == len(decisions) == len(owner_rows) == len(bridges) == 1
    candidate_ids = set(candidates)
    assert candidate_ids == set(decisions) == {row["candidate_id"] for row in owner_rows}
    assert candidate_ids == {row["candidate_id"] for row in bridges}
    assert {row["source_record_id"] for row in owner_rows} == EXPECTED_SOURCE_RECORD_IDS
    assert {row["method"] for row in bridges} == EXPECTED_METHODS
    assert [row["overlay_row_sha256"] for row in owner_rows] == EXPECTED_OWNER_ROW_HASHES
    assert [row["bridge_row_sha256"] for row in bridges] == EXPECTED_BRIDGE_HASHES

    for row in owner_rows:
        assert set(row) == OWNER_ROW_KEYS
        assert_row_digest(row, "overlay_row_sha256")
        candidate = candidates[row["candidate_id"]]
        decision = decisions[row["candidate_id"]]
        assert_row_digest(candidate, "candidate_record_sha256")
        assert_row_digest(decision, "decision_record_sha256")
        assert row["candidate_record_sha256"] == candidate["candidate_record_sha256"]
        assert row["decision_record_sha256"] == decision["decision_record_sha256"]
        assert row["source"] == candidate["route_source"]
        assert row["source_record_id"] == decision["route_record_id"]
        assert row["feature_id"] == decision["candidate_feature_id"]
        assert row["candidate_record_sha256"] == "ef62ffbe177d4ffb6474492d54a2d08e90ee342f4f05d5bd5094f0dc47c84d8d"
        assert row["decision_record_sha256"] == "ebe22dd2ca9e3a0e53cabfab807c315f5dba2496a3b7c406deab6751aa5747fa"
        assert row["source_record_key"] == "route|RUN077-ROUTE-0634|CAP-FIN-BILLING-INVOICE-LIFECYCLE"
        assert row["feature_id"] == "CAP-FIN-BILLING-INVOICE-LIFECYCLE"
        assert row["review_rationale"] == decision["rationale"]
        assert row["review_outcome"] == decision["outcome"] == "OWNER_ROUTE_ACTION"
        assert row["static_source_feature_ownership_credit"] is True
        assert len(row["credit_boundary"]) == 26
        assert not any(row["credit_boundary"].values())
        assert decision["route_ownership_authorized"] is True
        assert decision["controller_action_bridge_authorized"] is True
        assert decision["page_ownership_authorized"] is False
        assert decision["prior_page_owner_context_inherited_or_recredited"] is False
        assert decision["current_overlay_credit_awarded"] is False
        assert decision["reviewer_wrote_files"] is False
        assert decision[
            "site_permission_privacy_direct_object_query_projection_lifecycle_concurrency_correctness_authorized"
        ] is False
        assert decision[
            "runtime_database_build_browser_test_benchmark_ease_release_pass_completion_authorized"
        ] is False

    source_blob_checks = 0
    for bridge in bridges:
        assert_row_digest(bridge, "bridge_row_sha256")
        candidate = candidates[bridge["candidate_id"]]
        decision = decisions[bridge["candidate_id"]]
        primary = candidate["controller_action"]["primary_method_slice"]
        assert bridge["candidate_record_sha256"] == candidate["candidate_record_sha256"]
        assert bridge["decision_record_sha256"] == decision["decision_record_sha256"]
        assert bridge["route_record_id"] == candidate["route_source"]["route_record_id"]
        assert bridge["controller_fqcn"] == candidate["controller_action"]["controller_fqcn"]
        assert bridge["controller_file"] == primary["source_file"]
        assert bridge["controller_file_sha256"] == primary["source_file_sha256"]
        assert bridge["controller_file_blob_id"] == primary["source_file_blob_id"]
        assert bridge["method"] == primary["method"] == decision["controller_method"]
        assert bridge["definition_anchor"] == primary["definition_anchor"]
        assert bridge["method_review_slice_sha256"] == primary["review_slice"]["text_sha256"]
        assert bridge["static_controller_action_bridge_credit"] is True
        assert all(
            bridge[key] is False
            for key in (
                "page_ownership_credit",
                "correctness_credit",
                "runtime_credit",
                "application_browser_credit",
                "executed_test_credit",
                "completion_credit",
            )
        )
        source_path = REPO / bridge["controller_file"]
        assert sha256_file(source_path) == bridge["controller_file_sha256"]
        assert git("hash-object", str(source_path)) == bridge["controller_file_blob_id"]
        source_blob_checks += 1

    route_file = REPO / next(iter(candidates.values()))["route_source"]["route_file"]
    assert sha256_file(route_file) == "cf6eed8437206aaf05feb541d031ce406382e13153a31bb831ef66b29994f1aa"
    assert git("hash-object", str(route_file)) == "3823dded458ab0f2bf20fe9b5de992acb414a5eb"
    source_blob_checks += 1
    route_text = route_file.read_text(encoding="utf-8")
    for candidate in candidates.values():
        source_line = int(candidate["route_source"]["source_anchor"].rsplit(":", 1)[1])
        route_statement_lines = route_text.splitlines()[source_line - 1 : source_line + 2]
        route_statement_raw = "\n".join(route_statement_lines).lstrip()
        route_statement_excerpt = " ".join(line.strip() for line in route_statement_lines)
        assert route_statement_excerpt == candidate["route_source"]["statement_excerpt"]
        assert sha256_bytes(route_statement_raw.encode("utf-8")) == candidate["route_source"]["statement_sha256"]

    assert overlay["combined_counts"] == EXPECTED_COUNTS
    assert overlay["queue_accounting"] == EXPECTED_QUEUE
    assert overlay["identity"] == EXPECTED_IDENTITY
    assert len(overlay["identity"]) == 41
    assert overlay["identity_discovery"] == {
        key: EXPECTED_IDENTITY[key] for key in overlay["identity_discovery"]
    }
    assert canonical_json_sha256(owner_rows) == EXPECTED_IDENTITY["new_overlay_source_records_sha256"]
    assert canonical_list_sha256([row["overlay_row_sha256"] for row in owner_rows]) == EXPECTED_IDENTITY[
        "new_overlay_row_sha256_list_sha256"
    ]
    assert canonical_json_sha256(bridges) == EXPECTED_IDENTITY["new_action_bridges_sha256"]
    assert canonical_list_sha256([row["bridge_row_sha256"] for row in bridges]) == EXPECTED_IDENTITY[
        "new_action_bridge_row_sha256_list_sha256"
    ]
    assert 3929 == 661 + 3268
    assert 661 == 304 + 357
    assert 3218 == 304 + 12 + 5 + 0 + 2897
    assert 711 == 357 + 9 + 0 + 0 + 345
    assert 256 == 63 + 242 - 49 == 234 + 22
    assert 507 == 115 + 392
    assert 115 == 93 + 10 + 5 + 0 + 7
    assert 414 == 392 + 10 + 5 + 0 + 7

    packet = cohort["source_review_packet"]
    preserved_packet = overlay["source_packet_expansion_preservation"]
    assert packet["source_review_packet_sha256"] == SOURCE_PACKET_SHA256
    assert packet["required_source_file_count"] == 33
    assert packet["material_dependency_method_slice_count"] == 10
    assert packet["source_review_complete"] is False
    assert packet["source_packet_completeness_claimed"] is False
    assert packet["material_dependency_semantics_complete"] is False
    required_source_files = packet["required_source_files"]
    assert len(required_source_files) == 33
    for source in required_source_files:
        source_path = REPO / source["path"]
        assert sha256_file(source_path) == source["sha256"]
        assert git("hash-object", str(source_path)) == source["blob_id"]
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{source['path']}") == source["application_commit_blob_id"]
        assert source["blob_id"] == source["application_commit_blob_id"]
        source_blob_checks += 1
    method_slices = packet["material_dependency_method_slices"]
    assert len(method_slices) == 10
    for method_slice in method_slices:
        source_path = REPO / method_slice["source_file"]
        assert sha256_file(source_path) == method_slice["source_file_sha256"]
        assert git("hash-object", str(source_path)) == method_slice["source_file_blob_id"]
        source_blob_checks += 1
    assert preserved_packet["total_disclosed_expansion_entries"] == 12
    assert preserved_packet["widened_existing_packet_files"] == 7
    assert preserved_packet["newly_followed_files"] == 5
    assert preserved_packet["expanded_file_records"] == review["source_packet_expansion"]["expanded_files"]
    assert canonical_json_sha256(preserved_packet["expanded_file_records"]) == EXPECTED_IDENTITY[
        "source_packet_expansions_sha256"
    ]
    for expansion in preserved_packet["expanded_file_records"]:
        source_path = REPO / expansion["path"]
        assert sha256_file(source_path) == expansion["sha256"]
        assert git("hash-object", str(source_path)) == expansion["head_blob_id"]
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{expansion['path']}") == expansion["application_commit_blob_id"]
        assert expansion["head_blob_id"] == expansion["application_commit_blob_id"]
        assert expansion["expansion_changes_original_packet_bytes"] is False
        assert expansion["expansion_authorizes_correctness_credit"] is False
        source_blob_checks += 1
    assert source_blob_checks == 57

    findings = overlay["assurance_findings_preservation"]
    assert findings["candidate_findings"] == 6
    assert findings["shared_findings"] == 3
    assert findings["total_findings"] == len(findings["findings"]) == 9
    finding_ids = [row["finding_id"] for row in findings["findings"]]
    assert len(finding_ids) == len(set(finding_ids))
    assert canonical_json_sha256(findings["findings"]) == EXPECTED_IDENTITY["assurance_findings_sha256"]
    assert canonical_list_sha256(finding_ids) == EXPECTED_IDENTITY["assurance_finding_id_list_sha256"]
    assert findings["final_finding_credit_authorized"] is False
    assert findings["correctness_or_downstream_credit_authorized"] is False

    assert overlay["projection_reconciliation"] == {
        "run137r_projection_credit_awarded": False,
        "run138_current_static_overlay_credit_applied": True,
        "current_source_owner_records": 661,
        "current_route_owner_records": 304,
        "current_page_owner_records": 357,
        "current_static_controller_action_bridges": 92,
        "current_reviewed_queue_surface_rows": 115,
        "current_pending_unreviewed_queue_surface_rows": 392,
        "correctness_or_downstream_credit_authorized": False,
        "rule": "RUN137R authorized a projection only; RUN138 applies exactly one route owner and bridge with no page or correctness credit.",
    }
    assert overlay["page_context_boundary"]["new_page_owner_records"] == 0
    assert overlay["page_context_boundary"]["page_ownership_inherited"] is False
    assert overlay["page_context_boundary"]["page_ownership_reassigned"] is False
    assert overlay["page_context_boundary"]["existing_page_owner_context_rows"] == 2
    assert overlay["page_context_boundary"]["existing_index_page_record_id"] == "PAGE-ROOT-B4964DF8343DF25A"
    assert overlay["page_context_boundary"]["existing_show_page_record_id"] == "PAGE-ROOT-E1ACF667B368A747"
    assert overlay["noninheritance_boundary"]["selected_queue_index_zero_based"] == 77
    assert overlay["noninheritance_boundary"]["selected_queue_id"] == "RUN090-ROUTE-0078"
    assert overlay["noninheritance_boundary"]["selected_route_record_id"] == "RUN077-ROUTE-0634"
    assert overlay["noninheritance_boundary"]["prior_page_owner_context_inherited_or_recredited"] is False
    assert overlay["noninheritance_boundary"]["frontend_path_or_navigation_ownership_used"] is False
    assert overlay["noninheritance_boundary"]["next_queue_index_zero_based"] == 78
    assert overlay["noninheritance_boundary"]["next_queue_id"] == "RUN090-ROUTE-0079"
    assert overlay["noninheritance_boundary"]["next_route_record_id"] == "RUN077-ROUTE-0669"
    assert overlay["noninheritance_boundary"]["next_feature_id"] == "CAP-FIN-SITE-PORTFOLIO-OVERVIEW"
    assert overlay["noninheritance_boundary"]["next_boundary_selected_or_credited"] is False
    assert overlay["reviewer_lineage"]["independent_candidate_reviews"] == review["independent_candidate_reviews"]
    assert overlay["reviewer_lineage"]["synthesis_review"] == review["synthesis_review"]
    assert overlay["reviewer_lineage"]["all_reviewers_wrote_files"] is False
    assert overlay["denominator_boundary"] == {
        "run_077_bounded_static_records": 3929,
        "framework_expanded_route_page_denominator": None,
        "page_denominator_711_roots_vs_1058_page_tree_files_resolved": False,
        "complete_route_page_feature_crosswalk": False,
        "gate_4_complete": False,
    }
    assert {key for key, value in overlay["credit_boundary"].items() if value} == EXPECTED_TRUE_CREDIT
    assert overlay["audit_completion_test_met"] is False

    with MATRIX_PATH.open(newline="", encoding="utf-8-sig") as handle:
        matrix_rows = list(csv.DictReader(handle))
    assert len(matrix_rows) == 340
    assert sum(row.get("benchmark_mapping_credit", "").strip().lower() == "true" for row in matrix_rows) == 0

    payload = {
        "schema_version": "run-138r-independent-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22-v1",
        "run_id": "RUN-138R-INDEPENDENT-REVIEWED-OUTCOME-NEUTRAL-FINANCE-INVOICE-INDEX-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-22",
        "status": "GO_THREE_PART_POST_COMMIT_REVIEW_COMPLETE_1_OWNER_1_BRIDGE_BOUNDED_STATIC_ONLY",
        "reviewed_on": "2026-08-26",
        "decision": {
            "verdict": "GO",
            "independent_reviews": 3,
            "mechanical_source_blob_checks_reported": 57,
            "mechanical_identity_fields_recomputed": 41,
            "lineage_identity_fields_recomputed": 41,
            "input_hashes_verified": 23,
            "reviewers_wrote_files": 0,
            "mechanical_discrepancies": 0,
            "semantic_or_preservation_discrepancies": 0,
            "lineage_or_credit_discrepancies": 0,
            "arithmetic_identity_or_denominator_discrepancies": 0,
            "byte_provenance_or_credit_discrepancies": 0,
            "route_owner_records_verified": 1,
            "controller_action_bridges_verified": 1,
            "page_owner_records_verified": 0,
            "source_packet_expansion_records_verified": 12,
            "assurance_findings_verified": 9,
            "published_identity_fields_verified": 41,
            "bounded_static_route_feature_ownership_authorized": True,
            "static_controller_action_bridges_authorized": True,
            "static_page_feature_ownership_authorized": False,
            "wholesale_queue_ownership_authorized": False,
            "complete_route_page_feature_crosswalk_authorized": False,
            "matrix_mutation_authorized": False,
            "reporting_materialization_authorized": True,
            "correctness_or_downstream_credit_authorized": False,
            "gate_4_complete": False,
        },
        "pins": {
            "review_checkpoint_commit": AUDIT_HEAD,
            "review_checkpoint_tree": AUDIT_TREE,
            "producer_checkpoint_commit": PRODUCER_CHECKPOINT_HEAD,
            "producer_checkpoint_tree": PRODUCER_CHECKPOINT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "app_tree": APP_TREE,
            "routes_tree": ROUTES_TREE,
            "resources_js_tree": RESOURCES_JS_TREE,
            "resources_js_pages_tree": RESOURCES_JS_PAGES_TREE,
            "tests_tree": TESTS_TREE,
            "matrix_sha256": MATRIX_SHA256,
            "producer_generator": GENERATOR_PATH.relative_to(AUDIT_DIR).as_posix(),
            "producer_generator_sha256": GENERATOR_SHA256,
            "producer_generator_blob_id": GENERATOR_BLOB_ID,
            "producer": PRODUCER_PATH.relative_to(AUDIT_DIR).as_posix(),
            "producer_sha256": PRODUCER_SHA256,
            "producer_blob_id": PRODUCER_BLOB_ID,
            "cohort_sha256": COHORT_SHA256,
            "cohort_review_sha256": COHORT_REVIEW_SHA256,
            "materializer": Path(__file__).relative_to(AUDIT_DIR).as_posix(),
            "materializer_sha256": sha256_file(Path(__file__)),
            "producer_literal_dictionary_count_checked": producer_dict_literals,
            "review_materializer_literal_dictionary_count_checked": assert_no_duplicate_literal_dict_keys(Path(__file__)),
            "inputs": overlay["pins"]["inputs"],
        },
        "architecture_rule": (
            "Oblivion Findings is one operating organisation across multiple Sites. Static route and action ownership "
            "does not prove approved-Site access, permission correctness, canonical ownership, direct-object "
            "concealment, privacy, query or projection correctness, minimisation, lifecycle, concurrency, durability, "
            "or release readiness."
        ),
        "review_methods": [
            "Partition A independently checked exact committed files, worktree bytes, Git objects, deterministic replay, and conservation arithmetic.",
            "Partition B independently traced the selected route, controller actions, feature identity, page and sibling noninheritance, and Site/privacy/correctness boundaries.",
            "Partition C independently checked lineage, packet expansions, findings, all 41 identities, matrix zero-credit, and every downstream zero-credit field.",
            "The orchestrator replayed the committed producer byte-for-byte at its checkpoint and materialized this receipt from the three read-only returns.",
        ],
        "reviewers": [
            {
                "review_id": "RUN138R_POST_COMMIT_MECHANICAL_REPLAY_ACCOUNTING_1",
                "reviewer_task_path": "/root/run138_mechanical_seal",
                "verdict": "GO",
                "confidence": "HIGH",
                "discrepancies": 0,
                "source_blob_checks": 57,
                "identity_fields_recomputed": 41,
                "input_hashes_verified": 23,
                "source_key_collisions": 0,
                "source_id_collisions": 0,
                "bridge_key_collisions": 0,
                "queue_key_collisions": 0,
                "ast_duplicate_literal_keys": 0,
                "audit_pycache_count": 0,
                "verified_scope": [
                    "exact RUN138 commit, tree, two-file diff, blobs, hashes, and clean application trees",
                    "23 of 23 pinned inputs and a byte-identical mocked producer-era replay from the RUN137R checkpoint",
                    "one owner and bridge, 41 identities, collision freedom, conservation accounting, and no audit pycache",
                ],
                "audit_artifact_writes": False,
                "reviewer_wrote_files": False,
            },
            {
                "review_id": "RUN138R_PARTITION_2_SEMANTIC_OWNERSHIP_NONINHERITANCE",
                "reviewer_task_path": "/root/run138_semantic_seal",
                "verdict": "GO",
                "confidence": "HIGH",
                "discrepancies": 0,
                "exact_single_route_owner": True,
                "exact_single_action_bridge": True,
                "direct_index_render_verified": "app/Domain/Finance/Http/Controllers/InvoiceController.php:109",
                "existing_page_owners_preserved": 2,
                "new_or_recredited_page_owners": 0,
                "required_source_files": 33,
                "material_dependency_method_slices": 10,
                "source_packet_expansions": 12,
                "assurance_findings": 9,
                "owner_key": "route|RUN077-ROUTE-0634|CAP-FIN-BILLING-INVOICE-LIFECYCLE",
                "action_key": "RUN077-ROUTE-0634|app/Domain/Finance/Http/Controllers/InvoiceController.php:index|CAP-FIN-BILLING-INVOICE-LIFECYCLE",
                "bridge_key": [
                    "app/Domain/Finance/Http/Controllers/InvoiceController.php",
                    "index",
                    "CAP-FIN-BILLING-INVOICE-LIFECYCLE",
                ],
                "verified_scope": [
                    "the selected invoices.index route, InvoiceController::index, exact feature identity, and direct Index render",
                    "bounded CAP-FIN-BILLING-INVOICE-LIFECYCLE route ownership without page inheritance or recredit",
                    "preserved Index and Show page owners, next queue boundary 78, Site/privacy boundaries, and zero correctness credit",
                ],
                "audit_artifact_writes": False,
                "reviewer_wrote_files": False,
            },
            {
                "review_id": "RUN138R_PARTITION_3_LINEAGE_PROVENANCE_IDENTITY_CREDIT",
                "reviewer_task_path": "/root/run138_lineage_seal",
                "verdict": "GO",
                "confidence": "HIGH",
                "discrepancies": 0,
                "exact_added_files": 2,
                "workspace_clean": True,
                "identity_fields_recomputed": 41,
                "matrix_mapping_credit_rows": 0,
                "matrix_rows": 340,
                "all_downstream_credit_false": True,
                "verified_scope": [
                    "RUN137 cohort, two candidate reviews, synthesis, RUN138 generator and receipt lineage",
                    "33-file and 10-slice packet, twelve disclosed expansions, nine findings, and 41 published identities",
                    "exactly two bounded static credit keys true with matrix, correctness, runtime, browser, tests, benchmark, release, and completion false",
                ],
                "audit_artifact_writes": False,
                "reviewer_wrote_files": False,
            },
        ],
        "verified_combined_counts": EXPECTED_COUNTS,
        "verified_queue_accounting": EXPECTED_QUEUE,
        "verified_identity": EXPECTED_IDENTITY,
        "verified_conservation": overlay["outcome_conservation"],
        "verified_projection_reconciliation": overlay["projection_reconciliation"],
        "verified_denominator_boundary": overlay["denominator_boundary"],
        "verified_page_context_boundary": overlay["page_context_boundary"],
        "verified_noninheritance_boundary": overlay["noninheritance_boundary"],
        "verified_source_packet_expansion_preservation": preserved_packet,
        "verified_assurance_findings_preservation": findings,
        "verified_producer_credit_boundary": overlay["credit_boundary"],
        "credit_boundary": {
            "INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING": True,
            "new_source_ownership": False,
            "new_route_ownership": False,
            "new_page_ownership": False,
            "new_controller_action_bridge": False,
            "direct_exact_queue_review": False,
            "current_overlay_ownership_credit": False,
            "prior_page_owner_context_inherited_or_recredited": False,
            "frontend_caller_ownership": False,
            "complete_route_page_feature_crosswalk": False,
            "matrix_mutation": False,
            "canonical_object_ownership_correctness": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "privacy_correctness": False,
            "direct_object_correctness": False,
            "query_correctness": False,
            "projection_correctness": False,
            "response_minimization_correctness": False,
            "lifecycle_correctness": False,
            "concurrency_or_idempotency_correctness": False,
            "event_or_downstream_durability_correctness": False,
            "runtime": False,
            "database": False,
            "build": False,
            "application_browser": False,
            "responsive_application": False,
            "visual_application_workflow": False,
            "executed_tests": False,
            "application_source_mutation": False,
            "benchmark": False,
            "ease": False,
            "release": False,
            "pass": False,
            "final_finding": False,
            "completion": False,
            "audit_complete": False,
        },
        "mutation_attestation": {
            "reviewers_edited_audit_artifacts": False,
            "persistent_reviewer_workspace_mutation": False,
            "receipt_materialized_by_orchestrator_from_reviewer_returns": True,
            "application_source_changed": False,
            "matrix_changed": False,
            "runtime_or_external_system_changed": False,
            "audit_artifacts_only": True,
        },
        "attestation": (
            "Three fresh read-only post-commit reviews and an exact producer replay found zero discrepancies. "
            "Exactly one invoice-index route owner and one controller-action bridge remain authorized "
            "as bounded static ownership only. Gate 4 and the comprehensive audit remain open."
        ),
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-independent-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-review-wave-22.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/raw-run-138r-independent-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json",
        ],
    }
    assert len(payload) == 26
    assert len(payload["pins"]) == 25
    assert len(payload["review_methods"]) == 4
    assert len(payload["reviewers"]) == 3
    assert len(payload["verified_combined_counts"]) == 23
    assert len(payload["verified_queue_accounting"]) == 16
    assert len(payload["verified_identity"]) == 41
    assert len(payload["verified_conservation"]) == 11
    assert len(payload["verified_projection_reconciliation"]) == 10
    assert len(payload["verified_denominator_boundary"]) == 5
    assert len(payload["verified_page_context_boundary"]) == 12
    assert len(payload["verified_noninheritance_boundary"]) == 10
    assert len(payload["verified_source_packet_expansion_preservation"]) == 13
    assert len(payload["verified_assurance_findings_preservation"]) == 9
    assert len(payload["mutation_attestation"]) == 7
    assert len(payload["wrote_files"]) == 2
    assert {key for key, value in payload["verified_producer_credit_boundary"].items() if value} == EXPECTED_TRUE_CREDIT
    assert {key for key, value in payload["credit_boundary"].items() if value} == {
        "INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"
    }
    assert payload["decision"]["reporting_materialization_authorized"] is True
    assert payload["decision"]["correctness_or_downstream_credit_authorized"] is False
    assert payload["artifact_completion_test_met"] is True
    assert payload["audit_completion_test_met"] is False
    return payload


def main() -> None:
    payload = build()
    encoded = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    output_sha256 = sha256_bytes(encoded)
    if OUTPUT_PATH.exists():
        assert OUTPUT_PATH.read_bytes() == encoded, f"Refusing to overwrite different bytes: {OUTPUT_PATH}"
    else:
        temporary = OUTPUT_PATH.with_suffix(OUTPUT_PATH.suffix + ".tmp")
        temporary.write_bytes(encoded)
        assert sha256_file(temporary) == output_sha256
        os.replace(temporary, OUTPUT_PATH)
    assert sha256_file(OUTPUT_PATH) == output_sha256
    expected_status = {
        f"?? {Path(__file__).relative_to(REPO).as_posix()}",
        f"?? {OUTPUT_PATH.relative_to(REPO).as_posix()}",
    }
    assert set(git("status", "--porcelain").splitlines()) == expected_status
    print(
        json.dumps(
            {
                "status": payload["status"],
                "output": OUTPUT_PATH.relative_to(REPO).as_posix(),
                "sha256": output_sha256,
                "owners": payload["decision"]["route_owner_records_verified"],
                "bridges": payload["decision"]["controller_action_bridges_verified"],
                "reporting_authorized": payload["decision"]["reporting_materialization_authorized"],
                "gate_4_complete": payload["decision"]["gate_4_complete"],
                "audit_complete": payload["audit_completion_test_met"],
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
