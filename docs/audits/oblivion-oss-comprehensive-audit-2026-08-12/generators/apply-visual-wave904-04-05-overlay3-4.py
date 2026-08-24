#!/usr/bin/env python3
"""Record visual Waves 04/05 and apply independently reviewed overlay Waves 3/4."""

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
GENERATED_AT = "2026-08-21T21:30:00+12:00"
AUDITED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"

MANIFEST = SOURCE / "working-capability-manifest-904.json"
INVENTORY = AUDIT / "inventory-904.json"
VISUAL = AUDIT / "05-browser-visual-coverage-matrix-904.csv"
CLASSIFIER = SOURCE / "overlay-trigger-classification.json"
POINTER = SOURCE / "canonical-audit-inputs.json"
VISUAL04 = SOURCE / "visual-final-id-adjudication-904-wave04.json"
VISUAL05 = SOURCE / "visual-final-id-adjudication-904-wave05.json"
OVERLAY3 = SOURCE / "overlay-trigger-adjudication-904-wave3.json"
OVERLAY4 = SOURCE / "overlay-trigger-adjudication-904-wave4.json"
SUMMARY = SOURCE / "final-904-visual-wave04-05-overlay3-4-generation-summary.json"

PINS = {
    "manifest": "ffca48609deab9a8938105c857786594a9a5431c31efe329ef4288da6165358f",
    "reviewed_inventory_before_wave35": "579d2bde9e5f0d28ff1e912da354ec0244f6abe9eebaaf2eabf3c7ad3af2144e",
    "current_inventory_after_wave35": "598d76cd63b23a7ea49164ad43e12cb10afdb9fed8437807d8b50d20b090cb9b",
    "visual_matrix": "707885a83264c8e2ab3f92898578a2b20ba3e8a2ccdb3ece17156d8c0774c293",
    "overlay_classifier": "3eac285c870c67c114f768a1d9679e17abd7ad189fab45624b276da6669c020c",
}

VISUAL04_IDS = [
    "VIS-019391", "VIS-019728", "VIS-020247", "VIS-020634", "VIS-020636",
    "VIS-020642", "VIS-020660", "VIS-020683", "VIS-020730", "VIS-020892",
    "VIS-020894", "VIS-020897", "VIS-020957", "VIS-021347", "VIS-015203",
    "VIS-017873", "VIS-017994", "VIS-018581", "VIS-018582", "VIS-018583",
    "VIS-018692", "VIS-018695", "VIS-019116", "VIS-019117", "VIS-019118",
]

VISUAL05_IDS = [
    "VIS-019123", "VIS-019124", "VIS-019287", "VIS-019288", "VIS-019353",
    "VIS-019354", "VIS-020027", "VIS-020069", "VIS-020071", "VIS-020260",
    "VIS-020261", "VIS-020287", "VIS-020290", "VIS-020291", "VIS-020292",
    "VIS-020311", "VIS-020504", "VIS-021097", "VIS-021231", "VIS-015860",
    "VIS-017464", "VIS-017843", "VIS-018489", "VIS-018718", "VIS-018991",
]

VISUAL_META = {
    "wave04": {
        "ordered_sha256": "ca8236b0954d381ec327f622c3f33f0f9f74b09c2b3a4b311983ca879748a318",
        "sorted_sha256": "dd01093828f7feac69559fc05248023bab3e6962dbaf07b26a3b96c6e3c727cb",
        "exclusion_sha256": "55151532692dce11241c0ed01ba3b33d03d2c5a03c2ca182e31129a541e85dbd",
        "proof_sha256": "58514e5e09e0bd07571555af4727de79feb5980ab555f507d168f4a5f5e420ab",
        "source_map_sha256": "d95b24178842c7a583072b6d802762f338eec6098353e0129e30e200517112a2",
        "rule": "Exclude 82 reviewed IDs; take 14 remaining exact two-route/singleton-different-owner rows, then first 11 exact-controller three-route/singleton-owner rows; every owner intersection empty and no singleton page fallback.",
    },
    "wave05": {
        "ordered_sha256": "0c8085ad469220c213b03c0a943358e83c23c6e63d49e7b043ea8db931af898d",
        "sorted_sha256": "dceb6b3ed3d6ebd1fe0603db0ec8d2eaa7a131f37384c6ca5d3823ba8f11126e",
        "exclusion_sha256": "581df9e0569ed58aab6a462a551d17d6cf5d382d0d2f249e7f8acfb4ba285f72",
        "proof_sha256": "c91f632bd6d1c50f91da463fc2b12a121b15deed10b00f05a61bc177c65a777b",
        "source_map_sha256": "0d2abf9ceb194aadc47706aa65d9cf68dd2beffa50b449dde193a5c96c84089d",
        "rule": "Exclude 107 reviewed IDs; require three exact singleton-owner routes on one controller with every method present and an empty owner intersection; rank exact controller anchors then remaining rows and take 25.",
    },
}

OVERLAY3_ROWS = [
    ("resources/js/components/respite/workspace.tsx", 475, "RestraintEventWizard", "372f9df625ca14dfd8c9f2885e15afefb4d0ea9481d05c0163fac44bed4fe0b7", "state 138; callback/setter 401; child stays.tsx:267; bind 474/open 477"),
    ("resources/js/components/respite/workspace.tsx", 506, "ReasonDialog", "372f9df625ca14dfd8c9f2885e15afefb4d0ea9481d05c0163fac44bed4fe0b7", "state 147; onDecline/setter 342-343; child referrals.tsx:178; bind 505/open 507"),
    ("resources/js/components/rostering/calendar-pane.tsx", 1642, "PeekPopover", "db91992debab73eeb37e6e2d59eda7f0fd61bcff11a487ca1ea77e5585e17dcf", "state 972; onChipPeek/setter 1212-1219; intrinsic button 279-281; bind 1641"),
    ("resources/js/pages/health-safety/analytics.tsx", 843, "DrillModal", "ce3a441569662d3eadf49b90702fd6d23a8b4e9095daffd6487733b97c5e894a", "state 536; openDrill/setter 569; direct menu 574; render 843"),
    ("resources/js/pages/health-safety/dashboard.tsx", 261, "ReportIncidentDialog", "2a7210a9931e60fc62ebe622a193c8c2431887853c99303c8ff5f170dc5d10bd", "state 148; onWorkflow/setter 248-257; launcher incident 37/72; bind 260"),
    ("resources/js/pages/health-safety/dashboard.tsx", 266, "SubstanceWizardDialog", "2a7210a9931e60fc62ebe622a193c8c2431887853c99303c8ff5f170dc5d10bd", "state 148; onWorkflow/setter 248-257; launcher substance 43/72; bind 265"),
    ("resources/js/pages/health-safety/drills/index.tsx", 377, "DrillCompleteDialog", "708530f18c8be8f7ae18e738a301c534dfcbbebb5ed5100bd08ead24636c7fe2", "state 102; launchComplete/setter 127-128; direct menu 159; bind 376/open 377"),
    ("resources/js/pages/health-safety/risk-assessments/index.tsx", 412, "RaWizardDialog", "47fb2b22e65e242441bcb156fc71a6a97a1aa943dd0a3d452f54730d1e055a58", "state 122; openNew/setter 194-195; direct Button 343; bind 411"),
    ("resources/js/pages/hr/calendar/index.tsx", 892, "ICalSubscribeDialog", "a0e00dec21bf6f91ed218da1b55aca1cc0012216480f9208891d283b0840104d", "state 225; callback/setter 677; hero QuickAction 180; bind 892"),
    ("resources/js/pages/hr/calendar/index.tsx", 928, "CalendarDetailPopover", "a0e00dec21bf6f91ed218da1b55aca1cc0012216480f9208891d283b0840104d", "state 227; handleEventClick/setter 494-499; direct menu 552; bind 927"),
]

OVERLAY4_ROWS = [
    ("resources/js/pages/hr/calendar/index.tsx", 944, "QuickAddPopover", "a0e00dec21bf6f91ed218da1b55aca1cc0012216480f9208891d283b0840104d", "state 229; onAdd/setter 843; month-grid trigger 355; bind 943"),
    ("resources/js/pages/hr/recruitment/index.tsx", 609, "KitDialog", "835851c86852e419a28d9da07c3a7df5f5f769384f01a395f4b7639cbc4e89ee", "state 204; onNew/setter 559; intrinsic trigger 1449; bind/open 609"),
    ("resources/js/pages/hr/recruitment/index.tsx", 610, "ScoreDialog", "835851c86852e419a28d9da07c3a7df5f5f769384f01a395f4b7639cbc4e89ee", "state 205; onScore/setter 539; intrinsic trigger 1182; bind/open 610"),
    ("resources/js/pages/hr/recruitment/index.tsx", 611, "BulkEmailDialog", "835851c86852e419a28d9da07c3a7df5f5f769384f01a395f4b7639cbc4e89ee", "state 197; callback/setter 490; intrinsic trigger 828; open 611"),
    ("resources/js/pages/hr/recruitment/index.tsx", 612, "BulkRejectDialog", "835851c86852e419a28d9da07c3a7df5f5f769384f01a395f4b7639cbc4e89ee", "state 198; callback/setter 488; intrinsic trigger 837; open 612"),
    ("resources/js/pages/hr/recruitment/index.tsx", 613, "TagManagerDialog", "835851c86852e419a28d9da07c3a7df5f5f769384f01a395f4b7639cbc4e89ee", "state 199; callback/setter 484; intrinsic trigger 806; open 613"),
    ("resources/js/pages/my-day/_dialogs.tsx", 1063, "MealLogRecordDialog", "0734412c3590bab7a4e408b86aa4f2d27e193485f980a6e5cf0a90bcddd29697", "state 1004; onPick=setSelected 1045; resident tile 227; bind/open 1062/1067"),
    ("resources/js/pages/my-day/index.tsx", 739, "DatePopover", "b357700c0214a27d5aa79ff90e52e7db704476e1ce14c9900d0855558ccc6b8b", "state 138; callback/setter 736; StaffHeader button 138; bind 738"),
    ("resources/js/pages/operations/clients/index.tsx", 1690, "DailyNoteWizard", "50d94c614cbd2b00266dbc8a94a5cca7e3d1e951c3c35b3bb19165d380c9555d", "state 1133; context-menu action/setter 1319-1320; bind/open 1689/1692"),
    ("resources/js/pages/operations/handovers/Index.tsx", 275, "HandoverWizard", "9f52afb22328c1c5f3541b259593cdb0b759d6a045fec78783b1b56d57797da9", "state 54; openNew/setter 149-152; hero Button 250; bind/open 274/276"),
]

OVERLAY_META = {
    "wave3": {"selection_sha256": "290fd900e6068e047970f60fd96862e6c169d6c6fd69dc0c77dcd7be4a6c2f42",
              "proof_sha256": "47283e7b352d4f4447328fc802eca633ad779d9b35909c40dced1ae92ef6fa48",
              "source_map_sha256": "ad9aed02a45b8f18697b678b30eac767d66c894ce964dfd8e2ff41dc6a667dbd",
              "exclusion_sha256": "1cedf51984148e75067ed086b6a052d8f3710c808ffe209c55d4ff491833733f"},
    "wave4": {"selection_sha256": "effb162addc169f574859d312f4742a286d7787b975817141891df136ba2b966",
              "proof_sha256": "bff1a4f641a49a1f28f0ba608341464444cb9abae74cef8b5870c529eca98d51",
              "source_map_sha256": "5b7cd9684e4a0d963a81fda4fbbdc875c4dadda5e81a3df4d8370b88896bcaca",
              "exclusion_sha256": "e84ad29c18059147eba71f5f3f6d8b49c30c89d200fc731635acf61b1881185e"},
}


def sha_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def sha_lines(lines: list[str]) -> str:
    return hashlib.sha256("\n".join(lines).encode("utf-8")).hexdigest()


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


def visual_artifact(wave: str, selected: list[str], meta: dict[str, str]) -> dict[str, Any]:
    require(sha_lines(selected) == meta["ordered_sha256"], f"{wave} ordered selection drift")
    require(sha_lines(sorted(selected)) == meta["sorted_sha256"], f"{wave} sorted selection drift")
    return {
        "schema_version": "1.0.0", "artifact": f"visual-final-id-adjudication-904-{wave}",
        "generated_at": GENERATED_AT, "audited_commit": AUDITED_COMMIT,
        "status": "independently_reviewed_zero_promotion",
        "audit_boundary": "Static final-ID ownership adjudication only. No browser, runtime, screenshot, usability, finding, benchmark or completion credit is inferred.",
        "inputs": {"manifest": {"sha256": PINS["manifest"]},
                   "reviewed_inventory": {"sha256": PINS["reviewed_inventory_before_wave35"]},
                   "current_inventory_after_benchmark_only_wave35": record(INVENTORY),
                   "visual_matrix": record(VISUAL)},
        "selection": {"rule": meta["rule"], "exclusion_sha256": meta["exclusion_sha256"],
                      "ordered_ids": selected, "ordered_sha256": meta["ordered_sha256"],
                      "sorted_sha256": meta["sorted_sha256"], "proof_tuple_sha256": meta["proof_sha256"],
                      "source_map_sha256": meta["source_map_sha256"]},
        "adjudications": [{"visual_id": visual_id, "verdict": "RETAIN_UNRESOLVED",
                           "reason": "Exact route owners have an empty final-ID intersection and no singleton exact page fallback.",
                           "runtime_credit": 0} for visual_id in selected],
        "count_delta": {"visual_assigned": 0, "visual_unresolved": 0, "material_assigned": 0, "material_unresolved": 0, "runtime_credit": 0},
        "post_counts": {"visual_assigned": 8168, "visual_rows": 8753, "visual_unresolved": 585,
                        "material_assigned": 3948, "material_rows": 4312, "material_unresolved": 364,
                        "unique_assigned_targets": 774},
    }


def apply_overlay_wave(classifier: dict[str, Any], rows: list[tuple[str, int, str, str, str]], wave: str,
                       expected: dict[str, int]) -> dict[str, Any]:
    meta = OVERLAY_META[wave]
    selection = [f"{file}:{line}:{symbol}" for file, line, symbol, _, _ in rows]
    require(sha_lines(selection) == meta["selection_sha256"], f"Overlay {wave} selection drift")
    by_key = {(str(row["file"]), int(row["line"]), str(row["symbol"])): row for row in classifier["custom_rows"]}
    adjudications: list[dict[str, Any]] = []
    for file, line, symbol, source_sha, chain in rows:
        key = (file, line, symbol)
        require(key in by_key, f"Missing overlay row: {key}")
        row = by_key[key]
        require(row["trigger_classification"] == "internal_state_trigger_candidate", f"Overlay class drift: {key}")
        require(row["trigger_resolution"] == "source_inferred_not_exactly_paired", f"Overlay resolution drift: {key}")
        require(sha_file(ROOT / file) == source_sha, f"Overlay source drift: {file}")
        evidence = {"kind": "named_state_open_handler", "file": file, "source_sha256": source_sha,
                    "state_identifier": row.get("state_identifier"), "setter": row.get("setter_identifier"),
                    "static_chain": chain, "credit_boundary": "static pairing only"}
        row["trigger_classification"] = "same_scope_state_handler"
        row["trigger_resolution"] = "resolved"
        row["trigger_evidence"] = [evidence]
        adjudications.append({"file": file, "line": line, "symbol": symbol,
                              "verdict": "GO_exact_static_trigger_pairing", "evidence": evidence})
    class_counts = Counter(str(row["trigger_classification"]) for row in classifier["custom_rows"])
    resolution_counts = Counter(str(row["trigger_resolution"]) for row in classifier["custom_rows"])
    require(class_counts["same_scope_state_handler"] == expected["same_scope"], f"Overlay {wave} same-scope drift")
    require(class_counts["internal_state_trigger_candidate"] == expected["candidate"], f"Overlay {wave} candidate drift")
    require(resolution_counts["resolved"] == expected["resolved"], f"Overlay {wave} resolved drift")
    require(resolution_counts["source_inferred_not_exactly_paired"] == expected["source_inferred"], f"Overlay {wave} inferred drift")
    require(class_counts["controlled_without_resolved_activator"] == 256 and resolution_counts["unresolved"] == 271, f"Overlay {wave} unrelated-count drift")
    classifier["custom_usage_layer"].update({"exact_trigger_resolved": expected["resolved"],
                                               "source_inferred_not_exactly_paired": expected["source_inferred"],
                                               "unresolved_or_blocked": 273,
                                               "classification_counts": dict(sorted(class_counts.items())),
                                               "resolution_counts": dict(sorted(resolution_counts.items()))})
    return {
        "schema_version": "1.0.0", "artifact": f"overlay-trigger-adjudication-904-{wave}",
        "generated_at": GENERATED_AT, "audited_commit": AUDITED_COMMIT,
        "status": "independently_reviewed_exact_static_pairings_runtime_unchanged",
        "audit_boundary": "Static source trigger pairing only. Runtime, focus, keyboard, viewport, visibility, permission, usability and completion credit remain zero.",
        "method": {"selection_sha256": meta["selection_sha256"], "exclusion_sha256": meta["exclusion_sha256"],
                   "independent_proof_tuple_sha256": meta["proof_sha256"], "independent_source_map_sha256": meta["source_map_sha256"],
                   "verdict": "GO_10_exact_static_trigger_pairings"},
        "adjudications": adjudications,
        "count_delta": {"exact_trigger_resolved": 10, "source_inferred_not_exactly_paired": -10,
                        "same_scope_state_handler": 10, "internal_state_trigger_candidate": -10,
                        "runtime_credit": 0},
        "post_counts": {"custom_denominator": 659, "exact_trigger_resolved": expected["resolved"],
                        "source_inferred_not_exactly_paired": expected["source_inferred"], "unresolved_or_blocked": 273,
                        "same_scope_state_handler": expected["same_scope"], "internal_state_trigger_candidate": expected["candidate"],
                        "controlled_without_resolved_activator": 256, "generic_unresolved": 271,
                        "primitive_denominator": 477, "primitive_exact_trigger_resolved": 208, "primitive_unresolved": 269},
    }


if all(path.exists() for path in (VISUAL04, VISUAL05, OVERLAY3, OVERLAY4, SUMMARY)):
    classifier = load(CLASSIFIER)
    require(classifier["custom_usage_layer"]["exact_trigger_resolved"] == 193, "Existing overlay count drift")
    pointer = load(POINTER)
    for key, path in (("visual_wave904_04", VISUAL04), ("visual_wave904_05", VISUAL05),
                      ("overlay_trigger_wave3", OVERLAY3), ("overlay_trigger_wave4", OVERLAY4),
                      ("visual_wave04_05_overlay3_4_generation_summary", SUMMARY)):
        require(pointer["artifacts"][key] == record(path), f"Existing pointer drift: {key}")
    require(pointer["artifacts"]["overlay_trigger_classification"] == record(CLASSIFIER), "Existing classifier pointer drift")
    print(json.dumps({"status": "already_applied", "visual04": record(VISUAL04), "visual05": record(VISUAL05),
                      "overlay3": record(OVERLAY3), "overlay4": record(OVERLAY4), "classifier": record(CLASSIFIER)}, indent=2))
    raise SystemExit(0)

require(not any(path.exists() for path in (VISUAL04, VISUAL05, OVERLAY3, OVERLAY4, SUMMARY)), "Partial output set exists")
require(sha_file(MANIFEST) == PINS["manifest"], "Manifest input drift")
require(sha_file(INVENTORY) == PINS["current_inventory_after_wave35"], "Current inventory input drift")
require(sha_file(VISUAL) == PINS["visual_matrix"], "Visual matrix input drift")
require(sha_file(CLASSIFIER) == PINS["overlay_classifier"], "Overlay classifier input drift")

visual04 = visual_artifact("wave04", VISUAL04_IDS, VISUAL_META["wave04"])
visual05 = visual_artifact("wave05", VISUAL05_IDS, VISUAL_META["wave05"])
write_json(VISUAL04, visual04)
write_json(VISUAL05, visual05)

classifier = load(CLASSIFIER)
overlay3 = apply_overlay_wave(classifier, OVERLAY3_ROWS, "wave3", {"resolved": 183, "source_inferred": 203, "same_scope": 164, "candidate": 203})
write_json(OVERLAY3, overlay3)
overlay4 = apply_overlay_wave(classifier, OVERLAY4_ROWS, "wave4", {"resolved": 193, "source_inferred": 193, "same_scope": 174, "candidate": 193})
write_json(OVERLAY4, overlay4)
write_json(CLASSIFIER, classifier)

summary = {
    "schema_version": "1.0.0", "artifact": "final-904-visual-wave04-05-overlay3-4-generation-summary",
    "generated_at": GENERATED_AT, "audited_commit": AUDITED_COMMIT,
    "outputs": {"visual_wave04": record(VISUAL04), "visual_wave05": record(VISUAL05),
                "overlay_wave3": record(OVERLAY3), "overlay_wave4": record(OVERLAY4),
                "overlay_classifier": record(CLASSIFIER)},
    "counts": {"visual_assigned": 8168, "visual_unresolved": 585, "material_assigned": 3948, "material_unresolved": 364,
               "overlay_exact_resolved": 193, "overlay_unresolved_or_blocked": 273,
               "primitive_exact_resolved": 208, "primitive_unresolved": 269},
    "credit_boundary": {"visual_final_id_promotions": 0, "overlay_static_pairings": 20,
                        "runtime_credit_delta": 0, "browser_credit_delta": 0, "completion_credit_delta": 0},
}
write_json(SUMMARY, summary)

pointer = load(POINTER)
pointer["generated_at"] = max(pointer.get("generated_at", ""), GENERATED_AT)
pointer["artifacts"].update({"visual_wave904_04": record(VISUAL04), "visual_wave904_05": record(VISUAL05),
                             "overlay_trigger_wave3": record(OVERLAY3), "overlay_trigger_wave4": record(OVERLAY4),
                             "overlay_trigger_classification": record(CLASSIFIER),
                             "visual_wave04_05_overlay3_4_generation_summary": record(SUMMARY)})
pointer["completion_status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"
pointer["runtime_credit_delta"] = 0
write_json(POINTER, pointer)

print(json.dumps({"status": "applied", "visual04": record(VISUAL04), "visual05": record(VISUAL05),
                  "overlay3": record(OVERLAY3), "overlay4": record(OVERLAY4),
                  "classifier": record(CLASSIFIER), "summary": record(SUMMARY), "pointer": record(POINTER)}, indent=2))
