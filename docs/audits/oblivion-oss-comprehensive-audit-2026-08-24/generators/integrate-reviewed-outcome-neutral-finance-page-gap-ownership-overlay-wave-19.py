#!/usr/bin/env python3
"""Integrate RUN-125R's four reviewed Finance page-owner decisions.

The overlay adds three Chart-of-Accounts pages and one Manual-Journal page.
The journal page's semantic feature correction does not resolve its parent
route evidence gap or mutate the canonical matrix. No route, bridge, queue, or
downstream readiness credit is created.
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
OUTPUT_PATH = AUDIT_DIR / "evidence/source/current-run-126-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json"

AUDIT_HEAD = "dcd5c9185729f3b824125220bad3c0f2b3688116"
AUDIT_TREE = "c06e43135e99bc2ee1b49ca86c98295dc8645e05"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
COHORT_GENERATOR_SHA256 = "e27ba0b1c7cc4e0fdeeea67272efe628700e9b70dffdc9ef3210b449c7d2ca84"
REVIEW_MATERIALIZER_SHA256 = "4ea69659b9994458ad9993a3af65092362ceaf2c67af672b3ce962b40c60ef98"

INPUT_SHA256 = {
    "03-feature-to-benchmark-matrix.csv": "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390",
    "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json": "bfec52a9bed7176045457e8920bdc7b65e1c1d5c5eeb28a266f49c12acf69bcf",
    "evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json": "5390e55d9e47d1845afa8b3848bdab7687747cbb62c946dd4d66d3c435e8de0b",
    "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json": "7f76c9cfeae906a64c92013fbf9a12cf180d39a315bfa13e778b6332365cdd2a",
    "evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json": "cf68900351832c790ec53b4996daaf640c604c498f902c85dec681113bf492dd",
    "evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json": "9c1600d2365c3527e185f313b7cb1586705f39cefb15d5d212dc708b45b630ea",
    "evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json": "9375fb374b589c5068334795fa03d80a7ba1fd35695808f99c9e2591e886efca",
    "evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json": "cbeb46be682ca0c9ca54012e2c27cf548b54e9cbb75b050fcd61691ca43aaef2",
    "evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json": "33dd2f8ffa2b35c6651a6bf18923872e70ba5c2f91fce8b7222666bb8a91fc8b",
    "evidence/source/current-run-122-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json": "d7aee21e7c4230b44707a22b7fa93478a84e9a5b4775ecd25aaffede764855ca",
    "evidence/source/raw-run-122r-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json": "2130e3801b6ac163580bc56f23d6647136c83fdadc8ea65804b1559d36b29484",
    "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json": "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    "evidence/source/root-run-125-outcome-neutral-finance-page-gap-cohort-wave-19.json": "7d0df6edfacb63a9a7ab64140d47b2570a617db0147e4b0be6d5317fe38e3d92",
    "evidence/source/raw-run-125r-independent-outcome-neutral-finance-page-gap-review-wave-19.json": "b26d70eeee965d7dcbbf8e3e439f54bd35b5ab7fa1dfbf7a26c278cc59bb6c73",
}

BASELINE_RELATIVE = "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json"
OVERLAY_RELATIVES = [
    "evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json",
    "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json",
    "evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json",
    "evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json",
    "evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json",
    "evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json",
    "evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json",
    "evidence/source/current-run-122-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json",
]
CURRENT_OVERLAY_RELATIVE = OVERLAY_RELATIVES[-1]
CURRENT_OVERLAY_REVIEW_RELATIVE = "evidence/source/raw-run-122r-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json"
QUEUE_RELATIVE = "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json"
COHORT_RELATIVE = "evidence/source/root-run-125-outcome-neutral-finance-page-gap-cohort-wave-19.json"
REVIEW_RELATIVE = "evidence/source/raw-run-125r-independent-outcome-neutral-finance-page-gap-review-wave-19.json"

EXPECTED_IDENTITY = {
    "owner_candidate_id_list_sha256": "ff4c1c639cc7519bdea3ef29ea4ff6592d0f8acc293bc07c4048f78b124262e7",
    "owner_page_record_id_list_sha256": "a2d51602fc0e86ea02dd81d71d8b47243e7fbbd69739b6b0d081110b795cb926",
    "owner_page_feature_key_list_sha256": "5605a9cec727f1f06ea338d0799ea84400f7938a834adab19a599c78f6214e44",
    "owner_candidate_record_sha256_list_sha256": "5a2e3b660231cddf054428b35f38c0ce98474fe74bd351402d7c30169398f73b",
    "owner_decision_record_sha256_list_sha256": "1a0b684ef085d31e3e10bff57ec428f4be84d1e2f472d7c2381725b19249bc1d",
    "owner_feature_id_list_sha256": "eb8954fc7dfc8edb61cadda15de2d93869f4f84dd81193cb33aa8c014aa21de4",
    "new_union_feature_id_list_sha256": "4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945",
    "new_page_feature_id_list_sha256": "4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945",
    "new_source_record_key_list_sha256": "4f59519a403a40b3756f9d796334263c7bba0eebb20bf0650476779eb8db6524",
    "combined_source_record_key_list_sha256": "2e75ad7cb4b5196ad7b2b2ffaa09a7e896410850b730fdc2e8fc8b709c4012d6",
    "combined_source_record_id_list_sha256": "0293c692153094f9850664de028589723d28dfafc831bfa61fd52a147198c97d",
    "combined_feature_id_list_sha256": "2cb0227da903585ea6568e3344c98581ef963687f58e11e071439843842d121c",
    "combined_route_feature_id_list_sha256": "64595c8044120aa86ae8425c5ac789f5a63f2b459c9b38d2e43a6c8b5ff5b1c6",
    "combined_page_feature_id_list_sha256": "e594dc7ea90d15e2e266bd3a5e655cc10b90521910c25608947b743c81d02b2c",
    "combined_route_page_overlap_feature_id_list_sha256": "8e7f778e7448d773194e3d751dcfdc089fd3611b85fd4f910c61e0997cecf811",
    "parent_candidate_id_list_sha256": "84b9d3db82af0fc132562359e2d58f60ba1e287b8615244b63d5a856adf5533b",
    "parent_route_record_id_list_sha256": "5a5712f6822b93b1c6163cbe30a727e0f16561a9b22e39c948d6fd2c36aaef66",
    "parent_queue_id_list_sha256": "80f39ca25595aeabd3b10e3b242c116fe362346ff0a91d3937a6120e584b7543",
    "render_anchor_list_sha256": "ffe44d1d3fc25d3bcb7501818a5d0cebe3cb0e0e3de8cfb1424c8e29eb0e37b2",
    "new_overlay_source_records_sha256": "585d5a3c07e8ef192ec2e5462ae0714ab7a0ccadf5617a470d2892700c7dc4ae",
    "new_overlay_row_sha256_list_sha256": "61b27d53c80cccc490cb11b7c8e589b2ed0c49733d4be1177a47b967a8016700",
}


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def canonical_json_sha256(value: Any) -> str:
    raw = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(raw).hexdigest()


def canonical_list_sha256(values: list[str] | set[str]) -> str:
    return canonical_json_sha256(sorted(values))


def load(relative: str) -> dict[str, Any]:
    value = json.loads((AUDIT_DIR / relative).read_text(encoding="utf-8"))
    assert isinstance(value, dict)
    return value


def git(*args: str) -> str:
    return subprocess.run(["git", *args], cwd=REPO, check=True, capture_output=True, text=True, encoding="utf-8").stdout.strip()


def assert_workspace_and_inputs(cohort: dict[str, Any], review: dict[str, Any]) -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == AUDIT_HEAD
    assert git("rev-parse", "HEAD^{tree}") == AUDIT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "HEAD:app") == APP_TREE
    assert git("rev-parse", "HEAD:routes") == ROUTES_TREE
    assert git("rev-parse", "HEAD:resources/js") == RESOURCES_JS_TREE
    assert git("rev-parse", "HEAD:resources/js/pages") == RESOURCES_JS_PAGES_TREE
    assert git("status", "--porcelain", "--", "app", "routes", "resources/js") == ""
    for relative, digest in INPUT_SHA256.items():
        assert sha256_file(AUDIT_DIR / relative) == digest, relative
    assert sha256_file(AUDIT_DIR / cohort["pins"]["generator"]) == COHORT_GENERATOR_SHA256
    assert cohort["pins"]["generator_sha256"] == COHORT_GENERATOR_SHA256
    assert sha256_file(AUDIT_DIR / review["pins"]["materializer"]) == REVIEW_MATERIALIZER_SHA256
    assert review["pins"]["materializer_sha256"] == REVIEW_MATERIALIZER_SHA256


def build_overlay_records(candidates: list[dict[str, Any]], decisions: dict[str, dict[str, Any]]) -> list[dict[str, Any]]:
    rows = []
    for index, candidate in enumerate(candidates, 1):
        decision = decisions[candidate["candidate_id"]]
        page = candidate["page_source"]
        feature = candidate["feature_identity_projection"]
        parent = candidate["reviewed_parent_action_provenance"]
        row = {
            "overlay_mapping_id": f"RUN126-PAGE-{index:02d}",
            "candidate_id": candidate["candidate_id"],
            "candidate_record_sha256": candidate["candidate_record_sha256"],
            "decision_record_sha256": decision["decision_record_sha256"],
            "surface": "PAGE_ROOT_SOURCE_RECORD",
            "source_record_id": page["page_record_id"],
            "source_record_key": f"page|{page['page_record_id']}|{candidate['candidate_feature_id']}",
            "feature_id": candidate["candidate_feature_id"],
            "feature_class": feature["feature_class"],
            "module": feature["module"],
            "user_job": feature["user_job"],
            "source": page,
            "parent_candidate_id": parent["parent_candidate_id"],
            "parent_route_record_id": parent["route_record_id"],
            "parent_queue_id": parent["queue_id"],
            "parent_projected_feature_id": parent["parent_projected_feature_id"],
            "parent_outcome": parent["parent_outcome"],
            "semantic_feature_differs_from_parent_projection": parent["semantic_feature_differs_from_parent_projection"],
            "render_source_anchor": parent["selected_render_callsite"]["source_anchor"],
            "review_outcome": "OWNER_PAGE",
            "review_rationale": decision["rationale"],
            "static_readiness_risks": decision["static_readiness_risks"],
            "static_source_feature_ownership_credit": True,
            "credit_boundary": {
                "route_ownership": False,
                "controller_action_bridge": False,
                "direct_exact_queue_review": False,
                "matrix_mutation": False,
                "framework_route_reachability": False,
                "navigation": False,
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
        }
        row["overlay_row_sha256"] = canonical_json_sha256(row)
        rows.append(row)
    return sorted(rows, key=lambda row: row["source_record_id"])


def build() -> dict[str, Any]:
    cohort = load(COHORT_RELATIVE)
    review = load(REVIEW_RELATIVE)
    current = load(CURRENT_OVERLAY_RELATIVE)
    current_review = load(CURRENT_OVERLAY_REVIEW_RELATIVE)
    baseline = load(BASELINE_RELATIVE)
    overlays = [load(relative) for relative in OVERLAY_RELATIVES]
    queue = load(QUEUE_RELATIVE)
    assert_workspace_and_inputs(cohort, review)

    assert current_review["decision"]["verdict"] == "GO"
    assert current["combined_counts"]["source_owner_records"] == 648
    assert current["combined_counts"]["route_owner_records"] == 295
    assert current["combined_counts"]["page_owner_records"] == 353
    assert current["combined_counts"]["distinct_feature_ids"] == 256
    assert current["combined_counts"]["static_controller_action_bridges"] == 83
    assert review["decision"]["verdict"] == "GO_4_EXPLICIT_OWNER_PAGE"
    assert review["decision"]["owner_pages"] == review["decision"]["static_page_owner_records_authorized"] == 4
    assert review["decision"]["static_route_owner_records_authorized"] == 0
    assert review["decision"]["static_controller_action_bridges_authorized"] == 0
    assert review["decision"]["owner_only_overlay_authorized"] is True
    assert review["decision"]["matrix_mutation_authorized"] is False
    assert review["credit_boundary"]["page_ownership"] is False

    candidates = sorted(cohort["records"], key=lambda row: row["candidate_id"])
    decisions = {row["candidate_id"]: row for row in review["page_decisions"]}
    assert len(candidates) == len(decisions) == 4
    for candidate in candidates:
        decision = decisions[candidate["candidate_id"]]
        without_digest = {key: value for key, value in decision.items() if key != "decision_record_sha256"}
        assert decision["decision_record_sha256"] == canonical_json_sha256(without_digest)
        assert decision["outcome"] == "OWNER_PAGE"
        assert decision["page_ownership_authorized"] is True
        assert decision["route_ownership_authorized"] is False
        assert decision["controller_action_bridge_authorized"] is False
        assert decision["downstream_credit_authorized"] is False
        assert decision["candidate_record_sha256"] == candidate["candidate_record_sha256"]
        assert decision["page_record_id"] == candidate["page_source"]["page_record_id"]
        assert decision["candidate_feature_id"] == candidate["candidate_feature_id"]

    new_records = build_overlay_records(candidates, decisions)
    assert len(new_records) == 4
    assert Counter(row["feature_id"] for row in new_records) == {
        "CAP-FIN-CHART-OF-ACCOUNTS": 3,
        "CAP-FIN-MANUAL-JOURNAL-LIFECYCLE": 1,
    }

    prior_records = list(baseline["records"])
    for overlay in overlays:
        prior_records.extend(overlay["overlay_source_records"])
    prior_ids = {row["source_record_id"] for row in prior_records}
    prior_keys = {row["source_record_key"] for row in prior_records}
    prior_page_ids = {row["source_record_id"] for row in prior_records if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"}
    assert len(prior_records) == len(prior_ids) == len(prior_keys) == 648
    assert len(prior_page_ids) == 353
    new_ids = {row["source_record_id"] for row in new_records}
    new_keys = {row["source_record_key"] for row in new_records}
    assert len(new_ids) == len(new_keys) == 4
    assert not (prior_ids & new_ids)
    assert not (prior_keys & new_keys)

    accepted_parent_ids = {row["candidate_id"] for row in current["overlay_source_records"]}
    non_owner_by_id = {row["candidate_id"]: row for row in current["reviewed_non_owner_outcomes"]}
    account_parents = {row["parent_candidate_id"] for row in new_records if row["feature_id"] == "CAP-FIN-CHART-OF-ACCOUNTS"}
    journal_row = next(row for row in new_records if row["feature_id"] == "CAP-FIN-MANUAL-JOURNAL-LIFECYCLE")
    assert account_parents <= accepted_parent_ids
    assert journal_row["parent_candidate_id"] not in accepted_parent_ids
    assert non_owner_by_id[journal_row["parent_candidate_id"]]["outcome"] == "EVIDENCE_GAP"
    assert journal_row["parent_outcome"] == "EVIDENCE_GAP"
    assert journal_row["parent_projected_feature_id"] == "CAP-FIN-CHART-OF-ACCOUNTS"
    assert journal_row["semantic_feature_differs_from_parent_projection"] is True

    queue_ids = {row["queue_id"] for row in queue["records"]}
    queue_keys = {row["canonical_key"] for row in queue["records"]}
    assert {row["parent_queue_id"] for row in new_records} <= queue_ids
    assert not ({f"page|{page_id}" for page_id in new_ids} & queue_keys)

    combined_records = prior_records + new_records
    combined_ids = {row["source_record_id"] for row in combined_records}
    combined_keys = {row["source_record_key"] for row in combined_records}
    feature_ids = {row["feature_id"] for row in combined_records}
    route_features = {row["feature_id"] for row in combined_records if row["surface"] == "ROUTE_SOURCE_RECORD"}
    page_features = {row["feature_id"] for row in combined_records if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"}
    overlap_features = route_features & page_features
    assert len(combined_records) == len(combined_ids) == len(combined_keys) == 652
    assert len(feature_ids) == 256
    assert len(route_features) == 62
    assert len(page_features) == 242
    assert len(overlap_features) == 48
    class_by_feature: dict[str, str] = {}
    for row in combined_records:
        class_by_feature.setdefault(row["feature_id"], row["feature_class"])
        assert class_by_feature[row["feature_id"]] == row["feature_class"]
    assert Counter(class_by_feature.values()) == {"H": 234, "D": 22}
    owner_features = {row["feature_id"] for row in new_records}
    prior_features = {row["feature_id"] for row in prior_records}
    prior_page_features = {row["feature_id"] for row in prior_records if row["surface"] == "PAGE_ROOT_SOURCE_RECORD"}
    assert owner_features <= prior_features
    assert owner_features <= prior_page_features

    identity = {
        "owner_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in candidates]),
        "owner_page_record_id_list_sha256": canonical_list_sha256(new_ids),
        "owner_page_feature_key_list_sha256": canonical_list_sha256([row["page_feature_key"] for row in candidates]),
        "owner_candidate_record_sha256_list_sha256": canonical_list_sha256([row["candidate_record_sha256"] for row in candidates]),
        "owner_decision_record_sha256_list_sha256": canonical_list_sha256([decisions[row["candidate_id"]]["decision_record_sha256"] for row in candidates]),
        "owner_feature_id_list_sha256": canonical_list_sha256(owner_features),
        "new_union_feature_id_list_sha256": canonical_list_sha256(owner_features - prior_features),
        "new_page_feature_id_list_sha256": canonical_list_sha256(owner_features - prior_page_features),
        "new_source_record_key_list_sha256": canonical_list_sha256(new_keys),
        "combined_source_record_key_list_sha256": canonical_list_sha256(combined_keys),
        "combined_source_record_id_list_sha256": canonical_list_sha256(combined_ids),
        "combined_feature_id_list_sha256": canonical_list_sha256(feature_ids),
        "combined_route_feature_id_list_sha256": canonical_list_sha256(route_features),
        "combined_page_feature_id_list_sha256": canonical_list_sha256(page_features),
        "combined_route_page_overlap_feature_id_list_sha256": canonical_list_sha256(overlap_features),
        "parent_candidate_id_list_sha256": canonical_list_sha256({row["parent_candidate_id"] for row in new_records}),
        "parent_route_record_id_list_sha256": canonical_list_sha256({row["parent_route_record_id"] for row in new_records}),
        "parent_queue_id_list_sha256": canonical_list_sha256({row["parent_queue_id"] for row in new_records}),
        "render_anchor_list_sha256": canonical_list_sha256([row["render_source_anchor"] for row in new_records]),
        "new_overlay_source_records_sha256": canonical_json_sha256(new_records),
        "new_overlay_row_sha256_list_sha256": canonical_list_sha256([row["overlay_row_sha256"] for row in new_records]),
    }
    if EXPECTED_IDENTITY:
        assert identity == EXPECTED_IDENTITY

    ownership_percent = (Decimal(652) * Decimal(100) / Decimal(3929)).quantize(Decimal("0.000001"), rounding=ROUND_HALF_UP)
    assert str(ownership_percent) == "16.594553"
    queue_accounting = dict(current["queue_accounting"])
    queue_accounting.update({
        "new_reviewed_route_surface_rows": 0,
        "new_owner_route_surface_rows": 0,
        "new_shared_route_surface_rows": 0,
        "new_alias_route_surface_rows": 0,
        "new_evidence_gap_route_surface_rows": 0,
        "new_reviewed_page_surface_rows": 0,
        "new_owner_page_surface_rows": 0,
        "wholesale_queue_ownership_authorized": False,
    })
    assert queue_accounting["queue_surfaces_without_ownership"] == 423

    return {
        "schema_version": "1.0.0",
        "run_id": "RUN-126-REVIEWED-OUTCOME-NEUTRAL-FINANCE-PAGE-GAP-OWNERSHIP-OVERLAY-WAVE-19",
        "status": "FOUR_REVIEWED_FINANCE_PAGE_OWNERS_INTEGRATED_BOUNDED_STATIC_ONLY",
        "generated_on": "2026-08-26",
        "pins": {
            "checkpoint_commit": AUDIT_HEAD,
            "checkpoint_tree": AUDIT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "app_tree": APP_TREE,
            "routes_tree": ROUTES_TREE,
            "resources_js_tree": RESOURCES_JS_TREE,
            "resources_js_pages_tree": RESOURCES_JS_PAGES_TREE,
            "matrix_sha256": INPUT_SHA256["03-feature-to-benchmark-matrix.csv"],
            "generator": Path(__file__).relative_to(AUDIT_DIR).as_posix(),
            "generator_sha256": sha256_file(Path(__file__)),
            "cohort_generator": cohort["pins"]["generator"],
            "cohort_generator_sha256": COHORT_GENERATOR_SHA256,
            "review_materializer": review["pins"]["materializer"],
            "review_materializer_sha256": REVIEW_MATERIALIZER_SHA256,
            "inputs": INPUT_SHA256,
        },
        "architecture_rule": "Oblivion Findings is one operating organisation across multiple Sites. Bounded static page ownership does not establish Site access, roles or permissions, canonical record ownership, direct-object concealment, privacy, ledger correctness, runtime, or release readiness.",
        "baseline": {
            "run_id": current["run_id"],
            "review_run_id": current_review["run_id"],
            "source_owner_records": 648,
            "route_owner_records": 295,
            "page_owner_records": 353,
            "distinct_feature_ids": 256,
            "static_controller_action_bridges": 83,
            "ledger_sha256": INPUT_SHA256[CURRENT_OVERLAY_RELATIVE],
            "review_sha256": INPUT_SHA256[CURRENT_OVERLAY_REVIEW_RELATIVE],
        },
        "reviewed_overlay": {
            "producer_run_id": cohort["run_id"],
            "review_run_id": review["run_id"],
            "reviewed_pages": 4,
            "owner_pages": 4,
            "shared_relations": 0,
            "alias_or_redirect": 0,
            "dead_or_noncanonical": 0,
            "evidence_gaps": 0,
            "accepted_source_owner_records": 4,
            "accepted_route_owner_records": 0,
            "accepted_page_owner_records": 4,
            "accepted_controller_action_bridges": 0,
            "accepted_distinct_feature_ids": 2,
            "new_distinct_feature_ids": 0,
            "new_feature_ids": [],
            "new_page_feature_ids": [],
            "direct_queue_rows_reconciled": 0,
            "route_ownership_inherited": False,
            "journal_route_gap_resolved": False,
            "matrix_mutation": False,
            "cohort_sha256": INPUT_SHA256[COHORT_RELATIVE],
            "review_sha256": INPUT_SHA256[REVIEW_RELATIVE],
        },
        "combined_counts": {
            "source_owner_records": 652,
            "route_owner_records": 295,
            "page_owner_records": 357,
            "distinct_feature_ids": 256,
            "distinct_H_feature_ids": 234,
            "distinct_D_feature_ids": 22,
            "route_distinct_feature_ids": 62,
            "page_distinct_feature_ids": 242,
            "route_page_feature_overlap": 48,
            "static_controller_action_bridges": 83,
            "bounded_static_source_denominator": 3929,
            "bounded_static_source_ownership_percent": "16.594553",
            "bounded_static_source_residual_records": 3277,
            "residual_explicit_unmapped_routes": 2906,
            "semantic_shared_routes": 12,
            "reviewed_alias_routes": 5,
            "reviewed_dead_routes": 0,
            "evidence_gap_routes_tagged_within_residual": 7,
            "residual_unadjudicated_page_roots": 345,
            "semantic_shared_page_roots": 9,
            "reviewed_alias_page_roots": 0,
            "reviewed_dead_page_roots": 0,
            "evidence_gap_page_roots_tagged_within_residual": 1,
        },
        "queue_accounting": queue_accounting,
        "page_context_boundary": {
            "run_121_literal_page_callsites": 6,
            "already_owned_before_run_125": 2,
            "reviewed_unowned_page_roots": 4,
            "new_page_owner_records": 4,
            "remaining_unowned_from_run_121_context": 0,
            "journal_page_feature_repaired": True,
            "journal_parent_route_evidence_gap_preserved": True,
            "page_review_does_not_mutate_matrix_or_route_outcomes": True,
        },
        "overlay_source_records": new_records,
        "new_static_controller_action_bridges": [],
        "reviewed_non_owner_outcomes": current["reviewed_non_owner_outcomes"],
        "identity": identity,
        "outcome_conservation": {
            **review["outcome_conservation"],
            "bounded_source_equation": "3929 = 652 owner + 3277 non-owner residual",
            "owner_surface_equation": "652 = 295 route + 357 page",
            "route_universe_equation": "3218 = 295 owner + 12 shared + 5 alias + 0 dead + 2906 residual; seven evidence gaps are tagged inside residual",
            "page_universe_equation": "711 = 357 owner + 9 shared + 0 alias + 0 dead + 345 residual",
            "existing_evidence_gap_is_tagged_within_345_page_residual": True,
            "queue_equation": "507 = 106 reviewed + 401 pending",
            "reviewed_queue_equation": "106 = 84 owner + 10 shared + 5 alias + 0 dead + 7 evidence gap",
        },
        "denominator_boundary": {
            "run_077_bounded_static_records": 3929,
            "framework_expanded_route_page_denominator": None,
            "page_denominator_711_roots_vs_1058_page_tree_files_resolved": False,
            "complete_route_page_feature_crosswalk": False,
            "gate_4_complete": False,
        },
        "credit_boundary": {
            "STATIC_PAGE_FEATURE_OWNERSHIP_FOR_4_RECORDS": True,
            "static_route_feature_ownership_added": False,
            "static_controller_action_bridge_added": False,
            "direct_exact_queue_review_added": False,
            "journal_route_gap_resolved": False,
            "matrix_mutation": False,
            "wholesale_507_queue_ownership": False,
            "complete_route_page_feature_crosswalk": False,
            "framework_route_reachability": False,
            "navigation": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "privacy_correctness": False,
            "direct_object_correctness": False,
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
        "mutation_attestation": {
            "application_source_changed": False,
            "matrix_changed": False,
            "runtime_or_external_system_changed": False,
            "audit_artifacts_only": True,
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/integrate-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/current-run-126-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json",
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
        "source_owner_records": payload["combined_counts"]["source_owner_records"],
        "route_owner_records": payload["combined_counts"]["route_owner_records"],
        "page_owner_records": payload["combined_counts"]["page_owner_records"],
        "identity": payload["identity"],
        "audit_complete": False,
    }, indent=2))


if __name__ == "__main__":
    main()
