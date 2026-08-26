#!/usr/bin/env python3
"""Integrate the independently reviewed RUN-137 invoice-index route owner.

Exactly one static route source owner and its exact InvoiceController::index
bridge are added. The two existing invoice page owners, callers, sibling pages,
and all correctness, runtime, browser, test, benchmark, and completion classes
remain non-inheritable. Twelve disclosed source-packet expansions and nine
provisional assurance findings are preserved without correctness credit.
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
    / "evidence/source/current-run-138-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json"
)
GENERATOR_RELATIVE_PATH = Path(__file__).relative_to(REPO).as_posix()
OUTPUT_RELATIVE_PATH = OUTPUT_PATH.relative_to(REPO).as_posix()

CHECKPOINT_COMMIT = "97c1813915da6122dd6cc3485d3c5338797c34d1"
CHECKPOINT_TREE = "1ba2cecf1f091724cdaf45757f01029cc87d5e13"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
TESTS_TREE = "fef0122b31fdccbe2f9f805f7515666c74e2880a"
COHORT_GENERATOR_SHA256 = "93766689117c88173a08f8548a04d7e62f00eadf71fb7fefa302936e540c9bd9"
REVIEW_MATERIALIZER_SHA256 = "4a4eb33dd34832b2182bfe27bf13f90f3a30e7406b74552e82dda2f0c73b99c5"
SOURCE_PACKET_SHA256 = "d357634ecafca5373cc7141d4d604599431ac836ed2e7274bd5b508d2fadf81e"
FEATURE_ID = "CAP-FIN-BILLING-INVOICE-LIFECYCLE"

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
    "run134_overlay": AUDIT_DIR / "evidence/source/current-run-134-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json",
    "run134_review": AUDIT_DIR / "evidence/source/raw-run-134r-independent-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json",
    "run091_cohort": AUDIT_DIR / "evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json",
    "run113_cohort": AUDIT_DIR / "evidence/source/root-run-113-outcome-neutral-name-only-route-action-cohort-wave-16.json",
    "run121_cohort": AUDIT_DIR / "evidence/source/root-run-121-outcome-neutral-finance-chart-route-action-cohort-wave-18.json",
    "run129_cohort": AUDIT_DIR / "evidence/source/root-run-129-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.json",
    "run133_cohort": AUDIT_DIR / "evidence/source/root-run-133-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.json",
    "queue": AUDIT_DIR / "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json",
    "cohort": AUDIT_DIR / "evidence/source/root-run-137-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.json",
    "review": AUDIT_DIR / "evidence/source/raw-run-137r-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22.json",
    "run135_reporting": AUDIT_DIR / "evidence/source/current-run-135-reviewed-finance-accounting-integration-route-action-reporting-wave-21.json",
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
    "run134_overlay": "e82514d96ac01db1cba72e9a469b2bb9c15404d2c42ff124c816e38b086bb669",
    "run134_review": "da3107cdcbb4ab286c208f85d994676d00f933d4002a966fb89773f8ef0857d3",
    "run091_cohort": "7b1fea57104e9f4bfea7ee450ef535ac57e7fa69bc978212b4eafb0fe1ed0cae",
    "run113_cohort": "0849ca5c10e31a4f3e5133a521a570d60b004e723fb17a7f2e812e485f345461",
    "run121_cohort": "cfe0e3635e5e86bf8e7e2f65d2094743738bfa5edc36e361ecf5eb14986f316e",
    "run129_cohort": "6d1efad203c368986ada06746f4314382b2dee4d214b25799dc531c02608180c",
    "run133_cohort": "58d87fa101e4e1b51d232baf80e1a2e3ef75dad89f37dc0dcd615c2f16e29ce4",
    "queue": "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    "cohort": "e2a6a346365ada6013b82f4e29aa955ffcedf7f3b53ab88279c700407d3012bc",
    "review": "a3659294a8d2f9c203968a885da7b48f928d5341dbcb2b177eb85b40a058411f",
    "run135_reporting": "af70461527e7b22855b0a7917121112ca973fe4e88450b6b87ef0b5ae39d99da",
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
    "run134_overlay",
)

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
        encoding="utf-8",
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
    assert len(INPUT_PATHS) == len(EXPECTED_INPUT_SHA256) == 23
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


def build_overlay_source_record(
    candidate: dict[str, Any], decision: dict[str, Any]
) -> dict[str, Any]:
    assert decision["outcome"] == "OWNER_ROUTE_ACTION"
    assert decision["route_ownership_authorized"] is True
    source = candidate["route_source"]
    feature = candidate["feature_identity_projection"]
    row: dict[str, Any] = {
        "overlay_mapping_id": "RUN138-ROUTE-01",
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
            "canonical_object_ownership_correctness": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "privacy_correctness": False,
            "direct_object_correctness": False,
            "query_correctness": False,
            "projection_correctness": False,
            "response_minimization_correctness": False,
            "lifecycle_correctness": False,
            "concurrency_correctness": False,
            "runtime": False,
            "database": False,
            "build": False,
            "application_browser": False,
            "executed_tests": False,
            "benchmark": False,
            "ease": False,
            "release": False,
            "pass": False,
            "final_finding": False,
            "completion": False,
            "audit_complete": False,
        },
    }
    row["overlay_row_sha256"] = canonical_json_sha256(row)
    return row


def build_action_bridge(
    candidate: dict[str, Any], decision: dict[str, Any]
) -> dict[str, Any]:
    assert decision["controller_action_bridge_authorized"] is True
    action = candidate["controller_action"]
    primary = action["primary_method_slice"]
    bridge: dict[str, Any] = {
        "bridge_id": "RUN138-BRIDGE-01",
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
    return bridge


def build() -> dict[str, Any]:
    data = {name: load_json(path) for name, path in INPUT_PATHS.items() if path.suffix == ".json"}
    assert_workspace_and_inputs(data)
    baseline = data["baseline"]
    cohort = data["cohort"]
    review = data["review"]
    reporting = data["run135_reporting"]
    run134_review = data["run134_review"]

    assert cohort["run_id"] == "RUN-137-OUTCOME-NEUTRAL-FINANCE-INVOICE-INDEX-ROUTE-ACTION-COHORT-WAVE-22"
    assert cohort["status"] == "ONE_NAME_ONLY_FINANCE_INVOICE_INDEX_ROUTE_ACTION_CANDIDATE_PENDING_FRESH_REVIEW_ZERO_CREDIT"
    assert cohort["pins"]["checkpoint_commit"] == "9da5bbfa5a575f272cee2389ab5de5178e063c03"
    assert cohort["pins"]["checkpoint_tree"] == "4b84050bc4e960a17cf8321313677cfe53134c28"
    assert cohort["pins"]["generator_sha256"] == COHORT_GENERATOR_SHA256
    assert cohort["source_review_packet"]["source_review_packet_sha256"] == SOURCE_PACKET_SHA256
    assert cohort["source_review_packet"]["required_source_file_count"] == 33
    assert cohort["source_review_packet"]["material_dependency_method_slice_count"] == 10
    assert cohort["source_review_packet"]["source_review_complete"] is False
    assert cohort["source_review_packet"]["source_packet_completeness_claimed"] is False
    assert cohort["source_review_packet"]["material_dependency_semantics_complete"] is False
    assert cohort["source_review_packet"]["known_expansion_candidates_adjudicated"] is False
    required_source_files = cohort["source_review_packet"]["required_source_files"]
    assert len(required_source_files) == 33
    assert len({row["path"] for row in required_source_files}) == 33
    for row in required_source_files:
        source_path = REPO / row["path"]
        assert sha256_file(source_path) == row["sha256"]
        assert git("hash-object", str(source_path)) == row["blob_id"]
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{row['path']}") == row["application_commit_blob_id"]
        assert row["blob_id"] == row["application_commit_blob_id"]
    dependency_slices = cohort["source_review_packet"]["material_dependency_method_slices"]
    assert len(dependency_slices) == 10
    for row in dependency_slices:
        source_path = REPO / row["source_file"]
        assert sha256_file(source_path) == row["source_file_sha256"]
        assert git("hash-object", str(source_path)) == row["source_file_blob_id"]
    assert cohort["counts"]["candidate_route_actions"] == 1
    assert cohort["counts"]["candidate_page_records"] == 0
    assert cohort["page_and_caller_context_boundary"]["existing_page_owner_context_rows"] == 2
    assert cohort["page_and_caller_context_boundary"]["new_page_owner_records"] == 0
    assert cohort["page_and_caller_context_boundary"]["page_ownership_inherited"] is False
    assert cohort["next_queue_boundary"] == {
        "queue_index_zero_based": 78,
        "queue_id": "RUN090-ROUTE-0079",
        "route_record_id": "RUN077-ROUTE-0669",
        "candidate_feature_id": "CAP-FIN-SITE-PORTFOLIO-OVERVIEW",
        "selected_for_run137": False,
        "credit_awarded": False,
    }

    assert review["run_id"] == "RUN-137R-INDEPENDENT-OUTCOME-NEUTRAL-FINANCE-INVOICE-INDEX-ROUTE-ACTION-REVIEW-WAVE-22"
    assert review["status"] == "GO_TWO_INDEPENDENT_CANDIDATE_REVIEWS_AND_FRESH_SYNTHESIS_COMPLETE_ONE_BOUNDED_OWNER_ZERO_DOWNSTREAM_CREDIT"
    assert review["pins"]["checkpoint_commit"] == "18b841ea03c89d732fd4786618d1af3b6378211c"
    assert review["pins"]["checkpoint_tree"] == "51078a6ce8472644e032d934dea75cd5a718efda"
    assert review["pins"]["cohort_sha256"] == EXPECTED_INPUT_SHA256["cohort"]
    assert review["pins"]["materializer_sha256"] == REVIEW_MATERIALIZER_SHA256
    assert review["pins"]["cohort_source_review_packet_sha256"] == SOURCE_PACKET_SHA256
    assert review["decision"]["verdict"] == "GO_ACCEPT_1_OWNER_ROUTE_ACTION_FOR_BOUNDED_LATER_INTEGRATION"
    assert review["decision"]["independent_candidate_reviews"] == 2
    assert review["decision"]["cohort_synthesis_reviews"] == 1
    assert review["decision"]["mechanical_discrepancies"] == 0
    assert review["decision"]["semantic_outcome_discrepancies"] == 0
    assert review["decision"]["identity_or_key_discrepancies"] == 0
    assert review["decision"]["page_credit_discrepancies"] == 0
    assert review["decision"]["bounded_overlay_authorized"] is True
    assert review["decision"]["correctness_or_downstream_credit_authorized"] is False
    assert len(review["independent_candidate_reviews"]) == 2
    assert [row["reviewer_task_path"] for row in review["independent_candidate_reviews"]] == [
        "/root/run137_invoice_semantic_a",
        "/root/run137_invoice_semantic_b",
    ]
    assert all(row["reviewer_wrote_files"] is False for row in review["independent_candidate_reviews"])
    assert review["synthesis_review"]["reviewer_task_path"] == "/root/run137_review_synthesis"
    assert review["synthesis_review"]["reviewer_wrote_files"] is False
    assert review["synthesis_review"]["outcome_variables"] == {
        "O": 1,
        "S": 0,
        "A": 0,
        "D": 0,
        "E": 0,
    }
    assert review["synthesis_review"]["next_queue_boundary_changed"] is False
    assert review["synthesis_review"]["current_overlay_credit_awarded"] is False
    assert run134_review["decision"]["verdict"] == "GO"
    assert run134_review["decision"]["reporting_materialization_authorized"] is True
    assert run134_review["decision"]["correctness_or_downstream_credit_authorized"] is False

    candidates = list(cohort["records"])
    decisions = index_unique(review["action_decisions"], "candidate_id")
    assert len(candidates) == len(decisions) == 1
    candidate = candidates[0]
    decision = decisions[candidate["candidate_id"]]
    assert_row_digest(candidate, "candidate_record_sha256")
    assert_row_digest(decision, "decision_record_sha256")
    assert candidate["candidate_id"] == "RUN137-FINANCE-INVOICE-INDEX-ROUTE-ACTION-01"
    assert candidate["candidate_record_sha256"] == "ef62ffbe177d4ffb6474492d54a2d08e90ee342f4f05d5bd5094f0dc47c84d8d"
    assert decision["decision_record_sha256"] == "ebe22dd2ca9e3a0e53cabfab807c315f5dba2496a3b7c406deab6751aa5747fa"
    assert decision["candidate_record_sha256"] == candidate["candidate_record_sha256"]
    assert candidate["queue_index_zero_based"] == decision["queue_index_zero_based"] == 77
    assert candidate["queue_id"] == decision["queue_id"] == "RUN090-ROUTE-0078"
    assert candidate["queue_canonical_key"] == decision["queue_canonical_key"] == "route|RUN077-ROUTE-0634"
    assert candidate["route_source"]["route_record_id"] == decision["route_record_id"] == "RUN077-ROUTE-0634"
    assert candidate["route_source"]["literal_route_name"] == decision["literal_route_name"] == "invoices.index"
    assert candidate["candidate_feature_id"] == decision["candidate_feature_id"] == FEATURE_ID
    assert candidate["action_key"] == decision["action_key"] == (
        "RUN077-ROUTE-0634|app/Domain/Finance/Http/Controllers/InvoiceController.php:index|"
        "CAP-FIN-BILLING-INVOICE-LIFECYCLE"
    )
    assert decision["outcome"] == "OWNER_ROUTE_ACTION"
    assert decision["route_ownership_authorized"] is True
    assert decision["controller_action_bridge_authorized"] is True
    assert decision["page_ownership_authorized"] is False
    assert decision["prior_page_owner_context_inherited_or_recredited"] is False
    assert decision["current_overlay_credit_awarded"] is False

    overlay_record = build_overlay_source_record(candidate, decision)
    action_bridge = build_action_bridge(candidate, decision)
    overlay_records = [overlay_record]
    action_bridges = [action_bridge]
    assert overlay_record["overlay_mapping_id"] == "RUN138-ROUTE-01"
    assert action_bridge["bridge_id"] == "RUN138-BRIDGE-01"
    assert overlay_record["source_record_key"] == decision["owner_source_record_key"]
    assert [
        action_bridge["controller_file"],
        action_bridge["method"],
        action_bridge["feature_id"],
    ] == decision["bridge_key"]
    assert action_bridge["controller_file_sha256"] == "5ecb4b7e41641b2c709a24b20f1a9692dd583204f607d965f84444bedfb55db2"
    assert action_bridge["controller_file_blob_id"] == "c6da7ae16135c852a0e2d735dca23d7b2846032c"
    assert action_bridge["method_review_slice_sha256"] == "d6a65dac3d97628ab02a39c40e0d568f1219a94009175c2c39cec2d6156fd794"
    assert sha256_file(REPO / candidate["route_source"]["route_file"]) == candidate["route_source"]["route_file_sha256"]
    assert git("hash-object", str(REPO / candidate["route_source"]["route_file"])) == candidate["route_source"]["route_file_blob_id"]
    assert sha256_file(REPO / action_bridge["controller_file"]) == action_bridge["controller_file_sha256"]
    assert git("hash-object", str(REPO / action_bridge["controller_file"])) == action_bridge["controller_file_blob_id"]

    prior_records: list[dict[str, Any]] = list(baseline["records"])
    prior_bridges: list[dict[str, Any]] = []
    for name in OVERLAY_NAMES:
        overlay = data[name]
        prior_records += list(overlay["overlay_source_records"])
        for field in ("static_controller_action_bridges", "new_static_controller_action_bridges"):
            prior_bridges += list(overlay.get(field, []))
    prior_keys = {row["source_record_key"] for row in prior_records}
    prior_ids = {row["source_record_id"] for row in prior_records}
    prior_bridge_keys = {
        (row["controller_file"], row["method"], row["feature_id"])
        for row in prior_bridges
    }
    assert len(prior_records) == len(prior_keys) == len(prior_ids) == 660
    assert sum(row["surface"] == "ROUTE_SOURCE_RECORD" for row in prior_records) == 303
    assert sum(row["surface"] == "PAGE_ROOT_SOURCE_RECORD" for row in prior_records) == 357
    assert len(prior_bridges) == len(prior_bridge_keys) == 91

    new_keys = {overlay_record["source_record_key"]}
    new_ids = {overlay_record["source_record_id"]}
    new_bridge_keys = {
        (
            action_bridge["controller_file"],
            action_bridge["method"],
            action_bridge["feature_id"],
        )
    }
    assert not (prior_keys & new_keys)
    assert not (prior_ids & new_ids)
    assert not (prior_bridge_keys & new_bridge_keys)
    combined_records = prior_records + overlay_records
    combined_keys = {row["source_record_key"] for row in combined_records}
    combined_ids = {row["source_record_id"] for row in combined_records}
    combined_bridge_keys = prior_bridge_keys | new_bridge_keys
    assert len(combined_records) == len(combined_keys) == len(combined_ids) == 661
    assert len(combined_bridge_keys) == 92

    prior_feature_ids = {row["feature_id"] for row in prior_records}
    prior_route_feature_ids = {
        row["feature_id"] for row in prior_records if row["surface"] == "ROUTE_SOURCE_RECORD"
    }
    prior_page_feature_ids = {
        row["feature_id"] for row in prior_records if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"
    }
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
    assert FEATURE_ID in prior_feature_ids
    assert FEATURE_ID not in prior_route_feature_ids
    assert FEATURE_ID in prior_page_feature_ids
    assert len(combined_feature_ids) == 256
    assert Counter(feature_class_by_id.values()) == {"H": 234, "D": 22}
    assert len(route_feature_ids) == 63
    assert len(page_feature_ids) == 242
    assert len(overlap_feature_ids) == 49
    new_union_feature_ids = {FEATURE_ID} - prior_feature_ids
    new_route_feature_ids = {FEATURE_ID} - prior_route_feature_ids
    new_page_feature_ids = set()
    assert new_union_feature_ids == set()
    assert new_route_feature_ids == {FEATURE_ID}
    assert new_page_feature_ids == set()

    queue_rows = data["queue"]["records"]
    queue_by_key = index_unique(queue_rows, "canonical_key")
    new_reviewed_queue_keys = {candidate["queue_canonical_key"]}
    assert len(queue_rows) == len(queue_by_key) == 507
    assert queue_by_key[candidate["queue_canonical_key"]]["review_state"]["status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
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
    run133_queue_keys = {
        row["queue_canonical_key"] for row in data["run133_cohort"]["records"]
    }
    assert [
        len(run091_queue_keys),
        len(run098_queue_keys),
        len(run102_queue_keys),
        len(run110_queue_keys),
        len(run113_queue_keys),
        len(run121_queue_keys),
        len(run129_queue_keys),
        len(run133_queue_keys),
    ] == [12, 23, 24, 1, 24, 22, 2, 6]
    prior_reviewed_queue_keys = (
        run091_queue_keys
        | run098_queue_keys
        | run102_queue_keys
        | run110_queue_keys
        | run113_queue_keys
        | run121_queue_keys
        | run129_queue_keys
        | run133_queue_keys
    )
    assert len(prior_reviewed_queue_keys) == 114
    assert not (prior_reviewed_queue_keys & new_reviewed_queue_keys)
    combined_reviewed_queue_keys = prior_reviewed_queue_keys | new_reviewed_queue_keys
    assert len(combined_reviewed_queue_keys) == 115
    assert reporting["counts"]["reviewed_queue_surface_rows"] == 114
    assert reporting["counts"]["pending_unreviewed_queue_surface_rows"] == 393
    assert reporting["counts"]["source_owner_records"] == 660
    assert reporting["counts"]["static_controller_action_bridges"] == 91

    combined_route_records = [
        row for row in combined_records if row["surface"] == "ROUTE_SOURCE_RECORD"
    ]
    combined_page_records = [
        row for row in combined_records if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"
    ]
    assert len(combined_route_records) == 304
    assert len(combined_page_records) == 357

    expansion = review["source_packet_expansion"]
    expanded_files = expansion["expanded_files"]
    assert len(expanded_files) == 12
    assert sum(row["original_packet_present"] is True for row in expanded_files) == 7
    assert sum(row["original_packet_present"] is False for row in expanded_files) == 5
    assert expansion["original_source_review_complete"] is False
    assert expansion["original_source_packet_completeness_claimed"] is False
    assert expansion["original_material_dependency_semantics_complete"] is False
    assert expansion["original_packet_retroactively_described_as_complete"] is False
    assert expansion["all_expanded_files_match_application_commit_blobs"] is True
    assert expansion["expansion_authorizes_action_outcome_change"] is False
    assert expansion["expansion_authorizes_correctness_credit"] is False
    assert canonical_json_sha256(expanded_files) == review["verified_global_identity"]["source_packet_expansions_sha256"]
    for row in expanded_files:
        source_path = REPO / row["path"]
        assert sha256_file(source_path) == row["sha256"]
        assert git("hash-object", str(source_path)) == row["head_blob_id"]
        assert row["head_blob_id"] == row["application_commit_blob_id"]
        assert row["head_matches_application_commit_blob"] is True
        assert row["expansion_changes_original_packet_bytes"] is False
        assert row["expansion_authorizes_correctness_credit"] is False

    candidate_findings = list(decision["assurance_findings"])
    shared_findings = list(review["shared_assurance_findings"])
    assurance_findings = candidate_findings + shared_findings
    assurance_finding_ids = [row["finding_id"] for row in assurance_findings]
    assert len(candidate_findings) == 6
    assert len(shared_findings) == 3
    assert len(assurance_findings) == len(set(assurance_finding_ids)) == 9
    assert all(row["correctness_credit_authorized"] is False for row in assurance_findings)
    assert canonical_json_sha256(candidate_findings) == review["verified_global_identity"]["candidate_assurance_findings_sha256"]
    assert canonical_json_sha256(shared_findings) == review["verified_global_identity"]["shared_assurance_findings_sha256"]

    combined_counts = {
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
        "bounded_static_source_ownership_percent": str(
            (Decimal(661) * Decimal(100) / Decimal(3929)).quantize(
                Decimal("0.000001"), rounding=ROUND_HALF_UP
            )
        ),
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
    assert combined_counts["bounded_static_source_ownership_percent"] == "16.823619"
    projection = review["reviewed_projection_if_integrated"]
    assert projection["projection_credit_awarded"] is False
    for key, value in combined_counts.items():
        if key in projection:
            assert projection[key] == value, key
        else:
            assert reporting["counts"][key] == value, key

    queue_accounting = {
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

    computed_identity = {
        "owner_candidate_id_list_sha256": canonical_list_sha256({candidate["candidate_id"]}),
        "owner_route_record_id_list_sha256": canonical_list_sha256(new_ids),
        "owner_source_record_key_list_sha256": canonical_list_sha256(new_keys),
        "owner_action_key_list_sha256": canonical_list_sha256({candidate["action_key"]}),
        "owner_bridge_key_list_sha256": canonical_list_sha256({"|".join(next(iter(new_bridge_keys)))}),
        "owner_candidate_record_sha256_list_sha256": canonical_list_sha256({candidate["candidate_record_sha256"]}),
        "owner_decision_record_sha256_list_sha256": canonical_list_sha256({decision["decision_record_sha256"]}),
        "owner_queue_id_list_sha256": canonical_list_sha256({candidate["queue_id"]}),
        "owner_queue_key_list_sha256": canonical_list_sha256(new_reviewed_queue_keys),
        "new_union_feature_id_list_sha256": canonical_list_sha256(new_union_feature_ids),
        "new_route_feature_id_list_sha256": canonical_list_sha256(new_route_feature_ids),
        "new_page_feature_id_list_sha256": canonical_list_sha256(new_page_feature_ids),
        "prior_source_record_key_list_sha256": canonical_list_sha256(prior_keys),
        "prior_source_record_id_list_sha256": canonical_list_sha256(prior_ids),
        "combined_source_record_key_list_sha256": canonical_list_sha256(combined_keys),
        "combined_source_record_id_list_sha256": canonical_list_sha256(combined_ids),
        "combined_feature_id_list_sha256": canonical_list_sha256(combined_feature_ids),
        "combined_route_feature_id_list_sha256": canonical_list_sha256(route_feature_ids),
        "combined_page_feature_id_list_sha256": canonical_list_sha256(page_feature_ids),
        "combined_route_page_overlap_feature_id_list_sha256": canonical_list_sha256(overlap_feature_ids),
        "prior_bridge_key_list_sha256": canonical_list_sha256({"|".join(key) for key in prior_bridge_keys}),
        "combined_bridge_key_list_sha256": canonical_list_sha256({"|".join(key) for key in combined_bridge_keys}),
        "new_reviewed_queue_key_list_sha256": canonical_list_sha256(new_reviewed_queue_keys),
        "prior_reviewed_queue_key_list_sha256": canonical_list_sha256(prior_reviewed_queue_keys),
        "combined_reviewed_queue_key_list_sha256": canonical_list_sha256(combined_reviewed_queue_keys),
        "combined_route_record_id_list_sha256": canonical_list_sha256({row["source_record_id"] for row in combined_route_records}),
        "combined_route_source_record_key_list_sha256": canonical_list_sha256({row["source_record_key"] for row in combined_route_records}),
        "combined_page_record_id_list_sha256": canonical_list_sha256({row["source_record_id"] for row in combined_page_records}),
        "combined_page_source_record_key_list_sha256": canonical_list_sha256({row["source_record_key"] for row in combined_page_records}),
        "new_overlay_source_records_sha256": canonical_json_sha256(overlay_records),
        "new_overlay_row_sha256_list_sha256": canonical_list_sha256({overlay_record["overlay_row_sha256"]}),
        "new_action_bridges_sha256": canonical_json_sha256(action_bridges),
        "new_action_bridge_row_sha256_list_sha256": canonical_list_sha256({action_bridge["bridge_row_sha256"]}),
        "reviewed_decision_record_sha256_list_sha256": review["verified_global_identity"]["decision_record_sha256_list_sha256"],
        "reviewed_decisions_sha256": review["verified_global_identity"]["reviewed_decisions_sha256"],
        "synthesis_record_sha256": review["verified_global_identity"]["synthesis_record_sha256"],
        "source_packet_expansions_sha256": review["verified_global_identity"]["source_packet_expansions_sha256"],
        "assurance_findings_sha256": canonical_json_sha256(assurance_findings),
        "assurance_finding_id_list_sha256": canonical_list_sha256(set(assurance_finding_ids)),
        "independent_reviews_sha256": review["verified_global_identity"]["independent_reviews_sha256"],
        "independent_review_record_sha256_list_sha256": review["verified_global_identity"]["independent_review_record_sha256_list_sha256"],
    }
    if EXPECTED_IDENTITY:
        assert computed_identity == EXPECTED_IDENTITY

    payload = {
        "schema_version": "run-138-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22-v1",
        "run_id": "RUN-138-REVIEWED-OUTCOME-NEUTRAL-FINANCE-INVOICE-INDEX-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-22",
        "status": "ONE_REVIEWED_INVOICE_INDEX_ROUTE_ACTION_OWNER_AND_BRIDGE_INTEGRATED_STATIC_ONLY",
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
            "Oblivion Findings is one operating organisation across multiple Sites. Legacy organisation storage "
            "context is not an access boundary. Static invoice-index route/action ownership establishes neither "
            "approved-Site reach, exact permission, canonical record ownership, direct-object concealment, privacy, "
            "query or projection correctness, minimisation, lifecycle, concurrency, nor release readiness."
        ),
        "baseline": {
            "reporting_run_id": reporting["run_id"],
            "source_owner_records": 660,
            "route_owner_records": 303,
            "page_owner_records": 357,
            "static_controller_action_bridges": 91,
            "reviewed_queue_surface_rows": 114,
            "pending_unreviewed_queue_surface_rows": 393,
            "reporting_sha256": EXPECTED_INPUT_SHA256["run135_reporting"],
        },
        "reviewed_overlay": {
            "producer_run_id": cohort["run_id"],
            "review_run_id": review["run_id"],
            "reviewed_route_actions": 1,
            "owner_route_actions": 1,
            "shared_relations": 0,
            "alias_or_redirect": 0,
            "dead_or_noncanonical": 0,
            "evidence_gaps": 0,
            "accepted_source_owner_records": 1,
            "accepted_route_owner_records": 1,
            "accepted_page_owner_records": 0,
            "accepted_controller_action_bridges": 1,
            "accepted_distinct_feature_ids": 1,
            "new_distinct_feature_ids": 0,
            "new_route_feature_ids": [FEATURE_ID],
            "new_page_feature_ids": [],
            "reviewed_non_owner_records_preserved": 0,
            "current_static_overlay_credit_applied": True,
            "page_ownership_inherited": False,
            "prior_page_owner_context_inherited_or_recredited": False,
            "caller_or_sibling_ownership_used": False,
            "cohort_sha256": EXPECTED_INPUT_SHA256["cohort"],
            "review_sha256": EXPECTED_INPUT_SHA256["review"],
        },
        "source_packet_expansion_preservation": {
            "total_disclosed_expansion_entries": 12,
            "widened_existing_packet_files": 7,
            "newly_followed_files": 5,
            "original_required_source_files": 33,
            "original_material_dependency_method_slices": 10,
            "cohort_source_review_packet_sha256": SOURCE_PACKET_SHA256,
            "source_packet_expansions_sha256": review["verified_global_identity"]["source_packet_expansions_sha256"],
            "original_source_review_complete": False,
            "original_source_packet_completeness_claimed": False,
            "original_material_dependency_semantics_complete": False,
            "original_packet_retroactively_described_as_complete": False,
            "correctness_credit_authorized": False,
            "expanded_file_records": expanded_files,
        },
        "assurance_findings_preservation": {
            "candidate_findings": 6,
            "shared_findings": 3,
            "total_findings": 9,
            "assurance_findings_sha256": computed_identity["assurance_findings_sha256"],
            "assurance_finding_id_list_sha256": computed_identity["assurance_finding_id_list_sha256"],
            "provisional_only": True,
            "final_finding_credit_authorized": False,
            "correctness_or_downstream_credit_authorized": False,
            "findings": assurance_findings,
        },
        "reviewer_lineage": {
            "independent_candidate_reviews": review["independent_candidate_reviews"],
            "independent_reviews_sha256": review["verified_global_identity"]["independent_reviews_sha256"],
            "synthesis_review": review["synthesis_review"],
            "synthesis_record_sha256": review["verified_global_identity"]["synthesis_record_sha256"],
            "reviewed_decisions_sha256": review["verified_global_identity"]["reviewed_decisions_sha256"],
            "all_reviewers_wrote_files": False,
        },
        "combined_counts": combined_counts,
        "queue_accounting": queue_accounting,
        "page_context_boundary": {
            "literal_inertia_page_callsites": 1,
            "existing_page_owner_context_rows": 2,
            "frontend_static_path_contexts": 7,
            "existing_index_page_record_id": "PAGE-ROOT-B4964DF8343DF25A",
            "existing_index_page_owner_row_id": "RUN086-PAGE-MAP-0207",
            "existing_show_page_record_id": "PAGE-ROOT-E1ACF667B368A747",
            "existing_show_page_owner_row_id": "RUN086-PAGE-MAP-0272",
            "existing_page_feature_id": FEATURE_ID,
            "new_page_owner_records": 0,
            "page_ownership_inherited": False,
            "page_ownership_reassigned": False,
            "rule": "Index and Show remain existing page-owner context; the selected render and callers transfer no page or correctness ownership.",
        },
        "noninheritance_boundary": {
            "selected_queue_index_zero_based": 77,
            "selected_queue_id": "RUN090-ROUTE-0078",
            "selected_route_record_id": "RUN077-ROUTE-0634",
            "prior_page_owner_context_inherited_or_recredited": False,
            "frontend_path_or_navigation_ownership_used": False,
            "next_queue_index_zero_based": 78,
            "next_queue_id": "RUN090-ROUTE-0079",
            "next_route_record_id": "RUN077-ROUTE-0669",
            "next_feature_id": "CAP-FIN-SITE-PORTFOLIO-OVERVIEW",
            "next_boundary_selected_or_credited": False,
        },
        "overlay_source_records": overlay_records,
        "new_static_controller_action_bridges": action_bridges,
        "reviewed_non_owner_outcomes": [],
        "identity": computed_identity,
        "identity_discovery": computed_identity,
        "outcome_conservation": {
            "reviewed_outcomes_equation": "1 = 1 owner + 0 shared + 0 alias + 0 dead + 0 evidence gap",
            "bounded_source_equation": "3929 = 661 owner + 3268 non-owner residual",
            "owner_surface_equation": "661 = 304 route + 357 page",
            "feature_union_equation": "256 = 63 route + 242 page - 49 overlap",
            "route_universe_equation": "3218 = 304 owner + 12 shared + 5 alias + 0 dead + 2897 residual",
            "evidence_gap_is_tagged_within_2897_route_residual": True,
            "page_universe_equation": "711 = 357 owner + 9 shared + 0 alias + 0 dead + 345 residual",
            "evidence_gap_is_tagged_within_345_page_residual": True,
            "queue_equation": "507 = 115 reviewed + 392 pending",
            "reviewed_queue_equation": "115 = 93 owner + 10 shared + 5 alias + 0 dead + 7 evidence gap",
            "queue_without_ownership_equation": "414 = 392 pending + 10 shared + 5 alias + 0 dead + 7 evidence gap",
        },
        "projection_reconciliation": {
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
        },
        "denominator_boundary": {
            "run_077_bounded_static_records": 3929,
            "framework_expanded_route_page_denominator": None,
            "page_denominator_711_roots_vs_1058_page_tree_files_resolved": False,
            "complete_route_page_feature_crosswalk": False,
            "gate_4_complete": False,
        },
        "credit_boundary": {
            "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD": True,
            "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION": True,
            "static_page_feature_ownership": False,
            "frontend_caller_ownership": False,
            "prior_page_owner_context_inherited_or_recredited": False,
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
            "query_correctness": False,
            "projection_correctness": False,
            "response_minimization_correctness": False,
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
            "reports_changed": False,
            "dashboard_generator_changed": False,
            "dashboard_html_changed": False,
            "runtime_or_external_system_changed": False,
            "audit_artifacts_only": True,
            "whole_repository_status_scope_asserted": True,
            "only_expected_untracked_run138_artifacts_present": True,
            "expected_status_paths": [GENERATOR_RELATIVE_PATH, OUTPUT_RELATIVE_PATH],
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [GENERATOR_RELATIVE_PATH, OUTPUT_RELATIVE_PATH],
    }
    assert 3929 == payload["combined_counts"]["source_owner_records"] + payload["combined_counts"]["bounded_static_source_residual_records"]
    assert 661 == payload["combined_counts"]["route_owner_records"] + payload["combined_counts"]["page_owner_records"]
    assert 3218 == 304 + 12 + 5 + 0 + 2897
    assert 711 == 357 + 9 + 0 + 0 + 345
    assert 256 == 63 + 242 - 49 == 234 + 22
    assert 507 == 115 + 392
    assert 115 == 93 + 10 + 5 + 0 + 7
    assert 414 == 392 + 10 + 5 + 0 + 7
    assert {key for key, value in payload["credit_boundary"].items() if value} == {
        "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD",
        "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION",
    }
    assert payload["audit_completion_test_met"] is False
    return payload


def main() -> None:
    payload = build()
    encoded = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    if not OUTPUT_PATH.exists() or OUTPUT_PATH.read_bytes() != encoded:
        OUTPUT_PATH.write_bytes(encoded)
    raw = OUTPUT_PATH.read_bytes()
    assert raw == encoded
    assert raw.startswith(b"\xef\xbb\xbf") is False
    assert b"\r\n" not in raw
    assert raw.endswith(b"\n")
    assert set(git("status", "--porcelain").splitlines()) == {
        f"?? {GENERATOR_RELATIVE_PATH}",
        f"?? {OUTPUT_RELATIVE_PATH}",
    }
    print(
        json.dumps(
            {
                "status": payload["status"],
                "output": OUTPUT_RELATIVE_PATH,
                "sha256": sha256_file(OUTPUT_PATH),
                "source_owner_records": payload["combined_counts"]["source_owner_records"],
                "route_owner_records": payload["combined_counts"]["route_owner_records"],
                "page_owner_records": payload["combined_counts"]["page_owner_records"],
                "bridges": payload["combined_counts"]["static_controller_action_bridges"],
                "reviewed_queue": payload["queue_accounting"]["reviewed_queue_surface_rows"],
                "pending_queue": payload["queue_accounting"]["pending_unreviewed_queue_surface_rows"],
                "gate_4_complete": payload["denominator_boundary"]["gate_4_complete"],
                "audit_complete": payload["audit_completion_test_met"],
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
