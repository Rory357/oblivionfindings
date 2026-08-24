#!/usr/bin/env python3
"""Append the bounded current-main Clinical Lead browser finding idempotently."""

from __future__ import annotations

import json
from collections import Counter
from pathlib import Path


AUDIT = Path(__file__).resolve().parent.parent
FINDINGS = AUDIT / "findings.json"
MANIFEST = AUDIT / "evidence" / "source" / "working-capability-manifest-902.json"
EVIDENCE = AUDIT / "evidence" / "source" / "browser-clinical-lead-current-main-pass-902.json"
FINDING_ID = "VIS-EMAR-CLINICAL-LEAD-MOBILE-OVERFLOW-01"


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


data = json.loads(FINDINGS.read_text(encoding="utf-8"))
manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))
evidence = json.loads(EVIDENCE.read_text(encoding="utf-8"))
manifest_ids = {row["working_key"] for row in manifest["targets"]}
require("CAP-MED-EMAR-WORKSPACE" in manifest_ids, "Current eMAR workspace target missing")
require(evidence["coverage_effect"]["representative_actor_classes_after"] == 12, "Actor evidence drift")

finding = {
    "id": FINDING_ID,
    "remediation": {
        "status": "in_progress",
        "task_id": "019ffe46-f6af-7652-a951-cc056af7826e",
        "branch": "codex/vis-emar-clinical-lead-mobile-overflow-01",
        "commit": "24dbcb21a996f88c5be36c2908c9e6e136c90278",
        "note": (
            "A two-file responsive-layout correction is committed and pushed on an isolated branch. "
            "Focused Vitest, TypeScript, scoped ESLint/Prettier, client and SSR builds passed. "
            "Exact-worktree read-only browser verification at 390x844, 1024x768, 1280x800 and "
            "1440x900 found no document overflow or console warnings in the sampled state and preserved "
            "the existing workspace actions. Independent source review subsequently rejected completion "
            "because the Client board header and other fixed-row card headers remain outside the focused "
            "test boundary and may overflow at 320px/360px with populated content. A bounded correction "
            "and expanded browser proof are requested; merge remains blocked, so this does not change the "
            "immutable audited-baseline finding or any task/ease/runtime completion gate."
        ),
    },
    "feature_ids": ["CAP-MED-EMAR-WORKSPACE"],
    "passes": ["P2", "P4", "P8"],
    "module": "eMAR and medications",
    "submodule": "Clinical Lead medication-oversight workspace responsive layout",
    "actor_and_job": "A Clinical/Medication Lead reviews medication exceptions, controlled-drug checks, compliance and client progress from a phone-sized viewport.",
    "route_url": {
        "summary": "Exact current eMAR dashboard route.",
        "route_names": ["emar.index"],
        "route_paths": ["emar"],
    },
    "frontend_anchor": {
        "summary": "The one-column mobile grid contains cards whose intrinsic minimum width expands beyond the grid and document viewport.",
        "page_files": ["resources/js/pages/emar/Index.tsx:948-1288"],
        "audited_commit": "ad19f994a280835d039d1a31ebdcb05778733c5a",
    },
    "visual_context": {
        "visual_id": "BVIS-0013",
        "classification": "Observed",
        "role": "Clinical Lead",
        "site_scope": "Synthetic local test data; one visible site in the medication workspace",
        "viewport": "390x844",
        "state": "Loaded eMAR oversight dashboard before any domain mutation",
        "pattern_type": "responsive overflow / safety-critical operational dashboard",
        "component_anchor": "resources/js/pages/emar/Index.tsx:948-1288",
        "screenshot_reference": "evidence/browser/clinical-lead-emar-390x844.png",
        "internal_baseline": "The Health & Clinical command centre rendered without horizontal overflow for the same role and viewport.",
    },
    "pattern_implementation": "At 390x844, the eMAR document client width is 375px but scroll width is 601px. The main grid is 313px wide while its Action centre and right-rail cards render at 563px. The same route has no document overflow at 1024x768, 1280x800 or 1440x900.",
    "backend_anchors": [
        "app/Http/Controllers/Emar/EmarController.php:788-808",
        "ROUTE-0327",
        "PAGE-0096",
    ],
    "current_behavior": "A directly authenticated Clinical Lead can open eMAR, but at the required 390x844 viewport the page is 226px wider than the document client area. Action-centre, compliance, med-pass and reason cards extend off-screen and require horizontal scrolling. Recharts also emits a non-fatal negative-size warning on each eMAR render.",
    "current_workflow": {
        "summary": "Login as the synthetic Clinical Lead, open eMAR and inspect the oversight dashboard at 390x844 without submitting an action.",
        "failure_sequence": "Render /emar at 390x844; the grid client is 313px; child cards retain 563px width; document scroll width becomes 601px against 375px client width.",
        "boundary": "Responsive presentation and scanability only; medication state and backend authorization were not mutated or completion-tested.",
        "completion_evidence": "Direct-role browser render, exact DOM geometry, full-page screenshot, console capture and exact current source anchor.",
    },
    "ease_evidence": {
        "validation_status": "Browser-observed responsive defect; canonical task and ten-dimension score remain unexecuted",
        "evidence_basis": "Exact 390x844 geometry plus three larger required-viewport controls",
        "current_scores": {
            "discoverability": None,
            "comprehension": None,
            "learnability": None,
            "efficiency": None,
            "error_prevention": None,
            "recovery": None,
            "accessibility": None,
            "safety_and_trust": None,
            "consistency": None,
            "cross_module_continuity": None,
        },
        "friction": {
            "completion_time": "Not measured; no task submitted",
            "step_count": "Login and one route navigation for observation only",
            "required_field_count": "Not applicable",
            "decision_count": "Not measured",
            "context_switches": "Horizontal pan is required to inspect the full action/compliance region",
            "dead_ends": "No dead end proved; content remains reachable with horizontal scrolling",
            "recovery_path": "Use a larger viewport; no in-page responsive recovery exists",
        },
        "target_scores": {"all_dimensions": 4, "safety_critical_error_prevention_and_trust": 5},
        "independent_review": "Re-sample the corrected mobile grid with a Clinical Lead and a medication frontline actor before assigning task or ease scores.",
    },
    "evidence": {
        "anchors": [
            "evidence/source/browser-clinical-lead-current-main-pass-902.json",
            "evidence/browser/clinical-lead-emar-390x844.png",
            "resources/js/pages/emar/Index.tsx:948-1288",
        ],
        "existing_tests": [],
        "tests_executed": False,
        "browser_claim_limit": "Responsive overflow and the chart warning are proved on current local main. No medication task, persisted state, denial, recovery or production behavior is claimed.",
    },
    "problem_root_cause": "The mobile single-column grid does not constrain its card/grid items to the available width; intrinsic content sizing expands the Action centre and right rail from a 313px grid track to 563px.",
    "impact": "A phone-sized Clinical Lead view cannot scan the safety-critical action centre and medication oversight cards without horizontal panning, increasing omission and interpretation risk during mobile review.",
    "benchmark": {
        "selected": "Benchmark unproved",
        "url_and_sha": "",
        "verified_behavior": "",
        "outcome": "Unproved—no completion credit",
        "no_match_evidence": "This is a current-product responsive finding, not a new benchmark adjudication.",
    },
    "neutral_requirements": "At every required viewport, the medication-oversight workspace must fit the document width, preserve readable action priority and keep all controls reachable without page-level horizontal scrolling.",
    "better_oblivion_design": "Preserve the existing eMAR composition and constrain grid/card children to min-width zero, allowing action headers, tabs, charts and outcome rows to wrap or scroll only within their intentional local containers.",
    "target_ease": {
        "scores": {"all_dimensions": 4, "safety_critical_error_prevention_and_trust": 5},
        "measurable_outcome": "At 390x844, document scroll width is no greater than client width and the action centre, compliance, outcomes and reason cards remain fully reachable and readable.",
    },
    "cross_module_effects": "The same Clinical Lead can render Health & Clinical without overflow; the defect is currently bounded to the eMAR workspace composition, not the global shell.",
    "rbac_privacy": "No permission broadening is required. Preserve the Clinical Lead's current Site, role, ownership and minimum-necessary data boundaries.",
    "priority": "P1",
    "effort": "S",
    "dependencies_sequence": "Constrain the existing grid/card composition, verify chart sizing, then repeat the two Clinical Lead pages at all four required viewports.",
    "proposed_owner": "Medication Experience Owner and Design System Owner",
    "confidence": "High—direct-role render, exact DOM geometry, screenshot and source composition agree",
    "source_boundary": "Observed on a local owner-confirmed test/development checkout at current remediation main; no production claim is made.",
    "interim_safeguard": "Use tablet/desktop for eMAR oversight until the mobile layout is corrected; do not treat the mobile render as completed usability evidence.",
    "acceptance_criteria": [
        "At 390x844, /emar document scroll width is less than or equal to document client width.",
        "Action centre, compliance, outcomes and reason cards fit or use intentional local overflow without clipping page controls.",
        "Recharts emits no negative width/height warning after layout settles.",
        "The same layout remains non-overflowing at 1024x768, 1280x800 and 1440x900.",
        "Clinical Lead Site/role data scope and existing eMAR wording/actions are unchanged.",
    ],
    "missing_tests": [
        "eMAR dashboard 390x844 responsive geometry",
        "Action-centre and right-rail min-width regression",
        "Recharts settled-container warning check",
    ],
    "validation_plan": [
        "Render /emar as Clinical Lead at all four required viewports",
        "Assert document scrollWidth <= clientWidth at 390x844",
        "Capture visible cards and console after chart settle",
        "Confirm Health & Clinical and desktop eMAR controls remain unchanged",
    ],
    "official_sources": [],
    "statement_types": {
        "source": "The current eMAR page defines the affected grid/cards at Index.tsx:948-1288.",
        "official_source": "No legal, clinical or third-party design conclusion is asserted.",
        "inference": "Horizontal panning may increase omission risk; no medication error was induced or claimed.",
        "specialist_decision": "Medication and design owners must select the responsive wrapping/containment behavior.",
    },
    "official_source_proposition_keys": [],
    "feature_link_reconciliation": {
        "method": "Exact current ROUTE-0327/PAGE-0096 and eMAR workspace source intersection.",
        "projection_status": "one exact current link; browser-observed at 390x844; task completion blocked",
        "legacy_feature_ids": ["MED-EMAR"],
        "decisions": [
            {
                "method": "exact routed render and observed source ownership",
                "feature_ids": ["CAP-MED-EMAR-WORKSPACE"],
                "route_hits": ["ROUTE-0327"],
                "page_hits": ["PAGE-0096"],
            }
        ],
        "uncertainties": [],
    },
}

rows = [row for row in data["findings"] if row.get("id") != FINDING_ID]
rows.append(finding)
require(len({row["id"] for row in rows}) == len(rows), "Finding IDs are not unique")

# Refresh the previously retained global-shell browser boundary without changing
# its historical symptom or verified remediation result.
for row in rows:
    if row.get("id") == "TASK-SHIFT-RELATION-500-01":
        row["evidence"]["browser_claim_limit"] = (
            "The 500 and relationship mismatch remain proved at the audited snapshot. "
            "Current-main Administrator and direct Clinical Lead landings now render, and the Clinical Lead "
            "Health & Clinical/eMAR entry surfaces were sampled at all four required viewports. Full canonical "
            "task execution and provider-failure recovery remain outside this browser slice."
        )

priority = Counter(row.get("priority", "") for row in rows)
exact_links = {
    (row["id"], feature)
    for row in rows
    for feature in row.get("feature_ids", [])
    if feature in manifest_ids
}
exact_finding_ids = {finding_id for finding_id, _ in exact_links}
p0_p1 = [row for row in rows if row.get("priority") in {"P0", "P1"}]
p0_p1_exact = {row["id"] for row in p0_p1} & exact_finding_ids
decisions = [
    decision
    for row in rows
    for decision in row.get("feature_link_reconciliation", {}).get("decisions", [])
]
old_reconciliation = data["counts"]["feature_link_reconciliation"]
old_reconciliation.update({
    "findings": len(rows),
    "total_links": sum(len(row.get("feature_ids", [])) for row in rows),
    "literal_exact_current_links": len(exact_links),
    "literal_exact_current_targets": len({feature for _, feature in exact_links}),
    "findings_with_literal_exact_current_id": len(exact_finding_ids),
    "p0_p1_with_literal_exact_current_id": len(p0_p1_exact),
    "p0_p1_without_literal_exact_current_id": len(p0_p1) - len(p0_p1_exact),
    "findings_with_uncertainty": sum(
        bool(row.get("feature_link_reconciliation", {}).get("uncertainties")) for row in rows
    ),
    "findings_without_literal_exact_current_id": len(rows) - len(exact_finding_ids),
    "route_intersection_groups": sum(bool(decision.get("route_hits")) for decision in decisions),
    "unique_page_intersection_groups": sum(bool(decision.get("page_hits")) for decision in decisions),
    "final_feature_link_coverage_established": len(exact_finding_ids) == len(rows),
})
old_reconciliation["visual_linkage"] = {
    "assigned": 8153,
    "rows": 8753,
    "unresolved": 600,
    "unique_working_ids": 771,
}
data["counts"].update({"P0": priority["P0"], "P1": priority["P1"], "P2": priority["P2"], "P3": priority["P3"]})
existing_remediation_snapshot = data.get("remediation_snapshot", {})
remediation_counts = Counter(
    row.get("remediation", {}).get("status", "open") for row in rows
)
data["remediation_snapshot"] = {
    "as_of": existing_remediation_snapshot.get("as_of", "not recorded"),
    "origin_main_commit": existing_remediation_snapshot.get("origin_main_commit", "not recorded"),
    "status_counts": {
        "open": remediation_counts["open"],
        "in_progress": remediation_counts["in_progress"],
        "fixed_pending_verification": remediation_counts["fixed_pending_verification"],
        "verified": remediation_counts["verified"],
    },
    "boundary": "The audited_commit remains the immutable evidence baseline. This separate snapshot records later remediation and current-main browser evidence without retroactively changing baseline behavior or canonical task credit.",
}
data["audit_status"] = (
    "Blocked—not comprehensive or complete. The corrected 902-target register is current (788H/111D/3M). "
    "All 3,024 routes and 962 pages have accepted-target or excluded-surface static dispositions; accepted IDs "
    "map to 2,985 routes and 945 pages. Benchmark/NCM completion credit is 450/902, visual final-ID linkage is "
    f"8,153/8,753, material-state linkage is 3,935/4,312, and {len(rows)} source-backed findings are retained. "
    f"All {len(p0_p1_exact)} P0/P1 findings contain a literal current-manifest ID. A bounded current-main browser pass now samples 12/12 "
    "actor classes, but 0/788 canonical tasks are complete and runtime remains incomplete."
)
data["statement"] = (
    "Full schema for every retained finding. The 902-row stable-ID manifest is current; static evidence, browser "
    "observation, inference, official-source propositions and owner decisions remain separated. Actor entry is "
    "12/12 on the bounded local pass, but task, failure, recovery and usability completion are not claimed."
)
data["findings"] = rows

FINDINGS.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
print(json.dumps({
    "findings": len(rows),
    "priority": dict(sorted(priority.items())),
    "literal_exact_links": len(exact_links),
    "literal_exact_targets": len({feature for _, feature in exact_links}),
    "p0_p1_exact": len(p0_p1_exact),
}, indent=2))
