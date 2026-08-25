#!/usr/bin/env python3
"""Integrate the independently reviewed RUN-091 owner-chain overlay.

Nine explicit OWNER_CHAIN decisions add 18 bounded route/page source-owner
records and nine static controller-action bridges to the reviewed RUN-086
baseline.  Two multi-feature chains remain shared and receive no ownership.
The matrix and every framework/runtime/browser/test/benchmark/Pass/finding/
completion boundary remain unchanged.
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
    / "evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json"
)

AUDIT_HEAD = "786a2e2f8ab21142d0cb93bd9f5ceb1bf1aa6bb5"
AUDIT_TREE = "a1b32e32ef254a07016990051ed30eb28fdf8b9e"
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
    "cohort": AUDIT_DIR
    / "evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json",
    "cohort_review": AUDIT_DIR
    / "evidence/source/raw-run-091r-independent-closed-route-action-page-chain-review-wave-11.json",
}
EXPECTED_INPUT_SHA256 = {
    "matrix": MATRIX_SHA256,
    "baseline": "bfec52a9bed7176045457e8920bdc7b65e1c1d5c5eeb28a266f49c12acf69bcf",
    "baseline_review": "56c4832af941353aaf230ca17c792ea7191c6aebfc05bc1c511a757d5998d699",
    "queue": "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    "cohort": "7b1fea57104e9f4bfea7ee450ef535ac57e7fa69bc978212b4eafb0fe1ed0cae",
    "cohort_review": "fb88ca666bc9f91298ab33fefa1dadbb39a4a612215fca814932f59bfc2f199b",
}

EXPECTED_OWNER_CHAIN_IDS = {
    "RUN091-CHAIN-01",
    "RUN091-CHAIN-02",
    "RUN091-CHAIN-03",
    "RUN091-CHAIN-04",
    "RUN091-CHAIN-05",
    "RUN091-CHAIN-06",
    "RUN091-CHAIN-07",
    "RUN091-CHAIN-08",
    "RUN091-CHAIN-09",
}
EXPECTED_SHARED_CHAIN_IDS = {"RUN091-CHAIN-10", "RUN091-CHAIN-11"}

EXPECTED_IDENTITY = {
    "owner_chain_key_list_sha256": "70fc80182cc8466362a1d312867c9a24f9329852729c565bea07371f9e3ee585",
    "owner_route_record_id_list_sha256": "f555d6b1031c8d04cdb15869662c7a08838eaffe84fdeaba8dc07471ad105ed8",
    "owner_page_record_id_list_sha256": "486b658651b75a4b87b3e0540ff1925836ee4a63263ce604f2aff71d9a874edc",
    "owner_feature_id_list_sha256": "2f02ff110e037045ce71e141db510dc0793dce9d34e2bcde510793b2b2c8333a",
    "owner_chain_record_sha256_list_sha256": "a83b940f137c2687458e873dc8933391e9e0b781a7f361895f6d205634a68525",
    "shared_chain_key_list_sha256": "64a90e557d61c22b713c71631621ce21b527784d0950935ce8cde2414d4fd0ca",
    "combined_source_record_key_list_sha256": "b2726509349bfddd1489ffcf3f65c015fbbef762a489cb1f55b7552212b93e8e",
    "combined_feature_id_list_sha256": "29be90e5a06cc8aa2d2b9a6de00f0f5fddd5e56bbe225ac8acf7bd62c7a91e91",
    "combined_route_feature_id_list_sha256": "aa0c57648bf9152dda63d695ca04d745632f87054ce69100ca3d27ee94d825e4",
    "combined_page_feature_id_list_sha256": "cb5e00a382b133ab5664ed1889bec77c21e469d2bd0d0c144ce299c67f64070e",
    "combined_route_page_overlap_feature_id_list_sha256": "2300359bddb5e2436dc751b7820164e85cc50b2048f71618d2270ea8cd657928",
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


def build_overlay_source_records(owner_chains: list[dict[str, Any]]) -> list[dict[str, Any]]:
    records: list[dict[str, Any]] = []
    for chain in owner_chains:
        feature_id = chain["candidate_feature_id"]
        for surface, source in (
            ("ROUTE_SOURCE_RECORD", chain["route_source"]),
            ("PAGE_ROOT_SOURCE_RECORD", chain["page_source"]),
        ):
            source_record_id = (
                source["route_record_id"]
                if surface == "ROUTE_SOURCE_RECORD"
                else source["page_record_id"]
            )
            surface_key = "route" if surface == "ROUTE_SOURCE_RECORD" else "page"
            row = {
                "overlay_mapping_id": (
                    f"RUN092-{surface_key.upper()}-{chain['chain_id'].rsplit('-', 1)[-1]}"
                ),
                "chain_id": chain["chain_id"],
                "chain_record_sha256": chain["chain_record_sha256"],
                "surface": surface,
                "source_record_id": source_record_id,
                "source_record_key": f"{surface_key}|{source_record_id}|{feature_id}",
                "feature_id": feature_id,
                "feature_class": chain["feature_identity_projection"]["feature_class"],
                "module": chain["feature_identity_projection"]["module"],
                "user_job": chain["feature_identity_projection"]["user_job"],
                "source": source,
                "review_outcome": "OWNER_CHAIN",
                "static_source_feature_ownership_credit": True,
                "credit_boundary": {
                    "framework_route_reachability": False,
                    "navigation": False,
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
    records.sort(key=lambda row: (row["surface"], row["source_record_id"]))
    return records


def build_action_bridges(owner_chains: list[dict[str, Any]]) -> list[dict[str, Any]]:
    bridges: list[dict[str, Any]] = []
    for chain in owner_chains:
        action = chain["controller_action_bridge"]
        bridge = {
            "bridge_id": f"RUN092-BRIDGE-{chain['chain_id'].rsplit('-', 1)[-1]}",
            "chain_id": chain["chain_id"],
            "feature_id": chain["candidate_feature_id"],
            "route_record_id": chain["route_source"]["route_record_id"],
            "page_record_id": chain["page_source"]["page_record_id"],
            "controller_file": action["controller_file"],
            "controller_file_sha256": action["controller_file_sha256"],
            "controller_file_blob_id": action["controller_file_blob_id"],
            "method": action["method"],
            "definition_anchor": action["definition_anchor"],
            "render_source_anchor": action["render_source_anchor"],
            "method_review_slice_sha256": action["method_review_slice"]["text_sha256"],
            "review_outcome": "OWNER_CHAIN",
            "static_controller_action_bridge_credit": True,
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
    cohort = load_json(INPUT_PATHS["cohort"])
    review = load_json(INPUT_PATHS["cohort_review"])

    assert baseline["record_set"]["count"] == 530
    assert baseline["counts"]["selected"]["route_records"] == 212
    assert baseline["counts"]["selected"]["page_records"] == 318
    assert baseline["counts"]["selected"]["distinct_feature_ids"] == 235
    assert baseline_review["decision"]["static_source_feature_ownership_authorized"] is True
    assert baseline_review["decision"]["gate_4_complete"] is False
    assert queue["record_set"]["count"] == 507
    assert queue["counts"]["ownership_credit_awarded"] == 0
    assert cohort["counts"]["chains"] == 11
    assert cohort["counts"]["ownership_credit_awarded"] == 0
    assert review["decision"]["verdict"] == "GO_WITH_EXPLICIT_NON_OWNER_DECISIONS"
    assert review["decision"]["mechanical_discrepancies"] == 0
    assert review["decision"]["owner_chains"] == 9
    assert review["decision"]["shared_relation_chains"] == 2
    assert review["decision"]["static_source_feature_ownership_records_authorized"] == 18
    assert review["decision"]["static_controller_action_bridges_authorized"] == 9
    assert review["decision"]["all_11_owner_overlay_authorized"] is False
    assert review["decision"]["matrix_mutation_authorized"] is False
    assert review["decision"]["gate_4_complete"] is False
    assert review["credit_boundary"]["STATIC_SOURCE_FEATURE_OWNERSHIP_FOR_18_RECORDS"] is True
    assert review["credit_boundary"]["STATIC_CONTROLLER_ACTION_BRIDGE_FOR_9_ACTIONS"] is True

    cohort_by_id = {row["chain_id"]: row for row in cohort["records"]}
    decisions_by_id = {row["chain_id"]: row for row in review["chain_decisions"]}
    assert set(cohort_by_id) == set(decisions_by_id)
    assert len(cohort_by_id) == 11
    assert all(
        decisions_by_id[chain_id]["chain_record_sha256"] == row["chain_record_sha256"]
        for chain_id, row in cohort_by_id.items()
    )
    owner_ids = {
        chain_id for chain_id, decision in decisions_by_id.items() if decision["outcome"] == "OWNER_CHAIN"
    }
    shared_ids = {
        chain_id
        for chain_id, decision in decisions_by_id.items()
        if decision["outcome"] == "SHARED_RELATION"
    }
    assert owner_ids == EXPECTED_OWNER_CHAIN_IDS
    assert shared_ids == EXPECTED_SHARED_CHAIN_IDS
    assert all(decision["outcome"] in {"OWNER_CHAIN", "SHARED_RELATION"} for decision in decisions_by_id.values())

    owner_chains = sorted((cohort_by_id[value] for value in owner_ids), key=lambda row: row["chain_id"])
    shared_chains = sorted((cohort_by_id[value] for value in shared_ids), key=lambda row: row["chain_id"])
    assert canonical_list_sha256([row["chain_key"] for row in owner_chains]) == EXPECTED_IDENTITY[
        "owner_chain_key_list_sha256"
    ]
    assert canonical_list_sha256(
        [row["route_source"]["route_record_id"] for row in owner_chains]
    ) == EXPECTED_IDENTITY["owner_route_record_id_list_sha256"]
    assert canonical_list_sha256(
        [row["page_source"]["page_record_id"] for row in owner_chains]
    ) == EXPECTED_IDENTITY["owner_page_record_id_list_sha256"]
    assert canonical_list_sha256(
        {row["candidate_feature_id"] for row in owner_chains}
    ) == EXPECTED_IDENTITY["owner_feature_id_list_sha256"]
    assert canonical_list_sha256(
        [row["chain_record_sha256"] for row in owner_chains]
    ) == EXPECTED_IDENTITY["owner_chain_record_sha256_list_sha256"]
    assert canonical_list_sha256([row["chain_key"] for row in shared_chains]) == EXPECTED_IDENTITY[
        "shared_chain_key_list_sha256"
    ]

    overlay_records = build_overlay_source_records(owner_chains)
    action_bridges = build_action_bridges(owner_chains)
    assert len(overlay_records) == 18
    assert Counter(row["surface"] for row in overlay_records) == {
        "ROUTE_SOURCE_RECORD": 9,
        "PAGE_ROOT_SOURCE_RECORD": 9,
    }
    assert len(action_bridges) == 9
    assert len({row["source_record_key"] for row in overlay_records}) == 18
    assert not ({row["source_record_key"] for row in overlay_records} & {row["source_record_key"] for row in baseline["records"]})

    combined_source_keys = [row["source_record_key"] for row in baseline["records"]]
    combined_source_keys += [row["source_record_key"] for row in overlay_records]
    combined_feature_ids = {row["feature_id"] for row in baseline["records"]}
    combined_feature_ids.update(row["feature_id"] for row in overlay_records)
    route_feature_ids = {
        row["feature_id"] for row in baseline["records"] if row["surface"] == "ROUTE_SOURCE_RECORD"
    }
    route_feature_ids.update(row["feature_id"] for row in overlay_records if row["surface"] == "ROUTE_SOURCE_RECORD")
    page_feature_ids = {
        row["feature_id"] for row in baseline["records"] if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"
    }
    page_feature_ids.update(row["feature_id"] for row in overlay_records if row["surface"] == "PAGE_ROOT_SOURCE_RECORD")
    overlap_feature_ids = route_feature_ids & page_feature_ids

    assert len(combined_source_keys) == 548
    assert len(set(combined_source_keys)) == 548
    assert len(combined_feature_ids) == 239
    assert len(route_feature_ids) == 36
    assert len(page_feature_ids) == 234
    assert len(overlap_feature_ids) == 31
    assert canonical_list_sha256(combined_source_keys) == EXPECTED_IDENTITY[
        "combined_source_record_key_list_sha256"
    ]
    assert canonical_list_sha256(combined_feature_ids) == EXPECTED_IDENTITY[
        "combined_feature_id_list_sha256"
    ]
    assert canonical_list_sha256(route_feature_ids) == EXPECTED_IDENTITY[
        "combined_route_feature_id_list_sha256"
    ]
    assert canonical_list_sha256(page_feature_ids) == EXPECTED_IDENTITY[
        "combined_page_feature_id_list_sha256"
    ]
    assert canonical_list_sha256(overlap_feature_ids) == EXPECTED_IDENTITY[
        "combined_route_page_overlap_feature_id_list_sha256"
    ]
    assert Counter(
        row["feature_class"]
        for row in baseline["feature_summaries"]
        if row["feature_id"] in combined_feature_ids
    )["D"] == 16
    assert len([value for value in combined_feature_ids if value.startswith("CAP-")]) == 239

    queue_keys = {row["canonical_key"] for row in queue["records"]}
    reviewed_route_keys = {f"route|{row['route_source']['route_record_id']}" for row in cohort["records"]}
    reviewed_page_keys = {f"page|{row['page_source']['page_record_id']}" for row in cohort["records"]}
    reviewed_queue_keys = queue_keys & (reviewed_route_keys | reviewed_page_keys)
    assert len(reviewed_queue_keys) == 12
    owner_queue_keys = queue_keys & (
        {f"route|{row['route_source']['route_record_id']}" for row in owner_chains}
        | {f"page|{row['page_source']['page_record_id']}" for row in owner_chains}
    )
    shared_queue_keys = reviewed_queue_keys - owner_queue_keys
    assert (len(owner_queue_keys), len(shared_queue_keys), len(queue_keys - reviewed_queue_keys)) == (
        10,
        2,
        495,
    )

    accepted_feature_ids = {row["candidate_feature_id"] for row in owner_chains}
    baseline_feature_ids = {row["feature_id"] for row in baseline["feature_summaries"]}
    new_feature_ids = accepted_feature_ids - baseline_feature_ids
    assert new_feature_ids == {
        "CAP-COMP-EXCEPTION-COMMAND-CENTRE",
        "CAP-FIN-ACCOUNTING-INTEGRATION-CONFIGURATION",
        "CAP-FLEET-RESIDENT-TRACKING",
        "CAP-INT-SITE-PROVIDER-CONNECTION",
    }

    return {
        "schema_version": "1.0.0",
        "run_id": "RUN-092-REVIEWED-STATIC-SOURCE-OWNERSHIP-OVERLAY-WAVE-11",
        "status": "NINE_REVIEWED_OWNER_CHAINS_INTEGRATED_BOUNDED_STATIC_OWNERSHIP_ONLY",
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
            "Oblivion Findings is one operating organisation with multiple Sites. Bounded static ownership "
            "does not establish permission, Site/privacy correctness, runtime behaviour, or release readiness."
        ),
        "baseline": {
            "run_id": baseline["run_id"],
            "review_run_id": baseline_review["run_id"],
            "source_owner_records": 530,
            "route_owner_records": 212,
            "page_owner_records": 318,
            "distinct_feature_ids": 235,
            "ledger_sha256": EXPECTED_INPUT_SHA256["baseline"],
            "review_sha256": EXPECTED_INPUT_SHA256["baseline_review"],
        },
        "reviewed_overlay": {
            "producer_run_id": cohort["run_id"],
            "review_run_id": review["run_id"],
            "reviewed_chains": 11,
            "owner_chains": 9,
            "shared_relation_chains": 2,
            "accepted_source_owner_records": 18,
            "accepted_route_owner_records": 9,
            "accepted_page_owner_records": 9,
            "accepted_controller_action_bridges": 9,
            "accepted_distinct_feature_ids": 8,
            "new_distinct_feature_ids": 4,
            "new_feature_ids": sorted(new_feature_ids),
            "cohort_sha256": EXPECTED_INPUT_SHA256["cohort"],
            "review_sha256": EXPECTED_INPUT_SHA256["cohort_review"],
        },
        "combined_counts": {
            "source_owner_records": 548,
            "route_owner_records": 221,
            "page_owner_records": 327,
            "distinct_feature_ids": 239,
            "distinct_H_feature_ids": 223,
            "distinct_D_feature_ids": 16,
            "route_distinct_feature_ids": 36,
            "page_distinct_feature_ids": 234,
            "route_page_feature_overlap": 31,
            "static_controller_action_bridges": 9,
            "bounded_static_source_denominator": 3929,
            "bounded_static_source_ownership_percent": "13.947569",
            "bounded_static_source_residual_records": 3381,
            "residual_explicit_unmapped_routes": 2992,
            "semantic_shared_routes": 5,
            "residual_unadjudicated_page_roots": 382,
            "semantic_shared_page_roots": 2,
        },
        "queue_accounting": {
            "direct_exact_queue_records": 507,
            "reviewed_queue_surface_rows": 12,
            "owner_queue_surface_rows": 10,
            "shared_queue_surface_rows": 2,
            "pending_unreviewed_queue_surface_rows": 495,
            "queue_surfaces_without_ownership": 497,
            "auxiliary_chain_page_rows_reviewed": 10,
            "wholesale_queue_ownership_authorized": False,
        },
        "overlay_source_records": overlay_records,
        "static_controller_action_bridges": action_bridges,
        "shared_relation_chains": [
            {
                "chain_id": chain["chain_id"],
                "chain_key": chain["chain_key"],
                "chain_record_sha256": chain["chain_record_sha256"],
                "candidate_feature_id": chain["candidate_feature_id"],
                "route_record_id": chain["route_source"]["route_record_id"],
                "page_record_id": chain["page_source"]["page_record_id"],
                "review_outcome": "SHARED_RELATION",
                "review_rationale": decisions_by_id[chain["chain_id"]]["rationale"],
                "static_source_feature_ownership_credit": False,
            }
            for chain in shared_chains
        ],
        "identity": {
            **EXPECTED_IDENTITY,
            "overlay_source_records_sha256": canonical_json_sha256(overlay_records),
            "overlay_row_sha256_list_sha256": canonical_list_sha256(
                [row["overlay_row_sha256"] for row in overlay_records]
            ),
            "action_bridges_sha256": canonical_json_sha256(action_bridges),
            "action_bridge_row_sha256_list_sha256": canonical_list_sha256(
                [row["bridge_row_sha256"] for row in action_bridges]
            ),
        },
        "denominator_boundary": {
            "run_077_bounded_static_records": 3929,
            "framework_expanded_route_page_denominator": None,
            "page_denominator_711_roots_vs_963_page_tree_files_resolved": False,
            "complete_route_page_feature_crosswalk": False,
            "gate_4_complete": False,
        },
        "credit_boundary": {
            "STATIC_SOURCE_FEATURE_OWNERSHIP": True,
            "STATIC_CONTROLLER_ACTION_BRIDGE": True,
            "two_shared_relations_as_one_to_one_ownership": False,
            "wholesale_507_queue_ownership": False,
            "complete_route_page_feature_crosswalk": False,
            "framework_route_reachability": False,
            "navigation": False,
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
            "integrate-reviewed-static-source-ownership-overlay-wave-11.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/"
            "current-run-092-reviewed-static-source-ownership-overlay-wave-11.json",
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
                "shared_relation_chains": payload["reviewed_overlay"]["shared_relation_chains"],
                "gate_4_complete": payload["denominator_boundary"]["gate_4_complete"],
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
