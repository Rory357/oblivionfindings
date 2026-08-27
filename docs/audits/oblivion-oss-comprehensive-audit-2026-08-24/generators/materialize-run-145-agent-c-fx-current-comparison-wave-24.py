from __future__ import annotations

import hashlib
import json
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
SEALED_INPUT = AUDIT_DIR / "evidence/benchmark/sealed-run-145-agent-c-finance-invoice-fx-current-comparison-input-wave-24.json"
OUTPUT = AUDIT_DIR / "evidence/benchmark/raw-run-145-agent-c-fx-current-comparison-wave-24.json"
EXPECTED_INPUT_SHA256 = "722deceb2bf9a9462c5db69adcf700e9ce0cba62bffe0bef160e004ade82a032"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def row(
    identifier: str,
    outcome: str,
    loci: list[str],
    evidence: str,
    evidence_limit: list[str],
    improvement_ids: list[str] | None = None,
) -> dict[str, object]:
    return {
        "id": identifier,
        "outcome": outcome,
        "exact_current_locus": loci,
        "evidence": evidence,
        "evidence_limit": evidence_limit,
        "improvement_ids": improvement_ids or [],
    }


input_bytes = SEALED_INPUT.read_bytes()
assert sha256(input_bytes) == EXPECTED_INPUT_SHA256
sealed_input = json.loads(input_bytes)
assert sealed_input["application_source_pin"] == {
    "commit": APPLICATION_COMMIT,
    "tree": APPLICATION_TREE,
    "read_mode": "PINNED_GIT_OBJECT_ONLY",
}
target = next(
    item
    for item in sealed_input["canonical_targets"]
    if item["feature_id"] == "CAP-FIN-FX-REVALUATION"
)
assert len(target["current_source_manifest"]) == 8

rows = [
    row("FX-M01", "PARTIAL", ["app/Domain/Finance/Http/Controllers/FxRevaluationController.php:41-50 FxRevaluationController::create", "app/Domain/Finance/Services/FxRevaluationService.php:30-35 FxRevaluationService::calculateUnrealisedGainLoss"], "The controller derives legacy organisational ledger context from the signed-in user and a request or default date before querying. The context remains nullable and the preview date is defaulted rather than required and validated.", ["S", "B"], ["IMP-FX-01"]),
    row("FX-M02", "PARTIAL", ["routes/finance.php:62 authenticated finance route group", "routes/finance.php:594-600 FX revaluation permission group"], "Authentication and finance.ledger.manage guard inspection, creation, and posting. Journal creation is guarded, but its authority is not distinct from record inspection.", ["S", "A"], ["IMP-FX-02"]),
    row("FX-M03", "PARTIAL", ["app/Domain/Finance/Services/FxRevaluationService.php:41-47 open foreign-currency bill query", "app/Domain/Finance/Services/FxRevaluationService.php:80-86 foreign-currency bank-account query"], "The service filters foreign-currency open bills and active non-zero foreign-currency bank accounts. It does not enumerate foreign-currency balance-sheet ledger accounts or express group and stock exclusions.", ["S", "B"], ["IMP-FX-03"]),
    row("FX-M04", "MET", ["app/Domain/Finance/Services/FxRevaluationService.php:49-75 bill preview rows", "app/Domain/Finance/Services/FxRevaluationService.php:88-117 bank-account preview rows", "resources/js/pages/finance/fx-revaluations/Create.tsx:13-23 PreviewItem", "resources/js/pages/finance/fx-revaluations/Create.tsx:175-260 preview table"], "Each returned row contains foreign amount, booked and current rates, booked and current base values, and gain or loss; the page displays all six required financial values.", ["S"]),
    row("FX-M05", "PARTIAL", ["app/Domain/Finance/Services/FxRevaluationService.php:50-61 bill calculation", "app/Domain/Finance/Services/FxRevaluationService.php:89-104 bank-account calculation", "app/Domain/Finance/Services/CurrencyService.php:15-64 CurrencyService::getExchangeRate"], "The foreign amount is reused unchanged, the selected date is passed to rate lookup, and gain or loss is new minus booked base value. When no dated rate is found, an undated currency-table rate or 1.0 can be used, and no durable rate snapshot is visible.", ["S", "R"], ["IMP-FX-03"]),
    row("FX-M06", "GAP", ["app/Domain/Finance/Services/FxRevaluationService.php:45-47 bill eligibility", "app/Domain/Finance/Services/FxRevaluationService.php:63-77 zero-gain bill filtering", "app/Domain/Finance/Services/FxRevaluationService.php:83-106 bank eligibility and filtering"], "Zero bank balances and zero gain-or-loss rows are excluded. No residual-carrying-balance clearing branch is present.", ["S", "B"], ["IMP-FX-03"]),
    row("FX-M07", "PARTIAL", ["app/Domain/Finance/Http/Controllers/FxRevaluationController.php:54-62 FxRevaluationController::store", "resources/js/pages/finance/fx-revaluations/Create.tsx:49-68 date state and submission", "resources/js/pages/finance/fx-revaluations/Create.tsx:99-115 date field"], "Store requires a date and derives organisational context. No rounding-loss allowance field or 0 <= allowance < 1 validation exists, and preview retrieval does not require an explicitly supplied date.", ["S", "B"], ["IMP-FX-01"]),
    row("FX-M08", "PARTIAL", ["app/Domain/Finance/Services/FxRevaluationService.php:63-77 zero-gain bill filtering", "app/Domain/Finance/Services/FxRevaluationService.php:106-119 zero-gain bank filtering", "app/Domain/Finance/Services/FxRevaluationService.php:132-143 FxRevaluationService::createRevaluation", "app/Domain/Finance/Services/FxRevaluationService.php:160-164 posting materiality guard"], "Zero-gain rows are removed during calculation. Draft creation still succeeds with zero items; failure occurs only later when posting an immaterial aggregate.", ["S"], ["IMP-FX-04"]),
    row("FX-M09", "MET", ["resources/js/pages/finance/fx-revaluations/Create.tsx:158-163 empty preview feedback"], "Empty preview feedback says no foreign-currency items were found and that no open bills or bank balances require revaluation.", ["S"]),
    row("FX-M10", "MET", ["app/Domain/Finance/Services/FxRevaluationService.php:166-181 gain-loss and retained-earnings account lookup"], "Posting is blocked if account 8300 for FX gain or loss is absent; the current implementation also requires account 3000.", ["S", "B"]),
    row("FX-M11", "PARTIAL", ["app/Domain/Finance/Services/FxRevaluationService.php:183-227 journal construction and link", "app/Domain/Finance/Services/JournalPostingService.php:42-61 balance validation", "app/Domain/Finance/Services/JournalPostingService.php:343-385 JournalPostingService::createDraftJournalRecord", "app/Domain/Finance/Models/FinFxRevaluation.php:38-41 FinFxRevaluation::journal"], "The service creates actor-attributable, balanced debit and credit lines and links the posted journal to the record. Lines are aggregate base-currency amounts with no foreign amount, currency, rate, or per-exposure account lineage.", ["S", "B"], ["IMP-FX-05"]),
    row("FX-M12", "GAP", ["app/Domain/Finance/Http/Controllers/FxRevaluationController.php:72-80 FxRevaluationController::post", "resources/js/pages/finance/fx-revaluations/Index.tsx:268-270 journal-number cell"], "Success returns a generic message and later shows a plain journal number. There is no journal navigation action and no zero-balance journal result.", ["S", "B"], ["IMP-FX-06"]),
    row("FX-M13", "PARTIAL", ["app/Domain/Finance/Models/FinFxRevaluation.php:38-41 FinFxRevaluation::journal", "app/Domain/Finance/Http/Controllers/FxRevaluationController.php:21-34 FxRevaluationController::index projection"], "A record can locate one linked journal and expose its number. The slice does not select posted status, compare booked gain or loss with the revaluation total, or independently report reversal status.", ["S", "B"], ["IMP-FX-06"]),
    row("FX-M14", "PARTIAL", ["app/Domain/Finance/Services/JournalPostingService.php:142-243 JournalPostingService::reverse", "app/Domain/Finance/Services/JournalPostingService.php:404-440 JournalPostingService::resolveExistingReversal", "routes/finance.php:594-600 FX route set", "app/Domain/Finance/Http/Controllers/FxRevaluationController.php:72-82 available FX mutation action"], "Generic reversal infrastructure requires a posted journal, locks it, and prevents duplicate ledger effects. No FX reversal action exists; the helper immediately posts the reversal instead of leaving it draft, and an existing reversal is returned rather than surfaced as an actor-facing blocker.", ["S", "G"], ["IMP-FX-07"]),
    row("FX-M15", "PARTIAL", ["app/Domain/Finance/Models/FinFxRevaluation.php:17-25 persisted state fields", "app/Domain/Finance/Services/FxRevaluationService.php:136-155 draft creation and draft guard", "app/Domain/Finance/Services/FxRevaluationService.php:223-227 posted-state update", "resources/js/pages/finance/fx-revaluations/Index.tsx:24-33 Revaluation type", "resources/js/pages/finance/fx-revaluations/Index.tsx:91-99 draft action condition", "resources/js/pages/finance/fx-revaluations/Index.tsx:261-293 status, journal, and post action", "resources/js/pages/finance/fx-revaluations/Index.tsx:329-343 post confirmation"], "Draft and posted record conditions and a linked journal number are distinguishable. No separate submitted condition or draft-reversal condition is exposed; the UI states that posting cannot be undone.", ["S", "B"], ["IMP-FX-07"]),
    row("FX-N01", "NOT_COMPARABLE", ["app/Domain/Finance/Services/CurrencyService.php:15-64 rate lookup and fallback", "app/Domain/Finance/Services/JournalPostingService.php:30-341 posting, reversal, and locking", "routes/finance.php:594-600 FX permission and actions"], "Current source contains static posting, rate, locking, and reversal mechanisms, but neutral evidence did not establish final orchestration, provenance, idempotency, concurrency, approval separation, recovery, accessibility, or complete authorization. Those unknowns remain unknown.", ["N", "S"]),
    row("FX-N02", "NOT_COMPARABLE", ["routes/finance.php:62 authenticated finance group", "routes/finance.php:594-600 FX permission and actions", "app/Domain/Finance/Http/Controllers/FxRevaluationController.php:19-22 index context and query", "app/Domain/Finance/Http/Controllers/FxRevaluationController.php:72-75 route-bound post action"], "The neutral ledger context establishes no Site, privacy, or direct-object model. The current slice also cannot establish those boundaries; they are reported separately and not treated as comparable upstream behavior.", ["N", "S", "B"]),
    row("FX-AC01", "PARTIAL", ["app/Domain/Finance/Http/Controllers/FxRevaluationController.php:41-50 FxRevaluationController::create", "app/Domain/Finance/Http/Controllers/FxRevaluationController.php:54-62 FxRevaluationController::store"], "Store rejects a missing date, but preview retrieval supplies today automatically and accepts nullable organisational context, so omission does not consistently produce validation feedback before retrieval.", ["S", "B"], ["IMP-FX-01"]),
    row("FX-AC02", "PARTIAL", ["routes/finance.php:594-600 FX revaluation permission group"], "finance.ledger.manage protects creation and posting, but it also protects inspection; there is no inspect-only path proving the required authority separation.", ["S", "A"], ["IMP-FX-02"]),
    row("FX-AC03", "PARTIAL", ["app/Domain/Finance/Services/FxRevaluationService.php:41-47 open bill query", "app/Domain/Finance/Services/FxRevaluationService.php:80-86 bank-account query"], "Same-currency records are excluded, but eligibility is based on bills and bank accounts rather than ledger-account class, and group and stock exclusions are absent.", ["S", "B"], ["IMP-FX-03"]),
    row("FX-AC04", "PARTIAL", ["app/Domain/Finance/Services/FxRevaluationService.php:49-75 bill values and calculation", "app/Domain/Finance/Services/FxRevaluationService.php:88-117 bank-account values and calculation", "app/Domain/Finance/Services/CurrencyService.php:15-64 dated lookup and fallback", "resources/js/pages/finance/fx-revaluations/Create.tsx:175-260 preview table"], "The six values are displayed and arithmetic preserves the foreign amount, but effective-date assurance is weakened by the undated or 1.0 fallback and absence of a persisted snapshot.", ["S", "R"], ["IMP-FX-03"]),
    row("FX-AC05", "GAP", ["app/Domain/Finance/Services/FxRevaluationService.php:45-47 bill eligibility", "app/Domain/Finance/Services/FxRevaluationService.php:63-77 bill filtering", "app/Domain/Finance/Services/FxRevaluationService.php:83-106 bank eligibility and filtering"], "Rows lacking an observed balance are excluded rather than routed through residual clearing.", ["S", "B"], ["IMP-FX-03"]),
    row("FX-AC06", "GAP", ["app/Domain/Finance/Http/Controllers/FxRevaluationController.php:56-59 request validation", "resources/js/pages/finance/fx-revaluations/Create.tsx:51-54 form data", "resources/js/pages/finance/fx-revaluations/Create.tsx:271-300 submission form"], "Neither request validation nor the form contains a rounding-loss allowance, so none of the stated numeric boundaries can be enforced.", ["S", "B"], ["IMP-FX-01"]),
    row("FX-AC07", "PARTIAL", ["app/Domain/Finance/Services/FxRevaluationService.php:63-77 zero-gain bill filtering", "app/Domain/Finance/Services/FxRevaluationService.php:106-119 zero-gain bank filtering", "app/Domain/Finance/Services/FxRevaluationService.php:132-143 draft persistence", "app/Domain/Finance/Services/FxRevaluationService.php:160-164 post-time materiality rejection"], "Zero-gain rows are filtered, but a zero-row draft can still be submitted and persisted; rejection is deferred until posting.", ["S"], ["IMP-FX-04"]),
    row("FX-AC08", "MET", ["resources/js/pages/finance/fx-revaluations/Create.tsx:158-163 empty-result message"], "The empty result displays an observed-equivalent no-results and no-work message.", ["S"]),
    row("FX-AC09", "MET", ["app/Domain/Finance/Services/FxRevaluationService.php:166-181 gain-loss account guard"], "Absence of the required FX gain or loss account prevents journal creation.", ["S", "B"]),
    row("FX-AC10", "PARTIAL", ["app/Domain/Finance/Services/FxRevaluationService.php:183-227 journal construction and link", "app/Domain/Finance/Services/JournalPostingService.php:42-61 balance validation", "app/Domain/Finance/Services/JournalPostingService.php:343-385 draft journal persistence", "resources/js/pages/finance/fx-revaluations/Index.tsx:268-270 journal-number display"], "The journal is linked, actor-attributable, and balanced, and its number is reported. It lacks multi-currency exposure metadata, a navigable ordinary-journal result, and any applicable zero-balance-journal result.", ["S", "B"], ["IMP-FX-05", "IMP-FX-06"]),
    row("FX-AC11", "GAP", ["app/Domain/Finance/Models/FinFxRevaluation.php:38-41 FinFxRevaluation::journal", "app/Domain/Finance/Http/Controllers/FxRevaluationController.php:21-34 index projection"], "The record has a journal relation, but no source in the slice reports gain-or-loss agreement and posted-reversal status as independent facts.", ["S", "B"], ["IMP-FX-06"]),
    row("FX-AC12", "PARTIAL", ["app/Domain/Finance/Services/JournalPostingService.php:142-243 JournalPostingService::reverse", "app/Domain/Finance/Services/JournalPostingService.php:404-440 JournalPostingService::resolveExistingReversal", "routes/finance.php:594-600 FX route set"], "Generic infrastructure rejects non-posted sources and avoids duplicate effects, but FX exposes no reversal action, the inverse is posted immediately, and an existing draft is not disclosed to the actor as the blocker.", ["S", "G"], ["IMP-FX-07"]),
    row("FX-AC13", "PARTIAL", ["app/Domain/Finance/Services/FxRevaluationService.php:136-155 draft creation and guard", "app/Domain/Finance/Services/FxRevaluationService.php:223-227 posted-state update", "resources/js/pages/finance/fx-revaluations/Index.tsx:24-33 Revaluation type", "resources/js/pages/finance/fx-revaluations/Index.tsx:261-293 status, journal, and post action"], "Draft, posted, and linked-journal conditions are visible. Submitted and draft-reversal conditions are not independently represented.", ["S", "B"], ["IMP-FX-07"]),
]

expected_ids = {
    *(f"FX-M{i:02d}" for i in range(1, 16)),
    "FX-N01", "FX-N02",
    *(f"FX-AC{i:02d}" for i in range(1, 14)),
}
assert len(rows) == 30
assert {item["id"] for item in rows} == expected_ids
outcome_counts = {
    outcome: sum(item["outcome"] == outcome for item in rows)
    for outcome in ("MET", "PARTIAL", "GAP", "CONTRADICTED", "NOT_COMPARABLE")
}
assert outcome_counts == {"MET": 5, "PARTIAL": 18, "GAP": 5, "CONTRADICTED": 0, "NOT_COMPARABLE": 2}

improvements = [
    {"id": "IMP-FX-01", "applies_only_to_outcomes": ["PARTIAL", "GAP"], "requirement": "Keep the one-organisation architecture and add no organisation or tenant selector. Resolve canonical ledger context server-side, fail closed when unavailable, require and validate an as-at date before preview queries, and add a rounding allowance constrained to 0 <= value < 1."},
    {"id": "IMP-FX-02", "applies_only_to_outcomes": ["PARTIAL"], "requirement": "Retain native permission middleware while separating finance read authority from the exact existing journal-write and posting authority. Apply the exact action permission again at the bound-object mutation boundary."},
    {"id": "IMP-FX-03", "applies_only_to_outcomes": ["PARTIAL", "GAP"], "requirement": "Calculate from canonical foreign-currency balance-sheet exposures, explicitly excluding parent or group, stock, and base-currency accounts. Persist an immutable draft snapshot of foreign amount, booked rate and base value, selected effective rate and provenance, new base value, and gain or loss. Route missing foreign or base balances into a named residual-clearing branch."},
    {"id": "IMP-FX-04", "applies_only_to_outcomes": ["PARTIAL"], "requirement": "Filter zero-gain rows before draft persistence and reject creation when no actionable row remains, reusing the existing native no-work feedback."},
    {"id": "IMP-FX-05", "applies_only_to_outcomes": ["PARTIAL"], "requirement": "Build journal lines from the persisted exposure snapshot, debit or credit affected control accounts, balance through the configured unrealised FX gain or loss account, retain currency, rate, source, actor, and applicable Site attribution, and preserve native transaction and balance checks."},
    {"id": "IMP-FX-06", "applies_only_to_outcomes": ["PARTIAL", "GAP"], "requirement": "Return a native link to the ordinary journal and any residual-clearing journal. Expose linked-journal posting state, booked FX total versus snapshot agreement, and reversal state as independent facts on the existing history surface."},
    {"id": "IMP-FX-07", "applies_only_to_outcomes": ["PARTIAL"], "requirement": "Add an FX-owned reversal action that validates the scoped revaluation and posted journal, uses native locking and inverse-line safeguards, creates a linked draft reversal without posting it, and surfaces an existing draft reversal as the duplicate blocker. Preserve existing PageHero, StatusBadge, ConfirmDialog, Laravel, Inertia, and React patterns."},
]

result = {
    "schema_version": "raw_run_145_agent_c_fx_current_comparison_wave_24_v1",
    "run_id": "RUN-145-C-FX-CURRENT-COMPARISON-WAVE-24",
    "status": "PINNED_STATIC_COMPARISON_COMPLETE_ZERO_CREDIT",
    "generated_on": "2026-08-26",
    "agent": {"agent_id": "/root/run145_agent_c_fx", "role": "clean_room_current_oblivion_fx_comparator", "fresh_context": True},
    "input_seal": {"path": "evidence/benchmark/sealed-run-145-agent-c-finance-invoice-fx-current-comparison-input-wave-24.json", "bytes": len(input_bytes), "sha256": EXPECTED_INPUT_SHA256},
    "target": {key: target[key] for key in ("feature_id", "feature_class", "module", "user_job", "neutral_capability_ids", "current_mapping_credit", "current_completion_status")},
    "source_pin": {"commit": APPLICATION_COMMIT, "tree": APPLICATION_TREE, "read_mode": "PINNED_GIT_OBJECT_ONLY"},
    "run130_owner_context": target["current_source_packet"],
    "source_manifest_verification": {"declared_files": 8, "verified_blob_and_sha256_matches": 8, "files_used": 8, "rows": target["current_source_manifest"]},
    "evidence_limit_legend": {
        "S": "Static pinned source only; no runtime, browser, database, build, or executed-test proof.",
        "B": "Absence is bounded to the eight sealed FX files; unlisted schema, models, policies, and framework configuration were not inspected.",
        "A": "Effective role membership, permission aliases, middleware behavior, and policy registration are outside the manifest.",
        "R": "FX-rate scope implementation, stored data, and effective-date results were unavailable; only the visible call and fallback path were assessed.",
        "G": "Generic journal infrastructure is not proof of an exposed or authorized FX action.",
        "N": "The neutral requirement deliberately preserves an unknown and cannot be converted into parity or credit.",
    },
    "comparison_rows": rows,
    "outcome_counts": outcome_counts,
    "native_workflow": [
        "Authenticated finance routes guarded by finance.ledger.manage provide a date-driven preview, aggregate draft creation, and immediate GL posting.",
        "Create derives the signed-in user's legacy organisational context and defaults the as-at date to today.",
        "FxRevaluationService scans foreign-currency open bills and non-zero active foreign-currency bank accounts.",
        "CurrencyService uses a dated direct or inverse rate when found, otherwise a currency-table rate or 1.0 fallback.",
        "Create.tsx displays foreign amount, booked and current rates, booked and current base values, and gain or loss.",
        "Store validates date and notes, recalculates, and persists only an aggregate draft total, creator, and item-count note.",
        "Post validates draft state and materiality, looks up chart codes 8300 and 3000, creates a balanced aggregate two-line adjustment journal, posts it immediately, and links it to the revaluation.",
        "Index exposes draft or posted state and a plain journal number.",
        "Generic journal reversal infrastructure exists but is not connected to an FX route or page action.",
    ],
    "boundary": {
        "architecture": "One operating organisation across multiple Sites; organization_id is legacy organisational context and not a tenant boundary.",
        "rbac": ["All FX routes use finance.ledger.manage.", "The slice establishes a permission guard but not least-privilege separation among inspection, draft creation, and journal posting.", "Effective permission aliases, roles, and policy registration were outside the sealed manifest."],
        "site_scope": ["No Site is selected, retained, or validated in FX exposure calculation.", "FX journal construction omits site_id although the generic journal writer accepts it.", "Organisation-wide FX should require an explicit global-Site finance capability; ordinary approved-Site access must not silently imply organisation-wide posting.", "Site-owned exposures should retain their canonical Site on snapshot and journal lines and be checked against approved Sites."],
        "direct_object": ["Index and create derive organisational context from the signed-in user.", "FxRevaluationController::post accepts ordinary FinFxRevaluation route-model binding with no visible policy, canonical-context, approved-Site, or ownership check.", "The enumerated slice does not establish concealment for an existing but out-of-scope direct ID."],
        "privacy": ["Financial references, balances, creator names, and journal identifiers are visible to anyone passing the single manage permission.", "No finer privacy boundary is visible in the sealed FX slice."],
        "terminology": "The UI uses native finance language and introduces no tenant terminology or supported-living terminology conflict.",
    },
    "native_improvement_requirements": improvements,
    "counts": {"comparison_rows": 30, **outcome_counts, "source_files": 8, "benchmark_selections": 0, "NCM_findings": 0, "credit_awards": 0, "writes_by_agent_c": 0},
    "attestation": {
        "exact_corrected_sealed_input_hash_verified": True,
        "only_fx_neutral_slice_used": True,
        "all_30_fx_ids_reviewed_once": True,
        "all_8_manifest_files_accounted_for": True,
        "all_8_commit_blob_and_sha256_values_verified": True,
        "working_tree_application_source_read": False,
        "invoice_source_read": False,
        "agent_a_input_or_upstream_identity_read": False,
        "run071_or_old_comparison_read": False,
        "network_browser_runtime_tests_build_database_or_package_tools_used": False,
        "unknowns_preserved": True,
        "benchmark_selected_or_NCM_mapping_credit_awarded": False,
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
