#!/usr/bin/env python3
"""Record the HR Pass-8 source challenge and append its reviewed webhook finding."""

from __future__ import annotations

import hashlib
import json
import subprocess
from pathlib import Path
from typing import Any


GENERATOR = Path(__file__).resolve()
AUDIT = GENERATOR.parent.parent
REPO = AUDIT.parent.parent.parent
SOURCE = AUDIT / "evidence" / "source"
GENERATED_AT = "2026-08-21T20:10:00+12:00"
AUDITED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
CURRENT_MAIN = "20ad5cef0aacb3d055e685d2f8b7b583cb8d78f4"
FINDING_ID = "HR-WEBHOOK-OUTBOUND-SSRF-01"

PATHS = {
    "benchmark": SOURCE / "benchmark-final-904-mapping.json",
    "inventory": AUDIT / "inventory-904.json",
    "manifest": SOURCE / "working-capability-manifest-904.json",
    "findings": AUDIT / "findings.json",
    "reconciliation": SOURCE / "finding-link-reconciliation.json",
    "official_map": SOURCE / "official-nz-finding-proposition-map.json",
    "pointer": SOURCE / "canonical-audit-inputs.json",
    "pass8": SOURCE / "pass8-human-resources-904-2026-08-21.json",
    "summary": SOURCE / "final-904-hr-webhook-ssrf-generation-summary.json",
}

PRE_PINS = {
    "benchmark": "84f73bd34c2ff0e59551196a8a1886b4790de6eebc8f2be34b6e5978ea008491",
    "inventory": "579d2bde9e5f0d28ff1e912da354ec0244f6abe9eebaaf2eabf3c7ad3af2144e",
    "manifest": "ffca48609deab9a8938105c857786594a9a5431c31efe329ef4288da6165358f",
    "findings": "0ed5f04514261c0ae33b1a6e2c6af76b57e42391e288da67a88be9638cd0ea57",
    "reconciliation": "9f01591314ab68d1b47a5b0217e982c288f66ee6424a1d82ee7901aa876e9fbf",
    "official_map": "4a1caf7a8dab6106c0d6c5094e265185d56f9dd2a37fdb474699a4fd8899be74",
}

FEATURE_IDS = ["CAP-HR-WEBHOOK-ENDPOINTS", "CAP-HR-WEBHOOK-DELIVERY-RETRY"]
ROUTE_IDS = ["ROUTE-1745", "ROUTE-1746", "ROUTE-1747", "ROUTE-1748", "ROUTE-1749"]

SOURCE_CHAIN = [
    {"path": "routes/hr.php", "baseline_blob": "e475f4ed99c39b3c531e2a07fa00c423e68c6ee4", "baseline_loci": "L1269-L1283",
     "current_blob": "20737ca6b96df6a88527216d99db00ddf3bec4f8", "current_loci": "L1331-L1345"},
    {"path": "app/Http/Controllers/Hr/HrWebhookController.php", "baseline_blob": "e0d76f132a79d7b9aa7eea1b356a3d84b5439579", "baseline_loci": "L79-L123,L143-L150",
     "current_blob": "064a742d20a0fe8cff522d291fef41e5dfe02203", "current_loci": "L76-L115,L132-L136"},
    {"path": "app/Domain/Hr/Services/HrWebhookService.php", "baseline_blob": "6884e04ad8fa8ba1a4b9a863a18d97c97650b0b3", "baseline_loci": "L46-L110,L116-L156",
     "current_blob": "db1779d70cc6a900f59f87e923fb8e74d326ae4b", "current_loci": "L59-L158,L163-L210"},
    {"path": "app/Domain/Hr/Models/HrWebhookEndpoint.php", "baseline_blob": "285632d4b6a0cc891bb8611d37fe85d85c17e08f", "baseline_loci": "L16-L41",
     "current_blob": "88d3dcbe4bb19bda23c029911b0a9ba8f7498f31", "current_loci": "L17-L42"},
    {"path": "app/Domain/Hr/Jobs/DeliverHrWebhookJob.php", "baseline_blob": "57b59c38640e1eed8a9db7ca997f7a7fc974156f", "baseline_loci": "L76-L107,L125-L136",
     "current_blob": "96e197ee96e7cb1e75f4f0ab70a9828848ca7cdf", "current_loci": "L74-L114,L130-L140"},
    {"path": "resources/js/pages/hr/settings/webhooks.tsx", "baseline_blob": "c558e1e9222d97989b00e8ac1b303c5ac1ecc7e8", "baseline_loci": "L111-L149,L226-L242",
     "current_blob": "4230ff29d85b995877443488922a3441ed098340", "current_loci": "L115-L153,L231-L247"},
    {"path": "database/seeders/SeedHrPermissionsSeeder.php", "baseline_blob": "2a79bc9337b2c73c1b621eaf5be0a31a1112af5c", "baseline_loci": "L86-L87,L115-L138",
     "current_blob": "0a6856db6393a631b02e09ac1f45edf14d06f718", "current_loci": "L88-L89,L117-L143"},
    {"path": "tests/Feature/Hr/HrWebhookDeliveryTest.php", "baseline_blob": "1e33850a0525dc392a00d38bd756dd8fbe1572c4", "baseline_loci": "L36-L129",
     "current_blob": "0eae7dbdff8b60bda95499eb749832386462b39b", "current_loci": "L65-L171,L227-L276"},
    {"path": "database/migrations/2026_02_18_050000_create_hr_webhooks_tables.php", "baseline_blob": "53f5e4f923620377456d781f8762787143336fe0", "baseline_loci": "L14",
     "current_blob": "53f5e4f923620377456d781f8762787143336fe0", "current_loci": "L14"},
]


def sha_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha_file(path: Path) -> str:
    return sha_bytes(path.read_bytes())


def load(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def save(path: Path, value: Any) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")


def rel(path: Path) -> str:
    return path.relative_to(AUDIT).as_posix()


def pin(path: Path) -> dict[str, Any]:
    return {"path": rel(path), "sha256": sha_file(path), "bytes": path.stat().st_size}


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def git(*args: str) -> str:
    return subprocess.check_output(["git", *args], cwd=REPO, text=True).strip()


def git_blob(blob: str) -> bytes:
    return subprocess.check_output(["git", "cat-file", "blob", blob], cwd=REPO)


def verify_source_chain() -> list[dict[str, Any]]:
    require(git("rev-parse", "HEAD") == AUDITED_COMMIT, "Audited worktree HEAD drift")
    require(git("rev-parse", "refs/remotes/origin/main") == CURRENT_MAIN, "Current-main cross-check drift")
    verified: list[dict[str, Any]] = []
    for row in SOURCE_CHAIN:
        baseline_ref = git("rev-parse", f"{AUDITED_COMMIT}:{row['path']}")
        current_ref = git("rev-parse", f"{CURRENT_MAIN}:{row['path']}")
        require(baseline_ref == row["baseline_blob"], f"Baseline blob drift: {row['path']}")
        require(current_ref == row["current_blob"], f"Current blob drift: {row['path']}")
        verified.append({**row, "baseline_sha256": sha_bytes(git_blob(row["baseline_blob"])),
                         "current_sha256": sha_bytes(git_blob(row["current_blob"]))})
    return verified


def finding_payload() -> dict[str, Any]:
    anchors = [
        "routes/hr.php:1269-1283",
        "app/Http/Controllers/Hr/HrWebhookController.php:79-123,143-150",
        "app/Domain/Hr/Services/HrWebhookService.php:46-156",
        "app/Domain/Hr/Models/HrWebhookEndpoint.php:16-41",
        "app/Domain/Hr/Jobs/DeliverHrWebhookJob.php:76-107,125-136",
        "resources/js/pages/hr/settings/webhooks.tsx:111-149,226-242",
        "tests/Feature/Hr/HrWebhookDeliveryTest.php:36-129",
    ]
    return {
        "id": FINDING_ID,
        "feature_ids": FEATURE_IDS,
        "passes": ["P1", "P2", "P5", "P6", "P7", "P8"],
        "module": "Human resources",
        "submodule": "Outbound HR webhook destination governance",
        "actor_and_job": "An authenticated administrator or HR role with hr.settings.manage creates or updates an outbound HR webhook endpoint, or retries a delivery.",
        "route_url": {
            "summary": "Five exact HR webhook management/retry routes share one privileged settings surface.",
            "route_names": ["hr.settings.webhooks.index", "hr.settings.webhooks.store", "hr.settings.webhooks.update",
                            "hr.settings.webhooks.toggleActive", "hr.settings.webhooks.deliveries.retry"],
            "route_paths": ["hr/settings/webhooks", "hr/settings/webhooks/{endpoint}",
                            "hr/settings/webhooks/{endpoint}/toggle-active", "hr/settings/webhooks/deliveries/{delivery}/retry"],
        },
        "frontend_anchor": {"summary": "The endpoint form accepts a free-text URL and the same page exposes retry.",
                            "page_files": ["resources/js/pages/hr/settings/webhooks.tsx:111-153,226-247"],
                            "audited_commit": AUDITED_COMMIT},
        "visual_context": {"visual_id": "None assigned", "classification": "Source-inferred",
                           "role": "Privileged HR settings actor; representative runtime unavailable",
                           "site_scope": "Single tenant; destination governance is organisation-level",
                           "viewport": "Not safely reproduced", "state": "Source trace",
                           "pattern_type": "backend/source finding", "component_anchor": "See source anchors",
                           "screenshot_reference": "None—no screenshot or exploit is claimed",
                           "internal_baseline": "Native HR settings, permission and queued-job conventions"},
        "pattern_implementation": "Static route/controller/service/job review only; no network reachability, response, exploit or rendered defect is claimed.",
        "backend_anchors": anchors,
        "current_behavior": "At both pinned source snapshots, an actor with hr.settings.manage can persist a URL accepted by generic URL validation. Event publication or retry queues a job that POSTs the stored URL without an HR destination policy, private/reserved-address rejection, DNS binding control, or explicit redirect guard.",
        "current_workflow": {
            "summary": "Source-reviewed; five routes, one exact page owner and two canonical capabilities. Queue execution and destination reachability were not tested.",
            "failure_sequence": "A privileged actor stores a loopback, private, link-local, rebinding or redirecting destination; a later event or retry can reach the direct HTTP sink if the runtime network permits it.",
            "boundary": "Privileged configuration, outbound destination identity, redirect/DNS resolution, HR payload disclosure and failure diagnostics.",
            "completion_evidence": "Static source chain and independent duplicate/priority review only; representative execution remains zero.",
        },
        "ease_evidence": {
            "validation_status": "Blocked—source finding retained; no representative runtime or ten-dimension validation executed",
            "evidence_basis": "Static source trace only",
            "current_scores": {key: 0 for key in ["discoverability", "comprehension", "learnability", "efficiency",
                                                          "error_prevention", "recovery", "accessibility", "safety_and_trust",
                                                          "consistency", "cross_module_continuity"]},
            "friction": {"completion_time": "Not measured", "step_count": "Not measured", "required_field_count": "Not measured",
                         "decision_count": "Owner decision required", "context_switches": "Not measured", "dead_ends": "Runtime unknown",
                         "recovery_path": "Reject unsafe destinations before persistence or delivery; preserve a non-secret failure reason and authorised correction/retry path."},
            "target_scores": {"all_dimensions": 4, "safety_critical_error_prevention_and_trust": 5},
            "independent_review": "Independent source review confirmed a new P1 and rejected any runtime exploit or P0 claim.",
        },
        "evidence": {"anchors": anchors,
                     "existing_tests": ["HrWebhookDeliveryTest covers public example delivery and retry but no loopback/private/link-local/DNS/redirect denial"],
                     "tests_executed": False,
                     "browser_claim_limit": "No credential, application request, queue worker, destination, response or exploit was exercised."},
        "problem_root_cause": "Generic URL syntax validation is treated as sufficient outbound destination authority, and the queued HTTP sink has no canonical destination/egress policy.",
        "impact": "If runtime egress permits it, a privileged configuration mistake or abuse could send HR webhook payloads to unintended internal or redirected destinations. No reachability or disclosure was observed.",
        "benchmark": {"selected": "None", "url_and_sha": "", "verified_behavior": "",
                      "outcome": "Evidence gap—not a documented No Credible Match",
                      "no_match_evidence": "Both exact HR webhook targets remain benchmark-unproved; no NCM credit is inferred from this finding."},
        "neutral_requirements": "Apply one native outbound destination policy before persistence and again after DNS resolution/redirect decisions; preserve bounded failure diagnostics and authorised retry.",
        "better_oblivion_design": "Create one reusable outbound-destination authority that validates scheme, host, resolved addresses and redirects, then route HR delivery and retries through it.",
        "target_ease": {"scores": {"all_dimensions": 4, "safety_critical_error_prevention_and_trust": 5},
                        "measurable_outcome": "A privileged actor can configure an approved public endpoint, while unsafe destinations fail before any request and expose a safe correction path."},
        "cross_module_effects": "Keep HR payload ownership, delivery provenance, retry identity and diagnostics in the existing HR webhook aggregate; do not create a second delivery ledger.",
        "rbac_privacy": "hr.settings.manage remains necessary but is not sufficient destination authority; test privileged denial and minimum-necessary payload handling separately.",
        "priority": "P1", "effort": "M",
        "dependencies_sequence": "Security owner defines destination policy; apply it to create/update and delivery; add deterministic network-denial tests; then run representative browser/retry verification.",
        "proposed_owner": "HR Product Owner and Application Security Owner",
        "confidence": "High for the static source path; runtime egress, DNS behavior, target reachability and exploitability remain unverified",
        "source_boundary": "Audited-baseline and current-main source are separately pinned. Official sources frame secure web/application design only; no legal applicability, certification or exploit conclusion is inferred.",
        "interim_safeguard": "Restrict hr.settings.manage and manually review configured HR webhook destinations until a canonical destination policy is enforced.",
        "acceptance_criteria": [
            "Create and update reject loopback, private, link-local, multicast, unspecified and otherwise reserved destinations before persistence.",
            "Delivery re-resolves and revalidates every address and redirect hop before connecting.",
            "Approved public HTTPS endpoints remain functional with bounded timeouts and secret-redacted diagnostics.",
            "Retry uses the same destination policy and stable delivery identity.",
            "Tests cover IPv4/IPv6 literals, DNS rebinding, redirects, encoded hosts and an approved endpoint without real external egress.",
        ],
        "missing_tests": ["Loopback/private/link-local IPv4 and IPv6 denial", "DNS rebinding denial",
                          "Redirect-to-private denial", "Encoded/alternate host-form denial", "Approved public endpoint and retry"],
        "validation_plan": ["Unit-test the canonical destination classifier", "Run fake-resolver/fake-transport feature tests without external egress",
                            "Verify create/update/retry use the same policy", "Run privileged representative browser checks at required viewports after correction",
                            "Retain open status until merged-to-main and runtime evidence are canonical"],
        "official_sources": [{"id": "NZ-HISO-10029-2022", "title": "HISO 10029:2022 Health Information Security Framework",
                              "authority": "Health New Zealand / Health Information Standards Organisation",
                              "url": "https://static.info.content.health.nz/docs/HISO/HISO%2010029%20Health%20Information%20Security%20Framework.pdf",
                              "supporting_url": "https://www.healthnz.govt.nz/health-professionals/guidance-standards/topic/data-and-standards/health-information-standards/approved-health-information-standards/information-governance",
                              "inspected_date": "2026-08-12"}],
        "statement_types": {
            "source": "Generic URL validation, verbatim persistence, queued delivery/retry and direct HTTP POST are source-observed at the pinned commits.",
            "official_source": "HISF-APPSEC and HISF-WEB frame approved application-security and secure-web requirements; they do not prove reachability, compromise, certification or the exact allowlist mechanism.",
            "inference": "Potential private/internal reachability and HR payload disclosure are bounded static inferences; neither was executed or observed.",
            "specialist_decision": "P1 priority and the destination policy require the HR and application-security owners.",
        },
        "official_source_proposition_keys": ["HISF-APPSEC", "HISF-WEB"],
        "feature_link_reconciliation": {
            "method": "route-first: exact five audited routes and one uniquely owned endpoint page; retry is linked by its route only and receives no shared-page inheritance",
            "projection_status": "literal_current_904_manifest_links_present; runtime_and_remediation_unverified",
            "legacy_feature_ids": ["HR-HR-WEBHOOK"],
            "decisions": [{"legacy_family_id": "independent-pass8-hr-webhook-destination-2026-08-21",
                           "method": "source-proven exact current target route/backend intersection",
                           "feature_ids": FEATURE_IDS, "route_hits": ROUTE_IDS,
                           "page_hits": [{"page_id": "PAGE-0513", "feature_id": "CAP-HR-WEBHOOK-ENDPOINTS"}],
                           "source_anchors": anchors,
                           "evidence": "Fresh HR Pass-8 review traced privileged URL persistence and retry through the direct HTTP sink and retained two literal current IDs without runtime credit.",
                           "audited_commit": AUDITED_COMMIT, "current_main_static_cross_check": CURRENT_MAIN}],
            "uncertainties": [{"reason_code": "runtime_network_and_representative_role_unexecuted",
                               "detail": "Static evidence supports the finding; egress, DNS, redirects, target reachability, payload disclosure and representative behavior remain unverified.",
                               "smallest_next_evidence": "Apply the reviewed destination policy, then run fake-resolver/fake-transport denial tests and a privileged no-external-egress browser check."}],
        },
        "remediation": {"status": "open", "note": "No isolated remediation branch or runtime verification is recorded."},
    }


def rebuild_reconciliation(payload: dict[str, Any], findings: dict[str, Any], manifest: dict[str, Any]) -> None:
    manifest_ids = {row["working_key"] for row in manifest["targets"]}
    rows = findings["findings"]
    exact = [(row["id"], feature) for row in rows for feature in row.get("feature_ids", []) if feature in manifest_ids]
    exact_findings = {finding_id for finding_id, _ in exact}
    p0p1 = [row for row in rows if row["priority"] in {"P0", "P1"}]
    p0p1_exact = {row["id"] for row in p0p1} & exact_findings
    decisions = [d for row in rows for d in row.get("feature_link_reconciliation", {}).get("decisions", [])]
    prior = payload["current_final_id_link_summary"]
    payload["generated_at"] = GENERATED_AT
    payload["status"] = "current_904_literal_link_reconciliation_partial_runtime_unverified"
    payload["scope_boundary"] = "Links preserve audited source and literal current 904 IDs; they do not establish runtime outcome, remediation or completion."
    payload["current_final_id_link_summary"] = {
        "literal_links": len(exact), "literal_targets": len({feature for _, feature in exact}),
        "explicitly_re_adjudicated_links": prior["explicitly_re_adjudicated_links"] + len(FEATURE_IDS),
        "explicitly_re_adjudicated_findings": sorted(set(prior["explicitly_re_adjudicated_findings"]) | {FINDING_ID}),
        "findings_with_literal_exact_current_id": len(exact_findings),
        "findings_without_literal_exact_current_id": len(rows) - len(exact_findings),
        "p0_p1_with_literal_exact_current_id": len(p0p1_exact),
        "p0_p1_without_literal_exact_current_id": len(p0p1) - len(p0p1_exact), "complete": False,
    }
    payload["counts"] = {
        "findings": len(rows), "total_links": sum(len(row.get("feature_ids", [])) for row in rows),
        "findings_with_uncertainty": sum(bool(row.get("feature_link_reconciliation", {}).get("uncertainties")) for row in rows),
        "findings_without_literal_exact_current_id": len(rows) - len(exact_findings),
        "route_intersection_groups": sum(bool(d.get("route_hits")) for d in decisions),
        "unique_page_intersection_groups": sum(bool(d.get("page_hits")) for d in decisions),
        "one_to_one_groups": sum("one-to-one" in str(d.get("method", "")).lower() for d in decisions),
    }
    payload["findings"] = [{"finding_id": row["id"], "feature_ids": row.get("feature_ids", []),
                            "literal_current_feature_ids": [f for f in row.get("feature_ids", []) if f in manifest_ids],
                            "reconciliation": row.get("feature_link_reconciliation", {})} for row in rows]
    require(payload["counts"] == {"findings": 94, "total_links": 267, "findings_with_uncertainty": 26,
                                  "findings_without_literal_exact_current_id": 0, "route_intersection_groups": 41,
                                  "unique_page_intersection_groups": 3, "one_to_one_groups": 104}, "Finding reconciliation count drift")
    require(payload["current_final_id_link_summary"]["literal_links"] == 168, "Literal link drift")
    require(payload["current_final_id_link_summary"]["literal_targets"] == 136, "Literal target drift")
    require(payload["current_final_id_link_summary"]["p0_p1_with_literal_exact_current_id"] == 82, "P0/P1 literal drift")


def validate_existing() -> None:
    findings = load(PATHS["findings"])
    require(sum(row["id"] == FINDING_ID for row in findings["findings"]) == 1, "Existing finding duplication")
    pointer = load(PATHS["pointer"])
    require(pointer["artifacts"]["pass8_human_resources"] == pin(PATHS["pass8"]), "Pass8 pointer drift")
    require(pointer["artifacts"]["hr_webhook_ssrf_generation_summary"] == pin(PATHS["summary"]), "Summary pointer drift")
    summary = load(PATHS["summary"])
    for key in ("findings", "reconciliation", "official_map", "pass8"):
        require(summary["outputs"][key] == pin(PATHS[key]), f"Existing output drift: {key}")
    print(json.dumps({"status": "idempotent_no_change", "finding_id": FINDING_ID}, indent=2))


if any(row["id"] == FINDING_ID for row in load(PATHS["findings"])["findings"]):
    validate_existing()
    raise SystemExit(0)

for name, expected in PRE_PINS.items():
    require(sha_file(PATHS[name]) == expected, f"Input SHA drift: {name}")
verified_source = verify_source_chain()

benchmark = load(PATHS["benchmark"])
inventory = load(PATHS["inventory"])
manifest = load(PATHS["manifest"])
findings = load(PATHS["findings"])
reconciliation = load(PATHS["reconciliation"])
official_map = load(PATHS["official_map"])
pointer = load(PATHS["pointer"])

require(benchmark["summary"]["eligible_total"] == 464 and benchmark["summary"]["completion_unproved"]["total"] == 440, "Benchmark count drift")
manifest_ids = {row["working_key"] for row in manifest["targets"]}
require(set(FEATURE_IDS) <= manifest_ids, "Finding target missing from manifest")
by_benchmark = {row["working_key"]: row for row in benchmark["targets"]}
require(all(not by_benchmark[key]["completion_credit"] for key in FEATURE_IDS), "Webhook benchmark status unexpectedly credited")

hr_features = [row for row in inventory["features"] if row["module_key"] == "HR"]
require(len(hr_features) == 154, "HR denominator drift")
require({c: sum(row["class"] == c for row in hr_features) for c in ("H", "D", "M")} == {"H": 136, "D": 18, "M": 0}, "HR class drift")
require(sum(not by_benchmark[row["working_key"]]["completion_credit"] for row in hr_features) == 36, "HR post-Wave34 unproved drift")
require(sum(row["module_key"] == "HR" for row in inventory["tests"]) == 157, "HR test inventory drift")

pass8 = {
    "schema_version": "1.0.0", "artifact": "pass8-human-resources-904-2026-08-21",
    "generated_at": GENERATED_AT, "audited_commit": AUDITED_COMMIT,
    "current_main_static_cross_check": CURRENT_MAIN,
    "status": "source_only_pass8_challenge_no_module_completion_credit",
    "selection": {"method": "Equal-weight normalized exposure across target count, benchmark-unproved count, unresolved P0/P1 density and runtime-gap count.",
                  "selected_module": "HR", "selected_score": 74.27, "next_scores": {"HEALTH_SAFETY": 50.75, "OPERATIONS": 50.25}},
    "module_counts": {"targets": 154, "H": 136, "D": 18, "M": 0,
                      "benchmark_decided_at_selection": 110, "benchmark_unproved_at_selection": 44,
                      "benchmark_decided_after_wave34": 118, "benchmark_unproved_after_wave34": 36,
                      "linked_p0_p1_before_wave": 7, "linked_p0_p1_after_wave": 8,
                      "runtime_unexecuted": 154, "test_inventory_rows": 157},
    "eight_pass": {
        "P1": {"reviewed": 154, "denominator": 154, "boundary": "Static identity and route/source inventory only."},
        "P2": {"executed": 0, "denominator": 154, "boundary": "Representative persisted tasks unexecuted."},
        "P3": {"decided": 118, "denominator": 154, "unproved": 36,
               "selection_boundary": "The module was selected before Wave34, when 110 were decided and 44 unproved."},
        "P4": {"executed": 0, "denominator": 154, "boundary": "Happy/error/recovery/handoff/responsive/accessibility execution absent."},
        "P5": {"static_reviewed": 154, "denominator": 154, "runtime_data_effects_verified": 0},
        "P6": {"exact_source_finding_official_links": 3, "denominator": 154, "boundary": "New finding is source-reviewed but broad module proposition coverage remains incomplete."},
        "P7": {"source_constraint_failure_links": 6, "denominator": 154, "tests_executed": 0},
        "P8": {"static_identity_challenged": 154, "denominator": 154, "module_completion_credit": False},
    },
    "new_finding": {"id": FINDING_ID, "priority": "P1", "feature_ids": FEATURE_IDS, "route_ids": ROUTE_IDS,
                    "verdict": "independently_reviewed_new_nonduplicate_p1_static_only"},
    "source_chain": verified_source,
    "duplicate_boundary": {"existing_finding_count": 93, "exact_target_link_collisions": 0, "exact_route_link_collisions": 0,
                           "related_not_duplicate": ["INTEG-WEBHOOK-001", "ARCH-P0-C", "SEC-UNIFI-TLS-01"]},
    "credit_boundary": {"runtime_credit_delta": 0, "browser_credit_delta": 0, "benchmark_credit_delta": 0,
                        "module_completion_delta": 0, "finding_delta": 1},
}
save(PATHS["pass8"], pass8)

findings["findings"].append(finding_payload())
findings["findings"].sort(key=lambda row: row["id"])
findings["counts"]["P1"] = 63
links = findings["counts"]["feature_link_reconciliation"]
links.update({"benchmark_mapping": {"eligible": 464, "verified_benchmark": 375, "documented_no_credible_match": 89, "completion_unproved": 440},
              "findings": 94, "total_links": 267, "literal_exact_current_links": 168,
              "literal_exact_current_targets": 136, "findings_with_literal_exact_current_id": 94,
              "p0_p1_with_literal_exact_current_id": 82, "p0_p1_without_literal_exact_current_id": 0,
              "findings_with_uncertainty": 26, "findings_without_literal_exact_current_id": 0,
              "route_intersection_groups": 41, "unique_page_intersection_groups": 3})
findings["audit_status"] = "Blocked—not comprehensive or complete. The canonical 904-target register is current (790H/111D/3M). Benchmark/NCM completion credit is 464/904, visual final-ID linkage is 8,168/8,753, material-state linkage is 3,948/4,312, and 94 source-backed findings are retained. All 82/82 P0/P1 findings contain a literal current-manifest ID; runtime remains unexecuted."
findings["statement"] = "Full schema for every retained finding. Static evidence, inference, official propositions and owner decisions remain separated; runtime and representative-role completion are not claimed."
rebuild_reconciliation(reconciliation, findings, manifest)

require(official_map["denominator"] == official_map["reviewed"] == 51, "Official-map base drift")
official_map["findings"].append({"finding_id": FINDING_ID, "proposition_keys": ["HISF-APPSEC", "HISF-WEB"]})
official_map["findings"].sort(key=lambda row: row["finding_id"])
official_map["denominator"] = official_map["reviewed"] = 52
official_map["coverage_percent"] = 100.0
official_map["owner_boundary_rows"] = sum(any(str(key).startswith("OWNER-") for key in row["proposition_keys"]) for row in official_map["findings"])
require(official_map["owner_boundary_rows"] == 27, "Official owner boundary drift")

save(PATHS["findings"], findings)
save(PATHS["reconciliation"], reconciliation)
save(PATHS["official_map"], official_map)

outputs = {key: pin(PATHS[key]) for key in ("findings", "reconciliation", "official_map", "pass8")}
summary = {
    "schema_version": "1.0.0", "artifact": "final-904-hr-webhook-ssrf-generation-summary",
    "generated_at": GENERATED_AT, "audited_commit": AUDITED_COMMIT, "current_main_static_cross_check": CURRENT_MAIN,
    "finding_id": FINDING_ID, "status": "generated_open_p1_static_only_runtime_and_completion_blocked",
    "inputs": {key: {"path": rel(PATHS[key]), "sha256": value, "bytes": PATHS[key].stat().st_size} for key, value in PRE_PINS.items()},
    "source_chain": verified_source, "outputs": outputs,
    "counts": {"denominator": {"total": 904, "H": 790, "D": 111, "M": 3},
               "benchmark": {"eligible": 464, "verified": 375, "ncm": 89, "unproved": 440},
               "findings": {"total": 94, "P0": 19, "P1": 63, "P2": 12},
               "finding_links": {"total": 267, "literal": 168, "literal_targets": 136, "p0_p1_literal": 82},
               "official_map": {"denominator": 52, "reviewed": 52, "owner_boundary_rows": 27}},
    "credit_boundary": {"static_finding_added": 1, "runtime_credit_delta": 0, "browser_credit_delta": 0,
                        "benchmark_credit_delta": 0, "remediation_credit_delta": 0, "completion_credit_delta": 0},
    "idempotence": "A second run validates hashes and pointer entries and performs no write.",
}
save(PATHS["summary"], summary)

pointer["generated_at"] = max(pointer.get("generated_at", ""), GENERATED_AT)
pointer["artifacts"].update({"findings": outputs["findings"], "finding_link_reconciliation": outputs["reconciliation"],
                             "official_nz_finding_proposition_map": outputs["official_map"],
                             "pass8_human_resources": outputs["pass8"],
                             "hr_webhook_ssrf_generation_summary": pin(PATHS["summary"])})
pointer["completion_status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"
pointer["runtime_credit_delta"] = 0
save(PATHS["pointer"], pointer)

validate_existing()
print(json.dumps({"status": "generated", "finding_id": FINDING_ID, "outputs": outputs, "pointer": pin(PATHS["pointer"])}, indent=2))
