#!/usr/bin/env python3
"""Stage RUN-076 matrix-derived reporting artifacts without awarding audit credit.

This generator is deliberately conservative.  It turns already committed audit
evidence into reviewable reports, but it does not convert static locators,
sentinels, unknown-build observations, or provisional claims into final findings,
browser coverage, ease scores, benchmark mappings, pass credit, or completion.
"""

from __future__ import annotations

import csv
import hashlib
import json
import os
from collections import Counter, defaultdict
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_DIR = Path(os.environ["OBLIVION_AUDIT_REPORT_OUTPUT_DIR"]).resolve()
assert OUTPUT_DIR.is_dir()
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
AUDIT_CHECKPOINT = "0d5a05e30878d4c24cb7b83c27e63e8c09b498a3"
GENERATED_ON = "2026-08-25"
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
ARCHITECTURE_RULE = (
    "One operating organisation across multiple Sites; Site access, exact action "
    "permissions, ownership, consent and privacy are the boundaries."
)


def read_json(relative: str):
    return json.loads((AUDIT_DIR / relative).read_text(encoding="utf-8"))


def read_csv(relative: str) -> list[dict[str, str]]:
    with (AUDIT_DIR / relative).open(encoding="utf-8-sig", newline="") as handle:
        return list(csv.DictReader(handle))


def write_text(relative: str, text: str) -> None:
    path = OUTPUT_DIR / relative
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(text.rstrip() + "\n", encoding="utf-8", newline="\n")


def write_json(relative: str, value) -> None:
    write_text(relative, json.dumps(value, indent=2, ensure_ascii=False))


def sha256(relative: str) -> str:
    return hashlib.sha256((AUDIT_DIR / relative).read_bytes()).hexdigest()


def sha256_output(relative: str) -> str:
    return hashlib.sha256((OUTPUT_DIR / relative).read_bytes()).hexdigest()


def md(value) -> str:
    if value is None or value == "":
        return "NOT_ESTABLISHED_CURRENT_AUDIT"
    return str(value).replace("|", "\\|").replace("\r", " ").replace("\n", "<br>")


matrix = read_csv("03-feature-to-benchmark-matrix.csv")
scorecard = read_csv("04-workflow-usability-scorecard.csv")
visual_rows = read_csv("05-browser-visual-coverage-matrix.csv")
benchmark_rows = read_csv("06-open-source-benchmark-register.csv")
wave1 = read_json("evidence/source/current-feature-discovery-wave-01.json")
wave2 = read_json("evidence/source/current-feature-discovery-wave-02.json")
usability = read_json("evidence/source/current-usability-task-script-materialization-wave-01.json")
visual_evidence = read_json("evidence/source/current-visual-matrix-materialization-wave-01.json")
visual_static = read_json("evidence/source/current-visual-static-census-wave-01.json")
unknown_build = read_json("evidence/browser/current-deployed-selected-feature-observation-wave-03.json")
auth_block = read_json("evidence/browser/root-run-072-authentication-blocked-frontline-slice-wave-04.json")
project_denominator = read_json("evidence/benchmark/current-prompt-project-denominator-reconciliation.json")
formal_upstream = read_json("evidence/benchmark/current-formal-upstream-triage-wave-03.json")
artifact_contract = read_json("evidence/source/raw-run-073a-required-artifact-contract-wave-05.json")
journey_evidence = read_json("evidence/source/raw-run-073b-cross-module-journeys-wave-05.json")
journey_review = read_json("evidence/source/raw-run-073d-independent-journey-review-wave-05.json")
architecture_evidence = read_json("evidence/source/root-run-073c-architecture-data-integration-security-wave-05.json")
official_sources = read_json("evidence/official-sources/nz-source-baseline-2026-08-24.json")

assert len(matrix) == 340
assert Counter(row["feature_class"] for row in matrix) == {"H": 300, "D": 40}
assert len(scorecard) == 300
assert len(visual_rows) == 2812
assert len(benchmark_rows) == 98
assert all(row["benchmark_mapping_credit"] == "false" for row in matrix)
assert all(row["browser_status"] == "Blocked" for row in visual_rows)
assert usability["counts"]["validated_task_scripts"] == 0
assert project_denominator["unique_repositories"] == 95
assert project_denominator["listed_url_occurrences"] == 98
assert artifact_contract["run_id"] == "RUN-073A"
assert len(artifact_contract["completion_gates"]) == 26
assert journey_evidence["counts"]["journeys"] == 8
assert journey_evidence["counts"]["handoffs"] == 44
assert sum(journey_evidence["counts"][key] for key in ("PROVEN", "PARTIAL", "NOT_ESTABLISHED")) == 44
assert journey_review["run_id"] == "RUN-073D"
assert journey_review["input"]["sha256"] == sha256("evidence/source/raw-run-073b-cross-module-journeys-wave-05.json")
assert journey_review["validated_totals"]["fresh_independent_source_reviews"] == 8
assert journey_review["validated_totals"]["prompt_grade_completed_journeys"] == 0
assert all(journey_review["validated_totals"][key] == journey_evidence["counts"][key] for key in ("journeys", "handoffs", "PROVEN", "PARTIAL", "NOT_ESTABLISHED"))
assert architecture_evidence["run_id"] == "RUN-073C"
assert architecture_evidence["counts"]["entity_families"] == 13
assert architecture_evidence["counts"]["technical_concerns"] == 17
assert architecture_evidence["counts"]["provisional_claims"] == 9
assert architecture_evidence["counts"]["provisional_P1"] == 7
assert architecture_evidence["counts"]["provisional_P2"] == 2
assert architecture_evidence["counts"]["final_findings"] == 0
assert architecture_evidence["counts"]["runtime_confirmed_findings"] == 0
assert architecture_evidence["counts"]["not_established_items"] == 10
assert len(architecture_evidence["entity_families"]) == 13
assert len(architecture_evidence["technical_concerns"]) == 17
assert len(architecture_evidence["provisional_claims"]) == 9
assert len(architecture_evidence["not_established"]) == 10
assert Counter(claim["severity"] for claim in architecture_evidence["provisional_claims"]) == {"P1": 7, "P2": 2}
assert len(official_sources["sources"]) == 6
assert architecture_evidence["official_source_boundary"]["sha256"] == sha256("evidence/official-sources/nz-source-baseline-2026-08-24.json")

matrix_by_id = {row["feature_id"]: row for row in matrix}
score_by_id = {row["feature_id"]: row for row in scorecard}
raw_findings = wave1["provisional_findings"] + wave2["provisional_findings"]
assert len(raw_findings) == 12


FINDING_CONTRACTS = {
    "MED-RBAC-01": {
        "feature_id": "CAP-MED-CD-REGISTER-BALANCE",
        "related": ["CAP-MED-DESTRUCTION-LIFECYCLE", "CAP-MED-STOCK-MOVEMENT"],
        "passes": ["P1", "P5", "P6", "P7"],
        "impact": "Possible controlled-medication action-authority bypass, with resident safety and governed-register integrity consequences.",
        "root": "The provisional source review found broad order-management routing adjacent to narrower controlled-action capabilities; exact enforcement remains unverified.",
        "required_gate": "Independently trace every controlled-drug, destruction and stock command to its exact capability, then execute allowed and denied role/Site/witness cases.",
        "interim": "Do not treat the broad orders permission as evidence that controlled actions are authorised; require manual role review before any production remediation decision.",
    },
    "MED-CD-SCOPE-01": {
        "feature_id": "CAP-MED-DESTRUCTION-LIFECYCLE",
        "related": ["CAP-MED-CD-REGISTER-BALANCE", "CAP-MED-STOCK-MOVEMENT"],
        "passes": ["P1", "P2", "P5", "P6", "P7"],
        "impact": "Possible foreign-Site or mismatched Client/medication/witness disclosure or mutation, affecting privacy and medication safety.",
        "root": "The provisional source review found independently supplied identifiers whose canonical relationship checks were not established at every boundary.",
        "required_gate": "Prove owner-first canonical scope reconciliation and concealed foreign-Site/direct-ID denial for Client, medication, Site and witness combinations.",
        "interim": "Treat controlled-drug and destruction requests with mixed identifiers as high-risk until the canonical scope path is independently proven.",
    },
    "MED-CD-ATOMICITY-01": {
        "feature_id": "CAP-MED-CD-REGISTER-BALANCE",
        "related": ["CAP-MED-DESTRUCTION-LIFECYCLE", "CAP-MED-STOCK-MOVEMENT"],
        "passes": ["P1", "P2", "P5", "P7"],
        "impact": "Possible divergence between the controlled-drug register and physical/logical stock under failure or concurrency.",
        "root": "One encompassing owner-first transaction and lock order were not established by the provisional source slice.",
        "required_gate": "Independently prove transaction boundaries, parent-to-child lock order, rollback, retry and concurrent balance assertions.",
        "interim": "Use existing reconciliation controls and investigate any register/stock mismatch; this audit does not assert that a mismatch has occurred.",
    },
    "GOV-EXECUTIVE-VISIBILITY-01": {
        "feature_id": "CAP-GOV-MEETING-AGENDA-MINUTES-ATTENDANCE",
        "related": ["CAP-GOV-RESOLUTION-VOTE-QUORUM"],
        "passes": ["P1", "P4", "P5", "P6", "P7"],
        "impact": "Possible disclosure of executive-session or committee material beyond its intended audience.",
        "root": "Broad governance viewing was visible in the source slice, while executive-session and committee projection filtering was not established.",
        "required_gate": "Independently review policies and run negative direct-ID, calendar, committee, executive-session, picker and attachment tests.",
        "interim": "Do not use the provisional claim as proof of disclosure; restrict operational review of sensitive meetings to explicitly authorised audiences pending verification.",
    },
    "GOV-BOARD-PACK-VISIBILITY-01": {
        "feature_id": "CAP-GOV-BOARD-PACK-DISTRIBUTION",
        "related": ["CAP-GOV-MEETING-AGENDA-MINUTES-ATTENDANCE"],
        "passes": ["P1", "P4", "P5", "P6", "P7"],
        "impact": "Possible disclosure of confidential board-pack metadata or attachments to non-recipients.",
        "root": "The provisional slice did not establish that every list, manifest, attachment and read-tracking path applies the recipient boundary.",
        "required_gate": "Independently review policy and execute recipient/non-recipient tests for ordinary, executive and supplementary pack material.",
        "interim": "Keep board-pack distribution explicitly recipient-controlled and avoid assuming broad governance visibility is equivalent to receipt.",
    },
    "GOV-RESOLUTION-QUORUM-01": {
        "feature_id": "CAP-GOV-RESOLUTION-VOTE-QUORUM",
        "related": [],
        "passes": ["P1", "P2", "P5", "P7"],
        "impact": "Possible incorrect quorum, eligibility or close outcome under changing participation/conflict state or concurrency.",
        "root": "The source slice did not establish one immutable, locked eligibility and conflict snapshot shared by vote, quorum and close.",
        "required_gate": "Independently review the decision model and execute sequential/concurrent eligibility, conflict, quorum, vote-close, replay and immutable-evidence tests.",
        "interim": "Require governance owners to verify quorum and conflicts against the authoritative meeting record until the runtime contract is proven.",
    },
    "HS-REGISTER-SITE-SCOPE-01": {
        "feature_id": "CAP-HS-FIRST-AID-REGISTER",
        "related": ["CAP-HS-WORKER-PARTICIPATION", "CAP-HS-PPE-REGISTER", "CAP-HS-HAZARDOUS-SUBSTANCES-SDS", "CAP-HS-EMERGENCY-DRILLS"],
        "passes": ["P1", "P2", "P5", "P6", "P7"],
        "impact": "Possible cross-Site disclosure or mutation of safety records within the same organisation.",
        "root": "Optional Site filtering appeared in the sampled controllers; universal approved-Site scoping was not established.",
        "required_gate": "Independently review each controller and execute foreign-Site list, picker, direct-ID, export and write denials alongside explicit global-role positives.",
        "interim": "Treat Site selection as a filter, not an authorisation grant; operational reviewers should verify Site access separately from action authority.",
    },
    "PRIV-REPORT-DOMAIN-RBAC-01": {
        "feature_id": "CAP-PRIV-COMPLIANCE-REPORT-EXPORT",
        "related": ["CAP-PRIV-BREACH-LIFECYCLE", "CAP-PRIV-RETENTION-POLICY-LIFECYCLE", "CAP-PRIV-LEGAL-HOLD", "CAP-PRIV-PIA-LIFECYCLE"],
        "passes": ["P1", "P4", "P5", "P6", "P7"],
        "impact": "Possible over-broad access to sensitive privacy aggregates or exports.",
        "root": "The provisional report slice appeared to reuse request-view authority across distinct privacy domains.",
        "required_gate": "Independently trace fields and execute a per-report and per-export capability-denial matrix with Site/privacy fixtures.",
        "interim": "Do not infer export authority from dashboard or request visibility; review sensitive exports under the exact domain capability.",
    },
    "SAFE-INTAKE-CANONICAL-SCOPE-01": {
        "feature_id": "CAP-SAFE-CONCERN-INTAKE-TRIAGE",
        "related": ["CAP-SAFE-TERMINAL-PROJECTION"],
        "passes": ["P1", "P2", "P5", "P6", "P7"],
        "impact": "Possible incorrect cross-Site/person/incident linkage, privacy exposure or misdirected safeguarding response.",
        "root": "Canonical reconciliation of submitted Site, person and incident identifiers was not established before downstream projection.",
        "required_gate": "Confirm reporter product policy and execute adversarial foreign-Site, person, incident, update and projection tests.",
        "interim": "Operationally verify the Site/person/incident chain for sensitive intake records pending independent proof.",
    },
    "SAFE-ALERT-DEDUP-IDENTITY-01": {
        "feature_id": "CAP-CR-ALERT-WORKLIST-LIFECYCLE",
        "related": ["CAP-SAFE-CONCERN-INTAKE-TRIAGE", "CAP-SAFE-TERMINAL-PROJECTION"],
        "passes": ["P2", "P5", "P6", "P7"],
        "impact": "Possible collapse of distinct safeguarding concerns into one Control Room alert, delaying response or obscuring custody.",
        "root": "The provisional source slice did not establish concern identity as part of the generic deduplication identity.",
        "required_gate": "Create distinct same-client and personless concerns within the deduplication window and prove distinct concern-owned alert custody chains.",
        "interim": "When reconciling alerts, verify source concern identity rather than relying on generic client/time similarity alone.",
    },
    "SAFE-PROJECTION-DURABILITY-01": {
        "feature_id": "CAP-SAFE-TERMINAL-PROJECTION",
        "related": ["CAP-CR-ALERT-WORKLIST-LIFECYCLE", "CAP-HS-INCIDENT-HANDOVER-ACCEPTANCE"],
        "passes": ["P2", "P5", "P7"],
        "impact": "Possible silent loss or indefinite delay of safety projections after a committed safeguarding intake.",
        "root": "The inspected observer caught post-commit projection failure, while the sampled recovery owner did not visibly include safeguarding sources.",
        "required_gate": "Inject H&S and Control Room projection failures and prove durable retry/reconciliation ownership, alert identity and idempotent recovery.",
        "interim": "Reconcile committed safeguarding concerns against H&S and Control Room projections until durable recovery is proven.",
    },
    "SET-API-WEBHOOK-DESTINATION-01": {
        "feature_id": "CAP-INT-OUTBOUND-WEBHOOK-CONNECTION",
        "related": ["CAP-INT-API-KEY-ADMIN", "CAP-HR-WEBHOOK-CONFIGURATION"],
        "passes": ["P1", "P5", "P6", "P7"],
        "impact": "Possible server-side requests to internal, reserved or metadata destinations, affecting security and availability.",
        "root": "The administrator-supplied test destination was not visibly bound to the repository-native destination and redirect policy in the sampled path.",
        "required_gate": "Run an authorised security review covering loopback, private, reserved, metadata, redirect, DNS-rebinding and egress-control cases.",
        "interim": "Administrators should use only approved public webhook destinations pending independent destination-policy verification.",
    },
}

assert set(FINDING_CONTRACTS) == {row["finding_id"] for row in raw_findings}


def status_for_anchor(value: str) -> str:
    return "NOT_ESTABLISHED" if value.startswith("NOT_ESTABLISHED") else "SOURCE_LOCATOR_ONLY"


findings = []
for raw in raw_findings:
    contract = FINDING_CONTRACTS[raw["finding_id"]]
    feature = matrix_by_id[contract["feature_id"]]
    score = score_by_id.get(contract["feature_id"])
    required_gate = raw.get("required_gate", contract["required_gate"])
    finding = {
        "id": raw["finding_id"],
        "record_status": "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING",
        "feature_id": contract["feature_id"],
        "related_feature_ids": contract["related"],
        "passes": contract["passes"],
        "module_submodule": {
            "module": feature["module"],
            "submodule": feature["submodule"],
            "submodule_status": "CANONICAL_SUBMODULE_DENOMINATOR_NOT_ESTABLISHED",
        },
        "actor_and_job": {
            "actor": feature["owning_actor"],
            "secondary_actors": feature["secondary_actors"],
            "job": feature["user_job"],
            "status": "SOURCE_BOUND_NOT_REPRESENTATIVE_USER_VALIDATED",
        },
        "route_url": {
            "route_names": feature["route_names"],
            "route_paths": None if feature["route_paths"].startswith("NOT_ESTABLISHED") else feature["route_paths"],
            "raw_route_path_field": feature["route_paths"],
            "status": status_for_anchor(feature["route_paths"]),
        },
        "frontend_anchor": {
            "page_files": None if feature["page_files"].startswith("NOT_ESTABLISHED") else feature["page_files"],
            "raw_page_file_field": feature["page_files"],
            "application_commit": APPLICATION_COMMIT,
            "status": status_for_anchor(feature["page_files"]),
        },
        "visual_context": {
            "visual_id": None,
            "role": None,
            "site_scope": None,
            "viewport": None,
            "ui_state": None,
            "pattern_type": None,
            "screenshot_reference": None,
            "status": "BLOCKED_NOT_OBSERVED_OR_LINKED_CURRENT_AUDIT",
        },
        "pattern_implementation": {
            "shared_component_or_variant": None,
            "overlay_trigger": None,
            "internal_baseline": None,
            "status": "NOT_ADJUDICATED_FOR_THIS_PROVISIONAL_CLAIM",
        },
        "backend_anchor": {
            "matrix_anchors": feature["backend_anchors"],
            "claim_anchors": raw["anchors"],
            "controller_request_service_model_policy_job_event_migration": "PARTIAL_SOURCE_SLICE_ONLY",
            "application_commit": APPLICATION_COMMIT,
        },
        "current_behaviour": {
            "classification": "SOURCE_INFERRED_PROVISIONAL",
            "summary": raw["source_claim"],
            "runtime_observed": False,
        },
        "current_workflow": {
            "matrix_summary": feature["current_workflow_summary"],
            "entry_prerequisites_steps_decisions_states_failure_recovery_handoff_completion": "NOT_FULLY_RECONSTRUCTED_OR_EXECUTED_CURRENT_AUDIT",
        },
        "ease_evidence": {
            "status": "NOT_MEASURED",
            "ten_dimensions": None,
            "completion_time": None,
            "step_count": None,
            "field_count": None,
            "decision_count": None,
            "context_switches": None,
            "uncertainty_points": None,
            "task_script": score["task_script_path"] if score else None,
        },
        "evidence": {
            "source_claim_record": (
                "evidence/source/current-feature-discovery-wave-01.json"
                if raw in wave1["provisional_findings"]
                else "evidence/source/current-feature-discovery-wave-02.json"
            ),
            "claim_anchors": raw["anchors"],
            "tests_executed": 0,
            "browser_cells": 0,
            "database_checks": 0,
        },
        "problem_root_cause": contract["root"],
        "impact": contract["impact"],
        "benchmark": {
            "official_repository": None,
            "inspected_ref": None,
            "verified_behaviour": None,
            "no_credible_match_evidence": None,
            "status": "NOT_MAPPED_AND_NO_FINAL_NO_MATCH_CURRENT_AUDIT",
        },
        "benchmark_outcome": "NOT_ADJUDICATED_CURRENT_AUDIT",
        "neutral_requirements": [
            "Preserve the legitimate user job while enforcing the canonical owner and exact action authority.",
            "Keep Site access, direct-object privacy and consent checks explicit; broader Site scope never replaces action authority.",
            "Make success, failure, audit provenance and recovery observable without copying third-party wording, assets, source or layout.",
        ],
        "better_oblivion_design": {
            "proposal": None,
            "status": "NOT_DESIGNED_UNTIL_NEUTRAL_REQUIREMENTS_BENCHMARK_AND_CURRENT_FINDING_ARE_FINAL",
        },
        "target_ease": {
            "scores": None,
            "measurable_reduction": None,
            "status": "NOT_MEASURED",
        },
        "cross_module_effects": contract["related"],
        "rbac_privacy": ARCHITECTURE_RULE,
        "priority": raw["provisional_severity"],
        "priority_status": "PROVISIONAL_NOT_FINAL_PRIORITY_COUNT",
        "effort": {"size": None, "assumptions": None, "status": "NOT_ESTIMATED"},
        "dependencies_sequence": [
            "Independent source review at the audited application pin",
            required_gate,
            "Only then decide whether a native remediation contract is warranted",
        ],
        "confidence": {
            "level": "LOW_PENDING_INDEPENDENT_AND_RUNTIME_REVIEW",
            "evidence_gap": required_gate,
        },
        "source_boundary": "Native Oblivion analysis only; no third-party source, assets, wording or distinctive layout may be copied.",
        "interim_safeguard": contract["interim"],
        "acceptance_criteria": {
            "given_when_then": None,
            "status": "NOT_FINAL; REQUIRED GATE: " + required_gate,
        },
        "validation_plan": {
            "required": required_gate,
            "unit": "NOT_PLANNED_TO_EXACT_CASES",
            "feature": "NOT_EXECUTED",
            "architecture": "NOT_EXECUTED",
            "e2e": "BLOCKED",
            "visual_accessibility": "BLOCKED",
            "performance_concurrency": "NOT_EXECUTED",
            "representative_user": "NOT_EXECUTED",
        },
        "finalization_blockers": [
            "Independent current-source review",
            "Exact final feature/finding ownership confirmation",
            "Safe attributable runtime and/or browser validation where applicable",
            "Target-specific benchmark or exhaustive final no-match adjudication",
            "Complete observable acceptance criteria and validation plan",
        ],
        "independent_review": {
            "status": "NOT_COMPLETED",
            "reviewer": None,
            "disagreements": None,
            "reconciliation": None,
        },
        "unresolved_fields": [
            "final exact route/page/action ownership where a sentinel remains",
            "VISUAL-ID, role, Site, viewport, state, pattern baseline and screenshot",
            "observed workflow and ten-dimension current/target ease measures",
            "target-specific benchmark or exhaustive final no-match",
            "native design, effort, final acceptance criteria and executable validation cases",
        ],
        "completion_credit": False,
        "credit": {
            "final_finding": False,
            "p0_p1_schema_gate": False,
            "benchmark": False,
            "browser": False,
            "ease": False,
            "pass": False,
            "completion": False,
        },
    }
    findings.append(finding)

findings_json = {
    "schema_version": "oblivion_audit_findings_v1_provisional",
    "audit_status": "PROVISIONAL_SOURCE_CLAIMS_MATERIALIZED_ZERO_FINAL_FINDING_CREDIT",
    "generated_on": GENERATED_ON,
    "pins": {
        "application_commit": APPLICATION_COMMIT,
        "application_tree": APPLICATION_TREE,
        "audit_checkpoint_parent": AUDIT_CHECKPOINT,
        "governing_prompt_sha256": PROMPT_SHA256,
    },
    "architecture_rule": ARCHITECTURE_RULE,
    "denominators": {
        "canonical_features": 340,
        "human_features": 300,
        "system_data_features": 40,
        "canonical_submodules": None,
        "provisional_source_claim_floor": 12,
    },
    "counts": {
        "provisional_source_claims": len(findings),
        "provisional_P1": sum(row["priority"] == "P1" for row in findings),
        "final_P0": 0,
        "final_P1": 0,
        "complete_prompt_finding_schema": 0,
        "benchmark_mapped": 0,
        "final_no_match": 0,
        "browser_observed": 0,
    },
    "credit_boundary": (
        "Field presence and explicit null/sentinel values make the provisional records reviewable; "
        "they do not satisfy the final finding-quality gate."
    ),
    "reconciliation": {
        "provisional_ids_unique": True,
        "every_primary_feature_id_in_canonical_matrix": True,
        "final_ids_cross_file_reconciled": False,
        "reason": "There are no final finding IDs; provisional links do not satisfy the cross-file final-finding gate.",
    },
    "records": findings,
}
write_json("findings.json", findings_json)


module_groups: dict[str, list[dict[str, str]]] = defaultdict(list)
for row in matrix:
    module_groups[row["module"]].append(row)

finding_ids_by_feature: dict[str, list[str]] = defaultdict(list)
for row in findings:
    finding_ids_by_feature[row["feature_id"]].append(row["id"])
    for related in row["related_feature_ids"]:
        finding_ids_by_feature[related].append(row["id"] + " (related)")

lines = [
    "# 07 — Module and feature findings",
    "",
    "> Status: in progress; source-bound reporting only. This file materializes all 340 frozen canonical static feature identities and 12 provisional source claims. It contains **0 final P0/P1 findings** and awards no Pass, runtime, browser, ease, benchmark or completion credit.",
    "",
    f"Application source pin: `{APPLICATION_COMMIT}` (tree `{APPLICATION_TREE}`).",
    f"Governing prompt SHA-256: `{PROMPT_SHA256}`.",
    "",
    f"Architecture rule: {ARCHITECTURE_RULE}",
    "",
    "## Exact accounting",
    "",
    "| Item | Current evidence | Credit |",
    "|---|---:|---:|",
    "| Frozen canonical feature identities | 340 / 340 (300 H, 40 D) | static identity only |",
    "| Feature rows represented below | 340 / 340 | reporting presence only |",
    "| Provisional source claims | 12 P1-labelled claims | 0 final P1 |",
    "| Verified benchmark or final no-match | 0 / 340 | 0 |",
    "| Validated H task/ease rows | 0 / 300 | 0 |",
    "| Modules with all P1–P8 complete | 0 / 29 frozen feature modules | 0 |",
    "",
    "File presence is not audit completion. A feature row with no linked provisional claim means only that no claim has yet been materialized for it; it is not evidence of no defect.",
    "",
    "## Module summary",
    "",
    "| Module | H | D | Total | Provisional claim links | Final findings |",
    "|---|---:|---:|---:|---:|---:|",
]
for module in sorted(module_groups):
    rows = module_groups[module]
    linked = sorted({item for row in rows for item in finding_ids_by_feature.get(row["feature_id"], [])})
    lines.append(
        f"| {md(module)} | {sum(r['feature_class'] == 'H' for r in rows)} | "
        f"{sum(r['feature_class'] == 'D' for r in rows)} | {len(rows)} | {md('; '.join(linked) or 'none materialized')} | 0 |"
    )

lines += ["", "## Provisional source claims", ""]
for finding in findings:
    lines += [
        f"### {finding['id']} — {finding['feature_id']}",
        "",
        f"- Status: `{finding['record_status']}`; priority label `{finding['priority']}` is provisional.",
        f"- Pass lenses: {', '.join(finding['passes'])}; none is complete from this claim.",
        f"- Module/submodule: {finding['module_submodule']['module']} / {finding['module_submodule']['submodule']}.",
        f"- Actor/job: {finding['actor_and_job']['actor']} — {finding['actor_and_job']['job']}.",
        f"- Route loci: {finding['route_url']['raw_route_path_field']}.",
        f"- Frontend loci: {finding['frontend_anchor']['raw_page_file_field']}.",
        f"- Backend claim anchors: {'; '.join(finding['backend_anchor']['claim_anchors'])}.",
        f"- Source-inferred current behaviour: {finding['current_behaviour']['summary']}",
        f"- Root-cause hypothesis: {finding['problem_root_cause']}",
        f"- Potential impact: {finding['impact']}",
        f"- Interim evidence safeguard: {finding['interim_safeguard']}",
        f"- Required finalization gate: {finding['validation_plan']['required']}",
        "- Browser/visual/ease: blocked or not measured; no screenshot, task, 4/5 score or finding credit.",
        "- Benchmark/no-copy: no target-specific mapping or final no-match; any eventual proposal must be an original native Oblivion design.",
        "",
    ]

lines += [
    "## Feature-by-feature evidence register",
    "",
    "Each row is a frozen static identity, not a completed feature audit. Exact source locators are preserved; `NOT_ESTABLISHED_CURRENT_AUDIT` is not a numeric zero and is not evidence of absence.",
    "",
]
for module in sorted(module_groups):
    lines += [
        f"### {module}",
        "",
        "| Feature ID | Class | Actor/job | Route/page loci | Backend/test loci | P1–P8 status | Task/ease | Benchmark | Finding links |",
        "|---|---|---|---|---|---|---|---|---|",
    ]
    for row in sorted(module_groups[module], key=lambda item: item["feature_id"]):
        score = score_by_id.get(row["feature_id"])
        task = (
            f"contract present; validation={score['validation_status']}; scores={score['score_measurement_status']}"
            if score
            else "D/system capability; no H task contract denominator"
        )
        pass_status = "; ".join(f"{p}={row[p]}" for p in ("P1", "P2", "P3", "P4", "P5", "P6", "P7", "P8"))
        route_page = f"routes: {row['route_paths']}<br>pages: {row['page_files']}"
        backend_test = f"backend: {row['backend_anchors']}<br>tests: {row['test_anchors']}"
        actor_job = f"{row['owning_actor']}<br>{row['user_job']}"
        linked = "; ".join(sorted(set(finding_ids_by_feature.get(row["feature_id"], [])))) or "none materialized"
        lines.append(
            f"| `{md(row['feature_id'])}` | {md(row['feature_class'])} | {md(actor_job)} | {md(route_page)} | "
            f"{md(backend_test)} | {md(pass_status)} | {md(task)} | 0 credit; {md(row['selected_open_source_benchmark'])} | {md(linked)} |"
        )
    lines.append("")

lines += [
    "## Finalization contract",
    "",
    "A claim can become final only after independent source review, exact canonical ownership, full prompt finding fields, task/browser/runtime evidence where applicable, target-specific benchmark or exhaustive no-match adjudication, observable acceptance criteria, and an executable validation plan. Until then the final P0/P1 count remains zero.",
]
write_text("07-module-findings.md", "\n".join(lines))


journey_lines = [
    "# 08 — Cross-module journeys",
    "",
    "> Status: all eight prompt-named journeys now have a pinned **source-level reconstruction**. This is not prompt-grade journey completion: independent resampling, runtime/browser execution, all four viewports, material UI states, representative roles/Sites and task/ease evidence remain absent.",
    "",
    f"Application source pin: `{APPLICATION_COMMIT}` (tree `{APPLICATION_TREE}`).",
    f"Governing prompt SHA-256: `{PROMPT_SHA256}`.",
    "",
    f"Architecture rule: {ARCHITECTURE_RULE}",
    "",
    "## Exact accounting",
    "",
    "| Measure | Current result | Credit |",
    "|---|---:|---:|",
    "| Prompt-named journeys represented | 8 / 8 | source reporting only |",
    "| Ordered handoffs classified | 44 / 44 | source classification only |",
    f"| PROVEN source handoffs | {journey_evidence['counts']['PROVEN']} | no runtime inheritance |",
    f"| PARTIAL source handoffs | {journey_evidence['counts']['PARTIAL']} | no completion credit |",
    f"| NOT_ESTABLISHED source handoffs | {journey_evidence['counts']['NOT_ESTABLISHED']} | explicit gap, not product-wide absence |",
    "| Fresh independent source reviews | 8 / 8 | source-semantic review only |",
    "| Fresh independent prompt-grade journey reviews | 0 / 8 | 0 |",
    "| Runtime/browser journey executions | 0 / 8 | 0 |",
    "| Four-viewport journey sets | 0 / 8 | 0 |",
    "| Validated ten-dimension journey ease sets | 0 / 8 | 0 |",
    "| Final journey findings | 0 | 0 |",
    "",
    "A fresh independent reviewer reopened all eight source reconstructions and validated 155/155 selected handoff anchors with 27 PROVEN, 8 PARTIAL and 9 NOT_ESTABLISHED classifications. This closes only the source-semantic review cell. Gate 7 remains 0/8 because prompt-grade runtime/browser execution, representative roles/Sites, all four viewports, material states and ease evidence remain absent.",
    "",
    "## Journey summary",
    "",
    "| Journey | Features | PROVEN | PARTIAL | NOT ESTABLISHED | Provisional candidates | Prompt-grade status |",
    "|---|---:|---:|---:|---:|---:|---|",
]
for journey in journey_evidence["journeys"]:
    candidate_count = len(journey["provisional_finding_candidates"])
    journey_lines.append(
        f"| `{journey['journey_id']}` {md(journey['name'])} | {len(journey['feature_ids'])} | "
        f"{journey['handoff_counts']['PROVEN']} | {journey['handoff_counts']['PARTIAL']} | "
        f"{journey['handoff_counts']['NOT_ESTABLISHED']} | {candidate_count} | source reconstructed and independently source-reviewed; prompt-grade execution open |"
    )

for journey in journey_evidence["journeys"]:
    journey_lines += [
        "",
        f"## {journey['journey_id']} — {journey['name']}",
        "",
        f"Actors/jobs: {', '.join(journey['actors_jobs'])}.",
        "",
        "Canonical feature identities: " + ", ".join(f"`{item}`" for item in journey["feature_ids"]) + ".",
        "",
    ]
    if journey.get("identity_coverage_note"):
        journey_lines += [f"Identity gap: {journey['identity_coverage_note']}", ""]
    script_links = []
    for feature_id in journey["feature_ids"]:
        score = score_by_id.get(feature_id)
        if score:
            script_links.append(f"`{score['task_script_path']}`")
        else:
            script_links.append(f"`{feature_id}` has no H task contract row")
    journey_lines += [
        "Task-contract loci: " + "; ".join(script_links) + ". Every contract remains unexecuted with all current/target dimensions `NOT_MEASURED`.",
        "",
        "| Handoff | From → to | Classification | Canonical owner | Exact source anchors | Site/role/privacy boundary | Proof or gap |",
        "|---|---|---|---|---|---|---|",
    ]
    for handoff in journey["ordered_handoffs"]:
        journey_lines.append(
            f"| `{handoff['id']}` | {md(handoff['from'])} → {md(handoff['to'])} | `{handoff['classification']}` | "
            f"{md(handoff['canonical_owner'])} | {md('; '.join(handoff['evidence']))} | "
            f"{md(handoff['site_role_privacy'])} | {md(handoff['proof_or_gap'])} |"
        )
    journey_lines += [
        "",
        "### Provisional source candidates",
        "",
    ]
    for candidate in journey["provisional_finding_candidates"]:
        journey_lines.append(
            f"- `{candidate['candidate_id']}` — provisional `{candidate['severity']}`: {candidate['title']} "
            f"(features: {', '.join(candidate['feature_ids'])}; status `{candidate['status']}`)."
        )
    journey_lines += [
        "",
        "These are **not final findings** and are not added to the 12-record provisional finding floor without a separate independent finding-quality adjudication.",
        "",
        f"Browser/task blocker: {journey['browser_task_script_coverage_blocker']}",
        "",
        f"Completion test: {journey['completion_test']}",
        "",
        "Still-open path classes: happy-path runtime confirmation; validation/error/retry; reject/return/correction; emergency/override; reopen/recovery; downtime; notifications/reports; all four viewports; representative role/Site and direct-ID denials.",
    ]

journey_lines += [
    "",
    "## Cross-journey ownership and duplicate review",
    "",
    "| Review ID | Journeys | Collision | Adjudication |",
    "|---|---|---|---|",
]
for row in journey_evidence["cross_review"]["collisions_and_duplicates"]:
    journey_lines.append(
        f"| `{row['id']}` | {md(', '.join(row['journeys']))} | {md(row['collision'])} | {md(row['adjudication'])} |"
    )
journey_lines += [
    "",
    "No adjacent semantics are inherited. Shared Client, Site, Asset, Device, shift, incident, timesheet or finance context does not make parallel records the same canonical owner.",
    "",
    "## Browser and independent-review closure matrix",
    "",
    "| Required dimension | Current value | Status |",
    "|---|---:|---|",
    "| Fresh independent source review per journey | 8 / 8 | source-semantic GO; no execution credit |",
    "| 1440×900 | 0 / 8 | blocked |",
    "| 1280×800 | 0 / 8 | blocked |",
    "| 1024×768 | 0 / 8 | blocked |",
    "| 390×844 | 0 / 8 | blocked |",
    "| Representative roles and approved Sites | 0 / 8 | blocked |",
    "| Material states and redacted screenshots | 0 / 8 | blocked |",
    "| Current and target ten-dimension ease scores | 0 / 8 | not measured |",
    "",
    "Required input: an attributable current-source build, safe environment classification, authenticated representative roles, approved Site and synthetic/non-sensitive fixtures. A user may manually sign in; this audit will not invent credentials or bypass authentication.",
    "",
    "## Credit boundary",
    "",
    "The reconstruction grants no benchmark, NCM, final-finding, runtime, browser, test-execution, ease, Pass or audit-completion credit. Test files above are source locators only and were not executed.",
]
write_text("08-cross-module-journeys.md", "\n".join(journey_lines))


pattern_counts = Counter(row["pattern_type"] for row in visual_rows)
page_owner_counts = Counter(row["page_owner_status"] for row in visual_rows)
candidate_link_counts = Counter(row["candidate_link_status"] for row in visual_rows)
baseline_counts = Counter(row["internal_baseline"] for row in visual_rows)
route_owner_counts = Counter(row["route_owner_status"] for row in visual_rows)
type_counts = Counter(row["row_type"] for row in visual_rows)
unknown_counts = unknown_build["counts"]

ui_lines = [
    "# 09 — UI, UX, accessibility and visual consistency",
    "",
    "> Status: in progress. The complete static visual census is materialized, but **0 current-source rendered application instances** are credited. Every browser claim below is explicitly labelled.",
    "",
    f"Application source pin: `{APPLICATION_COMMIT}` (tree `{APPLICATION_TREE}`).",
    f"Governing prompt SHA-256: `{PROMPT_SHA256}`.",
    "",
    "## Evidence boundary",
    "",
    "- `Source-inferred`: committed TSX/component/trigger locators only.",
    "- `Observed`: none attributable to the current application source pin.",
    "- `Blocked`: all 2,812 static visual rows lack the required attributable role × Site × viewport × state browser observation.",
    "- `Not safely reproducible`: the signed-in six-route sample is preserved only as an unknown-build observation and carries zero current-source credit.",
    "",
    "## Hero and overlay census",
    "",
    "| Pattern | Rows | Browser status | Finding links |",
    "|---|---:|---|---|",
]
for label in ("HERO_BANNER", "OVERLAY", "OVERLAY_TRIGGER"):
    ui_lines.append(f"| {label} | {pattern_counts[label]:,} | Blocked | 0 / {pattern_counts[label]:,} |")
ui_lines += [
    f"| **Total** | **{len(visual_rows):,}** | **2,812 Blocked** | **0 / 2,812** |",
    "",
    "Static subtypes: " + ", ".join(f"`{key}` {value:,}" for key, value in sorted(type_counts.items())) + ".",
    "",
    "| Static definition/trigger family | Count | Partition |",
    "|---|---:|---|",
    f"| Hero definitions | {visual_static['heroes']['definitions']:,} | {md('; '.join(f'{key}={value}' for key, value in visual_static['heroes']['definition_partition'].items()))} |",
    f"| Overlay definitions | {visual_static['overlays']['definitions']:,} | {md('; '.join(f'{key}={value}' for key, value in visual_static['overlays']['definition_partition'].items()))} |",
    f"| Declarative overlay triggers | {visual_static['triggers']['declarative_primitive_tags']:,} | component trigger tags |",
    f"| Direct inline opening handlers | {visual_static['triggers']['direct_inline_opening_handler_sites']:,} | positive opening locators |",
    f"| Named-handler references | {visual_static['triggers']['local_named_handler_reference_sites']:,} | named positive opening locators |",
    f"| Gate 9 current static accounting | {visual_static['overlays']['definitions'] + visual_static['triggers']['declarative_primitive_tags'] + visual_static['triggers']['direct_inline_opening_handler_sites'] + visual_static['triggers']['local_named_handler_reference_sites']:,} | 473 definitions + 942 trigger sites; rendered verification remains 0 |",
    "",
    "Overlay material-state locators: " + ", ".join(
        f"`{key}` {value:,}" for key, value in visual_static["overlays"]["material_state_partition"].items()
    ) + ". These are source states, not role/Site/viewport browser observations.",
    "",
    "## Ownership and linkage partitions",
    "",
    "| Partition | Count | Interpretation |",
    "|---|---:|---|",
]
for key, value in page_owner_counts.most_common():
    ui_lines.append(f"| page owner — `{key}` | {value:,} | static render-root relation only |")
for key, value in route_owner_counts.most_common():
    ui_lines.append(f"| route owner — `{key}` | {value:,} | static route-owner relation only |")
for key, value in candidate_link_counts.most_common():
    ui_lines.append(f"| candidate feature link — `{key}` | {value:,} | not final FEATURE-ID ownership |")
ui_lines += [
    "",
    "## Internal baseline census",
    "",
    "| Internal baseline locator | Instances | Status |",
    "|---|---:|---|",
]
for key, value in baseline_counts.most_common():
    ui_lines.append(f"| `{md(key)}` | {value:,} | source-inferred only |")
ui_lines += [
    "",
    "The baseline census is not a rendered side-by-side comparison. In particular, `NOT_ESTABLISHED` means that this audit has not linked an internal gold standard; it does not prove inconsistency.",
    "",
    "## Other required UI pattern families",
    "",
    "| Pattern family | Current denominator | Current-source browser observation | Required closure |",
    "|---|---:|---:|---|",
    "| Primary/secondary navigation and mobile navigation | unknown | 0 | freeze implementations/destinations, roles, Sites, active/focus states and four-viewport behaviour |",
    "| Page containers, breadcrumbs and tabs | unknown | 0 | classify hierarchy, duplicate destinations, overflow, keyboard order and canonical owner |",
    "| Filters, searches, pickers and pagination | unknown | 0 | classify empty/loading/error/result states, Site/privacy scope and recovery |",
    "| Cards, tables and mobile-card alternatives | unknown | 0 | classify responsive transforms, density, sorting, status semantics and horizontal overflow |",
    "| Forms and validation | unknown | 0 | classify required fields, inline/summary errors, destructive confirmation, cancel and retry |",
    "| Empty, loading, error, success and stale-data states | unknown | 0 | bind every material state to a route, role, Site, viewport and VISUAL-ID |",
    "| Status badges, timelines, notifications and toasts | unknown | 0 | prove vocabulary, provenance, acknowledgement, undo/recovery and access-filtered projections |",
    "",
    "These unknown denominators are explicit evidence gaps. The hero/overlay census must not be misrepresented as a complete design-system or accessibility audit.",
    "",
    "## Responsive, state and accessibility coverage",
    "",
    "| Required evidence | Current numerator | Denominator | Classification |",
    "|---|---:|---:|---|",
    "| Safely reachable routes at standard desktop width | 0 | unknown | Blocked |",
    "| Selected families and journeys at 1440×900, 1280×800, 1024×768 and 390×844 | 0 | unknown | Blocked |",
    "| Material visual states fully classified | not established | not established | Blocked |",
    "| Material hero/overlay finding families independently resampled | 0 | 2 provisional unknown-build families | Not safely reproducible |",
    "| Current-source WCAG 2.2 AA browser checks | 0 | unknown | Blocked |",
    "| Redacted current-source screenshots | 0 | unknown | Blocked |",
    "| H-feature ten-dimension current ease scores | 0 | 3,000 | Not measured |",
    "| H-feature ten-dimension target ease scores | 0 | 3,000 | Not measured |",
    "",
    "The unknown-build observation records "
    f"{unknown_counts['selected_routes']} routes, {unknown_counts['unknown_build_route_viewport_cells']} route/viewport cells, "
    f"{unknown_counts['unknown_build_overlay_families']} pre-submit overlay families and "
    f"{unknown_counts['unknown_build_provisional_candidates']} provisional candidates. It retained zero screenshots and changed zero records. "
    "Because no authoritative deployed commit/tree or build marker was established, it supplies **no current-source browser, responsive, accessibility, finding or ease credit**.",
    "",
    f"The later RUN-072 check selected {auth_block['counts']['selected_routes']} routes but stopped after `/my-day` redirected both available contexts to `/login`; authenticated cells remain 0 and no credentials or mutations occurred.",
    "",
    "## Provisional pattern risks requiring attributable resampling",
    "",
    "1. Focus restoration was a candidate across four unknown-build overlay families; trigger, initial focus, close mechanism and restored locator were not captured.",
    "2. Escape-key behaviour was a candidate in one unknown-build employee overlay; the current source build and exact overlay owner were not proven.",
    "",
    "Neither item is a current-source finding. The required resample must bind build identity, actor role, approved Site, safe fixture, exact VISUAL-ID/FEATURE-ID, all four viewports, material states, DOM/focus evidence and a redacted screenshot before independent review.",
    "",
    "## Reusable native design-system recommendations",
    "",
    "These are neutral audit recommendations, not application fixes:",
    "",
    "- Declare one auditable hero/banner variant contract around existing Oblivion primitives and link every local exception to a reason; preserve current routes and wording unless a later remediation explicitly changes them.",
    "- Route modal, dialog, drawer, sheet and popover behaviour through existing internal primitives with explicit trigger, focus, Escape, cancel, error, success and recovery contracts.",
    "- Store role/Site/state/viewport evidence by VISUAL-ID so a screenshot can never stand alone without scope and root-cause analysis.",
    "- Treat desktop/mobile layouts as the same workflow owner with responsive variants, not independent feature identities.",
    "- Preserve necessary safety friction; improve clarity and recovery only after representative task evidence is measured.",
    "",
    "## No-copy boundary",
    "",
    "All eventual designs must be original native Oblivion implementations. External projects may contribute neutral user needs and verified behaviour references only; no source, assets, wording or distinctive layout may be copied.",
]
write_text("09-ui-ux-accessibility-visual-consistency.md", "\n".join(ui_lines))


architecture_counts = architecture_evidence["counts"]
census = architecture_evidence["static_census"]
architecture_lines = [
    "# 10 — Architecture, data, integration and security",
    "",
    "> Source-only architecture synthesis at the pinned application commit. This report identifies representative owners and provisional source conditions; it does not establish exhaustive uniqueness, deployed state, runtime impact, a final finding, compliance, safety assurance, Pass credit or audit completion.",
    "",
    f"Architecture constraint: {ARCHITECTURE_RULE}",
    "",
    "## Accounting boundary",
    "",
    "| Ledger | Current source accounting | Credit boundary |",
    "|---|---:|---|",
    f"| Canonical entity families | {architecture_counts['entity_families']} | representative owners/projections accounted for; runtime correctness and exhaustive uniqueness unexecuted |",
    f"| Technical concerns | {architecture_counts['technical_concerns']} | source-classified only |",
    f"| Provisional architecture claims | {architecture_counts['provisional_claims']} ({architecture_counts['provisional_P1']} P1 · {architecture_counts['provisional_P2']} P2) | separate source candidates; not final findings |",
    f"| Explicit `NOT_ESTABLISHED` items | {architecture_counts['not_established_items']} | require deployed/runtime evidence |",
    f"| Final findings | {architecture_counts['final_findings']} | zero |",
    f"| Runtime-confirmed findings | {architecture_counts['runtime_confirmed_findings']} | zero |",
    f"| Unbounded duplicate-owner collisions proven | {architecture_counts['unbounded_duplicate_owner_collisions_proven']} | zero; absence is not proven globally |",
    "",
    f"The normalized evidence serializes {architecture_counts['serialized_anchor_occurrences']} anchor occurrences, representing {architecture_counts['distinct_reviewed_anchor_ranges']} distinct source ranges across {architecture_counts['reviewed_paths']} pinned-tree paths, with {architecture_counts['invalid_reviewed_anchors']} invalid ranges. That validation establishes locator integrity only.",
    "",
    "## Static source census",
    "",
    "| Surface | Count |",
    "|---|---:|",
    f"| Controllers | {census['controllers']} |",
    f"| Service entries | {census['service_entries']} |",
    f"| Models | {census['models']} |",
    f"| Policies | {census['policies']} |",
    f"| Jobs | {census['jobs']} |",
    f"| Events | {census['events']} |",
    f"| Listeners | {census['listeners']} |",
    f"| Observers | {census['observers']} |",
    f"| Migrations | {census['migrations']} |",
    f"| PHP test files | {census['php_test_files']} |",
    "",
    "The earlier lexical test count is omitted because its counting rule was not reproducible. File counts are source inventory, not executed coverage.",
    "",
    "## Canonical entity and ownership ledger",
    "",
    "| ID | Entity family | Source disposition | Representative pinned anchors |",
    "|---|---|---|---|",
]
for entity in architecture_evidence["entity_families"]:
    anchors = "<br>".join(f"`{anchor}`" for anchor in entity["evidence"])
    architecture_lines.append(
        f"| `{entity['id']}` | {md(entity['family'])} | `{md(entity['disposition'])}` | {anchors} |"
    )
architecture_lines += [
    "",
    "`REPRESENTATIVE_SOURCE_OWNER_IDENTIFIED` and related labels mean that a bounded source owner or projection was located. They do not prove production integrity, exhaustive uniqueness, direct-object concealment, Site-safe access, or the absence of legitimate domain-specific projections.",
    "",
    "## Technical concern ledger",
    "",
    "| Concern | Source disposition | Provisional claim |",
    "|---|---|---|",
]
for concern in architecture_evidence["technical_concerns"]:
    claim_id = f"`{concern['claim_id']}`" if concern["claim_id"] else "none"
    architecture_lines.append(
        f"| `{concern['id']}` | `{md(concern['disposition'])}` | {claim_id} |"
    )
architecture_lines += [
    "",
    "The signal/outbox path has a source-mapped observer, durable outbox and retrying dispatch job; worker operation remains unexecuted. HR webhook sender/receiver surfaces are source-mapped, while configured endpoints, receiver ownership, delivery and retries remain unknown. Audit logging surfaces are identifiable, but runtime completeness is not established.",
    "",
    "## Provisional source-condition register",
    "",
    "> These nine rows are deliberately separate from the 12 discovery claims in `findings.json`. None is a final finding and none inherits runtime, browser, executed-test, benchmark, ease, Pass or completion credit.",
    "",
    "| Candidate | Priority | Disposition | Narrow title |",
    "|---|---:|---|---|",
]
for claim in architecture_evidence["provisional_claims"]:
    architecture_lines.append(
        f"| `{claim['id']}` | {claim['severity']} | `{md(claim['disposition'])}` | {md(claim['title'])} |"
    )
for claim in architecture_evidence["provisional_claims"]:
    anchors = ", ".join(f"`{anchor}`" for anchor in claim["evidence"])
    architecture_lines += [
        "",
        f"### {claim['id']} — {claim['title']}",
        "",
        f"- Priority: **{claim['severity']} provisional source-only**.",
        f"- Disposition: `{claim['disposition']}`.",
        f"- Narrow claim: {claim['claim']}",
        f"- Pinned anchors: {anchors}.",
        f"- Required promotion gate: {claim['required_gate']}",
    ]
architecture_lines += [
    "",
    "## Explicitly not established",
    "",
]
for index, unknown in enumerate(architecture_evidence["not_established"], start=1):
    architecture_lines.append(f"{index}. {unknown}")
architecture_lines += [
    "",
    "## Official New Zealand source boundary",
    "",
    f"The partial official baseline contains {architecture_evidence['official_source_boundary']['sources']} current-source records and is pinned by SHA-256 `{architecture_evidence['official_source_boundary']['sha256']}`. {architecture_evidence['official_source_boundary']['use']}",
    "",
    "It grants **zero compliance, legal, clinical or security assurance**. Blocked source retrievals, product-specific mapping, specialist decisions and operational evidence remain open.",
    "",
    "## Zero-credit conclusion",
    "",
    "Artifact 10 is materialized for source review. Architecture candidates remain provisional; final findings, runtime confirmations, current-build browser evidence, executed tests, benchmark mappings, ease scores, completed Passes and audit completion all remain zero.",
]
write_text("10-architecture-data-integration-security.md", "\n".join(architecture_lines))


roadmap_lines = [
    "# 11 — Prioritised audit-completion roadmap",
    "",
    "> This is an evidence-closure roadmap, not authorisation to remediate the application. Priority labels on the 12 source claims remain provisional until their independent and runtime gates are satisfied.",
    "",
    f"Architecture constraint: {ARCHITECTURE_RULE}",
    "",
    "## Stop-gaps while evidence is incomplete",
    "",
    "- Do not present the audit as comprehensive or complete; keep every static, browser, benchmark, ease and finding credit boundary visible.",
    "- Operationally reconcile safety-critical medication and safeguarding records through existing governed processes; this does not assert that a defect has occurred.",
    "- Review sensitive governance/privacy exports and webhook destinations under their exact action authority; never treat broad Site visibility as action permission.",
    "- Preserve the no-copy boundary and do not implement fixes in this audit run.",
    "",
    "## Dependency waves",
    "",
    "| Wave | Target window | Evidence owner | Scope | Effort | Exit test | Principal risk |",
    "|---|---|---|---|---|---|---|",
    "| A — source denominators and ownership | days 0–30 | audit orchestrator + independent source reviewers | framework route denominator; 711 page-root classification; route/page→FEATURE-ID; 782 models; 75 policies; 735 service entries; critical async subset; canonical module/submodule ledger | XL | Gates 1–4 and 14–18 have exact denominators and independently reconciled rows | collapsing locators into semantic ownership or inheriting family credit |",
    "| B — safe attributable task/browser evidence | days 0–30 in parallel after access | browser/task agents under root control | current-build identity, non-production/read-only safety, representative roles/Sites/fixtures, 300 H scripts, four viewports, material states, screenshots, DOM/a11y and independent 4/5 review | XL | Gates 6–13, 22–24 have exact numerators/denominators; 0 unexplained browser claims | live-data mutation, unknown build attribution, or invented ease scores |",
    "| C — target-specific benchmark closure | days 0–60 | clean A→B→C→D benchmark chains | formally triage 95 unique prompt repos / 98 occurrences; 1–3 target candidates; target-specific neutral requirements; exact mappings or exhaustive final no-match | XL | Gates 5 and 19 are 340/340 and 95/95; every edge independently adjudicated | family inheritance, observer-only credit, incomplete NCM search |",
    "| D — eight journeys and technical boundaries | days 0–60 | journey + architecture reviewers | eight exact journeys; canonical entity ownership; duplicates; events/outboxes; integrations; finance; Site/RBAC/privacy/safety; tests/performance/operability | XL | Gates 7, 17 and technical P2/P5/P6/P7 cells reconciled with source and execution evidence | treating source links as runtime behaviour |",
    "| E — final findings and independent Pass 8 | days 60–90 | fresh reviewers with no prior ownership | full finding schema, native proposals, interim safeguards, acceptance/validation contracts, cross-module sequencing, visual resample, all agent reconciliation | XL | Gates 20–21 and 25–26 complete; every module has P1–P8; no live agent | confirmation bias and premature completion claim |",
    "",
    "## Highest-risk provisional review order",
    "",
    "1. Medication exact authority, canonical scope and register/stock atomicity (`MED-*`).",
    "2. Safeguarding intake, alert identity and durable projection (`SAFE-*`).",
    "3. Webhook destination enforcement (`SET-API-WEBHOOK-DESTINATION-01`).",
    "4. Privacy report domain capability and H&S Site scope (`PRIV-*`, `HS-*`).",
    "5. Governance confidentiality and quorum snapshot (`GOV-*`).",
    "",
    "This order is risk-led, not a final remediation sequence. Each item must first pass the exact independent review and validation gate in `findings.json`.",
    "",
    "## Per-claim evidence-closure queue",
    "",
    "| Provisional ID | Feature/module | Proposed evidence owner | Effort | Interim safeguard | Exit test |",
    "|---|---|---|---|---|---|",
]
for finding in findings:
    roadmap_lines.append(
        f"| `{finding['id']}` | `{finding['feature_id']}` / {md(finding['module_submodule']['module'])} | "
        f"independent source reviewer + task-specific runtime/browser reviewer | NOT_ESTIMATED | "
        f"{md(finding['interim_safeguard'])} | {md(finding['validation_plan']['required'])} |"
    )
roadmap_lines += [
    "",
    "No application owner, delivery effort or remediation design is assigned by this audit. Those fields remain deliberately unresolved until a claim becomes a final finding.",
    "",
    "## Required inputs and decisions",
    "",
    "- Safe attributable current-source environment/build identity or an authoritative deployed commit/tree marker.",
    "- Manual sign-in or approved credential entry by the user; no credential invention, bypass or storage inspection.",
    "- Representative role, approved-Site and synthetic/non-sensitive fixture definitions, plus explicit read-only/pre-submit boundaries and cleanup authority for any later mutation-capable lane.",
    "- A separately authorised runtime/test/database gate if execution becomes necessary; static source does not grant it.",
    "- Specialist decisions for legal, clinical and security assertions after the audit separates official source, inference and decision boundaries.",
]
write_text("11-prioritised-roadmap.md", "\n".join(roadmap_lines))


native_lines = [
    "# 12 — Native-build and do-not-copy register",
    "",
    "> Benchmark-only boundary: no third-party source, assets, wording or distinctive layout may be imported or copied. This audit performs product/workflow comparison only and does not authorise integration, reuse, relicensing or application remediation.",
    "",
    "## Exact project denominator",
    "",
    f"- Prompt occurrences: **{project_denominator['listed_url_occurrences']}**.",
    f"- Unique prompt repositories: **{project_denominator['unique_repositories']}**.",
    "- Duplicate occurrences: `glpi-project/glpi`, `netbox-community/netbox`, and `opf/openproject` each appear twice.",
    f"- Physical register rows: **{project_denominator['physical_register_unique_rows']}** = 95 prompt repositories + 3 historical/supplemental rows.",
    f"- Formally accepted current project records: **{formal_upstream['counts']['formal_projects_accepted']} / 95** unique prompt repositories; observer-only records do not count.",
    "- Target-specific feature mappings: **0 / 340**. Final no-matches/NCMs: **0 / 340**.",
    "",
    "Historical labels such as `Native benchmark`, `Reject` or `Separate future decision` in the physical register are preserved provenance fields. They are not current target-edge decisions and do not change the 0/340 matrix.",
    "",
    "## Mandatory clean-room sequence",
    "",
    "1. Agent A records identity-bound upstream observations from an official repository/ref.",
    "2. Agent B receives an identity-stripped packet and writes neutral, source-independent requirements, preserving unknowns.",
    "3. Agent C compares the pinned current Oblivion facet to those neutral requirements without reading upstream identity/source.",
    "4. Agent D independently adjudicates lineage, collisions and disposition before identity is reattached.",
    "5. Root alone updates the canonical matrix. A candidate-edge NO-GO is not an exhaustive final no-match or NCM.",
    "",
    "## Native proposal register",
    "",
    "| Provisional finding | Neutral need available | External target mapped | Native design proposed | Copy boundary | Current disposition |",
    "|---|---|---:|---:|---|---|",
]
for finding in findings:
    native_lines.append(
        f"| `{finding['id']}` | 3 source-independent baseline needs, not target-specific | 0 | 0 | no source/assets/wording/layout copied | provisional claim; design deferred |"
    )
native_lines += [
    "",
    "The baseline needs above are generic safety/ownership requirements, not a completed benchmark-derived neutral specification. A native proposal remains prohibited from final credit until its target-specific clean-room chain and current finding are both complete.",
    "",
    "## 340-target no-copy coverage register",
    "",
    "| Feature ID | Module/class | Candidate field | Selected benchmark | Neutral requirements | No-match evidence | Current target credit | Boundary |",
    "|---|---|---|---|---|---|---:|---|",
]
for row in sorted(matrix, key=lambda item: item["feature_id"]):
    native_lines.append(
        f"| `{md(row['feature_id'])}` | {md(row['module'])} / {md(row['feature_class'])} | "
        f"{md(row['benchmark_candidates'])} | {md(row['selected_open_source_benchmark'])} | "
        f"{md(row['neutral_requirements_extracted'])} | {md(row['no_match_evidence'])} | 0 | "
        f"candidate/sentinel fields are non-credit; native design deferred |"
    )
native_lines += [
    "",
    "All 340 rows have `benchmark_mapping_credit=false`. A non-empty candidate or sentinel field is not an approved edge, neutral specification, final no-match or NCM.",
    "",
    "## Project triage provenance and exclusions",
    "",
    "| Project | Prompt membership | Prompt occurrences | Physical outcome label | Current formal triage | Root licence status | Edition boundary status | Selection/exclusion note |",
    "|---|---|---:|---|---|---|---|---|",
]
for row in sorted(benchmark_rows, key=lambda item: item["project"].lower()):
    native_lines.append(
        f"| `{md(row['project'])}` | {md(row['current_audit_prompt_denominator_membership'])} | "
        f"{md(row['current_prompt_occurrence_count'])} | {md(row['benchmark_outcome'])} | "
        f"{md(row['current_project_triage_status'])} | {md(row['current_project_triage_root_licence_status'])} | "
        f"{md(row['current_project_triage_edition_boundary_status'])} | {md(row['reason_selected_or_excluded'])} |"
    )
native_lines += [
    "",
    "## Licence, edition and maturity boundary",
    "",
    "- All listed projects remain benchmark-only regardless of licence.",
    "- Root licence and edition boundaries are provenance and maturity controls, not permission to copy or integrate.",
    "- A missing or partial licence/edition record blocks formal triage credit; it does not imply that a project is unsafe or unsuitable generally.",
    "- Any later reuse, integration or licence decision requires a separate explicitly authorised legal and technical review.",
    "- This register is not legal advice, clinical certification or a security attestation.",
]
write_text("12-native-build-and-do-not-copy-register.md", "\n".join(native_lines))


outputs = [
    "07-module-findings.md",
    "08-cross-module-journeys.md",
    "09-ui-ux-accessibility-visual-consistency.md",
    "10-architecture-data-integration-security.md",
    "11-prioritised-roadmap.md",
    "12-native-build-and-do-not-copy-register.md",
    "findings.json",
]
evidence = {
    "schema_version": 1,
    "run_id": "RUN-076-STAGED-REPORTING-MATERIALIZATION",
    "status": "SOURCE_BOUND_REPORTING_MATERIALIZED_ZERO_FINAL_CREDIT",
    "generated_on": GENERATED_ON,
    "pins": {
        "application_commit": APPLICATION_COMMIT,
        "application_tree": APPLICATION_TREE,
        "audit_checkpoint_parent": AUDIT_CHECKPOINT,
        "governing_prompt_sha256": PROMPT_SHA256,
    },
    "architecture_rule": ARCHITECTURE_RULE,
    "counts": {
        "canonical_features_represented": len(matrix),
        "H_features": sum(row["feature_class"] == "H" for row in matrix),
        "D_features": sum(row["feature_class"] == "D" for row in matrix),
        "provisional_source_claims": len(findings),
        "final_findings": 0,
        "static_visual_rows_represented": len(visual_rows),
        "current_source_rendered_visual_rows": 0,
        "prompt_unique_projects": project_denominator["unique_repositories"],
        "prompt_occurrences": project_denominator["listed_url_occurrences"],
        "benchmark_mappings": 0,
        "final_no_matches": 0,
        "source_reconstructed_journeys": journey_evidence["counts"]["journeys"],
        "source_classified_journey_handoffs": journey_evidence["counts"]["handoffs"],
        "fresh_independent_source_reviewed_journeys": journey_review["validated_totals"]["fresh_independent_source_reviews"],
        "prompt_grade_completed_journeys": 0,
        "canonical_entity_families": architecture_evidence["counts"]["entity_families"],
        "technical_concerns": architecture_evidence["counts"]["technical_concerns"],
        "architecture_provisional_claims": architecture_evidence["counts"]["provisional_claims"],
        "architecture_not_established_items": architecture_evidence["counts"]["not_established_items"],
        "architecture_final_findings": 0,
        "architecture_runtime_confirmed_findings": 0,
    },
    "inputs": {
        relative: sha256(relative)
        for relative in [
            "03-feature-to-benchmark-matrix.csv",
            "04-workflow-usability-scorecard.csv",
            "05-browser-visual-coverage-matrix.csv",
            "06-open-source-benchmark-register.csv",
            "evidence/source/current-feature-discovery-wave-01.json",
            "evidence/source/current-feature-discovery-wave-02.json",
            "evidence/source/current-usability-task-script-materialization-wave-01.json",
            "evidence/source/current-visual-matrix-materialization-wave-01.json",
            "evidence/source/current-visual-static-census-wave-01.json",
            "evidence/browser/current-deployed-selected-feature-observation-wave-03.json",
            "evidence/browser/root-run-072-authentication-blocked-frontline-slice-wave-04.json",
            "evidence/benchmark/current-prompt-project-denominator-reconciliation.json",
            "evidence/benchmark/current-formal-upstream-triage-wave-03.json",
            "evidence/source/raw-run-073a-required-artifact-contract-wave-05.json",
            "evidence/source/raw-run-073b-cross-module-journeys-wave-05.json",
            "evidence/source/raw-run-073d-independent-journey-review-wave-05.json",
            "evidence/source/root-run-073c-architecture-data-integration-security-wave-05.json",
            "evidence/official-sources/nz-source-baseline-2026-08-24.json",
        ]
    },
    "outputs": {relative: sha256_output(relative) for relative in outputs},
    "credit_boundary": {
        "artifact_presence": True,
        "final_finding": False,
        "browser": False,
        "ease": False,
        "benchmark_mapping": False,
        "final_no_match": False,
        "pass": False,
        "completion": False,
    },
    "attestation": "Root-only deterministic reporting materialization; no application files, browser, runtime, tests, build, database or VCS state changed by this generator.",
}
write_json("staged-reporting-materialization-wave-06.json", evidence)
