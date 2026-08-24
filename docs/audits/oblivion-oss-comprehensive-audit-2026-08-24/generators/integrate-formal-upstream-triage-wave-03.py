#!/usr/bin/env python3
from __future__ import annotations

import csv
import hashlib
import json
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
APP_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APP_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
MANIFEST_SHA256 = "f3f70f7b68b38c27e7c0c37f204515df28322c53285e5a2c8dcb463250ca735b"
MATRIX_SHA256 = "df6e1b1b357439ad1fd829bebf4e2d33d20d067d515eb945c352e2350a4194a4"
REGISTER_SHA256 = "cc493cd1807e62a9ffa27192c658400e697391b7a0baa3f0014628145c6b7b91"
DENOMINATOR_SHA256 = "51180ed25968ea7dd28bc6bdc39ccc575e3777c8ce210a8a78dd874316d5f5c9"
TRIAGE_SHA256 = "ea0bb6bde44aa8f227d6e4133788e8fcb08c3069e2aecab4e0bc194cee2f3651"
FACET_SHA256 = "d41cc046de9b7580e937ca1b9d1df7f9237947dcaed5bf0f95772b667d8d9f3e"
PROJECT_LIST_SHA256 = "464beffff0916810b37e5595d82b75ed48247fb09584d830e359758a3b8c1601"
TARGET_LIST_SHA256 = "dcea716f75cecf079e22e3afce580bdeefe48868a687c55e13b2d3fd1af74e9d"

INPUTS = {
    "hr_finance_producer": (
        "evidence/benchmark/raw-run-058a-formal-upstream-hr-finance-wave-03.json",
        "3a2dd623bdf7fb64f177e425de24ac65e1f5cf1c38161124e66974251a79473a",
    ),
    "med_clinical_producer_invalidated": (
        "evidence/benchmark/raw-run-058b-formal-upstream-med-clinical-wave-03.json",
        "9499356df5e63e70636f3b16083002bcf200b5bf5397acedf690b00728f1b00a",
    ),
    "incident_producer": (
        "evidence/benchmark/raw-run-058c-formal-upstream-incident-adversary-wave-03.json",
        "e5c14d45133c9a491767e0db0b87c40ded0fc56674f88a3092dc8899592da2eb",
    ),
    "incident_review": (
        "evidence/benchmark/raw-run-059a-independent-incident-cross-review-wave-03.json",
        "7901f8399c4d431dbc0a9465e23d8d65ecf073e824af34e030cdfae23a654558",
    ),
    "hr_finance_review": (
        "evidence/benchmark/raw-run-059c-independent-hr-finance-cross-review-wave-03.json",
        "4c9187043b060d71fcef13e46e81da9c6dc1ab023b83d1c1aa448abeb0be7fb5",
    ),
    "med_clinical_review": (
        "evidence/benchmark/raw-run-059d-independent-med-clinical-cross-review-wave-03.json",
        "e6153109317a1441a7d7bb36206cf59a0932f1a98a3bcbcad3bc3f51d423a06b",
    ),
    "incident_reseal_draft": (
        "evidence/benchmark/raw-run-061a-corrected-incident-reseal-draft-wave-03.json",
        "73a52b2bfb951ab3c10aeaa7dd976764b7793affff7c2442f0cee699ed79f6d5",
    ),
    "incident_reseal_review": (
        "evidence/benchmark/raw-run-062-independent-incident-reseal-review-wave-03.json",
        "17cfb9e2653f52db6b3b29efc39a0ea38da5c92e7fbc27ef4908358be3410634",
    ),
    "med_clinical_reissue": (
        "evidence/benchmark/raw-run-061b-fresh-upstream-only-med-clinical-reissue-wave-03.json",
        "14952a9e4e328efed5adfe9a25829193538e40576c974a31c9d05a585323e2d5",
    ),
    "med_clinical_reissue_review": (
        "evidence/benchmark/raw-run-063-independent-med-clinical-reissue-review-wave-03.json",
        "bc565a5ba96dd8a484734a51cda9c548d5f2f478594752f13b5a0e60d0a5817d",
    ),
    "incident_fresh_reseal_draft": (
        "evidence/benchmark/raw-run-063-fresh-incident-reseal-draft-wave-03.json",
        "95014a45c22c239b20efcdd4988bf27f70925d606b68d72cd3e68f639048eeac",
    ),
    "incident_fresh_reseal_review": (
        "evidence/benchmark/raw-run-064a-independent-fresh-incident-reseal-review-wave-03.json",
        "d86289cae95b8cc06a654932cbd62ba3a39e22e89174aa0f9d7351f5c8f66810",
    ),
    "hr_finance_reseal_draft": (
        "evidence/benchmark/raw-run-061b-corrected-hr-finance-reseal-draft-wave-03.json",
        "2ddc6bdc84efd13c11ec5ecd2087e669b96f05e80036d39e9d961bbdc72a2607",
    ),
    "hr_finance_reseal_review": (
        "evidence/benchmark/raw-run-064b-independent-hr-finance-reseal-review-wave-03.json",
        "c2402847e3b766077ccc9b1e7279990354dd120e682dc258ed6bcc77829763b7",
    ),
    "incident_corrected_reseal": (
        "evidence/benchmark/raw-run-065-corrected-incident-reseal-wave-03.json",
        "0dc787b4646be83edee158ac7d15103aecbfb0dc4d9b8c67328a02cd05df5fec",
    ),
    "incident_corrected_reseal_review": (
        "evidence/benchmark/raw-run-066-independent-corrected-incident-reseal-review-wave-03.json",
        "dc439693111e3da8f6f61daa18b595853b4f94846d5aa8fcbcc16ebf9b480864",
    ),
    "formal_integration_adversarial_check": (
        "evidence/benchmark/raw-run-066a-formal-integration-adversarial-check-wave-03.json",
        "6d63d4155ab5d5f47a7a48186104cbccc03bcc2593759690e09c9d06a7649faa",
    ),
    "hr_finance_provenance_recovery": (
        "evidence/benchmark/raw-run-066b-hr-finance-provenance-recovery-wave-03.json",
        "2cc60e46c54bddfb1345bcce3ebf646655f0381e2ea9a9ca0fd262b9ea2ea1f4",
    ),
    "med_clinical_correction_feasibility": (
        "evidence/benchmark/raw-run-066c-med-clinical-correction-feasibility-wave-03.json",
        "ac7a3f3fef25e7f281bf21092e116c90164f70d705e87f1db8d138f31f99d8f3",
    ),
    "hr_finance_review_checklist": (
        "evidence/benchmark/raw-run-066e-hr-finance-corrected-reseal-review-checklist-wave-03.json",
        "bed40804fd86a3468f9e4a0a17cea9c8291242b92e4b4b69df5991aee8d8e7da",
    ),
    "med_clinical_provenance_recovery": (
        "evidence/benchmark/raw-run-066d-med-clinical-provenance-recovery-wave-03.json",
        "889b86a6795f2c7d29c42fc2dbc388ea22fcd99b5216c251f350644be3a1b83d",
    ),
    "hr_finance_claim_contract_check": (
        "evidence/benchmark/raw-run-066f-hr-finance-claim-contract-check-wave-03.json",
        "4a14433ec43f03db81a7afa4d8a701ced7b98a643d2179d25fa80907385efd6f",
    ),
    "med_clinical_review_checklist": (
        "evidence/benchmark/raw-run-066g-med-clinical-normalizer-review-checklist-wave-03.json",
        "aab0aba3512c8bee0b099e59f667a0b99138636da0f2e04c0be8ee3a1f08f39c",
    ),
    "hr_finance_claim_implementation_check": (
        "evidence/benchmark/raw-run-066h-hr-finance-claim-implementation-check-wave-03.json",
        "d61e70c48651cdcd5cdfc24d0663514fcfe2d27e318995ce90885789f9b501e8",
    ),
    "hr_finance_corrected_reseal": (
        "evidence/benchmark/raw-run-067b-corrected-hr-finance-reseal-wave-03.json",
        "e1c269c2fd971c37db6b1b6b7273193ee29bcbdf25879c33deb242a3c8093118",
    ),
    "med_clinical_corrected_normalizer": (
        "evidence/benchmark/raw-run-067c-corrected-med-clinical-normalizer-wave-03.json",
        "f426c34d459474f84d9c9901ce2b931256ac8560dca6885821572b142126d29b",
    ),
    "hr_finance_corrected_reseal_review": (
        "evidence/benchmark/raw-run-068-independent-hr-finance-exact-file-review-wave-03.json",
        "984eed1586257233d70cf6d1a3cb586e6d24ec160bdbc68b3045a55cb6f8f26a",
    ),
    "med_clinical_corrected_normalizer_review": (
        "evidence/benchmark/raw-run-069-independent-med-clinical-exact-file-review-wave-03.json",
        "6cc20e6aca377e645979fe1c5fa08f58a7192590f3061e3606469fb556c6c46e",
    ),
}


def sha256_file(relative_path: str) -> str:
    return hashlib.sha256((AUDIT_DIR / relative_path).read_bytes()).hexdigest()


def read_json(relative_path: str) -> dict:
    return json.loads((AUDIT_DIR / relative_path).read_text(encoding="utf-8"))


def write_json(relative_path: str, payload: dict) -> None:
    text = json.dumps(payload, indent=2, sort_keys=True, ensure_ascii=False) + "\n"
    (AUDIT_DIR / relative_path).write_text(text, encoding="utf-8", newline="\n")


def stable_list_hash(values: list[str]) -> str:
    return hashlib.sha256(("\n".join(sorted(values)) + "\n").encode("utf-8")).hexdigest()


def normal_repo(value: str) -> str:
    return value.strip().casefold()


for _, (path, expected_hash) in INPUTS.items():
    assert sha256_file(path) == expected_hash
assert sha256_file("03-feature-to-benchmark-matrix.csv") == MATRIX_SHA256
assert sha256_file("06-open-source-benchmark-register.csv") == REGISTER_SHA256
assert sha256_file("evidence/benchmark/current-prompt-project-denominator-reconciliation.json") == DENOMINATOR_SHA256
assert sha256_file("evidence/benchmark/current-upstream-project-triage-wave-01.json") == TRIAGE_SHA256
assert sha256_file("evidence/benchmark/current-upstream-facet-refinement-wave-02.json") == FACET_SHA256
assert sha256_file("evidence/source/audit-run-manifest.json") == MANIFEST_SHA256

loaded = {name: read_json(path) for name, (path, _) in INPUTS.items()}
hr = loaded["hr_finance_producer"]
med = loaded["med_clinical_producer_invalidated"]
incident = loaded["incident_producer"]
hr_review = loaded["hr_finance_review"]
med_review = loaded["med_clinical_review"]
incident_review = loaded["incident_review"]
incident_reseal = loaded["incident_reseal_draft"]
incident_reseal_review = loaded["incident_reseal_review"]
med_reissue = loaded["med_clinical_reissue"]
med_reissue_review = loaded["med_clinical_reissue_review"]
incident_fresh_reseal = loaded["incident_fresh_reseal_draft"]
incident_fresh_reseal_review = loaded["incident_fresh_reseal_review"]
hr_reseal = loaded["hr_finance_reseal_draft"]
hr_reseal_review = loaded["hr_finance_reseal_review"]
incident_corrected_reseal = loaded["incident_corrected_reseal"]
incident_corrected_reseal_review = loaded["incident_corrected_reseal_review"]
formal_integration_review = loaded["formal_integration_adversarial_check"]
hr_provenance_recovery = loaded["hr_finance_provenance_recovery"]
med_correction_feasibility = loaded["med_clinical_correction_feasibility"]
hr_review_checklist = loaded["hr_finance_review_checklist"]
med_provenance_recovery = loaded["med_clinical_provenance_recovery"]
hr_claim_contract_check = loaded["hr_finance_claim_contract_check"]
med_review_checklist = loaded["med_clinical_review_checklist"]
hr_claim_implementation_check = loaded["hr_finance_claim_implementation_check"]
hr_corrected_reseal = loaded["hr_finance_corrected_reseal"]
med_corrected_normalizer = loaded["med_clinical_corrected_normalizer"]
hr_corrected_reseal_review = loaded["hr_finance_corrected_reseal_review"]
med_corrected_normalizer_review = loaded["med_clinical_corrected_normalizer_review"]
manifest = read_json("evidence/source/audit-run-manifest.json")

assert hr["pins"]["application_commit_not_inspected"] == APP_COMMIT
assert hr["pins"]["application_tree_not_inspected"] == APP_TREE
assert med["scope"]["pin"] == APP_COMMIT
assert med["scope"]["tree"] == APP_TREE
assert incident["scope"]["application_pin"] == APP_COMMIT
assert incident["scope"]["application_tree"] == APP_TREE
assert med_reissue["pin"][:2] == [APP_COMMIT, APP_TREE]
assert "multiple Sites" in med_reissue["pin"][2]

assert hr_review["reviewed_packet"]["sha256"] == INPUTS["hr_finance_producer"][1]
assert med_review["reviewed_packet"]["sha256"] == INPUTS["med_clinical_producer_invalidated"][1]
assert incident_review["reviewed_packet"]["sha256"] == INPUTS["incident_producer"][1]
assert incident_reseal_review["hashes"]["RUN-061A-N"] == INPUTS["incident_reseal_draft"][1]
assert med_reissue["059D_sha256"] == INPUTS["med_clinical_review"][1]
assert med_reissue_review["reviewed_packet"]["sha256"] == INPUTS["med_clinical_reissue"][1]
assert med_reissue_review["verdict"] == "NO_GO_FORMAL_ACCEPTANCE_0_OF_8"
assert incident_fresh_reseal_review["reviewed_packet"]["sha256"] == INPUTS["incident_fresh_reseal_draft"][1]
assert incident_fresh_reseal_review["ftc_counts"]["formal_project_records_accepted"] == 0
assert incident_fresh_reseal_review["ftc_counts"]["project_check_instances"] == 72
assert incident_fresh_reseal_review["ftc_counts"]["passed"] == 69
assert incident_fresh_reseal_review["ftc_counts"]["failed"] == 3
assert hr_reseal_review["reviewed_packet"]["sha256"] == INPUTS["hr_finance_reseal_draft"][1]
assert hr_reseal_review["counts"]["formal_project_records_accepted"] == 0
assert hr_reseal_review["ftc"]["instances"] == {"total": 144, "accepted": 126, "rejected": 18}
assert hr_reseal_review["counts"]["source_facet_links_matching"] == 11
assert hr_reseal_review["counts"]["source_facet_links_mismatching"] == 1
assert incident_corrected_reseal["files_read"][0][1] == INPUTS["incident_fresh_reseal_draft"][1]
assert incident_corrected_reseal["files_read"][1][1] == INPUTS["incident_fresh_reseal_review"][1]
assert incident_corrected_reseal_review["reviewed_packet"]["sha256"] == INPUTS["incident_corrected_reseal"][1]
assert incident_corrected_reseal_review["verdict"] == "GO_3_OF_3_FORMAL_UPSTREAM_PROJECT_RECORDS_ACCEPTED_ZERO_DOWNSTREAM_CREDIT"
assert incident_corrected_reseal_review["counts"]["formal_project_records_accepted"] == 3
assert incident_corrected_reseal_review["ftc"]["passed"] == 72
assert incident_corrected_reseal_review["ftc"]["failed"] == 0
assert incident_corrected_reseal_review["reviewed_packet"]["hash_match"] is True
assert incident_corrected_reseal_review["reviewed_packet"]["different_independent_reviewer"] is True
assert incident_corrected_reseal_review["reviewed_packet"]["producer"] == incident_corrected_reseal["responsible_agent_identity"]
assert incident_corrected_reseal_review["responsible_agent_identity"] != incident_corrected_reseal["responsible_agent_identity"]
assert incident_corrected_reseal_review["ftc"]["profile"] == "A" * 24
assert len(incident_corrected_reseal_review["ftc"]["matrix"]) == 3
assert len({row[0] for row in incident_corrected_reseal_review["ftc"]["matrix"]}) == 3
assert all(row[1] == "A" * 24 for row in incident_corrected_reseal_review["ftc"]["matrix"])
assert incident_corrected_reseal_review["ftc"]["projects_all_24_pass"] == 3
assert incident_corrected_reseal_review["credit_boundary"]["formal_upstream_project_record_acceptance"] is True
assert incident_corrected_reseal_review["credit_boundary"]["all_downstream"] is False
assert formal_integration_review["verdict"] == "NO_GO_PRE_INTEGRATION_UNTIL_BOUNDED_CORRECTIONS"
assert hr_provenance_recovery["provenance"]["verdict"] == "DURABLY_ESTABLISHED_WITHOUT_INFERENCE_FOR_EXACT_RETURNED_PAYLOAD"
assert hr_provenance_recovery["provenance"]["FTC01_recovery"]["status"] == "RECOVERABLE_BY_RESEAL"
assert med_correction_feasibility["feasibility_verdict"].startswith("FTC23_MECHANICALLY_FEASIBLE")
assert hr_review_checklist["status"] == "CHECKLIST_ONLY_NO_PREACCEPTANCE"
assert med_provenance_recovery["durable_provenance_verdict"] == "RECOVERED_EXACT_CHAIN_EXTERNAL_TO_PACKET"
assert med_provenance_recovery["FTC-01_recovery_status"]["source_reissuer_identity"] == "RECOVERED_DURABLY"
assert hr_claim_contract_check["verdict"] == "CONDITIONAL_GO"
assert hr_claim_contract_check["count_check"]["total"] == 108
assert med_review_checklist["kind"] == "NO_PREACCEPTANCE_MED_CLINICAL_NORMALIZER_REVIEW_CHECKLIST"
assert hr_claim_implementation_check["verdict"] == "GO_ON_IMPLEMENTATION_SUMMARY"
assert hr_corrected_reseal["schema"] == "RUN-067B-HR-FINANCE-CORRECTED-RESEAL-1"
assert hr_corrected_reseal["status"] == "CORRECTED_RESEAL_PENDING_DIFFERENT_EXACT_HASH_REVIEWER"
assert hr_corrected_reseal["responsible_agent_identity"] == "/root/run063_med_clinical_reissue_review"
assert hr_corrected_reseal["formal_acceptance"] is False
assert hr_corrected_reseal["generator_ready"] is False
assert hr_corrected_reseal["counts"]["projects"] == 6
assert hr_corrected_reseal["counts"]["facets"] == 12
assert hr_corrected_reseal["counts"]["claims"] == 108
assert hr_corrected_reseal["counts"]["claim_bearing_source_fields"] == 67
assert hr_corrected_reseal["counts"]["mapped_claims"] == 108
assert hr_corrected_reseal["counts"]["unmapped_claims"] == 0
assert hr_corrected_reseal["counts"]["coverage_gaps"] == 0
assert hr_corrected_reseal["counts"]["coverage_duplicates"] == 0
assert hr_corrected_reseal["counts"]["ownership_violations"] == 0
assert hr_corrected_reseal["all_downstream_credits_false"] is True
assert med_corrected_normalizer["schema"] == "RUN-MED-CLINICAL-CORRECTED-FULL-RESEAL-2"
assert med_corrected_normalizer["status"] == "NORMALIZED_RESEAL_PENDING_THIRD_EXACT_HASH_REVIEW"
assert med_corrected_normalizer["formal_acceptance"] is False
assert med_corrected_normalizer["generator_ready"] is False
assert med_corrected_normalizer["identities"]["source_reissuer"]["canonical_agent_path"] == "/root/run061_fresh_med_clinical_reissue"
assert med_corrected_normalizer["identities"]["normalizer"]["canonical_agent_path"] == "/root/run061_formal_integration_contract"
assert med_corrected_normalizer["identities"]["normalizer"]["distinct_from_source_reissuer"] is True
assert med_corrected_normalizer["counts"]["projects"] == 9
assert med_corrected_normalizer["counts"]["prompt_projects"] == 8
assert med_corrected_normalizer["counts"]["historical_projects"] == 1
assert med_corrected_normalizer["counts"]["relations"] == 10
assert med_corrected_normalizer["validation"]["unresolved_references"] == 0
assert all(value is False for value in med_corrected_normalizer["credits"].values())
assert hr_corrected_reseal_review["schema"] == "RUN-068-HR-FINANCE-INDEPENDENT-EXACT-FILE-REVIEW-1"
assert hr_corrected_reseal_review["reviewed_packet"]["path"] == INPUTS["hr_finance_corrected_reseal"][0]
assert hr_corrected_reseal_review["reviewed_packet"]["sha256"] == INPUTS["hr_finance_corrected_reseal"][1]
assert hr_corrected_reseal_review["reviewed_packet"]["hash_match"] is True
assert hr_corrected_reseal_review["reviewed_packet"]["producer"] == hr_corrected_reseal["responsible_agent_identity"]
assert hr_corrected_reseal_review["reviewed_packet"]["reviewer"] != hr_corrected_reseal["responsible_agent_identity"]
assert hr_corrected_reseal_review["reviewed_packet"]["different_independent_reviewer"] is True
assert hr_corrected_reseal_review["ftc"]["project_check_instances"] == 144
assert hr_corrected_reseal_review["ftc"]["passed"] == 144
assert hr_corrected_reseal_review["ftc"]["failed"] == 0
assert len(hr_corrected_reseal_review["ftc"]["projects"]) == 6
assert len({row["id"] for row in hr_corrected_reseal_review["ftc"]["projects"]}) == 6
assert all(row["profile"] == "A" * 24 for row in hr_corrected_reseal_review["ftc"]["projects"])
assert len(hr_corrected_reseal_review["facets"]) == 12
assert len({row["id"] for row in hr_corrected_reseal_review["facets"]}) == 12
assert all(row["decision"] == "ACCEPT_FORMAL_UPSTREAM_FACET_RECORD" for row in hr_corrected_reseal_review["facets"])
assert hr_corrected_reseal_review["counts"]["accepted_project_records"] == 6
assert hr_corrected_reseal_review["counts"]["accepted_facet_records"] == 12
assert hr_corrected_reseal_review["counts"]["formal_target_edges"] == 0
assert hr_corrected_reseal_review["counts"]["final_no_matches"] == 0
assert hr_corrected_reseal_review["counts"]["NCM"] == 0
assert hr_corrected_reseal_review["defects"] == []
assert hr_corrected_reseal_review["credit_boundary"]["formal_upstream_project_record_acceptance"] is True
assert hr_corrected_reseal_review["credit_boundary"]["formal_upstream_facet_record_acceptance"] is True
assert all(
    value is False
    for key, value in hr_corrected_reseal_review["credit_boundary"].items()
    if key not in {"formal_upstream_project_record_acceptance", "formal_upstream_facet_record_acceptance"}
)
assert med_corrected_normalizer_review["schema"] == "RUN-067C-MED-CLINICAL-THIRD-IDENTITY-EXACT-FILE-REVIEW-1"
assert med_corrected_normalizer_review["status"] == "NO_GO_FORMAL_RECORD_LAYER"
assert med_corrected_normalizer_review["reviewed_packet"]["path"].endswith(INPUTS["med_clinical_corrected_normalizer"][0])
assert med_corrected_normalizer_review["reviewed_packet"]["sha256"] == INPUTS["med_clinical_corrected_normalizer"][1]
assert med_corrected_normalizer_review["reviewed_packet"]["framing_match"] is True
assert med_corrected_normalizer_review["responsible_reviewer_identity"] not in {
    med_corrected_normalizer["identities"]["source_reissuer"]["canonical_agent_path"],
    med_corrected_normalizer["identities"]["normalizer"]["canonical_agent_path"],
}
assert med_corrected_normalizer_review["identity_separation"]["three_agent_paths_distinct"] is True
assert med_corrected_normalizer_review["identity_separation"]["durable_normalizer_path_session_turn_complete"] is False
assert med_corrected_normalizer_review["ftc"]["prompt_profile"] == "F" * 22 + "P" + "F"
assert med_corrected_normalizer_review["ftc"]["prompt_instance_counts"] == {
    "total": 192,
    "pass": 8,
    "fail": 184,
    "not_applicable": 0,
}
assert med_corrected_normalizer_review["prompt_repository_adjudication"]["formally_accepted_count"] == 0
assert med_corrected_normalizer_review["relation_adjudication"]["formally_accepted_count"] == 0
assert med_corrected_normalizer_review["counts"]["formal_prompt_projects"] == 0
assert med_corrected_normalizer_review["counts"]["formal_relations"] == 0
assert med_corrected_normalizer_review["counts"]["formal_facets"] == 0
assert med_corrected_normalizer_review["generator_ready"] is False
assert med_corrected_normalizer_review["formal_acceptance"] is False
assert all(value is False for value in med_corrected_normalizer_review["all_downstream_credits_false"].values())
assert manifest["governing_prompt"]["sha256"] == PROMPT_SHA256
assert manifest["governing_prompt"]["bytes"] == 88305
assert manifest["source_pins"]["application_source_commit"] == APP_COMMIT
assert manifest["source_pins"]["application_source_tree"] == APP_TREE

ftc = {f"FTC-{number:02d}" for number in range(1, 25)}
for review in (hr_review, incident_review):
    for row in review["project_reviews"]:
        accepted = set(row["accepted_checks"])
        corrected = set(row["corrected_checks"])
        rejected = set(row["rejected_checks"])
        assert not accepted & corrected
        assert not accepted & rejected
        assert not corrected & rejected
        assert accepted | corrected | rejected == ftc

med_ftc = med_review["FTC_decisions"]
med_accepted = set(med_ftc["accepted"])
med_corrected = {row["id"] for row in med_ftc["correction_required"]}
med_rejected = {row["id"] for row in med_ftc["rejected"]}
assert not med_accepted & med_corrected
assert not med_accepted & med_rejected
assert not med_corrected & med_rejected
assert med_accepted | med_corrected | med_rejected == ftc

assert hr_review["counts"]["formal_project_records_accepted_now"] == 0
assert hr_review["counts"]["formal_facets_accepted_now"] == 0
assert incident_review["counts"]["formal_project_records_accepted_now"] == 0
assert med_review["project_decision_counts"]["accepted_as_is"] == 0
assert incident_reseal_review["output_ready_counts"]["formal_projects"] == 0
assert incident_reseal_review["overall_verdict"] == "NO_GO"
assert med_reissue["formal_acceptance"] is False
assert med_reissue["generator_ready"] is False

initial_rows: list[dict] = []
for row in hr["project_records"]:
    initial_rows.append({
        "display_repository": row["id"],
        "lineage": "HR_FINANCE",
        "raw_disposition": row["status"],
    })
for row in med["project_records"]:
    initial_rows.append({
        "display_repository": row["id"],
        "lineage": "MED_CLINICAL",
        "raw_disposition": row["disposition"],
    })
for row in incident["project_records"]:
    initial_rows.append({
        "display_repository": row["project"],
        "lineage": "INCIDENT",
        "raw_disposition": row["relation"],
    })

assert len(initial_rows) == 18
assert len({normal_repo(row["display_repository"]) for row in initial_rows}) == 18
assert stable_list_hash([normal_repo(row["display_repository"]) for row in initial_rows]) == PROJECT_LIST_SHA256

targets = set(hr["targets"])
targets.update(row["target_id"] for row in med["feature_candidate_relations"])
targets.add(incident["scope"]["target"])
assert len(targets) == 6
assert stable_list_hash(list(targets)) == TARGET_LIST_SHA256

assert len(hr["facets"]) == 12
assert sum(len(row["facets"]) for row in med["feature_candidate_relations"]) == 10
assert len(incident["incident_feature_relations"]) == 7
assert len(hr["facets"]) + sum(len(row["facets"]) for row in med["feature_candidate_relations"]) + len(incident["incident_feature_relations"]) == 29

with (AUDIT_DIR / "06-open-source-benchmark-register.csv").open(encoding="utf-8-sig", newline="") as handle:
    register_rows = list(csv.DictReader(handle))
register_by_repo = {normal_repo(row["project"]): row for row in register_rows}
assert len(register_rows) == 98
assert len(register_by_repo) == 98

hr_decisions = {normal_repo(row["project"]): row["outcome"] for row in hr_review["project_reviews"]}
med_decisions = {normal_repo(row["project"]): row["decision"] for row in med_review["project_decisions"]}
incident_decisions = {normal_repo(row["project"]): row["outcome"] for row in incident_review["project_reviews"]}
initial_review_decisions = {
    "HR_FINANCE": hr_decisions,
    "MED_CLINICAL": med_decisions,
    "INCIDENT": incident_decisions,
}

accepted_incident_decisions = {
    normal_repo(row[1]): row[3]
    for row in incident_corrected_reseal_review["project_decisions"]
    if row[3].startswith("ACCEPT_")
}
accepted_incident_repositories = set(accepted_incident_decisions)
assert accepted_incident_repositories == {
    "braedonsaunders/beaconhs",
    "oneuptime/oneuptime",
    "opf/openproject",
}
accepted_incident_direct = sum(
    row[2].startswith("DIRECT_")
    for row in incident_corrected_reseal_review["project_decisions"]
)
accepted_incident_adjacent = sum(
    row[2].startswith("ADJACENT_")
    for row in incident_corrected_reseal_review["project_decisions"]
)
assert (accepted_incident_direct, accepted_incident_adjacent) == (1, 2)

hr_source_by_repo = {
    normal_repo(row[1]): row
    for row in hr_corrected_reseal["projects"]
}
med_source_by_repo = {
    normal_repo(row["v2_preimage"][6][0]): row
    for row in med_corrected_normalizer["projects"]
}
incident_source_by_repo = {
    normal_repo(row[1]): row
    for row in incident_corrected_reseal["projects"]
}
assert set(hr_source_by_repo) == set(hr_decisions)
assert set(med_source_by_repo) == set(med_decisions)
assert set(incident_source_by_repo) == set(incident_decisions)

accepted_hr_decisions = {
    normal_repo(row["repository"]): row["decision"]
    for row in hr_corrected_reseal_review["ftc"]["projects"]
    if row["decision"].startswith("ACCEPT_") and row["profile"] == "A" * 24
}
accepted_hr_repositories = set(accepted_hr_decisions)
assert accepted_hr_repositories == set(hr_source_by_repo)
assert len(accepted_hr_repositories) == 6
assert med_corrected_normalizer_review["prompt_repository_adjudication"]["formally_accepted"] == []
accepted_med_decisions: dict[str, str] = {}

latest_dispositions = {
    "HR_FINANCE": {repo: row[2] for repo, row in hr_source_by_repo.items()},
    "MED_CLINICAL": {repo: row["v2_preimage"][11][1] for repo, row in med_source_by_repo.items()},
    "INCIDENT": {repo: row[7] for repo, row in incident_source_by_repo.items()},
}
formal_decisions = {
    "HR_FINANCE": accepted_hr_decisions,
    "MED_CLINICAL": accepted_med_decisions,
    "INCIDENT": accepted_incident_decisions,
}
accepted_repositories_by_lineage = {
    "HR_FINANCE": accepted_hr_repositories,
    "MED_CLINICAL": set(),
    "INCIDENT": accepted_incident_repositories,
}
hr_projects_array_jcs_sha256 = next(
    row[2]
    for row in hr_corrected_reseal["integrity"]["array_hashes"]
    if row[0] == "projects"
)

attempts = []
for index, row in enumerate(sorted(initial_rows, key=lambda item: normal_repo(item["display_repository"])), start=1):
    repo_key = normal_repo(row["display_repository"])
    register_row = register_by_repo[repo_key]
    membership = register_row["current_audit_prompt_denominator_membership"]
    weight = int(register_row["current_prompt_occurrence_count"])
    lineage = row["lineage"]
    effective = latest_dispositions[lineage][repo_key]
    formally_accepted = repo_key in accepted_repositories_by_lineage[lineage]
    accepted_source = None
    accepted_review = None
    if formally_accepted:
        if lineage == "INCIDENT":
            source_record = incident_source_by_repo[repo_key]
            source_role = "incident_corrected_reseal"
            review_role = "incident_corrected_reseal_review"
            record_id = source_record[0]
            record_hash_domain = "INCIDENT_COMPLETE_PROJECT_RECORD_SHA256"
            record_hash = source_record[-1]
            reviewer_identity = incident_corrected_reseal_review["responsible_agent_identity"]
        elif lineage == "HR_FINANCE":
            source_record = hr_source_by_repo[repo_key]
            source_role = "hr_finance_corrected_reseal"
            review_role = "hr_finance_corrected_reseal_review"
            record_id = source_record[0]
            record_hash_domain = "HR_PROJECTS_ARRAY_JCS_SHA256_PLUS_PROJECT_ID"
            record_hash = hr_projects_array_jcs_sha256
            reviewer_identity = hr_corrected_reseal_review["reviewed_packet"]["reviewer"]
        else:
            raise AssertionError("Medication/clinical has no accepted records in this checkpoint")
        accepted_source = {
            "role": source_role,
            "path": INPUTS[source_role][0],
            "sha256": INPUTS[source_role][1],
            "record_id": record_id,
            "record_hash_domain": record_hash_domain,
            "record_hash": record_hash,
        }
        accepted_review = {
            "role": review_role,
            "path": INPUTS[review_role][0],
            "sha256": INPUTS[review_role][1],
            "reviewer_identity": reviewer_identity,
            "decision_project_id": record_id,
        }
    attempts.append({
        "stable_attempt_id": f"UPSTREAM-W03-{index:03d}",
        "normalized_repository": repo_key,
        "display_repository": row["display_repository"],
        "lineage": row["lineage"],
        "denominator_membership": membership,
        "prompt_occurrence_weight": weight,
        "raw_disposition": row["raw_disposition"],
        "initial_review_disposition": initial_review_decisions[lineage][repo_key],
        "effective_disposition": effective,
        "formal_acceptance_decision": formal_decisions[lineage].get(repo_key),
        "formal_project_record_accepted": formally_accepted,
        "accepted_record_source": accepted_source,
        "accepted_record_review": accepted_review,
        "downstream_credit": False,
    })

assert sum(row["prompt_occurrence_weight"] for row in attempts) == 18
assert sum(row["denominator_membership"] == "IN_PROMPT_UNIQUE_95" for row in attempts) == 17
assert sum(row["denominator_membership"] == "HISTORICAL_EXTRA_OUTSIDE_PROMPT" for row in attempts) == 1
assert next(row for row in attempts if row["normalized_repository"] == "bahmni/openmrs-module-ipd")["prompt_occurrence_weight"] == 0
assert next(row for row in attempts if row["normalized_repository"] == "opf/openproject")["prompt_occurrence_weight"] == 2
accepted_attempts = [row for row in attempts if row["formal_project_record_accepted"]]
assert len(accepted_attempts) == 9
assert sum(row["prompt_occurrence_weight"] for row in accepted_attempts) == 10
assert all(row["denominator_membership"] == "IN_PROMPT_UNIQUE_95" for row in accepted_attempts)
assert all(row["downstream_credit"] is False for row in attempts)
assert all(
    (row["accepted_record_source"] is not None) == row["formal_project_record_accepted"]
    and (row["accepted_record_review"] is not None) == row["formal_project_record_accepted"]
    for row in attempts
)

input_manifest = [
    {
        "role": role,
        "path": path,
        "sha256": expected_hash,
        "bytes": (AUDIT_DIR / path).stat().st_size,
    }
    for role, (path, expected_hash) in INPUTS.items()
]
input_manifest_by_role = {row["role"]: row for row in input_manifest}
assert len(input_manifest_by_role) == len(INPUTS) == len(input_manifest)
assert set(input_manifest_by_role) == set(INPUTS)
assert all(
    sha256_file(row["path"]) == row["sha256"]
    for row in input_manifest_by_role.values()
)

FINAL_RUN_ID = "RUN-070"
formal_projects_accepted = len(accepted_attempts)
formal_facets_accepted = hr_corrected_reseal_review["counts"]["accepted_facet_records"]
accepted_prompt_repositories = sorted(row["normalized_repository"] for row in accepted_attempts)
accepted_prompt_occurrence_weight = sum(row["prompt_occurrence_weight"] for row in accepted_attempts)
assert formal_projects_accepted == 9
assert formal_facets_accepted == 12
assert len(accepted_prompt_repositories) == len(set(accepted_prompt_repositories)) == 9
assert accepted_prompt_occurrence_weight == 10

credit_boundary = {
    "formal_upstream_project_record_acceptance": True,
    "formal_upstream_facet_record_acceptance": True,
    "project_selection_credit": False,
    "facet_selection_credit": False,
    "neutral_requirements_credit": False,
    "current_product_comparison_credit": False,
    "target_mapping_credit": False,
    "benchmark_credit": False,
    "final_no_match_credit": False,
    "NCM_credit": False,
    "runtime_credit": False,
    "browser_credit": False,
    "workflow_credit": False,
    "responsive_credit": False,
    "rendered_visual_credit": False,
    "test_execution_credit": False,
    "finding_credit": False,
    "certification_credit": False,
    "clinical_safety_credit": False,
    "ease_credit": False,
    "release_credit": False,
    "pass_credit": False,
    "completion_credit": False,
    "audit_complete": False,
}

output = {
    "schema_version": "1.0",
    "run_id": FINAL_RUN_ID,
    "status": f"{formal_projects_accepted}_FORMAL_UPSTREAM_PROJECT_RECORDS_{formal_facets_accepted}_FORMAL_FACETS_ACCEPTED_ZERO_TARGET_EDGE_ZERO_DOWNSTREAM_CREDIT",
    "as_of_date": "2026-08-25",
    "governing_pins": {
        "application_commit": APP_COMMIT,
        "application_tree": APP_TREE,
        "architecture": "single operating organisation across multiple Sites",
        "prompt": {
            "sha256": PROMPT_SHA256,
            "verification": "MANIFEST_WITNESS_ONLY_NOT_REHASHED",
            "provided_filename": manifest["governing_prompt"]["provided_filename"],
            "bytes": manifest["governing_prompt"]["bytes"],
            "manifest_path": "evidence/source/audit-run-manifest.json",
            "manifest_sha256": MANIFEST_SHA256,
        },
        "matrix_sha256": MATRIX_SHA256,
        "register_sha256": REGISTER_SHA256,
        "denominator_sha256": DENOMINATOR_SHA256,
    },
    "input_manifest": input_manifest,
    "denominator_reconciliation": {
        "prompt_url_occurrences": 98,
        "prompt_unique_repositories": 95,
        "wave_initial_project_records": 18,
        "wave_unique_repositories": 18,
        "wave_prompt_repositories": 17,
        "wave_historical_extras": 1,
        "wave_prompt_occurrence_weight": 18,
        "normalized_project_list_sha256": PROJECT_LIST_SHA256,
        "accepted_prompt_repositories": formal_projects_accepted,
        "accepted_prompt_occurrence_weight": accepted_prompt_occurrence_weight,
    },
    "target_inventory": {
        "target_ids": sorted(targets),
        "target_count": 6,
        "target_list_sha256": TARGET_LIST_SHA256,
        "initial_facet_aspect_subrecords": 29,
        "scope": "Initial RUN-058A/B/C facet and aspect records only; later loci, claims and correction records excluded.",
    },
    "formal_project_record_acceptance": {
        "accepted_records": formal_projects_accepted,
        "accepted_prompt_repositories": accepted_prompt_repositories,
        "accepted_prompt_occurrence_weight": accepted_prompt_occurrence_weight,
        "lineages": {
            "INCIDENT": {
                "accepted_records": len(accepted_incident_repositories),
                "direct_records": accepted_incident_direct,
                "adjacent_nonpromotable_records": accepted_incident_adjacent,
                "source_packet": INPUTS["incident_corrected_reseal"][0],
                "independent_review": INPUTS["incident_corrected_reseal_review"][0],
            },
            "HR_FINANCE": {
                "accepted_records": len(accepted_hr_repositories),
                "accepted_selected_classification_records": hr_corrected_reseal_review["counts"]["accepted_selected_classification_records"],
                "accepted_exclusion_classification_records": hr_corrected_reseal_review["counts"]["accepted_exclusion_classification_records"],
                "source_packet": INPUTS["hr_finance_corrected_reseal"][0],
                "independent_review": INPUTS["hr_finance_corrected_reseal_review"][0],
            },
            "MED_CLINICAL": {
                "accepted_records": 0,
                "structurally_valid_prompt_records": med_corrected_normalizer_review["prompt_repository_adjudication"]["structurally_valid_count"],
                "source_packet": INPUTS["med_clinical_corrected_normalizer"][0],
                "independent_review": INPUTS["med_clinical_corrected_normalizer_review"][0],
                "verdict": "NO_GO_FORMAL_RECORD_LAYER",
            },
        },
        "formal_facets": formal_facets_accepted,
        "formal_facet_ids": sorted(row["id"] for row in hr_corrected_reseal_review["facets"]),
        "facet_scope": "Bounded HR/finance upstream facet-triage records only; S, S+A and N are not target mappings or final no-matches.",
        "formal_target_edges": 0,
        "final_no_matches": 0,
        "NCM": 0,
    },
    "normalized_project_attempts": attempts,
    "review_summary": {
        "hr_finance": {
            "projects": 6,
            "facets": 12,
            "project_check_instances": 144,
            "accepted_checks": 112,
            "correction_required_checks": 16,
            "rejected_checks": 16,
            "formal_projects_accepted": 0,
            "formal_facets_accepted": 0,
        },
        "med_clinical_invalidated_packet": {
            "project_records": 9,
            "prompt_records": 8,
            "historical_extras": 1,
            "facets": 10,
            "packet_ftc_accepted": 13,
            "packet_ftc_correction_required": 9,
            "packet_ftc_rejected": 2,
            "formal_projects_accepted": 0,
            "invalidating_control": "FTC-01_DIRECT_NO_CROSS_READ_CONTRADICTION",
        },
        "incident": {
            "projects": 3,
            "aspects": 7,
            "project_check_instances": 72,
            "accepted_checks": 54,
            "correction_required_checks": 10,
            "rejected_checks": 8,
            "formal_projects_accepted": 0,
        },
        "incident_reseal": {
            "projects": 3,
            "substantive_corrections_closed": 3,
            "project_check_instances": 72,
            "ready_check_instances": 66,
            "blocked_check_instances": 6,
            "blocked_controls": ["FTC-01", "FTC-23"],
            "formal_projects_accepted": 0,
            "verdict": "NO_GO",
        },
        "incident_fresh_reseal": {
            "projects": 3,
            "project_check_instances": 72,
            "accepted_check_instances": incident_fresh_reseal_review["ftc_counts"]["passed"],
            "blocked_check_instances": incident_fresh_reseal_review["ftc_counts"]["failed"],
            "blocked_controls": ["FTC-23"],
            "formal_projects_accepted": 0,
            "verdict": "NO_GO_TERMINAL_LF_DECLARATION_MISMATCH",
        },
        "incident_corrected_reseal": {
            "projects": 3,
            "project_check_instances": 72,
            "accepted_check_instances": incident_corrected_reseal_review["ftc"]["passed"],
            "blocked_check_instances": incident_corrected_reseal_review["ftc"]["failed"],
            "formal_projects_accepted": incident_corrected_reseal_review["counts"]["formal_project_records_accepted"],
            "direct_formal_records": accepted_incident_direct,
            "adjacent_formal_records": accepted_incident_adjacent,
            "formal_facets_accepted": 0,
            "verdict": "GO_BOUNDED_UPSTREAM_PROJECT_RECORDS_ONLY",
        },
        "hr_finance_reseal": {
            "projects": 6,
            "facets": 12,
            "project_check_instances": 144,
            "accepted_check_instances": hr_reseal_review["ftc"]["instances"]["accepted"],
            "rejected_check_instances": hr_reseal_review["ftc"]["instances"]["rejected"],
            "blocked_controls": ["FTC-01", "FTC-10", "FTC-23"],
            "source_facet_links_matching": hr_reseal_review["counts"]["source_facet_links_matching"],
            "source_facet_links_total": (
                hr_reseal_review["counts"]["source_facet_links_matching"]
                + hr_reseal_review["counts"]["source_facet_links_mismatching"]
            ),
            "formal_projects_accepted": 0,
            "formal_facets_accepted": 0,
            "verdict": "NO_GO_CORRECT_AND_RESEAL",
        },
        "hr_finance_corrected_reseal": {
            "projects": hr_corrected_reseal["counts"]["projects"],
            "facets": hr_corrected_reseal["counts"]["facets"],
            "claims": hr_corrected_reseal["counts"]["claims"],
            "claim_bearing_source_fields": hr_corrected_reseal["counts"]["claim_bearing_source_fields"],
            "project_check_instances": hr_corrected_reseal_review["ftc"]["project_check_instances"],
            "accepted_check_instances": hr_corrected_reseal_review["ftc"]["passed"],
            "blocked_check_instances": hr_corrected_reseal_review["ftc"]["failed"],
            "formal_projects_accepted": len(accepted_hr_repositories),
            "formal_facets_accepted": formal_facets_accepted,
            "formal_target_edges": 0,
            "verdict": "GO_BOUNDED_UPSTREAM_PROJECT_AND_FACET_RECORDS_ONLY",
        },
        "med_clinical_reissue": {
            "projects": 9,
            "prompt_projects": 8,
            "historical_extras": 1,
            "relations": 10,
            "formal_projects_accepted": 0,
            "rejected_prompt_projects": 7,
            "quarantined_prompt_projects": 1,
            "verdict": "NO_GO_FTC_01_FTC_23_ZERO_OF_8_FORMALLY_ACCEPTED",
        },
        "med_clinical_corrected_normalizer": {
            "projects": med_corrected_normalizer["counts"]["projects"],
            "prompt_projects": med_corrected_normalizer["counts"]["prompt_projects"],
            "historical_extras": med_corrected_normalizer["counts"]["historical_projects"],
            "relations": med_corrected_normalizer["counts"]["relations"],
            "project_v2_hashes_reproduced": med_corrected_normalizer_review["counts"]["project_v2_hashes_reproduced"],
            "relation_v2_hashes_reproduced": med_corrected_normalizer_review["counts"]["relation_v2_hashes_reproduced"],
            "project_check_instances": med_corrected_normalizer_review["ftc"]["prompt_instance_counts"]["total"],
            "accepted_check_instances": med_corrected_normalizer_review["ftc"]["prompt_instance_counts"]["pass"],
            "blocked_check_instances": med_corrected_normalizer_review["ftc"]["prompt_instance_counts"]["fail"],
            "blocked_controls": ["FTC-01", "FTC-02..FTC-22", "FTC-24"],
            "formal_projects_accepted": 0,
            "formal_facets_accepted": 0,
            "verdict": "NO_GO_MISSING_DURABLE_NORMALIZER_SESSION_TURN_AND_NORMATIVE_CONTROL_DEFINITIONS",
        },
    },
    "counts": {
        "formal_projects_accepted": formal_projects_accepted,
        "formal_facets_accepted": formal_facets_accepted,
        "formal_target_edges": 0,
        "final_no_matches": 0,
        "NCM": 0,
        "matrix_rows_changed": 0,
        "register_rows_changed": 0,
    },
    "contradictions": [
        {
            "id": "CONTRADICTION-RUN-058B-NO-CROSS-READ",
            "effect": "ENTIRE_PACKET_INVALID_FOR_FORMAL_ACCEPTANCE",
            "detail": "RUN-058B read current-facet-neutral-comparison-wave-02.json while attesting current-product comparison false.",
        },
        {
            "id": "CONTRADICTION-RUN-058C-GENERATOR-READY",
            "effect": "INITIAL_PACKET_NOT_ACCEPTED_CORRECTED_RESEAL_SUPERSEDES_FOR_PROJECT_RECORD_ONLY",
            "detail": "RUN-058C declared generator_ready true without satisfying all controls; RUN-065/RUN-066 later seal and accept three project records only.",
        },
        {
            "id": "BOUNDARY-RUN-061B-HR-FINANCE",
            "effect": "HISTORICAL_NO_GO_SUPERSEDED_FOR_BOUNDED_PROJECT_AND_FACET_RECORD_ACCEPTANCE_ONLY",
            "detail": "The earlier reseal failed FTC-01, FTC-10 and FTC-23. RUN-067B/RUN-068 later close those defects and accept six project plus twelve facet records; downstream selection, comparison, mapping and NCM credit remain zero.",
        },
        {
            "id": "BOUNDARY-RUN-067C-MED-CLINICAL",
            "effect": "ZERO_MED_CLINICAL_FORMAL_PROJECT_OR_FACET_ACCEPTANCE",
            "detail": "The normalized packet reproduces all V2 and legacy digests, but independent review cannot re-prove FTC-01, FTC-02 through FTC-22, or FTC-24 from the allowed evidence boundary.",
        },
        {
            "id": "BOUNDARY-RUN-058A-VOCABULARY",
            "effect": "ZERO_SELECTION_MAPPING_OR_NCM_CREDIT",
            "detail": "S, S+A, N and selected-project vocabulary are later-comparison labels only.",
        },
    ],
    "acceptance_gate": {
        "required_controls": [f"FTC-{number:02d}" for number in range(1, 25)],
        "required_result": "ALL_24_ACCEPTED_PER_PROJECT_BY_DIFFERENT_REVIEWER_OF_EXACT_PACKET_HASH",
        "scope_if_passed": "FORMAL_UPSTREAM_PROJECT_OR_FACET_RECORD_TRIAGE_ONLY",
        "future_artifacts_preaccepted": False,
    },
    "matrix_immutability": {
        "before_sha256": MATRIX_SHA256,
        "after_sha256": sha256_file("03-feature-to-benchmark-matrix.csv"),
        "byte_identical": True,
        "promoted_mappings_or_final_no_matches": 0,
        "credited_rows": "0/340",
    },
    "register_immutability": {
        "before_sha256": REGISTER_SHA256,
        "after_sha256": sha256_file("06-open-source-benchmark-register.csv"),
        "byte_identical": True,
    },
    "credit_boundary": credit_boundary,
    "reproducibility": {
        "generator_path": "generators/integrate-formal-upstream-triage-wave-03.py",
        "generator_sha256": sha256_file("generators/integrate-formal-upstream-triage-wave-03.py"),
        "serialization": "UTF-8, LF, sorted keys, two-space indentation, terminal LF",
        "live_timestamp": False,
    },
}

agent_register = {
    "schema_version": "1.0",
    "run_id": FINAL_RUN_ID,
    "status": f"ALL_MATERIALIZED_RETURNS_REPRESENTED_{formal_projects_accepted}_FORMAL_PROJECT_RECORDS_{formal_facets_accepted}_FORMAL_FACETS_ZERO_DOWNSTREAM_CREDIT",
    "root_writer_only": True,
    "lineages": [
        {
            "id": "HR_FINANCE",
            "producer": input_manifest_by_role["hr_finance_producer"],
            "reviewer": input_manifest_by_role["hr_finance_review"],
            "reseal": input_manifest_by_role["hr_finance_reseal_draft"],
            "reseal_reviewer": input_manifest_by_role["hr_finance_reseal_review"],
            "corrected_reseal": input_manifest_by_role["hr_finance_corrected_reseal"],
            "corrected_reseal_reviewer": input_manifest_by_role["hr_finance_corrected_reseal_review"],
            "effective_status": "GO_6_BOUNDED_FORMAL_PROJECT_RECORDS_12_BOUNDED_FACET_RECORDS_ZERO_DOWNSTREAM",
            "formal_projects_accepted": len(accepted_hr_repositories),
            "formal_facets_accepted": formal_facets_accepted,
        },
        {
            "id": "MED_CLINICAL",
            "producer": input_manifest_by_role["med_clinical_producer_invalidated"],
            "reviewer": input_manifest_by_role["med_clinical_review"],
            "reissue": input_manifest_by_role["med_clinical_reissue"],
            "reissue_reviewer": input_manifest_by_role["med_clinical_reissue_review"],
            "corrected_normalizer": input_manifest_by_role["med_clinical_corrected_normalizer"],
            "corrected_normalizer_reviewer": input_manifest_by_role["med_clinical_corrected_normalizer_review"],
            "effective_status": "NO_GO_MISSING_DURABLE_NORMALIZER_SESSION_TURN_AND_NORMATIVE_CONTROLS_ZERO_OF_8",
            "formal_projects_accepted": 0,
            "formal_facets_accepted": 0,
        },
        {
            "id": "INCIDENT",
            "producer": input_manifest_by_role["incident_producer"],
            "reviewer": input_manifest_by_role["incident_review"],
            "reseal": input_manifest_by_role["incident_reseal_draft"],
            "reseal_reviewer": input_manifest_by_role["incident_reseal_review"],
            "fresh_reseal": input_manifest_by_role["incident_fresh_reseal_draft"],
            "fresh_reseal_reviewer": input_manifest_by_role["incident_fresh_reseal_review"],
            "corrected_reseal": input_manifest_by_role["incident_corrected_reseal"],
            "corrected_reseal_reviewer": input_manifest_by_role["incident_corrected_reseal_review"],
            "effective_status": "GO_THREE_BOUNDED_FORMAL_UPSTREAM_PROJECT_RECORDS_ZERO_DOWNSTREAM",
            "formal_projects_accepted": len(accepted_incident_repositories),
            "direct_formal_records": accepted_incident_direct,
            "adjacent_nonpromotable_formal_records": accepted_incident_adjacent,
        },
    ],
    "supplemental_returns": [
        {
            "id": "QUARANTINED_NON_AUTHORITATIVE_INCIDENT_MECHANICAL_ATTEMPT",
            "responsible_agent_identity": "/root/run063_incident_final_reseal",
            "status": "NO_GO_NON_AUTHORITATIVE_MECHANICAL_ASSISTANCE_ONLY",
            "reason": "The return hashed the out-of-allowlist benchmark register and was not materialized or used as an integration input.",
            "used_as_input": False,
            "formal_projects_accepted": 0,
        },
        {
            "id": "FORMAL_INTEGRATION_ADVERSARIAL_CHECK",
            "artifact": input_manifest_by_role["formal_integration_adversarial_check"],
            "status": f"PRE_INTEGRATION_CORRECTION_CONTRACT_CONSUMED_BY_{FINAL_RUN_ID}",
            "formal_projects_accepted": 0,
            "downstream_credit": False,
        },
        {
            "id": "HR_FINANCE_PROVENANCE_RECOVERY",
            "artifact": input_manifest_by_role["hr_finance_provenance_recovery"],
            "status": "FTC_01_DURABLY_RECOVERED_PENDING_CORRECTED_RESEAL",
            "formal_projects_accepted": 0,
            "downstream_credit": False,
        },
        {
            "id": "MED_CLINICAL_CORRECTION_FEASIBILITY",
            "artifact": input_manifest_by_role["med_clinical_correction_feasibility"],
            "status": "FTC_23_FEASIBLE_FTC_01_REQUIRES_VERIFIED_PRODUCER_OR_FRESH_REISSUER",
            "formal_projects_accepted": 0,
            "downstream_credit": False,
        },
        {
            "id": "HR_FINANCE_REVIEW_CHECKLIST",
            "artifact": input_manifest_by_role["hr_finance_review_checklist"],
            "status": "CHECKLIST_ONLY_NO_PREACCEPTANCE",
            "formal_projects_accepted": 0,
            "downstream_credit": False,
        },
        {
            "id": "MED_CLINICAL_PROVENANCE_RECOVERY",
            "artifact": input_manifest_by_role["med_clinical_provenance_recovery"],
            "status": "SOURCE_REISSUER_IDENTITY_RECOVERED_DISTINCT_NORMALIZER_PENDING",
            "formal_projects_accepted": 0,
            "downstream_credit": False,
        },
        {
            "id": "HR_FINANCE_CLAIM_CONTRACT_CHECK",
            "artifact": input_manifest_by_role["hr_finance_claim_contract_check"],
            "status": "CONDITIONAL_GO_108_ATOMS_PENDING_SEALED_PACKET_AND_DIFFERENT_REVIEWER",
            "formal_projects_accepted": 0,
            "downstream_credit": False,
        },
        {
            "id": "MED_CLINICAL_REVIEW_CHECKLIST",
            "artifact": input_manifest_by_role["med_clinical_review_checklist"],
            "status": "CHECKLIST_ONLY_NO_PREACCEPTANCE",
            "formal_projects_accepted": 0,
            "downstream_credit": False,
        },
        {
            "id": "HR_FINANCE_CLAIM_IMPLEMENTATION_CHECK",
            "artifact": input_manifest_by_role["hr_finance_claim_implementation_check"],
            "status": "CONTRACT_COMPLETE_PENDING_EXACT_FILE_REVIEW",
            "formal_projects_accepted": 0,
            "downstream_credit": False,
        },
    ],
    "counts": {
        "initial_producers": 3,
        "initial_independent_reviews": 3,
        "correction_drafts": 5,
        "correction_reviews": 5,
        "quarantined_non_authoritative_returns": 1,
        "integration_adversarial_reviews": 1,
        "provenance_recoveries": 2,
        "correction_feasibility_contracts": 1,
        "review_checklists": 2,
        "claim_contract_checks": 1,
        "claim_implementation_checks": 1,
        "corrected_packets_independently_reviewed": 2,
        "formal_projects_accepted": formal_projects_accepted,
        "formal_facets_accepted": formal_facets_accepted,
        "formal_edges": 0,
        "final_no_matches": 0,
    },
    "credit_boundary": credit_boundary,
    "attestation": {
        "application_source_modified": False,
        "application_source_inspected_by_generator": False,
        "runtime_browser_tests_build_database_used_by_generator": False,
        "mapping_or_NCM_awarded": False,
        "audit_complete": False,
    },
}

represented_roles: list[str] = []


def collect_manifest_roles(value: object) -> None:
    if isinstance(value, dict):
        if {"role", "path", "sha256", "bytes"}.issubset(value):
            represented_roles.append(str(value["role"]))
        for nested in value.values():
            collect_manifest_roles(nested)
    elif isinstance(value, list):
        for nested in value:
            collect_manifest_roles(nested)


collect_manifest_roles(agent_register["lineages"])
collect_manifest_roles(agent_register["supplemental_returns"])
assert len(represented_roles) == len(set(represented_roles)) == len(INPUTS)
assert set(represented_roles) == set(INPUTS)

assert output["matrix_immutability"]["after_sha256"] == MATRIX_SHA256
assert output["register_immutability"]["after_sha256"] == REGISTER_SHA256
assert output["run_id"] == agent_register["run_id"] == FINAL_RUN_ID
assert output["counts"]["formal_projects_accepted"] == formal_projects_accepted
assert output["counts"]["formal_facets_accepted"] == formal_facets_accepted
assert agent_register["counts"]["formal_projects_accepted"] == formal_projects_accepted
assert agent_register["counts"]["formal_facets_accepted"] == formal_facets_accepted
assert output["denominator_reconciliation"]["accepted_prompt_repositories"] == formal_projects_accepted
assert output["denominator_reconciliation"]["accepted_prompt_occurrence_weight"] == accepted_prompt_occurrence_weight
assert output["formal_project_record_acceptance"]["accepted_records"] == formal_projects_accepted
assert output["formal_project_record_acceptance"]["accepted_prompt_repositories"] == accepted_prompt_repositories
assert output["formal_project_record_acceptance"]["accepted_prompt_occurrence_weight"] == accepted_prompt_occurrence_weight
assert output["formal_project_record_acceptance"]["formal_facets"] == formal_facets_accepted
assert accepted_incident_direct + accepted_incident_adjacent == len(accepted_incident_repositories)
assert all(
    row[2].startswith("ADJACENT_")
    for row in incident_corrected_reseal_review["project_decisions"]
    if row[1].casefold() in accepted_incident_repositories and row[2].startswith("ADJACENT_")
)
assert all(output["counts"][key] == 0 for key in ("formal_target_edges", "final_no_matches", "NCM", "matrix_rows_changed", "register_rows_changed"))
assert agent_register["counts"]["formal_edges"] == 0
assert agent_register["counts"]["final_no_matches"] == 0
assert credit_boundary["formal_upstream_project_record_acceptance"] is True
assert credit_boundary["formal_upstream_facet_record_acceptance"] is True
assert all(
    value is False
    for key, value in credit_boundary.items()
    if key not in {"formal_upstream_project_record_acceptance", "formal_upstream_facet_record_acceptance"}
)

write_json("evidence/benchmark/current-formal-upstream-triage-wave-03.json", output)
write_json("evidence/benchmark/current-formal-upstream-triage-agent-register.json", agent_register)

assert sha256_file("03-feature-to-benchmark-matrix.csv") == MATRIX_SHA256
assert sha256_file("06-open-source-benchmark-register.csv") == REGISTER_SHA256
