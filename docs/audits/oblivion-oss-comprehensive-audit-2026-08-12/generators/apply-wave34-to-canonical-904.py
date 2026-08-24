#!/usr/bin/env python3
"""Apply independently reviewed Wave 34 HR comparator evidence to canonical 904 artifacts."""

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
GENERATED_AT = "2026-08-21T20:00:00+12:00"

BENCHMARK = SOURCE / "benchmark-final-904-mapping.json"
INVENTORY = AUDIT / "inventory-904.json"
LEDGER = AUDIT / "02-eight-pass-coverage-ledger-904.csv"
MATRIX = AUDIT / "03-feature-to-benchmark-matrix-904.csv"
POINTER = SOURCE / "canonical-audit-inputs.json"
ARTIFACT = SOURCE / "benchmark-target-specific-adjudication-904-wave34.json"
SUMMARY = SOURCE / "final-904-benchmark-wave34-generation-summary.json"

EXPECTED = {
    BENCHMARK: "0ed383ce0977bc8705523343443997d14cd13fabf14b01ba9f83173116876ce2",
    INVENTORY: "37cba2c22121ef641e425ba891e60757cc1a0b112ec9ec710ed71e317d673f6e",
    LEDGER: "315b50fd58e17bedc7330ffd071b4abe4473d529d7147f78bb7c372095acb6a6",
    MATRIX: "f7b9b429707fbc58e8a500c401d814db379bea7547d35d913aa119878882c5c8",
}

SELECTION_SHA = "69d9d6fcf42d870b548fd9ce4df44edc228754c7c728ca44fa64d72ffa382d30"
EXPECTED_FULL_TUPLE_SHA = "3a549adf821c3cbd2d6e5f8379b4060571b20d2e2d23ec639e7d33890773fe7b"
EXPECTED_ELIGIBLE_TUPLE_SHA = "054611272522ea27e23bceba34278d7875e511a47874168e8a429fccb3baf600"

HRMS_REPO = "frappe/hrms"
HRMS_URL = "https://github.com/frappe/hrms"
HRMS_COMMIT = "51c2d3bde2d2797ad929eaeef27311c64d5a1b33"
FRAPPE_REPO = "frappe/frappe"
FRAPPE_URL = "https://github.com/frappe/frappe"
FRAPPE_COMMIT = "492f3b90d2e96a2ebbc0a4c32e73026bacba457b"

FILES = [
    {"repo": HRMS_REPO, "path": "license.txt", "sha256": "f333043685c88280b1a0a41b4f8e2eacb02079f0bfca4d467e52c8834c658cea", "loci": "L1-L22"},
    {"repo": HRMS_REPO, "path": "pyproject.toml", "sha256": "577dabe023f75fd4f2f11ca5544bf4fa124fa64b61e0921b06ebb673ea1a0a61", "loci": "L73"},
    {"repo": HRMS_REPO, "path": "hrms/hr/doctype/leave_application/leave_application.py", "sha256": "dcb6b498870ceea4f8bc71fb373336a3280130c51ceac5206ee39718bc857c4b", "loci": "L1408-L1437,L1440-L1485,L1488-L1526"},
    {"repo": HRMS_REPO, "path": "hrms/api/__init__.py", "sha256": "61e4adba8852721c27fa0a5773684be3250b759e67dde9c47c7756374374d905", "loci": "L41-L59,L92-L96,L256-L283,L345-L416"},
    {"repo": HRMS_REPO, "path": "hrms/hr/report/employee_leave_balance/employee_leave_balance.json", "sha256": "a5f4aeaba061d54cc1883a2a866a0a8ffb2efe7e51f8751cd6f070788f7de463", "loci": "L14-L31"},
    {"repo": HRMS_REPO, "path": "hrms/hr/report/employee_leave_balance/employee_leave_balance.py", "sha256": "70087dfa28e56a113ddca1439253e6cb33d1dadf0426601d4c12ee0b0c186ace", "loci": "L21-L28,L87-L136,L142-L166"},
    {"repo": HRMS_REPO, "path": "hrms/hr/report/leave_ledger/leave_ledger.json", "sha256": "b18a4d0f92be92369b9816e58d597b2ed3f17e110c01fd294ce828ba377311bd", "loci": "L15-L35"},
    {"repo": HRMS_REPO, "path": "hrms/hr/report/leave_ledger/leave_ledger.py", "sha256": "cd579c80231bd6307895088eb26cf5d149c72c7a0da390ac4f476ae48b90d0e4", "loci": "L11-L15,L119-L164"},
    {"repo": HRMS_REPO, "path": "hrms/hr/workspace/recruitment/recruitment.json", "sha256": "3e8fb8b6ffd522b6114e1355ae3ecd3d214bbaf528271b0d1d82f2024e2872bd", "loci": "L51-L66,L344-L361"},
    {"repo": HRMS_REPO, "path": "hrms/hr/report/recruitment_analytics/recruitment_analytics.json", "sha256": "e3e6099434ee770a6bcb04ea650145343364788f538b1998f2d431bb11bd6e6f", "loci": "L12-L26"},
    {"repo": HRMS_REPO, "path": "hrms/hr/report/recruitment_analytics/recruitment_analytics.py", "sha256": "ebf97c14952e5535f99b9ec668bd82f6e267cc698e69adab16a0e823fcaf21b2", "loci": "L9-L18,L69-L82,L119-L162,L167-L204"},
    {"repo": HRMS_REPO, "path": "hrms/hr/workspace/leaves/leaves.json", "sha256": "0f040cad063eb704948d5fd9f4d2be185128b2044bce5a52dda5a8ff7f1adb12", "loci": "L103-L138,L299-L342"},
    {"repo": FRAPPE_REPO, "path": "LICENSE", "sha256": "bc6001a54ffcc4ab520424d7dbb85b293578efcdcb7d8f8055e00dddf942e5d7", "loci": "L1-L21"},
    {"repo": FRAPPE_REPO, "path": "frappe/__init__.py", "sha256": "19977f0aacc5d047cb1b773e1f9e7d10e0434ca92113bb8073bad68ef4623897", "loci": "L144"},
    {"repo": FRAPPE_REPO, "path": "frappe/desk/query_report.py", "sha256": "f4a36673e0c05bd82e7c0f09a12a2d8ff47979e6e5ce6c6c2ba7997559f435d0", "loci": "L25-L56,L225-L299,L376-L519,L860-L898"},
    {"repo": FRAPPE_REPO, "path": "frappe/permissions.py", "sha256": "93c90a4c7d4a9f21983a0fe3037ac93223f5e8398bda86d6e73f5ca9453b639f", "loci": "L660-L668"},
    {"repo": FRAPPE_REPO, "path": "frappe/core/doctype/report/report.json", "sha256": "719b5736fb53b8eec1e3575f8e938adefc987d44c497e7247d05f2e11b3bcdc0", "loci": "L44-L146,L264-L307"},
    {"repo": FRAPPE_REPO, "path": "frappe/core/doctype/report/report.py", "sha256": "d91ae2950c4486d9e92f8ddd46c095198252dcbf55185d301ec9cc817708db7e", "loci": "L63-L99,L138-L162,L257-L331"},
    {"repo": FRAPPE_REPO, "path": "frappe/email/doctype/auto_email_report/auto_email_report.json", "sha256": "5e291442125a0bea7d8e92cf2e30dee0997f8881b4bab07ad9d512b368fccbb1", "loci": "L41-L59,L66-L80,L97-L179,L226-L251"},
    {"repo": FRAPPE_REPO, "path": "frappe/email/doctype/auto_email_report/auto_email_report.py", "sha256": "0c475653cf148b71f0e1d27cfc0177ea6cbee061076ca5c1d673af4b0bd536c2", "loci": "L37-L160,L273-L367"},
]

EVALUATIONS = [
    {
        "working_key": "CAP-HR-CALENDAR-FEED",
        "research_candidate": "Frappe HRMS leave and holiday calendar feed",
        "neutral_requirement": "Return an authorised date-range HR leave, department, block-date and holiday feed.",
        "evidence_loci": f"{HRMS_REPO}@{HRMS_COMMIT} :: hrms/hr/doctype/leave_application/leave_application.py :: L1408-L1437,L1440-L1485,L1488-L1526",
        "verified_behavior": "The pinned HRMS leave calendar endpoint builds a date-range feed with leave, department, block-date and holiday events; a global setting may intentionally expose all department members.",
        "reason": "Bounded leave/holiday feed only; no Oblivion unified six-layer calendar, Site, team or department-policy parity.",
    },
    {
        "working_key": "CAP-HR-HR-API-LEAVE",
        "research_candidate": "Frappe HRMS leave API",
        "neutral_requirement": "Expose permission-filtered leave applications and the current session employee's leave balance.",
        "evidence_loci": f"{HRMS_REPO}@{HRMS_COMMIT} :: hrms/api/__init__.py :: L41-L59,L92-L96,L256-L283,L345-L416",
        "verified_behavior": "The pinned API uses permission-filtered frappe.get_list for applications and resolves leave balances for the current-session employee.",
        "reason": "No arbitrary userId balance, sensitive-description redaction, Site boundary, or Oblivion viewAny/approve/manage parity.",
    },
    {
        "working_key": "CAP-HR-HR-REPORT-EXPORT",
        "research_candidate": "HRMS report definitions with Frappe Query Report exporter",
        "neutral_requirement": "Export an authorised HRMS report immediately or in a bounded background email flow.",
        "evidence_loci": f"{FRAPPE_REPO}@{FRAPPE_COMMIT} :: frappe/desk/query_report.py :: L25-L56,L225-L299,L376-L519; frappe/permissions.py :: L660-L668; composed with exact HRMS report definitions",
        "verified_behavior": "The permission-checked Query Report stack exports exact HRMS report definitions as immediate CSV/XLSX or background emailed output.",
        "reason": "No persisted Oblivion export-history, show, direct-object, Site or dataset parity.",
    },
    {
        "working_key": "CAP-HR-LEAVE-REPORT-EXPORT",
        "research_candidate": "HRMS Employee Leave Balance and Leave Ledger reports with Frappe exporter",
        "neutral_requirement": "Export authorised leave-balance or leave-ledger report rows through the report framework.",
        "evidence_loci": f"{HRMS_REPO}@{HRMS_COMMIT} :: employee_leave_balance JSON/PY :: L14-L31,L21-L28,L87-L136,L142-L166; leave_ledger JSON/PY :: L15-L35,L11-L15,L119-L164; {FRAPPE_REPO}@{FRAPPE_COMMIT} :: frappe/desk/query_report.py :: L25-L56,L225-L299,L376-L519",
        "verified_behavior": "The exact leave balance and ledger definitions compose with the permission-checked report exporter.",
        "reason": "Leave balance/ledger export only; no Bradford factor, utilisation, PDF or Oblivion authority parity.",
    },
    {
        "working_key": "CAP-HR-RECRUITMENT-EXPORT",
        "research_candidate": "HRMS Recruitment Analytics with Frappe exporter",
        "neutral_requirement": "Export authorised recruitment analytics report rows.",
        "evidence_loci": f"{HRMS_REPO}@{HRMS_COMMIT} :: recruitment workspace :: L51-L66,L344-L361; recruitment_analytics JSON/PY :: L12-L26,L9-L18,L69-L82,L119-L162,L167-L204; {FRAPPE_REPO}@{FRAPPE_COMMIT} :: frappe/desk/query_report.py :: L25-L56,L225-L299,L376-L519",
        "verified_behavior": "The HRMS recruitment workspace binds Recruitment Analytics to the permission-checked report export stack.",
        "reason": "Recruitment Analytics only; no complete requisition, offer, pipeline and analytics four-dataset parity or record-scope claim.",
    },
    {
        "working_key": "CAP-HR-REPORT-BUILDER-DEFINITION",
        "research_candidate": "Frappe custom Report definition and Query Report save/run",
        "neutral_requirement": "Define, filter, column-select, save and run a bounded custom report after reference-report access.",
        "evidence_loci": f"{FRAPPE_REPO}@{FRAPPE_COMMIT} :: frappe/core/doctype/report/report.json :: L44-L146,L264-L307; report.py :: L63-L99,L138-L162,L257-L331; frappe/desk/query_report.py :: L860-L898",
        "verified_behavior": "The framework stores report definitions, filters and columns and exposes save-and-run mechanics after reference-report access.",
        "reason": "Generic framework capability applied to HR; no HR whitelist, Site privacy or stronger write-authority parity.",
    },
    {
        "working_key": "CAP-HR-REPORT-CATALOG-RUN",
        "research_candidate": "HRMS Leaves and Recruitment report catalog with Frappe runner",
        "neutral_requirement": "List exact HRMS reports and run them through a permission-checked report engine.",
        "evidence_loci": f"{HRMS_REPO}@{HRMS_COMMIT} :: leaves workspace :: L103-L138,L299-L342; recruitment workspace :: L51-L66,L344-L361; exact report JSONs; {FRAPPE_REPO}@{FRAPPE_COMMIT} :: frappe/desk/query_report.py :: L25-L56,L225-L299",
        "verified_behavior": "The HRMS workspaces catalog exact HR reports that run through Frappe's permission-checked report engine.",
        "reason": "Generic runner alone is insufficient; no catalog completeness, Site policy or Oblivion report-schema parity.",
    },
    {
        "working_key": "CAP-HR-REPORT-SUBSCRIPTIONS",
        "research_candidate": "Frappe Auto Email Report",
        "neutral_requirement": "Let report managers configure scheduled report delivery that executes as a configured user.",
        "evidence_loci": f"{FRAPPE_REPO}@{FRAPPE_COMMIT} :: frappe/email/doctype/auto_email_report/auto_email_report.json :: L41-L59,L66-L80,L97-L179,L226-L251; auto_email_report.py :: L37-L160,L273-L367",
        "verified_behavior": "System Manager and Report Manager configure scheduled report email output executed for a configured user; recipient addresses are validated syntactically.",
        "reason": "Manager-curated only; no employee self-service, own-subscription, same-Site recipient or Oblivion hr.reports.view/export parity.",
    },
]

SELECTED = [row["working_key"] for row in EVALUATIONS]


def sha_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def sha_lines(lines: list[str], *, sort: bool = False) -> str:
    data = sorted(lines) if sort else lines
    return hashlib.sha256("\n".join(data).encode("utf-8")).hexdigest()


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
    return "|".join([str(row["working_key"]), str(row["status"]),
                     ";".join(sorted(set(str(v) for v in row.get("source_units", [])))),
                     ";".join(sorted(set(str(v) for v in row.get("evidence_loci", []))))])


if ARTIFACT.exists():
    current = load(BENCHMARK)
    require(current["summary"]["eligible_total"] == 464, "Existing Wave34 eligible count drift")
    require(current["summary"]["completion_unproved"]["total"] == 440, "Existing Wave34 unproved count drift")
    print(json.dumps({"status": "already_applied", "wave34": record(ARTIFACT), "benchmark": record(BENCHMARK),
                      "inventory": record(INVENTORY), "ledger": record(LEDGER), "matrix": record(MATRIX),
                      "summary": record(SUMMARY)}, indent=2))
    raise SystemExit(0)

for path, expected in EXPECTED.items():
    require(sha_file(path) == expected, f"Input SHA drift: {path}")
require(sha_lines(SELECTED) == SELECTION_SHA, "Wave34 selection digest mismatch")

benchmark = load(BENCHMARK)
inventory = load(INVENTORY)
require(benchmark["audited_commit"] == COMMIT and len(benchmark["targets"]) == 904, "Benchmark base drift")
by_key = {row["working_key"]: row for row in benchmark["targets"]}
for key in SELECTED:
    require(not by_key[key]["completion_credit"] and str(by_key[key]["status"]).startswith("unproved"), f"Target not unproved: {key}")

artifact = {
    "schema_version": "1.0.0", "artifact": "benchmark-target-specific-adjudication-904-wave34",
    "generated_at": GENERATED_AT, "audited_commit": COMMIT, "read_only": True,
    "scope": "Eight current HR completion-unproved targets independently reviewed; eight bounded direct comparator decisions and zero retained/NCM decisions.",
    "methodology": {"family_credit_inherited": False, "runtime_boundary": "Comparator source evidence only; no Oblivion product, runtime, browser, representative-task, Site-policy or release credit.",
                    "no_copy_rule": "Behavioural evidence only; no source, schema, UI or wording is copied.",
                    "selection_preimage_rule": "Packet-order IDs joined by LF with no terminal LF, UTF-8 without BOM.",
                    "selection_sha256": SELECTION_SHA,
                    "independent_review_verdict": "GO_after_mandatory_scope_corrections_8_direct_0_retained"},
    "selected_target_ids": SELECTED,
    "input_pins": {"benchmark_final_904_before_wave": record(BENCHMARK)},
    "repository_snapshots": {
        "FRAPPE_HRMS": {"repo": HRMS_REPO, "official_repository_url": HRMS_URL, "commit_sha": HRMS_COMMIT,
                          "parent_sha": "1dff2c5acba6a074842cc6bcc1bdea1eff585227", "root_tree_sha": "031668094179f3a85686fff19e547f86f939aa4b",
                          "repository_root_licence": "GPL-3.0-only", "dependency_boundary": "Frappe >=17.0.0-dev,<18.0.0"},
        "FRAPPE_FRAMEWORK": {"repo": FRAPPE_REPO, "official_repository_url": FRAPPE_URL, "commit_sha": FRAPPE_COMMIT,
                               "root_tree_sha": "185cf09e310889e353dcf3a05f2f0ad4e5e9f090", "repository_root_licence": "MIT", "version": "17.0.0-dev"},
    },
    "verified_files": FILES,
    "counts": {"evaluated": 8, "direct": 8, "retained_unproved": 0, "documented_ncm": 0},
    "evaluations": [{**row, "candidate_status": "verified_benchmark_direct_recommended", "completion_credit_recommended": True} for row in EVALUATIONS],
    "collision_disclosure": {"prior_named_wave_packets": 28, "prior_evaluation_occurrences": 294,
                              "prior_unique_evaluated_ids": 288, "prior_unique_id_set_sha256": "9056432de06f2ce71c28ae5e5d49eb0bfba063f2f10b521a1fb5b5fca3a3fc74",
                              "selected_target_intersection": 0, "comparator_path_set_sha256": "48087454c4520200d2a1f89f305747fcc68c694f8a56f043e806255df961d94c",
                              "source_reuse": "Four comparator paths were previously inspected, but exact target-specific lines and the HRMS-to-Frappe composition are newly adjudicated. Repository or framework reuse never transfers family credit."},
    "count_delta": {"verified_benchmark_direct": 8, "eligible_total": 8, "completion_unproved": -8, "documented_ncm": 0},
    "post_wave_totals": {"verified_benchmark_direct": 353, "verified_benchmark_total": 375, "documented_ncm": 89, "eligible_total": 464, "completion_unproved": 440},
}
write_json(ARTIFACT, artifact)

evaluation_by_key = {row["working_key"]: row for row in EVALUATIONS}
for key in SELECTED:
    row = by_key[key]
    evaluation = evaluation_by_key[key]
    row.update({"status": "verified_benchmark_direct", "inheritance_method": "fresh_target_specific_wave34_direct",
                "prior_outcome": row["status"], "source_units": [f"fresh-904-wave34:{key}"],
                "evidence_loci": [evaluation["evidence_loci"]], "completion_credit": True})

status = Counter(str(row["status"]) for row in benchmark["targets"])
unproved = {"ordinary": status["unproved"], "audit_assigned_stable_name": status["unproved_audit_assigned_id"],
            "prior_pending": status["unproved_pending"], "prior_reject": status["unproved_reject"],
            "source_stable_semantic_merge": status["unproved_source_stable"]}
unproved["total"] = sum(unproved.values())
require(unproved == {"ordinary": 401, "audit_assigned_stable_name": 11, "prior_pending": 24, "prior_reject": 3,
                     "source_stable_semantic_merge": 1, "total": 440}, "Wave34 partition mismatch")

benchmark.update({
    "generated_at": GENERATED_AT,
    "status": "target_specific_464_of_904_complete_not_overall_audit_completion",
    "summary": {"verified_benchmark": {"direct": 353, "strict_one_to_one_rename": 22, "total": 375},
                "documented_no_credible_match": {"direct": 82, "strict_one_to_one_rename": 7, "total": 89},
                "eligible_total": 464, "completion_unproved": unproved, "status_counts": dict(sorted(status.items()))},
    "completion_boundary": {"eligible_rows": 464, "completion_unproved_rows": 440,
                            "statement": benchmark["completion_boundary"]["statement"],
                            "formal_audit_gate": "blocked_440_of_904_targets_lack_completed_target_specific_benchmark_or_documented_no_match_outcome"},
})
benchmark["checksum_algorithm"]["full_mapping_sha256"] = sha_lines([mapping_tuple(row) for row in benchmark["targets"]], sort=True)
benchmark["checksum_algorithm"]["eligible_subset_sha256"] = sha_lines([mapping_tuple(row) for row in benchmark["targets"] if row.get("completion_credit")], sort=True)
require(benchmark["checksum_algorithm"]["full_mapping_sha256"] == EXPECTED_FULL_TUPLE_SHA, "Post full tuple drift")
require(benchmark["checksum_algorithm"]["eligible_subset_sha256"] == EXPECTED_ELIGIBLE_TUPLE_SHA, "Post eligible tuple drift")
benchmark["inputs"]["target_specific_wave34"] = {**record(ARTIFACT), "accepted_direct_count": 8,
                                                    "retained_unproved_count": 0, "selected_keys_sha256": SELECTION_SHA}
write_json(BENCHMARK, benchmark)

feature_by_key = {row["working_key"]: row for row in inventory["features"]}
for key in SELECTED:
    feature_by_key[key]["benchmark_mapping"] = {field: copy.deepcopy(by_key[key][field]) for field in
                                                ("status", "completion_credit", "inheritance_method", "prior_outcome", "source_units", "evidence_loci")}
inventory["generated_at"] = GENERATED_AT
inventory["benchmark_mapping"].update({"working_manifest_eligible": 464, "working_manifest_verified_benchmark": 375,
                                       "working_manifest_verified_direct": 353, "working_manifest_verified_rename": 22,
                                       "working_manifest_documented_no_credible_match": 89, "working_manifest_documented_ncm_direct": 82,
                                       "working_manifest_documented_ncm_rename": 7, "working_manifest_completion_unproved": 440,
                                       "completion_gate_status": "464/904 final targets have evidence-preserving benchmark/NCM mapping; 440 remain completion-unproved"})
inventory["pass_status"]["P3"] = "Blocked—464/904 targets mapped with evidence-preserving completion credit (375 verified benchmark, 89 documented No Credible Match); 440 unproved"
inventory["capability_denominator_status"]["benchmark_mapping"] = {"eligible": 464, "verified_benchmark": 375, "documented_no_credible_match": 89, "completion_unproved": 440}
inventory["canonical_feature_register_metadata"]["benchmark_mapping"] = {"verified_benchmark": 375, "documented_no_credible_match": 89, "completion_credit": 464, "completion_unproved": 440}
inventory["canonical_feature_register_metadata"]["source_artifacts"]["benchmark_mapping"] = rel(BENCHMARK)
inventory["canonical_feature_register_metadata"]["source_artifacts"]["benchmark_mapping_sha256"] = sha_file(BENCHMARK)
write_json(INVENTORY, inventory)


def read_csv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        return list(reader.fieldnames or []), [dict(row) for row in reader]


def write_csv(path: Path, headers: list[str], rows: list[dict[str, str]]) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=headers, extrasaction="raise", lineterminator="\n")
        writer.writeheader(); writer.writerows(rows)


ledger_headers, ledger_rows = read_csv(LEDGER)
matrix_headers, matrix_rows = read_csv(MATRIX)
ledger_by_key = {row["feature_id"]: row for row in ledger_rows}
matrix_by_key = {row["feature_id"]: row for row in matrix_rows}
mapped_p3 = "Mapped—verified benchmark with final-target completion credit; inheritance=fresh_target_specific_wave34_direct; full feature parity is not claimed."
for evaluation in EVALUATIONS:
    key = evaluation["working_key"]
    ledger_row = ledger_by_key[key]
    matrix_row = matrix_by_key[key]
    ledger_row["P3_status"] = mapped_p3
    ledger_row["gaps"] = ledger_row["gaps"].replace("P3 benchmark/no-match completion unproved; ", "")
    ledger_row["evidence_count"] = str(int(ledger_row["evidence_count"] or "0") + 1)
    matrix_row.update({"benchmark_candidates": evaluation["research_candidate"],
                       "selected_open_source_benchmark": "Frappe HRMS + Frappe Framework",
                       "benchmark_url_and_sha": f"{HRMS_URL}/commit/{HRMS_COMMIT} | {FRAPPE_URL}/commit/{FRAPPE_COMMIT}",
                       "verified_behaviour": evaluation["verified_behavior"],
                       "neutral_requirements_extracted": evaluation["neutral_requirement"],
                       "no_match_evidence": "", "P3": mapped_p3,
                       "confidence": "High for the bounded comparator slice; Oblivion Site, authority, runtime and full parity remain unverified"})
write_csv(LEDGER, ledger_headers, ledger_rows)
write_csv(MATRIX, matrix_headers, matrix_rows)

summary = {"schema_version": "1.0.0", "artifact": "final-904-benchmark-wave34-generation-summary",
           "generated_at": GENERATED_AT, "audited_commit": COMMIT, "read_only": True,
           "inputs": {"wave34": record(ARTIFACT)},
           "outputs": {"benchmark": record(BENCHMARK), "inventory": record(INVENTORY), "ledger": record(LEDGER), "matrix": record(MATRIX)},
           "counts": {"denominator": 904, "direct": 353, "rename": 22, "verified": 375, "ncm": 89, "eligible": 464, "completion_unproved": 440},
           "validation": {"selected": 8, "direct": 8, "retained": 0, "runtime_credit_delta": 0,
                          "completion_status": "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"}}
write_json(SUMMARY, summary)

pointer = load(POINTER)
pointer["generated_at"] = max(pointer.get("generated_at", ""), GENERATED_AT)
pointer["artifacts"].update({"benchmark": record(BENCHMARK), "inventory": record(INVENTORY),
                             "eight_pass_ledger": record(LEDGER), "benchmark_matrix": record(MATRIX),
                             "benchmark_wave34": record(ARTIFACT), "benchmark_wave34_generation_summary": record(SUMMARY)})
pointer["completion_status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"
pointer["runtime_credit_delta"] = 0
write_json(POINTER, pointer)

print(json.dumps({"status": "applied", "wave34": record(ARTIFACT), "benchmark": record(BENCHMARK),
                  "inventory": record(INVENTORY), "ledger": record(LEDGER), "matrix": record(MATRIX),
                  "summary": record(SUMMARY), "active_inputs": record(POINTER)}, indent=2))
