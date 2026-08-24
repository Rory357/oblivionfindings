#!/usr/bin/env python3
"""Record zero-promotion visual Wave 09 and exact-static overlay Wave 8."""

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
GENERATED_AT = "2026-08-22T00:25:00+12:00"
AUDITED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"

MANIFEST = SOURCE / "working-capability-manifest-904.json"
INVENTORY = AUDIT / "inventory-904.json"
VISUAL = AUDIT / "05-browser-visual-coverage-matrix-904.csv"
CLASSIFIER = SOURCE / "overlay-trigger-classification.json"
POINTER = SOURCE / "canonical-audit-inputs.json"
VISUAL09 = SOURCE / "visual-final-id-adjudication-904-wave09.json"
OVERLAY8 = SOURCE / "overlay-trigger-adjudication-904-wave8.json"
SUMMARY = SOURCE / "final-904-visual-wave09-overlay8-generation-summary.json"

PINS = {
    MANIFEST: "ffca48609deab9a8938105c857786594a9a5431c31efe329ef4288da6165358f",
    INVENTORY: "ac693df595d4d350263eea039a1775c78d06088aba3a2bea8867a1d0e883c99f",
    VISUAL: "707885a83264c8e2ab3f92898578a2b20ba3e8a2ccdb3ece17156d8c0774c293",
    CLASSIFIER: "563fc8ba4177f427c46dc085c8395878a20b43363cfa390cb2036382bdd87c13",
    POINTER: "e64857227b27d7da90bb7e052d7df20e5dc13cf5074182d50109fec792fe62d2",
}

VISUAL_IDS = [
    "VIS-019311", "VIS-019312", "VIS-020288", "VIS-020070", "VIS-021098",
    "VIS-021099", "VIS-019078", "VIS-019079", "VIS-020394", "VIS-020395",
    "VIS-018698", "VIS-018699", "VIS-019382", "VIS-019383", "VIS-021085",
    "VIS-021086", "VIS-021092", "VIS-021093", "VIS-019729", "VIS-020635",
    "VIS-020954", "VIS-018492", "VIS-019767", "VIS-019768", "VIS-020656",
]

# file, line, symbol, source sha, state, setter, static chain, prior class, prior resolution
OVERLAY_ROWS = [
    ("resources/js/pages/emar/ControlledDrugs.tsx", 554, "RecordDestructionDialog",
     "087cda89a8c7060607783d6af56e80386e6a8ff913ded04eeb49811ca332b449", "modal", "setModal",
     "state 92; record-destruction triggers 230/282; render and close 554", "internal_state_trigger_candidate", "source_inferred_not_exactly_paired"),
    ("resources/js/pages/emar/ControlledDrugs.tsx", 555, "ResolveDiscrepancyDialog",
     "087cda89a8c7060607783d6af56e80386e6a8ff913ded04eeb49811ca332b449", "modal", "setModal",
     "state 92; resolve-discrepancy triggers 224/469; render and close 555", "controlled_without_resolved_activator", "unresolved"),
    ("resources/js/pages/emar/ControlledDrugs.tsx", 556, "LossActionDialog",
     "087cda89a8c7060607783d6af56e80386e6a8ff913ded04eeb49811ca332b449", "modal", "setModal",
     "state 92; loss-action triggers 236/237/526/527; render and close 556", "controlled_without_resolved_activator", "unresolved"),
    ("resources/js/pages/emar/ControlledDrugs.tsx", 558, "CdDetailDialog",
     "087cda89a8c7060607783d6af56e80386e6a8ff913ded04eeb49811ca332b449", "modal", "setModal",
     "state 92; detail triggers 174/188/252; guard/render 557-558 and close 560", "internal_state_trigger_candidate", "source_inferred_not_exactly_paired"),
    ("resources/js/pages/emar/Destructions.tsx", 321, "RecordDestructionDialog",
     "b0476ed0a27eb5e4fe1702c77d44a2902d722c171cf0b3e5ad2b3f2155a89b77", "modal", "setModal",
     "state 94; record triggers 210/267; render and close 321", "internal_state_trigger_candidate", "source_inferred_not_exactly_paired"),
    ("resources/js/pages/emar/Destructions.tsx", 322, "VoidDestructionDialog",
     "b0476ed0a27eb5e4fe1702c77d44a2902d722c171cf0b3e5ad2b3f2155a89b77", "modal", "setModal",
     "state 94; void triggers 154/267/288; render and close 322", "controlled_without_resolved_activator", "unresolved"),
    ("resources/js/pages/emar/Destructions.tsx", 324, "DestructionDetailDialog",
     "b0476ed0a27eb5e4fe1702c77d44a2902d722c171cf0b3e5ad2b3f2155a89b77", "modal", "setModal",
     "state 94; detail triggers 136/148/163/165; guard/render 323-324 and close 326", "internal_state_trigger_candidate", "source_inferred_not_exactly_paired"),
    ("resources/js/pages/emar/Index.tsx", 1368, "GenerateRoundsModal",
     "7f2b2a717f6b09b1e593648951449ac8ccb42ba5a1d68334a1ae1d5b6c610af8", "modal", "setModal",
     "state 404; trigger 677; component/open 1368-1369 and close 1370", "internal_state_trigger_candidate", "source_inferred_not_exactly_paired"),
    ("resources/js/pages/emar/Index.tsx", 1373, "ReportErrorModal",
     "7f2b2a717f6b09b1e593648951449ac8ccb42ba5a1d68334a1ae1d5b6c610af8", "modal", "setModal",
     "state 404; trigger 828; component/open 1373-1374 and close 1375", "internal_state_trigger_candidate", "source_inferred_not_exactly_paired"),
    ("resources/js/pages/emar/Index.tsx", 1378, "AddMedicationModal",
     "7f2b2a717f6b09b1e593648951449ac8ccb42ba5a1d68334a1ae1d5b6c610af8", "modal", "setModal",
     "state 404; trigger 1046; component/open 1378-1379 and close 1380", "internal_state_trigger_candidate", "source_inferred_not_exactly_paired"),
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


if any(path.exists() for path in (VISUAL09, OVERLAY8, SUMMARY)):
    require(all(path.exists() for path in (VISUAL09, OVERLAY8, SUMMARY)), "Partial Wave09 output set")
    classifier = load(CLASSIFIER)
    require(classifier["custom_usage_layer"]["exact_trigger_resolved"] == 233, "Existing custom count drift")
    require(classifier["primitive_root_layer"]["exact_trigger_resolved"] == 242 and classifier["primitive_root_layer"]["unresolved"] == 235,
            "Existing primitive count drift")
    pointer = load(POINTER)
    for key, path in (("visual_wave904_09", VISUAL09), ("overlay_trigger_wave8", OVERLAY8),
                      ("visual_wave09_overlay8_generation_summary", SUMMARY), ("overlay_trigger_classification", CLASSIFIER)):
        require(pointer["artifacts"][key] == record(path), f"Existing pointer drift: {key}")
    print(json.dumps({"status": "already_applied", "visual09": record(VISUAL09), "overlay8": record(OVERLAY8),
                      "classifier": record(CLASSIFIER), "summary": record(SUMMARY)}, indent=2))
    raise SystemExit(0)

for path, expected in PINS.items():
    require(sha_file(path) == expected, f"Input SHA drift: {path}")
require(sha_lines(VISUAL_IDS) == "efb6f4191b5614c3b6a2d55048d44a246b2a43bb31abba407f85cf090c2e4ee9", "Visual selection drift")
require(sha_lines(VISUAL_IDS, sort=True) == "8b7b87c90dbd0ebd9821b79726fd6a34cf143b2d9f967b91118dd93f39d7ec0d", "Visual set drift")

with VISUAL.open("r", encoding="utf-8-sig", newline="") as handle:
    visual_rows = list(csv.DictReader(handle))
by_visual = {row["visual_id"]: row for row in visual_rows}
require(len(by_visual) == len(visual_rows), "Visual ID uniqueness drift")
for visual_id in VISUAL_IDS:
    row = by_visual[visual_id]
    require(not row["feature_id"] and row["feature_link_status"].startswith("unresolved"), f"Visual state drift: {visual_id}")
    require(row["pattern_type"] == "material-state-applicability", f"Visual material-state drift: {visual_id}")

visual09 = {
    "schema_version": "1.0.0", "artifact": "visual-final-id-adjudication-904-wave09",
    "generated_at": GENERATED_AT, "audited_commit": AUDITED_COMMIT, "status": "independently_reviewed_zero_promotion",
    "audit_boundary": "Audited-baseline static final-ID ownership adjudication only. No current-main, browser, runtime, screenshot, usability, finding, benchmark or completion credit is inferred.",
    "inputs": {"manifest": record(MANIFEST), "inventory": record(INVENTORY), "visual_matrix": record(VISUAL), "pointer_preimage": record(POINTER)},
    "selection": {"rule": "Exclude 207 prior reviewed IDs; take the next 25 unresolved material rows under exact singleton route ownership, shared-controller method existence, empty owner intersection and no singleton exact page fallback.",
        "exclusion_count": 207, "exclusion_sha256": "12f4df45e305cd5323704a1dcc6953a606d46e49721b3de6ead8c56f778f5b17",
        "ordered_ids": VISUAL_IDS, "ordered_sha256": "efb6f4191b5614c3b6a2d55048d44a246b2a43bb31abba407f85cf090c2e4ee9",
        "sorted_sha256": "8b7b87c90dbd0ebd9821b79726fd6a34cf143b2d9f967b91118dd93f39d7ec0d",
        "proof_jsonl_sha256": "d4f6502906ee25d7ba62226be771e5d35bf446a28263082ba2f7d1807303cfe4",
        "source_map_jsonl_sha256": "123de14943345634973f8628a820e1cd0f91123fcef4b1b839587c02f2380a1c",
        "independent_replay": "207 exclusions and historical Wave2 derivation reconstructed; every route-owner intersection empty; 18 rows have no page fallback and seven have multi-owner pages; all methods present across 16 controllers.",
        "serialization": "Packet-order objects; sorted JSON keys; compact separators; UTF-8/no BOM; LF join without terminal LF."},
    "adjudications": [{"visual_id": visual_id, "verdict": "RETAIN_UNRESOLVED",
        "reason": "Exact route owners have an empty final-ID intersection and no singleton exact page fallback.", "runtime_credit": 0} for visual_id in VISUAL_IDS],
    "count_delta": {"visual_assigned": 0, "visual_unresolved": 0, "material_assigned": 0, "material_unresolved": 0, "runtime_credit": 0},
    "post_counts": {"visual_assigned": 8168, "visual_rows": 8753, "visual_unresolved": 585,
        "material_assigned": 3948, "material_rows": 4312, "material_unresolved": 364, "unique_assigned_targets": 774},
}

classifier = load(CLASSIFIER)
primitive_before = json.loads(json.dumps(classifier["primitive_root_layer"]))
require(primitive_before["denominator"] == 477 and primitive_before["exact_trigger_resolved"] == 242 and primitive_before["unresolved"] == 235,
        "Primitive preimage drift")
by_key = {(str(row["file"]), int(row["line"]), str(row["symbol"])): row for row in classifier["custom_rows"]}
adjudications = []
for file, line, symbol, source_sha, state, setter, chain, prior_class, prior_resolution in OVERLAY_ROWS:
    key = (file, line, symbol)
    require(key in by_key, f"Missing overlay row: {key}")
    row = by_key[key]
    require(row["trigger_classification"] == prior_class and row["trigger_resolution"] == prior_resolution, f"Overlay state drift: {key}")
    require(row.get("state_identifier") is None and row.get("setter_identifier") is None, f"Overlay null preimage drift: {key}")
    require(sha_file(ROOT / file) == source_sha, f"Overlay source drift: {file}")
    evidence = {"kind": "named_state_open_handler", "file": file, "source_sha256": source_sha,
                "state_identifier": state, "setter": setter, "static_chain": chain,
                "credit_boundary": "audited-baseline static pairing only"}
    row["state_identifier"] = state
    row["setter_identifier"] = setter
    row["trigger_classification"] = "same_scope_state_handler"
    row["trigger_resolution"] = "resolved"
    row["trigger_evidence"] = [evidence]
    adjudications.append({"file": file, "line": line, "symbol": symbol,
        "prior_classification": prior_class, "prior_resolution": prior_resolution,
        "verdict": "GO_exact_static_trigger_pairing", "evidence": evidence})

selection = [f"{file}:{line}:{symbol}" for file, line, symbol, *_ in OVERLAY_ROWS]
require(sha_lines(selection) == "536468783e4225ef97b70ba2c4358b641fba9392a657151d69af96ae3c119c23", "Overlay selection drift")
class_counts = Counter(str(row["trigger_classification"]) for row in classifier["custom_rows"])
resolution_counts = Counter(str(row["trigger_resolution"]) for row in classifier["custom_rows"])
require(class_counts["same_scope_state_handler"] == 214 and class_counts["internal_state_trigger_candidate"] == 159, "Overlay class-count drift")
require(class_counts["controlled_without_resolved_activator"] == 250, "Overlay controlled-count drift")
require(resolution_counts["resolved"] == 233 and resolution_counts["source_inferred_not_exactly_paired"] == 159, "Overlay resolution-count drift")
require(resolution_counts["unresolved"] == 265 and resolution_counts["blocked_parent_prop_not_present_at_callsite"] == 2, "Overlay unresolved-count drift")
classifier["custom_usage_layer"].update({"exact_trigger_resolved": 233, "source_inferred_not_exactly_paired": 159,
    "unresolved_or_blocked": 267, "classification_counts": dict(sorted(class_counts.items())),
    "resolution_counts": dict(sorted(resolution_counts.items()))})
require(classifier["primitive_root_layer"] == primitive_before, "Primitive layer changed during custom overlay adjudication")

overlay8 = {
    "schema_version": "1.0.0", "artifact": "overlay-trigger-adjudication-904-wave8",
    "generated_at": GENERATED_AT, "audited_commit": AUDITED_COMMIT,
    "status": "independently_reviewed_exact_static_pairings_runtime_unchanged",
    "audit_boundary": "Audited-baseline static source trigger pairing only. Current-main, runtime, focus, keyboard, viewport, visibility, permission, usability and completion credit remain zero.",
    "method": {"selection_sha256": "536468783e4225ef97b70ba2c4358b641fba9392a657151d69af96ae3c119c23",
        "exclusion_count": 70, "exclusion_sha256": "f9e1af56a2e7adf09490eeb4bf244932a04049ca6df8aab006d54f5e639597f9",
        "proof_jsonl_sha256": "7f6426a8cfcd431e6c71ee6df489dd6c2471f35b611b828613ecc25c8ba907d7",
        "source_map_jsonl_sha256": "67e0dde6d7fd0500d2ae32955f7a320f5427cf014dd5c62a0392519440b00a3f",
        "independent_review_verdict": "GO_after_correcting_and_preserving_primitive_242_235"},
    "adjudications": adjudications,
    "count_delta": {"exact_trigger_resolved": 10, "source_inferred_not_exactly_paired": -7,
        "generic_unresolved": -3, "same_scope_state_handler": 10, "internal_state_trigger_candidate": -7,
        "controlled_without_resolved_activator": -3, "runtime_credit": 0},
    "post_counts": {"custom_denominator": 659, "exact_trigger_resolved": 233,
        "source_inferred_not_exactly_paired": 159, "unresolved_or_blocked": 267,
        "same_scope_state_handler": 214, "internal_state_trigger_candidate": 159,
        "controlled_without_resolved_activator": 250, "generic_unresolved": 265,
        "primitive_denominator": 477, "primitive_exact_trigger_resolved": 242, "primitive_unresolved": 235},
}

write_json(VISUAL09, visual09)
write_json(OVERLAY8, overlay8)
write_json(CLASSIFIER, classifier)
summary = {"schema_version": "1.0.0", "artifact": "final-904-visual-wave09-overlay8-generation-summary",
    "generated_at": GENERATED_AT, "audited_commit": AUDITED_COMMIT,
    "outputs": {"visual_wave09": record(VISUAL09), "overlay_wave8": record(OVERLAY8), "overlay_classifier": record(CLASSIFIER)},
    "counts": {"visual_assigned": 8168, "visual_unresolved": 585, "material_assigned": 3948, "material_unresolved": 364,
        "overlay_exact_resolved": 233, "overlay_unresolved_or_blocked": 267,
        "primitive_exact_resolved": 242, "primitive_unresolved": 235},
    "credit_boundary": {"visual_final_id_promotions": 0, "overlay_static_pairings": 10,
        "runtime_credit_delta": 0, "browser_credit_delta": 0, "completion_credit_delta": 0}}
write_json(SUMMARY, summary)
pointer = load(POINTER)
pointer["generated_at"] = max(pointer.get("generated_at", ""), GENERATED_AT)
pointer["artifacts"].update({"visual_wave904_09": record(VISUAL09), "overlay_trigger_wave8": record(OVERLAY8),
    "overlay_trigger_classification": record(CLASSIFIER), "visual_wave09_overlay8_generation_summary": record(SUMMARY)})
pointer["completion_status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"
pointer["runtime_credit_delta"] = 0
write_json(POINTER, pointer)
print(json.dumps({"status": "applied", "visual09": record(VISUAL09), "overlay8": record(OVERLAY8),
                  "classifier": record(CLASSIFIER), "summary": record(SUMMARY), "pointer": record(POINTER)}, indent=2))
