#!/usr/bin/env python3
"""Apply independently reviewed Wave 37 Reporting/Sites comparator evidence to canonical 904 artifacts."""

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
GENERATED_AT = "2026-08-21T23:20:00+12:00"

BENCHMARK = SOURCE / "benchmark-final-904-mapping.json"
INVENTORY = AUDIT / "inventory-904.json"
LEDGER = AUDIT / "02-eight-pass-coverage-ledger-904.csv"
MATRIX = AUDIT / "03-feature-to-benchmark-matrix-904.csv"
POINTER = SOURCE / "canonical-audit-inputs.json"
ARTIFACT = SOURCE / "benchmark-target-specific-adjudication-904-wave37.json"
SUMMARY = SOURCE / "final-904-benchmark-wave37-generation-summary.json"

EXPECTED = {
    BENCHMARK: "3bda9a751759f2f24e0ff56f8e5fbddbedc548528ca4bcc5511b53d187eba710",
    INVENTORY: "bca2b2549bef0e737df5bde5e2db6a158dd53f978aa6b84800e3a7f561ed8ec2",
    LEDGER: "500ddb4754628d961b41b3bf2d24708cc8dfc4d7e8a3e1dc705278a7f02d173a",
    MATRIX: "bdc4a018e0c242d485dc3e6ae7f188a7c1f752bf723647d977310495b2136790",
    POINTER: "fab33357bf1315f874c22b3f2dc908f5d26036b9d50b44b791bbd273cc7275fc",
}

SNIPE = {"repo": "grokability/snipe-it", "url": "https://github.com/grokability/snipe-it",
         "commit": "93b0696d69582761176e4de06e99d0d6034be6af", "tree": "ac2c9972dbfe0f85716202320a33ffa5ee5e2d61"}

SELECTED = [
    "CAP-REP-MODULE-REPORT-ASSETS", "CAP-REP-MODULE-REPORT-AUDIT-LOGS",
    "CAP-SITE-SITE-REPORTING-ASSET-CONDITION", "CAP-SITE-SITE-REPORTING-CATALOGUE",
    "CAP-SITE-SITE-REPORTING-VENDOR-CONTACTS-EXPORT", "CAP-SITE-SITE-VENDOR-GLOBAL-AUDIT",
    "CAP-SITE-SITE-VENDOR-GLOBAL-DIRECTORY", "CAP-SITE-SITE-VENDOR-LIFECYCLE",
]
SELECTION_SHA = "ad99faa8dd0bd22220a5b2dc7ed9044ce1b94919ad10854f0addc6b638b69da7"
DIRECT = {
    "CAP-REP-MODULE-REPORT-ASSETS", "CAP-REP-MODULE-REPORT-AUDIT-LOGS",
    "CAP-SITE-SITE-REPORTING-CATALOGUE", "CAP-SITE-SITE-VENDOR-GLOBAL-DIRECTORY",
    "CAP-SITE-SITE-VENDOR-LIFECYCLE",
}

FILES = [
    {"path": "LICENSE", "blob": "2def0e8831c25daae02b8b87cd439da27f1ac1c1", "sha256": "91b65277959ec273763d28ef002e83a6b3fba57c7a35436c9e5b66536333d720", "loci": "root AGPL v3 licence"},
    {"path": "composer.json", "blob": "88d87ef95436cf476e401c88398d2fb2051cd99a", "sha256": "db425b0801afa6fc3c28ca15985b21715e1f93fad17513124fed60153edbec12", "loci": "AGPL-3.0-or-later package declaration"},
    {"path": "routes/web.php", "blob": "e6d99eea22d8c7417ec7a32883dd8ded7ebb0837", "sha256": "b87733d36a4c7d22a84d2a97508d92335e9f70524a8381aa50d18dbd6eb4259c", "loci": "L724-L732"},
    {"path": "app/Http/Controllers/ReportsController.php", "blob": "fe921fafe012f432e3586eff3897e94b8520577e", "sha256": "6d4b97d917ea40b4d29a9660e1ed6b6e076d3756d951039d51bb64e67ff8ca1d", "loci": "L58-L80,L272-L384,L471-L489,L502-L510,L520-L731,L740-L899,L967-L1194"},
    {"path": "resources/views/reports/custom/asset.blade.php", "blob": "e5548c6c4ad7caba98450a1ea0231e44877226a0", "sha256": "46c2748fb879408361a0950343124482b63e34165cd3c0b7adf1387dc5902675", "loci": "L40-L166,L332-L393,L644-L671,L715-L730"},
    {"path": "app/Http/Requests/CustomAssetReportRequest.php", "blob": "c2018a16a0509a47ef5c0bb2c830ceac9ea242bd", "sha256": "8612f1aabe2059bfe3a3748cd15863c65669e0991b366d35240fc4d6aecb5f31", "loci": "L8-L38"},
    {"path": "app/Http/Controllers/Api/ReportsController.php", "blob": "ae61437a60cc573e9c5185eb61b013b320c1d7f0", "sha256": "431b559dea2aff1656950e467cb44d075a6d752d2d5ec4292a3af52e46dccc44", "loci": "L26-L172"},
    {"path": "resources/views/reports/activity.blade.php", "blob": "d9b58b2dda9e7886e7ba7597cf352188c9b523e4", "sha256": "d57e95e9639f3774df2ab8c0a8a7c5b8f023c4200734206114ee69640622997e", "loci": "L10-L45"},
    {"path": "resources/views/reports/index.blade.php", "blob": "6470ed43d18ef50003e9bff6107b08deb62c028e", "sha256": "1afecafd37b3ec456e10ae8f79dfcb89ac1154ad6108d32dee38191899e32d7f", "loci": "L12-L72,L91-L174"},
    {"path": "app/Http/Transformers/ActionlogsTransformer.php", "blob": "966ed73185a710d9b20528fc4a16e37ef4fbcf7e", "sha256": "aa637d18ece1a56b63bfa02d127045671d1b8560227dcc6afff37a35bb6aa57e", "loci": "L164-L224"},
    {"path": "app/Models/Traits/Loggable.php", "blob": "cf5e8b03a1e9b0663248db1e22d6c199615e82cf", "sha256": "60ac251ac524ba434f7c599449f084669d7884335caf0f1e472a5e8139705d71", "loci": "supplier/action-log support"},
    {"path": "app/Http/Controllers/SuppliersController.php", "blob": "1128743e7d3e9c85cc12e19d65d52e6059c17062", "sha256": "c504518310b9ddb3effcfebe09e9511e05a7d8dae0cd0c3c8577d02fbdf02267", "loci": "L28-L72,L80-L154,L163-L167"},
    {"path": "app/Http/Controllers/Api/SuppliersController.php", "blob": "0eb045d2b3fe337b44b7ff709a559f3f7304350b", "sha256": "d23a580801d8e4c8b17cdf3c2526e95dd8bacbe11c0c04bf0915f0534d222194", "loci": "L32-L125,L134-L227"},
    {"path": "app/Http/Transformers/SuppliersTransformer.php", "blob": "efe83defb6d3e169a61a89b5894eaeed10d4442b", "sha256": "5235c04704c30e1cdb23743401867b455341640f1d03fc1cec19ad6ec551be57", "loci": "L12-L63"},
    {"path": "app/Models/Supplier.php", "blob": "750670647997463e54013f5c4306dbf83c1aaf4b", "sha256": "28814ea5558faca830d556cf86ac7cfec2f95632ab3b09fe713926e53862d0ca", "loci": "supplier relationships, validation and soft deletion"},
    {"path": "app/Actions/Suppliers/DestroySupplierAction.php", "blob": "da5260bd53f3f825c6a68c65fe8c4c16b5cdad75", "sha256": "e2ce62d4262da912e9f411468c7980c183e783d62943e1efcd5257dab3a139fe", "loci": "L23-L65"},
    {"path": "resources/views/suppliers/index.blade.php", "blob": "8190bd3146262b56019deb2acc46680ae1a7c2ba", "sha256": "b2bd559a220491543bc705af8c7e618ee0658c442cbf9523f99442543343c9cf", "loci": "supplier directory table"},
]

EVALUATIONS = [
    {"working_key": "CAP-REP-MODULE-REPORT-ASSETS", "status": "direct", "candidate": "Snipe-IT custom asset report",
     "requirement": "Run an authorised configurable asset report with bounded filters, selected fields and safe export.",
     "behavior": "The custom asset report filters location, supplier, status, dates and assignment state, selects fields and streams formula-escaped CSV.",
     "loci": "grokability/snipe-it@93b0696d69582761176e4de06e99d0d6034be6af :: ReportsController.php L471-L489,L502-L510,L520-L731,L740-L899,L967-L1194; resources/views/reports/custom/asset.blade.php L40-L166,L332-L393,L644-L671,L715-L730; CustomAssetReportRequest.php L8-L38",
     "reason": "No Oblivion Site policy, direct-object concealment, exact columns, frontend, accessibility or runtime parity."},
    {"working_key": "CAP-REP-MODULE-REPORT-AUDIT-LOGS", "status": "direct", "candidate": "Snipe-IT audit-log report",
     "requirement": "Review and export an authorised audit-log report with actor, action, target, time and source context.",
     "behavior": "Named authorised GET/POST report routes provide filtered activity rows and a controller-owned CSV export with transformed actor/action/item/time/IP data.",
     "loci": "grokability/snipe-it@93b0696d69582761176e4de06e99d0d6034be6af :: routes/web.php L724-L732; ReportsController.php L272-L384; Api/ReportsController.php L26-L172; reports/activity.blade.php L10-L45; ActionlogsTransformer.php L164-L224",
     "reason": "No Oblivion Site scope, exact audit categories, direct-object concealment, browser or runtime parity."},
    {"working_key": "CAP-SITE-SITE-REPORTING-ASSET-CONDITION", "status": "retain", "candidate": "Snipe-IT asset report",
     "reason": "Status, location, warranty and audit fields do not compute Site-filtered condition groups, status totals or expired/30-day warranty-risk summaries."},
    {"working_key": "CAP-SITE-SITE-REPORTING-CATALOGUE", "status": "direct", "candidate": "Snipe-IT report catalogue",
     "requirement": "Present an authorised catalogue of distinct reports with linked attention counts.",
     "behavior": "The report controller and index expose distinct report entries and linked attention counts.",
     "loci": "grokability/snipe-it@93b0696d69582761176e4de06e99d0d6034be6af :: ReportsController.php L58-L80; resources/views/reports/index.blade.php L12-L72,L91-L174",
     "reason": "No Oblivion catalogue completeness, Site/role boundary, frontend or runtime parity."},
    {"working_key": "CAP-SITE-SITE-REPORTING-VENDOR-CONTACTS-EXPORT", "status": "retain", "candidate": "Snipe-IT supplier table export",
     "reason": "Adjacent browser-table contact export lacks the exact server-owned active-vendor CSV with Site/region/service/preferred/contact-method fields and formula-safe cell handling."},
    {"working_key": "CAP-SITE-SITE-VENDOR-GLOBAL-AUDIT", "status": "retain", "candidate": "Snipe-IT supplier action logs",
     "reason": "Generic supplier CRUD action logs do not provide credential-vault reveal/copy/rotation/denial lifecycle or target/result security audit."},
    {"working_key": "CAP-SITE-SITE-VENDOR-GLOBAL-DIRECTORY", "status": "direct", "candidate": "Snipe-IT supplier directory",
     "requirement": "Search and filter an authorised supplier directory with contacts and related inventory counts.",
     "behavior": "Web/API controllers, transformer and index table expose searchable supplier contacts plus asset, licence, accessory, component and consumable counts.",
     "loci": "grokability/snipe-it@93b0696d69582761176e4de06e99d0d6034be6af :: SuppliersController.php L28-L32,L163-L167; Api/SuppliersController.php L32-L125; SuppliersTransformer.php L12-L63; suppliers/index.blade.php",
     "reason": "No Oblivion Site, active/preferred/vendor-service, direct-object, frontend or runtime parity."},
    {"working_key": "CAP-SITE-SITE-VENDOR-LIFECYCLE", "status": "direct", "candidate": "Snipe-IT supplier lifecycle",
     "requirement": "Create, update and delete an authorised supplier while preserving creator identity and dependent-record guards.",
     "behavior": "Web/API lifecycle paths validate authority, persist supplier/contact fields and creator identity, soft-delete, and refuse deletion while dependent inventory remains.",
     "loci": "grokability/snipe-it@93b0696d69582761176e4de06e99d0d6034be6af :: SuppliersController.php L37-L72,L80-L154; Api/SuppliersController.php L134-L227; Supplier.php; DestroySupplierAction.php L23-L65",
     "reason": "No Oblivion Site ownership, exact vendor schema, audit/replay, frontend or runtime parity."},
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
    return "|".join([str(row["working_key"]), str(row["status"]),
                     ";".join(sorted(set(str(value) for value in row.get("source_units", [])))),
                     ";".join(sorted(set(str(value) for value in row.get("evidence_loci", []))))])


if any(path.exists() for path in (ARTIFACT, SUMMARY)):
    require(all(path.exists() for path in (ARTIFACT, SUMMARY)), "Partial Wave37 output set")
    current, summary = load(BENCHMARK), load(SUMMARY)
    require(current["summary"]["eligible_total"] == 478 and current["summary"]["completion_unproved"]["total"] == 426, "Existing Wave37 count drift")
    for key, path in (("benchmark", BENCHMARK), ("inventory", INVENTORY), ("ledger", LEDGER), ("matrix", MATRIX)):
        require(summary["outputs"][key] == record(path), f"Existing Wave37 output drift: {key}")
    pointer = load(POINTER)
    require(pointer["artifacts"]["benchmark_wave37"] == record(ARTIFACT), "Wave37 pointer drift")
    require(pointer["artifacts"]["benchmark_wave37_generation_summary"] == record(SUMMARY), "Wave37 summary pointer drift")
    print(json.dumps({"status": "already_applied", "wave37": record(ARTIFACT), "benchmark": record(BENCHMARK), "summary": record(SUMMARY)}, indent=2))
    raise SystemExit(0)

for path, expected in EXPECTED.items():
    require(sha_file(path) == expected, f"Input SHA drift: {path}")
require(sha_lines(SELECTED) == SELECTION_SHA, "Wave37 selection digest mismatch")
benchmark, inventory = load(BENCHMARK), load(INVENTORY)
require(benchmark["audited_commit"] == COMMIT and len(benchmark["targets"]) == 904, "Benchmark base drift")
by_key = {row["working_key"]: row for row in benchmark["targets"]}
require(all(not by_key[key]["completion_credit"] and by_key[key]["status"] == "unproved" for key in SELECTED), "Selected target state drift")

artifact = {"schema_version": "1.0.0", "artifact": "benchmark-target-specific-adjudication-904-wave37",
    "generated_at": GENERATED_AT, "audited_commit": COMMIT, "read_only": True,
    "scope": "Eight current Reporting/Sites completion-unproved targets independently reviewed; five bounded direct decisions and three retained-unproved decisions.",
    "methodology": {"family_credit_inherited": False,
        "runtime_boundary": "Comparator evidence changes benchmark P3 mapping completion_credit only; Oblivion product, runtime, browser, representative-task, release and overall-audit completion deltas remain zero.",
        "no_copy_rule": "Behavioural evidence only; no source, schema, UI or wording is copied.",
        "selection_preimage_rule": "Packet-order IDs joined by LF with no terminal LF, UTF-8 without BOM.",
        "selection_sha256": SELECTION_SHA, "independent_review_verdict": "GO_5_direct_3_retained_after_precision_corrections"},
    "selected_target_ids": SELECTED, "input_pins": {"benchmark_final_904_before_wave": record(BENCHMARK)},
    "repository_snapshots": {"SNIPE_IT": {**SNIPE, "commit_date": "2026-08-20T12:08:34Z", "default_branch": "master",
        "repository_root_licence": "GNU AGPL v3", "package_licence": "AGPL-3.0-or-later",
        "edition_boundary": "Official self-hosted community repository only; hosted services, support arrangements and out-of-tree extensions excluded."}},
    "verified_files": [{"repo": SNIPE["repo"], **row} for row in FILES],
    "counts": {"evaluated": 8, "direct": 5, "retained_unproved": 3, "documented_ncm": 0},
    "evaluations": [{**row, "candidate_status": "verified_benchmark_direct_recommended" if row["status"] == "direct" else "retain_unproved",
                     "completion_credit_recommended": row["status"] == "direct"} for row in EVALUATIONS],
    "collision_disclosure": {"prior_materialized_packets": 32, "prior_unique_evaluated_ids": 332,
        "prior_unique_id_set_sha256": "b84f50a66001aa4b1102bc4ee8693b568f9cdb20683bdfd107aedf06482084c6",
        "selected_target_intersection": 0,
        "behavioral_path_collision": "The 14/14 originally selected behavioral evidence paths have zero prior Snipe-IT behavior-path intersection; the added route-binding path also has zero repo-qualified collision.",
        "metadata_path_boundary": "LICENSE and composer.json are repository-identity metadata and are not claimed collision-free under raw cross-repository path-only comparison.",
        "source_reuse": "Snipe-IT appeared at an older commit for unrelated asset/checkout/QR targets; no family or source-path credit is inherited."},
    "count_delta": {"verified_benchmark_direct": 5, "eligible_total": 5, "completion_unproved": -5, "documented_ncm": 0,
                    "product_runtime_representative_task_overall_completion": 0},
    "post_wave_totals": {"verified_benchmark_direct": 367, "verified_benchmark_total": 389,
                         "documented_ncm": 89, "eligible_total": 478, "completion_unproved": 426}}
write_json(ARTIFACT, artifact)

evaluation_by_key = {row["working_key"]: row for row in EVALUATIONS}
for key in DIRECT:
    row = by_key[key]; evaluation = evaluation_by_key[key]
    row.update({"status": "verified_benchmark_direct", "inheritance_method": "fresh_target_specific_wave37_direct",
                "prior_outcome": row["status"], "source_units": [f"fresh-904-wave37:{key}"],
                "evidence_loci": [evaluation["loci"]], "completion_credit": True})

status = Counter(str(row["status"]) for row in benchmark["targets"])
unproved = {"ordinary": status["unproved"], "audit_assigned_stable_name": status["unproved_audit_assigned_id"],
            "prior_pending": status["unproved_pending"], "prior_reject": status["unproved_reject"],
            "source_stable_semantic_merge": status["unproved_source_stable"]}
unproved["total"] = sum(unproved.values())
require(unproved == {"ordinary": 387, "audit_assigned_stable_name": 11, "prior_pending": 24, "prior_reject": 3,
                     "source_stable_semantic_merge": 1, "total": 426}, "Wave37 partition mismatch")
benchmark.update({"generated_at": GENERATED_AT, "status": "target_specific_478_of_904_complete_not_overall_audit_completion",
    "summary": {"verified_benchmark": {"direct": 367, "strict_one_to_one_rename": 22, "total": 389},
                "documented_no_credible_match": {"direct": 82, "strict_one_to_one_rename": 7, "total": 89},
                "eligible_total": 478, "completion_unproved": unproved, "status_counts": dict(sorted(status.items()))},
    "completion_boundary": {"eligible_rows": 478, "completion_unproved_rows": 426,
        "statement": benchmark["completion_boundary"]["statement"],
        "formal_audit_gate": "blocked_426_of_904_targets_lack_completed_target_specific_benchmark_or_documented_no_match_outcome"}})
benchmark["checksum_algorithm"]["full_mapping_sha256"] = sha_lines([mapping_tuple(item) for item in benchmark["targets"]], sort=True)
benchmark["checksum_algorithm"]["eligible_subset_sha256"] = sha_lines([mapping_tuple(item) for item in benchmark["targets"] if item.get("completion_credit")], sort=True)
benchmark["inputs"]["target_specific_wave37"] = {**record(ARTIFACT), "accepted_direct_count": 5,
    "retained_unproved_count": 3, "selected_keys_sha256": SELECTION_SHA}
write_json(BENCHMARK, benchmark)

for key in DIRECT:
    feature = next(item for item in inventory["features"] if item["working_key"] == key)
    row = by_key[key]
    feature["benchmark_mapping"] = {field: copy.deepcopy(row[field]) for field in
        ("status", "completion_credit", "inheritance_method", "prior_outcome", "source_units", "evidence_loci")}
inventory["generated_at"] = GENERATED_AT
inventory["benchmark_mapping"].update({"working_manifest_eligible": 478, "working_manifest_verified_benchmark": 389,
    "working_manifest_verified_direct": 367, "working_manifest_verified_rename": 22,
    "working_manifest_documented_no_credible_match": 89, "working_manifest_documented_ncm_direct": 82,
    "working_manifest_documented_ncm_rename": 7, "working_manifest_completion_unproved": 426,
    "completion_gate_status": "478/904 final targets have evidence-preserving benchmark/NCM mapping; 426 remain completion-unproved"})
inventory["pass_status"]["P3"] = "Blocked—478/904 targets mapped with evidence-preserving completion credit (389 verified benchmark, 89 documented No Credible Match); 426 unproved"
inventory["capability_denominator_status"]["benchmark_mapping"] = {"eligible": 478, "verified_benchmark": 389, "documented_no_credible_match": 89, "completion_unproved": 426}
inventory["canonical_feature_register_metadata"]["benchmark_mapping"] = {"verified_benchmark": 389, "documented_no_credible_match": 89, "completion_credit": 478, "completion_unproved": 426}
inventory["canonical_feature_register_metadata"]["source_artifacts"]["benchmark_mapping_sha256"] = sha_file(BENCHMARK)
write_json(INVENTORY, inventory)


def read_csv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle); return list(reader.fieldnames or []), [dict(item) for item in reader]


def write_csv(path: Path, headers: list[str], rows: list[dict[str, str]]) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=headers, extrasaction="raise", lineterminator="\n"); writer.writeheader(); writer.writerows(rows)


ledger_headers, ledger_rows = read_csv(LEDGER); matrix_headers, matrix_rows = read_csv(MATRIX)
for key in DIRECT:
    evaluation = evaluation_by_key[key]
    ledger_row = next(item for item in ledger_rows if item["feature_id"] == key)
    matrix_row = next(item for item in matrix_rows if item["feature_id"] == key)
    mapped_p3 = "Mapped—verified benchmark with final-target completion credit; inheritance=fresh_target_specific_wave37_direct; full feature parity is not claimed."
    ledger_row["P3_status"] = mapped_p3
    ledger_row["gaps"] = ledger_row["gaps"].replace("P3 benchmark/no-match completion unproved; ", "")
    ledger_row["evidence_count"] = str(int(ledger_row["evidence_count"] or "0") + 1)
    matrix_row.update({"benchmark_candidates": evaluation["candidate"], "selected_open_source_benchmark": evaluation["candidate"],
        "benchmark_url_and_sha": f"{SNIPE['url']}/commit/{SNIPE['commit']}", "verified_behaviour": evaluation["behavior"],
        "neutral_requirements_extracted": evaluation["requirement"], "no_match_evidence": "", "P3": mapped_p3,
        "confidence": "High for the bounded comparator slice; Oblivion Site/role/direct-object/frontend/runtime parity remains unverified"})
write_csv(LEDGER, ledger_headers, ledger_rows); write_csv(MATRIX, matrix_headers, matrix_rows)

summary = {"schema_version": "1.0.0", "artifact": "final-904-benchmark-wave37-generation-summary",
    "generated_at": GENERATED_AT, "audited_commit": COMMIT, "read_only": True,
    "inputs": {"wave37": record(ARTIFACT)},
    "outputs": {"benchmark": record(BENCHMARK), "inventory": record(INVENTORY), "ledger": record(LEDGER), "matrix": record(MATRIX)},
    "mapping_tuple_hashes": {"full": benchmark["checksum_algorithm"]["full_mapping_sha256"],
                             "eligible": benchmark["checksum_algorithm"]["eligible_subset_sha256"]},
    "counts": {"denominator": 904, "direct": 367, "rename": 22, "verified": 389, "ncm": 89, "eligible": 478, "completion_unproved": 426},
    "validation": {"selected": 8, "direct": 5, "retained": 3, "benchmark_p3_completion_credit_delta": 5,
                   "product_runtime_representative_task_overall_completion_delta": 0,
                   "completion_status": "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"}}
write_json(SUMMARY, summary)
pointer = load(POINTER); pointer["generated_at"] = max(pointer.get("generated_at", ""), GENERATED_AT)
pointer["artifacts"].update({"benchmark": record(BENCHMARK), "inventory": record(INVENTORY),
    "eight_pass_ledger": record(LEDGER), "benchmark_matrix": record(MATRIX),
    "benchmark_wave37": record(ARTIFACT), "benchmark_wave37_generation_summary": record(SUMMARY)})
pointer["completion_status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"; pointer["runtime_credit_delta"] = 0
write_json(POINTER, pointer)
print(json.dumps({"status": "applied", "wave37": record(ARTIFACT), "benchmark": record(BENCHMARK),
                  "inventory": record(INVENTORY), "ledger": record(LEDGER), "matrix": record(MATRIX),
                  "summary": record(SUMMARY), "active_inputs": record(POINTER)}, indent=2))
