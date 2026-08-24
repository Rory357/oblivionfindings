#!/usr/bin/env python3
"""Apply independently reviewed Wave 41 BeaconHS procedure comparator evidence."""

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
GENERATED_AT = "2026-08-22T01:10:00+12:00"
BENCHMARK = SOURCE / "benchmark-final-904-mapping.json"
INVENTORY = AUDIT / "inventory-904.json"
LEDGER = AUDIT / "02-eight-pass-coverage-ledger-904.csv"
MATRIX = AUDIT / "03-feature-to-benchmark-matrix-904.csv"
POINTER = SOURCE / "canonical-audit-inputs.json"
ARTIFACT = SOURCE / "benchmark-target-specific-adjudication-904-wave41.json"
SUMMARY = SOURCE / "final-904-benchmark-wave41-generation-summary.json"

EXPECTED = {
    BENCHMARK: "dad3d1d194c83e2556bb3fa7b2c6d86ca8a1fe9a21bbae58e73a9eda6769c37b",
    INVENTORY: "9b3efa1334e7a8db65c3380ccbb6c1206142196cbfd9e0bc69cecb7516e224f4",
    LEDGER: "1890d20026d24e0cd32be5d294ca38b463b00808cced831a804ee894ec6bf837",
    MATRIX: "1a5375e86ea75028c70de8d54ba7b43087d9ce301643e0dd5a9d5f84d8caae6a",
    POINTER: "2820b0224414ae00796710eed82ccc2e2ea112a3e4f426ca242dbac4de6c28c4",
}
BEACON = {
    "repo": "braedonsaunders/beaconhs",
    "url": "https://github.com/braedonsaunders/beaconhs",
    "commit": "7e1f3ec12708e2d612755c41ef36599c9de24a5c",
    "parent": "bf0ca8f51d47599b1606b5c61251ddfddf9ade3f",
    "tree": "a57745b337988b1259dd818c8be7fda7d235e01d",
}
SELECTED = [
    "CAP-HS-PROCEDURE-AUTHORING-EVIDENCE",
    "CAP-HS-PROCEDURE-REVIEW-APPROVAL",
    "CAP-HS-PROCEDURE-ACKNOWLEDGEMENT",
    "CAP-HS-PROCEDURE-ARCHIVE-RESTORE",
    "CAP-HS-PROCEDURE-ATTACHMENT-DOWNLOAD",
    "CAP-HS-PROCEDURE-REGISTER-EXPORT",
    "CAP-HS-WORKER-PARTICIPATION-CONSULTATION-DOWNLOAD",
    "CAP-HS-WORKER-PARTICIPATION-MINUTES-DOWNLOAD",
]
SELECTION_SHA = "7546c09bad476fcd6b209d21a2d9bd2ee518bcb221f3a8f72ba7b2e3e48b60c8"
PRIOR_IDS_SHA = "c6541df6b40669be03c76f6d67b9d576e29034f84cc1573cc4b7824059093d09"
SOURCE_PATH_SHA = "f2afe45f1a80dcdc88bbe32269885bd9795c18b50bf3b7ff7551c760fa45e453"
SOURCE_MAP_SHA = "9e85c6c7f821b44e89b70d006bcf764de7386b1e5e682fed0eb62871464a97f7"

FILES = [
    {"path":"LICENSE","blob":"be3f7b28e564e7dd05eaf59d64adba1a4065ac0e","bytes":34523,"sha256":"0d96a4ff68ad6d4b6f1f30f713b18d5184912ba8dd389f86aa7710db079abcb0","loci":"L1-L25"},
    {"path":"README.md","blob":"10f0bdaca4c54d8d91aff44c683f404a96711f94","bytes":14816,"sha256":"9eb1497e04bef29f12a54470afa3a338a04c40ac90c130b596d3cb2e415da517","loci":"L1-L80"},
    {"path":"package.json","blob":"ba32547af34e9c3d59a26fee317f2ed3c76642f2","bytes":1144,"sha256":"6fbe1569ff6010e2f73205612bafa226fa140b42250f2f56f62ac9ccfe61aa89","loci":"L1-L12"},
    {"path":"packages/db/src/schema/documents.ts","blob":"7ba2455b0739ca25f50d030aaa2936c415855658","bytes":17083,"sha256":"ddb5b12c7a3088bea235c9d5700a210ece59d8c42e0f039ec43aa3ee5977b52b","loci":"L23-L130,L132-L318"},
    {"path":"packages/db/src/schema/document-types.ts","blob":"5cbfce591db6614dd23d92de7f04ea93e2f72a64","bytes":2824,"sha256":"46e3b6e3a7894d333ba62c2996229b96f6a244e237f881d818cf1eb74a42f0a9","loci":"L13-L59"},
    {"path":"apps/web/src/app/(app)/documents/_actions.ts","blob":"12679d31d0c05572ccf4b1c9f595a954c72a24dd","bytes":12266,"sha256":"58df36f5ab06169de9c1ed87eee2a90e2eb25c26f9e80b16250687e9c83839d3","loci":"L32-L55,L61-L115"},
    {"path":"apps/web/src/app/(app)/documents/[id]/_actions.ts","blob":"8c16d9040c3c1a0633063c9793313621438f24d6","bytes":13087,"sha256":"09cbe32e022c387c11be9d5680d2a3a33923a6f4d2baecf2c447a5aa21deb8af","loci":"L158-L259,L261-L349"},
    {"path":"apps/web/src/app/(app)/documents/[id]/_ack-actions.ts","blob":"69601ff951063f6d9edbe7cbd4e7756f80d1fe8e","bytes":20251,"sha256":"528d83ea604e38a1f4f90da1c5b052d3a8cb18580283b9a181665ae00dfcd205","loci":"L96-L248"},
    {"path":"apps/web/src/app/(app)/documents/[id]/_master-actions.ts","blob":"eec8ef269c374c9770a994ac7cb73816aed0d4f7","bytes":13301,"sha256":"86bb0356b21380a34f00be18722d6a5d15faef36c62d262306499f7603db434b","loci":"L114-L225,L228-L379"},
    {"path":"apps/web/src/app/(app)/documents/[id]/page.tsx","blob":"1598d00a1cd54202eb85bb3e6cbd8ed87b007144","bytes":64445,"sha256":"4acbee3ce165b45aabec55dceac3a5bbdfce95604737a16b7742dc6c6df02198","loci":"L112-L320,L840-L856,L1120-L1165,L1202-L1219"},
    {"path":"apps/web/src/app/(app)/documents/[id]/_acknowledgments-panel.tsx","blob":"8eb542cd50ec0acde860a9b74cdd87ad3be6a339","bytes":11633,"sha256":"bce75a660e3c0511941cb3f297be628afe19899f76c54c2dd606dc5daf179bd1","loci":"L20,L46-L91,L103-L145"},
    {"path":"apps/web/src/app/(app)/documents/[id]/versions/[versionId]/download/route.ts","blob":"33caf9f12d945b058103315cc6aa69ebfd41f7fa","bytes":2846,"sha256":"0227f1cd9a5eb701321a70c36e0e6cd5fec6a87a56e13a1ff2cb3b7f25e40171","loci":"L16-L69"},
    {"path":"apps/web/src/app/(app)/documents/export.csv/route.ts","blob":"47c00c59e227777673b75370ff4902fae7510d2d","bytes":4249,"sha256":"e7d6a2eebb735489dd3319b957ba4acdd67233c8c784df46900151a9e85c57a4","loci":"L23-L120"},
    {"path":"apps/web/src/lib/csv.ts","blob":"bfbdfd1f6f24b8c550d6f6e31b0584c2d81adbc4","bytes":2432,"sha256":"04efb53d6e4a1dee45795c022949f2757dadb3574a14debccee8b001e7b4e8a4","loci":"L12-L67"},
    {"path":"packages/db/src/schema/document-management-reviews.ts","blob":"592ed3a5f9d47266fa575e52e0a7d26578b2f550","bytes":6292,"sha256":"063f1eee307be9178466b6596b27cbc827ab63231c3357cd1884b51d17d96dfe","loci":"L27-L117"},
    {"path":"apps/web/src/app/(app)/documents/management-reviews/[id]/actions.ts","blob":"2453b20b2ed09b2f5248c4ec38a9b10e549dbd17","bytes":8787,"sha256":"fe32ffa6efaa97363a133adccf0629c2a74d7ca3b00d9fe203d5063cc95d880d","loci":"L24-L250"},
]

EVALS = [
    {"working_key":"CAP-HS-PROCEDURE-AUTHORING-EVIDENCE","decision":"direct","candidate":"BeaconHS controlled-document authoring and immutable publish snapshots","requirement":"Author controlled procedures from a working master and publish immutable numbered evidence with actor, time, change and rendered-output provenance.","behavior":"Typed controlled documents support create, update and import of a working DOCX master, then produce an immutable numbered publish snapshot with actor, time, changelog, rendered attachment lifecycle and audit evidence.","loci":f"braedonsaunders/beaconhs@{BEACON['commit']} :: packages/db/src/schema/documents.ts L23-L130; packages/db/src/schema/document-types.ts L13-L59; apps/web/src/app/(app)/documents/_actions.ts L32-L55; apps/web/src/app/(app)/documents/[id]/_actions.ts L158-L349; apps/web/src/app/(app)/documents/[id]/_master-actions.ts L114-L379; apps/web/src/app/(app)/documents/[id]/page.tsx L112-L220","reason":"Bounded controlled-document analogue only; no Oblivion Site/RBAC, procedure state, approval, runtime or safety-certification parity."},
    {"working_key":"CAP-HS-PROCEDURE-REVIEW-APPROVAL","decision":"retain","candidate":"BeaconHS periodic published-document review","reason":"Periodic approved-no-change, updated or retired review against an already-published version does not prove draft submission, request-changes, maker-checker approval or approval-gated publication."},
    {"working_key":"CAP-HS-PROCEDURE-ACKNOWLEDGEMENT","decision":"direct","candidate":"BeaconHS exact-version document acknowledgement","requirement":"Bind a worker acknowledgement to the exact published procedure version with actor, time, replay control, signature/evidence and audit provenance.","behavior":"The acknowledgement panel invokes a permission-bound action that locks a published document, resolves its current published version, rejects or absorbs replay, and persists a unique person-version acknowledgement with time, signature/evidence and audit record.","loci":f"braedonsaunders/beaconhs@{BEACON['commit']} :: packages/db/src/schema/documents.ts L200-L260; apps/web/src/app/(app)/documents/[id]/_ack-actions.ts L96-L248; apps/web/src/app/(app)/documents/[id]/_acknowledgments-panel.tsx L20,L46-L91,L103-L145; apps/web/src/app/(app)/documents/[id]/page.tsx L1202-L1219","reason":"Exact-version acknowledgement analogue only; no Oblivion workforce eligibility, Site, expiry, competency, runtime or browser parity."},
    {"working_key":"CAP-HS-PROCEDURE-ARCHIVE-RESTORE","decision":"retain","candidate":"BeaconHS document archive and retire behavior","reason":"Archive/retire behavior exists, but no explicit archived-record restore transition and audit path is proved; unpublish/publish is not restore."},
    {"working_key":"CAP-HS-PROCEDURE-ATTACHMENT-DOWNLOAD","decision":"direct","candidate":"BeaconHS exact-version controlled-document download","requirement":"Download the authorised exact published procedure attachment with stable version identity, content disposition and access audit.","behavior":"Per-version PDF/DOCX links call a route that validates identifiers and permission/published visibility, resolves the exact version attachment, audits the access and streams it with Content-Disposition.","loci":f"braedonsaunders/beaconhs@{BEACON['commit']} :: apps/web/src/app/(app)/documents/[id]/page.tsx L1120-L1165; apps/web/src/app/(app)/documents/[id]/versions/[versionId]/download/route.ts L16-L69","reason":"Exact-version download analogue only; no Oblivion attachment ownership, Site/direct-object, malware, retention, runtime or browser parity."},
    {"working_key":"CAP-HS-PROCEDURE-REGISTER-EXPORT","decision":"direct","candidate":"BeaconHS controlled-document register CSV export","requirement":"Export an authorised, bounded and audit-recorded procedure register with safe CSV encoding and current review/status fields.","behavior":"The document-register export applies filters, ordering and a row cap, records an audit event, and returns title, key, category, status and review fields through a formula-injection-hardened RFC4180-style CSV utility.","loci":f"braedonsaunders/beaconhs@{BEACON['commit']} :: apps/web/src/app/(app)/documents/export.csv/route.ts L23-L120; apps/web/src/lib/csv.ts L12-L67","reason":"Bounded register-export analogue only; no Oblivion Site/role, exact field, retention, runtime or browser parity."},
    {"working_key":"CAP-HS-WORKER-PARTICIPATION-CONSULTATION-DOWNLOAD","decision":"retain","candidate":"BeaconHS management-review records","reason":"Participants, discussion, decisions, action items and reviewed-document pins do not prove a consultation-specific downloadable artifact or governed generator."},
    {"working_key":"CAP-HS-WORKER-PARTICIPATION-MINUTES-DOWNLOAD","decision":"retain","candidate":"BeaconHS management-review records","reason":"Management-review persistence does not prove a worker-participation minutes artifact, type-specific download route or exact minutes provenance; generic document download is not inherited."},
]
DIRECT = {row["working_key"] for row in EVALS if row["decision"] == "direct"}

def sha_file(path: Path) -> str: return hashlib.sha256(path.read_bytes()).hexdigest()
def sha_lines(values: list[str], sort: bool = False) -> str: return hashlib.sha256("\n".join(sorted(values) if sort else values).encode()).hexdigest()
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
    require(all(path.exists() for path in (ARTIFACT,SUMMARY)),"Partial Wave41 output set")
    summary=load(SUMMARY); current=load(BENCHMARK)
    counts=current["summary"]
    require(counts["verified_benchmark"]=={"direct":389,"strict_one_to_one_rename":22,"total":411},"Existing verified partition drift")
    require(counts["documented_no_credible_match"]["total"]==89 and counts["eligible_total"]==500 and counts["completion_unproved"]["total"]==404,"Existing Wave41 count drift")
    for key,path in (("benchmark",BENCHMARK),("inventory",INVENTORY),("ledger",LEDGER),("matrix",MATRIX)):
        require(summary["outputs"][key]==record(path),f"Existing output drift: {key}")
    pointer=load(POINTER)
    for key,path in (("benchmark",BENCHMARK),("inventory",INVENTORY),("eight_pass_ledger",LEDGER),("benchmark_matrix",MATRIX),("benchmark_wave41",ARTIFACT),("benchmark_wave41_generation_summary",SUMMARY)):
        require(pointer["artifacts"][key]==record(path),f"Wave41 pointer drift: {key}")
    print(json.dumps({"status":"already_applied","wave41":record(ARTIFACT),"summary":record(SUMMARY)},indent=2)); raise SystemExit

for path,expected in EXPECTED.items(): require(sha_file(path)==expected,f"Input drift: {path.name}")
require(sha_lines(SELECTED)==SELECTION_SHA and len(DIRECT)==4,"Selection drift")
require(sha_lines([row["path"] for row in FILES],sort=True)==SOURCE_PATH_SHA,"Source-path digest drift")
require(canonical(FILES,sort_by="path")==SOURCE_MAP_SHA,"Source-map digest drift")
proof_sha=canonical(EVALS)
benchmark,inventory=load(BENCHMARK),load(INVENTORY)
by_key={row["working_key"]:row for row in benchmark["targets"]}
require(len(benchmark["targets"])==904 and all(by_key[key]["status"]=="unproved" and not by_key[key]["completion_credit"] for key in SELECTED),"Selected state drift")

artifact={"schema_version":"1.0.0","artifact":"benchmark-target-specific-adjudication-904-wave41","generated_at":GENERATED_AT,"audited_commit":COMMIT,"read_only":True,
    "scope":"Eight current Health and Safety completion-unproved targets independently reviewed; four bounded direct decisions and four retained-unproved decisions.",
    "methodology":{"family_credit_inherited":False,"runtime_boundary":"Comparator evidence changes benchmark P3 mapping completion_credit only; product, runtime, browser, representative-task, release and overall-audit completion deltas remain zero.","no_copy_rule":"Behavioural evidence only; no source, schema, UI, assets or wording is copied.","selection_preimage_rule":"Packet-order IDs joined by LF with no terminal LF, UTF-8 without BOM.","selection_sha256":SELECTION_SHA,"evaluation_proof_rule":"Packet-order evaluation objects; JSON sorted keys, compact separators; LF joined without terminal LF; UTF-8 without BOM.","evaluation_proof_sha256":proof_sha,"source_registry_rule":"Exact verified file objects sorted by path; JSON sorted keys, compact separators; LF joined without terminal LF; UTF-8 without BOM.","source_registry_sha256":SOURCE_MAP_SHA,"source_path_rule":"Source paths sorted lexicographically and joined by LF without terminal LF; UTF-8 without BOM.","source_path_sha256":SOURCE_PATH_SHA,"independent_review_verdict":"GO_4_direct_4_retained_with_exact_path_and_AGPL_precision"},
    "selected_target_ids":SELECTED,"input_pins":{"benchmark_before_wave":record(BENCHMARK),"inventory_before_wave":record(INVENTORY),"ledger_before_wave":record(LEDGER),"matrix_before_wave":record(MATRIX),"pointer_before_wave":record(POINTER)},
    "repository_snapshots":{"BEACONHS":{**BEACON,"committer_date":"2026-08-17T19:24:26Z","subject":"chore: keep main quality gates clean","default_branch":"main","repository_root_licence":"AGPL-3.0-or-later (package declaration; root LICENSE is GNU AGPLv3 text)","edition_boundary":"Official public repository paths at the pinned commit only; no uncited service, deployment, commercial or proprietary behavior inferred."}},
    "verified_files":[{"repo":BEACON["repo"],**row} for row in FILES],"counts":{"evaluated":8,"direct":4,"retained_unproved":4,"documented_ncm":0},
    "evaluations":[dict(row,candidate_status=("verified_benchmark_direct_recommended" if row["decision"]=="direct" else "retain_unproved"),completion_credit_recommended=(row["decision"]=="direct")) for row in EVALS],
    "collision_disclosure":{"prior_target_specific_packets":36,"prior_evaluation_occurrences":370,"unique_prior_evaluated_ids":364,"unique_prior_ids_sha256":PRIOR_IDS_SHA,"selected_target_intersection":0,"repository_reuse":"BeaconHS was previously inspected at an older commit.","exact_behavioral_path_intersection":0,"source_reuse":"All selected target IDs and exact proposed behavior paths are fresh; retained rows receive no family or generic-document inheritance."},
    "count_delta":{"verified_benchmark_direct":4,"eligible_total":4,"completion_unproved":-4,"documented_ncm":0,"product_runtime_representative_task_overall_completion":0},
    "post_wave_totals":{"verified_benchmark_direct":389,"verified_benchmark_total":411,"documented_ncm":89,"eligible_total":500,"completion_unproved":404}}
save(ARTIFACT,artifact)

eval_by={row["working_key"]:row for row in EVALS}
for key in DIRECT:
    row=by_key[key]; ev=eval_by[key]
    row.update({"status":"verified_benchmark_direct","inheritance_method":"fresh_target_specific_wave41_direct","prior_outcome":"unproved","source_units":[f"fresh-904-wave41:{key}"],"evidence_loci":[ev["loci"]],"completion_credit":True})
status=Counter(str(row["status"]) for row in benchmark["targets"])
unproved={"ordinary":status["unproved"],"audit_assigned_stable_name":status["unproved_audit_assigned_id"],"prior_pending":status["unproved_pending"],"prior_reject":status["unproved_reject"],"source_stable_semantic_merge":status["unproved_source_stable"]}; unproved["total"]=sum(unproved.values())
require(unproved=={"ordinary":365,"audit_assigned_stable_name":11,"prior_pending":24,"prior_reject":3,"source_stable_semantic_merge":1,"total":404},"Partition drift")
benchmark.update({"generated_at":GENERATED_AT,"status":"target_specific_500_of_904_complete_not_overall_audit_completion","summary":{"verified_benchmark":{"direct":389,"strict_one_to_one_rename":22,"total":411},"documented_no_credible_match":{"direct":82,"strict_one_to_one_rename":7,"total":89},"eligible_total":500,"completion_unproved":unproved,"status_counts":dict(sorted(status.items()))},"completion_boundary":{"eligible_rows":500,"completion_unproved_rows":404,"statement":benchmark["completion_boundary"]["statement"],"formal_audit_gate":"blocked_404_of_904_targets_lack_completed_target_specific_benchmark_or_documented_no_match_outcome"}})
benchmark["checksum_algorithm"]["full_mapping_sha256"]=sha_lines([tuple_line(row) for row in benchmark["targets"]],sort=True)
benchmark["checksum_algorithm"]["eligible_subset_sha256"]=sha_lines([tuple_line(row) for row in benchmark["targets"] if row.get("completion_credit")],sort=True)
benchmark["inputs"]["target_specific_wave41"]={**record(ARTIFACT),"accepted_direct_count":4,"retained_unproved_count":4,"selected_keys_sha256":SELECTION_SHA,"evaluation_proof_sha256":proof_sha,"source_registry_sha256":SOURCE_MAP_SHA}
save(BENCHMARK,benchmark)

for key in DIRECT:
    feature=next(row for row in inventory["features"] if row["working_key"]==key)
    feature["benchmark_mapping"]={field:copy.deepcopy(by_key[key][field]) for field in ("status","completion_credit","inheritance_method","prior_outcome","source_units","evidence_loci")}
inventory["generated_at"]=GENERATED_AT
inventory["benchmark_mapping"].update({"working_manifest_eligible":500,"working_manifest_verified_benchmark":411,"working_manifest_verified_direct":389,"working_manifest_verified_rename":22,"working_manifest_documented_no_credible_match":89,"working_manifest_documented_ncm_direct":82,"working_manifest_documented_ncm_rename":7,"working_manifest_completion_unproved":404,"completion_gate_status":"500/904 final targets have evidence-preserving benchmark/NCM mapping; 404 remain completion-unproved"})
inventory["pass_status"]["P3"]="Blocked—500/904 targets mapped with evidence-preserving completion credit (411 verified benchmark, 89 documented No Credible Match); 404 unproved"
inventory["capability_denominator_status"]["benchmark_mapping"]={"eligible":500,"verified_benchmark":411,"documented_no_credible_match":89,"completion_unproved":404}
inventory["canonical_feature_register_metadata"]["benchmark_mapping"]={"verified_benchmark":411,"documented_no_credible_match":89,"completion_credit":500,"completion_unproved":404}
inventory["canonical_feature_register_metadata"]["source_artifacts"]["benchmark_mapping_sha256"]=sha_file(BENCHMARK)
save(INVENTORY,inventory)

lh,lr=read_csv(LEDGER); mh,mr=read_csv(MATRIX)
for key in DIRECT:
    ev=eval_by[key]; l=next(row for row in lr if row["feature_id"]==key); m=next(row for row in mr if row["feature_id"]==key)
    mapped="Mapped—verified benchmark with final-target completion credit; inheritance=fresh_target_specific_wave41_direct; full feature parity is not claimed."
    l["P3_status"]=mapped; l["gaps"]=l["gaps"].replace("P3 benchmark/no-match completion unproved; ",""); l["evidence_count"]=str(int(l["evidence_count"] or "0")+1)
    m.update({"benchmark_candidates":ev["candidate"],"selected_open_source_benchmark":ev["candidate"],"benchmark_url_and_sha":f"{BEACON['url']}/commit/{BEACON['commit']}","verified_behaviour":ev["behavior"],"neutral_requirements_extracted":ev["requirement"],"no_match_evidence":"","P3":mapped,"confidence":"High for the bounded comparator slice; Oblivion Site/role/direct-object/frontend/runtime parity remains unverified"})
write_csv(LEDGER,lh,lr); write_csv(MATRIX,mh,mr)

summary={"schema_version":"1.0.0","artifact":"final-904-benchmark-wave41-generation-summary","generated_at":GENERATED_AT,"audited_commit":COMMIT,"read_only":True,"inputs":{"wave41":record(ARTIFACT)},"outputs":{"benchmark":record(BENCHMARK),"inventory":record(INVENTORY),"ledger":record(LEDGER),"matrix":record(MATRIX)},"mapping_tuple_hashes":{"full":benchmark["checksum_algorithm"]["full_mapping_sha256"],"eligible":benchmark["checksum_algorithm"]["eligible_subset_sha256"]},"proof_hashes":{"selection":SELECTION_SHA,"evaluations":proof_sha,"source_registry":SOURCE_MAP_SHA,"source_paths":SOURCE_PATH_SHA,"prior_unique_ids":PRIOR_IDS_SHA},"counts":{"denominator":904,"direct":389,"rename":22,"verified":411,"ncm":89,"eligible":500,"completion_unproved":404},"downstream_refresh":{"required_order":["generators/refresh-current-904-summaries.py","generators/refresh-audit-dashboard-data.py","generators/finalize-current-904-validation.py"],"expected_boundary":{"denominator":904,"direct":389,"rename":22,"verified":411,"ncm":89,"eligible":500,"completion_unproved":404},"credit_boundary":"No product, runtime, browser, representative-task, release or overall-audit completion credit."},"validation":{"selected":8,"direct":4,"retained":4,"benchmark_p3_completion_credit_delta":4,"product_runtime_representative_task_overall_completion_delta":0,"completion_status":"BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"}}
save(SUMMARY,summary)
pointer=load(POINTER); pointer["generated_at"]=max(pointer.get("generated_at",""),GENERATED_AT)
pointer["artifacts"].update({"benchmark":record(BENCHMARK),"inventory":record(INVENTORY),"eight_pass_ledger":record(LEDGER),"benchmark_matrix":record(MATRIX),"benchmark_wave41":record(ARTIFACT),"benchmark_wave41_generation_summary":record(SUMMARY)})
pointer["completion_status"]="BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"; pointer["runtime_credit_delta"]=0; save(POINTER,pointer)
print(json.dumps({"status":"applied","wave41":record(ARTIFACT),"benchmark":record(BENCHMARK),"inventory":record(INVENTORY),"ledger":record(LEDGER),"matrix":record(MATRIX),"summary":record(SUMMARY),"active_inputs":record(POINTER)},indent=2))
