from __future__ import annotations

import copy
from collections import Counter
import hashlib
import json
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24"
SOURCE = AUDIT / "evidence/source"
PRIOR = SOURCE / "current-run-138-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json"
COHORT = SOURCE / "root-run-141-outcome-neutral-finance-site-portfolio-overview-route-action-cohort-wave-23.json"
REVIEW = SOURCE / "raw-run-141r-independent-outcome-neutral-finance-site-portfolio-overview-route-action-review-wave-23.json"
OUTPUT = SOURCE / "current-run-142-reviewed-outcome-neutral-finance-site-portfolio-overview-route-action-ownership-overlay-wave-23.json"
EXPECTED_HEAD = "19cd019da8ea0cf8953296fddf423045e4299d74"
EXPECTED_TREE = "3ce2d122fd6755c90447d235841133ed45e1089b"
EXPECTED_COHORT = "9062d90b961e496b0bf5ad48fc3f930a8161394fb8d2b9b88ad298807bd90fc3"
EXPECTED_REVIEW = "02f78f9e6305c783fd13b790f5d0e044e437bfc2d6853eeb49ec5a65cdd8fd8b"
EXPECTED_INPUT_MAP_SHA256 = "27d1725c7663ac69e3fe1676cfb8092c0ea2073cfa8ceaef676be8361fb51f15"
INPUTS = [
    "03-feature-to-benchmark-matrix.csv",
    "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json",
    *[f"evidence/source/current-run-{n}-" + name for n, name in [
        ("092", "reviewed-static-source-ownership-overlay-wave-11.json"),
        ("098", "reviewed-route-controller-only-ownership-overlay-wave-12.json"),
        ("102", "reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json"),
        ("106", "reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json"),
        ("110", "reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json"),
        ("114", "reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json"),
        ("118", "reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json"),
        ("122", "reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json"),
        ("126", "reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json"),
        ("130", "reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json"),
        ("134", "reviewed-outcome-neutral-finance-accounting-integration-route-action-ownership-overlay-wave-21.json"),
        ("138", "reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json"),
    ]],
    "evidence/source/raw-run-138r-independent-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json",
    "evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json",
    "evidence/source/root-run-113-outcome-neutral-name-only-route-action-cohort-wave-16.json",
    "evidence/source/root-run-121-outcome-neutral-finance-chart-route-action-cohort-wave-18.json",
    "evidence/source/root-run-129-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.json",
    "evidence/source/root-run-133-outcome-neutral-finance-accounting-integration-route-action-cohort-wave-21.json",
    "evidence/source/root-run-137-outcome-neutral-finance-invoice-index-route-action-cohort-wave-22.json",
    "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json",
    "evidence/source/root-run-141-outcome-neutral-finance-site-portfolio-overview-route-action-cohort-wave-23.json",
    "evidence/source/raw-run-141r-independent-outcome-neutral-finance-site-portfolio-overview-route-action-review-wave-23.json",
    "evidence/source/current-run-139-reviewed-finance-invoice-index-route-action-reporting-wave-22.json",
]

def digest(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()

def hjson(value: object) -> str:
    raw = json.dumps(value, ensure_ascii=False, separators=(",", ":"), sort_keys=True).encode()
    return hashlib.sha256(raw).hexdigest()

def hlist(values: list[str]) -> str:
    ordered = sorted(set(values))
    return hashlib.sha256("\n".join(ordered).encode()).hexdigest()

def read_audit(name: str) -> dict:
    def strict(pairs: list[tuple[str, object]]) -> dict:
        assert len(pairs) == len({key for key, _ in pairs}), f"duplicate JSON key in {name}"
        return dict(pairs)
    return json.loads((AUDIT / name).read_text(encoding="utf-8"), object_pairs_hook=strict)

def git(*args: str) -> str:
    return subprocess.run(["git", *args], cwd=ROOT, check=True, text=True, capture_output=True).stdout.strip()

def git_bytes(*args: str) -> bytes:
    return subprocess.run(["git", *args], cwd=ROOT, check=True, capture_output=True).stdout

def resolve_pointer(root: object, pointer: str) -> list[object]:
    values = [root]
    for token in pointer.strip("/").split("/"):
        next_values: list[object] = []
        for value in values:
            if token == "*":
                assert isinstance(value, list)
                next_values.extend(value)
            elif isinstance(value, list):
                next_values.append(value[int(token)])
            else:
                assert isinstance(value, dict) and token in value
                next_values.append(value[token])
        values = next_values
    return values

def sealed(record: dict, field: str) -> dict:
    result = copy.deepcopy(record)
    result[field] = hjson(result)
    return result

def rediscover_identity(row: dict, bridge: dict) -> dict:
    """Second-pass identity reconstruction from freshly parsed pinned ledgers."""
    fresh_review = read_audit("evidence/source/raw-run-141r-independent-outcome-neutral-finance-site-portfolio-overview-route-action-review-wave-23.json")
    decision = fresh_review["action_decisions"][0]
    assert decision["decision_record_sha256"] == hjson({k:v for k,v in decision.items() if k != "decision_record_sha256"})
    baseline = read_audit("evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json")
    overlay_names = [name for name in INPUTS if name.startswith("evidence/source/current-run-") and "reporting" not in name]
    prior_records = list(baseline["records"])
    prior_bridges: list[dict] = []
    for name in overlay_names:
        ledger = read_audit(name)
        prior_records.extend(ledger.get("overlay_source_records", []))
        prior_bridges.extend(ledger.get("static_controller_action_bridges", []))
        prior_bridges.extend(ledger.get("new_static_controller_action_bridges", []))
    records = prior_records + [json.loads(json.dumps(row))]
    routes = [record for record in records if record["surface"] == "ROUTE_SOURCE_RECORD"]
    pages = [record for record in records if record["surface"] == "PAGE_ROOT_SOURCE_RECORD"]
    prior_bridge_keys = ["|".join((b["controller_file"], b["method"], b["feature_id"])) for b in prior_bridges]
    bridge_keys = prior_bridge_keys + ["|".join((bridge["controller_file"], bridge["method"], bridge["feature_id"]))]
    queue_keys: set[str] = set()
    for chain in read_audit("evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json")["records"]:
        queue_keys.update(("route|" + chain["route_source"]["route_record_id"], "page|" + chain["page_source"]["page_record_id"]))
    for number in ("098", "102"):
        ledger = next(read_audit(name) for name in overlay_names if f"current-run-{number}-" in name)
        queue_keys.update(("route|" if r["surface"] == "ROUTE_SOURCE_RECORD" else "page|") + r["source_record_id"] for r in ledger["overlay_source_records"])
        queue_keys.update("route|" + r["route_record_id"] for r in ledger.get("reviewed_non_owner_outcomes", []))
    ledger110 = next(read_audit(name) for name in overlay_names if "current-run-110-" in name)
    queue_keys.update(r["queue_canonical_key"] for r in ledger110["new_reviewed_queue_outcomes"])
    for number in ("113", "121", "129", "133"):
        name = next(path for path in INPUTS if f"root-run-{number}-" in path)
        queue_keys.update(r["queue_canonical_key"] for r in read_audit(name)["records"])
    queue_keys.update(r["queue_canonical_key"] for r in read_audit("evidence/source/raw-run-137r-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22.json")["action_decisions"])
    queue_keys &= {r["canonical_key"] for r in read_audit("evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json")["records"]}
    combined_queue = list(queue_keys | {decision["queue_canonical_key"]})
    fresh_rg = fresh_review["verified_global_identity"]
    discovered = {
        "owner_candidate_id_list_sha256":hlist([decision["candidate_id"]]),"owner_route_record_id_list_sha256":hlist([row["source_record_id"]]),
        "owner_source_record_key_list_sha256":hlist([row["source_record_key"]]),"owner_action_key_list_sha256":hlist([decision["action_key"]]),
        "owner_bridge_key_list_sha256":hlist(["|".join((bridge["controller_file"],bridge["method"],bridge["feature_id"]))]),
        "owner_candidate_record_sha256_list_sha256":hlist([decision["candidate_record_sha256"]]),"owner_decision_record_sha256_list_sha256":hlist([decision["decision_record_sha256"]]),
        "owner_queue_id_list_sha256":hlist([decision["queue_id"]]),"owner_queue_key_list_sha256":hlist([decision["queue_canonical_key"]]),
        "new_union_feature_id_list_sha256":hlist([]),"new_route_feature_id_list_sha256":hlist([row["feature_id"]]),"new_page_feature_id_list_sha256":hlist([]),
        "prior_source_record_key_list_sha256":hlist([r["source_record_key"] for r in prior_records]),"prior_source_record_id_list_sha256":hlist([r["source_record_id"] for r in prior_records]),
        "combined_source_record_key_list_sha256":hlist([r["source_record_key"] for r in records]),"combined_source_record_id_list_sha256":hlist([r["source_record_id"] for r in records]),
        "combined_feature_id_list_sha256":hlist([r["feature_id"] for r in records]),"combined_route_feature_id_list_sha256":hlist([r["feature_id"] for r in routes]),
        "combined_page_feature_id_list_sha256":hlist([r["feature_id"] for r in pages]),"combined_route_page_overlap_feature_id_list_sha256":hlist(list({r["feature_id"] for r in routes}&{r["feature_id"] for r in pages})),
        "prior_bridge_key_list_sha256":hlist(prior_bridge_keys),"combined_bridge_key_list_sha256":hlist(bridge_keys),
        "new_reviewed_queue_key_list_sha256":hlist([decision["queue_canonical_key"]]),"prior_reviewed_queue_key_list_sha256":hlist(list(queue_keys)),"combined_reviewed_queue_key_list_sha256":hlist(combined_queue),
        "combined_route_record_id_list_sha256":hlist([r["source_record_id"] for r in routes]),"combined_route_source_record_key_list_sha256":hlist([r["source_record_key"] for r in routes]),
        "combined_page_record_id_list_sha256":hlist([r["source_record_id"] for r in pages]),"combined_page_source_record_key_list_sha256":hlist([r["source_record_key"] for r in pages]),
        "new_overlay_source_records_sha256":hjson([row]),"new_overlay_row_sha256_list_sha256":hlist([row["overlay_row_sha256"]]),
        "new_action_bridges_sha256":hjson([bridge]),"new_action_bridge_row_sha256_list_sha256":hlist([bridge["bridge_row_sha256"]]),
        "reviewed_decision_record_sha256_list_sha256":fresh_rg["decision_record_sha256_list_sha256"],"reviewed_decisions_sha256":fresh_rg["reviewed_decisions_sha256"],
        "synthesis_record_sha256":fresh_rg["synthesis_record_sha256"],"source_packet_expansion_union_manifest_sha256":fresh_rg["source_packet_expansion_union_manifest_sha256"],
        "source_packet_expansions_sha256":fresh_rg["source_packet_expansions_sha256"],"assurance_findings_sha256":fresh_rg["assurance_findings_sha256"],
        "shared_assurance_findings_sha256":fresh_rg["shared_assurance_findings_sha256"],"combined_assurance_findings_sha256":fresh_rg["combined_assurance_findings_sha256"],
        "independent_reviews_sha256":fresh_rg["independent_reviews_sha256"],"independent_review_record_sha256_list_sha256":fresh_rg["independent_review_record_sha256_list_sha256"],
    }
    return discovered | {f"run141r_{key}": value for key, value in fresh_rg.items()}

def main() -> None:
    assert git("rev-parse", "HEAD") == EXPECTED_HEAD
    assert git("show", "-s", "--format=%T", "HEAD") == EXPECTED_TREE
    assert git("branch", "--show-current") == "main"
    assert digest(COHORT) == EXPECTED_COHORT
    assert digest(REVIEW) == EXPECTED_REVIEW
    prior = read_audit("evidence/source/current-run-138-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json")
    cohort = read_audit("evidence/source/root-run-141-outcome-neutral-finance-site-portfolio-overview-route-action-cohort-wave-23.json")
    review = read_audit("evidence/source/raw-run-141r-independent-outcome-neutral-finance-site-portfolio-overview-route-action-review-wave-23.json")
    candidate = cohort["records"][0]
    decision = review["action_decisions"][0]
    assert candidate["candidate_record_sha256"] == hjson({k:v for k,v in candidate.items() if k != "candidate_record_sha256"})
    assert review["decision"]["verdict"] == "GO_ACCEPT_1_OWNER_ROUTE_ACTION_FOR_BOUNDED_LATER_INTEGRATION"
    assert decision["outcome"] == "OWNER_ROUTE_ACTION"
    assert decision["candidate_id"] == candidate["candidate_id"]
    assert decision["page_ownership_authorized"] is False
    route = candidate["route_source"]
    feature = candidate["feature_identity_projection"]
    action = candidate["controller_action"]["primary_method_slice"]
    row = sealed({
        "overlay_mapping_id": "RUN142-ROUTE-01",
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
        "credit_boundary": {k: False for k in ["page_ownership","frontend_caller_ownership","framework_route_reachability","navigation","canonical_object_ownership_correctness","site_authorization_correctness","permission_correctness","privacy_correctness","direct_object_correctness","query_correctness","projection_correctness","period_correctness","allocation_provenance_or_reversal_correctness","utility_true_up_sign_correctness","event_or_downstream_durability_correctness","response_minimization_correctness","lifecycle_correctness","concurrency_correctness","runtime","database","build","application_browser","executed_tests","benchmark","ease","release","pass","final_finding","completion","audit_complete"]},
    }, "overlay_row_sha256")
    bridge = sealed({
        "bridge_id": "RUN142-BRIDGE-01",
        "candidate_id": decision["candidate_id"],
        "candidate_record_sha256": decision["candidate_record_sha256"],
        "decision_record_sha256": decision["decision_record_sha256"],
        "feature_id": decision["candidate_feature_id"],
        "route_record_id": decision["route_record_id"],
        "controller_fqcn": candidate["controller_action"]["controller_fqcn"],
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
        "completion_credit": False,
    }, "bridge_row_sha256")
    out = copy.deepcopy(prior)
    out["schema_version"] = "run-142-reviewed-outcome-neutral-finance-site-portfolio-overview-route-action-ownership-overlay-wave-23-v1"
    out["run_id"] = "RUN-142-REVIEWED-OUTCOME-NEUTRAL-FINANCE-SITE-PORTFOLIO-OVERVIEW-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-23"
    out["status"] = "ONE_REVIEWED_FINANCE_SITE_PORTFOLIO_OVERVIEW_ROUTE_ACTION_OWNER_AND_BRIDGE_INTEGRATED_STATIC_ONLY"
    out["generated_on"] = "2026-08-26"
    out["pins"]["checkpoint_commit"] = EXPECTED_HEAD
    out["pins"]["checkpoint_tree"] = EXPECTED_TREE
    out["pins"]["run141_cohort_sha256"] = EXPECTED_COHORT
    out["pins"]["run141r_review_sha256"] = EXPECTED_REVIEW
    out["pins"]["run141_cohort_generator_sha256"] = "d3cfd34687ba6c6a9b6afecfe9bfc02d2b700b15de881c1ef651877c486fd6a0"
    out["pins"]["run141r_materializer_sha256"] = "41c2b9855fd8a0f6510dbf9e5fcd61c27be4de7178fef7a6a77dbf5b52ffb699"
    out["pins"]["cohort_source_review_packet_sha256"] = "f26d6249445bfaa19a5b8ff193ecd74eefe90c606514ea1705c0181914a9271f"
    out["pins"]["generator"] = str(Path(__file__).resolve().relative_to(ROOT)).replace("\\", "/")
    out["pins"]["generator_sha256"] = digest(Path(__file__).resolve())
    out["pins"]["cohort_generator"] = "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/build-outcome-neutral-finance-site-portfolio-overview-route-action-cohort-wave-23.py"
    out["pins"]["cohort_generator_sha256"] = "d3cfd34687ba6c6a9b6afecfe9bfc02d2b700b15de881c1ef651877c486fd6a0"
    out["pins"]["review_materializer"] = "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-independent-outcome-neutral-finance-site-portfolio-overview-route-action-review-wave-23.py"
    out["pins"]["review_materializer_sha256"] = "41c2b9855fd8a0f6510dbf9e5fcd61c27be4de7178fef7a6a77dbf5b52ffb699"
    assert len(INPUTS) == 25 and len(set(INPUTS)) == 25
    out["pins"]["inputs"] = {name: digest(AUDIT / name) for name in INPUTS}
    assert hjson(out["pins"]["inputs"]) == EXPECTED_INPUT_MAP_SHA256
    run139_path = "evidence/source/current-run-139-reviewed-finance-invoice-index-route-action-reporting-wave-22.json"
    run139 = read_audit(run139_path)
    assert out["pins"]["inputs"][run139_path] == "bdc0b866db9409220bcac7bf66075e8cf89460fb40d61021fe7c98a705597231"
    assert run139["run_id"] == "RUN-139-REVIEWED-FINANCE-INVOICE-INDEX-ROUTE-ACTION-REPORTING-WAVE-22"
    out["baseline"] = {
        "reporting_run_id": run139["run_id"],
        "source_owner_records": 661,
        "route_owner_records": 304,
        "page_owner_records": 357,
        "static_controller_action_bridges": 92,
        "reviewed_queue_surface_rows": 115,
        "pending_unreviewed_queue_surface_rows": 392,
        "reporting_sha256": out["pins"]["inputs"][run139_path],
    }
    out["reviewed_overlay"] = {
        "producer_run_id": cohort["run_id"], "review_run_id": review["run_id"],
        "reviewed_route_actions": 1, "owner_route_actions": 1, "shared_relations": 0,
        "alias_or_redirect": 0, "dead_or_noncanonical": 0, "evidence_gaps": 0,
        "accepted_source_owner_records": 1, "accepted_route_owner_records": 1,
        "accepted_page_owner_records": 0, "accepted_controller_action_bridges": 1,
        "accepted_distinct_feature_ids": 1, "new_distinct_feature_ids": 0,
        "new_route_feature_ids": [decision["candidate_feature_id"]], "new_page_feature_ids": [],
        "reviewed_non_owner_records_preserved": 0, "current_static_overlay_credit_applied": True,
        "page_ownership_inherited": False, "prior_page_owner_context_inherited_or_recredited": False,
        "caller_or_sibling_ownership_used": False, "cohort_sha256": EXPECTED_COHORT, "review_sha256": EXPECTED_REVIEW,
    }
    out["architecture_rule"] = review["architecture_rule"]
    out["source_packet_expansion_preservation"] = review["source_packet_expansion"]
    out["assurance_findings_preservation"] = {
        "action_findings": decision["assurance_findings"],
        "shared_findings": review["shared_assurance_findings"],
        "reconciliation": review["assurance_finding_reconciliation"],
        "correctness_or_downstream_credit_authorized": False,
    }
    out["reviewer_lineage"] = {
        "independent_candidate_reviews": review["independent_candidate_reviews"],
        "synthesis_review": review["synthesis_review"],
        "verified_global_identity": review["verified_global_identity"],
        "verified_counts": review["verified_counts"],
    }
    out["combined_counts"].update({"source_owner_records":662,"route_owner_records":305,"page_owner_records":357,"distinct_feature_ids":256,"route_distinct_feature_ids":64,"page_distinct_feature_ids":242,"route_page_feature_overlap":50,"static_controller_action_bridges":93,"bounded_static_source_ownership_percent":"16.849071","bounded_static_source_residual_records":3267,"residual_explicit_unmapped_routes":2896})
    out["queue_accounting"].update({"reviewed_queue_surface_rows":116,"owner_queue_surface_rows":94,"pending_unreviewed_queue_surface_rows":391,"queue_surfaces_without_ownership":413})
    out["page_context_boundary"] = review["page_sibling_caller_neighbor_reconciliation"]
    out["page_context_boundary"].pop("current_overlay_credit_awarded", None)
    out["page_context_boundary"]["run141r_current_overlay_credit_awarded"] = False
    out["page_context_boundary"]["run142_page_or_correctness_credit_awarded"] = False
    out["noninheritance_boundary"] = {"page_owner_not_inherited_or_recredited":True,"sibling_route_not_inherited":True,"callers_not_inherited":True,"neighbor_index_79_not_recredited":True,"next_index_80_not_selected_or_credited":True,"current_overlay_correctness_and_downstream_credit":False}
    out["overlay_source_records"] = [row]
    out["new_static_controller_action_bridges"] = [bridge]
    out["reviewed_non_owner_outcomes"] = []
    baseline = read_audit("evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json")
    overlay_names = [name for name in INPUTS if name.startswith("evidence/source/current-run-") and "reporting" not in name]
    prior_records = list(baseline["records"])
    prior_bridges = []
    for name in overlay_names:
        overlay = read_audit(name)
        prior_records.extend(overlay.get("overlay_source_records", []))
        prior_bridges.extend(overlay.get("static_controller_action_bridges", []))
        prior_bridges.extend(overlay.get("new_static_controller_action_bridges", []))
    assert len(prior_records) == 661 and len(prior_bridges) == 92
    combined_records = prior_records + [row]
    assert len({r["source_record_key"] for r in combined_records}) == len(combined_records) == 662
    assert len({r["source_record_id"] for r in combined_records}) == len(combined_records)
    route_records = [r for r in combined_records if r["surface"] == "ROUTE_SOURCE_RECORD"]
    page_records = [r for r in combined_records if r["surface"] == "PAGE_ROOT_SOURCE_RECORD"]
    prior_bridge_keys = ["|".join([b["controller_file"], b["method"], b["feature_id"]]) for b in prior_bridges]
    combined_bridge_keys = prior_bridge_keys + ["|".join(decision["bridge_key"])]
    assert len(set(combined_bridge_keys)) == len(combined_bridge_keys) == 93
    queue_keys: set[str] = set()
    for chain in read_audit("evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json")["records"]:
        queue_keys.update(("route|" + chain["route_source"]["route_record_id"], "page|" + chain["page_source"]["page_record_id"]))
    for number in ("098", "102"):
        overlay = next(read_audit(n) for n in overlay_names if f"current-run-{number}-" in n)
        queue_keys.update(("route|" if r["surface"] == "ROUTE_SOURCE_RECORD" else "page|") + r["source_record_id"] for r in overlay["overlay_source_records"])
        queue_keys.update("route|" + r["route_record_id"] for r in overlay.get("reviewed_non_owner_outcomes", []))
    run110 = next(read_audit(n) for n in overlay_names if "current-run-110-" in n)
    queue_keys.update(r["queue_canonical_key"] for r in run110["new_reviewed_queue_outcomes"])
    for number in ("113", "121", "129", "133"):
        cohort_name = next(n for n in INPUTS if f"root-run-{number}-" in n)
        queue_keys.update(r["queue_canonical_key"] for r in read_audit(cohort_name)["records"])
    run137_review = read_audit("evidence/source/raw-run-137r-independent-outcome-neutral-finance-invoice-index-route-action-review-wave-22.json")
    queue_keys.update(r["queue_canonical_key"] for r in run137_review["action_decisions"])
    frozen_queue_keys = {r["canonical_key"] for r in read_audit("evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json")["records"]}
    queue_keys &= frozen_queue_keys
    assert len(queue_keys) == 115, len(queue_keys)
    combined_queue_keys = list(queue_keys | {decision["queue_canonical_key"]})
    assert len(combined_queue_keys) == 116
    rg = review["verified_global_identity"]
    overlay_identity = {
        "owner_candidate_id_list_sha256":hlist([decision["candidate_id"]]),
        "owner_route_record_id_list_sha256":hlist([decision["route_record_id"]]),
        "owner_source_record_key_list_sha256":hlist([decision["owner_source_record_key"]]),
        "owner_action_key_list_sha256":hlist([decision["action_key"]]),
        "owner_bridge_key_list_sha256":hlist(["|".join(decision["bridge_key"])]),
        "owner_candidate_record_sha256_list_sha256":hlist([decision["candidate_record_sha256"]]),
        "owner_decision_record_sha256_list_sha256":hlist([decision["decision_record_sha256"]]),
        "owner_queue_id_list_sha256":hlist([decision["queue_id"]]),
        "owner_queue_key_list_sha256":hlist([decision["queue_canonical_key"]]),
        "new_union_feature_id_list_sha256":hlist([]),
        "new_route_feature_id_list_sha256":hlist([decision["candidate_feature_id"]]),
        "new_page_feature_id_list_sha256":hlist([]),
        "prior_source_record_key_list_sha256":hlist([r["source_record_key"] for r in prior_records]),
        "prior_source_record_id_list_sha256":hlist([r["source_record_id"] for r in prior_records]),
        "combined_source_record_key_list_sha256":hlist([r["source_record_key"] for r in combined_records]),
        "combined_source_record_id_list_sha256":hlist([r["source_record_id"] for r in combined_records]),
        "combined_feature_id_list_sha256":hlist([r["feature_id"] for r in combined_records]),
        "combined_route_feature_id_list_sha256":hlist([r["feature_id"] for r in route_records]),
        "combined_page_feature_id_list_sha256":hlist([r["feature_id"] for r in page_records]),
        "combined_route_page_overlap_feature_id_list_sha256":hlist(list({r["feature_id"] for r in route_records} & {r["feature_id"] for r in page_records})),
        "prior_bridge_key_list_sha256":hlist(prior_bridge_keys),
        "combined_bridge_key_list_sha256":hlist(combined_bridge_keys),
        "new_reviewed_queue_key_list_sha256":hlist([decision["queue_canonical_key"]]),
        "prior_reviewed_queue_key_list_sha256":hlist(list(queue_keys)),
        "combined_reviewed_queue_key_list_sha256":hlist(combined_queue_keys),
        "combined_route_record_id_list_sha256":hlist([r["source_record_id"] for r in route_records]),
        "combined_route_source_record_key_list_sha256":hlist([r["source_record_key"] for r in route_records]),
        "combined_page_record_id_list_sha256":hlist([r["source_record_id"] for r in page_records]),
        "combined_page_source_record_key_list_sha256":hlist([r["source_record_key"] for r in page_records]),
        "new_overlay_source_records_sha256":hjson([row]),
        "new_overlay_row_sha256_list_sha256":hlist([row["overlay_row_sha256"]]),
        "new_action_bridges_sha256":hjson([bridge]),
        "new_action_bridge_row_sha256_list_sha256":hlist([bridge["bridge_row_sha256"]]),
        "reviewed_decision_record_sha256_list_sha256":rg["decision_record_sha256_list_sha256"],
        "reviewed_decisions_sha256":rg["reviewed_decisions_sha256"],
        "synthesis_record_sha256":rg["synthesis_record_sha256"],
        "source_packet_expansion_union_manifest_sha256":rg["source_packet_expansion_union_manifest_sha256"],
        "source_packet_expansions_sha256":rg["source_packet_expansions_sha256"],
        "assurance_findings_sha256":rg["assurance_findings_sha256"],
        "shared_assurance_findings_sha256":rg["shared_assurance_findings_sha256"],
        "combined_assurance_findings_sha256":rg["combined_assurance_findings_sha256"],
        "independent_reviews_sha256":rg["independent_reviews_sha256"],
        "independent_review_record_sha256_list_sha256":rg["independent_review_record_sha256_list_sha256"],
    }
    out["identity"] = overlay_identity | {f"run141r_{key}": value for key, value in rg.items()}
    out["identity_discovery"] = rediscover_identity(row, bridge)
    out["outcome_conservation"] = {
        "reviewed_outcomes_equation":"1 = 1 owner + 0 shared + 0 alias + 0 dead + 0 evidence gap",
        "bounded_source_equation":"3929 = 662 owner + 3267 non-owner residual",
        "owner_surface_equation":"662 = 305 route + 357 page",
        "feature_union_equation":"256 = 64 route + 242 page - 50 overlap",
        "route_universe_equation":"3218 = 305 owner + 12 shared + 5 alias + 0 dead + 2896 residual",
        "evidence_gap_is_tagged_within_2896_route_residual":True,
        "page_universe_equation":"711 = 357 owner + 9 shared + 0 alias + 0 dead + 345 residual",
        "evidence_gap_is_tagged_within_345_page_residual":True,
        "queue_equation":"507 = 116 reviewed + 391 pending",
        "reviewed_queue_equation":"116 = 94 owner + 10 shared + 5 alias + 0 dead + 7 evidence gap",
        "queue_without_ownership_equation":"413 = 391 pending + 10 shared + 5 alias + 0 dead + 7 evidence gap",
        "reviewed_route_actions":1,"owner_route_actions":1,"shared_relations":0,"alias_or_redirect":0,"dead_or_noncanonical":0,"evidence_gaps":0,
    }
    out["projection_reconciliation"] = {
        "run141r_projection_credit_awarded":False,
        "run142_current_static_overlay_credit_applied":True,
        "current_source_owner_records":662,"current_route_owner_records":305,"current_page_owner_records":357,
        "current_static_controller_action_bridges":93,"current_reviewed_queue_surface_rows":116,
        "current_pending_unreviewed_queue_surface_rows":391,"correctness_or_downstream_credit_authorized":False,
        "rule":"RUN141R authorized a projection only; RUN142 applies exactly one route owner and bridge with no page or correctness credit.",
        "new_union_feature_ids":[],"new_route_feature_ids":[decision["candidate_feature_id"]],"new_page_feature_ids":[],
        "run141r_feature_set_reconciliation_sha256":rg["feature_set_reconciliation_sha256"],
    }
    out["credit_boundary"] = {k: False for k in out["credit_boundary"]}
    out["credit_boundary"].pop("event_and_downstream_durability_correctness", None)
    for key in ("period_correctness","allocation_provenance_or_reversal_correctness","utility_true_up_sign_correctness","event_or_downstream_durability_correctness"):
        out["credit_boundary"][key] = False
    out["credit_boundary"].update({"STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD":True,"STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION":True})
    assert [k for k, value in out["credit_boundary"].items() if value] == ["STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD", "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION"]
    assert out["combined_counts"]["source_owner_records"] + out["combined_counts"]["bounded_static_source_residual_records"] == out["combined_counts"]["bounded_static_source_denominator"]
    assert out["queue_accounting"]["reviewed_queue_surface_rows"] + out["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == out["queue_accounting"]["direct_exact_queue_records"]
    assert all(value is True for key, value in out["noninheritance_boundary"].items() if key != "current_overlay_correctness_and_downstream_credit")
    assert out["noninheritance_boundary"]["current_overlay_correctness_and_downstream_credit"] is False
    generator_rel = str(Path(__file__).resolve().relative_to(ROOT)).replace("\\", "/")
    output_rel = str(OUTPUT.relative_to(ROOT)).replace("\\", "/")
    out["mutation_attestation"] = {"application_source_changed":False,"matrix_changed":False,"reports_changed":False,"dashboard_generator_changed":False,"dashboard_html_changed":False,"runtime_or_external_system_changed":False,"audit_artifacts_only":True,"whole_repository_status_scope_asserted":True,"only_expected_untracked_run142_artifacts_present":True,"expected_status_paths":[generator_rel,output_rel],"application_source_files_changed":0,"test_files_changed":0,"matrix_files_changed":0,"dashboard_or_reporting_files_changed":0,"only_run142_generator_and_output_written":True}
    out["artifact_completion_test_met"] = True
    out["audit_completion_test_met"] = False
    out["wrote_files"] = [generator_rel, output_rel]
    # Final input-derived seals. These intentionally repeat no producer constants without
    # resolving them against a pinned source structure first.
    assert out["schema_version"] == "run-142-reviewed-outcome-neutral-finance-site-portfolio-overview-route-action-ownership-overlay-wave-23-v1"
    assert out["run_id"] == "RUN-142-REVIEWED-OUTCOME-NEUTRAL-FINANCE-SITE-PORTFOLIO-OVERVIEW-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-23"
    assert out["status"] == "ONE_REVIEWED_FINANCE_SITE_PORTFOLIO_OVERVIEW_ROUTE_ACTION_OWNER_AND_BRIDGE_INTEGRATED_STATIC_ONLY"
    assert out["pins"]["generator"] == generator_rel and out["pins"]["generator_sha256"] == digest(Path(__file__).resolve())
    for treeish, expected in {
        "a0493442b9e392d324055c35bf25b69421dc2d35^{tree}":"f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1",
        "a0493442b9e392d324055c35bf25b69421dc2d35:app":"92c8425a7cb15a92609c69a8c2f26bbda4f178b7",
        "a0493442b9e392d324055c35bf25b69421dc2d35:routes":"9b7f78510d970db64ea3a6540e8a36b8700bf272",
        "a0493442b9e392d324055c35bf25b69421dc2d35:resources/js":"1671a7551c004571c48bb00c34522928e6f1f173",
        "a0493442b9e392d324055c35bf25b69421dc2d35:resources/js/pages":"e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e",
        "a0493442b9e392d324055c35bf25b69421dc2d35:tests":"fef0122b31fdccbe2f9f805f7515666c74e2880a",
    }.items(): assert git("rev-parse", treeish) == expected
    assert out["pins"]["application_commit"] == "a0493442b9e392d324055c35bf25b69421dc2d35"
    assert out["pins"]["application_tree"] == "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
    assert out["pins"]["app_tree"] == "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
    assert out["pins"]["routes_tree"] == "9b7f78510d970db64ea3a6540e8a36b8700bf272"
    assert out["pins"]["resources_js_tree"] == "1671a7551c004571c48bb00c34522928e6f1f173"
    assert out["pins"]["resources_js_pages_tree"] == "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
    assert out["pins"]["tests_tree"] == "fef0122b31fdccbe2f9f805f7515666c74e2880a"
    assert digest(ROOT / out["pins"]["cohort_generator"]) == out["pins"]["cohort_generator_sha256"] == "d3cfd34687ba6c6a9b6afecfe9bfc02d2b700b15de881c1ef651877c486fd6a0"
    assert digest(ROOT / out["pins"]["review_materializer"]) == out["pins"]["review_materializer_sha256"] == "41c2b9855fd8a0f6510dbf9e5fcd61c27be4de7178fef7a6a77dbf5b52ffb699"
    assert cohort["pins"]["generator"] == "generators/build-outcome-neutral-finance-site-portfolio-overview-route-action-cohort-wave-23.py" and cohort["pins"]["generator_sha256"] == out["pins"]["cohort_generator_sha256"]
    assert review["pins"]["cohort"] == "evidence/source/root-run-141-outcome-neutral-finance-site-portfolio-overview-route-action-cohort-wave-23.json" and review["pins"]["cohort_sha256"] == EXPECTED_COHORT
    assert review["pins"]["materializer"] == "generators/materialize-independent-outcome-neutral-finance-site-portfolio-overview-route-action-review-wave-23.py" and review["pins"]["materializer_sha256"] == out["pins"]["review_materializer_sha256"]
    assert cohort["source_review_packet"]["source_review_packet_sha256"] == out["pins"]["cohort_source_review_packet_sha256"] == "f26d6249445bfaa19a5b8ff193ecd74eefe90c606514ea1705c0181914a9271f"
    assert out["architecture_rule"] == review["architecture_rule"]
    assert "one operating organisation across multiple Sites" in out["architecture_rule"]
    assert out["wrote_files"] == [generator_rel, output_rel]
    run139_counts = run139["counts"]
    for key, expected in {
        "source_owner_records":661,"route_owner_records":304,"page_owner_records":357,
        "static_controller_action_bridges":92,"reviewed_queue_surface_rows":115,
        "pending_unreviewed_queue_surface_rows":392,
    }.items():
        assert run139_counts[key] == expected and out["baseline"][key] == expected
    feature_class_counts = Counter({r["feature_id"]: r["feature_class"] for r in combined_records}.values())
    assert feature_class_counts == {"H":234,"D":22}
    assert len(route_records) == 305 and len(page_records) == 357
    assert len(set(r["feature_id"] for r in combined_records)) == 256
    assert len(set(r["feature_id"] for r in route_records)) == 64
    assert len(set(r["feature_id"] for r in page_records)) == 242
    assert len({r["feature_id"] for r in route_records} & {r["feature_id"] for r in page_records}) == 50
    for key, expected in {
        "distinct_H_feature_ids":234,"distinct_D_feature_ids":22,
        "semantic_shared_routes":12,"reviewed_alias_routes":5,"reviewed_dead_routes":0,
        "evidence_gap_routes_tagged_within_residual":7,"residual_unadjudicated_page_roots":345,
        "semantic_shared_page_roots":9,"reviewed_alias_page_roots":0,"reviewed_dead_page_roots":0,
        "evidence_gap_page_roots_tagged_within_residual":1,
    }.items():
        assert run139_counts[key] == expected and out["combined_counts"][key] == expected
    assert out["combined_counts"] == {
        "source_owner_records":662,"route_owner_records":305,"page_owner_records":357,
        "distinct_feature_ids":256,"distinct_H_feature_ids":234,"distinct_D_feature_ids":22,
        "route_distinct_feature_ids":64,"page_distinct_feature_ids":242,"route_page_feature_overlap":50,
        "static_controller_action_bridges":93,"bounded_static_source_denominator":3929,
        "bounded_static_source_ownership_percent":"16.849071","bounded_static_source_residual_records":3267,
        "residual_explicit_unmapped_routes":2896,"semantic_shared_routes":12,"reviewed_alias_routes":5,
        "reviewed_dead_routes":0,"evidence_gap_routes_tagged_within_residual":7,
        "residual_unadjudicated_page_roots":345,"semantic_shared_page_roots":9,
        "reviewed_alias_page_roots":0,"reviewed_dead_page_roots":0,"evidence_gap_page_roots_tagged_within_residual":1,
    }
    expansion = out["source_packet_expansion_preservation"]
    assert len(expansion["expanded_files"]) == review["verified_counts"]["source_packet_expansion_files"] == 24
    assert sum(r["original_packet_present"] for r in expansion["expanded_files"]) == 6
    assert sum(not r["original_packet_present"] for r in expansion["expanded_files"]) == 18
    original_packet_paths = {r["path"] for r in cohort["source_review_packet"]["required_source_files"]}
    assert all(r["original_packet_present"] is (r["path"] in original_packet_paths) for r in expansion["expanded_files"])
    assert hjson(expansion["expanded_files"]) == rg["source_packet_expansions_sha256"]
    assert expansion["canonical_union_manifest_sha256"] == rg["source_packet_expansion_union_manifest_sha256"]
    assert expansion["locus_corrections"] == [{"reviewer_task_path":"/root/run141_candidate_reviewer_a","path":"app/Domain/Finance/Services/JournalPostingService.php","requested_locus":"404-498","corrected_locus":"404-493","reason":"frozen file ends at line 493","outcome_changed":False}]
    assert hjson(expansion["locus_corrections"]) == rg["source_packet_expansion_locus_correction_records_sha256"]
    assert len(expansion["requested_but_not_fully_inspected"]) == 3
    assert hjson(expansion["requested_but_not_fully_inspected"]) == rg["unresolved_correctness_only_boundaries_sha256"]
    for expanded in expansion["expanded_files"]:
        frozen = git_bytes("show", f"{out['pins']['application_commit']}:{expanded['path']}")
        assert hashlib.sha256(frozen).hexdigest() == expanded["sha256"]
        assert git("rev-parse", f"{out['pins']['application_commit']}:{expanded['path']}") == expanded["application_commit_blob_id"] == expanded["head_blob_id"]
        line_count = len(frozen.decode("utf-8").splitlines())
        for locus in expanded["expanded_review_loci"]:
            locus_path, bounds = locus.rsplit(":", 1)
            assert locus_path == expanded["path"]
            start, end = ([int(part) for part in bounds.split("-", 1)] if "-" in bounds else (int(bounds), int(bounds)))
            assert 1 <= start <= end <= line_count
    preservation = out["assurance_findings_preservation"]
    lineage = out["reviewer_lineage"]
    assert len(preservation["action_findings"]) == 9 and len(preservation["shared_findings"]) == 3
    assert hjson(preservation["action_findings"]) == rg["assurance_findings_sha256"]
    assert hjson(preservation["shared_findings"]) == rg["shared_assurance_findings_sha256"]
    assert hjson(preservation["reconciliation"]) == rg["assurance_finding_reconciliation_sha256"]
    assert preservation["reconciliation"]["total_input_observations"] == 17
    mapping = preservation["reconciliation"]["input_rows"]
    mapping_tuples = [(r["reviewer_task_path"],r["local_assurance_observation_id"],r["assurance_family_id"],r["output_finding_id"],r["output_scope"]) for r in mapping]
    assert len(mapping_tuples) == 17 and hjson(mapping) == "baf95ef78ccad1bd9fab6e16f37e576d4bcebf3b73176c4beb62369f7fea1e27"
    assert preservation["reconciliation"]["mapping_rows_sha256"] == hjson(mapping)
    observation_ids = [(r["reviewer_task_path"], r["local_assurance_observation_id"]) for r in mapping]
    assert len(mapping) == len(set(observation_ids)) == 17
    declared_observations = {(r["reviewer_task_path"], observation) for r in lineage["independent_candidate_reviews"] for observation in r["local_assurance_observation_ids"]}
    assert set(observation_ids) == declared_observations
    action_finding_ids = {f["finding_id"] for f in preservation["action_findings"]}
    shared_finding_ids = {f["finding_id"] for f in preservation["shared_findings"]}
    assert {r["output_finding_id"] for r in mapping if r["output_scope"] == "ACTION"} == action_finding_ids
    assert {r["output_finding_id"] for r in mapping if r["output_scope"] == "SHARED"} <= shared_finding_ids
    assert len(lineage["independent_candidate_reviews"]) == 2
    assert [r["review_id"] for r in lineage["independent_candidate_reviews"]] == ["RUN141R-INDEPENDENT-REVIEW-A","RUN141R-INDEPENDENT-REVIEW-B"]
    assert hjson(lineage["independent_candidate_reviews"]) == rg["independent_reviews_sha256"]
    assert lineage["synthesis_review"]["synthesis_record_sha256"] == rg["synthesis_record_sha256"]
    assert lineage["verified_global_identity"] == rg and lineage["verified_counts"] == review["verified_counts"]
    assert [r["blinded_review"] for r in lineage["independent_candidate_reviews"]] == [True, True]
    assert [r["question_disposition_count"] for r in lineage["independent_candidate_reviews"]] == [8, 8]
    assert [r["requested_expansion_path_count"] for r in lineage["independent_candidate_reviews"]] == [24, 15]
    assert [r["local_assurance_observation_id_count"] for r in lineage["independent_candidate_reviews"]] == [9, 8]
    assert lineage["synthesis_review"]["reviewer_task_path"] not in {r["reviewer_task_path"] for r in lineage["independent_candidate_reviews"]}
    assert lineage["synthesis_review"]["outcome_variables"] == {"O":1,"S":0,"A":0,"D":0,"E":0}
    for reviewer in lineage["independent_candidate_reviews"]:
        assert reviewer["independent_review_record_sha256"] == hjson({k:v for k,v in reviewer.items() if k != "independent_review_record_sha256"})
        assert reviewer["question_dispositions_sha256"] == hjson(reviewer["question_dispositions"])
        assert reviewer["local_assurance_observation_id_list_sha256"] == hlist(reviewer["local_assurance_observation_ids"])
        assert reviewer["requested_expansion_path_list_sha256"] == hlist(reviewer["requested_expansion_paths"])
    assert lineage["synthesis_review"]["synthesis_record_sha256"] == hjson({k:v for k,v in lineage["synthesis_review"].items() if k != "synthesis_record_sha256"})
    assert decision["decision_record_sha256"] == hjson({k:v for k,v in decision.items() if k != "decision_record_sha256"})
    assert set(review["verified_count_evidence"]) == set(review["verified_counts"])
    assert all(review["verified_count_evidence"][key] for key in review["verified_counts"])
    for key, pointers in review["verified_count_evidence"].items():
        resolved = [value for pointer in pointers for value in resolve_pointer(review, pointer)]
        assert resolved or review["verified_counts"][key] == 0
    assert hjson(review["verified_counts"]) == rg["verified_counts_sha256"]
    assert hjson(review["verified_count_evidence"]) == rg["verified_count_evidence_sha256"]
    page = out["page_context_boundary"]
    assert page["selected_action_evidence"]["literal_inertia_page_callsite_count"] == 0
    assert page["selected_action_evidence"]["returns_json_response"] is True
    assert page["existing_page_owner_context"]["owner_row_id"] == "RUN086-PAGE-MAP-0313"
    assert page["existing_page_owner_context"]["source_record_id"] == "PAGE-ROOT-FC2C5F5706FD9066"
    assert page["separate_page_route_sibling"]["queue_index_zero_based"] == 40
    assert page["separate_page_route_sibling"]["queue_id"] == "RUN090-ROUTE-0041" and page["separate_page_route_sibling"]["route_record_id"] == "RUN077-ROUTE-0418"
    assert len(page["page_path_caller_contexts"]) == 3
    assert page["excluded_immediate_raw_neighbor"]["queue_index_zero_based"] == 79 and page["excluded_immediate_raw_neighbor"]["queue_id"] == "RUN090-ROUTE-0080" and page["excluded_immediate_raw_neighbor"]["route_record_id"] == "RUN077-ROUTE-0688" and page["excluded_immediate_raw_neighbor"]["recredit_authorized"] is False
    assert page["next_pending_boundary"]["queue_index_zero_based"] == 80 and page["next_pending_boundary"]["queue_id"] == "RUN090-ROUTE-0081" and page["next_pending_boundary"]["route_record_id"] == "RUN077-ROUTE-0689" and page["next_pending_boundary"]["credit_awarded"] is False
    assert page["run141r_current_overlay_credit_awarded"] is False and page["run142_page_or_correctness_credit_awarded"] is False
    assert page["page_ownership_inherited_reassigned_or_recredited"] is False and page["sibling_route_identity_or_outcome_inherited"] is False and page["caller_presence_preselected_route_outcome"] is False
    assert page["excluded_adjacent_row_recredited"] is False and page["next_pending_boundary_changed_or_credited"] is False
    assert page["existing_page_owner_context"]["ownership_inheritable_to_run141"] is False and page["existing_page_owner_context"]["route_or_correctness_credit_inheritable_to_run141"] is False
    assert page["separate_page_route_sibling"]["selected_or_credited_by_run141"] is False and page["separate_page_route_sibling"]["identity_or_outcome_inheritable_to_selected_api_action"] is False
    assert all(caller["selected_api_route_or_page_ownership_inheritable"] is False for caller in page["page_path_caller_contexts"])
    baseline_page = next(r for r in read_audit("evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json")["records"] if r["source_record_id"] == "PAGE-ROOT-FC2C5F5706FD9066")
    assert baseline_page["mapping_id"] == page["existing_page_owner_context"]["owner_row_id"]
    assert baseline_page["source_record_key"] == page["existing_page_owner_context"]["source_record_key"]
    assert baseline_page["ledger_row_sha256"] == hjson({k:v for k,v in baseline_page.items() if k != "ledger_row_sha256"}) == page["existing_page_owner_context"]["owner_row_sha256"]
    queue_rows = read_audit("evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json")["records"]
    sibling = queue_rows[40]
    assert (sibling["queue_id"],sibling["source_record_id"],sibling["candidate_feature_id"]) == (page["separate_page_route_sibling"]["queue_id"],page["separate_page_route_sibling"]["route_record_id"],"CAP-FIN-SITE-PORTFOLIO-OVERVIEW")
    for caller in page["page_path_caller_contexts"]:
        caller_bytes = git_bytes("show", f"{out['pins']['application_commit']}:{caller['source_file']}")
        caller_lines = caller_bytes.decode("utf-8").splitlines()
        line_number = int(caller["source_anchor"].rsplit(":",1)[1])
        assert hashlib.sha256(caller_bytes).hexdigest() == caller["source_file_sha256"]
        assert git("rev-parse", f"{out['pins']['application_commit']}:{caller['source_file']}") == caller["source_file_blob_id"]
        assert caller_lines[line_number-1].strip() == caller["source_line"].strip()
        assert hashlib.sha256(caller_lines[line_number-1].encode()).hexdigest() == caller["source_line_sha256"]
    neighbor = queue_rows[79]
    assert neighbor["queue_record_sha256"] == "211f64b0be95130eb5aef8d196032b161102565f34c1568d1adbe0f78742b151"
    assert (neighbor["queue_id"],neighbor["source_record_id"]) == (page["excluded_immediate_raw_neighbor"]["queue_id"],page["excluded_immediate_raw_neighbor"]["route_record_id"])
    run098 = read_audit("evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json")
    neighbor_owner = next(r for r in run098["overlay_source_records"] if r["source_record_id"] == neighbor["source_record_id"])
    neighbor_bridge = next(r for r in run098["new_static_controller_action_bridges"] if r["bridge_id"] == "RUN098-BRIDGE-07")
    assert neighbor_owner["static_source_feature_ownership_credit"] is True and neighbor_bridge["static_controller_action_bridge_credit"] is True
    next_row = queue_rows[80]
    assert next_row["queue_record_sha256"] == "b73b6a2cf4340520554c6725d701e26f1b313334e8025d6db4f5e7de51392fda"
    assert (next_row["queue_id"],next_row["source_record_id"]) == (page["next_pending_boundary"]["queue_id"],page["next_pending_boundary"]["route_record_id"])
    fresh_reviews = lineage["independent_candidate_reviews"]
    fresh_counts = {
        "independent_candidate_reviews":len(fresh_reviews),
        "cohort_synthesis_reviews":1,
        "total_fresh_semantic_reviews":len(fresh_reviews)+1,
        "unique_reviewed_candidates":len({r["candidate_id"] for r in fresh_reviews}),
        "reviewed_route_actions":len(review["action_decisions"]),
        "owner_route_actions":sum(r["outcome"] == "OWNER_ROUTE_ACTION" for r in review["action_decisions"]),
        "accepted_route_records":sum(r["route_ownership_authorized"] for r in review["action_decisions"]),
        "accepted_controller_action_bridges":sum(r["controller_action_bridge_authorized"] for r in review["action_decisions"]),
        "accepted_page_records":sum(r["page_ownership_authorized"] for r in review["action_decisions"]),
        "accepted_distinct_feature_ids":len({r["candidate_feature_id"] for r in review["action_decisions"]}),
        "new_distinct_feature_ids":len({r["candidate_feature_id"] for r in review["action_decisions"]}-{r["feature_id"] for r in prior_records}),
        "selected_controller_literal_inertia_page_callsites":page["selected_action_evidence"]["literal_inertia_page_callsite_count"],
        "existing_page_owner_context_rows":int(bool(page["existing_page_owner_context"])),
        "separate_page_route_sibling_context_rows":int(bool(page["separate_page_route_sibling"])),
        "page_path_caller_contexts":len(page["page_path_caller_contexts"]),
        "selected_api_frontend_exact_caller_occurrences":page["selected_api_exact_frontend_caller_occurrences"],
        "source_packet_expansion_files":len(expansion["expanded_files"]),
        "source_packet_expansion_existing_files":sum(r["original_packet_present"] for r in expansion["expanded_files"]),
        "source_packet_expansion_new_files":sum(not r["original_packet_present"] for r in expansion["expanded_files"]),
        "source_packet_expansion_locus_corrections":len(expansion["locus_corrections"]),
        "independent_question_dispositions":sum(r["question_disposition_count"] for r in fresh_reviews),
        "reviewer_requested_expansion_references":sum(r["requested_expansion_path_count"] for r in fresh_reviews),
        "local_assurance_observations":sum(r["local_assurance_observation_id_count"] for r in fresh_reviews),
        "assurance_reconciliation_input_rows":len(mapping),
        "deduplicated_assurance_families":len({r["assurance_family_id"] for r in mapping if r["output_scope"] == "ACTION"}),
        "shared_assurance_findings":len(preservation["shared_findings"]),
        "assurance_evidence_records":len(preservation["action_findings"])+len(preservation["shared_findings"]),
        "mapped_action_assurance_outputs":len({r["output_finding_id"] for r in mapping if r["output_scope"] == "ACTION"}),
        "mapped_shared_assurance_outputs":len({r["output_finding_id"] for r in mapping if r["output_scope"] == "SHARED"}),
        "unique_mapped_assurance_outputs":len({r["output_finding_id"] for r in mapping}),
        "unmapped_assurance_inputs":len(declared_observations-set(observation_ids)),
        "multiply_mapped_assurance_inputs":len(mapping)-len(set(observation_ids)),
        "reviewer_written_files":sum(r["reviewer_wrote_files"] for r in fresh_reviews)+int(lineage["synthesis_review"]["reviewer_wrote_files"]),
        "matrix_rows_changed":0,
        "matrix_cells_changed":0,
    }
    assert len(fresh_counts) == 35 and fresh_counts == review["verified_counts"]
    assert set(review["verified_count_evidence"]) == set(fresh_counts)
    assert out["identity_discovery"] == out["identity"] and len(out["identity"]) == 91
    assert out["outcome_conservation"]["bounded_source_equation"] == f"3929 = {len(combined_records)} owner + {3929-len(combined_records)} non-owner residual"
    assert out["outcome_conservation"]["owner_surface_equation"] == f"{len(combined_records)} = {len(route_records)} route + {len(page_records)} page"
    assert out["outcome_conservation"]["queue_equation"] == f"507 = {len(combined_queue_keys)} reviewed + {507-len(combined_queue_keys)} pending"
    assert out["projection_reconciliation"]["current_source_owner_records"] == len(combined_records)
    assert out["projection_reconciliation"]["current_static_controller_action_bridges"] == len(combined_bridge_keys)
    assert out["projection_reconciliation"]["run141r_feature_set_reconciliation_sha256"] == rg["feature_set_reconciliation_sha256"]
    assert row["overlay_row_sha256"] == hjson({k:v for k,v in row.items() if k != "overlay_row_sha256"})
    assert bridge["bridge_row_sha256"] == hjson({k:v for k,v in bridge.items() if k != "bridge_row_sha256"})
    assert row["candidate_id"] == candidate["candidate_id"] == decision["candidate_id"]
    assert decision["candidate_record_sha256"] == candidate["candidate_record_sha256"] == hjson({k:v for k,v in candidate.items() if k != "candidate_record_sha256"})
    assert len(cohort["records"]) == len(review["action_decisions"]) == 1
    assert review["run_id"] == "RUN-141R-INDEPENDENT-OUTCOME-NEUTRAL-FINANCE-SITE-PORTFOLIO-OVERVIEW-ROUTE-ACTION-REVIEW-WAVE-23"
    assert review["status"] == "GO_TWO_BLINDED_REVIEWS_AND_DISTINCT_SYNTHESIS_COMPLETE_ONE_BOUNDED_OWNER_ZERO_DOWNSTREAM_CREDIT"
    assert decision["route_ownership_authorized"] is True and decision["controller_action_bridge_authorized"] is True
    assert decision["page_ownership_authorized"] is False and decision["current_overlay_credit_awarded"] is False
    assert decision["site_permission_privacy_direct_object_query_projection_period_lifecycle_concurrency_correctness_authorized"] is False
    assert review["decision"]["bounded_overlay_authorized"] is True and review["decision"]["current_overlay_credit_awarded"] is False and review["decision"]["correctness_or_downstream_credit_authorized"] is False
    assert len(out["overlay_source_records"]) == len(out["new_static_controller_action_bridges"]) == 1
    assert row["source_record_id"] == "RUN077-ROUTE-0669" and row["feature_id"] == "CAP-FIN-SITE-PORTFOLIO-OVERVIEW"
    frozen_route_lines = git_bytes("show", f"{out['pins']['application_commit']}:{row['source']['route_file']}").decode("utf-8").splitlines()
    statement_raw = frozen_route_lines[780].lstrip() + "\n" + frozen_route_lines[781]
    assert row["source"]["statement_sha256"] == hashlib.sha256(statement_raw.encode()).hexdigest()
    assert row["source"]["source_key"] == "routes/finance.php:781:9:get:258"
    assert row["source"]["route_method"] == "get" and row["source"]["literal_uri"] == "/sites/overview" and row["source"]["literal_route_name"] == "sites.overview"
    assert row["source"]["static_group_context"]["derived_name"] == "finance.api.sites.overview" and row["source"]["static_group_context"]["derived_uri"] == "/finance/api/sites/overview"
    assert bridge["method"] == "sitesOverview" and bridge["feature_id"] == row["feature_id"]
    assert row["source_record_key"] == decision["owner_source_record_key"]
    assert decision["action_key"] == candidate["action_key"]
    assert [bridge["controller_file"],bridge["method"],bridge["feature_id"]] == decision["bridge_key"]
    assert all(row["credit_boundary"][key] is False for key in ("period_correctness","allocation_provenance_or_reversal_correctness","utility_true_up_sign_correctness","event_or_downstream_durability_correctness","lifecycle_correctness"))
    assert all(out["credit_boundary"][key] is False for key in ("period_correctness","allocation_provenance_or_reversal_correctness","utility_true_up_sign_correctness","event_or_downstream_durability_correctness"))
    assert out["queue_accounting"] == {"direct_exact_queue_records":507,"reviewed_queue_surface_rows":116,"owner_queue_surface_rows":94,"shared_queue_surface_rows":10,"alias_queue_surface_rows":5,"dead_queue_surface_rows":0,"evidence_gap_queue_surface_rows":7,"pending_unreviewed_queue_surface_rows":391,"queue_surfaces_without_ownership":413,"new_reviewed_route_surface_rows":1,"new_owner_route_surface_rows":1,"new_shared_route_surface_rows":0,"new_alias_route_surface_rows":0,"new_dead_route_surface_rows":0,"new_evidence_gap_route_surface_rows":0,"wholesale_queue_ownership_authorized":False}
    for key in ("direct_exact_queue_records","shared_queue_surface_rows","alias_queue_surface_rows","dead_queue_surface_rows","evidence_gap_queue_surface_rows"):
        assert out["queue_accounting"][key] == run139_counts[key]
    assert out["pins"]["matrix_sha256"] == digest(AUDIT / "03-feature-to-benchmark-matrix.csv")
    assert digest(ROOT / row["source"]["route_file"]) == row["source"]["route_file_sha256"]
    assert git("hash-object", str(ROOT / row["source"]["route_file"])) == row["source"]["route_file_blob_id"]
    route_bytes = git_bytes("show", f"{out['pins']['application_commit']}:{row['source']['route_file']}")
    assert hashlib.sha256(route_bytes).hexdigest() == row["source"]["route_file_sha256"]
    assert git("rev-parse", f"{out['pins']['application_commit']}:{row['source']['route_file']}") == row["source"]["route_file_blob_id"]
    route_lines = route_bytes.decode("utf-8").splitlines()
    assert route_lines[780] == "        Route::get('/sites/overview', [FinancialInsightsApiController::class, 'sitesOverview'])"
    assert route_lines[781] == "            ->name('sites.overview');"
    assert " ".join(line.strip() for line in route_lines[780:782]) == row["source"]["statement_excerpt"]
    assert row["source"]["source_anchor"] == "routes/finance.php:781"
    assert digest(ROOT / bridge["controller_file"]) == bridge["controller_file_sha256"]
    assert git("hash-object", str(ROOT / bridge["controller_file"])) == bridge["controller_file_blob_id"]
    controller_bytes = git_bytes("show", f"{out['pins']['application_commit']}:{bridge['controller_file']}")
    assert hashlib.sha256(controller_bytes).hexdigest() == bridge["controller_file_sha256"]
    assert git("rev-parse", f"{out['pins']['application_commit']}:{bridge['controller_file']}") == bridge["controller_file_blob_id"]
    controller_lines = controller_bytes.decode("utf-8").splitlines()
    method_slice = "\n".join(controller_lines[57:77])
    assert controller_lines[57] == "    public function sitesOverview(Request $request): JsonResponse"
    assert hashlib.sha256(method_slice.encode()).hexdigest() == bridge["method_review_slice_sha256"]
    assert method_slice == candidate["controller_action"]["primary_method_slice"]["review_slice"]["text"]
    assert bridge["definition_anchor"] == "app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:58"
    slice_record = candidate["controller_action"]["primary_method_slice"]["review_slice"]
    assert (slice_record["start_line"],slice_record["end_line"],slice_record["next_method_definition_line"],slice_record["line_count"]) == (58,77,78,20)
    requester_a = {r["path"] for r in expansion["expanded_files"] if r["requesters"] in ("A","A+B")}
    requester_b = {r["path"] for r in expansion["expanded_files"] if r["requesters"] == "A+B"}
    assert len(requester_a) == 24 and len(requester_b) == 15
    assert requester_a == set(lineage["independent_candidate_reviews"][0]["requested_expansion_paths"])
    assert requester_b == set(lineage["independent_candidate_reviews"][1]["requested_expansion_paths"])
    assert hlist(list(requester_a)) == lineage["independent_candidate_reviews"][0]["requested_expansion_path_list_sha256"]
    assert hlist(list(requester_b)) == lineage["independent_candidate_reviews"][1]["requested_expansion_path_list_sha256"]
    expected_equations = {
        "reviewed_outcomes_equation":"1 = 1 owner + 0 shared + 0 alias + 0 dead + 0 evidence gap",
        "bounded_source_equation":"3929 = 662 owner + 3267 non-owner residual",
        "owner_surface_equation":"662 = 305 route + 357 page",
        "feature_union_equation":"256 = 64 route + 242 page - 50 overlap",
        "route_universe_equation":"3218 = 305 owner + 12 shared + 5 alias + 0 dead + 2896 residual",
        "page_universe_equation":"711 = 357 owner + 9 shared + 0 alias + 0 dead + 345 residual",
        "queue_equation":"507 = 116 reviewed + 391 pending",
        "reviewed_queue_equation":"116 = 94 owner + 10 shared + 5 alias + 0 dead + 7 evidence gap",
        "queue_without_ownership_equation":"413 = 391 pending + 10 shared + 5 alias + 0 dead + 7 evidence gap",
    }
    for key, expected in expected_equations.items(): assert out["outcome_conservation"][key] == expected
    encoded = (json.dumps(out, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    OUTPUT.write_bytes(encoded)
    assert OUTPUT.read_bytes() == encoded and not encoded.startswith(b"\xef\xbb\xbf") and b"\r\n" not in encoded
    json.loads(encoded, object_pairs_hook=lambda pairs: dict(pairs) if len(pairs) == len({k for k, _ in pairs}) else (_ for _ in ()).throw(AssertionError("duplicate JSON key")))
    expected_status = {f"?? {generator_rel}", f"?? {output_rel}"}
    assert set(git("status", "--short").splitlines()) == expected_status
    assert not list(AUDIT.rglob("__pycache__"))

if __name__ == "__main__":
    main()
