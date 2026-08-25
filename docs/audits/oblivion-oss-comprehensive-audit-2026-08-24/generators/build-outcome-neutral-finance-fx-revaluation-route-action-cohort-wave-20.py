#!/usr/bin/env python3
"""Freeze RUN-129's two pending FX-revaluation route actions for fresh review.

The reviewed RUN-127 reporting boundary leaves 401 RUN-090 queue rows pending.
This producer selects only the two remaining CAP-FIN-FX-REVALUATION route rows:
the draft-creation action and the posting action.  Existing ownership for the
Index/Create routes and pages is retained solely as non-inheritable context.
No semantic outcome, source ownership, correctness, runtime, or downstream
credit is granted by this producer.
"""

from __future__ import annotations

import csv
import hashlib
import importlib.util
import json
from collections import Counter
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
BASE_GENERATOR = (
    AUDIT_DIR
    / "generators/build-outcome-neutral-finance-chart-route-action-cohort-wave-18.py"
)
OUTPUT_PATH = (
    AUDIT_DIR
    / "evidence/source/root-run-129-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.json"
)
PROMPT_PATH = Path(
    r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md"
)

CHECKPOINT_COMMIT = "73b941a2ff8b587f4cfc813564dab0dd74a3c68b"
CHECKPOINT_TREE = "9e33027018fc5b77b5f6ee09d0b4828c8cc45240"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
TESTS_TREE = "fef0122b31fdccbe2f9f805f7515666c74e2880a"
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
FEATURE_ID = "CAP-FIN-FX-REVALUATION"

EXPECTED_QUEUE_IDS = ["RUN090-ROUTE-0069", "RUN090-ROUTE-0070"]
EXPECTED_ROUTE_IDS = ["RUN077-ROUTE-0590", "RUN077-ROUTE-0591"]
EXPECTED_QUEUE_INDICES = [68, 69]
PRIOR_ROUTE_IDS = ["RUN077-ROUTE-0588", "RUN077-ROUTE-0589"]
PRIOR_PAGE_IDS = ["PAGE-ROOT-B05B4F27C8925C26", "PAGE-ROOT-636314D5B8CE1E1A"]

spec = importlib.util.spec_from_file_location("run121_base", BASE_GENERATOR)
assert spec and spec.loader
BASE = importlib.util.module_from_spec(spec)
spec.loader.exec_module(BASE)

sha256_file = BASE.sha256_file
canonical_json_sha256 = BASE.canonical_json_sha256
canonical_list_sha256 = BASE.canonical_list_sha256
load_json = BASE.load_json
git = BASE.git
index_unique = BASE.index_unique
semantic_slice = BASE.semantic_slice
transitive_local_helper_slices = BASE.transitive_local_helper_slices
request_contracts_for_slice = BASE.request_contracts_for_slice
feature_projection = BASE.feature_projection

INPUT_PATHS = {
    "base_generator": BASE_GENERATOR,
    "matrix": AUDIT_DIR / "03-feature-to-benchmark-matrix.csv",
    "manifest": AUDIT_DIR / "evidence/source/root-run-077-route-page-universe-manifest-wave-07.json",
    "classification": AUDIT_DIR / "evidence/source/current-route-page-classification-wave-07.json",
    "candidate_manifest": AUDIT_DIR / "evidence/source/root-run-082-exact-owner-containment-candidate-manifest-wave-08.json",
    "candidate_review": AUDIT_DIR / "evidence/source/raw-run-082r-independent-exact-owner-containment-review-wave-08.json",
    "ownership_ledger": AUDIT_DIR / "evidence/source/root-run-086-reviewed-static-route-page-feature-ownership-wave-10.json",
    "direct_queue": AUDIT_DIR / "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json",
    "run091_cohort": AUDIT_DIR / "evidence/source/root-run-091-closed-route-action-page-chain-cohort-wave-11.json",
    "run092_overlay": AUDIT_DIR / "evidence/source/current-run-092-reviewed-static-source-ownership-overlay-wave-11.json",
    "run097_cohort": AUDIT_DIR / "evidence/source/root-run-097-route-controller-only-candidate-cohort-wave-12.json",
    "run098_overlay": AUDIT_DIR / "evidence/source/current-run-098-reviewed-route-controller-only-ownership-overlay-wave-12.json",
    "run101_cohort": AUDIT_DIR / "evidence/source/root-run-101-outcome-neutral-route-action-cohort-wave-13.json",
    "run102_overlay": AUDIT_DIR / "evidence/source/current-run-102-reviewed-outcome-neutral-route-action-ownership-overlay-wave-13.json",
    "run106_overlay": AUDIT_DIR / "evidence/source/current-run-106-reviewed-outcome-neutral-page-render-owner-ownership-overlay-wave-14.json",
    "run110_overlay": AUDIT_DIR / "evidence/source/current-run-110-reviewed-outcome-neutral-page-render-owner-tail-ownership-overlay-wave-15.json",
    "run113_cohort": AUDIT_DIR / "evidence/source/root-run-113-outcome-neutral-name-only-route-action-cohort-wave-16.json",
    "run114_overlay": AUDIT_DIR / "evidence/source/current-run-114-reviewed-outcome-neutral-name-only-route-action-ownership-overlay-wave-16.json",
    "run118_overlay": AUDIT_DIR / "evidence/source/current-run-118-reviewed-outcome-neutral-respite-handover-page-gap-ownership-overlay-wave-17.json",
    "run121_cohort": AUDIT_DIR / "evidence/source/root-run-121-outcome-neutral-finance-chart-route-action-cohort-wave-18.json",
    "run122_overlay": AUDIT_DIR / "evidence/source/current-run-122-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json",
    "run122_overlay_review": AUDIT_DIR / "evidence/source/raw-run-122r-independent-reviewed-outcome-neutral-finance-chart-route-action-ownership-overlay-wave-18.json",
    "run126_overlay": AUDIT_DIR / "evidence/source/current-run-126-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json",
    "run126_overlay_review": AUDIT_DIR / "evidence/source/raw-run-126r-independent-reviewed-outcome-neutral-finance-page-gap-ownership-overlay-wave-19.json",
    "run127_reporting": AUDIT_DIR / "evidence/source/current-run-127-reviewed-finance-page-gap-reporting-wave-19.json",
    "run128_dashboard": AUDIT_DIR / "evidence/browser/current-audit-dashboard-verification-run-128-wave-19.json",
}

EXPECTED_INPUT_SHA256 = {
    "base_generator": "c7795bee971e051873e3953eb4e1bb7c62eb372b6890149700d0c401d64305dd",
    "matrix": "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390",
    "manifest": "150fcff9b100ad85a7a2e998ed69c8dafdc2d0098e8ec2b4dbac7d3b404061be",
    "classification": "7b534fea15e6c4ec98fbb2cb0c761e26116b2abee500c39e599b0e779729df97",
    "candidate_manifest": "3c6ad4df13a09ae8ff8f19aee09a1907f9754a1877806659676c6c2898652f85",
    "candidate_review": "a6a4f886ca209bc41ffa86afec37f6bddaf062ac80a6b375391adeea20e1c396",
    "ownership_ledger": "bfec52a9bed7176045457e8920bdc7b65e1c1d5c5eeb28a266f49c12acf69bcf",
    "direct_queue": "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    "run091_cohort": "7b1fea57104e9f4bfea7ee450ef535ac57e7fa69bc978212b4eafb0fe1ed0cae",
    "run092_overlay": "5390e55d9e47d1845afa8b3848bdab7687747cbb62c946dd4d66d3c435e8de0b",
    "run097_cohort": "69981d1bc22d76b8f17834040272260d9b33c151535a3ff2ef17ae4643923933",
    "run098_overlay": "7f76c9cfeae906a64c92013fbf9a12cf180d39a315bfa13e778b6332365cdd2a",
    "run101_cohort": "3a8f4c3f11668406f34db7e50ae561fe1c6516e7002eb7e8271851e62c3ff655",
    "run102_overlay": "cf68900351832c790ec53b4996daaf640c604c498f902c85dec681113bf492dd",
    "run106_overlay": "9c1600d2365c3527e185f313b7cb1586705f39cefb15d5d212dc708b45b630ea",
    "run110_overlay": "9375fb374b589c5068334795fa03d80a7ba1fd35695808f99c9e2591e886efca",
    "run113_cohort": "0849ca5c10e31a4f3e5133a521a570d60b004e723fb17a7f2e812e485f345461",
    "run114_overlay": "cbeb46be682ca0c9ca54012e2c27cf548b54e9cbb75b050fcd61691ca43aaef2",
    "run118_overlay": "33dd2f8ffa2b35c6651a6bf18923872e70ba5c2f91fce8b7222666bb8a91fc8b",
    "run121_cohort": "cfe0e3635e5e86bf8e7e2f65d2094743738bfa5edc36e361ecf5eb14986f316e",
    "run122_overlay": "d7aee21e7c4230b44707a22b7fa93478a84e9a5b4775ecd25aaffede764855ca",
    "run122_overlay_review": "2130e3801b6ac163580bc56f23d6647136c83fdadc8ea65804b1559d36b29484",
    "run126_overlay": "15ab65b479daa7e7c3f2f3fbd979a13ead87dfbedf31c163a27b5eb809b12f10",
    "run126_overlay_review": "78d969e823885ed7a12a3b6c4e3b2856e91823588e4f51f9dbeefb12f5d22be2",
    "run127_reporting": "9db62d439c45af768a7d1cd919251488a8c877fc20f59de27ec88e153588c040",
    "run128_dashboard": "c6d92421fa9e51ae875067de414fb9c38e52708cd6293fae42dc82a5bb2bd9bc",
}

SOURCE_FILE_SPECS = {
    "routes/finance.php": {
        "sha256": "cf6eed8437206aaf05feb541d031ce406382e13153a31bb831ef66b29994f1aa",
        "review_loci": ["routes/finance.php:62", "routes/finance.php:594-600"],
        "purpose": "auth prefix, finance name prefix, ledger-manage permission group, and exact route declarations",
    },
    "app/Domain/Finance/Http/Controllers/FxRevaluationController.php": {
        "sha256": "1bc7062eeac5d36889fa058c476207919971c96beeda2dac72a60f75b797545b",
        "review_loci": [
            "app/Domain/Finance/Http/Controllers/FxRevaluationController.php:11-82",
            "app/Domain/Finance/Http/Controllers/FxRevaluationController.php:54-71",
            "app/Domain/Finance/Http/Controllers/FxRevaluationController.php:72-83",
        ],
        "purpose": "complete controller context and the two selected actions",
    },
    "app/Domain/Finance/Services/FxRevaluationService.php": {
        "sha256": "bfdb84ddee4a99c3f0551e27d31a8d51c21348ecf14e56150346bdf382df6892",
        "review_loci": [
            "app/Domain/Finance/Services/FxRevaluationService.php:30-131",
            "app/Domain/Finance/Services/FxRevaluationService.php:132-148",
            "app/Domain/Finance/Services/FxRevaluationService.php:149-232",
        ],
        "purpose": "calculation, draft creation, status transition, account selection, and journal posting",
    },
    "app/Domain/Finance/Models/FinFxRevaluation.php": {
        "sha256": "3d819eb5769837c78094f4adecc489f4b60201382779237db3bf7c47564f2395",
        "review_loci": [
            "app/Domain/Finance/Models/FinFxRevaluation.php:11-52",
            "app/Domain/Finance/Models/FinFxRevaluation.php:48-52",
        ],
        "purpose": "binding model, fillable ownership context, state, relationships, and optional organisation scope",
    },
    "app/Domain/Finance/Services/CurrencyService.php": {
        "sha256": "9936ff104f596bf6fc222e7341d2ba907b34d44c998f99842689a1d3added25a",
        "review_loci": [
            "app/Domain/Finance/Services/CurrencyService.php:15-71",
            "app/Domain/Finance/Services/CurrencyService.php:94-104",
        ],
        "purpose": "exchange-rate lookup, silent fallbacks, and placeholder manual-rate semantics",
    },
    "app/Domain/Finance/Services/JournalPostingService.php": {
        "sha256": "092af77653c278507f2bdb10fdcf24b327d511c274b473bb081e813b66f65526",
        "review_loci": [
            "app/Domain/Finance/Services/JournalPostingService.php:21-29",
            "app/Domain/Finance/Services/JournalPostingService.php:30-141",
            "app/Domain/Finance/Services/JournalPostingService.php:261-271",
            "app/Domain/Finance/Services/JournalPostingService.php:272-303",
            "app/Domain/Finance/Services/JournalPostingService.php:304-342",
            "app/Domain/Finance/Services/JournalPostingService.php:343-387",
            "app/Domain/Finance/Services/JournalPostingService.php:388-403",
        ],
        "purpose": "journal creation, validation, posting, locking, sequence, and line persistence",
    },
    "resources/js/pages/finance/fx-revaluations/Create.tsx": {
        "sha256": "34276b902e90da6bf8bbce9b5e902fe1d9c43c29340436d1d936c10e3cc0bce1",
        "review_loci": ["resources/js/pages/finance/fx-revaluations/Create.tsx:52-69"],
        "purpose": "already-owned page context containing the store caller",
    },
    "resources/js/pages/finance/fx-revaluations/Index.tsx": {
        "sha256": "f16069b9ab7b30163d008145cbb674849079f446943020fa2492e23a76694115",
        "review_loci": ["resources/js/pages/finance/fx-revaluations/Index.tsx:58-72"],
        "purpose": "already-owned page context containing the post caller",
    },
    "app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php": {
        "sha256": "c50111efb044965e3c301338491f67510fecba1d915725a1d2b7efb6d12c1f80",
        "review_loci": [
            "app/Domain/Finance/Services/AccountingSyncProviders/XeroSyncProvider.php:27-319"
        ],
        "purpose": "matrix-listed identity collision context only, not inherited action evidence",
    },
    "tests/Browser/Finance/FinanceMiscTest.php": {
        "sha256": "bfa9048e0c16ccda2a7a11916e3118c5b4dc9cdad1f8e12c48f81697338be836",
        "review_loci": [
            "tests/Browser/Finance/FinanceMiscTest.php:206-214",
            "tests/Browser/Finance/FinanceMiscTest.php:316-323",
        ],
        "purpose": "unexecuted load-only browser context; it does not exercise either selected action",
    },
    "docs/architecture/single-tenant-application.md": {
        "sha256": "3dea6218db87ce22bed3cab6b9c500d1a850445d04e9325cb16c23a604979b3c",
        "review_loci": ["docs/architecture/single-tenant-application.md:1-13"],
        "purpose": "canonical one-organisation multi-Site architecture and legacy organisation-field boundary",
    },
    "app/Models/Concerns/WritesLegacyOrganizationStorageContext.php": {
        "sha256": "265b76912c184888fc137988ccd30ba72d43ee89eb7167ba5f6fdcd31ea99118",
        "review_loci": ["app/Models/Concerns/WritesLegacyOrganizationStorageContext.php:1-26"],
        "purpose": "legacy organisation storage context that is explicitly not an access boundary",
    },
    "bootstrap/app.php": {
        "sha256": "7869aa6a50968b1114d2b65e26de5fae85cae86387b77e94dfdd88c421bebd76",
        "review_loci": ["bootstrap/app.php:1-96"],
        "purpose": "middleware alias and route middleware registration context",
    },
    "app/Http/Middleware/EnsurePermission.php": {
        "sha256": "d9477e5fe8d3dd762332be8ddf3929e4e6098d039e625af40a0241a0ab958e30",
        "review_loci": ["app/Http/Middleware/EnsurePermission.php:1-23"],
        "purpose": "literal finance.ledger.manage permission enforcement path",
    },
    "app/Models/User.php": {
        "sha256": "0d184ebe6a28395b34b195751ffb62390f4c32828c982103ad613430bc4e59ae",
        "review_loci": ["app/Models/User.php:359-407"],
        "purpose": "canDo permission semantics and actor access context",
    },
    "app/Providers/AuthServiceProvider.php": {
        "sha256": "7bcbfb866f657f1e64665806b9f496f3994ba0e90f59686ef71fb97c458b47be",
        "review_loci": ["app/Providers/AuthServiceProvider.php:1-212"],
        "purpose": "policy registration context confirming no explicit FinFxRevaluation policy mapping",
    },
    "app/Models/Concerns/AuditableChanges.php": {
        "sha256": "7f6cccadbeb9a9d8ea62b42ff350922e647ab2e076af3216343a6fab811478be",
        "review_loci": ["app/Models/Concerns/AuditableChanges.php:1-88"],
        "purpose": "direct revaluation model audit lifecycle context",
    },
    "app/Domain/Finance/Events/JournalPosted.php": {
        "sha256": "7690e35aa5dcf5f30f61a625173f02fc32e6fc6fc5ed56a9dad1c7325c51aa57",
        "review_loci": ["app/Domain/Finance/Events/JournalPosted.php:1-20"],
        "purpose": "after-post event payload context",
    },
    "app/Providers/AppServiceProvider.php": {
        "sha256": "eeb5aed328a861e6332caab76851976fc48673d7a5d171273ac59ba2b8a75745",
        "review_loci": ["app/Providers/AppServiceProvider.php:524-532"],
        "purpose": "JournalPosted listener registration context",
    },
    "app/Listeners/Finance/LogJournalPosted.php": {
        "sha256": "a9427dd5ba554b45c894b06890326ed6eb70dbd2703fa18ac0096ee40e942777",
        "review_loci": ["app/Listeners/Finance/LogJournalPosted.php:1-15"],
        "purpose": "registered journal-post listener semantics",
    },
    "app/Domain/Finance/Models/FinCurrency.php": {
        "sha256": "c38acc4fcfa6705bf58527c3a16c4730a43f96c52f4278e78805f8d35d3d37cd",
        "review_loci": ["app/Domain/Finance/Models/FinCurrency.php:1-65"],
        "purpose": "currency organisation scope, base flag, and fallback rate fields",
    },
    "app/Domain/Finance/Models/FinFxRate.php": {
        "sha256": "b04ca1e1d52076549fcf721d452ca235cd467beb3c940a1f828cbdd0607ab6a5",
        "review_loci": ["app/Domain/Finance/Models/FinFxRate.php:1-51"],
        "purpose": "dated rate pair and optional organisation scope semantics",
    },
    "app/Domain/Finance/Models/FinBill.php": {
        "sha256": "467220b9a994070626369fcb7e5153cdbcd994e9196f171df8b00aefbfacbc04",
        "review_loci": ["app/Domain/Finance/Models/FinBill.php:1-130"],
        "purpose": "foreign-currency open-bill calculation dependency",
    },
    "app/Domain/Finance/Models/FinBankAccount.php": {
        "sha256": "7dfde42851923705cdade60a9569eeabc6975e57c0eab9bd1dac9001c01fc78f",
        "review_loci": ["app/Domain/Finance/Models/FinBankAccount.php:1-71"],
        "purpose": "foreign-currency bank-balance calculation dependency",
    },
    "app/Domain/Finance/Models/FinAccount.php": {
        "sha256": "d5ef3eb0c8ba92035aeee5e804ff3f117a1a7117b2cd1b81eeadc9ba200a43cc",
        "review_loci": ["app/Domain/Finance/Models/FinAccount.php:1-99"],
        "purpose": "8300 and 3000 account selection and organisation-scope dependency",
    },
    "app/Domain/Finance/Models/FinJournal.php": {
        "sha256": "27455075c067e39e76ef0d29f754ff2d9a684c5bd35af0c9c68deef742e423d8",
        "review_loci": ["app/Domain/Finance/Models/FinJournal.php:1-114"],
        "purpose": "journal status, source linkage, sequence, and relationship context",
    },
    "app/Domain/Finance/Models/FinJournalLine.php": {
        "sha256": "8bc6f37faacfcaab72c651c1baa897ef110e2e77a52ac1db102c33ce72bd633e",
        "review_loci": ["app/Domain/Finance/Models/FinJournalLine.php:1-64"],
        "purpose": "journal line account, Site, client, and allocation context",
    },
    "app/Domain/Finance/Models/FinFiscalPeriod.php": {
        "sha256": "2c98c141041211b5ae52e54cb68ed511c43d4ff1584e237ed9c19c08f1f83de1",
        "review_loci": ["app/Domain/Finance/Models/FinFiscalPeriod.php:1-53"],
        "purpose": "fiscal-period organisation scope and open-state dependency",
    },
    "database/migrations/2026_03_28_001000_create_fin_journals_table.php": {
        "sha256": "2468357f53468d4744b59432014c60b8a7a17a41644e02846ac2210f89d9dbcb",
        "review_loci": ["database/migrations/2026_03_28_001000_create_fin_journals_table.php:1-39"],
        "purpose": "journal uniqueness, source linkage, and posting schema constraints",
    },
    "database/migrations/2026_03_28_001100_create_fin_journal_lines_table.php": {
        "sha256": "b44b0c8642ac058236f950c9fe2db5de9b9964fd86e14686c99407d1345b890f",
        "review_loci": ["database/migrations/2026_03_28_001100_create_fin_journal_lines_table.php:1-29"],
        "purpose": "journal-line foreign keys and allocation schema constraints",
    },
    "database/migrations/2026_03_28_003100_create_fin_currencies_table.php": {
        "sha256": "b543815d6be1f6ee4d33cd14d35f7585ec8f6f1145722f01fe62cb1cbba22f77",
        "review_loci": ["database/migrations/2026_03_28_003100_create_fin_currencies_table.php:1-30"],
        "purpose": "currency base/rate schema and uniqueness constraints",
    },
    "database/migrations/2026_03_28_003200_create_fin_fx_rates_table.php": {
        "sha256": "89afbc266a02d20260da55e48a244a52cf1adbd3634b58b3dee1fcf71f6ebd2b",
        "review_loci": ["database/migrations/2026_03_28_003200_create_fin_fx_rates_table.php:1-29"],
        "purpose": "FX pair/date schema and uniqueness constraints",
    },
    "database/migrations/2026_03_28_003300_create_fin_fx_revaluations_table.php": {
        "sha256": "9b2aa38a4e08c599cbaa64052374a9e54d9946b606f9c99caa5d042425ae8ec0",
        "review_loci": ["database/migrations/2026_03_28_003300_create_fin_fx_revaluations_table.php:1-26"],
        "purpose": "revaluation status, journal backlink, and missing idempotency constraints",
    },
    "database/migrations/2026_03_28_003400_add_currency_fields_to_fin_tables.php": {
        "sha256": "eb403ab419ff7a0187d36642e36c5f6ac837c1e71da5dc7703b68a5028b0ee50",
        "review_loci": ["database/migrations/2026_03_28_003400_add_currency_fields_to_fin_tables.php:1-51"],
        "purpose": "bill and bank-account currency/rate schema context",
    },
    "database/migrations/2026_08_23_000080_enforce_fixed_asset_depreciation_period.php": {
        "sha256": "3927a800cec93e8720c5921711cae193530652aba965ef825cb1d5e93a0d13be",
        "review_loci": ["database/migrations/2026_08_23_000080_enforce_fixed_asset_depreciation_period.php:1-163"],
        "purpose": "journal sequence mutex schema and backfill context",
    },
    "tests/Feature/Finance/ConsolidationQuarantineTest.php": {
        "sha256": "52d861a82cf5f2e497bf734dab3f319c5097c17de258006c0165bd6b586a710c",
        "review_loci": ["tests/Feature/Finance/ConsolidationQuarantineTest.php:1-162"],
        "purpose": "matrix-listed but unrelated unexecuted test context",
    },
}

OVERLAY_NAMES = (
    "run092_overlay",
    "run098_overlay",
    "run102_overlay",
    "run106_overlay",
    "run110_overlay",
    "run114_overlay",
    "run118_overlay",
    "run122_overlay",
    "run126_overlay",
)


def assert_workspace_and_inputs() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git("rev-parse", "HEAD^{tree}") == CHECKPOINT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "HEAD:app") == APP_TREE
    assert git("rev-parse", "HEAD:routes") == ROUTES_TREE
    assert git("rev-parse", "HEAD:resources/js") == RESOURCES_JS_TREE
    assert git("rev-parse", "HEAD:resources/js/pages") == RESOURCES_JS_PAGES_TREE
    assert git("rev-parse", "HEAD:tests") == TESTS_TREE
    assert git("status", "--porcelain", "--", "app", "routes", "resources/js", "tests") == ""
    assert PROMPT_PATH.is_file()
    assert sha256_file(PROMPT_PATH) == PROMPT_SHA256
    for name, target in INPUT_PATHS.items():
        assert target.is_file(), target
        assert sha256_file(target) == EXPECTED_INPUT_SHA256[name], name
    for relative, spec_data in SOURCE_FILE_SPECS.items():
        target = REPO / relative
        assert target.is_file(), target
        assert sha256_file(target) == spec_data["sha256"], relative


def cohort_route_ids(payload: dict[str, Any]) -> set[str]:
    route_ids: set[str] = set()
    for row in payload["records"]:
        route_id = row.get("route_record_id") or row.get("route_source", {}).get("route_record_id")
        assert isinstance(route_id, str) and route_id
        route_ids.add(route_id)
    return route_ids


def exact_line_context(relative: str, needle: str) -> dict[str, Any]:
    lines = (REPO / relative).read_text(encoding="utf-8-sig").splitlines()
    matches = [(number, line) for number, line in enumerate(lines, 1) if needle in line]
    assert len(matches) == 1, (relative, needle, matches)
    number, line = matches[0]
    return {
        "page_file": relative,
        "page_file_sha256": sha256_file(REPO / relative),
        "page_file_blob_id": git("rev-parse", f"HEAD:{relative}"),
        "source_anchor": f"{relative}:{number}",
        "source_line": line.strip(),
        "source_line_sha256": hashlib.sha256(line.encode("utf-8")).hexdigest(),
    }


def source_review_packet() -> dict[str, Any]:
    required_files = []
    for relative, spec_data in SOURCE_FILE_SPECS.items():
        required_files.append(
            {
                "path": relative,
                "sha256": spec_data["sha256"],
                "blob_id": git("rev-parse", f"HEAD:{relative}"),
                "review_loci": spec_data["review_loci"],
                "purpose": spec_data["purpose"],
            }
        )

    method_specs = [
        ("app/Domain/Finance/Services/FxRevaluationService.php", "calculateUnrealisedGainLoss"),
        ("app/Domain/Finance/Services/FxRevaluationService.php", "createRevaluation"),
        ("app/Domain/Finance/Services/FxRevaluationService.php", "postRevaluation"),
        ("app/Domain/Finance/Services/CurrencyService.php", "getExchangeRate"),
        ("app/Domain/Finance/Services/CurrencyService.php", "fetchLatestRates"),
        ("app/Domain/Finance/Models/FinFxRevaluation.php", "scopeForOrganization"),
        ("app/Domain/Finance/Services/JournalPostingService.php", "createDraftJournal"),
        ("app/Domain/Finance/Services/JournalPostingService.php", "post"),
        ("app/Domain/Finance/Services/JournalPostingService.php", "createAndPost"),
        ("app/Domain/Finance/Services/JournalPostingService.php", "generateJournalNumber"),
        ("app/Domain/Finance/Services/JournalPostingService.php", "lockJournalSequence"),
        ("app/Domain/Finance/Services/JournalPostingService.php", "createDraftJournalRecord"),
        ("app/Domain/Finance/Services/JournalPostingService.php", "nextJournalNumberFromLedger"),
    ]
    dependency_slices = [semantic_slice(path, method) for path, method in method_specs]

    packet = {
        "required_source_files": required_files,
        "required_source_file_count": len(required_files),
        "material_dependency_method_slices": dependency_slices,
        "material_dependency_method_slice_count": len(dependency_slices),
        "source_review_complete": False,
        "source_packet_completeness_claimed": False,
        "material_dependency_semantics_complete": False,
        "matrix_collision_context_is_owner_evidence": False,
        "unexecuted_test_context_is_runtime_evidence": False,
        "unresolved_semantic_gap_inventory": [
            {
                "gap": "authorization_and_permission_resolution",
                "status": "REQUIRES_FRESH_SEMANTIC_REVIEW",
                "boundary": "middleware declaration and permission storage do not prove exact-action authorization",
            },
            {
                "gap": "single_organisation_multi_site_record_scope",
                "status": "REQUIRES_FRESH_SEMANTIC_REVIEW",
                "boundary": "legacy organization_id context does not prove approved-Site or canonical-record scope",
            },
            {
                "gap": "direct_object_binding_and_concealment",
                "status": "REQUIRES_FRESH_SEMANTIC_REVIEW",
                "boundary": "implicit model binding does not prove canonical ownership or direct-object denial",
            },
            {
                "gap": "currency_rate_provenance_and_fallback",
                "status": "REQUIRES_FRESH_SEMANTIC_REVIEW",
                "boundary": "manual placeholder rates and date fallbacks require separate correctness adjudication",
            },
            {
                "gap": "revaluation_snapshot_schema_and_idempotency",
                "status": "REQUIRES_FRESH_SEMANTIC_REVIEW",
                "boundary": "schema constraints do not establish immutable item/rate snapshots or exactly-once posting",
            },
            {
                "gap": "transaction_and_lock_ordering",
                "status": "REQUIRES_FRESH_SEMANTIC_REVIEW",
                "boundary": "journal-number sequence locking does not lock the revaluation lifecycle row",
            },
            {
                "gap": "journal_posted_event_side_effects",
                "status": "REQUIRES_FRESH_SEMANTIC_REVIEW",
                "boundary": "event dispatch and listener registration do not prove durable downstream correctness",
            },
            {
                "gap": "store_and_post_executable_test_evidence",
                "status": "REQUIRES_FRESH_SEMANTIC_REVIEW",
                "boundary": "frozen tests do not execute either selected action",
            },
        ],
        "review_rule": (
            "Review each selected controller action completely, then follow the frozen material dependency "
            "slices. Unresolved external model, policy, Site, permission, direct-object, privacy, ledger, or "
            "concurrency semantics require EVIDENCE_GAP rather than inferred credit."
        ),
    }
    packet["source_review_packet_sha256"] = canonical_json_sha256(packet)
    return packet


def build() -> dict[str, Any]:
    assert_workspace_and_inputs()
    with INPUT_PATHS["matrix"].open("r", encoding="utf-8-sig", newline="") as handle:
        matrix_rows = list(csv.DictReader(handle))
    assert len(matrix_rows) == 340
    matrix_by_id = index_unique(matrix_rows, "feature_id")

    manifest = load_json(INPUT_PATHS["manifest"])
    classification = load_json(INPUT_PATHS["classification"])
    candidates = load_json(INPUT_PATHS["candidate_manifest"])
    candidate_review = load_json(INPUT_PATHS["candidate_review"])
    ownership = load_json(INPUT_PATHS["ownership_ledger"])
    queue = load_json(INPUT_PATHS["direct_queue"])
    cohorts = {
        name: load_json(INPUT_PATHS[name])
        for name in (
            "run091_cohort",
            "run097_cohort",
            "run101_cohort",
            "run113_cohort",
            "run121_cohort",
        )
    }
    overlays = {name: load_json(INPUT_PATHS[name]) for name in OVERLAY_NAMES}
    run122_review = load_json(INPUT_PATHS["run122_overlay_review"])
    run126_review = load_json(INPUT_PATHS["run126_overlay_review"])
    run127 = load_json(INPUT_PATHS["run127_reporting"])
    run128 = load_json(INPUT_PATHS["run128_dashboard"])

    assert candidate_review["verdict"]["decision"] == "GO"
    assert run122_review["decision"]["verdict"] == "GO"
    assert run126_review["decision"]["verdict"] == "GO"
    assert run128["verification"]["state"] == "GO"
    assert run128["pins"]["reporting_receipt_sha256"] == EXPECTED_INPUT_SHA256["run127_reporting"]
    assert run128["audit_completion_test_met"] is False

    expected_baseline = {
        "source_owner_records": 652,
        "route_owner_records": 295,
        "page_owner_records": 357,
        "distinct_feature_ids": 256,
        "distinct_H_feature_ids": 234,
        "distinct_D_feature_ids": 22,
        "route_distinct_feature_ids": 62,
        "page_distinct_feature_ids": 242,
        "route_page_feature_overlap": 48,
        "static_controller_action_bridges": 83,
        "bounded_static_source_denominator": 3929,
        "bounded_static_source_ownership_percent": "16.594553",
        "bounded_static_source_residual_records": 3277,
        "residual_explicit_unmapped_routes": 2906,
        "semantic_shared_routes": 12,
        "reviewed_alias_routes": 5,
        "reviewed_dead_routes": 0,
        "evidence_gap_routes_tagged_within_residual": 7,
        "residual_unadjudicated_page_roots": 345,
        "semantic_shared_page_roots": 9,
        "reviewed_alias_page_roots": 0,
        "reviewed_dead_page_roots": 0,
        "evidence_gap_page_roots_tagged_within_residual": 1,
        "matrix_rows_changed": 0,
        "matrix_cells_changed": 0,
        "direct_exact_queue_records": 507,
        "reviewed_queue_surface_rows": 106,
        "owner_queue_surface_rows": 84,
        "shared_queue_surface_rows": 10,
        "alias_queue_surface_rows": 5,
        "dead_queue_surface_rows": 0,
        "evidence_gap_queue_surface_rows": 7,
        "pending_unreviewed_queue_surface_rows": 401,
        "queue_surfaces_without_ownership": 423,
    }
    for key, value in expected_baseline.items():
        assert run127["counts"][key] == value, key

    route_rows = list(manifest["route_universe"]["primary_route_facade_callsites"])
    route_rows += list(manifest["route_universe"]["route_like_sentinels"])
    route_by_id = index_unique(route_rows, "route_record_id")
    decision_by_id = index_unique(classification["route_decisions"], "route_record_id")
    candidate_by_id = index_unique(
        candidates["route_static_candidate_census"]["records"], "route_record_id"
    )

    current_owner_rows: list[dict[str, Any]] = list(ownership["records"])
    owner_origin: dict[str, str] = {
        row["source_record_id"]: "ownership_ledger" for row in ownership["records"]
    }
    bridge_rows: list[dict[str, Any]] = []
    bridge_origin: dict[str, str] = {}
    for name in OVERLAY_NAMES:
        overlay = overlays[name]
        for row in overlay["overlay_source_records"]:
            current_owner_rows.append(row)
            assert row["source_record_id"] not in owner_origin
            owner_origin[row["source_record_id"]] = name
        for bridge_field in ("static_controller_action_bridges", "new_static_controller_action_bridges"):
            for row in overlay.get(bridge_field, []):
                bridge_rows.append(row)
                assert row["bridge_id"] not in bridge_origin
                bridge_origin[row["bridge_id"]] = name

    current_owner_by_id = index_unique(current_owner_rows, "source_record_id")
    current_owner_ids = set(current_owner_by_id)
    current_owner_features = {row["feature_id"] for row in current_owner_rows}
    assert len(current_owner_rows) == len(current_owner_ids) == 652
    assert len(current_owner_features) == 256
    assert FEATURE_ID in current_owner_features
    assert len(bridge_rows) == 83

    reviewed_route_ids: set[str] = set()
    for cohort in cohorts.values():
        reviewed_route_ids |= cohort_route_ids(cohort)
    assert len(reviewed_route_ids) == 104

    feature_queue = [
        (index, row)
        for index, row in enumerate(queue["records"])
        if row["candidate_feature_id"] == FEATURE_ID
    ]
    assert [row["source_record_id"] for _, row in feature_queue] == PRIOR_ROUTE_IDS + EXPECTED_ROUTE_IDS
    assert all(route_id in reviewed_route_ids for route_id in PRIOR_ROUTE_IDS)
    assert all(route_id in current_owner_ids for route_id in PRIOR_ROUTE_IDS)
    assert all(route_id not in reviewed_route_ids for route_id in EXPECTED_ROUTE_IDS)
    assert all(route_id not in current_owner_ids for route_id in EXPECTED_ROUTE_IDS)

    selected = [
        (index, row) for index, row in feature_queue if row["queue_id"] in EXPECTED_QUEUE_IDS
    ]
    assert [index for index, _ in selected] == EXPECTED_QUEUE_INDICES
    assert [row["queue_id"] for _, row in selected] == EXPECTED_QUEUE_IDS
    assert [row["source_record_id"] for _, row in selected] == EXPECTED_ROUTE_IDS

    prior_owner_specs = [
        ("RUN077-ROUTE-0588", "run098_overlay", "INDEX_ROUTE_OWNER_CONTEXT"),
        ("RUN077-ROUTE-0589", "run092_overlay", "CREATE_ROUTE_OWNER_CONTEXT"),
        ("PAGE-ROOT-B05B4F27C8925C26", "ownership_ledger", "INDEX_PAGE_OWNER_CONTEXT"),
        ("PAGE-ROOT-636314D5B8CE1E1A", "run092_overlay", "CREATE_PAGE_OWNER_CONTEXT"),
    ]
    prior_owner_context: list[dict[str, Any]] = []
    for source_id, expected_origin, relation in prior_owner_specs:
        row = current_owner_by_id[source_id]
        assert owner_origin[source_id] == expected_origin
        assert row["feature_id"] == FEATURE_ID
        prior_owner_context.append(
            {
                "relation": relation,
                "surface": row["surface"],
                "source_record_id": source_id,
                "source_record_key": row["source_record_key"],
                "feature_id": row["feature_id"],
                "owner_artifact": INPUT_PATHS[expected_origin].relative_to(AUDIT_DIR).as_posix(),
                "owner_row_id": row.get("mapping_id") or row.get("overlay_mapping_id"),
                "owner_row_sha256": row.get("ledger_row_sha256") or row.get("overlay_row_sha256"),
                "current_static_owner_credit_preserved": True,
                "ownership_inheritable_to_run129": False,
                "route_action_bridge_inheritable_to_run129": False,
                "correctness_or_downstream_credit_inheritable_to_run129": False,
            }
        )
    assert len(prior_owner_context) == 4

    prior_bridge_specs = [
        ("RUN098-BRIDGE-06", "run098_overlay", "RUN077-ROUTE-0588", "index"),
        ("RUN092-BRIDGE-02", "run092_overlay", "RUN077-ROUTE-0589", "create"),
    ]
    prior_bridge_by_id = index_unique(bridge_rows, "bridge_id")
    prior_bridge_context: list[dict[str, Any]] = []
    for bridge_id, expected_origin, route_id, method in prior_bridge_specs:
        row = prior_bridge_by_id[bridge_id]
        assert bridge_origin[bridge_id] == expected_origin
        assert row["route_record_id"] == route_id
        assert row["controller_file"] == "app/Domain/Finance/Http/Controllers/FxRevaluationController.php"
        assert row["method"] == method
        assert row["feature_id"] == FEATURE_ID
        prior_bridge_context.append(
            {
                "bridge_id": bridge_id,
                "bridge_key": [row["controller_file"], row["method"], row["feature_id"]],
                "route_record_id": row["route_record_id"],
                "controller_file": row["controller_file"],
                "method": row["method"],
                "feature_id": row["feature_id"],
                "bridge_row_sha256": row["bridge_row_sha256"],
                "owner_artifact": INPUT_PATHS[expected_origin].relative_to(AUDIT_DIR).as_posix(),
                "current_static_bridge_credit_preserved": True,
                "bridge_inheritable_to_run129": False,
                "ownership_or_correctness_inheritable_to_run129": False,
            }
        )
    assert [row["bridge_row_sha256"] for row in prior_bridge_context] == [
        "092fa44c8c89a09f8bfc172a849342bc6f3795af1c802117f0d70512078e68f3",
        "67c8ac1a720dd7671d45977569616291771fb20a7e9c364edfea40c7681867e8",
    ]

    frontend_callers = {
        "RUN077-ROUTE-0590": {
            **exact_line_context(
                "resources/js/pages/finance/fx-revaluations/Create.tsx",
                "post('/finance/fx-revaluations');",
            ),
            "page_record_id": "PAGE-ROOT-636314D5B8CE1E1A",
            "current_static_source_owner": True,
            "page_ownership_inheritable": False,
        },
        "RUN077-ROUTE-0591": {
            **exact_line_context(
                "resources/js/pages/finance/fx-revaluations/Index.tsx",
                "`/finance/fx-revaluations/${postTarget.id}/post`,",
            ),
            "page_record_id": "PAGE-ROOT-B05B4F27C8925C26",
            "current_static_source_owner": True,
            "page_ownership_inheritable": False,
        },
    }

    packet = source_review_packet()
    existing_bridge_keys = {
        (row["controller_file"], row["method"], row["feature_id"]) for row in bridge_rows
    }
    assert len(existing_bridge_keys) == len(bridge_rows) == 83
    records: list[dict[str, Any]] = []
    for sequence, (queue_index, queue_row) in enumerate(selected, 1):
        route_id = queue_row["source_record_id"]
        route_row = route_by_id[route_id]
        decision = decision_by_id[route_id]
        candidate = candidate_by_id[route_id]
        backend = candidate["backend_method_relation"]
        resolution = backend["resolution"]

        assert queue_row["surface"] == "ROUTE_SOURCE_RECORD"
        assert queue_row["review_state"]["status"] == "PENDING_FRESH_SEMANTIC_REVIEW"
        assert queue_row["secondary_lane"]["relation_comparison"] == "BOTH_LANES_IDENTICAL"
        assert queue_row["secondary_lane"]["contradictory_candidate_present"] is False
        assert decision["classification"] == "EXPLICIT_UNMAPPED_SENTINEL"
        assert candidate["relation_comparison"] == "BOTH_LANES_IDENTICAL"
        assert candidate["name_relation"]["candidate_feature_ids"] == [FEATURE_ID]
        assert backend["candidate_count"] == 1
        assert backend["candidate_feature_ids"] == [FEATURE_ID]
        assert resolution["status"] == "EXACT_CLASS_METHOD_ARRAY_RESOLVED_UNIQUE_DEFINITION"
        assert sha256_file(REPO / route_row["route_file"]) == route_row["route_file_sha256"]
        assert sha256_file(REPO / resolution["controller_file"]) == resolution["controller_file_sha256"]

        primary = semantic_slice(resolution["controller_file"], resolution["method"])
        assert primary["definition_line"] == resolution["definition_line"]
        helpers = transitive_local_helper_slices(
            resolution["controller_file"], resolution["method"], primary["review_slice"]["text"]
        )
        requests = request_contracts_for_slice(
            resolution["controller_file"], primary["review_slice"]["text"]
        )
        bridge_key = (resolution["controller_file"], resolution["method"], FEATURE_ID)
        assert bridge_key not in existing_bridge_keys

        action_key = f"{route_id}|{resolution['controller_file']}:{resolution['method']}|{FEATURE_ID}"
        record: dict[str, Any] = {
            "candidate_id": f"RUN129-FINANCE-FX-REVALUATION-ROUTE-ACTION-{sequence:02d}",
            "action_key": action_key,
            "review_partition": "A" if sequence == 1 else "B",
            "run090_original_partition": queue_row["review_partition"],
            "queue_index_zero_based": queue_index,
            "queue_id": queue_row["queue_id"],
            "queue_canonical_key": queue_row["canonical_key"],
            "candidate_feature_id": FEATURE_ID,
            "converged_identity": {
                "direct_identity": queue_row["direct_identity"],
                "relation_comparison": "BOTH_LANES_IDENTICAL",
                "name_candidate_count": candidate["name_relation"]["candidate_count"],
                "name_candidate_feature_ids": candidate["name_relation"]["candidate_feature_ids"],
                "backend_candidate_count": backend["candidate_count"],
                "backend_candidate_feature_ids": backend["candidate_feature_ids"],
                "matching_backend_anchors": backend["matching_matrix_anchors"],
                "two_identical_static_lanes_preselect_semantic_ownership": False,
                "candidate_only": True,
            },
            "route_source": {
                "route_record_id": route_id,
                "route_file": route_row["route_file"],
                "route_file_sha256": route_row["route_file_sha256"],
                "route_file_blob_id": route_row["route_file_blob_id"],
                "source_key": route_row["source_key"],
                "source_anchor": route_row["source_anchor"],
                "route_method": route_row["route_method"],
                "literal_uri": route_row["literal_uri"],
                "literal_route_name": queue_row["source"]["literal_route_name"],
                "action_expression": route_row["action_expression"],
                "statement_excerpt": route_row["statement_excerpt"],
                "statement_sha256": route_row["statement_sha256"],
                "framework_expanded_name_not_executed": True,
            },
            "controller_action": {
                "relation_class": "BOTH_LANES_IDENTICAL_EXACT_CONTROLLER_ACTION_REVIEW_CANDIDATE",
                "controller_fqcn": resolution["resolved_fqcn"],
                "primary_method_slice": primary,
                "transitive_local_helper_slices": helpers,
                "request_contracts": requests,
                "literal_inertia_page_callsites": [],
                "literal_inertia_page_callsite_count": 0,
                "shared_source_review_packet_sha256": packet["source_review_packet_sha256"],
                "external_dependency_semantics_complete": False,
                "route_ownership_credit": False,
                "controller_action_bridge_credit": False,
                "page_ownership_credit": False,
            },
            "frontend_caller_context": frontend_callers[route_id],
            "feature_identity_projection": feature_projection(matrix_by_id[FEATURE_ID]),
            "collision_checks": {
                "previous_review_source_collision": False,
                "current_owner_source_collision": False,
                "existing_controller_action_bridge_collision": False,
                "prior_owner_sibling_context_present": True,
                "prior_owner_sibling_inheritance_authorized": False,
            },
            "fresh_review_state": {
                "status": "PENDING",
                "allowed_outcomes": [
                    "OWNER_ROUTE_ACTION",
                    "SHARED_RELATION",
                    "ALIAS_OR_REDIRECT",
                    "DEAD_OR_NONCANONICAL",
                    "EVIDENCE_GAP",
                ],
                "route_ownership_credit": False,
                "controller_action_bridge_credit": False,
                "page_ownership_credit": False,
                "correctness_credit": False,
                "downstream_credit": False,
            },
            "evidence_digests": {
                "queue_record_sha256": queue_row["queue_record_sha256"],
                "route_manifest_record_sha256": canonical_json_sha256(route_row),
                "route_candidate_record_sha256": canonical_json_sha256(candidate),
                "route_decision_sha256": canonical_json_sha256(decision),
                "primary_method_slice_sha256": primary["review_slice"]["text_sha256"],
                "local_support_sha256": canonical_json_sha256(helpers),
                "request_support_sha256": canonical_json_sha256(requests),
                "frontend_caller_context_sha256": canonical_json_sha256(frontend_callers[route_id]),
                "source_review_packet_sha256": packet["source_review_packet_sha256"],
            },
        }
        record["candidate_record_sha256"] = canonical_json_sha256(record)
        records.append(record)

    assert len(records) == 2
    assert len({row["queue_id"] for row in records}) == 2
    assert len({row["route_source"]["route_record_id"] for row in records}) == 2
    assert len({row["action_key"] for row in records}) == 2
    assert Counter(row["review_partition"] for row in records) == {"A": 1, "B": 1}
    assert Counter(row["run090_original_partition"] for row in records) == {"A": 2}
    assert {row["candidate_feature_id"] for row in records} == {FEATURE_ID}
    assert all(row["converged_identity"]["candidate_only"] for row in records)
    assert all(row["converged_identity"]["name_candidate_count"] == 1 for row in records)
    assert all(row["converged_identity"]["backend_candidate_count"] == 1 for row in records)
    assert all(len(row["converged_identity"]["matching_backend_anchors"]) == 1 for row in records)
    assert all(row["controller_action"]["literal_inertia_page_callsites"] == [] for row in records)
    assert all(row["controller_action"]["literal_inertia_page_callsite_count"] == 0 for row in records)

    partitions: dict[str, dict[str, Any]] = {}
    for partition in ("A", "B"):
        assigned = [row for row in records if row["review_partition"] == partition]
        assert len(assigned) == 1
        partitions[partition] = {
            "assigned_candidates": 1,
            "candidate_ids": [row["candidate_id"] for row in assigned],
            "queue_id_list_sha256": canonical_list_sha256([row["queue_id"] for row in assigned]),
            "action_key_list_sha256": canonical_list_sha256([row["action_key"] for row in assigned]),
            "controller_files": [
                row["controller_action"]["primary_method_slice"]["source_file"] for row in assigned
            ],
            "shared_source_review_packet_sha256": packet["source_review_packet_sha256"],
            "fresh_reviewer_required": True,
        }

    feature_ids = {row["candidate_feature_id"] for row in records}
    assert feature_ids - current_owner_features == set()
    identity = {
        "queue_index_list_sha256": canonical_list_sha256(
            [str(row["queue_index_zero_based"]) for row in records]
        ),
        "queue_id_list_sha256": canonical_list_sha256([row["queue_id"] for row in records]),
        "canonical_key_list_sha256": canonical_list_sha256(
            [row["queue_canonical_key"] for row in records]
        ),
        "queue_id_canonical_key_pair_list_sha256": canonical_list_sha256(
            [f"{row['queue_id']}|{row['queue_canonical_key']}" for row in records]
        ),
        "route_record_id_list_sha256": canonical_list_sha256(
            [row["route_source"]["route_record_id"] for row in records]
        ),
        "source_key_list_sha256": canonical_list_sha256(
            [row["route_source"]["source_key"] for row in records]
        ),
        "action_key_list_sha256": canonical_list_sha256([row["action_key"] for row in records]),
        "feature_id_list_sha256": canonical_list_sha256(feature_ids),
        "new_feature_id_list_sha256": canonical_list_sha256(feature_ids - current_owner_features),
        "prior_owner_source_record_id_list_sha256": canonical_list_sha256(
            [row["source_record_id"] for row in prior_owner_context]
        ),
        "candidate_record_sha256_list_sha256": canonical_list_sha256(
            [row["candidate_record_sha256"] for row in records]
        ),
        "records_sha256": canonical_json_sha256(records),
        "source_review_packet_sha256": packet["source_review_packet_sha256"],
        "prior_owner_context_sha256": canonical_json_sha256(prior_owner_context),
        "prior_bridge_context_sha256": canonical_json_sha256(prior_bridge_context),
        "prior_bridge_key_list_sha256": canonical_list_sha256(
            ["|".join(row["bridge_key"]) for row in prior_bridge_context]
        ),
    }
    assert identity["queue_id_canonical_key_pair_list_sha256"] == (
        "8e77059f1efe6cb0c65ac96982a73ca7f6436b749315880958a6dd2ca0c1209c"
    )
    assert identity["new_feature_id_list_sha256"] == (
        "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
    )

    return {
        "schema_version": "run-129-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20-v1",
        "run_id": "RUN-129-OUTCOME-NEUTRAL-FINANCE-FX-REVALUATION-ROUTE-ACTION-COHORT-WAVE-20",
        "status": "TWO_FINANCE_FX_REVALUATION_ROUTE_ACTION_CANDIDATES_PENDING_FRESH_REVIEW_ZERO_CREDIT",
        "generated_on": "2026-08-26",
        "pins": {
            "checkpoint_commit": CHECKPOINT_COMMIT,
            "checkpoint_tree": CHECKPOINT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "app_tree": APP_TREE,
            "routes_tree": ROUTES_TREE,
            "resources_js_tree": RESOURCES_JS_TREE,
            "resources_js_pages_tree": RESOURCES_JS_PAGES_TREE,
            "tests_tree": TESTS_TREE,
            "prompt_path": str(PROMPT_PATH),
            "prompt_sha256": PROMPT_SHA256,
            "generator": Path(__file__).relative_to(AUDIT_DIR).as_posix(),
            "generator_sha256": sha256_file(Path(__file__)),
            "inputs": {
                INPUT_PATHS[name].relative_to(AUDIT_DIR).as_posix(): digest
                for name, digest in EXPECTED_INPUT_SHA256.items()
            },
        },
        "architecture_rule": (
            "Oblivion Findings is one operating organisation across multiple Sites. Existing organization_id "
            "fields are legacy schema or organisational context, not a tenant boundary. Roles, permissions, "
            "approved Sites, canonical record ownership, direct-object concealment, privacy, ledger integrity, "
            "and concurrency remain separate questions and receive zero credit here."
        ),
        "selection_contract": {
            "outcome_neutral": True,
            "candidate_owner_projection_authorized": False,
            "rule": (
                "From the RUN-127/RUN-128 current boundary, freeze all and only RUN090-ROUTE-0069 and "
                "RUN090-ROUTE-0070 in queue order. Require CAP-FIN-FX-REVALUATION singleton exact literal-name "
                "identity, exact unique controller-method resolution, the same single backend candidate, zero "
                "contradiction, no prior review, no current owner, and no controller-action bridge collision."
            ),
            "smallest_coherent_tail_rule": (
                "The feature has four direct queue routes. Index and create were already independently reviewed "
                "and owned; store and post are the only pending rows. The two pending actions share the exact "
                "route group, controller, feature projection, service chain, and page caller context."
            ),
            "both_lanes_rule": (
                "BOTH_LANES_IDENTICAL justifies fresh semantic review. Exact name and backend containment do not "
                "select OWNER_ROUTE_ACTION or prove authorization, Site, privacy, lifecycle, or ledger correctness."
            ),
            "page_rule": (
                "The already-owned Create and Index pages are caller context only. This cohort contains zero page "
                "records and cannot inherit, duplicate, or extend their page ownership credit."
            ),
            "prior_owner_rule": (
                "Existing ownership for RUN077-ROUTE-0588, RUN077-ROUTE-0589, PAGE-ROOT-B05B4F27C8925C26, "
                "and PAGE-ROOT-636314D5B8CE1E1A, plus RUN098-BRIDGE-06 and RUN092-BRIDGE-02, is preserved "
                "but contributes no route-action decision or bridge inheritance here."
            ),
            "prohibited_inheritance": [
                "route group or adjacency",
                "shared controller or service containment",
                "identical static candidate lanes",
                "existing sibling route ownership",
                "existing sibling controller-action bridge ownership",
                "existing caller page ownership",
                "middleware or permission declaration",
                "legacy organization_id context",
                "unexecuted test presence",
                "navigation, runtime, browser, benchmark, or completion",
            ],
        },
        "current_baseline": expected_baseline,
        "source_review_packet": packet,
        "prior_owner_context_non_inheritable": prior_owner_context,
        "prior_bridge_context_non_inheritable": prior_bridge_context,
        "semantic_review_focus": {
            "store_action": [
                "read validation, actor context, gain/loss calculation, draft creation, notes mutation, and redirect",
                "trace bills, bank accounts, currencies, date/rate fallbacks, and canonical Site or record scope",
                "do not convert successful draft creation semantics into ledger-correctness credit",
            ],
            "post_action": [
                "read route-model binding, direct-object concealment, draft-state transition, journal creation, and errors",
                "trace fiscal period, account, journal line, sequence, transaction, and lock ordering semantics",
                "separately adjudicate action ownership and correctness; an unsafe owner can remain an owner",
            ],
            "shared_boundary": (
                "Determine whether both actions directly realise the canonical user job 'Preview, create, and post "
                "an FX revaluation to the general ledger.' Do not infer that conclusion from the sibling Index/Create "
                "ownership or from both static lanes agreeing."
            ),
        },
        "risk_register": [
            {
                "risk_id": "RUN129-RISK-DIRECT-OBJECT",
                "observed_loci": [
                    "app/Domain/Finance/Http/Controllers/FxRevaluationController.php:72-75"
                ],
                "observation": (
                    "The post action accepts an implicitly bound FinFxRevaluation and immediately passes it to the "
                    "service; the controller slice contains no explicit canonical ownership or concealment check."
                ),
                "credit_authorized": False,
            },
            {
                "risk_id": "RUN129-RISK-SINGLE-ORG-MULTI-SITE",
                "observed_loci": [
                    "app/Domain/Finance/Http/Controllers/FxRevaluationController.php:61-62",
                    "app/Domain/Finance/Models/FinFxRevaluation.php:17-25",
                    "app/Domain/Finance/Models/FinFxRevaluation.php:48-50",
                ],
                "observation": (
                    "The source passes legacy organization_id context, the record exposes no site_id in its fillable "
                    "fields, and the scope applies its filter only for a truthy value. Review whether the canonical "
                    "job is organisation-wide or requires approved-Site scope; do not model this as tenancy."
                ),
                "credit_authorized": False,
            },
            {
                "risk_id": "RUN129-RISK-CURRENCY-LOOKUP-SCOPE",
                "observed_loci": [
                    "app/Domain/Finance/Services/FxRevaluationService.php:64",
                    "app/Domain/Finance/Services/FxRevaluationService.php:90",
                    "app/Domain/Finance/Services/CurrencyService.php:45-46",
                ],
                "observation": "Direct FinCurrency::find lookups require separate canonical record-scope review.",
                "credit_authorized": False,
            },
            {
                "risk_id": "RUN129-RISK-DUPLICATE-POST-CONCURRENCY",
                "observed_loci": [
                    "app/Domain/Finance/Services/FxRevaluationService.php:149-157",
                    "app/Domain/Finance/Services/FxRevaluationService.php:215-227",
                ],
                "observation": (
                    "The draft-state check precedes the transaction and the frozen method slice does not lock the "
                    "revaluation row. The journal-number sequence mutex does not itself prove exactly-once "
                    "revaluation posting."
                ),
                "credit_authorized": False,
            },
            {
                "risk_id": "RUN129-RISK-RATE-PROVENANCE",
                "observed_loci": [
                    "app/Domain/Finance/Services/CurrencyService.php:15-71",
                    "app/Domain/Finance/Services/CurrencyService.php:94-104",
                    "app/Domain/Finance/Services/FxRevaluationService.php:30-131",
                ],
                "observation": (
                    "Rate selection permits date fallback, while latest-rate fetching is a manual placeholder. "
                    "Fresh review must establish provenance, date semantics, and failure handling."
                ),
                "credit_authorized": False,
            },
            {
                "risk_id": "RUN129-RISK-SNAPSHOT-IDEMPOTENCY-SCHEMA",
                "observed_loci": [
                    "database/migrations/2026_03_28_003300_create_fin_fx_revaluations_table.php:1-26",
                    "database/migrations/2026_03_28_003400_add_currency_fields_to_fin_tables.php:1-51",
                    "app/Domain/Finance/Services/FxRevaluationService.php:132-232",
                ],
                "observation": (
                    "The frozen schema and service require separate review for immutable calculation snapshots, "
                    "stable idempotency identity, unique posting constraints, and replay behavior."
                ),
                "credit_authorized": False,
            },
            {
                "risk_id": "RUN129-RISK-POSTED-EVENT-SIDE-EFFECTS",
                "observed_loci": [
                    "app/Domain/Finance/Events/JournalPosted.php:1-20",
                    "app/Listeners/Finance/LogJournalPosted.php:1-15",
                    "app/Providers/AppServiceProvider.php:524-532",
                ],
                "observation": (
                    "A posted event and listener are present, but their registration and execution are untested "
                    "context and grant no durability, audit-log, or downstream correctness credit."
                ),
                "credit_authorized": False,
            },
            {
                "risk_id": "RUN129-RISK-LOAD-ONLY-TESTS",
                "observed_loci": [
                    "tests/Browser/Finance/FinanceMiscTest.php:206-214",
                    "tests/Browser/Finance/FinanceMiscTest.php:316-323",
                ],
                "observation": "The frozen tests load Index/Create only and do not exercise store or post.",
                "credit_authorized": False,
            },
        ],
        "stop_rules": [
            "Abort materialization on any checkpoint, tree, input, source-file, queue identity, or source-record drift.",
            "Classify unresolved material dependency, authorization, Site, direct-object, privacy, ledger, or concurrency semantics as EVIDENCE_GAP.",
            "Do not inherit an outcome from Index/Create routes, Index/Create pages, the finance route group, or the matrix user job.",
            "Do not integrate any OWNER_ROUTE_ACTION until both partition decisions and a cohort-level synthesis reconcile the shared service chain.",
            "Preserve every non-owner outcome and all zero-credit boundaries exactly; never coerce a two-row closure to two owners.",
        ],
        "counts": {
            "candidate_route_actions": 2,
            "candidate_route_records": 2,
            "candidate_controller_action_bridges": 2,
            "candidate_page_records": 0,
            "distinct_feature_ids": 1,
            "distinct_feature_ids_not_in_current_owner_set": 0,
            "both_lanes_identical_candidates": 2,
            "name_only_candidates": 0,
            "controller_files": 1,
            "frontend_caller_contexts": 2,
            "frontend_caller_contexts_currently_owned": 2,
            "prior_owned_route_context_rows": 2,
            "prior_owned_page_context_rows": 2,
            "prior_owned_controller_action_bridge_context_rows": 2,
            "required_source_files": packet["required_source_file_count"],
            "material_dependency_method_slices": packet["material_dependency_method_slice_count"],
            "new_feature_ids": 0,
            "queue_pending_before": 401,
            "selected_pending_queue_surfaces": 2,
            "queue_unselected_pending": 399,
            "ownership_credit_awarded": 0,
            "controller_action_bridge_credit_awarded": 0,
            "page_ownership_credit_awarded": 0,
            "site_authorization_credit_awarded": 0,
            "permission_credit_awarded": 0,
            "direct_object_credit_awarded": 0,
            "privacy_credit_awarded": 0,
            "ledger_integrity_credit_awarded": 0,
            "concurrency_credit_awarded": 0,
            "runtime_credit": 0,
            "application_browser_credit": 0,
            "executed_test_credit": 0,
            "benchmark_credit": 0,
            "pass_credit": 0,
            "completion_credit": 0,
            "new_queue_review_credit": 0,
            "matrix_mutation_credit": 0,
            "application_source_mutation_credit": 0,
            "release_credit": 0,
        },
        "identity": identity,
        "review_partitions": partitions,
        "records": records,
        "fresh_review_contract": {
            "status": "PENDING",
            "required_partition_reviews": 2,
            "required_cohort_synthesis": 1,
            "required_reviews": 3,
            "three_fresh_reviewers_required": True,
            "reviewers_must_be_fresh_from_discovery_producer": True,
            "review_topology_rationale": (
                "Two candidates cannot form three non-empty disjoint partitions. Assign store to A and post to B, "
                "then require a third fresh reviewer to synthesize both decisions and the shared service chain."
            ),
            "cohort_synthesizer_must_be_fresh_from_both_partition_reviewers": True,
            "required_outcome_per_candidate": True,
            "allowed_outcomes": [
                "OWNER_ROUTE_ACTION",
                "SHARED_RELATION",
                "ALIAS_OR_REDIRECT",
                "DEAD_OR_NONCANONICAL",
                "EVIDENCE_GAP",
            ],
            "integration_rule": (
                "Only an explicit OWNER_ROUTE_ACTION may later add one route owner and one controller-action "
                "bridge. Every other outcome adds neither. Page owners, correctness, runtime, browser, test, "
                "benchmark, pass, and completion remain zero regardless of outcome."
            ),
            "page_owner_records_authorized": 0,
            "ownership_integration_authorized": False,
            "downstream_credit_authorized": False,
        },
        "outcome_neutral_conservation_contract": {
            "outcome_variables": "O owner, S shared, A alias, D dead, E evidence gap",
            "equation": "O + S + A + D + E = 2",
            "bounded_sources": "3929 = (652 + O) + (3277 - O)",
            "owner_surfaces": "652 + O = (295 + O) routes + 357 pages",
            "queue": "507 = (106 + 2 reviewed) + 399 pending",
            "queue_reviewed": "108 = (84 + O) owner + (10 + S) shared + (5 + A) alias + D dead + (7 + E) gap",
            "queue_without_ownership": "423 - O = 399 pending + (10 + S) shared + (5 + A) alias + D dead + (7 + E) gap",
            "route_universe": (
                "3218 = (295 + O) owner + (12 + S) shared + (5 + A) alias + D dead + "
                "(2906 - O - S - A - D) residual; 7 + E gaps are tagged within residual"
            ),
            "pages": "711 = 357 owner + 9 shared + 345 residual; one earlier gap remains tagged within residual",
            "controller_action_bridges": "83 + O",
            "distinct_feature_ids": "256 regardless of O because CAP-FIN-FX-REVALUATION is already represented",
            "feature_sets": "256 = 234 H + 22 D; route 62, page 242, overlap 48; unchanged regardless of outcome",
            "matrix_mutation": "0 rows and 0 cells changed",
            "bounded_ownership_percent": "100 * (652 + O) / 3929; no projection is current credit",
            "all_owner_projection_only": {
                "source_owner_records": 654,
                "route_owner_records": 297,
                "page_owner_records": 357,
                "static_controller_action_bridges": 85,
                "bounded_static_source_residual_records": 3275,
                "residual_explicit_unmapped_routes": 2904,
                "bounded_static_source_ownership_percent": "16.645457",
                "reviewed_queue_surface_rows": 108,
                "owner_queue_surface_rows": 86,
                "pending_unreviewed_queue_surface_rows": 399,
                "queue_surfaces_without_ownership": 421,
                "projection_credit_awarded": False,
            },
            "projection_credit_awarded": False,
        },
        "denominator_boundary": {
            "run_077_bounded_static_records": 3929,
            "framework_expanded_route_page_denominator": None,
            "complete_route_page_feature_crosswalk": False,
            "gate_4_complete": False,
        },
        "credit_boundary": {
            "route_action_candidate_cohort": True,
            "static_route_feature_ownership": False,
            "static_controller_action_bridge": False,
            "page_ownership": False,
            "new_queue_review": False,
            "navigation": False,
            "canonical_object_ownership": False,
            "matrix_mutation": False,
            "application_source_mutation": False,
            "responsive_application": False,
            "visual_application_workflow": False,
            "release": False,
            "prior_owner_context_preserved": True,
            "prior_owner_context_inherited": False,
            "framework_route_reachability": False,
            "site_authorization_correctness": False,
            "permission_correctness": False,
            "direct_object_concealment": False,
            "privacy_correctness": False,
            "ledger_integrity_correctness": False,
            "lifecycle_correctness": False,
            "concurrency_correctness": False,
            "runtime": False,
            "database": False,
            "build": False,
            "application_browser": False,
            "executed_tests": False,
            "benchmark": False,
            "ease": False,
            "pass": False,
            "final_finding": False,
            "completion": False,
            "audit_complete": False,
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/build-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/root-run-129-outcome-neutral-finance-fx-revaluation-route-action-cohort-wave-20.json",
        ],
    }


def main() -> None:
    payload = build()
    encoded = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    if not OUTPUT_PATH.exists() or OUTPUT_PATH.read_bytes() != encoded:
        OUTPUT_PATH.write_bytes(encoded)
    print(
        json.dumps(
            {
                "status": payload["status"],
                "output": OUTPUT_PATH.relative_to(REPO).as_posix(),
                "sha256": sha256_file(OUTPUT_PATH),
                "candidate_route_actions": payload["counts"]["candidate_route_actions"],
                "review_partitions": {
                    key: value["assigned_candidates"]
                    for key, value in payload["review_partitions"].items()
                },
                "ownership_credit_awarded": payload["counts"]["ownership_credit_awarded"],
                "completion_credit": payload["counts"]["completion_credit"],
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
