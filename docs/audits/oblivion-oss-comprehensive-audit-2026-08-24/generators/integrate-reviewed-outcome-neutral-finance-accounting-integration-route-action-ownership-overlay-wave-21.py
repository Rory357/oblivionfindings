#!/usr/bin/env python3
"""Integrate the six independently reviewed RUN-133 accounting route owners.

Only store, update, testConnection, destroy, mapping, and updateMapping route
records and their exact controller-action bridges are added. Existing page,
prior index, and backend-only sync context remains non-inheritable. The source
assurance findings and six packet expansions are preserved without granting
correctness or downstream credit.
"""

from __future__ import annotations

import hashlib
import json
import subprocess
from collections import Counter
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_PATH = (
    AUDIT_DIR
    / "evidence/source/current-run-134-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json"
)
GENERATOR_RELATIVE_PATH = Path(__file__).relative_to(REPO).as_posix()
OUTPUT_RELATIVE_PATH = OUTPUT_PATH.relative_to(REPO).as_posix()

CHECKPOINT_COMMIT = "3a763f742711279b0fc9b1e3a5f3a76b5f480dee"
CHECKPOINT_TREE = "cf59494737503c0c692e265b91f26021371d0e05"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
TESTS_TREE = "fef0122b31fdccbe2f9f805f7515666c74e2880a"
COHORT_GENERATOR_SHA256 = "476966a02322f59f385fb59dc9a55a3774e868e512cb58d5f0606698cbfd08af"
REVIEW_MATERIALIZER_SHA256 = "f878b16d485ff802d9ca5fd51bbd82628d37efed3151eaadcc72ed777ad5783d"
SOURCE_PACKET_SHA256 = "d99da8f946f350820b2ed9484180dde61dd180016dd2fece8f472b8d7a0171d3"
FEATURE_ID = "CAP-FIN-ACCOUNTING-INTEGRATION-CONFIGURATION"

INPUT_PATHS = {
    "matrix": AUDIT_DIR / "03-feature-to-benchmark-matrix.csv",
    "baseline": AUDIT_DIR / "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json",
    "run092_overlay": AUDIT_DIR / "evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json",
    "run098_overlay": AUDIT_DIR / "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json",
    "run102_overlay": AUDIT_DIR / "evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json",
    "run106_overlay": AUDIT_DIR / "evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json",
    "run110_overlay": AUDIT_DIR / "evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json",
    "run114_overlay": AUDIT_DIR / "evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json",
    "run118_overlay": AUDIT_DIR / "evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json",
    "run122_overlay": AUDIT_DIR / "evidence/source/current-run-122-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json",
    "run126_overlay": AUDIT_DIR / "evidence/source/current-run-126-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json",
    "run130_overlay": AUDIT_DIR / "evidence/source/current-run-130-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json",
    "run130_review": AUDIT_DIR / "evidence/source/raw-run-130r-independent-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json",
    "run091_cohort": AUDIT_DIR / "evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json",
    "run113_cohort": AUDIT_DIR / "evidence/source/root-run-113-outcome-neutral-name-only-route-action-cohort-wave-16.json",
    "run121_cohort": AUDIT_DIR / "evidence/source/root-run-121-outcome-neutral-finance-chart-route-action-cohort-wave-18.json",
    "run129_cohort": AUDIT_DIR / "evidence/source/root-run-129-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.json",
    "queue": AUDIT_DIR / "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json",
    "cohort": AUDIT_DIR / "evidence/source/root-run-133-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.json",
    "review": AUDIT_DIR / "evidence/source/raw-run-133r-independent-outcome-neutral-finance-accounting-integration-route-action-review-wave-21.json",
    "run131_reporting": AUDIT_DIR / "evidence/source/current-run-131-reviewed-finance-fx-revaluation-route-action-reporting-wave-20.json",
}

EXPECTED_INPUT_SHA256 = {
    "matrix": "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390",
    "baseline": "bfec52a9bed7176045457e8920bdc7b65e1c1d5c5eeb28a266f49c12acf69bcf",
    "run092_overlay": "5390e55d9e47d1845afa8b3848bdab7687747cbb62c946dd4d66d3c435e8de0b",
    "run098_overlay": "7f76c9cfeae906a64c92013fbf9a12cf180d39a315bfa13e778b6332365cdd2a",
    "run102_overlay": "cf68900351832c790ec53b4996daaf640c604c498f902c85dec681113bf492dd",
    "run106_overlay": "9c1600d2365c3527e185f313b7cb1586705f39cefb15d5d212dc708b45b630ea",
    "run110_overlay": "9375fb374b589c5068334795fa03d80a7ba1fd35695808f99c9e2591e886efca",
    "run114_overlay": "cbeb46be682ca0c9ca54012e2c27cf548b54e9cbb75b050fcd61691ca43aaef2",
    "run118_overlay": "33dd2f8ffa2b35c6651a6bf18923872e70ba5c2f91fce8b7222666bb8a91fc8b",
    "run122_overlay": "d7aee21e7c4230b44707a22b7fa93478a84e9a5b4775ecd25aaffede764855ca",
    "run126_overlay": "15ab65b479daa7e7c3f2f3fbd979a13ead87dfbedf31c163a27b5eb809b12f10",
    "run130_overlay": "f32b3d997a9e7dd932e041f5acf30dea02ee5b62fee3b0901cfbe5cc59f2ed0a",
    "run130_review": "4f7e5d74ce3711ce5ff00ac2a499ddde125115b1537a0de2e17375792f3d8590",
    "run091_cohort": "7b1fea57104e9f4bfea7ee450ef535ac57e7fa69bc978212b4eafb0fe1ed0cae",
    "run113_cohort": "0849ca5c10e31a4f3e5133a521a570d60b004e723fb17a7f2e812e485f345461",
    "run121_cohort": "cfe0e3635e5e86bf8e7e2f65d2094743738bfa5edc36e361ecf5eb14986f316e",
    "run129_cohort": "6d1efad203c368986ada06746f4314382b2dee4d214b25799dc531c02608180c",
    "queue": "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    "cohort": "58d87fa101e4e1b51d232baf80e1a2e3ef75dad89f37dc0dcd615c2f16e29ce4",
    "review": "7b56e738132dad35a0273b764d7f5e401219d6d52394306b41d2afac3a821420",
    "run131_reporting": "191d428161b0f96758bf4ca32d968d87cd9efb1e0a4e9fdd26741f8952063099",
}

OVERLAY_NAMES = (
    "run092_overlay",
    "run098_overlay",
    "run102_overlay",
    "run106_overlay",
    "run110_overlay",
    "run114_overlay",
    "run118_overlay",
    "run122_overlay",
    "run126_overlay",
    "run130_overlay",
)

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
}

EXPECTED_FINAL_IDENTITY = {
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


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def canonical_json_sha256(value: Any) -> str:
    encoded = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(encoded.encode("utf-8")).hexdigest()


def canonical_list_sha256(values: list[str] | set[str]) -> str:
    return hashlib.sha256("\n".join(sorted(values)).encode("utf-8")).hexdigest()


def load_json(path: Path) -> dict[str, Any]:
    def reject_duplicate_keys(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        value: dict[str, Any] = {}
        for key, item in pairs:
            assert key not in value, (path, key)
            value[key] = item
        return value

    value = json.loads(
        path.read_text(encoding="utf-8"), object_pairs_hook=reject_duplicate_keys
    )
    assert isinstance(value, dict), path
    return value


def git(*args: str) -> str:
    result = subprocess.run(
        ["git", *args],
        cwd=REPO,
        check=True,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    return result.stdout.strip()


def assert_row_digest(row: dict[str, Any], digest_key: str) -> None:
    body = {key: value for key, value in row.items() if key != digest_key}
    assert row[digest_key] == canonical_json_sha256(body)


def index_unique(rows: list[dict[str, Any]], key: str) -> dict[str, dict[str, Any]]:
    indexed = {row[key]: row for row in rows}
    assert len(indexed) == len(rows)
    return indexed


def canonical_queue_key(surface: str, source_record_id: str) -> str:
    if surface == "ROUTE_SOURCE_RECORD":
        return f"route|{source_record_id}"
    assert surface == "PAGE_ROOT_SOURCE_RECORD", surface
    return f"page|{source_record_id}"


def assert_workspace_and_inputs(data: dict[str, dict[str, Any]]) -> None:
    assert len(INPUT_PATHS) == len(EXPECTED_INPUT_SHA256) == 21
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git("rev-parse", "HEAD^{tree}") == CHECKPOINT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "HEAD:app") == APP_TREE
    assert git("rev-parse", "HEAD:routes") == ROUTES_TREE
    assert git("rev-parse", "HEAD:resources/js") == RESOURCES_JS_TREE
    assert git("rev-parse", "HEAD:resources/js/pages") == RESOURCES_JS_PAGES_TREE
    assert git("rev-parse", "HEAD:tests") == TESTS_TREE
    status_lines = set(git("status", "--porcelain").splitlines())
    assert status_lines == {
        f"?? {GENERATOR_RELATIVE_PATH}",
        f"?? {OUTPUT_RELATIVE_PATH}",
    }, status_lines
    for name, path in INPUT_PATHS.items():
        assert path.is_file(), path
        assert sha256_file(path) == EXPECTED_INPUT_SHA256[name], name
    cohort = data["cohort"]
    review = data["review"]
    assert sha256_file(AUDIT_DIR / cohort["pins"]["generator"]) == COHORT_GENERATOR_SHA256
    assert sha256_file(AUDIT_DIR / review["pins"]["materializer"]) == REVIEW_MATERIALIZER_SHA256


def build_overlay_source_records(
    candidates: list[dict[str, Any]], decisions: dict[str, dict[str, Any]]
) -> list[dict[str, Any]]:
    records: list[dict[str, Any]] = []
    for candidate in candidates:
        decision = decisions[candidate["candidate_id"]]
        assert decision["outcome"] == "OWNER_ROUTE_ACTION"
        assert decision["route_ownership_authorized"] is True
        source = candidate["route_source"]
        feature = candidate["feature_identity_projection"]
        suffix = candidate["candidate_id"].rsplit("-", 1)[-1]
        row: dict[str, Any] = {
            "overlay_mapping_id": f"RUN134-ROUTE-{suffix}",
            "candidate_id": candidate["candidate_id"],
            "candidate_record_sha256": candidate["candidate_record_sha256"],
            "decision_record_sha256": decision["decision_record_sha256"],
            "surface": "ROUTE_SOURCE_RECORD",
            "source_record_id": source["route_record_id"],
            "source_record_key": f"route|{source['route_record_id']}|{candidate['candidate_feature_id']}",
            "feature_id": candidate["candidate_feature_id"],
            "feature_class": feature["feature_class"],
            "module": feature["module"],
            "user_job": feature["user_job"],
            "source": source,
            "review_outcome": "OWNER_ROUTE_ACTION",
            "review_rationale": decision["rationale"],
            "static_source_feature_ownership_credit": True,
            "credit_boundary": {
                "page_ownership": False,
                "frontend_caller_ownership": False,
                "framework_route_reachability": False,
                "navigation": False,
                "site_authorization_correctness": False,
                "permission_correctness": False,
                "privacy_correctness": False,
                "direct_object_correctness": False,
                "provider_and_mapping_correctness": False,
                "lifecycle_correctness": False,
                "concurrency_correctness": False,
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
        }
        row["overlay_row_sha256"] = canonical_json_sha256(row)
        records.append(row)
    return sorted(records, key=lambda row: row["overlay_mapping_id"])


def build_action_bridges(
    candidates: list[dict[str, Any]], decisions: dict[str, dict[str, Any]]
) -> list[dict[str, Any]]:
    bridges: list[dict[str, Any]] = []
    for candidate in candidates:
        decision = decisions[candidate["candidate_id"]]
        assert decision["controller_action_bridge_authorized"] is True
        action = candidate["controller_action"]
        primary = action["primary_method_slice"]
        suffix = candidate["candidate_id"].rsplit("-", 1)[-1]
        bridge: dict[str, Any] = {
            "bridge_id": f"RUN134-BRIDGE-{suffix}",
            "candidate_id": candidate["candidate_id"],
            "candidate_record_sha256": candidate["candidate_record_sha256"],
            "decision_record_sha256": decision["decision_record_sha256"],
            "feature_id": candidate["candidate_feature_id"],
            "route_record_id": candidate["route_source"]["route_record_id"],
            "controller_fqcn": action["controller_fqcn"],
            "controller_file": primary["source_file"],
            "controller_file_sha256": primary["source_file_sha256"],
            "controller_file_blob_id": primary["source_file_blob_id"],
            "method": primary["method"],
            "definition_anchor": primary["definition_anchor"],
            "method_review_slice_sha256": primary["review_slice"]["text_sha256"],
            "review_outcome": "OWNER_ROUTE_ACTION",
            "static_controller_action_bridge_credit": True,
            "page_ownership_credit": False,
            "correctness_credit": False,
            "runtime_credit": False,
            "application_browser_credit": False,
            "executed_test_credit": False,
            "completion_credit": False,
        }
        bridge["bridge_row_sha256"] = canonical_json_sha256(bridge)
        bridges.append(bridge)
    return sorted(bridges, key=lambda row: row["bridge_id"])


def build() -> dict[str, Any]:
    data = {
        name: load_json(path)
        for name, path in INPUT_PATHS.items()
        if name != "matrix"
    }
    assert_workspace_and_inputs(data)
    baseline = data["baseline"]
    cohort = data["cohort"]
    review = data["review"]
    reporting = data["run131_reporting"]
    run130_review = data["run130_review"]

    assert cohort["run_id"] == "RUN-133-OUTCOME-NEUTRAL-FINANCE-ACCOUNTING-INTEGRATION-ROUTE-ACTION-COHORT-WAVE-21"
    assert review["run_id"] == "RUN-133R-INDEPENDENT-OUTCOME-NEUTRAL-FINANCE-ACCOUNTING-INTEGRATION-ROUTE-ACTION-REVIEW-WAVE-21"
    assert review["decision"]["verdict"] == "GO_6_EXPLICIT_OWNER_ROUTE_ACTION"
    assert review["decision"]["mechanical_discrepancies"] == 0
    assert review["decision"]["semantic_outcome_discrepancies"] == 0
    assert review["decision"]["bounded_overlay_authorized"] is True
    assert review["decision"]["current_overlay_credit_awarded"] is False
    assert review["decision"]["correctness_or_downstream_credit_authorized"] is False
    assert review["synthesis_review"]["bounded_overlay_integration_authorized"] is True
    assert review["synthesis_review"]["outcome_variables"] == {"O": 6, "S": 0, "A": 0, "D": 0, "E": 0}
    assert review["verified_counts"]["assurance_findings"] == 22
    assert review["verified_counts"]["source_packet_expansion_files"] == 6
    assert review["verified_counts"]["source_packet_expansion_existing_files"] == 2
    assert review["verified_counts"]["source_packet_expansion_new_files"] == 4
    assert review["verified_counts"]["partition_reviews"] == 3
    assert review["verified_counts"]["cohort_synthesis_reviews"] == 1
    assert cohort["source_review_packet"]["source_review_packet_sha256"] == SOURCE_PACKET_SHA256
    assert cohort["source_review_packet"]["required_source_file_count"] == 35
    assert cohort["source_review_packet"]["material_dependency_method_slice_count"] == 13
    assert cohort["source_review_packet"]["source_review_complete"] is False
    assert cohort["source_review_packet"]["source_packet_completeness_claimed"] is False
    assert cohort["source_review_packet"]["material_dependency_semantics_complete"] is False
    assert cohort["page_context_boundary"]["selected_controller_literal_inertia_page_callsites"] == 1
    assert cohort["page_context_boundary"]["existing_distinct_caller_or_render_pages"] == 2
    assert cohort["page_context_boundary"]["selected_frontend_literal_caller_contexts"] == 5
    assert cohort["page_context_boundary"]["selected_routes_without_literal_caller_in_frozen_pages"] == 1
    assert cohort["page_context_boundary"]["new_page_owner_records"] == 0
    assert cohort["page_context_boundary"]["page_ownership_inherited"] is False
    assert cohort["page_context_boundary"]["page_ownership_reassigned"] is False
    assert cohort["excluded_backend_only_sync_context"]["route_record_id"] == "RUN077-ROUTE-0595"
    assert cohort["excluded_backend_only_sync_context"]["ownership_credit_awarded"] is False
    assert cohort["next_queue_boundary"]["queue_index_zero_based"] == 77
    assert cohort["next_queue_boundary"]["route_record_id"] == "RUN077-ROUTE-0634"
    assert [row["reviewer_task_path"] for row in review["partition_reviews"]] == [
        "/root/run125_accounts_show_edit",
        "/root/run125_accounts_create",
        "/root/run129_final_seal",
    ]
    assert all(row["reviewer_wrote_files"] is False for row in review["partition_reviews"])
    assert review["synthesis_review"]["reviewer_task_path"] == "/root/run133r_synthesis"
    assert review["synthesis_review"]["reviewer_wrote_files"] is False
    assert run130_review["decision"]["verdict"] == "GO"
    assert run130_review["decision"]["mechanical_discrepancies"] == 0
    assert run130_review["decision"]["semantic_or_preservation_discrepancies"] == 0
    assert run130_review["decision"]["arithmetic_identity_or_denominator_discrepancies"] == 0
    assert run130_review["decision"]["byte_provenance_or_credit_discrepancies"] == 0
    assert run130_review["decision"]["reporting_materialization_authorized"] is True
    assert run130_review["decision"]["correctness_or_downstream_credit_authorized"] is False
    assert run130_review["pins"]["producer"] == INPUT_PATHS["run130_overlay"].relative_to(AUDIT_DIR).as_posix()
    assert run130_review["pins"]["producer_sha256"] == EXPECTED_INPUT_SHA256["run130_overlay"]
    assert data["run129_cohort"]["run_id"] == "RUN-129-OUTCOME-NEUTRAL-FINANCE-FX-REVALUATION-ROUTE-ACTION-COHORT-WAVE-20"

    candidates = list(cohort["records"])
    decisions = index_unique(review["action_decisions"], "candidate_id")
    assert len(candidates) == len(decisions) == 6
    assert {row["candidate_id"] for row in candidates} == set(decisions)
    for candidate in candidates:
        assert_row_digest(candidate, "candidate_record_sha256")
        decision = decisions[candidate["candidate_id"]]
        assert_row_digest(decision, "decision_record_sha256")
        assert decision["candidate_record_sha256"] == candidate["candidate_record_sha256"]
        assert decision["queue_id"] == candidate["queue_id"]
        assert decision["queue_canonical_key"] == candidate["queue_canonical_key"]
        assert decision["route_record_id"] == candidate["route_source"]["route_record_id"]
        assert decision["action_key"] == candidate["action_key"]
        assert decision["candidate_feature_id"] == candidate["candidate_feature_id"] == FEATURE_ID
        assert decision["outcome"] == "OWNER_ROUTE_ACTION"
        assert decision["page_ownership_authorized"] is False
        assert decision["prior_owner_or_bridge_inheritance_authorized"] is False

    overlay_records = build_overlay_source_records(candidates, decisions)
    action_bridges = build_action_bridges(candidates, decisions)
    assert len(overlay_records) == len(action_bridges) == 6
    assert [row["overlay_mapping_id"] for row in overlay_records] == [
        f"RUN134-ROUTE-{suffix:02d}" for suffix in range(1, 7)
    ]
    assert [row["bridge_id"] for row in action_bridges] == [
        f"RUN134-BRIDGE-{suffix:02d}" for suffix in range(1, 7)
    ]
    assert [row["overlay_row_sha256"] for row in overlay_records] == [
        "84466ff474839e1499ab26bdad9997a271b8420883314115f4283f382962f729",
        "029a87bc0e22b988fec04ec1e96448f73db4293204c43cf75b47f8e3b37abb6a",
        "758e1794ae63a90d61c349006ca51a211863d8b9c475628947dd5e7d53282e2b",
        "a65f90cef8559480a51c994b7ff2f78c4252fc494af5ce06f0f9d3cb656abb20",
        "7fbb9f2eb3f8423d7ed308be7fc6cfe7b1daa1a303a806ede47e8517662d0363",
        "8a36d9304cd7cf6fb2cb65db97ecbd79f9c3dca505c2c45420d5a063d05809b3",
    ]
    assert [row["bridge_row_sha256"] for row in action_bridges] == [
        "59f451aa0ff21722d291f476682d7e1e750a6eeb436cf8ef795e49fa5c4be918",
        "09a085bcdfebe5d82e7932a638a1404151cbbc1fdd4a88204263c416b463102a",
        "ea61fbd1450e5b853eaf8f7946810a97ffe8a8599bb2b0f15407bd52d2f11980",
        "700407428eb356765a37d0958fe9f538cc0fa444003f4aab4146e03d36ebac61",
        "8c2e3d3a935fcf53b518e2c6f47b5e977ebef7effa72cda397540385a93f8480",
        "039ba71fab85618acfe03cef3913ffc17361611837187ff47aa2953555167320",
    ]

    prior_records: list[dict[str, Any]] = list(baseline["records"])
    prior_bridges: list[dict[str, Any]] = []
    for name in OVERLAY_NAMES:
        overlay = data[name]
        prior_records += list(overlay["overlay_source_records"])
        for field in ("static_controller_action_bridges", "new_static_controller_action_bridges"):
            prior_bridges += list(overlay.get(field, []))
    prior_keys = {row["source_record_key"] for row in prior_records}
    prior_ids = {row["source_record_id"] for row in prior_records}
    prior_route_ids = {
        row["source_record_id"] for row in prior_records if row["surface"] == "ROUTE_SOURCE_RECORD"
    }
    prior_bridge_keys = {
        (row["controller_file"], row["method"], row["feature_id"]) for row in prior_bridges
    }
    assert len(prior_records) == len(prior_keys) == len(prior_ids) == 654
    assert len(prior_route_ids) == 297
    assert len(prior_bridges) == len(prior_bridge_keys) == 85

    new_keys = {row["source_record_key"] for row in overlay_records}
    new_ids = {row["source_record_id"] for row in overlay_records}
    new_bridge_keys = {
        (row["controller_file"], row["method"], row["feature_id"]) for row in action_bridges
    }
    assert len(new_keys) == len(new_ids) == len(new_bridge_keys) == 6
    assert not (prior_keys & new_keys)
    assert not (prior_ids & new_ids)
    assert not (prior_bridge_keys & new_bridge_keys)
    combined_records = prior_records + overlay_records
    combined_keys = {row["source_record_key"] for row in combined_records}
    combined_ids = {row["source_record_id"] for row in combined_records}
    combined_bridge_keys = prior_bridge_keys | new_bridge_keys
    assert len(combined_records) == len(combined_keys) == len(combined_ids) == 660
    assert len(combined_bridge_keys) == 91

    combined_feature_ids = {row["feature_id"] for row in combined_records}
    route_feature_ids = {
        row["feature_id"] for row in combined_records if row["surface"] == "ROUTE_SOURCE_RECORD"
    }
    page_feature_ids = {
        row["feature_id"] for row in combined_records if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"
    }
    overlap_feature_ids = route_feature_ids & page_feature_ids
    feature_class_by_id: dict[str, str] = {}
    for row in combined_records:
        feature_class_by_id.setdefault(row["feature_id"], row["feature_class"])
        assert feature_class_by_id[row["feature_id"]] == row["feature_class"]
    assert len(combined_feature_ids) == 256
    assert Counter(feature_class_by_id.values()) == {"H": 234, "D": 22}
    assert len(route_feature_ids) == 62
    assert len(page_feature_ids) == 242
    assert len(overlap_feature_ids) == 48
    prior_feature_ids = {row["feature_id"] for row in prior_records}
    prior_route_feature_ids = {
        row["feature_id"] for row in prior_records if row["surface"] == "ROUTE_SOURCE_RECORD"
    }
    accepted_feature_ids = {row["candidate_feature_id"] for row in candidates}
    assert accepted_feature_ids == {FEATURE_ID}
    assert accepted_feature_ids <= prior_feature_ids
    assert accepted_feature_ids <= prior_route_feature_ids
    assert accepted_feature_ids - prior_feature_ids == set()
    assert accepted_feature_ids - prior_route_feature_ids == set()

    queue_rows = data["queue"]["records"]
    queue_by_key = index_unique(queue_rows, "canonical_key")
    new_reviewed_queue_keys = {row["queue_canonical_key"] for row in candidates}
    assert len(queue_rows) == len(queue_by_key) == 507
    assert len(new_reviewed_queue_keys) == 6
    for key in new_reviewed_queue_keys:
        assert queue_by_key[key]["review_state"]["status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
    assert reporting["counts"]["reviewed_queue_surface_rows"] == 108
    assert reporting["counts"]["pending_unreviewed_queue_surface_rows"] == 399
    assert reporting["counts"]["source_owner_records"] == 654
    assert reporting["counts"]["static_controller_action_bridges"] == 85

    run091_queue_keys: set[str] = set()
    for chain in data["run091_cohort"]["records"]:
        for surface, source, id_field in (
            ("ROUTE_SOURCE_RECORD", chain["route_source"], "route_record_id"),
            ("PAGE_ROOT_SOURCE_RECORD", chain["page_source"], "page_record_id"),
        ):
            key = canonical_queue_key(surface, source[id_field])
            if key in queue_by_key:
                run091_queue_keys.add(key)
    run098_queue_keys = {
        canonical_queue_key(row["surface"], row["source_record_id"])
        for row in data["run098_overlay"]["overlay_source_records"]
    }
    run102_queue_keys = {
        canonical_queue_key(row["surface"], row["source_record_id"])
        for row in data["run102_overlay"]["overlay_source_records"]
    } | {
        f"route|{row['route_record_id']}"
        for row in data["run102_overlay"]["reviewed_non_owner_outcomes"]
    }
    run110_queue_keys = {
        row["queue_canonical_key"]
        for row in data["run110_overlay"]["new_reviewed_queue_outcomes"]
    }
    run113_queue_keys = {
        row["queue_canonical_key"] for row in data["run113_cohort"]["records"]
    }
    run121_queue_keys = {
        row["queue_canonical_key"] for row in data["run121_cohort"]["records"]
    }
    run129_queue_keys = {
        row["queue_canonical_key"] for row in data["run129_cohort"]["records"]
    }
    assert [
        len(run091_queue_keys),
        len(run098_queue_keys),
        len(run102_queue_keys),
        len(run110_queue_keys),
        len(run113_queue_keys),
        len(run121_queue_keys),
        len(run129_queue_keys),
    ] == [12, 23, 24, 1, 24, 22, 2]
    prior_reviewed_queue_keys = (
        run091_queue_keys
        | run098_queue_keys
        | run102_queue_keys
        | run110_queue_keys
        | run113_queue_keys
        | run121_queue_keys
        | run129_queue_keys
    )
    assert len(prior_reviewed_queue_keys) == 108
    assert prior_reviewed_queue_keys <= set(queue_by_key)
    assert not (prior_reviewed_queue_keys & new_reviewed_queue_keys)
    combined_reviewed_queue_keys = prior_reviewed_queue_keys | new_reviewed_queue_keys
    assert len(combined_reviewed_queue_keys) == 114

    combined_route_records = [
        row for row in combined_records if row["surface"] == "ROUTE_SOURCE_RECORD"
    ]
    combined_page_records = [
        row for row in combined_records if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"
    ]
    assert len(combined_route_records) == 303
    assert len(combined_page_records) == 357

    computed_identity = {
        "owner_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in candidates]),
        "owner_route_record_id_list_sha256": canonical_list_sha256(new_ids),
        "owner_source_record_key_list_sha256": canonical_list_sha256(new_keys),
        "owner_action_key_list_sha256": canonical_list_sha256([row["action_key"] for row in candidates]),
        "owner_bridge_key_list_sha256": canonical_list_sha256(["|".join(key) for key in new_bridge_keys]),
        "owner_candidate_record_sha256_list_sha256": canonical_list_sha256([row["candidate_record_sha256"] for row in candidates]),
        "owner_decision_record_sha256_list_sha256": canonical_list_sha256([decisions[row["candidate_id"]]["decision_record_sha256"] for row in candidates]),
        "owner_queue_id_list_sha256": canonical_list_sha256([row["queue_id"] for row in candidates]),
        "owner_queue_key_list_sha256": canonical_list_sha256(new_reviewed_queue_keys),
        "new_union_feature_id_list_sha256": canonical_list_sha256(set()),
        "new_route_feature_id_list_sha256": canonical_list_sha256(set()),
        "new_page_feature_id_list_sha256": canonical_list_sha256(set()),
        "prior_source_record_key_list_sha256": canonical_list_sha256(prior_keys),
        "prior_source_record_id_list_sha256": canonical_list_sha256(prior_ids),
        "combined_source_record_key_list_sha256": canonical_list_sha256(combined_keys),
        "combined_source_record_id_list_sha256": canonical_list_sha256(combined_ids),
        "combined_feature_id_list_sha256": canonical_list_sha256(combined_feature_ids),
        "combined_route_feature_id_list_sha256": canonical_list_sha256(route_feature_ids),
        "combined_page_feature_id_list_sha256": canonical_list_sha256(page_feature_ids),
        "combined_route_page_overlap_feature_id_list_sha256": canonical_list_sha256(overlap_feature_ids),
        "prior_bridge_key_list_sha256": canonical_list_sha256(["|".join(key) for key in prior_bridge_keys]),
        "combined_bridge_key_list_sha256": canonical_list_sha256(["|".join(key) for key in combined_bridge_keys]),
        "new_reviewed_queue_key_list_sha256": canonical_list_sha256(new_reviewed_queue_keys),
        "prior_reviewed_queue_key_list_sha256": canonical_list_sha256(prior_reviewed_queue_keys),
        "combined_reviewed_queue_key_list_sha256": canonical_list_sha256(combined_reviewed_queue_keys),
        "combined_route_record_id_list_sha256": canonical_list_sha256(
            [row["source_record_id"] for row in combined_route_records]
        ),
        "combined_route_source_record_key_list_sha256": canonical_list_sha256(
            [row["source_record_key"] for row in combined_route_records]
        ),
        "combined_page_record_id_list_sha256": canonical_list_sha256(
            [row["source_record_id"] for row in combined_page_records]
        ),
        "combined_page_source_record_key_list_sha256": canonical_list_sha256(
            [row["source_record_key"] for row in combined_page_records]
        ),
    }
    if EXPECTED_IDENTITY:
        assert computed_identity == EXPECTED_IDENTITY

    assurance_findings = (
        [
            finding
            for candidate in candidates
            for finding in decisions[candidate["candidate_id"]]["assurance_findings"]
        ]
        + review["shared_assurance_findings"]
    )
    assurance_finding_ids = [row["finding_id"] for row in assurance_findings]
    assert len(assurance_findings) == len(set(assurance_finding_ids)) == 22
    identity = {
        **computed_identity,
        "new_overlay_source_records_sha256": canonical_json_sha256(overlay_records),
        "new_overlay_row_sha256_list_sha256": canonical_list_sha256([row["overlay_row_sha256"] for row in overlay_records]),
        "new_action_bridges_sha256": canonical_json_sha256(action_bridges),
        "new_action_bridge_row_sha256_list_sha256": canonical_list_sha256([row["bridge_row_sha256"] for row in action_bridges]),
        "reviewed_decision_record_sha256_list_sha256": review["verified_global_identity"]["decision_record_sha256_list_sha256"],
        "reviewed_decisions_sha256": review["verified_global_identity"]["reviewed_decisions_sha256"],
        "synthesis_record_sha256": review["verified_global_identity"]["synthesis_record_sha256"],
        "source_packet_expansions_sha256": review["verified_global_identity"]["source_packet_expansions_sha256"],
        "assurance_findings_sha256": canonical_json_sha256(
            assurance_findings
        ),
        "assurance_finding_id_list_sha256": canonical_list_sha256(assurance_finding_ids),
        "partition_reviews_sha256": review["verified_global_identity"]["partition_reviews_sha256"],
    }
    if EXPECTED_FINAL_IDENTITY:
        assert identity == {**EXPECTED_IDENTITY, **EXPECTED_FINAL_IDENTITY}

    combined_counts = {
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
        "bounded_static_source_ownership_percent": str(
            (Decimal(660) * Decimal(100) / Decimal(3929)).quantize(
                Decimal("0.000001"), rounding=ROUND_HALF_UP
            )
        ),
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
    assert combined_counts["bounded_static_source_ownership_percent"] == "16.798167"
    projection = review["reviewed_projection_if_integrated"]
    assert projection["projection_credit_awarded"] is False
    for key, value in combined_counts.items():
        if key in projection:
            assert projection[key] == value, key
        else:
            assert reporting["counts"][key] == value, key

    queue_accounting = {
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
    for key in (
        "direct_exact_queue_records",
        "reviewed_queue_surface_rows",
        "owner_queue_surface_rows",
        "shared_queue_surface_rows",
        "alias_queue_surface_rows",
        "dead_queue_surface_rows",
        "evidence_gap_queue_surface_rows",
        "pending_unreviewed_queue_surface_rows",
        "queue_surfaces_without_ownership",
    ):
        assert projection[key] == queue_accounting[key], key

    payload = {
        "schema_version": "run-134-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21-v1",
        "run_id": "RUN-134-REVIEWED-OUTCOME-NEUTRAL-FINANCE-ACCOUNTING-INTEGRATION-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-21",
        "status": "SIX_REVIEWED_ACCOUNTING_INTEGRATION_ROUTE_ACTION_OWNERS_AND_BRIDGES_INTEGRATED_STATIC_ONLY",
        "generated_on": "2026-08-26",
        "pins": {
            "checkpoint_commit": CHECKPOINT_COMMIT,
            "checkpoint_tree": CHECKPOINT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "app_tree": APP_TREE,
            "routes_tree": ROUTES_TREE,
            "resources_js_tree": RESOURCES_JS_TREE,
            "resources_js_pages_tree": RESOURCES_JS_PAGES_TREE,
            "tests_tree": TESTS_TREE,
            "matrix_sha256": EXPECTED_INPUT_SHA256["matrix"],
            "generator": Path(__file__).relative_to(AUDIT_DIR).as_posix(),
            "generator_sha256": sha256_file(Path(__file__)),
            "cohort_generator": cohort["pins"]["generator"],
            "cohort_generator_sha256": COHORT_GENERATOR_SHA256,
            "review_materializer": review["pins"]["materializer"],
            "review_materializer_sha256": REVIEW_MATERIALIZER_SHA256,
            "cohort_source_review_packet_sha256": SOURCE_PACKET_SHA256,
            "inputs": {
                INPUT_PATHS[name].relative_to(AUDIT_DIR).as_posix(): digest
                for name, digest in EXPECTED_INPUT_SHA256.items()
            },
        },
        "architecture_rule": (
            "Oblivion Findings is one operating organisation across multiple Sites. Legacy organisation context "
            "is not tenancy or access authority, and provider tenant_id is external provider context. Static "
            "Finance route/action ownership establishes neither approved-Site reach, exact permission, canonical "
            "concealment, privacy, provider behavior, mapping integrity, lifecycle, concurrency, audit durability, "
            "nor release readiness."
        ),
        "baseline": {
            "reporting_run_id": reporting["run_id"],
            "source_owner_records": 654,
            "route_owner_records": 297,
            "page_owner_records": 357,
            "static_controller_action_bridges": 85,
            "reviewed_queue_surface_rows": 108,
            "pending_unreviewed_queue_surface_rows": 399,
            "reporting_sha256": EXPECTED_INPUT_SHA256["run131_reporting"],
        },
        "reviewed_overlay": {
            "producer_run_id": cohort["run_id"],
            "review_run_id": review["run_id"],
            "reviewed_route_actions": 6,
            "owner_route_actions": 6,
            "shared_relations": 0,
            "alias_or_redirect": 0,
            "dead_or_noncanonical": 0,
            "evidence_gaps": 0,
            "accepted_source_owner_records": 6,
            "accepted_route_owner_records": 6,
            "accepted_page_owner_records": 0,
            "accepted_controller_action_bridges": 6,
            "accepted_distinct_feature_ids": 1,
            "new_distinct_feature_ids": 0,
            "new_route_feature_ids": [],
            "new_page_feature_ids": [],
            "reviewed_non_owner_records_preserved": 0,
            "current_static_overlay_credit_applied": True,
            "page_ownership_inherited": False,
            "prior_owner_or_bridge_inheritance_used": False,
            "excluded_backend_only_sync_ownership_used": False,
            "cohort_sha256": EXPECTED_INPUT_SHA256["cohort"],
            "review_sha256": EXPECTED_INPUT_SHA256["review"],
        },
        "source_packet_expansion_preservation": {
            "total_disclosed_expansion_entries": 6,
            "widened_existing_packet_files": 2,
            "newly_followed_files": 4,
            "original_required_source_files": 35,
            "original_material_dependency_method_slices": 13,
            "cohort_source_review_packet_sha256": SOURCE_PACKET_SHA256,
            "source_packet_expansions_sha256": review["verified_global_identity"]["source_packet_expansions_sha256"],
            "original_source_review_complete": False,
            "original_source_packet_completeness_claimed": False,
            "original_material_dependency_semantics_complete": False,
            "original_packet_retroactively_described_as_complete": False,
            "correctness_credit_authorized": False,
            "expanded_file_records": review["source_packet_expansion"]["expanded_files"],
        },
        "assurance_findings_preservation": {
            "candidate_findings": 15,
            "shared_findings": 7,
            "total_findings": 22,
            "assurance_findings_sha256": identity["assurance_findings_sha256"],
            "assurance_finding_id_list_sha256": identity["assurance_finding_id_list_sha256"],
            "final_finding_credit_authorized": False,
            "correctness_or_downstream_credit_authorized": False,
            "findings": assurance_findings,
        },
        "reviewer_lineage": {
            "partition_reviews": review["partition_reviews"],
            "partition_reviews_sha256": review["verified_global_identity"]["partition_reviews_sha256"],
            "synthesis_review": review["synthesis_review"],
            "synthesis_record_sha256": review["verified_global_identity"]["synthesis_record_sha256"],
            "reviewed_decisions_sha256": review["verified_global_identity"]["reviewed_decisions_sha256"],
            "all_reviewers_wrote_files": False,
        },
        "combined_counts": combined_counts,
        "queue_accounting": queue_accounting,
        "page_context_boundary": {
            "literal_inertia_page_callsites": 1,
            "existing_caller_or_render_pages": 2,
            "selected_frontend_literal_caller_contexts": 5,
            "selected_routes_without_literal_caller_in_frozen_pages": 1,
            "existing_index_page_record_id": "PAGE-ROOT-679D3E7F4B5402CB",
            "existing_index_page_feature_id": FEATURE_ID,
            "existing_mapping_page_record_id": "PAGE-ROOT-BA2E4950746EAF10",
            "existing_mapping_page_feature_id": "CAP-FIN-XERO-ACCOUNTING-SYNC",
            "new_page_owner_records": 0,
            "page_ownership_inherited": False,
            "page_ownership_reassigned": False,
            "rule": "Index and Mapping remain existing page-owner context; no caller or render transfers page ownership.",
        },
        "noninheritance_boundary": {
            "already_reviewed_index_route_record_id": "RUN077-ROUTE-0592",
            "already_reviewed_index_owner_row_id": "RUN092-ROUTE-03",
            "already_reviewed_index_bridge_id": "RUN092-BRIDGE-03",
            "prior_index_owner_or_bridge_inheritance_used": False,
            "excluded_backend_only_sync_route_record_id": "RUN077-ROUTE-0595",
            "excluded_backend_only_sync_selected": False,
            "excluded_backend_only_sync_ownership_or_bridge_awarded": False,
            "next_queue_index_zero_based": 77,
            "next_queue_id": "RUN090-ROUTE-0078",
            "next_route_record_id": "RUN077-ROUTE-0634",
            "next_boundary_selected_or_credited": False,
        },
        "overlay_source_records": overlay_records,
        "new_static_controller_action_bridges": action_bridges,
        "reviewed_non_owner_outcomes": [],
        "identity": identity,
        "identity_discovery": computed_identity,
        "outcome_conservation": {
            "reviewed_outcomes_equation": "6 = 6 owner + 0 shared + 0 alias + 0 dead + 0 evidence gap",
            "bounded_source_equation": "3929 = 660 owner + 3269 non-owner residual",
            "owner_surface_equation": "660 = 303 route + 357 page",
            "feature_union_equation": "256 = 62 route + 242 page - 48 overlap",
            "route_universe_equation": "3218 = 303 owner + 12 shared + 5 alias + 0 dead + 2898 residual",
            "evidence_gap_is_tagged_within_2898_route_residual": True,
            "page_universe_equation": "711 = 357 owner + 9 shared + 0 alias + 0 dead + 345 residual",
            "evidence_gap_is_tagged_within_345_page_residual": True,
            "queue_equation": "507 = 114 reviewed + 393 pending",
            "reviewed_queue_equation": "114 = 92 owner + 10 shared + 5 alias + 0 dead + 7 evidence gap",
            "queue_without_ownership_equation": "415 = 393 pending + 10 shared + 5 alias + 0 dead + 7 evidence gap",
        },
        "projection_reconciliation": {
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
        },
        "denominator_boundary": {
            "run_077_bounded_static_records": 3929,
            "framework_expanded_route_page_denominator": None,
            "page_denominator_711_roots_vs_1058_page_tree_files_resolved": False,
            "complete_route_page_feature_crosswalk": False,
            "gate_4_complete": False,
        },
        "credit_boundary": {
            "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_6_RECORDS": True,
            "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_6_ACTIONS": True,
            "static_page_feature_ownership": False,
            "frontend_caller_ownership": False,
            "prior_owner_or_bridge_inheritance": False,
            "excluded_backend_only_sync_ownership": False,
            "matrix_mutation": False,
            "wholesale_507_queue_ownership": False,
            "complete_route_page_feature_crosswalk": False,
            "framework_route_reachability": False,
            "navigation": False,
            "canonical_object_ownership_correctness": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "privacy_correctness": False,
            "direct_object_concealment_correctness": False,
            "provider_credential_and_behavior_correctness": False,
            "mapping_and_external_posting_correctness": False,
            "audit_privacy_and_durability_correctness": False,
            "lifecycle_correctness": False,
            "concurrency_and_idempotency_correctness": False,
            "event_and_downstream_durability_correctness": False,
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
            "application_source_changed": False,
            "matrix_changed": False,
            "runtime_or_external_system_changed": False,
            "audit_artifacts_only": True,
            "whole_repository_status_scope_asserted": True,
            "only_expected_untracked_run134_artifacts_present": True,
            "expected_status_paths": [GENERATOR_RELATIVE_PATH, OUTPUT_RELATIVE_PATH],
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/integrate-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/current-run-134-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json",
        ],
    }
    return payload


def main() -> None:
    payload = build()
    encoded = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    if not OUTPUT_PATH.exists() or OUTPUT_PATH.read_bytes() != encoded:
        OUTPUT_PATH.write_bytes(encoded)
    print(
        json.dumps(
            {
                "status": payload["status"],
                "output": OUTPUT_PATH.relative_to(REPO).as_posix(),
                "sha256": sha256_file(OUTPUT_PATH),
                "source_owner_records": payload["combined_counts"]["source_owner_records"],
                "route_owner_records": payload["combined_counts"]["route_owner_records"],
                "bridges": payload["combined_counts"]["static_controller_action_bridges"],
                "audit_complete": payload["audit_completion_test_met"],
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
