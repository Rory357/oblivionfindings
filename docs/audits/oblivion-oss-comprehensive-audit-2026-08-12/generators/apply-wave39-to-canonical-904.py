#!/usr/bin/env python3
"""Apply independently reviewed Wave 39 Moodle policy comparator evidence."""

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
GENERATED_AT = "2026-08-22T00:05:00+12:00"

BENCHMARK = SOURCE / "benchmark-final-904-mapping.json"
INVENTORY = AUDIT / "inventory-904.json"
LEDGER = AUDIT / "02-eight-pass-coverage-ledger-904.csv"
MATRIX = AUDIT / "03-feature-to-benchmark-matrix-904.csv"
POINTER = SOURCE / "canonical-audit-inputs.json"
ARTIFACT = SOURCE / "benchmark-target-specific-adjudication-904-wave39.json"
SUMMARY = SOURCE / "final-904-benchmark-wave39-generation-summary.json"

EXPECTED = {
    BENCHMARK: "dcda86ed2a6a4328ddc2ba23780641afd2daafe860d310c3c89218ffa8a07458",
    INVENTORY: "379b672e77be24ac4d1b8829099b41c5e6f790e58604d8b1218f4aaf20a2bef0",
    LEDGER: "c30a4542bfeba4560345b0a94843cc60f2df0e9fc413df332b3f895abc853158",
    MATRIX: "918b58c295f2e47b0784a9921eeca56943657cdc015e4eb79ea02b311b754526",
    POINTER: "332aa1dce635c4c98c2677dadb3bf4b8890631e9a3bc0a93bbc745b68ce74f9a",
}

MOODLE = {
    "repo": "moodle/moodle",
    "url": "https://github.com/moodle/moodle",
    "commit": "6216fe4ed19a5a3c88c0951d1647e9f2d626bcbb",
    "tree": "7bf240a29b14e4a4384eb8aad186204695641751",
    "parent": "fd4de0edf5fe50cfd6e98f8aa2e7dcc9f6b10bd6",
}

SELECTED = [
    "CAP-GOV-POLICY-AUTHORING-VERSIONING",
    "CAP-GOV-POLICY-APPROVAL",
    "CAP-GOV-POLICY-INDIVIDUAL-ATTESTATION",
    "CAP-GOV-POLICY-ATTESTATION-OVERSIGHT",
    "CAP-HR-POLICY-LIFECYCLE",
    "CAP-HR-POLICY-VERSIONS",
    "CAP-HR-POLICY-ATTESTATION-EMPLOYEE",
    "CAP-HR-POLICY-ATTESTATION-OVERSIGHT",
]
SELECTION_SHA = "3a74c7297f38ca56b6fa75f2ac89692d3804b016ac57bacb52e6402ebdd3da99"
PRIOR_IDS_SHA = "9877e8ccd82a27fe53bf5c32d9cc2ef72bb10aec6545956151a14bba5bce54aa"
DIRECT = set(SELECTED) - {"CAP-GOV-POLICY-APPROVAL"}

FILES = [
    {"path": "COPYING.txt", "blob": "94a9ed024d3859793618152ea559a168bbcbb5e2", "bytes": 35147,
     "sha256": "8ceb4b9ee5adedde47b31e975c1d90c73ad27b6b165a1dcd80c7c545eb65b903", "loci": "L1-L20"},
    {"path": "public/admin/tool/policy/classes/api.php", "blob": "6c257b8f00934cea74541e4e0408deffe90f2280", "bytes": 44163,
     "sha256": "267a030491ce77cf4f17d3b403995db6addc6b6da38eee7cd2926041211fea8b", "loci": "L88-L174,L229-L257,L369-L566,L668-L817,L921-L1064"},
    {"path": "public/admin/tool/policy/classes/acceptances_table.php", "blob": "15ac140f2f297fdc8ca1e4171e4e05e28db0b288", "bytes": 24919,
     "sha256": "0752f9aaef983bdee5986a6737f9127b5920af78287d138314ba526808f89a27", "loci": "L45-L126,L173-L239,L531-L560"},
    {"path": "public/admin/tool/policy/db/install.xml", "blob": "b5eefedd6664e749d9bcb486c243b63e22f8dc63", "bytes": 6159,
     "sha256": "9cc0f7ba0b600c121fdef5aacf3a22ead340eaa12b2d914cd94013e13772d5b1", "loci": "L8-L60"},
    {"path": "public/admin/tool/policy/db/access.php", "blob": "1b2aa617890f267346c1bdf21127dcd6a781a8bc", "bytes": 1729,
     "sha256": "cb0b1af653a9df0fa2b994c14c77be06140df1d858336a1ac4a22f8427fe249d", "loci": "L28-L60"},
    {"path": "public/admin/tool/policy/settings.php", "blob": "78aa6b2f67f9e8ca62cf085e94f4916f8e495387", "bytes": 1810,
     "sha256": "4e3fd907e4cd0757c1b5ebc3db4565f3edca6ebe7d711b0b7a63e1af6e3a9213", "loci": "L32-L50"},
    {"path": "public/admin/tool/policy/editpolicydoc.php", "blob": "4a9f9ac342b67936144af5a3f76219fd3cb45178", "bytes": 6499,
     "sha256": "5269977543b1c242b14bbf7ac57f8a8b438f3d0f5d2aae869159f237cf70aaa7", "loci": "L32-L42,L45-L113,L132-L180"},
    {"path": "public/admin/tool/policy/acceptances.php", "blob": "dbf0dc929bdcdcc5de382016ebeded2edab53dd2", "bytes": 2671,
     "sha256": "032a3f5c04a310e39033c74e6e4813ffb1d5a2ef5cd6b6cf73372d122f4c5022", "loci": "L31-L58"},
    {"path": "public/admin/tool/policy/classes/external/set_acceptances_status.php", "blob": "01caae7ee206e91140441706c251eaae7d0892ea", "bytes": 5875,
     "sha256": "554289fc88fe9bdc8a339fa35758b69ab1fc062522efb5f715f0ae1138ad9c44", "loci": "L44-L56,L64-L136"},
    {"path": "public/admin/tool/policy/classes/external/get_user_acceptances.php", "blob": "ba3f2541689a3ec638d3c23a1c0a95f2f2ac8d6b", "bytes": 8516,
     "sha256": "ceab4fadc9a2a1a3530408987dc29ab78d9f0c96665e704e6508aec0a6aae441", "loci": "L46-L130,L142-L168"},
]

EVALUATIONS = [
    {"working_key": "CAP-GOV-POLICY-AUTHORING-VERSIONING", "status": "direct", "candidate": "Moodle policy document lifecycle",
     "requirement": "Author and version a governed policy with durable current, draft and archived revision states under explicit management authority.",
     "behavior": "Policy identities own managed content revisions; authorised managers create, edit, activate, inactivate, restore drafts and delete eligible drafts while prior current versions are archived.",
     "loci": "moodle/moodle@6216fe4ed19a5a3c88c0951d1647e9f2d626bcbb :: public/admin/tool/policy/classes/api.php L88-L174,L369-L566; editpolicydoc.php L32-L42,L45-L113,L132-L180; db/install.xml L8-L41; db/access.php L46-L60",
     "reason": "Policy authoring/versioning comparator only; no Oblivion Site, role, approval-separation, notification, UI or runtime parity."},
    {"working_key": "CAP-GOV-POLICY-APPROVAL", "status": "retain", "candidate": "Moodle policy activation",
     "reason": "Managed activation does not preserve a distinct approval decision, approver/time, rationale or author-versus-approver separation for a submitted version."},
    {"working_key": "CAP-GOV-POLICY-INDIVIDUAL-ATTESTATION", "status": "direct", "candidate": "Moodle policy acceptance",
     "requirement": "Record an authenticated individual's accept or decline response against an exact applicable policy version with durable actor, language and time evidence.",
     "behavior": "Applicable policy versions receive per-user accept/decline status bound to version, user and language with created/modified timestamps and a unique version-user key.",
     "loci": "moodle/moodle@6216fe4ed19a5a3c88c0951d1647e9f2d626bcbb :: public/admin/tool/policy/classes/api.php L668-L817,L921-L1064; classes/external/set_acceptances_status.php L44-L56,L64-L136; classes/external/get_user_acceptances.php L46-L130,L142-L168; db/install.xml L43-L60; db/access.php L28-L44",
     "reason": "Exact-version user attestation only; no Oblivion policy assignment, Site, employee or runtime parity."},
    {"working_key": "CAP-GOV-POLICY-ATTESTATION-OVERSIGHT", "status": "direct", "candidate": "Moodle policy acceptance report",
     "requirement": "Permit authorised oversight of accepted, declined and missing policy responses across users and versions, including bounded export.",
     "behavior": "Capability-gated acceptance reporting filters and exports per-user policy/version response states and missing responses with modifier evidence.",
     "loci": "moodle/moodle@6216fe4ed19a5a3c88c0951d1647e9f2d626bcbb :: public/admin/tool/policy/settings.php L32-L50; db/access.php L54-L60; acceptances.php L31-L58; classes/acceptances_table.php L45-L126,L173-L239,L531-L560; classes/api.php L88-L174",
     "reason": "Governance response oversight only; no Oblivion board denominator, Site, role or runtime parity."},
    {"working_key": "CAP-HR-POLICY-LIFECYCLE", "status": "direct", "candidate": "Moodle workforce-policy lifecycle analogue",
     "requirement": "Manage a workforce-policy analogue through authored draft, active, inactive and archived states while retaining its version history.",
     "behavior": "Authorised policy managers create, edit, activate, inactivate, archive, restore as draft and delete eligible draft policy records.",
     "loci": "moodle/moodle@6216fe4ed19a5a3c88c0951d1647e9f2d626bcbb :: public/admin/tool/policy/classes/api.php L369-L566; editpolicydoc.php L32-L42,L97-L113,L132-L180; db/access.php L46-L52",
     "reason": "Authenticated-site-user workforce analogue only, not an Oblivion employee-policy aggregate, Site assignment or HR authority contract."},
    {"working_key": "CAP-HR-POLICY-VERSIONS", "status": "direct", "candidate": "Moodle policy revision register analogue",
     "requirement": "Retain multiple timestamped workforce-policy analogue revisions under a stable policy identity with current, draft and archived classification and editor evidence.",
     "behavior": "A stable policy identity owns multiple version rows with status, timestamps and last-editor identity; managed transitions preserve prior versions and guard draft deletion.",
     "loci": "moodle/moodle@6216fe4ed19a5a3c88c0951d1647e9f2d626bcbb :: public/admin/tool/policy/classes/api.php L88-L174,L229-L257,L369-L566; db/install.xml L8-L41",
     "reason": "Version register analogue only; no Oblivion HR record ownership, Site, UI or runtime parity."},
    {"working_key": "CAP-HR-POLICY-ATTESTATION-EMPLOYEE", "status": "direct", "candidate": "Moodle authenticated-user policy attestation analogue",
     "requirement": "Let an authenticated workforce-user analogue attest to an exact current policy version with durable accepted or declined evidence.",
     "behavior": "An authenticated user responds to the applicable exact version and persists accepted/declined state, actor, language and timestamps.",
     "loci": "moodle/moodle@6216fe4ed19a5a3c88c0951d1647e9f2d626bcbb :: public/admin/tool/policy/classes/api.php L668-L817,L921-L1037; classes/external/set_acceptances_status.php L44-L56,L64-L136; db/install.xml L43-L60",
     "reason": "Authenticated-user workforce analogue only; no Oblivion employee-profile linkage, Site assignment or runtime parity."},
    {"working_key": "CAP-HR-POLICY-ATTESTATION-OVERSIGHT", "status": "direct", "candidate": "Moodle workforce-attestation oversight analogue",
     "requirement": "Allow an authorised workforce-policy overseer analogue to filter, inspect and download aggregate and per-user policy response state.",
     "behavior": "Capability-gated pages and tables expose policy/version acceptance, decline and missing-response status across users with export support.",
     "loci": "moodle/moodle@6216fe4ed19a5a3c88c0951d1647e9f2d626bcbb :: public/admin/tool/policy/settings.php L32-L50; acceptances.php L31-L58; classes/acceptances_table.php L45-L126,L173-L239,L531-L560; classes/external/get_user_acceptances.php L46-L130,L142-L168",
     "reason": "Workforce-user oversight analogue only; no Oblivion HR Site, employee denominator, role or runtime parity."},
]


def sha_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def sha_lines(lines: list[str], *, sort: bool = False) -> str:
    values = sorted(lines) if sort else lines
    return hashlib.sha256("\n".join(values).encode("utf-8")).hexdigest()


def canonical_jsonl(rows: list[dict[str, Any]], *, sort_by: str | None = None) -> str:
    values = sorted(rows, key=lambda row: str(row[sort_by])) if sort_by else rows
    text = "\n".join(json.dumps(row, ensure_ascii=False, sort_keys=True, separators=(",", ":")) for row in values)
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


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


def read_csv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        return list(reader.fieldnames or []), [dict(item) for item in reader]


def write_csv(path: Path, headers: list[str], rows: list[dict[str, str]]) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=headers, extrasaction="raise", lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)


if any(path.exists() for path in (ARTIFACT, SUMMARY)):
    require(all(path.exists() for path in (ARTIFACT, SUMMARY)), "Partial Wave39 output set")
    current, summary = load(BENCHMARK), load(SUMMARY)
    require(current["summary"]["eligible_total"] == 491 and current["summary"]["completion_unproved"]["total"] == 413,
            "Existing Wave39 count drift")
    for key, path in (("benchmark", BENCHMARK), ("inventory", INVENTORY), ("ledger", LEDGER), ("matrix", MATRIX)):
        require(summary["outputs"][key] == record(path), f"Existing Wave39 output drift: {key}")
    pointer = load(POINTER)
    require(pointer["artifacts"]["benchmark_wave39"] == record(ARTIFACT), "Wave39 pointer drift")
    require(pointer["artifacts"]["benchmark_wave39_generation_summary"] == record(SUMMARY), "Wave39 summary pointer drift")
    print(json.dumps({"status": "already_applied", "wave39": record(ARTIFACT), "summary": record(SUMMARY)}, indent=2))
    raise SystemExit(0)

for path, expected in EXPECTED.items():
    require(sha_file(path) == expected, f"Input drift: {path.name}")
require(sha_lines(SELECTED) == SELECTION_SHA, "Wave39 selection digest mismatch")
require(len(DIRECT) == 7 and len(SELECTED) == 8, "Wave39 selection cardinality mismatch")

benchmark, inventory = load(BENCHMARK), load(INVENTORY)
require(benchmark["audited_commit"] == COMMIT and len(benchmark["targets"]) == 904, "Benchmark base drift")
by_key = {row["working_key"]: row for row in benchmark["targets"]}
require(all(by_key[key]["class"] == "H" and by_key[key]["status"] == "unproved" and
            not by_key[key]["completion_credit"] for key in SELECTED), "Selected target state drift")

proof_sha = canonical_jsonl(EVALUATIONS)
source_registry_sha = canonical_jsonl(FILES, sort_by="path")
artifact = {
    "schema_version": "1.0.0", "artifact": "benchmark-target-specific-adjudication-904-wave39",
    "generated_at": GENERATED_AT, "audited_commit": COMMIT, "read_only": True,
    "scope": "Eight current Governance/HR policy completion-unproved targets independently reviewed; seven bounded direct decisions and one retained-unproved decision.",
    "methodology": {"family_credit_inherited": False,
        "runtime_boundary": "Comparator evidence changes benchmark P3 mapping completion_credit only; Oblivion product, runtime, browser, representative-task, release and overall-audit completion deltas remain zero.",
        "no_copy_rule": "Behavioural evidence only; no source, schema, UI, assets or wording is copied.",
        "selection_preimage_rule": "Packet-order IDs joined by LF with no terminal LF, UTF-8 without BOM.",
        "selection_sha256": SELECTION_SHA,
        "evaluation_proof_rule": "Packet-order evaluation objects; JSON sorted keys, compact separators; LF join without terminal LF; UTF-8 without BOM.",
        "evaluation_proof_sha256": proof_sha,
        "source_registry_rule": "Source file objects sorted by path; JSON sorted keys, compact separators; LF join without terminal LF; UTF-8 without BOM.",
        "source_registry_sha256": source_registry_sha,
        "independent_review_verdict": "GO_7_direct_1_retained_after_correcting_api_sha256_and_bounding_hr_analogues"},
    "selected_target_ids": SELECTED,
    "input_pins": {"benchmark_final_904_before_wave": record(BENCHMARK), "canonical_pointer_before_wave": record(POINTER)},
    "repository_snapshots": {"MOODLE": {**MOODLE, "committer_date": "2026-08-18T16:04:03Z",
        "subject": "weekly release 5.3dev", "default_branch": "main", "repository_root_licence": "GNU GPL v3-or-later",
        "edition_boundary": "Official Moodle community core only; MoodleCloud, partner services, proprietary plugins/themes and out-of-tree integrations excluded."}},
    "verified_files": [{"repo": MOODLE["repo"], **row} for row in FILES],
    "counts": {"evaluated": 8, "direct": 7, "retained_unproved": 1, "documented_ncm": 0},
    "evaluations": [dict(row, candidate_status=("verified_benchmark_direct_recommended" if row["status"] == "direct" else "retain_unproved"),
                         completion_credit_recommended=(row["status"] == "direct")) for row in EVALUATIONS],
    "collision_disclosure": {"materialized_target_specific_packets": 34, "prior_evaluation_occurrences": 354,
        "unique_prior_evaluated_ids": 348, "unique_prior_ids_sha256": PRIOR_IDS_SHA, "selected_target_intersection": 0,
        "prior_moodle_mentions": 0, "prior_moodle_behavioral_path_intersection": 0,
        "source_reuse": "The same pinned Moodle policy subsystem supports separately bounded Governance and HR analogue rows; every row carries its own neutral requirement, locus subset and parity limit, with no family inheritance."},
    "count_delta": {"verified_benchmark_direct": 7, "eligible_total": 7, "completion_unproved": -7,
                    "documented_ncm": 0, "product_runtime_representative_task_overall_completion": 0},
    "post_wave_totals": {"verified_benchmark_direct": 380, "verified_benchmark_total": 402,
                         "documented_ncm": 89, "eligible_total": 491, "completion_unproved": 413},
}
write_json(ARTIFACT, artifact)

evaluation_by_key = {row["working_key"]: row for row in EVALUATIONS}
for key in DIRECT:
    row = by_key[key]
    evaluation = evaluation_by_key[key]
    row.update({"status": "verified_benchmark_direct", "inheritance_method": "fresh_target_specific_wave39_direct",
                "prior_outcome": "unproved", "source_units": [f"fresh-904-wave39:{key}"],
                "evidence_loci": [evaluation["loci"]], "completion_credit": True})

status = Counter(str(row["status"]) for row in benchmark["targets"])
unproved = {"ordinary": status["unproved"], "audit_assigned_stable_name": status["unproved_audit_assigned_id"],
            "prior_pending": status["unproved_pending"], "prior_reject": status["unproved_reject"],
            "source_stable_semantic_merge": status["unproved_source_stable"]}
unproved["total"] = sum(unproved.values())
require(unproved == {"ordinary": 374, "audit_assigned_stable_name": 11, "prior_pending": 24,
                     "prior_reject": 3, "source_stable_semantic_merge": 1, "total": 413}, "Wave39 partition mismatch")
benchmark.update({"generated_at": GENERATED_AT, "status": "target_specific_491_of_904_complete_not_overall_audit_completion",
    "summary": {"verified_benchmark": {"direct": 380, "strict_one_to_one_rename": 22, "total": 402},
                "documented_no_credible_match": {"direct": 82, "strict_one_to_one_rename": 7, "total": 89},
                "eligible_total": 491, "completion_unproved": unproved, "status_counts": dict(sorted(status.items()))},
    "completion_boundary": {"eligible_rows": 491, "completion_unproved_rows": 413,
        "statement": benchmark["completion_boundary"]["statement"],
        "formal_audit_gate": "blocked_413_of_904_targets_lack_completed_target_specific_benchmark_or_documented_no_match_outcome"}})
benchmark["checksum_algorithm"]["full_mapping_sha256"] = sha_lines([mapping_tuple(item) for item in benchmark["targets"]], sort=True)
benchmark["checksum_algorithm"]["eligible_subset_sha256"] = sha_lines([mapping_tuple(item) for item in benchmark["targets"] if item.get("completion_credit")], sort=True)
benchmark["inputs"]["target_specific_wave39"] = {**record(ARTIFACT), "accepted_direct_count": 7,
    "retained_unproved_count": 1, "selected_keys_sha256": SELECTION_SHA,
    "evaluation_proof_sha256": proof_sha, "source_registry_sha256": source_registry_sha}
write_json(BENCHMARK, benchmark)

for key in DIRECT:
    feature = next(item for item in inventory["features"] if item["working_key"] == key)
    row = by_key[key]
    feature["benchmark_mapping"] = {field: copy.deepcopy(row[field]) for field in
        ("status", "completion_credit", "inheritance_method", "prior_outcome", "source_units", "evidence_loci")}
inventory["generated_at"] = GENERATED_AT
inventory["benchmark_mapping"].update({"working_manifest_eligible": 491, "working_manifest_verified_benchmark": 402,
    "working_manifest_verified_direct": 380, "working_manifest_verified_rename": 22,
    "working_manifest_documented_no_credible_match": 89, "working_manifest_documented_ncm_direct": 82,
    "working_manifest_documented_ncm_rename": 7, "working_manifest_completion_unproved": 413,
    "completion_gate_status": "491/904 final targets have evidence-preserving benchmark/NCM mapping; 413 remain completion-unproved"})
inventory["pass_status"]["P3"] = "Blocked—491/904 targets mapped with evidence-preserving completion credit (402 verified benchmark, 89 documented No Credible Match); 413 unproved"
inventory["capability_denominator_status"]["benchmark_mapping"] = {"eligible": 491, "verified_benchmark": 402, "documented_no_credible_match": 89, "completion_unproved": 413}
inventory["canonical_feature_register_metadata"]["benchmark_mapping"] = {"verified_benchmark": 402, "documented_no_credible_match": 89, "completion_credit": 491, "completion_unproved": 413}
inventory["canonical_feature_register_metadata"]["source_artifacts"]["benchmark_mapping_sha256"] = sha_file(BENCHMARK)
write_json(INVENTORY, inventory)

ledger_headers, ledger_rows = read_csv(LEDGER)
matrix_headers, matrix_rows = read_csv(MATRIX)
for key in DIRECT:
    evaluation = evaluation_by_key[key]
    ledger_row = next(item for item in ledger_rows if item["feature_id"] == key)
    matrix_row = next(item for item in matrix_rows if item["feature_id"] == key)
    mapped_p3 = "Mapped—verified benchmark with final-target completion credit; inheritance=fresh_target_specific_wave39_direct; full feature parity is not claimed."
    ledger_row["P3_status"] = mapped_p3
    ledger_row["gaps"] = ledger_row["gaps"].replace("P3 benchmark/no-match completion unproved; ", "")
    ledger_row["evidence_count"] = str(int(ledger_row["evidence_count"] or "0") + 1)
    matrix_row.update({"benchmark_candidates": evaluation["candidate"], "selected_open_source_benchmark": evaluation["candidate"],
        "benchmark_url_and_sha": f"{MOODLE['url']}/commit/{MOODLE['commit']}", "verified_behaviour": evaluation["behavior"],
        "neutral_requirements_extracted": evaluation["requirement"], "no_match_evidence": "", "P3": mapped_p3,
        "confidence": "High for the bounded comparator slice; Oblivion Site/role/direct-object/employee/frontend/runtime parity remains unverified"})
write_csv(LEDGER, ledger_headers, ledger_rows)
write_csv(MATRIX, matrix_headers, matrix_rows)

summary = {"schema_version": "1.0.0", "artifact": "final-904-benchmark-wave39-generation-summary",
    "generated_at": GENERATED_AT, "audited_commit": COMMIT, "read_only": True,
    "inputs": {"wave39": record(ARTIFACT)},
    "outputs": {"benchmark": record(BENCHMARK), "inventory": record(INVENTORY), "ledger": record(LEDGER), "matrix": record(MATRIX)},
    "mapping_tuple_hashes": {"full": benchmark["checksum_algorithm"]["full_mapping_sha256"],
                             "eligible": benchmark["checksum_algorithm"]["eligible_subset_sha256"]},
    "proof_hashes": {"selection": SELECTION_SHA, "evaluations": proof_sha, "source_registry": source_registry_sha,
                     "prior_unique_ids": PRIOR_IDS_SHA},
    "counts": {"denominator": 904, "direct": 380, "rename": 22, "verified": 402, "ncm": 89, "eligible": 491, "completion_unproved": 413},
    "validation": {"selected": 8, "direct": 7, "retained": 1, "benchmark_p3_completion_credit_delta": 7,
                   "product_runtime_representative_task_overall_completion_delta": 0,
                   "completion_status": "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"}}
write_json(SUMMARY, summary)
pointer = load(POINTER)
pointer["generated_at"] = max(pointer.get("generated_at", ""), GENERATED_AT)
pointer["artifacts"].update({"benchmark": record(BENCHMARK), "inventory": record(INVENTORY),
    "eight_pass_ledger": record(LEDGER), "benchmark_matrix": record(MATRIX),
    "benchmark_wave39": record(ARTIFACT), "benchmark_wave39_generation_summary": record(SUMMARY)})
pointer["completion_status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"
pointer["runtime_credit_delta"] = 0
write_json(POINTER, pointer)
print(json.dumps({"status": "applied", "wave39": record(ARTIFACT), "benchmark": record(BENCHMARK),
                  "inventory": record(INVENTORY), "ledger": record(LEDGER), "matrix": record(MATRIX),
                  "summary": record(SUMMARY), "active_inputs": record(POINTER)}, indent=2))
