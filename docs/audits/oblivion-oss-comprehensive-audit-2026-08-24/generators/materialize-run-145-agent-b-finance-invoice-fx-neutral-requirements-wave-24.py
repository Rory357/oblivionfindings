from __future__ import annotations

import csv
import hashlib
import json
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
SEALED_B_INPUT = AUDIT_DIR / "evidence/benchmark/sealed-run-145-agent-b-finance-invoice-fx-input-wave-24.json"
RAW_B_OUTPUT = AUDIT_DIR / "evidence/benchmark/raw-run-145-agent-b-finance-invoice-fx-neutral-requirements-wave-24.json"
SEALED_C_OUTPUT = AUDIT_DIR / "evidence/benchmark/sealed-run-145-agent-c-finance-invoice-fx-current-comparison-input-wave-24.json"

EXPECTED_B_INPUT_SHA256 = "98fcf8f7ac5e16c1aadd2b3902dcfb9a1991cf92f366ec1f411824b4e118ca03"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
REGISTER_SHA256 = "cc493cd1807e62a9ffa27192c658400e697391b7a0baa3f0014628145c6b7b91"
COHORT_SHA256 = "f6522709277cadadabce1c01478fc7ed5f08e16cebc7fdf048a22a32149673e9"
FX_SOURCE_PACKET_SHA256 = "f32b3d997a9e7dd932e041f5acf30dea02ee5b62fee3b0901cfbe5cc59f2ed0a"
INVOICE_SOURCE_PACKET_SHA256 = "005a55c952ec3f3b2a5bac9f3c99000fa4eae65a488764dfd1f4662063431701"
MATRIX_PATH = AUDIT_DIR / "03-feature-to-benchmark-matrix.csv"
REGISTER_PATH = AUDIT_DIR / "06-open-source-benchmark-register.csv"
COHORT_FEATURE_IDS = [
    "CAP-FIN-BILLING-INVOICE-LIFECYCLE",
    "CAP-FIN-FX-REVALUATION",
]


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def pretty_bytes(value: object) -> bytes:
    return (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8")


def write_exact(path: Path, value: object) -> tuple[int, str]:
    data = pretty_bytes(value)
    if path.exists():
        assert path.read_bytes() == data, f"Refusing to overwrite different bytes: {path}"
    else:
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
    return len(data), sha256(data)


def verified_file_seal(path: Path, expected_sha256: str) -> dict[str, object]:
    data = path.read_bytes()
    actual = sha256(data)
    assert actual == expected_sha256, f"{path}: expected {expected_sha256}, got {actual}"
    return {
        "path": str(path.relative_to(AUDIT_DIR)).replace("\\", "/"),
        "bytes": len(data),
        "sha256": actual,
    }


def requirement(identifier: str, text: str, sources: list[str]) -> dict[str, object]:
    return {"id": identifier, "requirement": text, "source_observation_ids": sources}


def criterion(identifier: str, text: str, sources: list[str]) -> dict[str, object]:
    return {"id": identifier, "criterion": text, "source_observation_ids": sources}


input_bytes = SEALED_B_INPUT.read_bytes()
assert sha256(input_bytes) == EXPECTED_B_INPUT_SHA256
sealed_input = json.loads(input_bytes)
assert sealed_input["counts"] == {
    "payloads": 4,
    "observations": 38,
    "identity_keys": 0,
    "implementation_details": 0,
    "current_product_comparisons": 0,
    "credit_awards": 0,
}

matrix_seal = verified_file_seal(MATRIX_PATH, MATRIX_SHA256)
register_seal = verified_file_seal(REGISTER_PATH, REGISTER_SHA256)
with MATRIX_PATH.open("r", encoding="utf-8-sig", newline="") as handle:
    matrix_rows = list(csv.DictReader(handle))
assert len(matrix_rows) == 340
assert len({item["feature_id"] for item in matrix_rows}) == 340
matrix_target_rows: dict[str, dict[str, str]] = {}
for feature_id in COHORT_FEATURE_IDS:
    matches = [item for item in matrix_rows if item["feature_id"] == feature_id]
    assert len(matches) == 1, f"Expected exactly one canonical matrix row for {feature_id}"
    source = matches[0]
    matrix_target_rows[feature_id] = {
        "feature_id": source["feature_id"],
        "module": source["module"],
        "user_job": source["user_job"],
        "feature_class": source["feature_class"],
        "benchmark_mapping_credit": source["benchmark_mapping_credit"],
        "completion_status": source["completion_status"],
    }
    assert source["benchmark_mapping_credit"].lower() == "false"
cohort_bytes = ("\n".join(sorted(COHORT_FEATURE_IDS)) + "\n").encode("utf-8")
assert sha256(cohort_bytes) == COHORT_SHA256
generator_path = Path(__file__).resolve()
generator_bytes = generator_path.read_bytes()
root_provenance_seals = {
    "role": "ROOT_ONLY_MATERIALIZATION_AND_CANONICAL_TARGET_REATTACHMENT_NOT_AGENT_B_SEMANTIC_INPUT",
    "canonical_matrix": {**matrix_seal, "content_embedded": False},
    "benchmark_register": {
        **register_seal,
        "content_embedded": False,
        "semantic_input_to_agent_b_or_agent_c": False,
    },
    "canonical_target_cohort": {
        "feature_ids": COHORT_FEATURE_IDS,
        "count": len(COHORT_FEATURE_IDS),
        "canonical_bytes": "Sorted feature IDs joined by LF with terminal LF",
        "bytes": len(cohort_bytes),
        "sha256": COHORT_SHA256,
        "each_feature_id_occurs_exactly_once_in_pinned_matrix": True,
        "matrix_target_rows": [matrix_target_rows[feature_id] for feature_id in COHORT_FEATURE_IDS],
    },
    "generator": {
        "path": str(generator_path.relative_to(AUDIT_DIR)).replace("\\", "/"),
        "bytes": len(generator_bytes),
        "sha256": sha256(generator_bytes),
    },
}

capabilities = [
    {
        "capability_id": "NEUTRAL-FX-145",
        "input_packet_ids": ["OBS-FX-145-A"],
        "MUST": [
            requirement("FX-M01", "The revaluation flow must capture an organisational ledger context and reporting or posting date before retrieving balances.", ["FX01"]),
            requirement("FX-M02", "Creating revaluation journals must require journal-write authority distinct from permission to inspect the revaluation record.", ["FX02"]),
            requirement("FX-M03", "The flow must enumerate balance-sheet accounts held in a currency different from the base currency, while excluding group, stock, and same-currency accounts.", ["FX03"]),
            requirement("FX-M04", "Each eligible row must expose the foreign-currency balance, current base-currency balance, current exchange rate, new exchange rate, new base-currency balance, and calculated gain or loss.", ["FX04"]),
            requirement("FX-M05", "The new base-currency balance must be calculated from the unchanged foreign-currency amount and an exchange rate effective for the selected date; gain or loss must equal the difference from the current base-currency balance.", ["FX05"]),
            requirement("FX-M06", "A row with no foreign-currency balance or no base-currency balance must be handled separately so a residual carrying balance can be cleared instead of being processed as an ordinary revaluation.", ["FX06"]),
            requirement("FX-M07", "Organisational ledger context and date must be present, and the rounding-loss allowance must satisfy 0 <= allowance < 1.", ["FX07"]),
            requirement("FX-M08", "Submission must remove rows without a gain or loss and must fail when no gain-or-loss row remains.", ["FX08"]),
            requirement("FX-M09", "When no qualifying balances exist, the actor must receive feedback stating either that no outstanding items require revaluation or that none were found.", ["FX09"]),
            requirement("FX-M10", "Journal creation must be blocked unless an unrealised gain or loss account is configured.", ["FX10"]),
            requirement("FX-M11", "Successful action must create attributable multi-currency journal entries linked to the revaluation record, with balanced debit and credit totals.", ["FX11"]),
            requirement("FX-M12", "Success feedback must provide access to the ordinary revaluation journal and, when one was created, the zero-balance journal.", ["FX12"]),
            requirement("FX-M13", "The record must locate posted linked journals, determine whether their booked gain or loss equals the revaluation total, and independently determine whether reversals have posted.", ["FX13"]),
            requirement("FX-M14", "A reversal action must create draft reversals only for posted linked journals that have no reversal; an existing draft reversal must prevent duplicate creation and be surfaced to the actor.", ["FX14"]),
            requirement("FX-M15", "The flow must distinguish the observed draft and submitted record conditions, linked-journal condition, and draft-reversal condition wherever their corresponding behavior is available; no additional transition ordering is implied.", ["FX15"]),
        ],
        "SHOULD": [],
        "NOT_ESTABLISHED": [
            requirement("FX-N01", "Final posting orchestration, exchange-rate provenance, retry idempotency, concurrent execution behavior, approval separation, durable recovery, accessibility, and the complete authorization scope are not established.", ["FX15"]),
            requirement("FX-N02", "Organisational ledger scope does not establish any site-level access model, direct-object concealment, privacy boundary, or supported-living workflow.", ["FX16"]),
        ],
        "acceptance_criteria": [
            criterion("FX-AC01", "Balance retrieval cannot proceed until both organisational ledger context and date are supplied; omission produces validation feedback.", ["FX01", "FX07"]),
            criterion("FX-AC02", "An actor able only to inspect the record cannot create its journals without the separately required journal-write authority.", ["FX02"]),
            criterion("FX-AC03", "Given a mixture of eligible and excluded accounts, only foreign-currency balance-sheet accounts outside the group, stock, and same-currency exclusions appear.", ["FX03"]),
            criterion("FX-AC04", "Every eligible row displays all six observed financial values, and recalculation preserves the foreign amount, uses the date-effective rate, and derives gain or loss from the current and new base amounts.", ["FX04", "FX05"]),
            criterion("FX-AC05", "A row lacking either observed balance is routed through the residual-clearing branch rather than ordinary revaluation handling.", ["FX06"]),
            criterion("FX-AC06", "Negative and values greater than or equal to one are rejected for rounding-loss allowance; zero and values below one satisfy the stated numeric boundary when other mandatory inputs are present.", ["FX07"]),
            criterion("FX-AC07", "Submission removes zero-gain-or-loss rows, and submission fails when that removal leaves no gain-or-loss row.", ["FX08"]),
            criterion("FX-AC08", "An empty qualifying result produces one of the two observed no-work or no-results messages.", ["FX09"]),
            criterion("FX-AC09", "Journal creation cannot proceed when the unrealised gain or loss account is absent.", ["FX10"]),
            criterion("FX-AC10", "Successful creation yields linked, attributable, multi-currency entries whose debit and credit totals balance, and reports the ordinary journal plus any applicable zero-balance journal.", ["FX11", "FX12"]),
            criterion("FX-AC11", "For posted linked journals, the record can separately report gain-or-loss agreement with the revaluation total and posted-reversal status.", ["FX13"]),
            criterion("FX-AC12", "A posted linked journal without a reversal can receive one draft reversal; a journal that is not posted or already has a reversal does not receive another, and an existing draft is disclosed as the duplication blocker.", ["FX14"]),
            criterion("FX-AC13", "The observed draft, submitted, linked-journal, and draft-reversal conditions are distinguishable without implying unobserved final-posting states.", ["FX15"]),
        ],
    },
    {
        "capability_id": "NEUTRAL-INVOICE-145-A1",
        "input_packet_ids": ["OBS-INV-145-A1"],
        "MUST": [
            requirement("I1-M01", "An actor must be able to create an invoice in Draft and subsequently submit it.", ["I101"]),
            requirement("I1-M02", "Draft, Submitted, Unpaid, Partly Paid, Paid, Overdue, and Cancelled must be represented as distinct states; no transition order beyond separately observed actions is inferred.", ["I102"]),
            requirement("I1-M03", "Submission must apply an approval control and produce accounting effects; stock effects must also apply when the invoice bears stock.", ["I103"]),
            requirement("I1-M04", "A submitted invoice must offer payment capture and payment-request actions.", ["I104"]),
            requirement("I1-M05", "A payment request must support an attached invoice or payment link, must be handed off only after the initiating change commits, and must suppress duplicate outbound hand-offs for that observed request.", ["I105"]),
            requirement("I1-M06", "Cancellation must reverse relevant effects, cancel associated ledger entries, and change the invoice state to Cancelled.", ["I106"]),
        ],
        "SHOULD": [],
        "NOT_ESTABLISHED": [
            requirement("I1-N01", "Target-specific site access, privacy, direct-object concealment, retry behavior beyond the observed send hand-off, accessibility, and supported-living terminology are not established.", ["I107"]),
        ],
        "acceptance_criteria": [
            criterion("I1-AC01", "A newly created invoice is Draft, can later be submitted, and each of the seven observed states is distinguishable.", ["I101", "I102"]),
            criterion("I1-AC02", "Submission passes through the approval control, produces accounting effects, and applies stock effects only where stock is involved.", ["I103"]),
            criterion("I1-AC03", "A submitted invoice exposes both payment capture and payment-request actions.", ["I104"]),
            criterion("I1-AC04", "A payment request can carry either the invoice attachment or a payment link; no outbound hand-off occurs before commit, and duplicate hand-offs for that observed request are suppressed.", ["I105"]),
            criterion("I1-AC05", "Cancellation reverses applicable effects, cancels associated ledger entries, and leaves the invoice in Cancelled.", ["I106"]),
        ],
    },
    {
        "capability_id": "NEUTRAL-INVOICE-145-A2",
        "input_packet_ids": ["OBS-INV-145-A2"],
        "MUST": [
            requirement("I2-M01", "Draft, Validated, Closed, and Abandoned must be represented as distinct customer-invoice states; no additional equivalence or transition ordering is implied.", ["I201"]),
            requirement("I2-M02", "Invoice creation must begin in Draft.", ["I202"]),
            requirement("I2-M03", "An invoice that is paid must be explicitly closed.", ["I203"]),
            requirement("I2-M04", "An unpaid invoice must support abandonment with a cancellation event, while correction of a paid invoice must use a distinct credit-note path rather than that same cancellation path.", ["I204"]),
            requirement("I2-M05", "Successful email delivery must increment the invoice send counter and emit a delivery event.", ["I205"]),
            requirement("I2-M06", "Payment creation must distribute amounts across invoices and close each invoice that becomes paid.", ["I206"]),
        ],
        "SHOULD": [],
        "NOT_ESTABLISHED": [
            requirement("I2-N01", "Exact equivalence among abandonment, cancellation, credit-note correction, and any target lifecycle is not established.", ["I207"]),
            requirement("I2-N02", "Site access, privacy, direct-object concealment, retry behavior, and accessibility parity are not established.", ["I207"]),
        ],
        "acceptance_criteria": [
            criterion("I2-AC01", "A newly created invoice is Draft, and Draft, Validated, Closed, and Abandoned remain distinguishable states.", ["I201", "I202"]),
            criterion("I2-AC02", "When an invoice becomes paid, it is explicitly Closed.", ["I203"]),
            criterion("I2-AC03", "An unpaid invoice can be abandoned and emits the observed cancellation event; a paid invoice is corrected through a separate credit-note path.", ["I204"]),
            criterion("I2-AC04", "On successful email delivery, the send counter increments and a delivery event is emitted.", ["I205"]),
            criterion("I2-AC05", "A payment can be distributed across multiple invoices, and every invoice made paid by that allocation is closed.", ["I206"]),
        ],
    },
    {
        "capability_id": "NEUTRAL-INVOICE-145-ADJACENT",
        "input_packet_ids": ["OBS-INV-145-ADJ"],
        "relationship_boundary": "ADJACENT_ONLY_NOT_AN_EXACT_INVOICE_LIFECYCLE_MATCH",
        "MUST": [
            requirement("IA-M01", "Create, mail, delete, deliver, write-off, and payment-history actions must each be subject to an authorization check, without prescribing roles or an authorization model.", ["IA01"]),
            requirement("IA-M02", "Invoice creation and its related journal work must succeed or fail as one unit.", ["IA02"]),
            requirement("IA-M03", "Invoice email delivery must support later transport hand-off, may include a PDF attachment, and must mark the invoice delivered only after transport succeeds.", ["IA03"]),
            requirement("IA-M04", "Only one delivery operation may proceed for an invoice at a time, and duplicate delivery attempts must be rejected.", ["IA04"]),
            requirement("IA-M05", "Payment allocation must update the invoice paid amount.", ["IA05"]),
            requirement("IA-M06", "Deletion must evaluate a stable invoice state and must be rejected when payments or applied credits exist.", ["IA06"]),
        ],
        "SHOULD": [],
        "NOT_ESTABLISHED": [
            requirement("IA-N01", "This adjacent slice does not establish a submitted-invoice cancellation or reversal lifecycle; it establishes only observed deletion and write-off behavior.", ["IA07"]),
            requirement("IA-N02", "No organisational-partitioning abstraction, site, access, privacy, or supported-living semantics may be inferred from this packet.", ["IA08"]),
        ],
        "acceptance_criteria": [
            criterion("IA-AC01", "Each of the six listed actions invokes its relevant authorization guard; no particular role structure is assumed.", ["IA01"]),
            criterion("IA-AC02", "A failure in either invoice creation or related journal work leaves neither side partially completed.", ["IA02"]),
            criterion("IA-AC03", "Delivery may be handed off for later transport and may include a PDF, but delivered status is recorded only following successful transport.", ["IA03"]),
            criterion("IA-AC04", "A second delivery attempt is rejected while or after the protected delivery operation makes it a duplicate; no concurrent-execution model beyond this safeguard is inferred.", ["IA04"]),
            criterion("IA-AC05", "Allocating a payment changes the invoice paid amount accordingly.", ["IA05"]),
            criterion("IA-AC06", "Deletion is rejected, and the invoice retained, when a payment or applied credit exists.", ["IA06"]),
        ],
    },
]

all_requirement_rows = [row for capability in capabilities for key in ("MUST", "SHOULD", "NOT_ESTABLISHED") for row in capability[key]]
all_criterion_rows = [row for capability in capabilities for row in capability["acceptance_criteria"]]
assert len([row for capability in capabilities for row in capability["MUST"]]) == 33
assert len([row for capability in capabilities for row in capability["SHOULD"]]) == 0
assert len([row for capability in capabilities for row in capability["NOT_ESTABLISHED"]]) == 7
assert len(all_criterion_rows) == 29
assert len({row["id"] for row in all_requirement_rows + all_criterion_rows}) == 69

covered_observations = {
    source_id
    for row in all_requirement_rows + all_criterion_rows
    for source_id in row["source_observation_ids"]
}
expected_observations = {
    *(f"FX{i:02d}" for i in range(1, 17)),
    *(f"I{i}" for i in range(101, 108)),
    *(f"I{i}" for i in range(201, 208)),
    *(f"IA{i:02d}" for i in range(1, 9)),
}
assert covered_observations == expected_observations

agent_b = {
    "schema_version": "raw_run_145_agent_b_finance_invoice_fx_neutral_requirements_wave_24_v1",
    "run_id": "RUN-145-B-FINANCE-INVOICE-FX-NEUTRAL-REQUIREMENTS-WAVE-24",
    "status": "CLEAN_NEUTRAL_SPECIFICATION_COMPLETE_ZERO_CREDIT",
    "generated_on": "2026-08-26",
    "agent": {
        "agent_id": "/root/run145_agent_b",
        "role": "clean_room_neutral_requirements_writer",
        "fresh_context": True,
        "input_access": "SUPPLIED_IDENTITY_STRIPPED_OBSERVATIONS_ONLY",
    },
    "input_seal": {
        "path": "evidence/benchmark/sealed-run-145-agent-b-finance-invoice-fx-input-wave-24.json",
        "bytes": len(input_bytes),
        "sha256": EXPECTED_B_INPUT_SHA256,
    },
    "root_materialization_provenance": root_provenance_seals,
    "normative_interpretation": {
        "MUST": "Directly established behavior restated without source identity or implementation.",
        "SHOULD": "Evidence-backed preference only; none was established.",
        "NOT_ESTABLISHED": "Unknown, unproved, explicitly limited, or non-transferable behavior that must not be inferred.",
    },
    "capabilities": capabilities,
    "counts": {
        "input_packets": 4,
        "capability_sections": 4,
        "source_observations": 38,
        "unique_source_observations_cited": 38,
        "uncited_source_observations": 0,
        "MUST": 33,
        "SHOULD": 0,
        "NOT_ESTABLISHED": 7,
        "acceptance_criteria": 29,
        "benchmark_selections": 0,
        "cross_packet_equivalence_findings": 0,
        "NCM_findings": 0,
        "credit_awards": 0,
        "writes_by_agent_b": 0,
    },
    "attestation": {
        "only_supplied_identity_stripped_packets_used": True,
        "repository_filesystem_network_browser_runtime_test_build_database_git_access": False,
        "upstream_and_target_identity_absent": True,
        "source_implementation_absent": True,
        "unknowns_retained": True,
        "adjacent_invoice_packet_not_promoted": True,
        "every_requirement_has_source_observation_ids": True,
        "all_38_observations_cited": True,
        "selection_mapping_NCM_benchmark_pass_runtime_browser_or_completion_credit_awarded": False,
        "audit_artifact_writes_by_root_only": True,
    },
}

b_bytes, b_hash = write_exact(RAW_B_OUTPUT, agent_b)

fx_manifest = [
    ("routes/finance.php", "3823dded458ab0f2bf20fe9b5de992acb414a5eb", "cf6eed8437206aaf05feb541d031ce406382e13153a31bb831ef66b29994f1aa", "route declarations and finance permission groups"),
    ("app/Domain/Finance/Http/Controllers/FxRevaluationController.php", "adf4ad66c51f31167d97f76d7de3a886e70c4d3d", "1bc7062eeac5d36889fa058c476207919971c96beeda2dac72a60f75b797545b", "index, create, store, and post orchestration"),
    ("app/Domain/Finance/Services/FxRevaluationService.php", "bc99401688ecbfcd3a69dab4661b8ae47389bc31", "bfdb84ddee4a99c3f0551e27d31a8d51c21348ecf14e56150346bdf382df6892", "calculation, draft creation, journal construction, and posting state"),
    ("app/Domain/Finance/Models/FinFxRevaluation.php", "3ef73b98c0e5346829f5d140f7406a0c54f68911", "3d819eb5769837c78094f4adecc489f4b60201382779237db3bf7c47564f2395", "revaluation state and relationships"),
    ("app/Domain/Finance/Services/CurrencyService.php", "123a98a164a435298acf83976a8bdde021ef2718", "9936ff104f596bf6fc222e7341d2ba907b34d44c998f99842689a1d3added25a", "rate lookup and fallback behavior"),
    ("app/Domain/Finance/Services/JournalPostingService.php", "4e7817681f3ca3453da1f87a698969d6d575f357", "092af77653c278507f2bdb10fdcf24b327d511c274b473bb081e813b66f65526", "journal validation, balance, locking, posting, and reversal"),
    ("resources/js/pages/finance/fx-revaluations/Index.tsx", "681458ff85a07b247b9b463e4ad1e35c7f0a1121", "f16069b9ab7b30163d008145cbb674849079f446943020fa2492e23a76694115", "list and post interaction"),
    ("resources/js/pages/finance/fx-revaluations/Create.tsx", "62572ebf719c17de86ebd6e717e61045e7282165", "34276b902e90da6bf8bbce9b5e902fe1d9c43c29340436d1d936c10e3cc0bce1", "preview and create interaction"),
]

invoice_manifest = [
    ("routes/finance.php", "3823dded458ab0f2bf20fe9b5de992acb414a5eb", "cf6eed8437206aaf05feb541d031ce406382e13153a31bb831ef66b29994f1aa", "invoice route declarations and permission middleware"),
    ("app/Domain/Finance/Http/Controllers/InvoiceController.php", "c6da7ae16135c852a0e2d735dca23d7b2846032c", "5ecb4b7e41641b2c709a24b20f1a9692dd583204f607d965f84444bedfb55db2", "create, update, send, pay, cancel, PDF, and response behavior"),
    ("app/Domain/Finance/Models/FinInvoice.php", "ef8de91c1afeb6740a634fb295af01eb9d2da82a", "3e3597605da19687657620e4bf44a31590422d2a887dd8c6068db6fa9a617d31", "invoice state, ownership context, and relationships"),
    ("app/Domain/Finance/Policies/FinInvoicePolicy.php", "c7a3dc27737d422c08cf8fe26ea2fedc45f3770d", "31aef68ea81ebdf9d125976d6a854dc73d87908473eda0808addb1067d13ec3e", "invoice authorization policy"),
    ("app/Domain/Finance/Http/Requests/StoreInvoiceRequest.php", "c6d4c8561874624f0e0f99e5c8fe7264d27b3ff1", "2dd6fc86f211bd98dc251e4d4d370b275d017abeddab441c490e1d09aba7bb4f", "creation validation"),
    ("app/Domain/Finance/Http/Requests/UpdateInvoiceRequest.php", "58e296c5193113cb182e88547d2b1431d006ad54", "18e86d3895584a97c6513819f80d17801c3e4806aa3114efe8fe64ee8fc04d82", "update validation"),
    ("app/Domain/Finance/Services/FinInvoiceJournalService.php", "f6d9f29d66df92646268455941ba06397c2aeef6", "fcd88e7f5ffef058795131c03cd1954d076335556eef055228a9ba1ddbd321e7", "issue and reversal journal behavior"),
    ("app/Domain/Finance/Jobs/PostFinInvoiceJournalJob.php", "0916cfd12fe9e82fb60f92d520e1c4f290aca7b8", "3dd18a01e5c9c525dc8336ccf8927ca43b7b1c69da8fecae639c9a6f7c9a60e3", "queued issue-journal behavior"),
    ("app/Domain/Finance/Jobs/SendInvoiceEmailJob.php", "5368573bf13be72842478b11d8b02688c9c70d5a", "158eba65a2f031c98a9356c93e854673c9a8e9a802fc562aa3789da7c76febfe", "queued email outcome and retry behavior"),
    ("app/Domain/Finance/Notifications/InvoiceEmailNotification.php", "239983e27333eef9b4a57fc46c9800ed4a2559cc", "43f16e2af862f2ec5060ecf3c8c8136982fdfecdcc75273e6da03d80e230ef35", "invoice mail content and attachment hand-off"),
    ("app/Domain/Finance/Services/AccountsReceivableService.php", "10c4336129ae4e45eab81edca24970f020a9efa8", "d055790df95edc29e3f4c6a05279af94791c4e4e5e3ceb49154ca1a19b3e0fca", "receipt allocation, paid-state, and statement behavior"),
    ("app/Domain/Finance/Services/PaymentSettlementSiteScope.php", "948a769757020ab5d404748e3ad957380ebb8a57", "cd837fbf242cb6d94307a141d2df7ce9ea0bd79c31c7de2f80b8517de243645f", "canonical Site scope for payment settlement"),
    ("app/Domain/Finance/Services/PaymentSettlementRecorder.php", "a9dfcd90dfc73eba01a4ca987ce384e6a96b71a4", "bce86c79250bdd1d4b87bcdb9524684a0928971b718299f288e6c80125b8743a", "append-only settlement recording"),
    ("app/Domain/Finance/Services/InvoicePdfService.php", "87db04111f5a108e0a96b143f2234d6d80ec1367", "8943adfc26e96ea001c4c2017da3acde8ab00f136d4313442d16b72d14acdbb5", "PDF generation and storage"),
    ("resources/js/pages/finance/invoices/Index.tsx", "1b1b88b34c9eb877b248e5363ccfbdaf2fc3f606", "316c3abf37728ffadf1a40042714b84e196b2a91a83c512e833e4ae9af920a13", "invoice list states and actions"),
    ("resources/js/pages/finance/invoices/Create.tsx", "20afaf04f37ed7742815c84623de5c686f82d3a7", "e2a5b309f489de58dc2a7ead4fc6d3217b39d77a385fd87dc8466336f35f1042", "invoice creation interaction"),
    ("resources/js/pages/finance/invoices/Edit.tsx", "b6a2ad7495b5d60235cc97d45a1529fd856bcc73", "248f6b6cfbed9fdbae9ec59bed60bebb83623410abf19735501db6dcf2d959c6", "draft update interaction"),
    ("resources/js/pages/finance/invoices/Show.tsx", "cf40bf3677cf820add6592fda0b38135acc6d325", "815ac5992fafa4c4026db4658d311e9505494ed610f48dab9da8082afec37c19", "invoice detail, send, pay, cancel, and feedback"),
]


def manifest_rows(rows: list[tuple[str, str, str, str]]) -> list[dict[str, str]]:
    return [{"path": path, "blob_id": blob, "sha256": digest, "purpose": purpose} for path, blob, digest, purpose in rows]


sealed_c = {
    "schema_version": "sealed_run_145_agent_c_finance_invoice_fx_current_comparison_input_wave_24_v1",
    "run_id": "RUN-145-C-INPUT-FINANCE-INVOICE-FX-WAVE-24",
    "status": "NEUTRAL_SPECIFICATION_PLUS_PINNED_CURRENT_SOURCE_MANIFEST_ONLY",
    "neutral_specification_seal": {
        "path": "evidence/benchmark/raw-run-145-agent-b-finance-invoice-fx-neutral-requirements-wave-24.json",
        "bytes": b_bytes,
        "sha256": b_hash,
    },
    "neutral_specification": agent_b,
    "root_reattachment_provenance": root_provenance_seals,
    "application_source_pin": {
        "commit": APPLICATION_COMMIT,
        "tree": APPLICATION_TREE,
        "read_mode": "PINNED_GIT_OBJECT_ONLY",
    },
    "canonical_targets": [
        {
            "feature_id": matrix_target_rows["CAP-FIN-FX-REVALUATION"]["feature_id"],
            "feature_class": matrix_target_rows["CAP-FIN-FX-REVALUATION"]["feature_class"],
            "module": matrix_target_rows["CAP-FIN-FX-REVALUATION"]["module"],
            "user_job": matrix_target_rows["CAP-FIN-FX-REVALUATION"]["user_job"],
            "neutral_capability_ids": ["NEUTRAL-FX-145"],
            "current_mapping_credit": matrix_target_rows["CAP-FIN-FX-REVALUATION"]["benchmark_mapping_credit"].lower() == "true",
            "current_completion_status": matrix_target_rows["CAP-FIN-FX-REVALUATION"]["completion_status"],
            "current_source_packet": {
                "path": "evidence/source/current-run-130-reviewed-outcome-neutral-finance-fx-revaluation-route-action-ownership-overlay-wave-20.json",
                "sha256": FX_SOURCE_PACKET_SHA256,
                "accepted_route_owner_ids": ["RUN130-ROUTE-01", "RUN130-ROUTE-02"],
                "accepted_bridge_ids": ["RUN130-BRIDGE-01", "RUN130-BRIDGE-02"],
                "credit_boundary": "STATIC_OWNER_CONTEXT_ONLY_NO_CORRECTNESS_RUNTIME_BROWSER_TEST_BENCHMARK_OR_COMPLETION_CREDIT",
            },
            "current_source_manifest": manifest_rows(fx_manifest),
        },
        {
            "feature_id": matrix_target_rows["CAP-FIN-BILLING-INVOICE-LIFECYCLE"]["feature_id"],
            "feature_class": matrix_target_rows["CAP-FIN-BILLING-INVOICE-LIFECYCLE"]["feature_class"],
            "module": matrix_target_rows["CAP-FIN-BILLING-INVOICE-LIFECYCLE"]["module"],
            "user_job": matrix_target_rows["CAP-FIN-BILLING-INVOICE-LIFECYCLE"]["user_job"],
            "neutral_capability_ids": ["NEUTRAL-INVOICE-145-A1", "NEUTRAL-INVOICE-145-A2", "NEUTRAL-INVOICE-145-ADJACENT"],
            "adjacent_capability_id": "NEUTRAL-INVOICE-145-ADJACENT",
            "current_mapping_credit": matrix_target_rows["CAP-FIN-BILLING-INVOICE-LIFECYCLE"]["benchmark_mapping_credit"].lower() == "true",
            "current_completion_status": matrix_target_rows["CAP-FIN-BILLING-INVOICE-LIFECYCLE"]["completion_status"],
            "current_source_packet": {
                "path": "evidence/source/current-run-138-reviewed-outcome-neutral-finance-invoice-index-route-action-ownership-overlay-wave-22.json",
                "sha256": INVOICE_SOURCE_PACKET_SHA256,
                "accepted_route_owner_ids": ["RUN138-ROUTE-01"],
                "accepted_bridge_ids": ["RUN138-BRIDGE-01"],
                "credit_boundary": "STATIC_INDEX_OWNER_CONTEXT_ONLY_NO_CORRECTNESS_RUNTIME_BROWSER_TEST_BENCHMARK_OR_COMPLETION_CREDIT",
            },
            "current_source_manifest": manifest_rows(invoice_manifest),
        },
    ],
    "architecture_rule": "Oblivion Findings is one operating organisation across multiple Sites. Compare against exact permissions, approved Sites, canonical ownership, direct-object concealment, privacy, supported-living terminology, native Laravel/Inertia/React workflow, and existing ownership direction. Do not introduce tenant concepts.",
    "allowed_operation": "Read only the embedded neutral specification, the two named current-source owner packets, and the enumerated application files at the exact application commit. Compare every MUST, NOT_ESTABLISHED, and acceptance criterion to current source. Propose an original Oblivion-native workflow only where the neutral requirement exposes a gap or improvement opportunity.",
    "prohibited_context": [
        "RUN-145 Agent-A raw or sealed upstream inputs",
        "upstream project, repository, release, source, licence, edition, wording, layout, or implementation identity",
        "RUN-071 or any historical mapping-readiness conclusion",
        "working-tree application source",
        "unlisted application or audit files",
        "network, browser, runtime, tests, build, database, or external state",
        "benchmark selection, NCM, mapping, pass, release, or completion credit",
    ],
    "comparison_contract": {
        "comparison_unit": "Each of 33 MUST requirements, 7 NOT_ESTABLISHED boundaries, and 29 acceptance criteria, evaluated against only the applicable same-target pinned current source.",
        "allowed_outcomes": ["MET", "PARTIAL", "GAP", "CONTRADICTED", "NOT_COMPARABLE"],
        "unknowns_must_be_preserved": True,
        "adjacent_invoice_requirements_must_remain_separate": True,
        "current_source_static_only": True,
        "source_presence_is_not_runtime_or_browser_proof": True,
        "upstream_identity_withheld": True,
        "project_selection_mapping_or_NCM_decision_allowed": False,
        "credit_allowed": False,
    },
    "counts": {
        "canonical_targets": 2,
        "neutral_capability_sections": 4,
        "MUST": 33,
        "NOT_ESTABLISHED": 7,
        "acceptance_criteria": 29,
        "comparison_rows_required": 69,
        "FX_current_source_files": len(fx_manifest),
        "invoice_current_source_files": len(invoice_manifest),
        "upstream_identity_records": 0,
        "credit_awards": 0,
    },
    "attestation": {
        "upstream_identity_and_implementation_excluded": True,
        "current_source_exact_commit_and_manifest_pinned": True,
        "working_tree_source_prohibited": True,
        "runtime_browser_test_network_evidence_excluded": True,
        "zero_credit": True,
    },
}

c_bytes, c_hash = write_exact(SEALED_C_OUTPUT, sealed_c)

print(f"{RAW_B_OUTPUT.relative_to(AUDIT_DIR)}\t{b_bytes}\t{b_hash}")
print(f"{SEALED_C_OUTPUT.relative_to(AUDIT_DIR)}\t{c_bytes}\t{c_hash}")
