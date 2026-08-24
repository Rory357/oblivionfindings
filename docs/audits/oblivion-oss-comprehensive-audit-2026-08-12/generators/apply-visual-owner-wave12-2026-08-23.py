#!/usr/bin/env python3
"""Apply the read-only-reviewed zero-promotion visual ownership Wave 12."""

from __future__ import annotations

import csv
import hashlib
import json
import subprocess
from collections import Counter
from pathlib import Path
from typing import Any


GENERATOR = Path(__file__).resolve()
AUDIT = GENERATOR.parent.parent
SOURCE = AUDIT / "evidence" / "source"
AUDITED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
GENERATED_AT = "2026-08-23T16:32:00+12:00"

VISUAL = AUDIT / "05-browser-visual-coverage-matrix-904.csv"
VISUAL_ALIAS = AUDIT / "05-browser-visual-coverage-matrix.csv"
SUMMARY = SOURCE / "final-904-visual-link-generation-summary.json"
POINTER = SOURCE / "canonical-audit-inputs.json"
ADJUDICATION = SOURCE / "visual-final-id-adjudication-904-wave12.json"

INPUT_SHA256 = "987dbdc74f3412a9fe75e801e1010de31f9df389b1eb44201aa940c137e825f0"
SELECTED_IDS = [
    *(f"VIS-{number:06d}" for number in range(569, 573)),
    *(f"VIS-{number:06d}" for number in range(925, 929)),
    *(f"VIS-{number:06d}" for number in range(1165, 1173)),
    *(f"VIS-{number:06d}" for number in range(1181, 1185)),
]
ORDERED_ID_SHA256 = "9362b1455635e6d7210364a369dab0afcaff041a7f130afb7fafe9989f8c98eb"

INCIDENT_TARGETS = (
    "CAP-INC-INCIDENT-AUTHOR|CAP-INC-INCIDENT-EVIDENCE-MANAGEMENT|"
    "CAP-INC-INCIDENT-FOLLOWUP|CAP-INC-INCIDENT-REVIEW-CLOSURE"
)
CALENDAR_TARGETS = (
    "CAP-HR-CALENDAR-EVENT-MANAGEMENT|CAP-HR-CALENDAR-FEED|"
    "CAP-HR-CALENDAR-PARTICIPATION"
)

SOURCE_PINS = {
    "routes/fleet-assets.php": "fe035d667c199077369bff7a43a875e5e1777933451dbff67f73d78a195080cd",
    "resources/js/pages/fleet-assets/settings/notifications.tsx": "bf33b5560f332b5f1ef42cfab3b0ff5c2a3746fd552e40d88302a8ed92e9b806",
    "routes/hr.php": "29a6d6f7d1f733c633995fdbbdc7bc66d3debbf4cd6073e27b66d0cd71c843fb",
    "app/Http/Controllers/Hr/CalendarController.php": "35ebbeccc4f783dc8adf5e5c12b0af0338576c0ca783c70d9d9cf07460fec6b4",
    "resources/js/pages/hr/calendar/index.tsx": "a0e00dec21bf6f91ed218da1b55aca1cc0012216480f9208891d283b0840104d",
    "routes/incidents.php": "6f66cbc8ecf17ad668b87d51aa95a4efa71d718187a10aff7ccced9c72c6ea04",
    "app/Http/Controllers/IncidentController.php": "91b44b3ae853d6060263a7a15964bef853b770a55097d22406588854ad93a885",
    "resources/js/pages/incidents/index.tsx": "20cbbaffa49118c393622758632ba6183f760296486df0fd458a38eb73f730ef",
    "resources/js/pages/incidents/show.tsx": "c66152a2407a4acadbac8dcc436697036780778170fe8f0408b07d07dae7cf69",
    "resources/js/components/incidents/incident-detail-dialog.tsx": "af2cb54ac22e6f6273acf64a1d652f3282c90d05e47da342dc70e7ba5b1be154",
    "routes/web.php": "85a5d4eb667019d82d99b4b5347541218582584874c01e08d4b077b76cfaaafb",
    "resources/js/pages/internal/_design/page-hero.tsx": "d57cd7e18493d7b754182acd07bdef78dc04d027691507a76d1879575af1d282",
}

SOURCE_LOCI = {
    "routes/fleet-assets.php": "L96-L99",
    "resources/js/pages/fleet-assets/settings/notifications.tsx": "L154-L173,L278-L285",
    "routes/hr.php": "L1111-L1120",
    "app/Http/Controllers/Hr/CalendarController.php": "L38-L102,L179,L223,L498",
    "resources/js/pages/hr/calendar/index.tsx": "L224-L317,L425-L538",
    "routes/incidents.php": "L45-L96",
    "app/Http/Controllers/IncidentController.php": "L33-L278,L693-L700",
    "resources/js/pages/incidents/index.tsx": "L240-L277,L344-L492",
    "resources/js/pages/incidents/show.tsx": "L6-L21",
    "resources/js/components/incidents/incident-detail-dialog.tsx": "L154-L351,L557-L715",
    "routes/web.php": "L116-L127",
    "resources/js/pages/internal/_design/page-hero.tsx": "L57-L96,L267-L268",
}


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def sha256_bytes(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def sha256(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def record(path: Path) -> dict[str, Any]:
    return {
        "path": path.relative_to(AUDIT).as_posix(),
        "sha256": sha256(path),
        "bytes": path.stat().st_size,
    }


def load_json(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8-sig"))


def write_json(path: Path, value: Any) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")


def semantic_tuple(rows: list[dict[str, str]]) -> str:
    fields = (
        "visual_id",
        "legacy_feature_id",
        "feature_id",
        "working_feature_ids",
        "feature_link_status",
        "feature_link_evidence",
    )
    unit = "\x1f"
    payload = "\n".join(unit.join(row[field] for field in fields) for row in rows)
    return sha256_bytes(payload.encode("utf-8"))


head = subprocess.run(
    ["git", "rev-parse", "HEAD"],
    cwd=AUDIT,
    check=True,
    stdout=subprocess.PIPE,
    stderr=subprocess.PIPE,
    text=True,
).stdout.strip()
require(head == AUDITED_COMMIT, f"Audited checkout drift: {head}")
require(sha256(VISUAL) == INPUT_SHA256, "Visual Wave 12 input drift")
require(sha256_bytes("\n".join(SELECTED_IDS).encode("utf-8")) == ORDERED_ID_SHA256, "Selection identity drift")

source_records: list[dict[str, Any]] = []
for path, expected in SOURCE_PINS.items():
    raw = subprocess.run(
        ["git", "show", f"{AUDITED_COMMIT}:{path}"],
        cwd=AUDIT,
        check=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    ).stdout
    actual = sha256_bytes(raw)
    require(actual == expected, f"Audited source SHA drift: {path} {actual}")
    source_records.append({"path": path, "sha256": actual, "loci": SOURCE_LOCI[path]})

with VISUAL.open("r", encoding="utf-8-sig", newline="") as handle:
    reader = csv.DictReader(handle)
    headers = list(reader.fieldnames or [])
    rows = [dict(row) for row in reader]

require(len(headers) == 22 and len(rows) == 8753, "Visual matrix shape drift")
require(len({row["visual_id"] for row in rows}) == 8753, "Duplicate VISUAL-ID")
by_id = {row["visual_id"]: row for row in rows}
require(set(SELECTED_IDS).issubset(by_id), "Selected visual row missing")

fleet_evidence = (
    "ROUTE-0821 and PAGE-0252 are explicitly excluded from the accepted 904 capability denominator: "
    "reachable authenticated UI whose toggles and Save change transient client state only; no persistence request, "
    "backend owner, candidate target or final FEATURE-ID exists "
    "(SURFACE-ROUTE-0821-REACHABLE-UI-ONLY-NONPERSISTENT-NOOP; SURFACE-PAGE-0252-DEAD-OR-NOOP)."
)
calendar_evidence = (
    "ROUTE-1296 and PAGE-0408 are exact-shared by CAP-HR-CALENDAR-EVENT-MANAGEMENT|"
    "CAP-HR-CALENDAR-PARTICIPATION; the page source also loads ROUTE-1304 owned by CAP-HR-CALENDAR-FEED. "
    "The initial page envelope is not an event-management action, participation action or feed response, so no "
    "singleton final owner is proved."
)
incident_index_evidence = (
    f"ROUTE-1838 and PAGE-0526 are exact-shared by {INCIDENT_TARGETS}. The initial register/detail envelope "
    "exposes distinct authoring, evidence, follow-up and review/closure jobs; the retired legacy label "
    "CAP-INC-INCIDENT-CORRECTIVE-HANDOFF is not a final 904 key, so no singleton final owner is proved."
)
incident_show_evidence = incident_index_evidence.replace("ROUTE-1838 and PAGE-0526", "ROUTE-1840 and PAGE-0527")
hero_evidence = (
    "ROUTE-1861 and PAGE-0531 are explicitly excluded from the accepted 904 capability denominator as "
    "infrastructure_or_out_of_product. The admin/local PageHero design-system showcase has demo controls but no "
    "product action, persisted data owner, candidate target or final FEATURE-ID "
    "(SURFACE-ROUTE-1861-2497-2943-2944-3010-INFRASTRUCTURE-OR-OUT-OF-PRODUCT; "
    "SURFACE-PAGE-0531-INFRASTRUCTURE-OR-OUT-OF-PRODUCT)."
)

changes: list[dict[str, Any]] = []
for visual_id in SELECTED_IDS:
    row = by_id[visual_id]
    old = {key: row[key] for key in ("feature_id", "working_feature_ids", "feature_link_status", "feature_link_evidence")}
    require(row["feature_id"] == "" and row["pattern_type"] == "page/component", f"Unexpected selected row: {visual_id}")

    number = int(visual_id.removeprefix("VIS-"))
    if 569 <= number <= 572:
        require(row["working_feature_ids"] == "" and row["feature_link_status"] == "unresolved_no_manifest_lineage", f"Fleet prior drift: {visual_id}")
        row["feature_link_evidence"] = fleet_evidence
        group = "fleet_notifications_excluded_noop"
    elif 925 <= number <= 928:
        require(row["working_feature_ids"] == CALENDAR_TARGETS and row["feature_link_status"] == "unresolved_split_family_page_ambiguous", f"Calendar prior drift: {visual_id}")
        row["feature_link_evidence"] = calendar_evidence
        group = "hr_calendar_shared_envelope"
    elif 1165 <= number <= 1168:
        require(row["working_feature_ids"] == "" and row["feature_link_status"] == "unresolved_no_manifest_lineage", f"Incident index prior drift: {visual_id}")
        row["working_feature_ids"] = INCIDENT_TARGETS
        row["feature_link_status"] = "unresolved_split_family_page_ambiguous"
        row["feature_link_evidence"] = incident_index_evidence
        group = "incident_index_shared_envelope"
    elif 1169 <= number <= 1172:
        require(row["working_feature_ids"] == "" and row["feature_link_status"] == "unresolved_no_manifest_lineage", f"Incident show prior drift: {visual_id}")
        row["working_feature_ids"] = INCIDENT_TARGETS
        row["feature_link_status"] = "unresolved_split_family_page_ambiguous"
        row["feature_link_evidence"] = incident_show_evidence
        group = "incident_show_shared_envelope"
    else:
        require(1181 <= number <= 1184, f"Unexpected selection identity: {visual_id}")
        require(row["working_feature_ids"] == "" and row["feature_link_status"] == "unresolved_no_manifest_lineage", f"PageHero prior drift: {visual_id}")
        row["feature_link_evidence"] = hero_evidence
        group = "pagehero_showcase_excluded_infrastructure"

    new = {key: row[key] for key in old}
    changes.append({"visual_id": visual_id, "group": group, "old": old, "new": new})

status_counts = Counter(row["feature_link_status"] for row in rows)
require(status_counts["unresolved_no_manifest_lineage"] == 250, "Wave 12 no-lineage count drift")
require(status_counts["unresolved_split_family_page_ambiguous"] == 246, "Wave 12 ambiguous count drift")
require(status_counts["unresolved_split_source_family"] == 89, "Wave 12 split-family count drift")
require(sum(bool(row["feature_id"]) for row in rows) == 8168, "Wave 12 changed visual ownership")
material = [row for row in rows if row["pattern_type"] == "material-state-applicability"]
require(sum(bool(row["feature_id"]) for row in material) == 3948, "Wave 12 changed material ownership")

with VISUAL.open("w", encoding="utf-8", newline="") as handle:
    writer = csv.DictWriter(handle, fieldnames=headers, extrasaction="raise", lineterminator="\n")
    writer.writeheader()
    writer.writerows(rows)
VISUAL_ALIAS.write_bytes(VISUAL.read_bytes())

adjudication = {
    "schema_version": "1.0",
    "artifact": "visual-final-id-adjudication-904-wave12",
    "generated_at": GENERATED_AT,
    "audited_commit": AUDITED_COMMIT,
    "reviewer": "/root/visual_owner_wave",
    "status": "reviewed_zero_promotion_exact_blockers_added",
    "selection": {
        "rows": 20,
        "visual_ids": SELECTED_IDS,
        "ordered_id_sha256": ORDERED_ID_SHA256,
        "rule": "Earliest 20 unresolved VISUAL-ID rows in existing matrix order.",
    },
    "sources": source_records,
    "changes": changes,
    "post_counts": {
        "rows": 8753,
        "assigned_final_feature_id": 8168,
        "unresolved_final_feature_id": 585,
        "material_assigned": 3948,
        "material_unresolved": 364,
        "unresolved_status_counts": {
            "unresolved_no_manifest_lineage": 250,
            "unresolved_split_family_page_ambiguous": 246,
            "unresolved_split_source_family": 89,
        },
    },
    "credit_boundary": {
        "final_id_promotions": 0,
        "material_id_promotions": 0,
        "browser_credit_delta": 0,
        "runtime_credit_delta": 0,
        "completion_credit_delta": 0,
    },
}
write_json(ADJUDICATION, adjudication)

summary = load_json(SUMMARY)
summary["generated_at"] = GENERATED_AT
summary["counts"]["status_counts"] = dict(sorted(status_counts.items()))
summary["outputs"]["matrix_sha256"] = sha256(VISUAL)
summary["outputs"]["semantic_tuple_sha256"] = semantic_tuple(rows)
summary["wave12"] = {
    "adjudication": record(ADJUDICATION),
    "reviewed_rows": 20,
    "promoted": 0,
    "material_rows": 0,
    "generic_blockers_replaced": 20,
    "claim_limit": "Exact final-ID ownership blockers only. No browser, runtime, material-state execution, usability or completion credit changed.",
}
write_json(SUMMARY, summary)

pointer = load_json(POINTER)
pointer["artifacts"]["visual_wave904_12"] = record(ADJUDICATION)
pointer["artifacts"]["visual_matrix"] = record(VISUAL)
pointer["artifacts"]["visual_generation_summary"] = record(SUMMARY)
write_json(POINTER, pointer)

print(json.dumps({
    "adjudication": record(ADJUDICATION),
    "visual_matrix": record(VISUAL),
    "visual_summary": record(SUMMARY),
    "semantic_tuple_sha256": summary["outputs"]["semantic_tuple_sha256"],
    "status_counts": dict(sorted(status_counts.items())),
    "promotions": 0,
}, ensure_ascii=False, indent=2))
