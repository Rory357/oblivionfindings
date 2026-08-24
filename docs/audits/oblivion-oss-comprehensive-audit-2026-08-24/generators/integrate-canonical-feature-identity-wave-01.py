#!/usr/bin/env python3
"""Materialize the reconciled static canonical feature denominator.

This deterministic generator reads only committed audit evidence. It does not
boot Oblivion Findings, execute application code, contact a network service, or
award runtime, browser, benchmark, ease, release, or completion credit.
"""

from __future__ import annotations

import csv
import hashlib
import io
import json
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any


AUDIT_DIR = Path(__file__).resolve().parents[1]
SOURCE_DIR = AUDIT_DIR / "evidence/source"
GENERATED_AT = "2026-08-24T20:30:00+12:00"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
AUDIT_INPUT_COMMIT = "9a50423dbc35e4d49ae6290d1fd90ba5e75e4fde"
BENCHMARK_WAVE_SHA256 = "a024bf1dffbf0608c3aaaa1026d461daf3f29e5c39f9a92882ce41c20b3ec138"

DISCOVERY_HASHES = {
    "current-feature-discovery-wave-01.json": "c422cfd9e4005c083518abe9e8837c16740e8797b249ffdd2a9f9e4e00ad2aeb",
    "current-feature-discovery-wave-02.json": "3a9404e19db17d46b88b13bf545f22e1fe4897b41cc119341f56197a8b321a71",
    "current-feature-discovery-wave-03.json": "7eec5a50d8f38184696a827bafb35c4e1c1f94fc8104abc7d075f502ce7f4c42",
}

RUN_FILES = {
    17: "raw-run-017-canonical-identity-frontline.json",
    18: "raw-run-018-canonical-identity-platform.json",
    19: "raw-run-019-canonical-identity-adversary.json",
    20: "raw-run-020-canonical-identity-frontline-reconciliation.json",
    21: "raw-run-021-canonical-identity-platform-reconciliation.json",
    22: "raw-run-022-canonical-identity-blocked-owner-verification.json",
    23: "raw-run-023-canonical-identity-cross-scope-arbitration.json",
    24: "raw-run-024-canonical-identity-remaining-hr-finance.json",
    25: "raw-run-025-canonical-identity-remaining-platform.json",
    26: "raw-run-026-canonical-identity-report-catalog.json",
    27: "raw-run-027-canonical-identity-medical-profile-owner.json",
    28: "raw-run-028-canonical-identity-denominator-integration.json",
    29: "raw-run-029-canonical-identity-denominator-red-team.json",
}

RUN_HASHES = {
    17: "f503ce331a80457e04caf5859c69feecc8ccb9ac3f345ff81bf5b3d2fc3d5e4e",
    18: "74c793051fd186c3f077c01d9f4de76b95b58039aa3f30c99c794f478574e629",
    19: "5590cebe920fc8779cd7bb771e6cce2e4115e6aa6a3f103c6e4d15ee4964ea64",
    20: "5720eee0e0677585bb960f8d7209e469d13a6d3d1087f2475659c7c514c2ee70",
    21: "356c64cf2cae6c9eb1270c6b4f58596a4e5be1761c8b6b3be9a61d100e72bc20",
    22: "05553c6e66bfae73a540302a38b121e61cd7573f628a5e89e3643b0fda0e261f",
    23: "1fb344ce088abb9c92d6f2d0d83835fed95555e0a38fb485e69170d20c2c85a9",
    24: "7abd8eebc24593f8980e32bd5a6e0e50b3271e57a6195734447d70336a8c5a38",
    25: "a5f89f5f4cabbc5ef442991079abf95e6da7ab71f5538dd2a29f6edb0b7bc72d",
    26: "b8e421b5083e9e14f8954bfd4cf36cfee6e492d08e466ce8ab1668ee71a1dc4d",
    27: "b79a7c5db3e957db82f972117b32f259d8b517ebea5534d48a8bea0b8f57a2dd",
    28: "eb9d84c4ea731a2cff73ab6bf4445a5f2dd7c3f7a9da519317aecb2c98bec609",
    29: "5fb67e877747be1b9a911112598a225796c49936c09cd82ca631431e40dac2b1",
}

EXPECTED = {
    "source_scope": "7b5ea92ea06025118deb15479382cff5606849215de099d5f3efca7daa38c0a1",
    "layer_a_edges": "131fe9434e94d6158f7349c0522f42a40cf878fb3f7c4a2b7b71d0cc5e4831c0",
    "layer_a_target_ids": "19d278e7a56a7ae2cd0e233884e9e2e1f8d248728ca2f87d0b79e3f247b84abc",
    "layer_a_target_rows": "fa7045d5e5fba51fc2761cc1121549b24f18487555e79674c8b41abb12714173",
    "catalog_mapping_rows": "01bf2271d4398a3e3a5cc1ec9f3a5d62a69397819ac8d001d06a5d3acf224e85",
    "catalog_mapping_class_rows": "a2d0d398ad1520e7a1651ecd78ec45cfa732ad47d53e9ead2a620df593ea5ff5",
    "catalog_target_ids": "5c1329dc5372d206c98e4938da7f96895973dbd6602e575823b3ae5b073791f0",
    "catalog_target_rows": "ddff9bd47a9f86a640e3de0864e29b230476fcfd321ffccda225f55e2f66adc3",
    "global_target_ids": "da93e647664016d275ab1d2e7b2aad9ca15f055ebf2cd56630c22ad427afee53",
    "global_target_rows": "f33d53cf3c9ed7520b683686520eaca9903e50713f438768a8a70819f1c787ac",
    "global_target_class": "ce8d845a5d40d9080afb7e54bdbec5549290b5d472a4f10a464eed0e375ed39f",
}

EXPECTED_STATIC_GAPS = {
    "route": {
        "count": 120,
        "classes": {"H": 104, "D": 16},
        "sha256": "38ee1617e5e7f781236b7a9d13f0723f369d671942defc0b6fcfa021d8136d8c",
    },
    "page": {
        "count": 226,
        "classes": {"H": 201, "D": 25},
        "sha256": "927a86b3122228849bf6a12b565d9194c2ca9315ac5d8ba88d674180c43e6768",
    },
    "both": {
        "count": 116,
        "classes": {"H": 101, "D": 15},
        "sha256": "58148201b144327f097ba47aca8bbff8860cfb063221d7e26a0cbafc662eac12",
    },
}

# These eleven audit-input ranges extend beyond the pinned application's
# current EOF. Preserve the exact evidence loci while clamping only the stale
# terminal line so the emitted current-tree registry never claims invalid
# source positions.
ANCHOR_CLAMPS = {
    "tests/Feature/Finance/OverviewHubTest.php:40-58": "tests/Feature/Finance/OverviewHubTest.php:40-57",
    "tests/Feature/Finance/OverviewHubTest.php:9-58": "tests/Feature/Finance/OverviewHubTest.php:9-57",
    "app/Domain/Finance/Services/Calendar/FinanceCalendarAggregator.php:26-116": "app/Domain/Finance/Services/Calendar/FinanceCalendarAggregator.php:26-113",
    "tests/Feature/Finance/FinanceCalendarTest.php:122-166": "tests/Feature/Finance/FinanceCalendarTest.php:122-164",
    "tests/Feature/Finance/XeroAccountingSyncProviderTest.php:18-287": "tests/Feature/Finance/XeroAccountingSyncProviderTest.php:18-285",
    "tests/Feature/Hr/EmployeeProfileSitePrivacyTest.php:133-252": "tests/Feature/Hr/EmployeeProfileSitePrivacyTest.php:133-251",
    "tests/Feature/Hr/ReportBuilderCanonicalAccessTest.php:93-229": "tests/Feature/Hr/ReportBuilderCanonicalAccessTest.php:93-215",
    "tests/Feature/Hr/HrReportingExpandedTypesTest.php:123-210": "tests/Feature/Hr/HrReportingExpandedTypesTest.php:123-155",
    "app/Models/Integration/IntegrationSyncLog.php:13-82": "app/Models/Integration/IntegrationSyncLog.php:13-81",
    "tests/Feature/Hr/AttendanceSessionSiteBoundaryTest.php:77-329": "tests/Feature/Hr/AttendanceSessionSiteBoundaryTest.php:77-327",
    "app/Models/DataRetentionPolicy.php:11-101": "app/Models/DataRetentionPolicy.php:11-99",
}

MATRIX_FIELDS = [
    "feature_id", "module", "submodule", "owning_actor", "secondary_actors", "user_job",
    "criticality", "navigation_entry", "route_names", "route_paths", "page_files",
    "backend_anchors", "current_states", "current_workflow_summary", "benchmark_candidates",
    "selected_open_source_benchmark", "benchmark_url_and_sha", "verified_behaviour",
    "neutral_requirements_extracted", "no_match_evidence", "current_ease_score",
    "target_ease_score", "P1", "P2", "P3", "P4", "P5", "P6", "P7", "P8",
    "finding_ids", "confidence", "feature_class", "feature_identity_status", "test_anchors",
    "benchmark_mapping_credit", "completion_status", "evidence_limit",
]


def read_json(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(relative: str, payload: object) -> None:
    path = AUDIT_DIR / relative
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")


def write_csv(relative: str, rows: list[dict[str, object]]) -> None:
    buffer = io.StringIO(newline="")
    writer = csv.DictWriter(buffer, fieldnames=MATRIX_FIELDS, lineterminator="\n", quoting=csv.QUOTE_MINIMAL)
    writer.writeheader()
    writer.writerows(rows)
    (AUDIT_DIR / relative).write_text(buffer.getvalue(), encoding="utf-8", newline="\n")


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def digest_lines(lines: list[str]) -> str:
    return hashlib.sha256("\n".join(sorted(lines)).encode("utf-8")).hexdigest()


def target_id(value: object) -> str | None:
    if isinstance(value, str):
        return value
    if not isinstance(value, dict):
        return None
    return value.get("id") or value.get("feature_id") or value.get("target_id") or value.get("target")


def target_ids(values: object) -> list[str]:
    if not isinstance(values, list):
        return []
    return [item for value in values if (item := target_id(value))]


def flatten_strings(value: object) -> list[str]:
    if isinstance(value, str):
        if value in {"navigation_entry", "route_names", "route_paths", "page_files", "route_file"}:
            return []
        if value.startswith("NOT_"):
            return []
        return [value]
    if isinstance(value, list):
        return [item for value_item in value for item in flatten_strings(value_item)]
    if isinstance(value, dict):
        return [
            item
            for key, value_item in value.items()
            if key not in {"explicit_gaps", "gaps", "missing_evidence"}
            for item in flatten_strings(value_item)
        ]
    return []


def owner_text(value: object) -> str | None:
    if isinstance(value, str):
        return value
    if isinstance(value, dict):
        return "; ".join(f"{key}: {item}" for key, item in value.items() if isinstance(item, str)) or None
    return None


for name, expected_hash in DISCOVERY_HASHES.items():
    assert sha256_file(SOURCE_DIR / name) == expected_hash
for run_number, name in RUN_FILES.items():
    assert sha256_file(SOURCE_DIR / name) == RUN_HASHES[run_number]
assert sha256_file(AUDIT_DIR / "evidence/benchmark/current-benchmark-wave-01.json") == BENCHMARK_WAVE_SHA256

runs = {number: read_json(SOURCE_DIR / name) for number, name in RUN_FILES.items()}
waves = [read_json(SOURCE_DIR / name) for name in DISCOVERY_HASHES]
source_rows = [candidate for wave in waves for candidate in wave["candidates"]]
sources = {row["candidate_id"]: row for row in source_rows}
assert len(source_rows) == 186
assert len(sources) == 186

mapping: dict[str, list[str]] = {}
registry: dict[str, dict[str, Any]] = {}


def register(
    value: object,
    source_id: str | None,
    provenance: str,
    *,
    force_identity: bool = False,
    replace_definition: bool = False,
) -> None:
    feature_id = target_id(value)
    if not feature_id:
        return
    raw = value if isinstance(value, dict) else {}
    source = sources.get(source_id or "", {})
    prior = registry.get(feature_id, {})
    owns_identity = (
        not prior
        or force_identity
        or source_id == feature_id
        or (source_id is not None and source_id == prior.get("identity_source_id"))
    )
    if owns_identity:
        feature_class = raw.get("class") or raw.get("feature_class") or prior.get("feature_class") or source.get("feature_class")
        module = raw.get("module") or prior.get("module") or source.get("module")
        user_job = (
            raw.get("job") or raw.get("user_job") or raw.get("canonical_user_job")
            or prior.get("user_job") or source.get("user_job") or "NOT_ESTABLISHED_CURRENT_AUDIT"
        )
        owner = (
            owner_text(raw.get("owner")) or owner_text(raw.get("primary_actor"))
            or owner_text(raw.get("canonical_owner")) or owner_text(raw.get("primary_owning_actor"))
            or prior.get("canonical_owner") or owner_text(source.get("canonical_owner"))
            or "NOT_ESTABLISHED_CURRENT_AUDIT"
        )
        identity_source_id = source_id or feature_id
    else:
        feature_class = prior.get("feature_class")
        module = prior.get("module")
        user_job = prior.get("user_job")
        owner = prior.get("canonical_owner")
        identity_source_id = prior.get("identity_source_id")
    anchor_values: list[str] = []
    for key in (
        "anchors", "decisive_anchors", "canonical_production_anchors", "canonical_test_anchors",
        "production_anchors", "representative_test_anchors",
    ):
        anchor_values.extend(flatten_strings(raw.get(key)))
    if not anchor_values:
        anchor_values.extend(flatten_strings(source.get("production_anchors")))
        anchor_values.extend(flatten_strings(source.get("representative_test_anchors")))
    anchors = sorted(set(([] if replace_definition else prior.get("anchors", [])) + anchor_values))
    provenance_rows = list(prior.get("definition_provenance", []))
    if provenance not in provenance_rows:
        provenance_rows.append(provenance)
    registry[feature_id] = {
        "feature_id": feature_id,
        "feature_class": feature_class,
        "module": module,
        "user_job": user_job,
        "canonical_owner": owner,
        "anchors": anchors,
        "definition_provenance": provenance_rows,
        "identity_source_id": identity_source_id,
    }


run17 = runs[17]
for bucket in ("keep_h", "keep_d", "keep_m"):
    for source_id in run17["decisions"][bucket]:
        mapping[source_id] = [source_id]
        register(source_id, source_id, f"RUN-017 decisions.{bucket}:{source_id}")
for decision in run17["decisions"]["reclassify"]:
    source_id = decision["source_id"]
    mapping[source_id] = [decision["final_id"]]
    register(
        {
            "id": decision["final_id"], "class": decision["final_class"], "module": decision["module"],
            "job": decision.get("user_job"), "owner": decision.get("primary_actor"),
            "anchors": decision.get("decisive_anchors"),
        },
        source_id,
        f"RUN-017 decisions.reclassify:{source_id}",
    )
for decision in run17["decisions"]["merge"]:
    source_id = decision["source_id"]
    mapping[source_id] = target_ids(decision["final_ids"])
    for value in decision["final_ids"]:
        register(value, source_id, f"RUN-017 decisions.merge:{source_id}")
for decision in run17["decisions"]["splits"]:
    source_id = decision["source_id"]
    mapping[source_id] = target_ids(decision["final_features"])
    for value in decision["final_features"]:
        register(value, source_id, f"RUN-017 decisions.splits:{source_id}")

for decision in runs[20]["recommendations"]:
    source_id = decision["source_id"]
    mapping[source_id] = target_ids(decision.get("final_targets"))
    for value in decision.get("final_targets", []):
        register(value, source_id, f"RUN-020 recommendations:{source_id}")

for decision in runs[18]["candidate_decisions"]:
    source_id = decision["candidate_id"]
    mapping[source_id] = target_ids(decision.get("proposed_final_features"))
    for value in decision.get("proposed_final_features", []):
        enriched = dict(value)
        enriched.update(
            {
                "module": decision.get("module"), "job": decision.get("neutral_user_job"),
                "owner": decision.get("primary_owning_actor"),
                "anchors": decision.get("decisive_source_anchors"),
            }
        )
        register(enriched, source_id, f"RUN-018 candidate_decisions:{source_id}")

for decision in runs[21]["recommendations"]:
    source_id = decision["source_id"]
    mapping[source_id] = target_ids(decision.get("targets"))
    for value in decision.get("targets", []):
        register(value, source_id, f"RUN-021 recommendations:{source_id}")

# RUN-022 must follow RUN-021 so the retained quality surface supersedes the
# earlier evidence-gap exclusion.
for decision in runs[22]["decisions"]:
    source_id = decision["candidate_id"]
    mapping[source_id] = target_ids(decision.get("targets"))
    for value in decision.get("targets", []):
        enriched = dict(value) if isinstance(value, dict) else {"id": value}
        enriched["anchors"] = decision.get("anchors", [])
        register(enriched, source_id, f"RUN-022 decisions:{source_id}")

for decision in runs[24]["source_decisions"]:
    source_id = decision["source_id"]
    mapping[source_id] = target_ids(decision.get("targets"))
    for value in decision.get("targets", []):
        register(value, source_id, f"RUN-024 source_decisions:{source_id}")
for decision in runs[25]["decisions"]:
    source_id = decision["source_id"]
    mapping[source_id] = target_ids(decision.get("targets"))
    for value in decision.get("targets", []):
        register(value, source_id, f"RUN-025 decisions:{source_id}")

run_023_suppressed_target_anchors = {
    "CAP-DAY-TASK-REPORT",
    "CAP-REP-COMBINED-REPORTS",
    "CAP-REP-REPORT-CATALOG-HUB",
}
for group in runs[23]["collision_groups"]:
    for value in group.get("recommended_targets", []):
        if target_id(value) not in run_023_suppressed_target_anchors:
            register(value, None, f"RUN-023 {group['group_id']}")
    for value in group.get("recommended_resolutions", []):
        if value.get("target"):
            register(
                {
                    "id": value["target"], "class": value.get("feature_class"),
                    "module": sources.get(value.get("source_id", ""), {}).get("module"),
                    "anchors": value.get("anchors"),
                },
                value.get("source_id"),
                f"RUN-023 {group['group_id']}",
            )

# Exact cross-scope substitutions from RUN-023. These are replacements, not
# additive edges.
mapping["CAP-CLIN-TRENDS-SUMMARY-CARE-LENS"] = [
    "CAP-CLIN-TRENDS-LENS", "CAP-CLIN-CLIENT-SUMMARY-LENS", "CAP-CLIN-CARE-PLAN-REVIEW-LENS",
]
mapping["CAP-COMPLAINT-HR-CASEWORK"] = ["CAP-HR-CASEWORK-LIFECYCLE"]
mapping["CAP-CR-TASK-ESCALATION-MY-QUEUE"] = [
    "CAP-CR-ALERT-TASK-LIFECYCLE", "CAP-CR-ESCALATION-QUEUE-LIFECYCLE",
    "CAP-CR-TASK-TO-HS-CORRECTIVE-ACTION-TRANSFER",
]
mapping["CAP-OPS-CALENDAR-SYNC"] = ["CAP-OPS-PERSONAL-CALENDAR-CONNECTION"]
mapping["CAP-INT-ADMIN-CONNECTIONS"] = [
    "CAP-INT-API-KEY-ADMIN", "CAP-INT-OUTBOUND-WEBHOOK-CONNECTION",
    "CAP-INT-SITE-RESOURCE-CALENDAR-CONNECTION", "CAP-INT-SITE-RESOURCE-CALENDAR-SYNC",
    "CAP-INT-MAILBOX-CONNECTION",
]
mapping["CAP-DAY-TASK-REPORT"] = ["CAP-DAY-TASK-REPORT"]
mapping["CAP-DAY-ALL-TASKS-WORKBENCH"] = ["CAP-DAY-ALL-TASKS-WORKBENCH", "CAP-DAY-TASK-CSV-EXPORT"]

# A cross-scope relation must never replace the canonical target's own job or
# owner. RUN-023 also introduced six targets whose group rows carried
# class/module/anchors but no job/owner text, so normalize those definitions
# explicitly from the same decisive source surfaces.
canonical_metadata_overrides = {
    "CAP-OPS-CARE-PLAN-LIFECYCLE": {
        "class": "H", "module": "Operations",
        "job": "Create, review, attest, export, and close a care plan",
        "owner": "CarePlanController, CarePlanService, and CarePlanAttestationService",
        "anchors": [
            "routes/operations.php:638-686", "resources/js/pages/operations/care-plans/Index.tsx",
            "resources/js/pages/operations/care-plans/Show.tsx", "app/Services/Operations/CarePlanService.php:9",
            "app/Services/Operations/CarePlanAttestationService.php:19-392",
        ],
    },
    "CAP-HS-CORRECTIVE-ACTION-EVIDENCE": {
        "class": "H", "module": "Health & Safety",
        "job": "Create, complete, verify, close, and evidence corrective actions",
        "owner": "HsCorrectiveActionService",
        "anchors": [
            "app/Services/HealthSafety/HsCorrectiveActionService.php:53-592",
            "tests/Feature/HealthSafety/HsCorrectiveActionEvidenceTest.php:31-99",
        ],
    },
    "CAP-CLIN-CARE-PLAN-REVIEW-LENS": {
        "class": "H", "module": "Care & Clinical",
        "job": "Review Site-scoped active care plans due for review or sign-off through the read-only clinical lens.",
        "owner": "HealthClinicalDashboardController::carePlans and ClinicalDashboardService::getCarePlanLens",
        "anchors": [
            "routes/health-clinical.php:54-56",
            "app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:221-241",
            "resources/js/pages/health-clinical/CarePlans.tsx",
        ],
    },
    "CAP-CR-TASK-TO-HS-CORRECTIVE-ACTION-TRANSFER": {
        "class": "H", "module": "Control Room",
        "job": "Transfer an active Control Room task into a canonical Health & Safety corrective action.",
        "owner": "ControlRoomTaskController::transferToHealthSafety and ControlRoomAlertLifecycleService::transferTaskToHealthSafety",
        "anchors": [
            "routes/control-room.php:155-157",
            "app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:261-285",
            "app/Services/ControlRoom/ControlRoomAlertLifecycleService.php:936-1005",
        ],
    },
    "CAP-DAY-TASK-CSV-EXPORT": {
        "class": "D", "module": "Frontline Workspaces",
        "job": "Export the current filtered All Tasks workbench queue as spreadsheet-safe CSV.",
        "owner": "AllTasksController::index and AllTasksController::exportCsv",
        "anchors": [
            "app/Http/Controllers/AllTasksController.php:99-100",
            "app/Http/Controllers/AllTasksController.php:488-517",
            "resources/js/pages/tasks/index.tsx:393-558",
        ],
    },
    "CAP-INT-SITE-RESOURCE-CALENDAR-CONNECTION": {
        "class": "H", "module": "Integrations",
        "job": "Configure application-level calendar provider connections, approved-Site resource mappings, cadence, and conflict policy.",
        "owner": "CalendarSyncSettingsController and CalendarSyncOAuthController",
        "anchors": [
            "routes/settings.php:312-331",
            "app/Http/Controllers/Settings/CalendarSyncSettingsController.php:22-260",
            "app/Http/Controllers/Settings/CalendarSyncOAuthController.php:12-109",
        ],
    },
    "CAP-INT-SITE-RESOURCE-CALENDAR-SYNC": {
        "class": "D", "module": "Integrations",
        "job": "Queue and execute configured Site-resource calendar synchronisation.",
        "owner": "CalendarSyncSettingsController::syncNow, SyncResourceCalendarsJob, and CalendarSyncService",
        "anchors": [
            "routes/settings.php:320-322",
            "app/Services/Sites/Calendar/CalendarSyncService.php:18-555",
        ],
    },
    "CAP-OPS-PERSONAL-CALENDAR-CONNECTION": {
        "class": "H", "module": "Operations",
        "job": "Create, list, remove, and timestamp trigger attempts for user-owned personal calendar connection metadata.",
        "owner": "Operations\\CalendarSyncController and CalendarSync",
        "anchors": [
            "routes/operations.php:1318-1322", "app/Http/Controllers/Operations/CalendarSyncController.php:9-91",
            "resources/js/pages/operations/calendar-sync/Index.tsx:72-134",
        ],
    },
}

canonical_metadata_provenance = {
    "CAP-OPS-CARE-PLAN-LIFECYCLE": [
        "RUN-017 decisions.reclassify:CAP-OPS-CARE-PLAN-LIFECYCLE",
        "RUN-017 decisions.merge:CAP-OPS-CARE-PLAN-REVIEW-SIGNOFF",
        "RUN-020 recommendations:CAP-OPS-CARE-PLAN-LIFECYCLE",
        "RUN-023 XCG-CARE-PLAN-LENS-OWNER",
        "RUN-030 canonical metadata precedence",
    ],
    "CAP-HS-CORRECTIVE-ACTION-EVIDENCE": [
        "RUN-017 decisions.keep_h:CAP-HS-CORRECTIVE-ACTION-EVIDENCE",
        "RUN-023 XCG-HS-HANDOVER-VS-TASK-TRANSFER",
        "RUN-030 canonical metadata precedence",
    ],
    "CAP-CLIN-CARE-PLAN-REVIEW-LENS": [
        "RUN-023 XCG-CARE-PLAN-LENS-OWNER",
        "RUN-030 canonical metadata precedence",
    ],
    "CAP-CR-TASK-TO-HS-CORRECTIVE-ACTION-TRANSFER": [
        "RUN-023 XCG-HS-HANDOVER-VS-TASK-TRANSFER",
        "RUN-030 canonical metadata precedence",
    ],
    "CAP-DAY-TASK-CSV-EXPORT": [
        "RUN-023 XCG-ALL-TASKS-WORKBENCH",
        "RUN-029 exact task CSV edge reconciliation",
        "RUN-030 canonical metadata precedence",
    ],
    "CAP-INT-SITE-RESOURCE-CALENDAR-CONNECTION": [
        "RUN-023 XCG-CALENDAR-INTEGRATION",
        "RUN-030 canonical metadata precedence",
    ],
    "CAP-INT-SITE-RESOURCE-CALENDAR-SYNC": [
        "RUN-023 XCG-CALENDAR-INTEGRATION",
        "RUN-030 canonical metadata precedence",
    ],
    "CAP-OPS-PERSONAL-CALENDAR-CONNECTION": [
        "RUN-023 XCG-CALENDAR-INTEGRATION",
        "RUN-030 canonical metadata precedence",
    ],
}
assert set(canonical_metadata_provenance) == set(canonical_metadata_overrides)
for feature_id, metadata in canonical_metadata_overrides.items():
    register(
        {"id": feature_id, **metadata},
        feature_id,
        "RUN-030 canonical metadata precedence",
        force_identity=True,
        replace_definition=True,
    )
    registry[feature_id]["definition_provenance"] = canonical_metadata_provenance[feature_id]

# RUN-027 preserves the Clients owner and removes only the eMAR API alias edge.
mapping["CAP-MED-API-CURRENT-SURFACES"].remove("CAP-MED-CLIENT-MEDICAL-PROFILE")
register(
    {
        "id": runs[27]["target_id"], "class": runs[27]["feature_class"], "module": runs[27]["module"],
        "job": runs[27]["canonical_user_job"], "owner": "ClientController and ClientMedicalProfile",
        "anchors": runs[27]["canonical_production_anchors"] + runs[27]["canonical_test_anchors"],
    },
    "CAP-MED-CLIENT-MEDICAL-PROFILE",
    "RUN-027 medical-profile owner",
    force_identity=True,
    replace_definition=True,
)
registry[runs[27]["target_id"]]["definition_provenance"] = [
    "RUN-017 decisions.keep_h:CAP-MED-CLIENT-MEDICAL-PROFILE",
    "RUN-027 medical-profile owner",
]
register(
    {"id": "CAP-INC-INCIDENT-REPORT-CSV-EXPORT", "class": "D", "module": "Incidents"},
    "CAP-INC-REPORT-AUDIT-EXPORTS",
    "RUN-023 incident report owner normalization",
    force_identity=True,
)

# Accepted relation and compatibility-surface anchors enrich evidence without
# creating source-target identity edges or replacing canonical jobs/owners.
for relation in runs[24]["relations"]:
    register(
        {"id": relation["target"], "anchors": relation.get("anchors", [])},
        None,
        f"RUN-024 relation:{relation['relation']}",
    )
for relation, normalized_target in (
    (runs[25]["relations"][2], "CAP-PRIV-RETENTION-POLICY-LIFECYCLE"),
    (runs[25]["relations"][3], "CAP-PRIV-DSR-LIFECYCLE"),
    (runs[25]["relations"][4], "CAP-PRIV-BREACH-LIFECYCLE"),
):
    register(
        {"id": normalized_target, "anchors": [relation["source_anchor"]]},
        None,
        "RUN-025 relation:MERGE_EXTERNAL_COMPONENT",
    )
profile_alias_anchors = [
    anchor
    for relation in runs[27]["aliases_and_read_lenses"]
    for anchor in relation.get("anchors", [])
]
register(
    {"id": "CAP-MED-CLIENT-MEDICAL-PROFILE", "anchors": profile_alias_anchors},
    "CAP-MED-CLIENT-MEDICAL-PROFILE",
    "RUN-027 accepted alias and read-lens anchors",
)
emar_component_anchors = sorted(
    set(
        runs[27]["emar_api_disposition"]["anchors"]
        + [
            "routes/api_medications.php:107-114",
            "app/Http/Controllers/Api/MedicationsApiController.php:1218-1279",
        ]
    )
)
register(
    {"id": "CAP-MED-EMAR-WORKSPACE-ORDER-LIFECYCLE", "anchors": emar_component_anchors},
    "CAP-MED-API-CURRENT-SURFACES",
    "RUN-027 allergy component evidence no new edge",
)

assert set(mapping) == set(sources)
assert [source_id for source_id, targets in mapping.items() if not targets] == ["CAP-WHISTLE-PROTECTED-DISCLOSURE"]

edge_lines = sorted(
    f"{source_id}|{feature_id}"
    for source_id, feature_ids in mapping.items()
    for feature_id in sorted(set(feature_ids))
)
layer_a_ids = sorted({feature_id for feature_ids in mapping.values() for feature_id in feature_ids})
assert len(mapping) == 186
assert sum(bool(targets) for targets in mapping.values()) == 185
assert len(edge_lines) == 362
assert len("\n".join(edge_lines).encode("utf-8")) == 21968
assert len(layer_a_ids) == 338
assert digest_lines(sorted(mapping)) == EXPECTED["source_scope"]
assert digest_lines(edge_lines) == EXPECTED["layer_a_edges"]
assert digest_lines(layer_a_ids) == EXPECTED["layer_a_target_ids"]
assert all(registry.get(feature_id, {}).get("feature_class") in {"H", "D"} for feature_id in layer_a_ids)
assert all(registry.get(feature_id, {}).get("module") for feature_id in layer_a_ids)

layer_a_target_rows = [
    f"{feature_id}|{registry[feature_id]['feature_class']}|{registry[feature_id]['module']}"
    for feature_id in layer_a_ids
]
assert digest_lines(layer_a_target_rows) == EXPECTED["layer_a_target_rows"]
assert Counter(registry[feature_id]["feature_class"] for feature_id in layer_a_ids) == {"H": 300, "D": 38}

catalog_rows: list[dict[str, Any]] = []
for row in runs[26]["mappings"]:
    normalized_target = (
        "CAP-HR-WORKFORCE-REPORT-EXPORT" if row["catalog_key"] == "staff" else row["canonical_final_id"]
    )
    definition = {
        "id": normalized_target,
        "class": "D",
        "module": row["module_owner"],
        "anchors": runs[26]["shared_surface"]["anchors"]
        + [row["definition_anchor"], row["view_path"], row["export_path"]],
    }
    if normalized_target not in registry:
        definition.update(
            {
                "job": f"View and export the {row['label']} report",
                "owner": "ModuleReportController and the canonical module record owner",
            }
        )
    register(definition, None, f"RUN-026 catalog:{row['catalog_key']}")
    catalog_rows.append(
        {
            "catalog_key": row["catalog_key"],
            "original_target_id": row["canonical_final_id"],
            "normalized_target_id": normalized_target,
            "feature_class": "D",
            "module": registry[normalized_target]["module"],
            "resolution": row["resolution"],
            "credit_supportable": False,
        }
    )

catalog_rows_by_target: dict[str, list[dict[str, Any]]] = defaultdict(list)
for row in runs[26]["mappings"]:
    normalized_target = "CAP-HR-WORKFORCE-REPORT-EXPORT" if row["catalog_key"] == "staff" else row["canonical_final_id"]
    catalog_rows_by_target[normalized_target].append(row)
for feature_id, job, owner in (
    (
        "CAP-SAFE-CONCERN-REPORTING-EXPORT",
        "View and export the Safeguarding Concerns report.",
        "ModuleReportController and the SafeguardingConcern record owner",
    ),
    (
        "CAP-RESP-BOOKING-REQUEST-REPORTING-EXPORT",
        "View and export the Respite Bookings and Respite Requests reports.",
        "ModuleReportController plus the RespiteBooking and RespiteBookingRequest record owners",
    ),
):
    direct_rows = catalog_rows_by_target[feature_id]
    register(
        {
            "id": feature_id,
            "class": "D",
            "module": direct_rows[0]["module_owner"],
            "job": job,
            "owner": owner,
            "anchors": runs[26]["shared_surface"]["anchors"]
            + [value for row in direct_rows for value in (row["definition_anchor"], row["view_path"], row["export_path"])],
        },
        feature_id,
        "RUN-026 new narrow report target",
        force_identity=True,
        replace_definition=True,
    )

catalog_relation_lines = sorted(f"{row['catalog_key']}|{row['normalized_target_id']}" for row in catalog_rows)
catalog_relation_class_lines = sorted(f"{line}|D" for line in catalog_relation_lines)
catalog_ids = sorted({row["normalized_target_id"] for row in catalog_rows})
catalog_target_rows = [f"{feature_id}|{registry[feature_id]['feature_class']}|{registry[feature_id]['module']}" for feature_id in catalog_ids]
assert len(catalog_rows) == 14
assert len(catalog_ids) == 9
assert digest_lines(catalog_relation_lines) == EXPECTED["catalog_mapping_rows"]
assert digest_lines(catalog_relation_class_lines) == EXPECTED["catalog_mapping_class_rows"]
assert digest_lines(catalog_ids) == EXPECTED["catalog_target_ids"]
assert digest_lines(catalog_target_rows) == EXPECTED["catalog_target_rows"]

global_ids = sorted(set(layer_a_ids) | set(catalog_ids))
global_target_rows = [f"{feature_id}|{registry[feature_id]['feature_class']}|{registry[feature_id]['module']}" for feature_id in global_ids]
global_class_rows = [f"{registry[feature_id]['feature_class']}|{feature_id}" for feature_id in global_ids]
assert len(global_ids) == 340
assert digest_lines(global_ids) == EXPECTED["global_target_ids"]
assert digest_lines(global_target_rows) == EXPECTED["global_target_rows"]
global_class_hash = hashlib.sha256("\n".join(global_class_rows).encode("utf-8")).hexdigest()
assert global_class_hash == EXPECTED["global_target_class"], global_class_hash
assert Counter(registry[feature_id]["feature_class"] for feature_id in global_ids) == {"H": 300, "D": 40}

required_absences = runs[28]["required_absences"]
assert not set(required_absences) & set(global_ids)

source_ids_by_target: dict[str, list[str]] = defaultdict(list)
for source_id, feature_ids in mapping.items():
    for feature_id in feature_ids:
        source_ids_by_target[feature_id].append(source_id)

benchmark_wave = read_json(AUDIT_DIR / "evidence/benchmark/current-benchmark-wave-01.json")
observer_records = benchmark_wave["observer"]["mappings"]
observer_by_source: dict[str, list[dict[str, Any]]] = defaultdict(list)
for record in observer_records:
    observer_by_source[record["candidate_id"]].append(record)

registry_anchor_set = {
    anchor
    for feature_id in global_ids
    for anchor in registry[feature_id]["anchors"]
}
assert set(ANCHOR_CLAMPS) <= registry_anchor_set

targets_payload: list[dict[str, Any]] = []
matrix_rows: list[dict[str, object]] = []
for feature_id in global_ids:
    target = registry[feature_id]
    anchors = sorted({ANCHOR_CLAMPS.get(anchor, anchor) for anchor in target["anchors"]})
    source_ids = sorted(source_ids_by_target.get(feature_id, []))
    origin = "LAYER_A_SOURCE_MAPPING" if source_ids else "LAYER_B_REPORT_CATALOG"
    target_observers = sorted(
        [record for source_id in source_ids for record in observer_by_source.get(source_id, [])],
        key=lambda record: (record["project"].lower(), record["candidate_id"]),
    )
    unique_projects = sorted({record["project"] for record in target_observers}, key=str.lower)
    benchmark_refs = sorted(
        {f"{record['project_url']}@{record['historical_project_commit']}" for record in target_observers},
        key=str.lower,
    )
    route_anchors = [anchor for anchor in anchors if anchor.startswith("routes/")]
    page_anchors = [anchor for anchor in anchors if anchor.startswith("resources/js/pages/")]
    backend_anchors = [anchor for anchor in anchors if anchor.startswith("app/")]
    test_anchors = [anchor for anchor in anchors if anchor.startswith("tests/") or "/test/" in anchor.lower()]
    navigation_anchors = [
        anchor for anchor in anchors
        if "sidebar" in anchor.lower() or "navigation" in anchor.lower() or "nav" in Path(anchor.split(":", 1)[0]).stem.lower()
    ]
    targets_payload.append(
        {
            "feature_id": feature_id,
            "feature_class": target["feature_class"],
            "module": target["module"],
            "user_job": target["user_job"],
            "canonical_owner": target["canonical_owner"],
            "origin": origin,
            "source_ids": source_ids,
            "anchors": anchors,
            "definition_provenance": target["definition_provenance"],
            "identity_status": "STATIC_CANONICAL_IDENTITY_FROZEN",
            "benchmark_status": "NOT_TRIAGED_CURRENT_AUDIT",
            "runtime_credit": 0,
            "browser_credit": 0,
            "test_execution_credit": 0,
            "benchmark_credit": 0,
            "ease_credit": 0,
            "completion_credit": 0,
        }
    )
    matrix_rows.append(
        {
            "feature_id": feature_id,
            "module": target["module"],
            "submodule": "CANONICAL_STATIC_IDENTITY_DENOMINATOR",
            "owning_actor": target["canonical_owner"],
            "secondary_actors": "NOT_ESTABLISHED_CURRENT_AUDIT",
            "user_job": target["user_job"],
            "criticality": "NOT_ADJUDICATED_CURRENT_AUDIT",
            "navigation_entry": "; ".join(navigation_anchors) or "NOT_ESTABLISHED_CURRENT_AUDIT",
            "route_names": (
                "; ".join(runs[26]["shared_surface"]["route_names"])
                if feature_id in catalog_ids else "NOT_ESTABLISHED_CURRENT_AUDIT"
            ),
            "route_paths": "; ".join(route_anchors) or "NOT_ESTABLISHED_CURRENT_AUDIT",
            "page_files": "; ".join(page_anchors) or "NOT_ESTABLISHED_CURRENT_AUDIT",
            "backend_anchors": "; ".join(backend_anchors) or "NOT_ESTABLISHED_CURRENT_AUDIT",
            "current_states": "NOT_ESTABLISHED_CURRENT_AUDIT",
            "current_workflow_summary": f"Static canonical identity only: {target['user_job']}. Representative-role completion was not executed.",
            "benchmark_candidates": "; ".join(unique_projects) or "NOT_ESTABLISHED_CURRENT_AUDIT",
            "selected_open_source_benchmark": "NOT_SELECTED_CURRENT_AUDIT",
            "benchmark_url_and_sha": "; ".join(benchmark_refs) or "NOT_ESTABLISHED_CURRENT_AUDIT",
            "verified_behaviour": (
                "PROVISIONAL_GROUPED_SOURCE_OBSERVER_RELATION_ONLY; see evidence/benchmark/current-benchmark-wave-01.json"
                if unique_projects else "NOT_ESTABLISHED_CURRENT_AUDIT"
            ),
            "neutral_requirements_extracted": "NOT_ESTABLISHED_CURRENT_AUDIT",
            "no_match_evidence": "NOT_DOCUMENTED_CURRENT_AUDIT",
            "current_ease_score": "NOT_SCORED_CURRENT_AUDIT",
            "target_ease_score": "NOT_SCORED_CURRENT_AUDIT",
            "P1": "STATIC_CANONICAL_IDENTITY_FROZEN",
            "P2": "NOT_STARTED_CURRENT_AUDIT",
            "P3": "PROVISIONAL_GROUPED_SOURCE_OBSERVER_RELATION_NO_CREDIT" if unique_projects else "NOT_STARTED_CURRENT_AUDIT",
            "P4": "NOT_STARTED_CURRENT_AUDIT",
            "P5": "NOT_STARTED_CURRENT_AUDIT",
            "P6": "NOT_STARTED_CURRENT_AUDIT",
            "P7": "NOT_STARTED_CURRENT_AUDIT",
            "P8": "NOT_STARTED_CURRENT_AUDIT",
            "finding_ids": "NOT_LINKED_TO_CANONICAL_TARGET_CURRENT_AUDIT",
            "confidence": "STATIC_IDENTITY_RECONCILED",
            "feature_class": target["feature_class"],
            "feature_identity_status": "STATIC_CANONICAL_IDENTITY_FROZEN",
            "test_anchors": "; ".join(test_anchors) or "NOT_ESTABLISHED_CURRENT_AUDIT",
            "benchmark_mapping_credit": "false",
            "completion_status": "INCOMPLETE_CANONICAL_STATIC_IDENTITY_ONLY",
            "evidence_limit": "Static identity and source ownership only; no runtime, browser, executed-test, benchmark, ease, release, or completion credit.",
        }
    )

anchors_by_target = {
    target["feature_id"]: [anchor.replace("\\", "/") for anchor in target["anchors"]]
    for target in targets_payload
}
route_gap_ids = sorted(
    feature_id
    for feature_id, anchors in anchors_by_target.items()
    if not any(anchor.startswith("routes/") for anchor in anchors)
)
page_gap_ids = sorted(
    feature_id
    for feature_id, anchors in anchors_by_target.items()
    if not any(anchor.startswith("resources/js/pages/") for anchor in anchors)
)
both_gap_ids = sorted(set(route_gap_ids) & set(page_gap_ids))


def gap_class_counts(feature_ids: list[str]) -> dict[str, int]:
    counts = Counter(registry[feature_id]["feature_class"] for feature_id in feature_ids)
    return {"H": counts["H"], "D": counts["D"]}


for gap_kind, feature_ids in (
    ("route", route_gap_ids),
    ("page", page_gap_ids),
    ("both", both_gap_ids),
):
    assert len(feature_ids) == EXPECTED_STATIC_GAPS[gap_kind]["count"]
    assert gap_class_counts(feature_ids) == EXPECTED_STATIC_GAPS[gap_kind]["classes"]
    assert digest_lines(feature_ids) == EXPECTED_STATIC_GAPS[gap_kind]["sha256"]

static_evidence_gaps = {
    "definition": {
        "route_anchor_prefix": "routes/",
        "page_anchor_prefix": "resources/js/pages/",
        "normalization": "Sorted unique target IDs, UTF-8 LF, no BOM, no trailing LF",
    },
    "targets_missing_route_anchor": len(route_gap_ids),
    "route_gap_classes": gap_class_counts(route_gap_ids),
    "route_gap_sha256": digest_lines(route_gap_ids),
    "route_gap_target_ids": route_gap_ids,
    "targets_missing_page_anchor": len(page_gap_ids),
    "page_gap_classes": gap_class_counts(page_gap_ids),
    "page_gap_sha256": digest_lines(page_gap_ids),
    "page_gap_target_ids": page_gap_ids,
    "targets_missing_both_route_and_page_anchor": len(both_gap_ids),
    "both_gap_classes": gap_class_counts(both_gap_ids),
    "both_gap_sha256": digest_lines(both_gap_ids),
    "both_gap_target_ids": both_gap_ids,
}

module_counts = dict(sorted(Counter(registry[feature_id]["module"] for feature_id in global_ids).items()))
assert module_counts == runs[28]["global_denominator"]["module_counts"]

source_mapping_payload = []
for source_id in sorted(mapping):
    targets = sorted(set(mapping[source_id]))
    disposition = (
        "EXCLUDE" if not targets else "KEEP" if targets == [source_id]
        else "SPLIT_OR_MULTI_OWNER" if len(targets) > 1 else "ALIAS_OR_MERGE"
    )
    source_mapping_payload.append(
        {
            "source_id": source_id,
            "source_wave": "RUN-017" if source_id in set(run17["scope_candidate_ids"]) else "RUN-018",
            "disposition": disposition,
            "targets": targets,
        }
    )

payload = {
    "schema_version": 1,
    "run_id": "RUN-030",
    "status": "STATIC_CANONICAL_FEATURE_IDENTITY_FROZEN_AUDIT_INCOMPLETE",
    "generated_at": GENERATED_AT,
    "source_pin": {
        "application_commit": APPLICATION_COMMIT,
        "application_tree": APPLICATION_TREE,
        "audit_input_commit": AUDIT_INPUT_COMMIT,
        "non_audit_product_diff": 0,
    },
    "inputs": {
        "discovery_sha256": DISCOVERY_HASHES,
        "adjudication_sha256": {f"RUN-{number:03d}": RUN_HASHES[number] for number in RUN_FILES},
        "benchmark_observer_wave_sha256": BENCHMARK_WAVE_SHA256,
    },
    "precedence": runs[28]["precedence"],
    "counts": {
        "source_candidates": 186,
        "mapped_sources": 185,
        "excluded_sources": 1,
        "layer_a_edges": 362,
        "layer_a_targets": 338,
        "layer_b_catalog_relations": 14,
        "layer_b_catalog_targets": 9,
        "layer_b_new_targets": 2,
        "canonical_targets": 340,
        "classes": {"H": 300, "D": 40, "M": 0},
        "modules": module_counts,
    },
    "hashes": {
        **EXPECTED,
        "edge_serialized_bytes": 21968,
        "normalization": "Sorted lines, UTF-8 LF, no BOM, no trailing LF",
    },
    "source_mappings": source_mapping_payload,
    "layer_b_report_catalog": catalog_rows,
    "supplemental_relations": runs[28]["supplemental_relation_ledger"],
    "required_absences": required_absences,
    "targets": targets_payload,
    "static_evidence_gaps": static_evidence_gaps,
    "completion_gate": {
        "canonical_static_identity_frozen": True,
        "runtime_credit": 0,
        "browser_credit": 0,
        "test_execution_credit": 0,
        "benchmark_credit": 0,
        "ease_credit": 0,
        "release_credit": 0,
        "completion_credit": 0,
        "audit_complete": False,
    },
}

agent_register = {
    "schema_version": 1,
    "run_id": "RUN-030",
    "status": "CANONICAL_IDENTITY_INDEPENDENCE_AND_INTEGRATION_REGISTER_COMPLETE",
    "generated_at": GENERATED_AT,
    "agents": [
        {
            "role": "canonical_identity_integrator",
            "evidence_run": "RUN-028",
            "result": "AUTHORITATIVE_362_EDGE_SET_AND_340_TARGET_REGISTRY",
        },
        {
            "role": "canonical_identity_denominator_red_team",
            "evidence_run": "RUN-029",
            "result": "INDEPENDENT_DISAGREEMENT_RECONCILED_TO_EXACT_TASK_CSV_SOURCE_ROW",
        },
        {
            "role": "canonical_identity_medical_profile_owner",
            "evidence_run": "RUN-027",
            "result": "INDEPENDENT_TIE_BREAK_REPRODUCED_362_EDGE_HASH",
        },
        {
            "role": "root_deterministic_integrator",
            "evidence_run": "RUN-030",
            "result": "REPRODUCED_ALL_DECLARED_EDGE_TARGET_CLASS_MODULE_AND_CATALOG_HASHES",
        },
    ],
    "agreement": {
        "independent_reconstructions": 3,
        "source_candidates": 186,
        "layer_a_edges": 362,
        "layer_a_edge_sha256": EXPECTED["layer_a_edges"],
        "canonical_targets": 340,
        "classes": {"H": 300, "D": 40, "M": 0},
        "global_target_class_module_sha256": EXPECTED["global_target_rows"],
        "remaining_identity_conflicts": 0,
    },
    "credit_boundary": payload["completion_gate"],
}

write_json("evidence/source/current-canonical-feature-identity-wave-01.json", payload)
write_json("evidence/source/current-canonical-identity-agent-register.json", agent_register)
write_csv("03-feature-to-benchmark-matrix.csv", matrix_rows)

print(
    json.dumps(
        {
            "status": payload["status"],
            "source_candidates": 186,
            "layer_a_edges": 362,
            "canonical_targets": 340,
            "classes": {"H": 300, "D": 40, "M": 0},
            "matrix_rows": len(matrix_rows),
            "edge_sha256": EXPECTED["layer_a_edges"],
            "target_rows_sha256": EXPECTED["global_target_rows"],
            "completion_credit": 0,
        },
        indent=2,
    )
)
