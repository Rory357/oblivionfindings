#!/usr/bin/env python3
"""Apply independently reviewed Wave 36 medication comparator evidence to canonical 904 artifacts."""

from __future__ import annotations

import copy
import csv
import hashlib
import json
from collections import Counter
from pathlib import Path
from typing import Any


GENERATOR = Path(__file__).resolve()
AUDIT = GENERATOR.parent.parent
SOURCE = AUDIT / "evidence" / "source"
COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
GENERATED_AT = "2026-08-21T22:30:00+12:00"
BENCHMARK = SOURCE / "benchmark-final-904-mapping.json"
INVENTORY = AUDIT / "inventory-904.json"
LEDGER = AUDIT / "02-eight-pass-coverage-ledger-904.csv"
MATRIX = AUDIT / "03-feature-to-benchmark-matrix-904.csv"
POINTER = SOURCE / "canonical-audit-inputs.json"
ARTIFACT = SOURCE / "benchmark-target-specific-adjudication-904-wave36.json"
SUMMARY = SOURCE / "final-904-benchmark-wave36-generation-summary.json"

EXPECTED = {
    BENCHMARK: "0923c6681011fe90c1e9e71bbea6c1ac5dbed33b4773c884fae2958fe1df869b",
    INVENTORY: "598d76cd63b23a7ea49164ad43e12cb10afdb9fed8437807d8b50d20b090cb9b",
    LEDGER: "cc2f167c8eb9985dfcc7ab8e35caf1896b4e4a772bcb25979eea5e021f2f3368",
    MATRIX: "67144dc92dbaf6a12cfe200840fda0a3e23f4cde3f9d86d1c4dadca400dd1283",
}

SELECTED = [
    "CAP-MED-ALERT-TRIAGE-RESOLUTION", "CAP-MED-API-SHIFT-SUMMARY",
    "CAP-MED-HANDOVER-ACKNOWLEDGEMENT", "CAP-MED-INR-MONITORING",
    "CAP-MED-MAR-CHART-REVIEW", "CAP-MED-SHIFT-MEDICATION-SNAPSHOT-API",
    "CAP-MED-WORKER-PRN-EFFECTIVENESS", "CAP-MED-WORKER-TODAY-WORKLIST",
]
SELECTION_SHA = "4575845f55f67ec857f676294b6054e79670793ad23c0a3c627a84fa9cfec25e"
DIRECT = "CAP-MED-MAR-CHART-REVIEW"

IPD = {"repo": "Bahmni/openmrs-module-ipd-frontend", "url": "https://github.com/Bahmni/openmrs-module-ipd-frontend", "commit": "cd5aff98be8b6b46cf85ac4e61964c5f27eb59d9", "tree": "45a9d806a093eb75a3c22f663daa86dc8437e5fb"}
MEDADMIN = {"repo": "Bahmni/openmrs-module-medicationadministration", "url": "https://github.com/Bahmni/openmrs-module-medicationadministration", "commit": "acb65f75d3515b4f2b36083345bfbafc2ee146b0", "tree": "60d811565b010e60541fcada7d8fd50476255dd5"}

FILES = [
    {"repo": IPD["repo"], "path": "LICENSE", "blob": "53465c6cc453b4d66ebc891368b8a05205af2e66", "sha256": "7b284ff454b433a2343d6832749da4e8c6b26ee502cc6f4c4ffe50eb6f1a5e92", "loci": "L1-L25,L370-L401"},
    {"repo": IPD["repo"], "path": "src/components/DrugChart/DrugChartView.jsx", "blob": "0495a51d1d6e0d58d2a8e789d5363b2558d653f0", "sha256": "2355760271e0441540cfc090ebfbb3f03c1f394181590277f4bd9625a239a5ab", "loci": "L84-L119,L131-L176,L214-L266,L274-L327"},
    {"repo": IPD["repo"], "path": "src/components/DrugChart/DrugChart.jsx", "blob": "38348203ed2a00a3a7b06b65d5e5f3304855cbd6", "sha256": "3ad02d642d91dbf6a8b1e351f6b4a92b3aca94641af188368c09100ed7197b30", "loci": "L13-L25,L106-L147"},
    {"repo": IPD["repo"], "path": "src/components/DrugChart/CalendarRow.jsx", "blob": "dfcb628e97ba0ed111c6957777baaad7f5d7977a", "sha256": "fb132bd9fb3c62923d13f6bc8f145b01a9bfcd31fd33ae1d88d0e83c5691182f", "loci": "L23-L105"},
    {"repo": IPD["repo"], "path": "src/components/DrugChart/DrugListCell.jsx", "blob": "82c49382697db57aff646111445cb6698deb5828", "sha256": "b8594759173c997c899e6f642ce13526bb9103f2915267559bfd1e7186fc616c", "loci": "L23-L29,L121-L132,L200-L315"},
    {"repo": IPD["repo"], "path": "src/components/NursingTasks/NursingTasks.jsx", "blob": "c1b203089ca099978010e12d788dc005bacc2e9c", "sha256": "5b5a7ae52125e1e684edafee3d4de5a02e34d792af0009fca9fe35f584284368", "loci": "L49-L84,L93-L105,L236-L250,L335-L415,L421-L535,L582-L589"},
    {"repo": IPD["repo"], "path": "src/components/NursingTasks/NursingTasksUtils.js", "blob": "9718893283241503b4a763da8bfa7452644df53a", "sha256": "b034a0f5b7a0b098b7c38ad3702ba4856eef27a2da4a28f455a4e54f9342fcbb", "loci": "L18-L31,L49-L67,L75-L94,L135-L212,L253-L268"},
]

EVALUATIONS = [
    {"working_key": "CAP-MED-ALERT-TRIAGE-RESOLUTION", "candidate": "Bahmni IPD nursing tasks", "status": "retain", "reason": "Patient task display has no medication-alert instance triage, ownership, resolution or replay lifecycle."},
    {"working_key": "CAP-MED-API-SHIFT-SUMMARY", "candidate": "Bahmni IPD nursing task API", "status": "retain", "reason": "Patient/time retrieval and client categorisation are not a server-owned shift summary aggregate."},
    {"working_key": "CAP-MED-HANDOVER-ACKNOWLEDGEMENT", "candidate": "Bahmni IPD shift screens", "status": "retain", "reason": "No handover record, recipient acknowledgement, state, actor/time or exception workflow is present."},
    {"working_key": "CAP-MED-INR-MONITORING", "candidate": "Bahmni medication administration", "status": "retain", "reason": "No INR/warfarin result, range, review, escalation or dosing-monitoring locus is present."},
    {"working_key": DIRECT, "candidate": "Bahmni IPD Drug Chart", "status": "direct", "requirement": "Review a patient medication chart across a shift window with schedule, dose/route and administration outcome provenance.", "loci": f"{IPD['repo']}@{IPD['commit']} :: DrugChartView.jsx L84-L119,L131-L176,L214-L266,L274-L327; DrugChart.jsx L13-L25,L106-L147; CalendarRow.jsx L23-L105; DrugListCell.jsx L23-L29,L121-L132,L200-L315", "behavior": "A patient and shift-window drug chart renders scheduled medicines, dose/route, administered or not-administered status, performer/time/notes and shift navigation.", "reason": "No Oblivion Site/access, cross-client, administration write, stock, correction or runtime parity."},
    {"working_key": "CAP-MED-SHIFT-MEDICATION-SNAPSHOT-API", "candidate": "Bahmni IPD shift screens", "status": "retain", "reason": "No outgoing-shift composite of due/given/missed/refused doses, PRN reviews, omissions and stock/CD state."},
    {"working_key": "CAP-MED-WORKER-PRN-EFFECTIVENESS", "candidate": "Bahmni PRN scheduling and administration", "status": "retain", "reason": "PRN scheduling/administration lacks a post-dose outcome, reassessment time, effectiveness decision and escalation record."},
    {"working_key": "CAP-MED-WORKER-TODAY-WORKLIST", "candidate": "Bahmni IPD nursing tasks", "status": "retain", "reason": "A single-patient shift screen is not an assigned-worker multi-client full-day board with rounds, stock and follow-ups."},
]


def sha_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def sha_lines(lines: list[str], *, sort: bool = False) -> str:
    values = sorted(lines) if sort else lines
    return hashlib.sha256("\n".join(values).encode("utf-8")).hexdigest()


def load(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: Any) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")


def rel(path: Path) -> str:
    return path.relative_to(AUDIT).as_posix()


def record(path: Path) -> dict[str, Any]:
    return {"path": rel(path), "sha256": sha_file(path), "bytes": path.stat().st_size}


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def mapping_tuple(row: dict[str, Any]) -> str:
    return "|".join([str(row["working_key"]), str(row["status"]), ";".join(sorted(set(str(value) for value in row.get("source_units", [])))), ";".join(sorted(set(str(value) for value in row.get("evidence_loci", []))))])


if ARTIFACT.exists():
    current, summary = load(BENCHMARK), load(SUMMARY)
    require(current["summary"]["eligible_total"] == 473 and current["summary"]["completion_unproved"]["total"] == 431, "Existing Wave36 count drift")
    for key, path in (("benchmark", BENCHMARK), ("inventory", INVENTORY), ("ledger", LEDGER), ("matrix", MATRIX)):
        require(summary["outputs"][key] == record(path), f"Existing Wave36 output drift: {key}")
    print(json.dumps({"status": "already_applied", "wave36": record(ARTIFACT), "benchmark": record(BENCHMARK), "summary": record(SUMMARY)}, indent=2))
    raise SystemExit(0)

for path, expected in EXPECTED.items():
    require(sha_file(path) == expected, f"Input SHA drift: {path}")
require(sha_lines(SELECTED) == SELECTION_SHA, "Wave36 selection digest mismatch")
benchmark, inventory = load(BENCHMARK), load(INVENTORY)
by_key = {row["working_key"]: row for row in benchmark["targets"]}
require(all(not by_key[key]["completion_credit"] and by_key[key]["status"] == "unproved" for key in SELECTED), "Selected target state drift")

artifact = {"schema_version": "1.0.0", "artifact": "benchmark-target-specific-adjudication-904-wave36", "generated_at": GENERATED_AT, "audited_commit": COMMIT, "read_only": True, "scope": "Eight current medication completion-unproved targets independently reviewed; one bounded direct comparator decision and seven retained-unproved decisions.", "methodology": {"family_credit_inherited": False, "runtime_boundary": "Comparator source evidence only; no Oblivion product, runtime, API execution, browser, representative-task, Site-policy or release credit.", "no_copy_rule": "Behavioural evidence only; no source, schema, UI or wording is copied.", "selection_preimage_rule": "Packet-order IDs joined by LF with no terminal LF, UTF-8 without BOM.", "selection_sha256": SELECTION_SHA, "independent_review_verdict": "GO_1_direct_7_retained"}, "selected_target_ids": SELECTED, "input_pins": {"benchmark_final_904_before_wave": record(BENCHMARK)}, "repository_snapshots": {"BAHMNI_IPD_FRONTEND": {**IPD, "repository_root_licence": "MPL-2.0 with OpenMRS healthcare disclaimer"}, "BAHMNI_MEDICATION_ADMINISTRATION": {**MEDADMIN, "repository_root_licence": "MPL-2.0 with OpenMRS healthcare disclaimer"}}, "verified_files": FILES, "counts": {"evaluated": 8, "direct": 1, "retained_unproved": 7, "documented_ncm": 0}, "evaluations": [{**row, "candidate_status": "verified_benchmark_direct_recommended" if row["status"] == "direct" else "retain_unproved", "completion_credit_recommended": row["status"] == "direct"} for row in EVALUATIONS], "collision_disclosure": {"prior_named_wave_packets": 30, "prior_unique_evaluated_ids": 304, "prior_unique_id_set_sha256": "b9f66d9a448966257441f5e82e5af88a4cf07a015b9c030854f7e28467aa374b", "selected_target_intersection": 0, "selected_routes_unique": "14/14", "target_source_tuple_sha256": "a1000cd6178ade5c20a59159db445e7c83325b49e1df6faf8d85cad916fefb83", "source_reuse": "NursingTasksUtils and medication-administration model/DAO reuse is disclosed and earns no new credit; the direct DrugChart loci are new. Shared pages transfer no family credit."}, "count_delta": {"verified_benchmark_direct": 1, "eligible_total": 1, "completion_unproved": -1, "documented_ncm": 0}, "post_wave_totals": {"verified_benchmark_direct": 362, "verified_benchmark_total": 384, "documented_ncm": 89, "eligible_total": 473, "completion_unproved": 431}}
write_json(ARTIFACT, artifact)

evaluation = next(row for row in EVALUATIONS if row["working_key"] == DIRECT)
row = by_key[DIRECT]
row.update({"status": "verified_benchmark_direct", "inheritance_method": "fresh_target_specific_wave36_direct", "prior_outcome": row["status"], "source_units": [f"fresh-904-wave36:{DIRECT}"], "evidence_loci": [evaluation["loci"]], "completion_credit": True})
status = Counter(str(item["status"]) for item in benchmark["targets"])
unproved = {"ordinary": status["unproved"], "audit_assigned_stable_name": status["unproved_audit_assigned_id"], "prior_pending": status["unproved_pending"], "prior_reject": status["unproved_reject"], "source_stable_semantic_merge": status["unproved_source_stable"]}
unproved["total"] = sum(unproved.values())
require(unproved == {"ordinary": 392, "audit_assigned_stable_name": 11, "prior_pending": 24, "prior_reject": 3, "source_stable_semantic_merge": 1, "total": 431}, "Wave36 partition mismatch")
benchmark.update({"generated_at": GENERATED_AT, "status": "target_specific_473_of_904_complete_not_overall_audit_completion", "summary": {"verified_benchmark": {"direct": 362, "strict_one_to_one_rename": 22, "total": 384}, "documented_no_credible_match": {"direct": 82, "strict_one_to_one_rename": 7, "total": 89}, "eligible_total": 473, "completion_unproved": unproved, "status_counts": dict(sorted(status.items()))}, "completion_boundary": {"eligible_rows": 473, "completion_unproved_rows": 431, "statement": benchmark["completion_boundary"]["statement"], "formal_audit_gate": "blocked_431_of_904_targets_lack_completed_target_specific_benchmark_or_documented_no_match_outcome"}})
benchmark["checksum_algorithm"]["full_mapping_sha256"] = sha_lines([mapping_tuple(item) for item in benchmark["targets"]], sort=True)
benchmark["checksum_algorithm"]["eligible_subset_sha256"] = sha_lines([mapping_tuple(item) for item in benchmark["targets"] if item.get("completion_credit")], sort=True)
benchmark["inputs"]["target_specific_wave36"] = {**record(ARTIFACT), "accepted_direct_count": 1, "retained_unproved_count": 7, "selected_keys_sha256": SELECTION_SHA}
write_json(BENCHMARK, benchmark)

feature = next(item for item in inventory["features"] if item["working_key"] == DIRECT)
feature["benchmark_mapping"] = {field: copy.deepcopy(row[field]) for field in ("status", "completion_credit", "inheritance_method", "prior_outcome", "source_units", "evidence_loci")}
inventory["generated_at"] = GENERATED_AT
inventory["benchmark_mapping"].update({"working_manifest_eligible": 473, "working_manifest_verified_benchmark": 384, "working_manifest_verified_direct": 362, "working_manifest_verified_rename": 22, "working_manifest_documented_no_credible_match": 89, "working_manifest_documented_ncm_direct": 82, "working_manifest_documented_ncm_rename": 7, "working_manifest_completion_unproved": 431, "completion_gate_status": "473/904 final targets have evidence-preserving benchmark/NCM mapping; 431 remain completion-unproved"})
inventory["pass_status"]["P3"] = "Blocked—473/904 targets mapped with evidence-preserving completion credit (384 verified benchmark, 89 documented No Credible Match); 431 unproved"
inventory["capability_denominator_status"]["benchmark_mapping"] = {"eligible": 473, "verified_benchmark": 384, "documented_no_credible_match": 89, "completion_unproved": 431}
inventory["canonical_feature_register_metadata"]["benchmark_mapping"] = {"verified_benchmark": 384, "documented_no_credible_match": 89, "completion_credit": 473, "completion_unproved": 431}
inventory["canonical_feature_register_metadata"]["source_artifacts"]["benchmark_mapping_sha256"] = sha_file(BENCHMARK)
write_json(INVENTORY, inventory)

def read_csv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle); return list(reader.fieldnames or []), [dict(item) for item in reader]

def write_csv(path: Path, headers: list[str], rows: list[dict[str, str]]) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=headers, extrasaction="raise", lineterminator="\n"); writer.writeheader(); writer.writerows(rows)

ledger_headers, ledger_rows = read_csv(LEDGER); matrix_headers, matrix_rows = read_csv(MATRIX)
ledger_row = next(item for item in ledger_rows if item["feature_id"] == DIRECT)
matrix_row = next(item for item in matrix_rows if item["feature_id"] == DIRECT)
mapped_p3 = "Mapped—verified benchmark with final-target completion credit; inheritance=fresh_target_specific_wave36_direct; full feature parity is not claimed."
ledger_row["P3_status"] = mapped_p3; ledger_row["gaps"] = ledger_row["gaps"].replace("P3 benchmark/no-match completion unproved; ", ""); ledger_row["evidence_count"] = str(int(ledger_row["evidence_count"] or "0") + 1)
matrix_row.update({"benchmark_candidates": evaluation["candidate"], "selected_open_source_benchmark": "Bahmni IPD Drug Chart", "benchmark_url_and_sha": f"{IPD['url']}/commit/{IPD['commit']}", "verified_behaviour": evaluation["behavior"], "neutral_requirements_extracted": evaluation["requirement"], "no_match_evidence": "", "P3": mapped_p3, "confidence": "High for the bounded patient/shift chart-review slice; Oblivion Site, access, writes, stock, correction and runtime remain unverified"})
write_csv(LEDGER, ledger_headers, ledger_rows); write_csv(MATRIX, matrix_headers, matrix_rows)

summary = {"schema_version": "1.0.0", "artifact": "final-904-benchmark-wave36-generation-summary", "generated_at": GENERATED_AT, "audited_commit": COMMIT, "read_only": True, "inputs": {"wave36": record(ARTIFACT)}, "outputs": {"benchmark": record(BENCHMARK), "inventory": record(INVENTORY), "ledger": record(LEDGER), "matrix": record(MATRIX)}, "mapping_tuple_hashes": {"full": benchmark["checksum_algorithm"]["full_mapping_sha256"], "eligible": benchmark["checksum_algorithm"]["eligible_subset_sha256"]}, "counts": {"denominator": 904, "direct": 362, "rename": 22, "verified": 384, "ncm": 89, "eligible": 473, "completion_unproved": 431}, "validation": {"selected": 8, "direct": 1, "retained": 7, "runtime_credit_delta": 0, "completion_status": "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"}}
write_json(SUMMARY, summary)
pointer = load(POINTER); pointer["generated_at"] = max(pointer.get("generated_at", ""), GENERATED_AT)
pointer["artifacts"].update({"benchmark": record(BENCHMARK), "inventory": record(INVENTORY), "eight_pass_ledger": record(LEDGER), "benchmark_matrix": record(MATRIX), "benchmark_wave36": record(ARTIFACT), "benchmark_wave36_generation_summary": record(SUMMARY)})
pointer["completion_status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"; pointer["runtime_credit_delta"] = 0
write_json(POINTER, pointer)
print(json.dumps({"status": "applied", "wave36": record(ARTIFACT), "benchmark": record(BENCHMARK), "inventory": record(INVENTORY), "ledger": record(LEDGER), "matrix": record(MATRIX), "summary": record(SUMMARY), "active_inputs": record(POINTER)}, indent=2))
