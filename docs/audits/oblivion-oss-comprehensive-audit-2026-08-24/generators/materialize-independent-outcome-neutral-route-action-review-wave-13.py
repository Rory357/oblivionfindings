#!/usr/bin/env python3
"""Materialize the three-part independent RUN-101 semantic review.

Only explicit OWNER_ROUTE_ACTION decisions authorize bounded route-source and
controller-action-bridge integration. Redirects remain reviewed non-owners.
No page, runtime, browser, benchmark, Pass, finding, or completion credit is
created by this receipt.
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
OUTPUT_PATH = AUDIT_DIR / "evidence/source/raw-run-101r-independent-outcome-neutral-route-action-review-wave-13.json"
COHORT_PATH = AUDIT_DIR / "evidence/source/root-run-101-outcome-neutral-route-action-cohort-wave-13.json"
PRIOR_OVERLAY_PATH = AUDIT_DIR / "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json"

AUDIT_HEAD = "a6e6add624a42cd49715709ea310a8484c4903b6"
AUDIT_TREE = "59a7684269e46592de73d95540c6d7fa5fd18c2c"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
COHORT_SHA256 = "3a8f4c3f11668406f34db7e50ae561fe1c6516e7002eb7e8271851e62c3ff655"
COHORT_GENERATOR_SHA256 = "f3ada90da486ba700d21596fb765ab10f661c343944899551006d5db5b9e7a0f"
PRIOR_OVERLAY_SHA256 = "7f76c9cfeae906a64c92013fbf9a12cf180d39a315bfa13e778b6332365cdd2a"

OUTCOMES = {
    "RUN101-ROUTE-ACTION-01": "ALIAS_OR_REDIRECT",
    "RUN101-ROUTE-ACTION-02": "ALIAS_OR_REDIRECT",
    "RUN101-ROUTE-ACTION-03": "ALIAS_OR_REDIRECT",
    **{f"RUN101-ROUTE-ACTION-{index:02d}": "OWNER_ROUTE_ACTION" for index in range(4, 25)},
}

RATIONALES = {
    "RUN101-ROUTE-ACTION-01": "The index action only redirects direct or bookmarked recipe-library visits to the canonical Meal Planner surface.",
    "RUN101-ROUTE-ACTION-02": "The create action only redirects to the canonical Meal Planner route; the controller identifies its in-page recipe dialog as the creation surface.",
    "RUN101-ROUTE-ACTION-03": "The show action only redirects to the canonical Meal Planner surface and has no substantive route-action semantics of its own.",
    "RUN101-ROUTE-ACTION-04": "The edit action authorizes access, loads ingredients and tags, and returns editable recipe JSON; the non-JSON redirect and lack of a current rendered caller do not erase those positive action semantics.",
    "RUN101-ROUTE-ACTION-05": "The store action validates input and performs the recipe, tag, and ingredient creation transaction used by the Meal Planner dialog.",
    "RUN101-ROUTE-ACTION-06": "The update action validates input and performs the bound recipe, tag, and ingredient update transaction used by the Meal Planner dialog.",
    "RUN101-ROUTE-ACTION-07": "The destroy action authorizes and deletes the bound recipe under the canonical recipe-library job.",
    "RUN101-ROUTE-ACTION-08": "The index action returns the product catalogue, categories, and tags JSON used by the imported Meal Planner library dialog; its non-JSON redirect is only a fallback branch.",
    "RUN101-ROUTE-ACTION-09": "The store action validates input, creates a MealProduct, synchronizes tags, and serves the Meal Planner library dialog's JSON create request.",
    "RUN101-ROUTE-ACTION-10": "The update action validates and updates the bound MealProduct and tag relation for the canonical product-catalogue job.",
    "RUN101-ROUTE-ACTION-11": "The destroy action authorizes and deletes the bound MealProduct for the canonical product-catalogue job.",
    "RUN101-ROUTE-ACTION-12": "The JSON branch queries and returns the canonical dietary-tag library to a live imported Meal Planner dialog; only the non-JSON branch redirects.",
    "RUN101-ROUTE-ACTION-13": "The store action validates and creates a MealDietaryTag for the canonical dietary-tag-library job.",
    "RUN101-ROUTE-ACTION-14": "The update action validates and updates the bound MealDietaryTag for the canonical dietary-tag-library job.",
    "RUN101-ROUTE-ACTION-15": "The destroy action authorizes and deletes the bound MealDietaryTag for the canonical dietary-tag-library job.",
    "RUN101-ROUTE-ACTION-16": "The action directly marks only the authenticated user's unread notification relation as read and is invoked by current inbox and notification UI callers.",
    "RUN101-ROUTE-ACTION-17": "The action applies report filters and streams the security-events CSV for the exact reporting-export job.",
    "RUN101-ROUTE-ACTION-18": "The action scopes records to devices visible to the user and streams the maintenance CSV for the exact reporting-export job.",
    "RUN101-ROUTE-ACTION-19": "The controller validates the update request and delegates the bound article update to the pinned knowledge-base lifecycle service.",
    "RUN101-ROUTE-ACTION-20": "The wrapper binds the literal submitForReview transition and delegates it through lifecycleAction to the pinned lifecycle service method.",
    "RUN101-ROUTE-ACTION-21": "The wrapper binds the literal publish transition and delegates it through lifecycleAction to the pinned lifecycle service method.",
    "RUN101-ROUTE-ACTION-22": "The validated retire request binds a reason and delegates the literal retire transition through lifecycleAction to the pinned lifecycle service method.",
    "RUN101-ROUTE-ACTION-23": "The wrapper binds the literal restore transition and delegates it through lifecycleAction to the pinned lifecycle service method.",
    "RUN101-ROUTE-ACTION-24": "The validated delete request delegates to deleteDraft, whose pinned service slice enforces the draft-only deletion lifecycle and audit event.",
}

EXTRA_SOURCE_LOCI = {
    "RUN101-ROUTE-ACTION-16": [
        "resources/js/components/inbox-menus.tsx:214-220",
        "resources/js/pages/notifications/index.tsx:246-251",
        "resources/js/components/app-header.tsx:193",
    ],
}

PARTITION_METADATA = {
    "A": {
        "reviewer_task_path": "/root/run101r_partition_a",
        "verdict": "GO_REVIEW_COMPLETE_FIVE_OWNER_THREE_ALIAS",
        "review_notes": [
            "Recipe edit has positive JSON action semantics but its controller caller comment is stale relative to the current rendered editor; this blocks caller and page credit, not action ownership."
        ],
    },
    "B": {
        "reviewer_task_path": "/root/run101r_partition_b",
        "verdict": "GO_REVIEW_COMPLETE_EIGHT_OWNER_ROUTE_ACTION",
        "review_notes": [
            "Candidate 16 has current callers at resources/js/components/inbox-menus.tsx:214-220 and resources/js/pages/notifications/index.tsx:246-251, with InboxMenus rendered at resources/js/components/app-header.tsx:193; these are not represented by RUN-101 frontend_caller_census_keys. This is a bounded caller-census coverage omission, while literal_inertia_page_callsite_count=0 remains accurate."
        ],
    },
    "C": {
        "reviewer_task_path": "/root/run101r_partition_c",
        "verdict": "GO_REVIEW_COMPLETE_EIGHT_OWNER_ROUTE_ACTION",
        "review_notes": [
            "All six IT knowledge-base actions were resolved to literal lifecycle service methods; four transition wrappers use lifecycleAction, while update and destroy delegate directly."
        ],
    },
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
        ["git", *args], cwd=REPO, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True
    )
    return completed.stdout.strip()


def source_locus(slice_record: dict[str, Any]) -> str:
    review_slice = slice_record["review_slice"]
    return f"{slice_record['source_file']}:{review_slice['start_line']}-{review_slice['end_line']}"


def source_loci(candidate: dict[str, Any]) -> list[str]:
    action = candidate["controller_action"]
    loci = [candidate["route_source"]["source_anchor"], source_locus(action["primary_method_slice"])]
    helpers = action["transitive_local_helper_slices"]
    if isinstance(helpers, dict):
        helpers = [helpers]
    for helper in helpers:
        loci.append(source_locus(helper))
    support = action["external_semantic_support_slices"]
    if isinstance(support, dict):
        support = [support]
    for dependency in support:
        loci.append(source_locus(dependency))
    request_contract = action["request_contract"]
    if request_contract:
        for method_slice in request_contract["method_slices"]:
            loci.append(source_locus(method_slice))
    return list(dict.fromkeys(loci))


def assert_workspace_and_inputs(cohort: dict[str, Any]) -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == AUDIT_HEAD
    assert git("rev-parse", "HEAD^{tree}") == AUDIT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "HEAD:app") == APP_TREE
    assert git("rev-parse", "HEAD:routes") == ROUTES_TREE
    assert git("rev-parse", "HEAD:resources/js") == RESOURCES_JS_TREE
    assert git("rev-parse", "HEAD:resources/js/pages") == RESOURCES_JS_PAGES_TREE
    assert git("status", "--porcelain", "--", "app", "routes", "resources/js") == ""
    assert sha256_file(COHORT_PATH) == COHORT_SHA256
    cohort_generator = AUDIT_DIR / cohort["pins"]["generator"]
    assert sha256_file(cohort_generator) == COHORT_GENERATOR_SHA256
    for relative_path, expected_sha in cohort["pins"]["inputs"].items():
        path = AUDIT_DIR / relative_path
        assert path.is_file(), path
        assert sha256_file(path) == expected_sha, (relative_path, sha256_file(path), expected_sha)
    assert sha256_file(PRIOR_OVERLAY_PATH) == PRIOR_OVERLAY_SHA256


def build() -> dict[str, Any]:
    cohort = load_json(COHORT_PATH)
    assert_workspace_and_inputs(cohort)
    prior_overlay = load_json(PRIOR_OVERLAY_PATH)
    records = sorted(cohort["records"], key=lambda row: row["candidate_id"])
    assert len(records) == 24
    assert set(OUTCOMES) == {row["candidate_id"] for row in records}
    assert set(RATIONALES) == set(OUTCOMES)
    assert cohort["counts"]["candidate_route_actions"] == 24
    assert cohort["counts"]["ownership_credit_awarded"] == 0
    assert sum(row["controller_action"]["literal_inertia_page_callsite_count"] for row in records) == 0
    assert cohort["identity"]["records_sha256"] == canonical_json_sha256(records)
    for candidate in records:
        row_without_digest = {key: value for key, value in candidate.items() if key != "candidate_record_sha256"}
        assert candidate["candidate_record_sha256"] == canonical_json_sha256(row_without_digest)
        assert candidate["fresh_review_state"]["status"] == "PENDING"
        assert not any(candidate["collision_checks"].values())

    action_decisions: list[dict[str, Any]] = []
    for candidate in records:
        candidate_id = candidate["candidate_id"]
        decision = {
            "candidate_id": candidate_id,
            "partition_id": candidate["review_partition"],
            "queue_index_zero_based": candidate["queue_index_zero_based"],
            "queue_id": candidate["queue_id"],
            "route_record_id": candidate["route_source"]["route_record_id"],
            "candidate_feature_id": candidate["candidate_feature_id"],
            "candidate_record_sha256": candidate["candidate_record_sha256"],
            "outcome": OUTCOMES[candidate_id],
            "source_loci": source_loci(candidate) + EXTRA_SOURCE_LOCI.get(candidate_id, []),
            "rationale": RATIONALES[candidate_id],
            "route_ownership_authorized": OUTCOMES[candidate_id] == "OWNER_ROUTE_ACTION",
            "controller_action_bridge_authorized": OUTCOMES[candidate_id] == "OWNER_ROUTE_ACTION",
            "page_ownership_authorized": False,
        }
        if candidate_id == "RUN101-ROUTE-ACTION-04":
            decision["evidence_note"] = "Positive JSON action semantics are accepted; current-caller, rendered-page, and runtime credit remain false."
        if candidate_id == "RUN101-ROUTE-ACTION-16":
            decision["evidence_note"] = "Two current frontend callers were independently observed outside the packet's bounded caller-census keys; no page credit is inferred."
        decision["decision_record_sha256"] = canonical_json_sha256(decision)
        action_decisions.append(decision)

    outcome_counts = Counter(row["outcome"] for row in action_decisions)
    assert outcome_counts == {"OWNER_ROUTE_ACTION": 21, "ALIAS_OR_REDIRECT": 3}
    accepted = [row for row in records if OUTCOMES[row["candidate_id"]] == "OWNER_ROUTE_ACTION"]
    aliases = [row for row in records if OUTCOMES[row["candidate_id"]] == "ALIAS_OR_REDIRECT"]
    accepted_feature_ids = {row["candidate_feature_id"] for row in accepted}
    prior_feature_ids = {
        row["feature_id"]
        for row in prior_overlay["overlay_source_records"]
    }
    baseline_path = AUDIT_DIR / "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json"
    baseline = load_json(baseline_path)
    prior_feature_ids |= {row["feature_id"] for row in baseline["records"]}
    new_feature_ids = accepted_feature_ids - prior_feature_ids
    assert new_feature_ids == {
        "CAP-CATER-RECIPE-LIBRARY",
        "CAP-CATER-PRODUCT-CATALOG",
        "CAP-CATER-DIETARY-TAG-LIBRARY",
    }

    partition_reviews = []
    for partition_id in ("A", "B", "C"):
        partition_decisions = [row for row in action_decisions if row["partition_id"] == partition_id]
        counts = Counter(row["outcome"] for row in partition_decisions)
        metadata = PARTITION_METADATA[partition_id]
        partition_reviews.append(
            {
                "partition_id": partition_id,
                "reviewer_task_path": metadata["reviewer_task_path"],
                "verdict": metadata["verdict"],
                "candidate_count": len(partition_decisions),
                "owner_route_actions": counts["OWNER_ROUTE_ACTION"],
                "shared_relations": counts["SHARED_RELATION"],
                "alias_or_redirect": counts["ALIAS_OR_REDIRECT"],
                "dead_or_noncanonical": counts["DEAD_OR_NONCANONICAL"],
                "evidence_gaps": counts["EVIDENCE_GAP"],
                "action_key_list_sha256": cohort["review_partitions"][partition_id]["action_key_list_sha256"],
                "mechanical_discrepancies": [],
                "review_notes": metadata["review_notes"],
                "wrote_files": False,
                "write_scope": [],
            }
        )
    assert [(row["owner_route_actions"], row["alias_or_redirect"]) for row in partition_reviews] == [(5, 3), (8, 0), (8, 0)]

    owner_count = len(accepted)
    alias_count = len(aliases)
    combined_owner_records = prior_overlay["combined_counts"]["source_owner_records"] + owner_count
    residual_records = 3929 - combined_owner_records
    bounded_projection = {
        "reviewed_route_actions": 24,
        "new_owner_route_actions": owner_count,
        "new_shared_relations": 0,
        "new_alias_or_redirect": alias_count,
        "new_dead_or_noncanonical": 0,
        "new_evidence_gaps": 0,
        "combined_source_owner_records": combined_owner_records,
        "combined_route_owner_records": prior_overlay["combined_counts"]["route_owner_records"] + owner_count,
        "combined_page_owner_records": prior_overlay["combined_counts"]["page_owner_records"],
        "combined_static_controller_action_bridges": prior_overlay["combined_counts"]["static_controller_action_bridges"] + owner_count,
        "bounded_static_source_denominator": 3929,
        "bounded_static_source_residual_records": residual_records,
        "bounded_static_source_ownership_percent": f"{combined_owner_records / 3929 * 100:.6f}",
        "direct_exact_queue_records": 507,
        "reviewed_queue_surface_rows": 59,
        "owner_queue_surface_rows": 54,
        "shared_queue_surface_rows": 2,
        "alias_queue_surface_rows": 3,
        "dead_queue_surface_rows": 0,
        "evidence_gap_queue_surface_rows": 0,
        "pending_unreviewed_queue_surface_rows": 448,
        "queue_surfaces_without_ownership": 453,
        "residual_explicit_unmapped_routes": 2945,
        "semantic_shared_routes": 5,
        "reviewed_alias_routes": 3,
        "reviewed_dead_routes": 0,
        "evidence_gap_routes_tagged_within_residual": 0,
        "projection_credit_awarded": False,
    }
    assert combined_owner_records == 592
    assert residual_records == 3337
    assert bounded_projection["bounded_static_source_ownership_percent"] == "15.067447"
    assert 507 == 59 + 448
    assert 59 == 54 + 2 + 3
    assert 453 == 448 + 2 + 3
    assert 3218 == 265 + 5 + 3 + 2945

    identity = {
        "reviewed_queue_index_list_sha256": cohort["identity"]["queue_index_list_sha256"],
        "reviewed_queue_id_list_sha256": cohort["identity"]["queue_id_list_sha256"],
        "reviewed_canonical_key_list_sha256": cohort["identity"]["canonical_key_list_sha256"],
        "reviewed_source_key_list_sha256": cohort["identity"]["source_key_list_sha256"],
        "reviewed_route_record_id_list_sha256": cohort["identity"]["route_record_id_list_sha256"],
        "reviewed_feature_id_list_sha256": cohort["identity"]["feature_id_list_sha256"],
        "reviewed_action_key_list_sha256": cohort["identity"]["action_key_list_sha256"],
        "reviewed_candidate_record_sha256_list_sha256": cohort["identity"]["candidate_record_sha256_list_sha256"],
        "owner_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in accepted]),
        "alias_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in aliases]),
        "owner_feature_id_list_sha256": canonical_list_sha256(accepted_feature_ids),
        "new_owner_feature_id_list_sha256": canonical_list_sha256(new_feature_ids),
        "decision_record_sha256_list_sha256": canonical_list_sha256([row["decision_record_sha256"] for row in action_decisions]),
        "reviewed_decisions_sha256": canonical_json_sha256(action_decisions),
    }

    return {
        "schema_version": "run-101r-independent-outcome-neutral-route-action-review-wave-13-v1",
        "run_id": "RUN-101R-INDEPENDENT-OUTCOME-NEUTRAL-ROUTE-ACTION-REVIEW-WAVE-13",
        "status": "GO_THREE_PART_REVIEW_COMPLETE_21_OWNER_3_ALIAS_ZERO_OTHER",
        "reviewed_on": "2026-08-25",
        "decision": {
            "verdict": "GO_21_EXPLICIT_OWNER_ROUTE_ACTION_3_EXPLICIT_ALIAS_OR_REDIRECT",
            "mechanical_discrepancies": 0,
            "reviewed_route_actions": 24,
            "owner_route_actions": 21,
            "shared_relations": 0,
            "alias_or_redirect": 3,
            "dead_or_noncanonical": 0,
            "evidence_gaps": 0,
            "static_route_owner_records_authorized": 21,
            "static_controller_action_bridges_authorized": 21,
            "static_page_owner_records_authorized": 0,
            "owner_only_overlay_authorized": True,
            "non_owner_outcomes_preserved": True,
            "complete_route_page_feature_crosswalk_authorized": False,
            "matrix_mutation_authorized": False,
            "downstream_credit_authorized": False,
            "gate_4_complete": False,
        },
        "pins": {
            "checkpoint_commit": AUDIT_HEAD,
            "checkpoint_tree": AUDIT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "app_tree": APP_TREE,
            "routes_tree": ROUTES_TREE,
            "resources_js_tree": RESOURCES_JS_TREE,
            "resources_js_pages_tree": RESOURCES_JS_PAGES_TREE,
            "producer": COHORT_PATH.relative_to(AUDIT_DIR).as_posix(),
            "producer_sha256": COHORT_SHA256,
            "producer_generator": cohort["pins"]["generator"],
            "producer_generator_sha256": COHORT_GENERATOR_SHA256,
            "materializer": Path(__file__).relative_to(AUDIT_DIR).as_posix(),
            "materializer_sha256": sha256_file(Path(__file__)),
            "prior_overlay": PRIOR_OVERLAY_PATH.relative_to(AUDIT_DIR).as_posix(),
            "prior_overlay_sha256": PRIOR_OVERLAY_SHA256,
            "upstream_inputs": cohort["pins"]["inputs"],
        },
        "architecture_rule": "Oblivion Findings is one operating organisation with multiple Sites. This static semantic review does not prove Site, permission, privacy, direct-object, lifecycle, runtime, or release correctness.",
        "methods": [
            "Three fresh read-only reviewers independently reconstructed disjoint eight-record partitions from the pinned RUN-101 cohort and its upstream evidence.",
            "Each reviewer bound its decisions to exact candidate hashes and returned one allowed outcome per route action without writing files.",
            "Only OWNER_ROUTE_ACTION authorizes one bounded route-source owner and one controller-action bridge; aliases and redirects remain reviewed non-owners.",
            "Frontend callers and rendered page-graph context are evidence only and never confer page ownership or downstream credit.",
        ],
        "verified_counts": {
            "partition_reviews": 3,
            "go_review_completeness": 3,
            "mechanical_discrepancies": 0,
            "reviewed_route_actions": 24,
            "owner_route_actions": 21,
            "shared_relations": 0,
            "alias_or_redirect": 3,
            "dead_or_noncanonical": 0,
            "evidence_gaps": 0,
            "accepted_route_records": 21,
            "accepted_controller_action_bridges": 21,
            "accepted_page_records": 0,
            "accepted_distinct_feature_ids": len(accepted_feature_ids),
            "new_distinct_feature_ids": len(new_feature_ids),
            "literal_inertia_page_callsites": 0,
            "evidence_coverage_notes": 2,
            "reviewer_written_files": 0,
            "matrix_rows_changed": 0,
            "matrix_cells_changed": 0,
        },
        "verified_global_identity": identity,
        "partition_reviews": partition_reviews,
        "action_decisions": action_decisions,
        "bounded_integration_projection": bounded_projection,
        "outcome_conservation": {
            "reviewed_equation": "21 OWNER + 0 SHARED + 3 ALIAS + 0 DEAD + 0 GAP = 24",
            "bounded_sources": "3929 = 592 owned + 3337 residual",
            "owner_surfaces": "592 = 265 routes + 327 pages",
            "queue": "507 = 59 reviewed + 448 pending",
            "queue_reviewed": "59 = 54 owner + 2 shared + 3 alias + 0 dead + 0 gap",
            "queue_without_ownership": "453 = 448 pending + 2 shared + 3 alias + 0 dead + 0 gap",
            "route_universe": "3218 = 265 owner + 5 shared + 3 alias + 0 dead + 2945 residual",
            "pages": "711 = 327 owned + 382 unadjudicated + 2 shared",
            "controller_action_bridges": "53 = 32 prior + 21 reviewed owners",
        },
        "mutation_attestation": {
            "application_source_changed": False,
            "matrix_changed": False,
            "reviewer_written_files": False,
            "runtime_or_external_system_changed": False,
            "audit_artifacts_only": True,
        },
        "credit_boundary": {
            "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_21_RECORDS": True,
            "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_21_ACTIONS": True,
            "REVIEWED_ALIAS_OR_REDIRECT_FOR_3_RECORDS": True,
            "STATIC_PAGE_FEATURE_OWNERSHIP": False,
            "frontend_caller_ownership": False,
            "framework_route_reachability": False,
            "navigation": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "privacy_correctness": False,
            "direct_object_correctness": False,
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
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-independent-outcome-neutral-route-action-review-wave-13.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/raw-run-101r-independent-outcome-neutral-route-action-review-wave-13.json",
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
    print(json.dumps({
        "status": payload["status"],
        "output": OUTPUT_PATH.relative_to(REPO).as_posix(),
        "sha256": output_sha256,
        "owners": payload["decision"]["owner_route_actions"],
        "aliases": payload["decision"]["alias_or_redirect"],
        "projected_source_owners": payload["bounded_integration_projection"]["combined_source_owner_records"],
        "audit_complete": payload["audit_completion_test_met"],
    }, indent=2))


if __name__ == "__main__":
    main()
