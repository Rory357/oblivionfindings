from __future__ import annotations

import ast
from collections import Counter
from decimal import Decimal, ROUND_HALF_UP
import hashlib
import json
import subprocess
from pathlib import Path


ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24"
SOURCE = AUDIT / "evidence/source"
PRIOR = SOURCE / "current-run-142-reviewed-outcome-neutral-finance-site-portfolio-overview-route-action-ownership-overlay-wave-23.json"
PRIOR_REVIEW = SOURCE / "raw-run-142r-independent-reviewed-outcome-neutral-finance-site-portfolio-overview-route-action-ownership-overlay-wave-23.json"
COHORT = SOURCE / "root-run-148-outcome-neutral-fleet-daily-vehicle-check-store-route-action-cohort-wave-25.json"
REVIEW = SOURCE / "raw-run-148r-independent-outcome-neutral-fleet-daily-vehicle-check-store-route-action-review-wave-25.json"
OUTPUT = SOURCE / "current-run-149-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.json"

EXPECTED_HEAD = "6ce3cbd5f8989baad0a691be9ca16c302458f9c4"
EXPECTED_TREE = "1866911b513814ea50c43062d0bab594ccb8b88f"
EXPECTED_PRIOR = "2d5228394090bcdad9ebfc3976be87260bf1dc5d110a8297974b00d908b63cdb"
EXPECTED_PRIOR_REVIEW = "005cbe019f16d7705f7d632b97a8f2629bf7c5653ba3ff9b30c50bd10e2a44df"
EXPECTED_COHORT = "621c1794a73e232b6fc9ff8d2b81ac9ae31ea2ccfe9f038ae77afe332b3ab28d"
EXPECTED_REVIEW = "6720a7570f7f0547fca222758c0632cb7514d953a20605e7c00d6ce88efc18b2"
EXPECTED_MATRIX = "3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0"
EXPECTED_CANDIDATE = "589212109db42fd2e0b1611ea855ea76c469a492528d084949880d3601ac45b2"
EXPECTED_DECISION = "e28ab3b80b9de141bc3a958a79569f2145bff4a5da8f3bc39a24230cd7231f66"

OWNERSHIP_LEDGERS = [
    "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json",
    "evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json",
    "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json",
    "evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json",
    "evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json",
    "evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json",
    "evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json",
    "evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json",
    "evidence/source/current-run-122-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json",
    "evidence/source/current-run-126-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json",
    "evidence/source/current-run-130-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json",
    "evidence/source/current-run-134-reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json",
    "evidence/source/current-run-138-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json",
    "evidence/source/current-run-142-reviewed-outcome-neutral-finance-site-portfolio-overview-route-action-ownership-overlay-wave-23.json",
]

QUEUE_LINEAGE = [
    "evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json",
    "evidence/source/root-run-113-outcome-neutral-name-only-route-action-cohort-wave-16.json",
    "evidence/source/root-run-121-outcome-neutral-finance-chart-route-action-cohort-wave-18.json",
    "evidence/source/root-run-129-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.json",
    "evidence/source/root-run-133-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.json",
    "evidence/source/raw-run-137r-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22.json",
    "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json",
]

DIRECT_INPUTS = [
    "03-feature-to-benchmark-matrix.csv",
    *OWNERSHIP_LEDGERS,
    *QUEUE_LINEAGE,
    "evidence/source/raw-run-142r-independent-reviewed-outcome-neutral-finance-site-portfolio-overview-route-action-ownership-overlay-wave-23.json",
    "evidence/source/root-run-148-outcome-neutral-fleet-daily-vehicle-check-store-route-action-cohort-wave-25.json",
    "evidence/source/raw-run-148r-independent-outcome-neutral-fleet-daily-vehicle-check-store-route-action-review-wave-25.json",
]


def digest(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def hjson(value: object) -> str:
    raw = json.dumps(value, ensure_ascii=False, separators=(",", ":"), sort_keys=True).encode()
    return hashlib.sha256(raw).hexdigest()


def hlist(values: list[str]) -> str:
    return hashlib.sha256("\n".join(sorted(set(values))).encode()).hexdigest()


def strict_object(pairs: list[tuple[str, object]]) -> dict:
    assert len(pairs) == len({key for key, _ in pairs}), "duplicate JSON key"
    return dict(pairs)


def read_audit(name: str) -> dict:
    return json.loads((AUDIT / name).read_text(encoding="utf-8"), object_pairs_hook=strict_object)


def git(*args: str) -> str:
    return subprocess.run(
        ["git", *args], cwd=ROOT, check=True, text=True, capture_output=True
    ).stdout.strip()


def git_bytes(*args: str) -> bytes:
    return subprocess.run(["git", *args], cwd=ROOT, check=True, capture_output=True).stdout


def sealed(record: dict, field: str) -> dict:
    result = dict(record)
    result[field] = hjson(result)
    return result


def collect_prior_state() -> tuple[list[dict], list[dict]]:
    records: list[dict] = []
    bridges: list[dict] = []
    for index, name in enumerate(OWNERSHIP_LEDGERS):
        ledger = read_audit(name)
        records.extend(ledger["records"] if index == 0 else ledger.get("overlay_source_records", []))
        bridges.extend(ledger.get("static_controller_action_bridges", []))
        bridges.extend(ledger.get("new_static_controller_action_bridges", []))
    return records, bridges


def collect_prior_reviewed_queue_keys() -> set[str]:
    keys: set[str] = set()
    chains = read_audit(QUEUE_LINEAGE[0])["records"]
    for chain in chains:
        keys.update(
            (
                "route|" + chain["route_source"]["route_record_id"],
                "page|" + chain["page_source"]["page_record_id"],
            )
        )
    for name in OWNERSHIP_LEDGERS:
        if "current-run-098-" not in name and "current-run-102-" not in name:
            continue
        ledger = read_audit(name)
        keys.update(
            ("route|" if row["surface"] == "ROUTE_SOURCE_RECORD" else "page|")
            + row["source_record_id"]
            for row in ledger["overlay_source_records"]
        )
        keys.update("route|" + row["route_record_id"] for row in ledger.get("reviewed_non_owner_outcomes", []))
    run110 = read_audit(next(name for name in OWNERSHIP_LEDGERS if "current-run-110-" in name))
    keys.update(row["queue_canonical_key"] for row in run110["new_reviewed_queue_outcomes"])
    for name in QUEUE_LINEAGE[1:5]:
        keys.update(row["queue_canonical_key"] for row in read_audit(name)["records"])
    run137r = read_audit(QUEUE_LINEAGE[5])
    keys.update(row["queue_canonical_key"] for row in run137r["action_decisions"])
    run142 = read_audit(OWNERSHIP_LEDGERS[-1])
    keys.update("route|" + row["source_record_id"] for row in run142["overlay_source_records"])
    frozen_keys = {row["canonical_key"] for row in read_audit(QUEUE_LINEAGE[6])["records"]}
    return keys & frozen_keys


def build_identity(
    prior_records: list[dict],
    prior_bridges: list[dict],
    prior_queue_keys: set[str],
    row: dict,
    bridge: dict,
    decision: dict,
    review: dict,
) -> dict:
    combined_records = prior_records + [row]
    combined_bridges = prior_bridges + [bridge]
    combined_queue_keys = prior_queue_keys | {decision["queue_canonical_key"]}
    route_records = [item for item in combined_records if item["surface"] == "ROUTE_SOURCE_RECORD"]
    page_records = [item for item in combined_records if item["surface"] == "PAGE_ROOT_SOURCE_RECORD"]
    bridge_key = lambda item: "|".join((item["controller_file"], item["method"], item["feature_id"]))
    return {
        "owner_candidate_id_list_sha256": hlist([decision["candidate_id"]]),
        "owner_route_record_id_list_sha256": hlist([decision["route_record_id"]]),
        "owner_source_record_key_list_sha256": hlist([decision["owner_source_record_key"]]),
        "owner_action_key_list_sha256": hlist([decision["action_key"]]),
        "owner_bridge_key_list_sha256": hlist(["|".join(decision["bridge_key"])]),
        "owner_candidate_record_sha256_list_sha256": hlist([decision["candidate_record_sha256"]]),
        "owner_decision_record_sha256_list_sha256": hlist([decision["decision_record_sha256"]]),
        "owner_queue_id_list_sha256": hlist([decision["queue_id"]]),
        "owner_queue_key_list_sha256": hlist([decision["queue_canonical_key"]]),
        "prior_source_record_key_list_sha256": hlist([item["source_record_key"] for item in prior_records]),
        "prior_source_record_id_list_sha256": hlist([item["source_record_id"] for item in prior_records]),
        "combined_source_record_key_list_sha256": hlist([item["source_record_key"] for item in combined_records]),
        "combined_source_record_id_list_sha256": hlist([item["source_record_id"] for item in combined_records]),
        "combined_feature_id_list_sha256": hlist([item["feature_id"] for item in combined_records]),
        "combined_route_feature_id_list_sha256": hlist([item["feature_id"] for item in route_records]),
        "combined_page_feature_id_list_sha256": hlist([item["feature_id"] for item in page_records]),
        "combined_route_page_overlap_feature_id_list_sha256": hlist(
            list({item["feature_id"] for item in route_records} & {item["feature_id"] for item in page_records})
        ),
        "prior_bridge_key_list_sha256": hlist([bridge_key(item) for item in prior_bridges]),
        "combined_bridge_key_list_sha256": hlist([bridge_key(item) for item in combined_bridges]),
        "combined_route_record_id_list_sha256": hlist([item["source_record_id"] for item in route_records]),
        "combined_route_source_record_key_list_sha256": hlist([item["source_record_key"] for item in route_records]),
        "combined_page_record_id_list_sha256": hlist([item["source_record_id"] for item in page_records]),
        "combined_page_source_record_key_list_sha256": hlist([item["source_record_key"] for item in page_records]),
        "new_union_feature_id_list_sha256": hlist([]),
        "accepted_route_feature_id_list_sha256": hlist([decision["candidate_feature_id"]]),
        "prior_reviewed_queue_key_list_sha256": hlist(list(prior_queue_keys)),
        "combined_reviewed_queue_key_list_sha256": hlist(list(combined_queue_keys)),
        "new_overlay_source_records_sha256": hjson([row]),
        "new_overlay_row_sha256_list_sha256": hlist([row["overlay_row_sha256"]]),
        "new_action_bridges_sha256": hjson([bridge]),
        "new_action_bridge_row_sha256_list_sha256": hlist([bridge["bridge_row_sha256"]]),
        "independent_candidate_reviews_sha256": hjson(review["independent_candidate_reviews"]),
        "independent_review_record_sha256_list_sha256": hlist(
            [item["review_record_sha256"] for item in review["independent_candidate_reviews"]]
        ),
        "synthesis_record_sha256": review["synthesis_review"]["synthesis_record_sha256"],
        "decision_record_sha256": decision["decision_record_sha256"],
        "provisional_assurance_observations_sha256": hjson(review["provisional_assurance_observations"]),
        "provisional_assurance_observation_record_sha256_list_sha256": hlist(
            [item["observation_record_sha256"] for item in review["provisional_assurance_observations"]]
        ),
        "correctness_only_expansion_manifest_sha256": review["source_packet_expansion"][
            "correctness_only_expansion_manifest_sha256"
        ],
    }


def main() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == EXPECTED_HEAD
    assert git("show", "-s", "--format=%T", "HEAD") == EXPECTED_TREE
    assert len(DIRECT_INPUTS) == len(set(DIRECT_INPUTS)) == 25
    inputs = {name: digest(AUDIT / name) for name in DIRECT_INPUTS}
    assert inputs["03-feature-to-benchmark-matrix.csv"] == EXPECTED_MATRIX
    assert digest(PRIOR) == EXPECTED_PRIOR
    assert digest(PRIOR_REVIEW) == EXPECTED_PRIOR_REVIEW
    assert digest(COHORT) == EXPECTED_COHORT
    assert digest(REVIEW) == EXPECTED_REVIEW

    prior = read_audit(str(PRIOR.relative_to(AUDIT)).replace("\\", "/"))
    prior_review = read_audit(str(PRIOR_REVIEW.relative_to(AUDIT)).replace("\\", "/"))
    cohort = read_audit(str(COHORT.relative_to(AUDIT)).replace("\\", "/"))
    review = read_audit(str(REVIEW.relative_to(AUDIT)).replace("\\", "/"))
    for name in [*OWNERSHIP_LEDGERS[:-1], *QUEUE_LINEAGE]:
        assert prior["pins"]["inputs"][name] == inputs[name]
    assert inputs[OWNERSHIP_LEDGERS[-1]] == EXPECTED_PRIOR
    candidate = cohort["records"][0]
    decision = review["action_decisions"]
    assert candidate["candidate_record_sha256"] == EXPECTED_CANDIDATE == hjson(
        {key: value for key, value in candidate.items() if key != "candidate_record_sha256"}
    )
    assert decision["decision_record_sha256"] == EXPECTED_DECISION == hjson(
        {key: value for key, value in decision.items() if key != "decision_record_sha256"}
    )
    assert review["decision"] == "GO"
    assert review["status"] == "GO_ONE_STATIC_OWNER_AND_BRIDGE_AUTHORIZED_FOR_LATER_INTEGRATION_ZERO_CURRENT_OR_DOWNSTREAM_CREDIT"
    assert review["synthesis_review"]["verdict"] == "GO_ACCEPT_1_OWNER_ROUTE_ACTION_FOR_BOUNDED_LATER_INTEGRATION"
    assert decision["outcome"] == "OWNER_ROUTE_ACTION"
    assert decision["route_ownership_authorized"] is True
    assert decision["controller_action_bridge_authorized"] is True
    assert decision["page_ownership_authorized"] is False
    assert decision["current_overlay_credit_awarded"] is False
    assert decision["site_permission_privacy_direct_object_template_concurrency_audit_correctness_authorized"] is False
    assert decision["runtime_database_build_browser_test_benchmark_ease_release_pass_final_finding_completion_authorized"] is False
    assert review["pins"]["cohort_sha256"] == EXPECTED_COHORT
    assert review["pins"]["cohort_candidate_record_sha256"] == EXPECTED_CANDIDATE
    assert review["pins"]["generator_sha256"] == "c41b37679763c0ea0eb4a08fc14368692c5b4cc0176167c4369b637c6f68f4b3"
    assert cohort["pins"]["generator_sha256"] == "c8c6a9f1500fe088f6c61c3edff5351095518d14661a77af86b327a9ee253f65"
    assert digest(ROOT / review["pins"]["generator"]) == review["pins"]["generator_sha256"]
    assert digest(ROOT / cohort["pins"]["generator"]) == cohort["pins"]["generator_sha256"]
    for treeish, expected in {
        f"{cohort['pins']['application_commit']}^{{tree}}": cohort["pins"]["application_tree"],
        f"{cohort['pins']['application_commit']}:app": cohort["pins"]["app_tree"],
        f"{cohort['pins']['application_commit']}:routes": cohort["pins"]["routes_tree"],
        f"{cohort['pins']['application_commit']}:resources/js": cohort["pins"]["resources_js_tree"],
        f"{cohort['pins']['application_commit']}:resources/js/pages": cohort["pins"]["resources_js_pages_tree"],
        f"{cohort['pins']['application_commit']}:tests": cohort["pins"]["tests_tree"],
    }.items():
        assert git("rev-parse", treeish) == expected
    assert prior_review["pins"]["producer_sha256"] == EXPECTED_PRIOR
    assert prior_review["decision"]["verdict"] == "GO"
    assert prior_review["credit_boundary"]["INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"] is True

    reviewers = review["independent_candidate_reviews"]
    assert len(reviewers) == 2
    assert [item["outcome"] for item in reviewers] == ["OWNER_ROUTE_ACTION", "OWNER_ROUTE_ACTION"]
    assert [item["blinded_review"] for item in reviewers] == [False, False]
    assert [item["prior_outcome_visible_in_team_status"] for item in reviewers] == [False, True]
    assert all(item["other_candidate_reviewer_consulted"] is False for item in reviewers)
    assert all(item["independent_evidence_trace_completed"] is True for item in reviewers)
    for reviewer in reviewers:
        assert reviewer["review_record_sha256"] == hjson(
            {key: value for key, value in reviewer.items() if key != "review_record_sha256"}
        )
    synthesis = review["synthesis_review"]
    assert synthesis["synthesis_record_sha256"] == hjson(
        {key: value for key, value in synthesis.items() if key != "synthesis_record_sha256"}
    )

    route = candidate["route_source"]
    action = candidate["controller_action"]
    feature = candidate["feature_identity_projection"]
    row = sealed(
        {
            "overlay_mapping_id": "RUN149-ROUTE-01",
            "candidate_id": decision["candidate_id"],
            "candidate_record_sha256": decision["candidate_record_sha256"],
            "decision_record_sha256": decision["decision_record_sha256"],
            "surface": "ROUTE_SOURCE_RECORD",
            "source_record_id": decision["route_record_id"],
            "source_record_key": decision["owner_source_record_key"],
            "feature_id": decision["candidate_feature_id"],
            "feature_class": feature["feature_class"],
            "module": feature["module"],
            "user_job": feature["user_job"],
            "source": route,
            "review_outcome": "OWNER_ROUTE_ACTION",
            "review_rationale": decision["rationale"],
            "static_source_feature_ownership_credit": True,
            "credit_boundary": {
                key: False
                for key in (
                    "page_ownership",
                    "frontend_caller_ownership",
                    "framework_route_reachability",
                    "canonical_object_ownership_correctness",
                    "site_authorization_correctness",
                    "permission_correctness",
                    "privacy_correctness",
                    "direct_object_correctness",
                    "template_authority_correctness",
                    "concurrency_or_idempotency_correctness",
                    "audit_or_event_durability_correctness",
                    "runtime",
                    "database",
                    "build",
                    "application_browser",
                    "executed_tests",
                    "benchmark",
                    "ease",
                    "release",
                    "pass",
                    "final_finding",
                    "completion",
                    "audit_complete",
                )
            },
        },
        "overlay_row_sha256",
    )
    bridge = sealed(
        {
            "bridge_id": "RUN149-BRIDGE-01",
            "candidate_id": decision["candidate_id"],
            "candidate_record_sha256": decision["candidate_record_sha256"],
            "decision_record_sha256": decision["decision_record_sha256"],
            "feature_id": decision["candidate_feature_id"],
            "route_record_id": decision["route_record_id"],
            "controller_fqcn": action["resolved_fqcn"],
            "controller_file": action["source_file"],
            "controller_file_sha256": action["source_file_sha256"],
            "controller_file_blob_id": action["source_file_blob_id"],
            "method": action["method"],
            "definition_anchor": action["definition_anchor"],
            "method_review_slice_sha256": action["review_slice"]["text_sha256"],
            "review_outcome": "OWNER_ROUTE_ACTION",
            "static_controller_action_bridge_credit": True,
            "page_ownership_credit": False,
            "correctness_credit": False,
            "runtime_credit": False,
            "application_browser_credit": False,
            "executed_test_credit": False,
            "final_finding_credit": False,
            "completion_credit": False,
        },
        "bridge_row_sha256",
    )

    prior_records, prior_bridges = collect_prior_state()
    assert len(prior_records) == 662
    assert len(prior_bridges) == 93
    assert len({item["source_record_id"] for item in prior_records}) == 662
    assert len({item["source_record_key"] for item in prior_records}) == 662
    combined_records = prior_records + [row]
    combined_bridges = prior_bridges + [bridge]
    assert len({item["source_record_id"] for item in combined_records}) == 663
    assert len({item["source_record_key"] for item in combined_records}) == 663
    bridge_keys = [(item["controller_file"], item["method"], item["feature_id"]) for item in combined_bridges]
    assert len(bridge_keys) == len(set(bridge_keys)) == 94
    route_records = [item for item in combined_records if item["surface"] == "ROUTE_SOURCE_RECORD"]
    page_records = [item for item in combined_records if item["surface"] == "PAGE_ROOT_SOURCE_RECORD"]
    assert (len(route_records), len(page_records)) == (306, 357)
    feature_ids = {item["feature_id"] for item in combined_records}
    route_feature_ids = {item["feature_id"] for item in route_records}
    page_feature_ids = {item["feature_id"] for item in page_records}
    assert (len(feature_ids), len(route_feature_ids), len(page_feature_ids), len(route_feature_ids & page_feature_ids)) == (256, 64, 242, 50)
    assert decision["candidate_feature_id"] in route_feature_ids & page_feature_ids
    feature_class_counts = Counter({item["feature_id"]: item["feature_class"] for item in combined_records}.values())
    assert feature_class_counts == {"H": 234, "D": 22}

    prior_queue_keys = collect_prior_reviewed_queue_keys()
    assert len(prior_queue_keys) == 116
    assert decision["queue_canonical_key"] not in prior_queue_keys
    combined_queue_keys = prior_queue_keys | {decision["queue_canonical_key"]}
    assert len(combined_queue_keys) == 117
    queue_rows = read_audit(QUEUE_LINEAGE[6])["records"]
    selected_queue_row = queue_rows[80]
    next_queue_row = queue_rows[81]
    assert selected_queue_row["queue_record_sha256"] == "b73b6a2cf4340520554c6725d701e26f1b313334e8025d6db4f5e7de51392fda"
    assert (selected_queue_row["queue_id"], selected_queue_row["source_record_id"], selected_queue_row["canonical_key"]) == (
        "RUN090-ROUTE-0081",
        "RUN077-ROUTE-0689",
        decision["queue_canonical_key"],
    )
    assert (next_queue_row["queue_id"], next_queue_row["source_record_id"], next_queue_row["canonical_key"]) == (
        "RUN090-ROUTE-0082",
        "RUN077-ROUTE-0690",
        "route|RUN077-ROUTE-0690",
    )
    assert next_queue_row["queue_record_sha256"] == "c15a3e4371f5d063066b013b824205c24d1ab6126f49aea3d266e9b897b146de"

    bounded_percent = (Decimal(663) * Decimal(100) / Decimal(3929)).quantize(
        Decimal("0.000001"), rounding=ROUND_HALF_UP
    )
    assert format(bounded_percent, "f") == "16.874523"
    combined_counts = {
        "source_owner_records": 663,
        "route_owner_records": 306,
        "page_owner_records": 357,
        "distinct_feature_ids": 256,
        "distinct_H_feature_ids": 234,
        "distinct_D_feature_ids": 22,
        "route_distinct_feature_ids": 64,
        "page_distinct_feature_ids": 242,
        "route_page_feature_overlap": 50,
        "static_controller_action_bridges": 94,
        "bounded_static_source_denominator": 3929,
        "bounded_static_source_ownership_percent": format(bounded_percent, "f"),
        "bounded_static_source_residual_records": 3266,
        "residual_explicit_unmapped_routes": 2895,
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
    queue_accounting = {
        "direct_exact_queue_records": 507,
        "reviewed_queue_surface_rows": 117,
        "owner_queue_surface_rows": 95,
        "shared_queue_surface_rows": 10,
        "alias_queue_surface_rows": 5,
        "dead_queue_surface_rows": 0,
        "evidence_gap_queue_surface_rows": 7,
        "pending_unreviewed_queue_surface_rows": 390,
        "queue_surfaces_without_ownership": 412,
        "new_reviewed_route_surface_rows": 1,
        "new_owner_route_surface_rows": 1,
        "new_shared_route_surface_rows": 0,
        "new_alias_route_surface_rows": 0,
        "new_dead_route_surface_rows": 0,
        "new_evidence_gap_route_surface_rows": 0,
        "wholesale_queue_ownership_authorized": False,
    }
    assert combined_counts["source_owner_records"] + combined_counts["bounded_static_source_residual_records"] == 3929
    assert combined_counts["route_owner_records"] + combined_counts["page_owner_records"] == combined_counts["source_owner_records"]
    assert queue_accounting["reviewed_queue_surface_rows"] + queue_accounting["pending_unreviewed_queue_surface_rows"] == 507
    assert queue_accounting["owner_queue_surface_rows"] + queue_accounting["shared_queue_surface_rows"] + queue_accounting["alias_queue_surface_rows"] + queue_accounting["dead_queue_surface_rows"] + queue_accounting["evidence_gap_queue_surface_rows"] == queue_accounting["reviewed_queue_surface_rows"]
    assert queue_accounting["pending_unreviewed_queue_surface_rows"] + queue_accounting["shared_queue_surface_rows"] + queue_accounting["alias_queue_surface_rows"] + queue_accounting["dead_queue_surface_rows"] + queue_accounting["evidence_gap_queue_surface_rows"] == queue_accounting["queue_surfaces_without_ownership"]

    observations = review["provisional_assurance_observations"]
    assert len(observations) == 4
    assert [item["observation_id"] for item in observations] == decision["provisional_assurance_observation_ids"]
    for observation in observations:
        assert observation["status"] == "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING"
        assert observation["correctness_credit_authorized"] is False
        assert observation["final_finding_credit_authorized"] is False
        assert observation["observation_record_sha256"] == hjson(
            {key: value for key, value in observation.items() if key != "observation_record_sha256"}
        )
    expansion = review["source_packet_expansion"]
    assert expansion["ownership_material_expansion"] == []
    assert expansion["ownership_material_expansion_required"] is False
    assert expansion["narrow_ownership_decision_complete"] is True
    assert len(expansion["correctness_only_expanded_files"]) == 4
    assert len(expansion["requested_but_not_fully_inspected"]) == 4
    assert expansion["expansion_authorizes_correctness_credit"] is False
    for expanded in expansion["correctness_only_expanded_files"]:
        frozen = git_bytes("show", f"{cohort['pins']['application_commit']}:{expanded['path']}")
        assert hashlib.sha256(frozen).hexdigest() == expanded["sha256"]
        assert git("rev-parse", f"{cohort['pins']['application_commit']}:{expanded['path']}") == expanded["application_commit_blob_id"]
        assert expanded["authorizes_correctness_credit"] is False

    generator_rel = str(Path(__file__).resolve().relative_to(ROOT)).replace("\\", "/")
    output_rel = str(OUTPUT.relative_to(ROOT)).replace("\\", "/")
    input_map_sha256 = hjson(inputs)
    identity = build_identity(prior_records, prior_bridges, prior_queue_keys, row, bridge, decision, review)
    assert identity["prior_source_record_key_list_sha256"] == "667fe6984e63cd0d03c11220dea1c6aefc8b29e6a0739bb023b0f211ce679bd9"
    assert identity["prior_source_record_id_list_sha256"] == "c79942cb81cab4ed27727ec7d63bef31d9756e1349fa1468cd2d987459039f52"
    assert identity["combined_source_record_key_list_sha256"] == "b8cea49502df5597bcd46726e5bcf25130dcb760d8ad14ab69c36207634f1b84"
    assert identity["combined_source_record_id_list_sha256"] == "8a3fb2b0a0a3926c62a34db721d6d7efa26ecd7fa2199a9e11c0a78a92e75624"
    assert identity["combined_route_record_id_list_sha256"] == "b13444dfbcf0a7110996ed2b5d9dec583be16fd789326e8b438b285a5ae8fefc"
    assert identity["combined_route_source_record_key_list_sha256"] == "260f8d750edb61a86d1ae7a7327f2f65821b1f91d5fb08b58b40d7e0ce6b9ed3"
    assert identity["combined_bridge_key_list_sha256"] == "6e69d7888d98e221f6552a86cf1efd7a19074650c6655a1466cf8a77c4882e28"
    assert identity["combined_reviewed_queue_key_list_sha256"] == "7bfbc1ab7c57b332b7a4673499713d581ea0b03dc24436ae7dae3dc7413838f6"
    out = {
        "schema_version": "run-149-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25-v1",
        "run_id": "RUN-149-REVIEWED-OUTCOME-NEUTRAL-FLEET-DAILY-VEHICLE-CHECK-STORE-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-25",
        "status": "ONE_REVIEWED_FLEET_DAILY_VEHICLE_CHECK_STORE_ROUTE_ACTION_OWNER_AND_BRIDGE_INTEGRATED_STATIC_ONLY",
        "generated_on": "2026-08-27",
        "pins": {
            "checkpoint_commit": EXPECTED_HEAD,
            "checkpoint_tree": EXPECTED_TREE,
            "application_commit": cohort["pins"]["application_commit"],
            "application_tree": cohort["pins"]["application_tree"],
            "app_tree": cohort["pins"]["app_tree"],
            "routes_tree": cohort["pins"]["routes_tree"],
            "resources_js_tree": cohort["pins"]["resources_js_tree"],
            "resources_js_pages_tree": cohort["pins"]["resources_js_pages_tree"],
            "tests_tree": cohort["pins"]["tests_tree"],
            "matrix_sha256": EXPECTED_MATRIX,
            "generator": generator_rel,
            "generator_sha256": digest(Path(__file__).resolve()),
            "prior_overlay": str(PRIOR.relative_to(AUDIT)).replace("\\", "/"),
            "prior_overlay_sha256": EXPECTED_PRIOR,
            "prior_overlay_blob_id": git("rev-parse", f"HEAD:{PRIOR.relative_to(ROOT).as_posix()}"),
            "prior_overlay_review": str(PRIOR_REVIEW.relative_to(AUDIT)).replace("\\", "/"),
            "prior_overlay_review_sha256": EXPECTED_PRIOR_REVIEW,
            "prior_overlay_review_blob_id": git("rev-parse", f"HEAD:{PRIOR_REVIEW.relative_to(ROOT).as_posix()}"),
            "cohort": str(COHORT.relative_to(AUDIT)).replace("\\", "/"),
            "cohort_sha256": EXPECTED_COHORT,
            "cohort_blob_id": git("rev-parse", f"HEAD:{COHORT.relative_to(ROOT).as_posix()}"),
            "cohort_generator": cohort["pins"]["generator"],
            "cohort_generator_sha256": cohort["pins"]["generator_sha256"],
            "review": str(REVIEW.relative_to(AUDIT)).replace("\\", "/"),
            "review_sha256": EXPECTED_REVIEW,
            "review_blob_id": git("rev-parse", f"HEAD:{REVIEW.relative_to(ROOT).as_posix()}"),
            "review_materializer": review["pins"]["generator"],
            "review_materializer_sha256": review["pins"]["generator_sha256"],
            "inputs": inputs,
            "input_map_sha256": input_map_sha256,
        },
        "architecture_rule": review["architecture_rule"],
        "baseline": {
            "producer_run_id": prior["run_id"],
            "producer_review_run_id": prior_review["run_id"],
            "producer_review_status": prior_review["status"],
            "source_owner_records": 662,
            "route_owner_records": 305,
            "page_owner_records": 357,
            "static_controller_action_bridges": 93,
            "reviewed_queue_surface_rows": 116,
            "pending_unreviewed_queue_surface_rows": 391,
            "prior_overlay_sha256": EXPECTED_PRIOR,
            "prior_overlay_review_sha256": EXPECTED_PRIOR_REVIEW,
        },
        "reviewed_overlay": {
            "producer_run_id": cohort["run_id"],
            "review_run_id": review["run_id"],
            "reviewed_route_actions": 1,
            "owner_route_actions": 1,
            "shared_relations": 0,
            "evidence_gaps": 0,
            "accepted_source_owner_records": 1,
            "accepted_route_owner_records": 1,
            "accepted_page_owner_records": 0,
            "accepted_controller_action_bridges": 1,
            "accepted_distinct_feature_ids": 1,
            "new_distinct_feature_ids": 0,
            "current_static_overlay_credit_applied": True,
            "correctness_or_downstream_credit_authorized": False,
            "final_finding_credit_authorized": False,
            "cohort_sha256": EXPECTED_COHORT,
            "review_sha256": EXPECTED_REVIEW,
        },
        "source_packet_expansion_preservation": expansion,
        "provisional_assurance_observation_preservation": {
            "observations": observations,
            "observation_count": 4,
            "provisional_source_observations_only": True,
            "correctness_credit_authorized": False,
            "final_finding_credit_authorized": False,
        },
        "reviewer_lineage": {
            "independent_candidate_reviews": reviewers,
            "synthesis_review": synthesis,
            "action_decision": decision,
            "nonblinding_disclosure_preserved": {
                "review_a_blinded": False,
                "review_a_prior_outcome_visible_in_team_status": False,
                "review_b_blinded": False,
                "review_b_prior_outcome_visible_in_team_status": True,
                "reviewers_consulted_each_other": False,
                "both_completed_independent_evidence_traces": True,
            },
        },
        "combined_counts": combined_counts,
        "queue_accounting": queue_accounting,
        "page_sibling_and_next_boundary": {
            "review_reconciliation": review["page_sibling_and_next_boundary_reconciliation"],
            "excluded_preceding_reviewed_neighbor": cohort["excluded_preceding_reviewed_neighbor"],
            "next_pending_boundary": cohort["next_pending_boundary"],
            "next_pending_queue_record_sha256": next_queue_row["queue_record_sha256"],
            "run149_page_or_frontend_caller_credit_awarded": False,
            "run149_next_queue_credit_awarded": False,
        },
        "noninheritance_boundary": {
            "preceding_index_79_owner_not_inherited_or_recredited": True,
            "page_owner_not_inherited_or_recredited": True,
            "frontend_caller_not_inherited_or_recredited": True,
            "next_index_81_not_selected_or_credited": True,
            "current_overlay_correctness_and_downstream_credit": False,
        },
        "overlay_source_records": [row],
        "new_static_controller_action_bridges": [bridge],
        "reviewed_non_owner_outcomes": [],
        "identity": identity,
        "identity_discovery": identity,
        "outcome_conservation": {
            "reviewed_outcomes_equation": "1 = 1 owner + 0 shared + 0 evidence gap",
            "bounded_source_equation": "3929 = 663 owner + 3266 non-owner residual",
            "owner_surface_equation": "663 = 306 route + 357 page",
            "feature_union_equation": "256 = 64 route + 242 page - 50 overlap",
            "route_universe_equation": "3218 = 306 owner + 12 shared + 5 alias + 0 dead + 2895 residual",
            "page_universe_equation": "711 = 357 owner + 9 shared + 0 alias + 0 dead + 345 residual",
            "queue_equation": "507 = 117 reviewed + 390 pending",
            "reviewed_queue_equation": "117 = 95 owner + 10 shared + 5 alias + 0 dead + 7 evidence gap",
            "queue_without_ownership_equation": "412 = 390 pending + 10 shared + 5 alias + 0 dead + 7 evidence gap",
            "evidence_gap_routes_tagged_within_residual": True,
            "evidence_gap_page_roots_tagged_within_residual": True,
        },
        "projection_reconciliation": {
            "run148r_projection_credit_awarded": False,
            "run149_current_static_overlay_credit_applied": True,
            "current_source_owner_records": 663,
            "current_route_owner_records": 306,
            "current_page_owner_records": 357,
            "current_static_controller_action_bridges": 94,
            "current_reviewed_queue_surface_rows": 117,
            "current_pending_unreviewed_queue_surface_rows": 390,
            "new_union_feature_ids": [],
            "accepted_route_feature_ids": [decision["candidate_feature_id"]],
            "correctness_or_downstream_credit_authorized": False,
            "rule": "RUN148R authorized a later projection only; RUN149 applies exactly one route owner and bridge with no page, correctness, finding, or downstream credit.",
        },
        "denominator_boundary": prior["denominator_boundary"],
        "credit_boundary": {
            "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD": True,
            "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION": True,
            "static_page_feature_ownership": False,
            "frontend_caller_ownership": False,
            "complete_route_page_feature_crosswalk": False,
            "framework_route_reachability": False,
            "canonical_object_ownership_correctness": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "privacy_correctness": False,
            "direct_object_correctness": False,
            "template_authority_correctness": False,
            "concurrency_or_idempotency_correctness": False,
            "audit_or_event_durability_correctness": False,
            "runtime": False,
            "database": False,
            "build": False,
            "application_browser": False,
            "executed_tests": False,
            "application_source_mutation": False,
            "matrix_mutation": False,
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
            "test_files_changed": False,
            "matrix_changed": False,
            "reports_changed": False,
            "dashboard_generator_changed": False,
            "dashboard_html_changed": False,
            "runtime_or_external_system_changed": False,
            "audit_artifacts_only": True,
            "whole_repository_status_scope_asserted": True,
            "only_expected_run149_artifacts_present": True,
            "expected_status_paths": [generator_rel, output_rel],
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [generator_rel, output_rel],
    }

    assert "one operating organisation across multiple sites" in out["architecture_rule"].lower()
    assert out["pins"]["input_map_sha256"] == hjson(out["pins"]["inputs"])
    assert out["combined_counts"]["bounded_static_source_ownership_percent"] == "16.874523"
    assert out["page_sibling_and_next_boundary"]["next_pending_boundary"] == {
        "queue_index_zero_based": 81,
        "queue_id": "RUN090-ROUTE-0082",
        "route_record_id": "RUN077-ROUTE-0690",
        "candidate_feature_id": "CAP-FLEET-VEHICLE-REGISTER",
        "review_state": "PENDING_FRESH_SEMANTIC_REVIEW",
        "selected_for_run148": False,
        "credit_awarded": False,
    }
    assert [key for key, value in out["credit_boundary"].items() if value] == [
        "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD",
        "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION",
    ]
    assert all(
        item["correctness_credit_authorized"] is False and item["final_finding_credit_authorized"] is False
        for item in out["provisional_assurance_observation_preservation"]["observations"]
    )
    assert out["reviewed_overlay"]["final_finding_credit_authorized"] is False
    assert out["audit_completion_test_met"] is False

    assert route["route_record_id"] == "RUN077-ROUTE-0689"
    assert route["source_key"] == "routes/fleet-assets.php:46:9:post:6"
    assert route["route_method"] == "post"
    assert route["literal_uri"] == "/daily-check"
    assert route["literal_route_name"] == "fleet-assets.daily-check.store"
    assert action["source_file"] == "app/Http/Controllers/FleetAssets/DailyCheckController.php"
    assert action["method"] == "store"
    assert action["definition_anchor"] == "app/Http/Controllers/FleetAssets/DailyCheckController.php:134"
    application_commit = out["pins"]["application_commit"]
    route_bytes = git_bytes("show", f"{application_commit}:{route['route_file']}")
    assert hashlib.sha256(route_bytes).hexdigest() == route["route_file_sha256"]
    assert git("rev-parse", f"{application_commit}:{route['route_file']}") == route["route_file_blob_id"]
    route_lines = route_bytes.decode("utf-8").splitlines()
    assert route_lines[45] == "        Route::post('/daily-check', [DailyCheckController::class, 'store'])->name('fleet-assets.daily-check.store');"
    assert hashlib.sha256(route_lines[45].lstrip().encode()).hexdigest() == route["statement_sha256"]
    controller_bytes = git_bytes("show", f"{application_commit}:{action['source_file']}")
    assert hashlib.sha256(controller_bytes).hexdigest() == action["source_file_sha256"]
    assert git("rev-parse", f"{application_commit}:{action['source_file']}") == action["source_file_blob_id"]
    controller_lines = controller_bytes.decode("utf-8").splitlines()
    assert controller_lines[133] == "    public function store(Request $request)"
    assert "\n".join(controller_lines[133:191]) == action["review_slice"]["text"]
    assert hashlib.sha256(action["review_slice"]["text"].encode()).hexdigest() == action["review_slice"]["text_sha256"]
    for context in candidate["frontend_submit_context"]:
        source_bytes = git_bytes("show", f"{application_commit}:{context['source_file']}")
        source_lines = source_bytes.decode("utf-8").splitlines()
        line_number = int(context["source_anchor"].rsplit(":", 1)[1])
        assert hashlib.sha256(source_bytes).hexdigest() == context["source_file_sha256"]
        assert git("rev-parse", f"{application_commit}:{context['source_file']}") == context["source_file_blob_id"]
        assert source_lines[line_number - 1].strip() == context["source_line"].strip()
        assert hashlib.sha256(source_lines[line_number - 1].encode()).hexdigest() == context["source_line_sha256"]

    parsed_generator = ast.parse(Path(__file__).read_text(encoding="utf-8"))
    assert parsed_generator.body
    encoded = (json.dumps(out, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    OUTPUT.write_bytes(encoded)
    assert OUTPUT.read_bytes() == encoded
    assert not encoded.startswith(b"\xef\xbb\xbf") and b"\r\n" not in encoded
    reparsed = json.loads(encoded, object_pairs_hook=strict_object)
    assert reparsed == out
    fresh_records, fresh_bridges = collect_prior_state()
    fresh_queue_keys = collect_prior_reviewed_queue_keys()
    fresh_review = read_audit(str(REVIEW.relative_to(AUDIT)).replace("\\", "/"))
    assert build_identity(fresh_records, fresh_bridges, fresh_queue_keys, row, bridge, decision, fresh_review) == out["identity"]
    assert out["identity_discovery"] == out["identity"]
    expected_status = {f"?? {generator_rel}", f"?? {output_rel}"}
    actual_status = set(
        subprocess.run(
            ["git", "status", "--short"], cwd=ROOT, check=True, text=True, capture_output=True
        ).stdout.splitlines()
    )
    assert actual_status == expected_status, actual_status
    assert not list(AUDIT.rglob("__pycache__"))


if __name__ == "__main__":
    main()
