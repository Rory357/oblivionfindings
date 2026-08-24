#!/usr/bin/env python3
"""Record the independently reviewed Finance quote-conversion Pass-8 finding."""

from __future__ import annotations

import copy
import hashlib
import json
import subprocess
from pathlib import Path
from typing import Any

AUDIT = Path(__file__).resolve().parents[1]
SOURCE = AUDIT / "evidence" / "source"
AUDITED = "081ef198f9f992f224e8c0c9fba33df33dde40be"
MAIN = "20ad5cef0aacb3d055e685d2f8b7b583cb8d78f4"
GENERATED_AT = "2026-08-22T00:45:00+12:00"
FINDING_ID = "FIN-QUOTE-CONVERSION-01"
FEATURE_IDS = ["CAP-FIN-QUOTE-LIFECYCLE", "CAP-FIN-QUOTE-AGREEMENT-CONVERSION", "CAP-FIN-QUOTE-INVOICE-CONVERSION"]
ROUTE_IDS = ["ROUTE-0664", "ROUTE-0665", "ROUTE-0666", "ROUTE-0667", "ROUTE-0669"]
PAGE_IDS = ["PAGE-0185", "PAGE-0186"]

P = {
    "manifest": SOURCE / "working-capability-manifest-904.json",
    "benchmark": SOURCE / "benchmark-final-904-mapping.json",
    "inventory": AUDIT / "inventory-904.json",
    "findings": AUDIT / "findings.json",
    "reconciliation": SOURCE / "finding-link-reconciliation.json",
    "official_map": SOURCE / "official-nz-finding-proposition-map.json",
    "pointer": SOURCE / "canonical-audit-inputs.json",
    "pass8": SOURCE / "pass8-finance-quote-conversion-904-2026-08-21.json",
    "summary": SOURCE / "final-904-finance-quote-conversion-generation-summary.json",
}
PRE = {
    "manifest": "ffca48609deab9a8938105c857786594a9a5431c31efe329ef4288da6165358f",
    "benchmark": "dad3d1d194c83e2556bb3fa7b2c6d86ca8a1fe9a21bbae58e73a9eda6769c37b",
    "inventory": "80f0d84017fadd27a66256e633956cd44591b243591490c6dac4d4b81fa99f4c",
    "findings": "9008d693537212e58d2db84646295eb1af7a1fdf363c18d9bbdb2b63fb9f003b",
    "reconciliation": "d50e4a61101d8ad01044ea32cb392f429f98873f430b4cf197e9c80e1e1e2738",
    "official_map": "adc6b10d2f6a8903b0618ed3ba35bb5451d036317c2507f21471cd32629e49ac",
    "pointer": "9818d46ddf5149a469308de61dbe2d6e31eb15d00b73e5b23492c07aaf0d7012",
}
SOURCES = [
    {"path":"app/Domain/Finance/Http/Controllers/QuoteController.php","baseline":"e9056f74fad0468db720890138be29a2f4d2b2e976c29d619db2b927dda1ba9d","current":"b0f935d2bd5f50c40950f94d647efce6db2db1388ccc2c937aa0e89265a1afbb","loci":"L240-L300,L303-L333,L341-L412"},
    {"path":"database/migrations/2026_03_23_000400_create_service_agreements_table.php","baseline":"28d343b406bd1674bc20c03217abcf3daa8f8c9a2454f5c563ffd3f98f3a4021","current":"28d343b406bd1674bc20c03217abcf3daa8f8c9a2454f5c563ffd3f98f3a4021","loci":"L11-L30"},
    {"path":"database/migrations/2026_03_23_004200_create_quotes_table.php","baseline":"17de1742845bdf0483a9070537b6e8f78e847af3194f9039cacb9a3465c820fe","current":"17de1742845bdf0483a9070537b6e8f78e847af3194f9039cacb9a3465c820fe","loci":"L11-L39"},
    {"path":"database/migrations/2026_03_28_004100_create_fin_invoices_table.php","baseline":"c41d8e37e7ca9a450f1eea209f0148829c46a6c5b050204646491b9305f619b5","current":"c41d8e37e7ca9a450f1eea209f0148829c46a6c5b050204646491b9305f619b5","loci":"L11-L47"},
    {"path":"database/migrations/2026_05_02_100000_add_operations_metadata_to_fin_invoices.php","baseline":"b537b2df53d0307c8eba8464db0d572eb1470232e2d2a03bbef2991818e4a8e8","current":"b537b2df53d0307c8eba8464db0d572eb1470232e2d2a03bbef2991818e4a8e8","loci":"L18-L23"},
    {"path":"database/migrations/2026_06_14_070000_add_converted_to_invoice_id_to_quotes.php","baseline":"c802d42718dda3ff3e412d87d20a5cc6a1cbd7c1fb09f23e4a696702e602e293","current":"c802d42718dda3ff3e412d87d20a5cc6a1cbd7c1fb09f23e4a696702e602e293","loci":"L15-L17"},
    {"path":"app/Models/ServiceAgreement.php","baseline":"9e61af697137ab29ae3dee7202fcfea569817995eb503ebaeb2a1f88ed2bca56","current":"144f234ca522b08e248c1392a4de58778e37bf594aa6bc5021e09bbb5f94f6f7","loci":"fillable agreement_type and audit actor; no default"},
    {"path":"app/Models/ServiceAgreementLineItem.php","baseline":"cabad0ecdf0fa5890851b5f1de551a7d16d7a060acbb774feac22c0aeb0f65c6","current":"e700bb99ba81f9337903c5b7510008202196cb651cb2aca2efb6920c2a007529","loci":"budget_allocated mapping"},
    {"path":"app/Domain/Finance/Models/FinInvoice.php","baseline":"f958517c82b84db101f17474ed02e0c05eed84fe34a27158d62d189ff424d4f8","current":"f958517c82b84db101f17474ed02e0c05eed84fe34a27158d62d189ff424d4f8","loci":"L114-L123"},
    {"path":"tests/Feature/Finance/QuoteToInvoiceConversionTest.php","baseline":"ab09a2bb2d8d4689833e9c3a7d36cc3b326ca7887070e77d7b2162a52b31fa72","current":"ef173509e5b9a9a4bd4de0795ef31f2f7b1452db56512de37802c8bf1e789842","loci":"current L67-L95,L121-L147"},
    {"path":"resources/js/pages/finance/quotes/Show.tsx","baseline":"a15ca5bcddf223c348b3fd7a3dc973bed02bd6454c02eddb4e55ab4da570edc7","current":"add64ac7301431a1caaee5ad8c726e9c90d5ffa2c172341e48254b5f1e77837a","loci":"current L97-L139"},
]

def sha_bytes(data: bytes) -> str: return hashlib.sha256(data).hexdigest()
def sha_file(path: Path) -> str: return sha_bytes(path.read_bytes())
def load(path: Path) -> Any: return json.loads(path.read_text(encoding="utf-8"))
def save(path: Path, value: Any) -> None: path.write_text(json.dumps(value, ensure_ascii=False, indent=2)+"\n",encoding="utf-8",newline="\n")
def require(ok: bool, message: str) -> None:
    if not ok: raise RuntimeError(message)
def rel(path: Path) -> str: return path.relative_to(AUDIT).as_posix()
def pin(path: Path) -> dict[str, Any]: return {"path":rel(path),"sha256":sha_file(path),"bytes":path.stat().st_size}
def git_bytes(ref: str, path: str) -> bytes:
    return subprocess.run(["git","show",f"{ref}:{path}"],check=True,stdout=subprocess.PIPE).stdout
def verify_sources() -> list[dict[str, Any]]:
    out=[]
    for row in SOURCES:
        require(sha_bytes(git_bytes(AUDITED,row["path"]))==row["baseline"],f"Baseline drift: {row['path']}")
        require(sha_bytes(git_bytes(MAIN,row["path"]))==row["current"],f"Current-main drift: {row['path']}")
        out.append(copy.deepcopy(row))
    return out

def rebuild_reconciliation(payload, findings, manifest):
    ids={row["working_key"] for row in manifest["targets"]}; rows=findings["findings"]
    exact=[(row["id"],feature) for row in rows for feature in row.get("feature_ids",[]) if feature in ids]
    exact_findings={f for f,_ in exact}; p0p1=[row for row in rows if row["priority"] in {"P0","P1"}]
    decisions=[d for row in rows for d in row.get("feature_link_reconciliation",{}).get("decisions",[])]
    prior=payload["current_final_id_link_summary"]
    payload["generated_at"]=GENERATED_AT; payload["status"]="current_904_literal_link_reconciliation_partial_runtime_unverified"
    payload["current_final_id_link_summary"]={"literal_links":len(exact),"literal_targets":len({x for _,x in exact}),"explicitly_re_adjudicated_links":prior["explicitly_re_adjudicated_links"]+3,"explicitly_re_adjudicated_findings":sorted(set(prior["explicitly_re_adjudicated_findings"])|{FINDING_ID}),"findings_with_literal_exact_current_id":len(exact_findings),"findings_without_literal_exact_current_id":len(rows)-len(exact_findings),"p0_p1_with_literal_exact_current_id":len({row["id"] for row in p0p1}&exact_findings),"p0_p1_without_literal_exact_current_id":len(p0p1)-len({row["id"] for row in p0p1}&exact_findings),"complete":False}
    payload["counts"]={"findings":len(rows),"total_links":sum(len(row.get("feature_ids",[])) for row in rows),"findings_with_uncertainty":sum(bool(row.get("feature_link_reconciliation",{}).get("uncertainties")) for row in rows),"findings_without_literal_exact_current_id":len(rows)-len(exact_findings),"route_intersection_groups":sum(bool(d.get("route_hits")) for d in decisions),"unique_page_intersection_groups":sum(bool(d.get("page_hits")) for d in decisions),"one_to_one_groups":sum("one-to-one" in str(d.get("method","")).lower() for d in decisions)}
    payload["findings"]=[{"finding_id":row["id"],"feature_ids":row.get("feature_ids",[]),"literal_current_feature_ids":[x for x in row.get("feature_ids",[]) if x in ids],"reconciliation":row.get("feature_link_reconciliation",{})} for row in rows]
    require(payload["counts"]=={"findings":100,"total_links":281,"findings_with_uncertainty":32,"findings_without_literal_exact_current_id":0,"route_intersection_groups":47,"unique_page_intersection_groups":9,"one_to_one_groups":104},f"Reconciliation drift: {payload['counts']}")
    require(payload["current_final_id_link_summary"]["literal_links"]==182 and payload["current_final_id_link_summary"]["literal_targets"]==150 and payload["current_final_id_link_summary"]["p0_p1_with_literal_exact_current_id"]==88,"Literal-link drift")

def finding_payload(template):
    row=copy.deepcopy(template); zeros={k:0 for k in ["discoverability","comprehension","learnability","efficiency","error_prevention","recovery","accessibility","safety_and_trust","consistency","cross_module_continuity"]}
    anchors=[x["path"]+":"+x["loci"] for x in SOURCES]
    row.update({
        "id":FINDING_ID,"feature_ids":FEATURE_IDS,"passes":["P1","P2","P4","P5","P6","P7","P8"],"module":"Finance and funding","submodule":"Quote lifecycle and agreement/invoice conversion",
        "actor_and_job":"A Site-scoped actor with finance.ar.manage converts an accessible accepted quote exactly once under an owner-approved destination policy.",
        "route_url":{"summary":"Five exact quote mutations establish lifecycle and conversion boundaries.","route_names":["finance.quotes.update","finance.quotes.accept","finance.quotes.convert","finance.quotes.convert-to-invoice","finance.quotes.send"],"route_paths":["finance/quotes/{quote}","finance/quotes/{quote}/accept","finance/quotes/{quote}/convert","finance/quotes/{quote}/convert-to-invoice","finance/quotes/{quote}/send"]},
        "frontend_anchor":{"summary":"Index and Show make state actions reachable; accepted-only buttons do not protect direct routes.","page_files":["resources/js/pages/finance/quotes/Index.tsx","resources/js/pages/finance/quotes/Show.tsx"],"audited_commit":AUDITED},
        "visual_context":{"visual_id":"PAGE-0185 and PAGE-0186 source relations only","classification":"Source-inferred","role":"Site-scoped finance.ar.manage; runtime unavailable","site_scope":"Current accessibleQuotes scope is source-present and outside the defect root","viewport":"Not safely reproduced","state":"Quote lifecycle and conversion source trace","pattern_type":"backend/source finding","component_anchor":"finance/quotes pages","screenshot_reference":"None—no conversion or browser action is claimed","internal_baseline":"One locked, state-valid, replay-safe conversion owner"},
        "pattern_implementation":"Static controller/model/schema/test/UI review at audited and current-main commits; no quote, agreement or invoice was created or changed.","backend_anchors":anchors,
        "current_behavior":"Generic update, send and accept can rewrite terminal quote state. Agreement conversion omits required agreement_type and is expected from declared schema to fail at insert; if admitted, its header/lines/link writes are non-atomic and lack replay identity. Invoice writes are transactionally grouped, but replay/state is checked beforehand on an unlocked quote and the source tuple is not unique, so stale overlap is not safely idempotent. Current Site scoping and actor audit exist and are not the root.",
        "current_workflow":{"summary":"Source-reviewed five-route/two-page transition boundary; no representative conversion, failure or race was executed.","failure_sequence":"A direct terminal rewrite is accepted, agreement conversion meets the required-field mismatch, or overlapping conversions pass stale pre-transaction checks and attempt conflicting destinations.","boundary":"Locked quote state, owner-approved destination policy, durable source identity, atomic agreement fields/lines/link and collision-safe invoice numbering.","completion_evidence":"Static audited/current source only; no duplicate, partial agreement, exploit or financial loss is claimed."},
        "ease_evidence":{"validation_status":"Blocked—source finding retained; no representative runtime or ten-dimension validation executed","evidence_basis":"Static source and existing-test trace only","current_scores":zeros,"friction":{"completion_time":"Not measured","step_count":"Not measured","required_field_count":"Owner decision pending","decision_count":"Accounting owner must define allowed destinations","context_switches":"Not measured","dead_ends":"Agreement path is structurally expected to fail","recovery_path":"Serialize conversion and reconcile quote destination links before retry."},"target_scores":{"all_dimensions":4,"safety_critical_error_prevention_and_trust":5},"independent_review":"Independent review accepted P1 and corrected transaction, race, Site, actor-audit and observed-impact wording."},
        "evidence":{"anchors":anchors,"existing_tests":["Current test covers invoice happy path and completed sequential replay","Current test covers foreign-Site create/show only","No agreement, invalid-state, rollback or concurrent overlap case exists"],"tests_executed":False,"browser_claim_limit":"No quote, invoice, agreement, permission, viewport or persisted outcome was exercised."},
        "problem_root_cause":"Quote lifecycle and conversion mutations are separate controller writes rather than one locked aggregate transition: generic update/send/accept can rewrite terminal state; agreement conversion omits a required schema field and, if admitted, is non-atomic and non-idempotent; invoice conversion is atomic for its writes but checks replay/state before the transaction on an unlocked quote and has no unique source identity, so stale overlapping requests are not safely idempotent.",
        "impact":"The declared agreement path is blocked and stale overlap can produce errors, duplicate/repointed destinations or contradictory terminal state, undermining billing, funding and audit reconciliation. Runtime occurrence or loss remains unverified.",
        "benchmark":{"selected":"No new benchmark credit from this finding","url_and_sha":"","verified_behavior":"Finding evidence is independent of benchmark completion.","outcome":"Benchmark mapping unchanged","no_match_evidence":"Not an NCM adjudication."},
        "neutral_requirements":"Use one authorised locked quote transition with an explicit state/destination policy, durable replay identity, complete agreement fields and atomic destination linkage.",
        "better_oblivion_design":"Preserve current routes/pages while routing lifecycle and conversion through one accessible-scope, transaction, lock and idempotency owner.",
        "target_ease":{"scores":{"all_dimensions":4,"safety_critical_error_prevention_and_trust":5},"measurable_outcome":"One accepted quote converts to each owner-permitted destination exactly once; stale or invalid requests return a stable conflict with zero partial effect."},
        "cross_module_effects":"Preserve current Client Site scope, invoice journaling, agreement totals, reference numbering and audit owners; no target or source-family inheritance.",
        "rbac_privacy":"Preserve accessibleQuotes concealment. An explicit global-Site positive still requires finance.ar.manage; global scope never replaces action authority.",
        "priority":"P1","effort":"M","dependencies_sequence":"Accounting owner defines single-versus-dual destinations, agreement_type, totals/GST and correction rules; then implement locked transitions and isolated tests.","proposed_owner":"Finance Product Owner, Accounting Owner and Backend Assurance","confidence":"High for static state/schema/locking gaps; runtime occurrence and impact remain unverified",
        "source_boundary":"Internal source proves the workflow-integrity gap. HISF separation/logging and Accounting ownership frame risk only; no legal/accounting certification claim is made.",
        "interim_safeguard":"Disable agreement conversion; restrict invoice conversion to a reviewed Finance process and reconcile existing source/destination links before retry.",
        "acceptance_criteria":["One command reauthorizes finance.ar.manage and existing Client Site scope, begins a transaction, reselects and locks the quote, and loads lines inside it.","Accounting owner defines and the server enforces the state map and whether agreement/invoice destinations are exclusive or explicitly dual.","Agreement creation supplies validated agreement_type, totals/GST, actor/time and line values; header, lines and quote link commit or roll back together.","Agreement and invoice destinations have durable quote-origin uniqueness; same-payload replay converges and changed payload conflicts.","Invoice source tuple is unique and numbering uses a race-safe generator with backfill/floor handling.","Update/send/accept versus convert and same/cross-destination overlap serialize to one authoritative outcome.","Foreign-Site direct IDs remain concealed and explicit global scope still requires the action permission."],
        "missing_tests":["Agreement happy path and required fields","Invalid/terminal direct transitions","Same-destination and cross-destination concurrency","Update/send/accept-versus-convert races","Header/line/link failure rollback","Source uniqueness and invoice-number first-use concurrency","Foreign-Site concealment and explicit-global positive","Representative desktop/mobile conflict recovery"],
        "validation_plan":["Add current-main policy/feature tests for the state and destination contract","Use disposable MySQL for true overlap, rollback and constraint evidence","Backfill/audit existing source links before unique constraints","Exercise accepted-only buttons, double-click lockout and conflict recovery after remediation","Retain open status until merged-to-main and exact runtime evidence are canonical"],
        "official_sources":[{"id":"NZ-HISO-10029-2022","title":"HISO 10029:2022 Health Information Security Framework","authority":"Health New Zealand / HISO","url":"https://static.info.content.health.nz/docs/HISO/HISO%2010029%20Health%20Information%20Security%20Framework.pdf","supporting_url":"https://www.healthnz.govt.nz/health-professionals/guidance-standards/topic/data-and-standards/health-information-standards/approved-health-information-standards/information-governance","inspected_date":"2026-08-12"}],
        "statement_types":{"source":"Lifecycle rewrite, agreement schema mismatch/non-atomicity and invoice stale pre-transaction replay gap are source-observed.","official_source":"HISF-SOD/HISF-LOG and OWNER-ACCOUNTING frame assurance only.","inference":"Duplicate/error/reconciliation impact is bounded inference; no deployed overlap or loss was observed.","specialist_decision":"P1 priority, destination policy, agreement_type and accounting semantics require Finance ownership."},
        "official_source_proposition_keys":["HISF-SOD","HISF-LOG","OWNER-ACCOUNTING"],
        "feature_link_reconciliation":{"method":"route-first: exact five quote mutation routes and controller/schema boundary; two pages corroborate without family inheritance","projection_status":"literal_current_904_manifest_links_present; runtime_and_remediation_unverified","legacy_feature_ids":[],"decisions":[{"legacy_family_id":"independent-pass8-finance-quote-conversion-2026-08-21","method":"source-proven exact current target route/backend intersection","feature_ids":FEATURE_IDS,"route_hits":ROUTE_IDS,"page_hits":[{"page_id":"PAGE-0185","feature_ids":[FEATURE_IDS[0]]},{"page_id":"PAGE-0186","feature_ids":FEATURE_IDS}],"source_anchors":anchors,"evidence":"Fresh Finance Pass-8 review traced state, schema, transaction, replay, Site drift, UI and tests without runtime credit.","audited_commit":AUDITED,"current_main_static_cross_check":MAIN}],"uncertainties":[{"reason_code":"accounting_destination_policy_and_runtime_unexecuted","detail":"Single-versus-dual destination, agreement_type/totals and correction semantics require Accounting ownership; runtime overlap was not executed.","smallest_next_evidence":"Owner decides the destination/state contract; after remediation run isolated agreement insert and true concurrency evidence."}]},
        "remediation":{"status":"open","note":"No isolated remediation branch or runtime verification is recorded."}
    })
    return row

def validate_existing():
    findings=load(P["findings"]); require(sum(row["id"]==FINDING_ID for row in findings["findings"])==1,"Finding duplication")
    pointer=load(P["pointer"]); require(pointer["artifacts"]["pass8_finance_quote_conversion"]==pin(P["pass8"]),"Pass8 pointer drift"); require(pointer["artifacts"]["finance_quote_conversion_generation_summary"]==pin(P["summary"]),"Summary pointer drift")
    summary=load(P["summary"])
    for key in ("findings","reconciliation","official_map","pass8"): require(summary["outputs"][key]==pin(P[key]),f"Existing output drift: {key}")
    print(json.dumps({"status":"idempotent_no_change","finding_id":FINDING_ID},indent=2))

if any(row["id"]==FINDING_ID for row in load(P["findings"])["findings"]): validate_existing(); raise SystemExit
for name,expected in PRE.items(): require(sha_file(P[name])==expected,f"Input SHA drift: {name}")
verified=verify_sources(); manifest,benchmark,inventory=load(P["manifest"]),load(P["benchmark"]),load(P["inventory"])
findings,reconciliation,official,pointer=load(P["findings"]),load(P["reconciliation"]),load(P["official_map"]),load(P["pointer"])
ids={row["working_key"] for row in manifest["targets"]}; require(set(FEATURE_IDS)<=ids,"Feature drift")
route_rows={row["route_id"]:row for row in inventory["routes"]}; expected={"ROUTE-0664":[FEATURE_IDS[0]],"ROUTE-0665":[FEATURE_IDS[0]],"ROUTE-0666":[FEATURE_IDS[1]],"ROUTE-0667":[FEATURE_IDS[2]],"ROUTE-0669":[FEATURE_IDS[0]]}
for rid,owners in expected.items(): require(route_rows[rid]["working_canonical_feature_ids"]==owners,f"Route owner drift: {rid}")
pages={row["page_id"]:row for row in inventory["pages"]}; require(pages["PAGE-0185"]["working_canonical_feature_ids"]==[FEATURE_IDS[0]] and set(pages["PAGE-0186"]["working_canonical_feature_ids"])==set(FEATURE_IDS),"Page owner drift")
fin=[row for row in benchmark["targets"] if row["canonical_module"]=="FINANCE"]; require(len(fin)==71 and sum(bool(row["completion_credit"]) for row in fin)==57,"Finance partition drift")

pass8={"schema_version":"1.0.0","artifact":"pass8-finance-quote-conversion-904-2026-08-21","generated_at":GENERATED_AT,"audited_commit":AUDITED,"current_main_static_cross_check":MAIN,"status":"source_only_pass8_challenge_no_module_completion_credit","module_selection":{"module":"FINANCE","targets":71,"classes":{"H":64,"D":7,"M":0},"benchmark_decided":57,"benchmark_unproved":14,"selection_reason":"Weighted unresolved source-risk/runtime screen selected Finance; all representative task/runtime gates remain zero."},"pass_reconciliation":{"P1":{"static_identity_reviewed":71,"denominator":71},"P2":{"representative_persisted_tasks_executed":0,"denominator":64},"P3":{"benchmark_or_ncm_decided":57,"denominator":71,"unproved":14},"P4":{"representative_security_and_visual_tasks_executed":0,"denominator":64},"P5":{"static_architecture_reviewed":71,"runtime_data_effects_verified":0,"denominator":71},"P6":{"fresh_exact_source_finding_official_links":3,"denominator":71,"boundary":"Official propositions frame risk only."},"P7":{"fresh_source_constraint_failure_links":3,"tests_executed":0,"denominator":71},"P8":{"fresh_module_challenge":1,"module_completion_credit":0,"denominator":71}},"new_finding":{"id":FINDING_ID,"priority":"P1","feature_ids":FEATURE_IDS,"route_ids":ROUTE_IDS,"page_ids":PAGE_IDS,"runtime_credit_delta":0,"browser_credit_delta":0,"completion_credit_delta":0},"source_chain":verified,"duplicate_boundary":"No current finding owns the exact quote lifecycle/conversion IDs, routes or schema/locking root; payment, settlement and spend findings are distinct aggregates.","wording_corrections":["Invoice writes are transactionally grouped; the defect is stale pre-transaction replay/state on an unlocked quote without unique source identity.","Agreement failure/partial/duplicate impacts are schema-based or conditional, not runtime-observed.","Current Site scope and actor audit are present and outside the root; destination exclusivity remains owner-defined."],"completion_boundary":"No runtime, browser, representative-user, remediation, release or overall completion credit."}
save(P["pass8"],pass8)
findings["findings"].append(finding_payload(next(row for row in findings["findings"] if row["id"]=="GOV-SPEND-AUTHORITY-01"))); findings["findings"].sort(key=lambda row:row["id"])
findings["counts"]["P1"]=67; links=findings["counts"]["feature_link_reconciliation"]
links.update({"benchmark_mapping":{"eligible":496,"verified_benchmark":407,"documented_no_credible_match":89,"completion_unproved":408},"findings":100,"total_links":281,"literal_exact_current_links":182,"literal_exact_current_targets":150,"findings_with_literal_exact_current_id":100,"p0_p1_with_literal_exact_current_id":88,"p0_p1_without_literal_exact_current_id":0,"findings_with_uncertainty":32,"findings_without_literal_exact_current_id":0,"route_intersection_groups":47,"unique_page_intersection_groups":9})
findings["audit_status"]="Blocked—not comprehensive or complete. The canonical 904-target register is current (790H/111D/3M). Benchmark/NCM completion credit is 496/904, visual final-ID linkage is 8,168/8,753, material-state linkage is 3,948/4,312, and 100 source-backed findings are retained. All 88/88 P0/P1 findings contain a literal current-manifest ID; runtime remains unexecuted."
rebuild_reconciliation(reconciliation,findings,manifest)
require(official["denominator"]==official["reviewed"]==57,"Official-map drift"); official["findings"].append({"finding_id":FINDING_ID,"proposition_keys":["HISF-SOD","HISF-LOG","OWNER-ACCOUNTING"]}); official["findings"].sort(key=lambda row:row["finding_id"]); official["denominator"]=official["reviewed"]=58; official["coverage_percent"]=100.0; official["owner_boundary_rows"]=sum(any(str(k).startswith("OWNER-") for k in row["proposition_keys"]) for row in official["findings"]); require(official["owner_boundary_rows"]==32,"Owner boundary drift")
save(P["findings"],findings); save(P["reconciliation"],reconciliation); save(P["official_map"],official)
outputs={key:pin(P[key]) for key in ("findings","reconciliation","official_map","pass8")}
summary={"schema_version":"1.0.0","artifact":"final-904-finance-quote-conversion-generation-summary","generated_at":GENERATED_AT,"audited_commit":AUDITED,"current_main_static_cross_check":MAIN,"finding_id":FINDING_ID,"status":"generated_open_p1_static_only_runtime_and_completion_blocked","inputs":{key:{"path":rel(P[key]),"sha256":value,"bytes":P[key].stat().st_size} for key,value in PRE.items()},"outputs":outputs,"counts":{"findings":{"total":100,"P0":21,"P1":67,"P2":12},"links":{"total":281,"literal":182,"literal_targets":150,"p0_p1_literal":88},"official_map":{"denominator":58,"reviewed":58,"owner_boundary_rows":32},"benchmark":{"eligible":496,"unproved":408}},"credit_boundary":{"runtime":0,"browser":0,"remediation":0,"completion":0},"idempotence":"A second run validates current outputs and pointer entries and performs no write."}
save(P["summary"],summary); pointer["generated_at"]=max(pointer.get("generated_at",""),GENERATED_AT); pointer["artifacts"].update({"findings":outputs["findings"],"finding_link_reconciliation":outputs["reconciliation"],"official_nz_finding_proposition_map":outputs["official_map"],"pass8_finance_quote_conversion":outputs["pass8"],"finance_quote_conversion_generation_summary":pin(P["summary"])}); pointer["completion_status"]="BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"; pointer["runtime_credit_delta"]=0; save(P["pointer"],pointer)
print(json.dumps({"status":"generated","finding_id":FINDING_ID,"outputs":outputs,"summary":pin(P["summary"]),"pointer":pin(P["pointer"])},indent=2))
