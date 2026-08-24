#!/usr/bin/env python3
"""Build the eighth target-specific benchmark research payload."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path


AUDIT = Path(__file__).resolve().parent.parent
SOURCE = AUDIT / "evidence" / "source"
MANIFEST_PATH = SOURCE / "working-capability-manifest-902.json"
MAPPING_PATH = SOURCE / "benchmark-final-902-mapping.json"
OUTPUT_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave8.json"
COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
GENERATED_AT = "2026-08-13T22:46:33+12:00"
PRE_WAVE_MAPPING_SHA = "571e6a78bf34d5542168c32ab015f35e802933f4ac4cdb3188f047e93ca23511"
DIRECT_EDITION_BOUNDARY = (
    "Only the cited repository-native community source at the pinned immutable commit is credited; "
    "hosted, commercial, enterprise, private and unpinned behavior is excluded."
)


def load(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8-sig"))


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


OPENPROJECT = "0d46ea5d912e32f877537c59d8ab016b0c3e168f"
OPENEMR = "a6321a3e2978e62472dbb6c721b680ca2d65b592"
OPENMRS = "8bb5c2e9e36ab8fb09f0053786bc0f040775cc1e"
ONEUPTIME = "ba44f303182bb8e896809c8491023c8d2210f4ee"


DIRECT = [
    {
        "key": "CAP-CR-ALERT-COLLABORATION",
        "neutral": "Authorised operators can list, add and revise alert discussion entries only through the supplied visible alert, retaining author, time, visibility and notification provenance without disclosing a hidden parent.",
        "repo": "https://github.com/opf/openproject", "slug": "opf/openproject", "sha": OPENPROJECT, "spdx": "GPL-3.0",
        "loci": ["app/controllers/work_packages/activities_tab_controller.rb:L42-L45,L132-L162,L199-L201,L235-L250,L295-L315", "app/services/work_packages/activities_tab/comment_service.rb:L31-L83"],
        "slice": "Resolves a visible work package, authorises the activity action, creates or updates a journal comment, limits accepted fields, sanitises internal mentions, controls notifications and restricts journal lookup to the owning work package.",
        "limits": "Does not prove Oblivion alert/site ownership, child resolution, discussion deletion, privacy classification, Laravel audit events or runtime behavior. Enterprise-only internal-comment availability is excluded.",
    },
    {
        "key": "CAP-CR-ALERT-TASKS",
        "neutral": "Authorised operators can create and update alert tasks within the owning alert, retaining status, assignee, due-date and history evidence without cross-alert mutation.",
        "repo": "https://github.com/opf/openproject", "slug": "opf/openproject", "sha": OPENPROJECT, "spdx": "GPL-3.0",
        "loci": ["app/models/work_package.rb:L57-L67,L87-L110,L140-L148,L181-L203", "app/services/work_packages/create_service.rb:L31-L81,L84-L110"],
        "slice": "A work package belongs to project, type, status and author, may have assignee and watchers, and is created through contract-backed attribute validation with related scheduling and ancestor updates.",
        "limits": "Project work packages are not safety-alert tasks; no Oblivion alert parent, task ordering API, site boundary, notification contract or runtime behavior is proved.",
    },
    {
        "key": "CAP-CR-ALERT-TIME-TRACKING",
        "neutral": "Authorised operators can record time against the correct alert, with own-versus-other permission, membership, duplicate-active-timer prevention and actor/time provenance.",
        "repo": "https://github.com/opf/openproject", "slug": "opf/openproject", "sha": OPENPROJECT, "spdx": "GPL-3.0",
        "loci": ["modules/costs/app/contracts/time_entries/create_contract.rb:L29-L67", "modules/costs/app/services/time_entries/create_service.rb:L29-L54"],
        "slice": "Validates whether the actor may log time for self or others, checks project membership and work-package permission, rejects duplicate ongoing entries, persists the result and emits a creation event.",
        "limits": "Does not prove alert/site ownership, explicit stop behavior, duration rules, audit events or safety-response reporting.",
    },
    {
        "key": "CAP-CR-ALERT-WATCHERS",
        "neutral": "Authorised operators can list, add and remove watchers only for a visible owning alert while separating self-watch from watcher-management authority and hiding unavailable identities.",
        "repo": "https://github.com/opf/openproject", "slug": "opf/openproject", "sha": OPENPROJECT, "spdx": "GPL-3.0",
        "loci": ["app/controllers/watchers_controller.rb:L31-L68", "lib/api/v3/work_packages/watchers_api.rb:L34-L110"],
        "slice": "Resolves a visible watched object, distinguishes self-watch from watcher-management permissions, restricts candidates to visible non-locked principals and creates or removes watchers with safe not-found behavior.",
        "limits": "Does not prove Oblivion alert/site ownership, notification delivery, audit events or site-scoped user search.",
    },
    {
        "key": "CAP-CR-PLAYBOOK-RUN",
        "neutral": "An authorised actor can launch a versioned playbook with validated inputs and retain run state, timestamps, outputs, failures, cancellation and relaunch provenance.",
        "repo": "https://github.com/ansible/awx", "slug": "ansible/awx", "sha": "7242fe89ae247ef874f187d245b5925e570ae46f", "spdx": "Apache-2.0",
        "loci": ["awx_collection/plugins/modules/workflow_launch.py:L151-L198", "awx/main/models/unified_jobs.py:L82-L99,L882-L953,L968-L1034,L1361-L1385,L1494-L1543", "awx/api/serializers.py:L4786-L4880"],
        "slice": "Resolves a workflow template, validates allowed prompts and required inventory/variables, submits a launch, retains job identity/status and terminal timestamps, records launch prompts for relaunch and supports transaction-aware cancellation with a failure explanation.",
        "limits": "Automation workflows are not human safety playbooks; no Oblivion alert/site ownership, acknowledgement checklist, clinical decision or manual-step evidence is proved.",
    },
    {
        "key": "CAP-CLIN-FRONTLINE-OBSERVATION-RECORDING",
        "neutral": "An authorised worker can record an observation for the correct person and encounter context with validated data, actor/person/time provenance and atomic rollback on failure.",
        "repo": "https://github.com/openemr/openemr", "slug": "openemr/openemr", "sha": OPENEMR, "spdx": "GPL-3.0",
        "loci": ["src/Controllers/Interface/Forms/Observation/ObservationController.php:L75-L139,L242-L280,L283-L354"],
        "slice": "Checks form permission and CSRF, binds patient, encounter and authenticated provider, prevents an observation ID crossing patients, validates data and writes parent/sub-observations in a transaction with rollback.",
        "limits": "Does not prove Oblivion shift schedule, site access, direct-object policy, offline idempotency, observation taxonomy or worker UI.",
    },
    {
        "key": "CAP-CLIN-EVENT-REGISTER-RECORD",
        "neutral": "An authorised clinician can record and retrieve a person-linked clinical event with time, type, location, provider and correction/void provenance.",
        "repo": "https://github.com/openmrs/openmrs-core", "slug": "openmrs/openmrs-core", "sha": OPENMRS, "spdx": "MPL-2.0",
        "loci": ["api/src/main/java/org/openmrs/Encounter.java:L47-L112,L136-L188,L478-L488,L579-L621", "api/src/main/java/org/openmrs/api/EncounterService.java:L51-L82,L111-L181,L204-L226", "api/src/main/java/org/openmrs/api/impl/EncounterServiceImpl.java:L69-L101,L106-L173,L216-L236,L264-L274,L315-L324,L415-L491"],
        "slice": "Models a patient interaction with time, patient, location, type, visit and providers; permission-gates add/edit/view, saves transactionally and supports filtered retrieval plus reasoned void/unvoid.",
        "limits": "An encounter is not necessarily an adverse clinical event; severity, on-call escalation, follow-up and Oblivion site/direct-object rules remain unproved.",
    },
    {
        "key": "CAP-CLIN-EVENT-REVIEW-ESCALATION-CLOSURE",
        "neutral": "An authorised reviewer can apply ordered event-state transitions with actor, cause and timeline evidence and configure an ordered wait-based escalation rule.",
        "repo": "https://github.com/OneUptime/oneuptime", "slug": "OneUptime/oneuptime", "sha": ONEUPTIME, "spdx": "Apache-2.0",
        "loci": ["Common/Models/DatabaseModels/AlertStateTimeline.ts:L35-L86,L268-L323,L380-L490,L511-L568", "Common/Server/Services/AlertStateTimelineService.ts:L63-L215,L306-L461,L494-L524", "Common/Models/DatabaseModels/OnCallDutyPolicyEscalationRule.ts:L23-L75,L158-L236,L243-L323,L457-L494"],
        "slice": "Community source permission-gates alert state timelines, validates referenced state scope, rejects duplicate or backward transitions, records actor/root cause, updates current state and models ordered wait-based escalation-rule configuration.",
        "limits": "Paid-plan execution-timeline evidence is excluded. This does not prove clinical review/sign-off, escalation execution or delivery, acknowledgement, follow-up prerequisites, client records, Oblivion site boundaries or runtime behavior.",
    },
    {
        "key": "CAP-MED-PRESCRIPTION-LIFECYCLE",
        "neutral": "An authorised prescriber can create, retrieve and reasonedly deactivate a patient prescription while preserving structured medication identity and modification provenance.",
        "repo": "https://github.com/openemr/openemr", "slug": "openemr/openemr", "sha": OPENEMR, "spdx": "GPL-3.0",
        "loci": ["src/RestControllers/PrescriptionRestController.php:L35-L68,L70-L101,L103-L157", "src/Services/PrescriptionService.php:L375-L449,L451-L471"],
        "slice": "Authenticated endpoints create, retrieve, list and soft-delete prescriptions; creation validates required patient/drug fields and deletion marks the row inactive with modification time instead of erasing it.",
        "limits": "Does not prove a revision endpoint, prescriber registration, countersign, pharmacy handoff, site/direct-object controls or Oblivion audit UI.",
    },
    {
        "key": "CAP-MED-WORKER-SCHEDULED-DOSE-RECORD",
        "neutral": "An authorised worker can persist a medication-administration result bound to patient, order, performer, time, dose, route, status/reason and notes.",
        "repo": "https://github.com/Bahmni/openmrs-module-medicationadministration", "slug": "Bahmni/openmrs-module-medicationadministration", "sha": "acb65f75d3515b4f2b36083345bfbafc2ee146b0", "spdx": "MPL-2.0",
        "loci": ["api/src/main/java/org/openmrs/module/ipd/api/model/MedicationAdministration.java:L19-L59,L89-L197", "api/src/main/java/org/openmrs/module/fhir2/apiext/dao/impl/FhirMedicationAdministrationDaoImpl.java:L40-L58", "api/src/main/java/org/openmrs/module/fhir2/apiext/translators/impl/MedicationAdministrationTranslatorImpl.java:L67-L125,L131-L202"],
        "slice": "Represents administration with patient, encounter, performer, order, status/reason, time, dose, units, route and notes; permission-gates persistence and translates those fields to and from FHIR MedicationAdministration.",
        "limits": "Does not prove scheduled-window selection, five-rights checks, site/direct-object denial, offline idempotency, stock effects, incident generation or worker UI.",
    },
]


NCM = [
    {
        "key": "CAP-CR-EVIDENCE-PACK-ASSEMBLY",
        "neutral": "Assemble an authorised alert-scoped evidence pack from selected immutable versions with manifest, hashes, provenance, redaction decisions, generation state, failure/retry, download authorisation and download audit.",
        "search_terms": ["alert evidence pack assembly manifest hash immutable attachment versions export audit"],
        "rejected": [
            {"official_repository_url": "https://github.com/opf/openproject", "commit_sha": OPENPROJECT, "spdx": "GPL-3.0", "edition_boundary": "Pinned GPL-3.0 OpenProject Community source only; Enterprise add-ons, hosted behavior, private extensions and unpinned branches are excluded.", "source_loci": ["app/contracts/attachments/create_contract.rb:L31-L97", "app/services/attachments/create_service.rb:L31-L89", "app/models/work_package.rb:L195-L203"], "reason": "Proves authorised attachment creation, type allow-listing, a container mutex, journal refresh and notification, not an immutable alert pack with manifest, hashes, redaction snapshot and pack completion."},
            {"official_repository_url": "https://github.com/mayan-edms/Mayan-EDMS", "commit_sha": "8da13ee7e49fa23d24ab49906bf9cf6bfe3f44e9", "spdx": "Apache-2.0", "edition_boundary": "Pinned Apache-2.0 Mayan EDMS repository-native community source only; hosted services, private extensions and unpinned branches are excluded.", "source_loci": ["mayan/apps/documents/api_views/document_file_api_views.py:L93-L116", "mayan/apps/documents/api_views/document_version_api_views.py:L54-L73", "mayan/apps/events/views/export_views.py:L20-L48,L70-L107"], "reason": "Proves permission-gated file download, version export and queued event-list export, not one alert/case evidence-pack assembly with frozen versions, manifest, hashes, redaction and pack-level completion."},
        ],
        "reason": "The two inspected official repositories do not prove the same alert-scoped immutable assembly, manifest, provenance and downloadable completion boundary. This is bounded to the inspected corpus, not a global absence claim.",
    },
    {
        "key": "CAP-CLIN-BEHAVIOUR-REGISTER",
        "neutral": "Present an authorised person-filterable behaviour register with occurrence, behaviour definition, severity/risk, actor, support-plan context, action/review state and trends without unrelated-person disclosure.",
        "search_terms": ["behaviour support event register ABC antecedent behaviour consequence risk review open source health record"],
        "rejected": [
            {"official_repository_url": "https://github.com/openemr/openemr", "commit_sha": OPENEMR, "spdx": "GPL-3.0", "edition_boundary": "Pinned GPL-3.0 OpenEMR repository-native source only; hosted services, private extensions and unpinned branches are excluded.", "source_loci": ["src/Controllers/Interface/Forms/Observation/ObservationController.php:L180-L225,L242-L354"], "reason": "Generic patient/encounter observation listing and transaction-backed recording do not establish a behaviour register, support-plan context, ABC structure, risk or review state."},
            {"official_repository_url": "https://github.com/openmrs/openmrs-core", "commit_sha": OPENMRS, "spdx": "MPL-2.0", "edition_boundary": "Pinned MPL-2.0 OpenMRS community core only, subject to its Healthcare Disclaimer; hosted services, private extensions and unpinned branches are excluded.", "source_loci": ["api/src/main/java/org/openmrs/Obs.java:L41-L67,L95-L171,L180-L235", "api/src/main/java/org/openmrs/Encounter.java:L47-L112"], "reason": "Generic coded observations and encounters can store concepts but do not establish the behaviour-register actor job, support-plan/risk workflow, review state or completion."},
        ],
        "reason": "The inspected official health-record repositories prove generic observations, not the exact behaviour-register capability and completion boundary. This is bounded to the inspected corpus.",
    },
]


manifest = load(MANIFEST_PATH)
mapping = load(MAPPING_PATH)
manifest_by_key = {row["working_key"]: row for row in manifest["targets"]}
mapping_by_key = {row["working_key"]: row for row in mapping["targets"]}
definitions = DIRECT + NCM
require(len(definitions) == 12 and len({row["key"] for row in definitions}) == 12, "Wave 8 must contain 12 unique targets")
for definition in DIRECT:
    require(bool(str(definition.get("spdx", "")).strip()), f"Direct benchmark SPDX is missing: {definition['key']}")
    require(bool(DIRECT_EDITION_BOUNDARY.strip()), f"Direct benchmark edition boundary is missing: {definition['key']}")
for definition in NCM:
    require(bool(definition.get("rejected")), f"NCM rejected repository set is empty: {definition['key']}")
    for rejected in definition["rejected"]:
        require(bool(str(rejected.get("spdx", "")).strip()), f"NCM rejected repository SPDX is missing: {definition['key']}")
        require(bool(str(rejected.get("edition_boundary", "")).strip()), f"NCM rejected repository edition boundary is missing: {definition['key']}")


def lineage(identity: dict) -> dict:
    return {field: identity.get(field, []) for field in ("id_status", "class", "canonical_module", "source_family_ids", "route_ids", "page_ids", "backend_anchors")}


evaluations = []
for definition in DIRECT:
    key = definition["key"]
    identity, prior = manifest_by_key[key], mapping_by_key[key]
    require(prior["status"] in {"unproved", "verified_benchmark_direct"}, f"Unexpected target status: {key}")
    if prior["status"] == "verified_benchmark_direct":
        require(prior.get("source_units") == [f"fresh-902-wave8:{key}"], f"Wave 8 direct source-unit drift: {key}")
        require(prior.get("prior_outcome") == "unproved", f"Wave 8 direct prior-outcome drift: {key}")
    evidence_loci = [f"{definition['slug']}@{definition['sha']} :: {locus.replace(':L', ' :: L')}" for locus in definition["loci"]]
    evaluations.append({
        "working_key": key, "prior_status": "unproved", "candidate_status": "candidate_found_direct", "completion_credit_recommended": True,
        "neutral_requirement": definition["neutral"], "current_source_lineage": lineage(identity), "evidence_loci": evidence_loci,
        "benchmark": {"official_repository_url": definition["repo"], "commit_sha": definition["sha"], "source_loci": definition["loci"], "proven_slice": definition["slice"], "parity_limits": definition["limits"], "licence": {"spdx": definition["spdx"], "edition_boundary": DIRECT_EDITION_BOUNDARY}},
    })

for definition in NCM:
    key = definition["key"]
    identity, prior = manifest_by_key[key], mapping_by_key[key]
    require(prior["status"] in {"unproved", "documented_ncm_direct"}, f"Unexpected target status: {key}")
    if prior["status"] == "documented_ncm_direct":
        require(prior.get("source_units") == [f"fresh-902-wave8:{key}"], f"Wave 8 NCM source-unit drift: {key}")
        require(prior.get("prior_outcome") == "unproved", f"Wave 8 NCM prior-outcome drift: {key}")
    evidence_loci = []
    for rejected in definition["rejected"]:
        slug = rejected["official_repository_url"].removeprefix("https://github.com/")
        evidence_loci.extend(f"{slug}@{rejected['commit_sha']} :: {locus.replace(':L', ' :: L')}" for locus in rejected["source_loci"])
    evaluations.append({
        "working_key": key, "prior_status": "unproved", "candidate_status": "documented_ncm_direct", "completion_credit_recommended": True,
        "neutral_requirement": definition["neutral"], "current_source_lineage": lineage(identity), "search_terms": definition["search_terms"], "evidence_loci": evidence_loci,
        "rejected_repositories": definition["rejected"], "bounded_ncm_reason": definition["reason"],
    })

evaluations.sort(key=lambda row: row["working_key"])
output = {
    "schema_version": "1.0.0", "artifact": "benchmark-target-specific-adjudication-902-wave8", "generated_at": GENERATED_AT,
    "audited_repository": str(AUDIT.parents[2]), "audited_commit": COMMIT, "read_only": True,
    "scope": "Eighth bounded target-specific wave: 12 independently researched current unique ordinary-unproved targets, with no inherited family credit.",
    "methodology": {"credit_rule": "Only target-specific official repository-native source pinned to an immutable commit, with exact loci proving a material same-target slice or a bounded multi-repository NCM, receives completion credit.", "licence_rule": "Only cited community source is credited; hosted, paid-plan, enterprise, private and unpinned behavior is excluded.", "no_copy_rule": "Evidence is behavioural only; do not copy source, schema, UI, wording or distinctive layouts.", "family_credit_inherited": False, "runtime_boundary": "No application, browser, database, deployment or Git state was changed."},
    "input_pins": {"working_capability_manifest_902": {"path": "evidence/source/working-capability-manifest-902.json", "file_sha256": sha(MANIFEST_PATH)}, "benchmark_final_902_before_wave": {"path": "evidence/source/benchmark-final-902-mapping.json", "file_sha256": PRE_WAVE_MAPPING_SHA}},
    "counts": {"evaluated": 12, "verified_benchmark_direct_recommended": 10, "documented_ncm_direct_recommended": 2, "completion_credit_recommended": 12},
    "evaluations": evaluations,
    "projected_counts_after_application": {"denominator": 902, "verified_benchmark": {"direct": 254, "strict_one_to_one_rename": 22, "total": 276}, "documented_no_credible_match": {"direct": 79, "strict_one_to_one_rename": 7, "total": 86}, "eligible_total": 362, "completion_unproved_total": 540, "eligible_percentage": 40.1330},
    "integrity": {"selected_target_count": 12, "selected_targets_unique": True, "selected_targets_current_in_manifest": True, "selected_targets_were_ordinary_unproved_at_recheck": True, "all_upstream_repositories_official": True, "all_upstream_refs_immutable_commits": True, "licence_and_edition_screening_complete": True, "family_credit_inherited": False, "paid_only_evidence_used": False, "files_modified_by_research_agents": False, "independent_review": "Adversarial read-only review removed OneUptime Growth-plan execution-timeline evidence and narrowed the credited clinical event slice to community state-transition and escalation-rule configuration."},
}

OUTPUT_PATH.write_text(json.dumps(output, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(json.dumps({"output": str(OUTPUT_PATH), "sha256": sha(OUTPUT_PATH), "rows": len(evaluations)}, indent=2))
