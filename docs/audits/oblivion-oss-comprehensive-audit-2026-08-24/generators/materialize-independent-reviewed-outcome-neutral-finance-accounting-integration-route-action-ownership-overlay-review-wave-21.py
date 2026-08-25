#!/usr/bin/env python3
"""Materialize the independent post-commit review of the RUN-134 overlay.

The receipt records three fresh read-only reviews. It verifies the exact six
route owners and controller-action bridges, cumulative bounded accounting,
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
    / "evidence/source/raw-run-134r-independent-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json"
)
PRODUCER_PATH = (
    AUDIT_DIR
    / "evidence/source/current-run-134-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json"
)
GENERATOR_PATH = (
    AUDIT_DIR
    / "generators/integrate-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.py"
)
COHORT_PATH = (
    AUDIT_DIR
    / "evidence/source/root-run-133-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.json"
)
COHORT_REVIEW_PATH = (
    AUDIT_DIR
    / "evidence/source/raw-run-133r-independent-outcome-neutral-finance-accounting-integration-route-action-review-wave-21.json"
)
MATRIX_PATH = AUDIT_DIR / "03-feature-to-benchmark-matrix.csv"

AUDIT_HEAD = "d065bb602ff2f79a13c34c38a991a77a4b60ceff"
AUDIT_TREE = "882b2f8d0ea274a4bbb788ac9f56d0752b85fd4c"
PRODUCER_CHECKPOINT_HEAD = "3a763f742711279b0fc9b1e3a5f3a76b5f480dee"
PRODUCER_CHECKPOINT_TREE = "cf59494737503c0c692e265b91f26021371d0e05"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
TESTS_TREE = "fef0122b31fdccbe2f9f805f7515666c74e2880a"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
GENERATOR_SHA256 = "dec764ee611f3dd3bcc21484a04aab1773332dfec1e6cfec547f7abb4f2c56db"
PRODUCER_SHA256 = "e82514d96ac01db1cba72e9a469b2bb9c15404d2c42ff124c816e38b086bb669"
COHORT_SHA256 = "58d87fa101e4e1b51d232baf80e1a2e3ef75dad89f37dc0dcd615c2f16e29ce4"
COHORT_REVIEW_SHA256 = "7b56e738132dad35a0273b764d7f5e401219d6d52394306b41d2afac3a821420"
COHORT_GENERATOR_SHA256 = "476966a02322f59f385fb59dc9a55a3774e868e512cb58d5f0606698cbfd08af"
COHORT_REVIEW_MATERIALIZER_SHA256 = "f878b16d485ff802d9ca5fd51bbd82628d37efed3151eaadcc72ed777ad5783d"
GENERATOR_BLOB_ID = "6475cf294a752cad25fb33ab030c8c9ef018aa7c"
PRODUCER_BLOB_ID = "6f7f6cd45978e6b0b05276ea14efb6e239484d61"
SOURCE_PACKET_SHA256 = "d99da8f946f350820b2ed9484180dde61dd180016dd2fece8f472b8d7a0171d3"

EXPECTED_COUNTS = {
    "source_owner_records": 660,
    "route_owner_records": 303,
    "page_owner_records": 357,
    "distinct_feature_ids": 256,
    "distinct_H_feature_ids": 234,
    "distinct_D_feature_ids": 22,
    "route_distinct_feature_ids": 62,
    "page_distinct_feature_ids": 242,
    "route_page_feature_overlap": 48,
    "static_controller_action_bridges": 91,
    "bounded_static_source_denominator": 3929,
    "bounded_static_source_ownership_percent": "16.798167",
    "bounded_static_source_residual_records": 3269,
    "residual_explicit_unmapped_routes": 2898,
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
    "reviewed_queue_surface_rows": 114,
    "owner_queue_surface_rows": 92,
    "shared_queue_surface_rows": 10,
    "alias_queue_surface_rows": 5,
    "dead_queue_surface_rows": 0,
    "evidence_gap_queue_surface_rows": 7,
    "pending_unreviewed_queue_surface_rows": 393,
    "queue_surfaces_without_ownership": 415,
    "new_reviewed_route_surface_rows": 6,
    "new_owner_route_surface_rows": 6,
    "new_shared_route_surface_rows": 0,
    "new_alias_route_surface_rows": 0,
    "new_dead_route_surface_rows": 0,
    "new_evidence_gap_route_surface_rows": 0,
    "wholesale_queue_ownership_authorized": False,
}

EXPECTED_IDENTITY = {
    "owner_candidate_id_list_sha256": "d1f690f52a2e43287b1b2f119a7cd7f8d9b6ba3eca622860a401102e68a9f8b0",
    "owner_route_record_id_list_sha256": "29faf99573a19511a686a6281d363916970ee99f1908fd0ec9c2daeafc9d0ff7",
    "owner_source_record_key_list_sha256": "dc7246640d2f0dc93b09ea0f30f059eab85f712cdb2a79507fa2eb2053d3e8f0",
    "owner_action_key_list_sha256": "450ddf13b7dc723368d4b7dc51cb3332e51ed7504825a3ab93244af2c123a70b",
    "owner_bridge_key_list_sha256": "c497a3ad5ccc6e00da357d580b0a329a511430758bb692c795a7b9c5e47a11c1",
    "owner_candidate_record_sha256_list_sha256": "24f834eaf951b126287b8a15f076f52eb3bc17555efcae0970502d5ed8c2e7b4",
    "owner_decision_record_sha256_list_sha256": "1f856e3e6c4ca4a2b273fb0ab17f69101e27c2cc7cabda0d9f7b188ca6c76230",
    "owner_queue_id_list_sha256": "0ab1a5df6be68effc8e1d09302e154ce06100340ae35a0b988f471ff3d9e1b88",
    "owner_queue_key_list_sha256": "7759f09cbfc7d7965f56274be57a21e1211e709827b3050e3dc07b8c36112791",
    "new_union_feature_id_list_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
    "new_route_feature_id_list_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
    "new_page_feature_id_list_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
    "prior_source_record_key_list_sha256": "9de3288dc0b07f034d099fd3f6723b6ed7620fa9d2e8e003fc2bcd558b3e699e",
    "prior_source_record_id_list_sha256": "abdf6beef2cadc840860b0e43ce6acc3e15da51d85713d3fde98b6f8e37234b3",
    "combined_source_record_key_list_sha256": "3e6fadc773711705aeb62da073934a05e4404edf6b9a174dc2a2465f8091b0bd",
    "combined_source_record_id_list_sha256": "ce32de5f14093c8125a96e25b97912b10e48bf1cce1b9be5841e70739d76001d",
    "combined_feature_id_list_sha256": "874e55340e6abb600c50a64a147b81b9b5467587ee77f9e45eace4eb85ac55ca",
    "combined_route_feature_id_list_sha256": "89c69d9c74178944f705a60d004f01e4e5922d06a929b0dfba2342aafd6a3b7a",
    "combined_page_feature_id_list_sha256": "2fa6a11a5c66a016366db84ce89931db906b26cc911dfa88490cb6561ff261e8",
    "combined_route_page_overlap_feature_id_list_sha256": "00b1e6141c19311c68d7f8bcd154d971f2099fa768993cca4845baadd02aa217",
    "prior_bridge_key_list_sha256": "8130f9c6b87e89b8b079edf4ee9d9563a5d226fbbf858290416845d236c87d1f",
    "combined_bridge_key_list_sha256": "9f5cc86fc8c9527685bf1f3cce4801b9ee8c974e30f5fdb424b3cd8e793c0834",
    "new_reviewed_queue_key_list_sha256": "7759f09cbfc7d7965f56274be57a21e1211e709827b3050e3dc07b8c36112791",
    "prior_reviewed_queue_key_list_sha256": "113656569e79bc60019a2c82071fd7e7e3df19fab88b62e88d85d306e00d4de4",
    "combined_reviewed_queue_key_list_sha256": "464bde3341bea712d04a0b14e05863439937c842fd86e8d95767ec5ca7e2d591",
    "combined_route_record_id_list_sha256": "bb933706df26d1bc230eb321d7a319312da71eea9cea0b2809e30e68ce78dfdb",
    "combined_route_source_record_key_list_sha256": "dc72f5a3f6eeca16b2725badeaabec07d5d9a9bfbfea66f318f9d45ef3a6c4c1",
    "combined_page_record_id_list_sha256": "abef0c7e0e1ac503e06eb563aee6b19718f1192d7716ea214ee5d4859dce162e",
    "combined_page_source_record_key_list_sha256": "652acf45451422bfd45af9ea1f12bffffef6f1e386511ec0dbda6d520e0bdd9b",
    "new_overlay_source_records_sha256": "5d7755f3879f067cffddc717d3341b7d4dcbd96725d137ecf3309160de60bdf3",
    "new_overlay_row_sha256_list_sha256": "503ead9817b28c6a0dabd7ab1e073db759fc590e14ad1fc259cda72fab810a1d",
    "new_action_bridges_sha256": "90bb24eeacfb84862a39f0d55ef08e444b5264f6f850832e9ce0d568f191858c",
    "new_action_bridge_row_sha256_list_sha256": "f67c1a4c69964e10b345c307775668e0deab72fd7e5a4a13722131adf4030c75",
    "reviewed_decision_record_sha256_list_sha256": "1f856e3e6c4ca4a2b273fb0ab17f69101e27c2cc7cabda0d9f7b188ca6c76230",
    "reviewed_decisions_sha256": "9934548e8c3af6788a93fb6230d5e7e098c53b95a514ece0f62733d14b01408e",
    "synthesis_record_sha256": "a00fcc014462f0c3d2601f364a9571e52e45e66d717e2f079394df601d4545c3",
    "source_packet_expansions_sha256": "3a139fb0cae60345df17136d44cf430139eb03cf2b33260179712b33687de7fd",
    "assurance_findings_sha256": "19f1a6c796993c26b20c30afaf3becb9a303ba98c532ccd94eb034b536e1bf5c",
    "assurance_finding_id_list_sha256": "3df85cd9b3c5dec1eee473d9c8efaabcf5aed320714d79a8e009925c594f3a5a",
    "partition_reviews_sha256": "ba144061b7c441175cce261fd413f654be54ebe961bdc92f79384006343260f3",
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
EXPECTED_SOURCE_RECORD_IDS = {
    "RUN077-ROUTE-0593",
    "RUN077-ROUTE-0594",
    "RUN077-ROUTE-0596",
    "RUN077-ROUTE-0597",
    "RUN077-ROUTE-0598",
    "RUN077-ROUTE-0599",
}
EXPECTED_METHODS = {"store", "update", "testConnection", "destroy", "mapping", "updateMapping"}
EXPECTED_OWNER_ROW_HASHES = [
    "84466ff474839e1499ab26bdad9997a271b8420883314115f4283f382962f729",
    "029a87bc0e22b988fec04ec1e96448f73db4293204c43cf75b47f8e3b37abb6a",
    "758e1794ae63a90d61c349006ca51a211863d8b9c475628947dd5e7d53282e2b",
    "a65f90cef8559480a51c994b7ff2f78c4252fc494af5ce06f0f9d3cb656abb20",
    "7fbb9f2eb3f8423d7ed308be7fc6cfe7b1daa1a303a806ede47e8517662d0363",
    "8a36d9304cd7cf6fb2cb65db97ecbd79f9c3dca505c2c45420d5a063d05809b3",
]
EXPECTED_BRIDGE_HASHES = [
    "59f451aa0ff21722d291f476682d7e1e750a6eeb436cf8ef795e49fa5c4be918",
    "09a085bcdfebe5d82e7932a638a1404151cbbc1fdd4a88204263c416b463102a",
    "ea61fbd1450e5b853eaf8f7946810a97ffe8a8599bb2b0f15407bd52d2f11980",
    "700407428eb356765a37d0958fe9f538cc0fa444003f4aab4146e03d36ebac61",
    "8c2e3d3a935fcf53b518e2c6f47b5e977ebef7effa72cda397540385a93f8480",
    "039ba71fab85618acfe03cef3913ffc17361611837187ff47aa2953555167320",
]
EXPECTED_TRUE_CREDIT = {
    "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_6_RECORDS",
    "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_6_ACTIONS",
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
    assert not git("status", "--porcelain", "--", "app", "routes", "resources/js", "tests")


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
    for relative, expected in overlay["pins"]["inputs"].items():
        assert sha256_file(AUDIT_DIR / relative) == expected, relative

    candidates = {row["candidate_id"]: row for row in cohort["records"]}
    decisions = {row["candidate_id"]: row for row in review["action_decisions"]}
    owner_rows = overlay["overlay_source_records"]
    bridges = overlay["new_static_controller_action_bridges"]
    assert len(candidates) == len(decisions) == len(owner_rows) == len(bridges) == 6
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
        assert row["feature_id"] == "CAP-FIN-ACCOUNTING-INTEGRATION-CONFIGURATION"
        assert row["review_rationale"] == decision["rationale"]
        assert row["review_outcome"] == decision["outcome"] == "OWNER_ROUTE_ACTION"
        assert row["static_source_feature_ownership_credit"] is True
        assert len(row["credit_boundary"]) == 22
        assert not any(row["credit_boundary"].values())
        assert decision["route_ownership_authorized"] is True
        assert decision["controller_action_bridge_authorized"] is True
        assert decision["page_ownership_authorized"] is False
        assert decision["prior_owner_or_bridge_inheritance_authorized"] is False
        assert decision[
            "site_permission_privacy_direct_object_provider_mapping_lifecycle_concurrency_correctness_authorized"
        ] is False
        assert decision[
            "runtime_database_build_browser_test_benchmark_ease_release_pass_completion_authorized"
        ] is False

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

    route_file = REPO / next(iter(candidates.values()))["route_source"]["route_file"]
    assert sha256_file(route_file) == "cf6eed8437206aaf05feb541d031ce406382e13153a31bb831ef66b29994f1aa"
    assert git("hash-object", str(route_file)) == "3823dded458ab0f2bf20fe9b5de992acb414a5eb"
    route_text = route_file.read_text(encoding="utf-8")
    for candidate in candidates.values():
        assert candidate["route_source"]["statement_excerpt"] in route_text

    assert overlay["combined_counts"] == EXPECTED_COUNTS
    assert overlay["queue_accounting"] == EXPECTED_QUEUE
    assert overlay["identity"] == EXPECTED_IDENTITY
    assert len(overlay["identity"]) == 40
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
    assert 3929 == 660 + 3269
    assert 660 == 303 + 357
    assert 3218 == 303 + 12 + 5 + 0 + 2898
    assert 711 == 357 + 9 + 0 + 0 + 345
    assert 256 == 62 + 242 - 48 == 234 + 22
    assert 507 == 114 + 393
    assert 114 == 92 + 10 + 5 + 0 + 7
    assert 415 == 393 + 10 + 5 + 0 + 7

    packet = cohort["source_review_packet"]
    preserved_packet = overlay["source_packet_expansion_preservation"]
    assert packet["source_review_packet_sha256"] == SOURCE_PACKET_SHA256
    assert packet["required_source_file_count"] == 35
    assert packet["material_dependency_method_slice_count"] == 13
    assert packet["source_review_complete"] is False
    assert packet["source_packet_completeness_claimed"] is False
    assert packet["material_dependency_semantics_complete"] is False
    assert preserved_packet["total_disclosed_expansion_entries"] == 6
    assert preserved_packet["widened_existing_packet_files"] == 2
    assert preserved_packet["newly_followed_files"] == 4
    assert preserved_packet["expanded_file_records"] == review["source_packet_expansion"]["expanded_files"]
    assert canonical_json_sha256(preserved_packet["expanded_file_records"]) == EXPECTED_IDENTITY[
        "source_packet_expansions_sha256"
    ]
    for expansion in preserved_packet["expanded_file_records"]:
        source_path = REPO / expansion["path"]
        assert sha256_file(source_path) == expansion["sha256"]
        assert git("hash-object", str(source_path)) == expansion["blob_id"]
        assert expansion["expansion_changes_original_packet_bytes"] is False
        assert expansion["expansion_authorizes_correctness_credit"] is False

    findings = overlay["assurance_findings_preservation"]
    assert findings["candidate_findings"] == 15
    assert findings["shared_findings"] == 7
    assert findings["total_findings"] == len(findings["findings"]) == 22
    finding_ids = [row["finding_id"] for row in findings["findings"]]
    assert len(finding_ids) == len(set(finding_ids))
    assert canonical_json_sha256(findings["findings"]) == EXPECTED_IDENTITY["assurance_findings_sha256"]
    assert canonical_list_sha256(finding_ids) == EXPECTED_IDENTITY["assurance_finding_id_list_sha256"]
    assert findings["final_finding_credit_authorized"] is False
    assert findings["correctness_or_downstream_credit_authorized"] is False

    assert overlay["projection_reconciliation"] == {
        "run133r_projection_credit_awarded": False,
        "run134_current_static_overlay_credit_applied": True,
        "current_source_owner_records": 660,
        "current_route_owner_records": 303,
        "current_page_owner_records": 357,
        "current_static_controller_action_bridges": 91,
        "current_reviewed_queue_surface_rows": 114,
        "current_pending_unreviewed_queue_surface_rows": 393,
        "correctness_or_downstream_credit_authorized": False,
        "rule": "RUN133R authorized a projection only; RUN134 applies exactly those six route owners and bridges with no correctness credit.",
    }
    assert overlay["page_context_boundary"]["new_page_owner_records"] == 0
    assert overlay["page_context_boundary"]["page_ownership_inherited"] is False
    assert overlay["page_context_boundary"]["page_ownership_reassigned"] is False
    assert overlay["noninheritance_boundary"]["already_reviewed_index_route_record_id"] == "RUN077-ROUTE-0592"
    assert overlay["noninheritance_boundary"]["excluded_backend_only_sync_route_record_id"] == "RUN077-ROUTE-0595"
    assert overlay["noninheritance_boundary"]["excluded_backend_only_sync_selected"] is False
    assert overlay["noninheritance_boundary"]["next_queue_id"] == "RUN090-ROUTE-0078"
    assert overlay["noninheritance_boundary"]["next_boundary_selected_or_credited"] is False
    assert overlay["reviewer_lineage"]["partition_reviews"] == review["partition_reviews"]
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

    return {
        "schema_version": "run-134r-independent-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21-v1",
        "run_id": "RUN-134R-INDEPENDENT-REVIEWED-OUTCOME-NEUTRAL-FINANCE-ACCOUNTING-INTEGRATION-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-21",
        "status": "GO_THREE_PART_POST_COMMIT_REVIEW_COMPLETE_6_OWNER_6_BRIDGES_BOUNDED_STATIC_ONLY",
        "reviewed_on": "2026-08-26",
        "decision": {
            "verdict": "GO",
            "independent_reviews": 3,
            "mechanical_explicit_checks_reported": 159,
            "mechanical_generator_assertion_evaluations_reported": 93391,
            "semantic_logical_assertions_reported": 794,
            "semantic_source_loci_reported": 74,
            "semantic_source_files_reported": 28,
            "mechanical_discrepancies": 0,
            "semantic_or_preservation_discrepancies": 0,
            "arithmetic_identity_or_denominator_discrepancies": 0,
            "byte_provenance_or_credit_discrepancies": 0,
            "route_owner_records_verified": 6,
            "controller_action_bridges_verified": 6,
            "page_owner_records_verified": 0,
            "source_packet_expansion_records_verified": 6,
            "assurance_findings_verified": 22,
            "published_identity_fields_verified": 40,
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
            "concealment, privacy, provider or mapping behavior, lifecycle, concurrency, durability, or release readiness."
        ),
        "review_methods": [
            "Partition A independently checked exact committed files, worktree bytes, Git objects, deterministic replay, and conservation arithmetic.",
            "Partition B independently traced all six routes, controller actions, feature identity, page and sibling noninheritance, and Site/privacy/correctness boundaries.",
            "Partition C independently checked lineage, packet expansions, findings, all 40 identities, matrix zero-credit, and every downstream zero-credit field.",
            "The orchestrator replayed the committed producer byte-for-byte at its checkpoint and materialized this receipt from the three read-only returns.",
        ],
        "reviewers": [
            {
                "review_id": "RUN134R-MECHANICAL-REPLAY-ACCOUNTING",
                "reviewer_task_path": "/root/run134r_mechanical_review",
                "verdict": "GO",
                "discrepancies": 0,
                "explicit_checks": 159,
                "generator_assertion_evaluations": 93391,
                "generator_static_assertion_sites": 128,
                "verified_scope": [
                    "exact RUN134 commit, tree, two-file diff, blobs, hashes, and clean application trees",
                    "21 of 21 pinned inputs and a byte-identical deterministic producer replay from the RUN133R checkpoint",
                    "six owner and bridge hashes, 40 identity values, and 16 independent accounting checks",
                ],
                "audit_artifact_writes": False,
            },
            {
                "review_id": "RUN134R-SEMANTIC-NONINHERITANCE",
                "reviewer_task_path": "/root/run134r_semantic_review",
                "verdict": "GO_BOUNDED_RUN134_STATIC_OVERLAY_ONLY",
                "discrepancies": 0,
                "logical_assertions": 794,
                "source_loci": 74,
                "source_files": 28,
                "verified_scope": [
                    "six selected accounting-integration routes and their exact controller methods",
                    "bounded CAP-FIN-ACCOUNTING-INTEGRATION-CONFIGURATION ownership without page inheritance",
                    "preserved index route, Mapping page, backend-only sync exclusion, next queue boundary, and zero correctness credit",
                ],
                "audit_artifact_writes": False,
            },
            {
                "review_id": "RUN134R-PROVENANCE-IDENTITY-CREDIT",
                "reviewer_task_path": "/root/run134r_provenance_review",
                "verdict": "GO",
                "discrepancies": 0,
                "verified_scope": [
                    "RUN133 cohort, three partition reviews, synthesis, RUN134 generator and receipt lineage",
                    "35-file and 13-slice packet, six disclosed expansions, 22 findings, and 40 published identities",
                    "exactly two bounded static credit keys true with matrix, correctness, runtime, browser, tests, benchmark, release, and completion false",
                ],
                "audit_artifact_writes": False,
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
            "complete_route_page_feature_crosswalk": False,
            "matrix_mutation": False,
            "canonical_object_ownership_correctness": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "privacy_correctness": False,
            "direct_object_correctness": False,
            "provider_and_mapping_correctness": False,
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
            "Exactly six accounting-integration route owners and six controller-action bridges remain authorized "
            "as bounded static ownership only. Gate 4 and the comprehensive audit remain open."
        ),
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-independent-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-review-wave-21.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/raw-run-134r-independent-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json",
        ],
    }


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
