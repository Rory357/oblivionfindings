#!/usr/bin/env python3
"""Integrate the one bounded RUN179R Fleet trip-index route/action owner."""
from __future__ import annotations

from collections import Counter
from decimal import Decimal, ROUND_HALF_UP
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
from typing import Any


sys.dont_write_bytecode = True
REPO = Path(__file__).resolve().parents[4]
AUDIT = Path(__file__).resolve().parents[1]
PREFIX = AUDIT.relative_to(REPO).as_posix()
GENERATOR = "generators/integrate-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-wave-34.py"
OUTPUT = "evidence/source/current-run-180-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-wave-34.json"

HEAD = "db4aa8c943c63f43892cfd2dd9d7495be60796b4"
TREE = "d38e62180949fa5a1a6c09d906821d69ede5f95d"
PARENT = "b263db6e2c883cae8370cc3529eac490d121f2db"
APPLICATION_COMMIT = "f40e3d63ea99d774265ff9f2eefef8176ab0cbc7"
APPLICATION_TREE = "880721d56b7d379abf9628abb22a5a9b9445194b"
SUBTREES = {
    "app": "3a83cf8acdd88071870634501ab7eacf2d76e62a",
    "routes": "b62a85f59ba5f45a54fd666b3199a65453034272",
    "resources/js": "8a851516cdb76ded362fb5912e3e930e45c8df86",
    "resources/js/pages": "8ad1ecc5817310f2f45c64733ca72d771c798a2f",
    "tests": "332a54fe95c85c1c1ea9477a1ea115bce9f7b4ac",
    "database": "341446159b5d8f6e303db9e9cddabfd446b0e034",
    "bootstrap": "df6189abe5ab5343d88674c199c4ce46e6152a57",
    "docs/architecture": "3444047114f5f446954b032dedc4e0c7892180bd",
}

GOVERNING_PROMPT = {
    "path": r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md",
    "sha256": "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f",
    "role": "GOVERNING_AUDIT_PROMPT",
}
CONTINUATION_REQUEST = {
    "path": r"C:\Users\steph\.codex\attachments\8b35b9fe-b295-4a84-bdf9-a8afb05b2daa\pasted-text-1.txt",
    "sha256": "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d",
    "role": "CONTINUATION_REQUEST_ONLY",
    "is_governing_prompt": False,
}

BASE_COLLECTOR = "generators/integrate-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.py"
RUN149 = "evidence/source/current-run-149-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.json"
RUN153 = "evidence/source/current-run-153-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.json"
RUN153R = "evidence/source/current-run-153r-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.json"
RUN170_GENERATOR = "generators/integrate-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31.py"
RUN170 = "evidence/source/current-run-170-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31.json"
RUN170R = "evidence/source/current-run-170r-independent-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-review-wave-31.json"
QUEUE = "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json"
MATRIX = "03-feature-to-benchmark-matrix.csv"
COHORT_GENERATOR = "generators/build-outcome-neutral-fleet-trip-index-route-action-cohort-wave-34.py"
COHORT = "evidence/source/root-run-179-outcome-neutral-fleet-trip-index-route-action-cohort-wave-34.json"
REVIEW_GENERATOR = "generators/materialize-independent-outcome-neutral-fleet-trip-index-route-action-review-wave-34.py"
REVIEW = "evidence/source/raw-run-179r-independent-outcome-neutral-fleet-trip-index-route-action-review-wave-34.json"

EXPECTED = {
    BASE_COLLECTOR: "b5c7f04cd44ecd73dda9c7fe4a9e2e8616c68674cdc52d393ec696b06ad2327e",
    RUN149: "12a52c434ecd18a5c6a644378070aa5ab046f5e7080726b983ded8d9c7377a55",
    RUN153: "9b7e382f83787d807de8d752ecb3e6524280c707899aba78d47082765272e815",
    RUN153R: "7f1da8394a8054f01f34fb943a3fba6601bf70ea06d69cf97033f2208edf4461",
    RUN170_GENERATOR: "c732926f3112c987fbaaf3f398bc18b3d25027c7f1495c38016237a5cb6f28a3",
    RUN170: "c739a36e1975b60d42988be3de36b9fe1ea88cf942752c90112f40ebaa04cd8d",
    RUN170R: "62474100b0c2f027fa0c15f2bb841f08ad3de058da67725a931fcafec17dd139",
    QUEUE: "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    MATRIX: "3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0",
    COHORT_GENERATOR: "61c895a305f743f102765c9f86d38843c3ce61bcc1a8684a672aa2d7cd6ee157",
    COHORT: "5505cf17bb68d3e534116ea9d33e501e0222714b6e3779d0ec6b70f819cc3b0a",
    REVIEW_GENERATOR: "80cf0e6febabee80b1fa99f3f296cabade8959bd5a4fcd72983af19d335332cd",
    REVIEW: "67c5b09cbb26c95042bd7ba487c2a2c92a75d14363952ca35e9b72ee55e36d62",
}
EXPECTED_BLOBS = {
    BASE_COLLECTOR: "e8d9d1c9889be589a22db6dfea53d3122adce247",
    RUN149: "c5f0a3bda99167f66650d63bfdb35e18d8ed93b5",
    RUN153: "818b891cff9965193c60d83d0580c21a48d1a682",
    RUN153R: "20bb3580ba2cb60205694d52aa72e16cd2f2a423",
    RUN170_GENERATOR: "2603b130a0a674e6803413583c95b51bc3f83545",
    RUN170: "8cff90e1e86e5752cbfc3e59d03ccc5423e23ed6",
    RUN170R: "fbcccd7e19ea57db52a1d6ca462aa107107159d1",
    QUEUE: "66809274d25916f4e0d2426419bfde6e371ba1f1",
    MATRIX: "1f5fdab3ae80ae4ec1b9bc4ee47eef695bdd5416",
    COHORT_GENERATOR: "506a7007c8d7b8e719b1bfa904a880a2885fe8c1",
    COHORT: "ea3a958c125038a95c8d98370328a263d2a2c151",
    REVIEW_GENERATOR: "3004a455a14736f2641e7f71c506181a0b02d967",
    REVIEW: "7a1d16ff8ee0f0fe78aeac742322bee0c8c6e8ec",
}

CANDIDATE_HASH = "b09ac81def93dcb4800f4a1ac340c698ff73f538ae3bcca792b01a53d7c2b650"
QUEUE_SEAL = "928eeec741742f8329dd7e191a71f2d5249775b6de64e6a698a72836345ca011"
COHORT_SEAL = "2fb26afd47c818fe5654fdc685af9a87e40624ad44e205914cca85298593bfc2"
REVIEW_SEAL = "75589c560904f51656af7038037e988ae169b181ddc480b95d5fca35cdbec14b"
SYNTHESIS_SEAL = "2142515ab596130890f398a3cb06f7818c1c98264b48598e7ccc991ea6d1df2d"
DECISION_SEAL = "e3530def5fb093b5b2169659d32b3251a6726d493257602c8138d3a38bc050d3"
PRIOR_SOURCE_IDENTITY = "d691bbfc9eabfa3f34f0df294c24c6890d3082b2149ed8b553cc88747e3143e5"
PRIOR_BRIDGE_IDENTITY = "19ed2b2cabf56de20dc2ae10b70877536140dc76285c5c64462d71535b302498"
PRIOR_REVIEWED_IDENTITY = "acfca5e54d64c54334dbd94b30104244b3d2d6722a5426439aec7a8aa62d3ab5"
PRIOR_REVIEWED_CANONICAL = "e85b37e5410c1cc861f9116061e88fb82fdb854e5dc94e56eefe1947b3a7b510"
COMBINED_SOURCE_IDENTITY = "1648a470ca0293c4c065b30925b8eda5a9f78d35fa64935e644a3354e17cdbba"
COMBINED_BRIDGE_IDENTITY = "6ab1b8c1045ac6c159ba4aa5856ac58e648263a530f4f7c3031e4eed5d84fa32"
COMBINED_REVIEWED_IDENTITY = "5dbcecd3986300fe255fdb75efe6013c07f3adc4071745ebebf0c4a525ee99c9"
COMBINED_REVIEWED_CANONICAL = "738c7836dd770e12d67de62d4f28441825814d619bb641e070e25468786fb75e"
OVERLAY_ROW_SEAL = "5e502f8732212c48edbf5e83ddb114410a06ac6d986a8a0f71f7554ad4ce2f50"
BRIDGE_ROW_SEAL = "eff07ebed3567fd6e9c6aebd7980333dd8ee34a201a0ac2c4e47b93b26c2e5de"


def run(*args: str) -> bytes:
    return subprocess.run(args, cwd=REPO, check=True, capture_output=True).stdout


def git(*args: str) -> str:
    return run("git", *args).decode("utf-8").rstrip("\r\n")


def digest(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def audit_sha(relative: str) -> str:
    return digest((AUDIT / relative).read_bytes())


def canonical(value: Any) -> str:
    return digest(json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode())


def hlist(values: list[str] | set[str]) -> str:
    return digest("\n".join(sorted(set(values))).encode())


def strict_json(relative: str) -> dict[str, Any]:
    def hook(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, (relative, key)
            result[key] = value
        return result

    value = json.loads((AUDIT / relative).read_text(encoding="utf-8"), object_pairs_hook=hook)
    assert isinstance(value, dict)
    return value


def sealed(record: dict[str, Any], field: str) -> dict[str, Any]:
    record[field] = canonical(record)
    return record


def verify_seal(record: dict[str, Any], field: str, expected: str) -> None:
    raw = record[field]
    actual = raw["sha256"] if isinstance(raw, dict) else raw
    assert actual == expected
    assert actual == canonical({key: value for key, value in record.items() if key != field})


def validate_inputs() -> tuple[dict[str, Any], ...]:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == HEAD
    assert git("show", "-s", "--format=%T", "HEAD") == TREE
    assert git("show", "-s", "--format=%P", "HEAD") == PARENT
    assert git("show", "-s", "--format=%T", APPLICATION_COMMIT) == APPLICATION_TREE
    for path, expected in SUBTREES.items():
        assert git("rev-parse", f"HEAD:{path}") == expected
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{path}") == expected
    assert not git("status", "--porcelain", "--", "app", "routes", "resources/js", "tests", "database", "bootstrap")
    for relative, expected in EXPECTED.items():
        assert audit_sha(relative) == expected, relative
        assert git("rev-parse", f"HEAD:{PREFIX}/{relative}") == EXPECTED_BLOBS[relative], relative
    assert digest(Path(GOVERNING_PROMPT["path"]).read_bytes()) == GOVERNING_PROMPT["sha256"]
    assert digest(Path(CONTINUATION_REQUEST["path"]).read_bytes()) == CONTINUATION_REQUEST["sha256"]
    assert GOVERNING_PROMPT["sha256"] != CONTINUATION_REQUEST["sha256"]

    run149 = strict_json(RUN149)
    run153 = strict_json(RUN153)
    run153r = strict_json(RUN153R)
    run170 = strict_json(RUN170)
    run170r = strict_json(RUN170R)
    cohort = strict_json(COHORT)
    review = strict_json(REVIEW)

    verify_seal(cohort, "self_seal", COHORT_SEAL)
    verify_seal(review, "self_seal", REVIEW_SEAL)
    assert run170["combined_counts"]["source_owner_records"] == 665
    assert run170["combined_counts"]["static_controller_action_bridges"] == 96
    assert run170["queue_accounting"]["reviewed_queue_surface_rows"] == 119
    assert run170["identity"]["combined_source_record_key_list_sha256"] == PRIOR_SOURCE_IDENTITY
    assert run170["identity"]["combined_bridge_key_list_sha256"] == PRIOR_BRIDGE_IDENTITY
    assert run170["identity"]["combined_reviewed_queue_key_list_sha256"] == PRIOR_REVIEWED_IDENTITY
    assert run153r["decision"]["verdict"] == "GO" and run153r["decision"]["discrepancies"] == 0
    assert run170r["status"] == "GO_THREE_PART_POST_COMMIT_REVIEW_COMPLETE_REPORTING_ONLY_ZERO_NEW_OR_DOWNSTREAM_CREDIT"
    assert {key for key, value in run170r["credit_boundary"].items() if value} == {
        "INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING",
    }

    assert len(cohort["records"]) == 1
    candidate = cohort["records"][0]
    assert canonical(candidate) == CANDIDATE_HASH
    assert candidate["queue_record_sha256"] == QUEUE_SEAL
    assert candidate["canonical_key"] == "route|RUN077-ROUTE-0693"
    assert candidate["candidate_feature_id"] == "CAP-FLEET-VEHICLE-REGISTER"
    assert candidate["source"]["literal_route_name"] == "fleet-assets.trips.index"
    assert candidate["source"]["action_expression"] == "[VehicleController::class, 'trips']"

    assert review["decision"] == "GO"
    assert review["status"] == "GO_TWO_OF_TWO_FRESH_STRICT_CURRENT_TIEBREAKS_ACCEPT_ONE_STATIC_ROUTE_OWNER_AND_BRIDGE_FOR_LATER_INTEGRATION_ZERO_CURRENT_OR_DOWNSTREAM_CREDIT"
    synthesis = review["synthesis_review"]
    decision = review["action_decision"]
    assert synthesis["synthesis_record_sha256"] == SYNTHESIS_SEAL
    assert synthesis["synthesis_record_sha256"] == canonical({key: value for key, value in synthesis.items() if key != "synthesis_record_sha256"})
    assert decision["decision_record_sha256"] == DECISION_SEAL
    assert decision["decision_record_sha256"] == canonical({key: value for key, value in decision.items() if key != "decision_record_sha256"})
    assert decision["candidate_record_sha256"] == CANDIDATE_HASH
    assert decision["queue_record_self_seal_sha256"] == QUEUE_SEAL
    assert decision["outcome"] == "OWNER_ROUTE_ACTION"
    assert decision["owner_source_record_key"] == "route|RUN077-ROUTE-0693|CAP-FLEET-VEHICLE-REGISTER"
    assert decision["bridge_key"] == ["app/Http/Controllers/FleetAssets/VehicleController.php", "trips", "CAP-FLEET-VEHICLE-REGISTER"]
    assert decision["route_ownership_authorized_for_later_overlay"] is True
    assert decision["controller_action_bridge_authorized_for_later_overlay"] is True
    assert decision["page_ownership_authorized"] is False
    assert decision["adjacent_route_ownership_authorized"] is False
    assert decision["model_service_helper_caller_or_test_ownership_authorized"] is False
    assert decision["current_overlay_credit_awarded"] is False
    assert decision["correctness_or_downstream_credit_authorized"] is False

    tiebreaks = review["independent_semantic_tiebreak_reviews"]
    assert len(tiebreaks) == 2
    assert {item["outcome"] for item in tiebreaks} == {"OWNER_ROUTE_ACTION"}
    assert len({item["reviewer_task_path"] for item in tiebreaks}) == 2
    assert all(item["older_2026_08_12_bundle_consulted"] is False for item in tiebreaks)
    assert all(item["route_ownership_authorized_for_later_overlay"] is True for item in tiebreaks)
    assert synthesis["strict_current_split"] == {"OWNER_ROUTE_ACTION": 1, "EVIDENCE_GAP": 1}
    assert synthesis["original_strict_current_dissent_preserved"] is True
    assert synthesis["dissenting_strict_current_outcome"] == "EVIDENCE_GAP"
    assert synthesis["fresh_tiebreak_votes"] == {"OWNER_ROUTE_ACTION": 2, "SHARED_RELATION": 0, "EVIDENCE_GAP": 0}
    assert synthesis["candidate_outcome"] == {"OWNER_ROUTE_ACTION": 1, "SHARED_RELATION": 0, "EVIDENCE_GAP": 0}
    assert synthesis["excluded_2026_08_12_identity_or_credit_imported"] is False
    chronology = review["review_chronology"]
    strict_split = [item["reported_outcome"] for item in chronology if item["stage"] == "STRICT_CURRENT_RERUN"]
    assert strict_split == ["OWNER_ROUTE_ACTION", "EVIDENCE_GAP"]
    assert review["excluded_material_boundary"]["feature_identity_imported"] is False
    assert review["excluded_material_boundary"]["benchmark_or_mapping_credit_imported"] is False
    assert {key for key, value in review["credit_boundary"].items() if value} == {
        "reviewed_static_route_feature_ownership_for_1_record",
        "reviewed_static_controller_action_bridge_for_1_action",
        "bounded_overlay_integration_authorized_later_only",
    }
    return run149, run153, run153r, run170, run170r, cohort, review


def main() -> None:
    run149, run153, run153r, run170, run170r, cohort, review = validate_inputs()
    candidate = cohort["records"][0]
    feature = candidate["feature_identity_projection"]
    synthesis = review["synthesis_review"]
    decision = review["action_decision"]
    current_route = cohort["current_source_reconciliation"]["current_main_route_source"]
    current_controller = cohort["current_source_reconciliation"]["current_main_controller_resolution"]
    method_slice = cohort["source_review_packet"]["selected_controller_action_and_helper_slices"]["trips"]
    original_resolution = candidate["secondary_lane"]["backend_method_relation"]["resolution"]
    tiebreaks = review["independent_semantic_tiebreak_reviews"]

    row = sealed({
        "overlay_mapping_id": "RUN180-ROUTE-01",
        "candidate_record_sha256": CANDIDATE_HASH,
        "queue_record_self_seal_sha256": QUEUE_SEAL,
        "synthesis_record_sha256": SYNTHESIS_SEAL,
        "decision_record_sha256": DECISION_SEAL,
        "surface": "ROUTE_SOURCE_RECORD",
        "source_record_id": "RUN077-ROUTE-0693",
        "source_record_key": "route|RUN077-ROUTE-0693|CAP-FLEET-VEHICLE-REGISTER",
        "feature_id": "CAP-FLEET-VEHICLE-REGISTER",
        "feature_class": feature["feature_class"],
        "module": feature["module"],
        "user_job": feature["user_job"],
        "source": candidate["source"],
        "source_provenance": {
            "immutable_queue_route_file_sha256": candidate["source"]["route_file_sha256"],
            "immutable_queue_route_file_blob_id": candidate["source"]["route_file_blob_id"],
            "current_review_route_file_sha256": current_route["route_file_sha256"],
            "current_review_route_file_blob_id": current_route["route_file_blob_id"],
            "exact_statement_preserved_across_drift": True,
            "historical_hash_not_presented_as_current": True,
        },
        "review_outcome": "OWNER_ROUTE_ACTION",
        "accepted_tiebreak_review_ids": [item["review_id"] for item in tiebreaks],
        "accepted_tiebreak_review_record_sha256s": [item["review_record_sha256"] for item in tiebreaks],
        "original_strict_current_dissent_preserved": True,
        "original_strict_current_split": synthesis["strict_current_split"],
        "static_source_feature_ownership_credit": True,
        "credit_boundary": {key: False for key in (
            "page_ownership", "adjacent_route_ownership", "frontend_caller_ownership",
            "service_model_helper_or_test_ownership", "framework_route_reachability",
            "canonical_object_ownership_correctness", "approved_site_scope_correctness",
            "permission_correctness", "privacy_correctness", "direct_object_concealment_correctness",
            "query_projection_correctness", "runtime", "database", "build", "application_browser",
            "executed_tests", "benchmark", "final_no_match_or_NCM", "final_finding", "completion",
            "gate_4", "publication", "audit_complete",
        )},
    }, "overlay_row_sha256")
    assert row["overlay_row_sha256"] == OVERLAY_ROW_SEAL

    bridge = sealed({
        "bridge_id": "RUN180-BRIDGE-01",
        "candidate_record_sha256": CANDIDATE_HASH,
        "queue_record_self_seal_sha256": QUEUE_SEAL,
        "synthesis_record_sha256": SYNTHESIS_SEAL,
        "decision_record_sha256": DECISION_SEAL,
        "feature_id": "CAP-FLEET-VEHICLE-REGISTER",
        "route_record_id": "RUN077-ROUTE-0693",
        "controller_fqcn": original_resolution["resolved_fqcn"],
        "controller_file": method_slice["source_file"],
        "controller_file_sha256": method_slice["source_file_sha256"],
        "controller_file_blob_id": method_slice["source_file_blob_id"],
        "method": "trips",
        "definition_anchor": current_controller["definition_anchor"],
        "method_review_slice_sha256": method_slice["text_sha256"],
        "review_outcome": "OWNER_ROUTE_ACTION",
        "static_controller_action_bridge_credit": True,
        "page_ownership_credit": False,
        "adjacent_route_ownership_credit": False,
        "service_model_helper_caller_or_test_ownership_credit": False,
        "correctness_credit": False,
        "site_permission_privacy_or_direct_object_credit": False,
        "runtime_credit": False,
        "application_browser_credit": False,
        "executed_test_credit": False,
        "benchmark_credit": False,
        "final_finding_credit": False,
        "completion_credit": False,
        "publication_credit": False,
    }, "bridge_row_sha256")
    assert bridge["bridge_row_sha256"] == BRIDGE_ROW_SEAL

    spec = importlib.util.spec_from_file_location("run149_base", AUDIT / BASE_COLLECTOR)
    assert spec and spec.loader
    base = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(base)
    prior_records, prior_bridges = base.collect_prior_state()
    prior_records += run149["overlay_source_records"] + run153["overlay_source_records"] + run170["overlay_source_records"]
    prior_bridges += run149["new_static_controller_action_bridges"] + run153["new_static_controller_action_bridges"] + run170["new_static_controller_action_bridges"]
    assert (len(prior_records), len(prior_bridges)) == (665, 96)
    prior_source_keys = [item["source_record_key"] for item in prior_records]
    prior_bridge_keys = ["|".join((item["controller_file"], item["method"], item["feature_id"])) for item in prior_bridges]
    assert len(prior_source_keys) == len(set(prior_source_keys)) == 665
    assert len(prior_bridge_keys) == len(set(prior_bridge_keys)) == 96
    assert hlist(prior_source_keys) == PRIOR_SOURCE_IDENTITY
    assert hlist(prior_bridge_keys) == PRIOR_BRIDGE_IDENTITY
    assert row["source_record_key"] not in set(prior_source_keys)
    new_bridge_key = "|".join((bridge["controller_file"], bridge["method"], bridge["feature_id"]))
    assert new_bridge_key not in set(prior_bridge_keys)

    records, bridges = prior_records + [row], prior_bridges + [bridge]
    assert len({item["source_record_id"] for item in records}) == len({item["source_record_key"] for item in records}) == 666
    bridge_keys = [(item["controller_file"], item["method"], item["feature_id"]) for item in bridges]
    assert len(bridge_keys) == len(set(bridge_keys)) == 97
    routes = [item for item in records if item["surface"] == "ROUTE_SOURCE_RECORD"]
    pages = [item for item in records if item["surface"] == "PAGE_ROOT_SOURCE_RECORD"]
    assert (len(routes), len(pages)) == (309, 357)
    features = {item["feature_id"] for item in records}
    route_features = {item["feature_id"] for item in routes}
    page_features = {item["feature_id"] for item in pages}
    assert (len(features), len(route_features), len(page_features), len(route_features & page_features)) == (256, 64, 242, 50)
    assert Counter({item["feature_id"]: item["feature_class"] for item in records}.values()) == {"H": 234, "D": 22}
    percent = (Decimal(666) * 100 / Decimal(3929)).quantize(Decimal("0.000001"), rounding=ROUND_HALF_UP)
    assert format(percent, "f") == "16.950878"

    prior_keys = base.collect_prior_reviewed_queue_keys() | {
        "route|RUN077-ROUTE-0689", "route|RUN077-ROUTE-0690", "route|RUN077-ROUTE-0692",
    }
    assert len(prior_keys) == 119 and "route|RUN077-ROUTE-0693" not in prior_keys
    assert hlist(prior_keys) == PRIOR_REVIEWED_IDENTITY
    assert canonical(sorted(prior_keys)) == PRIOR_REVIEWED_CANONICAL
    reviewed_keys = prior_keys | {"route|RUN077-ROUTE-0693"}
    assert len(reviewed_keys) == 120
    queue_rows = strict_json(QUEUE)["records"]
    assert queue_rows[84]["canonical_key"] in reviewed_keys
    next_index = next(index for index in range(85, len(queue_rows)) if queue_rows[index]["canonical_key"] not in reviewed_keys)
    assert next_index == 85
    next_row = queue_rows[next_index]
    assert (
        next_row["queue_id"], next_row["source_record_id"], next_row["source"]["literal_route_name"],
        next_row["source"]["action_expression"], next_row["queue_record_sha256"],
    ) == (
        "RUN090-ROUTE-0086", "RUN077-ROUTE-0694", "fleet-assets.trips.playback",
        "[FleetTripController::class, 'show']", "f9df043e4557240020de213961c847fb56b8cd0e2d9b9144ec0b7a877ff84943",
    )

    counts = {
        "source_owner_records": 666,
        "route_owner_records": 309,
        "page_owner_records": 357,
        "distinct_feature_ids": 256,
        "distinct_H_feature_ids": 234,
        "distinct_D_feature_ids": 22,
        "route_distinct_feature_ids": 64,
        "page_distinct_feature_ids": 242,
        "route_page_feature_overlap": 50,
        "static_controller_action_bridges": 97,
        "bounded_static_source_denominator": 3929,
        "bounded_static_source_ownership_percent": format(percent, "f"),
        "bounded_static_source_residual_records": 3263,
        "residual_explicit_unmapped_routes": 2892,
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
    queue_counts = {
        "direct_exact_queue_records": 507,
        "reviewed_queue_surface_rows": 120,
        "owner_queue_surface_rows": 98,
        "shared_queue_surface_rows": 10,
        "alias_queue_surface_rows": 5,
        "dead_queue_surface_rows": 0,
        "evidence_gap_queue_surface_rows": 7,
        "pending_unreviewed_queue_surface_rows": 387,
        "queue_surfaces_without_ownership": 409,
        "new_reviewed_route_surface_rows": 1,
        "new_owner_route_surface_rows": 1,
    }
    assert 3929 == 666 + 3263 and 666 == 309 + 357
    assert 3218 == 309 + 12 + 5 + 0 + 2892
    assert 711 == 357 + 9 + 0 + 0 + 345
    assert 507 == 120 + 387 and 120 == 98 + 10 + 5 + 0 + 7
    assert 409 == 387 + 10 + 5 + 0 + 7
    assert len(features) == len(route_features) + len(page_features) - len(route_features & page_features) == 256

    identity = {
        "hash_algorithm": "sha256-of-lf-joined-sorted-unique-utf8",
        "prior_source_record_key_list_sha256": hlist(prior_source_keys),
        "combined_source_record_key_list_sha256": hlist([item["source_record_key"] for item in records]),
        "prior_bridge_key_list_sha256": hlist(prior_bridge_keys),
        "combined_bridge_key_list_sha256": hlist(["|".join((item["controller_file"], item["method"], item["feature_id"])) for item in bridges]),
        "prior_reviewed_queue_key_list_sha256": hlist(prior_keys),
        "combined_reviewed_queue_key_list_sha256": hlist(reviewed_keys),
        "canonical_json_reviewed_key_hashes": {"prior": canonical(sorted(prior_keys)), "combined": canonical(sorted(reviewed_keys))},
        "new_overlay_source_records_sha256": canonical([row]),
        "new_action_bridges_sha256": canonical([bridge]),
        "accepted_tiebreak_review_record_sha256s": [item["review_record_sha256"] for item in tiebreaks],
        "synthesis_record_sha256": SYNTHESIS_SEAL,
        "decision_record_sha256": DECISION_SEAL,
        "original_strict_current_chronology_record_sha256s": [item["chronology_record_sha256"] for item in review["review_chronology"] if item["stage"] == "STRICT_CURRENT_RERUN"],
    }
    assert identity["prior_source_record_key_list_sha256"] == PRIOR_SOURCE_IDENTITY
    assert identity["prior_bridge_key_list_sha256"] == PRIOR_BRIDGE_IDENTITY
    assert identity["prior_reviewed_queue_key_list_sha256"] == PRIOR_REVIEWED_IDENTITY
    assert identity["combined_source_record_key_list_sha256"] == COMBINED_SOURCE_IDENTITY
    assert identity["combined_bridge_key_list_sha256"] == COMBINED_BRIDGE_IDENTITY
    assert identity["combined_reviewed_queue_key_list_sha256"] == COMBINED_REVIEWED_IDENTITY
    assert identity["canonical_json_reviewed_key_hashes"]["combined"] == COMBINED_REVIEWED_CANONICAL

    false_credit = {key: False for key in (
        "static_page_feature_ownership", "adjacent_route_ownership", "frontend_caller_ownership",
        "service_model_helper_caller_or_test_ownership", "complete_route_page_feature_crosswalk",
        "framework_route_reachability", "canonical_object_ownership_correctness",
        "approved_site_scope_correctness", "permission_correctness", "privacy_correctness",
        "direct_object_concealment_correctness", "query_projection_correctness", "runtime", "database",
        "build", "application_browser", "responsive_application", "executed_tests",
        "application_source_mutation", "matrix_mutation", "benchmark", "final_no_match_or_NCM",
        "ease", "release", "publication", "pass", "final_finding", "feature_completion", "completion",
        "gate_4", "audit_complete",
    )}
    output: dict[str, Any] = {
        "schema_version": "run-180-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-wave-34-v1",
        "run_id": "RUN-180-REVIEWED-OUTCOME-NEUTRAL-FLEET-TRIP-INDEX-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-34",
        "status": "ONE_REVIEWED_FLEET_TRIP_INDEX_ROUTE_ACTION_OWNER_AND_BRIDGE_INTEGRATED_STATIC_ONLY_DISSENT_PRESERVED",
        "generated_on": "2026-08-30",
        "pins": {
            "checkpoint_commit": HEAD,
            "checkpoint_tree": TREE,
            "checkpoint_parent": PARENT,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "application_subtrees": SUBTREES,
            "governing_prompt": GOVERNING_PROMPT,
            "continuation_request": CONTINUATION_REQUEST,
            "generator": {
                "path": f"{PREFIX}/{GENERATOR}",
                "sha256": audit_sha(GENERATOR),
                "blob_id": git("hash-object", "--", str(AUDIT / GENERATOR)),
                "bytes": (AUDIT / GENERATOR).stat().st_size,
                "lines": len((AUDIT / GENERATOR).read_text(encoding="utf-8").splitlines()),
            },
            "inputs": EXPECTED,
            "input_blobs": EXPECTED_BLOBS,
            "input_map_sha256": canonical(EXPECTED),
            "cohort_self_seal_sha256": COHORT_SEAL,
            "review_self_seal_sha256": REVIEW_SEAL,
            "synthesis_record_sha256": SYNTHESIS_SEAL,
            "decision_record_sha256": DECISION_SEAL,
        },
        "architecture_rule": review["architecture_rule"],
        "baseline": {
            "source_owner_records": 665,
            "route_owner_records": 308,
            "page_owner_records": 357,
            "static_controller_action_bridges": 96,
            "reviewed_queue_surface_rows": 119,
            "pending_unreviewed_queue_surface_rows": 388,
            "latest_integrated_overlay": {"run_id": run170["run_id"], "status": run170["status"]},
            "latest_independent_overlay_review": {
                "run_id": run170r["run_id"],
                "status": run170r["status"],
                "reporting_only_credit": True,
                "new_or_current_or_downstream_credit": False,
            },
        },
        "reviewed_overlay": {
            "producer_run_id": cohort["run_id"],
            "review_run_id": review["run_id"],
            "reviewed_route_actions": 1,
            "owner_route_actions": 1,
            "accepted_source_owner_records": 1,
            "accepted_route_owner_records": 1,
            "accepted_page_owner_records": 0,
            "accepted_controller_action_bridges": 1,
            "accepted_adjacent_route_owners": 0,
            "accepted_model_service_helper_caller_or_test_owners": 0,
            "new_distinct_feature_ids": 0,
            "current_static_overlay_credit_applied": True,
            "correctness_or_downstream_credit_authorized": False,
            "final_finding_credit_authorized": False,
        },
        "reviewer_lineage_and_dissent_preservation": {
            "review_chronology": review["review_chronology"],
            "bounded_expansion": review["bounded_expansion"],
            "accepted_independent_semantic_tiebreak_reviews": tiebreaks,
            "synthesis_review": synthesis,
            "action_decision": decision,
            "original_strict_current_split_preserved": True,
            "original_dissenting_outcome": "EVIDENCE_GAP",
            "preliminary_shared_judgments_recredited": False,
            "excluded_older_bundle_identity_or_credit_imported": False,
        },
        "source_packet_boundary": review["source_packet_boundary"],
        "remediation_and_history_noninheritance": review["remediation_and_history_noninheritance"],
        "combined_counts": counts,
        "queue_accounting": queue_counts,
        "queue_boundary": {
            "preceding_index_83_not_recredited": True,
            "selected_index_84_integrated": True,
            "next_unresolved_index": next_index,
            "next_unresolved_queue_id": next_row["queue_id"],
            "next_unresolved_route_record_id": next_row["source_record_id"],
            "next_unresolved_route_name": next_row["source"]["literal_route_name"],
            "next_unresolved_action_expression": next_row["source"]["action_expression"],
            "next_unresolved_queue_record_sha256": next_row["queue_record_sha256"],
            "reviewed_key_count": len(reviewed_keys),
            "reviewed_key_list_sha256": hlist(reviewed_keys),
            "reviewed_key_list_canonical_json_sha256": canonical(sorted(reviewed_keys)),
        },
        "noninheritance_boundary": {
            "historical_remediation": cohort["remediation_and_history_noninheritance"],
            "historical_route_hash_not_presented_as_current": True,
            "page_caller_service_model_helper_or_test_not_inherited_or_recredited": True,
            "adjacent_route_identity_or_outcome_not_inherited": True,
            "older_2026_08_12_bundle_role": "NON_GOVERNING_EXCLUDED_FROM_IDENTITY_MAPPING_BENCHMARK_AND_CREDIT",
            "older_2026_08_12_feature_identity_imported": False,
            "older_2026_08_12_mapping_or_benchmark_credit_imported": False,
        },
        "overlay_source_records": [row],
        "new_static_controller_action_bridges": [bridge],
        "reviewed_non_owner_outcomes": [],
        "identity": identity,
        "outcome_conservation": {
            "reviewed_outcomes_equation": "1 = 1 owner + 0 shared + 0 evidence gap",
            "bounded_source_equation": "3929 = 666 owner + 3263 non-owner residual",
            "owner_surface_equation": "666 = 309 route + 357 page",
            "feature_union_equation": "256 = 64 route + 242 page - 50 overlap",
            "route_universe_equation": "3218 = 309 owner + 12 shared + 5 alias + 0 dead + 2892 residual",
            "page_universe_equation": "711 = 357 owner + 9 shared + 0 alias + 0 dead + 345 residual",
            "queue_equation": "507 = 120 reviewed + 387 pending",
            "reviewed_queue_equation": "120 = 98 owner + 10 shared + 5 alias + 0 dead + 7 evidence gap",
            "queue_without_ownership_equation": "409 = 387 pending + 10 shared + 5 alias + 0 dead + 7 evidence gap",
        },
        "credit_boundary": {
            "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD": True,
            "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION": True,
            **false_credit,
        },
        "mutation_attestation": {
            "application_source_changed": False,
            "test_files_changed": False,
            "matrix_changed": False,
            "reports_changed": False,
            "dashboard_generator_changed": False,
            "dashboard_html_changed": False,
            "runtime_or_external_system_changed": False,
            "audit_artifacts_only": True,
            "run180_producer_scope_contains_only_generator_and_receipt": True,
            "later_owned_generator_drafts_present_outside_run180_scope": False,
        },
        "completion_boundary": review["completion_boundary"],
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [f"{PREFIX}/{GENERATOR}", f"{PREFIX}/{OUTPUT}"],
    }
    output["self_seal"] = {
        "algorithm": "sha256-canonical-json-with-self-seal-omitted",
        "sha256": canonical(output),
    }
    target = AUDIT / OUTPUT
    temporary = target.with_name(target.name + ".tmp")
    assert not temporary.exists()
    payload = (json.dumps(output, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    temporary.write_bytes(payload)
    os.replace(temporary, target)
    parsed = strict_json(OUTPUT)
    seal = parsed.pop("self_seal")
    assert seal["sha256"] == canonical(parsed)
    assert target.read_bytes() == payload
    assert not temporary.exists()
    assert not git("status", "--porcelain", "--untracked-files=no")
    expected_untracked = {f"{PREFIX}/{GENERATOR}", f"{PREFIX}/{OUTPUT}"}
    actual_untracked = {line[3:] for line in git("status", "--porcelain").splitlines() if line.startswith("?? ")}
    assert actual_untracked == expected_untracked, (actual_untracked, expected_untracked)
    assert not list(AUDIT.rglob("__pycache__"))
    assert not list(AUDIT.rglob("*.tmp"))
    print(json.dumps({
        "status": output["status"],
        "generator_sha256": audit_sha(GENERATOR),
        "receipt_sha256": audit_sha(OUTPUT),
        "self_seal": output["self_seal"]["sha256"],
        "generator_bytes": (AUDIT / GENERATOR).stat().st_size,
        "generator_lines": len((AUDIT / GENERATOR).read_text(encoding="utf-8").splitlines()),
        "receipt_bytes": target.stat().st_size,
        "receipt_lines": len(target.read_text(encoding="utf-8").splitlines()),
        "counts": counts,
        "queue_accounting": queue_counts,
        "identity": identity,
        "overlay_row_sha256": row["overlay_row_sha256"],
        "bridge_row_sha256": bridge["bridge_row_sha256"],
    }, indent=2))


if __name__ == "__main__":
    main()
