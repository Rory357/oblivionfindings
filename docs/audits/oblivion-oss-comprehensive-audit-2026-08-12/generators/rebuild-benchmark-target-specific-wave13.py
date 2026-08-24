#!/usr/bin/env python3
"""Build the independently reviewed thirteenth target-specific benchmark payload."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path


AUDIT = Path(__file__).resolve().parent.parent
SOURCE = AUDIT / "evidence" / "source"
MANIFEST_PATH = SOURCE / "working-capability-manifest-902.json"
MAPPING_PATH = SOURCE / "benchmark-final-902-mapping.json"
OUTPUT_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave13.json"
COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
GENERATED_AT = "2026-08-14T14:31:00+12:00"
PRE_WAVE_MAPPING_SHA = "f0f18e8c0ce902b4a9ea625aa911ce3dcdb50c1bca6a75391a04926cb34b67bf"


def load(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8-sig"))


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


REPOS = {
    "ERP": {"repo": "ERPNext", "official_repository_url": "https://github.com/frappe/erpnext", "commit_sha": "be176617cce09ef74a1be6e2f917586aa5f262ef", "spdx": "GPL-3.0", "license_locus": "license.txt:L1-L17", "license_sha256": "3972dc9744f6499f0f9b2dbf76696f2ae7ad8af9b23dde66d6af86c9dfb36986"},
    "FRAPPE": {"repo": "Frappe", "official_repository_url": "https://github.com/frappe/frappe", "commit_sha": "9b8d265b27a1dfb11c7aef21a533a127e14a0a5a", "spdx": "MIT", "license_locus": "LICENSE:L1-L20", "license_sha256": "bc6001a54ffcc4ab520424d7dbb85b293578efcdcb7d8f8055e00dddf942e5d7"},
    "OPENPROJECT": {"repo": "OpenProject", "official_repository_url": "https://github.com/opf/openproject", "commit_sha": "d5fa0433dce7f3edd48d0120736ac844fe3748d9", "spdx": "GPL-3.0-or-later", "license_locus": "publiccode.yml:L55", "license_sha256": "3802de5be385f9de812523fbd963f2e1a7f9abbc41c0f525e1103bc6b9255da5"},
}
for repo in REPOS.values():
    repo["edition_boundary"] = "Pinned official community repository source only; hosted services, paid/Enterprise/private extensions and unpinned refs are excluded. GPL source is behavioural evidence only."


# key, repository, exact loci(path, lines, SHA), neutral requirement,
# proven material slice and conservative parity limits.
DIRECT = [
    ("CAP-FIN-BILLING-OVERVIEW", "ERP", [("erpnext/accounts/doctype/sales_invoice/sales_invoice.json", "L257-L281,L368-L381,L1128-L1148,L1197-L1209,L1260-L1271,L1666-L1677", "55200df93a0a4678159a5c75da257305e8e673ebd72a1e9bb2655e031e5c8997")], "Represent customer, billing date, totals, outstanding amount and invoice state.", "ERPNext's Sales Invoice schema materially defines those billing-overview fields.", "No listing/query authorization, line-item, Site/client, GST or ledger parity."),
    ("CAP-FIN-BILLING-ENTRIES", "ERP", [("erpnext/accounts/doctype/sales_invoice/sales_invoice.json", "L723-L732", "55200df93a0a4678159a5c75da257305e8e673ebd72a1e9bb2655e031e5c8997"), ("erpnext/accounts/doctype/sales_invoice/sales_invoice.py", "L239-L266,L344-L350,L469-L482,L598-L604", "2a67cd28b62389cd321b4f6b9f46f887e4c1b07014d8956a248a4229427aa237")], "Persist required billing entries and govern selected billing/cancel transitions.", "ERPNext defines a required item table and material invoice validation/cancellation transitions.", "No agreement entitlement, pricing authority, corrections, audit or Site policy."),
    ("CAP-FIN-QUOTE-INVOICE-CONVERSION", "ERP", [("erpnext/selling/doctype/quotation/quotation.py", "L516-L564,L567-L598", "1dbd799c08bb98d7140d929c75351ebf33cfded1e9a4eea3596fc6037a2a596c")], "Convert a submitted quotation to an invoice/customer transaction while validating and mapping customer/items.", "ERPNext validates submitted quotations and maps customer and item data into the target document.", "No idempotency, approval, GST, agreement conversion or scope parity."),
    ("CAP-FIN-BANK-FEED-LOGS", "ERP", [("erpnext/accounts/doctype/bank_statement_import/bank_statement_import.py", "L71-L99,L142-L171,L251-L288", "3bee457b253bd54cf5fc080f3470f13762ef04d576753e687e41dc6d1dde0005")], "Record queued bank import, rollback/error state, aggregate outcome and permission-gated detailed logs.", "ERPNext provides queued import processing, failure state, aggregate outcomes and explicitly permission-gated detailed-log retrieval.", "The aggregate-status method itself has no explicit cited permission check; no provider, credential, reconciliation, retention or Site parity."),
    ("CAP-GOV-AUDIT-LOG-REVIEW", "FRAPPE", [("frappe/core/doctype/audit_trail/audit_trail.py", "L26-L105", "45787bdad3586f40946313f1552398bfcdb8b8e821a064294a33643adbccbf51")], "Review bounded revisions with field and child-row differences.", "Frappe materially compares document revisions and exposes field and row differences.", "No complete immutable log, privacy filtering, taxonomy or global coverage."),
    ("CAP-GOV-AUDIT-LOG-EXPORT", "FRAPPE", [("frappe/core/doctype/data_export/exporter.py", "L33-L74,L362-L398,L453-L466", "765f6b9a786aa2aa034b89244fccf10c2bdf0d8dfffe563ffc409aca3fb488f2")], "Export permitted audit data with an access record and explicit export authority.", "Frappe logs access, checks export permission and retrieves permitted data for export.", "No export approval, redaction, encryption, retention or evidence-pack parity."),
    ("CAP-GOV-DOCUMENT-DOWNLOAD", "FRAPPE", [("frappe/core/doctype/file/file.py", "L818-L832,L875-L900,L908-L947", "ca58ee702ec7ce84471f70eeed38c36b9e1af7b24cb11fce7c73b9976f461166"), ("frappe/core/api/file.py", "L127-L132", "f7a8170256e6d2a872d633a90589ba5d62248774a235d303f3b93c361aa5f409")], "Authorize a protected document download through its File and governing parent record.", "Frappe materially provides generic File authorization and protected-parent permission delegation.", "No category/version approval, malware, watermarking, retention or Site policy."),
    ("CAP-COMP-CALENDAR", "FRAPPE", [("frappe/desk/doctype/event/event.py", "L64-L106,L236-L255,L280-L297,L363-L413", "1f68c322219896d1a8f8a4015264a4a4a95d4fe93c6b4a54f512330dc4c7ca0d")], "Schedule and retrieve permission-aware compliance calendar events in a bounded date window.", "Frappe materially implements permission-aware general event scheduling and date-window retrieval.", "No compliance obligation ownership, evidence, escalation or due-state parity."),
    ("CAP-SET-EMAIL-DELIVERY-CONFIG", "FRAPPE", [("frappe/email/doctype/email_account/email_account.py", "L126-L190,L221-L235,L383-L417,L480-L511", "11d8eb503931b583c12bee74490c30359d55aca202c7e13de27eb9ef89242434"), ("frappe/email/doctype/email_account/email_account.json", "L81-L115,L299-L391", "4fee94cfc1714fb17c4189644b5db65aff406208dc5e3c10efdbb150b799398f")], "Configure and validate inbound/outbound email delivery with enabled/default resolution.", "Frappe materially defines SMTP/IMAP configuration, validation and enabled-default selection.", "No secret-store, identity lifecycle, retention or product RBAC parity."),
    ("CAP-SET-NOTIFICATION-TEMPLATE-LIFECYCLE", "FRAPPE", [("frappe/email/doctype/notification/notification.py", "L78-L153,L190-L246,L298-L354", "9b0b2336fa429dec7633c6d918c18a7c92aa6235776b45ced33236217b2e10ef"), ("frappe/email/doctype/notification/notification.json", "L56-L228,L276-L306,L350-L354", "b203189aba8205447abab0424c0554249490a66e86cfc3f70ae52e035f28b34a")], "Govern notification definition validation, rendering, recipients, channel dispatch and error recording.", "Frappe materially implements those notification-template behaviors.", "No approval/versioning, recovery, consent or Site-boundary parity."),
    ("CAP-SET-CALENDAR-PROVIDER-CONNECTION", "FRAPPE", [("frappe/integrations/doctype/google_calendar/google_calendar.py", "L103-L181,L207-L237,L274-L377,L442-L498", "f65e55ed527b870fb92e5a745033b45f0692fead8651a3ad7948aad24afab37d"), ("frappe/integrations/doctype/google_calendar/google_calendar.json", "L47-L126", "07091160b987fc7fe5f2b2f9bf939a49f8c48e21839d6ed19ca9fb769629424d")], "Connect a calendar provider through OAuth, encrypted refresh-token use and guarded pull/push.", "Frappe materially implements Google OAuth, encrypted refresh-token use and guarded calendar synchronization.", "No Microsoft provider, revocation, Site mapping, conflict resolution or retention parity."),
]

NCM = (
    "CAP-GOV-RISK-REGISTER-LIFECYCLE",
    "Govern a risk register with risk scoring, treatment/evidence, appetite acceptance, closure rationale and board controls.",
    [
        ("OPENPROJECT", "app/models/work_package.rb", "L57-L110,L274-L313", "fe6207cbd7edc33fdcbfce9e7da247c6bcf20bbbb0f7e33c8d4150abfb7aefa7", "Generic work items do not establish the material risk-register lifecycle."),
        ("OPENPROJECT", "app/contracts/work_packages/update_contract.rb", "L31-L90", "8fa1c09c9e4db094d613aa57bf689642e6de9bb18a9c3d9b41b4440b1e3e2467", "Generic update validation does not prove risk scoring, treatment or acceptance."),
        ("FRAPPE", "frappe/model/workflow.py", "L34-L119", "f521f649e42287fb06ad84d34c9c4a1f97a148903cf6e1961bd686fb59183bc5", "Generic workflow transitions do not establish risk governance semantics."),
    ],
    "The inspected generic work-item and workflow sources do not establish the exact risk-register completion boundary. This conclusion is bounded to the inspected corpus.",
)


manifest = load(MANIFEST_PATH)
mapping = load(MAPPING_PATH)
require(manifest.get("audited_commit") == mapping.get("audited_commit") == COMMIT, "Commit mismatch")
require(sha(MAPPING_PATH) == PRE_WAVE_MAPPING_SHA, "Pre-wave mapping SHA mismatch")
manifest_by_key = {row["working_key"]: row for row in manifest["targets"]}
mapping_by_key = {row["working_key"]: row for row in mapping["targets"]}
require(len(manifest_by_key) == len(mapping_by_key) == 902, "Target identity count mismatch")


def lineage(key: str) -> dict:
    identity = manifest_by_key[key]
    return {**{name: identity.get(name, []) for name in ("source_family_ids", "route_ids", "page_ids", "backend_anchors")}, **{name: identity.get(name) for name in ("id_status", "class", "canonical_module")}}


evaluations = []
for key, repo_name, loci, neutral, proven, limits in DIRECT:
    prior = mapping_by_key[key]
    require(prior.get("status") == "unproved" and prior.get("completion_credit") is False, f"Prior direct status drift: {key}")
    repo = REPOS[repo_name]
    exact_loci = [{"path": path, "lines": lines, "sha256": file_sha, "primary_source_url": f"{repo['official_repository_url']}/blob/{repo['commit_sha']}/{path}"} for path, lines, file_sha in loci]
    evidence_loci = [f"{repo['official_repository_url']}@{repo['commit_sha']} :: {path} :: {lines} :: sha256={file_sha}" for path, lines, file_sha in loci]
    evaluations.append({"working_key": key, "prior_status": "unproved", "candidate_status": "candidate_found_direct", "completion_credit_recommended": True, "neutral_requirement": neutral, "current_source_lineage": lineage(key), "benchmark": {**repo, "exact_loci": exact_loci, "proven_slice": proven, "parity_limits": limits, "p6_caveats": "Benchmark-only behavior; do not copy source, schema, labels, layouts or product wording."}, "evidence_loci": evidence_loci})

key, neutral, rejected, bounded_reason = NCM
prior = mapping_by_key[key]
require(prior.get("status") == "unproved" and prior.get("completion_credit") is False, f"Prior NCM status drift: {key}")
rejected_repositories = []
evidence_loci = []
for repo_name, path, lines, file_sha, reason in rejected:
    repo = REPOS[repo_name]
    rejected_repositories.append({**repo, "source_loci": [f"{path}:{lines}"], "exact_loci": [{"path": path, "lines": lines, "sha256": file_sha}], "reason": reason})
    evidence_loci.append(f"{repo['official_repository_url']}@{repo['commit_sha']} :: {path} :: {lines} :: sha256={file_sha}")
require(len({row["official_repository_url"] for row in rejected_repositories}) >= 2, "Wave-13 NCM corpus too narrow")
evaluations.append({"working_key": key, "prior_status": "unproved", "candidate_status": "documented_ncm_direct", "completion_credit_recommended": True, "neutral_requirement": neutral, "current_source_lineage": lineage(key), "evidence_loci": evidence_loci, "rejected_repositories": rejected_repositories, "bounded_ncm_reason": bounded_reason})

keys = [row["working_key"] for row in evaluations]
require(len(keys) == len(set(keys)) == 12, "Wave-13 keys are not 12 unique targets")
keys_sha = hashlib.sha256("\n".join(sorted(keys)).encode()).hexdigest()
require(keys_sha == "47106ee802d46e1521b35b4d723a856c5ac28be07d5bf576bc3b20dcf05726ad", "Wave-13 key SHA drift")
lineage_lines = sorted("|".join((row["working_key"], row["prior_status"], ";".join(sorted(row["current_source_lineage"]["route_ids"])), ";".join(sorted(row["current_source_lineage"]["page_ids"])), ";".join(sorted(row["current_source_lineage"]["backend_anchors"])))) for row in evaluations)
lineage_sha = hashlib.sha256("\n".join(lineage_lines).encode()).hexdigest()
require(lineage_sha == "04db739d77600492a094ef66a3b0eace667af096ed39f9d6214c125dc69d96a3", "Wave-13 lineage SHA drift")
decision_lines = sorted("|".join((row["working_key"], row["candidate_status"], row["neutral_requirement"], (row.get("benchmark") or {}).get("proven_slice", row.get("bounded_ncm_reason", "")), (row.get("benchmark") or {}).get("parity_limits", ""))) for row in evaluations)
decision_sha = hashlib.sha256("\n".join(decision_lines).encode()).hexdigest()
require(sum(row["candidate_status"] == "candidate_found_direct" for row in evaluations) == 11, "Wave-13 direct count drift")
require(sum(row["candidate_status"] == "documented_ncm_direct" for row in evaluations) == 1, "Wave-13 NCM count drift")

artifact = {
    "schema_version": "1.0.0", "artifact": "benchmark-target-specific-adjudication-902-wave13", "generated_at": GENERATED_AT, "audited_commit": COMMIT, "read_only": True,
    "scope": "Thirteenth bounded target-specific wave: 12 current unique completion-unproved targets; eleven material direct slices and one bounded NCM decision.",
    "methodology": {"family_credit_inherited": False, "runtime_boundary": "No application, browser, database, deployment or Git state was changed.", "no_copy_rule": "Evidence is behavioural only; do not copy source, schema, UI, wording or distinctive layouts."},
    "source_slice_reuse_disclosure": ["No target-key reuse. Sales Invoice ranges are disjoint by target; prior quotation and File waves used different commits or ranges; the risk NCM reuses OpenProject only as bounded rejection evidence."],
    "input_pins": {"working_capability_manifest_902": {"path": "evidence/source/working-capability-manifest-902.json", "file_sha256": sha(MANIFEST_PATH)}, "benchmark_final_902_before_wave": {"path": "evidence/source/benchmark-final-902-mapping.json", "file_sha256": sha(MAPPING_PATH)}},
    "repository_snapshots": REPOS,
    "counts": {"evaluated": 12, "verified_benchmark_direct_recommended": 11, "documented_ncm_direct_recommended": 1, "completion_credit_recommended": 12},
    "selected_keys_sha256": keys_sha, "selected_lineage_tuple_sha256": lineage_sha, "review_decision_sha256": decision_sha,
    "review_decision_reference_sha256": "5c2efcffd7660c9ddc2485f03dcf33c8b3e9fc30a4dd96769be4a32a3035e613",
    "evaluations": evaluations,
    "projected_delta": {"verified_benchmark_direct": 11, "documented_ncm_direct": 1, "eligible_total": 12, "completion_unproved": -12},
}
OUTPUT_PATH.write_text(json.dumps(artifact, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(json.dumps({"output": str(OUTPUT_PATH), "sha256": sha(OUTPUT_PATH), "evaluated": 12, "direct": 11, "ncm": 1, "decision_sha256": decision_sha}, indent=2))
