#!/usr/bin/env python3
"""Record zero-promotion visual Wave 08 and exact-static overlay Wave 7."""

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
GENERATED_AT = "2026-08-21T23:45:00+12:00"
AUDITED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"

MANIFEST = SOURCE / "working-capability-manifest-904.json"
INVENTORY = AUDIT / "inventory-904.json"
VISUAL = AUDIT / "05-browser-visual-coverage-matrix-904.csv"
CLASSIFIER = SOURCE / "overlay-trigger-classification.json"
POINTER = SOURCE / "canonical-audit-inputs.json"
VISUAL08 = SOURCE / "visual-final-id-adjudication-904-wave08.json"
OVERLAY7 = SOURCE / "overlay-trigger-adjudication-904-wave7.json"
SUMMARY = SOURCE / "final-904-visual-wave08-overlay7-generation-summary.json"

PINS = {
    MANIFEST: "ffca48609deab9a8938105c857786594a9a5431c31efe329ef4288da6165358f",
    INVENTORY: "ac777686d9f7d3b806ee4668ccab6c1785e74586bce95abc82b7e16b713d815b",
    VISUAL: "707885a83264c8e2ab3f92898578a2b20ba3e8a2ccdb3ece17156d8c0774c293",
    CLASSIFIER: "db3351745d9c50b3b04b09e2702531dadee83ccac5ad2125108d1e309bfb33eb",
    POINTER: "1d8b8369a7f9cb8d78f0c3b74f5085d811876d00993dff25edcf3bcee915154c",
}

VISUAL_IDS = [
    "VIS-019173", "VIS-019289", "VIS-019290", "VIS-019330", "VIS-019748",
    "VIS-019965", "VIS-020228", "VIS-020726", "VIS-020875", "VIS-021194",
    "VIS-015390", "VIS-017936", "VIS-018650", "VIS-019473", "VIS-019750",
    "VIS-019976", "VIS-020278", "VIS-020280", "VIS-020654", "VIS-018559",
    "VIS-019413", "VIS-020672", "VIS-020729", "VIS-020912", "VIS-017405",
]

# file, line, symbol, source sha, state, setter, source chain, prior class, prior resolution
OVERLAY_ROWS = [
    ("resources/js/components/hr/feedback-wizards.tsx", 907, "TemplateWizard",
     "e1460281494650a51c74a5f92f90dcd883b9d62fe6de4904f65ea6cf0de65c27",
     "editing", "setEditing", "state 888; Button triggers 990/1014; conditional render 906-907; close 909",
     "internal_state_trigger_candidate", "source_inferred_not_exactly_paired"),
    ("resources/js/pages/control-room/broadcast.tsx", 280, "BroadcastWizard",
     "d2d6cc465f182cdd4e7d9f81c6e94e08a81ed4600ee65f5b546f5d1ccffbe3d3",
     "composerOpen", "setComposerOpen", "state 97-100; Button triggers 130/154; render 279-280; close 282",
     "internal_state_trigger_candidate", "source_inferred_not_exactly_paired"),
    ("resources/js/pages/emar/Competency.tsx", 294, "AssessmentWizardDialog",
     "fde0f2137a25f456ab855986051f450384b7328ebbcde24a5f0174ec85b0314f",
     "modal", "setModal", "state 83; new handler 92; triggers 116/208/280/285; discriminant render 294",
     "internal_state_trigger_candidate", "source_inferred_not_exactly_paired"),
    ("resources/js/pages/emar/Competency.tsx", 295, "AssessmentWizardDialog",
     "fde0f2137a25f456ab855986051f450384b7328ebbcde24a5f0174ec85b0314f",
     "modal", "setModal", "state 83; edit handler 90; trigger 103; discriminant render 295",
     "internal_state_trigger_candidate", "source_inferred_not_exactly_paired"),
    ("resources/js/pages/emar/Competency.tsx", 296, "AssessmentWizardDialog",
     "fde0f2137a25f456ab855986051f450384b7328ebbcde24a5f0174ec85b0314f",
     "modal", "setModal", "state 83; renew handler 89; triggers 102/271; discriminant render 296",
     "internal_state_trigger_candidate", "source_inferred_not_exactly_paired"),
    ("resources/js/pages/emar/Competency.tsx", 297, "ViewAssessmentDialog",
     "fde0f2137a25f456ab855986051f450384b7328ebbcde24a5f0174ec85b0314f",
     "modal", "setModal", "state 83; view handler 88; triggers 101/269; discriminant render 297",
     "controlled_without_resolved_activator", "unresolved"),
    ("resources/js/pages/emar/ControlledDrugs.tsx", 550, "RecordCdEntryDialog",
     "087cda89a8c7060607783d6af56e80386e6a8ff913ded04eeb49811ca332b449",
     "modal", "setModal", "state 92; entry triggers 215/279/313; discriminant render 550",
     "internal_state_trigger_candidate", "source_inferred_not_exactly_paired"),
    ("resources/js/pages/emar/ControlledDrugs.tsx", 551, "BalanceCheckDialog",
     "087cda89a8c7060607783d6af56e80386e6a8ff913ded04eeb49811ca332b449",
     "modal", "setModal", "state 92; balance triggers 217/280/281/317; discriminant render 551",
     "controlled_without_resolved_activator", "unresolved"),
    ("resources/js/pages/emar/ControlledDrugs.tsx", 552, "BalanceCheckDialog",
     "087cda89a8c7060607783d6af56e80386e6a8ff913ded04eeb49811ca332b449",
     "modal", "setModal", "state 92; balance-med triggers 214/217/417; discriminant render 552",
     "controlled_without_resolved_activator", "unresolved"),
    ("resources/js/pages/emar/ControlledDrugs.tsx", 553, "ReportLossDialog",
     "087cda89a8c7060607783d6af56e80386e6a8ff913ded04eeb49811ca332b449",
     "modal", "setModal", "state 92; loss triggers 218/283/321; discriminant render 553",
     "internal_state_trigger_candidate", "source_inferred_not_exactly_paired"),
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


if any(path.exists() for path in (VISUAL08, OVERLAY7, SUMMARY)):
    require(all(path.exists() for path in (VISUAL08, OVERLAY7, SUMMARY)), "Partial Wave08 output set")
    classifier = load(CLASSIFIER)
    require(classifier["custom_usage_layer"]["exact_trigger_resolved"] == 223, "Existing overlay count drift")
    pointer = load(POINTER)
    for key, path in (("visual_wave904_08", VISUAL08), ("overlay_trigger_wave7", OVERLAY7),
                      ("visual_wave08_overlay7_generation_summary", SUMMARY),
                      ("overlay_trigger_classification", CLASSIFIER)):
        require(pointer["artifacts"][key] == record(path), f"Existing pointer drift: {key}")
    print(json.dumps({"status": "already_applied", "visual08": record(VISUAL08), "overlay7": record(OVERLAY7),
                      "classifier": record(CLASSIFIER), "summary": record(SUMMARY)}, indent=2))
    raise SystemExit(0)

for path, expected in PINS.items():
    require(sha_file(path) == expected, f"Input SHA drift: {path}")
require(sha_lines(VISUAL_IDS) == "11d464dfa23028472fe9d4e819e4dd770452cafbebda6021a26962897cbf5be1", "Visual selection drift")
require(sha_lines(VISUAL_IDS, sort=True) == "3f2b51b5ff2015725d3f7f2f738195b167259ef1be97354b0505043d0ba63195", "Visual set drift")

with VISUAL.open("r", encoding="utf-8-sig", newline="") as handle:
    visual_rows = list(csv.DictReader(handle))
by_visual = {row["visual_id"]: row for row in visual_rows}
require(len(by_visual) == len(visual_rows), "Visual ID uniqueness drift")
for visual_id in VISUAL_IDS:
    row = by_visual[visual_id]
    require(not row["feature_id"] and row["feature_link_status"].startswith("unresolved"), f"Visual state drift: {visual_id}")
    require(row["pattern_type"] == "material-state-applicability", f"Visual material-state drift: {visual_id}")

visual08 = {
    "schema_version": "1.0.0", "artifact": "visual-final-id-adjudication-904-wave08",
    "generated_at": GENERATED_AT, "audited_commit": AUDITED_COMMIT,
    "status": "independently_reviewed_zero_promotion",
    "audit_boundary": "Audited-baseline static final-ID ownership adjudication only. No current-main, browser, runtime, screenshot, usability, finding, benchmark or completion credit is inferred.",
    "inputs": {"manifest": record(MANIFEST), "inventory": record(INVENTORY), "visual_matrix": record(VISUAL),
               "pointer_preimage": record(POINTER)},
    "selection": {"rule": "Exclude 182 prior reviewed visual IDs; select the next deterministic 25 unresolved material rows under exact singleton-route ownership, shared-controller method existence, empty owner intersection and no singleton exact page fallback.",
                  "exclusion_count": 182, "exclusion_sha256": "592058bfb019613a2016b12c10f569791a9f17a5ab2196daf32d4e0cd1ea7c39",
                  "ordered_ids": VISUAL_IDS, "ordered_sha256": "11d464dfa23028472fe9d4e819e4dd770452cafbebda6021a26962897cbf5be1",
                  "sorted_sha256": "3f2b51b5ff2015725d3f7f2f738195b167259ef1be97354b0505043d0ba63195",
                  "proof_jsonl_sha256": "bf091899f62c1747f2dd1082897e7413a4881ba3a298d147a8fcd05c8141ffca",
                  "source_map_jsonl_sha256": "73752ab5b32e68ac82f199680c49c27cd396706ed13817fa5b798266c41aa83b",
                  "serialization": "Packet-order objects; sorted JSON keys; compact separators; UTF-8/no BOM; LF join without terminal LF."},
    "adjudications": [{"visual_id": visual_id, "verdict": "RETAIN_UNRESOLVED",
                       "reason": "Exact route owners have an empty final-ID intersection and no singleton exact page fallback.",
                       "runtime_credit": 0} for visual_id in VISUAL_IDS],
    "count_delta": {"visual_assigned": 0, "visual_unresolved": 0, "material_assigned": 0, "material_unresolved": 0, "runtime_credit": 0},
    "post_counts": {"visual_assigned": 8168, "visual_rows": 8753, "visual_unresolved": 585,
                    "material_assigned": 3948, "material_rows": 4312, "material_unresolved": 364,
                    "unique_assigned_targets": 774},
}

classifier = load(CLASSIFIER)
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
require(sha_lines(selection) == "81c14f12e29f225856b3afdab6d7425b1d55b7e96760e2c045689f5b92f40c38", "Overlay selection drift")
class_counts = Counter(str(row["trigger_classification"]) for row in classifier["custom_rows"])
resolution_counts = Counter(str(row["trigger_resolution"]) for row in classifier["custom_rows"])
require(class_counts["same_scope_state_handler"] == 204 and class_counts["internal_state_trigger_candidate"] == 166, "Overlay class-count drift")
require(class_counts["controlled_without_resolved_activator"] == 253, "Overlay controlled-count drift")
require(resolution_counts["resolved"] == 223 and resolution_counts["source_inferred_not_exactly_paired"] == 166, "Overlay resolution-count drift")
require(resolution_counts["unresolved"] == 268 and resolution_counts["blocked_parent_prop_not_present_at_callsite"] == 2, "Overlay unresolved-count drift")
classifier["custom_usage_layer"].update({
    "exact_trigger_resolved": 223,
    "source_inferred_not_exactly_paired": 166,
    "unresolved_or_blocked": 270,
    "classification_counts": dict(sorted(class_counts.items())),
    "resolution_counts": dict(sorted(resolution_counts.items())),
})

overlay7 = {
    "schema_version": "1.0.0", "artifact": "overlay-trigger-adjudication-904-wave7",
    "generated_at": GENERATED_AT, "audited_commit": AUDITED_COMMIT,
    "status": "independently_reviewed_exact_static_pairings_runtime_unchanged",
    "audit_boundary": "Audited-baseline static source trigger pairing only. Current-main, runtime, focus, keyboard, viewport, visibility, permission, usability and completion credit remain zero.",
    "method": {"selection_sha256": "81c14f12e29f225856b3afdab6d7425b1d55b7e96760e2c045689f5b92f40c38",
               "exclusion_count": 60, "exclusion_sha256": "de81e7e5a0dda05359103593fa77f6aa78252fc0998192b2548a238d3ffd3811",
               "proof_jsonl_sha256": "057936527c9b6d8032b9805ee09eda87f193a40d13ccfea3a2e65a91f47f9ba7",
               "source_map_jsonl_sha256": "03435df06873ec21d7b4708130992d3b4761e1562d7f610fcc7f6c1cebb48d42",
               "verdict": "GO_10_exact_static_trigger_pairings"},
    "adjudications": adjudications,
    "count_delta": {"exact_trigger_resolved": 10, "source_inferred_not_exactly_paired": -7,
                    "generic_unresolved": -3, "same_scope_state_handler": 10,
                    "internal_state_trigger_candidate": -7, "controlled_without_resolved_activator": -3,
                    "runtime_credit": 0},
    "post_counts": {"custom_denominator": 659, "exact_trigger_resolved": 223,
                    "source_inferred_not_exactly_paired": 166, "unresolved_or_blocked": 270,
                    "same_scope_state_handler": 204, "internal_state_trigger_candidate": 166,
                    "controlled_without_resolved_activator": 253, "generic_unresolved": 268,
                    "primitive_denominator": 477, "primitive_exact_trigger_resolved": 208,
                    "primitive_unresolved": 269},
}

write_json(VISUAL08, visual08)
write_json(OVERLAY7, overlay7)
write_json(CLASSIFIER, classifier)
summary = {
    "schema_version": "1.0.0", "artifact": "final-904-visual-wave08-overlay7-generation-summary",
    "generated_at": GENERATED_AT, "audited_commit": AUDITED_COMMIT,
    "outputs": {"visual_wave08": record(VISUAL08), "overlay_wave7": record(OVERLAY7), "overlay_classifier": record(CLASSIFIER)},
    "counts": {"visual_assigned": 8168, "visual_unresolved": 585, "material_assigned": 3948, "material_unresolved": 364,
               "overlay_exact_resolved": 223, "overlay_unresolved_or_blocked": 270,
               "primitive_exact_resolved": 208, "primitive_unresolved": 269},
    "credit_boundary": {"visual_final_id_promotions": 0, "overlay_static_pairings": 10,
                        "runtime_credit_delta": 0, "browser_credit_delta": 0, "completion_credit_delta": 0},
}
write_json(SUMMARY, summary)
pointer = load(POINTER)
pointer["generated_at"] = max(pointer.get("generated_at", ""), GENERATED_AT)
pointer["artifacts"].update({
    "visual_wave904_08": record(VISUAL08),
    "overlay_trigger_wave7": record(OVERLAY7),
    "overlay_trigger_classification": record(CLASSIFIER),
    "visual_wave08_overlay7_generation_summary": record(SUMMARY),
})
pointer["completion_status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"
pointer["runtime_credit_delta"] = 0
write_json(POINTER, pointer)
print(json.dumps({"status": "applied", "visual08": record(VISUAL08), "overlay7": record(OVERLAY7),
                  "classifier": record(CLASSIFIER), "summary": record(SUMMARY), "pointer": record(POINTER)}, indent=2))
