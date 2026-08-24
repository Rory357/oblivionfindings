#!/usr/bin/env python3
"""Apply independently reviewed Wave 38 governance/compliance comparator evidence."""

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
GENERATED_AT = "2026-08-21T23:55:00+12:00"

BENCHMARK = SOURCE / "benchmark-final-904-mapping.json"
INVENTORY = AUDIT / "inventory-904.json"
LEDGER = AUDIT / "02-eight-pass-coverage-ledger-904.csv"
MATRIX = AUDIT / "03-feature-to-benchmark-matrix-904.csv"
POINTER = SOURCE / "canonical-audit-inputs.json"
ARTIFACT = SOURCE / "benchmark-target-specific-adjudication-904-wave38.json"
SUMMARY = SOURCE / "final-904-benchmark-wave38-generation-summary.json"

EXPECTED = {
    BENCHMARK: "4696a2521654be97a9d0d8528b4bb35080a68376ec096819e43c6008ea51ddbc",
    INVENTORY: "ac777686d9f7d3b806ee4668ccab6c1785e74586bce95abc82b7e16b713d815b",
    LEDGER: "27803d20b2cbce3476b2ea94518df710ccb60a4ea0d132f188047c557b1c290e",
    MATRIX: "c1065ca5e01c22ca08c1349b59fc540261f185f2290c374675381e26676e5664",
    POINTER: "f588e4bfc0537b192a2043a58c913f35b1bf4b7e3f423b5febab5dbf70cbf69f",
}

CISO = {
    "repo": "intuitem/ciso-assistant-community",
    "url": "https://github.com/intuitem/ciso-assistant-community",
    "commit": "82e8a7ed66fbfbd2d8ddc93e9fcfac702a1f8a8c",
    "tree": "b7afb2c9bfb2014a1e2b191aa7092ad4ee2f78af",
    "parent": "c99132248ae24e77209b0054c455ad0126bcf02a",
}

SELECTED = [
    "CAP-COMP-OBLIGATION-EVIDENCE",
    "CAP-COMP-OBLIGATION-LIFECYCLE",
    "CAP-GOV-RISK-ACCEPTANCE-CLOSURE",
    "CAP-GOV-RISK-COMMITTEE-OVERSIGHT",
    "CAP-GOV-RISK-HEATMAP",
    "CAP-GOV-RISK-TREATMENT-DOWNLOAD",
    "CAP-GOV-RISK-TREATMENTS-EVIDENCE",
    "CAP-GOV-RISK-TRENDS",
]
SELECTION_SHA = "e0426d3d835de5cbf9076f641136e94eaf30ea13bf9b5ad1ed50585934a00b48"
DIRECT = {
    "CAP-COMP-OBLIGATION-EVIDENCE",
    "CAP-COMP-OBLIGATION-LIFECYCLE",
    "CAP-GOV-RISK-HEATMAP",
    "CAP-GOV-RISK-TREATMENT-DOWNLOAD",
    "CAP-GOV-RISK-TREATMENTS-EVIDENCE",
    "CAP-GOV-RISK-TRENDS",
}

FILES = [
    {"path": "LICENSE.md", "blob": "c12c788b3b96e8e97004cda9a130d5c70114336c", "bytes": 817,
     "sha256": "5d880897167f748de8e9750feebd8331c04b06daba8f829ace3cbfc13866d13a", "loci": "L1-L9"},
    {"path": "LICENSE-AGPL.txt", "blob": "0ad25db4bd1d86c452db3f9602ccdbe172438f52", "bytes": 34523,
     "sha256": "8486a10c4393cee1c25392769ddd3b2d6c242d6ec7928e1414efff7dfb2f07ef", "loci": "L1-L12"},
    {"path": "enterprise/LICENSE.md", "blob": "a536385426bcf5126a73e7db9c643ea992e36203", "bytes": 2141,
     "sha256": "4344d7762701977262a0be691551c216c39deab97f04d1d2e6204d5391814e58", "loci": "L1-L16; excluded enterprise boundary"},
    {"path": "backend/core/base_models.py", "blob": "8daec5203ca2d3f457bed2dc62bdab6d940f99e6", "bytes": 9389,
     "sha256": "5641035b8e7edfdedd39bcec4c5c7e46b3409625e3e31c81162fce3aea2aeaa3", "loci": "L181-L190"},
    {"path": "backend/core/models.py", "blob": "ac813cacd4ccc9d41f369b09df2fb8bd017792ae", "bytes": 412264,
     "sha256": "3aaa52bef1882e17d5cef904a8630dfbe66565b99740feea325744bdaea4d36d", "loci": "L5067-L5205,L5589-L5715,L6292-L6316,L6322-L6372,L6385-L6446,L6852-L7032,L7322-L7400,L7540-L7581,L8778-L8882,L9911-L10016,L10324-L10420"},
    {"path": "backend/core/serializers.py", "blob": "a0070142e83291d488b1242dede6605072e002e2", "bytes": 221147,
     "sha256": "812068d623e17eb47cae1a25415b3ee9ffa2c6e45b1870f45f381949d68d135d", "loci": "L130-L275,L467-L505,L1459-L1624,L2568-L2728,L3313-L3485"},
    {"path": "backend/core/views.py", "blob": "168b6b0caa4748950a8d73d5d5f55aab726d7150", "bytes": 713378,
     "sha256": "4ec77ab6af39ebfa03b958d0f7f90b4001559dae5e5c401923a45d0a2cf78f7f", "loci": "L953-L1009,L4818-L4868,L7122-L7275,L9631-L9712,L10043-L10115,L10682-L10724,L14261-L14326"},
    {"path": "backend/metrology/models.py", "blob": "e433e171bb2b0f3d2d894dbc7bf84b492e86c9eb", "bytes": 44031,
     "sha256": "35669d51d5229723abffb4a550920bdcd59f415829821da886b6b0771fe07121", "loci": "L493-L546"},
    {"path": "frontend/src/routes/(app)/(internal)/risk-assessments/[id=uuid]/+page.svelte", "blob": "804e3bc3cdb927bc0a1849ae22a1e54999cf348b", "bytes": 17276,
     "sha256": "a57085c56bb44e6725700647aced6b59167cde1a3db61b3c7efb5664f7d44276", "loci": "L185-L213,L487-L527"},
    {"path": "frontend/src/routes/(app)/(internal)/risk-assessments/[id=uuid]/analytics/+page.server.ts", "blob": "698f2fd4065218dabb96a5fa7ed14477bd436841", "bytes": 1031,
     "sha256": "8b70bfdb324105202a51d7980e8e4f2213c7aecb0c84c3f2de774bf202cecada", "loci": "L4-L34"},
    {"path": "frontend/src/routes/(app)/(internal)/risk-assessments/[id=uuid]/analytics/+page.svelte", "blob": "0ffb1277200dbdb3a546e5c9c7b2f85344114ca2", "bytes": 8657,
     "sha256": "851f15cae100f360f5a9d61e3d5bde989137fc3313197813ee95895b4e03baba", "loci": "L27-L101,L122-L156"},
    {"path": "frontend/src/lib/components/RiskMatrix/RiskMatrix.svelte", "blob": "bf65d8cec6e4dbd4c30fd897098e4cce6b3331ac", "bytes": 7334,
     "sha256": "8c88e5df0b24b15b2296c53468cd308fd50918c891b492497ebbacdd9668a559", "loci": "L41-L85,L133-L232"},
    {"path": "product-docs/introduction/vocabulary.md", "blob": "f512f0bd245e9d666e3ff794a6e3f3142f97b437", "bytes": 18912,
     "sha256": "a8be8cb662e58617818bded10d35941a289bdd797dc2911b8870ca0c814efb76", "loci": "L169"},
]

EVALUATIONS = [
    {"working_key": "CAP-COMP-OBLIGATION-EVIDENCE", "status": "direct", "candidate": "CISO Assistant versioned evidence",
     "requirement": "Manage authorised, requirement-linked obligation evidence with versioned file/link material, integrity metadata and guarded retrieval.",
     "behavior": "Evidence and EvidenceRevision preserve status, expiry, attachment hashes and versions, link to RequirementAssessment, and expose scoped revision/download APIs.",
     "loci": "intuitem/ciso-assistant-community@82e8a7ed66fbfbd2d8ddc93e9fcfac702a1f8a8c :: backend/core/models.py L5067-L5205,L8778-L8878; serializers.py L2568-L2728; views.py L9631-L9712,L10043-L10115,L14261-L14323",
     "reason": "Analogous requirement-linked evidence lifecycle only; no exact Oblivion obligation upload, Site, direct-object, UI or runtime parity."},
    {"working_key": "CAP-COMP-OBLIGATION-LIFECYCLE", "status": "direct", "candidate": "CISO Assistant compliance assessment lifecycle",
     "requirement": "Operate an authorised compliance-requirement assessment lifecycle with state, result, observations, reviewer, dates and guarded writes.",
     "behavior": "ComplianceAssessment and RequirementAssessment implement planned/todo/in-progress/in-review/done state, due date, version and reviewer fields under writable serializer/view boundaries.",
     "loci": "intuitem/ciso-assistant-community@82e8a7ed66fbfbd2d8ddc93e9fcfac702a1f8a8c :: backend/core/base_models.py L181-L190; models.py L6322-L6372,L7322-L7400,L7540-L7581,L8778-L8882; serializers.py L130-L275,L3313-L3485; views.py L10682-L10724,L14261-L14326",
     "reason": "Framework-requirement assessment analogue, not a one-to-one free-form Oblivion regulatory-obligation model or runtime claim."},
    {"working_key": "CAP-GOV-RISK-ACCEPTANCE-CLOSURE", "status": "retain", "candidate": "CISO Assistant risk acceptance",
     "reason": "Approver-only accept/reject/revoke with justification, timestamps and expiry does not prove the indivisible target's distinct terminal risk-close state and closure rationale."},
    {"working_key": "CAP-GOV-RISK-COMMITTEE-OVERSIGHT", "status": "retain", "candidate": "CISO Assistant validation flow",
     "reason": "Generic assessment/evidence approval and vocabulary analogy do not provide committee identity, membership/quorum, committee-scoped portfolio, decisions, actions or review evidence."},
    {"working_key": "CAP-GOV-RISK-HEATMAP", "status": "direct", "candidate": "CISO Assistant risk matrix",
     "requirement": "Render an authorised inherent/current/residual probability-impact matrix with configured levels and per-cell scenario aggregation.",
     "behavior": "RiskScenario levels and the risk-assessment page construct inherent/current/residual clusters rendered by a configured RiskMatrix with count bubbles.",
     "loci": "intuitem/ciso-assistant-community@82e8a7ed66fbfbd2d8ddc93e9fcfac702a1f8a8c :: backend/core/models.py L6852-L7032; risk-assessments/[id=uuid]/+page.svelte L185-L213,L487-L527; RiskMatrix.svelte L41-L85,L133-L232",
     "reason": "No Oblivion Site, committee, exact scale, accessibility, browser or runtime parity."},
    {"working_key": "CAP-GOV-RISK-TREATMENT-DOWNLOAD", "status": "direct", "candidate": "CISO Assistant treatment-control evidence download",
     "requirement": "Download authorised evidence linked through a risk treatment/control with visibility guard and stable version response.",
     "behavior": "RiskScenario links AppliedControl, AppliedControl links Evidence, the action-plan serializer exposes evidence, and an authorised endpoint downloads its latest EvidenceRevision.",
     "loci": "intuitem/ciso-assistant-community@82e8a7ed66fbfbd2d8ddc93e9fcfac702a1f8a8c :: backend/core/models.py L5138-L5168,L5589-L5715,L6928-L6933; serializers.py L1598-L1624,L2568-L2682; views.py L9631-L9712,L10043-L10075",
     "reason": "Analogous control-evidence chain, not a native TreatmentAttachment entity or Oblivion Site/runtime contract."},
    {"working_key": "CAP-GOV-RISK-TREATMENTS-EVIDENCE", "status": "direct", "candidate": "CISO Assistant risk action-plan evidence",
     "requirement": "Manage authorised risk-treatment/control state, owner and dates with linked versioned evidence.",
     "behavior": "Scenario treatment state, linked AppliedControls, versioned Evidences and action-plan/RBAC list paths establish bounded treatment-control evidence handling.",
     "loci": "intuitem/ciso-assistant-community@82e8a7ed66fbfbd2d8ddc93e9fcfac702a1f8a8c :: backend/core/models.py L5067-L5205,L5589-L5715,L6852-L7032; serializers.py L1459-L1624,L2568-L2728; views.py L9631-L9712,L10043-L10115",
     "reason": "No Oblivion treatment schema, exact routes, Site/direct-object, replay, UI or runtime parity."},
    {"working_key": "CAP-GOV-RISK-TRENDS", "status": "direct", "candidate": "CISO Assistant risk analytics timeline",
     "requirement": "Review authorised dated risk snapshots and treatment distributions as an over-time risk trend.",
     "behavior": "RiskAssessment snapshots per-treatment counts and historical metrics; scoped views and analytics pages expose distributions and timelines.",
     "loci": "intuitem/ciso-assistant-community@82e8a7ed66fbfbd2d8ddc93e9fcfac702a1f8a8c :: backend/core/models.py L6292-L6316,L6385-L6446; backend/metrology/models.py L493-L546; views.py L4818-L4868; analytics/+page.server.ts L4-L34; analytics/+page.svelte L27-L101,L122-L156",
     "reason": "No Oblivion period, Site, committee, exact chart, browser or runtime parity."},
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


def record(path: Path) -> dict[str, Any]:
    return {"path": path.relative_to(AUDIT).as_posix(), "sha256": sha_file(path), "bytes": path.stat().st_size}


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def mapping_tuple(row: dict[str, Any]) -> str:
    return "|".join([str(row["working_key"]), str(row["status"]),
                     ";".join(sorted(set(str(value) for value in row.get("source_units", [])))),
                     ";".join(sorted(set(str(value) for value in row.get("evidence_loci", []))))])


if any(path.exists() for path in (ARTIFACT, SUMMARY)):
    require(all(path.exists() for path in (ARTIFACT, SUMMARY)), "Partial Wave38 output set")
    current, summary = load(BENCHMARK), load(SUMMARY)
    require(current["summary"]["eligible_total"] == 484 and current["summary"]["completion_unproved"]["total"] == 420, "Existing Wave38 count drift")
    for key, path in (("benchmark", BENCHMARK), ("inventory", INVENTORY), ("ledger", LEDGER), ("matrix", MATRIX)):
        require(summary["outputs"][key] == record(path), f"Existing Wave38 output drift: {key}")
    pointer = load(POINTER)
    require(pointer["artifacts"]["benchmark_wave38"] == record(ARTIFACT), "Wave38 pointer drift")
    require(pointer["artifacts"]["benchmark_wave38_generation_summary"] == record(SUMMARY), "Wave38 summary pointer drift")
    print(json.dumps({"status": "already_applied", "wave38": record(ARTIFACT),
                      "benchmark": record(BENCHMARK), "summary": record(SUMMARY)}, indent=2))
    raise SystemExit(0)

for path, expected in EXPECTED.items():
    require(sha_file(path) == expected, f"Input SHA drift: {path}")
require(sha_lines(SELECTED) == SELECTION_SHA, "Wave38 selection digest mismatch")
benchmark, inventory = load(BENCHMARK), load(INVENTORY)
require(benchmark["audited_commit"] == COMMIT and len(benchmark["targets"]) == 904, "Benchmark base drift")
by_key = {row["working_key"]: row for row in benchmark["targets"]}
require(all(not by_key[key]["completion_credit"] and by_key[key]["status"] == "unproved" for key in SELECTED), "Selected target state drift")

artifact = {
    "schema_version": "1.0.0", "artifact": "benchmark-target-specific-adjudication-904-wave38",
    "generated_at": GENERATED_AT, "audited_commit": COMMIT, "read_only": True,
    "scope": "Eight current Governance/Compliance completion-unproved targets independently reviewed; six bounded direct decisions and two retained-unproved decisions.",
    "methodology": {"family_credit_inherited": False,
        "runtime_boundary": "Comparator evidence changes benchmark P3 mapping completion_credit only; Oblivion product, runtime, browser, representative-task, release and overall-audit completion deltas remain zero.",
        "no_copy_rule": "Behavioural evidence only; no source, schema, UI or wording is copied.",
        "selection_preimage_rule": "Packet-order IDs joined by LF with no terminal LF, UTF-8 without BOM.",
        "selection_sha256": SELECTION_SHA,
        "independent_review_verdict": "GO_6_direct_2_retained_after_scope_and_collision_corrections"},
    "selected_target_ids": SELECTED,
    "input_pins": {"benchmark_final_904_before_wave": record(BENCHMARK), "canonical_pointer_before_wave": record(POINTER)},
    "repository_snapshots": {"CISO_ASSISTANT": {**CISO, "commit_date": "2026-08-21T09:26:19Z", "default_branch": "main",
        "repository_root_licence": "GNU AGPL v3", "edition_boundary": "Official self-hosted community code outside enterprise/** only; enterprise/PRO, hosted services and out-of-tree behavior excluded."}},
    "verified_files": [{"repo": CISO["repo"], **row} for row in FILES],
    "counts": {"evaluated": 8, "direct": 6, "retained_unproved": 2, "documented_ncm": 0},
    "evaluations": [{**row, "candidate_status": "verified_benchmark_direct_recommended" if row["status"] == "direct" else "retain_unproved",
                     "completion_credit_recommended": row["status"] == "direct"} for row in EVALUATIONS],
    "collision_disclosure": {"prior_materialized_packets": 33, "prior_evaluation_occurrences": 346,
        "prior_unique_evaluated_ids": 340, "prior_unique_id_set_sha256": "7800b8bed0ad3e126485bc223ba5fc67c50ac800b28bc9710331758ddf239eec",
        "selected_target_intersection": 0,
        "raw_path_collision": "backend/core/views.py previously appeared at CISO commit 1ba187... lines 500-625 for a privacy compliance-report export target; Wave38 uses commit 82e8... and disjoint target-specific loci beginning at line 953.",
        "exact_repo_commit_path_locus_collision": 0,
        "source_reuse": "Repository and one raw path are disclosed; no family, repository, path or adjacent-locus credit is inherited."},
    "count_delta": {"verified_benchmark_direct": 6, "eligible_total": 6, "completion_unproved": -6,
                    "documented_ncm": 0, "product_runtime_representative_task_overall_completion": 0},
    "post_wave_totals": {"verified_benchmark_direct": 373, "verified_benchmark_total": 395,
                         "documented_ncm": 89, "eligible_total": 484, "completion_unproved": 420},
}
write_json(ARTIFACT, artifact)

evaluation_by_key = {row["working_key"]: row for row in EVALUATIONS}
for key in DIRECT:
    row = by_key[key]
    prior = row["status"]
    evaluation = evaluation_by_key[key]
    row.update({"status": "verified_benchmark_direct", "inheritance_method": "fresh_target_specific_wave38_direct",
                "prior_outcome": prior, "source_units": [f"fresh-904-wave38:{key}"],
                "evidence_loci": [evaluation["loci"]], "completion_credit": True})

status = Counter(str(row["status"]) for row in benchmark["targets"])
unproved = {"ordinary": status["unproved"], "audit_assigned_stable_name": status["unproved_audit_assigned_id"],
            "prior_pending": status["unproved_pending"], "prior_reject": status["unproved_reject"],
            "source_stable_semantic_merge": status["unproved_source_stable"]}
unproved["total"] = sum(unproved.values())
require(unproved == {"ordinary": 381, "audit_assigned_stable_name": 11, "prior_pending": 24,
                     "prior_reject": 3, "source_stable_semantic_merge": 1, "total": 420}, "Wave38 partition mismatch")
benchmark.update({"generated_at": GENERATED_AT, "status": "target_specific_484_of_904_complete_not_overall_audit_completion",
    "summary": {"verified_benchmark": {"direct": 373, "strict_one_to_one_rename": 22, "total": 395},
                "documented_no_credible_match": {"direct": 82, "strict_one_to_one_rename": 7, "total": 89},
                "eligible_total": 484, "completion_unproved": unproved, "status_counts": dict(sorted(status.items()))},
    "completion_boundary": {"eligible_rows": 484, "completion_unproved_rows": 420,
        "statement": benchmark["completion_boundary"]["statement"],
        "formal_audit_gate": "blocked_420_of_904_targets_lack_completed_target_specific_benchmark_or_documented_no_match_outcome"}})
benchmark["checksum_algorithm"]["full_mapping_sha256"] = sha_lines([mapping_tuple(item) for item in benchmark["targets"]], sort=True)
benchmark["checksum_algorithm"]["eligible_subset_sha256"] = sha_lines([mapping_tuple(item) for item in benchmark["targets"] if item.get("completion_credit")], sort=True)
benchmark["inputs"]["target_specific_wave38"] = {**record(ARTIFACT), "accepted_direct_count": 6,
    "retained_unproved_count": 2, "selected_keys_sha256": SELECTION_SHA}
write_json(BENCHMARK, benchmark)

for key in DIRECT:
    feature = next(item for item in inventory["features"] if item["working_key"] == key)
    row = by_key[key]
    feature["benchmark_mapping"] = {field: copy.deepcopy(row[field]) for field in
        ("status", "completion_credit", "inheritance_method", "prior_outcome", "source_units", "evidence_loci")}
inventory["generated_at"] = GENERATED_AT
inventory["benchmark_mapping"].update({"working_manifest_eligible": 484, "working_manifest_verified_benchmark": 395,
    "working_manifest_verified_direct": 373, "working_manifest_verified_rename": 22,
    "working_manifest_documented_no_credible_match": 89, "working_manifest_documented_ncm_direct": 82,
    "working_manifest_documented_ncm_rename": 7, "working_manifest_completion_unproved": 420,
    "completion_gate_status": "484/904 final targets have evidence-preserving benchmark/NCM mapping; 420 remain completion-unproved"})
inventory["pass_status"]["P3"] = "Blocked—484/904 targets mapped with evidence-preserving completion credit (395 verified benchmark, 89 documented No Credible Match); 420 unproved"
inventory["capability_denominator_status"]["benchmark_mapping"] = {"eligible": 484, "verified_benchmark": 395, "documented_no_credible_match": 89, "completion_unproved": 420}
inventory["canonical_feature_register_metadata"]["benchmark_mapping"] = {"verified_benchmark": 395, "documented_no_credible_match": 89, "completion_credit": 484, "completion_unproved": 420}
inventory["canonical_feature_register_metadata"]["source_artifacts"]["benchmark_mapping_sha256"] = sha_file(BENCHMARK)
write_json(INVENTORY, inventory)


def read_csv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        return list(reader.fieldnames or []), [dict(item) for item in reader]


def write_csv(path: Path, headers: list[str], rows: list[dict[str, str]]) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=headers, extrasaction="raise", lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)


ledger_headers, ledger_rows = read_csv(LEDGER)
matrix_headers, matrix_rows = read_csv(MATRIX)
for key in DIRECT:
    evaluation = evaluation_by_key[key]
    ledger_row = next(item for item in ledger_rows if item["feature_id"] == key)
    matrix_row = next(item for item in matrix_rows if item["feature_id"] == key)
    mapped_p3 = "Mapped—verified benchmark with final-target completion credit; inheritance=fresh_target_specific_wave38_direct; full feature parity is not claimed."
    ledger_row["P3_status"] = mapped_p3
    ledger_row["gaps"] = ledger_row["gaps"].replace("P3 benchmark/no-match completion unproved; ", "")
    ledger_row["evidence_count"] = str(int(ledger_row["evidence_count"] or "0") + 1)
    matrix_row.update({"benchmark_candidates": evaluation["candidate"], "selected_open_source_benchmark": evaluation["candidate"],
        "benchmark_url_and_sha": f"{CISO['url']}/commit/{CISO['commit']}", "verified_behaviour": evaluation["behavior"],
        "neutral_requirements_extracted": evaluation["requirement"], "no_match_evidence": "", "P3": mapped_p3,
        "confidence": "High for the bounded comparator slice; Oblivion Site/role/direct-object/frontend/runtime parity remains unverified"})
write_csv(LEDGER, ledger_headers, ledger_rows)
write_csv(MATRIX, matrix_headers, matrix_rows)

summary = {"schema_version": "1.0.0", "artifact": "final-904-benchmark-wave38-generation-summary",
    "generated_at": GENERATED_AT, "audited_commit": COMMIT, "read_only": True,
    "inputs": {"wave38": record(ARTIFACT)},
    "outputs": {"benchmark": record(BENCHMARK), "inventory": record(INVENTORY), "ledger": record(LEDGER), "matrix": record(MATRIX)},
    "mapping_tuple_hashes": {"full": benchmark["checksum_algorithm"]["full_mapping_sha256"],
                             "eligible": benchmark["checksum_algorithm"]["eligible_subset_sha256"]},
    "counts": {"denominator": 904, "direct": 373, "rename": 22, "verified": 395, "ncm": 89, "eligible": 484, "completion_unproved": 420},
    "validation": {"selected": 8, "direct": 6, "retained": 2, "benchmark_p3_completion_credit_delta": 6,
                   "product_runtime_representative_task_overall_completion_delta": 0,
                   "completion_status": "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"}}
write_json(SUMMARY, summary)
pointer = load(POINTER)
pointer["generated_at"] = max(pointer.get("generated_at", ""), GENERATED_AT)
pointer["artifacts"].update({"benchmark": record(BENCHMARK), "inventory": record(INVENTORY),
    "eight_pass_ledger": record(LEDGER), "benchmark_matrix": record(MATRIX),
    "benchmark_wave38": record(ARTIFACT), "benchmark_wave38_generation_summary": record(SUMMARY)})
pointer["completion_status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"
pointer["runtime_credit_delta"] = 0
write_json(POINTER, pointer)
print(json.dumps({"status": "applied", "wave38": record(ARTIFACT), "benchmark": record(BENCHMARK),
                  "inventory": record(INVENTORY), "ledger": record(LEDGER), "matrix": record(MATRIX),
                  "summary": record(SUMMARY), "active_inputs": record(POINTER)}, indent=2))
