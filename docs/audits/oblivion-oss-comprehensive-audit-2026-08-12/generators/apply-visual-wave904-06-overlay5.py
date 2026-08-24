#!/usr/bin/env python3
"""Record zero-promotion visual Wave 06 and exact-static overlay Wave 5."""

from __future__ import annotations

import hashlib
import json
from collections import Counter
from pathlib import Path
from typing import Any


GENERATOR = Path(__file__).resolve()
AUDIT = GENERATOR.parent.parent
ROOT = AUDIT.parent.parent.parent
SOURCE = AUDIT / "evidence" / "source"
GENERATED_AT = "2026-08-21T22:10:00+12:00"
AUDITED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"

MANIFEST = SOURCE / "working-capability-manifest-904.json"
INVENTORY = AUDIT / "inventory-904.json"
VISUAL = AUDIT / "05-browser-visual-coverage-matrix-904.csv"
CLASSIFIER = SOURCE / "overlay-trigger-classification.json"
POINTER = SOURCE / "canonical-audit-inputs.json"
VISUAL06 = SOURCE / "visual-final-id-adjudication-904-wave06.json"
OVERLAY5 = SOURCE / "overlay-trigger-adjudication-904-wave5.json"
SUMMARY = SOURCE / "final-904-visual-wave06-overlay5-generation-summary.json"

PINS = {
    "manifest": "ffca48609deab9a8938105c857786594a9a5431c31efe329ef4288da6165358f",
    "inventory": "598d76cd63b23a7ea49164ad43e12cb10afdb9fed8437807d8b50d20b090cb9b",
    "visual": "707885a83264c8e2ab3f92898578a2b20ba3e8a2ccdb3ece17156d8c0774c293",
    "classifier": "fadee31f31a3d8a382a69a81b78f87698a4365bd3e45c197c9811c7b904e4262",
}

VISUAL_IDS = [
    "VIS-019390", "VIS-019967", "VIS-020307", "VIS-020678", "VIS-020686",
    "VIS-020688", "VIS-020698", "VIS-021202", "VIS-021285", "VIS-017874",
    "VIS-017875", "VIS-017995", "VIS-017996", "VIS-018056", "VIS-018057",
    "VIS-018058", "VIS-019077", "VIS-019142", "VIS-019143", "VIS-019177",
    "VIS-019178", "VIS-020239", "VIS-020304", "VIS-020393", "VIS-020506",
]

OVERLAY_ROWS = [
    ("resources/js/pages/operations/rostering/index.tsx", 2926, "CreateShiftDialog", "27d0280eb6149689a4b374acb250223e4e984e9db56c9d5bcb0ab837a1dd486d", "createOpen", "setCreateOpen", "state 588; openCreateShift 1894; setter 1913; trigger 769; open 2927"),
    ("resources/js/pages/operations/shift-notes/Index.tsx", 301, "NoteWizard", "deef1255373645c77faa0e79118fe7de608772da0e71297a910f4262f8dd3568", "wizardOpen", "setWizardOpen", "state 60; openNew 164; setter 166; trigger 294; open 302"),
    ("resources/js/pages/operations/shifts/components/create-shift-dialog.tsx", 1637, "OverrideConfirmationDialog", "7bc46250935cbd61a60e547f3109e2d80b45739d621dd89100010bda241c06b1", "overrideOpen", "setOverrideOpen", "state 528; handleSubmit 776; setter 792; form trigger 902; open 1638"),
    ("resources/js/pages/operations/shifts/index.tsx", 844, "CreateShiftDialog", "c5a21e407b1efd369547c7eced7ba4cca6b6caaf4eaa4e03a0797ba8162ead3f", "createOpen", "setCreateOpen", "state 186; openCreate 220; setter 222; trigger 218; open 845"),
    ("resources/js/pages/operations/timesheets/index.tsx", 822, "CreateTimesheetDialog", "57f1a586e42ca4392e8362ce96e6a8532f0aa1c5cd88ffebc7d2b55782e69a83", "createOpen", "setCreateOpen", "state 439; callback/setter 567-569; trigger 222; open 823"),
    ("resources/js/pages/privacy/dashboard.tsx", 336, "PrivacyActionModal", "35f9c3ed5bbcd076485706ed0b893028d61b84ab116c05e85cc0c9d573940862", "rowAction", "setRowAction", "state 94; destructive action/setter 168; bind/open 336"),
    ("resources/js/pages/sites/meal-planner/_calendar-grid.tsx", 1340, "ResidentEditDialog", "a5e94f1675f3300030c1b546b8bf1c64dd8760cff7fe6b8b4cb88f77d66f8ce0", "editResident", "setEditResident", "state 955; callback/setter 1209; trigger 521; bind 1339"),
    ("resources/js/pages/sites/meal-planner/_calendar-grid.tsx", 1343, "MoveMealDialog", "a5e94f1675f3300030c1b546b8bf1c64dd8760cff7fe6b8b4cb88f77d66f8ce0", "moveTarget", "setMoveTarget", "state 956; selector 251; trigger 265; handler/setter 1029-1036; bind 1342"),
    ("resources/js/pages/sites/meal-planner/index.tsx", 583, "SpendReportDialog", "7708fd1411ec1b4499669592cc933595256f318fd4361528bfe3aa622883773b", "spendOpen", "setSpendOpen", "state 94; callback/setter 446; trigger 1131; bind 583"),
    ("resources/js/pages/sites/show.tsx", 4507, "SiteGeofenceDialog", "95901651849efd4a5e71132c05b6e8af97b8d1b0628f2d67f84bbf68b45a99e9", "siteGeofenceOpen", "setSiteGeofenceOpen", "state 990; callback/setter 1774-1775; trigger 115; open 4514"),
]


def sha_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def sha_lines(lines: list[str]) -> str:
    return hashlib.sha256("\n".join(lines).encode("utf-8")).hexdigest()


def load(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: Any) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")


def record(path: Path) -> dict[str, Any]:
    return {"path": path.relative_to(AUDIT).as_posix(), "sha256": sha_file(path), "bytes": path.stat().st_size}


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


if all(path.exists() for path in (VISUAL06, OVERLAY5, SUMMARY)):
    classifier = load(CLASSIFIER)
    require(classifier["custom_usage_layer"]["exact_trigger_resolved"] == 203, "Existing overlay count drift")
    pointer = load(POINTER)
    for key, path in (("visual_wave904_06", VISUAL06), ("overlay_trigger_wave5", OVERLAY5),
                      ("visual_wave06_overlay5_generation_summary", SUMMARY),
                      ("overlay_trigger_classification", CLASSIFIER)):
        require(pointer["artifacts"][key] == record(path), f"Existing pointer drift: {key}")
    print(json.dumps({"status": "already_applied", "visual06": record(VISUAL06), "overlay5": record(OVERLAY5), "classifier": record(CLASSIFIER)}, indent=2))
    raise SystemExit(0)

require(not any(path.exists() for path in (VISUAL06, OVERLAY5, SUMMARY)), "Partial output set exists")
for path, expected, label in ((MANIFEST, PINS["manifest"], "manifest"), (INVENTORY, PINS["inventory"], "inventory"),
                              (VISUAL, PINS["visual"], "visual matrix"), (CLASSIFIER, PINS["classifier"], "overlay classifier")):
    require(sha_file(path) == expected, f"{label} input drift")
require(sha_lines(VISUAL_IDS) == "4767152706c40530ed9f36cef1232704893207be1c35777f93e922e4169cbc1a", "Visual selection drift")
require(sha_lines(sorted(VISUAL_IDS)) == "75f4886251dcf45f8926c6ca778e3c6c9a0199cec8596e21259ad8df9d6804f6", "Visual set drift")

visual06 = {
    "schema_version": "1.0.0", "artifact": "visual-final-id-adjudication-904-wave06",
    "generated_at": GENERATED_AT, "audited_commit": AUDITED_COMMIT,
    "status": "independently_reviewed_zero_promotion",
    "audit_boundary": "Static final-ID ownership adjudication only. No browser, runtime, screenshot, usability, finding, benchmark or completion credit is inferred.",
    "inputs": {"manifest": record(MANIFEST), "inventory": record(INVENTORY), "visual_matrix": record(VISUAL)},
    "selection": {"rule": "Exclude 132 reviewed IDs; exhaust remaining three-route rows then take first 16 exact-controller four-route rows; every exact owner intersection is empty and no singleton page fallback exists.",
                  "exclusion_count": 132, "exclusion_sha256": "dc02634beb28e7a592e751ce4230e9d1cf4ec56bcfcd782594e164a9cd0fcf1e",
                  "ordered_ids": VISUAL_IDS, "ordered_sha256": "4767152706c40530ed9f36cef1232704893207be1c35777f93e922e4169cbc1a",
                  "sorted_sha256": "75f4886251dcf45f8926c6ca778e3c6c9a0199cec8596e21259ad8df9d6804f6",
                  "proof_tuple_sha256": "46c1eec5443a52e3ecc21dd2e489fca1c3284c9cb3e35ee4aeb424ca514ffdac",
                  "source_map_sha256": "8de7ac50470fad0fda30a60df1949998cd03d4a8ba37dec695d4f4a04178352e"},
    "adjudications": [{"visual_id": row, "verdict": "RETAIN_UNRESOLVED", "reason": "Exact route owners have an empty final-ID intersection and no singleton exact page fallback.", "runtime_credit": 0} for row in VISUAL_IDS],
    "count_delta": {"visual_assigned": 0, "visual_unresolved": 0, "material_assigned": 0, "material_unresolved": 0, "runtime_credit": 0},
    "post_counts": {"visual_assigned": 8168, "visual_rows": 8753, "visual_unresolved": 585, "material_assigned": 3948, "material_rows": 4312, "material_unresolved": 364, "unique_assigned_targets": 774},
}
write_json(VISUAL06, visual06)

selection = [f"{file}:{line}:{symbol}" for file, line, symbol, *_ in OVERLAY_ROWS]
require(sha_lines(selection) == "0a5e481ac5e1c3f251f92a24c33b9288d441bf6a44d7c4e2a2d5e1177f35684f", "Overlay selection drift")
classifier = load(CLASSIFIER)
by_key = {(str(row["file"]), int(row["line"]), str(row["symbol"])): row for row in classifier["custom_rows"]}
adjudications = []
for file, line, symbol, source_sha, state, setter, chain in OVERLAY_ROWS:
    key = (file, line, symbol)
    require(key in by_key, f"Missing overlay row: {key}")
    row = by_key[key]
    require(row["trigger_classification"] == "internal_state_trigger_candidate" and row["trigger_resolution"] == "source_inferred_not_exactly_paired", f"Overlay state drift: {key}")
    require(row.get("state_identifier") == state and row.get("setter_identifier") == setter, f"Overlay identifier drift: {key}")
    require(sha_file(ROOT / file) == source_sha, f"Overlay source drift: {file}")
    evidence = {"kind": "named_state_open_handler", "file": file, "source_sha256": source_sha, "state_identifier": state, "setter": setter, "static_chain": chain, "credit_boundary": "static pairing only"}
    row["trigger_classification"] = "same_scope_state_handler"
    row["trigger_resolution"] = "resolved"
    row["trigger_evidence"] = [evidence]
    adjudications.append({"file": file, "line": line, "symbol": symbol, "verdict": "GO_exact_static_trigger_pairing", "evidence": evidence})

class_counts = Counter(str(row["trigger_classification"]) for row in classifier["custom_rows"])
resolution_counts = Counter(str(row["trigger_resolution"]) for row in classifier["custom_rows"])
require(class_counts["same_scope_state_handler"] == 184 and class_counts["internal_state_trigger_candidate"] == 183, "Overlay class-count drift")
require(resolution_counts["resolved"] == 203 and resolution_counts["source_inferred_not_exactly_paired"] == 183, "Overlay resolution-count drift")
require(class_counts["controlled_without_resolved_activator"] == 256 and resolution_counts["unresolved"] == 271, "Unrelated overlay-count drift")
classifier["custom_usage_layer"].update({"exact_trigger_resolved": 203, "source_inferred_not_exactly_paired": 183, "unresolved_or_blocked": 273, "classification_counts": dict(sorted(class_counts.items())), "resolution_counts": dict(sorted(resolution_counts.items()))})

overlay5 = {
    "schema_version": "1.0.0", "artifact": "overlay-trigger-adjudication-904-wave5",
    "generated_at": GENERATED_AT, "audited_commit": AUDITED_COMMIT,
    "status": "independently_reviewed_exact_static_pairings_runtime_unchanged",
    "audit_boundary": "Static source trigger pairing only. Runtime, focus, keyboard, viewport, visibility, permission, usability and completion credit remain zero.",
    "method": {"selection_sha256": "0a5e481ac5e1c3f251f92a24c33b9288d441bf6a44d7c4e2a2d5e1177f35684f", "exclusion_count": 40, "exclusion_sha256": "50f668c96a466532e2b199ba99dc2d8b42d4a091dd29b671a7e05a5bf9dc14f8", "independent_proof_tuple_sha256": "88910b30bcfdd5e3eef38755ef2e5bae3d02f01396fffe04ffa91396286f02e2", "independent_source_map_sha256": "beb0ce09b7f5c34bb58ab3cc8264bbc51acd1890e7c409e2d153bef21d5d791c", "verdict": "GO_10_exact_static_trigger_pairings"},
    "adjudications": adjudications,
    "count_delta": {"exact_trigger_resolved": 10, "source_inferred_not_exactly_paired": -10, "same_scope_state_handler": 10, "internal_state_trigger_candidate": -10, "runtime_credit": 0},
    "post_counts": {"custom_denominator": 659, "exact_trigger_resolved": 203, "source_inferred_not_exactly_paired": 183, "unresolved_or_blocked": 273, "same_scope_state_handler": 184, "internal_state_trigger_candidate": 183, "controlled_without_resolved_activator": 256, "generic_unresolved": 271, "primitive_denominator": 477, "primitive_exact_trigger_resolved": 208, "primitive_unresolved": 269},
}
write_json(OVERLAY5, overlay5)
write_json(CLASSIFIER, classifier)

summary = {"schema_version": "1.0.0", "artifact": "final-904-visual-wave06-overlay5-generation-summary", "generated_at": GENERATED_AT, "audited_commit": AUDITED_COMMIT,
           "outputs": {"visual_wave06": record(VISUAL06), "overlay_wave5": record(OVERLAY5), "overlay_classifier": record(CLASSIFIER)},
           "counts": {"visual_assigned": 8168, "visual_unresolved": 585, "material_assigned": 3948, "material_unresolved": 364, "overlay_exact_resolved": 203, "overlay_unresolved_or_blocked": 273, "primitive_exact_resolved": 208, "primitive_unresolved": 269},
           "credit_boundary": {"visual_final_id_promotions": 0, "overlay_static_pairings": 10, "runtime_credit_delta": 0, "browser_credit_delta": 0, "completion_credit_delta": 0}}
write_json(SUMMARY, summary)

pointer = load(POINTER)
pointer["generated_at"] = max(pointer.get("generated_at", ""), GENERATED_AT)
pointer["artifacts"].update({"visual_wave904_06": record(VISUAL06), "overlay_trigger_wave5": record(OVERLAY5), "overlay_trigger_classification": record(CLASSIFIER), "visual_wave06_overlay5_generation_summary": record(SUMMARY)})
pointer["completion_status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"
pointer["runtime_credit_delta"] = 0
write_json(POINTER, pointer)

print(json.dumps({"status": "applied", "visual06": record(VISUAL06), "overlay5": record(OVERLAY5), "classifier": record(CLASSIFIER), "summary": record(SUMMARY), "pointer": record(POINTER)}, indent=2))
