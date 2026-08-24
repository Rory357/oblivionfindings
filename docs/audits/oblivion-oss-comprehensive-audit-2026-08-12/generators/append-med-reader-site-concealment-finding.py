#!/usr/bin/env python3
"""Append the independently reviewed medication-reader Site-concealment finding.

This generator is intentionally narrow. It updates only the canonical finding,
finding-link reconciliation, official proposition map, canonical-input pointer,
and its own generation summary. It does not refresh dashboards or broad audit
summaries and it awards no runtime, browser, remediation, or completion credit.
"""

from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "evidence" / "source"
NOW = "2026-08-21T19:20:00+12:00"
AUDITED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
CURRENT_MAIN = "20ad5cef0aacb3d055e685d2f8b7b583cb8d78f4"
FINDING_ID = "MED-READER-SITE-CONCEALMENT-01"

PATHS = {
    "benchmark": SOURCE / "benchmark-final-904-mapping.json",
    "inventory": ROOT / "inventory-904.json",
    "ledger": ROOT / "02-eight-pass-coverage-ledger-904.csv",
    "matrix": ROOT / "03-feature-to-benchmark-matrix-904.csv",
    "manifest": SOURCE / "working-capability-manifest-904.json",
    "findings": ROOT / "findings.json",
    "reconciliation": SOURCE / "finding-link-reconciliation.json",
    "official_map": SOURCE / "official-nz-finding-proposition-map.json",
    "pointer": SOURCE / "canonical-audit-inputs.json",
    "summary": SOURCE / "final-904-med-reader-site-concealment-generation-summary.json",
}

PRE_PINS = {
    "benchmark": ("0ed383ce0977bc8705523343443997d14cd13fabf14b01ba9f83173116876ce2", 655824),
    "inventory": ("37cba2c22121ef641e425ba891e60757cc1a0b112ec9ec710ed71e317d673f6e", 11769530),
    "ledger": ("315b50fd58e17bedc7330ffd071b4abe4473d529d7147f78bb7c372095acb6a6", 1960869),
    "matrix": ("f7b9b429707fbc58e8a500c401d814db379bea7547d35d913aa119878882c5c8", 3290196),
    "manifest": ("ffca48609deab9a8938105c857786594a9a5431c31efe329ef4288da6165358f", 708566),
    "findings": ("4aacd5e5e7587578d7f242f1ed789ede6fcdcb05892eee0ab2ad5a07f8bf5ec7", 1277879),
    "reconciliation": ("96400f4b83f0689d146ed365ccf91c4acb30a36aa2cfdde0c5cdf33ae6ab88df", 236770),
    "official_map": ("8f41d6ca2354db1c7bcd7294df5b816ac3c076e7caa356f9556c05bbe3373e8f", 11702),
    "pointer": ("50c650ea7029b5d69c4b78e3709d273261d7ce797e869ec3f20a4560ca652235", 6362),
}

FEATURE_IDS = [
    "CAP-MED-MEDICATION-ORDER-LIFECYCLE",
    "CAP-MED-CD-REGISTER-BALANCE",
    "CAP-MED-STOCK-CONTROL",
    "CAP-MED-DESTRUCTION-REGISTER",
    "CAP-MED-API-ALERT-LIFECYCLE",
    "CAP-MED-API-DASHBOARD-WIDGETS",
    "CAP-MED-API-REPORT-DISPATCH",
]

ROUTE_IDS = [
    "ROUTE-0351",
    "ROUTE-0362",
    "ROUTE-0384",
    "ROUTE-0387",
    "ROUTE-0436",
    "ROUTE-0014",
    "ROUTE-0038",
    "ROUTE-0041",
    "ROUTE-0042",
]

BASELINE_ANCHORS = [
    "routes/emar.php:79-96,139-141",
    "routes/api_medications.php:12-16,77-83,93-100",
    "app/Http/Controllers/Emar/EmarController.php:87-91,591-621,1452-2107,2749-2832",
    "app/Http/Controllers/Api/MedicationsApiController.php:927-978,1028-1119",
    "app/Services/MedicationReportingService.php:24-110,111-388,389-488,489-602",
]

SOURCE_PINS = {
    "audited_baseline": {
        "commit": AUDITED_COMMIT,
        "files": {
            "routes/emar.php": "5a5b43f80db5de836185ac25c94aa29166fa36d1ce363fa9502caddc4ffc2405",
            "routes/api_medications.php": "00af9c267f2592896a59acbce802c6ac3174f5c2b2372218525f10d0287b63d8",
            "app/Http/Controllers/Emar/EmarController.php": "905d76fe97f9b57ed3b180e0bc213c6370f715f84aa851974a3c2f8fc6095cd7",
            "app/Http/Controllers/Api/MedicationsApiController.php": "c22abf0e6b29477aa3490f40afd4a8b3d3bba3c2be915a9f21513f18a376a912",
            "app/Services/MedicationReportingService.php": "6ab377355a5b000119bdca19b72de4e32fd449aa0c7b76aa42717b04dc44922c",
        },
    },
    "current_main_static_cross_check": {
        "commit": CURRENT_MAIN,
        "files": {
            "routes/emar.php": "5a5b43f80db5de836185ac25c94aa29166fa36d1ce363fa9502caddc4ffc2405",
            "routes/api_medications.php": "00af9c267f2592896a59acbce802c6ac3174f5c2b2372218525f10d0287b63d8",
            "app/Http/Controllers/Emar/EmarController.php": "fff88d41a6d9959f712eadb4f4469353730c6c2019ee35c8d1d3ff3b382eff0c",
            "app/Http/Controllers/Api/MedicationsApiController.php": "76474bb86ea1610d8ea25b5db10088ced9b149a6464e003c3c34ab652eccf75b",
            "app/Services/MedicationReportingService.php": "6ab377355a5b000119bdca19b72de4e32fd449aa0c7b76aa42717b04dc44922c",
        },
    },
}


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def pin(path: Path) -> dict[str, object]:
    return {
        "path": path.relative_to(ROOT).as_posix(),
        "sha256": sha256(path),
        "bytes": path.stat().st_size,
    }


def load(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def save(path: Path, payload: dict) -> None:
    data = (json.dumps(payload, indent=2, ensure_ascii=False) + "\n").encode("utf-8")
    temporary = path.with_name(f".{path.name}.{os.getpid()}.tmp")
    temporary.write_bytes(data)
    os.replace(temporary, path)


def require_pre_pins() -> dict[str, dict[str, object]]:
    actual = {}
    for name, (expected_hash, expected_bytes) in PRE_PINS.items():
        actual[name] = pin(PATHS[name])
        assert actual[name]["sha256"] == expected_hash, (name, actual[name], expected_hash)
        assert actual[name]["bytes"] == expected_bytes, (name, actual[name], expected_bytes)
    return actual


def finding_payload() -> dict:
    return {
        "id": FINDING_ID,
        "remediation": {
            "status": "open",
            "note": "Fresh independent Pass-8 source review retained the finding. No remediation branch, merge, runtime verification, or completion credit is recorded.",
        },
        "feature_ids": FEATURE_IDS,
        "passes": ["P1", "P2", "P3", "P4", "P5", "P6", "P7", "P8"],
        "module": "eMAR and medications",
        "submodule": "Cross-Site medication readers and direct-object concealment",
        "actor_and_job": "A Site-scoped medication viewer reviews medication orders, controlled-drug balances, stock, destructions, alerts, dashboard totals and reports for residents at Sites they are authorised to access.",
        "route_url": {
            "summary": "Nine exact audited GET route identities intersect the seven current 904 feature IDs.",
            "route_names": [
                "emar.controlled",
                "emar.medications",
                "emar.medications.detail",
                "emar.stock",
                "emar.destructions",
                "api.medications.alerts.index",
                "api.medications.dashboard.widgets",
                "api.medications.reports",
                "api.medications.reports.export",
            ],
            "route_paths": [
                "/emar/controlled",
                "/emar/medications",
                "/emar/medications/{medication}/detail",
                "/emar/stock",
                "/emar/destructions",
                "/api/medications/alerts",
                "/api/medications/dashboard/widgets",
                "/api/medications/reports",
                "/api/medications/reports/export",
            ],
        },
        "frontend_anchor": {
            "summary": "Existing medication pages consume the affected server readers; no rendered defect or visual credit is claimed.",
            "page_files": [
                "resources/js/pages/emar/ControlledDrugs.tsx",
                "resources/js/pages/emar/Medications.tsx",
                "resources/js/pages/emar/StockManagement.tsx",
                "resources/js/pages/emar/Destructions.tsx",
            ],
            "audited_commit": AUDITED_COMMIT,
        },
        "visual_context": {
            "visual_id": "None assigned",
            "classification": "Source-observed; browser-unverified",
            "role": "Site-scoped medication viewer",
            "site_scope": "Single tenant, multiple Sites",
            "viewport": "Not executed",
            "state": "Static reader trace",
            "pattern_type": "backend/privacy finding",
            "component_anchor": "See route and backend anchors",
            "screenshot_reference": "None—no screenshot is claimed",
            "internal_baseline": "Canonical UserSiteAccessService and ClientPolicy Site/direct-object boundary",
        },
        "pattern_implementation": "Preserve the existing eMAR pages and routes; enforce one canonical medication-reader Site scope and conceal foreign direct IDs server-side.",
        "backend_anchors": BASELINE_ANCHORS,
        "current_behavior": "At the audited baseline, action-permission middleware reaches readers whose client selectors, register queries, direct ClientMedication binding, aggregate widgets and report/export queries are organization-global or become unscoped when medicationViewableClientIds returns null for broad medication capabilities. The current-main static cross-check retains the same route definitions and reporting-service bytes and still exhibits the reader boundary.",
        "current_workflow": {
            "summary": "Static route-to-controller-to-query review across nine exact reader routes; no representative role was executed.",
            "failure_sequence": "A Site-A medication viewer with an accepted medication/report permission opens a global register, supplies a Site-B medication ID, or requests global alerts/widgets/reports and can receive Site-B resident medication facts or counts before canonical ClientPolicy/UserSiteAccessService concealment is enforced.",
            "boundary": "Site access, role capability, canonical Client ownership, direct-object concealment and minimum-necessary medication privacy; never tenant isolation.",
            "completion_evidence": "Source evidence only. No tests, browser, remediation, merge or production/runtime verification was performed in this review.",
        },
        "ease_evidence": {
            "validation_status": "Blocked—safety/privacy source finding retained; representative-role, failure-path and accessibility execution are unperformed",
            "evidence_basis": "Audited-baseline source trace plus current-main static drift cross-check",
            "current_scores": {
                "discoverability": 0,
                "comprehension": 0,
                "learnability": 0,
                "efficiency": 0,
                "error_prevention": 1,
                "recovery": 0,
                "accessibility": 0,
                "safety_and_trust": 1,
                "consistency": 1,
                "cross_module_continuity": 1,
            },
            "friction": {
                "completion_time": "Not measured—representative-role execution was not authorised",
                "step_count": "Not measured",
                "required_field_count": "Not measured",
                "decision_count": "Not measured",
                "context_switches": "Not measured",
                "dead_ends": "Unknown",
                "recovery_path": "Foreign-Site list rows, counts and direct IDs must be omitted or concealed with no existence or PHI leakage; an explicit global role is tested separately.",
            },
            "target_scores": {"all_dimensions": 4, "safety_critical_error_prevention_and_trust": 5},
            "independent_review": "Fresh independent Pass-8 review retained only bounded static risk evidence and withheld usability/runtime credit.",
        },
        "evidence": {
            "anchors": BASELINE_ANCHORS,
            "route_ids": ROUTE_IDS,
            "source_hashes": SOURCE_PINS,
            "current_main_method_anchors": [
                "app/Http/Controllers/Emar/EmarController.php:90-94,595-625,1485-2142,2784-2868",
                "app/Http/Controllers/Api/MedicationsApiController.php:1004-1055,1105-1196",
            ],
            "existing_tests": [
                "tests/Feature/Emar/ControlledDrugsTest.php",
                "tests/Feature/Emar/StockManagementTest.php",
                "tests/Feature/Emar/DestructionsTest.php",
                "tests/Feature/Emar/MedicationsDatabaseTest.php",
            ],
            "tests_executed": False,
            "browser_claim_limit": "No browser session was executed and no rendered, console, accessibility, responsive, runtime or release credit is claimed.",
        },
        "problem_root_cause": "Reader authorization is fragmented: route middleware checks an action permission, while several lists/aggregates/reports and a direct ClientMedication reader do not consistently lock the query to canonical accessible Client/Site IDs or re-authorize the bound object before disclosure.",
        "impact": "A Site-restricted worker can potentially disclose another Site's resident medication identity, controlled-drug information, stock/destruction evidence, alerts, dashboard counts or exported report rows. This is high-impact health-information and controlled-medication evidence exposure.",
        "benchmark": {
            "selected": "Target-specific decisions remain those in benchmark-final-904-mapping.json",
            "url_and_sha": "See each of the seven exact target rows in evidence/source/benchmark-final-904-mapping.json@0ed383ce0977bc8705523343443997d14cd13fabf14b01ba9f83173116876ce2",
            "verified_behavior": "This finding generator does not change or inherit benchmark decisions.",
            "outcome": "No benchmark credit delta",
            "no_match_evidence": "None claimed.",
        },
        "neutral_requirements": "Medication readers must start from canonical accessible Client/Site IDs, re-authorize direct objects, and require an explicitly named global Site role in addition to the action capability.",
        "better_oblivion_design": "Converge all medication readers and report builders on one canonical query scope that accepts the actor, applies UserSiteAccessService/ClientPolicy semantics, conceals foreign direct IDs, and exposes a separately tested explicit global-site bypass.",
        "target_ease": {
            "scores": {"all_dimensions": 4, "safety_critical_error_prevention_and_trust": 5},
            "measurable_outcome": "Site-A lists, aggregates, reports and direct reads contain no Site-B facts or counts; same-Site and explicitly global-role positives preserve the existing workflow.",
        },
        "cross_module_effects": "Preserve canonical Client, Site, medication, audit, reporting and alert ownership. Avoid a new tenant concept or a parallel medication source of truth.",
        "rbac_privacy": "Require both medication/report capability and canonical Site/direct-object authority. Test the explicit global Site permission separately and return 404 or non-disclosing denial for foreign IDs.",
        "priority": "P0",
        "effort": "L",
        "dependencies_sequence": "Medication product/clinical safety owner confirms reader inventory; authorization owner supplies canonical scope; then query, direct-object, report and regression coverage are corrected together.",
        "proposed_owner": "Medication Product Owner, Clinical Safety Owner, Authorization Platform Owner and Privacy Officer",
        "confidence": "High for static source exposure; runtime exploitability and representative-role behavior remain unverified",
        "source_boundary": "Audited-baseline source is immutable. Current-main comparison is drift evidence only. Official sources frame the risk; no legal, clinical, certification or runtime conclusion is inferred.",
        "interim_safeguard": "Restrict broad medication/report permissions to approved central roles until all reader paths have canonical Site scoping and direct-object concealment.",
        "acceptance_criteria": [
            "Every affected list, selector, aggregate, alert, widget and report/export query starts from canonical accessible Client/Site IDs.",
            "A directly supplied foreign-Site ClientMedication ID is concealed before medication details, movements or related PHI are loaded.",
            "An ordinary Site-A medication viewer sees no Site-B rows, labels, counts, alerts or report/export facts.",
            "Same-Site positive behavior and all existing routes/pages remain intact.",
            "An explicit global-Site role is positive only when it also holds the required medication/report action capability.",
            "Two-Site MySQL feature tests cover every reader family, including count and empty-state non-disclosure.",
            "Representative desktop/mobile browser verification confirms no stale or client-side leaked foreign facts before resolution credit.",
        ],
        "missing_tests": [
            "Two-Site list and selector exclusion for orders, controlled drugs, stock and destructions",
            "Foreign ClientMedication direct-ID 404/non-disclosing denial",
            "Two-Site alert/widget count non-disclosure",
            "Two-Site report and CSV export row/count non-disclosure",
            "Same-Site positive and explicit global-Site-plus-action positive",
            "Representative role browser verification at required viewports",
        ],
        "validation_plan": [
            "Review all affected queries against one canonical Site-access contract",
            "Run focused two-Site MySQL feature tests for ordinary and global roles",
            "Run report/export regression tests with foreign rows and zero-count assertions",
            "Perform authenticated browser verification without awarding credit from source alone",
            "Keep status open until canonical evidence records both merge-to-main and required runtime verification",
        ],
        "official_sources": [
            {
                "id": "NZ-HIPC-2020-R3A",
                "title": "Health Information Privacy Code 2020, including Rule 3A effective 1 May 2026",
                "authority": "Office of the Privacy Commissioner New Zealand",
                "url": "https://www.privacy.org.nz/privacy-principles/codes-of-practice/hipc2020/",
                "supporting_url": "https://www.privacy.org.nz/resources-and-learning/a-z-topics/ipp3a/hipc-rule-3a/",
                "inspected_date": "2026-08-12",
            },
            {
                "id": "NZ-HISO-10029-2022",
                "title": "HISO 10029:2022 Health Information Security Framework",
                "authority": "Health New Zealand / Health Information Standards Organisation",
                "url": "https://static.info.content.health.nz/docs/HISO/HISO%2010029%20Health%20Information%20Security%20Framework.pdf",
                "supporting_url": "https://www.healthnz.govt.nz/health-professionals/guidance-standards/topic/data-and-standards/health-information-standards/approved-health-information-standards/information-governance",
                "inspected_date": "2026-08-12",
            },
            {
                "id": "NZ-MOH-MMH-BREACH-REVIEW-2026",
                "title": "Manage My Health cybersecurity breach review final report",
                "authority": "New Zealand Ministry of Health",
                "url": "https://www.health.govt.nz/system/files/2026-05/11-manage-my-health-cybersecurity-breach-review-final-report.pdf",
                "supporting_url": "https://www.health.govt.nz/",
                "inspected_date": "2026-08-21",
            },
        ],
        "statement_types": {
            "source": "Routes, controller/query behavior and hashes are source-observed at the audited commit; current-main drift is separately pinned.",
            "official_source": "HISF-ACCESS, HIPC-R5 and MMH-BOLA frame access/privacy risk but do not independently prove the exact Oblivion control, legal applicability or certification.",
            "inference": "The Site-A to Site-B disclosure sequence is an evidence-backed static inference; it was not executed.",
            "specialist_decision": "P0 priority and the remediation design require the named medication, clinical safety, authorization and privacy owners.",
        },
        "official_source_proposition_keys": ["HISF-ACCESS", "HIPC-R5", "MMH-BOLA"],
        "feature_link_reconciliation": {
            "method": "route-first: exact audited route/controller intersection with literal IDs in the current 904 manifest; no shared-page inheritance",
            "projection_status": "literal_current_904_manifest_links_present; static_finding_retained; runtime_and_completion_unverified",
            "legacy_feature_ids": [],
            "decisions": [
                {
                    "legacy_family_id": "independent-pass8-medication-reader-site-concealment-2026-08-21",
                    "method": "source-proven exact current target route/backend intersection",
                    "feature_ids": FEATURE_IDS,
                    "route_hits": ROUTE_IDS,
                    "source_anchors": BASELINE_ANCHORS,
                    "evidence": "Fresh independent Pass-8 review traced nine audited GET routes through permission middleware into global or optionally unscoped medication readers and retained seven literal current 904 IDs without runtime credit.",
                    "audited_commit": AUDITED_COMMIT,
                    "current_main_static_cross_check": CURRENT_MAIN,
                }
            ],
            "uncertainties": [
                {
                    "reason_code": "runtime_and_representative_role_unexecuted",
                    "detail": "Static evidence supports retention and P0 escalation, but exploit reproduction, representative-role behavior, browser behavior and remediation status remain unverified.",
                    "smallest_next_evidence": "Run one bounded two-Site MySQL reader-security tree covering the nine exact routes, then authenticated desktop/mobile verification only after a reviewed correction is merged to the candidate branch.",
                }
            ],
        },
    }


def update_findings(payload: dict) -> None:
    assert payload["audited_commit"] == AUDITED_COMMIT
    assert all(row["id"] != FINDING_ID for row in payload["findings"])
    payload["findings"].append(finding_payload())
    payload["findings"].sort(key=lambda row: row["id"])
    payload["audit_status"] = "Blocked—not comprehensive or complete. The canonical 904-target register is current (790H/111D/3M). Benchmark/NCM completion credit is 456/904, visual final-ID linkage is 8,168/8,753, material-state linkage is 3,948/4,312, and 93 source-backed findings are retained. All 81/81 P0/P1 findings contain a literal current-manifest ID; runtime remains unexecuted."
    payload["statement"] = "Full schema for every retained finding. The 904-row stable-ID manifest is current; static evidence, inference, official-source propositions and owner decisions remain separated. Runtime, representative-role and usability completion are not claimed."
    payload["counts"]["P0"] = 19
    payload["counts"]["P1"] = 62
    payload["counts"]["P2"] = 12
    payload["counts"]["P3"] = 0
    links = payload["counts"]["feature_link_reconciliation"]
    links.update(
        {
            "projection_status": "904_current_literal_link_reconciliation_partial_not_runtime_validation",
            "working_accepted_capabilities": 904,
            "working_human_capabilities": 790,
            "working_manifest": "evidence/source/working-capability-manifest-904.json",
            "working_manifest_sha256": PRE_PINS["manifest"][0],
            "working_manifest_unique_stable_ids": 904,
            "stable_id_provenance": {"exact_current": 881, "source_stable": 5, "audit_assigned": 18},
            "route_enrichment": {"targets": 903, "relations": 3073, "unique_routes": 2993, "excluded_surface_relations": 31, "static_disposition_total": 3024},
            "page_enrichment": {"targets": 756, "relations": 1526, "unique_pages": 945, "excluded_surface_relations": 17, "static_disposition_total": 962},
            "backend_enrichment": {"targets": 731, "relations": 830, "unique_anchors": 469},
            "benchmark_mapping": {"eligible": 456, "verified_benchmark": 367, "documented_no_credible_match": 89, "completion_unproved": 448},
            "visual_linkage": {"assigned": 8168, "rows": 8753, "unresolved": 585},
            "material_state_linkage": {"assigned": 3948, "rows": 4312, "unresolved": 364},
            "findings": 93,
            "total_links": 265,
            "literal_exact_current_links": 166,
            "literal_exact_current_targets": 134,
            "findings_with_literal_exact_current_id": 93,
            "p0_p1_with_literal_exact_current_id": 81,
            "p0_p1_without_literal_exact_current_id": 0,
            "findings_with_uncertainty": 25,
            "findings_without_literal_exact_current_id": 0,
            "route_intersection_groups": 40,
            "unique_page_intersection_groups": 2,
        }
    )


def rebuild_reconciliation(payload: dict, findings: dict, manifest: dict) -> None:
    manifest_ids = {row["working_key"] for row in manifest["targets"]}
    rows = findings["findings"]
    exact = [(row["id"], feature) for row in rows for feature in row.get("feature_ids", []) if feature in manifest_ids]
    exact_findings = {finding_id for finding_id, _ in exact}
    p0p1 = [row for row in rows if row["priority"] in {"P0", "P1"}]
    p0p1_exact = {row["id"] for row in p0p1} & exact_findings
    decisions = [decision for row in rows for decision in row.get("feature_link_reconciliation", {}).get("decisions", [])]
    prior = payload["current_final_id_link_summary"]
    reviewed = sorted(set(prior["explicitly_re_adjudicated_findings"]) | {FINDING_ID})
    payload["generated_at"] = NOW
    payload["status"] = "current_904_literal_link_reconciliation_partial_runtime_unverified"
    payload["scope_boundary"] = "Links preserve audited source evidence and literal current 904 IDs; neither literal equality nor route/backend intersection establishes runtime outcome, remediation or completion."
    payload["current_final_id_link_summary"] = {
        "literal_links": len(exact),
        "literal_targets": len({feature for _, feature in exact}),
        "explicitly_re_adjudicated_links": prior["explicitly_re_adjudicated_links"] + len(FEATURE_IDS),
        "explicitly_re_adjudicated_findings": reviewed,
        "findings_with_literal_exact_current_id": len(exact_findings),
        "findings_without_literal_exact_current_id": len(rows) - len(exact_findings),
        "p0_p1_with_literal_exact_current_id": len(p0p1_exact),
        "p0_p1_without_literal_exact_current_id": len(p0p1) - len(p0p1_exact),
        "complete": False,
    }
    payload["counts"] = {
        "findings": len(rows),
        "total_links": sum(len(row.get("feature_ids", [])) for row in rows),
        "findings_with_uncertainty": sum(bool(row.get("feature_link_reconciliation", {}).get("uncertainties")) for row in rows),
        "findings_without_literal_exact_current_id": len(rows) - len(exact_findings),
        "route_intersection_groups": sum(bool(decision.get("route_hits")) for decision in decisions),
        "unique_page_intersection_groups": sum(bool(decision.get("page_hits")) for decision in decisions),
        "one_to_one_groups": sum("one-to-one" in str(decision.get("method", "")).lower() for decision in decisions),
    }
    payload["findings"] = [
        {
            "finding_id": row["id"],
            "feature_ids": row.get("feature_ids", []),
            "literal_current_feature_ids": [feature for feature in row.get("feature_ids", []) if feature in manifest_ids],
            "reconciliation": row.get("feature_link_reconciliation", {}),
        }
        for row in rows
    ]
    assert payload["counts"] == {
        "findings": 93,
        "total_links": 265,
        "findings_with_uncertainty": 25,
        "findings_without_literal_exact_current_id": 0,
        "route_intersection_groups": 40,
        "unique_page_intersection_groups": 2,
        "one_to_one_groups": 104,
    }
    assert payload["current_final_id_link_summary"]["literal_links"] == 166
    assert payload["current_final_id_link_summary"]["literal_targets"] == 134
    assert payload["current_final_id_link_summary"]["p0_p1_with_literal_exact_current_id"] == 81


def update_official_map(payload: dict) -> None:
    assert payload["denominator"] == 50 and payload["reviewed"] == 50
    assert all(row["finding_id"] != FINDING_ID for row in payload["findings"])
    payload["findings"].append(
        {"finding_id": FINDING_ID, "proposition_keys": ["HISF-ACCESS", "HIPC-R5", "MMH-BOLA"]}
    )
    payload["findings"].sort(key=lambda row: row["finding_id"])
    payload["denominator"] = 51
    payload["reviewed"] = 51
    payload["coverage_percent"] = 100.0
    payload["owner_boundary_rows"] = sum(
        any(str(key).startswith("OWNER-") for key in row["proposition_keys"])
        for row in payload["findings"]
    )
    assert payload["owner_boundary_rows"] == 27


def validate_existing_application() -> None:
    # Permit a newer copy of this same bounded generator to reconcile only its
    # own finding packet; unrelated canonical rows remain byte-for-byte data.
    findings = load(PATHS["findings"])
    desired = finding_payload()
    current = next(row for row in findings["findings"] if row["id"] == FINDING_ID)
    if current != desired:
        findings["findings"] = [desired if row["id"] == FINDING_ID else row for row in findings["findings"]]
        findings["findings"].sort(key=lambda row: row["id"])
        save(PATHS["findings"], findings)

        reconciliation = load(PATHS["reconciliation"])
        row = next(row for row in reconciliation["findings"] if row["finding_id"] == FINDING_ID)
        row["feature_ids"] = FEATURE_IDS
        row["literal_current_feature_ids"] = FEATURE_IDS
        row["reconciliation"] = desired["feature_link_reconciliation"]
        save(PATHS["reconciliation"], reconciliation)

        summary = load(PATHS["summary"])
        summary["output_artifacts"] = {
            name: pin(PATHS[name]) for name in ("findings", "reconciliation", "official_map")
        }
        summary["baseline_anchors"] = BASELINE_ANCHORS
        save(PATHS["summary"], summary)

        pointer = load(PATHS["pointer"])
        pointer["artifacts"]["findings"] = summary["output_artifacts"]["findings"]
        pointer["artifacts"]["finding_link_reconciliation"] = summary["output_artifacts"]["reconciliation"]
        pointer["artifacts"]["official_nz_finding_proposition_map"] = summary["output_artifacts"]["official_map"]
        pointer["artifacts"]["med_reader_site_concealment_generation_summary"] = pin(PATHS["summary"])
        save(PATHS["pointer"], pointer)

    summary = load(PATHS["summary"])
    assert summary["finding_id"] == FINDING_ID
    for name in ("findings", "reconciliation", "official_map"):
        assert pin(PATHS[name]) == summary["output_artifacts"][name]
    pointer = load(PATHS["pointer"])
    for key, name in (
        ("findings", "findings"),
        ("finding_link_reconciliation", "reconciliation"),
        ("official_nz_finding_proposition_map", "official_map"),
    ):
        assert pointer["artifacts"][key] == summary["output_artifacts"][name]
    assert pointer["artifacts"]["med_reader_site_concealment_generation_summary"] == pin(PATHS["summary"])
    assert sum(row["id"] == FINDING_ID for row in load(PATHS["findings"])["findings"]) == 1
    print(json.dumps({"status": "idempotent_no_change", "finding_id": FINDING_ID}, sort_keys=True))


def main() -> None:
    existing = load(PATHS["findings"])
    if any(row["id"] == FINDING_ID for row in existing["findings"]):
        validate_existing_application()
        return

    inputs = require_pre_pins()
    findings = existing
    reconciliation = load(PATHS["reconciliation"])
    official_map = load(PATHS["official_map"])
    pointer = load(PATHS["pointer"])
    manifest = load(PATHS["manifest"])

    assert manifest["counts"]["total"] == 904
    assert manifest["counts"]["H"] == 790
    assert manifest["counts"]["D"] == 111
    assert manifest["counts"]["M"] == 3
    manifest_ids = {row["working_key"] for row in manifest["targets"]}
    assert set(FEATURE_IDS) <= manifest_ids

    update_findings(findings)
    rebuild_reconciliation(reconciliation, findings, manifest)
    update_official_map(official_map)

    save(PATHS["findings"], findings)
    save(PATHS["reconciliation"], reconciliation)
    save(PATHS["official_map"], official_map)

    outputs = {name: pin(PATHS[name]) for name in ("findings", "reconciliation", "official_map")}
    summary = {
        "schema_version": "1.0",
        "artifact": "final-904-med-reader-site-concealment-generation-summary",
        "generated_at": NOW,
        "audited_commit": AUDITED_COMMIT,
        "current_main_static_cross_check": CURRENT_MAIN,
        "finding_id": FINDING_ID,
        "status": "generated_open_p0_static_only_runtime_and_completion_blocked",
        "scope": "Canonical finding append only; no application, runtime, broader summary or dashboard write.",
        "input_artifacts": inputs,
        "source_pins": SOURCE_PINS,
        "baseline_anchors": BASELINE_ANCHORS,
        "route_ids": ROUTE_IDS,
        "feature_ids": FEATURE_IDS,
        "output_artifacts": outputs,
        "counts": {
            "denominator": {"total": 904, "H": 790, "D": 111, "M": 3},
            "benchmark": {"eligible": 456, "verified": 367, "documented_no_credible_match": 89, "unproved": 448},
            "visual": {"assigned": 8168, "total": 8753, "unresolved": 585},
            "material": {"assigned": 3948, "total": 4312, "unresolved": 364},
            "findings": {"total": 93, "P0": 19, "P1": 62, "P2": 12, "P3": 0},
            "finding_links": {"total": 265, "literal": 166, "literal_targets": 134, "p0_p1_literal": 81},
            "official_map": {"denominator": 51, "reviewed": 51, "coverage_percent": 100.0, "owner_boundary_rows": 27},
        },
        "credit_boundary": {
            "static_finding_added": 1,
            "runtime_credit_delta": 0,
            "browser_credit_delta": 0,
            "benchmark_credit_delta": 0,
            "remediation_credit_delta": 0,
            "completion_credit_delta": 0,
        },
        "idempotence": "A second run validates the generated hashes and canonical pointer, performs no write, and returns idempotent_no_change.",
    }
    save(PATHS["summary"], summary)

    pointer["generated_at"] = NOW
    pointer["artifacts"]["findings"] = outputs["findings"]
    pointer["artifacts"]["finding_link_reconciliation"] = outputs["reconciliation"]
    pointer["artifacts"]["official_nz_finding_proposition_map"] = outputs["official_map"]
    pointer["artifacts"]["med_reader_site_concealment_generation_summary"] = pin(PATHS["summary"])
    pointer["completion_status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"
    pointer["runtime_credit_delta"] = 0
    save(PATHS["pointer"], pointer)

    validate_existing_application()
    print(json.dumps({"status": "generated", "finding_id": FINDING_ID, "outputs": outputs}, sort_keys=True))


if __name__ == "__main__":
    main()
