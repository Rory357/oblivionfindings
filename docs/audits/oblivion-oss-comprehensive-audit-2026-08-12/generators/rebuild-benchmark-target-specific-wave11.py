#!/usr/bin/env python3
"""Build the independently corrected eleventh target-specific benchmark payload."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path


AUDIT = Path(__file__).resolve().parent.parent
SOURCE = AUDIT / "evidence" / "source"
MANIFEST_PATH = SOURCE / "working-capability-manifest-902.json"
MAPPING_PATH = SOURCE / "benchmark-final-902-mapping.json"
OUTPUT_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave11.json"
COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
GENERATED_AT = "2026-08-14T13:59:00+12:00"
PRE_WAVE_MAPPING_SHA = "3161a576cacec7ff4bd6a7202f9f1ad028d46b4446bd71287ac5dd05d213ae84"


def load(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8-sig"))


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


REPOS = {
    "Frappe": {
        "official_repository_url": "https://github.com/frappe/frappe",
        "commit_sha": "d739c8107310d53afdf6f160047157df593c5d7d",
        "spdx": "MIT",
        "license_locus": "LICENSE:L1-L20",
        "license_sha256": "bc6001a54ffcc4ab520424d7dbb85b293578efcdcb7d8f8055e00dddf942e5d7",
        "edition_boundary": "Pinned repository-native MIT framework source only; Frappe Cloud, paid support, ERPNext/private apps and proprietary extensions excluded.",
    },
    "Keycloak": {
        "official_repository_url": "https://github.com/keycloak/keycloak",
        "commit_sha": "b515f41a5c56936a4aa9a86e70f8479359aeccc4",
        "spdx": "Apache-2.0",
        "license_locus": "LICENSE.txt:L2-L4",
        "license_sha256": "cfc7749b96f63bd31c3c42b5c471bf756814053e847c10f3eb003417bc523d30",
        "edition_boundary": "Pinned official Apache-2.0 Keycloak repository only; managed services, downstream distributions, paid support and unpinned extensions excluded.",
    },
    "Mastodon": {
        "official_repository_url": "https://github.com/mastodon/mastodon",
        "commit_sha": "3f5c32f43f57751f994919335cf7b065c53044d1",
        "spdx": "AGPL-3.0-or-later",
        "license_locus": "package.json:L3",
        "license_sha256": "14e0aaaa85974a4622cc3fa7d3676ad967efe67de1537bac9eb19fd87e5eda6c",
        "edition_boundary": "Pinned official AGPL Mastodon server source only; managed hosting, third-party clients/plugins and unpinned branches excluded.",
    },
}


SOURCE_SLICE_REUSE_DISCLOSURE = [
    "No Wave-11 target key appears in Waves 4-10; there is no prior target-key reuse or inherited source-family credit.",
    "The same Frappe private-file slice intentionally informs four distinct download targets in Wave 11. Each row limits credit to generic private attached-file authorization, access logging and serving behavior and does not inherit domain-specific policy.",
    "CAP-DAY-NOTIFICATION-CENTRE reuses the pinned Mastodon notification and marker slice used by Wave-10 CAP-PORT-NOTIFICATION-CENTRE, with independent current-target lineage and explicit Frontline parity limits.",
    "CAP-PUB-CONTACT-LEAD-SUBMISSION reuses part of the pinned Frappe Web Form submission slice used by Wave-10 CAP-OPS-CUSTOM-FORM-SUBMISSION, with a narrower public-contact parity boundary.",
    "CAP-SET-PERSONAL-NOTIFICATION-PREFERENCES reuses Mastodon preference controller and base loci used by Wave-10 CAP-PORT-PREFERENCES and adds target-specific UserSettings notification and value-validation loci.",
    "CAP-PORT-EXTERNAL-IDENTITY-ADMISSION overlaps Wave-4 CAP-AUTH-STAFF-EXTERNAL-IDENTITY at broker login and callback loci but adds distinct first-broker-flow dispatch and post-success link, import and update loci.",
    "Keycloak UserResource appeared in Wave 6 at unrelated session-management lines; Wave 11 uses the distinct impersonation method at L369-L430.",
    "This reuse is disclosed because the audit prompt expressly allows one benchmark to inform several features; it does not confer mechanical completion credit.",
]


# key, expected prior status, repository, exact loci(path, lines, file SHA),
# neutral requirement, proven material slice, and conservative parity limits.
DIRECT = [
    ("CAP-CLI-CLIENT-DOCUMENT-STAFF-DOWNLOAD", "unproved_audit_assigned_id", "Frappe", [("frappe/core/doctype/file/file.py", "L953-L954,L1046-L1086", "fb3c35493c113a8ce7a1393d749f67e68845df455c7133a50af042f839e0b0be"), ("frappe/core/doctype/file/utils.py", "L476-L489", "4bf20f222ce653dec5b1837369b00657e51439d2713e5dcb5ce7d46486cae583"), ("frappe/utils/response.py", "L296-L308,L329-L357", "8acfbffd4103a09101469e2f884b402bcfb28fbe579c79c1dc065e44cf429311")],
     "Provide a staff-facing private client-document download path that authorizes the File and, where it is attached to a record, can derive access from read permission on that record before serving and access-logging the file.",
     "Frappe denies guests at the private-file endpoint, resolves matching File records, authorizes each through File read permission with attached-record read as one possible path, records successful access and serves the private file.",
     "Frappe also permits configured ignore-file-permission hooks, Administrator, File ownership and explicit File shares; its generic File logic does not prove Oblivion client ownership, Site/PHI concealment, category and retention rules, staff-role policy or immutable audit evidence."),
    ("CAP-CR-EVIDENCE-DOWNLOAD", "unproved", "Frappe", [("frappe/core/doctype/file/file.py", "L953-L954,L1046-L1086", "fb3c35493c113a8ce7a1393d749f67e68845df455c7133a50af042f839e0b0be"), ("frappe/core/doctype/file/utils.py", "L476-L489", "4bf20f222ce653dec5b1837369b00657e51439d2713e5dcb5ce7d46486cae583"), ("frappe/utils/response.py", "L296-L308,L329-L357", "8acfbffd4103a09101469e2f884b402bcfb28fbe579c79c1dc065e44cf429311")],
     "Serve retained private Control Room evidence through a permission-governed File path that can derive access from its authoritative attached record, records successful access and returns the file.",
     "Frappe denies guests at the private-file endpoint, resolves matching File records, authorizes each through File read permission with attached-record read as one possible path, records successful access and serves the private file.",
     "Frappe's alternative Administrator, owner, share and hook paths differ materially. This generic slice does not prove Oblivion alert/incident parent-first resolution, Site scope, evidence custody and sealing, malware handling, retention, concealment or immutable provenance."),
    ("CAP-DAY-NOTIFICATION-CENTRE", "unproved", "Mastodon", [("app/controllers/api/v1/notifications_controller.rb", "L3-L43,L47-L69,L75-L94", "47690bce1e0dbe51d0c73dbc34dff699796106d5dd648c8135d9b46dc8e49396"), ("app/controllers/api/v1/markers_controller.rb", "L3-L29,L38-L40", "e65aef9a9877f49028a765a75f6a7731d0981ddacd3e0cf67995ce9864bec7df")],
     "Present an authenticated recipient-scoped notification centre with pagination, unread count, individual access and dismissal, clear-all behavior, and a per-user last-read marker with conflict handling.",
     "Mastodon enforces authenticated read/write scopes, lists and paginates current-account notifications, reports unread count from a per-user marker, scopes individual access and dismissal to the current account, clears all current-account notifications, and transactionally updates per-user timeline markers with stale-write conflict reporting.",
     "Dismiss and clear delete notifications rather than setting Oblivion-style read flags, and the marker is a timeline-level last_read_id rather than a single-notification mark-read operation. This does not prove Oblivion announcement/task composition, Site scope, mandatory safety acknowledgement, delivery state, accessibility or exact read-state semantics."),
    ("CAP-FIN-AUDIT-EXPORT-DOWNLOAD", "unproved", "Frappe", [("frappe/core/doctype/file/file.py", "L953-L954,L1046-L1086", "fb3c35493c113a8ce7a1393d749f67e68845df455c7133a50af042f839e0b0be"), ("frappe/core/doctype/file/utils.py", "L476-L489", "4bf20f222ce653dec5b1837369b00657e51439d2713e5dcb5ce7d46486cae583"), ("frappe/utils/response.py", "L296-L308,L329-L357", "8acfbffd4103a09101469e2f884b402bcfb28fbe579c79c1dc065e44cf429311")],
     "Serve an already-generated private finance audit-export file after a governing File/read-permission check, record successful access and return the file.",
     "Frappe denies guests at the private-file endpoint, resolves matching File records, authorizes each through File read permission with attached-record read as one possible path, records successful access and serves the private file.",
     "Frappe's generic permission alternatives differ from Oblivion finance policy. This proves neither export generation nor finance-specific authority, Site scope, package signing, expiry, reconciliation integrity or immutable export evidence."),
    ("CAP-HR-PAYSLIP-ADMIN-DOWNLOAD", "unproved", "Frappe", [("frappe/core/doctype/file/file.py", "L953-L954,L1046-L1086", "fb3c35493c113a8ce7a1393d749f67e68845df455c7133a50af042f839e0b0be"), ("frappe/core/doctype/file/utils.py", "L476-L489", "4bf20f222ce653dec5b1837369b00657e51439d2713e5dcb5ce7d46486cae583"), ("frappe/utils/response.py", "L296-L308,L329-L357", "8acfbffd4103a09101469e2f884b402bcfb28fbe579c79c1dc065e44cf429311")],
     "Serve a private payslip file to a permitted non-guest caller after a governing File/read-permission check, record successful access and return the file.",
     "Frappe denies guests at the private-file endpoint, resolves matching File records, authorizes each through File read permission with attached-record read as one possible path, records successful access and serves the private file.",
     "The generic Frappe path does not establish an HR-administrator or employee-payslip policy and includes owner/share/Administrator/hook alternatives. It does not prove Oblivion HR privacy, employee/admin separation, Site scope, payroll/PDF production, bulk handling, retention or direct-object concealment."),
    ("CAP-PORT-EXTERNAL-IDENTITY-ADMISSION", "unproved", "Keycloak", [("services/src/main/java/org/keycloak/services/resources/IdentityBrokerService.java", "L394-L438,L699-L741,L757-L830,L849-L952", "38ae5e0861189b47393f4933306e9395b096880d6467cf498e9eb8f0927be153")],
     "Support redirect and callback through a configured external identity provider, route an unknown identity through a governed first-login create/link flow, validate successful completion, and then link or update the brokered user.",
     "Keycloak resolves a provider alias, dispatches broker login, preprocesses identity-provider mappers, routes an unknown identity into the first-broker-login flow, validates that flow's success before linking, and imports or updates the resulting brokered user.",
     "These loci dispatch and validate completion of Keycloak's first-broker flow; they do not themselves prove Oblivion portal-client ownership, approval policy, Site scope, secret management, invitation/family authority, rate limiting or equivalent user experience."),
    ("CAP-PUB-CONTACT-LEAD-SUBMISSION", "unproved", "Frappe", [("frappe/website/doctype/web_form/web_form.py", "L743-L773,L810-L838", "c2db5709bdd2b8848f9969792eca08ae96331551b28e8b46e4d4b06ccb441829")],
     "Accept a submission through a published guest-capable public form under a bounded web-form submission rate limit and persist a stable target record.",
     "Frappe exposes a guest-capable POST/PUT Web Form submission endpoint, applies a bounded web-form rate limit, rejects unpublished forms, enforces login and edit mode where configured, and persists new or authorised updated target records.",
     "The rate-limit key shown is the shared web_form key rather than proof of an independent limit per form. The previously cited validate_mandatory method is not invoked in this file, and file uploads or allow_incomplete can enable ignore_mandatory during insertion. This slice does not prove Oblivion contact schema, required-field policy, consent, CAPTCHA, duplicate detection, email/assignment, SLA, NZ privacy notice or UX."),
    ("CAP-SET-PERSONAL-APPEARANCE", "unproved", "Mastodon", [("app/controllers/settings/preferences/appearance_controller.rb", "L3-L8", "b93a2cc8de8e9d51e74cc8e3e15a7b61caf9804c08e518449b7a89f1b7c55dd7"), ("app/controllers/settings/preferences/base_controller.rb", "L3-L23", "4ecab965b2f80018823c1f690dc2607e090198c69d2e84ebbb4bf9d130c1891c"), ("app/models/user_settings.rb", "L12-L43,L66-L98", "4986d9f988d09fee23b5c7d37af27b6037b10ea1a9ebb6ff00537fb0068c4a50")],
     "Allow the current user to update a closed catalogue of personal appearance preferences including theme, reduced motion, media display, colour scheme and contrast, with typed or enumerated validation where defined.",
     "Mastodon updates current_user through the closed UserSettings.keys catalogue; UserSettings defines theme, reduced motion, media, colour-scheme and contrast preferences, typecasts submitted values, and rejects values outside enumerated sets where an enumeration exists.",
     "The controller permits the global UserSettings catalogue rather than an appearance-only subset, and theme itself has no explicit enumeration in the cited model. This does not prove Oblivion themes, branding separation, preview/reset behavior, transport, accessibility validation or role usability."),
    ("CAP-SET-PERSONAL-NOTIFICATION-PREFERENCES", "unproved", "Mastodon", [("app/controllers/settings/preferences/notifications_controller.rb", "L3-L8", "15dd069202d140d26e9a2d3601a211343b813679b8a7c1f3a722ed56a1042ede"), ("app/controllers/settings/preferences/base_controller.rb", "L3-L23", "4ecab965b2f80018823c1f690dc2607e090198c69d2e84ebbb4bf9d130c1891c"), ("app/models/user_settings.rb", "L10-L19,L45-L64,L66-L98", "4986d9f988d09fee23b5c7d37af27b6037b10ea1a9ebb6ff00537fb0068c4a50")],
     "Allow the current user to update defined personal email and interaction notification settings through a closed global settings catalogue with type and enumerated-value validation.",
     "Mastodon updates current_user through the closed global UserSettings.keys catalogue; that catalogue defines email and interaction notification settings, typecasts values, and rejects values outside enumerated sets where defined.",
     "The notification preferences endpoint is not restricted to notification-only keys; it permits the complete UserSettings key catalogue. The slice does not prove Oblivion push-subscription lifecycle, channel availability, quiet hours, mandatory safety defaults, escalation, Site scope or delivery evidence."),
    ("CAP-SET-SSO-CONFIG-OVERVIEW", "unproved", "Keycloak", [("services/src/main/java/org/keycloak/services/resources/admin/IdentityProvidersResource.java", "L216-L252,L271-L307", "d8c5ffdb176e16a8a01950cecfd0e80704bdac8cf6fcbe1b49336acc19c5cf1d"), ("services/src/main/java/org/keycloak/services/resources/admin/IdentityProviderResource.java", "L112-L142,L166-L212", "253bc318f1ce2c5717a96949a27445487a1eead717de4f48470fd6cf206f399e")],
     "Allow authorised settings administrators to list and inspect configured SSO identity providers under view authority while reserving create, update and delete actions to manage authority and redacting secrets from read representations.",
     "Keycloak requires view-identity-provider authority for list and retrieval, strips secrets from read representations, and requires manage-identity-provider authority for create, update and delete operations while recording successful administrative mutations.",
     "Does not prove Oblivion terminology, secret encryption at rest, connection testing, Site policy, role/group mapping, approval, rollback, UI behavior or immutable audit evidence."),
    ("CAP-SET-SSO-GROUP-MAPPING", "unproved", "Keycloak", [("services/src/main/java/org/keycloak/services/resources/admin/IdentityProviderResource.java", "L259-L340,L347-L418", "253bc318f1ce2c5717a96949a27445487a1eead717de4f48470fd6cf206f399e"), ("services/src/main/java/org/keycloak/broker/oidc/mappers/AdvancedClaimToGroupMapper.java", "L41-L64,L101-L116", "603ab03f18ddef9cf5a43441ecc66a657e5d0ec549a5ef54a8cdb718406a127a"), ("services/src/main/java/org/keycloak/broker/oidc/mappers/AbstractClaimToGroupMapper.java", "L34-L65", "228336ccff4e3eb65e3e16da285030decc0c1f36c74e6f18194b6e2f40b71aee")],
     "Allow authorised administrators to govern external-claim-to-local-group mappings and apply qualifying mappings when brokered users are imported or subsequently updated.",
     "Keycloak separates view and manage authority for identity-provider mapper list/create/update/delete operations, defines claim and target-group mapping configuration, evaluates literal or regex claim values, joins qualifying imported users to the configured group, and joins or removes the group during brokered-user updates.",
     "Does not prove Oblivion Site membership, role-to-permission consequences, deny precedence, nested claims beyond the cited mapper, review/simulation, rollback or equivalent claim vocabulary."),
    ("CAP-SET-USER-IMPERSONATION", "unproved", "Keycloak", [("services/src/main/java/org/keycloak/services/resources/admin/UserResource.java", "L369-L430", "33e2d68baca90dd1fb909e0118809d0ecd133d10fc08afddf83b21e40d9238bb")],
     "Allow an authorised administrator to impersonate an enabled non-service user while retaining impersonator provenance in the resulting session and security event.",
     "Keycloak requires the impersonation feature and user-specific impersonation authority, rejects disabled users and service accounts, creates the target-user session, stores impersonator ID and username in session notes, emits an impersonation event and returns the account redirect.",
     "Does not prove Oblivion reason capture, reauthentication, duration, view-only mode, Site restrictions, user notice, action suppression or immutable audit retention."),
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
require(len(keys) == len(set(keys)) == 12, "Wave-11 keys are not 12 unique targets")
keys_sha = hashlib.sha256("\n".join(sorted(keys)).encode()).hexdigest()
require(keys_sha == "660e1e589b2ae14a501a6b43eb210e8fe8b7bfd7d3ff710bc99f236465bb6f8e", "Wave-11 key SHA drift")

evaluations = []
for key, prior_status, repo_name, loci, neutral, proven, limits in DIRECT:
    identity = manifest_by_key[key]
    prior = mapping_by_key[key]
    require(prior.get("status") == prior_status, f"Prior status drift: {key}")
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
        "prior_status": prior_status,
        "candidate_status": "candidate_found_direct",
        "completion_credit_recommended": True,
        "neutral_requirement": neutral,
        "current_source_lineage": lineage,
        "benchmark": {**repo, "repo": repo_name, "exact_loci": exact_loci, "proven_slice": proven, "parity_limits": limits, "p6_caveats": "Benchmark-only behavior; do not copy source, schema, labels, layouts or product wording."},
        "evidence_loci": evidence_loci,
    })

lineage_lines = sorted("|".join((row["working_key"], row["prior_status"], ";".join(sorted(row["current_source_lineage"]["route_ids"])), ";".join(sorted(row["current_source_lineage"]["page_ids"])), ";".join(sorted(row["current_source_lineage"]["backend_anchors"])))) for row in evaluations)
lineage_sha = hashlib.sha256("\n".join(lineage_lines).encode()).hexdigest()
require(lineage_sha == "f0ff1bfaf9dbc1eea1cdcd4c87edd80ec308d081f397bbc389ecbeb210cc9bdc", "Wave-11 lineage SHA drift")

artifact = {
    "schema_version": "1.0.0",
    "artifact": "benchmark-target-specific-adjudication-902-wave11",
    "generated_at": GENERATED_AT,
    "audited_commit": COMMIT,
    "read_only": True,
    "scope": "Eleventh bounded target-specific wave: 12 current unique completion-unproved targets, with no prior target-key reuse and explicitly disclosed source-slice reuse.",
    "methodology": {
        "credit_rule": "Only target-specific official repository-native source pinned to an immutable commit, with exact file hashes and loci proving a material same-target slice, receives credit.",
        "licence_rule": "Only cited community source is credited; hosted, paid, enterprise, private and unpinned behavior is excluded.",
        "no_copy_rule": "Evidence is behavioural only; do not copy source, schema, UI, wording or distinctive layouts.",
        "family_credit_inherited": False,
        "runtime_boundary": "No application, browser, database, deployment or Git state was changed.",
    },
    "source_slice_reuse_disclosure": SOURCE_SLICE_REUSE_DISCLOSURE,
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
