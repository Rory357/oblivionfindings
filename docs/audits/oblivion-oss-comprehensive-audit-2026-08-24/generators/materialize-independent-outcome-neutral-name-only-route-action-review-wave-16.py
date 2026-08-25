#!/usr/bin/env python3
"""Materialize the three fresh RUN-113 name-only route/action reviews."""

from __future__ import annotations

import hashlib
import json
import subprocess
from collections import Counter
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
PRODUCER_PATH = AUDIT_DIR / "evidence/source/root-run-113-outcome-neutral-name-only-route-action-cohort-wave-16.json"
PRODUCER_GENERATOR = AUDIT_DIR / "generators/build-outcome-neutral-name-only-route-action-cohort-wave-16.py"
BASELINE_OVERLAY = AUDIT_DIR / "evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json"
BASELINE_REVIEW = AUDIT_DIR / "evidence/source/raw-run-110r-independent-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json"
MATRIX_PATH = AUDIT_DIR / "03-feature-to-benchmark-matrix.csv"
OUTPUT_PATH = AUDIT_DIR / "evidence/source/raw-run-113r-independent-outcome-neutral-name-only-route-action-review-wave-16.json"

CHECKPOINT_COMMIT = "2078b84838d0b2c93b7b61f43c5c6116b53d004c"
CHECKPOINT_TREE = "e3eca91f9ad7aa16d515978d1d815a49100b5365"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
PRODUCER_SHA256 = "0849ca5c10e31a4f3e5133a521a570d60b004e723fb17a7f2e812e485f345461"
PRODUCER_GENERATOR_SHA256 = "9403a58b2949123daaf1b23fb1db7ea5060c81e595f725dbda2701fff680083f"
BASELINE_OVERLAY_SHA256 = "9375fb374b589c5068334795fa03d80a7ba1fd35695808f99c9e2591e886efca"
BASELINE_REVIEW_SHA256 = "e9b076e790e5346f99665f8f99ee609b4c7b7bac4767e416abc73a57f7dfd867"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"


DECISIONS = (
    ("01", "OWNER_ROUTE_ACTION", ["routes/fleet-assets.php:276", "app/Http/Controllers/FleetAssets/IncidentController.php:50-132", "app/Http/Controllers/FleetAssets/IncidentController.php:455-788", "app/Http/Controllers/FleetAssets/IncidentController.php:1090-1215"], "The action builds the incident worklist, statistics, detail, reporting, export, option, and owned index-page response for the incident-record job."),
    ("02", "OWNER_ROUTE_ACTION", ["routes/fleet-assets.php:277", "app/Http/Controllers/FleetAssets/IncidentController.php:1110-1133"], "The action performs incident-workflow asset and user option search used by the incident reporting flow."),
    ("03", "ALIAS_OR_REDIRECT", ["routes/fleet-assets.php:278", "app/Http/Controllers/FleetAssets/IncidentController.php:133-143"], "The controller explicitly retires the full-page create path and only redirects into the index wizard, so it remains a reviewed redirect non-owner."),
    ("04", "OWNER_ROUTE_ACTION", ["routes/fleet-assets.php:279", "app/Http/Controllers/FleetAssets/IncidentController.php:146-198", "app/Http/Controllers/FleetAssets/IncidentController.php:789-1088"], "The action validates and creates the incident, then performs incident-chain, safeguarding, signal, audit, and notification effects."),
    ("05", "OWNER_ROUTE_ACTION", ["routes/fleet-assets.php:280", "app/Http/Controllers/FleetAssets/IncidentController.php:200-210", "app/Http/Controllers/FleetAssets/IncidentController.php:584-788"], "The JSON branch returns substantive incident detail; the HTML fallback redirects into the modal workspace without erasing the action semantics."),
    ("06", "OWNER_ROUTE_ACTION", ["routes/fleet-assets.php:281-282", "app/Http/Controllers/FleetAssets/IncidentController.php:334-345"], "The action verifies incident/attachment convergence and streams the incident's private attachment."),
    ("07", "OWNER_ROUTE_ACTION", ["routes/fleet-assets.php:287", "app/Http/Controllers/FleetAssets/IncidentController.php:212-225"], "The action validates, maps, derives regulatory fields, updates, audits, and returns refreshed incident detail."),
    ("08", "OWNER_ROUTE_ACTION", ["routes/fleet-assets.php:288", "app/Http/Controllers/FleetAssets/IncidentController.php:229-265"], "The action validates and persists incident lifecycle status, enforces its closure-note gate, audits, emits a signal, and returns detail."),
    ("09", "OWNER_ROUTE_ACTION", ["routes/fleet-assets.php:289", "app/Http/Controllers/FleetAssets/IncidentController.php:267-287"], "The action validates and creates an incident follow-up, audits it, and returns refreshed incident detail."),
    ("10", "OWNER_ROUTE_ACTION", ["routes/fleet-assets.php:290-291", "app/Http/Controllers/FleetAssets/IncidentController.php:289-300"], "The action verifies follow-up/incident convergence, completes the follow-up, audits, and refreshes incident detail."),
    ("11", "OWNER_ROUTE_ACTION", ["routes/fleet-assets.php:292", "app/Http/Controllers/FleetAssets/IncidentController.php:302-332"], "The action validates and privately stores an incident attachment, creates its record, and audits the mutation."),
    ("12", "OWNER_ROUTE_ACTION", ["routes/fleet-assets.php:293-294", "app/Http/Controllers/FleetAssets/IncidentController.php:347-362"], "The action verifies attachment/incident convergence, removes stored content and its record, and audits the mutation."),
    ("13", "OWNER_ROUTE_ACTION", ["routes/fleet-assets.php:295", "app/Http/Controllers/FleetAssets/IncidentController.php:365-389"], "The action records incident Police and TCR fields and notification time, then audits the incident."),
    ("14", "OWNER_ROUTE_ACTION", ["routes/fleet-assets.php:296", "app/Http/Controllers/FleetAssets/IncidentController.php:391-411"], "The action records incident insurer, claim amount, and claim status fields, then audits the incident."),
    ("15", "OWNER_ROUTE_ACTION", ["routes/fleet-assets.php:297", "app/Http/Controllers/FleetAssets/IncidentController.php:413-432"], "The action records the incident's vehicle-off-road dates and state and clears service resumption."),
    ("16", "OWNER_ROUTE_ACTION", ["routes/fleet-assets.php:298", "app/Http/Controllers/FleetAssets/IncidentController.php:434-449", "app/Models/FleetIncident.php:351-353"], "The action records incident service resumption, which ends the model's effective off-road state."),
    ("17", "OWNER_ROUTE_ACTION", ["routes/respite.php:153", "app/Http/Controllers/Respite/RespiteHandoverNoteController.php:17-32"], "The action queries, filters, paginates, and renders the respite handover-note index."),
    ("18", "OWNER_ROUTE_ACTION", ["routes/respite.php:154", "app/Http/Controllers/Respite/RespiteHandoverNoteController.php:185-197"], "The action lists unacknowledged respite handover notes and renders their dedicated view."),
    ("19", "OWNER_ROUTE_ACTION", ["routes/respite.php:155", "app/Http/Controllers/Respite/RespiteHandoverNoteController.php:33-54"], "The action loads eligible stay context and renders the new respite handover-note form."),
    ("20", "OWNER_ROUTE_ACTION", ["routes/respite.php:156", "app/Http/Controllers/Respite/RespiteHandoverNoteController.php:90-108"], "The action loads the bound handover note and relations, records a subordinate view audit, and renders the note."),
    ("21", "OWNER_ROUTE_ACTION", ["routes/respite.php:157", "app/Http/Controllers/Respite/RespiteHandoverNoteController.php:171-184"], "The action filters handover notes to the bound stay and renders the stay-specific handover view."),
    ("22", "OWNER_ROUTE_ACTION", ["routes/respite.php:160", "app/Http/Controllers/Respite/RespiteHandoverNoteController.php:55-89"], "The action validates and creates the handover note, records subordinate audit/event effects, and redirects to its show route."),
    ("23", "OWNER_ROUTE_ACTION", ["routes/respite.php:161", "app/Http/Controllers/Respite/RespiteHandoverNoteController.php:109-139"], "The action validates and updates the bound handover note and records subordinate audit/event effects."),
    ("24", "OWNER_ROUTE_ACTION", ["routes/respite.php:162", "app/Http/Controllers/Respite/RespiteHandoverNoteController.php:140-170"], "The action performs the handover-note acknowledgement transition and records its audit/event."),
)

PARTITION_REVIEWERS = {
    "A": {
        "reviewer_task_path": "/root/run110r_plan",
        "verdict": "GO_REVIEW_COMPLETE_SEVEN_OWNER_ONE_ALIAS",
        "review_notes": [
            "Candidate 03 is an explicit retired create-page redirect and remains a non-owner.",
            "Read-group placement and approved-Site, permission, privacy, and direct-object behavior remain unproved.",
        ],
    },
    "B": {
        "reviewer_task_path": "/root/run110r_verify_final",
        "verdict": "GO_REVIEW_COMPLETE_EIGHT_OWNER_ROUTE_ACTION",
        "review_notes": [
            "All eight actions are substantive incident mutations under the fleet incident manage route group.",
            "Binding scope, upload/privacy assurance, transactional cleanup, and lifecycle invariants remain unproved.",
        ],
    },
    "C": {
        "reviewer_task_path": "/root/run113_route_cohort",
        "verdict": "GO_REVIEW_COMPLETE_EIGHT_OWNER_ROUTE_ACTION",
        "review_notes": [
            "All eight actions exclusively implement respite handover-note behavior; stay, audit, and event work is subordinate context.",
            "Four rendered pages remain explicit page evidence gaps and receive no page ownership credit.",
        ],
    },
}


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def canonical_json_sha256(value: Any) -> str:
    encoded = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(encoded.encode("utf-8")).hexdigest()


def canonical_list_sha256(values: list[str] | set[str]) -> str:
    return hashlib.sha256("\n".join(sorted(values)).encode("utf-8")).hexdigest()


def git(*args: str) -> str:
    result = subprocess.run(
        ["git", *args], cwd=REPO, check=True, text=True,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )
    return result.stdout.strip()


def assert_workspace() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git("rev-parse", "HEAD^{tree}") == CHECKPOINT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "HEAD:app") == APP_TREE
    assert git("rev-parse", "HEAD:routes") == ROUTES_TREE
    assert git("rev-parse", "HEAD:resources/js") == RESOURCES_JS_TREE
    assert git("rev-parse", "HEAD:resources/js/pages") == RESOURCES_JS_PAGES_TREE
    assert git("status", "--porcelain", "--", "app", "routes", "resources/js") == ""
    assert sha256_file(PRODUCER_PATH) == PRODUCER_SHA256
    assert sha256_file(PRODUCER_GENERATOR) == PRODUCER_GENERATOR_SHA256
    assert sha256_file(BASELINE_OVERLAY) == BASELINE_OVERLAY_SHA256
    assert sha256_file(BASELINE_REVIEW) == BASELINE_REVIEW_SHA256
    assert sha256_file(MATRIX_PATH) == MATRIX_SHA256


def build() -> dict[str, Any]:
    assert_workspace()
    producer = json.loads(PRODUCER_PATH.read_text(encoding="utf-8"))
    records = producer["records"]
    assert len(records) == 24
    records_by_id = {record["candidate_id"]: record for record in records}
    assert len(records_by_id) == 24
    for record in records:
        digest_source = dict(record)
        claimed = digest_source.pop("candidate_record_sha256")
        assert canonical_json_sha256(digest_source) == claimed
        assert record["fresh_review_state"]["status"] == "PENDING"
        assert record["name_only_identity"]["relation_comparison"] == "NAME_ONLY"
        assert record["name_only_identity"]["backend_candidate_count"] == 0
        assert record["controller_action"]["page_ownership_credit"] is False

    action_decisions: list[dict[str, Any]] = []
    for suffix, outcome, source_loci, rationale in DECISIONS:
        candidate_id = f"RUN113-NAME-ONLY-ROUTE-ACTION-{suffix}"
        record = records_by_id[candidate_id]
        assert outcome in record["fresh_review_state"]["allowed_outcomes"]
        for locus in source_loci:
            source_path = locus.split(":", 1)[0]
            assert (REPO / source_path).is_file(), locus
        owner = outcome == "OWNER_ROUTE_ACTION"
        decision: dict[str, Any] = {
            "candidate_id": candidate_id,
            "partition_id": record["review_partition"],
            "queue_index_zero_based": record["queue_index_zero_based"],
            "queue_id": record["queue_id"],
            "route_record_id": record["route_source"]["route_record_id"],
            "candidate_feature_id": record["candidate_feature_id"],
            "candidate_record_sha256": record["candidate_record_sha256"],
            "outcome": outcome,
            "source_loci": source_loci,
            "rationale": rationale,
            "route_ownership_authorized": owner,
            "controller_action_bridge_authorized": owner,
            "page_ownership_authorized": False,
            "site_permission_privacy_direct_object_lifecycle_correctness_authorized": False,
        }
        decision["decision_record_sha256"] = canonical_json_sha256(decision)
        action_decisions.append(decision)

    assert len(action_decisions) == 24
    outcome_counts = Counter(row["outcome"] for row in action_decisions)
    assert outcome_counts == {"OWNER_ROUTE_ACTION": 23, "ALIAS_OR_REDIRECT": 1}
    owner_decisions = [row for row in action_decisions if row["route_ownership_authorized"]]
    alias_decisions = [row for row in action_decisions if row["outcome"] == "ALIAS_OR_REDIRECT"]
    assert [row["candidate_id"] for row in alias_decisions] == ["RUN113-NAME-ONLY-ROUTE-ACTION-03"]

    page_contexts = [
        page for record in records for page in record["controller_action"]["literal_inertia_page_callsites"]
    ]
    assert len(page_contexts) == 7
    assert Counter(page["current_static_source_owner"] for page in page_contexts) == {True: 3, False: 4}
    assert Counter(page["run079_prompt_classification"] for page in page_contexts) == {
        "Reviewed": 3, "Evidence gap": 4,
    }

    partition_reviews: list[dict[str, Any]] = []
    expected_outcome_hashes = {
        "A": "e46b4d84e24b1d9f7b5d7c054d8082089a1f2ac95ec701f0077546344307f987",
        "C": "670ac8aa9765bf357da8ba62c2af16e2fbf96b0f24b45405510a4d998ebc87c8",
    }
    for partition in ("A", "B", "C"):
        partition_records = [record for record in records if record["review_partition"] == partition]
        partition_decisions = [row for row in action_decisions if row["partition_id"] == partition]
        assert len(partition_records) == len(partition_decisions) == 8
        meta = PARTITION_REVIEWERS[partition]
        outcome_hash = canonical_list_sha256([
            f"{row['candidate_id']}|{row['outcome']}" for row in partition_decisions
        ])
        if partition in expected_outcome_hashes:
            assert outcome_hash == expected_outcome_hashes[partition]
        partition_reviews.append({
            "partition_id": partition,
            **meta,
            "candidate_count": 8,
            "owner_route_actions": sum(row["outcome"] == "OWNER_ROUTE_ACTION" for row in partition_decisions),
            "shared_relations": 0,
            "alias_or_redirect": sum(row["outcome"] == "ALIAS_OR_REDIRECT" for row in partition_decisions),
            "dead_or_noncanonical": 0,
            "evidence_gaps": 0,
            "action_key_list_sha256": producer["review_partitions"][partition]["action_key_list_sha256"],
            "candidate_record_sha256_list_sha256": canonical_list_sha256([
                record["candidate_record_sha256"] for record in partition_records
            ]),
            "method_slice_sha256_list_sha256": canonical_list_sha256([
                record["controller_action"]["primary_method_slice"]["review_slice"]["text_sha256"]
                for record in partition_records
            ]),
            "outcome_projection_sha256": outcome_hash,
            "mechanical_discrepancies": [],
            "wrote_files": False,
            "write_scope": [],
        })

    owner_feature_ids = {row["candidate_feature_id"] for row in owner_decisions}
    assert owner_feature_ids == {"CAP-FLEET-INCIDENT-RECORD", "CAP-RESP-HANDOVER-NOTES"}
    review_projection = {
        "O": 23, "S": 0, "A": 1, "D": 0, "E": 0,
        "source_owner_records": 637,
        "route_owner_records": 288,
        "page_owner_records": 349,
        "source_residual_records": 3292,
        "distinct_feature_ids": 256,
        "static_controller_action_bridges": 76,
        "bounded_ownership_percent": "16.212777",
        "queue_records": 507,
        "reviewed_queue_surfaces": 84,
        "owned_queue_surfaces": 77,
        "shared_queue_surfaces": 3,
        "alias_queue_surfaces": 4,
        "pending_unreviewed": 423,
        "without_ownership": 430,
    }
    assert review_projection["source_owner_records"] + review_projection["source_residual_records"] == 3929
    assert review_projection["source_owner_records"] == review_projection["route_owner_records"] + review_projection["page_owner_records"]
    assert review_projection["queue_records"] == review_projection["reviewed_queue_surfaces"] + review_projection["pending_unreviewed"]
    assert review_projection["reviewed_queue_surfaces"] == (
        review_projection["owned_queue_surfaces"] + review_projection["shared_queue_surfaces"]
        + review_projection["alias_queue_surfaces"]
    )
    assert review_projection["without_ownership"] == (
        review_projection["pending_unreviewed"] + review_projection["shared_queue_surfaces"]
        + review_projection["alias_queue_surfaces"]
    )
    assert 3218 == 288 + 5 + 4 + 2921
    assert 711 == 349 + 9 + 353

    return {
        "schema_version": "run-113r-independent-outcome-neutral-name-only-route-action-review-wave-16-v1",
        "run_id": "RUN-113R-INDEPENDENT-OUTCOME-NEUTRAL-NAME-ONLY-ROUTE-ACTION-REVIEW-WAVE-16",
        "status": "GO_THREE_PART_REVIEW_COMPLETE_23_OWNER_1_ALIAS_ZERO_OTHER",
        "reviewed_on": "2026-08-26",
        "decision": {
            "verdict": "GO_23_EXPLICIT_OWNER_ROUTE_ACTION_1_EXPLICIT_ALIAS_OR_REDIRECT",
            "mechanical_discrepancies": 0,
            "reviewed_route_actions": 24,
            "owner_route_actions": 23,
            "shared_relations": 0,
            "alias_or_redirect": 1,
            "dead_or_noncanonical": 0,
            "evidence_gaps": 0,
            "static_route_owner_records_authorized": 23,
            "static_controller_action_bridges_authorized": 23,
            "static_page_owner_records_authorized": 0,
            "owner_only_overlay_authorized": True,
            "non_owner_outcomes_preserved": True,
            "complete_route_page_feature_crosswalk_authorized": False,
            "matrix_mutation_authorized": False,
            "downstream_credit_authorized": False,
            "gate_4_complete": False,
        },
        "pins": {
            "checkpoint_commit": CHECKPOINT_COMMIT,
            "checkpoint_tree": CHECKPOINT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "app_tree": APP_TREE,
            "routes_tree": ROUTES_TREE,
            "resources_js_tree": RESOURCES_JS_TREE,
            "resources_js_pages_tree": RESOURCES_JS_PAGES_TREE,
            "producer": PRODUCER_PATH.relative_to(AUDIT_DIR).as_posix(),
            "producer_sha256": PRODUCER_SHA256,
            "producer_generator": PRODUCER_GENERATOR.relative_to(AUDIT_DIR).as_posix(),
            "producer_generator_sha256": PRODUCER_GENERATOR_SHA256,
            "materializer": Path(__file__).relative_to(AUDIT_DIR).as_posix(),
            "materializer_sha256": sha256_file(Path(__file__)),
            "baseline_overlay_sha256": BASELINE_OVERLAY_SHA256,
            "baseline_overlay_review_sha256": BASELINE_REVIEW_SHA256,
            "matrix_sha256": MATRIX_SHA256,
        },
        "architecture_rule": (
            "Oblivion Findings is one operating organisation across multiple Sites. Static semantic ownership "
            "does not prove approved-Site reach, permissions, privacy, direct-object concealment, lifecycle, "
            "concurrency, runtime, or release correctness."
        ),
        "methods": [
            "Three fresh read-only reviewers independently reconstructed disjoint eight-record partitions from the pinned RUN-113 cohort and source.",
            "Each reviewer returned exactly one allowed outcome per action without writing files.",
            "NAME_ONLY and exact controller-method identity were review inputs, never automatic ownership.",
            "Only OWNER_ROUTE_ACTION authorizes one bounded route owner and one controller-action bridge; page context never confers page ownership.",
        ],
        "verified_counts": {
            "partition_reviews": 3,
            "go_review_completeness": 3,
            "mechanical_discrepancies": 0,
            "reviewed_route_actions": 24,
            "owner_route_actions": 23,
            "shared_relations": 0,
            "alias_or_redirect": 1,
            "dead_or_noncanonical": 0,
            "evidence_gaps": 0,
            "accepted_route_records": 23,
            "accepted_controller_action_bridges": 23,
            "accepted_page_records": 0,
            "accepted_distinct_feature_ids": 2,
            "new_distinct_feature_ids": 0,
            "literal_inertia_page_callsites": 7,
            "literal_page_callsites_currently_owned": 3,
            "literal_page_callsites_current_evidence_gap": 4,
            "reviewer_written_files": 0,
            "matrix_rows_changed": 0,
            "matrix_cells_changed": 0,
        },
        "verified_global_identity": {
            "reviewed_queue_index_list_sha256": producer["identity"]["queue_index_list_sha256"],
            "reviewed_queue_id_list_sha256": producer["identity"]["queue_id_list_sha256"],
            "reviewed_canonical_key_list_sha256": producer["identity"]["canonical_key_list_sha256"],
            "reviewed_queue_pair_list_sha256": producer["identity"]["queue_id_canonical_key_pair_list_sha256"],
            "reviewed_source_key_list_sha256": producer["identity"]["source_key_list_sha256"],
            "reviewed_route_record_id_list_sha256": producer["identity"]["route_record_id_list_sha256"],
            "reviewed_feature_id_list_sha256": producer["identity"]["feature_id_list_sha256"],
            "reviewed_action_key_list_sha256": producer["identity"]["action_key_list_sha256"],
            "reviewed_candidate_record_sha256_list_sha256": producer["identity"]["candidate_record_sha256_list_sha256"],
            "owner_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in owner_decisions]),
            "alias_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in alias_decisions]),
            "owner_feature_id_list_sha256": canonical_list_sha256(owner_feature_ids),
            "new_owner_feature_id_list_sha256": canonical_list_sha256(set()),
            "decision_record_sha256_list_sha256": canonical_list_sha256([
                row["decision_record_sha256"] for row in action_decisions
            ]),
            "reviewed_decisions_sha256": canonical_json_sha256(action_decisions),
        },
        "partition_reviews": partition_reviews,
        "action_decisions": action_decisions,
        "reviewed_projection_if_integrated": review_projection,
        "page_context_boundary": {
            "literal_callsites": 7,
            "currently_owned_page_callsites": 3,
            "current_page_evidence_gap_callsites": 4,
            "page_ownership_authorized": 0,
            "rule": "Owned pages remain observation only; four Respite page gaps remain gaps and cannot inherit route ownership.",
        },
        "credit_boundary": {
            "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_23_RECORDS": True,
            "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_23_ACTIONS": True,
            "REVIEWED_ALIAS_OR_REDIRECT_FOR_1_RECORD": True,
            "STATIC_PAGE_FEATURE_OWNERSHIP": False,
            "framework_route_reachability": False,
            "navigation": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "privacy_correctness": False,
            "direct_object_correctness": False,
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
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-independent-outcome-neutral-name-only-route-action-review-wave-16.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/raw-run-113r-independent-outcome-neutral-name-only-route-action-review-wave-16.json",
        ],
    }


def main() -> None:
    payload = build()
    encoded = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    if not OUTPUT_PATH.exists() or OUTPUT_PATH.read_bytes() != encoded:
        OUTPUT_PATH.write_bytes(encoded)
    print(json.dumps({
        "status": payload["status"],
        "output": OUTPUT_PATH.relative_to(REPO).as_posix(),
        "sha256": sha256_file(OUTPUT_PATH),
        "owner_route_actions": payload["decision"]["owner_route_actions"],
        "alias_or_redirect": payload["decision"]["alias_or_redirect"],
        "page_ownership_authorized": payload["decision"]["static_page_owner_records_authorized"],
    }, indent=2))


if __name__ == "__main__":
    main()
