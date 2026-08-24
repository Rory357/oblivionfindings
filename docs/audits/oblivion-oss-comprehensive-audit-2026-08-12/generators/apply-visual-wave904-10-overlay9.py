#!/usr/bin/env python3
"""Record zero-promotion visual Wave 10 and exact-static overlay Wave 9."""

from __future__ import annotations

import csv
import hashlib
import json
from collections import Counter
from pathlib import Path
from typing import Any

GENERATOR = Path(__file__).resolve()
AUDIT = GENERATOR.parent.parent
ROOT = AUDIT.parent.parent.parent
SOURCE = AUDIT / "evidence" / "source"
GENERATED_AT = "2026-08-22T00:55:00+12:00"
AUDITED = "081ef198f9f992f224e8c0c9fba33df33dde40be"
MANIFEST = SOURCE / "working-capability-manifest-904.json"
INVENTORY = AUDIT / "inventory-904.json"
VISUAL = AUDIT / "05-browser-visual-coverage-matrix-904.csv"
CLASSIFIER = SOURCE / "overlay-trigger-classification.json"
FINDINGS = AUDIT / "findings.json"
POINTER = SOURCE / "canonical-audit-inputs.json"
VISUAL10 = SOURCE / "visual-final-id-adjudication-904-wave10.json"
OVERLAY9 = SOURCE / "overlay-trigger-adjudication-904-wave9.json"
SUMMARY = SOURCE / "final-904-visual-wave10-overlay9-generation-summary.json"
PINS = {
    MANIFEST:"ffca48609deab9a8938105c857786594a9a5431c31efe329ef4288da6165358f",
    INVENTORY:"80f0d84017fadd27a66256e633956cd44591b243591490c6dac4d4b81fa99f4c",
    VISUAL:"707885a83264c8e2ab3f92898578a2b20ba3e8a2ccdb3ece17156d8c0774c293",
    CLASSIFIER:"5c105d68a9a25723a1c2d94da4582bcff5c035a08cceeb6477bc602c165cc0b8",
    FINDINGS:"c3e72e15e079aaaaf3db7a5600349038532b971869a8b38e5daed2940929f584",
    POINTER:"5c34005a6ec967dfd263b5315dcb41dd37737f81498e4cd30bd53e03463ac53a",
}
VISUAL_IDS = [
    "VIS-020680","VIS-018508","VIS-018588","VIS-019475","VIS-019978","VIS-020687","VIS-020955",
    "VIS-017937","VIS-018528","VIS-018573","VIS-018604","VIS-019749","VIS-020279","VIS-020674",
    "VIS-020830","VIS-021130","VIS-018491","VIS-018507","VIS-018720","VIS-019174","VIS-019175",
    "VIS-019474","VIS-020655","VIS-020679","VIS-018527",
]
VISUAL_ORDERED_SHA = "ec9ec80d023efac36f118d2efbc5112c7d2cbee332b469263d6ece7d7c4c51ef"
VISUAL_SORTED_SHA = "0be547f2be3ced8362bf8ae3f105c7b868d2b1743fe4ee1f5de0732e6c552602"
VISUAL_PRIOR_SHA = "fcc7782d9e07db00c3876d7d7a0290f9ebc3caaa04d9a4227c1731bd5e73cf11"
OVERLAY_PRIOR_SHA = "f463ee57f0b8daf1e619426b2a9498c88f7abd68f0956edf04b82d0086dac331"
OVERLAY_SELECTION_SHA = "f4cab344b6b29225233603539a4ff5e2cf278339456f2a28a378165b57383520"

# file, line, symbol, source SHA, state loci, trigger loci, render/open/close locus, static chain
OVERLAY_ROWS = [
    ("resources/js/pages/emar/Index.tsx",1383,"MedicationReviewModal","7f2b2a717f6b09b1e593648951449ac8ccb42ba5a1d68334a1ae1d5b6c610af8","modal union and modal/setModal L404-L414; helper L421-L424","medication-review trigger L1189","component/open/close L1383-L1385","modal state -> helper/setModal -> medication-review trigger -> rendered open contract -> close"),
    ("resources/js/pages/emar/Index.tsx",1388,"CdRegisterModal","7f2b2a717f6b09b1e593648951449ac8ccb42ba5a1d68334a1ae1d5b6c610af8","modal union and modal/setModal L404-L414; helper L421-L424","controlled-register trigger L887","component/open/close L1388-L1390","modal state -> helper/setModal -> controlled-register trigger -> rendered open contract -> close"),
    ("resources/js/pages/emar/Index.tsx",1397,"StockMovementModal","7f2b2a717f6b09b1e593648951449ac8ccb42ba5a1d68334a1ae1d5b6c610af8","modal union and modal/setModal L404-L414; helper L421-L424","stock-movement trigger L1238","component/open/close L1397-L1399","modal state -> helper/setModal -> stock-movement trigger -> rendered open contract -> close"),
    ("resources/js/pages/emar/Index.tsx",1404,"ReportsModal","7f2b2a717f6b09b1e593648951449ac8ccb42ba5a1d68334a1ae1d5b6c610af8","modal union and modal/setModal L404-L414; helper L421-L424","reports trigger L686","component/open/close L1404-L1406","modal state -> helper/setModal -> reports trigger -> rendered open contract -> close"),
    ("resources/js/pages/emar/Index.tsx",1410,"AuditLogModal","7f2b2a717f6b09b1e593648951449ac8ccb42ba5a1d68334a1ae1d5b6c610af8","modal union and modal/setModal L404-L414; helper L421-L424","audit-log trigger L921","component/open/close L1410-L1412","modal state -> helper/setModal -> audit-log trigger -> rendered open contract -> close"),
    ("resources/js/pages/emar/MedicationErrors.tsx",327,"ReportErrorModal","65b4c078bbd9421ed4bead50a9f66bb4a3b59f8c6da3b68d25f1e3d73ced0d03","modal discriminated union L39; modal/setModal L76","report trigger L202","conditional render/open/close L327","modal state -> setModal report trigger -> conditional rendered dialog -> close"),
    ("resources/js/pages/emar/MedicationErrors.tsx",328,"TriageDialog","65b4c078bbd9421ed4bead50a9f66bb4a3b59f8c6da3b68d25f1e3d73ced0d03","modal discriminated union L39; modal/setModal L76","triage handlers/triggers L128,L301,L311","conditional render/open/close L328","modal state -> triage handler/setModal -> action trigger -> conditional rendered dialog -> close"),
    ("resources/js/pages/emar/MedicationErrors.tsx",329,"ReviewErrorDialog","65b4c078bbd9421ed4bead50a9f66bb4a3b59f8c6da3b68d25f1e3d73ced0d03","modal discriminated union L39; modal/setModal L76","review handler/trigger L129,L312","conditional render/open/close L329","modal state -> review handler/setModal -> action trigger -> conditional rendered dialog -> close"),
    ("resources/js/pages/emar/MedicationErrors.tsx",330,"ResolveErrorDialog","65b4c078bbd9421ed4bead50a9f66bb4a3b59f8c6da3b68d25f1e3d73ced0d03","modal discriminated union L39; modal/setModal L76","resolve handler/trigger L130,L313","conditional render/open/close L330","modal state -> resolve handler/setModal -> action trigger -> conditional rendered dialog -> close"),
    ("resources/js/pages/emar/MedicationErrors.tsx",331,"CloseErrorDialog","65b4c078bbd9421ed4bead50a9f66bb4a3b59f8c6da3b68d25f1e3d73ced0d03","modal discriminated union L39; modal/setModal L76","close handler/trigger L131,L314","conditional render/open/close L331","modal state -> close handler/setModal -> action trigger -> conditional rendered dialog -> close"),
]

def sha_file(path: Path) -> str: return hashlib.sha256(path.read_bytes()).hexdigest()
def sha_lines(values: list[str], sort: bool=False) -> str: return hashlib.sha256("\n".join(sorted(values) if sort else values).encode()).hexdigest()
def canonical(rows: list[dict[str,Any]]) -> str: return hashlib.sha256("\n".join(json.dumps(row,ensure_ascii=False,sort_keys=True,separators=(",",":")) for row in rows).encode()).hexdigest()
def load(path: Path) -> Any: return json.loads(path.read_text(encoding="utf-8"))
def save(path: Path,value: Any) -> None: path.write_text(json.dumps(value,ensure_ascii=False,indent=2)+"\n",encoding="utf-8",newline="\n")
def record(path: Path) -> dict[str,Any]: return {"path":path.relative_to(AUDIT).as_posix(),"sha256":sha_file(path),"bytes":path.stat().st_size}
def require(ok: bool,message: str) -> None:
    if not ok: raise RuntimeError(message)
def controller_path(action: str) -> tuple[str,str]:
    klass,method=action.split("@",1); return klass.replace("\\","/")+".php",method

if any(path.exists() for path in (VISUAL10,OVERLAY9,SUMMARY)):
    require(all(path.exists() for path in (VISUAL10,OVERLAY9,SUMMARY)),"Partial Wave10 output set")
    classifier=load(CLASSIFIER); require(classifier["custom_usage_layer"]["exact_trigger_resolved"]==243,"Custom count drift"); require(classifier["primitive_root_layer"]["exact_trigger_resolved"]==242 and classifier["primitive_root_layer"]["unresolved"]==235,"Primitive drift")
    pointer=load(POINTER)
    for key,path in (("visual_wave904_10",VISUAL10),("overlay_trigger_wave9",OVERLAY9),("visual_wave10_overlay9_generation_summary",SUMMARY),("overlay_trigger_classification",CLASSIFIER)): require(pointer["artifacts"][key]==record(path),f"Pointer drift: {key}")
    print(json.dumps({"status":"already_applied","visual10":record(VISUAL10),"overlay9":record(OVERLAY9),"classifier":record(CLASSIFIER),"summary":record(SUMMARY)},indent=2)); raise SystemExit
for path,expected in PINS.items(): require(sha_file(path)==expected,f"Input drift: {path}")
require(sha_lines(VISUAL_IDS)==VISUAL_ORDERED_SHA and sha_lines(VISUAL_IDS,sort=True)==VISUAL_SORTED_SHA,"Visual selection drift")
selection=[f"{file}:{line}:{symbol}" for file,line,symbol,*_ in OVERLAY_ROWS]
require(sha_lines(selection)==OVERLAY_SELECTION_SHA,"Overlay identity drift")

with VISUAL.open("r",encoding="utf-8-sig",newline="") as handle: visual_rows=list(csv.DictReader(handle))
by_visual={row["visual_id"]:row for row in visual_rows}; inventory=load(INVENTORY)
routes_by_name={}
for route in inventory["routes"]: routes_by_name.setdefault(route["name"],[]).append(route)
pages_by_file={}
for page in inventory["pages"]: pages_by_file.setdefault(page["file"],[]).append(page)
proof=[]; source_maps=[]
for visual_id in VISUAL_IDS:
    row=by_visual[visual_id]; require(not row["feature_id"] and row["feature_link_status"].startswith("unresolved") and row["pattern_type"]=="material-state-applicability",f"Visual state drift: {visual_id}")
    route_names=[x.strip() for x in row["route_name"].split("|") if x.strip()]; selected=[]; owners=[]; source_loci={}
    for name in route_names:
        matches=routes_by_name.get(name,[]); require(len(matches)==1,f"Route-name ambiguity: {visual_id} {name}"); route=matches[0]; own=route["working_canonical_feature_ids"]; require(len(own)==1,f"Non-singleton route: {visual_id} {name}"); owners.append(set(own)); path,method=controller_path(route["action"]); require((ROOT/path).exists(),f"Missing controller: {path}"); source_loci.setdefault(path,[]).append(method)
        selected.append({"route_id":route["route_id"],"method":route["method"],"name":name,"path":route["uri"],"action":route["action"],"owner":own[0]})
    intersection=sorted(set.intersection(*owners)); require(not intersection,f"Non-empty owner intersection: {visual_id}")
    page_matches=pages_by_file.get(row["component_anchor"],[]); require(len(page_matches)<=1,f"Page ambiguity: {visual_id}")
    page=None
    if page_matches:
        p=page_matches[0]; require(len(p["working_canonical_feature_ids"])!=1,f"Singleton page fallback: {visual_id}"); page={"page_id":p["page_id"],"file":p["file"],"owners":p["working_canonical_feature_ids"],"source_sha256":sha_file(ROOT/p["file"])}; source_loci.setdefault(p["file"],[]).append("page component")
    proof.append({"visual_id":visual_id,"feature_link_status":row["feature_link_status"],"state":row["state"],"pattern_type":row["pattern_type"],"component_anchor":row["component_anchor"],"routes":selected,"owner_intersection":intersection,"page_fallback":page,"verdict":"RETAIN_UNRESOLVED","runtime_credit":0})
    source_maps.append({"visual_id":visual_id,"sources":[{"path":path,"sha256":sha_file(ROOT/path),"loci":sorted(set(loci))} for path,loci in sorted(source_loci.items())]})
visual_proof_sha=canonical(proof); visual_source_sha=canonical(source_maps)
visual10={"schema_version":"1.0.0","artifact":"visual-final-id-adjudication-904-wave10","generated_at":GENERATED_AT,"audited_commit":AUDITED,"status":"independently_reviewed_zero_promotion","audit_boundary":"Audited-baseline static final-ID ownership only; current-main, browser, runtime, screenshot, usability, finding, benchmark and completion credit remain zero.","inputs":{"manifest":record(MANIFEST),"inventory":record(INVENTORY),"visual_matrix":record(VISUAL),"pointer_preimage":record(POINTER)},"selection":{"rule":"After 232 prior reviewed IDs, take the next 25 unresolved material rows with singleton owners per route, one shared controller, empty owner intersection and no singleton page fallback.","exclusion_count":232,"exclusion_sha256":VISUAL_PRIOR_SHA,"ordered_ids":VISUAL_IDS,"ordered_sha256":VISUAL_ORDERED_SHA,"sorted_sha256":VISUAL_SORTED_SHA,"proof_jsonl_sha256":visual_proof_sha,"source_map_jsonl_sha256":visual_source_sha,"serialization":"Packet-order objects, sorted JSON keys, compact separators, UTF-8/no BOM, LF join without terminal LF.","independent_review":"Exact selection, empty owner intersections, multi-owner pages and controller methods independently replayed."},"adjudications":proof,"source_map":source_maps,"count_delta":{"visual_assigned":0,"visual_unresolved":0,"material_assigned":0,"material_unresolved":0,"runtime_credit":0},"post_counts":{"visual_assigned":8168,"visual_rows":8753,"visual_unresolved":585,"material_assigned":3948,"material_rows":4312,"material_unresolved":364,"unique_assigned_targets":774}}

classifier=load(CLASSIFIER); primitive_before=json.loads(json.dumps(classifier["primitive_root_layer"])); require(primitive_before["denominator"]==477 and primitive_before["exact_trigger_resolved"]==242 and primitive_before["unresolved"]==235,"Primitive preimage drift")
by_key={(str(row["file"]),int(row["line"]),str(row["symbol"])):row for row in classifier["custom_rows"]}; overlay_proof=[]; overlay_sources=[]
for file,line,symbol,source_sha,state_loci,trigger_loci,render_locus,chain in OVERLAY_ROWS:
    key=(file,line,symbol); require(key in by_key,f"Missing overlay: {key}"); row=by_key[key]; require(row["trigger_classification"]=="internal_state_trigger_candidate" and row["trigger_resolution"]=="source_inferred_not_exactly_paired" and row.get("state_identifier") is None and row.get("setter_identifier") is None,f"Overlay preimage drift: {key}"); require(sha_file(ROOT/file)==source_sha,f"Overlay source drift: {file}")
    proof_row={"file":file,"line":line,"symbol":symbol,"prior_classification":row["trigger_classification"],"prior_resolution":row["trigger_resolution"],"state_identifier":"modal","setter_identifier":"setModal","state_loci":state_loci,"trigger_loci":trigger_loci,"render_open_close_locus":render_locus,"static_chain":chain,"verdict":"GO_EXACT_STATIC_TRIGGER_PAIRING","runtime_credit":0}; overlay_proof.append(proof_row); overlay_sources.append({"identity":f"{file}:{line}:{symbol}","source_path":file,"source_sha256":source_sha,"loci":[state_loci,trigger_loci,render_locus]})
    row["state_identifier"]="modal"; row["setter_identifier"]="setModal"; row["trigger_classification"]="same_scope_state_handler"; row["trigger_resolution"]="resolved"; row["trigger_evidence"]=[{"kind":"named_state_open_handler","file":file,"source_sha256":source_sha,"state_identifier":"modal","setter":"setModal","state_loci":state_loci,"trigger_loci":trigger_loci,"render_open_close_locus":render_locus,"static_chain":chain,"credit_boundary":"audited-baseline static pairing only"}]
overlay_proof_sha=canonical(overlay_proof); overlay_source_sha=canonical(overlay_sources)
classes=Counter(str(row["trigger_classification"]) for row in classifier["custom_rows"]); resolutions=Counter(str(row["trigger_resolution"]) for row in classifier["custom_rows"])
require(classes["same_scope_state_handler"]==224 and classes["internal_state_trigger_candidate"]==149 and classes["controlled_without_resolved_activator"]==250,"Custom class drift"); require(resolutions["resolved"]==243 and resolutions["source_inferred_not_exactly_paired"]==149 and resolutions["unresolved"]==265 and resolutions["blocked_parent_prop_not_present_at_callsite"]==2,"Custom resolution drift")
classifier["custom_usage_layer"].update({"exact_trigger_resolved":243,"source_inferred_not_exactly_paired":149,"unresolved_or_blocked":267,"classification_counts":dict(sorted(classes.items())),"resolution_counts":dict(sorted(resolutions.items()))}); require(classifier["primitive_root_layer"]==primitive_before,"Primitive layer changed")
overlay9={"schema_version":"1.0.0","artifact":"overlay-trigger-adjudication-904-wave9","generated_at":GENERATED_AT,"audited_commit":AUDITED,"status":"independently_reviewed_exact_static_pairings_runtime_unchanged","audit_boundary":"Audited-baseline static source pairing only; current-main, runtime, focus, keyboard, viewport, visibility, permission, usability and completion credit remain zero.","method":{"identity_rule":"Canonical identity is file:line:symbol using resources/js/pages/emar paths.","selection_sha256":OVERLAY_SELECTION_SHA,"exclusion_count":80,"exclusion_sha256":OVERLAY_PRIOR_SHA,"proof_jsonl_sha256":overlay_proof_sha,"source_map_jsonl_sha256":overlay_source_sha,"serialization":"Packet-order objects, sorted JSON keys, compact separators, UTF-8/no BOM, LF join without terminal LF.","independent_review_verdict":"GO_after_correcting_emar_colon_identities_and_recomputing_explicit_proofs"},"adjudications":overlay_proof,"source_map":overlay_sources,"count_delta":{"exact_trigger_resolved":10,"source_inferred_not_exactly_paired":-10,"same_scope_state_handler":10,"internal_state_trigger_candidate":-10,"runtime_credit":0},"post_counts":{"custom_denominator":659,"exact_trigger_resolved":243,"source_inferred_not_exactly_paired":149,"unresolved_or_blocked":267,"same_scope_state_handler":224,"internal_state_trigger_candidate":149,"controlled_without_resolved_activator":250,"generic_unresolved":265,"primitive_denominator":477,"primitive_exact_trigger_resolved":242,"primitive_unresolved":235}}
save(VISUAL10,visual10); save(OVERLAY9,overlay9); save(CLASSIFIER,classifier)
summary={"schema_version":"1.0.0","artifact":"final-904-visual-wave10-overlay9-generation-summary","generated_at":GENERATED_AT,"audited_commit":AUDITED,"outputs":{"visual_wave10":record(VISUAL10),"overlay_wave9":record(OVERLAY9),"overlay_classifier":record(CLASSIFIER)},"proof_hashes":{"visual":visual_proof_sha,"visual_source_map":visual_source_sha,"overlay":overlay_proof_sha,"overlay_source_map":overlay_source_sha},"counts":{"visual_assigned":8168,"visual_unresolved":585,"material_assigned":3948,"material_unresolved":364,"overlay_exact_resolved":243,"overlay_unresolved_or_blocked":267,"primitive_exact_resolved":242,"primitive_unresolved":235},"credit_boundary":{"visual_final_id_promotions":0,"overlay_static_pairings":10,"runtime_credit_delta":0,"browser_credit_delta":0,"completion_credit_delta":0}}
save(SUMMARY,summary); pointer=load(POINTER); pointer["generated_at"]=max(pointer.get("generated_at",""),GENERATED_AT); pointer["artifacts"].update({"visual_wave904_10":record(VISUAL10),"overlay_trigger_wave9":record(OVERLAY9),"overlay_trigger_classification":record(CLASSIFIER),"visual_wave10_overlay9_generation_summary":record(SUMMARY)}); pointer["completion_status"]="BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"; pointer["runtime_credit_delta"]=0; save(POINTER,pointer)
print(json.dumps({"status":"applied","visual10":record(VISUAL10),"overlay9":record(OVERLAY9),"classifier":record(CLASSIFIER),"summary":record(SUMMARY),"pointer":record(POINTER)},indent=2))
