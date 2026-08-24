#!/usr/bin/env python3
"""Apply independently reviewed Wave 40 Plane roadmap comparator evidence."""

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
GENERATED_AT = "2026-08-22T00:35:00+12:00"
BENCHMARK = SOURCE / "benchmark-final-904-mapping.json"
INVENTORY = AUDIT / "inventory-904.json"
LEDGER = AUDIT / "02-eight-pass-coverage-ledger-904.csv"
MATRIX = AUDIT / "03-feature-to-benchmark-matrix-904.csv"
POINTER = SOURCE / "canonical-audit-inputs.json"
ARTIFACT = SOURCE / "benchmark-target-specific-adjudication-904-wave40.json"
SUMMARY = SOURCE / "final-904-benchmark-wave40-generation-summary.json"

EXPECTED = {
    BENCHMARK: "9a5aa8790a3d681d3f24ad5cf485da02f14db538eed5e65c8308a9d15f9bcf96",
    INVENTORY: "ac693df595d4d350263eea039a1775c78d06088aba3a2bea8867a1d0e883c99f",
    POINTER: "695d5879d31e029290103a69101519e3681928bdb07d93b03e0594b81399b55b",
}
PLANE = {
    "repo": "makeplane/plane", "url": "https://github.com/makeplane/plane",
    "commit": "e056bbf9eb6b511cdc0a5823b1bd6922e561a485",
    "parent": "1c8a60f858d8472aa56e29994ec1c7926da2c6ce",
    "tree": "81fdcf72d051bc126da59ea7363508ff0b9273a2",
}
SELECTED = [
    "CAP-ROAD-DASHBOARD", "CAP-ROAD-DECISION-REQUEST", "CAP-ROAD-INITIATIVE-LIFECYCLE",
    "CAP-ROAD-QUARTERLY-PLAN-APPROVAL-PUBLISH", "CAP-ROAD-QUARTERLY-PLAN-PREPARATION",
    "CAP-ROAD-REPORT-API", "CAP-ROAD-SUGGESTION-INGESTION", "CAP-ROAD-SUGGESTION-TRIAGE",
]
SELECTION_SHA = "3728bb0bc65631f8a7324978da245a503b37666709e4ea74a0f934749344b48b"
PRIOR_IDS_SHA = "5e291f7c39a2fd5ce9703bf549a301d20887aa880c0fbf6b624b0ca5fd67d211"
ORIGIN_SOURCE_PATH_IDENTITY_PIN = "aab26516e73e9e2d55718968a4aeb82c977d12395e0a2e2a8d7314edee65a77b"
SOURCE_PATH_SHA = "8a66ea307a7b52cf36f19bfdbe7bfe63208caaa4678a9659e3dc15ddc7df3a82"
ORIGIN_SOURCE_MAP_IDENTITY_PIN = "bcd16c8b3e8e591042c9241ca93f88d3047b60669f9e1e4d323358814fadeefa"
SOURCE_MAP_SHA = "e5499fb8ddf734946ea7ee106e8f94ec89c9ee6745e11bc2c349b3b355ce4be5"

FILES = [
    {"path":"COPYRIGHT.txt","blob":"2a6fd91ff30475efac0cb44a8c9f6ec233577e05","bytes":137,"sha256":"ad1bf05b64103bacbc72763e6e24b4a9e0f80fbaad12630935a67783258c8a0e","loci":"L1-L3"},
    {"path":"LICENSE.txt","blob":"5087e61e24bce453516dd67d07aec21923271472","bytes":34516,"sha256":"0a4acb10f33c93841a8eedac172152096383ceaf7eb5a0aa7e303951cf204c7e","loci":"L1-L15"},
    {"path":"package.json","blob":"119b0fba09cd70c42c8fb7cfc50f5dff8b403558","bytes":1435,"sha256":"d281d4be4439fb979214d892e7d07e91a7aea3e4e1d933ec1bb8d28279d4372e","loci":"L1-L8"},
    {"path":"apps/api/plane/db/models/module.py","blob":"d660116fa83448215693725f0ddce49356b40cea","bytes":7195,"sha256":"b82d53ef77a5110d22fa4fcdc37258f4c4c4fd061a64b75c36a8dc5909af0777","loci":"L58-L113,L130-L180"},
    {"path":"apps/api/plane/app/views/module/base.py","blob":"453382552187ef6582519d1303c2b50a7ae2a27e","bytes":32115,"sha256":"b37846e0e0aa31ae9d730e7d01a8ad3b3c01d8ad9942e79d1e3fb958658ab6fe","loci":"L294-L350,L651-L750"},
    {"path":"apps/api/plane/app/views/module/archive.py","blob":"36a3ea73995b84606328f80721c0c4ae836f1e2e","bytes":22643,"sha256":"6e308c83772f9bc6a3aae99305e2193cdd1d75e29ad16b92b1cb8b0401069fb9","loci":"L544-L565"},
    {"path":"apps/api/plane/db/models/cycle.py","blob":"78ea977d911df87521d888c893f78c429e55a42a","bytes":5025,"sha256":"5cabe2d31072df817554d78b7fae00eceffcc5f53b4c9c24e4301a2c3cc5847a","loci":"L60-L117"},
    {"path":"apps/api/plane/app/views/cycle/base.py","blob":"cb10c5d02459ae94beac1bdc0dc18f31f027ae8c","bytes":40627,"sha256":"8e2c34d10a1e40bce6e048b8e292db2888aba39556ba356c8fc83f4e4114dfd4","loci":"L270-L374,L658-L782,L786-L850"},
    {"path":"apps/api/plane/db/models/intake.py","blob":"700d5d8cf7475340508eb2c64eb4aefde64c1f85","bytes":2664,"sha256":"f6856a6b18e6053f00a08e55f78d37d00b23ee1a714ba9dfd092b260b818e6ae","loci":"L38-L80"},
    {"path":"apps/api/plane/app/serializers/intake.py","blob":"4037dfe1ca0b6b49b3bf09fdc3ba491c5fc79192","bytes":4872,"sha256":"318518e8c8633346f251912013a226e9beba6236a22a6fba39ae9d40cd9f9f26","loci":"L27-L84"},
    {"path":"apps/api/plane/app/views/intake/base.py","blob":"e51376e6633ba4dcfa6e6d79ada374c9f1b62f0a","bytes":25941,"sha256":"21c1b178e18c5cbd65b0d8aa4abca36d86bd02c163baa5d1be36e0020ad556f3","loci":"L177-L330,L334-L474"},
    {"path":"apps/api/plane/app/views/analytic/project_analytics.py","blob":"064e556a2cdc429b415fd465f337e23dd5d12710","bytes":16434,"sha256":"ec97fd0255ee6ff2c80d3fb838e15a7ceb7c3c87bb088072996e71c448dea3ff","loci":"L32-L95,L97-L179,L182-L240,L317-L367"},
    {"path":"apps/api/plane/db/models/exporter.py","blob":"7abfe63afd47e3e7b37656411cc59b5ab34f4e0f","bytes":2143,"sha256":"ab1e7d69ef5dd5ec550cd622878ce4fc5f42a84a9b5bef48eb7f6894a6b17934","loci":"L24-L63"},
    {"path":"apps/api/plane/app/views/exporter/base.py","blob":"64364ecf470bef4cf80e023d26f56ea4bb95bcaa","bytes":3223,"sha256":"ddddedbedb31962499a0e26d10e421c7af7dbb6a8b6cb312c4a59a1e4d832b34","loci":"L18-L84"},
]

EVALS = [
    {"working_key":"CAP-ROAD-DASHBOARD","decision":"direct","candidate":"Plane project/cycle roadmap analytics","requirement":"Provide an authorised roadmap overview with bounded work-state counts, distributions and completion charts.","behavior":"Permission-bound project and cycle analytics return total, backlog, unstarted, started and completed work plus assignee/state/estimate distributions and completion charts.","loci":f"makeplane/plane@{PLANE['commit']} :: apps/api/plane/app/views/analytic/project_analytics.py L32-L95,L97-L179,L182-L240,L317-L367; apps/api/plane/app/views/cycle/base.py L658-L782,L786-L850","reason":"Project/cycle work-item dashboard analogue only; no Oblivion cross-initiative governance, budget, risk, Site, UI or runtime parity."},
    {"working_key":"CAP-ROAD-DECISION-REQUEST","decision":"retain","candidate":"Plane intake and module states","reason":"No decision-request aggregate with requester, authorised resolver, rationale, due date, governed outcome and resolution provenance."},
    {"working_key":"CAP-ROAD-INITIATIVE-LIFECYCLE","decision":"direct","candidate":"Plane Module lifecycle analogue","requirement":"Manage an initiative-like work container with dates, lead, members, lifecycle state and bounded archive behavior.","behavior":"Modules persist description, dates, lead, members, work and status; authorised endpoints create/update/delete, record activity and archive or restore eligible completed/cancelled modules.","loci":f"makeplane/plane@{PLANE['commit']} :: apps/api/plane/db/models/module.py L58-L113,L130-L180; apps/api/plane/app/views/module/base.py L294-L350,L651-L750; apps/api/plane/app/views/module/archive.py L544-L565","reason":"Module analogue only; no exact Oblivion initiative transitions, scoring, budget, benefit, risk, assurance, Site or decision parity."},
    {"working_key":"CAP-ROAD-QUARTERLY-PLAN-APPROVAL-PUBLISH","decision":"retain","candidate":"Plane Cycle lifecycle","reason":"No manager/executive approval, approver/time/separation, publication gate or immutable published plan revision."},
    {"working_key":"CAP-ROAD-QUARTERLY-PLAN-PREPARATION","decision":"direct","candidate":"Plane Cycle preparation analogue","requirement":"Prepare a bounded named, owned and time-boxed planning cycle with linked work and progress distributions.","behavior":"Cycles persist name, paired dates, owner/version and linked work; authorised endpoints create/update and expose progress, state and estimate analytics with bounded completed/archive mutation behavior.","loci":f"makeplane/plane@{PLANE['commit']} :: apps/api/plane/db/models/cycle.py L60-L117; apps/api/plane/app/views/cycle/base.py L270-L374,L658-L782,L786-L850","reason":"Time-boxed cycle analogue only; not intrinsically quarterly and no submission, approval, publication, budget/risk snapshot or runtime parity."},
    {"working_key":"CAP-ROAD-REPORT-API","decision":"retain","candidate":"Plane analytics and issue exporter","reason":"Generic issue export/history and work analytics do not prove a typed quarterly-plan report snapshot covering initiatives, priority, budget, risk, assurance and decisions."},
    {"working_key":"CAP-ROAD-SUGGESTION-INGESTION","decision":"direct","candidate":"Plane IntakeIssue ingestion","requirement":"Ingest a named, priority-validated suggestion into a durable triage state with source and creator evidence.","behavior":"Authorised intake validates name and priority, creates a triage-state Issue and IntakeIssue, and records source, creator, activity and description-version provenance.","loci":f"makeplane/plane@{PLANE['commit']} :: apps/api/plane/db/models/intake.py L38-L80; apps/api/plane/app/views/intake/base.py L228-L330","reason":"Single authorised intake analogue only; no scheduled adapters, stable external idempotency, Oblivion Site or runtime parity."},
    {"working_key":"CAP-ROAD-SUGGESTION-TRIAGE","decision":"direct","candidate":"Plane IntakeIssue triage","requirement":"Filter and govern suggestion transitions through pending, rejected, snoozed, accepted and duplicate outcomes.","behavior":"Permission-bound intake lists and updates durable triage statuses; acceptance validates prerequisites, moves the underlying issue from triage to the project default state, and records activity.","loci":f"makeplane/plane@{PLANE['commit']} :: apps/api/plane/db/models/intake.py L38-L80; apps/api/plane/app/serializers/intake.py L27-L84; apps/api/plane/app/views/intake/base.py L177-L226,L334-L474","reason":"Triage analogue only; no Oblivion initiative conversion, scoring, owner SLA, duplicate-merge transaction, Site or runtime parity."},
]
DIRECT = {row["working_key"] for row in EVALS if row["decision"] == "direct"}

def sha_file(path: Path) -> str: return hashlib.sha256(path.read_bytes()).hexdigest()
def sha_lines(values: list[str], sort: bool = False) -> str:
    return hashlib.sha256("\n".join(sorted(values) if sort else values).encode()).hexdigest()
def canonical(rows: list[dict[str, Any]], sort_by: str | None = None) -> str:
    if sort_by: rows = sorted(rows, key=lambda row: str(row[sort_by]))
    return hashlib.sha256("\n".join(json.dumps(row, sort_keys=True, separators=(",", ":"), ensure_ascii=False) for row in rows).encode()).hexdigest()
def load(path: Path) -> Any: return json.loads(path.read_text(encoding="utf-8"))
def save(path: Path, value: Any) -> None: path.write_text(json.dumps(value, ensure_ascii=False, indent=2)+"\n", encoding="utf-8", newline="\n")
def record(path: Path) -> dict[str, Any]: return {"path":path.relative_to(AUDIT).as_posix(),"sha256":sha_file(path),"bytes":path.stat().st_size}
def require(ok: bool, message: str) -> None:
    if not ok: raise RuntimeError(message)
def tuple_line(row: dict[str, Any]) -> str:
    return "|".join([str(row["working_key"]),str(row["status"]),";".join(sorted(set(row.get("source_units",[])))),";".join(sorted(set(row.get("evidence_loci",[]))))])
def read_csv(path: Path):
    with path.open("r",encoding="utf-8-sig",newline="") as handle:
        reader=csv.DictReader(handle); return list(reader.fieldnames or []),[dict(row) for row in reader]
def write_csv(path: Path, headers, rows):
    with path.open("w",encoding="utf-8",newline="") as handle:
        writer=csv.DictWriter(handle,fieldnames=headers,extrasaction="raise",lineterminator="\n"); writer.writeheader(); writer.writerows(rows)

if any(path.exists() for path in (ARTIFACT,SUMMARY)):
    require(all(path.exists() for path in (ARTIFACT,SUMMARY)),"Partial Wave40 output set")
    summary=load(SUMMARY); current=load(BENCHMARK)
    require(current["summary"]["eligible_total"]==496 and current["summary"]["completion_unproved"]["total"]==408,"Existing Wave40 count drift")
    for key,path in (("benchmark",BENCHMARK),("inventory",INVENTORY),("ledger",LEDGER),("matrix",MATRIX)):
        require(summary["outputs"][key]==record(path),f"Existing output drift: {key}")
    pointer=load(POINTER); require(pointer["artifacts"]["benchmark_wave40"]==record(ARTIFACT),"Wave40 pointer drift")
    print(json.dumps({"status":"already_applied","wave40":record(ARTIFACT),"summary":record(SUMMARY)},indent=2)); raise SystemExit

for path,expected in EXPECTED.items(): require(sha_file(path)==expected,f"Input drift: {path.name}")
require(sha_lines(SELECTED)==SELECTION_SHA and len(DIRECT)==5,"Selection drift")
require(sha_lines([row["path"] for row in FILES],sort=True)==SOURCE_PATH_SHA,"Source-path digest drift")
require(canonical(FILES,sort_by="path")==SOURCE_MAP_SHA,"Source-map digest drift")
proof_sha=canonical(EVALS)
benchmark,inventory=load(BENCHMARK),load(INVENTORY)
by_key={row["working_key"]:row for row in benchmark["targets"]}
require(len(benchmark["targets"])==904 and all(by_key[key]["status"]=="unproved" and not by_key[key]["completion_credit"] for key in SELECTED),"Selected state drift")

artifact={"schema_version":"1.0.0","artifact":"benchmark-target-specific-adjudication-904-wave40","generated_at":GENERATED_AT,"audited_commit":COMMIT,"read_only":True,
    "scope":"Eight current Roadmap completion-unproved targets independently reviewed; five bounded direct decisions and three retained-unproved decisions.",
    "methodology":{"family_credit_inherited":False,"runtime_boundary":"Comparator evidence changes benchmark P3 mapping completion_credit only; product, runtime, browser, representative-task, release and overall-audit completion deltas remain zero.","no_copy_rule":"Behavioural evidence only; no source, schema, UI, assets or wording is copied.","selection_preimage_rule":"Packet-order IDs joined by LF with no terminal LF, UTF-8 without BOM.","selection_sha256":SELECTION_SHA,"evaluation_proof_rule":"Packet-order evaluation objects; JSON sorted keys, compact separators; LF joined without terminal LF; UTF-8 without BOM.","evaluation_proof_sha256":proof_sha,"source_registry_rule":"The exact verified file objects embedded here, sorted by path; JSON sorted keys, compact separators; LF joined without terminal LF; UTF-8 without BOM.","source_registry_sha256":SOURCE_MAP_SHA,"origin_source_registry_identity_pin":ORIGIN_SOURCE_MAP_IDENTITY_PIN,"origin_source_registry_pin_boundary":"Retained as the research-packet identity only; the materialized registry uses the fully declared embedded objects and its freshly computed digest.","source_path_rule":"Source paths sorted lexicographically and joined by LF without terminal LF; UTF-8 without BOM.","source_path_sha256":SOURCE_PATH_SHA,"origin_source_path_identity_pin":ORIGIN_SOURCE_PATH_IDENTITY_PIN,"origin_source_path_pin_boundary":"Retained as the research-packet identity only; it is not represented as a replay of the declared path-only rule.","independent_review_verdict":"GO_5_direct_3_retained_with_bounded_roadmap_analogues"},
    "selected_target_ids":SELECTED,"input_pins":{"benchmark_before_wave":record(BENCHMARK),"pointer_before_wave":record(POINTER)},
    "repository_snapshots":{"PLANE":{**PLANE,"committer_date":"2026-08-16T18:06:30Z","subject":"chore: dump version","default_branch":"preview","repository_root_licence":"AGPL-3.0-only","edition_boundary":"Official public community paths only; Plane Cloud, commercial plans, licence-service behavior, enterprise editor paths, proprietary integrations and uncited behavior excluded."}},
    "verified_files":[{"repo":PLANE["repo"],**row} for row in FILES],"counts":{"evaluated":8,"direct":5,"retained_unproved":3,"documented_ncm":0},
    "evaluations":[dict(row,candidate_status=("verified_benchmark_direct_recommended" if row["decision"]=="direct" else "retain_unproved"),completion_credit_recommended=(row["decision"]=="direct")) for row in EVALS],
    "collision_disclosure":{"materialized_target_specific_packets":35,"prior_evaluation_occurrences":362,"unique_prior_evaluated_ids":356,"unique_prior_ids_sha256":PRIOR_IDS_SHA,"selected_target_intersection":0,"prior_plane_mentions":0,"prior_plane_behavioral_path_intersection":0,"source_reuse":"Distinct Plane loci support separately bounded Roadmap analogues; retained rows receive no inherited credit."},
    "count_delta":{"verified_benchmark_direct":5,"eligible_total":5,"completion_unproved":-5,"documented_ncm":0,"product_runtime_representative_task_overall_completion":0},
    "post_wave_totals":{"verified_benchmark_direct":385,"verified_benchmark_total":407,"documented_ncm":89,"eligible_total":496,"completion_unproved":408}}
save(ARTIFACT,artifact)

eval_by={row["working_key"]:row for row in EVALS}
for key in DIRECT:
    row=by_key[key]; ev=eval_by[key]
    row.update({"status":"verified_benchmark_direct","inheritance_method":"fresh_target_specific_wave40_direct","prior_outcome":"unproved","source_units":[f"fresh-904-wave40:{key}"],"evidence_loci":[ev["loci"]],"completion_credit":True})
status=Counter(str(row["status"]) for row in benchmark["targets"])
unproved={"ordinary":status["unproved"],"audit_assigned_stable_name":status["unproved_audit_assigned_id"],"prior_pending":status["unproved_pending"],"prior_reject":status["unproved_reject"],"source_stable_semantic_merge":status["unproved_source_stable"]}; unproved["total"]=sum(unproved.values())
require(unproved=={"ordinary":369,"audit_assigned_stable_name":11,"prior_pending":24,"prior_reject":3,"source_stable_semantic_merge":1,"total":408},"Partition drift")
benchmark.update({"generated_at":GENERATED_AT,"status":"target_specific_496_of_904_complete_not_overall_audit_completion","summary":{"verified_benchmark":{"direct":385,"strict_one_to_one_rename":22,"total":407},"documented_no_credible_match":{"direct":82,"strict_one_to_one_rename":7,"total":89},"eligible_total":496,"completion_unproved":unproved,"status_counts":dict(sorted(status.items()))},"completion_boundary":{"eligible_rows":496,"completion_unproved_rows":408,"statement":benchmark["completion_boundary"]["statement"],"formal_audit_gate":"blocked_408_of_904_targets_lack_completed_target_specific_benchmark_or_documented_no_match_outcome"}})
benchmark["checksum_algorithm"]["full_mapping_sha256"]=sha_lines([tuple_line(row) for row in benchmark["targets"]],sort=True)
benchmark["checksum_algorithm"]["eligible_subset_sha256"]=sha_lines([tuple_line(row) for row in benchmark["targets"] if row.get("completion_credit")],sort=True)
benchmark["inputs"]["target_specific_wave40"]={**record(ARTIFACT),"accepted_direct_count":5,"retained_unproved_count":3,"selected_keys_sha256":SELECTION_SHA,"evaluation_proof_sha256":proof_sha,"source_registry_sha256":SOURCE_MAP_SHA}
save(BENCHMARK,benchmark)

for key in DIRECT:
    feature=next(row for row in inventory["features"] if row["working_key"]==key)
    feature["benchmark_mapping"]={field:copy.deepcopy(by_key[key][field]) for field in ("status","completion_credit","inheritance_method","prior_outcome","source_units","evidence_loci")}
inventory["generated_at"]=GENERATED_AT
inventory["benchmark_mapping"].update({"working_manifest_eligible":496,"working_manifest_verified_benchmark":407,"working_manifest_verified_direct":385,"working_manifest_verified_rename":22,"working_manifest_documented_no_credible_match":89,"working_manifest_documented_ncm_direct":82,"working_manifest_documented_ncm_rename":7,"working_manifest_completion_unproved":408,"completion_gate_status":"496/904 final targets have evidence-preserving benchmark/NCM mapping; 408 remain completion-unproved"})
inventory["pass_status"]["P3"]="Blocked—496/904 targets mapped with evidence-preserving completion credit (407 verified benchmark, 89 documented No Credible Match); 408 unproved"
inventory["capability_denominator_status"]["benchmark_mapping"]={"eligible":496,"verified_benchmark":407,"documented_no_credible_match":89,"completion_unproved":408}
inventory["canonical_feature_register_metadata"]["benchmark_mapping"]={"verified_benchmark":407,"documented_no_credible_match":89,"completion_credit":496,"completion_unproved":408}
inventory["canonical_feature_register_metadata"]["source_artifacts"]["benchmark_mapping_sha256"]=sha_file(BENCHMARK)
save(INVENTORY,inventory)

lh,lr=read_csv(LEDGER); mh,mr=read_csv(MATRIX)
for key in DIRECT:
    ev=eval_by[key]; l=next(row for row in lr if row["feature_id"]==key); m=next(row for row in mr if row["feature_id"]==key)
    mapped="Mapped—verified benchmark with final-target completion credit; inheritance=fresh_target_specific_wave40_direct; full feature parity is not claimed."
    l["P3_status"]=mapped; l["gaps"]=l["gaps"].replace("P3 benchmark/no-match completion unproved; ",""); l["evidence_count"]=str(int(l["evidence_count"] or "0")+1)
    m.update({"benchmark_candidates":ev["candidate"],"selected_open_source_benchmark":ev["candidate"],"benchmark_url_and_sha":f"{PLANE['url']}/commit/{PLANE['commit']}","verified_behaviour":ev["behavior"],"neutral_requirements_extracted":ev["requirement"],"no_match_evidence":"","P3":mapped,"confidence":"High for the bounded comparator slice; Oblivion Site/role/direct-object/frontend/runtime parity remains unverified"})
write_csv(LEDGER,lh,lr); write_csv(MATRIX,mh,mr)

summary={"schema_version":"1.0.0","artifact":"final-904-benchmark-wave40-generation-summary","generated_at":GENERATED_AT,"audited_commit":COMMIT,"read_only":True,"inputs":{"wave40":record(ARTIFACT)},"outputs":{"benchmark":record(BENCHMARK),"inventory":record(INVENTORY),"ledger":record(LEDGER),"matrix":record(MATRIX)},"mapping_tuple_hashes":{"full":benchmark["checksum_algorithm"]["full_mapping_sha256"],"eligible":benchmark["checksum_algorithm"]["eligible_subset_sha256"]},"proof_hashes":{"selection":SELECTION_SHA,"evaluations":proof_sha,"source_registry":SOURCE_MAP_SHA,"source_paths":SOURCE_PATH_SHA,"prior_unique_ids":PRIOR_IDS_SHA},"counts":{"denominator":904,"direct":385,"rename":22,"verified":407,"ncm":89,"eligible":496,"completion_unproved":408},"validation":{"selected":8,"direct":5,"retained":3,"benchmark_p3_completion_credit_delta":5,"product_runtime_representative_task_overall_completion_delta":0,"completion_status":"BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"}}
save(SUMMARY,summary)
pointer=load(POINTER); pointer["generated_at"]=max(pointer.get("generated_at",""),GENERATED_AT)
pointer["artifacts"].update({"benchmark":record(BENCHMARK),"inventory":record(INVENTORY),"eight_pass_ledger":record(LEDGER),"benchmark_matrix":record(MATRIX),"benchmark_wave40":record(ARTIFACT),"benchmark_wave40_generation_summary":record(SUMMARY)})
pointer["completion_status"]="BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"; pointer["runtime_credit_delta"]=0; save(POINTER,pointer)
print(json.dumps({"status":"applied","wave40":record(ARTIFACT),"benchmark":record(BENCHMARK),"inventory":record(INVENTORY),"ledger":record(LEDGER),"matrix":record(MATRIX),"summary":record(SUMMARY),"active_inputs":record(POINTER)},indent=2))
