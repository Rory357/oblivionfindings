#!/usr/bin/env python3
"""Integrate the independently reviewed RUN-097 route/action overlay.

Twenty-three explicit OWNER_ROUTE_ACTION decisions add 23 bounded route
source-owner records and 23 controller-action bridges to the cumulative
RUN-086 plus RUN-092 ledger. No page owner is added. The matrix and every
framework/runtime/browser/test/benchmark/Pass/finding/completion boundary
remain unchanged.
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
OUTPUT_PATH = (
    AUDIT_DIR
    / "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json"
)

AUDIT_HEAD = "76e03b1d57826e18b0965405279215d56122e7a1"
AUDIT_TREE = "7c00f20aedbcc6d3f091747abc19bd9d831b3aff"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"

INPUT_PATHS = {
    "matrix": AUDIT_DIR / "03-feature-to-benchmark-matrix.csv",
    "baseline": AUDIT_DIR
    / "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json",
    "baseline_review": AUDIT_DIR
    / "evidence/source/raw-run-086r-independent-reviewed-static-route-page-feature-ownership-wave-10.json",
    "queue": AUDIT_DIR
    / "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json",
    "prior_cohort": AUDIT_DIR
    / "evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json",
    "prior_cohort_review": AUDIT_DIR
    / "evidence/source/raw-run-091r-independent-closed-route-action-page-chain-review-wave-11.json",
    "prior_overlay": AUDIT_DIR
    / "evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json",
    "prior_overlay_review": AUDIT_DIR
    / "evidence/source/raw-run-092r-independent-reviewed-static-source-ownership-overlay-wave-11.json",
    "cohort": AUDIT_DIR
    / "evidence/source/root-run-097-route-controller-only-candidate-cohort-wave-12.json",
    "cohort_review": AUDIT_DIR
    / "evidence/source/raw-run-097r-independent-route-controller-only-review-wave-12.json",
}

EXPECTED_INPUT_SHA256 = {
    "matrix": MATRIX_SHA256,
    "baseline": "bfec52a9bed7176045457e8920bdc7b65e1c1d5c5eeb28a266f49c12acf69bcf",
    "baseline_review": "56c4832af941353aaf230ca17c792ea7191c6aebfc05bc1c511a757d5998d699",
    "queue": "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    "prior_cohort": "7b1fea57104e9f4bfea7ee450ef535ac57e7fa69bc978212b4eafb0fe1ed0cae",
    "prior_cohort_review": "fb88ca666bc9f91298ab33fefa1dadbb39a4a612215fca814932f59bfc2f199b",
    "prior_overlay": "5390e55d9e47d1845afa8b3848bdab7687747cbb62c946dd4d66d3c435e8de0b",
    "prior_overlay_review": "1111d30aa24935116c37f27bead824ca1bcca7444157e456d959e821af00669a",
    "cohort": "69981d1bc22d76b8f17834040272260d9b33c151535a3ff2ef17ae4643923933",
    "cohort_review": "125c36710cff83750e3bc2e443955f34b5c019f60b36b874790fce9de9774f0a",
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
        ["git", *args],
        cwd=REPO,
        check=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
    )
    return completed.stdout.strip()


def assert_workspace_and_inputs() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == AUDIT_HEAD
    assert git("rev-parse", "HEAD^{tree}") == AUDIT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "HEAD:app") == APP_TREE
    assert git("rev-parse", "HEAD:routes") == ROUTES_TREE
    assert git("rev-parse", "HEAD:resources/js") == RESOURCES_JS_TREE
    assert git("rev-parse", "HEAD:resources/js/pages") == RESOURCES_JS_PAGES_TREE
    assert git("status", "--porcelain", "--", "app", "routes", "resources/js") == ""
    for name, path in INPUT_PATHS.items():
        assert path.is_file(), path
        assert sha256_file(path) == EXPECTED_INPUT_SHA256[name], (name, sha256_file(path))


def build_overlay_source_records(
    candidates: list[dict[str, Any]], decisions: dict[str, dict[str, Any]]
) -> list[dict[str, Any]]:
    records: list[dict[str, Any]] = []
    for candidate in candidates:
        candidate_id = candidate["candidate_id"]
        decision = decisions[candidate_id]
        assert decision["outcome"] == "OWNER_ROUTE_ACTION"
        source = candidate["route_source"]
        feature = candidate["feature_identity_projection"]
        suffix = candidate_id.rsplit("-", 1)[-1]
        row = {
            "overlay_mapping_id": f"RUN098-ROUTE-{suffix}",
            "candidate_id": candidate_id,
            "candidate_record_sha256": candidate["candidate_record_sha256"],
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
                "framework_route_reachability": False,
                "navigation": False,
                "site_authorization_correctness": False,
                "permission_correctness": False,
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
        }
        row["overlay_row_sha256"] = canonical_json_sha256(row)
        records.append(row)
    records.sort(key=lambda row: row["source_record_id"])
    return records


def build_action_bridges(candidates: list[dict[str, Any]]) -> list[dict[str, Any]]:
    bridges: list[dict[str, Any]] = []
    for candidate in candidates:
        action = candidate["controller_action"]
        suffix = candidate["candidate_id"].rsplit("-", 1)[-1]
        bridge = {
            "bridge_id": f"RUN098-BRIDGE-{suffix}",
            "candidate_id": candidate["candidate_id"],
            "candidate_record_sha256": candidate["candidate_record_sha256"],
            "feature_id": candidate["candidate_feature_id"],
            "route_record_id": candidate["route_source"]["route_record_id"],
            "controller_fqcn": action["controller_fqcn"],
            "controller_file": action["controller_file"],
            "controller_file_sha256": action["controller_file_sha256"],
            "controller_file_blob_id": action["controller_file_blob_id"],
            "method": action["method"],
            "definition_anchor": action["definition_anchor"],
            "method_review_slice_sha256": action["method_review_slice"]["text_sha256"],
            "review_outcome": "OWNER_ROUTE_ACTION",
            "static_controller_action_bridge_credit": True,
            "page_ownership_credit": False,
            "runtime_credit": False,
            "application_browser_credit": False,
            "executed_test_credit": False,
            "completion_credit": False,
        }
        bridge["bridge_row_sha256"] = canonical_json_sha256(bridge)
        bridges.append(bridge)
    bridges.sort(key=lambda row: row["bridge_id"])
    return bridges


def build() -> dict[str, Any]:
    assert_workspace_and_inputs()
    baseline = load_json(INPUT_PATHS["baseline"])
    baseline_review = load_json(INPUT_PATHS["baseline_review"])
    queue = load_json(INPUT_PATHS["queue"])
    prior_cohort = load_json(INPUT_PATHS["prior_cohort"])
    prior_cohort_review = load_json(INPUT_PATHS["prior_cohort_review"])
    prior_overlay = load_json(INPUT_PATHS["prior_overlay"])
    prior_overlay_review = load_json(INPUT_PATHS["prior_overlay_review"])
    cohort = load_json(INPUT_PATHS["cohort"])
    review = load_json(INPUT_PATHS["cohort_review"])

    assert baseline["record_set"]["count"] == 530
    assert baseline_review["decision"]["static_source_feature_ownership_authorized"] is True
    assert prior_cohort_review["decision"]["owner_chains"] == 9
    assert prior_overlay["combined_counts"]["source_owner_records"] == 548
    assert prior_overlay["combined_counts"]["route_owner_records"] == 221
    assert prior_overlay["combined_counts"]["page_owner_records"] == 327
    assert prior_overlay["combined_counts"]["distinct_feature_ids"] == 239
    assert prior_overlay["combined_counts"]["static_controller_action_bridges"] == 9
    assert prior_overlay_review["decision"]["verdict"] == "GO"
    assert (
        prior_overlay_review["decision"][
            "bounded_static_source_feature_ownership_authorized"
        ]
        is True
    )
    assert prior_overlay_review["decision"]["mechanical_discrepancies"] == 0
    assert prior_overlay_review["decision"]["gate_4_complete"] is False
    assert queue["record_set"]["count"] == 507
    assert cohort["counts"]["candidate_route_actions"] == 23
    assert cohort["counts"]["candidate_page_records"] == 0
    assert cohort["counts"]["ownership_credit_awarded"] == 0
    assert review["decision"]["verdict"] == "GO_ALL_23_EXPLICIT_OWNER_ROUTE_ACTION"
    assert review["decision"]["mechanical_discrepancies"] == 0
    assert review["decision"]["owner_route_actions"] == 23
    assert review["decision"]["static_route_owner_records_authorized"] == 23
    assert review["decision"]["static_controller_action_bridges_authorized"] == 23
    assert review["decision"]["static_page_owner_records_authorized"] == 0
    assert review["decision"]["all_23_route_action_overlay_authorized"] is True
    assert review["decision"]["matrix_mutation_authorized"] is False
    assert review["decision"]["gate_4_complete"] is False
    assert review["credit_boundary"]["STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_23_RECORDS"] is True
    assert review["credit_boundary"]["STATIC_CONTROLLER_ACTION_BRIDGE_FOR_23_ACTIONS"] is True
    assert review["credit_boundary"]["STATIC_PAGE_FEATURE_OWNERSHIP"] is False

    candidates = sorted(cohort["records"], key=lambda row: row["candidate_id"])
    decisions = {row["candidate_id"]: row for row in review["action_decisions"]}
    assert len(candidates) == len(decisions) == 23
    assert {row["candidate_id"] for row in candidates} == set(decisions)
    for candidate in candidates:
        decision = decisions[candidate["candidate_id"]]
        assert decision["candidate_record_sha256"] == candidate["candidate_record_sha256"]
        assert decision["partition_id"] == candidate["review_partition"]
        assert decision["queue_id"] == candidate["queue_id"]
        assert decision["route_record_id"] == candidate["route_source"]["route_record_id"]
        assert decision["candidate_feature_id"] == candidate["candidate_feature_id"]
        assert decision["outcome"] == "OWNER_ROUTE_ACTION"

    assert canonical_list_sha256(
        [row["route_source"]["route_record_id"] for row in candidates]
    ) == cohort["identity"]["route_record_id_list_sha256"]
    assert canonical_list_sha256(
        {row["candidate_feature_id"] for row in candidates}
    ) == cohort["identity"]["feature_id_list_sha256"]
    assert canonical_list_sha256(
        [row["action_key"] for row in candidates]
    ) == cohort["identity"]["action_key_list_sha256"]
    assert canonical_list_sha256(
        [row["candidate_record_sha256"] for row in candidates]
    ) == cohort["identity"]["candidate_record_sha256_list_sha256"]

    overlay_records = build_overlay_source_records(candidates, decisions)
    action_bridges = build_action_bridges(candidates)
    assert len(overlay_records) == 23
    assert Counter(row["surface"] for row in overlay_records) == {"ROUTE_SOURCE_RECORD": 23}
    assert len(action_bridges) == 23
    assert len({row["source_record_key"] for row in overlay_records}) == 23

    prior_records = baseline["records"] + prior_overlay["overlay_source_records"]
    prior_keys = {row["source_record_key"] for row in prior_records}
    new_keys = {row["source_record_key"] for row in overlay_records}
    assert len(prior_records) == len(prior_keys) == 548
    assert not (prior_keys & new_keys)
    combined_records = prior_records + overlay_records
    combined_keys = {row["source_record_key"] for row in combined_records}
    assert len(combined_records) == len(combined_keys) == 571

    combined_feature_ids = {row["feature_id"] for row in combined_records}
    route_feature_ids = {
        row["feature_id"]
        for row in combined_records
        if row["surface"] == "ROUTE_SOURCE_RECORD"
    }
    page_feature_ids = {
        row["feature_id"]
        for row in combined_records
        if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"
    }
    overlap_feature_ids = route_feature_ids & page_feature_ids
    feature_class_by_id: dict[str, str] = {}
    for row in combined_records:
        feature_class_by_id.setdefault(row["feature_id"], row["feature_class"])
        assert feature_class_by_id[row["feature_id"]] == row["feature_class"]
    class_counts = Counter(feature_class_by_id.values())

    assert len(combined_feature_ids) == 246
    assert class_counts == {"H": 226, "D": 20}
    assert len(route_feature_ids) == 56
    assert len(page_feature_ids) == 234
    assert len(overlap_feature_ids) == 44
    accepted_feature_ids = {row["candidate_feature_id"] for row in candidates}
    prior_feature_ids = {row["feature_id"] for row in prior_records}
    new_feature_ids = accepted_feature_ids - prior_feature_ids
    assert new_feature_ids == {
        "CAP-DAY-ALL-TASKS-WORKBENCH",
        "CAP-HS-GOVERNANCE-REPORTS-EXPORT",
        "CAP-INT-INBOUND-PROVIDER-WEBHOOK",
        "CAP-INT-SITE-PROVIDER-SYNC",
        "CAP-INT-SITE-RESOURCE-CALENDAR-CONNECTION",
        "CAP-IT-KNOWLEDGE-BASE",
        "CAP-PRIV-EVIDENCE-ATTACHMENTS",
    }

    queue_keys = {row["canonical_key"] for row in queue["records"]}
    prior_reviewed_keys = queue_keys & (
        {f"route|{row['route_source']['route_record_id']}" for row in prior_cohort["records"]}
        | {f"page|{row['page_source']['page_record_id']}" for row in prior_cohort["records"]}
    )
    new_reviewed_keys = {
        f"route|{row['route_source']['route_record_id']}" for row in candidates
    }
    assert len(queue_keys) == 507
    assert len(prior_reviewed_keys) == 12
    assert len(new_reviewed_keys) == 23
    assert new_reviewed_keys <= queue_keys
    assert not (prior_reviewed_keys & new_reviewed_keys)
    assert len(prior_reviewed_keys | new_reviewed_keys) == 35

    identity = {
        "new_route_record_id_list_sha256": canonical_list_sha256(
            [row["source_record_id"] for row in overlay_records]
        ),
        "new_feature_id_list_sha256": canonical_list_sha256(
            {row["feature_id"] for row in overlay_records}
        ),
        "new_source_record_key_list_sha256": canonical_list_sha256(new_keys),
        "new_overlay_source_records_sha256": canonical_json_sha256(overlay_records),
        "new_overlay_row_sha256_list_sha256": canonical_list_sha256(
            [row["overlay_row_sha256"] for row in overlay_records]
        ),
        "new_action_bridges_sha256": canonical_json_sha256(action_bridges),
        "new_action_bridge_row_sha256_list_sha256": canonical_list_sha256(
            [row["bridge_row_sha256"] for row in action_bridges]
        ),
        "combined_source_record_key_list_sha256": canonical_list_sha256(combined_keys),
        "combined_feature_id_list_sha256": canonical_list_sha256(combined_feature_ids),
        "combined_route_feature_id_list_sha256": canonical_list_sha256(route_feature_ids),
        "combined_page_feature_id_list_sha256": canonical_list_sha256(page_feature_ids),
        "combined_route_page_overlap_feature_id_list_sha256": canonical_list_sha256(
            overlap_feature_ids
        ),
    }

    return {
        "schema_version": "1.0.0",
        "run_id": "RUN-098-REVIEWED-ROUTE-CONTROLLER-OWNERSHIP-OVERLAY-WAVE-12",
        "status": "TWENTY_THREE_REVIEWED_ROUTE_ACTION_OWNERS_INTEGRATED_BOUNDED_STATIC_ONLY",
        "generated_on": "2026-08-25",
        "pins": {
            "checkpoint_commit": AUDIT_HEAD,
            "checkpoint_tree": AUDIT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "app_tree": APP_TREE,
            "routes_tree": ROUTES_TREE,
            "resources_js_tree": RESOURCES_JS_TREE,
            "resources_js_pages_tree": RESOURCES_JS_PAGES_TREE,
            "matrix_sha256": MATRIX_SHA256,
            "generator": Path(__file__).relative_to(AUDIT_DIR).as_posix(),
            "generator_sha256": sha256_file(Path(__file__)),
            "inputs": {
                INPUT_PATHS[name].relative_to(AUDIT_DIR).as_posix(): digest
                for name, digest in EXPECTED_INPUT_SHA256.items()
            },
        },
        "architecture_rule": (
            "Oblivion Findings is one operating organisation with multiple Sites. Bounded static route/action "
            "ownership does not establish permission, Site/privacy/lifecycle correctness, runtime behaviour, "
            "or release readiness."
        ),
        "baseline": {
            "run_id": prior_overlay["run_id"],
            "review_run_id": prior_overlay_review["run_id"],
            "source_owner_records": 548,
            "route_owner_records": 221,
            "page_owner_records": 327,
            "distinct_feature_ids": 239,
            "static_controller_action_bridges": 9,
            "ledger_sha256": EXPECTED_INPUT_SHA256["prior_overlay"],
            "review_sha256": EXPECTED_INPUT_SHA256["prior_overlay_review"],
        },
        "reviewed_overlay": {
            "producer_run_id": cohort["run_id"],
            "review_run_id": review["run_id"],
            "reviewed_route_actions": 23,
            "owner_route_actions": 23,
            "shared_relations": 0,
            "accepted_source_owner_records": 23,
            "accepted_route_owner_records": 23,
            "accepted_page_owner_records": 0,
            "accepted_controller_action_bridges": 23,
            "accepted_distinct_feature_ids": 22,
            "new_distinct_feature_ids": 7,
            "new_feature_ids": sorted(new_feature_ids),
            "existing_owned_page_callsites_observed": 9,
            "page_ownership_inherited": False,
            "cohort_sha256": EXPECTED_INPUT_SHA256["cohort"],
            "review_sha256": EXPECTED_INPUT_SHA256["cohort_review"],
        },
        "combined_counts": {
            "source_owner_records": 571,
            "route_owner_records": 244,
            "page_owner_records": 327,
            "distinct_feature_ids": 246,
            "distinct_H_feature_ids": 226,
            "distinct_D_feature_ids": 20,
            "route_distinct_feature_ids": 56,
            "page_distinct_feature_ids": 234,
            "route_page_feature_overlap": 44,
            "static_controller_action_bridges": 32,
            "bounded_static_source_denominator": 3929,
            "bounded_static_source_ownership_percent": "14.532960",
            "bounded_static_source_residual_records": 3358,
            "residual_explicit_unmapped_routes": 2969,
            "semantic_shared_routes": 5,
            "residual_unadjudicated_page_roots": 382,
            "semantic_shared_page_roots": 2,
        },
        "queue_accounting": {
            "direct_exact_queue_records": 507,
            "reviewed_queue_surface_rows": 35,
            "owner_queue_surface_rows": 33,
            "shared_queue_surface_rows": 2,
            "pending_unreviewed_queue_surface_rows": 472,
            "queue_surfaces_without_ownership": 474,
            "new_reviewed_route_surface_rows": 23,
            "new_owner_route_surface_rows": 23,
            "wholesale_queue_ownership_authorized": False,
        },
        "overlay_source_records": overlay_records,
        "new_static_controller_action_bridges": action_bridges,
        "identity": identity,
        "denominator_boundary": {
            "run_077_bounded_static_records": 3929,
            "framework_expanded_route_page_denominator": None,
            "page_denominator_711_roots_vs_963_page_tree_files_resolved": False,
            "complete_route_page_feature_crosswalk": False,
            "gate_4_complete": False,
        },
        "credit_boundary": {
            "STATIC_ROUTE_FEATURE_OWNERSHIP": True,
            "STATIC_CONTROLLER_ACTION_BRIDGE": True,
            "static_page_feature_ownership": False,
            "wholesale_507_queue_ownership": False,
            "complete_route_page_feature_crosswalk": False,
            "framework_route_reachability": False,
            "navigation": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
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
        "mutation_attestation": {
            "application_source_changed": False,
            "matrix_changed": False,
            "runtime_or_external_system_changed": False,
            "audit_artifacts_only": True,
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/"
            "integrate-reviewed-route-controller-only-ownership-overlay-wave-12.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/"
            "current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json",
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
    print(
        json.dumps(
            {
                "status": payload["status"],
                "output": OUTPUT_PATH.relative_to(REPO).as_posix(),
                "sha256": output_sha256,
                "source_owner_records": payload["combined_counts"]["source_owner_records"],
                "route_owner_records": payload["combined_counts"]["route_owner_records"],
                "page_owner_records": payload["combined_counts"]["page_owner_records"],
                "distinct_feature_ids": payload["combined_counts"]["distinct_feature_ids"],
                "controller_action_bridges": payload["combined_counts"][
                    "static_controller_action_bridges"
                ],
                "gate_4_complete": payload["denominator_boundary"]["gate_4_complete"],
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
