#!/usr/bin/env python3
"""Refresh current human summaries and completion gates from active canonical 904 artifacts."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path
from typing import Any


GENERATOR = Path(__file__).resolve()
AUDIT = GENERATOR.parent.parent
SOURCE = AUDIT / "evidence" / "source"
RUN_AT = "2026-08-21T22:40:00+12:00"

POINTER = SOURCE / "canonical-audit-inputs.json"
MANIFEST = SOURCE / "working-capability-manifest-904.json"
BENCHMARK = SOURCE / "benchmark-final-904-mapping.json"
GAP = SOURCE / "route-page-gap-reconciliation-904.json"
SURFACE_RECONCILIATION = SOURCE / "route-page-source-provenance-reconciliation-2026-08-23.json"
INVENTORY = AUDIT / "inventory-904.json"
LEDGER = AUDIT / "02-eight-pass-coverage-ledger-904.csv"
MATRIX = AUDIT / "03-feature-to-benchmark-matrix-904.csv"
SCORECARD = AUDIT / "04-workflow-usability-scorecard-904.csv"
VISUAL = AUDIT / "05-browser-visual-coverage-matrix-904.csv"
VISUAL_SUMMARY = SOURCE / "final-904-visual-link-generation-summary.json"
OVERLAY = SOURCE / "overlay-trigger-classification.json"
FINDINGS = AUDIT / "findings.json"
OFFICIAL_MAP = SOURCE / "official-nz-finding-proposition-map.json"
COMPLETION = SOURCE / "completion-gate-report.json"
SUMMARY = SOURCE / "current-904-summary-generation-report.json"
EXECUTIVE = AUDIT / "00-executive-summary.md"
MODULE_MAP = AUDIT / "01-repository-module-map.md"
MODULE_FINDINGS = AUDIT / "07-module-findings.md"
VISUAL_NARRATIVE = AUDIT / "09-ui-ux-accessibility-visual-consistency.md"
ARCHITECTURE_NARRATIVE = AUDIT / "10-architecture-data-integration-security.md"
UNRESOLVED = AUDIT / "13-unresolved-questions-and-evidence-gaps.md"

EXPECTED = {
    MANIFEST: "b9c1cf28e53e26df91fe772d91924beb56f56c1b9f3c68ddb320134bf148aa10",
    GAP: "cd5c01133d6a0bc4ca9154bfbf3eb54457cb0d382dd97e9b15ebb7bf25f2c18c",
    SCORECARD: "c5220768ee860ebcf9b896ac67e839317f7dabab842065ae72b10c4718eda9a8",
    VISUAL: "8e6bec4c967e646870487d66fcdd61e020b3007dd9e331732b02efd3c7a2d62a",
    VISUAL_SUMMARY: "a54111b954bde8db34b9499a60fe535bfe3981b61c69752bb2a0e85838d8d382",
}


def sha_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


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


for path, expected in EXPECTED.items():
    require(sha_file(path) == expected, f"Input SHA drift: {path}")

pointer_input = load(POINTER)
manifest = load(MANIFEST)
benchmark = load(BENCHMARK)
surface_reconciliation = load(SURFACE_RECONCILIATION)
visual = load(VISUAL_SUMMARY)
overlay = load(OVERLAY)
official_map = load(OFFICIAL_MAP)
completion = load(COMPLETION)
findings_document = load(FINDINGS)
GENERATED_AT = max(
    [RUN_AT, str(pointer_input.get("generated_at", ""))]
    + [
        str(document.get("generated_at", ""))
        for document in (manifest, benchmark, visual, overlay, official_map, completion, findings_document)
        if isinstance(document, dict)
    ]
)
findings = findings_document.get("findings", findings_document)
priority_counts = {key: sum(row["priority"] == key for row in findings) for key in ("P0", "P1", "P2")}
benchmark_summary = benchmark["summary"]
eligible = benchmark_summary["eligible_total"]
unproved = benchmark_summary["completion_unproved"]["total"]
verified = benchmark_summary["verified_benchmark"]["total"]
verified_direct = benchmark_summary["verified_benchmark"]["direct"]
verified_rename = benchmark_summary["verified_benchmark"]["strict_one_to_one_rename"]
ncm = benchmark_summary["documented_no_credible_match"]["total"]
ncm_direct = benchmark_summary["documented_no_credible_match"]["direct"]
ncm_rename = benchmark_summary["documented_no_credible_match"]["strict_one_to_one_rename"]
finding_total = len(findings)
p0_p1_total = priority_counts["P0"] + priority_counts["P1"]
official_total = official_map["reviewed"]
overlay_exact = overlay["custom_usage_layer"]["exact_trigger_resolved"]
overlay_inferred = overlay["custom_usage_layer"]["source_inferred_not_exactly_paired"]
overlay_unresolved = overlay["custom_usage_layer"]["unresolved_or_blocked"]
primitive_exact = overlay["primitive_root_layer"]["exact_trigger_resolved"]
primitive_unresolved = overlay["primitive_root_layer"]["unresolved"]
primitive_denominator = overlay["primitive_root_layer"]["denominator"]
page_total = surface_reconciliation["page_provenance"]["prompt_page_denominator"]
page_mapped = surface_reconciliation["page_provenance"]["accepted_feature_id_mapped"]
page_unmapped = page_total - page_mapped
route_total = surface_reconciliation["route_provenance"]["streams"]["all"]["rows"]
route_mapped = surface_reconciliation["route_provenance"]["streams"]["source_backed"]["rows"]
combined_total = route_total + page_total
combined_mapped = route_mapped + page_mapped
require(manifest["counts"]["total"] == 904, "904 manifest count drift")
require(eligible + unproved == 904, "Benchmark partition drift")
require(visual["counts"]["assigned_final_feature_id"] == 8168, "904 visual count drift")
require(overlay_exact + overlay_inferred + overlay_unresolved == 659, "Overlay partition drift")
require(primitive_denominator == 477 and primitive_exact + primitive_unresolved == primitive_denominator, "Primitive overlay partition drift")
require(sum(priority_counts.values()) == finding_total, "Finding priority drift")
require(official_map["denominator"] == official_total, "Official-map partition drift")
require(surface_reconciliation["independent_review"]["combined_source_family_route_page_union_reconciled"] is True, "Route/page provenance review drift")
require((route_total, route_mapped, page_total, page_mapped) == (3024, 2994, 727, 714), "Route/page denominator drift")

# The finding register carries a compact benchmark summary for dashboard and
# downstream reconciliation. Refresh it from the canonical benchmark rather
# than leaving the value pinned to the wave that last added a finding.
finding_link_counts = findings_document["counts"]["feature_link_reconciliation"]
finding_link_counts["benchmark_mapping"] = {
    "eligible": eligible,
    "verified_benchmark": verified,
    "documented_no_credible_match": ncm,
    "completion_unproved": unproved,
}
finding_link_counts["working_manifest_sha256"] = sha_file(MANIFEST)
finding_link_counts["route_enrichment"].update({
    "targets": 903,
    "relations": 3076,
    "unique_routes": 2994,
    "excluded_surface_relations": 30,
    "static_disposition_total": 3024,
})
finding_link_counts["source_tree_page_enrichment"] = {
    "targets": 756,
    "relations": 1526,
    "unique_files_with_accepted_relations": 945,
    "classified_source_files": 962,
}
finding_link_counts["page_enrichment"] = {
    "targets": 682,
    "relations": 968,
    "unique_pages": page_mapped,
    "unmapped_pages": page_unmapped,
    "inertia_page_denominator": page_total,
}
findings_document["audit_status"] = (
    "Blocked—not comprehensive or complete. The canonical 904-target register is current "
    f"(790H/111D/3M). Benchmark/NCM completion credit is {eligible}/904, visual final-ID "
    "linkage is 8,168/8,753, material-state linkage is 3,948/4,312, and "
    f"{finding_total} source-backed findings are retained. All {p0_p1_total}/{p0_p1_total} "
    "P0/P1 findings contain a literal current-manifest ID; runtime remains unexecuted."
)
write_json(FINDINGS, findings_document)

g = completion["gates"]
completion["schema_version"] = "2.1"
completion["canonical_register"] = {
    "total": 904, "H": 790, "D": 111, "M": 3,
    "manifest": "working-capability-manifest-904.json", "manifest_sha256": sha_file(MANIFEST),
}
g["canonical_features_registered"].update({"completed": 904, "denominator": 904, "percent": 100.0})
g["feature_benchmark_or_documented_no_match"].update({
    "completed": eligible, "denominator": 904, "percent": round(eligible / 904 * 100, 2),
    "detail": f"{verified} verified benchmark mappings ({verified_direct} direct, {verified_rename} rename) and {ncm} target-specific NCM decisions ({ncm_direct} direct, {ncm_rename} rename); {unproved} unproved.",
})
g["final_id_task_scripts_structural"].update({
    "completed": 790, "denominator": 790, "percent": 100.0,
    "detail": "790 Markdown files and scorecard rows; current scores blank and runtime unexecuted.",
})
g["ten_dimension_ease_scores_measured_and_independently_validated"].update({"denominator": 790})
g["representative_role_tasks_executed"].update({"denominator": 790})
g["representative_actor_classes"]["detail"] = g["representative_actor_classes"]["detail"].replace("0/788", "0/790")
g["visual_rows_linked_to_exact_final_feature_id"].update({
    "completed": 8168, "percent": round(8168 / 8753 * 100, 2),
    "detail": "585 unresolved; 774 final IDs assigned and 837 have some visual lineage.",
})
g["custom_overlay_static_trigger_classification"].update({
    "completed": overlay_exact, "denominator": 659, "percent": round(overlay_exact / 659 * 100, 2),
    "detail": f"{overlay_inferred} are source-inferred candidates and {overlay_unresolved} unresolved or blocked; runtime interaction coverage is 0/659.",
})
g["primitive_overlay_static_trigger_classification"].update({
    "completed": primitive_exact,
    "denominator": primitive_denominator,
    "percent": round(primitive_exact / primitive_denominator * 100, 2),
    "detail": f"{primitive_unresolved} primitive roots remain without an exact statically reachable resolved activator; runtime interaction coverage is 0/{primitive_denominator}.",
})
g["material_required_states_linked_to_exact_final_feature_id"].update({
    "completed": 3948, "percent": round(3948 / 4312 * 100, 2),
    "detail": "364 unresolved; 715 final IDs represented; runtime execution 0/4,312.",
})
g["routes_with_stable_static_disposition_id"]["detail"] = "2,994 routes map to accepted targets; 30 retain excluded non-denominator SURFACE dispositions."
g["routes_mapped_to_accepted_canonical_feature_id"].update({
    "completed": 2994, "percent": round(2994 / 3024 * 100, 2),
    "detail": "30 routes are classified under excluded non-denominator SURFACE dispositions, not accepted canonical capability IDs. Static disposition is complete, but the prompt's literal FEATURE-ID mapping gate is not.",
})
g["pages_classified"].update({
    "completed": page_total, "denominator": page_total, "percent": 100.0,
    "status": "complete-source-classification",
    "detail": "727/727 true Inertia pages are classified: 702 exact render roots plus 25 page-shaped resolver orphans. All 962 tracked resources/js/pages files remain source-classified, including 190 support/components, 28 TS helpers and 17 tests/specs outside this page denominator.",
})
g["pages_with_stable_static_disposition_id"].update({
    "completed": page_total, "denominator": page_total, "percent": 100.0,
    "detail": f"{page_mapped} true pages map to accepted targets; {page_unmapped} retain excluded or unmapped dispositions.",
})
g["pages_mapped_to_accepted_canonical_feature_id"].update({
    "completed": page_mapped, "denominator": page_total,
    "percent": round(page_mapped / page_total * 100, 2),
    "detail": f"{page_unmapped} true Inertia pages lack an accepted canonical FEATURE-ID. Support/components, TS helpers and tests/specs remain classified source artifacts but do not inflate the page denominator.",
})
g["combined_route_page_static_disposition"].update({
    "completed": combined_total, "denominator": combined_total, "percent": 100.0,
})
g["combined_route_page_accepted_feature_id_mapping"].update({
    "completed": combined_mapped, "denominator": combined_total,
    "percent": round(combined_mapped / combined_total * 100, 2),
    "detail": f"{route_total - route_mapped} routes and {page_unmapped} true Inertia pages remain outside accepted canonical FEATURE-ID ownership; source classification is complete but mapping is not.",
})
g["p6_finding_propositions_reviewed"].update({"completed": official_total, "denominator": official_total, "percent": 100.0})
g["p0_p1_required_evidence_fields"].update({"completed": p0_p1_total, "denominator": p0_p1_total, "percent": 100.0})
g["p0_p1_exact_final_feature_link"].update({
    "completed": p0_p1_total, "denominator": p0_p1_total, "percent": 100.0,
    "detail": f"{p0_p1_total}/{p0_p1_total} P0/P1 findings contain a literal current ID; 0 do not. Literal equality is not runtime validation.",
})
g["findings_with_neutral_requirements_and_no_copy_boundary"].update({"completed": finding_total, "denominator": finding_total, "percent": 100.0})
g["p0_p1_exact_owner_or_explicit_no_owner_disposition"].update({
    "completed": p0_p1_total, "denominator": p0_p1_total, "percent": 100.0,
    "detail": f"All {p0_p1_total} P0/P1 findings have literal current-target links. This is not runtime validation.",
})
g["all_findings_with_literal_current_feature_id"].update({
    "completed": finding_total, "denominator": finding_total, "percent": 100.0,
    "detail": "All retained findings have at least one literal current target ID; this does not prove runtime reproduction or remediation.",
})
completion["remaining_static_work_not_requiring_user_input"] = [
    f"Target-specific benchmark/NCM research for {unproved} targets.",
    "Resolve 585 visual rows and 364 material-state rows without family-level inheritance.",
    "Retain target-specific finding validation and runtime reproduction boundaries despite complete literal current-ID linkage.",
]
completion["status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"
require(len(completion["completion_blockers"]) == 19, "Completion blocker count drift")
write_json(COMPLETION, completion)


def replace_prefixed_line(path: Path, prefix: str, replacement: str) -> None:
    lines = path.read_text(encoding="utf-8").splitlines()
    if replacement in lines:
        return
    matches = [index for index, line in enumerate(lines) if line.startswith(prefix)]
    require(len(matches) == 1, f"Expected one line beginning {prefix!r} in {path}")
    lines[matches[0]] = replacement
    path.write_text("\n".join(lines) + "\n", encoding="utf-8", newline="\n")


def replace_line_containing(path: Path, marker: str, replacement: str) -> None:
    lines = path.read_text(encoding="utf-8").splitlines()
    if replacement in lines:
        return
    matches = [index for index, line in enumerate(lines) if marker in line]
    require(len(matches) == 1, f"Expected one line containing {marker!r} in {path}")
    lines[matches[0]] = replacement
    path.write_text("\n".join(lines) + "\n", encoding="utf-8", newline="\n")


executive = EXECUTIVE
replace_prefixed_line(executive, "**The audit is blocker-limited", f"**The audit is blocker-limited and must not be described as comprehensive or complete.** Independent Pass-8 denominator reconciliation establishes a corrected working denominator of **904 distinct capabilities: 790 human UI/workflow targets, 111 download/API targets and three machine-ingress targets**. The versioned 904 successor adds two previously omitted, source-distinct medical jobs—ClientCondition collection lifecycle and ClientMedicalProfile singleton update—without altering product source or granting runtime credit. `evidence/source/working-capability-manifest-904.json` is the active register selected by `canonical-audit-inputs.json`; all 790 human targets have structural task scripts and blank scorecard rows. Accepted target relations cover {route_mapped:,}/{route_total:,} routes; {route_total - route_mapped} routes remain without accepted FEATURE-ID ownership. The corrected Inertia-page gate is {page_mapped}/{page_total}; the other {page_unmapped} true pages remain unmapped, while 235 support/helper/test files stay classified outside the page denominator. Matrix 05 preserves 8,753 visual observations, assigns 8,168 rows to 774 final IDs and leaves 585 unresolved; the 4,312 material rows assign 3,948 to 715 final IDs and leave 364 unresolved. The benchmark mapping credits {eligible}/904 targets—{verified} verified benchmark and {ncm} bounded documented No Credible Match—leaving {unproved} unproved. These are static evidence gains only: representative task execution, ease measurement, audit-wide tests, complete viewport/state proof and all-module Pass-8 completion remain blocked.")
replace_prefixed_line(executive, "The original signed-in browser pass", "The original signed-in browser pass sampled 11 actor classes and a later bounded pass sampled the Clinical/Medication Lead, yielding 12/12 actor-entry classes. No canonical task or journey completion is inferred: task execution remains 0/790 and journey execution 0/8.")
replace_prefixed_line(executive, "| Overlay implementations and trigger rows classified", f"| Overlay implementations and trigger rows classified | 1,282 / 1,282 | 477 primitive roots + 146 explicit primitive triggers + 659 custom usages; static classification only. Custom usages include {overlay_exact} exact relations, {overlay_inferred} source-inferred candidates and {overlay_unresolved} unresolved/blocked; primitive roots include {primitive_exact} exact relations and {primitive_unresolved} unresolved |")
replace_prefixed_line(executive, "The route observation denominator corrected", f"The route observation denominator corrected during reconciliation from 621 to **622** distinct rendered route IDs (578 standard plus 44 parameterised). It is a one-admin safe set, not the whole product denominator. The overlay adversarial pass also corrected a non-defensible lexical count of 672/420 to **659 genuine custom JSX usages / 417 symbols** using a parser-confirmed denominator; the final static classifier found {overlay_exact} exact custom trigger relations, {overlay_inferred} source-inferred candidates and {overlay_unresolved} unresolved/blocked; none is runtime interaction proof. Primitive roots are separately classified as {primitive_exact} exact and {primitive_unresolved} unresolved out of {primitive_denominator}.")
table_updates = {
    "| Unique routes assigned": "| Unique routes assigned to an accepted final `FEATURE-ID` | 2,994 / 3,024 | **99.01%**; the other 30 routes retain excluded non-denominator `SURFACE-*` dispositions |",
    "| Distinct-capability working denominator": "| Distinct-capability working denominator | 904 / 904 | 790 human + 111 download/API + 3 machine ingress; `evidence/source/working-capability-manifest-904.json` has 904 unique stable IDs (881 exact-current, 5 source-stable, 18 audit-assigned) |",
    "| Durable final capability rows": "| Durable final capability rows in versioned inventory and matrices 02–03 | 904 / 904 | 100% static identity rows; this is not runtime or pass completion |",
    "| Capability benchmark": f"| Capability benchmark or documented No Credible Match | {eligible} / 904 | **{eligible / 904 * 100:.2f}%**: {verified} verified benchmark and {ncm} bounded documented no-match; {unproved} completion-unproved |",
    "| Human capability task scripts": "| Human capability task scripts | 790 / 790 | 100% structurally materialised under `task-scripts/final-904/`; 0/790 representative-role executed or independently usability-validated |",
    "| Evidence-backed ten-dimension": "| Evidence-backed ten-dimension current ease scores | 0 / 790 | Score cells remain blank/`Not measured` |",
    "| Representative actor classes": "| Representative actor classes safely sampled | 12 / 12 | Bounded entry only; 0/790 canonical tasks complete |",
    "| Final-capability entry observations": "| Final-capability entry observations | exact numerator unestablished / 790 | Existing shared-page observations are not inherited by split capabilities |",
    "| Visual-matrix rows": "| Visual-matrix rows assigned to final IDs | 8,168 / 8,753 | 774 unique final IDs; 585 unresolved; static linkage is not runtime proof |",
    "| Material-state applicability": "| Material-state applicability linked to final IDs | 3,948 / 4,312 | **91.56%** linkage to 715 final IDs; 364 unresolved and 0 deliberately executed |",
    "| Final capability rows with P1": "| Final capability rows with P1–P8 cells populated | 904 / 904 | 100% structural cells; blocked cells remain blocked |",
}
for prefix, replacement in table_updates.items():
    replace_prefixed_line(executive, prefix, replacement)
replace_prefixed_line(executive, "| Mandatory current NZ source identities", f"| Mandatory current NZ source identities reconciled | 6 / 6 | 6/6 current official identities and {official_total}/{official_total} finding propositions reviewed; Pass 6 remains blocked on representative role/site/direct-object execution |")
replace_prefixed_line(executive, "| P0/P1 findings with exact current-ID links", f"| P0/P1 findings with exact current-ID links | {p0_p1_total} / {p0_p1_total} | All {finding_total} retained findings have at least one literal current ID; exact linkage remains attribution evidence, not runtime proof |")
replace_line_containing(executive, " P0** —", f"- **{priority_counts['P0']} P0** — immediate safety, privacy/security or potentially unrecoverable integrity roots, including the source-confirmed cross-Site medication and Fleet booking reader/direct-object concealment failures and the browser-observed authenticated Shift task-provider failure retained against the audited commit.")

module_map = MODULE_MAP
replace_prefixed_line(module_map, "Fresh independent Pass-8 adjudication", f"Fresh independent Pass-8 adjudication establishes **25 ownership modules and a corrected source-backed denominator of 904 capabilities: 790 human, 111 download/API and three machine-ingress targets**. The versioned 904 manifest adds the distinct ClientCondition collection lifecycle and ClientMedicalProfile singleton update. It contains 881 exact-current, five source-stable and 18 audit-assigned IDs. Static source provenance closes all {route_total:,} route identities and all {page_total} true Inertia-page identities. Accepted FEATURE-ID ownership remains {route_mapped:,}/{route_total:,} routes and {page_mapped}/{page_total} pages; classification is complete, mapping is not.")
replace_prefixed_line(module_map, "| **Total**", "| **Total** | **790** | **111** | **3** | **904** |")
replace_prefixed_line(module_map, "This closes the working denominator", "This closes the 904 static identity/disposition gate, not the substantive pass or runtime gates. All 790 human targets have structural task scripts. Matrix 05 assigns 8,168/8,753 rows to 774 final IDs and 3,948/4,312 material rows to 715 IDs; 585 visual and 364 material rows remain unresolved. Runtime task execution and independently reviewed ease scores remain 0/790.")
replace_prefixed_line(module_map, "- Overlay layers are separate", f"- Overlay layers are separate and overlapping, never summed: 659/659 custom usages statically classified ({overlay_exact} exact trigger relations, {overlay_inferred} source-inferred candidates, {overlay_unresolved} unresolved/blocked); {primitive_denominator}/{primitive_denominator} primitive roots classified ({primitive_exact} exact relations, {primitive_unresolved} unresolved); all 146 explicit trigger nodes pair into 145 roots. These are static relationships, not runtime interaction proof.")

findings_doc = MODULE_FINDINGS
replace_prefixed_line(findings_doc, "This document is the human-readable companion", f"This document is the human-readable companion to `findings.json`. The retained finding set is **{priority_counts['P0']} P0, {priority_counts['P1']} P1 and {priority_counts['P2']} P2**. The current versioned register is **904 capabilities (790 human, 111 download/API and three machine-ingress)**; findings remain linked to literal current IDs, but linkage does not prove runtime remediation.")
replace_prefixed_line(findings_doc, "The “Benchmark disposition”", f"The “Benchmark disposition” column is historical context only. Current target-specific reconciliation credits {eligible} targets ({verified} verified benchmark and {ncm} documented No Credible Match); {unproved} remain completion-unproved.")
replace_prefixed_line(findings_doc, "## Current 902-register additions", "## Current 904-register additions")

if "## MED-READER-SITE-CONCEALMENT-01" not in findings_doc.read_text(encoding="utf-8"):
    with findings_doc.open("a", encoding="utf-8", newline="\n") as handle:
        handle.write(
            "\n## MED-READER-SITE-CONCEALMENT-01 — P0 — eMAR and medications\n\n"
            "Broad medication-read permissions reach global medication, controlled-drug, destruction, stock, alert, widget and report queries without canonical accessible-Site filtering or direct-object concealment. The retained source finding is open and runtime-unverified; a pushed or source-ready branch does not resolve it.\n\n"
            "- Exact owners: `CAP-MED-MEDICATION-ORDER-LIFECYCLE`, `CAP-MED-CD-REGISTER-BALANCE`, `CAP-MED-STOCK-CONTROL`, `CAP-MED-DESTRUCTION-REGISTER`, `CAP-MED-API-ALERT-LIFECYCLE`, `CAP-MED-API-DASHBOARD-WIDGETS`, `CAP-MED-API-REPORT-DISPATCH`\n"
            "- Evidence: `routes/emar.php:79-96,139-141`; `routes/api_medications.php:12-16,76-100`; `app/Http/Controllers/Emar/EmarController.php:87-90,591-619,1452-1522,1692-1729,1811-2100,2749-2828`; `app/Http/Controllers/Api/MedicationsApiController.php:927-963,1028-1082`; `app/Services/MedicationReportingService.php`\n"
            "- Required verification: two-Site same-Site positive, foreign-list/direct-ID/report concealment, omitted-filter denial, and explicit global-Site permission plus exact action-permission positive.\n"
        )

if "## HR-WEBHOOK-OUTBOUND-SSRF-01" not in findings_doc.read_text(encoding="utf-8"):
    with findings_doc.open("a", encoding="utf-8", newline="\n") as handle:
        handle.write(
            "\n## HR-WEBHOOK-OUTBOUND-SSRF-01 — P1 — Human resources\n\n"
            "An actor with `hr.settings.manage` can persist a generic-URL-validated webhook destination, and event publication or retry queues a job that posts to it without a canonical private/reserved-address, DNS-binding or redirect policy. This is source-only evidence: no worker, destination, response or exploit was executed.\n\n"
            "- Exact owners: `CAP-HR-WEBHOOK-ENDPOINTS`, `CAP-HR-WEBHOOK-DELIVERY-RETRY`\n"
            "- Evidence: `routes/hr.php:1269-1283`; `app/Http/Controllers/Hr/HrWebhookController.php:79-123,143-150`; `app/Domain/Hr/Services/HrWebhookService.php:46-156`; `app/Domain/Hr/Jobs/DeliverHrWebhookJob.php:76-107,125-136`; `tests/Feature/Hr/HrWebhookDeliveryTest.php:36-129`\n"
            "- Required verification: fake-resolver/fake-transport denial for loopback/private/link-local/IPv6, DNS rebinding and redirect-to-private targets, plus an approved public endpoint and stable retry path.\n"
        )

if "## CLIN-PROTOCOL-SCHEDULING-01" not in findings_doc.read_text(encoding="utf-8"):
    with findings_doc.open("a", encoding="utf-8", newline="\n") as handle:
        handle.write(
            "\n## CLIN-PROTOCOL-SCHEDULING-01 — P1 — Health and clinical\n\n"
            "Six time-based clinical protocol frequencies depend on `ClinicalProtocolService::generateSchedule()`, but the production call graph contains only the method declaration. Protocol write paths persist the definition while due/overdue consumers only read existing schedule rows, and an empty denominator can report 100% compliance. This is source-only evidence: no deployed protocol, scheduler, missed observation or harm was executed or observed.\n\n"
            "- Exact owner: `CAP-CLIN-PROTOCOL-DEFINITION-LIFECYCLE`\n"
            "- Evidence: `routes/health-clinical.php:69-86`; `app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:22-132`; `app/Domain/Clinical/Services/ClinicalProtocolService.php:25-65`; `app/Http/Controllers/Clinical/ShiftClinicalController.php:32-49`; `app/Domain/Clinical/Services/ClinicalDashboardService.php:60-74,240-264,922-952`\n"
            "- Required verification: disposable-MySQL protocol creation/activation, bounded due-row visibility, activation/deactivation reconciliation, replay/concurrency convergence and honest failed/empty compliance reporting.\n"
        )

if "## FLEET-BOOKING-SITE-PRIVACY-01" not in findings_doc.read_text(encoding="utf-8"):
    with findings_doc.open("a", encoding="utf-8", newline="\n") as handle:
        handle.write(
            "\n## FLEET-BOOKING-SITE-PRIVACY-01 — P0 — Fleet and vehicles\n\n"
            "An ordinary Site-bound Support Worker can receive `fleet.viewAny`, while the vehicle-booking controller globally lists, exports and counts bookings; returns all active vehicles, Sites and Clients including transport needs; accepts global asset/Site IDs; and direct-loads lifecycle mutations without canonical Site/object scope. This is source-only evidence: no foreign record was populated, accessed or mutated.\n\n"
            "- Exact owners: `CAP-FLEET-VEHICLE-BOOKING-REQUEST`, `CAP-FLEET-VEHICLE-BOOKING-DECISION`, `CAP-FLEET-VEHICLE-BOOKING-CHECKOUT-RETURN`\n"
            "- Evidence: `routes/fleet-assets.php:108-126`; `database/seeders/RbacSeeder.php:732-759`; `app/Http/Controllers/FleetAssets/VehicleBookingController.php:23-100,202-375,391-511`; `app/Policies/AssetPolicy.php:17-21`; `app/Services/UserSiteAccessService.php:53-83,825-833`\n"
            "- Required verification: disposable two-Site same-Site positive, foreign register/picker/direct-ID concealment, zero side effects, and explicit global-Site plus exact booking-action permission positive.\n"
        )

replace_prefixed_line(VISUAL_NARRATIVE, "The Pass-8 parser re-count", f"The Pass-8 parser re-count corrected the earlier invalid 672/420 lexical result: 19 TypeScript generics and one comment had been mistaken for JSX. All 659 custom usages now have a static classification; independently reviewed static waves resolve bounded state→handler→trigger→open chains, so {overlay_exact} have exact static trigger relations, {overlay_inferred} are source-inferred candidates and {overlay_unresolved} remain unresolved/blocked. Static resolution is not runtime reachability, focus, Escape, scroll lock, teardown or restoration proof. The separate mobile-navigation record supports one custom-overlay failure; prior claims without structured per-instance logs remain excluded.")
replace_prefixed_line(VISUAL_NARRATIVE, "| Primitive overlay roots", f"| Primitive overlay roots | {primitive_denominator} | {primitive_denominator}/{primitive_denominator} classified: {primitive_exact} exact static trigger/state relations, {primitive_unresolved} unresolved |")
replace_prefixed_line(VISUAL_NARRATIVE, "| Genuine custom overlay JSX usages", f"| Genuine custom overlay JSX usages | 659 / 417 symbols | 659/659 classified: {overlay_exact} exact static trigger relations, {overlay_inferred} source-inferred candidates, {overlay_unresolved} unresolved/blocked; 654 reachable / 5 unreachable |")
replace_prefixed_line(VISUAL_NARRATIVE, "3. **Overlay trigger/focus evidence gap:**", f"3. **Overlay trigger/focus evidence gap:** {overlay_unresolved}/659 custom usages remain unresolved/blocked and {overlay_inferred}/659 are only source-inferred candidates; even the {overlay_exact} exact static relations do not establish runtime restoration or failure rate. The mobile app sidebar is the one retained structured interaction failure and visible-open screenshot ([BVIS-0008](evidence/browser/BVIS-0008-dashboard-mobile-navigation-overlay-390x844-observed-cropped.png)). Baseline: shared Radix Dialog/Sheet with name, trap, body lock and restoration.")
replace_prefixed_line(VISUAL_NARRATIVE, "Retained visual findings:", "Retained visual findings: `VIS-MOBILE-NAV-01`, `VIS-RESPONSIVE-OVERFLOW-01`, `VIS-HERO-DENSITY-01`, `VIS-OVERLAY-FOCUS-01`, `VIS-CR-SETTINGS-NAMES-01`, `VIS-DEPLOYED-DRIFT-01`, `VIS-SYSTEM-USERS-COUNT-01`, `VIS-MY-DAY-HEADER-OVERFLOW-01`, plus `INCIDENT-RECOVERY-01` for interruption recovery. The four material hero/overlay finding families have a reproducible independent-resample denominator. A read-only pass on historical browser-evidence pin `ad19f994a280835d039d1a31ebdcb05778733c5a` sampled 4/4: mobile navigation, overlay focus and incident recovery reproduced, while task-first hero distance partially reproduced. The audited-baseline numerator remains 0/4. A later exact-`081ef198…` preflight successfully installed the pinned dependencies, built and served exact audited assets, but `/control-room/shifts` redirected to login and no existing authenticated session or safe Active-shift fixture was available; no baseline interaction was credited. See `evidence/browser/current-main-visual-family-resample-2026-08-14.json` and `evidence/browser/frozen-baseline-control-room-handover-preflight-2026-08-21.json`. The My Day finding is browser-observed, screenshot-retained and source-traced; Support Worker repetition remains pending.")
replace_prefixed_line(ARCHITECTURE_NARRATIVE, "All six mandatory official-source identities", f"All six mandatory official-source identities were reconciled on 12 August 2026. The current Health New Zealand HISO core and supplier PDFs were directly retrieved and reviewed; {official_total} P6 finding propositions are independently mapped. Pass 6 still cannot be completed because representative role/site/direct-object behavior was not executed and applicability/conformance remain accountable-owner decisions. The table deliberately separates official-source observation, Oblivion source evidence, audit inference and accountable specialist decision. Full URLs, source anchors, retrieval limits and finding links are in `evidence/source/official-nz-finding-proposition-map.json`.")

unresolved = UNRESOLVED
replace_prefixed_line(unresolved, "The audit is **blocked", f"The audit is **blocked—not comprehensive or complete**. The corrected active denominator is **904 = 790 human + 111 download/API + 3 machine-ingress capabilities**. Static source provenance is reconciled for {route_total:,}/{route_total:,} routes and {page_total}/{page_total} true Inertia pages, but accepted FEATURE-ID ownership is only {route_mapped:,}/{route_total:,} routes and {page_mapped}/{page_total} pages. Completion remains blocked because {unproved} benchmark targets, 585/8,753 visual rows and 364/4,312 material rows remain unproved or unresolved, and runtime, state, ease-score and audit-wide test proof is absent.")
replace_prefixed_line(unresolved, "| Overlay runtime trigger/focus proof", f"| Overlay runtime trigger/focus proof | custom 659/659 static classifications ({overlay_exact} exact, {overlay_inferred} candidate, {overlay_unresolved} unresolved/blocked); primitive {primitive_denominator}/{primitive_denominator} ({primitive_exact} exact, {primitive_unresolved} unresolved) | Static mapping does not establish runtime reachability, focus/teardown failure rate or affected instances. | Safe keyboard/focus/scroll interaction traces at relevant roles/viewports. |")
gap_rows = {
    "| Representative actor execution": "| Representative actor execution | 12 / 12 actor classes sampled for bounded entry; 0/790 canonical tasks complete | Entry is not task success, denial, persistence, recovery or handoff. | Execute each canonical task with resettable fixtures and documented Site scope. |",
    "| Final capability mapping": f"| Final capability mapping | 904/904 IDs integrated; {route_mapped:,}/{route_total:,} routes and {page_mapped}/{page_total} true Inertia pages map to accepted targets | Static source provenance is complete, but {route_total - route_mapped} routes and {page_unmapped} pages still lack accepted FEATURE-ID ownership. | Resolve exact owners without inheriting shared-page or infrastructure evidence. |",
    "| Canonical capability task execution": "| Canonical capability task execution | 790/790 scripts and scorecard rows structurally present; 0/790 executed or scored | Structural scripts do not prove completion. | Provide resettable fixtures and representative actors. |",
    "| Capability benchmark": f"| Capability benchmark / No Credible Match | {eligible}/904 ({eligible / 904 * 100:.2f}%) mapped; {unproved} completion-unproved | {verified} verified benchmark plus {ncm} bounded NCM. | Complete target-specific research for {unproved}. |",
    "| Capability browser entry": "| Capability browser entry | bounded 12-role sample; exact task numerator remains 0/790 | Shared pages cannot be promoted to every split capability. | Use resettable fixtures for safe task-level execution. |",
    "| Material required-state execution": "| Material required-state execution | 4,312 rows; 3,948 linked to 715 final IDs, 364 unresolved (91.56%), 0 executed | Static applicability is not rendered-state proof. | Complete links and obtain safe runtime evidence. |",
    "| Role-sensitive visual states": "| Role-sensitive visual states | 0 / 790 human capabilities executed | No task-level visual denial/privacy assertion is accepted. | Use representative accounts and wrong-Site/wrong-record fixtures. |",
}
for prefix, replacement in gap_rows.items():
    replace_prefixed_line(unresolved, prefix, replacement)
replace_prefixed_line(unresolved, "- `evidence/source/working-capability-manifest-902.json`", "- `evidence/source/working-capability-manifest-904.json` contains 904 stable IDs: 881 exact-current, five source-stable and 18 audit-assigned; the active pointer prevents frozen 902 artifacts from being mistaken for current canonical inputs.")
replace_prefixed_line(unresolved, "- `evidence/source/benchmark-final-904-mapping.json`", f"- `evidence/source/benchmark-final-904-mapping.json` credits {eligible} targets ({verified} verified benchmark and {ncm} documented No Credible Match), or {eligible / 904 * 100:.2f}%; {unproved} remain completion-unproved.")
replace_prefixed_line(unresolved, "| Mandatory NZ-source review", f"| Mandatory NZ-source review | 6/6 identities and {official_total}/{official_total} finding propositions source-reviewed; role/site execution blocked | HISO/HIPC/other propositions frame product risk; they are not applicability, conformance, legal or clinical conclusions. | Representative site-scoped and global test accounts plus synthetic two-site fixtures; Security/Privacy owner confirms applicability and system boundary. |")
replace_prefixed_line(unresolved, "- The stable-ID spelling", f"- The stable-ID spelling and route/page dispositions are reflected in the canonical register. All {finding_total} retained findings and {p0_p1_total}/{p0_p1_total} P0/P1 findings have at least one literal current ID; partial visual linkage and absent runtime proof still prevent completion.")
replace_prefixed_line(unresolved, "- Twenty-four PNGs", "- Sixty PNGs are retained. Twenty-one are referenced by audit prose or evidence records and 39 remain orphaned; Matrix 05 directly links six rows. Fourteen centrally hash-pinned screenshots replay their recorded SHA-256, while several other retained images still lack explicit hash/redaction provenance. No structured side-by-side comparison artefact is credited without that provenance.")

report = {
    "schema_version": "1.0.0", "artifact": "current-904-summary-generation-report", "generated_at": GENERATED_AT,
    "status": "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE", "runtime_credit_delta": 0,
    "inputs": {name: record(path) for name, path in {
        "manifest": MANIFEST, "benchmark": BENCHMARK, "gap": GAP, "surface_reconciliation": SURFACE_RECONCILIATION, "inventory": INVENTORY,
        "ledger": LEDGER, "matrix": MATRIX, "scorecard": SCORECARD, "visual": VISUAL, "visual_summary": VISUAL_SUMMARY, "overlay": OVERLAY,
        "findings": FINDINGS, "official_map": OFFICIAL_MAP,
    }.items()},
    "outputs": {"completion_report": record(COMPLETION), "executive_summary": record(executive), "module_map": record(module_map), "module_findings": record(findings_doc), "visual_narrative": record(VISUAL_NARRATIVE), "architecture_narrative": record(ARCHITECTURE_NARRATIVE), "unresolved_questions": record(unresolved)},
    "counts": {"capabilities": 904, "human_tasks": 790, "benchmark_decided": eligible, "benchmark_unproved": unproved, "visual_assigned": 8168, "visual_unresolved": 585, "material_assigned": 3948, "material_unresolved": 364, "custom_overlay_exact": overlay_exact, "custom_overlay_unresolved_or_blocked": overlay_unresolved, "primitive_overlay_exact": primitive_exact, "primitive_overlay_unresolved": primitive_unresolved, "completion_blockers": 19},
}
write_json(SUMMARY, report)

pointer = load(POINTER)
pointer["generated_at"] = max(pointer.get("generated_at", ""), GENERATED_AT)
pointer["artifacts"]["findings"] = record(FINDINGS)
pointer["artifacts"]["completion_report"] = record(COMPLETION)
pointer["artifacts"]["current_summary_generation_report"] = record(SUMMARY)
pointer["artifacts"]["route_page_source_provenance_reconciliation"] = record(SURFACE_RECONCILIATION)
write_json(POINTER, pointer)

print(json.dumps({"completion": record(COMPLETION), "summary": record(SUMMARY), "active_inputs": record(POINTER)}, indent=2))
