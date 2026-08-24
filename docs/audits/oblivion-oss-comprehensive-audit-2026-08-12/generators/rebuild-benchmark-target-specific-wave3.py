#!/usr/bin/env python3
"""Build the third bounded target-specific benchmark wave for the 901 map.

Audit-artifact generator only. It reads the canonical manifest and writes one
adjudication artifact. It does not execute application code, tests, browsers,
databases, queues, jobs, deployments or product workflows.
"""

from __future__ import annotations

import hashlib
import json
from pathlib import Path


GENERATOR_DIR = Path(__file__).resolve().parent
AUDIT = GENERATOR_DIR.parent
SOURCE = AUDIT / "evidence" / "source"
MANIFEST = SOURCE / "working-capability-manifest-901.json"
OUTPUT = SOURCE / "benchmark-target-specific-adjudication-901-wave3.json"
COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
MANIFEST_SHA = "5b477cc3fa5e5343b223b7ba559919f708f945426f193dbb0510245771148900"
BASE_MAPPING_SHA = "4ea03909bf9b9b3f6dabffba249aa169788042be9060ba50cd37c483fbbaccf2"


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def lines_sha(values: list[str]) -> str:
    return hashlib.sha256("\n".join(sorted(values)).encode("utf-8")).hexdigest()


def load(path: Path) -> dict:
    with path.open("r", encoding="utf-8-sig") as handle:
        return json.load(handle)


def evidence_locus(repo_url: str, commit_sha: str, source_locus: str) -> str:
    owner_repo = repo_url.removeprefix("https://github.com/")
    path, ranges = source_locus.split(":", 1)
    return f"{owner_repo}@{commit_sha} :: {path} :: {ranges}"


manifest = load(MANIFEST)
assert sha(MANIFEST) == MANIFEST_SHA
assert manifest["audited_commit"] == COMMIT
manifest_by_key = {row["working_key"]: row for row in manifest["targets"]}
assert len(manifest_by_key) == 901


def lineage(key: str) -> dict:
    target = manifest_by_key[key]
    result = {
        "id_status": target["id_status"],
        "class": target["class"],
        "canonical_module": target["canonical_module"],
        "source_family_ids": sorted(set(target.get("source_family_ids", []))),
        "route_ids": sorted(set(target.get("route_ids", []))),
        "page_ids": sorted(set(target.get("page_ids", []))),
        "backend_anchors": sorted(set(target.get("backend_anchors", []))),
    }
    assert result["route_ids"] and result["page_ids"] and result["backend_anchors"]
    return result


def candidate(
    key: str,
    requirement: str,
    searches: list[str],
    repo_url: str,
    commit_sha: str,
    source_loci: list[str],
    proven_slice: str,
    parity_limits: str,
) -> dict:
    return {
        "working_key": key,
        "adjudication_id": f"fresh-901-wave3:{key}",
        "candidate_status": "candidate_found_direct",
        "completion_credit_recommended": True,
        "neutral_requirement": requirement,
        "search_terms": searches,
        "current_source_lineage": lineage(key),
        "evidence_loci": [evidence_locus(repo_url, commit_sha, item) for item in source_loci],
        "benchmark": {
            "official_repository_url": repo_url,
            "commit_sha": commit_sha,
            "source_loci": source_loci,
            "proven_slice": proven_slice,
            "parity_limits": parity_limits,
        },
        "inheritance_boundary": "Fresh target-specific material slice; no source-family, split, merge or prior outcome is inherited.",
    }


KEYCLOAK = "https://github.com/keycloak/keycloak"
KEYCLOAK_SHA = "7c34342861092b8b8ce8b48dbc0f99ca3c3e541d"
PRIMERO = "https://github.com/primeroIMS/primero"
PRIMERO_SHA = "9cea249d502269c258028844884d51e9a89bd00a"
ONEUPTIME = "https://github.com/OneUptime/oneuptime"
ONEUPTIME_SHA = "ba44f303182bb8e896809c8491023c8d2210f4ee"
CISO = "https://github.com/intuitem/ciso-assistant-community"
CISO_SHA = "1ba187b0117f4dba9e00605bd7d5319ded61cee3"


evaluations = [
    candidate(
        "CAP-SET-USER-ACCOUNT-LIFECYCLE",
        "An authorised identity administrator can create, validate, update, enable or disable, session-revoke, and retire a unique user account with credential and administrative-event evidence.",
        ["open source identity account create update enable disable revoke sessions delete admin events", "Keycloak UsersResource UserResource account lifecycle"],
        KEYCLOAK,
        KEYCLOAK_SHA,
        [
            "services/src/main/java/org/keycloak/services/resources/admin/UsersResource.java:L120-L190",
            "services/src/main/java/org/keycloak/services/resources/admin/UserResource.java:L175-L270",
            "services/src/main/java/org/keycloak/services/resources/admin/UserResource.java:L680-L727",
        ],
        "Permission-gated unique account creation with validation, credentials, groups and a successful admin event; permission-gated profile/enabled-state update with rollback/error paths and successful admin event; session logout and account deletion with successful admin events.",
        "Identity-system account lifecycle only; does not prove Oblivion staff/client profile coupling, site assignments, approvals, soft-delete semantics, role vocabulary, privacy or runtime acceptance.",
    ),
    candidate(
        "CAP-SET-TWO-FACTOR-MANAGEMENT",
        "An authenticated user can configure a labelled OTP credential, reject missing or invalid codes, optionally revoke other sessions, view non-secret credential metadata, and remove the credential with event evidence.",
        ["open source TOTP configure validate labelled credential remove session revoke event", "Keycloak UpdateTotp AccountCredentialResource"],
        KEYCLOAK,
        KEYCLOAK_SHA,
        [
            "services/src/main/java/org/keycloak/authentication/requiredactions/UpdateTotp.java:L95-L200",
            "services/src/main/java/org/keycloak/services/resources/account/AccountCredentialResource.java:L166-L240",
            "services/src/main/java/org/keycloak/services/resources/account/AccountCredentialResource.java:L297-L350",
            "services/src/main/java/org/keycloak/authentication/requiredactions/RecoveryAuthnCodesAction.java:L103-L140",
        ],
        "OTP challenge and validation, persisted labelled credential setup, optional logout of other sessions, generation and storage of recovery authentication codes, safe metadata listing with secret stripping, and authorised removal with credential/TOTP success events.",
        "TOTP credential slice only; no Oblivion Fortify confirmation UX, QR/secret presentation, recovery-code regeneration, password-confirm boundary, accessibility, rate limits or runtime parity.",
    ),
    candidate(
        "CAP-SET-ROLE-DEFINITIONS",
        "An authorised identity administrator can list, create, view, update, compose and delete stable role definitions while enforcing management permissions and recording administrative events.",
        ["open source RBAC role create update delete composite permission admin event", "Keycloak RoleContainerResource RoleByIdResource RoleResource"],
        KEYCLOAK,
        KEYCLOAK_SHA,
        [
            "services/src/main/java/org/keycloak/services/resources/admin/RoleContainerResource.java:L116-L242",
            "services/src/main/java/org/keycloak/services/resources/admin/RoleByIdResource.java:L88-L204",
            "services/src/main/java/org/keycloak/services/resources/admin/RoleResource.java:L55-L171",
        ],
        "Permission-gated role list/create/view/update/delete plus composite-role add/remove, validation, default-role deletion guard, and successful create/update/delete admin events.",
        "Generic realm/client RBAC only; does not prove Oblivion permission keys, landing routes, site scope, hierarchy/level semantics, user-count guardrails, direct-object denial or runtime parity.",
    ),
    candidate(
        "CAP-INC-INCIDENT-AUTHOR",
        "An authorised incident worker can create and update a persisted incident linked to a person/case, preserve stable identity, ownership, status, event details and actor/time history, and enforce record permissions.",
        ["open source incident case create update ownership status history attachments permission", "Primero incident model incidents controller"],
        PRIMERO,
        PRIMERO_SHA,
        ["app/models/incident.rb:L3-L87", "app/models/incident.rb:L93-L178", "app/controllers/api/v2/incidents_controller.rb:L3-L31", "app/controllers/api/v2/concerns/record.rb:L34-L52,L202-L217"],
        "A persisted, historical, ownable, attachable and access-loggable incident model linked to a case, with incident identifiers/details/status/owner, defaults, derived dates, case history, update behavior and permission-gated creation.",
        "Child-protection incident model, not Oblivion NZ supported-living classification, severity, notifications, site/assignment privacy, close/reopen rules, UI or runtime acceptance.",
    ),
    candidate(
        "CAP-INC-INCIDENT-EVIDENCE-MANAGEMENT",
        "An authorised incident worker can attach, view, update and detach evidence bound to an owning incident/case record with record-level and attachment-level authorization and file metadata.",
        ["open source incident attachment create update delete download record permission", "Primero incident attachments controller"],
        PRIMERO,
        PRIMERO_SHA,
        ["app/models/incident.rb:L3-L23", "app/controllers/api/v2/attachments_controller.rb:L3-L112"],
        "Incident records are attachable; attachment endpoints separately authorize owning-record read/write and attachment read/create/write/destroy, persist metadata changes, bind attachment to the record, safely stream content, and reject immutable-field updates.",
        "Generic case attachment evidence only; does not prove Oblivion file limits, malware scanning, evidence sensitivity, audit-retention, download headers, deletion recovery, site privacy or runtime parity.",
    ),
    candidate(
        "CAP-INC-SAFEGUARDING-STATUS-CLOSURE",
        "An authorised safeguarding case worker can move a protected case through explicit workflow states, close it with closure date, and reopen it while preserving actor/time reopen history.",
        ["open source safeguarding case workflow close reopen actor timestamp", "Primero reopenable case workflow closure date"],
        PRIMERO,
        PRIMERO_SHA,
        ["app/models/child.rb:L20-L50", "app/models/child.rb:L53-L75", "app/models/concerns/reopenable.rb:L3-L60", "app/models/concerns/workflow.rb:L3-L59"],
        "Historical, ownable, access-loggable protected case with closure date; explicit new/assessment/case-plan/services/closed/reopened workflow; close stamps date; reopen changes status and records reopening actor/time.",
        "Primero case closure, not Oblivion concern closure prerequisites, triage substantiation, open-action/referral override, subject-informed warning, sensitivity/site permissions, notifications or runtime parity.",
    ),
    candidate(
        "CAP-INC-SAFEGUARDING-ACTION-PLAN",
        "An authorised safeguarding worker can record an intervention/action plan with responsible provider/contact, goal, due timeframe, completion state, approval status and persisted case linkage.",
        ["open source safeguarding case action plan owner goal due completion approval", "Primero case plan interventions approval task completion"],
        PRIMERO,
        PRIMERO_SHA,
        ["db/configuration/forms/case/cp_case_plan.rb:L3-L35", "db/configuration/forms/case/cp_case_plan.rb:L38-L100", "app/models/tasks/case_plan_task.rb:L5-L27", "app/models/child.rb:L53-L75"],
        "Case-plan interventions persist service/intervention, responsible provider/contact, goal, expected end date and successful-implementation state; case plan includes manager approval/date/comments/status, initiation date, a due task and completion field, bound to the case.",
        "Configurable child-protection case plan, not Oblivion discrete safeguarding action records, assignee identities, priorities, cancellation, completion actor/notes, concern closure gates, site privacy or runtime parity.",
    ),
    candidate(
        "CAP-CR-ALERT-TRIAGE",
        "An authorised control-room operator can take ownership of an operational alert, maintain priority and state, reject invalid or duplicate transitions, and preserve actor/cause/time evidence for triage handoff.",
        ["open source alert state timeline acknowledge assign severity transition audit", "OneUptime Alert AlertStateTimeline AlertStateTimelineService"],
        ONEUPTIME,
        ONEUPTIME_SHA,
        [
            "Common/Models/DatabaseModels/Alert.ts:L58-L123,L1320-L1505,L1630-L1697,L2180-L2281",
            "Common/Models/DatabaseModels/AlertStateTimeline.ts:L26-L86,L300-L638",
            "Common/Server/Services/AlertStateTimelineService.ts:L62-L215,L217-L280,L305-L461",
            "App/FeatureSet/Workers/Jobs/AlertOwners/SendStateChangeNotification.ts:L33-L63,L185-L200,L208-L317",
        ],
        "Permission-gated and audited alert records retain current severity/state; timeline records actor, state, cause and time; service validates references, rejects repeats/backward transitions, updates current state, writes the feed, and notifies alert owners with project-owner fallback.",
        "Configurable SRE alert states are not Oblivion's exact triage/assign/SLA graph and do not prove local site/role/ownership/privacy boundaries, resident context, UI or runtime acceptance.",
    ),
    candidate(
        "CAP-CR-ALERT-RESPONSE-CLOSURE",
        "An authorised control-room operator can progress an alert-like operational record through explicit states to a resolved terminal state while retaining actor/cause/time evidence and a controlled history.",
        ["open source alert resolved final state timeline transition history archive", "OneUptime alert resolution state timeline service"],
        ONEUPTIME,
        ONEUPTIME_SHA,
        [
            "Common/Models/DatabaseModels/Alert.ts:L1320-L1505",
            "Common/Models/DatabaseModels/AlertStateTimeline.ts:L300-L638",
            "Common/Server/Services/AlertStateTimelineService.ts:L30-L60,L62-L215,L305-L461,L494-L524",
        ],
        "Alert state history retains actor/cause/time; service validates and applies transitions, recognizes the resolved final state, writes operational feed/notification effects and archives the alert channel.",
        "OneUptime's configurable SRE resolved state is not proof of Oblivion's two-step resolve-to-close semantics, required notes, linked incidents, site scope, notifications, UI or runtime acceptance.",
    ),
    candidate(
        "CAP-PRIV-BREACH-RECORD-ASSESS",
        "An authorised privacy lead can record, assign, assess and progress a data breach with type, risk, affected subjects/data, notification state, remediation/evidence, investigation linkage and terminal closure.",
        ["open source privacy data breach record risk affected subjects notification remediation evidence", "CISO Assistant privacy breach model"],
        CISO,
        CISO_SHA,
        ["backend/privacy/models.py:L475-L565"],
        "Persisted breach model includes breach type, risk level, discovered/investigating/authority-notified/subjects-notified/closed states, assignees, discovery time, affected subject/data counts and links, authority/subject notification timestamps/reference, consequences, remediation controls, evidence and linked investigation.",
        "AGPL-3.0 community privacy data model only; enterprise-labelled files are excluded. No controller authorization, NZ Privacy Act/OPC deadline semantics, Oblivion permissions/site scope, notification delivery, validation, correction/reopen or runtime acceptance; benchmark behavior only, no reuse conclusion.",
    ),
    candidate(
        "CAP-PRIV-DSR-INTAKE-CASE-MANAGEMENT",
        "An authorised privacy lead can register and own a data-rights request, classify its right, track request and due dates, move it through active/on-hold/done states, retain observations, and link affected processing records.",
        ["open source data subject rights request case owner due date status processing records", "CISO Assistant privacy rights request model"],
        CISO,
        CISO_SHA,
        ["backend/privacy/models.py:L432-L472"],
        "Persisted rights-request case supports deletion, rectification, access, portability, restriction, objection and other types; new/in-progress/on-hold/done states; owner, requested and due dates, observation and linked processing records.",
        "AGPL-3.0 community data model only; enterprise-labelled files are excluded. No requester identity verification, deadline extension/refusal rules, client export/fulfilment, NZ statutory semantics, Oblivion direct-object/site privacy, controller permissions, UI or runtime acceptance; benchmark behavior only.",
    ),
]


evaluations.append(candidate(
    "CAP-CR-ESCALATION-LIFECYCLE",
    "An authorised operational-response actor can configure ordered escalation rules, wait to the next step, and inspect an alert/incident-linked execution timeline retaining recipient, state, acknowledgement and actor/time evidence.",
    ["open source on call escalation ordered rules wait recipient acknowledgement timeline", "OneUptime escalation rule execution log timeline self hosted"],
    ONEUPTIME,
    ONEUPTIME_SHA,
    [
        "Common/Models/DatabaseModels/OnCallDutyPolicyEscalationRule.ts:L23-L75,L158-L236,L245-L323,L459-L534",
        "Common/Models/DatabaseModels/OnCallDutyPolicyExecutionLogTimeline.ts:L35-L74,L480-L597,L614-L867,L884-L1010,L1045-L1080",
        "App/FeatureSet/Dashboard/src/Components/OnCallPolicy/ExecutionLogs/ExecutionLogsTimelineTable.tsx:L34-L55,L76-L116,L123-L264",
        "Common/Server/Types/Database/Permissions/BillingPermission.ts:L23-L98",
        "README.md:L73",
    ],
    "Permission-gated ordered escalation rules include wait-to-next-step behavior; execution records link alert/incident and rule, recipient user/team/schedule, status/message, acknowledgement/time, creator and override; the UI exposes the execution timeline and status.",
    "Apache-2.0 and free to self-host; the model carries a hosted Growth-plan decorator, but billing enforcement runs only when billing is enabled and a current plan exists. This does not prove Oblivion queue-to-queue entry/exit, escalation level/reason, claim/bulk actions, site/privacy semantics or runtime acceptance.",
))


keys = [row["working_key"] for row in evaluations]
candidate_keys = sorted(row["working_key"] for row in evaluations if row["candidate_status"] == "candidate_found_direct")
ncm_keys = sorted(row["working_key"] for row in evaluations if row["candidate_status"] == "documented_ncm_direct")
assert len(keys) == len(set(keys)) == 12
assert len(candidate_keys) == 12 and ncm_keys == []
assert all(key in manifest_by_key for key in keys)
assert all(row["completion_credit_recommended"] is True for row in evaluations)

repository_snapshots = {}
for row in evaluations:
    if row["candidate_status"] == "candidate_found_direct":
        benchmark = row["benchmark"]
        repository_snapshots[benchmark["official_repository_url"]] = {
            "url": benchmark["official_repository_url"],
            "commit_sha": benchmark["commit_sha"],
        }
    else:
        for rejected in row["rejected_repositories"]:
            repository_snapshots[rejected["official_repository_url"]] = {
                "url": rejected["official_repository_url"],
                "commit_sha": rejected["commit_sha"],
            }

artifact = {
    "schema_version": "1.0.0",
    "artifact": "benchmark-target-specific-adjudication-901-wave3",
    "generated_at": "2026-08-13T11:35:00+12:00",
    "audited_repository": "<local-user>/Herd\\oblivionfindings",
    "audited_commit": COMMIT,
    "read_only": True,
    "scope": "Third bounded target-specific wave: 12 current unproved targets selected for high-value identity, incident, safeguarding, control-room and privacy boundaries.",
    "methodology": {
        "credit_rule": "Only target-specific official repositories pinned to immutable commits and exact source loci proving a material same-target slice receive verified credit.",
        "ncm_rule": "A completed NCM must retain target-specific searches, exact inspected evidence and explicit rejected-repository reasons; paid-plan behavior cannot receive open-source benchmark credit.",
        "no_copy_rule": "Evidence is behavioural only; do not copy source, schema, UI, wording or distinctive layout.",
        "runtime_boundary": "No application tests, browser journeys, databases, queues, jobs, commits, pushes, deployments or product mutations were executed for this research wave.",
    },
    "input_pins": {
        "working_capability_manifest_901": {"path": "evidence/source/working-capability-manifest-901.json", "file_sha256": MANIFEST_SHA},
        "benchmark_final_901_before_wave": {"path": "evidence/source/benchmark-final-901-mapping.json", "file_sha256": BASE_MAPPING_SHA},
    },
    "repository_snapshots": dict(sorted(repository_snapshots.items())),
    "counts": {"evaluated": 12, "verified_benchmark_recommended": 12, "documented_ncm_recommended": 0, "completion_credit_recommended": 12, "remains_unproved": 0},
    "evaluations": evaluations,
    "integrity": {
        "evaluated_keys_unique": True,
        "evaluated_key_sha256": lines_sha(keys),
        "verified_key_sha256": lines_sha(candidate_keys),
        "ncm_key_sha256": lines_sha(ncm_keys),
        "candidate_rows_have_repo_sha_and_loci": all(
            row["benchmark"].get("official_repository_url")
            and len(row["benchmark"].get("commit_sha", "")) == 40
            and row["benchmark"].get("source_loci")
            and row.get("evidence_loci")
            for row in evaluations if row["candidate_status"] == "candidate_found_direct"
        ),
        "ncm_rows_have_searches_rejections_and_evidence": all(
            row.get("search_terms") and row.get("rejected_repositories") and row.get("evidence_loci")
            for row in evaluations if row["candidate_status"] == "documented_ncm_direct"
        ),
        "manifest_lineage_snapshots_exact": all(
            row["current_source_lineage"] == lineage(row["working_key"]) for row in evaluations
        ),
        "runtime_or_product_mutations": 0,
    },
    "completion_gate": {"complete": False, "reason": "Twelve rows are recommended for target-specific credit; 599/901 targets will remain completion-unproved after integration."},
}

OUTPUT.write_text(json.dumps(artifact, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(json.dumps({
    "path": str(OUTPUT),
    "sha256": sha(OUTPUT),
    "counts": artifact["counts"],
    "evaluated_key_sha256": artifact["integrity"]["evaluated_key_sha256"],
    "verified_key_sha256": artifact["integrity"]["verified_key_sha256"],
    "ncm_key_sha256": artifact["integrity"]["ncm_key_sha256"],
}, indent=2))
