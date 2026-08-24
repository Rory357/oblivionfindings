#!/usr/bin/env python3
"""Apply independently reviewed Wave 35 HR comparator evidence to canonical 904 artifacts."""

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
GENERATED_AT = "2026-08-21T21:20:00+12:00"

BENCHMARK = SOURCE / "benchmark-final-904-mapping.json"
INVENTORY = AUDIT / "inventory-904.json"
LEDGER = AUDIT / "02-eight-pass-coverage-ledger-904.csv"
MATRIX = AUDIT / "03-feature-to-benchmark-matrix-904.csv"
POINTER = SOURCE / "canonical-audit-inputs.json"
ARTIFACT = SOURCE / "benchmark-target-specific-adjudication-904-wave35.json"
SUMMARY = SOURCE / "final-904-benchmark-wave35-generation-summary.json"

EXPECTED = {
    BENCHMARK: "84f73bd34c2ff0e59551196a8a1886b4790de6eebc8f2be34b6e5978ea008491",
    INVENTORY: "579d2bde9e5f0d28ff1e912da354ec0244f6abe9eebaaf2eabf3c7ad3af2144e",
    LEDGER: "c2cec6779d1ac4e8521a995a66a5a826e19a914e598f0bc309eb8d358386c0ae",
    MATRIX: "e6ac00c8703ca7037f4f49f2a4973b5b036cf77fb7586ba7677dcb73ac5f6112",
}

SELECTION_SHA = "f8dbd85ba6a8395a30b8a18310fe0e5d3512242bdecdcfbdac25832344732571"
TARGET_BINDING_SHA = "59c0a329a745961bec7957c2d2c08e7345905c02aa4d542e5038d3c87d896e16"

HRMS = {"repo": "frappe/hrms", "url": "https://github.com/frappe/hrms", "commit": "51c2d3bde2d2797ad929eaeef27311c64d5a1b33"}
FRAPPE = {"repo": "frappe/frappe", "url": "https://github.com/frappe/frappe", "commit": "492f3b90d2e96a2ebbc0a4c32e73026bacba457b"}
ERPNEXT = {"repo": "frappe/erpnext", "url": "https://github.com/frappe/erpnext", "commit": "275844d49654688b2323bd6b5273f13546a154a6"}

FILES = [
    {"repo": HRMS["repo"], "path": "license.txt", "blob": "a238a97b060d8bf487880f88de6114e795f9fba4", "sha256": "f333043685c88280b1a0a41b4f8e2eacb02079f0bfca4d467e52c8834c658cea", "loci": "L1-L22"},
    {"repo": HRMS["repo"], "path": "pyproject.toml", "blob": "b5902b5a7da185cbadf303a29a1ddf9a3b080e2f", "sha256": "577dabe023f75fd4f2f11ca5544bf4fa124fa64b61e0921b06ebb673ea1a0a61", "loci": "L65-L74"},
    {"repo": HRMS["repo"], "path": "hrms/api/__init__.py", "blob": "2b88ef96e2375451ce4cff6a6a8f5f32617e273e", "sha256": "61e4adba8852721c27fa0a5773684be3250b759e67dde9c47c7756374374d905", "loci": "L41-L78"},
    {"repo": HRMS["repo"], "path": "hrms/payroll/doctype/payroll_entry/payroll_entry.json", "blob": "fb9bc205c36137c1cb527f677a93f8b2dfb11e24", "sha256": "58637b7b07097eabe661e549c1eb48ec6b07dec89c3d6603401dfb56fb832719", "loci": "L343-L360"},
    {"repo": FRAPPE["repo"], "path": "LICENSE", "blob": "6919960f8ba9c6ea72a8a0b07d6e98b4b46c7531", "sha256": "bc6001a54ffcc4ab520424d7dbb85b293578efcdcb7d8f8055e00dddf942e5d7", "loci": "L1-L21"},
    {"repo": FRAPPE["repo"], "path": "frappe/__init__.py", "blob": "8f2d03231665bef3e28b7e853aaa0f2d2ca82883", "sha256": "19977f0aacc5d047cb1b773e1f9e7d10e0434ca92113bb8073bad68ef4623897", "loci": "L144-L145,L1272-L1304"},
    {"repo": FRAPPE["repo"], "path": "frappe/api/v1.py", "blob": "ef6f716168a89bc1eebe00c8b4a4aa2a9a6420bb", "sha256": "2c79acebe38876d6d961a49214bcb1ddc9c8e11d35b196cee913c1ac7ea92b2c", "loci": "L12-L35,L47-L91,L151-L158"},
    {"repo": FRAPPE["repo"], "path": "frappe/client.py", "blob": "fa029d9b146c8c86ded3dc088e3a04e5cca752e2", "sha256": "0537e9272b104815fa070f62c5afaa23f7a826edc9c5cfda73194f76b52243b7", "loci": "L25-L74,L93-L116"},
    {"repo": FRAPPE["repo"], "path": "frappe/core/doctype/data_import/data_import.py", "blob": "26bcce7a3dea3a38f652554b4fd286576d35fa5f", "sha256": "e2a1ca861cbb8db55f3f45d6c3ea9ad6031560cf1ed55254e1fffcb4491ac344", "loci": "L104-L120,L149-L168,L188-L201,L219-L291"},
    {"repo": FRAPPE["repo"], "path": "frappe/core/doctype/data_import/importer.py", "blob": "53e7b93452ae5e795ed4a4b4b3066a579e804018", "sha256": "74ff4958956b44b7408dda6fa5e8646537e00f0e612195c0c9984f414ad1f539", "loci": "L132-L180,L355-L398"},
    {"repo": FRAPPE["repo"], "path": "frappe/core/doctype/data_import/exporter.py", "blob": "8e9428e9662195ac2bc38f969e132c7e5b1d9e07", "sha256": "41b6aba74a68edd41f91f7e5a245b5f98d2a2f1f2f326c738b41712ee256e088", "loci": "L13-L52,L54-L140,L259-L282"},
    {"repo": FRAPPE["repo"], "path": "frappe/core/doctype/report/report.py", "blob": "7f02120e1244b785fb74224a857fb0e4abbcecf0", "sha256": "d91ae2950c4486d9e92f8ddd46c095198252dcbf55185d301ec9cc817708db7e", "loci": "L63-L117,L137-L160"},
    {"repo": FRAPPE["repo"], "path": "frappe/core/doctype/report/report.json", "blob": "8ee4289adebe37dc337382346cbbf99cbfb60377", "sha256": "719b5736fb53b8eec1e3575f8e938adefc987d44c497e7247d05f2e11b3bcdc0", "loci": "L1-L20,L263-L314"},
    {"repo": FRAPPE["repo"], "path": "frappe/desk/query_report.py", "blob": "cb06e665fa890790effebfac03670d04bf1bcce3", "sha256": "f4a36673e0c05bd82e7c0f09a12a2d8ff47979e6e5ce6c6c2ba7997559f435d0", "loci": "L25-L59,L223-L302,L375-L519"},
    {"repo": FRAPPE["repo"], "path": "frappe/public/js/frappe/views/reports/query_report.js", "blob": "5405755aff8a3b958175fe2516d9910820ee72a7", "sha256": "4e59b286e01422af65a916b2f27a42c09760bc19a5806a52f253ad211c1cb219", "loci": "L185-L230"},
    {"repo": FRAPPE["repo"], "path": "frappe/model/delete_doc.py", "blob": "91a6ef6c5e998948f171b3b18734dced6a2fe07b", "sha256": "18bbd774f43b654d9b70d3d50e0b6a23caf17a4e2415331a456d905c83f7806d", "loci": "L24-L76,L147-L184"},
    {"repo": ERPNEXT["repo"], "path": "license.txt", "blob": "f288702d2fa16d3cdf0035b15a9fcbc552cd88e7", "sha256": "3972dc9744f6499f0f9b2dbf76696f2ae7ad8af9b23dde66d6af86c9dfb36986", "loci": "L1-L25"},
    {"repo": ERPNEXT["repo"], "path": "pyproject.toml", "blob": "e89d4f8689b5fe8c9390da20168ae693e8fdce05", "sha256": "82a593e847a9726607aff13dbb80072bf8088b7edbf9e0f59e945c29ccebf592", "loci": "L1-L8,L44-L45,L90-L94"},
    {"repo": ERPNEXT["repo"], "path": "erpnext/setup/doctype/employee/employee.json", "blob": "03f68b91dc5ce7c3939f3bfeb9a4fa21e2b54ed2", "sha256": "238e600228dd3d0b842d2fd3915d64f0edea708fdc2cd9ee2edf8966fb2671c7", "loci": "L1-L4,L839-L885"},
    {"repo": ERPNEXT["repo"], "path": "erpnext/setup/doctype/designation/designation.json", "blob": "001fed22a9fb79dc25c747f589dbac40f43a0c94", "sha256": "772d13319c3367c177f51394bae0132a23ba2216ce3a74144eaa51d68c52fa25", "loci": "L1-L23,L34-L73"},
    {"repo": ERPNEXT["repo"], "path": "erpnext/projects/doctype/timesheet/timesheet.json", "blob": "ea50e074dbe64461e6026470fd432e607b156830", "sha256": "55367a893845663e72282908dc26b546c744b75386aee7d91174fbdc19429bc0", "loci": "L1-L32,L319-L405,L407-L411"},
]

EVALUATIONS = [
    {"working_key": "CAP-HR-HR-API-EMPLOYEES", "candidate": "Frappe HRMS Employee API",
     "requirement": "Expose a permission-filtered employee list and authorised Employee record detail.",
     "loci": f"{HRMS['repo']}@{HRMS['commit']} :: hrms/api/__init__.py :: L41-L78; {FRAPPE['repo']}@{FRAPPE['commit']} :: frappe/api/v1.py :: L12-L35,L47-L91,L151-L158; frappe/client.py :: L25-L74,L93-L116; {ERPNEXT['repo']}@{ERPNEXT['commit']} :: erpnext/setup/doctype/employee/employee.json :: L1-L4,L839-L885",
     "behavior": "A dedicated permission-filtered Employee list composes with field/document-authorised Employee resource detail.",
     "reason": "No Oblivion Site, primary-Site, response-schema, active filter or direct-ID concealment parity."},
    {"working_key": "CAP-HR-HR-API-PAYROLL", "candidate": "Frappe Payroll Entry resource API",
     "requirement": "Read a permissioned payroll-entry catalogue through an authenticated resource API.",
     "loci": f"{HRMS['repo']}@{HRMS['commit']} :: hrms/payroll/doctype/payroll_entry/payroll_entry.json :: L343-L360; {FRAPPE['repo']}@{FRAPPE['commit']} :: frappe/api/v1.py :: L12-L35,L47-L91; frappe/client.py :: L25-L74",
     "behavior": "The permission-enforcing resource-list API binds to Payroll Entry, whose HR Manager role has read/report authority.",
     "reason": "No Oblivion payroll-run state machine, Site, pagination or response-schema parity."},
    {"working_key": "CAP-HR-HR-API-POSITIONS", "candidate": "ERPNext Designation resource API",
     "requirement": "Read a permissioned HR position-title catalogue.",
     "loci": f"{ERPNEXT['repo']}@{ERPNEXT['commit']} :: erpnext/setup/doctype/designation/designation.json :: L1-L23,L34-L73; {FRAPPE['repo']}@{FRAPPE['commit']} :: frappe/api/v1.py :: L12-L35,L47-L91; frappe/client.py :: L25-L74",
     "behavior": "The resource API binds to the exact permissioned Designation HR title catalogue.",
     "reason": "Title catalogue only; no staffed-slot, vacancy, active-state, incumbency or Site parity."},
    {"working_key": "CAP-HR-HR-API-TIME", "candidate": "ERPNext Timesheet resource API",
     "requirement": "Read permissioned timesheets with employee, dates, time rows and totals.",
     "loci": f"{ERPNEXT['repo']}@{ERPNEXT['commit']} :: erpnext/projects/doctype/timesheet/timesheet.json :: L1-L32,L319-L405,L407-L411; {FRAPPE['repo']}@{FRAPPE['commit']} :: frappe/api/v1.py :: L12-L35,L47-L91; frappe/client.py :: L25-L74",
     "behavior": "The resource API binds to Timesheet with employee, date range, time_logs, total hours and HR read/report authority.",
     "reason": "No exact Oblivion HrTimeEntry, cross-worker filter, approval or Site semantics."},
    {"working_key": "CAP-HR-IMPORT-EXPORT-EMPLOYEE-EXPORT", "candidate": "ERPNext Employee with Frappe Exporter",
     "requirement": "Export authorised Employee fields and records as CSV or XLSX.",
     "loci": f"{ERPNEXT['repo']}@{ERPNEXT['commit']} :: erpnext/setup/doctype/employee/employee.json :: L839-L885; {FRAPPE['repo']}@{FRAPPE['commit']} :: frappe/core/doctype/data_import/exporter.py :: L13-L52,L54-L140,L259-L282",
     "behavior": "Employee grants HR export permission; Frappe checks can_export, filters readable fields and emits CSV/XLSX.",
     "reason": "No selected-ID/active-only, exact-column, Site or filename parity."},
    {"working_key": "CAP-HR-IMPORT-EXPORT-EMPLOYEE-IMPORT", "candidate": "ERPNext Employee with Frappe Data Import",
     "requirement": "Import Employee rows through permissioned insert/update/upsert document writes.",
     "loci": f"{ERPNEXT['repo']}@{ERPNEXT['commit']} :: erpnext/setup/doctype/employee/employee.json :: L1-L4,L839-L885; {FRAPPE['repo']}@{FRAPPE['commit']} :: frappe/core/doctype/data_import/data_import.py :: L104-L120,L149-L168,L188-L201,L219-L291; importer.py :: L132-L180,L355-L398",
     "behavior": "Employee allows import and grants HR import permission; Data Import validates both and Importer performs document writes.",
     "reason": "No Oblivion CSV schema, row-error UX, Site binding, actor provenance or replay parity."},
    {"working_key": "CAP-HR-IMPORT-EXPORT-TEMPLATE", "candidate": "Frappe blank Employee import template",
     "requirement": "Download a permission-compatible blank Employee import template.",
     "loci": f"{ERPNEXT['repo']}@{ERPNEXT['commit']} :: erpnext/setup/doctype/employee/employee.json :: L1-L4,L839-L885; {FRAPPE['repo']}@{FRAPPE['commit']} :: frappe/core/doctype/data_import/exporter.py :: L13-L52,L54-L140,L259-L282",
     "behavior": "The whitelisted template path supports blank CSV/Excel output with permissioned Employee metadata and import-compatible headers.",
     "reason": "No exact Oblivion template heading or validation-contract parity."},
    {"working_key": "CAP-HR-REPORT-BUILDER-SAVED-LIBRARY", "candidate": "Frappe Custom Report saved library",
     "requirement": "List, load, run, export and delete authorised saved custom reports for a reference report.",
     "loci": f"{FRAPPE['repo']}@{FRAPPE['commit']} :: frappe/public/js/frappe/views/reports/query_report.js :: L185-L230; frappe/core/doctype/report/report.py :: L63-L117,L137-L160; report.json :: L1-L20,L263-L314; frappe/desk/query_report.py :: L25-L59,L223-L302,L375-L519; frappe/model/delete_doc.py :: L24-L76,L147-L184",
     "behavior": "The Query Report UI lists permission-filtered sibling Custom Reports; backend loads stored definitions, checks authority, runs/exports them and permission-checks deletion.",
     "reason": "No inheritance from Wave34 definition credit; no HR whitelist, Oblivion ownership/Site scope or hr.reports.view/export parity."},
]

SELECTED = [row["working_key"] for row in EVALUATIONS]


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
    return "|".join([str(row["working_key"]), str(row["status"]),
                     ";".join(sorted(set(str(value) for value in row.get("source_units", [])))),
                     ";".join(sorted(set(str(value) for value in row.get("evidence_loci", []))))])


if ARTIFACT.exists():
    current = load(BENCHMARK)
    require(current["summary"]["eligible_total"] == 472, "Existing Wave35 eligible count drift")
    require(current["summary"]["completion_unproved"]["total"] == 432, "Existing Wave35 unproved count drift")
    summary = load(SUMMARY)
    for key, path in (("benchmark", BENCHMARK), ("inventory", INVENTORY), ("ledger", LEDGER), ("matrix", MATRIX)):
        require(summary["outputs"][key] == record(path), f"Existing Wave35 output drift: {key}")
    print(json.dumps({"status": "already_applied", "wave35": record(ARTIFACT), "benchmark": record(BENCHMARK),
                      "inventory": record(INVENTORY), "ledger": record(LEDGER), "matrix": record(MATRIX),
                      "summary": record(SUMMARY)}, indent=2))
    raise SystemExit(0)

for path, expected in EXPECTED.items():
    require(sha_file(path) == expected, f"Input SHA drift: {path}")
require(sha_lines(SELECTED) == SELECTION_SHA, "Wave35 selection digest mismatch")

benchmark = load(BENCHMARK)
inventory = load(INVENTORY)
require(benchmark["audited_commit"] == COMMIT and len(benchmark["targets"]) == 904, "Benchmark base drift")
by_key = {row["working_key"]: row for row in benchmark["targets"]}
for key in SELECTED:
    require(not by_key[key]["completion_credit"] and by_key[key]["status"] == "unproved", f"Target not ordinary unproved: {key}")

artifact = {
    "schema_version": "1.0.0", "artifact": "benchmark-target-specific-adjudication-904-wave35",
    "generated_at": GENERATED_AT, "audited_commit": COMMIT, "read_only": True,
    "scope": "Eight current HR completion-unproved targets independently reviewed; eight bounded direct comparator decisions and zero retained/NCM decisions.",
    "methodology": {"family_credit_inherited": False,
                    "runtime_boundary": "Comparator source evidence only; no Oblivion product, runtime, API execution, browser, representative-task, Site-policy or release credit.",
                    "no_copy_rule": "Behavioural evidence only; no source, schema, UI or wording is copied.",
                    "selection_preimage_rule": "Packet-order IDs joined by LF with no terminal LF, UTF-8 without BOM.",
                    "selection_sha256": SELECTION_SHA, "target_binding_sha256": TARGET_BINDING_SHA,
                    "independent_review_verdict": "GO_8_direct_0_retained_after_strict_scope_bounds"},
    "selected_target_ids": SELECTED,
    "input_pins": {"benchmark_final_904_before_wave": record(BENCHMARK)},
    "repository_snapshots": {
        "FRAPPE_HRMS": {**HRMS, "parent": "1dff2c5acba6a074842cc6bcc1bdea1eff585227", "tree": "031668094179f3a85686fff19e547f86f939aa4b", "repository_root_licence": "GPL-3.0-only", "dependency": "Frappe and ERPNext >=17.0.0-dev,<18.0.0"},
        "FRAPPE_FRAMEWORK": {**FRAPPE, "parents": ["c82403c598b75a8c6eee06a3d63d6c83b5060749", "5294e7ce1cc78be622be79bd819b3a5cfe8090e0"], "tree": "185cf09e310889e353dcf3a05f2f0ad4e5e9f090", "repository_root_licence": "MIT", "version": "17.0.0-dev"},
        "ERPNEXT": {**ERPNEXT, "parent": "624d402143e517e864b1f697ec3d7e72c58237e0", "tree": "ced06126041067ec405c9f88f975cfee50d06b9b", "repository_root_licence": "GPL-3.0-only", "dependency": "Frappe >=17.0.0-dev,<18.0.0"},
    },
    "verified_files": FILES,
    "counts": {"evaluated": 8, "direct": 8, "retained_unproved": 0, "documented_ncm": 0},
    "evaluations": [{**row, "candidate_status": "verified_benchmark_direct_recommended", "completion_credit_recommended": True} for row in EVALUATIONS],
    "collision_disclosure": {"prior_named_wave_packets": 29, "prior_evaluation_occurrences": 302, "prior_unique_evaluated_ids": 296,
                              "prior_packet_basename_sha256": "846372a77d5b7fda74c1668f1e3c73ce613ed7fb037036deca4d91a004943f2f",
                              "prior_unique_id_set_sha256": "7fd75b7a2fff4c65528436819be1c9749829a02b51f087fcda7c85828507bbab",
                              "selected_target_intersection": 0, "selected_routes_unique": "12/12", "selected_page_unique": "PAGE-0508",
                              "target_source_tuple_sha256": "df6fa165134272f0da50f8d55b54b2cd391de00c82245c89c5b34ef9c700db9a",
                              "source_reuse": "Shared controller, framework and repository paths are disclosed. Each generic mechanism is credited only when bound to its exact final-ID DocType, action or saved-report UI; no family inheritance."},
    "count_delta": {"verified_benchmark_direct": 8, "eligible_total": 8, "completion_unproved": -8, "documented_ncm": 0},
    "post_wave_totals": {"verified_benchmark_direct": 361, "verified_benchmark_total": 383, "documented_ncm": 89, "eligible_total": 472, "completion_unproved": 432},
}
write_json(ARTIFACT, artifact)

evaluation_by_key = {row["working_key"]: row for row in EVALUATIONS}
for key in SELECTED:
    row = by_key[key]
    evaluation = evaluation_by_key[key]
    row.update({"status": "verified_benchmark_direct", "inheritance_method": "fresh_target_specific_wave35_direct",
                "prior_outcome": row["status"], "source_units": [f"fresh-904-wave35:{key}"],
                "evidence_loci": [evaluation["loci"]], "completion_credit": True})

status = Counter(str(row["status"]) for row in benchmark["targets"])
unproved = {"ordinary": status["unproved"], "audit_assigned_stable_name": status["unproved_audit_assigned_id"],
            "prior_pending": status["unproved_pending"], "prior_reject": status["unproved_reject"],
            "source_stable_semantic_merge": status["unproved_source_stable"]}
unproved["total"] = sum(unproved.values())
require(unproved == {"ordinary": 393, "audit_assigned_stable_name": 11, "prior_pending": 24, "prior_reject": 3,
                     "source_stable_semantic_merge": 1, "total": 432}, "Wave35 partition mismatch")

benchmark.update({
    "generated_at": GENERATED_AT,
    "status": "target_specific_472_of_904_complete_not_overall_audit_completion",
    "summary": {"verified_benchmark": {"direct": 361, "strict_one_to_one_rename": 22, "total": 383},
                "documented_no_credible_match": {"direct": 82, "strict_one_to_one_rename": 7, "total": 89},
                "eligible_total": 472, "completion_unproved": unproved, "status_counts": dict(sorted(status.items()))},
    "completion_boundary": {"eligible_rows": 472, "completion_unproved_rows": 432,
                            "statement": benchmark["completion_boundary"]["statement"],
                            "formal_audit_gate": "blocked_432_of_904_targets_lack_completed_target_specific_benchmark_or_documented_no_match_outcome"},
})
benchmark["checksum_algorithm"]["full_mapping_sha256"] = sha_lines([mapping_tuple(row) for row in benchmark["targets"]], sort=True)
benchmark["checksum_algorithm"]["eligible_subset_sha256"] = sha_lines([mapping_tuple(row) for row in benchmark["targets"] if row.get("completion_credit")], sort=True)
benchmark["inputs"]["target_specific_wave35"] = {**record(ARTIFACT), "accepted_direct_count": 8,
                                                    "retained_unproved_count": 0, "selected_keys_sha256": SELECTION_SHA}
write_json(BENCHMARK, benchmark)

feature_by_key = {row["working_key"]: row for row in inventory["features"]}
for key in SELECTED:
    feature_by_key[key]["benchmark_mapping"] = {field: copy.deepcopy(by_key[key][field]) for field in
                                                ("status", "completion_credit", "inheritance_method", "prior_outcome", "source_units", "evidence_loci")}
inventory["generated_at"] = GENERATED_AT
inventory["benchmark_mapping"].update({"working_manifest_eligible": 472, "working_manifest_verified_benchmark": 383,
                                       "working_manifest_verified_direct": 361, "working_manifest_verified_rename": 22,
                                       "working_manifest_documented_no_credible_match": 89, "working_manifest_documented_ncm_direct": 82,
                                       "working_manifest_documented_ncm_rename": 7, "working_manifest_completion_unproved": 432,
                                       "completion_gate_status": "472/904 final targets have evidence-preserving benchmark/NCM mapping; 432 remain completion-unproved"})
inventory["pass_status"]["P3"] = "Blocked—472/904 targets mapped with evidence-preserving completion credit (383 verified benchmark, 89 documented No Credible Match); 432 unproved"
inventory["capability_denominator_status"]["benchmark_mapping"] = {"eligible": 472, "verified_benchmark": 383, "documented_no_credible_match": 89, "completion_unproved": 432}
inventory["canonical_feature_register_metadata"]["benchmark_mapping"] = {"verified_benchmark": 383, "documented_no_credible_match": 89, "completion_credit": 472, "completion_unproved": 432}
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
        writer.writeheader()
        writer.writerows(rows)


ledger_headers, ledger_rows = read_csv(LEDGER)
matrix_headers, matrix_rows = read_csv(MATRIX)
ledger_by_key = {row["feature_id"]: row for row in ledger_rows}
matrix_by_key = {row["feature_id"]: row for row in matrix_rows}
mapped_p3 = "Mapped—verified benchmark with final-target completion credit; inheritance=fresh_target_specific_wave35_direct; full feature parity is not claimed."
for evaluation in EVALUATIONS:
    key = evaluation["working_key"]
    ledger_row = ledger_by_key[key]
    matrix_row = matrix_by_key[key]
    ledger_row["P3_status"] = mapped_p3
    ledger_row["gaps"] = ledger_row["gaps"].replace("P3 benchmark/no-match completion unproved; ", "")
    ledger_row["evidence_count"] = str(int(ledger_row["evidence_count"] or "0") + 1)
    matrix_row.update({"benchmark_candidates": evaluation["candidate"],
                       "selected_open_source_benchmark": "Frappe HRMS + Frappe Framework + ERPNext",
                       "benchmark_url_and_sha": f"{HRMS['url']}/commit/{HRMS['commit']} | {FRAPPE['url']}/commit/{FRAPPE['commit']} | {ERPNEXT['url']}/commit/{ERPNEXT['commit']}",
                       "verified_behaviour": evaluation["behavior"], "neutral_requirements_extracted": evaluation["requirement"],
                       "no_match_evidence": "", "P3": mapped_p3,
                       "confidence": "High for the bounded comparator slice; Oblivion Site, authority, runtime and full parity remain unverified"})
write_csv(LEDGER, ledger_headers, ledger_rows)
write_csv(MATRIX, matrix_headers, matrix_rows)

summary = {"schema_version": "1.0.0", "artifact": "final-904-benchmark-wave35-generation-summary",
           "generated_at": GENERATED_AT, "audited_commit": COMMIT, "read_only": True,
           "inputs": {"wave35": record(ARTIFACT)},
           "outputs": {"benchmark": record(BENCHMARK), "inventory": record(INVENTORY), "ledger": record(LEDGER), "matrix": record(MATRIX)},
           "mapping_tuple_hashes": {"full": benchmark["checksum_algorithm"]["full_mapping_sha256"],
                                    "eligible": benchmark["checksum_algorithm"]["eligible_subset_sha256"]},
           "counts": {"denominator": 904, "direct": 361, "rename": 22, "verified": 383, "ncm": 89, "eligible": 472, "completion_unproved": 432},
           "validation": {"selected": 8, "direct": 8, "retained": 0, "runtime_credit_delta": 0,
                          "completion_status": "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"}}
write_json(SUMMARY, summary)

pointer = load(POINTER)
pointer["generated_at"] = max(pointer.get("generated_at", ""), GENERATED_AT)
pointer["artifacts"].update({"benchmark": record(BENCHMARK), "inventory": record(INVENTORY),
                             "eight_pass_ledger": record(LEDGER), "benchmark_matrix": record(MATRIX),
                             "benchmark_wave35": record(ARTIFACT), "benchmark_wave35_generation_summary": record(SUMMARY)})
pointer["completion_status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"
pointer["runtime_credit_delta"] = 0
write_json(POINTER, pointer)

print(json.dumps({"status": "applied", "wave35": record(ARTIFACT), "benchmark": record(BENCHMARK),
                  "inventory": record(INVENTORY), "ledger": record(LEDGER), "matrix": record(MATRIX),
                  "summary": record(SUMMARY), "active_inputs": record(POINTER)}, indent=2))
