from __future__ import annotations

import hashlib
import json
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
RAW_OUTPUT = AUDIT_DIR / "evidence/benchmark/raw-run-145-agent-a-finance-invoice-fx-observed-behavior-wave-24.json"
SEALED_OUTPUT = AUDIT_DIR / "evidence/benchmark/sealed-run-145-agent-b-finance-invoice-fx-input-wave-24.json"

APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
CHECKPOINT_COMMIT = "3bc3aff5875e6be9fab8ff66bba6c4a30c1b1522"
CHECKPOINT_TREE = "580a50753b01bd97a0044d5d772413c63729cb66"
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
REGISTER_SHA256 = "cc493cd1807e62a9ffa27192c658400e697391b7a0baa3f0014628145c6b7b91"
COHORT_SHA256 = "f6522709277cadadabce1c01478fc7ed5f08e16cebc7fdf048a22a32149673e9"


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def canonical_bytes(value: object) -> bytes:
    return json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode("utf-8")


def pretty_bytes(value: object) -> bytes:
    return (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8")


def require_hash(relative_path: str, expected: str) -> None:
    actual = sha256((AUDIT_DIR / relative_path).read_bytes())
    assert actual == expected, f"{relative_path}: expected {expected}, got {actual}"


def write_exact(path: Path, value: object) -> tuple[int, str]:
    data = pretty_bytes(value)
    if path.exists():
        assert path.read_bytes() == data, f"Refusing to overwrite different bytes: {path}"
    else:
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
    return len(data), sha256(data)


require_hash("03-feature-to-benchmark-matrix.csv", MATRIX_SHA256)
require_hash("06-open-source-benchmark-register.csv", REGISTER_SHA256)

feature_ids = [
    "CAP-FIN-BILLING-INVOICE-LIFECYCLE",
    "CAP-FIN-FX-REVALUATION",
]
assert sha256(("\n".join(sorted(feature_ids)) + "\n").encode("utf-8")) == COHORT_SHA256

project_records = {
    "ERP": {
        "project": "frappe/erpnext",
        "canonical_url": "https://github.com/frappe/erpnext",
        "inspected_ref": "v16.33.0",
        "commit_sha": "b24c9eba551905e256e336ff170a91a92d197a2f",
        "release_published_at": "2026-08-25T17:04:34Z",
        "inspected_date": "2026-08-26",
        "root_licence": "Root license.txt contains GPLv3 text; -only or -or-later semantics are not inferred from that root file alone.",
        "root_licence_url": "https://github.com/frappe/erpnext/blob/b24c9eba551905e256e336ff170a91a92d197a2f/license.txt",
        "root_licence_bytes": 35149,
        "root_licence_sha256": "3972dc9744f6499f0f9b2dbf76696f2ae7ad8af9b23dde66d6af86c9dfb36986",
        "edition_boundary": "Public GPL ERPNext repository at the pinned release only; Frappe Cloud, private extensions, payment providers, and uncited Frappe Framework behavior are excluded.",
    },
    "DOL": {
        "project": "Dolibarr/dolibarr",
        "canonical_url": "https://github.com/Dolibarr/dolibarr",
        "inspected_ref": "24.0.0",
        "tag_object_sha": "5dd1b29feb8014839b54bb0f48d988eeac3c61dd",
        "commit_sha": "769c7db907099643558e77d7002c109cfda919e5",
        "release_published_at": "2026-08-20T19:49:15Z",
        "inspected_date": "2026-08-26",
        "root_licence": "Pinned root COPYING contains GNU General Public License version 3 text; inspected source headers separately state GPL-3.0-or-later; the root file alone does not establish an SPDX suffix.",
        "root_licence_url": "https://github.com/Dolibarr/dolibarr/blob/769c7db907099643558e77d7002c109cfda919e5/COPYING",
        "root_licence_bytes": 35151,
        "root_licence_sha256": "e79e9c8a0c85d735ff98185918ec94ed7d175efc377012787aebcf3b80f0d90b",
        "edition_boundary": "Public Dolibarr core repository only; DoliStore modules, hosted services, and external payment providers are excluded.",
    },
    "BIG": {
        "project": "bigcapitalhq/bigcapital",
        "canonical_url": "https://github.com/bigcapitalhq/bigcapital",
        "inspected_ref": "v0.25.33",
        "commit_sha": "41033239e0f93e4fc6cf1832743ae6bdbab25306",
        "release_published_at": "2026-08-24T19:31:45Z",
        "inspected_date": "2026-08-26",
        "root_licence": "Root LICENSE contains AGPLv3 text; -only or -or-later semantics are not inferred from that root file alone.",
        "root_licence_url": "https://github.com/bigcapitalhq/bigcapital/blob/41033239e0f93e4fc6cf1832743ae6bdbab25306/LICENSE",
        "root_licence_bytes": 34282,
        "root_licence_sha256": "6222fdf64728d91bef797fae1c610f97737df67fd07026b7a83d661252fdade6",
        "edition_boundary": "Public repository only; hosted cloud, payment-provider behavior, and deployment services are excluded. Upstream multi-organisation abstractions are not transferable.",
    },
}

source_files = {
    "ERP_FX": {
        "path": "erpnext/accounts/doctype/exchange_rate_revaluation/exchange_rate_revaluation.py",
        "url": "https://github.com/frappe/erpnext/blob/b24c9eba551905e256e336ff170a91a92d197a2f/erpnext/accounts/doctype/exchange_rate_revaluation/exchange_rate_revaluation.py",
        "bytes": 23540,
        "sha256": "5a150de600679229dfeb5167aab3edf7d69c92756ed4f0dc8b4469674b391923",
        "git_blob_sha": "3d45d0445ff3aebba42ed94464a50614ba98cc82",
        "lines": 736,
    },
    "ERP_INV": {
        "path": "erpnext/accounts/doctype/sales_invoice/sales_invoice.py",
        "url": "https://github.com/frappe/erpnext/blob/b24c9eba551905e256e336ff170a91a92d197a2f/erpnext/accounts/doctype/sales_invoice/sales_invoice.py",
        "bytes": 105952,
        "sha256": "28375699a7560c88b881e2c3a80f195a8f8d457a75ed52a8e3483d8eeacae8cf",
        "lines": 3245,
    },
    "ERP_INV_JS": {
        "path": "erpnext/accounts/doctype/sales_invoice/sales_invoice.js",
        "url": "https://github.com/frappe/erpnext/blob/b24c9eba551905e256e336ff170a91a92d197a2f/erpnext/accounts/doctype/sales_invoice/sales_invoice.js",
        "bytes": 32484,
        "sha256": "9d56f15d5f088fc0552fd7a9f3c1c06cbde95b3e6a158feb12314c4d553c239d",
        "lines": 1258,
    },
    "ERP_PAY_REQ": {
        "path": "erpnext/accounts/doctype/payment_request/payment_request.py",
        "url": "https://github.com/frappe/erpnext/blob/b24c9eba551905e256e336ff170a91a92d197a2f/erpnext/accounts/doctype/payment_request/payment_request.py",
        "bytes": 36909,
        "sha256": "1eb339edfa2f338fd84c755fecdee69f17dbec78fc7415369e314957059bb487",
        "lines": 1207,
    },
    "DOL_INV": {
        "path": "htdocs/compta/facture/class/facture.class.php",
        "url": "https://github.com/Dolibarr/dolibarr/blob/769c7db907099643558e77d7002c109cfda919e5/htdocs/compta/facture/class/facture.class.php",
        "bytes": 253633,
        "sha256": "6f215bbe0441800567c7f2c6d056d1da26cea93048d20a2afb1ed520e0a4f084",
        "lines": 6594,
    },
    "DOL_CARD": {
        "path": "htdocs/compta/facture/card.php",
        "url": "https://github.com/Dolibarr/dolibarr/blob/769c7db907099643558e77d7002c109cfda919e5/htdocs/compta/facture/card.php",
        "bytes": 318498,
        "sha256": "7052ebae3e6bb8d4cb98d0b5160950205664898cfad913d358d8b0fc7bf3cd2f",
        "lines": 7113,
    },
    "DOL_MAIL": {
        "path": "htdocs/core/actions_sendmails.inc.php",
        "url": "https://github.com/Dolibarr/dolibarr/blob/769c7db907099643558e77d7002c109cfda919e5/htdocs/core/actions_sendmails.inc.php",
        "bytes": 22795,
        "sha256": "507a4ee516f49d1a9bda97e2876159a8e945859102e201f0a5408f9b67d273a8",
        "lines": 564,
    },
    "DOL_PAYMENT": {
        "path": "htdocs/compta/paiement.php",
        "url": "https://github.com/Dolibarr/dolibarr/blob/769c7db907099643558e77d7002c109cfda919e5/htdocs/compta/paiement.php",
        "bytes": 43204,
        "sha256": "2d09579ed68c87e3ef59b84a871d428dc2e003973d9dab87e6f002ffd567422d",
        "lines": 1083,
    },
    "BIG_CONTROLLER": {
        "path": "packages/server/src/modules/SaleInvoices/SaleInvoices.controller.ts",
        "url": "https://github.com/bigcapitalhq/bigcapital/blob/41033239e0f93e4fc6cf1832743ae6bdbab25306/packages/server/src/modules/SaleInvoices/SaleInvoices.controller.ts",
        "bytes": 13608,
        "sha256": "d3da8c26f9f7754c924dd838292227cded08e4c92544177198b33b301a4ca477",
        "lines": 423,
    },
    "BIG_CREATE": {
        "path": "packages/server/src/modules/SaleInvoices/commands/CreateSaleInvoice.service.ts",
        "url": "https://github.com/bigcapitalhq/bigcapital/blob/41033239e0f93e4fc6cf1832743ae6bdbab25306/packages/server/src/modules/SaleInvoices/commands/CreateSaleInvoice.service.ts",
        "bytes": 5471,
        "sha256": "81c87b457e3632d35866c5750266feeeecd91484d0444ccb35bf20449b7a3804",
        "lines": 138,
    },
    "BIG_MAIL": {
        "path": "packages/server/src/modules/SaleInvoices/commands/SendSaleInvoiceMail.ts",
        "url": "https://github.com/bigcapitalhq/bigcapital/blob/41033239e0f93e4fc6cf1832743ae6bdbab25306/packages/server/src/modules/SaleInvoices/commands/SendSaleInvoiceMail.ts",
        "bytes": 4921,
        "sha256": "80c558788ebbafd8c317a91ce6b818e14a99256abbe2170eca493d705fd4fec5",
        "lines": 149,
    },
    "BIG_MAIL_SENT": {
        "path": "packages/server/src/modules/SaleInvoices/subscribers/InvoiceChangeStatusOnMailSentSubscriber.ts",
        "url": "https://github.com/bigcapitalhq/bigcapital/blob/41033239e0f93e4fc6cf1832743ae6bdbab25306/packages/server/src/modules/SaleInvoices/subscribers/InvoiceChangeStatusOnMailSentSubscriber.ts",
        "bytes": 1156,
        "sha256": "30ecc526ee9a8aee4829dc6e44179ccaeb054572c8b2a8529142627c7ea9ae6a",
        "git_blob_sha": "7deba1495dd62fa658af01de4d15399306c3ba4b",
        "lines": 36,
    },
    "BIG_DELIVER": {
        "path": "packages/server/src/modules/SaleInvoices/commands/DeliverSaleInvoice.service.ts",
        "url": "https://github.com/bigcapitalhq/bigcapital/blob/41033239e0f93e4fc6cf1832743ae6bdbab25306/packages/server/src/modules/SaleInvoices/commands/DeliverSaleInvoice.service.ts",
        "bytes": 3458,
        "sha256": "bef51623509cf7fee99deb8e455844ec5eeb6e736c43514c2bc631a4b6d3775e",
        "lines": 94,
    },
    "BIG_DELETE": {
        "path": "packages/server/src/modules/SaleInvoices/commands/DeleteSaleInvoice.service.ts",
        "url": "https://github.com/bigcapitalhq/bigcapital/blob/41033239e0f93e4fc6cf1832743ae6bdbab25306/packages/server/src/modules/SaleInvoices/commands/DeleteSaleInvoice.service.ts",
        "bytes": 6314,
        "sha256": "cb34e7fc8c3c5fb83af441ac7c8cf6cf7a1fabd00ba703e764a6bb790ae070df",
        "lines": 169,
    },
    "BIG_PAYMENT": {
        "path": "packages/server/src/modules/PaymentReceived/commands/PaymentReceivedInvoiceSync.service.ts",
        "url": "https://github.com/bigcapitalhq/bigcapital/blob/41033239e0f93e4fc6cf1832743ae6bdbab25306/packages/server/src/modules/PaymentReceived/commands/PaymentReceivedInvoiceSync.service.ts",
        "bytes": 1500,
        "sha256": "9558694389a1e764fe659c38b2588561bf0b7a61675d7d711a805cebd9644637",
        "lines": 47,
    },
}


def observation(identifier: str, category: str, text: str) -> dict[str, str]:
    return {"id": identifier, "category": category, "observation": text}


packets = [
    {
        "payload": {
            "schema": "IDENTITY_STRIPPED_OBSERVED_BEHAVIOR_PACKET_V1",
            "packet_id": "OBS-FX-145-A",
            "capability_handle": "financial_exchange_rate_revaluation",
            "observations": [
                observation("FX01", "entry_point", "A financial revaluation record accepts an organisational ledger context and reporting/posting date before balances are fetched."),
                observation("FX02", "role_authority", "Creating revaluation journals requires separate journal-write authority; inspecting the record alone does not establish that authority."),
                observation("FX03", "information", "Eligible balance-sheet accounts held in a currency different from the base currency are enumerated, excluding group, stock, and same-currency accounts."),
                observation("FX04", "information", "Each row presents foreign-currency balance, current base-currency balance, current exchange rate, new exchange rate, new base-currency balance, and calculated gain or loss."),
                observation("FX05", "step", "The new base-currency balance is calculated from the unchanged foreign-currency amount and a date-effective exchange rate; gain/loss is the difference from the current base-currency balance."),
                observation("FX06", "branch", "Rows with no foreign-currency or base-currency balance are handled separately so residual carrying balances can be cleared rather than treated as ordinary revaluations."),
                observation("FX07", "validation", "Organisational ledger context and date are mandatory; rounding-loss allowance must be at least zero and below one."),
                observation("FX08", "validation", "Submission removes rows without gain/loss and fails if no gain/loss row remains."),
                observation("FX09", "feedback", "When no qualifying balances exist, the actor is told either that no outstanding items need revaluation or none were found."),
                observation("FX10", "safeguard", "A configured unrealised gain/loss account is required before journals can be created."),
                observation("FX11", "output", "The action creates attributable multi-currency journal entries linked back to the revaluation record and calculates balanced debit/credit totals."),
                observation("FX12", "output_feedback", "Successful creation reports links to the created ordinary revaluation journal and, where applicable, a zero-balance journal."),
                observation("FX13", "recovery", "The record can locate posted linked journals and determine whether their booked gain/loss equals the revaluation total, and separately whether reversals have posted."),
                observation("FX14", "recovery", "A reversal action creates draft reversals only for posted linked journals that do not already have reversals; an existing draft reversal blocks duplicate creation and is surfaced to the actor."),
                observation("FX15", "state_unknown", "Draft, submitted, linked-journal, and draft-reversal behavior is observed, but final posting orchestration, provider-rate provenance, idempotency across retries, concurrent execution, approval separation, durable recovery, accessibility, and full authorization scope are not established."),
                observation("FX16", "boundary", "The observed organisational ledger scope is not evidence of any site-level access model, direct-object concealment, privacy boundary, or supported-living workflow."),
            ],
            "counts": {"observations": 16, "identity_keys": 0, "implementation_details": 0, "mapping_or_credit_claims": 0},
            "attestation": {"identity_stripped": True, "observed_behavior_only": True, "unknowns_retained": True, "zero_credit": True},
        },
        "reattachment_appendix": {
            "source_project_keys": ["ERP"],
            "source_file_keys": ["ERP_FX"],
            "claim_bindings": {
                "FX01": ["ERP_FX:73-75", "ERP_FX:158-210"],
                "FX02": ["ERP_FX:368-379", "ERP_FX:597-600", "ERP_FX:685-694"],
                "FX03": ["ERP_FX:185-210"],
                "FX04": ["ERP_FX:276-349"],
                "FX05": ["ERP_FX:283-307"],
                "FX06": ["ERP_FX:310-347", "ERP_FX:386-497"],
                "FX07": ["ERP_FX:42-48", "ERP_FX:73-75"],
                "FX08": ["ERP_FX:77-90"],
                "FX09": ["ERP_FX:351-356"],
                "FX10": ["ERP_FX:358-366"],
                "FX11": ["ERP_FX:499-595"],
                "FX12": ["ERP_FX:368-384"],
                "FX13": ["ERP_FX:95-156"],
                "FX14": ["ERP_FX:597-639"],
                "FX15": ["ERP_FX:42-639"],
                "FX16": ["ROOT_ARCHITECTURE_BOUNDARY"],
            },
        },
    },
    {
        "payload": {
            "schema": "IDENTITY_STRIPPED_OBSERVED_BEHAVIOR_PACKET_V1",
            "packet_id": "OBS-INV-145-A1",
            "capability_handle": "invoice_lifecycle_candidate_one",
            "observations": [
                observation("I101", "entry_point", "An actor can create an invoice as a draft and later submit it."),
                observation("I102", "states", "Draft, Submitted, Unpaid, Partly Paid, Paid, Overdue, and Cancelled are explicitly represented."),
                observation("I103", "safeguard_output", "Submission applies approval control and produces accounting effects; stock effects may also apply for stock-bearing invoices."),
                observation("I104", "branch", "A submitted invoice offers payment capture and a payment-request action."),
                observation("I105", "handoff_feedback", "A payment request can send an attached invoice or payment link using a deduplicated after-commit job."),
                observation("I106", "recovery", "Cancellation reverses relevant effects, cancels ledger entries, and changes the invoice to Cancelled."),
                observation("I107", "unknowns", "The observed behavior does not establish target-specific site access, privacy, direct-object concealment, retry semantics beyond the send job, accessibility, or supported-living terminology."),
            ],
            "counts": {"observations": 7, "identity_keys": 0, "implementation_details": 0, "mapping_or_credit_claims": 0},
            "attestation": {"identity_stripped": True, "observed_behavior_only": True, "unknowns_retained": True, "zero_credit": True},
        },
        "reattachment_appendix": {
            "source_project_keys": ["ERP"],
            "source_file_keys": ["ERP_INV", "ERP_INV_JS", "ERP_PAY_REQ"],
            "claim_bindings": {
                "I101": ["ERP_INV:469-477", "ERP_INV:2271-2312"],
                "I102": ["ERP_INV:207-220", "ERP_INV:2271-2312"],
                "I103": ["ERP_INV:469-520"],
                "I104": ["ERP_INV_JS:97-145"],
                "I105": ["ERP_PAY_REQ:438-465"],
                "I106": ["ERP_INV:599-645", "ERP_INV:2271-2312"],
                "I107": ["ROOT_LIMITATION_PRESERVATION"],
            },
        },
    },
    {
        "payload": {
            "schema": "IDENTITY_STRIPPED_OBSERVED_BEHAVIOR_PACKET_V1",
            "packet_id": "OBS-INV-145-A2",
            "capability_handle": "invoice_lifecycle_candidate_two",
            "observations": [
                observation("I201", "states", "Customer invoices have Draft, Validated, Closed, and Abandoned states."),
                observation("I202", "entry_point", "Creation begins in Draft."),
                observation("I203", "payment", "A paid invoice is explicitly closed."),
                observation("I204", "cancellation", "An unpaid invoice can be abandoned with a cancellation event; correction of a paid invoice is a distinct credit-note path rather than the same cancellation."),
                observation("I205", "handoff_audit", "Successful invoice email delivery increments a send counter and emits a delivery event."),
                observation("I206", "payment", "Payment creation distributes amounts across invoices and may close invoices that become paid."),
                observation("I207", "unknowns", "Exact equivalence between abandonment, cancellation, credit-note correction, and the target lifecycle is not established; site, privacy, direct-object, retry, and accessibility parity are also not established."),
            ],
            "counts": {"observations": 7, "identity_keys": 0, "implementation_details": 0, "mapping_or_credit_claims": 0},
            "attestation": {"identity_stripped": True, "observed_behavior_only": True, "unknowns_retained": True, "zero_credit": True},
        },
        "reattachment_appendix": {
            "source_project_keys": ["DOL"],
            "source_file_keys": ["DOL_INV", "DOL_CARD", "DOL_MAIL", "DOL_PAYMENT"],
            "claim_bindings": {
                "I201": ["DOL_INV:408-460"],
                "I202": ["DOL_INV:489-513"],
                "I203": ["DOL_INV:3357-3400"],
                "I204": ["DOL_INV:3476-3534", "DOL_INV:5360-5386"],
                "I205": ["DOL_CARD:3644-3658", "DOL_MAIL:444-503"],
                "I206": ["DOL_PAYMENT:230-312"],
                "I207": ["ROOT_LIMITATION_PRESERVATION"],
            },
        },
    },
    {
        "payload": {
            "schema": "IDENTITY_STRIPPED_OBSERVED_BEHAVIOR_PACKET_V1",
            "packet_id": "OBS-INV-145-ADJ",
            "capability_handle": "adjacent_invoice_evidence_not_exact_match",
            "observations": [
                observation("IA01", "safeguard", "Create, mail, delete, deliver, write-off, and payment-history actions are permission guarded."),
                observation("IA02", "output", "Invoice creation and its related journal work share one unit of work."),
                observation("IA03", "handoff_recovery", "Invoice email is queued, may attach a PDF, and marks the invoice delivered only after transport succeeds."),
                observation("IA04", "safeguard", "Delivery locks the invoice and rejects duplicate delivery."),
                observation("IA05", "payment", "Payment allocation updates the invoice paid amount."),
                observation("IA06", "safeguard", "Deletion locks the invoice and rejects deletion when payments or applied credits exist."),
                observation("IA07", "limitation", "The observed slice proves deletion and write-off behavior, not a submitted-invoice cancellation or reversal lifecycle, so it is adjacent evidence only."),
                observation("IA08", "boundary", "Any upstream multi-organisation abstraction is excluded; no site, access, privacy, or supported-living semantics may be inferred."),
            ],
            "counts": {"observations": 8, "identity_keys": 0, "implementation_details": 0, "mapping_or_credit_claims": 0},
            "attestation": {"identity_stripped": True, "observed_behavior_only": True, "unknowns_retained": True, "adjacent_only": True, "zero_credit": True},
        },
        "reattachment_appendix": {
            "source_project_keys": ["BIG"],
            "source_file_keys": ["BIG_CONTROLLER", "BIG_CREATE", "BIG_MAIL", "BIG_MAIL_SENT", "BIG_DELIVER", "BIG_DELETE", "BIG_PAYMENT"],
            "claim_bindings": {
                "IA01": ["BIG_CONTROLLER:105-173", "BIG_CONTROLLER:279-359"],
                "IA02": ["BIG_CREATE:52-125"],
                "IA03": ["BIG_MAIL:39-148", "BIG_MAIL_SENT:10-33"],
                "IA04": ["BIG_DELIVER:35-92"],
                "IA05": ["BIG_PAYMENT:16-43"],
                "IA06": ["BIG_DELETE:53-98"],
                "IA07": ["ROOT_ADJACENCY_LIMIT"],
                "IA08": ["ROOT_ARCHITECTURE_BOUNDARY"],
            },
        },
    },
]

assert len(packets) == 4
assert sum(packet["payload"]["counts"]["observations"] for packet in packets) == 38
assert {packet["payload"]["packet_id"] for packet in packets} == {
    "OBS-FX-145-A",
    "OBS-INV-145-A1",
    "OBS-INV-145-A2",
    "OBS-INV-145-ADJ",
}

for packet in packets:
    payload = packet["payload"]
    observation_ids = {row["id"] for row in payload["observations"]}
    assert len(observation_ids) == payload["counts"]["observations"]
    assert observation_ids == set(packet["reattachment_appendix"]["claim_bindings"])
    payload_bytes = canonical_bytes(payload)
    packet["reattachment_appendix"]["payload_seal"] = {
        "algorithm": "SHA-256",
        "canonical_bytes": "UTF-8 compact JSON; no BOM; no terminal newline",
        "bytes": len(payload_bytes),
        "sha256": sha256(payload_bytes),
    }

script_relative = str(Path(__file__).resolve().relative_to(AUDIT_DIR)).replace("\\", "/")
script_bytes = Path(__file__).read_bytes()

raw = {
    "schema_version": "raw_run_145_agent_a_finance_invoice_fx_observed_behavior_wave_24_v1",
    "run_id": "RUN-145-A-FINANCE-INVOICE-FX-OBSERVED-BEHAVIOR-WAVE-24",
    "status": "FOUR_IDENTITY_STRIPPED_OBSERVED_BEHAVIOR_PACKETS_COMPLETE_ZERO_DOWNSTREAM_CREDIT",
    "generated_on": "2026-08-26",
    "agent_role": "upstream_observed_behavior_extractor",
    "pins": {
        "checkpoint_commit": CHECKPOINT_COMMIT,
        "checkpoint_tree": CHECKPOINT_TREE,
        "application_commit": APPLICATION_COMMIT,
        "application_tree": APPLICATION_TREE,
        "prompt_sha256": PROMPT_SHA256,
        "matrix_sha256": MATRIX_SHA256,
        "benchmark_register_sha256": REGISTER_SHA256,
        "selected_feature_id_sha256": COHORT_SHA256,
        "generator": script_relative,
        "generator_sha256": sha256(script_bytes),
    },
    "canonical_cohort_reattachment_by_root": {
        "feature_ids": feature_ids,
        "count": 2,
        "sha256": COHORT_SHA256,
        "rule": "Canonical target identity is excluded from every Agent-B payload and is retained here only for later root reattachment.",
    },
    "architecture_boundary": "Oblivion Findings is one operating organisation across multiple Sites. Upstream Company or organisation abstractions do not establish or replace approved-Site access, exact permissions, canonical ownership, direct-object concealment, privacy, supported-living terminology, or native workflow design.",
    "project_records": project_records,
    "source_files": source_files,
    "packets": packets,
    "excluded_inputs": {
        "run_071b_readiness_packet": "EXCLUDED_NO_GO_FACET_HASH_MISBINDING_NOT_NEEDED_FOR_THIS_CHAIN",
        "historical_mapping_or_credit": "EXCLUDED",
        "working_tree_application_source": "EXCLUDED_FROM_AGENT_A",
        "benchmark_repository_execution": "PROHIBITED_AND_NOT_PERFORMED",
    },
    "counts": {
        "canonical_targets": 2,
        "identity_stripped_packets": 4,
        "observations": 38,
        "direct_candidate_project_records": 2,
        "adjacent_project_records": 1,
        "total_project_records": 3,
        "selected_benchmarks": 0,
        "documented_no_credible_matches": 0,
        "mapping_credit_awards": 0,
        "completion_credit_awards": 0,
    },
    "credit_boundary": {
        "upstream_observer_evidence": True,
        "neutral_requirements": False,
        "current_product_comparison": False,
        "benchmark_selection": False,
        "NCM": False,
        "mapping": False,
        "runtime": False,
        "browser": False,
        "pass": False,
        "completion": False,
        "audit_complete": False,
    },
    "attestation": {
        "official_primary_sources_only": True,
        "release_tag_and_commit_rechecked": True,
        "licence_and_edition_boundaries_recorded": True,
        "no_benchmark_source_or_asset_copied": True,
        "no_application_source_read_by_agent_a": True,
        "identity_stripped_payloads": True,
        "unknowns_and_adjacency_limits_retained": True,
        "audit_artifact_writes_by_root_only": True,
        "zero_downstream_credit": True,
    },
}

raw_bytes, raw_hash = write_exact(RAW_OUTPUT, raw)

sealed_payloads = []
for packet in packets:
    payload = packet["payload"]
    payload_bytes = canonical_bytes(payload)
    seal = packet["reattachment_appendix"]["payload_seal"]
    assert (len(payload_bytes), sha256(payload_bytes)) == (seal["bytes"], seal["sha256"])
    sealed_payloads.append({"payload_seal": seal, "payload": payload})
sealed_payloads.sort(key=lambda item: item["payload"]["packet_id"])

sealed = {
    "schema_version": "sealed_run_145_agent_b_finance_invoice_fx_input_wave_24_v1",
    "run_id": "RUN-145-B-INPUT-FINANCE-INVOICE-FX-WAVE-24",
    "status": "IDENTITY_STRIPPED_OBSERVED_BEHAVIOR_ONLY",
    "allowed_operation": "Derive source-independent user needs, states, safeguards, feedback, recovery, hand-off, output, and acceptance criteria while preserving every unknown and adjacency limit.",
    "prohibited_context": [
        "upstream repository, project, release, source path, source wording, implementation, licence, or edition identity",
        "canonical target or product identity",
        "current-product source or behavior",
        "old comparisons, mappings, selections, NCM decisions, or credit",
        "network, browser, filesystem, repository, runtime, tests, build, or database",
    ],
    "input_payloads": sealed_payloads,
    "counts": {
        "payloads": 4,
        "observations": 38,
        "identity_keys": 0,
        "implementation_details": 0,
        "current_product_comparisons": 0,
        "credit_awards": 0,
    },
    "attestation": {
        "source_reattachment_appendices_excluded": True,
        "canonical_target_reattachment_excluded": True,
        "identity_stripped": True,
        "unknowns_retained": True,
        "zero_credit": True,
    },
}

sealed_bytes, sealed_hash = write_exact(SEALED_OUTPUT, sealed)

print(f"{RAW_OUTPUT.relative_to(AUDIT_DIR)}\t{raw_bytes}\t{raw_hash}")
print(f"{SEALED_OUTPUT.relative_to(AUDIT_DIR)}\t{sealed_bytes}\t{sealed_hash}")
