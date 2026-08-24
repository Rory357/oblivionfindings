from __future__ import annotations

import json
import re
from collections import Counter
from datetime import datetime
from pathlib import Path


AUDIT = Path(__file__).resolve().parent.parent
DASHBOARD = AUDIT / "audit-dashboard.html"
FINDINGS = AUDIT / "findings.json"
BENCHMARK = AUDIT / "evidence" / "source" / "benchmark-final-904-mapping.json"
VISUAL = AUDIT / "evidence" / "source" / "final-904-visual-link-generation-summary.json"
PROJECT_TRIAGE = AUDIT / "evidence" / "source" / "project-specific-triage-complete-2026-08-14.json"
ORCHESTRATION = AUDIT / "evidence" / "source" / "orchestration-status-2026-08-14.json"
REMEDIATION_WORKSPACE = (
    AUDIT
    / "evidence"
    / "source"
    / "remediation-workspace-census-2026-08-23.json"
)
REMEDIATION_DELIVERY = (
    AUDIT
    / "evidence"
    / "source"
    / "remediation-delivery-snapshot-2026-08-23.json"
)
COMPLETION_REPORT = AUDIT / "evidence" / "source" / "completion-gate-report.json"
CURRENT_MAIN_HANDOVER = (
    AUDIT
    / "evidence"
    / "browser"
    / "current-main-control-room-handover-viewport-evidence.json"
)
CURRENT_MAIN_VISUAL_RESAMPLE = (
    AUDIT
    / "evidence"
    / "browser"
    / "current-main-visual-family-resample-2026-08-14.json"
)

START = "<!-- AUDIT_DATA_START -->"
END = "<!-- AUDIT_DATA_END -->"


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


finding_document = json.loads(FINDINGS.read_text(encoding="utf-8"))
finding_rows = finding_document.get("findings", finding_document)

benchmark_document = json.loads(BENCHMARK.read_text(encoding="utf-8"))
benchmark_rows = benchmark_document["targets"]
visual_document = json.loads(VISUAL.read_text(encoding="utf-8"))
project_triage_document = json.loads(PROJECT_TRIAGE.read_text(encoding="utf-8"))
orchestration_document = json.loads(ORCHESTRATION.read_text(encoding="utf-8"))
remediation_workspace_document = json.loads(
    REMEDIATION_WORKSPACE.read_text(encoding="utf-8")
)
remediation_delivery_document = json.loads(
    REMEDIATION_DELIVERY.read_text(encoding="utf-8")
)
completion_report_document = json.loads(COMPLETION_REPORT.read_text(encoding="utf-8"))
current_main_handover_document = json.loads(
    CURRENT_MAIN_HANDOVER.read_text(encoding="utf-8")
)
current_main_visual_resample_document = json.loads(
    CURRENT_MAIN_VISUAL_RESAMPLE.read_text(encoding="utf-8")
)
completion_blocker_count = len(completion_report_document["completion_blockers"])
require(
    completion_report_document["status"] == "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE",
    "Completion report is not in the required blocked state",
)
require(completion_blocker_count > 0, "Completion blocker count is zero")
historical_browser_evidence_pin = str(current_main_handover_document["rendered_commit"])
require(
    historical_browser_evidence_pin
    == str(current_main_visual_resample_document["rendered_commit"]),
    "Historical browser evidence pins disagree",
)
historical_browser_evidence_short_pin = f"{historical_browser_evidence_pin[:7]}…"

unproved_rows = [
    {
        "module": row["canonical_module"].strip(),
        "id": row["working_key"].strip(),
        "status": row["status"].strip(),
    }
    for row in benchmark_rows
    if row.get("status", "").startswith("unproved")
]

module_counts = Counter(row["module"] for row in unproved_rows)
dashboard_findings = [
    {
        "id": row["id"],
        "priority": row["priority"],
        "remediationStatus": row.get("remediation", {}).get("status", "open"),
        "remediationNote": row.get("remediation", {}).get("note", ""),
        "remediationTaskId": row.get("remediation", {}).get("task_id", ""),
        "module": row["module"],
        "theme": row.get("submodule", ""),
        "problem": row.get("problem_root_cause", ""),
        "impact": row.get("impact", ""),
        "owner": row.get("proposed_owner", ""),
    }
    for row in finding_rows
]
remediation_counts = Counter(
    row["remediationStatus"] for row in dashboard_findings
)
priority_counts = Counter(row["priority"] for row in dashboard_findings)

payload = {
    "summary": {
        "capabilities": benchmark_document["denominator"]["total"],
        "humanCapabilities": benchmark_document["denominator"]["H"],
        "downloadApiCapabilities": benchmark_document["denominator"]["D"],
        "machineCapabilities": benchmark_document["denominator"]["M"],
        "canonicalRegistered": completion_report_document["gates"]["canonical_features_registered"]["completed"],
        "canonicalDenominator": completion_report_document["gates"]["canonical_features_registered"]["denominator"],
        "classifiedRoutePage": completion_report_document["gates"]["combined_route_page_static_disposition"]["completed"],
        "routePageDenominator": completion_report_document["gates"]["combined_route_page_static_disposition"]["denominator"],
        "benchmarkDecided": benchmark_document["summary"]["eligible_total"],
        "benchmarkVerified": benchmark_document["summary"]["verified_benchmark"]["total"],
        "benchmarkNcm": benchmark_document["summary"]["documented_no_credible_match"]["total"],
        "benchmarkUnproved": benchmark_document["summary"]["completion_unproved"]["total"],
        "visualRows": visual_document["counts"]["rows"],
        "visualAssigned": visual_document["counts"]["assigned_final_feature_id"],
        "visualUnresolved": visual_document["counts"]["unresolved_final_feature_id"],
        "visualUniqueTargets": visual_document["counts"]["unique_assigned_final_feature_ids"],
        "materialRows": completion_report_document["gates"]["material_required_states_linked_to_exact_final_feature_id"]["denominator"],
        "materialAssigned": completion_report_document["gates"]["material_required_states_linked_to_exact_final_feature_id"]["completed"],
        "projectsTriaged": project_triage_document["prompt_gate"]["substantive"],
        "projectDenominator": project_triage_document["prompt_gate"]["denominator"],
        "acceptedRoutes": completion_report_document["gates"]["routes_mapped_to_accepted_canonical_feature_id"]["completed"],
        "routeInventory": completion_report_document["gates"]["routes_mapped_to_accepted_canonical_feature_id"]["denominator"],
        "excludedRoutes": completion_report_document["gates"]["routes_mapped_to_accepted_canonical_feature_id"]["denominator"] - completion_report_document["gates"]["routes_mapped_to_accepted_canonical_feature_id"]["completed"],
        "acceptedPages": completion_report_document["gates"]["pages_mapped_to_accepted_canonical_feature_id"]["completed"],
        "pageInventory": completion_report_document["gates"]["pages_mapped_to_accepted_canonical_feature_id"]["denominator"],
        "excludedPages": completion_report_document["gates"]["pages_mapped_to_accepted_canonical_feature_id"]["denominator"] - completion_report_document["gates"]["pages_mapped_to_accepted_canonical_feature_id"]["completed"],
        "findingsOpen": remediation_counts["open"],
        "findingsInProgress": remediation_counts["in_progress"],
        "findingsFixedPendingVerification": remediation_counts["fixed_pending_verification"],
        "findingsVerified": remediation_counts["verified"],
        "findingsP0": priority_counts["P0"],
        "findingsP1": priority_counts["P1"],
        "findingsP2": priority_counts["P2"],
        "completionBlockedGates": completion_blocker_count,
        "tasksExecuted": completion_report_document["gates"]["representative_role_tasks_executed"]["completed"],
        "taskDenominator": completion_report_document["gates"]["representative_role_tasks_executed"]["denominator"],
        "testsExecuted": completion_report_document["gates"]["tests_executed"]["completed"],
        "testDenominator": completion_report_document["gates"]["tests_executed"]["denominator"],
        "journeysExecuted": completion_report_document["gates"]["journeys_executed_all_viewports"]["completed"],
        "journeyDenominator": completion_report_document["gates"]["journeys_executed_all_viewports"]["denominator"],
        "modulesPass8Complete": completion_report_document["gates"]["modules_with_all_eight_passes_complete"]["completed"],
        "moduleDenominator": completion_report_document["gates"]["modules_with_all_eight_passes_complete"]["denominator"],
    },
    "orchestration": orchestration_document,
    "remediationWorkspace": remediation_workspace_document,
    "remediationDelivery": remediation_delivery_document,
    "unprovedModules": sorted(
        module_counts.items(), key=lambda item: (-item[1], item[0])
    ),
    "currentMainBrowserEvidence": {
        "renderedCommit": current_main_handover_document["rendered_commit"],
        "routeName": current_main_handover_document["environment"]["route_name"],
        "requiredViewportsObserved": current_main_handover_document["summary"]["required_viewports_observed"],
        "requiredViewportsTotal": current_main_handover_document["summary"]["required_viewports_total"],
        "horizontalOverflowObservations": current_main_handover_document["summary"]["horizontal_overflow_observations"],
        "auditedBaselineCreditChanged": current_main_handover_document["summary"]["audited_baseline_credit_changed"],
        "sourceDriftMaterial": current_main_handover_document["source_drift"]["material"],
        "evidencePath": "evidence/browser/current-main-control-room-handover-viewport-evidence.json",
    },
    "currentMainVisualResample": {
        "familiesResampled": current_main_visual_resample_document["denominator"]["current_main_families_resampled"],
        "familyDenominator": current_main_visual_resample_document["denominator"]["retained_material_hero_or_overlay_finding_families"],
        "reproduced": current_main_visual_resample_document["summary"]["current_main_reproduced"],
        "partiallyReproduced": current_main_visual_resample_document["summary"]["current_main_partially_reproduced"],
        "auditedBaselineFamiliesResampled": current_main_visual_resample_document["denominator"]["audited_baseline_families_resampled"],
        "auditedBaselineCreditChanged": current_main_visual_resample_document["denominator"]["audited_baseline_credit_changed"],
        "evidencePath": "evidence/browser/current-main-visual-family-resample-2026-08-14.json",
    },
    "unprovedFeatures": sorted(
        unproved_rows, key=lambda row: (row["module"], row["id"])
    ),
    "findings": sorted(
        dashboard_findings,
        key=lambda row: (
            {"P0": 0, "P1": 1, "P2": 2}.get(row["priority"], 9),
            row["module"],
            row["id"],
        ),
    ),
}

json_payload = json.dumps(payload, ensure_ascii=False, separators=(",", ":"))
json_payload = json_payload.replace("<", "\\u003c")
replacement = (
    f"{START}\n"
    f"  <script id=\"dashboardData\" type=\"application/json\">{json_payload}</script>\n"
    f"  {END}"
)

source = DASHBOARD.read_text(encoding="utf-8")
require(source.count(START) == 1, "Dashboard data start marker missing or duplicated")
require(source.count(END) == 1, "Dashboard data end marker missing or duplicated")

updated = re.sub(
    rf"{re.escape(START)}.*?{re.escape(END)}",
    replacement,
    source,
    count=1,
    flags=re.DOTALL,
)


def replace_once(pattern: str, replacement_text: str, label: str) -> None:
    global updated
    candidate, count = re.subn(pattern, replacement_text, updated, count=1, flags=re.DOTALL)
    if count == 1:
        updated = candidate
        return
    require(
        replacement_text in updated,
        f"Dashboard static metric replacement failed: {label}",
    )


def replace_all(pattern: str, replacement_text: str, label: str) -> None:
    global updated
    candidate, count = re.subn(pattern, replacement_text, updated, flags=re.DOTALL)
    if count > 0:
        updated = candidate
        return
    require(
        replacement_text in updated,
        f"Dashboard static metric replacement failed: {label}",
    )


def replace_tracker(
    name: str,
    completed: int,
    total: int,
    complete_label: str,
) -> None:
    global updated
    require(total > 0, f"Dashboard tracker denominator is zero: {name}")
    require(0 <= completed <= total, f"Dashboard tracker count is invalid: {name}")

    percent = 100 * completed / total
    is_complete = completed == total
    row_class = " complete" if is_complete else ""
    state = complete_label if is_complete else f"{total - completed:,} remain"
    pattern = re.compile(
        rf'(<div class="track-row)(?: complete| blocked)?(">\s*'
        rf'<div class="track-name"><strong>{re.escape(name)}</strong>.*?'
        rf'<div class="track-bar"><span style="width:)[0-9.]+(%"></span></div>'
        rf'<div class="track-count">)[0-9,]+ / [0-9,]+'
        rf'(</div><div class="track-state">).*?(</div>)',
        flags=re.DOTALL,
    )

    def replacement_tracker(match: re.Match[str]) -> str:
        return (
            f"{match.group(1)}{row_class}{match.group(2)}{percent:.2f}"
            f"{match.group(3)}{completed:,} / {total:,}{match.group(4)}"
            f"{state}{match.group(5)}"
        )

    updated, count = pattern.subn(replacement_tracker, updated, count=1)
    require(count == 1, f"Dashboard tracker replacement failed: {name}")


summary = payload["summary"]
human_capabilities = int(summary["humanCapabilities"])
download_api_capabilities = int(summary["downloadApiCapabilities"])
machine_capabilities = int(summary["machineCapabilities"])
canonical_registered = int(summary["canonicalRegistered"])
canonical_denominator = int(summary["canonicalDenominator"])
classified_route_page = int(summary["classifiedRoutePage"])
route_page_denominator = int(summary["routePageDenominator"])
decided = int(summary["benchmarkDecided"])
verified = int(summary["benchmarkVerified"])
ncm = int(summary["benchmarkNcm"])
unproved = int(summary["benchmarkUnproved"])
capabilities = int(summary["capabilities"])
findings_p0 = int(summary["findingsP0"])
findings_p1 = int(summary["findingsP1"])
findings_p2 = int(summary["findingsP2"])
findings_open = int(summary["findingsOpen"])
findings_in_progress = int(summary["findingsInProgress"])
findings_fixed_pending = int(summary["findingsFixedPendingVerification"])
findings_verified = int(summary["findingsVerified"])
findings_total = findings_p0 + findings_p1 + findings_p2
findings_p0_p1 = findings_p0 + findings_p1
findings_p0_percent = 100 * findings_p0 / findings_total
findings_p1_percent = 100 * findings_p1 / findings_total
findings_p2_percent = 100 * findings_p2 / findings_total
visual_assigned = int(summary["visualAssigned"])
visual_rows = int(summary["visualRows"])
visual_unresolved = int(summary["visualUnresolved"])
material_rows = int(summary["materialRows"])
material_assigned = int(summary["materialAssigned"])
material_unresolved = material_rows - material_assigned
projects_triaged = int(summary["projectsTriaged"])
project_denominator = int(summary["projectDenominator"])
projects_remaining = project_denominator - projects_triaged
tasks_executed = int(summary["tasksExecuted"])
task_denominator = int(summary["taskDenominator"])
tests_executed = int(summary["testsExecuted"])
test_denominator = int(summary["testDenominator"])
journeys_executed = int(summary["journeysExecuted"])
journey_denominator = int(summary["journeyDenominator"])
modules_pass8_complete = int(summary["modulesPass8Complete"])
module_denominator = int(summary["moduleDenominator"])
accepted_routes = int(summary["acceptedRoutes"])
route_inventory = int(summary["routeInventory"])
excluded_routes = int(summary["excludedRoutes"])
accepted_pages = int(summary["acceptedPages"])
page_inventory = int(summary["pageInventory"])
excluded_pages = int(summary["excludedPages"])
route_percent = 100 * accepted_routes / route_inventory
page_percent = 100 * accepted_pages / page_inventory
orchestration_summary = orchestration_document["summary"]
workspace_summary = remediation_workspace_document["summary"]
delivery_summary = remediation_delivery_document["summary"]
research_tasks_active = int(workspace_summary["background_audit_tasks_active"])
remediation_tasks_active = 0
background_tasks_active = research_tasks_active + remediation_tasks_active
slot_holder = str(orchestration_summary["slot_holder"])
slot_in_use = int(orchestration_summary["heavy_php_slots_in_use"])
current_main_sha = str(remediation_workspace_document["current_main"])
workspace_findings = int(workspace_summary["findings_with_current_workspace"])
workspace_candidates = int(workspace_summary["findings_with_local_candidate_delta"])
workspace_clean = int(workspace_summary["clean_current_main_reconciliations"])
workspace_new_merges = int(
    workspace_summary["newly_merged_by_this_workspace_census"]
)
workspace_new_runtime = int(
    workspace_summary["newly_runtime_verified_by_this_workspace_census"]
)
delivery_total = int(delivery_summary["remediation_lanes"])
delivery_source_ready = int(delivery_summary["source_ready_or_better_count"])
delivery_runtime = int(delivery_summary["runtime_verified_count"])
delivery_runtime_pending = int(delivery_summary["runtime_pending_count"])
delivery_published = int(delivery_summary["branch_published_count"])
delivery_merged = int(delivery_summary["merged_to_main_count"])
delivery_tests = int(delivery_summary["focused_tests_passed"])
delivery_assertions = int(delivery_summary["focused_assertions_passed"])
benchmark_percent = 100 * decided / capabilities
visual_percent = 100 * visual_assigned / visual_rows
material_percent = 100 * material_assigned / material_rows
project_percent = 100 * projects_triaged / project_denominator
verified_percent = 100 * verified / capabilities
ncm_percent = 100 * ncm / capabilities
unproved_percent = 100 * unproved / capabilities
top_modules = payload["unprovedModules"][:4]
top_module_sentence = (
    f"{top_modules[1][0].replace('_', ' ').title()} follows with {top_modules[1][1]}, then "
    f"{top_modules[2][0].replace('_', ' ').title()} with {top_modules[2][1]} and "
    f"{top_modules[3][0].replace('_', ' ').title()} with {top_modules[3][1]}."
)
dashboard_date = datetime.fromisoformat(
    str(remediation_workspace_document["generated_at"])
).strftime("%d %b %Y").lstrip("0")
slot_summary = (
    f"sole heavy/frontend slot held by {slot_holder}"
    if slot_in_use
    else "sole heavy/frontend slot free"
)

replace_once(r"(\.stack \.verified \{ width: )[0-9.]+(%;)", rf"\g<1>{verified_percent:.2f}\g<2>", "verified stack width")
replace_once(r"(\.stack \.p0 \{ width: )[0-9.]+(%;)", rf"\g<1>{findings_p0_percent:.2f}\g<2>", "P0 stack width")
replace_once(r"(\.stack \.p1 \{ width: )[0-9.]+(%;)", rf"\g<1>{findings_p1_percent:.2f}\g<2>", "P1 stack width")
replace_once(r"(\.stack \.p2 \{ width: )[0-9.]+(%;)", rf"\g<1>{findings_p2_percent:.2f}\g<2>", "P2 stack width")
replace_once(r"<strong>BAD · Serious findings</strong><span>\d+ critical P0 and \d+ high P1 issues\.</span>", f"<strong>BAD · Serious findings</strong><span>{summary['findingsP0']} critical P0 and {summary['findingsP1']} high P1 issues.</span>", "serious finding truth")
replace_once(r"<strong>GOOD · Static map</strong><span>\d+ capabilities; every route and page classified\.</span>", f"<strong>GOOD · Static map</strong><span>{capabilities} capabilities; every route and page classified.</span>", "static capability truth")
replace_all(r"\b0/788\b", f"{tasks_executed}/{task_denominator}", "canonical task ratios")
replace_once(r"<small>\d+ human · \d+ API/download · \d+ machine</small>", f"<small>{human_capabilities} human · {download_api_capabilities} API/download · {machine_capabilities} machine</small>", "capability class summary")
replace_once(r"All \d+ human capabilities have a structural task script and scorecard row ready for testing\.", f"All {human_capabilities} human capabilities have a structural task script and scorecard row ready for testing.", "human task structural summary")
replace_once(r"\d+ of \d+ canonical human tasks and \d+ of \d+ executable tests were run by this audit\.", f"{tasks_executed} of {task_denominator} canonical human tasks and {tests_executed} of {test_denominator} executable tests were run by this audit.", "task and test execution summary")
replace_once(r"Run all \d+ task scripts, the test suite and all eight journeys", f"Run all {task_denominator} task scripts, the test suite and all eight journeys", "independent re-audit task total")
replace_once(r"<p>\d+ mandatory completion gates remain blocked\. This is a useful audit, not a readiness certificate\.</p>", f"<p>{completion_blocker_count} mandatory completion gates remain blocked. This is a useful audit, not a readiness certificate.</p>", "completion blocker count")
replace_once(r"<li>\d+ stable capability identities\.</li>", f"<li>{capabilities} stable capability identities.</li>", "stable capability summary")
replace_once(r"<li>\d+ retained findings: \d+ P0, \d+ P1 and \d+ P2\.</li>", f"<li>{findings_total} retained findings: {findings_p0} P0, {findings_p1} P1 and {findings_p2} P2.</li>", "retained findings summary")
replace_once(r"<li>All \d+ P0/P1 findings have a current owner, evidence and acceptance criteria\.</li>", f"<li>All {findings_p0_p1} P0/P1 findings have a current owner, evidence and acceptance criteria.</li>", "P0/P1 finding ownership summary")
replace_once(r"<li>All [\d,]+ routes and [\d,]+ (?:Inertia )?pages(?: statically)? classified\.</li>", f"<li>All {route_inventory:,} routes and {page_inventory:,} Inertia pages statically classified.</li>", "route and true page denominator summary")
replace_once(r"<small>\d+ P0 · \d+ P1 · \d+ P2</small>", f"<small>{findings_p0} P0 · {findings_p1} P1 · {findings_p2} P2</small>", "finding priority summary")
replace_once(r"(<span class=\"kicker\">Issues found</span>\s*<strong>)\d+(</strong>)", rf"\g<1>{findings_total}\g<2>", "finding total metric")
replace_once(r"How the \d+ retained findings divide by priority\.", f"How the {findings_total} retained findings divide by priority.", "finding severity total")
replace_once(r"aria-label=\"\d+ priority zero, \d+ priority one, \d+ priority two\"", f"aria-label=\"{findings_p0} priority zero, {findings_p1} priority one, {findings_p2} priority two\"", "finding severity ARIA")
replace_once(r">\d+ P0 — critical</span>", f">{findings_p0} P0 — critical</span>", "P0 legend")
replace_once(r"<li>All \d+ findings now point to at least one current capability owner\.</li>", f"<li>All {findings_total} findings now point to at least one current capability owner.</li>", "finding ownership summary")
replace_once(r"<li>\d+ P0 and \d+ P1 findings include serious availability, authorization, safety, recovery and data-boundary risks\.</li>", f"<li>{findings_p0} P0 and {findings_p1} P1 findings include serious availability, authorization, safety, recovery and data-boundary risks.</li>", "serious finding narrative")
replace_once(r">\d+ P1 — high</span>", f">{findings_p1} P1 — high</span>", "P1 legend")
replace_once(r">\d+ P2 — important</span>", f">{findings_p2} P2 — important</span>", "P2 legend")
replace_once(r"All \d+ findings have a current capability link\. Ownership is not the same as remediation\.", f"All {findings_total} findings have a current capability link. Ownership is not the same as remediation.", "finding linkage narrative")
replace_once(r"<a class=\"button\" href=\"findings\.json\">Open all \d+ findings</a>", f"<a class=\"button\" href=\"findings.json\">Open all {findings_total} findings</a>", "findings button")
replace_once(r"<span id=\"orchestrationSummary\">.*?</span>", f"<span id=\"orchestrationSummary\"><strong>{background_tasks_active} background tasks active:</strong> audit expansion stopped · remediation workspace snapshot complete · {slot_summary}</span>", "orchestration summary")
replace_once(r"<span id=\"currentMainRelease\">.*?</span>", f"<span id=\"currentMainRelease\">Latest reviewed release on main: <code>{current_main_sha}</code></span>", "current main release")
replace_once(r"<h3 id=\"workspaceFindingHeading\">.*?</h3>", f"<h3 id=\"workspaceFindingHeading\">{workspace_findings} findings have current workspaces</h3>", "workspace finding heading")
replace_once(r"<li id=\"workspaceCandidateSummary\">.*?</li>", f"<li id=\"workspaceCandidateSummary\">{workspace_candidates} findings have a local dirty or ahead candidate.</li>", "workspace candidate summary")
replace_once(r"<li id=\"workspaceCleanSummary\">.*?</li>", f"<li id=\"workspaceCleanSummary\">{workspace_clean} have a clean current-main reconciliation with no duplicate local patch.</li>", "workspace clean summary")
replace_once(r"<span id=\"deliveryCardLabel\" class=\"decision-label\">.*?</span>", "<span id=\"deliveryCardLabel\" class=\"decision-label\">Latest remediation delivery</span>", "delivery card label")
replace_once(r"<h3 id=\"reviewedWaveHeading\">.*?</h3>", f"<h3 id=\"reviewedWaveHeading\">{delivery_runtime} runtime-verified · {delivery_runtime_pending} pending runtime</h3>", "delivery heading")
replace_once(r"<li id=\"reviewedWavePublication\">.*?</li>", f"<li id=\"reviewedWavePublication\">{delivery_source_ready} of {delivery_total} are source-ready or better; {delivery_published} published and {delivery_merged} merged.</li>", "delivery publication summary")
replace_once(r"<li id=\"reviewedWaveRuntime\">.*?</li>", f"<li id=\"reviewedWaveRuntime\">AUTH gate: {delivery_tests} tests and {delivery_assertions} assertions passed.</li>", "delivery runtime summary")
replace_once(r"<h2 id=\"issues-title\">\d+ issues found</h2>", f"<h2 id=\"issues-title\">{findings_total} issues found</h2>", "issues heading total")
replace_once(r"<div class=\"priority p0\"><strong>\d+</strong><span>P0 critical</span></div>", f"<div class=\"priority p0\"><strong>{findings_p0}</strong><span>P0 critical</span></div>", "P0 priority card")
replace_once(r"<div class=\"priority p1\"><strong>\d+</strong><span>P1 high</span></div>", f"<div class=\"priority p1\"><strong>{findings_p1}</strong><span>P1 high</span></div>", "P1 priority card")
replace_once(r"<div class=\"priority p2\"><strong>\d+</strong><span>P2 important</span></div>", f"<div class=\"priority p2\"><strong>{findings_p2}</strong><span>P2 important</span></div>", "P2 priority card")
replace_once(r"<strong id=\"remediationSummary\">.*?</strong>", f"<strong id=\"remediationSummary\">{findings_in_progress} marked in progress · {findings_fixed_pending} awaiting verification · {findings_verified} verified fixed · {findings_open} open</strong>", "static remediation summary")
replace_once(r"Current checkpoint: <strong>.*?</strong>", f"Current checkpoint: <strong>{dashboard_date} · live {capabilities} audit and remediation state</strong>", "current checkpoint date")
replace_once(r"<div class=\"update-stamp\"><strong>.*?</strong><br>Last dashboard update: .*?</div>", f"<div class=\"update-stamp\"><strong>Checkpoint {capabilities} · live evidence and remediation state</strong><br>Last dashboard update: {dashboard_date}</div>", "tracker checkpoint")
replace_once(r"(\.stack \.ncm \{ width: )[0-9.]+(%;)", rf"\g<1>{ncm_percent:.2f}\g<2>", "NCM stack width")
replace_once(r"(\.stack \.unproved \{ width: )[0-9.]+(%;)", rf"\g<1>{unproved_percent:.2f}\g<2>", "unproved stack width")
replace_once(r"All 97 prompt-listed open-source projects have catalogue metadata; \d+ have substantive project-specific review\.", f"All 97 prompt-listed open-source projects have catalogue metadata; {projects_triaged} have substantive project-specific review.", "project overview")
replace_once(r"\d+ capabilities still need a benchmark or bounded no-match decision\.", f"{unproved} capabilities still need a benchmark or bounded no-match decision.", "unknown overview")
replace_once(r"(<span class=\"kicker\">Features still unproved</span>\s*<strong>)\d+(</strong>)", rf"\g<1>{unproved}\g<2>", "unproved metric")
replace_once(r"(<span class=\"kicker\">Capability map</span>\s*<strong>)\d+(</strong>)", rf"\g<1>{capabilities}\g<2>", "capability total metric")
replace_tracker(
    "Capability identities",
    canonical_registered,
    canonical_denominator,
    "Complete static",
)
replace_tracker(
    "Route/page classification",
    classified_route_page,
    route_page_denominator,
    "Complete static",
)
replace_tracker(
    "Substantive project triage",
    projects_triaged,
    project_denominator,
    "Complete substantive",
)
replace_tracker(
    "Benchmark decisions",
    decided,
    capabilities,
    "Complete evidence",
)
replace_tracker(
    "Visual ownership",
    visual_assigned,
    visual_rows,
    "Complete ownership",
)
replace_tracker(
    "Canonical task execution",
    tasks_executed,
    task_denominator,
    "Complete runtime",
)
replace_tracker(
    "Audit-wide executable tests",
    tests_executed,
    test_denominator,
    "Complete runtime",
)
replace_tracker(
    "Cross-module journeys",
    journeys_executed,
    journey_denominator,
    "Complete runtime",
)
replace_tracker(
    "Modules with all eight passes",
    modules_pass8_complete,
    module_denominator,
    "Complete evidence",
)
replace_once(r"<div class=\"donut\" style=\"--p: [0-9.]+; --c: var\(--teal\)\" role=\"img\" aria-label=\"[0-9.]+ percent of routes map to accepted capability IDs\">\s*<div class=\"donut-value\"><strong>[0-9.]+%</strong><span>[0-9,]+ / [0-9,]+</span></div>", f"<div class=\"donut\" style=\"--p: {route_percent:.2f}; --c: var(--teal)\" role=\"img\" aria-label=\"{route_percent:.2f} percent of routes map to accepted capability IDs\">\n            <div class=\"donut-value\"><strong>{route_percent:.2f}%</strong><span>{accepted_routes:,} / {route_inventory:,}</span></div>", "route donut")
replace_once(r"All routes are classified; \d+ sit outside the accepted capability denominator\.", f"All routes are classified; {excluded_routes} sit outside the accepted capability denominator.", "route donut copy")
replace_once(r"<div class=\"donut\" style=\"--p: [0-9.]+; --c: var\(--teal\)\" role=\"img\" aria-label=\"[0-9.]+ percent of pages map to accepted capability IDs\">\s*<div class=\"donut-value\"><strong>[0-9.]+%</strong><span>[0-9,]+ / [0-9,]+</span></div>", f"<div class=\"donut\" style=\"--p: {page_percent:.2f}; --c: var(--teal)\" role=\"img\" aria-label=\"{page_percent:.2f} percent of pages map to accepted capability IDs\">\n            <div class=\"donut-value\"><strong>{page_percent:.2f}%</strong><span>{accepted_pages:,} / {page_inventory:,}</span></div>", "page donut")
replace_once(r"All pages are classified; \d+ retain excluded surface dispositions\.", f"All pages are classified; {excluded_pages} retain excluded surface dispositions.", "page donut copy")
replace_once(r"<div class=\"donut\" style=\"--p: [0-9.]+; --c: var\(--amber\)\" role=\"img\" aria-label=\"[0-9.]+ percent of capabilities benchmarked or given a bounded no credible match decision\">\s*<div class=\"donut-value\"><strong>[0-9.]+%</strong><span>[0-9,]+ / [0-9,]+</span></div>", f"<div class=\"donut\" style=\"--p: {benchmark_percent:.2f}; --c: var(--amber)\" role=\"img\" aria-label=\"{benchmark_percent:.2f} percent of capabilities benchmarked or given a bounded no credible match decision\">\n            <div class=\"donut-value\"><strong>{benchmark_percent:.2f}%</strong><span>{decided:,} / {capabilities:,}</span></div>", "benchmark donut")
replace_once(r"\d+ verified analogues plus \d+ bounded “no credible match” decisions\.", f"{verified} verified analogues plus {ncm} bounded “no credible match” decisions.", "benchmark donut copy")
replace_once(r"<div class=\"donut\" style=\"--p: [0-9.]+; --c: var\(--blue\)\" role=\"img\" aria-label=\"[0-9.]+ percent of visual rows linked to a final feature ID\">\s*<div class=\"donut-value\"><strong>[0-9.]+%</strong><span>[0-9,]+ / [0-9,]+</span></div>", f"<div class=\"donut\" style=\"--p: {visual_percent:.2f}; --c: var(--blue)\" role=\"img\" aria-label=\"{visual_percent:.2f} percent of visual rows linked to a final feature ID\">\n            <div class=\"donut-value\"><strong>{visual_percent:.2f}%</strong><span>{visual_assigned:,} / {visual_rows:,}</span></div>", "visual donut")
replace_once(r"<div class=\"donut\" style=\"--p: [0-9.]+; --c: var\(--blue\)\" role=\"img\" aria-label=\"[0-9.]+ percent of required material states linked to a final feature ID\">\s*<div class=\"donut-value\"><strong>[0-9.]+%</strong><span>[0-9,]+ / [0-9,]+</span></div>", f"<div class=\"donut\" style=\"--p: {material_percent:.2f}; --c: var(--blue)\" role=\"img\" aria-label=\"{material_percent:.2f} percent of required material states linked to a final feature ID\">\n            <div class=\"donut-value\"><strong>{material_percent:.2f}%</strong><span>{material_assigned:,} / {material_rows:,}</span></div>", "material-state donut")
replace_once(r"\d+ visual rows still lack a uniquely proved final feature owner\.", f"{visual_unresolved} visual rows still lack a uniquely proved final feature owner.", "visual donut copy")
replace_once(r"\d+ capabilities still lack a completed target-specific benchmark decision\.", f"{unproved} capabilities still lack a completed target-specific benchmark decision.", "benchmark verdict")
replace_once(r"\d+ stable capability identities give the product a much clearer, traceable map\.", f"{capabilities} stable capability identities give the product a much clearer, traceable map.", "stable capability verdict")
replace_once(r"\d+ visual rows and \d+ material-state rows remain without exact final ownership\.", f"{visual_unresolved} visual rows and {material_unresolved} material-state rows remain without exact final ownership.", "visual verdict")
replace_once(r"aria-label=\"\d+ verified, \d+ no credible match, \d+ unproved\"", f"aria-label=\"{verified} verified, {ncm} no credible match, {unproved} unproved\"", "benchmark stack label")
replace_once(r">\d+ verified</span>", f">{verified} verified</span>", "benchmark verified legend")
replace_once(r">\d+ bounded NCM</span>", f">{ncm} bounded NCM</span>", "benchmark NCM legend")
replace_once(r">\d+ unproved</span>", f">{unproved} unproved</span>", "benchmark unproved legend")
replace_once(r"(<h2 id=\"unproved-title\">)\d+( capabilities remain unproved</h2>)", rf"\g<1>{unproved}\g<2>", "unproved title")
replace_once(r"How the \d+ capabilities divide today\.", f"How the {capabilities} capabilities divide today.", "capability distribution total")
replace_once(r"<p>[^<]* follows with \d+, then [^<]* with \d+ and [^<]* with \d+\.</p>", f"<p>{top_module_sentence}</p>", "module queue narrative")
replace_once(r"<strong>\d+ decided \+ \d+ unproved = \d+ total</strong>", f"<strong>{decided} decided + {unproved} unproved = {capabilities} total</strong>", "benchmark arithmetic")
replace_once(r"to inspect all \d+ unproved targets\.", f"to inspect all {unproved} unproved targets.", "unproved matrix link")
replace_once(r"Open the complete \d+-row benchmark matrix", f"Open the complete {capabilities}-row benchmark matrix", "benchmark matrix total")
replace_once(r"Resolve the \d+ benchmark decisions and the \d+ visual ownership gaps without broad family assumptions\.", f"Resolve the {unproved} benchmark decisions and the {visual_unresolved} visual ownership gaps without broad family assumptions.", "next research step")
replace_once(r"Use the full matrix for all \d+ unproved targets\.", f"Use the full matrix for all {unproved} unproved targets.", "empty filter guidance")
replace_once(r"The synthetic Clinical Lead was directly sampled on current main at all four viewports\.", f"The synthetic Clinical Lead was directly sampled on the historical browser-evidence pin <code>{historical_browser_evidence_short_pin}</code> at all four viewports; it is not a current <code>origin/main</code> assertion.", "historical Clinical Lead browser pin")
replace_once(r"Current main now has a fresh 4/4 supplemental resample, but source drift means the immutable-baseline gate honestly remains 0/4\.", f"The historical browser-evidence pin <code>{historical_browser_evidence_short_pin}</code> has a 4/4 supplemental resample, but source drift means the immutable-baseline gate honestly remains 0/4.", "historical visual resample pin")
replace_once(r"Current main now routes Staff creation through HR People with an authorised active-Site picker and atomic identity creation\.", f"The historical remediation browser-evidence pin <code>{historical_browser_evidence_short_pin}</code> routes Staff creation through HR People with an authorised active-Site picker and atomic identity creation; this is not a current <code>origin/main</code> assertion.", "historical staff browser pin")
replace_once(r"Current main uses the corrected Shift relationship and the finding is recorded as verified fixed\.", f"The historical remediation browser-evidence pin <code>{historical_browser_evidence_short_pin}</code> used the corrected Shift relationship; the finding remains recorded as verified fixed without asserting current <code>origin/main</code> state.", "historical Shift browser pin")
replace_once(r"Direct Clinical Lead login on current main\.", f"Direct Clinical Lead login on the historical browser-evidence pin <code>{historical_browser_evidence_short_pin}</code>.", "historical Clinical Lead step pin")
replace_once(r"Supplemental current-main browser evidence · baseline unchanged", f"Supplemental historical browser evidence (<code>{historical_browser_evidence_short_pin}</code>) · baseline unchanged", "historical handover eyebrow")
replace_once(r"This proves the current-main rendering only; the four immutable-baseline rows remain lightweight and the 1,876/1,880 baseline gate is unchanged\.", f"This proves only rendering on the historical browser-evidence pin <code>{historical_browser_evidence_short_pin}</code>, not current <code>origin/main</code>; the four immutable-baseline rows remain lightweight and the 1,876/1,880 baseline gate is unchanged.", "historical handover boundary")
replace_once(r"Supplemental current-main resample · four retained visual families", f"Supplemental historical browser-evidence resample (<code>{historical_browser_evidence_short_pin}</code>) · four retained visual families", "historical resample eyebrow")
replace_once(r"<strong>Evidence boundary:</strong> this healthy isolated server renders current main <code>ad19f…</code>; the audited Herd checkout at", f"<strong>Evidence boundary:</strong> this healthy isolated server rendered the historical browser-evidence pin <code>{historical_browser_evidence_short_pin}</code>, not current <code>origin/main</code>; the audited Herd checkout at", "historical resample server pin")
replace_once(r"baseline resample gate remains 0/4 rather than inheriting current-main evidence\.", "baseline resample gate remains 0/4 rather than inheriting historical browser-evidence credit.", "historical resample credit boundary")
replace_once(r"Current authority: \d+-capability audit snapshot", f"Current authority: {capabilities}-capability audit snapshot", "footer capability authority")

DASHBOARD.write_text(updated, encoding="utf-8", newline="\n")

print(
    json.dumps(
        {
            "dashboard": str(DASHBOARD),
            "findings": len(payload["findings"]),
            "unproved_features": len(payload["unprovedFeatures"]),
            "modules": len(payload["unprovedModules"]),
            "benchmark_decided": payload["summary"]["benchmarkDecided"],
            "visual_assigned": payload["summary"]["visualAssigned"],
        },
        indent=2,
    )
)
