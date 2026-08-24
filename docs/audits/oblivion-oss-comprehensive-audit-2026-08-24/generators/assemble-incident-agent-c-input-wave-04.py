from __future__ import annotations

import hashlib
import json
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
NEUTRAL_PATH = AUDIT_DIR / "evidence/benchmark/raw-run-072-agent-b-neutral-incident-requirements-wave-04.json"
SOURCE_PATH = AUDIT_DIR / "evidence/benchmark/raw-run-049-current-source-facet-refinement-wave-02.json"
OUTPUT_PATH = AUDIT_DIR / "evidence/benchmark/sealed-run-072-agent-c-incident-comparison-input-wave-04.json"
EXPECTED_NEUTRAL_SHA256 = "425f9c38320e37915e5ceff33a4f65b8d96b8183cb6e2b70955e07b1145e8c97"
EXPECTED_SOURCE_SHA256 = "2ed6b9bae1270a4c00b3b427daa077c145cfb370aa26cc4d12e4a3e68acc765a"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
FACETS = {"incident_review", "incident_closure"}


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


neutral_bytes = NEUTRAL_PATH.read_bytes()
source_bytes = SOURCE_PATH.read_bytes()
assert sha256(neutral_bytes) == EXPECTED_NEUTRAL_SHA256
assert sha256(source_bytes) == EXPECTED_SOURCE_SHA256
neutral = json.loads(neutral_bytes)
source = json.loads(source_bytes)

assert neutral["run_id"] == "RUN-072-B"
assert neutral["counts"]["requirements"] == {
    "MUST": 14,
    "SHOULD": 0,
    "NOT_ESTABLISHED": 25,
    "total": 39,
}
assert neutral["counts"]["unique_source_observation_ids_cited"] == 48
assert neutral["counts"]["uncited_source_observation_ids"] == 0
assert source["run_id"] == "RUN-049"
assert source["input_boundary"]["application_commit"] == APPLICATION_COMMIT
assert source["input_boundary"]["application_tree"] == APPLICATION_TREE
assert source["input_boundary"]["application_read_mode"] == "PINNED_GIT_OBJECT_ONLY"
assert source["counts"]["credited_facets"] == 0

current_source_facets = [facet for facet in source["facets"] if facet["facet_key"] in FACETS]
current_source_facets.sort(key=lambda facet: facet["facet_key"])
assert len(current_source_facets) == 2
assert {facet["facet_key"] for facet in current_source_facets} == FACETS
assert all(facet["feature_id"] == "CAP-INC-INCIDENT-REVIEW-CLOSURE" for facet in current_source_facets)
assert all(facet["credit"] is False and facet["completion_credit"] is False for facet in current_source_facets)

payload = {
    "schema_version": "sealed_run_072_agent_c_incident_comparison_input_wave_04_v1",
    "run_id": "RUN-072-C-INPUT",
    "status": "NEUTRAL_SPECIFICATION_PLUS_PINNED_CURRENT_SOURCE_ONLY",
    "input_seals": {
        "neutral_specification": {
            "path": "evidence/benchmark/raw-run-072-agent-b-neutral-incident-requirements-wave-04.json",
            "bytes": len(neutral_bytes),
            "sha256": EXPECTED_NEUTRAL_SHA256,
        },
        "current_source_packet": {
            "path": "evidence/benchmark/raw-run-049-current-source-facet-refinement-wave-02.json",
            "bytes": len(source_bytes),
            "sha256": EXPECTED_SOURCE_SHA256,
        },
    },
    "application_source_pin": {
        "commit": APPLICATION_COMMIT,
        "tree": APPLICATION_TREE,
        "read_mode": "PINNED_GIT_OBJECT_ONLY",
    },
    "neutral_specification": neutral,
    "current_source_facets": current_source_facets,
    "comparison_contract": {
        "unit": "Each of the 39 neutral requirements, compared independently to the applicable pinned current-source facet.",
        "allowed_outcomes": ["MET", "PARTIAL", "GAP", "CONTRADICTED", "NOT_COMPARABLE"],
        "unknowns_must_be_preserved": True,
        "current_source_static_only": True,
        "upstream_identity_withheld": True,
        "project_selection_or_mapping_decision_allowed": False,
        "credit_allowed": False,
    },
    "counts": {
        "neutral_requirements": 39,
        "current_source_facets": 2,
        "upstream_identity_records": 0,
        "old_comparison_records": 0,
        "credit_awards": 0,
    },
    "attestation": {
        "upstream_project_identity_excluded": True,
        "reattachment_appendices_excluded": True,
        "unrelated_current_source_facets_excluded": True,
        "runtime_browser_test_network_evidence_excluded": True,
        "zero_credit": True,
    },
}
output_bytes = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
if OUTPUT_PATH.exists():
    assert OUTPUT_PATH.read_bytes() == output_bytes, f"Refusing to overwrite different bytes: {OUTPUT_PATH}"
else:
    OUTPUT_PATH.write_bytes(output_bytes)
assert json.loads(OUTPUT_PATH.read_bytes()) == payload
print(f"{OUTPUT_PATH.relative_to(AUDIT_DIR)}\t{len(output_bytes)}\t{sha256(output_bytes)}")
