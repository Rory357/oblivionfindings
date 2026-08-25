#!/usr/bin/env python3
"""Build the RUN-097 route/controller-only semantic review cohort.

RUN-091 exhausted the stricter unresolved route -> exact action -> sole new page
predicate at eleven chains, all already reviewed.  This producer therefore
freezes a separate, narrower lane: exact route-name and exact controller-method
singletons whose complete action slice is suitable for fresh semantic review.
It cannot award page ownership or any execution/downstream credit.
"""

from __future__ import annotations

import csv
import hashlib
import json
import os
import re
import subprocess
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_PATH = AUDIT_DIR / "evidence/source/root-run-097-route-controller-only-candidate-cohort-wave-12.json"
PROMPT_PATH = Path(r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md")

AUDIT_HEAD = "76e03b1d57826e18b0965405279215d56122e7a1"
AUDIT_TREE = "7c00f20aedbcc6d3f091747abc19bd9d831b3aff"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"

INPUT_PATHS = {
    "matrix": AUDIT_DIR / "03-feature-to-benchmark-matrix.csv",
    "manifest": AUDIT_DIR / "evidence/source/root-run-077-route-page-universe-manifest-wave-07.json",
    "classification": AUDIT_DIR / "evidence/source/current-route-page-classification-wave-07.json",
    "candidate_manifest": AUDIT_DIR / "evidence/source/root-run-082-exact-owner-containment-candidate-manifest-wave-08.json",
    "candidate_review": AUDIT_DIR / "evidence/source/raw-run-082r-independent-exact-owner-containment-review-wave-08.json",
    "ownership_ledger": AUDIT_DIR / "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json",
    "ownership_review": AUDIT_DIR / "evidence/source/raw-run-086r-independent-reviewed-static-route-page-feature-ownership-wave-10.json",
    "direct_queue": AUDIT_DIR / "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json",
    "closed_cohort": AUDIT_DIR / "evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json",
    "closed_review": AUDIT_DIR / "evidence/source/raw-run-091r-independent-closed-route-action-page-chain-review-wave-11.json",
    "current_overlay": AUDIT_DIR / "evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json",
    "overlay_review": AUDIT_DIR / "evidence/source/raw-run-092r-independent-reviewed-static-source-ownership-overlay-wave-11.json",
}

EXPECTED_INPUT_SHA256 = {
    "matrix": "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390",
    "manifest": "150fcff9b100ad85a7a2e998ed69c8dafdc2d0098e8ec2b4dbac7d3b404061be",
    "classification": "7b534fea15e6c4ec98fbb2cb0c761e26116b2abee500c39e599b0e779729df97",
    "candidate_manifest": "3c6ad4df13a09ae8ff8f19aee09a1907f9754a1877806659676c6c2898652f85",
    "candidate_review": "a6a4f886ca209bc41ffa86afec37f6bddaf062ac80a6b375391adeea20e1c396",
    "ownership_ledger": "bfec52a9bed7176045457e8920bdc7b65e1c1d5c5eeb28a266f49c12acf69bcf",
    "ownership_review": "56c4832af941353aaf230ca17c792ea7191c6aebfc05bc1c511a757d5998d699",
    "direct_queue": "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    "closed_cohort": "7b1fea57104e9f4bfea7ee450ef535ac57e7fa69bc978212b4eafb0fe1ed0cae",
    "closed_review": "fb88ca666bc9f91298ab33fefa1dadbb39a4a612215fca814932f59bfc2f199b",
    "current_overlay": "5390e55d9e47d1845afa8b3848bdab7687747cbb62c946dd4d66d3c435e8de0b",
    "overlay_review": "1111d30aa24935116c37f27bead824ca1bcca7444157e456d959e821af00669a",
}

# Zero-based RUN-090 queue positions.  The A/B/C labels retain the disjoint
# discovery slices only; final review must be performed by fresh agents.
SELECTED = (
    (36, "A", "RUN090-ROUTE-0037", "RUN077-ROUTE-0414", "CAP-FIN-EXECUTIVE-INSIGHTS"),
    (37, "A", "RUN090-ROUTE-0038", "RUN077-ROUTE-0415", "CAP-FIN-CASH-POSITION"),
    (38, "A", "RUN090-ROUTE-0039", "RUN077-ROUTE-0416", "CAP-FIN-OBLIGATION-CALENDAR"),
    (41, "A", "RUN090-ROUTE-0042", "RUN077-ROUTE-0419", "CAP-FIN-SITE-FINANCIAL-DASHBOARD"),
    (42, "A", "RUN090-ROUTE-0043", "RUN077-ROUTE-0420", "CAP-FIN-CLIENT-FINANCIAL-SUMMARY"),
    (66, "A", "RUN090-ROUTE-0067", "RUN077-ROUTE-0588", "CAP-FIN-FX-REVALUATION"),
    (79, "A", "RUN090-ROUTE-0080", "RUN077-ROUTE-0688", "CAP-FLEET-DAILY-VEHICLE-CHECK"),
    (99, "A", "RUN090-ROUTE-0100", "RUN077-ROUTE-0734", "CAP-FLEET-GEOFENCE-LIFECYCLE"),
    (176, "B", "RUN090-ROUTE-0177", "RUN077-ROUTE-1114", "CAP-HS-GOVERNANCE-REPORTS-EXPORT"),
    (269, "B", "RUN090-ROUTE-0270", "RUN077-ROUTE-1897", "CAP-INT-INBOUND-PROVIDER-WEBHOOK"),
    (274, "B", "RUN090-ROUTE-0275", "RUN077-ROUTE-2262", "CAP-PORT-CLIENT-WORKSPACE"),
    (287, "B", "RUN090-ROUTE-0288", "RUN077-ROUTE-2309", "CAP-NOTIF-PERSONAL-INBOX-ACK"),
    (288, "B", "RUN090-ROUTE-0289", "RUN077-ROUTE-2310", "CAP-NOTIF-PERSONAL-INBOX-ACK"),
    (293, "B", "RUN090-ROUTE-0294", "RUN077-ROUTE-2341", "CAP-PRIV-EVIDENCE-ATTACHMENTS"),
    (304, "B", "RUN090-ROUTE-0305", "RUN077-ROUTE-2363", "CAP-PRIV-RETENTION-EXECUTION-EVIDENCE"),
    (309, "B", "RUN090-ROUTE-0310", "RUN077-ROUTE-2389", "CAP-PRIV-COMPLIANCE-REPORT-EXPORT"),
    (443, "C", "RUN090-ROUTE-0444", "RUN077-ROUTE-2671", "CAP-INT-SITE-PROVIDER-CONNECTION"),
    (447, "C", "RUN090-ROUTE-0448", "RUN077-ROUTE-2675", "CAP-INT-SITE-PROVIDER-SYNC"),
    (462, "C", "RUN090-ROUTE-0463", "RUN077-ROUTE-2715", "CAP-SEC-REPORTING-EXPORT"),
    (471, "C", "RUN090-ROUTE-0472", "RUN077-ROUTE-2779", "CAP-SET-DATA-GOVERNANCE"),
    (477, "C", "RUN090-ROUTE-0478", "RUN077-ROUTE-2804", "CAP-INT-SITE-RESOURCE-CALENDAR-CONNECTION"),
    (483, "C", "RUN090-ROUTE-0484", "RUN077-ROUTE-3058", "CAP-DAY-ALL-TASKS-WORKBENCH"),
    (495, "C", "RUN090-ROUTE-0496", "RUN077-ROUTE-3192", "CAP-IT-KNOWLEDGE-BASE"),
)

FUNCTION_RE = re.compile(r"\bfunction\s+&?\s*[A-Za-z_][A-Za-z0-9_]*\s*\(")


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(target: Path) -> str:
    return sha256_bytes(target.read_bytes())


def canonical_json_sha256(value: Any) -> str:
    raw = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return sha256_bytes(raw.encode("utf-8"))


def canonical_list_sha256(values: list[str] | set[str]) -> str:
    return sha256_bytes("\n".join(sorted(values)).encode("utf-8"))


def load_json(target: Path) -> dict[str, Any]:
    value = json.loads(target.read_text(encoding="utf-8"))
    assert isinstance(value, dict), target
    return value


def git(*args: str) -> str:
    result = subprocess.run(
        ["git", *args], cwd=REPO, check=True, stdout=subprocess.PIPE,
        stderr=subprocess.PIPE, text=True,
    )
    return result.stdout.strip()


def index_unique(rows: list[dict[str, Any]], key: str) -> dict[str, dict[str, Any]]:
    result: dict[str, dict[str, Any]] = {}
    for row in rows:
        value = row.get(key)
        assert isinstance(value, str) and value, (key, value)
        assert value not in result, (key, value)
        result[value] = row
    return result


def mask_php_comments(source: str) -> str:
    chars = list(source)
    index = 0
    state = "code"
    quote = ""
    while index < len(chars):
        char = chars[index]
        nxt = chars[index + 1] if index + 1 < len(chars) else ""
        if state == "code":
            if char in {"'", '"'}:
                state, quote = "string", char
            elif char == "/" and nxt == "/":
                chars[index] = chars[index + 1] = " "
                state, index = "line_comment", index + 1
            elif char == "#":
                chars[index], state = " ", "line_comment"
            elif char == "/" and nxt == "*":
                chars[index] = chars[index + 1] = " "
                state, index = "block_comment", index + 1
        elif state == "string":
            if char == "\\":
                index += 1
            elif char == quote:
                state, quote = "code", ""
        elif state == "line_comment":
            if char in {"\r", "\n"}:
                state = "code"
            else:
                chars[index] = " "
        elif state == "block_comment":
            if char == "*" and nxt == "/":
                chars[index] = chars[index + 1] = " "
                state, index = "code", index + 1
            elif char not in {"\r", "\n"}:
                chars[index] = " "
        index += 1
    assert state != "block_comment"
    return "".join(chars)


def method_review_slice(controller_file: str, definition_line: int) -> dict[str, Any]:
    source = (REPO / controller_file).read_text(encoding="utf-8-sig")
    masked = mask_php_comments(source)
    definitions = sorted(
        masked.count("\n", 0, match.start()) + 1 for match in FUNCTION_RE.finditer(masked)
    )
    assert definition_line in definitions, (controller_file, definition_line)
    later = [line for line in definitions if line > definition_line]
    next_line = later[0] if later else len(source.splitlines()) + 1
    lines = source.splitlines()[definition_line - 1 : next_line - 1]
    text = "\n".join(lines)
    return {
        "start_line": definition_line,
        "end_line": next_line - 1,
        "next_method_definition_line": next_line if later else None,
        "line_count": len(lines),
        "text": text,
        "text_sha256": sha256_bytes(text.encode("utf-8")),
    }


def feature_projection(row: dict[str, str]) -> dict[str, str]:
    return {
        "feature_id": row["feature_id"],
        "feature_class": row["feature_class"],
        "module": row["module"],
        "user_job": row["user_job"],
        "route_names": row["route_names"],
        "route_paths": row["route_paths"],
        "page_files": row["page_files"],
        "backend_anchors": row["backend_anchors"],
        "feature_identity_status": row["feature_identity_status"],
    }


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
    assert sha256_file(PROMPT_PATH) == PROMPT_SHA256
    for name, target in INPUT_PATHS.items():
        assert target.is_file(), target
        assert sha256_file(target) == EXPECTED_INPUT_SHA256[name], name


def build() -> dict[str, Any]:
    assert_workspace_and_inputs()
    with INPUT_PATHS["matrix"].open("r", encoding="utf-8-sig", newline="") as handle:
        matrix_rows = list(csv.DictReader(handle))
    assert len(matrix_rows) == 340
    matrix_by_id = index_unique(matrix_rows, "feature_id")

    manifest = load_json(INPUT_PATHS["manifest"])
    classification = load_json(INPUT_PATHS["classification"])
    candidates = load_json(INPUT_PATHS["candidate_manifest"])
    candidate_review = load_json(INPUT_PATHS["candidate_review"])
    ownership = load_json(INPUT_PATHS["ownership_ledger"])
    ownership_review = load_json(INPUT_PATHS["ownership_review"])
    queue = load_json(INPUT_PATHS["direct_queue"])
    closed_cohort = load_json(INPUT_PATHS["closed_cohort"])
    closed_review = load_json(INPUT_PATHS["closed_review"])
    overlay = load_json(INPUT_PATHS["current_overlay"])
    overlay_review = load_json(INPUT_PATHS["overlay_review"])

    assert candidate_review["verdict"]["decision"] == "GO"
    assert ownership_review["decision"]["verdict"] == "GO"
    assert queue["record_set"]["count"] == 507
    assert closed_cohort["counts"]["chains"] == 11
    assert closed_review["decision"]["owner_chains"] == 9
    assert closed_review["decision"]["shared_relation_chains"] == 2
    assert overlay["combined_counts"]["source_owner_records"] == 548
    assert overlay_review["decision"]["verdict"] == "GO"
    assert overlay_review["decision"]["gate_4_complete"] is False

    route_manifest_rows = list(manifest["route_universe"]["primary_route_facade_callsites"])
    route_manifest_rows += list(manifest["route_universe"]["route_like_sentinels"])
    route_manifest_by_id = index_unique(route_manifest_rows, "route_record_id")
    route_decision_by_id = index_unique(classification["route_decisions"], "route_record_id")
    route_candidate_by_id = index_unique(
        candidates["route_static_candidate_census"]["records"], "route_record_id"
    )
    page_manifest_rows = list(manifest["page_universe"]["page_roots"])

    current_owner_rows = list(ownership["records"]) + list(overlay["overlay_source_records"])
    owner_features_by_source: dict[str, set[str]] = defaultdict(set)
    for row in current_owner_rows:
        owner_features_by_source[row["source_record_id"]].add(row["feature_id"])
    current_owner_ids = set(owner_features_by_source)
    current_owner_features = {row["feature_id"] for row in current_owner_rows}

    records: list[dict[str, Any]] = []
    for sequence, (queue_index, partition, queue_id, route_id, feature_id) in enumerate(SELECTED, 1):
        queue_row = queue["records"][queue_index]
        assert queue_row["queue_id"] == queue_id
        assert queue_row["source_record_id"] == route_id
        assert queue_row["candidate_feature_id"] == feature_id
        assert queue_row["surface"] == "ROUTE_SOURCE_RECORD"
        assert queue_row["review_state"]["status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
        assert queue_row["secondary_lane"]["relation_comparison"] == "BOTH_LANES_IDENTICAL"
        assert queue_row["secondary_lane"]["contradictory_candidate_present"] is False

        route_manifest = route_manifest_by_id[route_id]
        route_candidate = route_candidate_by_id[route_id]
        route_decision = route_decision_by_id[route_id]
        matrix_row = matrix_by_id[feature_id]
        backend = route_candidate["backend_method_relation"]
        resolution = backend["resolution"]
        assert route_decision["classification"] == "EXPLICIT_UNMAPPED_SENTINEL"
        assert route_id not in current_owner_ids
        assert route_candidate["relation_comparison"] == "BOTH_LANES_IDENTICAL"
        assert route_candidate["name_relation"]["candidate_feature_ids"] == [feature_id]
        assert backend["candidate_feature_ids"] == [feature_id]
        assert backend["candidate_count"] == 1
        assert resolution["status"] == "EXACT_CLASS_METHOD_ARRAY_RESOLVED_UNIQUE_DEFINITION"
        assert route_manifest["route_file"] == queue_row["source"]["route_file"]
        assert route_manifest["statement_sha256"] == queue_row["source"]["statement_sha256"]
        assert sha256_file(REPO / route_manifest["route_file"]) == route_manifest["route_file_sha256"]
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{route_manifest['route_file']}") == route_manifest["route_file_blob_id"]
        assert sha256_file(REPO / resolution["controller_file"]) == resolution["controller_file_sha256"]
        controller_blob = git("rev-parse", f"{APPLICATION_COMMIT}:{resolution['controller_file']}")
        review_slice = method_review_slice(resolution["controller_file"], resolution["definition_line"])

        literal_pages: list[dict[str, Any]] = []
        for page_row in page_manifest_rows:
            for callsite in page_row["render_callsites"]:
                if (
                    callsite["source_file"] == resolution["controller_file"]
                    and review_slice["start_line"] <= callsite["source_line"] <= review_slice["end_line"]
                ):
                    page_id = page_row["page_record_id"]
                    literal_pages.append({
                        "page_record_id": page_id,
                        "page_file": page_row["page_file"],
                        "render_name": callsite["render_name"],
                        "source_anchor": callsite["source_anchor"],
                        "currently_owned": page_id in current_owner_ids,
                        "current_owner_feature_ids": sorted(owner_features_by_source.get(page_id, set())),
                    })
        assert all(row["currently_owned"] for row in literal_pages)

        projection = feature_projection(matrix_row)
        action_key = f"{route_id}|{resolution['controller_file']}:{resolution['method']}|{feature_id}"
        record: dict[str, Any] = {
            "candidate_id": f"RUN097-ROUTE-ACTION-{sequence:02d}",
            "action_key": action_key,
            "review_partition": partition,
            "queue_index_zero_based": queue_index,
            "queue_id": queue_id,
            "queue_canonical_key": queue_row["canonical_key"],
            "candidate_feature_id": feature_id,
            "route_source": {
                "route_record_id": route_id,
                "route_file": route_manifest["route_file"],
                "route_file_sha256": route_manifest["route_file_sha256"],
                "route_file_blob_id": route_manifest["route_file_blob_id"],
                "source_key": route_manifest["source_key"],
                "source_anchor": route_manifest["source_anchor"],
                "route_method": route_manifest["route_method"],
                "literal_uri": route_manifest["literal_uri"],
                "literal_route_name": queue_row["source"]["literal_route_name"],
                "action_expression": route_manifest["action_expression"],
                "statement_excerpt": route_manifest["statement_excerpt"],
                "statement_sha256": route_manifest["statement_sha256"],
                "direct_identity": queue_row["direct_identity"],
            },
            "controller_action": {
                "relation_class": "STATIC_CONTROLLER_ACTION_REVIEW_CANDIDATE",
                "controller_fqcn": resolution["resolved_fqcn"],
                "controller_file": resolution["controller_file"],
                "controller_file_sha256": resolution["controller_file_sha256"],
                "controller_file_blob_id": controller_blob,
                "method": resolution["method"],
                "definition_line": resolution["definition_line"],
                "definition_anchor": resolution["definition_anchor"],
                "method_review_slice": review_slice,
                "literal_inertia_page_callsites": literal_pages,
                "literal_inertia_page_callsite_count": len(literal_pages),
                "unowned_literal_page_callsite_count": 0,
                "route_ownership_credit": False,
                "controller_action_bridge_credit": False,
                "page_ownership_credit": False,
            },
            "feature_identity_projection": projection,
            "evidence_digests": {
                "queue_record_sha256": queue_row["queue_record_sha256"],
                "route_manifest_record_sha256": canonical_json_sha256(route_manifest),
                "route_candidate_record_sha256": canonical_json_sha256(route_candidate),
                "route_decision_sha256": canonical_json_sha256(route_decision),
                "feature_projection_sha256": canonical_json_sha256(projection),
                "method_review_slice_sha256": review_slice["text_sha256"],
            },
            "fresh_review_state": {
                "status": "PENDING",
                "allowed_outcomes": [
                    "OWNER_ROUTE_ACTION",
                    "SHARED_RELATION",
                    "ALIAS_OR_REDIRECT",
                    "DEAD_OR_NONCANONICAL",
                    "EVIDENCE_GAP",
                ],
                "route_ownership_credit": False,
                "controller_action_bridge_credit": False,
                "page_ownership_credit": False,
            },
        }
        record["evidence_digests"]["joined_candidate_evidence_sha256"] = canonical_json_sha256({
            "queue_record_sha256": record["evidence_digests"]["queue_record_sha256"],
            "route_manifest_record_sha256": record["evidence_digests"]["route_manifest_record_sha256"],
            "route_candidate_record_sha256": record["evidence_digests"]["route_candidate_record_sha256"],
            "route_decision_sha256": record["evidence_digests"]["route_decision_sha256"],
            "feature_projection_sha256": record["evidence_digests"]["feature_projection_sha256"],
            "method_review_slice_sha256": review_slice["text_sha256"],
        })
        record["candidate_record_sha256"] = canonical_json_sha256(record)
        records.append(record)

    assert len(records) == 23
    assert len({row["route_source"]["route_record_id"] for row in records}) == 23
    assert Counter(row["review_partition"] for row in records) == {"A": 8, "B": 8, "C": 7}
    feature_ids = {row["candidate_feature_id"] for row in records}
    assert len(feature_ids) == 22
    new_feature_ids = feature_ids - current_owner_features
    assert len(new_feature_ids) == 7

    partition_summaries = {}
    for partition in ("A", "B", "C"):
        assigned = [row for row in records if row["review_partition"] == partition]
        partition_summaries[partition] = {
            "assigned_candidates": len(assigned),
            "candidate_ids": [row["candidate_id"] for row in assigned],
            "action_key_list_sha256": canonical_list_sha256([row["action_key"] for row in assigned]),
            "fresh_reviewer_required": True,
        }

    return {
        "schema_version": "1.0.0",
        "run_id": "RUN-097-ROUTE-CONTROLLER-ONLY-CANDIDATE-COHORT-WAVE-12",
        "status": "ROUTE_CONTROLLER_CANDIDATE_COHORT_PENDING_THREE_FRESH_REVIEWS_ZERO_CREDIT",
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
            "prompt_path": str(PROMPT_PATH),
            "prompt_sha256": PROMPT_SHA256,
            "generator": Path(__file__).relative_to(AUDIT_DIR).as_posix(),
            "generator_sha256": sha256_file(Path(__file__)),
            "inputs": {
                INPUT_PATHS[name].relative_to(AUDIT_DIR).as_posix(): digest
                for name, digest in EXPECTED_INPUT_SHA256.items()
            },
        },
        "architecture_rule": (
            "Oblivion Findings is one operating organisation with multiple Sites. Static route/action "
            "ownership never establishes permission, approved-Site reach, direct-object concealment, "
            "privacy, lifecycle correctness, framework reachability, runtime, or release readiness."
        ),
        "selection_contract": {
            "strict_new_closed_route_action_page_chains": 0,
            "strict_lane_exhaustion_basis": (
                "RUN-091 globally reconstructs and asserts exactly 11 sole-new-page chains; RUN-091R "
                "reviews all 11 and RUN-092 accounts all 12 overlapping queue surfaces."
            ),
            "route_only_rule": (
                "Select explicit-unmapped RUN-090 route surfaces whose exact literal route-name and exact "
                "unique controller-method lanes are identical singleton FEATURE-IDs, exclude every current "
                "owner, pin the complete exact method slice, and withhold all page ownership."
            ),
            "semantic_boundary": (
                "The 23 records are discovery-selected review candidates only. Fresh reviewers must decide "
                "whether each complete route statement and method action has one canonical user job or is "
                "shared, ambiguous, alias/dead, or an evidence gap."
            ),
            "prohibited_inheritance": [
                "route group prefix", "adjacency", "controller/file containment", "method name alone",
                "existing page ownership", "middleware", "navigation", "prior ownership", "runtime",
            ],
        },
        "counts": {
            "candidate_route_actions": 23,
            "candidate_route_records": 23,
            "candidate_controller_action_bridges": 23,
            "candidate_page_records": 0,
            "distinct_feature_ids": 22,
            "distinct_feature_ids_not_in_current_owner_set": 7,
            "existing_owned_page_callsites_observed": sum(
                row["controller_action"]["literal_inertia_page_callsite_count"] for row in records
            ),
            "unowned_page_callsites_observed": 0,
            "queue_pending_before": 495,
            "selected_pending_queue_surfaces": 23,
            "queue_unselected_pending": 472,
            "ownership_credit_awarded": 0,
            "page_ownership_credit_awarded": 0,
            "framework_routes_executed": 0,
            "runtime_credit": 0,
            "build_credit": 0,
            "application_browser_credit": 0,
            "executed_test_credit": 0,
            "benchmark_credit": 0,
            "pass_credit": 0,
            "completion_credit": 0,
        },
        "identity": {
            "queue_index_list_sha256": canonical_list_sha256([str(row[0]) for row in SELECTED]),
            "route_record_id_list_sha256": canonical_list_sha256(
                [row["route_source"]["route_record_id"] for row in records]
            ),
            "feature_id_list_sha256": canonical_list_sha256(feature_ids),
            "new_feature_id_list_sha256": canonical_list_sha256(new_feature_ids),
            "action_key_list_sha256": canonical_list_sha256([row["action_key"] for row in records]),
            "candidate_record_sha256_list_sha256": canonical_list_sha256(
                [row["candidate_record_sha256"] for row in records]
            ),
            "records_sha256": canonical_json_sha256(records),
        },
        "review_partitions": partition_summaries,
        "records": records,
        "fresh_review_contract": {
            "status": "PENDING",
            "required_reviews": 3,
            "required_outcome_per_candidate": True,
            "reviewers_must_be_fresh_from_discovery_agents": True,
            "allowed_outcomes": [
                "OWNER_ROUTE_ACTION", "SHARED_RELATION", "ALIAS_OR_REDIRECT",
                "DEAD_OR_NONCANONICAL", "EVIDENCE_GAP",
            ],
            "required_checks": [
                "Reconstruct every assigned record from the pinned queue, manifest, classification, candidate census, matrix, and current owner overlay.",
                "Read the complete route statement, exact controller-method slice, canonical user job, and every subordinate operation in that method.",
                "Reject shared/multi-feature actions, semantic mismatch, aliases, dead/noncanonical routes, dynamic delegation not resolved by the packet, and evidence gaps.",
                "Never infer page ownership from an already-owned render target; this cohort authorizes zero page records.",
                "Keep Site/permission/privacy/lifecycle readiness and all runtime/browser/test/benchmark/Pass/finding/completion credit false.",
            ],
            "ownership_integration_authorized": False,
        },
        "projected_overlay_only_if_all_23_are_explicit_owner_route_action": {
            "static_route_owners": {"before": 221, "after": 244},
            "static_page_owners": {"before": 327, "after": 327},
            "bounded_static_source_owners": {"before": 548, "after": 571, "denominator": 3929},
            "bounded_static_source_ownership_percent_after": "14.532960",
            "bounded_static_source_residual_records": {"before": 3381, "after": 3358},
            "explicit_unmapped_routes": {"before": 2992, "after": 2969},
            "distinct_owned_feature_ids": {"before": 239, "after": 246},
            "static_controller_action_bridges": {"before": 9, "after": 32},
            "queue_reviewed_surfaces": {"before": 12, "after": 35},
            "queue_owner_surfaces": {"before": 10, "after": 33},
            "queue_shared_surfaces": {"before": 2, "after": 2},
            "queue_pending_unreviewed": {"before": 495, "after": 472},
            "queue_surfaces_without_ownership": {"before": 497, "after": 474},
            "projection_credit_awarded": False,
        },
        "denominator_boundary": {
            "run_077_bounded_static_records": 3929,
            "framework_expanded_route_page_denominator": None,
            "page_denominator_711_roots_vs_963_page_tree_files_resolved": False,
            "gate_4_complete": False,
        },
        "credit_boundary": {
            "route_controller_candidate_cohort": True,
            "static_source_feature_ownership": False,
            "static_controller_action_bridge": False,
            "page_ownership": False,
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
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/build-route-controller-only-candidate-cohort-wave-12.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/root-run-097-route-controller-only-candidate-cohort-wave-12.json",
        ],
    }


def main() -> None:
    payload = build()
    encoded = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    digest = sha256_bytes(encoded)
    OUTPUT_PATH.parent.mkdir(parents=True, exist_ok=True)
    if OUTPUT_PATH.exists():
        assert OUTPUT_PATH.read_bytes() == encoded, f"Refusing to overwrite different bytes: {OUTPUT_PATH}"
    else:
        temporary = OUTPUT_PATH.with_suffix(OUTPUT_PATH.suffix + ".tmp")
        temporary.write_bytes(encoded)
        assert sha256_file(temporary) == digest
        os.replace(temporary, OUTPUT_PATH)
    assert sha256_file(OUTPUT_PATH) == digest
    print(json.dumps({
        "status": payload["status"],
        "output": OUTPUT_PATH.relative_to(REPO).as_posix(),
        "sha256": digest,
        "candidate_route_actions": payload["counts"]["candidate_route_actions"],
        "distinct_feature_ids": payload["counts"]["distinct_feature_ids"],
        "strict_new_closed_chains": payload["selection_contract"]["strict_new_closed_route_action_page_chains"],
        "fresh_review_status": payload["fresh_review_contract"]["status"],
        "ownership_credit_awarded": payload["counts"]["ownership_credit_awarded"],
    }, indent=2))


if __name__ == "__main__":
    main()
