#!/usr/bin/env python3
"""Build the second bounded target-specific benchmark wave for the 901 map.

Audit-artifact generator only.  It does not execute application code, tests,
browser journeys, databases or external workflows.
"""

from __future__ import annotations

import hashlib
import json
from pathlib import Path


GENERATOR_DIR = Path(__file__).resolve().parent
AUDIT = GENERATOR_DIR.parent
SOURCE = AUDIT / "evidence" / "source"
OUTPUT = SOURCE / "benchmark-target-specific-adjudication-901-wave2.json"
COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
MANIFEST_SHA = "5b477cc3fa5e5343b223b7ba559919f708f945426f193dbb0510245771148900"
BASE_MAPPING_SHA = "e56f2b7fc48d574451bde581f9601dc8353e8ce39541001aea6b1cbe7a6f6e08"


def locus(owner: str, sha: str, path: str, lines: str) -> str:
    return f"{owner}@{sha} :: {path} :: {lines}"


SNIPE_SHA = "5fced85cafa5f49bb820b83d68850bb9e62d62f5"
BIGCAP_SHA = "b9a431c1685a427b71b9bb757a97450b85ecb35f"

evaluations = [
    {
        "working_key": "FLEET-ASSET",
        "adjudication_id": "fresh-901-wave2:FLEET-ASSET",
        "candidate_status": "candidate_found_direct",
        "completion_credit_recommended": True,
        "neutral_requirement": "An authorised asset operator can list, view, create, update and retire a stable asset record while preserving identity and history.",
        "search_terms": ["open source authorised asset CRUD history retirement", "Snipe-IT asset create update delete history"],
        "evidence_loci": [
            locus("grokability/snipe-it", SNIPE_SHA, "app/Http/Controllers/Api/AssetsController.php", "L63-L445"),
            locus("grokability/snipe-it", SNIPE_SHA, "app/Http/Controllers/Api/AssetsController.php", "L533-L543"),
            locus("grokability/snipe-it", SNIPE_SHA, "app/Http/Controllers/Api/AssetsController.php", "L645-L752"),
            locus("grokability/snipe-it", SNIPE_SHA, "app/Http/Controllers/Api/AssetsController.php", "L1096-L1117"),
        ],
        "benchmark": {
            "official_repository_url": "https://github.com/grokability/snipe-it",
            "commit_sha": SNIPE_SHA,
            "source_loci": [
                "app/Http/Controllers/Api/AssetsController.php:L63-L445",
                "app/Http/Controllers/Api/AssetsController.php:L533-L543",
                "app/Http/Controllers/Api/AssetsController.php:L645-L752",
                "app/Http/Controllers/Api/AssetsController.php:L1096-L1117",
            ],
            "proven_slice": "Authorised list/create/show/update plus retirement and asset-history/identity behavior for a persisted asset record.",
            "parity_limits": "General IT assets, not Oblivion fleet readiness, VIN, registration, maintenance, site access, ownership/privacy or runtime acceptance.",
        },
        "inheritance_boundary": "Fresh target-specific material asset slice; prior pending evidence is not mechanically promoted.",
    },
    {
        "working_key": "SEC-DEVICE-ASSIGNMENT",
        "adjudication_id": "fresh-901-wave2:SEC-DEVICE-ASSIGNMENT",
        "candidate_status": "candidate_found_direct",
        "completion_credit_recommended": True,
        "neutral_requirement": "An authorised operator can assign an available managed device to a target, release it and retrieve assignment history with actor/time evidence.",
        "search_terms": ["open source device asset checkout checkin assignment history", "Snipe-IT authorised custody assignment release"],
        "evidence_loci": [
            locus("grokability/snipe-it", SNIPE_SHA, "app/Http/Controllers/Assets/AssetCheckoutController.php", "L33-L60,L70-L177"),
            locus("grokability/snipe-it", SNIPE_SHA, "app/Http/Controllers/Assets/AssetCheckinController.php", "L84-L203"),
            locus("grokability/snipe-it", SNIPE_SHA, "app/Http/Controllers/Api/AssetsController.php", "L1920-L1928"),
        ],
        "benchmark": {
            "official_repository_url": "https://github.com/grokability/snipe-it",
            "commit_sha": SNIPE_SHA,
            "source_loci": [
                "app/Http/Controllers/Assets/AssetCheckoutController.php:L33-L60,L70-L177",
                "app/Http/Controllers/Assets/AssetCheckinController.php:L84-L203",
                "app/Http/Controllers/Api/AssetsController.php:L1920-L1928",
            ],
            "proven_slice": "Permission-gated checkout/check-in with availability checks, expected return, actor/event evidence and authorised assignment-history retrieval.",
            "parity_limits": "IT-asset custody does not prove Oblivion device identity, telemetry, tracking consent, assignment types, client/site privacy or runtime behavior.",
        },
        "inheritance_boundary": "Fresh assignment/release/history check; prior pending device-model evidence is not inherited.",
    },
    {
        "working_key": "CAP-FIN-API-CLIENT-FINANCIAL-SUMMARY-LEDGER",
        "adjudication_id": "fresh-901-wave2:CAP-FIN-API-CLIENT-FINANCIAL-SUMMARY-LEDGER",
        "candidate_status": "candidate_found_direct",
        "completion_credit_recommended": True,
        "neutral_requirement": "An authorised finance API actor can request a client-scoped balance summary and chronological transaction ledger for selected stable client identifiers.",
        "search_terms": ["open source customer balance summary transactions by customer API", "BigCapital customer IDs balance running ledger"],
        "evidence_loci": [
            locus("bigcapitalhq/bigcapital", BIGCAP_SHA, "packages/server/src/modules/FinancialStatements/modules/CustomerBalanceSummary/CustomerBalanceSummary.controller.ts", "L21-L98"),
            locus("bigcapitalhq/bigcapital", BIGCAP_SHA, "packages/server/src/modules/FinancialStatements/modules/CustomerBalanceSummary/CustomerBalanceSummaryQuery.dto.ts", "L5-L13"),
            locus("bigcapitalhq/bigcapital", BIGCAP_SHA, "packages/server/src/modules/FinancialStatements/modules/TransactionsByCustomer/TransactionsByCustomer.controller.ts", "L21-L91"),
            locus("bigcapitalhq/bigcapital", BIGCAP_SHA, "packages/server/src/modules/FinancialStatements/modules/TransactionsByCustomer/TransactionsByCustomerQuery.dto.ts", "L4-L7"),
        ],
        "benchmark": {
            "official_repository_url": "https://github.com/bigcapitalhq/bigcapital",
            "commit_sha": BIGCAP_SHA,
            "source_loci": [
                "packages/server/src/modules/FinancialStatements/modules/CustomerBalanceSummary/CustomerBalanceSummary.controller.ts:L21-L98",
                "packages/server/src/modules/FinancialStatements/modules/CustomerBalanceSummary/CustomerBalanceSummaryQuery.dto.ts:L5-L13",
                "packages/server/src/modules/FinancialStatements/modules/TransactionsByCustomer/TransactionsByCustomer.controller.ts:L21-L91",
                "packages/server/src/modules/FinancialStatements/modules/TransactionsByCustomer/TransactionsByCustomerQuery.dto.ts:L4-L7",
            ],
            "proven_slice": "Customer-ID-filtered balance summary and transactions-by-customer endpoints with opening/closing totals, debit/credit and chronological transaction/running-balance fields.",
            "parity_limits": "A commercial customer is not a supported person; this does not prove Oblivion combined cost-summary schema, direct-object/site denial or runtime parity.",
        },
        "inheritance_boundary": "Audit-assigned target receives only this independently checked customer-summary/ledger slice.",
    },
    {
        "working_key": "FIN-MATCH-RULE",
        "adjudication_id": "fresh-901-wave2:FIN-MATCH-RULE",
        "candidate_status": "candidate_found_direct",
        "completion_credit_recommended": True,
        "neutral_requirement": "An authorised finance operator can create, inspect, edit, list and delete stable bank-matching rules.",
        "search_terms": ["open source bank matching rule CRUD", "BigCapital BankRules controller create edit delete list"],
        "evidence_loci": [
            locus("bigcapitalhq/bigcapital", BIGCAP_SHA, "packages/server/src/modules/BankRules/BankRules.controller.ts", "L22-L87"),
        ],
        "benchmark": {
            "official_repository_url": "https://github.com/bigcapitalhq/bigcapital",
            "commit_sha": BIGCAP_SHA,
            "source_loci": ["packages/server/src/modules/BankRules/BankRules.controller.ts:L22-L87"],
            "proven_slice": "Create, edit, delete, retrieve and list endpoints for persisted bank rules.",
            "parity_limits": "Does not prove predicate execution, auto-matching, ordering, audit history, reversal, organisation/site scoping, permission parity or runtime behavior.",
        },
        "inheritance_boundary": "Fresh bounded CRUD slice supersedes the prior pending state; matching execution remains outside the credited slice.",
    },
    {
        "working_key": "CAP-ASSET-ASSET-QR-LABEL-ARTIFACT",
        "adjudication_id": "fresh-901-wave2:CAP-ASSET-ASSET-QR-LABEL-ARTIFACT",
        "candidate_status": "candidate_found_direct",
        "completion_credit_recommended": True,
        "neutral_requirement": "An authorised asset viewer can obtain a printable or scannable QR artifact linked to a stable asset.",
        "search_terms": ["open source authorised asset QR PNG label PDF", "Snipe-IT asset QR code labels"],
        "evidence_loci": [
            locus("grokability/snipe-it", SNIPE_SHA, "app/Http/Controllers/QrCodeController.php", "L26-L64"),
            locus("grokability/snipe-it", SNIPE_SHA, "app/Http/Controllers/Api/AssetsController.php", "L2014-L2097"),
        ],
        "benchmark": {
            "official_repository_url": "https://github.com/grokability/snipe-it",
            "commit_sha": SNIPE_SHA,
            "source_loci": [
                "app/Http/Controllers/QrCodeController.php:L26-L64",
                "app/Http/Controllers/Api/AssetsController.php:L2014-L2097",
            ],
            "proven_slice": "Authorised QR generation targeting the asset route and authorised asset-label PDF generation.",
            "parity_limits": "Does not prove Oblivion PNG/SVG/download parity, label layout, opaque tokens or site authorization; Snipe-IT encodes a direct object route.",
        },
        "inheritance_boundary": "Fresh target-specific artifact check; token resolution remains a separate unproved target.",
    },
    {
        "working_key": "CAP-FIN-API-SITE-FINANCIAL-SUMMARY",
        "adjudication_id": "fresh-901-wave2:CAP-FIN-API-SITE-FINANCIAL-SUMMARY",
        "candidate_status": "documented_ncm_direct",
        "completion_credit_recommended": False,
        "search_terms": ["facility site branch financial summary endpoint open source", "ERPNext site cost centre financial summary API"],
        "rejected_repositories": [
            {"official_repository_url": "https://github.com/bigcapitalhq/bigcapital", "commit_sha": BIGCAP_SHA, "reason": "Customer and branch modules did not establish a facility/site identity plus site-scoped API authorization boundary."},
            {"official_repository_url": "https://github.com/frappe/erpnext", "commit_sha": "83e33b343cfac160f3811461d1737e67be82efd9", "reason": "Company/cost-centre dimensions are not the target service-site identity and direct-object boundary."}
        ],
        "must_remain_unproved_reason": "The two-repository check is insufficient for a completed bounded NCM and found no exact site-keyed boundary.",
    },
    {
        "working_key": "CAP-OPS-CARE-PLAN-REVIEW-CYCLE",
        "adjudication_id": "fresh-901-wave2:CAP-OPS-CARE-PLAN-REVIEW-CYCLE",
        "candidate_status": "documented_ncm_direct",
        "completion_credit_recommended": False,
        "search_terms": ["care plan start review complete review owner handoff open source"],
        "rejected_repositories": [
            {"official_repository_url": "https://github.com/openemr/openemr", "commit_sha": "312b5d042f7fa49cc78ebed041155fba339a991c", "reason": "FHIR CarePlan representation/status did not establish an explicit review-cycle state machine."},
            {"official_repository_url": "https://github.com/ohcnetwork/care", "commit_sha": "c64a1ef0e726b06788b63bf4537dea5d16af2269", "reason": "No care-plan review-cycle transition with reviewer provenance and handoff was established."}
        ],
        "must_remain_unproved_reason": "No exact review-cycle candidate; bounded NCM corpus is not yet sufficient for credit.",
    },
    {
        "working_key": "CAP-OPS-CARE-PLAN-SIGNOFF",
        "adjudication_id": "fresh-901-wave2:CAP-OPS-CARE-PLAN-SIGNOFF",
        "candidate_status": "documented_ncm_direct",
        "completion_credit_recommended": False,
        "search_terms": ["care plan signoff revoke signature signer timestamp open source"],
        "rejected_repositories": [
            {"official_repository_url": "https://github.com/openemr/openemr", "commit_sha": "312b5d042f7fa49cc78ebed041155fba339a991c", "reason": "Generic signatures and CarePlan support did not prove care-plan-specific signoff/revocation."},
            {"official_repository_url": "https://github.com/ohcnetwork/care", "commit_sha": "c64a1ef0e726b06788b63bf4537dea5d16af2269", "reason": "No care-plan signoff and revocation boundary was established."}
        ],
        "must_remain_unproved_reason": "No exact signoff/revocation candidate and insufficient bounded NCM corpus.",
    },
    {
        "working_key": "CAP-OPS-TIMESHEET-AUTHOR-SUBMIT",
        "adjudication_id": "fresh-901-wave2:CAP-OPS-TIMESHEET-AUTHOR-SUBMIT",
        "candidate_status": "documented_ncm_direct",
        "completion_credit_recommended": False,
        "search_terms": ["timesheet author submit manager repair resubmit open source"],
        "rejected_repositories": [
            {"official_repository_url": "https://github.com/kimai/kimai", "commit_sha": "fd8989e9011aeff0a9ed573c294707140b4401b0", "reason": "Time capture/locking/export did not establish author-submit to manager-owner to author-resubmit."},
            {"official_repository_url": "https://github.com/frappe/erpnext", "commit_sha": "83e33b343cfac160f3811461d1737e67be82efd9", "reason": "Generic document submission did not establish the exact ownership handoff and repair/resubmit boundary."}
        ],
        "must_remain_unproved_reason": "No exact author submission/resubmission candidate and insufficient bounded NCM corpus.",
    },
    {
        "working_key": "FIN-BANK-TRANSACTION",
        "adjudication_id": "fresh-901-wave2:FIN-BANK-TRANSACTION",
        "candidate_status": "candidate_found_partial",
        "completion_credit_recommended": False,
        "candidate": {
            "official_repository_url": "https://github.com/bigcapitalhq/bigcapital", "commit_sha": BIGCAP_SHA,
            "evidence_loci": [locus("bigcapitalhq/bigcapital", BIGCAP_SHA, "packages/server/src/modules/BankingTransactions/controllers/BankingTransactions.controller.ts", "L27-L147")],
            "proven_slice": "Paginated list, create, detail and delete operations for bank transactions.",
            "parity_limits": "No import endpoint, file mapping, duplicate protection, provenance or reconciliation-ownership proof."
        },
        "must_remain_unproved_reason": "The import and duplicate-safe provenance portion remains unsupported.",
    },
    {
        "working_key": "CAP-ASSET-ASSET-QR-TOKEN-RESOLUTION",
        "adjudication_id": "fresh-901-wave2:CAP-ASSET-ASSET-QR-TOKEN-RESOLUTION",
        "candidate_status": "documented_ncm_direct",
        "completion_credit_recommended": False,
        "search_terms": ["opaque revocable asset QR token resolver open source"],
        "rejected_repositories": [
            {"official_repository_url": "https://github.com/grokability/snipe-it", "commit_sha": SNIPE_SHA, "reason": "The QR encodes a direct object route using type and ID, not an opaque/revocable token."},
            {"official_repository_url": "https://github.com/netbox-community/netbox", "commit_sha": "a08d9f13fceeab9fc5bf8f998ddc0997e867eab8", "reason": "Core routes did not establish an opaque-token resolver; results surfaced a separate plugin."}
        ],
        "must_remain_unproved_reason": "No opaque/revocable token resolver and insufficient bounded NCM corpus.",
    },
    {
        "working_key": "CAP-MED-MEDICATION-ORDER-LIFECYCLE",
        "adjudication_id": "fresh-901-wave2:CAP-MED-MEDICATION-ORDER-LIFECYCLE",
        "candidate_status": "candidate_found_partial",
        "completion_credit_recommended": False,
        "candidate": {
            "official_repository_url": "https://github.com/openmrs/openmrs-core", "commit_sha": "8bb5c2e9e36ab8fb09f0053786bc0f040775cc1e",
            "evidence_loci": ["openmrs/openmrs-core@8bb5c2e9e36ab8fb09f0053786bc0f040775cc1e :: api/src/main/java/org/openmrs/DrugOrder.java :: L33-L98,L444-L455,L467-L506"],
            "proven_slice": "Dose, frequency, PRN, drug, duration, route, discontinuation, revision lineage and expiry fields.",
            "parity_limits": "No target store/update/destroy/import/detail actions, authorization, reconciliation or user workflow."
        },
        "must_remain_unproved_reason": "Only the order model and lineage slice are proven.",
    },
    {
        "working_key": "CAP-MED-CLIENT-DOSE-ADMINISTRATION",
        "adjudication_id": "fresh-901-wave2:CAP-MED-CLIENT-DOSE-ADMINISTRATION",
        "candidate_status": "candidate_found_partial",
        "completion_credit_recommended": False,
        "candidate": {
            "official_repository_url": "https://github.com/Bahmni/openmrs-module-medicationadministration", "commit_sha": "acb65f75d3515b4f2b36083345bfbafc2ee146b0",
            "evidence_loci": ["Bahmni/openmrs-module-medicationadministration@acb65f75d3515b4f2b36083345bfbafc2ee146b0 :: api/src/main/java/org/openmrs/module/ipd/api/model/MedicationAdministration.java :: L17-L55"],
            "proven_slice": "Administration record shape with patient, encounter, drug, performer, order, status/reason, time, dose, route and notes.",
            "parity_limits": "No explicit create/save endpoint, authorization, corrections or five-rights safety controls."
        },
        "must_remain_unproved_reason": "Record shape does not prove the administration write boundary.",
    },
    {
        "working_key": "CAP-MED-WORKER-PRN-ADMINISTRATION",
        "adjudication_id": "fresh-901-wave2:CAP-MED-WORKER-PRN-ADMINISTRATION",
        "candidate_status": "documented_ncm_direct",
        "completion_credit_recommended": False,
        "search_terms": ["PRN medication worker administration reason effectiveness open source"],
        "rejected_repositories": [
            {"official_repository_url": "https://github.com/openmrs/openmrs-core", "commit_sha": "8bb5c2e9e36ab8fb09f0053786bc0f040775cc1e", "reason": "DrugOrder proves a PRN flag/condition, not a worker-performed administration event."},
            {"official_repository_url": "https://github.com/Bahmni/openmrs-module-medicationadministration", "commit_sha": "acb65f75d3515b4f2b36083345bfbafc2ee146b0", "reason": "The record shape does not establish PRN trigger, decision, effectiveness or worker action endpoint."}
        ],
        "must_remain_unproved_reason": "No exact PRN decision/administration candidate and insufficient bounded NCM corpus.",
    },
    {
        "working_key": "CAP-INC-SAFEGUARDING-TRIAGE-OWNERSHIP",
        "adjudication_id": "fresh-901-wave2:CAP-INC-SAFEGUARDING-TRIAGE-OWNERSHIP",
        "candidate_status": "candidate_found_partial",
        "completion_credit_recommended": False,
        "candidate": {
            "official_repository_url": "https://github.com/primeroIMS/primero", "commit_sha": "9cea249d502269c258028844884d51e9a89bd00a",
            "evidence_loci": [
                "primeroIMS/primero@9cea249d502269c258028844884d51e9a89bd00a :: app/controllers/api/v2/assigns_controller.rb :: L7-L48",
                "primeroIMS/primero@9cea249d502269c258028844884d51e9a89bd00a :: app/services/bulk_assign_service.rb :: L10-L43"
            ],
            "proven_slice": "Permission-gated owner assignment, actor/notes evidence, history and unassign action.",
            "parity_limits": "No concern-triage decision/state, sensitivity, subject-informed handling or Oblivion site/privacy proof."
        },
        "must_remain_unproved_reason": "Ownership is analogous; the safeguarding-triage boundary remains unproved.",
    },
]

keys = [row["working_key"] for row in evaluations]
recommended = sorted(row["working_key"] for row in evaluations if row["completion_credit_recommended"])
assert len(keys) == len(set(keys)) == 15
assert len(recommended) == 5
key_sha = hashlib.sha256("\n".join(sorted(keys)).encode("utf-8")).hexdigest()
recommended_sha = hashlib.sha256("\n".join(recommended).encode("utf-8")).hexdigest()
assert recommended_sha == "0af7d64a38f1c6eff12c5fb141790a37b380d1967fc51fc4f87546bc2574cc9b"

artifact = {
    "schema_version": "1.0.0",
    "artifact": "benchmark-target-specific-adjudication-901-wave2",
    "generated_at": "2026-08-13T10:24:00+12:00",
    "audited_repository": "<local-user>/Herd\\oblivionfindings",
    "audited_commit": COMMIT,
    "read_only": True,
    "scope": "Second bounded target-specific wave: 15 current unproved capabilities selected by finding priority, safety criticality or exact enriched route envelope.",
    "methodology": {
        "credit_rule": "Only a direct candidate with an official repository pinned to an immutable commit and exact source loci proving a material slice of the same target can receive credit.",
        "partial_rule": "Partial candidates and incomplete bounded NCM searches remain completion-unproved even when they add useful context.",
        "no_copy_rule": "Evidence is behavioural only; do not copy source, schema, UI, wording or distinctive layout.",
        "runtime_boundary": "No application tests, browser journeys, databases, queues, jobs, commits, pushes, deployments or product mutations were executed for this research wave."
    },
    "input_pins": {
        "working_capability_manifest_901": {"path": "evidence/source/working-capability-manifest-901.json", "file_sha256": MANIFEST_SHA},
        "benchmark_final_901_before_wave": {"path": "evidence/source/benchmark-final-901-mapping.json", "file_sha256": BASE_MAPPING_SHA}
    },
    "repository_snapshots": {
        "repo:snipe-it": {"url": "https://github.com/grokability/snipe-it", "commit_sha": SNIPE_SHA},
        "repo:bigcapital": {"url": "https://github.com/bigcapitalhq/bigcapital", "commit_sha": BIGCAP_SHA}
    },
    "counts": {"evaluated": 15, "completion_credit_recommended": 5, "remains_unproved": 10},
    "evaluations": evaluations,
    "integrity": {
        "evaluated_keys_unique": True,
        "evaluated_key_sha256": key_sha,
        "recommended_key_sha256": recommended_sha,
        "recommended_rows_have_repo_sha_and_loci": all(
            row.get("benchmark", {}).get("official_repository_url")
            and row.get("benchmark", {}).get("commit_sha")
            and row.get("benchmark", {}).get("source_loci")
            and row.get("evidence_loci")
            for row in evaluations if row["completion_credit_recommended"]
        ),
        "runtime_or_product_mutations": 0
    },
    "completion_gate": {"complete": False, "reason": "Five direct rows are recommended for credit; ten evaluated rows remain unproved and 611/901 will remain without completed benchmark/NCM decisions after integration."}
}

OUTPUT.write_text(json.dumps(artifact, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(json.dumps({"path": str(OUTPUT), "sha256": hashlib.sha256(OUTPUT.read_bytes()).hexdigest(), "counts": artifact["counts"], "recommended_key_sha256": recommended_sha}, indent=2))
