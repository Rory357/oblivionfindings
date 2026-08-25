#!/usr/bin/env python3
"""Materialize the independent post-commit review of the RUN-130 FX overlay.

The receipt records three fresh read-only reviews. It verifies the exact two
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
from collections import Counter
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_PATH = (
    AUDIT_DIR
    / "evidence/source/raw-run-130r-independent-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json"
)
PRODUCER_PATH = (
    AUDIT_DIR
    / "evidence/source/current-run-130-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json"
)
GENERATOR_PATH = (
    AUDIT_DIR
    / "generators/integrate-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.py"
)
COHORT_PATH = (
    AUDIT_DIR
    / "evidence/source/root-run-129-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.json"
)
COHORT_REVIEW_PATH = (
    AUDIT_DIR
    / "evidence/source/raw-run-129r-independent-outcome-neutral-finance-fx-revaluation-route-action-review-wave-20.json"
)
MATRIX_PATH = AUDIT_DIR / "03-feature-to-benchmark-matrix.csv"

AUDIT_HEAD = "bf4b79591714cc1ccdffc9552ef81d97f257c74f"
AUDIT_TREE = "402a2c8e7d7d8fd3bec4c14bf33fdac3c0d60072"
PRODUCER_CHECKPOINT_HEAD = "f85a9a84353b7ac9c80ca1b7b79f9cec3ebc620e"
PRODUCER_CHECKPOINT_TREE = "92e9971dbdd46a6dc0ccaeec583b5e08472ae1c6"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
TESTS_TREE = "fef0122b31fdccbe2f9f805f7515666c74e2880a"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
GENERATOR_SHA256 = "3bdde28921e35b6a8ec45610af9a52cb55c0d37bdb2de179a2fa9eeecfe976e1"
PRODUCER_SHA256 = "f32b3d997a9e7dd932e041f5acf30dea02ee5b62fee3b0901cfbe5cc59f2ed0a"
COHORT_SHA256 = "6d1efad203c368986ada06746f4314382b2dee4d214b25799dc531c02608180c"
COHORT_REVIEW_SHA256 = "9eb86243c72c7aa0c0f1cf6d250b7ad4184c2e0602c8217b7f3c0e70dcded67a"
GENERATOR_BLOB_ID = "7b1b6ba335941521e762e64ed936950b788c7863"
PRODUCER_BLOB_ID = "7f5f56b3ae7aeb324ea6b220ca9a7b2760e67bd8"
SOURCE_PACKET_SHA256 = "73269f26602fe2213e9715b9183b9765e4151c1d9fc3c37d934a4bfb2e99a940"

EXPECTED_COUNTS = {
    "source_owner_records": 654,
    "route_owner_records": 297,
    "page_owner_records": 357,
    "distinct_feature_ids": 256,
    "distinct_H_feature_ids": 234,
    "distinct_D_feature_ids": 22,
    "route_distinct_feature_ids": 62,
    "page_distinct_feature_ids": 242,
    "route_page_feature_overlap": 48,
    "static_controller_action_bridges": 85,
    "bounded_static_source_denominator": 3929,
    "bounded_static_source_ownership_percent": "16.645457",
    "bounded_static_source_residual_records": 3275,
    "residual_explicit_unmapped_routes": 2904,
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
    "reviewed_queue_surface_rows": 108,
    "owner_queue_surface_rows": 86,
    "shared_queue_surface_rows": 10,
    "alias_queue_surface_rows": 5,
    "dead_queue_surface_rows": 0,
    "evidence_gap_queue_surface_rows": 7,
    "pending_unreviewed_queue_surface_rows": 399,
    "queue_surfaces_without_ownership": 421,
    "new_reviewed_route_surface_rows": 2,
    "new_owner_route_surface_rows": 2,
    "new_shared_route_surface_rows": 0,
    "new_alias_route_surface_rows": 0,
    "new_dead_route_surface_rows": 0,
    "new_evidence_gap_route_surface_rows": 0,
    "wholesale_queue_ownership_authorized": False,
}

EXPECTED_IDENTITY = {
    "owner_candidate_id_list_sha256": "c2fe18aef9cb177cec2c78564fc64b70bab1601a4562b66a10d15d1d55b70958",
    "owner_route_record_id_list_sha256": "ca22dfda855373d9be5ffdcf36584964ce318e37ff18e7b5b031d48ffe986ea5",
    "owner_source_record_key_list_sha256": "4d8b055337e1ba1060fcb3630f2b0daff62b6f639895751ff33b93f36b51d074",
    "owner_action_key_list_sha256": "a5ff47c4e9bd0d71de49f56599f3f9391704814134069e34e41dda55e7f4050e",
    "owner_bridge_key_list_sha256": "cef2abe41187dd897437e8489edac96e621aed77022b7be133cdfa3701a07800",
    "owner_candidate_record_sha256_list_sha256": "10a8acbd85bae1936551a22115108171095b02d6654cd398ff65e0d2b33fff7e",
    "owner_decision_record_sha256_list_sha256": "d68268cf91aa1f4974a276b561567103f7e9bc730852cf2c7501db4929d7cc13",
    "owner_queue_id_list_sha256": "31f3762cce7511c4b08d49589fdff1d49bd42bd46335eb283d6f407e3853e09e",
    "owner_queue_key_list_sha256": "94dcc2c12148d200f511571028c46f98370b1984036533031425c160a9aa4961",
    "new_union_feature_id_list_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
    "new_route_feature_id_list_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
    "new_page_feature_id_list_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
    "prior_source_record_key_list_sha256": "02c9fdc8b6603db3b281739e0c4688f93a16adf0fe4f7a389d61dc04600e51b9",
    "prior_source_record_id_list_sha256": "a290263d5eb1a4bafad5592a04f6bedd167a81dcc917dd906646700f9ad325c7",
    "combined_source_record_key_list_sha256": "9de3288dc0b07f034d099fd3f6723b6ed7620fa9d2e8e003fc2bcd558b3e699e",
    "combined_source_record_id_list_sha256": "abdf6beef2cadc840860b0e43ce6acc3e15da51d85713d3fde98b6f8e37234b3",
    "combined_feature_id_list_sha256": "874e55340e6abb600c50a64a147b81b9b5467587ee77f9e45eace4eb85ac55ca",
    "combined_route_feature_id_list_sha256": "89c69d9c74178944f705a60d004f01e4e5922d06a929b0dfba2342aafd6a3b7a",
    "combined_page_feature_id_list_sha256": "2fa6a11a5c66a016366db84ce89931db906b26cc911dfa88490cb6561ff261e8",
    "combined_route_page_overlap_feature_id_list_sha256": "00b1e6141c19311c68d7f8bcd154d971f2099fa768993cca4845baadd02aa217",
    "prior_bridge_key_list_sha256": "c8bc97e2afe9f849a3bcf81f271e0e43066266a0bd0c65770846ccc36cb88f61",
    "combined_bridge_key_list_sha256": "8130f9c6b87e89b8b079edf4ee9d9563a5d226fbbf858290416845d236c87d1f",
    "new_reviewed_queue_key_list_sha256": "94dcc2c12148d200f511571028c46f98370b1984036533031425c160a9aa4961",
    "prior_reviewed_queue_key_list_sha256": "c1e9746f10ea6ea866b9cb78619f71f5e37533984bda904ea112b0a66a195a9c",
    "combined_reviewed_queue_key_list_sha256": "113656569e79bc60019a2c82071fd7e7e3df19fab88b62e88d85d306e00d4de4",
    "combined_route_record_id_list_sha256": "f77fa8670712c925ee67dc937622c88001d7feb6c9e38c658573352a8792dd73",
    "combined_route_source_record_key_list_sha256": "f195902390d7e4febff184256f5ceb03b2897ef5d4d7e48ef6ece28d616a2b11",
    "combined_page_record_id_list_sha256": "abef0c7e0e1ac503e06eb563aee6b19718f1192d7716ea214ee5d4859dce162e",
    "combined_page_source_record_key_list_sha256": "652acf45451422bfd45af9ea1f12bffffef6f1e386511ec0dbda6d520e0bdd9b",
    "new_overlay_source_records_sha256": "78c4390d9f68fb2b3e36b86cde5d86e9ff54c85ea34764af06f2b5a8d57ab9a6",
    "new_overlay_row_sha256_list_sha256": "981d523b664020a414e753fc186432d7f9eb028ba1fe4284c6526ebc9e1bb91d",
    "new_action_bridges_sha256": "ec11dc28486c6f94b79e99efd0a7f578281f1a60a634118c5d60cb2e09b80d39",
    "new_action_bridge_row_sha256_list_sha256": "6c8eea94923402c384a787494c3cecad06f9b5ca3e28a6001ce2d8bbf3b7b097",
    "reviewed_decision_record_sha256_list_sha256": "d68268cf91aa1f4974a276b561567103f7e9bc730852cf2c7501db4929d7cc13",
    "reviewed_decisions_sha256": "1455ccabc2b0f7aa8810a0839e104b4bc2918f0688fb3dad774bec441311cc55",
    "synthesis_record_sha256": "d6587bcf49ef21d19469402e77231f3bd8f55e8ca9cdc93aa53659c966af5329",
    "source_packet_expansions_sha256": "ecd3919cb2a69c4a672a1f40a1172cb02ad8b4439ae72f9da84899ddb6bfb138",
    "assurance_findings_sha256": "70a075b661a4d4beaa6d656899b9d97974651d1177b746ddc1093b91550b4383",
    "assurance_finding_id_list_sha256": "dec1f2fac563d0ee9b6a9e0d489fdffe569ee0bede0570c5c5f512f850223af2",
    "partition_reviews_sha256": "ccdd83025b2fdd55e49c4db86669861e51a6bef3d73323b3702275687afb3051",
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
EXPECTED_TRUE_CREDIT = {
    "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_2_RECORDS",
    "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_2_ACTIONS",
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


def build() -> dict[str, Any]:
    assert_workspace()
    assert sha256_file(GENERATOR_PATH) == GENERATOR_SHA256
    assert sha256_file(PRODUCER_PATH) == PRODUCER_SHA256
    assert sha256_file(COHORT_PATH) == COHORT_SHA256
    assert sha256_file(COHORT_REVIEW_PATH) == COHORT_REVIEW_SHA256
    assert sha256_file(MATRIX_PATH) == MATRIX_SHA256
    producer_dict_literals = assert_no_duplicate_literal_dict_keys(GENERATOR_PATH)

    overlay = load_json(PRODUCER_PATH)
    cohort = load_json(COHORT_PATH)
    review = load_json(COHORT_REVIEW_PATH)
    assert overlay["pins"]["generator_sha256"] == GENERATOR_SHA256
    assert overlay["pins"]["cohort_generator_sha256"] == "2e23ca7736f0e21460f130a6fafc89a68f228b6f8a52137a2209795d500b0982"
    assert overlay["pins"]["review_materializer_sha256"] == "c77ac164b6869bca82d929df623a19dd40f0c72fa593d7fb805c72c9ece8d60b"
    assert overlay["pins"]["cohort_source_review_packet_sha256"] == SOURCE_PACKET_SHA256
    assert overlay["pins"]["matrix_sha256"] == MATRIX_SHA256
    assert overlay["pins"]["checkpoint_commit"] == PRODUCER_CHECKPOINT_HEAD
    assert overlay["pins"]["checkpoint_tree"] == PRODUCER_CHECKPOINT_TREE
    for relative, expected in overlay["pins"]["inputs"].items():
        assert sha256_file(AUDIT_DIR / relative) == expected, relative

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

    candidates = {row["candidate_id"]: row for row in cohort["records"]}
    decisions = {row["candidate_id"]: row for row in review["action_decisions"]}
    owner_rows = overlay["overlay_source_records"]
    bridges = overlay["new_static_controller_action_bridges"]
    assert len(candidates) == len(decisions) == len(owner_rows) == len(bridges) == 2
    assert set(candidates) == set(decisions) == {row["candidate_id"] for row in owner_rows} == {
        row["candidate_id"] for row in bridges
    }
    assert {row["source_record_id"] for row in owner_rows} == {"RUN077-ROUTE-0590", "RUN077-ROUTE-0591"}
    assert {row["method"] for row in bridges} == {"store", "post"}
    assert [row["overlay_row_sha256"] for row in owner_rows] == [
        "5134d3cf042c8d6240e57d1854f07c0ce1b8ec6470002da2370fb4321a94a796",
        "bc5f7e38ccbcc6ff7dbe8f8291770fc532c7b1a26a5218de36ac67499e35c647",
    ]
    assert [row["bridge_row_sha256"] for row in bridges] == [
        "4ffb8e00ed872cb58f007fb170e1891f34de2127cbe8d7b94b191c7a7ccc2b17",
        "8184223dfa7e5d73fa59c3bf765581a825d53349dd564ec91ce519618461a256",
    ]

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
        assert row["feature_id"] == decision["candidate_feature_id"] == "CAP-FIN-FX-REVALUATION"
        assert row["review_rationale"] == decision["rationale"]
        assert row["review_outcome"] == decision["outcome"] == "OWNER_ROUTE_ACTION"
        assert row["static_source_feature_ownership_credit"] is True
        assert len(row["credit_boundary"]) == 22
        assert not any(row["credit_boundary"].values())
        assert decision["page_ownership_authorized"] is False
        assert decision["prior_owner_or_bridge_inheritance_authorized"] is False
        assert decision["site_permission_privacy_direct_object_rate_ledger_lifecycle_concurrency_correctness_authorized"] is False
        assert decision["runtime_database_build_browser_test_benchmark_ease_release_pass_completion_authorized"] is False

    bridge_by_candidate = {row["candidate_id"]: row for row in bridges}
    for candidate_id, bridge in bridge_by_candidate.items():
        assert_row_digest(bridge, "bridge_row_sha256")
        candidate = candidates[candidate_id]
        decision = decisions[candidate_id]
        action = candidate["controller_action"]
        primary = action["primary_method_slice"]
        assert bridge["candidate_record_sha256"] == candidate["candidate_record_sha256"]
        assert bridge["decision_record_sha256"] == decision["decision_record_sha256"]
        assert bridge["route_record_id"] == candidate["route_source"]["route_record_id"]
        assert bridge["controller_fqcn"] == action["controller_fqcn"]
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
    assert 3929 == 654 + 3275
    assert 654 == 297 + 357
    assert 3218 == 297 + 12 + 5 + 0 + 2904
    assert 711 == 357 + 9 + 0 + 0 + 345
    assert 256 == 62 + 242 - 48 == 234 + 22
    assert 507 == 108 + 399
    assert 108 == 86 + 10 + 5 + 0 + 7
    assert 421 == 399 + 10 + 5 + 0 + 7

    packet = cohort["source_review_packet"]
    preserved_packet = overlay["source_packet_expansion_preservation"]
    assert packet["source_review_packet_sha256"] == SOURCE_PACKET_SHA256
    assert packet["required_source_file_count"] == 36
    assert packet["material_dependency_method_slice_count"] == 13
    assert packet["source_review_complete"] is False
    assert packet["source_packet_completeness_claimed"] is False
    assert packet["material_dependency_semantics_complete"] is False
    assert preserved_packet["total_disclosed_expansion_entries"] == 16
    assert preserved_packet["widened_existing_packet_files"] == 14
    assert preserved_packet["newly_followed_files"] == 2
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
    assert findings["candidate_findings"] == 12
    assert findings["shared_findings"] == 3
    assert findings["total_findings"] == len(findings["findings"]) == 15
    finding_ids = [row["finding_id"] for row in findings["findings"]]
    assert len(finding_ids) == len(set(finding_ids))
    assert canonical_json_sha256(findings["findings"]) == EXPECTED_IDENTITY["assurance_findings_sha256"]
    assert canonical_list_sha256(finding_ids) == EXPECTED_IDENTITY["assurance_finding_id_list_sha256"]
    assert findings["final_finding_credit_authorized"] is False
    assert findings["correctness_or_downstream_credit_authorized"] is False

    assert overlay["projection_reconciliation"] == {
        "run129r_projection_credit_awarded": False,
        "run130_current_static_overlay_credit_applied": True,
        "current_source_owner_records": 654,
        "current_route_owner_records": 297,
        "current_page_owner_records": 357,
        "current_static_controller_action_bridges": 85,
        "current_reviewed_queue_surface_rows": 108,
        "current_pending_unreviewed_queue_surface_rows": 399,
        "correctness_or_downstream_credit_authorized": False,
        "rule": "RUN129R authorized a projection only; RUN130 applies exactly that bounded static ownership and no correctness credit.",
    }
    assert overlay["denominator_boundary"]["run_077_bounded_static_records"] == 3929
    assert overlay["denominator_boundary"]["framework_expanded_route_page_denominator"] is None
    assert overlay["denominator_boundary"]["page_denominator_711_roots_vs_1058_page_tree_files_resolved"] is False
    assert overlay["denominator_boundary"]["complete_route_page_feature_crosswalk"] is False
    assert overlay["denominator_boundary"]["gate_4_complete"] is False
    assert {key for key, value in overlay["credit_boundary"].items() if value} == EXPECTED_TRUE_CREDIT
    assert overlay["audit_completion_test_met"] is False

    with MATRIX_PATH.open(newline="", encoding="utf-8-sig") as handle:
        matrix_rows = list(csv.DictReader(handle))
    assert len(matrix_rows) == 340
    assert sum(row.get("benchmark_mapping_credit", "").strip().lower() == "true" for row in matrix_rows) == 0

    return {
        "schema_version": "run-130r-independent-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20-v1",
        "run_id": "RUN-130R-INDEPENDENT-REVIEWED-OUTCOME-NEUTRAL-FINANCE-FX-REVALUATION-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-20",
        "status": "GO_THREE_PART_POST_COMMIT_REVIEW_COMPLETE_2_OWNER_2_BRIDGES_BOUNDED_STATIC_ONLY",
        "reviewed_on": "2026-08-26",
        "decision": {
            "verdict": "GO",
            "independent_reviews": 3,
            "logical_checks_reported_by_semantic_reviewer": 159,
            "mechanical_discrepancies": 0,
            "semantic_or_preservation_discrepancies": 0,
            "arithmetic_identity_or_denominator_discrepancies": 0,
            "byte_provenance_or_credit_discrepancies": 0,
            "route_owner_records_verified": 2,
            "controller_action_bridges_verified": 2,
            "page_owner_records_verified": 0,
            "source_packet_expansion_records_verified": 16,
            "assurance_findings_verified": 15,
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
            "inputs": overlay["pins"]["inputs"],
        },
        "architecture_rule": (
            "Oblivion Findings is one operating organisation across multiple Sites. Static route and action ownership "
            "does not prove approved-Site access, permission correctness, canonical ownership, direct-object "
            "concealment, privacy, rate provenance, ledger integrity, lifecycle, durability, or release readiness."
        ),
        "review_methods": [
            "Partition A traced each route, controller action, method slice, semantic job, row digest, bridge digest, and prior-universe collision boundary through pinned source.",
            "Partition B independently reconstructed 654 owners, 85 bridges, all queue and denominator equations, all 40 published identities, and the unresolved 711-root versus 1058-file crosswalk.",
            "Partition C verified exact committed bytes, Git provenance, reviewer lineage, all 16 source-packet expansions, all 15 findings, duplicate-free structures, and every zero-credit boundary.",
            "The orchestrator replayed the committed producer byte-for-byte at its checkpoint and materialized this receipt from the three read-only returns.",
        ],
        "reviewers": [
            {
                "review_id": "RUN130R-OWNER-BRIDGE-SEMANTICS",
                "reviewer_task_path": "/root/run125_accounts_create",
                "verdict": "GO_ZERO_OWNER_OR_BRIDGE_SEMANTIC_DISCREPANCIES",
                "discrepancies": 0,
                "logical_checks": 159,
                "verified_scope": [
                    "two exact RUN129 decisions, RUN090 queue identities, RUN077 route identities, and source statements",
                    "store and post controller methods, pinned slices, semantic job ownership, 22-field nested credit boundaries, and bridge hashes",
                    "652-plus-2 owner and 83-plus-2 bridge disjointness without sibling inheritance",
                    "zero Site, permission, privacy, direct-object, rate, ledger, lifecycle, runtime, test, or completion credit",
                ],
                "audit_artifact_writes": False,
            },
            {
                "review_id": "RUN130R-ACCOUNTING-IDENTITY-DENOMINATORS",
                "reviewer_task_path": "/root/run125_accounts_show_edit",
                "verdict": "GO_ZERO_ACCOUNTING_IDENTITY_OR_DENOMINATOR_DISCREPANCIES",
                "discrepancies": 0,
                "published_identity_fields_verified": 40,
                "verified_scope": [
                    "baseline and all overlays through 654 unique owners and 85 unique bridges",
                    "feature, route, page, overlap, queue, residual, percentage, and conservation equations",
                    "all 40 published identities and matrix zero-credit identity",
                    "structurally exact but semantically unresolved 711-root versus 1058-file page crosswalk",
                ],
                "audit_artifact_writes": False,
            },
            {
                "review_id": "RUN130R-BYTES-PROVENANCE-CREDIT",
                "reviewer_task_path": "/root/run129_final_seal",
                "verdict": "GO_ZERO_BYTE_PROVENANCE_OR_CREDIT_DISCREPANCIES",
                "discrepancies": 0,
                "verified_scope": [
                    "commit changes exactly the two RUN130 audit artifacts and committed bytes equal worktree bytes",
                    "RUN126R, RUN129, RUN129R, reviewer, decision, and synthesis lineage",
                    "36-file and 13-slice packet, 16 expansions, 15 findings, and duplicate-free structures",
                    "exactly two static credit keys true and all correctness, runtime, browser, test, benchmark, release, final, and completion fields false",
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
            "rate_and_snapshot_correctness": False,
            "ledger_integrity_correctness": False,
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
            "Exactly two FX revaluation route owners and two controller-action bridges remain authorized as "
            "bounded static ownership only. Gate 4 and the comprehensive audit remain open."
        ),
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-independent-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-review-wave-20.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/raw-run-130r-independent-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json",
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
