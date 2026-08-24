#!/usr/bin/env python3
"""Build the tenth target-specific benchmark research payload."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path


AUDIT = Path(__file__).resolve().parent.parent
SOURCE = AUDIT / "evidence" / "source"
MANIFEST_PATH = SOURCE / "working-capability-manifest-902.json"
MAPPING_PATH = SOURCE / "benchmark-final-902-mapping.json"
OUTPUT_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave10.json"
COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
GENERATED_AT = "2026-08-14T11:50:48+12:00"
PRE_WAVE_MAPPING_SHA = "cfed6aeea4dcac5a132ca6d1b066652f0d6345d541d33dbe8908a0252150a7c2"


def load(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8-sig"))


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


REPOS = {
    "GLPI": {
        "official_repository_url": "https://github.com/glpi-project/glpi",
        "commit_sha": "9d56231da7e0dc8cfc1a8a6844bf52e839cbf1be",
        "spdx": "GPL-3.0-or-later",
        "license_locus": "composer.json:L4",
        "license_sha256": "b366d90fc8952396a9b2b091c02ad0070c381365cad1effa4e50f855ed159b24",
        "edition_boundary": "Pinned official GLPI community repository only; hosted services, marketplace plugins, paid support, private plugins and unpinned branches excluded.",
    },
    "Mastodon": {
        "official_repository_url": "https://github.com/mastodon/mastodon",
        "commit_sha": "3f5c32f43f57751f994919335cf7b065c53044d1",
        "spdx": "AGPL-3.0-or-later",
        "license_locus": "package.json:L3",
        "license_sha256": "14e0aaaa85974a4622cc3fa7d3676ad967efe67de1537bac9eb19fd87e5eda6c",
        "edition_boundary": "Pinned official AGPL Mastodon server source only; managed hosting, third-party clients/plugins and unpinned branches excluded.",
    },
    "Frappe": {
        "official_repository_url": "https://github.com/frappe/frappe",
        "commit_sha": "d739c8107310d53afdf6f160047157df593c5d7d",
        "spdx": "MIT",
        "license_locus": "LICENSE:L1-L20",
        "license_sha256": "bc6001a54ffcc4ab520424d7dbb85b293578efcdcb7d8f8055e00dddf942e5d7",
        "edition_boundary": "Pinned repository-native MIT framework source only; Frappe Cloud, paid support, ERPNext/private apps and proprietary extensions excluded.",
    },
    "OpenProject": {
        "official_repository_url": "https://github.com/opf/openproject",
        "commit_sha": "d5fa0433dce7f3edd48d0120736ac844fe3748d9",
        "spdx": "GPL-3.0-or-later",
        "license_locus": "publiccode.yml:L55",
        "license_sha256": "3802de5be385f9de812523fbd963f2e1a7f9abbc41c0f525e1103bc6b9255da5",
        "edition_boundary": "Pinned OpenProject community-core loci only; Enterprise add-ons, hosted-only behavior, paid support and unpinned branches excluded.",
    },
}


# key, repository, exact loci(path, lines, file SHA), neutral requirement,
# proven material slice, and conservative parity limits.
DIRECT = [
    ("CAP-IT-SUPPORT-TICKET", "GLPI",
     [("src/Ticket.php", "L59-L63,L95-L101,L210-L213,L314-L318,L598-L603,L1582-L1587", "ba2d26b5ddce3fcf3e34af323dfddcb37ddfb42d3b63c0e1298b57417d60d89f"), ("front/ticket.form.php", "L45-L50,L77-L90,L132-L160", "aa35e262b5e51370976c670613e20af73adce775ba96eeb1b64442cbe92c4b6c")],
     "Receive, classify, permission-gate, update and resolve support tickets through explicit states while retaining a stable ticket record.",
     "GLPI distinguishes incident and request tickets, checks entity/right access, and supports authorised add, update, delete/restore and status-managed ticket processing.",
     "Does not prove Oblivion Site scoping, provisioning linkage, SLA design, assignment notifications, attachments, resolution evidence or direct-object concealment."),
    ("CAP-AUTH-PUBLIC-REGISTRATION-PENDING-APPROVAL", "Mastodon",
     [("app/controllers/auth/registrations_controller.rb", "L9-L16,L30-L32", "db3ca6051cdc2faa21c3cb2c62896b2541c96f96393c0c7e6290cb581c5a166f"), ("app/models/user.rb", "L105-L118,L217-L249,L417-L456,L480-L485,L503-L508", "970eaad1dbacf3c07bb19397b1c2bfab0b0ab0fafc79a017bbc5b2611803183c"), ("app/controllers/admin/accounts_controller.rb", "L4-L12,L58-L69", "9ef868bc7b3a997c1518fcdb1e89efe54bbb8b6346c8f0d274be67ee9b3f0aed")],
     "Permit public registration while holding qualifying accounts in a non-active pending state until an authorised administrator approves or rejects them.",
     "Mastodon persists approved/pending state, prevents pending accounts from becoming fully functional, notifies authorised staff, and exposes explicit approve/reject actions.",
     "Does not prove Oblivion role assignment, Clinical Lead creation, Site membership, invite policy, identity proofing, email delivery, rate limits or Fortify integration."),
    ("CAP-PORT-NOTIFICATION-CENTRE", "Mastodon",
     [("app/controllers/api/v1/notifications_controller.rb", "L3-L19,L22-L42,L47-L64", "47690bce1e0dbe51d0c73dbc34dff699796106d5dd648c8135d9b46dc8e49396"), ("app/controllers/api/v1/markers_controller.rb", "L3-L29,L38-L40", "e65aef9a9877f49028a765a75f6a7731d0981ddacd3e0cf67995ce9864bec7df")],
     "Present an authenticated recipient-scoped notification centre with pagination, unread state and explicit single/all read-state mutation.",
     "Mastodon exposes authenticated, scope-authorised notification listing, unread counts, individual access, clearing/dismissal and transactional per-user read markers with conflict reporting.",
     "Marker semantics differ from Oblivion read flags; does not prove portal-client ownership, Site privacy, delivery channels, notification templates, accessibility or family-account direct-object denial."),
    ("CAP-PORT-PREFERENCES", "Mastodon",
     [("app/controllers/settings/preferences/base_controller.rb", "L3-L12,L21-L23", "4ecab965b2f80018823c1f690dc2607e090198c69d2e84ebbb4bf9d130c1891c"), ("app/controllers/settings/preferences/notifications_controller.rb", "L3-L8", "15dd069202d140d26e9a2d3601a211343b813679b8a7c1f3a722ed56a1042ede"), ("app/controllers/api/v1/preferences_controller.rb", "L3-L9", "948eb69e0f1471c7fb3e30026920449368c7d6cb55b128981641fc3044729917"), ("app/serializers/rest/preferences_serializer.rb", "L3-L11,L13-L39", "3beca7efe9c16a0ae27abd27b48596ff5242a6672163f467baecd9c3f3583088")],
     "Allow an authenticated portal user to view and update only an allowlisted set of personal display, locale and notification preferences with validation feedback.",
     "Mastodon uses an authenticated preference owner, a narrow permitted parameter set, validation-preserving update flow and a stable read projection for personal preferences.",
     "Preference vocabulary is social-network-specific and does not prove Oblivion family communication consent, accessibility controls, channel verification, quiet hours or portal ownership boundaries."),
    ("CAP-OPS-CUSTOM-FORM-DEFINITION", "Frappe",
     [("frappe/website/doctype/web_form/web_form.json", "L88-L93,L117-L135,L184-L190", "4546876e3f44a0a97bc14c32cfd712b6c36f0e8fdd456b19b32c30b23644022c"), ("frappe/website/doctype/web_form/web_form.py", "L31-L50,L90-L118,L129-L148,L248-L269", "c2db5709bdd2b8848f9969792eca08ae96331551b28e8b46e4d4b06ccb441829")],
     "Define and govern a reusable form schema, including target record type, ordered fields, requiredness and authenticated/editability behavior.",
     "Frappe Web Form definitions bind to a DocType, own an ordered field table, validate field presence and hidden/mandatory conflicts, and configure login, edit and multiple-response behavior.",
     "Does not prove Oblivion supported field catalogue, versioning, publication, Site assignment, form retirement, audit evidence, conditional logic or existing-submission migration."),
    ("CAP-OPS-CUSTOM-FORM-SUBMISSION", "Frappe",
     [("frappe/website/doctype/web_form/web_form.py", "L644-L649,L743-L773,L810-L817,L829-L836,L1024-L1031", "c2db5709bdd2b8848f9969792eca08ae96331551b28e8b46e4d4b06ccb441829")],
     "Render a governed form definition, validate submitted values and persist a new or authorised edited response without bypassing the form's access rules.",
     "Frappe exposes form-data retrieval and rate-limited create/update submission, validates mandatory values, rejects unauthorised edits, and distinguishes request-key versus role-permission persistence.",
     "Does not prove Oblivion Site/client ownership, signature/evidence fields, draft recovery, duplicate/idempotency handling, offline capture, submission exports or representative-role UX."),
    ("CAP-HR-APPROVAL-CHAIN-CONFIG", "Frappe",
     [("frappe/workflow/doctype/workflow/workflow.json", "L58-L63,L77-L103", "fceb3601e69920096b96768a4f98dc61ae6d61d29cb35c718f3095b238043247"), ("frappe/workflow/doctype/workflow_transition/workflow_transition.json", "L4-L20,L22-L68,L71-L108", "4d67e464525371fb3bb707a52665c8781db6add1b4b54b3a5e1c8d5c226436ae"), ("frappe/workflow/doctype/workflow/workflow.py", "L11-L42,L88-L93", "261d1ec4632bc0d2c904bfd3738222004a5e3870c502dd767370921321bcd43f")],
     "Configure an approval chain as explicit states and role-authorised transitions, including next state, conditions and self-approval policy.",
     "Frappe workflows define document states and transitions with explicit actions, next states, allowed roles, conditions, self-approval configuration and an authoritative workflow-state field.",
     "Does not prove Oblivion HR object coverage, ordered multi-approver quorum, delegation, effective dating, Site scope, chain amendment/versioning or maker-checker defaults."),
    ("CAP-HR-APPROVAL-INSTANCE-DECISION", "Frappe",
     [("frappe/workflow/doctype/workflow_action/workflow_action.py", "L44-L48,L81-L87,L132-L166,L244-L250,L291-L315,L376-L390", "b05ab7748187f2fe1310a63fd915c939a45ff3511afa60eb67e6671c71d4299d"), ("frappe/model/workflow.py", "L43-L49,L118-L129,L205-L223,L288-L302", "726d9c9225a68e24809f635867d61b969d20e3c932f423349b0eb2e16c760934")],
     "Present pending decisions only to authorised approver roles and apply a valid decision to the current record state while preserving actor and completion provenance.",
     "Frappe creates role-scoped open Workflow Actions, filters pending actions by allowed role, verifies current transitions, applies the selected action, and records completed-by actor and role.",
     "Does not prove Oblivion HR request types, Site scope, sequential/quorum approval, delegation, rejection reasons, stale-version UX, notification recovery or immutable audit storage."),
    ("CAP-GOV-ACTION-TRACKING-MANAGEMENT", "OpenProject",
     [("lib/api/v3/work_packages/work_package_representer.rb", "L362-L376,L468-L470,L504-L510,L560-L578", "2c26dea717d1e659d99fea4fa5923b3046048cdd11f77bc227e06ef5d9cc66dd"), ("app/services/work_packages/create_service.rb", "L31-L50,L87-L93,L112-L115", "2e3a5e38e0224a8721483eb6401fb9feb3cedafee10c3244d8279fb163b2dbb4"), ("app/contracts/work_packages/update_contract.rb", "L31-L42,L62-L80", "8fa1c09c9e4db094d613aa57bf689642e6de9bb18a9c3d9b41b4440b1e3e2467"), ("app/services/work_packages/update_service.rb", "L31-L55,L57-L75", "d1fbeff98e8ef5825fa86094c892de7a4cc2cca5927c68ae799ad5d83792509a")],
     "Maintain an authorised action/work-item register with subject, description, project/context, owner, priority, schedule, status and percentage progress.",
     "OpenProject community work packages expose subject, description, dates, percentage progress, project, type, priority, status and assignee, with contract-authorised creation and update services.",
     "Does not prove Oblivion governance provenance, blocking/unblocking semantics, escalation rules, evidence links, committee ownership, Site visibility, overdue alerts or direct-object concealment."),
    ("CAP-GOV-ACTION-COMPLETION", "OpenProject",
     [("app/models/work_package.rb", "L57-L64,L103-L110,L274-L281,L308-L312", "fe6207cbd7edc33fdcbfce9e7da247c6bcf20bbbb0f7e33c8d4150abfb7aefa7"), ("app/forms/statuses/form.rb", "L49-L68", "4ec02dd26beaa3b2fb44472605fc8a60f592525ff3925fea1abcaceb85bd8b08"), ("lib/api/v3/statuses/status_representer.rb", "L37-L47", "4e06aa141238a435e98ec4b3d2ebe7ef577e60e6c77f42a07b96917d78e0cbb6"), ("app/contracts/work_packages/update_contract.rb", "L31-L42,L62-L80", "8fa1c09c9e4db094d613aa57bf689642e6de9bb18a9c3d9b41b4440b1e3e2467")],
     "Complete an authorised action through a governed terminal status and represent closed/open state and completion progress consistently.",
     "OpenProject statuses explicitly carry closed state and default completion ratio; work packages expose open/closed scopes, terminal-state interpretation and permission/locking-aware updates.",
     "Does not prove Oblivion completion evidence, completion reason, blocked prerequisites, maker-checker separation, reopen policy, immutable completion event or committee sign-off."),
    ("CAP-SET-PERMISSION-MATRIX", "Frappe",
     [("frappe/core/page/permission_manager/permission_manager.py", "L30-L76,L80-L102,L116-L159,L162-L185", "35e61c517d5eeb9132f2ce0bbff56a4abf76612b246dcbac0c902282934e64f8"), ("frappe/core/page/permission_manager/permission_manager.js", "L1-L22,L64-L74,L454-L478,L500-L550,L565-L576", "97c6d73b119f55546bdb2cfdeedeb0274a5912ad1dcdacf8429ddf21aba2ff7f")],
     "Present and administer a role-by-resource permission matrix with explicit permission types and permission levels, restricted to authorised access administrators.",
     "Frappe's System-Manager-only permission manager reads role/DocType rules, presents permission types and levels, and supports explicit add, update, remove and reset operations.",
     "Does not prove Oblivion permission vocabulary, route-policy parity, Site scope, effective access calculation, deny precedence, impersonation behavior, change approval or regression analysis."),
    ("CAP-SET-PERMISSION-OVERRIDES", "Frappe",
     [("frappe/core/doctype/user_permission/user_permission.json", "L20-L53,L66-L73", "522034601b85231aecc97b976c34043edfc0e16ed57cd6564319729805e4fa3c"), ("frappe/core/doctype/user_permission/user_permission.py", "L15-L45,L47-L70", "b0b936edcf0870fcef7f33385126752869dc44cf4e6f10f51fb000edda458a89"), ("frappe/permissions.py", "L287-L353,L671-L708,L753-L758", "93c90a4c7d4a9f21983a0fe3037ac93223f5e8398bda86d6e73f5ca9453b639f")],
     "Maintain explicit user/resource permission overrides with a constrained resource value, optional target scope, duplicate protection and immediate access-cache invalidation.",
     "Frappe User Permission records bind a user to an allowed DocType/value and optional applicable resource, validate duplicates/default overlap, and invalidate cached permissions on update or deletion.",
     "Frappe overrides are allow-oriented and DocType-based; this does not prove Oblivion allow/deny precedence, Site inheritance, expiry, reason/evidence, approval, bulk assignment or complete effective-access simulation."),
]


manifest = load(MANIFEST_PATH)
mapping = load(MAPPING_PATH)
require(manifest.get("audited_commit") == COMMIT, "Manifest commit mismatch")
require(mapping.get("audited_commit") == COMMIT, "Mapping commit mismatch")
require(sha(MAPPING_PATH) == PRE_WAVE_MAPPING_SHA, "Pre-wave mapping SHA mismatch")
manifest_by_key = {row["working_key"]: row for row in manifest["targets"]}
mapping_by_key = {row["working_key"]: row for row in mapping["targets"]}
require(len(manifest_by_key) == len(mapping_by_key) == 902, "Target identity count mismatch")

keys = [row[0] for row in DIRECT]
require(len(keys) == len(set(keys)) == 12, "Wave-10 keys are not 12 unique targets")
keys_sha = hashlib.sha256("\n".join(sorted(keys)).encode()).hexdigest()
require(keys_sha == "86484e9b3e464feb1d9269c219692ac5e4bc27f39a20f60d0a9b8da998707c73", "Wave-10 key SHA drift")

evaluations = []
for key, repo_name, loci, neutral, proven, limits in DIRECT:
    identity = manifest_by_key[key]
    prior = mapping_by_key[key]
    require(prior.get("status") == "unproved", f"Prior status drift: {key}")
    require(prior.get("completion_credit") is False, f"Target already credited: {key}")
    repo = REPOS[repo_name]
    exact_loci = []
    evidence_loci = []
    for path, lines, file_sha in loci:
        url = f"{repo['official_repository_url']}/blob/{repo['commit_sha']}/{path}"
        exact_loci.append({"path": path, "lines": lines, "sha256": file_sha, "primary_source_url": url})
        evidence_loci.append(f"{repo['official_repository_url']}@{repo['commit_sha']} :: {path} :: {lines} :: sha256={file_sha}")
    lineage = {name: identity.get(name, []) for name in ("source_family_ids", "route_ids", "page_ids", "backend_anchors")}
    lineage.update({name: identity.get(name) for name in ("id_status", "class", "canonical_module")})
    evaluations.append({
        "working_key": key,
        "prior_status": "unproved",
        "candidate_status": "candidate_found_direct",
        "completion_credit_recommended": True,
        "neutral_requirement": neutral,
        "current_source_lineage": lineage,
        "benchmark": {**repo, "repo": repo_name, "exact_loci": exact_loci, "proven_slice": proven, "parity_limits": limits, "p6_caveats": "Benchmark-only behavior; do not copy source, schema, labels, layouts or product wording."},
        "evidence_loci": evidence_loci,
    })

lineage_lines = sorted("|".join((row["working_key"], row["prior_status"], ";".join(sorted(row["current_source_lineage"]["route_ids"])), ";".join(sorted(row["current_source_lineage"]["page_ids"])), ";".join(sorted(row["current_source_lineage"]["backend_anchors"])))) for row in evaluations)
lineage_sha = hashlib.sha256("\n".join(lineage_lines).encode()).hexdigest()
require(lineage_sha == "06f7beab9c13df8423a775d03c45a487bfb571558abf66cb35619d5b9134912b", "Wave-10 lineage SHA drift")

artifact = {
    "schema_version": "1.0.0",
    "artifact": "benchmark-target-specific-adjudication-902-wave10",
    "generated_at": GENERATED_AT,
    "audited_commit": COMMIT,
    "read_only": True,
    "scope": "Tenth bounded target-specific wave: 12 current unique completion-unproved targets, with no prior-wave reuse or inherited family credit.",
    "methodology": {
        "credit_rule": "Only target-specific official repository-native source pinned to an immutable commit, with exact file hashes and loci proving a material same-target slice, receives credit.",
        "licence_rule": "Only cited community source is credited; hosted, paid, enterprise, private and unpinned behavior is excluded.",
        "no_copy_rule": "Evidence is behavioural only; do not copy source, schema, UI, wording or distinctive layouts.",
        "family_credit_inherited": False,
        "runtime_boundary": "No application, browser, database, deployment or Git state was changed.",
    },
    "input_pins": {
        "working_capability_manifest_902": {"path": "evidence/source/working-capability-manifest-902.json", "file_sha256": sha(MANIFEST_PATH)},
        "benchmark_final_902_before_wave": {"path": "evidence/source/benchmark-final-902-mapping.json", "file_sha256": sha(MAPPING_PATH)},
    },
    "repository_snapshots": REPOS,
    "counts": {"evaluated": 12, "verified_benchmark_direct_recommended": 12, "documented_ncm_direct_recommended": 0, "completion_credit_recommended": 12},
    "selected_keys_sha256": keys_sha,
    "selected_lineage_tuple_sha256": lineage_sha,
    "evaluations": evaluations,
    "projected_delta": {"verified_benchmark_direct": 12, "eligible_total": 12, "completion_unproved": -12},
}
OUTPUT_PATH.write_text(json.dumps(artifact, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(json.dumps({"output": str(OUTPUT_PATH), "sha256": sha(OUTPUT_PATH), "evaluated": 12, "direct": 12}, indent=2))
