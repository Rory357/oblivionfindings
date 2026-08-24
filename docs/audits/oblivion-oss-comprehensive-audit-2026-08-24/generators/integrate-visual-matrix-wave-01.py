#!/usr/bin/env python3
"""Validate and normalize RUN-016 visual-matrix materialization evidence."""

from __future__ import annotations

import collections
import csv
import hashlib
import io
import json
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
GENERATED_AT = "2026-08-24T18:10:00+12:00"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
MATRIX_SHA256 = "564224d295f8a2d3bad6001b74743fb0a1d75eb41315a817264307353b74dd84"
ROW_KEY_LIST_SHA256 = "af2940a2faaa117438ec0653302f61614971339194e3c88eb7a126f9be12cf57"
VISUAL_ID_LIST_SHA256 = "b8a26f5641bc00655c7fc6d4cd39c29fd48b70772bfdf4d80da41465275817d7"

HEADERS = [
    "visual_id", "source_row_key", "row_type", "pattern_type", "source_status", "browser_status",
    "feature_id", "feature_id_status", "candidate_feature_ids", "candidate_link_status", "module",
    "source_file", "source_line", "source_symbol", "source_anchor", "source_ordinal", "instance_class",
    "definition_class", "definition_anchors", "material_state", "trigger_kind", "trigger_attribute",
    "trigger_setters", "trigger_handler", "target_instance_visual_ids", "target_link_status",
    "page_owner_status", "page_roots", "render_owners", "route_owner_status", "route_owners", "route_names",
    "route_paths", "role_scope", "site_scope", "role_hint", "site_hint", "privacy_hint", "viewport",
    "ui_state", "screenshot_ref", "internal_baseline", "finding_ids", "application_commit", "application_tree",
    "resources_js_tree", "evidence", "blocked_reason", "execution_credit",
]

TYPE_EXPECTATIONS = {
    "HERO_INSTANCE": {
        "rows": 659,
        "visual_id_list_sha256": "d0e8c0b18077ffaa5d2666dbd473fe981c85a606c6237485c8fc4d0848885124",
        "normalized_row_lines_sha256": "228c502383220dda086847a6aa6f3125e1f6cbc32d849bb9927b57f30e4db528",
    },
    "OVERLAY_INSTANCE": {
        "rows": 1211,
        "visual_id_list_sha256": "01481ff0edf19cab0620a9bde547f2e9f5f2f966b242d6ad7a1c38a9294bc4e9",
        "normalized_row_lines_sha256": "59d02a33c777d8be7eed5f20d47045605314a91725abdca1dd2f653f4ecbfd44",
    },
    "DECLARATIVE_TRIGGER": {
        "rows": 115,
        "visual_id_list_sha256": "44d8f0b98d768c39a2fd15cb44d1f3431c623c09c6699ab0ccdc8ef48f9a30a9",
        "normalized_row_lines_sha256": "b57991b160b50caf875dc8aaf3630adcf97c1fc765d11278db78e9dde6bd0c13",
    },
    "DIRECT_INLINE_TRIGGER": {
        "rows": 689,
        "visual_id_list_sha256": "a1acb6f6e02546a634579d31d9fd09dc0c84516b7b47021c78d588fbb96020b2",
        "normalized_row_lines_sha256": "c7eb91cf6b764ab6c3c56ef3f8768c1e6297c3845c9f99a8ab650c4eb2896a41",
    },
    "NAMED_HANDLER_TRIGGER": {
        "rows": 138,
        "visual_id_list_sha256": "af38e36e3dba4e75c3f8e4f81084966ae0244001c77a2d6654983538e598ed5c",
        "normalized_row_lines_sha256": "04feee2036bd5c63120464c8d75d4a1fd08236bafa20885ac836a8d6b9e56ba1",
    },
}


def sha256_bytes(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def list_hash(values: list[str]) -> str:
    return sha256_bytes(("\n".join(sorted(values)) + "\n").encode("utf-8"))


def csv_lines_hash(rows: list[dict[str, str]]) -> str:
    buffer = io.StringIO(newline="")
    writer = csv.DictWriter(buffer, fieldnames=HEADERS, lineterminator="\n", quoting=csv.QUOTE_ALL)
    writer.writerows(rows)
    return sha256_bytes(buffer.getvalue().encode("utf-8"))


def write_json(relative: str, payload: object) -> None:
    path = AUDIT_DIR / relative
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")


def digest(payload: object) -> str:
    raw = json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    return sha256_bytes(raw)


matrix_path = AUDIT_DIR / "05-browser-visual-coverage-matrix.csv"
matrix_bytes = matrix_path.read_bytes()
assert sha256_bytes(matrix_bytes) == MATRIX_SHA256
assert matrix_bytes.startswith(b'"visual_id","source_row_key"')
assert matrix_bytes.endswith(b"\n") and b"\r\n" not in matrix_bytes

with matrix_path.open(encoding="utf-8", newline="") as handle:
    reader = csv.DictReader(handle)
    assert list(reader.fieldnames or []) == HEADERS
    rows = list(reader)

assert len(rows) == 2812
assert len({row["source_row_key"] for row in rows}) == 2812
assert len({row["visual_id"] for row in rows}) == 2812
assert list_hash([row["source_row_key"] for row in rows]) == ROW_KEY_LIST_SHA256
assert list_hash([row["visual_id"] for row in rows]) == VISUAL_ID_LIST_SHA256
assert all(value != "" for row in rows for value in row.values())
assert all(row["application_commit"] == APPLICATION_COMMIT for row in rows)
assert all(row["application_tree"] == APPLICATION_TREE for row in rows)
assert all(row["resources_js_tree"] == RESOURCES_JS_TREE for row in rows)
assert all(row["source_status"] == "Source-inferred" and row["browser_status"] == "Blocked" for row in rows)
assert all(row["feature_id"] == "BLOCKED_NOT_ESTABLISHED_FINAL_FEATURE_ID" for row in rows)
assert all(row["execution_credit"] == "browser=0|build=0|runtime=0|tests=0" for row in rows)

for row_type, expected in TYPE_EXPECTATIONS.items():
    typed = [row for row in rows if row["row_type"] == row_type]
    assert len(typed) == expected["rows"]
    assert list_hash([row["visual_id"] for row in typed]) == expected["visual_id_list_sha256"]
    assert csv_lines_hash(typed) == expected["normalized_row_lines_sha256"]

assert collections.Counter(row["row_type"] for row in rows) == collections.Counter({key: value["rows"] for key, value in TYPE_EXPECTATIONS.items()})

materialization_payload = {
    "schema_version": 1,
    "status": "CURRENT_VISUAL_MATRIX_STATIC_MATERIALIZATION_COMPLETE_ZERO_BROWSER_CREDIT",
    "generated_at": GENERATED_AT,
    "pins": {
        "application_commit": APPLICATION_COMMIT,
        "application_tree": APPLICATION_TREE,
        "resources_js_tree": RESOURCES_JS_TREE,
        "typescript_version": "5.9.3",
    },
    "matrix": {
        "path": "05-browser-visual-coverage-matrix.csv",
        "columns": len(HEADERS),
        "rows": len(rows),
        "unique_source_row_keys": len({row["source_row_key"] for row in rows}),
        "unique_visual_ids": len({row["visual_id"] for row in rows}),
        "sha256": MATRIX_SHA256,
        "source_row_key_list_sha256": ROW_KEY_LIST_SHA256,
        "visual_id_list_sha256": VISUAL_ID_LIST_SHA256,
        "type_expectations": TYPE_EXPECTATIONS,
    },
    "static_partitions": {
        "page_owner": {
            row_type: dict(collections.Counter(row["page_owner_status"] for row in rows if row["row_type"] == row_type))
            for row_type in TYPE_EXPECTATIONS
        },
        "candidate_link": {
            row_type: dict(collections.Counter(row["candidate_link_status"] for row in rows if row["row_type"] == row_type))
            for row_type in TYPE_EXPECTATIONS
        },
        "target_link": {
            row_type: dict(collections.Counter(row["target_link_status"] for row in rows if row["row_type"] == row_type))
            for row_type in ("DECLARATIVE_TRIGGER", "DIRECT_INLINE_TRIGGER", "NAMED_HANDLER_TRIGGER")
        },
        "access_token_hints": {
            row_type: {
                "role_true": sum(row["role_hint"] == "true" for row in rows if row["row_type"] == row_type),
                "site_true": sum(row["site_hint"] == "true" for row in rows if row["row_type"] == row_type),
                "privacy_true": sum(row["privacy_hint"] == "true" for row in rows if row["row_type"] == row_type),
            }
            for row_type in TYPE_EXPECTATIONS
        },
    },
    "reconciliation": "The exact RUN-016 machine linkage rerun supersedes linkage-only arithmetic drift in the earlier RUN-013 prose handoff; primary RUN-013 counts and hashes are unchanged.",
    "evidence_count": 2812,
    "evidence_count_basis": "Unique row-level static hero, overlay, declarative-trigger, direct-inline-trigger, and named-handler-trigger projections. Supporting RUN-013 ledgers retain their separate primary evidence_count=4276.",
    "credit_boundary": {
        "source_materialization": "2812/2812",
        "browser": 0,
        "build": 0,
        "runtime": 0,
        "tests": 0,
        "route_execution": 0,
        "access_control": 0,
        "rendered_visual_coverage": 0,
        "final_feature_identity": 0,
    },
    "completion_test_met": True,
    "audit_completion_credit": False,
}

assignment = {
    "assignment_id": "RUN-016",
    "agent_task_path": "/root/current_visual_static_census",
    "role": "full visual-row materialization contract and linkage reconciler",
    "pass_lens": "Pass 4 static visual inventory and route/feature linkage support",
    "scope": "All 2812 source-derived hero, overlay, declarative-trigger, direct-inline-trigger, and named-handler-trigger rows",
    "application_commit": APPLICATION_COMMIT,
    "architecture_rule": "Single tenant, multiple Sites; lexical role/Site/privacy hints are not authorization evidence.",
    "no_write_rule": "Return generator source and structured evidence in messages; do not edit repository files.",
    "return_status": "COMPLETE_AFTER_EXECUTABLE_NO_WRITE_REPRODUCTION",
    "evidence_count": 2812,
    "evidence_count_basis": materialization_payload["evidence_count_basis"],
    "completion_test_met": True,
    "wrote_files": False,
    "root_reconciliation": "The root orchestrator applied the returned generator, passed its no-write check, materialized the exact 2812-row CSV, and independently matched the expected SHA-256.",
    "credit_boundary": materialization_payload["credit_boundary"],
    "unresolved_gaps": "Dynamic callbacks, injected handlers, runtime dispatch, exact routes, final feature identities, roles, approved-Site behavior, viewports, screenshots, accessibility, rendered states, and application build identity remain open.",
}
assignment["normalized_payload_sha256"] = digest(assignment)

agent_payload = {
    "schema_version": 1,
    "status": "FORMAL_VISUAL_MATRIX_MATERIALIZATION_RECONCILED_AUDIT_INCOMPLETE",
    "generated_at": GENERATED_AT,
    "application_commit": APPLICATION_COMMIT,
    "writer_boundary": "Only the root orchestrator wrote the generator, matrix, and normalized audit evidence; RUN-016 returned source/evidence in messages and reported wrote_files=false.",
    "wave_formal_assignments_eligible": 1,
    "cumulative_formal_assignments_eligible": 16,
    "literal_prompt_minimum": 8,
    "literal_prompt_minimum_met": True,
    "planned_formal_assignments_target": 11,
    "planned_target_met": True,
    "all_returned": True,
    "all_completion_tests_met": True,
    "all_reported_no_writes": True,
    "outstanding_required_roles_or_waves": [
        "canonical feature identity and collision adjudication",
        "safe current-build rendered role/Site/viewport/state coverage",
        "full per-project upstream behavior/licence/edition triage",
        "fresh Pass 8 cross-reviewers",
        "final no-live-agent reconciliation",
    ],
    "assignment_returns": [assignment],
    "finalization_gate": False,
}


def main() -> None:
    write_json("evidence/source/current-visual-matrix-materialization-wave-01.json", materialization_payload)
    write_json("evidence/source/current-visual-matrix-agent-register.json", agent_payload)


if __name__ == "__main__":
    main()
