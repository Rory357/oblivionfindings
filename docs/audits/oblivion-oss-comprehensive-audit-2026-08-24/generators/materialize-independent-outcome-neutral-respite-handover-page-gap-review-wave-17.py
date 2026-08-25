#!/usr/bin/env python3
"""Materialize the fresh three-part RUN-117 respite handover page review.

All four complete pages are explicit OWNER_PAGE decisions for the already
represented CAP-RESP-HANDOVER-NOTES feature. This receipt authorizes a later
owner-only overlay; it does not itself award ownership, Site/privacy,
permission, runtime, browser, test, benchmark, Pass, finding, or completion
credit.
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
OUTPUT_PATH = AUDIT_DIR / "evidence/source/raw-run-117r-independent-outcome-neutral-respite-handover-page-gap-review-wave-17.json"
COHORT_PATH = AUDIT_DIR / "evidence/source/root-run-117-outcome-neutral-respite-handover-page-gap-cohort-wave-17.json"

AUDIT_HEAD = "7257748a0d5ef535b947df6e206ce4c8080db3e1"
AUDIT_TREE = "95bbd27134f831c61e57317ba4a63cf9f3cf24ac"
COHORT_CHECKPOINT = "d4018e911ce8a1fea2d39549e87759f615a6cc79"
COHORT_CHECKPOINT_TREE = "d8daa77b3d0f7c9ded1ab93461c6d37fbaa07d79"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
COHORT_SHA256 = "e468e7e7736e49eea629b4faec1fdce94d7de30eee478b08c81b90793622bd2e"
COHORT_GENERATOR_SHA256 = "85068c7a0170e155b3f5e41b87c91d27c7a45f3e2a117ea2444af91eb45a4374"

EXPECTED_COHORT_IDENTITY = {
    "page_record_id_list_sha256": "a71054d3753e542d05b84cd0e645c7521ffd367e08fd419d4c4be4c6bae44367",
    "page_file_list_sha256": "5406a77c11e7a77e3bf1c4830339fced8ab37645d47ee7374a4567802cf3b5ab",
    "page_record_id_file_pair_list_sha256": "45b8fc097ed9d7e4babac110fc1e8aa5e2a2f374c7b1a29bb78c686ee3ec6984",
    "render_anchor_list_sha256": "f8b116d62c35a13923c053a7db4104fccffe8a5e96ea1dddac472cf76076cd67",
    "parent_candidate_id_list_sha256": "a9be1de656193791a684757962dcf28328effdf849dc9a65c30568499cbc36bf",
    "parent_queue_id_list_sha256": "97aeb1d938ef1c82ac189766193fb5fc3d1bf4dedd9d9c21851b2bc8917b98ca",
    "parent_decision_record_sha256_list_sha256": "a2b640e97bb83647f3d1db26c8acae1e04f5d4de7d0e7bdfeb6a1ece16fb6798",
    "page_feature_key_list_sha256": "86e1d57b727184388309159271320032722b515a1c78b91e8d36a0d8a75a3777",
    "candidate_record_sha256_list_sha256": "bae799445a849f2b9808e92bf509aa6508283692d3e9d7af2241229e653735a0",
    "records_sha256": "f17b58203e1e9e46ef1ec99db5cafbe09191fb0b9b3fc6f6c93d98a1f505ad1c",
}

DECISIONS: dict[str, dict[str, Any]] = {
    "RUN117-RESPITE-HANDOVER-PAGE-GAP-01": {
        "outcome": "OWNER_PAGE",
        "canonical_feature_ids": ["CAP-RESP-HANDOVER-NOTES"],
        "rationale": "The dedicated unacknowledged register exclusively lists and acknowledges respite handover notes under the canonical handover-notes job.",
        "source_loci": [
            "resources/js/pages/respite/handover-notes/unacknowledged.tsx:15-125",
            "app/Http/Controllers/Respite/RespiteHandoverNoteController.php:185-195",
            "routes/respite.php:151-162",
            "03-feature-to-benchmark-matrix.csv:280",
            "tests/Browser/Respite/RespiteTest.php:246-253",
        ],
        "uncertainty": "No production incoming navigation beyond its self-breadcrumb was found; this is a discoverability concern, not contrary ownership evidence.",
        "readiness_risks": [
            "The controller query is organisation-wide and does not establish canonical approved-Site scope.",
            "Sensitive-note privacy, permission correctness, and foreign-Site direct-object concealment were not established.",
        ],
    },
    "RUN117-RESPITE-HANDOVER-PAGE-GAP-02": {
        "outcome": "OWNER_PAGE",
        "canonical_feature_ids": ["CAP-RESP-HANDOVER-NOTES"],
        "rationale": "The dedicated create page owns the handover draft, stay/type/notes/sensitivity inputs, and POST submission for the canonical create leg.",
        "source_loci": [
            "resources/js/pages/respite/handover-notes/create.tsx:38-64,96-266",
            "app/Http/Controllers/Respite/RespiteHandoverNoteController.php:33-87",
            "routes/respite.php:152-162",
            "resources/js/pages/respite/handover-notes/index.tsx:66-74",
            "resources/js/pages/respite/handover-notes/for-stay.tsx:35-41",
            "03-feature-to-benchmark-matrix.csv:280",
        ],
        "uncertainty": "None for static semantic ownership; generic dictation, autosave, layout, and navigation helpers do not introduce a competing canonical job.",
        "readiness_risks": [
            "Eligible stays and store validation do not establish canonical approved-Site or direct-object authorization.",
            "GET create uses view permission while POST submission requires manage permission.",
            "Potentially sensitive draft content is persisted in browser local storage.",
        ],
    },
    "RUN117-RESPITE-HANDOVER-PAGE-GAP-03": {
        "outcome": "OWNER_PAGE",
        "canonical_feature_ids": ["CAP-RESP-HANDOVER-NOTES"],
        "rationale": "The dedicated detail page exclusively presents one handover note and performs its canonical acknowledgement action.",
        "source_loci": [
            "resources/js/pages/respite/handover-notes/show.tsx:14-103",
            "app/Http/Controllers/Respite/RespiteHandoverNoteController.php:90-107,140-168",
            "routes/respite.php:152-162",
            "03-feature-to-benchmark-matrix.csv:280",
        ],
        "uncertainty": "None for static semantic ownership; the shared Respite subnavigation is navigation only.",
        "readiness_risks": [
            "Implicit model binding does not establish canonical approved-Site scope or foreign-Site direct-object concealment.",
            "The UI exposes acknowledge while the mutation route separately requires manage permission.",
        ],
    },
    "RUN117-RESPITE-HANDOVER-PAGE-GAP-04": {
        "outcome": "OWNER_PAGE",
        "canonical_feature_ids": ["CAP-RESP-HANDOVER-NOTES"],
        "rationale": "The dedicated per-stay handover register exclusively presents that stay's handover notes and links to their create/detail actions.",
        "source_loci": [
            "resources/js/pages/respite/handover-notes/for-stay.tsx:16-99",
            "app/Http/Controllers/Respite/RespiteHandoverNoteController.php:171-182",
            "resources/js/pages/respite/stays/show.tsx:546-558",
            "routes/respite.php:157",
            "03-feature-to-benchmark-matrix.csv:280",
        ],
        "uncertainty": "No dedicated executed test or browser proof was established; the stay is contextual scope and not a competing stay-lifecycle job.",
        "readiness_risks": [
            "Direct stay binding does not establish the canonical approved-Site resolver used by the stay controller.",
            "Sensitive-note privacy and foreign-Site direct-object concealment were not established.",
        ],
    },
}

PARTITION_METADATA = {
    "A": {"reviewer_task_paths": ["/root/run117_review_a"], "verdict": "GO_TWO_OWNER_PAGES"},
    "B": {"reviewer_task_paths": ["/root/run117_review_b"], "verdict": "GO_ONE_OWNER_PAGE"},
    "C": {"reviewer_task_paths": ["/root/run117_review_c"], "verdict": "GO_ONE_OWNER_PAGE"},
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


def assert_review_slice(source_file: str, review_slice: dict[str, Any]) -> None:
    assert review_slice["text_sha256"] == sha256_bytes(review_slice["text"].encode("utf-8"))
    assert review_slice["line_count"] == review_slice["end_line"] - review_slice["start_line"] + 1
    lines = (REPO / source_file).read_text(encoding="utf-8").splitlines(keepends=True)
    actual = "".join(lines[review_slice["start_line"] - 1 : review_slice["end_line"]])
    assert actual.rstrip("\n") == review_slice["text"].rstrip("\n")


def assert_workspace_and_inputs(cohort: dict[str, Any]) -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == AUDIT_HEAD
    assert git("rev-parse", "HEAD^{tree}") == AUDIT_TREE
    assert git("rev-parse", "HEAD^") == COHORT_CHECKPOINT
    assert git("rev-parse", f"{COHORT_CHECKPOINT}^{{tree}}") == COHORT_CHECKPOINT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "HEAD:app") == APP_TREE
    assert git("rev-parse", "HEAD:routes") == ROUTES_TREE
    assert git("rev-parse", "HEAD:resources/js") == RESOURCES_JS_TREE
    assert git("rev-parse", "HEAD:resources/js/pages") == RESOURCES_JS_PAGES_TREE
    assert git("status", "--porcelain", "--", "app", "routes", "resources/js") == ""
    assert sha256_file(COHORT_PATH) == COHORT_SHA256
    cohort_generator = AUDIT_DIR / cohort["pins"]["generator"]
    assert sha256_file(cohort_generator) == COHORT_GENERATOR_SHA256
    assert cohort["pins"]["generator_sha256"] == COHORT_GENERATOR_SHA256
    assert cohort["pins"]["checkpoint_commit"] == COHORT_CHECKPOINT
    assert cohort["pins"]["checkpoint_tree"] == COHORT_CHECKPOINT_TREE
    for relative_path, expected_sha in cohort["pins"]["inputs"].items():
        path = AUDIT_DIR / relative_path
        assert path.is_file(), path
        assert sha256_file(path) == expected_sha, relative_path


def assert_candidate_sources(candidate: dict[str, Any]) -> None:
    page = candidate["page_source"]
    parent = candidate["reviewed_parent_action_provenance"]
    page_path = REPO / page["page_file"]
    controller_path = REPO / parent["controller_file"]
    assert sha256_file(page_path) == page["page_file_sha256"]
    assert len(page_path.read_text(encoding="utf-8").splitlines()) == page["page_line_count"]
    assert git("rev-parse", f"{APPLICATION_COMMIT}:{page['page_file']}") == page["page_file_blob_id"]
    assert sha256_file(controller_path) == parent["controller_file_sha256"]
    assert git("rev-parse", f"{APPLICATION_COMMIT}:{parent['controller_file']}") == parent["controller_file_blob_id"]
    assert_review_slice(parent["controller_file"], parent["method_review_slice"])
    selected = parent["selected_render_callsite"]
    assert selected["page_file"] == page["page_file"]
    assert selected["source_file"] == parent["controller_file"]
    assert selected["source_file_sha256"] == parent["controller_file_sha256"]
    assert selected["source_file_blob_id"] == parent["controller_file_blob_id"]
    assert page["render_call_count"] == 1
    assert parent["parent_route_owner_present"] is True
    assert parent["parent_action_bridge_present"] is True
    assert parent["parent_page_ownership_authorized"] is False
    assert parent["page_ownership_inheritance_prohibited"] is True


def build() -> dict[str, Any]:
    cohort = load_json(COHORT_PATH)
    assert_workspace_and_inputs(cohort)
    records = sorted(cohort["records"], key=lambda row: row["candidate_id"])
    assert len(records) == 4
    assert set(DECISIONS) == {row["candidate_id"] for row in records}
    assert cohort["identity"] == EXPECTED_COHORT_IDENTITY
    assert cohort["identity"]["records_sha256"] == canonical_json_sha256(records)
    assert cohort["counts"]["ownership_credit_awarded"] == 0
    assert cohort["audit_completion_test_met"] is False
    assert [cohort["review_partitions"][key]["assigned_candidates"] for key in "ABC"] == [2, 1, 1]

    decision_rows: list[dict[str, Any]] = []
    for candidate in records:
        without_digest = {key: value for key, value in candidate.items() if key != "candidate_record_sha256"}
        assert candidate["candidate_record_sha256"] == canonical_json_sha256(without_digest)
        assert candidate["fresh_review_state"]["status"] == "PENDING"
        assert candidate["collision_checks"] == {
            "prior_page_review_collision": False,
            "current_page_owner_collision": False,
            "direct_queue_overlap": False,
            "parent_route_owner_missing": False,
            "parent_action_bridge_missing": False,
            "page_candidate_lane_convergence": False,
        }
        assert_candidate_sources(candidate)

        page = candidate["page_source"]
        parent = candidate["reviewed_parent_action_provenance"]
        review = DECISIONS[candidate["candidate_id"]]
        outcome = review["outcome"]
        assert outcome in cohort["fresh_review_contract"]["allowed_outcomes"]
        assert review["canonical_feature_ids"] == [candidate["candidate_feature_id"]]
        row = {
            "candidate_id": candidate["candidate_id"],
            "partition_id": candidate["review_partition"],
            "page_feature_key": candidate["page_feature_key"],
            "page_record_id": page["page_record_id"],
            "page_file": page["page_file"],
            "candidate_feature_id": candidate["candidate_feature_id"],
            "canonical_feature_ids": review["canonical_feature_ids"],
            "parent_candidate_id": parent["parent_candidate_id"],
            "parent_route_record_id": parent["route_record_id"],
            "render_source_anchor": parent["selected_render_callsite"]["source_anchor"],
            "candidate_record_sha256": candidate["candidate_record_sha256"],
            "joined_candidate_evidence_sha256": candidate["evidence_digests"]["joined_candidate_evidence_sha256"],
            "outcome": outcome,
            "source_loci": review["source_loci"],
            "rationale": review["rationale"],
            "uncertainty": review["uncertainty"],
            "static_readiness_risks": review["readiness_risks"],
            "page_ownership_authorized": outcome == "OWNER_PAGE",
            "route_ownership_authorized": False,
            "controller_action_bridge_authorized": False,
            "downstream_credit_authorized": False,
        }
        row["decision_record_sha256"] = canonical_json_sha256(row)
        decision_rows.append(row)

    counts = Counter(row["outcome"] for row in decision_rows)
    assert counts == {"OWNER_PAGE": 4}
    owners = list(decision_rows)

    partition_reviews = []
    for partition_id in "ABC":
        rows = [row for row in decision_rows if row["partition_id"] == partition_id]
        partition_counts = Counter(row["outcome"] for row in rows)
        metadata = PARTITION_METADATA[partition_id]
        partition = {
            "partition_id": partition_id,
            "reviewer_task_paths": metadata["reviewer_task_paths"],
            "synthesis_task_path": "/root",
            "verdict": metadata["verdict"],
            "candidate_count": len(rows),
            "owner_pages": partition_counts["OWNER_PAGE"],
            "shared_relations": partition_counts["SHARED_RELATION"],
            "alias_or_redirect": partition_counts["ALIAS_OR_REDIRECT"],
            "dead_or_noncanonical": partition_counts["DEAD_OR_NONCANONICAL"],
            "evidence_gaps": partition_counts["EVIDENCE_GAP"],
            "page_feature_key_list_sha256": cohort["review_partitions"][partition_id]["page_feature_key_list_sha256"],
            "mechanical_discrepancies": 0,
            "reviewer_wrote_files": False,
        }
        partition["partition_review_sha256"] = canonical_json_sha256(partition)
        partition_reviews.append(partition)

    outcome_conservation = {
        "reviewed_candidates": 4,
        "owner_pages": 4,
        "shared_relations": 0,
        "alias_or_redirect": 0,
        "dead_or_noncanonical": 0,
        "evidence_gaps": 0,
        "equation": "4 = 4 OWNER_PAGE + 0 SHARED_RELATION + 0 ALIAS_OR_REDIRECT + 0 DEAD_OR_NONCANONICAL + 0 EVIDENCE_GAP",
        "all_candidates_conserved": True,
    }

    return {
        "schema_version": "1.0.0",
        "run_id": "RUN-117R-INDEPENDENT-OUTCOME-NEUTRAL-RESPITE-HANDOVER-PAGE-GAP-REVIEW-WAVE-17",
        "status": "FRESH_REVIEW_GO_FOUR_OWNER_PAGES_ZERO_CURRENT_CREDIT",
        "generated_on": "2026-08-26",
        "pins": {
            "publication_checkpoint_commit": AUDIT_HEAD,
            "publication_checkpoint_tree": AUDIT_TREE,
            "cohort_checkpoint_commit": COHORT_CHECKPOINT,
            "cohort_checkpoint_tree": COHORT_CHECKPOINT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "app_tree": APP_TREE,
            "routes_tree": ROUTES_TREE,
            "resources_js_tree": RESOURCES_JS_TREE,
            "resources_js_pages_tree": RESOURCES_JS_PAGES_TREE,
            "cohort": COHORT_PATH.relative_to(AUDIT_DIR).as_posix(),
            "cohort_sha256": COHORT_SHA256,
            "cohort_generator": cohort["pins"]["generator"],
            "cohort_generator_sha256": COHORT_GENERATOR_SHA256,
            "materializer": Path(__file__).relative_to(AUDIT_DIR).as_posix(),
            "materializer_sha256": sha256_file(Path(__file__)),
            "prompt_path": cohort["pins"]["prompt_path"],
            "prompt_sha256": cohort["pins"]["prompt_sha256"],
            "inputs": cohort["pins"]["inputs"],
        },
        "architecture_rule": "Oblivion Findings is one operating organisation with multiple Sites. Page ownership is separate from Site access, roles and permissions, canonical ownership, direct-object concealment, privacy, and lifecycle correctness.",
        "review_method": {
            "fresh_partition_review": True,
            "complete_page_source_reviewed": True,
            "exact_render_method_reviewed": True,
            "material_imports_reviewed": True,
            "canonical_matrix_user_job_reviewed": True,
            "parent_route_ownership_inherited": False,
            "reviewer_writes": 0,
        },
        "partition_reviews": partition_reviews,
        "page_decisions": decision_rows,
        "outcome_conservation": outcome_conservation,
        "decision": {
            "verdict": "GO_4_EXPLICIT_OWNER_PAGE",
            "mechanical_discrepancies": 0,
            "owner_pages": 4,
            "shared_relations": 0,
            "alias_or_redirect": 0,
            "dead_or_noncanonical": 0,
            "evidence_gaps": 0,
            "static_page_owner_records_authorized": 4,
            "static_route_owner_records_authorized": 0,
            "static_controller_action_bridges_authorized": 0,
            "owner_only_overlay_authorized": True,
            "matrix_mutation_authorized": False,
            "gate_4_complete": False,
        },
        "identity": {
            **EXPECTED_COHORT_IDENTITY,
            "owner_candidate_id_list_sha256": canonical_list_sha256([row["candidate_id"] for row in owners]),
            "owner_page_record_id_list_sha256": canonical_list_sha256([row["page_record_id"] for row in owners]),
            "owner_page_feature_key_list_sha256": canonical_list_sha256([row["page_feature_key"] for row in owners]),
            "owner_candidate_record_sha256_list_sha256": canonical_list_sha256([row["candidate_record_sha256"] for row in owners]),
            "decision_records_sha256": canonical_json_sha256(decision_rows),
            "decision_record_sha256_list_sha256": canonical_list_sha256([row["decision_record_sha256"] for row in decision_rows]),
            "partition_reviews_sha256": canonical_json_sha256(partition_reviews),
        },
        "projected_integration_only": {
            "projection_is_current_credit": False,
            "bounded_source_records": "3929 = 641 owner + 3288 non-owner residual",
            "owner_surfaces": "641 = 288 route + 353 page",
            "page_universe": "711 = 353 owner + 9 shared + 0 alias + 0 dead + 349 residual",
            "evidence_gap_tagged_within_page_residual": 1,
            "static_controller_action_bridges": 76,
            "direct_exact_queue": "507 = 84 reviewed + 423 pending",
            "direct_exact_queue_without_current_static_ownership": 430,
            "distinct_feature_ids": 256,
            "bounded_static_source_ownership_percent": "16.314584",
        },
        "credit_boundary": {
            "PAGE_REVIEW_DECISIONS_FOR_LATER_OVERLAY": True,
            "page_ownership": False,
            "route_ownership": False,
            "static_controller_action_bridge": False,
            "framework_route_reachability": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "direct_object_concealment": False,
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
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-independent-outcome-neutral-respite-handover-page-gap-review-wave-17.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/raw-run-117r-independent-outcome-neutral-respite-handover-page-gap-review-wave-17.json",
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
        "owner_pages": payload["decision"]["owner_pages"],
        "page_ownership_credit_awarded": 0,
    }, indent=2))


if __name__ == "__main__":
    main()
