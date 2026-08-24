#!/usr/bin/env python3
"""Rebuild the evidence-preserving benchmark/NCM map for the 901 manifest.

Writes audit artifacts only. It does not execute application code, tests,
browser journeys, databases or external systems.
"""

from __future__ import annotations

import hashlib
import json
from collections import Counter
from pathlib import Path
from typing import Any


GENERATOR_DIR = Path(__file__).resolve().parent
AUDIT = GENERATOR_DIR.parent
SOURCE = AUDIT / "evidence" / "source"
MANIFEST_PATH = SOURCE / "working-capability-manifest-901.json"
BASE_MAPPING_PATH = SOURCE / "benchmark-final-894-mapping.json"
FRESH_PATH = SOURCE / "benchmark-target-specific-adjudication-901.json"
WAVE2_PATH = SOURCE / "benchmark-target-specific-adjudication-901-wave2.json"
WAVE3_PATH = SOURCE / "benchmark-target-specific-adjudication-901-wave3.json"
OUTPUT_PATH = SOURCE / "benchmark-final-901-mapping.json"
SUMMARY_PATH = SOURCE / "benchmark-final-901-generation-summary.json"
EXPECTED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"


def load(path: Path) -> dict[str, Any]:
    with path.open("r", encoding="utf-8-sig") as handle:
        value = json.load(handle)
    if not isinstance(value, dict):
        raise RuntimeError(f"Expected object: {path}")
    return value


def write(path: Path, value: dict[str, Any]) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def sha_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def strings(value: Any) -> list[str]:
    if value is None:
        return []
    if not isinstance(value, list):
        raise RuntimeError(f"Expected array, got {type(value).__name__}")
    return sorted({str(item).strip() for item in value if str(item).strip()})


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def tuple_line(row: dict[str, Any]) -> str:
    return "|".join([
        str(row["working_key"]),
        str(row["status"]),
        ";".join(strings(row.get("source_units", []))),
        ";".join(strings(row.get("evidence_loci", []))),
    ])


def sha_lines(lines: list[str]) -> str:
    return hashlib.sha256("\n".join(sorted(lines)).encode("utf-8")).hexdigest()


manifest = load(MANIFEST_PATH)
base = load(BASE_MAPPING_PATH)
fresh = load(FRESH_PATH)
wave2 = load(WAVE2_PATH)
wave3 = load(WAVE3_PATH)

require(manifest.get("audited_commit") == EXPECTED_COMMIT, "Manifest commit mismatch")
require(base.get("audited_commit") == EXPECTED_COMMIT, "Base mapping commit mismatch")
require(fresh.get("audited_commit") == EXPECTED_COMMIT, "Fresh adjudication commit mismatch")
require(wave2.get("audited_commit") == EXPECTED_COMMIT, "Wave-2 adjudication commit mismatch")
require(wave2.get("read_only") is True, "Wave-2 adjudication must be read-only")
require(wave3.get("audited_commit") == EXPECTED_COMMIT, "Wave-3 adjudication commit mismatch")
require(wave3.get("read_only") is True, "Wave-3 adjudication must be read-only")

manifest_rows = list(manifest.get("targets", []))
require(len(manifest_rows) == 901, f"Expected 901 manifest rows, got {len(manifest_rows)}")
manifest_by_key = {str(row["working_key"]): row for row in manifest_rows}
require(len(manifest_by_key) == 901, "Manifest working keys are not unique")

base_rows = list(base.get("targets", []))
base_by_key = {str(row["working_key"]): row for row in base_rows}
require(len(base_rows) == len(base_by_key) == 894, "Base benchmark map is not exactly 894 unique rows")
require(set(base_by_key) <= set(manifest_by_key), "Base mapping contains a target absent from 901 manifest")

rows: list[dict[str, Any]] = []
for key in sorted(manifest_by_key):
    manifest_row = manifest_by_key[key]
    if key in base_by_key:
        row = dict(base_by_key[key])
    else:
        id_status = str(manifest_row.get("id_status", ""))
        if id_status == "audit_assigned_stable_name":
            status = "unproved_audit_assigned_id"
        elif id_status.startswith("source_stable"):
            status = "unproved_source_stable"
        else:
            status = "unproved"
        row = {
            "working_key": key,
            "status": status,
            "inheritance_method": "none_new_901_target_without_completed_target_specific_adjudication",
            "prior_outcome": None,
            "source_units": [],
            "evidence_loci": [],
            "completion_credit": False,
        }

    row.update({
        "working_key": key,
        "id_status": manifest_row.get("id_status"),
        "class": manifest_row.get("class"),
        "canonical_module": manifest_row.get("canonical_module"),
        "source_family_ids": strings(manifest_row.get("source_family_ids", [])),
    })
    row["source_units"] = strings(row.get("source_units", []))
    row["evidence_loci"] = strings(row.get("evidence_loci", []))
    rows.append(row)

row_by_key = {str(row["working_key"]): row for row in rows}

# The SITE-DASHBOARD/NetBox rename was invalidated by the source-lineage
# correction to SITE-SITE-MEAL-PLAN. No old credit can survive if the fresh
# target-specific row is absent or fails validation.
meal = row_by_key["CAP-SITE-MEAL-SERVICE-COMPLETION"]
meal.update({
    "status": "unproved_pending",
    "inheritance_method": "none_lineage_corrected_prior_benchmark_invalid",
    "prior_outcome": "pending",
    "source_units": [],
    "evidence_loci": [],
    "completion_credit": False,
})

fresh_rows = list(fresh.get("targets", []))
require(len(fresh_rows) == 20, f"Expected 20 fresh target adjudications, got {len(fresh_rows)}")
fresh_keys = {str(row.get("working_key")) for row in fresh_rows}
require(len(fresh_keys) == 20, "Fresh adjudication target keys are not unique")
require(fresh_keys <= set(row_by_key), "Fresh adjudication contains unknown target keys")

wave2_rows = list(wave2.get("evaluations", []))
require(len(wave2_rows) == 15, f"Expected 15 wave-2 evaluations, got {len(wave2_rows)}")
wave2_keys = {str(row.get("working_key")) for row in wave2_rows}
require(len(wave2_keys) == 15, "Wave-2 target keys are not unique")
require(wave2_keys <= set(row_by_key), "Wave-2 adjudication contains unknown target keys")
require(not (wave2_keys & fresh_keys), "Wave-2 and initial fresh target keys overlap")
wave2_recommended = [row for row in wave2_rows if row.get("completion_credit_recommended") is True]
wave2_recommended_keys = {str(row.get("working_key")) for row in wave2_recommended}
expected_wave2_keys = {
    "FLEET-ASSET",
    "SEC-DEVICE-ASSIGNMENT",
    "CAP-FIN-API-CLIENT-FINANCIAL-SUMMARY-LEDGER",
    "FIN-MATCH-RULE",
    "CAP-ASSET-ASSET-QR-LABEL-ARTIFACT",
}
require(wave2_recommended_keys == expected_wave2_keys, "Wave-2 recommended target set mismatch")
require(
    sha_lines(list(wave2_recommended_keys)) == "0af7d64a38f1c6eff12c5fb141790a37b380d1967fc51fc4f87546bc2574cc9b",
    "Wave-2 recommended key checksum mismatch",
)

wave3_rows = list(wave3.get("evaluations", []))
require(len(wave3_rows) == 12, f"Expected 12 wave-3 evaluations, got {len(wave3_rows)}")
wave3_keys = {str(row.get("working_key")) for row in wave3_rows}
require(len(wave3_keys) == 12, "Wave-3 target keys are not unique")
require(wave3_keys <= set(row_by_key), "Wave-3 adjudication contains unknown target keys")
require(not (wave3_keys & fresh_keys), "Wave-3 and initial fresh target keys overlap")
require(not (wave3_keys & wave2_keys), "Wave-3 and wave-2 target keys overlap")
require(all(row.get("completion_credit_recommended") is True for row in wave3_rows), "Every wave-3 row must be completed")
require(
    sha_lines(list(wave3_keys)) == "dcff44f68063383716a17b9e4349f3160ad9250a23f7be7cfbd0bef58cf8fcb1",
    "Wave-3 evaluated key checksum mismatch",
)

for adjudication in fresh_rows:
    key = str(adjudication["working_key"])
    status = str(adjudication.get("mapping_status", ""))
    require(status in {"verified_benchmark_direct", "documented_ncm_direct"}, f"Invalid fresh status for {key}: {status}")
    source_unit = str(adjudication.get("source_unit", "")).strip()
    evidence_loci = strings(adjudication.get("evidence_loci", []))
    require(source_unit, f"Fresh adjudication lacks source unit: {key}")
    require(evidence_loci, f"Fresh adjudication lacks evidence loci: {key}")
    if status == "verified_benchmark_direct":
        require(adjudication.get("benchmark", {}).get("official_repository_url"), f"Benchmark repo missing: {key}")
        require(adjudication.get("benchmark", {}).get("commit_sha"), f"Benchmark SHA missing: {key}")
        require(adjudication.get("benchmark", {}).get("source_loci"), f"Benchmark source loci missing: {key}")
        prior_outcome = "verified"
    else:
        require(adjudication.get("search_terms"), f"NCM search terms missing: {key}")
        require(adjudication.get("rejected_repositories"), f"NCM rejected repositories missing: {key}")
        prior_outcome = "ncm"
    row_by_key[key].update({
        "status": status,
        "inheritance_method": "fresh_target_specific_adjudication",
        "prior_outcome": prior_outcome,
        "source_units": [source_unit],
        "evidence_loci": evidence_loci,
        "completion_credit": True,
        "fresh_adjudication_id": str(adjudication.get("adjudication_id", source_unit)),
    })

repository_snapshots = list(wave2.get("repository_snapshots", {}).values())
snapshot_pairs = {
    (str(snapshot.get("url", "")), str(snapshot.get("commit_sha", "")))
    for snapshot in repository_snapshots
}
for adjudication in wave2_recommended:
    key = str(adjudication["working_key"])
    require(not row_by_key[key].get("completion_credit"), f"Wave-2 target already has completion credit: {key}")
    require(adjudication.get("candidate_status") == "candidate_found_direct", f"Invalid wave-2 candidate status: {key}")
    evidence_loci = strings(adjudication.get("evidence_loci", []))
    benchmark = adjudication.get("benchmark", {})
    repo_url = str(benchmark.get("official_repository_url", "")).strip()
    commit_sha = str(benchmark.get("commit_sha", "")).strip()
    source_loci = strings(benchmark.get("source_loci", []))
    require(repo_url.startswith("https://github.com/"), f"Wave-2 official repository missing: {key}")
    require(len(commit_sha) == 40 and all(ch in "0123456789abcdef" for ch in commit_sha), f"Invalid wave-2 SHA: {key}")
    require((repo_url, commit_sha) in snapshot_pairs, f"Wave-2 repository snapshot mismatch: {key}")
    require(adjudication.get("neutral_requirement"), f"Wave-2 neutral requirement missing: {key}")
    require(adjudication.get("search_terms"), f"Wave-2 search terms missing: {key}")
    require(adjudication.get("inheritance_boundary"), f"Wave-2 inheritance boundary missing: {key}")
    require(evidence_loci, f"Wave-2 evidence loci missing: {key}")
    require(source_loci, f"Wave-2 source loci missing: {key}")
    require(benchmark.get("proven_slice"), f"Wave-2 proven slice missing: {key}")
    require(benchmark.get("parity_limits"), f"Wave-2 parity limits missing: {key}")
    if key == "CAP-ASSET-ASSET-QR-LABEL-ARTIFACT":
        joined = "\n".join(source_loci)
        require("QrCodeController.php:L26-L64" in joined, "QR controller locus is not the corrected range")
        require("AssetsController.php:L2014-L2097" in joined, "Asset-label locus is not the corrected range")
        require("L1844-L1904" not in joined, "Unrelated QR locus must not receive credit")
    source_unit = f"current-901:{key}"
    row_by_key[key].update({
        "status": "verified_benchmark_direct",
        "inheritance_method": "fresh_target_specific_adjudication",
        "prior_outcome": "verified",
        "source_units": [source_unit],
        "evidence_loci": evidence_loci,
        "completion_credit": True,
        "fresh_adjudication_id": str(adjudication.get("adjudication_id", source_unit)),
    })

wave3_repository_snapshots = list(wave3.get("repository_snapshots", {}).values())
wave3_snapshot_pairs = {
    (str(snapshot.get("url", "")), str(snapshot.get("commit_sha", "")))
    for snapshot in wave3_repository_snapshots
}
for adjudication in wave3_rows:
    key = str(adjudication["working_key"])
    require(not row_by_key[key].get("completion_credit"), f"Wave-3 target already has completion credit: {key}")
    candidate_status = str(adjudication.get("candidate_status", ""))
    require(candidate_status in {"candidate_found_direct", "documented_ncm_direct"}, f"Invalid wave-3 status: {key}")
    require(adjudication.get("neutral_requirement"), f"Wave-3 neutral requirement missing: {key}")
    require(adjudication.get("search_terms"), f"Wave-3 search terms missing: {key}")
    require(adjudication.get("inheritance_boundary"), f"Wave-3 inheritance boundary missing: {key}")
    evidence_loci = strings(adjudication.get("evidence_loci", []))
    require(evidence_loci, f"Wave-3 evidence loci missing: {key}")
    lineage = adjudication.get("current_source_lineage", {})
    require(
        strings(lineage.get("source_family_ids", []))
        == strings(manifest_by_key[key].get("source_family_ids", [])),
        f"Wave-3 source lineage mismatch: {key}",
    )

    if candidate_status == "candidate_found_direct":
        benchmark = adjudication.get("benchmark", {})
        repo_url = str(benchmark.get("official_repository_url", "")).strip()
        commit_sha = str(benchmark.get("commit_sha", "")).strip()
        source_loci = strings(benchmark.get("source_loci", []))
        require(repo_url.startswith("https://github.com/"), f"Wave-3 official repository missing: {key}")
        require(len(commit_sha) == 40 and all(ch in "0123456789abcdef" for ch in commit_sha), f"Invalid wave-3 SHA: {key}")
        require((repo_url, commit_sha) in wave3_snapshot_pairs, f"Wave-3 repository snapshot mismatch: {key}")
        require(source_loci, f"Wave-3 source loci missing: {key}")
        require(benchmark.get("proven_slice"), f"Wave-3 proven slice missing: {key}")
        require(benchmark.get("parity_limits"), f"Wave-3 parity limits missing: {key}")
        final_status = "verified_benchmark_direct"
        prior_outcome = "verified"
    else:
        rejected = list(adjudication.get("rejected_repositories", []))
        require(rejected, f"Wave-3 NCM repository rejections missing: {key}")
        require(adjudication.get("bounded_ncm_reason"), f"Wave-3 bounded NCM reason missing: {key}")
        require(all(
            (str(item.get("official_repository_url", "")), str(item.get("commit_sha", ""))) in wave3_snapshot_pairs
            and str(item.get("reason", "")).strip()
            for item in rejected
        ), f"Wave-3 NCM snapshot/reason mismatch: {key}")
        final_status = "documented_ncm_direct"
        prior_outcome = "ncm"

    source_unit = f"current-901:{key}"
    row_by_key[key].update({
        "status": final_status,
        "inheritance_method": "fresh_target_specific_adjudication_wave3",
        "prior_outcome": prior_outcome,
        "source_units": [source_unit],
        "evidence_loci": evidence_loci,
        "completion_credit": True,
        "fresh_adjudication_id": str(adjudication.get("adjudication_id", source_unit)),
    })

rows = [row_by_key[key] for key in sorted(row_by_key)]
status_counts = Counter(str(row["status"]) for row in rows)
verified_direct = status_counts["verified_benchmark_direct"]
verified_rename = status_counts["verified_benchmark_rename"]
ncm_direct = status_counts["documented_ncm_direct"]
ncm_rename = status_counts["documented_ncm_rename"]
eligible = sum(bool(row.get("completion_credit")) for row in rows)
unproved = len(rows) - eligible

require(verified_direct == 196 and verified_rename == 22, f"Expected 196 direct + 22 rename verified rows, got {verified_direct}+{verified_rename}")
require(ncm_direct == 77 and ncm_rename == 7, f"Expected 77 direct + 7 rename NCM rows, got {ncm_direct}+{ncm_rename}")
require(eligible == 302 and unproved == 599, f"Expected 302/599 split, got {eligible}/{unproved}")

unproved_statuses = {
    "ordinary": "unproved",
    "audit_assigned_stable_name": "unproved_audit_assigned_id",
    "prior_pending": "unproved_pending",
    "prior_reject": "unproved_reject",
    "source_stable_semantic_merge": "unproved_source_stable",
}
completion_unproved = {name: status_counts[status] for name, status in unproved_statuses.items()}
completion_unproved["total"] = sum(completion_unproved.values())
require(completion_unproved == {
    "ordinary": 555,
    "audit_assigned_stable_name": 10,
    "prior_pending": 30,
    "prior_reject": 3,
    "source_stable_semantic_merge": 1,
    "total": 599,
}, f"Unexpected unproved partition: {completion_unproved}")

all_lines = [tuple_line(row) for row in rows]
eligible_lines = [tuple_line(row) for row in rows if row.get("completion_credit")]

output = {
    "schema_version": "1.1",
    "artifact": "benchmark-final-901-mapping",
    "generated_at": wave3.get("generated_at"),
    "audited_commit": EXPECTED_COMMIT,
    "status": "target_specific_302_of_901_complete_not_overall_audit_completion",
    "audit_boundary": "Evidence-preserving static benchmark and bounded No Credible Match mapping only. No source code was copied and no runtime/browser/database claim is made.",
    "denominator": {"total": 901, "H": 788, "D": 111, "M": 2},
    "summary": {
        "verified_benchmark": {"direct": verified_direct, "strict_one_to_one_rename": verified_rename, "total": verified_direct + verified_rename},
        "documented_no_credible_match": {"direct": ncm_direct, "strict_one_to_one_rename": ncm_rename, "total": ncm_direct + ncm_rename},
        "eligible_total": eligible,
        "completion_unproved": completion_unproved,
        "status_counts": dict(sorted(status_counts.items())),
    },
    "completion_boundary": {
        "eligible_rows": eligible,
        "completion_unproved_rows": unproved,
        "statement": "Only completed target-specific verified-benchmark/documented-NCM rows, or previously proved direct/strict-rename rows, receive credit. Split/regroup/merge and pending/reject rows do not inherit mechanically.",
        "formal_audit_gate": "blocked_599_of_901_targets_lack_completed_target_specific_benchmark_or_documented_no_match_outcome",
    },
    "rules": {
        **dict(base.get("rules", {})),
        "fresh_target_specific": "A fresh row must record a neutral Oblivion requirement, target-specific queries and either an official pinned repository locus or bounded rejected-repository evaluations.",
        "lineage_correction": "A source-family correction invalidates any old direct/rename credit unless the corrected target receives a fresh target-specific adjudication.",
    },
    "checksum_algorithm": {
        "tuple_schema": "working_key|status|source_units|evidence_loci",
        "array_encoding": "Ordinal-sort and deduplicate source_units and evidence_loci independently; join each array with semicolon.",
        "record_encoding": "Ordinal-sort complete tuple lines; join with LF and no terminal LF; UTF-8 without BOM; SHA-256 lowercase hexadecimal.",
        "eligible_subset": "Rows where completion_credit is true.",
        "full_mapping_sha256": sha_lines(all_lines),
        "eligible_subset_sha256": sha_lines(eligible_lines),
    },
    "inputs": {
        "working_manifest": {"path": f"evidence/source/{MANIFEST_PATH.name}", "file_sha256": sha_file(MANIFEST_PATH), "canonical_stable_target_ids_sha256": manifest.get("checksums", {}).get("canonical_stable_target_ids_sha256")},
        "base_894_mapping": {"path": f"evidence/source/{BASE_MAPPING_PATH.name}", "file_sha256": sha_file(BASE_MAPPING_PATH)},
        "fresh_target_specific_adjudication": {"path": f"evidence/source/{FRESH_PATH.name}", "file_sha256": sha_file(FRESH_PATH)},
        "fresh_target_specific_adjudication_wave2": {"path": f"evidence/source/{WAVE2_PATH.name}", "file_sha256": sha_file(WAVE2_PATH)},
        "fresh_target_specific_adjudication_wave3": {"path": f"evidence/source/{WAVE3_PATH.name}", "file_sha256": sha_file(WAVE3_PATH)},
        "selected_benchmark_adjudication": base.get("inputs", {}).get("selected_benchmark_adjudication"),
        "no_credible_match_adjudication": base.get("inputs", {}).get("no_credible_match_adjudication"),
    },
    "explicit_exclusions": {
        **dict(base.get("explicit_exclusions", {})),
        "lineage_corrected_prior_credit_replaced": ["CAP-SITE-MEAL-SERVICE-COMPLETION"],
    },
    "targets": rows,
}

write(OUTPUT_PATH, output)
summary = {
    "schema_version": "1.0",
    "artifact": "benchmark-final-901-generation-summary",
    "generated_at": wave3.get("generated_at"),
    "audited_commit": EXPECTED_COMMIT,
    "inputs": output["inputs"],
    "output": {"file": OUTPUT_PATH.name, "sha256": sha_file(OUTPUT_PATH)},
    "counts": output["summary"],
    "checksums": output["checksum_algorithm"],
    "validation": {
        "exact_manifest_key_set": set(row_by_key) == set(manifest_by_key),
        "unique_target_keys": len(row_by_key) == 901,
        "fresh_target_count": len(fresh_rows) == 20,
        "wave2_evaluated_target_count": len(wave2_rows) == 15,
        "wave2_selected_target_count": len(wave2_recommended) == 5,
        "wave3_evaluated_target_count": len(wave3_rows) == 12,
        "wave3_verified_target_count": sum(row.get("candidate_status") == "candidate_found_direct" for row in wave3_rows) == 12,
        "wave3_documented_ncm_target_count": sum(row.get("candidate_status") == "documented_ncm_direct" for row in wave3_rows) == 0,
        "combined_fresh_selected_target_count": len(fresh_rows) + len(wave2_recommended) + len(wave3_rows) == 37,
        "eligible_rows_have_one_source_unit": all(len(row["source_units"]) == 1 for row in rows if row.get("completion_credit")),
        "eligible_rows_have_evidence_loci": all(bool(row["evidence_loci"]) for row in rows if row.get("completion_credit")),
        "lineage_snapshots_match_manifest": all(row["source_family_ids"] == strings(manifest_by_key[row["working_key"]].get("source_family_ids", [])) for row in rows),
    },
    "completion_gate": {"complete": False, "reason": "599/901 targets still require completed target-specific benchmark or bounded No Credible Match adjudication."},
}
write(SUMMARY_PATH, summary)

print(json.dumps({
    "output": str(OUTPUT_PATH),
    "sha256": sha_file(OUTPUT_PATH),
    "eligible": eligible,
    "unproved": unproved,
    "verified": verified_direct + verified_rename,
    "ncm": ncm_direct + ncm_rename,
    "full_tuple_sha256": output["checksum_algorithm"]["full_mapping_sha256"],
    "eligible_tuple_sha256": output["checksum_algorithm"]["eligible_subset_sha256"],
}, indent=2))
