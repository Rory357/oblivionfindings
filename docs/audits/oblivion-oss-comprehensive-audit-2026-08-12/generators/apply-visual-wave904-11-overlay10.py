#!/usr/bin/env python3
"""Record zero-promotion visual Wave 11 and exact-static overlay Wave 10."""

from __future__ import annotations

import csv
import hashlib
import json
import re
import subprocess
import sys
from collections import Counter
from pathlib import Path
from typing import Any


GENERATOR = Path(__file__).resolve()
AUDIT = GENERATOR.parent.parent
ROOT = AUDIT.parent.parent.parent
SOURCE = AUDIT / "evidence" / "source"
GENERATED_AT = "2026-08-22T01:40:00+12:00"
AUDITED = "081ef198f9f992f224e8c0c9fba33df33dde40be"
MANIFEST = SOURCE / "working-capability-manifest-904.json"
INVENTORY = AUDIT / "inventory-904.json"
VISUAL = AUDIT / "05-browser-visual-coverage-matrix-904.csv"
CLASSIFIER = SOURCE / "overlay-trigger-classification.json"
FINDINGS = AUDIT / "findings.json"
POINTER = SOURCE / "canonical-audit-inputs.json"
VISUAL11 = SOURCE / "visual-final-id-adjudication-904-wave11.json"
OVERLAY10 = SOURCE / "overlay-trigger-adjudication-904-wave10.json"
SUMMARY = SOURCE / "final-904-visual-wave11-overlay10-generation-summary.json"
WAVE1 = SOURCE / "visual-final-id-ownership-904-wave1.json"
PRIOR_VISUAL_WAVES = [SOURCE / f"visual-final-id-adjudication-904-wave{wave:02d}.json" for wave in range(3, 11)]
PRIOR_OVERLAY_WAVES = [SOURCE / f"overlay-trigger-adjudication-904-wave{wave}.json" for wave in range(1, 10)]

PINS = {
    MANIFEST:"b9c1cf28e53e26df91fe772d91924beb56f56c1b9f3c68ddb320134bf148aa10",
    INVENTORY:"e193306f8b748485ae0e4d7e1cb9d5da9f8c6f652b8ea9911b9a87b9d954b5d8",
    VISUAL:"0345bf86094320e154e76c01153c2242b097fcdeeab3d4661033fa2ae4bb94ef",
    CLASSIFIER:"79b605ee62810497ccc955fca4f2dc523870da22891488a39a4af4ef4cb9da36",
    FINDINGS:"b5b2db96be0aced376178fe778076b231c25e5ecb12b9c084e422bf61afdfe7c",
    POINTER:"230f32dc9df1d0d3c972ea755b3eb6acb3d4d938079140e7efddbc735e3a917c",
}

VISUAL_IDS = [
    "VIS-018558","VIS-018605","VIS-018606","VIS-018719","VIS-018587","VIS-020673","VIS-018572",
    "VIS-019966","VIS-020895","VIS-016187","VIS-019977","VIS-015518","VIS-017246","VIS-017247",
    "VIS-017474","VIS-019505","VIS-020718","VIS-020104","VIS-015706","VIS-015794","VIS-019644",
    "VIS-019708","VIS-020879","VIS-018637","VIS-018636",
]
TIER_A = set(VISUAL_IDS[:7])
WAVE1_COUNT = 32
WAVE1_SHA = "bbec2abef290aaaccce4960da49c9dac2bd0fde12be0d02590c73dbfb6140b41"
WAVE2_ELIGIBLE_COUNT = 30
WAVE2_COUNT = 25
WAVE2_SHA = "3000af48cd790aebd2c33d7df5ea61e8edd151d2c5e7cfb2e1540b4522dea171"
VISUAL_PRIOR_COUNT = 257
VISUAL_PRIOR_SHA = "49b560ad3abdaa2b4962985fce7381d1d6fec8e340672eca1c942bfb4e76a3d1"
VISUAL_ORDERED_SHA = "80e32ed08db43cda77a27aac509626f93494d8302f867fc460bb6205fcee73e2"
VISUAL_SORTED_SHA = "8545c5cf544dbca63549250caa434c10c61daa3b461f6ba9ddfde89922d8438f"
TIER_B_OMITTED_IDS = ["VIS-014326","VIS-018651","VIS-017252","VIS-017253","VIS-020720","VIS-020721"]
TIER_B_OMITTED_SHA = "5083aae4bca78cb6db1a8098b21f5a954fa63111c2fcfd74d2b143eb5f4f168b"
OVERLAY_PRIOR_COUNT = 90
OVERLAY_PRIOR_SHA = "c3aa679aee30beb63b1eca2e9fdf36ef67ff429edd43ffacc385d8acb8793892"
OVERLAY_SELECTION_SHA = "57d3d757d7ffc01a8564d4d374c476d5bf662bcd2d724da3cab372d508ff0f09"

# file, line, symbol, source SHA, prior class, prior resolution, state loci, trigger loci, render locus, chain
OVERLAY_ROWS = [
    ("resources/js/pages/emar/Medications.tsx",459,"AddMedicationDialog","ed19e615224a43e8f821e0f6f583a72451c25dbf76be6358439f2b1e968fbe8f","internal_state_trigger_candidate","source_inferred_not_exactly_paired","Modal union L22-L27; modal/setModal L109","add triggers L239,L375","conditional render/open/close L459","modal state -> setModal add trigger -> conditional rendered dialog -> close"),
    ("resources/js/pages/emar/Medications.tsx",460,"ImportCsvDialog","ed19e615224a43e8f821e0f6f583a72451c25dbf76be6358439f2b1e968fbe8f","controlled_without_resolved_activator","unresolved","Modal union L22-L27; modal/setModal L109","import trigger L243","conditional render/open/close L460","modal state -> setModal import trigger -> conditional rendered dialog -> close"),
    ("resources/js/pages/emar/Medications.tsx",461,"InteractionsDialog","ed19e615224a43e8f821e0f6f583a72451c25dbf76be6358439f2b1e968fbe8f","controlled_without_resolved_activator","unresolved","Modal union L22-L27; modal/setModal L109","interactions trigger L355","conditional render/open/close L461","modal state -> setModal interactions trigger -> conditional rendered dialog -> close"),
    ("resources/js/pages/emar/Medications.tsx",462,"EditMedicationDialog","ed19e615224a43e8f821e0f6f583a72451c25dbf76be6358439f2b1e968fbe8f","controlled_without_resolved_activator","unresolved","Modal union L22-L27; modal/setModal L109","edit triggers L188,L470","conditional render/open/close L462","modal state -> setModal edit trigger -> conditional rendered dialog -> close"),
    ("resources/js/pages/emar/Medications.tsx",463,"DiscontinueDialog","ed19e615224a43e8f821e0f6f583a72451c25dbf76be6358439f2b1e968fbe8f","controlled_without_resolved_activator","unresolved","Modal union L22-L27; modal/setModal L109","discontinue triggers L202,L471","conditional render/open/close L463","modal state -> setModal discontinue trigger -> conditional rendered dialog -> close"),
    ("resources/js/pages/emar/Medications.tsx",464,"RejectOrderDialog","ed19e615224a43e8f821e0f6f583a72451c25dbf76be6358439f2b1e968fbe8f","controlled_without_resolved_activator","unresolved","Modal union L22-L27; modal/setModal L109","reject triggers L192,L472","conditional render/open/close L464","modal state -> setModal reject trigger -> conditional rendered dialog -> close"),
    ("resources/js/pages/emar/Medications.tsx",466,"MedicationDetailDialog","ed19e615224a43e8f821e0f6f583a72451c25dbf76be6358439f2b1e968fbe8f","internal_state_trigger_candidate","source_inferred_not_exactly_paired","Modal union L22-L27; modal/setModal L109","detail triggers L187,L400,L404; nested transitions L470-L472","guarded render/open/close L465-L474","modal state -> setModal detail trigger -> guarded rendered dialog -> close"),
    ("resources/js/pages/emar/Prescriptions.tsx",549,"NewOrderDialog","97180849f60571e40881b777724c749bf7ea6d11a9366057750a6c0cd976e7d1","internal_state_trigger_candidate","source_inferred_not_exactly_paired","Modal union L24-L28; modal/setModal L115","new-order trigger L270","conditional render/open/close L549","modal state -> setModal order trigger -> conditional rendered dialog -> close"),
    ("resources/js/pages/emar/Prescriptions.tsx",550,"CovertDialog","97180849f60571e40881b777724c749bf7ea6d11a9366057750a6c0cd976e7d1","internal_state_trigger_candidate","source_inferred_not_exactly_paired","Modal union L24-L28; modal/setModal L115","covert triggers L274,L501","conditional render/open/close L550","modal state -> setModal covert trigger -> conditional rendered dialog -> close"),
    ("resources/js/pages/emar/Prescriptions.tsx",552,"OrderDetailDialog","97180849f60571e40881b777724c749bf7ea6d11a9366057750a6c0cd976e7d1","internal_state_trigger_candidate","source_inferred_not_exactly_paired","Modal union L24-L28; modal/setModal L115; openDetail L190","detail triggers L203,L369,L455,L478,L532","guarded render/open/close L551-L560","modal state -> openDetail/setModal trigger -> guarded rendered dialog -> close"),
]


def sha_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def sha_lines(values: list[str], sort: bool = False) -> str:
    return hashlib.sha256("\n".join(sorted(values) if sort else values).encode()).hexdigest()


def canonical(rows: list[dict[str, Any]]) -> str:
    return hashlib.sha256("\n".join(json.dumps(row, ensure_ascii=False, sort_keys=True, separators=(",", ":")) for row in rows).encode()).hexdigest()


def load(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def save(path: Path, value: Any) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")


def record(path: Path) -> dict[str, Any]:
    return {"path": path.relative_to(AUDIT).as_posix(), "sha256": sha_file(path), "bytes": path.stat().st_size}


def require(ok: bool, message: str) -> None:
    if not ok:
        raise RuntimeError(message)


def collect_visual_ids(value: Any, output: set[str]) -> None:
    if isinstance(value, str) and re.fullmatch(r"VIS-[0-9]+", value):
        output.add(value)
    elif isinstance(value, dict):
        for nested in value.values():
            collect_visual_ids(nested, output)
    elif isinstance(value, list):
        for nested in value:
            collect_visual_ids(nested, output)


def controller_path(action: str) -> tuple[str, str]:
    klass, method = action.split("@", 1)
    path = klass.replace("\\", "/") + ".php"
    if path.startswith("App/"):
        path = "app/" + path[4:]
    return path, method


require(subprocess.run(["git", "rev-parse", "HEAD"], check=True, stdout=subprocess.PIPE, text=True).stdout.strip() == AUDITED, "Audited checkout drift")

outputs_exist = any(path.exists() for path in (VISUAL11, OVERLAY10, SUMMARY))
if outputs_exist:
    require(all(path.exists() for path in (VISUAL11, OVERLAY10, SUMMARY)), "Partial Wave11 output set")

for path in (MANIFEST, INVENTORY, VISUAL, FINDINGS):
    require(sha_file(path) == PINS[path], f"Stable input drift: {path}")
require(sha_lines(VISUAL_IDS) == VISUAL_ORDERED_SHA and sha_lines(VISUAL_IDS, sort=True) == VISUAL_SORTED_SHA, "Visual selection drift")
overlay_identities = [f"{file}:{line}:{symbol}" for file,line,symbol,*_ in OVERLAY_ROWS]
require(sha_lines(overlay_identities) == OVERLAY_SELECTION_SHA, "Overlay selection drift")

with VISUAL.open("r", encoding="utf-8-sig", newline="") as handle:
    visual_rows = list(csv.DictReader(handle))
by_visual = {row["visual_id"]: row for row in visual_rows}
inventory = load(INVENTORY)
routes_by_name: dict[str, list[dict[str, Any]]] = {}
for route in inventory["routes"]:
    routes_by_name.setdefault(route["name"], []).append(route)
pages_by_file: dict[str, list[dict[str, Any]]] = {}
for page in inventory["pages"]:
    pages_by_file.setdefault(page["file"], []).append(page)


def route_context(row: dict[str, str], *, require_two: bool = False, require_singleton: bool = False, require_controller_anchor: bool = False) -> dict[str, Any] | None:
    route_names = [value.strip() for value in row.get("route_name", "").split("|") if value.strip()]
    if not route_names or (require_two and len(route_names) != 2):
        return None
    matches = [routes_by_name.get(name, []) for name in route_names]
    if any(len(group) != 1 for group in matches):
        return None
    routes = [group[0] for group in matches]
    owner_sets = [set(route.get("working_canonical_feature_ids", [])) for route in routes]
    if any(not owners for owners in owner_sets) or (require_singleton and any(len(owners) != 1 for owners in owner_sets)):
        return None
    if any("@" not in str(route.get("action", "")) for route in routes):
        return None
    controller_methods = [controller_path(str(route["action"])) for route in routes]
    controller_paths = {path for path, _ in controller_methods}
    if len(controller_paths) != 1:
        return None
    controller = next(iter(controller_paths))
    if require_controller_anchor and row.get("component_anchor") != controller:
        return None
    controller_file = ROOT / controller
    if not controller_file.exists():
        return None
    source_text = controller_file.read_text(encoding="utf-8", errors="ignore")
    if any(not re.search(rf"function\s+{re.escape(method)}\s*\(", source_text) for _, method in controller_methods):
        return None
    page_matches = pages_by_file.get(row.get("component_anchor", ""), [])
    if len(page_matches) > 1 or (page_matches and len(page_matches[0].get("working_canonical_feature_ids", [])) == 1):
        return None
    return {
        "route_count": len(routes),
        "owner_sets": owner_sets,
        "intersection": set.intersection(*owner_sets),
        "union": set.union(*owner_sets),
        "max_owner_cardinality": max(len(owners) for owners in owner_sets),
    }


wave1_ids: set[str] = set()
collect_visual_ids(load(WAVE1), wave1_ids)
require(len(wave1_ids) == WAVE1_COUNT and sha_lines(list(wave1_ids), sort=True) == WAVE1_SHA, "Wave1 exclusion reconstruction drift")

wave2_eligible: list[str] = []
for row in visual_rows:
    if row["visual_id"] in wave1_ids or row["feature_id"] or not row["feature_link_status"].startswith("unresolved") or row["pattern_type"] != "material-state-applicability":
        continue
    context = route_context(row, require_two=True, require_singleton=True, require_controller_anchor=True)
    if context is not None and not context["intersection"] and len(context["union"]) == 2:
        wave2_eligible.append(row["visual_id"])
wave2_eligible.sort()
require(len(wave2_eligible) == WAVE2_ELIGIBLE_COUNT, "Wave2 eligible-pool drift")
wave2_ids = wave2_eligible[:WAVE2_COUNT]
require(sha_lines(wave2_ids) == WAVE2_SHA, "Wave2 selection reconstruction drift")

prior_visual_ids = set(wave1_ids) | set(wave2_ids)
prior_wave_reconstruction: list[dict[str, Any]] = []
for path in PRIOR_VISUAL_WAVES:
    ids: set[str] = set()
    collect_visual_ids(load(path), ids)
    require(len(ids) == 25, f"Prior visual wave count drift: {path.name}")
    require(not (prior_visual_ids & ids), f"Prior visual wave overlap: {path.name}")
    prior_visual_ids.update(ids)
    prior_wave_reconstruction.append({"path":path.relative_to(AUDIT).as_posix(),"count":len(ids),"sha256":sha_lines(list(ids), sort=True)})
require(len(prior_visual_ids) == VISUAL_PRIOR_COUNT and sha_lines(list(prior_visual_ids), sort=True) == VISUAL_PRIOR_SHA, "Prior visual exclusion reconstruction drift")
require(not (prior_visual_ids & set(VISUAL_IDS)), "Wave11 selection overlaps prior exclusions")

tier_a_candidates: list[tuple[int, str, dict[str, Any]]] = []
tier_b_candidates: list[tuple[int, int, int, str, dict[str, Any]]] = []
for row in visual_rows:
    if row["visual_id"] in prior_visual_ids or row["feature_id"] or not row["feature_link_status"].startswith("unresolved") or row["pattern_type"] != "material-state-applicability":
        continue
    context = route_context(row)
    if context is None or len(context["intersection"]) == 1:
        continue
    if all(len(owners) == 1 for owners in context["owner_sets"]) and not context["intersection"]:
        tier_a_candidates.append((context["route_count"], row["visual_id"], context))
    else:
        tier_b_candidates.append((len(context["union"]), context["max_owner_cardinality"], context["route_count"], row["visual_id"], context))
tier_a_candidates.sort(key=lambda item: (item[0], item[1]))
tier_b_candidates.sort(key=lambda item: (item[0], item[1], item[2], item[3]))
require([item[1] for item in tier_a_candidates] == VISUAL_IDS[:7], "Tier A candidate/rank drift")
require([item[3] for item in tier_b_candidates[:18]] == VISUAL_IDS[7:], "Tier B candidate/rank drift")
tier_b_omitted = [item[3] for item in tier_b_candidates[18:]]
require(tier_b_omitted == TIER_B_OMITTED_IDS and sha_lines(tier_b_omitted) == TIER_B_OMITTED_SHA, "Tier B omitted-boundary drift")
candidate_rank_proof = [
    {"visual_id":visual_id,"tier":"A","rank":rank,"route_count":route_count,"route_owner_union_cardinality":len(context["union"]),"max_per_route_owner_cardinality":context["max_owner_cardinality"],"selected":True}
    for rank,(route_count,visual_id,context) in enumerate(tier_a_candidates, start=1)
] + [
    {"visual_id":visual_id,"tier":"B","rank":rank,"route_count":route_count,"route_owner_union_cardinality":union_count,"max_per_route_owner_cardinality":max_owner,"selected":rank <= 18}
    for rank,(union_count,max_owner,route_count,visual_id,_) in enumerate(tier_b_candidates, start=1)
]
candidate_rank_sha = canonical(candidate_rank_proof)

prior_overlay_identities: set[str] = set()
prior_overlay_reconstruction: list[dict[str, Any]] = []
for path in PRIOR_OVERLAY_WAVES:
    artifact = load(path)
    identities = {f"{row['file']}:{row['line']}:{row['symbol']}" for row in artifact["adjudications"]}
    require(not (prior_overlay_identities & identities), f"Prior overlay overlap: {path.name}")
    prior_overlay_identities.update(identities)
    prior_overlay_reconstruction.append({"path":path.relative_to(AUDIT).as_posix(),"count":len(identities),"sha256":sha_lines(list(identities), sort=True)})
require(len(prior_overlay_identities) == OVERLAY_PRIOR_COUNT and sha_lines(list(prior_overlay_identities), sort=True) == OVERLAY_PRIOR_SHA, "Prior overlay exclusion reconstruction drift")
require(not (prior_overlay_identities & set(overlay_identities)), "Overlay10 selection overlaps prior exclusions")

if outputs_exist:
    classifier = load(CLASSIFIER)
    require(classifier["custom_usage_layer"]["exact_trigger_resolved"] == 253, "Custom count drift")
    require(classifier["custom_usage_layer"]["source_inferred_not_exactly_paired"] == 144 and classifier["custom_usage_layer"]["unresolved_or_blocked"] == 262, "Custom partition drift")
    require(classifier["primitive_root_layer"]["exact_trigger_resolved"] == 242 and classifier["primitive_root_layer"]["unresolved"] == 235, "Primitive drift")
    summary = load(SUMMARY)
    require(summary["proof_hashes"]["visual_candidate_rank"] == candidate_rank_sha, "Candidate-rank proof drift")
    for key, path in (("visual_wave11",VISUAL11),("overlay_wave10",OVERLAY10),("overlay_classifier",CLASSIFIER)):
        require(summary["outputs"][key] == record(path), f"Summary output drift: {key}")
    pointer = load(POINTER)
    for key, path in (("visual_wave904_11",VISUAL11),("overlay_trigger_wave10",OVERLAY10),("visual_wave11_overlay10_generation_summary",SUMMARY),("overlay_trigger_classification",CLASSIFIER)):
        require(pointer["artifacts"][key] == record(path), f"Pointer drift: {key}")
    require(pointer["completion_status"] == "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE", "Pointer completion-status drift")
    require(pointer.get("runtime_credit_delta") == 0, "Pointer runtime-credit drift")
    print(json.dumps({"status":"already_applied","visual11":record(VISUAL11),"overlay10":record(OVERLAY10),"classifier":record(CLASSIFIER),"summary":record(SUMMARY)}, indent=2))
    raise SystemExit

for path in (CLASSIFIER, POINTER):
    require(sha_file(path) == PINS[path], f"Mutable preimage drift: {path}")

proof: list[dict[str, Any]] = []
source_maps: list[dict[str, Any]] = []
for visual_id in VISUAL_IDS:
    row = by_visual[visual_id]
    require(not row["feature_id"] and row["feature_link_status"].startswith("unresolved") and row["pattern_type"] == "material-state-applicability", f"Visual state drift: {visual_id}")
    route_names = [value.strip() for value in row["route_name"].split("|") if value.strip()]
    selected: list[dict[str, Any]] = []
    owner_sets: list[set[str]] = []
    source_loci: dict[str, list[str]] = {}
    for name in route_names:
        matches = routes_by_name.get(name, [])
        require(len(matches) == 1, f"Route-name ambiguity: {visual_id} {name}")
        route = matches[0]
        owners = list(route["working_canonical_feature_ids"])
        require(owners, f"Empty route owner set: {visual_id} {name}")
        path, method = controller_path(route["action"])
        require((ROOT / path).exists(), f"Missing controller: {path}")
        source_text = (ROOT / path).read_text(encoding="utf-8", errors="ignore")
        require(re.search(rf"function\s+{re.escape(method)}\s*\(", source_text), f"Missing method: {path}@{method}")
        source_loci.setdefault(path, []).append(method)
        owner_sets.append(set(owners))
        selected.append({"route_id":route["route_id"],"method":route["method"],"name":name,"path":route["uri"],"action":route["action"],"controller_locus":f"{path}@{method}","owners":owners})
    intersection = sorted(set.intersection(*owner_sets))
    union = sorted(set.union(*owner_sets))
    page_matches = pages_by_file.get(row["component_anchor"], [])
    require(len(page_matches) <= 1, f"Page ambiguity: {visual_id}")
    page = None
    if page_matches:
        candidate = page_matches[0]
        require(len(candidate["working_canonical_feature_ids"]) != 1, f"Singleton page fallback: {visual_id}")
        page = {"page_id":candidate["page_id"],"file":candidate["file"],"owners":candidate["working_canonical_feature_ids"],"source_sha256":sha_file(ROOT / candidate["file"])}
        source_loci.setdefault(candidate["file"], []).append("page component")
    tier = "A_singleton_route_owners_empty_intersection" if visual_id in TIER_A else "B_nonempty_route_owner_sets_nonunique_intersection"
    if visual_id in TIER_A:
        require(all(len(owners) == 1 for owners in owner_sets) and not intersection, f"Tier A drift: {visual_id}")
    else:
        require(len(intersection) != 1, f"Tier B uniquely assignable drift: {visual_id}")
    proof.append({"visual_id":visual_id,"feature_link_status":row["feature_link_status"],"state":row["state"],"pattern_type":row["pattern_type"],"component_anchor":row["component_anchor"],"selection_tier":tier,"routes":selected,"route_owner_intersection":intersection,"route_owner_union":union,"page_context":page,"verdict":"RETAIN_UNRESOLVED","reason":"Exact route owners do not yield one common final ID and no singleton exact page fallback exists.","runtime_credit":0})
    source_maps.append({"visual_id":visual_id,"sources":[{"path":path,"sha256":sha_file(ROOT/path),"loci":sorted(set(loci))} for path,loci in sorted(source_loci.items())]})

visual_proof_sha = canonical(proof)
visual_source_sha = canonical(source_maps)
visual11 = {
    "schema_version":"1.0.0","artifact":"visual-final-id-adjudication-904-wave11","generated_at":GENERATED_AT,"audited_commit":AUDITED,
    "status":"independently_reviewed_zero_promotion","audit_boundary":"Audited-baseline static final-ID ownership only; current-main, browser, runtime, screenshot, usability, finding, benchmark and completion credit remain zero.",
    "inputs":{"manifest":record(MANIFEST),"inventory":record(INVENTORY),"visual_matrix":record(VISUAL),"pointer_preimage":record(POINTER)},
    "selection":{"rule":"Reconstruct Wave1 and the derived Wave2 first-25 exclusion, union exact Wave03-10 artifacts, then exhaust Tier A sorted by (route_count, visual_id) and take the first 18 Tier B rows sorted by (route_owner_union_cardinality, max_per_route_owner_cardinality, route_count, visual_id); no singleton page fallback.","exclusion_count":VISUAL_PRIOR_COUNT,"exclusion_sha256":VISUAL_PRIOR_SHA,"exclusion_reconstruction":{"wave1":{"path":WAVE1.relative_to(AUDIT).as_posix(),"count":len(wave1_ids),"sha256":WAVE1_SHA},"wave2":{"eligible_count":len(wave2_eligible),"selected_count":len(wave2_ids),"selected_ids":wave2_ids,"sha256":WAVE2_SHA},"waves03_10":prior_wave_reconstruction},"candidate_pool":{"tier_a_count":len(tier_a_candidates),"tier_b_count":len(tier_b_candidates),"rank_proof":candidate_rank_proof,"rank_proof_jsonl_sha256":candidate_rank_sha,"tier_b_omitted_ids":tier_b_omitted,"tier_b_omitted_sha256":TIER_B_OMITTED_SHA},"ordered_ids":VISUAL_IDS,"ordered_sha256":VISUAL_ORDERED_SHA,"sorted_sha256":VISUAL_SORTED_SHA,"proof_jsonl_sha256":visual_proof_sha,"source_map_jsonl_sha256":visual_source_sha,"serialization":"Packet-order objects, sorted JSON keys, compact separators, UTF-8/no BOM, LF join without terminal LF.","independent_review":"Exact exclusions, candidate pools, ranking boundary, ownership ambiguity, page fallbacks and audited controller methods independently reviewed."},
    "adjudications":proof,"source_map":source_maps,
    "count_delta":{"visual_assigned":0,"visual_unresolved":0,"material_assigned":0,"material_unresolved":0,"runtime_credit":0},
    "post_counts":{"visual_assigned":8168,"visual_rows":8753,"visual_unresolved":585,"material_assigned":3948,"material_rows":4312,"material_unresolved":364,"unique_assigned_targets":774},
}

classifier = load(CLASSIFIER)
primitive_before = json.loads(json.dumps(classifier["primitive_root_layer"]))
require(primitive_before["denominator"] == 477 and primitive_before["exact_trigger_resolved"] == 242 and primitive_before["unresolved"] == 235, "Primitive preimage drift")
by_key = {(str(row["file"]), int(row["line"]), str(row["symbol"])): row for row in classifier["custom_rows"]}
overlay_proof: list[dict[str, Any]] = []
overlay_sources: list[dict[str, Any]] = []
for file,line,symbol,source_sha,prior_class,prior_resolution,state_loci,trigger_loci,render_locus,chain in OVERLAY_ROWS:
    key = (file,line,symbol)
    require(key in by_key, f"Missing overlay: {key}")
    row = by_key[key]
    require(row["trigger_classification"] == prior_class and row["trigger_resolution"] == prior_resolution and row.get("state_identifier") is None and row.get("setter_identifier") is None, f"Overlay preimage drift: {key}")
    require(sha_file(ROOT / file) == source_sha, f"Overlay source drift: {file}")
    proof_row = {"file":file,"line":line,"symbol":symbol,"prior_classification":prior_class,"prior_resolution":prior_resolution,"state_identifier":"modal","setter_identifier":"setModal","state_loci":state_loci,"trigger_loci":trigger_loci,"render_open_close_locus":render_locus,"static_chain":chain,"verdict":"GO_EXACT_STATIC_TRIGGER_PAIRING","runtime_credit":0}
    overlay_proof.append(proof_row)
    overlay_sources.append({"identity":f"{file}:{line}:{symbol}","source_path":file,"source_sha256":source_sha,"loci":[state_loci,trigger_loci,render_locus]})
    row["state_identifier"] = "modal"
    row["setter_identifier"] = "setModal"
    row["trigger_classification"] = "same_scope_state_handler"
    row["trigger_resolution"] = "resolved"
    row["trigger_evidence"] = [{"kind":"named_state_open_handler","file":file,"source_sha256":source_sha,"state_identifier":"modal","setter":"setModal","state_loci":state_loci,"trigger_loci":trigger_loci,"render_open_close_locus":render_locus,"static_chain":chain,"credit_boundary":"audited-baseline static pairing only"}]

overlay_proof_sha = canonical(overlay_proof)
overlay_source_sha = canonical(overlay_sources)
classes = Counter(str(row["trigger_classification"]) for row in classifier["custom_rows"])
resolutions = Counter(str(row["trigger_resolution"]) for row in classifier["custom_rows"])
require(classes["same_scope_state_handler"] == 234 and classes["internal_state_trigger_candidate"] == 144 and classes["controlled_without_resolved_activator"] == 245, "Custom class drift")
require(resolutions["resolved"] == 253 and resolutions["source_inferred_not_exactly_paired"] == 144 and resolutions["unresolved"] == 260 and resolutions["blocked_parent_prop_not_present_at_callsite"] == 2, "Custom resolution drift")
classifier["custom_usage_layer"].update({"exact_trigger_resolved":253,"source_inferred_not_exactly_paired":144,"unresolved_or_blocked":262,"classification_counts":dict(sorted(classes.items())),"resolution_counts":dict(sorted(resolutions.items()))})
require(classifier["primitive_root_layer"] == primitive_before, "Primitive layer changed")
overlay10 = {
    "schema_version":"1.0.0","artifact":"overlay-trigger-adjudication-904-wave10","generated_at":GENERATED_AT,"audited_commit":AUDITED,
    "status":"independently_reviewed_exact_static_pairings_runtime_unchanged","audit_boundary":"Audited-baseline static source pairing only; current-main, runtime, focus, keyboard, viewport, visibility, permission, usability and completion credit remain zero.",
    "method":{"identity_rule":"Canonical identity is file:line:symbol under resources/js/pages/emar.","selection_sha256":OVERLAY_SELECTION_SHA,"exclusion_count":OVERLAY_PRIOR_COUNT,"exclusion_sha256":OVERLAY_PRIOR_SHA,"exclusion_reconstruction":prior_overlay_reconstruction,"proof_jsonl_sha256":overlay_proof_sha,"source_map_jsonl_sha256":overlay_source_sha,"serialization":"Packet-order objects, sorted JSON keys, compact separators, UTF-8/no BOM, LF join without terminal LF.","independent_review_verdict":"GO_10_exact_static_pairings_zero_runtime_credit"},
    "adjudications":overlay_proof,"source_map":overlay_sources,
    "count_delta":{"exact_trigger_resolved":10,"source_inferred_not_exactly_paired":-5,"unresolved_or_blocked":-5,"same_scope_state_handler":10,"internal_state_trigger_candidate":-5,"controlled_without_resolved_activator":-5,"runtime_credit":0},
    "post_counts":{"custom_denominator":659,"exact_trigger_resolved":253,"source_inferred_not_exactly_paired":144,"unresolved_or_blocked":262,"same_scope_state_handler":234,"internal_state_trigger_candidate":144,"controlled_without_resolved_activator":245,"generic_unresolved":260,"blocked_parent_prop_not_present_at_callsite":2,"primitive_denominator":477,"primitive_exact_trigger_resolved":242,"primitive_unresolved":235},
}

if "--check" in sys.argv:
    print(json.dumps({
        "status":"check_only",
        "visual_exclusion_count":len(prior_visual_ids),
        "visual_exclusion_sha256":sha_lines(list(prior_visual_ids), sort=True),
        "tier_a_count":len(tier_a_candidates),
        "tier_b_count":len(tier_b_candidates),
        "tier_b_omitted_ids":tier_b_omitted,
        "candidate_rank_sha256":candidate_rank_sha,
        "visual_proof_sha256":visual_proof_sha,
        "visual_source_map_sha256":visual_source_sha,
        "overlay_exclusion_count":len(prior_overlay_identities),
        "overlay_exclusion_sha256":sha_lines(list(prior_overlay_identities), sort=True),
        "overlay_proof_sha256":overlay_proof_sha,
        "overlay_source_map_sha256":overlay_source_sha,
        "primitive_preserved":classifier["primitive_root_layer"] == primitive_before,
        "credit_delta":{"visual":0,"runtime":0,"browser":0,"completion":0},
    }, indent=2))
    raise SystemExit

save(VISUAL11, visual11)
save(OVERLAY10, overlay10)
save(CLASSIFIER, classifier)
summary = {
    "schema_version":"1.0.0","artifact":"final-904-visual-wave11-overlay10-generation-summary","generated_at":GENERATED_AT,"audited_commit":AUDITED,
    "outputs":{"visual_wave11":record(VISUAL11),"overlay_wave10":record(OVERLAY10),"overlay_classifier":record(CLASSIFIER)},
    "proof_hashes":{"visual":visual_proof_sha,"visual_source_map":visual_source_sha,"visual_candidate_rank":candidate_rank_sha,"overlay":overlay_proof_sha,"overlay_source_map":overlay_source_sha},
    "counts":{"visual_assigned":8168,"visual_unresolved":585,"material_assigned":3948,"material_unresolved":364,"overlay_exact_resolved":253,"overlay_source_inferred":144,"overlay_unresolved_or_blocked":262,"primitive_exact_resolved":242,"primitive_unresolved":235},
    "downstream_refresh":{"required_order":["generators/refresh-current-904-summaries.py","generators/refresh-audit-dashboard-data.py","generators/finalize-current-904-validation.py"]},
    "credit_boundary":{"visual_final_id_promotions":0,"overlay_static_pairings":10,"runtime_credit_delta":0,"browser_credit_delta":0,"completion_credit_delta":0},
}
save(SUMMARY, summary)
pointer = load(POINTER)
pointer["generated_at"] = max(str(pointer.get("generated_at", "")), GENERATED_AT)
pointer["artifacts"].update({"visual_wave904_11":record(VISUAL11),"overlay_trigger_wave10":record(OVERLAY10),"overlay_trigger_classification":record(CLASSIFIER),"visual_wave11_overlay10_generation_summary":record(SUMMARY)})
pointer["completion_status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"
pointer["runtime_credit_delta"] = 0
save(POINTER, pointer)
print(json.dumps({"status":"applied","visual11":record(VISUAL11),"overlay10":record(OVERLAY10),"classifier":record(CLASSIFIER),"summary":record(SUMMARY),"pointer":record(POINTER)}, indent=2))
