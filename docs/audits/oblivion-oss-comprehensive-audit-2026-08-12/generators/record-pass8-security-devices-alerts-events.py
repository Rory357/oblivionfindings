#!/usr/bin/env python3
"""Record the ARCH-P0-B Alerts & Events temporal-custody verification failure."""

from __future__ import annotations

import csv
import hashlib
import io
import json
import subprocess
from collections import Counter
from pathlib import Path
from typing import Any


AUDIT = Path(__file__).resolve().parents[1]
SOURCE = AUDIT / "evidence" / "source"
AUDITED = "081ef198f9f992f224e8c0c9fba33df33dde40be"
MAIN = "20ad5cef0aacb3d055e685d2f8b7b583cb8d78f4"
GENERATED_AT = "2026-08-22T01:25:00+12:00"
FINDING_ID = "ARCH-P0-B"
FEATURE_ID = "SEC-ALERTS-EVENTS"
VISUAL_ID = "VIS-020827"

P = {
    "manifest": SOURCE / "working-capability-manifest-904.json",
    "benchmark": SOURCE / "benchmark-final-904-mapping.json",
    "inventory": AUDIT / "inventory-904.json",
    "findings": AUDIT / "findings.json",
    "reconciliation": SOURCE / "finding-link-reconciliation.json",
    "visual": AUDIT / "05-browser-visual-coverage-matrix-904.csv",
    "pointer": SOURCE / "canonical-audit-inputs.json",
    "pass8": SOURCE / "pass8-security-devices-904-2026-08-21.json",
    "summary": SOURCE / "final-904-security-devices-alerts-events-verification-generation-summary.json",
}
PRE = {
    "manifest": "b9c1cf28e53e26df91fe772d91924beb56f56c1b9f3c68ddb320134bf148aa10",
    "benchmark": "659dc53cd3f8438c0c699b17d7579c449f741081f963956b2c941183905717b7",
    "inventory": "e193306f8b748485ae0e4d7e1cb9d5da9f8c6f652b8ea9911b9a87b9d954b5d8",
    "findings": "715faa7c011101aa1389697004aa647e5084450c257fdb9299018c37e4af6c2b",
    "reconciliation": "941c556f4506673ab06b4673194289855e33830ad4276b57e0e59f20a4b324ca",
    "visual": "707885a83264c8e2ab3f92898578a2b20ba3e8a2ccdb3ece17156d8c0774c293",
    "pointer": "d30661d99ae5919347d9dc2b65a97e89eaa49fee2f621d1965b084de4a0307ba",
}

SOURCE_PINS = [
    {"path":"app/Domain/SecurityDevices/Http/Controllers/AlertsEventsController.php","audited":"d1a4d530751b9c2db26873f431a95408bbd1fe9e17cc1cdf510daa9b82d43cf7","current":"cdb14692b7788da761e3104b9366ca56d4e0d2008c2e9d9f3eabcb86805f288e","loci":"audited L23-L33,L92-L101; current L18-L24,L30-L39,L98-L109,L125-L150"},
    {"path":"app/Domain/SecurityDevices/Http/Controllers/ReportsController.php","audited":"1bb84f4d5bdf6f2b8d454fe82b2c67ff2750871cd1962ee89bdd0b65d2b6b7b7","current":"5f98d973f3bfcd72e7b025433bfa9b70d93a639c526a3fc50ff851f8f32a96ad","loci":"current L38-L57,L118-L166"},
    {"path":"app/Domain/SecurityDevices/Services/SecurityDevicesAccessService.php","audited":None,"current":"40085ca9fe543a91ba74cecb439b09c3ca143e1ed5c759271630616ec8faa007","loci":"current L530-L590"},
    {"path":"routes/security-devices.php","audited":"6a3ad91c504ebce8640afac368b63602f4d7e893bef3eda508601e9c6f613544","current":"c92b48d3edf7b0d89bd5bdcd77c74b4139d2959fd459b9a53c08fc71e7b0ba3f","loci":"current L47-L50,L328-L330"},
    {"path":"database/seeders/SecurityDevicesPermissionsSeeder.php","audited":"0eb5c550af86e6f1e6cc4ca2f17b0b06438b971be9a21f149fdc745be379c754","current":"4aa44f654fb533f148ff4459f354e8c324e6cb3203744e919b9cab5cd7eef4ad","loci":"current L95-L123,L136-L160"},
    {"path":"tests/Feature/SecurityDevices/AlertsEventsControllerTest.php","audited":"1ff3a92633f7cadc9171f9d0fe3e9149506d533abcca480f3c9f07dc161cdec0","current":"572526c96aa7c78ac5d6ab56332d3a705a6d689bd3d6e887f45a471bf88064f6","loci":"current L332-L366; all-Sites happy path only"},
    {"path":"resources/js/pages/security-devices/alerts-events.tsx","audited":"c85951dca9445f50241abe63c02ac690ee6020b8f8b5eb55f4988c9397d108e6","current":"95ed7d5d1ff1b9ea58b7b38200c9c8d15f2d80a210756c3c2eb5094b16b6f749","loci":"PAGE-0814 exact routed page"},
]


def sha_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha_file(path: Path) -> str:
    return sha_bytes(path.read_bytes())


def load(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def save(path: Path, value: Any) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")


def require(ok: bool, message: str) -> None:
    if not ok:
        raise RuntimeError(message)


def rel(path: Path) -> str:
    return path.relative_to(AUDIT).as_posix()


def pin(path: Path) -> dict[str, Any]:
    return {"path": rel(path), "sha256": sha_file(path), "bytes": path.stat().st_size}


def git_bytes(ref: str, path: str) -> bytes | None:
    result = subprocess.run(["git", "show", f"{ref}:{path}"], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    return None if result.returncode else result.stdout


def verify_sources() -> None:
    for row in SOURCE_PINS:
        audited = git_bytes(AUDITED, row["path"])
        current = git_bytes(MAIN, row["path"])
        require((None if audited is None else sha_bytes(audited)) == row["audited"], f"Audited source drift: {row['path']}")
        require(current is not None and sha_bytes(current) == row["current"], f"Current-main source drift: {row['path']}")


def git_ref(ref: str) -> str:
    return subprocess.run(["git", "rev-parse", ref], check=True, stdout=subprocess.PIPE, text=True).stdout.strip()


def read_csv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        return list(reader.fieldnames or []), [dict(row) for row in reader]


def write_csv(path: Path, headers: list[str], rows: list[dict[str, str]]) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=headers, extrasaction="raise", lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)


def csv_bytes(headers: list[str], rows: list[dict[str, str]]) -> bytes:
    buffer = io.StringIO(newline="")
    writer = csv.DictWriter(buffer, fieldnames=headers, extrasaction="raise", lineterminator="\n")
    writer.writeheader()
    writer.writerows(rows)
    return buffer.getvalue().encode("utf-8")


def rebuild_reconciliation(payload: dict[str, Any], findings: dict[str, Any], manifest: dict[str, Any]) -> None:
    ids = {row["working_key"] for row in manifest["targets"]}
    rows = findings["findings"]
    exact = [(row["id"], feature) for row in rows for feature in row.get("feature_ids", []) if feature in ids]
    exact_findings = {finding for finding, _ in exact}
    p0p1 = {row["id"] for row in rows if row["priority"] in {"P0", "P1"}}
    decisions = [decision for row in rows for decision in row.get("feature_link_reconciliation", {}).get("decisions", [])]
    prior = payload["current_final_id_link_summary"]
    payload["generated_at"] = GENERATED_AT
    payload["status"] = "current_904_literal_link_reconciliation_partial_runtime_unverified"
    payload["current_final_id_link_summary"] = {
        "literal_links": len(exact),
        "literal_targets": len({feature for _, feature in exact}),
        "explicitly_re_adjudicated_links": prior["explicitly_re_adjudicated_links"] + 1,
        "explicitly_re_adjudicated_findings": sorted(set(prior["explicitly_re_adjudicated_findings"]) | {FINDING_ID}),
        "findings_with_literal_exact_current_id": len(exact_findings),
        "findings_without_literal_exact_current_id": len(rows) - len(exact_findings),
        "p0_p1_with_literal_exact_current_id": len(p0p1 & exact_findings),
        "p0_p1_without_literal_exact_current_id": len(p0p1 - exact_findings),
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
            "literal_current_feature_ids": [feature for feature in row.get("feature_ids", []) if feature in ids],
            "reconciliation": row.get("feature_link_reconciliation", {}),
        }
        for row in rows
    ]
    require(payload["counts"] == {"findings":100,"total_links":282,"findings_with_uncertainty":32,"findings_without_literal_exact_current_id":0,"route_intersection_groups":48,"unique_page_intersection_groups":10,"one_to_one_groups":104}, f"Reconciliation drift: {payload['counts']}")
    require(payload["current_final_id_link_summary"]["literal_links"] == 183, "Literal-link drift")
    require(payload["current_final_id_link_summary"]["literal_targets"] == 150, "Literal-target drift")
    require(payload["current_final_id_link_summary"]["explicitly_re_adjudicated_links"] == 132, "Re-adjudication drift")


require(git_ref("HEAD") == AUDITED, "Audited checkout drift")
require(git_ref("origin/main") == MAIN, "Current-main ref drift")

if any(P[name].exists() for name in ("pass8", "summary")):
    require(all(P[name].exists() for name in ("pass8", "summary")), "Partial Security Devices output set")
    findings = load(P["findings"])
    row = next(item for item in findings["findings"] if item["id"] == FINDING_ID)
    require(FEATURE_ID in row["feature_ids"] and row["remediation"]["status"] == "in_progress" and row["remediation"].get("completed_at") is None, "Existing finding state drift")
    summary = load(P["summary"])
    for key in ("findings", "reconciliation", "visual", "pass8"):
        require(summary["outputs"][key] == pin(P[key]), f"Existing output drift: {key}")
    pointer = load(P["pointer"])
    for key, path in (("findings",P["findings"]),("finding_link_reconciliation",P["reconciliation"]),("visual_matrix",P["visual"]),("pass8_security_devices",P["pass8"]),("security_devices_alerts_events_verification_generation_summary",P["summary"])):
        require(pointer["artifacts"][key] == pin(path), f"Pointer drift: {key}")
    print(json.dumps({"status":"already_applied","pass8":pin(P["pass8"]),"summary":pin(P["summary"])}, indent=2))
    raise SystemExit

for name, expected in PRE.items():
    require(sha_file(P[name]) == expected, f"Input SHA drift: {name}")
input_records = {name:{"path":rel(P[name]),"sha256":expected,"bytes":P[name].stat().st_size} for name,expected in PRE.items()}
verify_sources()

manifest = load(P["manifest"])
benchmark = load(P["benchmark"])
inventory = load(P["inventory"])
findings = load(P["findings"])
reconciliation = load(P["reconciliation"])
pointer = load(P["pointer"])
require(any(row["working_key"] == FEATURE_ID for row in manifest["targets"]), "Feature ID missing")
require(benchmark["summary"]["eligible_total"] == 500 and benchmark["summary"]["completion_unproved"]["total"] == 404, "Benchmark drift")
route = next(row for row in inventory["routes"] if row["route_id"] == "ROUTE-2529")
page = next(row for row in inventory["pages"] if row["page_id"] == "PAGE-0814")
require(route["working_canonical_feature_ids"] == [FEATURE_ID], "Route ownership drift")
require(page["working_canonical_feature_ids"] == [FEATURE_ID], "Page ownership drift")

matches = [row for row in findings["findings"] if row["id"] == FINDING_ID]
require(len(matches) == 1, "ARCH-P0-B cardinality drift")
finding = matches[0]
require(finding["priority"] == "P0" and finding["remediation"]["status"] == "fixed_pending_verification", "ARCH-P0-B pre-state drift")
require(FEATURE_ID not in finding["feature_ids"], "ARCH-P0-B already linked without artifacts")

finding["feature_ids"] = sorted(set(finding["feature_ids"]) | {FEATURE_ID})
finding["route_url"]["route_names"] = sorted(set(finding["route_url"].get("route_names", [])) | {"security-devices.alerts-events"})
finding["route_url"]["route_paths"] = sorted(set(finding["route_url"].get("route_paths", [])) | {"security-devices/alerts-events"})
finding["route_url"]["summary"] = "Existing exact device routes plus ROUTE-2529; the Alerts & Events route is the residual temporal-custody verification failure."
finding["frontend_anchor"]["page_files"] = sorted(set(finding["frontend_anchor"].get("page_files", [])) | {"resources/js/pages/security-devices/alerts-events.tsx"})
finding["frontend_anchor"]["summary"] = "PAGE-0814 is the exact routed Alerts & Events owner for the residual temporal-custody verification failure; PAGE-0816 is not inherited."
new_anchors = [
    "app/Domain/SecurityDevices/Http/Controllers/AlertsEventsController.php:18-24,30-39,98-109,125-150",
    "app/Domain/SecurityDevices/Http/Controllers/ReportsController.php:38-57,118-166",
    "app/Domain/SecurityDevices/Services/SecurityDevicesAccessService.php:530-590",
    "routes/security-devices.php:47-50,328-330",
    "database/seeders/SecurityDevicesPermissionsSeeder.php:95-123,136-160",
]
finding["backend_anchors"] = sorted(set(finding.get("backend_anchors", [])) | set(new_anchors))
finding["evidence"]["anchors"] = sorted(set(finding["evidence"].get("anchors", [])) | set(new_anchors))
finding["evidence"]["existing_tests"] = sorted(set(finding["evidence"].get("existing_tests", [])) | {"AlertsEventsControllerTest covers an all-Sites happy path only", "Temporal-custody service tests do not exercise ROUTE-2529"})
finding["current_behavior"] = "The merged temporal-custody core exists, and ReportsController applies occurred-at custody scope. AlertsEventsController still scopes historical DeviceEvent rows, totals and filter vocabularies through devices visible under current custody. After transfer, the receiving Site can see pre-transfer events and the former Site loses its historical events. This is a current-main static verification failure; endpoint runtime behavior was not executed."
finding["current_workflow"]["summary"] = "Fresh Security Devices Pass-8 source challenge found one unconverted historical-event reader after the merged temporal-custody remediation; no endpoint task was executed."
finding["current_workflow"]["failure_sequence"] = "A device records event A under Site A, transfers to Site B, then the current-device scope exposes event A to B and removes it from A instead of evaluating custody at occurred_at."
finding["current_workflow"]["boundary"] = "Historical DeviceEvent rows, totals, severity counts, filter vocabularies, direct device filters and pagination must use temporal custody; explicit global access is separate."
finding["current_workflow"]["completion_evidence"] = "Current-main source mismatch against the canonical temporal helper; no runtime, browser, persisted task or remediation completion evidence."
finding["remediation"]["status"] = "in_progress"
finding["remediation"]["completed_at"] = None
finding["remediation"]["note"] = "Historical remediation evidence remains: the prior task, branch, commit and merged temporal-custody core are preserved. Fresh current-main static verification found ROUTE-2529 still using current-device visibility for historical events, so the finding is reopened in progress and no longer carries a completion timestamp. Acceptance is incomplete; endpoint regression, runtime and browser verification remain outstanding."
finding["acceptance_criteria"] = list(dict.fromkeys(finding["acceptance_criteria"] + [
    "Apply the canonical occurred-at temporal custody scope to Alerts & Events rows, totals, severity counts, event-type/source vocabularies, search, pagination and forced device filters.",
    "With Site A custody/event A then transfer to Site B/event B, A sees only A and B only B; no-Site fails closed, quarantine is explicit, and a separately authorised global positive is covered.",
]))
finding["missing_tests"] = list(dict.fromkeys(finding["missing_tests"] + [
    "ROUTE-2529 transferred-device historical event rows/counts/vocabularies/direct-filter denial",
    "Alerts & Events no-Site fail-closed, quarantine semantics and explicit global positive",
]))
finding["feature_link_reconciliation"]["decisions"].append({
    "legacy_family_id":"independent-pass8-security-devices-alerts-events-2026-08-21",
    "method":"source-proven exact current target route/backend intersection",
    "feature_ids":[FEATURE_ID],
    "route_hits":[{"feature_id":FEATURE_ID,"route_id":"ROUTE-2529","route_name":"security-devices.alerts-events","route_path":"security-devices/alerts-events","evidence":"Current AlertsEventsController uses current-device visibility while the canonical ReportsController path applies occurred-at temporal custody."}],
    "page_hits":[{"page_id":"PAGE-0814","feature_ids":[FEATURE_ID]}],
    "source_anchors":new_anchors,
    "evidence":"Fresh source-only Pass-8 verification failure; no new finding and no runtime/browser/remediation credit.",
    "audited_commit":AUDITED,
    "current_main_static_cross_check":MAIN,
})

status_counts = Counter(row.get("remediation", {}).get("status") for row in findings["findings"])
require(status_counts == Counter({"open":51,"in_progress":17,"fixed_pending_verification":30,"verified":2}), f"Remediation count drift: {status_counts}")
findings["remediation_snapshot"] = {
    "as_of": GENERATED_AT,
    "origin_main_commit": MAIN,
    "status_counts": {key: status_counts[key] for key in ("open","in_progress","fixed_pending_verification","verified")},
    "boundary": "Current row-derived status snapshot. The audited commit remains immutable; the ARCH-P0-B residual is source-verified but runtime/browser verification and remediation completion remain outstanding.",
}
links = findings["counts"]["feature_link_reconciliation"]
links.update({
    "benchmark_mapping":{"eligible":500,"verified_benchmark":411,"documented_no_credible_match":89,"completion_unproved":404},
    "findings":100,"total_links":282,"literal_exact_current_links":183,"literal_exact_current_targets":150,
    "findings_with_literal_exact_current_id":100,"p0_p1_with_literal_exact_current_id":88,"p0_p1_without_literal_exact_current_id":0,
    "findings_with_uncertainty":32,"findings_without_literal_exact_current_id":0,"route_intersection_groups":48,"unique_page_intersection_groups":10,
})
findings["audit_status"] = "Blocked—not comprehensive or complete. The canonical 904-target register is current (790H/111D/3M). Benchmark/NCM completion credit is 500/904; 100 findings remain, and ARCH-P0-B is in progress after a source-confirmed residual endpoint gap. Runtime remains unexecuted."

rebuild_reconciliation(reconciliation, findings, manifest)

headers, visual_rows = read_csv(P["visual"])
require(csv_bytes(headers, visual_rows) == P["visual"].read_bytes(), "Visual CSV round-trip drift")
visual_matches = [row for row in visual_rows if row["visual_id"] == VISUAL_ID]
require(len(visual_matches) == 1, "VIS-020827 cardinality drift")
visual = visual_matches[0]
require(visual["feature_id"] == FEATURE_ID and visual["classification"] == "Not safely reproducible" and not visual["screenshot"], "VIS-020827 state drift")
visual["finding_ids"] = ";".join(sorted(set(filter(None, visual["finding_ids"].split(";"))) | {FINDING_ID}))

pass8 = {
    "schema_version":"1.0.0",
    "artifact":"pass8-security-devices-904-2026-08-21",
    "generated_at":GENERATED_AT,
    "audited_commit":AUDITED,
    "current_main_static_cross_check":MAIN,
    "status":"source_only_existing_finding_verification_failure_no_new_finding_no_completion_credit",
    "module":{"id":"SECURITY_DEVICES","targets":37,"classes":{"H":36,"D":0,"M":1},"benchmark_decided":13,"benchmark_unproved":24},
    "finding":{"id":FINDING_ID,"priority":"P0","status":"in_progress","feature_id":FEATURE_ID,"route_id":"ROUTE-2529","page_id":"PAGE-0814","co_link_preserved":"ARCH-P0-C"},
    "source_pins":SOURCE_PINS,
    "verification_failure":{"root":"Historical event scope uses current device visibility rather than occurred-at temporal custody.","failure_sequence":finding["current_workflow"]["failure_sequence"],"canonical_comparison":"ReportsController calls SecurityDevicesAccessService::applyTemporalEventCustodyScope; AlertsEventsController does not.","runtime_executed":False,"browser_executed":False},
    "pass_reconciliation":{"P1":"37/37 static identities reviewed","P2":"0/36 representative tasks executed","P3":"13/37 benchmark/NCM decided; 24 unproved","P4":"0/36 representative visual/security tasks executed","P5":"37/37 static architecture reviewed; runtime effects 0","P6":"4/37 target rows linked; official propositions frame risk only","P7":"4/37 target rows linked; tests executed 0","P8":"fresh module challenge 1; module completion 0/37"},
    "visual":{"visual_id":VISUAL_ID,"classification":"Not safely reproducible","finding_association_added":True,"runtime_or_visual_credit_delta":0},
    "count_delta":{"findings":0,"P0":0,"P1":0,"P2":0,"finding_links":1,"literal_links":1,"literal_targets":0,"runtime":0,"browser":0,"benchmark":0,"remediation_completion":0,"overall_completion":0},
    "completion_boundary":"BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE; no product, runtime, browser, test-execution, benchmark, release, remediation-completion or overall-audit completion credit.",
}

save(P["pass8"], pass8)
save(P["findings"], findings)
save(P["reconciliation"], reconciliation)
write_csv(P["visual"], headers, visual_rows)

outputs = {key: pin(P[key]) for key in ("findings","reconciliation","visual","pass8")}
summary = {
    "schema_version":"1.0.0",
    "artifact":"final-904-security-devices-alerts-events-verification-generation-summary",
    "generated_at":GENERATED_AT,
    "audited_commit":AUDITED,
    "current_main_static_cross_check":MAIN,
    "status":"existing_p0_verification_failure_recorded_no_new_finding_zero_runtime_credit",
    "inputs":input_records,
    "outputs":outputs,
    "counts":{"findings":{"total":100,"P0":21,"P1":67,"P2":12},"remediation":{"open":51,"in_progress":17,"fixed_pending_verification":30,"verified":2},"links":{"total":282,"literal":183,"literal_targets":150},"benchmark":{"eligible":500,"unproved":404}},
    "downstream_refresh":{"required_order":["generators/refresh-current-904-summaries.py","generators/refresh-audit-dashboard-data.py","generators/finalize-current-904-validation.py"]},
    "credit_boundary":{"runtime":0,"browser":0,"benchmark":0,"remediation_completion":0,"overall_completion":0},
    "idempotence":"A successful immediate second run before downstream regeneration validates all direct outputs and pointer records without writing. Later downstream summary refresh intentionally rewrites findings and supersedes this direct-output equality; partial failure is stop-only.",
}
save(P["summary"], summary)
pointer["generated_at"] = max(str(pointer.get("generated_at", "")), GENERATED_AT)
pointer["artifacts"].update({
    "findings":outputs["findings"],
    "finding_link_reconciliation":outputs["reconciliation"],
    "visual_matrix":outputs["visual"],
    "pass8_security_devices":outputs["pass8"],
    "security_devices_alerts_events_verification_generation_summary":pin(P["summary"]),
})
pointer["completion_status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"
pointer["runtime_credit_delta"] = 0
save(P["pointer"], pointer)
print(json.dumps({"status":"generated","outputs":outputs,"summary":pin(P["summary"]),"pointer":pin(P["pointer"])}, indent=2))
