from __future__ import annotations

import hashlib
import json
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
SEALED_INPUT = AUDIT_DIR / "evidence/benchmark/sealed-run-145-agent-c-finance-invoice-fx-current-comparison-input-wave-24.json"
OUTPUT = AUDIT_DIR / "evidence/benchmark/raw-run-145-agent-c-invoice-current-comparison-wave-24.json"
EXPECTED_INPUT_SHA256 = "722deceb2bf9a9462c5db69adcf700e9ce0cba62bffe0bef160e004ade82a032"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def row(
    identifier: str,
    outcome: str,
    loci: str,
    evidence: str,
    evidence_limit: str,
    improvements: list[str] | None = None,
) -> dict[str, object]:
    return {
        "id": identifier,
        "outcome": outcome,
        "exact_current_locus": loci,
        "evidence": evidence,
        "evidence_limit": evidence_limit,
        "improvement_ids": improvements or [],
    }


input_bytes = SEALED_INPUT.read_bytes()
assert sha256(input_bytes) == EXPECTED_INPUT_SHA256
sealed_input = json.loads(input_bytes)
assert sealed_input["application_source_pin"] == {
    "commit": APPLICATION_COMMIT,
    "tree": APPLICATION_TREE,
    "read_mode": "PINNED_GIT_OBJECT_ONLY",
}
invoice_target = next(
    target
    for target in sealed_input["canonical_targets"]
    if target["feature_id"] == "CAP-FIN-BILLING-INVOICE-LIFECYCLE"
)
assert len(invoice_target["current_source_manifest"]) == 18

comparison_rows = [
    row("I1-M01", "PARTIAL", "app/Domain/Finance/Http/Controllers/InvoiceController.php::store 210-415; ::send 567-604", "Creation writes status=draft; later Send changes Draft to sent. There is no distinct submit action or Submitted state.", "S,B", ["R1"]),
    row("I1-M02", "PARTIAL", "resources/js/pages/finance/invoices/Index.tsx 301-321; app/Domain/Finance/Models/FinInvoice.php 29-72; app/Domain/Finance/Services/AccountsReceivableService.php 341-435", "Current vocabulary exposes Draft, Sent, Viewed, Paid, Overdue, Cancelled. Submitted, Unpaid and Partly Paid are not distinct; partial receipts leave the status receivable until full payment.", "S,B", ["R1"]),
    row("I1-M03", "PARTIAL", "routes/finance.php 683-709; InvoiceController.php::send 567-600; FinInvoiceJournalService.php::postInvoiceJournal 25-109", "finance.ar.manage and policy update authority guard Send, and a posting job is queued. No separate approval control or stock-bearing invoice effects are present.", "S,B,D", ["R2"]),
    row("I1-M04", "PARTIAL", "resources/js/pages/finance/invoices/Show.tsx 98-103, 186-200; Index.tsx 140-143, 195-201", "Sent receivables can expose Mark Paid or Record receipt. No distinct payment-request action is established; Send Email is invoice delivery, not proven equivalent.", "S,B", ["R3", "R6"]),
    row("I1-M05", "PARTIAL", "InvoiceController.php::send 581-600; SendInvoiceEmailJob.php::handle 26-57; InvoiceEmailNotification.php::toMail 24-53", "Dispatch occurs after the local transaction returns and email may carry the generated PDF. Repeated Send calls dispatch repeated jobs; no request identity, payment link, unique job, or duplicate-handoff suppression exists.", "S,B", ["R3", "R5"]),
    row("I1-M06", "PARTIAL", "InvoiceController.php::cancel 679-732; FinInvoiceJournalService.php::reverseInvoiceJournal 111-145; InvoiceController.php::store 405-408", "Cancellation locks the invoice, rejects paid or settled invoices, invokes journal reversal, and sets Cancelled. The reversal implementation is outside this slice, and delivery-bound billing entries changed to invoiced are not restored.", "S,D", ["R4"]),
    row("I1-N01", "NOT_COMPARABLE", "FinInvoicePolicy.php 10-28; PaymentSettlementSiteScope.php 42-64, 111-122; InvoiceController.php 37-124, 418-604", "The neutral packet does not establish target Site, privacy, concealment, retry, or accessibility semantics. Current-source boundary findings are independent and cannot turn that unknown into parity.", "U"),
    row("I1-AC01", "PARTIAL", "InvoiceController.php 375-415, 581-588; Index.tsx 301-321", "New invoice is Draft and can later be sent, but only six current state labels are exposed and no Submitted, Unpaid, or Partly Paid distinction exists.", "S,B", ["R1"]),
    row("I1-AC02", "PARTIAL", "routes/finance.php 698-700; InvoiceController.php 569-600; FinInvoiceJournalService.php 40-105", "Manage authority and issue-journal construction exist; distinct approval and conditional stock effects do not. Posting is asynchronous after the state commit.", "S,B,D", ["R2"]),
    row("I1-AC03", "PARTIAL", "Show.tsx 98-103, 186-200; AccountsReceivableService.php::allocatePayment 296-440", "Payment capture exists for receivable states. No separate payment-request action is established. The Show page incorrectly offers Mark Paid on Draft even though the service rejects Draft.", "S,B", ["R3", "R6"]),
    row("I1-AC04", "PARTIAL", "InvoiceController.php 581-600; InvoiceEmailNotification.php 24-53; SendInvoiceEmailJob.php 26-57", "PDF delivery and post-transaction dispatch are present. Duplicate outbound handoffs are not suppressed.", "S,B", ["R3", "R5"]),
    row("I1-AC05", "PARTIAL", "InvoiceController.php 688-721; FinInvoiceJournalService.php 111-145", "Cancelled state and requested journal reversal exist, but full reversal of all originating effects and downstream ledger implementation are not established.", "S,D", ["R4"]),
    row("I2-M01", "PARTIAL", "Index.tsx 301-321; FinInvoice.php 29-72", "Draft exists, but Validated, Closed and Abandoned are absent. Sent, Paid and Cancelled are not treated as equivalents.", "S,B,U", ["R1"]),
    row("I2-M02", "MET", "InvoiceController.php 375-399; AccountsReceivableService.php::createInvoice 93-121", "Both native creation paths explicitly create Draft invoices.", "S"),
    row("I2-M03", "GAP", "AccountsReceivableService.php 426-435; InvoiceController.php 663-669", "Full settlement sets status=paid and paid_at; no explicit close operation or Closed state exists.", "S,B,U", ["R1", "R6"]),
    row("I2-M04", "PARTIAL", "InvoiceController.php::cancel 688-723; routes/finance.php 286-301", "Unsettled invoices can be cancelled and paid invoices are rejected. No cancellation event is emitted, and the separate AP credit-note routes do not establish a paid customer-invoice correction path.", "S,B,D", ["R4"]),
    row("I2-M05", "GAP", "SendInvoiceEmailJob.php 26-57", "Successful notification updates sent_at or status and logs. There is no send counter or delivery domain event.", "S,B", ["R5"]),
    row("I2-M06", "GAP", "AccountsReceivableService.php::allocatePayment 296-440", "Each request locks and allocates against one invoice only, then marks it Paid. No multi-invoice distribution or explicit closing exists.", "S,B", ["R6"]),
    row("I2-N01", "NOT_COMPARABLE", "InvoiceController.php 567-732; routes/finance.php 286-301", "Neutral equivalence among abandonment, cancellation, correction and the target lifecycle remains unknown; no label equivalence was inferred.", "U"),
    row("I2-N02", "NOT_COMPARABLE", "FinInvoicePolicy.php 10-28; PaymentSettlementSiteScope.php 42-64; InvoiceController.php 37-124, 418-604", "Neutral Site, privacy, concealment, retry, and accessibility parity remains unestablished despite independently observable current-source behavior.", "U"),
    row("I2-AC01", "PARTIAL", "InvoiceController.php 375-399; Index.tsx 301-321", "Draft creation is present; Validated, Closed and Abandoned are not distinguishable.", "S,B,U", ["R1"]),
    row("I2-AC02", "GAP", "AccountsReceivableService.php 426-435", "Full payment records Paid, not Closed.", "S,B,U", ["R1", "R6"]),
    row("I2-AC03", "PARTIAL", "InvoiceController.php 688-723; routes/finance.php 286-301", "Unpaid cancellation exists and paid cancellation is blocked; no cancellation event or linked customer credit-note correction path is established.", "S,B,D", ["R4"]),
    row("I2-AC04", "GAP", "SendInvoiceEmailJob.php 43-55", "Successful mail records timestamp and log only; no counter increment or delivery event.", "S,B", ["R5"]),
    row("I2-AC05", "GAP", "AccountsReceivableService.php 296-440", "Allocation accepts one invoice_id and marks that invoice Paid when fully settled; no multi-invoice distribution or closure.", "S,B", ["R6"]),
    row("IA-M01", "PARTIAL", "routes/finance.php 683-709; FinInvoicePolicy.php 10-28", "Create and Send have manage guards; Mark Paid and Cancel are also guarded. No invoice Delete, distinct Deliver, Write-off, or Payment-history actions exist in the bounded slice.", "S,B,U", ["R7", "R8"]),
    row("IA-M02", "CONTRADICTED", "InvoiceController.php::store 268-412; ::send 581-600", "Invoice creation commits without journal work; issuing later commits Sent and only then dispatches a separate journal job. Failure can leave an issued invoice without its journal.", "S,U"),
    row("IA-M03", "PARTIAL", "InvoiceController.php 581-600; SendInvoiceEmailJob.php 26-57; InvoiceEmailNotification.php 24-53", "Later queued transport and optional PDF exist, but the controller records Sent and sent_at before transport succeeds.", "S,U", ["R5"]),
    row("IA-M04", "CONTRADICTED", "InvoiceController.php::send 567-604; Show.tsx 98-101, 186-190", "No row lock, unique job, delivery token, or sent-state rejection exists. The UI and backend permit resending an already-sent invoice.", "S,B,U"),
    row("IA-M05", "MET", "PaymentSettlementRecorder.php 83-113; InvoiceController.php 82-96; AccountsReceivableService.php 426-435", "Allocation rows persist the amount; index projection recalculates amount_paid, and full allocation updates Paid.", "S"),
    row("IA-M06", "GAP", "routes/finance.php 674-709; InvoiceController.php 418-732; FinInvoice.php 18-20", "Model supports soft deletes, but no invoice deletion action, stable-state deletion check, or payment or applied-credit rejection workflow is present.", "S,B,U", ["R8"]),
    row("IA-N01", "NOT_COMPARABLE", "InvoiceController.php::cancel 679-732; FinInvoiceJournalService.php 111-145", "Adjacent deletion or write-off evidence does not establish target cancellation or reversal. Current cancellation is independently visible but does not promote the adjacent packet.", "U"),
    row("IA-N02", "NOT_COMPARABLE", "PaymentSettlementSiteScope.php 15-25, 42-64; InvoiceController.php 35-41", "No organisational-partitioning abstraction or target Site, privacy, or support semantics may be inferred from the adjacent packet.", "U"),
    row("IA-AC01", "PARTIAL", "routes/finance.php 683-709; FinInvoicePolicy.php 10-28", "Only a subset of the six adjacent actions exists and invokes guards.", "S,B,U", ["R7", "R8"]),
    row("IA-AC02", "CONTRADICTED", "InvoiceController.php 268-412, 581-600", "Draft creation and issue-journal work are deliberately separated across commits and queue handoff.", "S,U"),
    row("IA-AC03", "PARTIAL", "SendInvoiceEmailJob.php 26-57; InvoiceEmailNotification.php 24-53; InvoiceController.php 584-600", "Deferred email and PDF attachment exist, but the delivery-like Sent timestamp is recorded before transport.", "S,U", ["R5"]),
    row("IA-AC04", "CONTRADICTED", "InvoiceController.php 567-604; Show.tsx 98-101, 186-190", "A second delivery attempt is accepted and dispatches another job; no duplicate blocker is surfaced.", "S,B,U"),
    row("IA-AC05", "MET", "PaymentSettlementRecorder.php 85-102; InvoiceController.php 85-93", "Recording an allocation changes the derived paid amount accordingly.", "S"),
    row("IA-AC06", "GAP", "routes/finance.php 674-709; InvoiceController.php 418-732", "No invoice delete endpoint or payments or applied-credit deletion guard exists.", "S,B,U", ["R8"]),
]

expected_ids = {
    *(f"I1-M{i:02d}" for i in range(1, 7)), "I1-N01", *(f"I1-AC{i:02d}" for i in range(1, 6)),
    *(f"I2-M{i:02d}" for i in range(1, 7)), "I2-N01", "I2-N02", *(f"I2-AC{i:02d}" for i in range(1, 6)),
    *(f"IA-M{i:02d}" for i in range(1, 7)), "IA-N01", "IA-N02", *(f"IA-AC{i:02d}" for i in range(1, 7)),
}
assert len(comparison_rows) == 39
assert {item["id"] for item in comparison_rows} == expected_ids
outcome_counts = {
    outcome: sum(item["outcome"] == outcome for item in comparison_rows)
    for outcome in ("MET", "PARTIAL", "GAP", "CONTRADICTED", "NOT_COMPARABLE")
}
assert outcome_counts == {"MET": 3, "PARTIAL": 19, "GAP": 8, "CONTRADICTED": 4, "NOT_COMPARABLE": 5}

improvements = {
    "R1": "Define one auditable Oblivion invoice state machine that distinctly represents preparation, approval or issue, outstanding, partial settlement, full settlement, overdue handling, cancellation, and correction. Do not infer Submitted or Sent, Paid or Closed, or Cancelled or Abandoned equivalence without a root-owned mapping decision.",
    "R2": "Make issue or submission an explicit action with its exact capability, approval rule, locked transition, accounting intent, and conditional stock effects where Oblivion invoices genuinely contain stock.",
    "R3": "Distinguish receipt capture from requesting payment. Persist a request or version identity, support the native invoice PDF or an Oblivion payment link, commit the intent before handoff, and suppress duplicate handoffs.",
    "R4": "Expose a native cancellation or correction workflow that locks state, rejects settlement conflicts, reverses the issue journal, restores applicable delivered-support or stock effects, records an event and audit trail, and gives paid invoices a separately governed correction path.",
    "R5": "Make delivery idempotent and concurrency-safe; record delivery state, send count, and delivery event only after successful transport, with retry-safe failure recovery.",
    "R6": "Retain invoice locking, Site scope, and replay binding while recording partial settlement explicitly; support a single receipt distributed over multiple selected invoices if adopted, and explicitly finalise every fully settled invoice.",
    "R7": "Apply action-specific permission plus canonical target-Site policy checks to every list and direct-object read, write, download, and send route, returning concealed 404s outside approved Sites.",
    "R8": "Keep delete, write-off, and payment-history separate from lifecycle mapping; if adopted, give each an exact permission, stable locked-state check, Site concealment, and payment or applied-credit safeguards.",
}

result = {
    "schema_version": "raw_run_145_agent_c_invoice_current_comparison_wave_24_v1",
    "run_id": "RUN-145-C-INVOICE-CURRENT-COMPARISON-WAVE-24",
    "status": "PINNED_STATIC_COMPARISON_COMPLETE_ZERO_CREDIT",
    "generated_on": "2026-08-26",
    "agent": {
        "agent_id": "/root/run145_agent_c_invoice",
        "role": "clean_room_current_oblivion_invoice_comparator",
        "fresh_context": True,
    },
    "input_seal": {
        "path": "evidence/benchmark/sealed-run-145-agent-c-finance-invoice-fx-current-comparison-input-wave-24.json",
        "bytes": len(input_bytes),
        "sha256": EXPECTED_INPUT_SHA256,
    },
    "target": {
        "feature_id": invoice_target["feature_id"],
        "feature_class": invoice_target["feature_class"],
        "user_job": invoice_target["user_job"],
        "neutral_capability_ids": invoice_target["neutral_capability_ids"],
        "adjacent_capability_id": invoice_target["adjacent_capability_id"],
    },
    "source_pin": {"commit": APPLICATION_COMMIT, "tree": APPLICATION_TREE},
    "source_manifest_verification": {
        "declared_files": 18,
        "verified_blob_and_sha256_matches": 18,
        "files_used": 18,
        "rows": invoice_target["current_source_manifest"],
    },
    "evidence_limit_legend": {
        "S": "Pinned static source only; no runtime, browser, or test proof.",
        "B": "Absence established only across the 18 enumerated invoice blobs.",
        "D": "Downstream implementation was not in the invoice manifest; only the invocation or caller is established.",
        "U": "Neutral unknown or adjacent-only boundary preserved; no equivalence inferred.",
    },
    "comparison_rows": comparison_rows,
    "outcome_counts": outcome_counts,
    "native_improvement_requirements": improvements,
    "current_native_workflow": [
        "Authenticated routes use finance.ar.view or finance.ar.manage.",
        "Manager creation selects approved-Site Clients and delivered-support entries, then transactionally creates invoice plus lines in Draft and marks bound billing entries Invoiced.",
        "Draft editing is allowed except for delivery-bound invoices.",
        "Send changes Draft to Sent and records sent_at, commits, then separately dispatches the issue-journal job and email job.",
        "The email job generates a PDF when needed and sends it as an attachment.",
        "Receipt allocation locks the invoice, enforces the canonical Client Site, binds an idempotency key to the actor, invoice, and payload, posts a receipt journal, records append-only settlement provenance, and marks the invoice Paid when fully allocated.",
        "Cancellation locks and Site-checks the invoice, rejects paid or settled invoices, requests linked-journal reversal, and records Cancelled.",
        "The detail UI exposes Edit, Download PDF, Send Email, and Mark Paid. It does not expose the implemented Cancel action.",
    ],
    "site_rbac_privacy_direct_object_boundary": {
        "positive_static_evidence": [
            "Exact AR route permissions are present.",
            "Client and delivered-support choices during creation use UserSiteAccessService.",
            "Mark Paid and Cancel use PaymentSettlementSiteScope, require an active canonical Client Site, and conceal denial with 404.",
            "Settlement provenance binds Site, target, journal, actor, and source.",
            "organization_id is the single-application storage context, not a tenant boundary.",
        ],
        "unresolved_static_boundary": [
            "Index and export use FinInvoice::forOrganization(1) without applyInvoiceScope; any actor with finance.ar.view can statically query all Sites.",
            "Show, Edit, Update, Send, and PDF use direct route-model binding without canonical Site scope. The policy checks only AR permission, not record ownership or Site.",
            "Show returns the invoice model and relations, including client contact and address, notes and email content, creator, and journal context, without Site-based minimisation.",
            "Direct-ID concealment is established for Mark Paid and Cancel only, not read, edit, update, send, or PDF.",
            "Funder-only invoices may have no Client, while settlement and cancellation require a canonical Client Site; those invoices are statically unscopeable for those actions.",
            "Show renders Edit, Send, and Mark Paid based on status rather than canManage; view-only users can be shown actions that backend middleware rejects.",
            "Show offers Mark Paid for Draft, while AccountsReceivableService accepts only Sent, Viewed, or Overdue.",
        ],
    },
    "counts": {
        "comparison_rows": 39,
        **outcome_counts,
        "source_files": 18,
        "benchmark_selections": 0,
        "NCM_findings": 0,
        "credit_awards": 0,
        "writes_by_agent_c": 0,
    },
    "attestation": {
        "invoice_slice_only": True,
        "all_neutral_rows_reviewed_exactly_once": True,
        "adjacent_capability_kept_separate": True,
        "unknowns_preserved": True,
        "upstream_identity_not_encountered": True,
        "working_tree_application_source_read": False,
        "network_browser_runtime_tests_build_database_or_package_tools_used": False,
        "benchmark_selection_mapping_NCM_or_credit_awarded": False,
        "audit_artifact_writes_by_root_only": True,
    },
}

output_bytes = (json.dumps(result, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
if OUTPUT.exists():
    assert OUTPUT.read_bytes() == output_bytes, f"Refusing to overwrite different bytes: {OUTPUT}"
else:
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_bytes(output_bytes)

print(f"{OUTPUT.relative_to(AUDIT_DIR)}\t{len(output_bytes)}\t{sha256(output_bytes)}")
