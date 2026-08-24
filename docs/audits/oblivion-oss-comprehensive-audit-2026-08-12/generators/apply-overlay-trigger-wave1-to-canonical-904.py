#!/usr/bin/env python3
"""Apply independently reviewed exact custom-overlay trigger pairings to the 904 audit."""

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
GENERATED_AT = "2026-08-21T18:35:00+12:00"
AUDITED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"

CLASSIFIER = SOURCE / "overlay-trigger-classification.json"
ARTIFACT = SOURCE / "overlay-trigger-adjudication-904-wave1.json"
POINTER = SOURCE / "canonical-audit-inputs.json"

INPUT_CLASSIFIER_SHA = "38182baa52a521729b876ffae6c59e7c39cf054419cd496d0c835f572b2e4f26"
INDEPENDENT_SELECTED_SHA = "59dad82256002c2e580d777347cb90722caac4cfbc3e38a53778d749278c5f9d"
INDEPENDENT_PROOF_SHA = "839d4ba74a6626d3baf95598b5e4d55addfabdb2cfda5e52a1c4ac1b96b9396f"
INDEPENDENT_SOURCE_MAP_SHA = "4dfbb9f11568ea62302c1f2b03e9a841afdb187ed135ed169e628bb3fa8b2166"


ROWS = [
    {
        "file": "resources/js/components/clinical/shift-observations-due-card.tsx",
        "line": 152,
        "symbol": "ObservationRecordSheet",
        "source_sha256": "afcceba695681b1dec9fb21538fae251777bbacdb0cfdee3ec59803f1a64ae59",
        "state": "sheetOpen",
        "setter": "setSheetOpen",
        "state_line": 33,
        "handler": "handleRecord",
        "handler_line": 59,
        "setter_line": 62,
        "trigger": "Button onClick={() => handleRecord(item)}",
        "trigger_line": 141,
        "open_contract": "open={sheetOpen}",
        "open_line": 155,
    },
    {
        "file": "resources/js/pages/emar/Handovers.tsx",
        "line": 298,
        "symbol": "HandoverWizard",
        "source_sha256": "478c73d86a49b9ba0b494dce80da1bbd904136aeae5b6e5ad8a243e6de31ac8c",
        "state": "wizardOpen",
        "setter": "setWizardOpen",
        "state_line": 78,
        "handler": "openNew",
        "handler_line": 161,
        "setter_line": 161,
        "trigger": "Button invokes openNew",
        "trigger_line": 212,
        "open_contract": "open={wizardOpen}",
        "open_line": 299,
    },
    {
        "file": "resources/js/pages/fleet-assets/incidents/index.tsx",
        "line": 516,
        "symbol": "FleetIncidentReportDialog",
        "source_sha256": "9b3d2d4c4fa399d64950c23a777f8b1b6e8630dfafa2be33a514584bc73e9eda",
        "state": "reportMode",
        "setter": "setReportMode",
        "state_line": 205,
        "handler": "openReport",
        "handler_line": 208,
        "setter_line": 210,
        "trigger": "menu buttons invoke openReport",
        "trigger_line": 445,
        "open_contract": "conditional mount with literal open",
        "open_line": 517,
    },
    {
        "file": "resources/js/pages/health-safety/restraints/index.tsx",
        "line": 392,
        "symbol": "RestraintEventWizard",
        "source_sha256": "8f5baa0a801a69cea725b3ee8847b820bb31828c9edcdd19866c0784d8d665a9",
        "state": "eventWizard",
        "setter": "setEventWizard",
        "state_line": 96,
        "handler": "openEventWizard",
        "handler_line": 123,
        "setter_line": 125,
        "trigger": "Button invokes openEventWizard",
        "trigger_line": 343,
        "open_contract": "open={eventWizard}",
        "open_line": 393,
    },
    {
        "file": "resources/js/pages/health-safety/substances/index.tsx",
        "line": 416,
        "symbol": "SubstanceWizardDialog",
        "source_sha256": "0a92b8968395496c3f317edc073c134e49c3b9b6c16ce879db7e287dda1c2a17",
        "state": "wizardOpen",
        "setter": "setWizardOpen",
        "state_line": 170,
        "handler": "startAdd",
        "handler_line": 189,
        "setter_line": 191,
        "trigger": "Button invokes startAdd",
        "trigger_line": 339,
        "open_contract": "conditional mount with literal open",
        "open_line": 417,
    },
    {
        "file": "resources/js/pages/hr/announcements/index.tsx",
        "line": 456,
        "symbol": "AnnouncementWizard",
        "source_sha256": "6a3508d6f2198ce8f0e5439abde7a55cf2af7f440822af51f8947dfd9008f5d1",
        "state": "wizardOpen",
        "setter": "setWizardOpen",
        "state_line": 231,
        "handler": "openComposer",
        "handler_line": 263,
        "setter_line": 265,
        "trigger": "buttons invoke openComposer",
        "trigger_line": 357,
        "open_contract": "open={wizardOpen}",
        "open_line": 457,
    },
    {
        "file": "resources/js/pages/hr/compensation/bands.tsx",
        "line": 1199,
        "symbol": "BandWizard",
        "source_sha256": "1eed2af4903eb0c431ac29eb8a30696d11863226ac97a704cd237f0d80856df2",
        "state": "wizardOpen",
        "setter": "setWizardOpen",
        "state_line": 991,
        "handler": "openCreate",
        "handler_line": 1018,
        "setter_line": 1021,
        "trigger": "buttons invoke openCreate",
        "trigger_line": 1122,
        "open_contract": "open={wizardOpen}",
        "open_line": 1201,
    },
    {
        "file": "resources/js/pages/hr/my/leave.tsx",
        "line": 383,
        "symbol": "LeaveRequestDialog",
        "source_sha256": "7bf542cdca0e01933297915790853b6e63b653942dd064bd245ebe5730a9dbea",
        "state": "wizardOpen",
        "setter": "setWizardOpen",
        "state_line": 176,
        "handler": "openNew",
        "handler_line": 188,
        "setter_line": 190,
        "trigger": "Button onClick invokes openNew",
        "trigger_line": 266,
        "open_contract": "open={wizardOpen}",
        "open_line": 385,
    },
    {
        "file": "resources/js/pages/operations/service-agreements/Show.tsx",
        "line": 1292,
        "symbol": "TransitionDialog",
        "source_sha256": "bcf0d9b7008ebe215313ab5a564639275c9ad4d1dfec4a58000d6b64b971d8a9",
        "state": "dialogOpen",
        "setter": "setDialogOpen",
        "state_line": 644,
        "handler": "openTransition",
        "handler_line": 661,
        "setter_line": 663,
        "trigger": "Button invokes openTransition",
        "trigger_line": 713,
        "open_contract": "open={dialogOpen}",
        "open_line": 1292,
    },
    {
        "file": "resources/js/pages/sites/calendar/SiteCalendar.tsx",
        "line": 1624,
        "symbol": "CreateEventDialog",
        "source_sha256": "e893262769297e15496d2f33d7ec573c98af2d61a6d935153137f99893a992d5",
        "state": "createOpen",
        "setter": "setCreateOpen",
        "state_line": 547,
        "handler": "openCreate",
        "handler_line": 888,
        "setter_line": 891,
        "trigger": "Button invokes openCreate",
        "trigger_line": 1218,
        "open_contract": "open={createOpen}",
        "open_line": 1625,
    },
]


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


selected_preimage = [f"{row['file']}:{row['line']}:{row['symbol']}" for row in ROWS]
require(sha_lines(selected_preimage) == INDEPENDENT_SELECTED_SHA, "Independent selected-row digest mismatch")

if ARTIFACT.exists():
    current = load(CLASSIFIER)
    if (
        current["custom_usage_layer"]["exact_trigger_resolved"] == 163
        and current["custom_usage_layer"]["source_inferred_not_exactly_paired"] == 218
    ):
        print(json.dumps({"status": "already_applied", "classifier": record(CLASSIFIER), "artifact": record(ARTIFACT)}, indent=2))
        raise SystemExit(0)

require(sha_file(CLASSIFIER) == INPUT_CLASSIFIER_SHA, "Overlay classifier input SHA drift")
classifier = load(CLASSIFIER)
require(classifier["audited_commit"] == AUDITED_COMMIT, "Audited commit drift")
require(len(classifier["custom_rows"]) == 659, "Custom-overlay denominator drift")

by_key: dict[tuple[str, int, str], dict[str, Any]] = {}
for row in classifier["custom_rows"]:
    key = (str(row["file"]), int(row["line"]), str(row["symbol"]))
    require(key not in by_key, f"Duplicate custom-overlay row: {key}")
    by_key[key] = row

adjudications: list[dict[str, Any]] = []
for proof in ROWS:
    key = (proof["file"], proof["line"], proof["symbol"])
    require(key in by_key, f"Missing custom-overlay row: {key}")
    row = by_key[key]
    require(row["trigger_classification"] == "internal_state_trigger_candidate", f"Classification drift: {key}")
    require(row["trigger_resolution"] == "source_inferred_not_exactly_paired", f"Resolution drift: {key}")
    require(row.get("state_identifier") == proof["state"], f"State drift: {key}")
    require(row.get("setter_identifier") == proof["setter"], f"Setter drift: {key}")

    source_path = ROOT / proof["file"]
    require(sha_file(source_path) == proof["source_sha256"], f"Source SHA drift: {proof['file']}")
    source_lines = source_path.read_text(encoding="utf-8").splitlines()
    for line_number in [proof["state_line"], proof["handler_line"], proof["setter_line"], proof["trigger_line"], proof["open_line"]]:
        require(1 <= line_number <= len(source_lines), f"Source line missing: {proof['file']}:{line_number}")
    require(proof["state"] in source_lines[proof["state_line"] - 1], f"State anchor drift: {proof['file']}")
    require(proof["setter"] in source_lines[proof["state_line"] - 1], f"State setter declaration drift: {proof['file']}")
    require(proof["handler"] in source_lines[proof["handler_line"] - 1], f"Handler anchor drift: {proof['file']}")
    require(proof["setter"] in source_lines[proof["setter_line"] - 1], f"Open setter anchor drift: {proof['file']}")
    require(proof["handler"] in source_lines[proof["trigger_line"] - 1], f"Trigger anchor drift: {proof['file']}")
    require(proof["symbol"] in source_lines[proof["line"] - 1], f"Overlay component anchor drift: {proof['file']}")
    require("open" in source_lines[proof["open_line"] - 1], f"Open contract drift: {proof['file']}")

    evidence = {
        "kind": "named_state_open_handler",
        "file": proof["file"],
        "state_identifier": proof["state"],
        "state_line": proof["state_line"],
        "handler": proof["handler"],
        "handler_line": proof["handler_line"],
        "setter": proof["setter"],
        "setter_line": proof["setter_line"],
        "trigger": proof["trigger"],
        "trigger_line": proof["trigger_line"],
        "open_contract": proof["open_contract"],
        "open_line": proof["open_line"],
        "source_sha256": proof["source_sha256"],
    }
    row["trigger_classification"] = "same_scope_state_handler"
    row["trigger_resolution"] = "resolved"
    row["trigger_evidence"] = [evidence]
    adjudications.append({
        "file": proof["file"],
        "line": proof["line"],
        "symbol": proof["symbol"],
        "verdict": "GO_exact_static_trigger_pairing",
        "evidence": evidence,
    })

class_counts = Counter(str(row["trigger_classification"]) for row in classifier["custom_rows"])
resolution_counts = Counter(str(row["trigger_resolution"]) for row in classifier["custom_rows"])
require(class_counts["same_scope_state_handler"] == 144, "Same-scope count drift")
require(class_counts["internal_state_trigger_candidate"] == 218, "Candidate count drift")
require(resolution_counts["resolved"] == 163, "Resolved count drift")
require(resolution_counts["source_inferred_not_exactly_paired"] == 218, "Source-inferred count drift")
require(resolution_counts["blocked_parent_prop_not_present_at_callsite"] + resolution_counts["unresolved"] == 278, "Unresolved count drift")

classifier["custom_usage_layer"].update({
    "exact_trigger_resolved": 163,
    "source_inferred_not_exactly_paired": 218,
    "unresolved_or_blocked": 278,
    "classification_counts": dict(sorted(class_counts.items())),
    "resolution_counts": dict(sorted(resolution_counts.items())),
})
classifier["custom_usage_layer"]["classification_percent"] = 100
write_json(CLASSIFIER, classifier)

artifact = {
    "schema_version": "1.0.0",
    "artifact": "overlay-trigger-adjudication-904-wave1",
    "generated_at": GENERATED_AT,
    "audited_commit": AUDITED_COMMIT,
    "status": "independently_reviewed_exact_static_pairings_runtime_unchanged",
    "audit_boundary": "Static custom-overlay trigger pairing only. No browser, runtime, focus, keyboard, permission, visibility, usability or completion credit is inferred.",
    "inputs": {"classifier_before": {"sha256": INPUT_CLASSIFIER_SHA, "rows": 659}},
    "method": {
        "selection_preimage": "Packet-order file:line:symbol rows joined by LF with no terminal LF, UTF-8 without BOM.",
        "selection_sha256": INDEPENDENT_SELECTED_SHA,
        "independent_proof_tuple_sha256": INDEPENDENT_PROOF_SHA,
        "independent_source_map_sha256": INDEPENDENT_SOURCE_MAP_SHA,
        "verdict": "GO_10_exact_same_file_named_state_open_handler_chains",
    },
    "adjudications": adjudications,
    "count_delta": {
        "exact_trigger_resolved": 10,
        "source_inferred_not_exactly_paired": -10,
        "same_scope_state_handler": 10,
        "internal_state_trigger_candidate": -10,
        "unresolved_or_blocked": 0,
        "runtime_credit": 0,
    },
    "post_counts": {
        "custom_denominator": 659,
        "exact_trigger_resolved": 163,
        "source_inferred_not_exactly_paired": 218,
        "unresolved_or_blocked": 278,
        "primitive_denominator": 477,
        "primitive_exact_trigger_resolved": 208,
    },
}
write_json(ARTIFACT, artifact)

pointer = load(POINTER)
pointer["generated_at"] = max(pointer.get("generated_at", ""), GENERATED_AT)
pointer["artifacts"]["overlay_trigger_classification"] = record(CLASSIFIER)
pointer["artifacts"]["overlay_trigger_wave1"] = record(ARTIFACT)
write_json(POINTER, pointer)

print(json.dumps({"status": "applied", "classifier": record(CLASSIFIER), "artifact": record(ARTIFACT), "pointer": record(POINTER)}, indent=2))
