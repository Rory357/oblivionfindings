# Consent Requests

## What it is

A staff-initiated, family-portal-mediated workflow for capturing informed consent (or substituted consent) before an action that requires it. Staff compose a Right-7 disclosure on the client profile, pick a portal-linked recipient (welfare guardian, EPOA, parent/guardian, court-appointed, next-of-kin, or the client themselves), and send it. The recipient reviews the disclosure in the family portal and either approves or declines. On approval, a [`ClientConsent`](../app/Models/ClientConsent.php) row is materialised with `evidence_type='portal_signature'` and the originating draft (currently a [`DeviceAssignment`](../app/Domain/SecurityDevices/Models/DeviceAssignment.php)) can proceed.

## Compliance frame

- **Privacy Act 2020** — IPP 3 (collection from the individual) and IPP 10 (use limits) are honoured by capturing `purpose`, `data_scope`, and `retention_period_days` per request.
- **Health Information Privacy Code 2020 (HIPC)** — Rule 11 disclosures require an authority basis; that basis is recorded in `recipient_relationship` and resolved by `ConsentRequest::authorityToConsent()`.
- **HDC Code of Rights, Right 7** — informed consent. Right 7(1) elements (purpose, alternatives, withdrawal) are mandatory `purpose` + optional `withdrawal_method_text` fields.
- **HDC Right 7(4)** — best-interests / least-restrictive. When the recipient is a substituted decision-maker, [`ConsentRequestService::materialiseClientConsent()`](../app/Services/ConsentRequestService.php) sets `best_interests_decision=true` and copies `least_restrictive_justification` into `best_interests_rationale`.
- **PPPR Act 1988** — substituted consent authority for welfare guardians and EPOA (personal care & welfare). Enforced by [`ConsentRequest::AUTHORISED_SUBSTITUTE_RELATIONS`](../app/Models/ConsentRequest.php).
- **CRPD Article 12** — supported decision-making. The portal UI shows the unmodified Right-7 disclosure to the recipient regardless of their authority class.

## Schema (`consent_requests`)

See [`2026_04_19_000001_create_consent_requests_table.php`](../database/migrations/2026_04_19_000001_create_consent_requests_table.php).

| Column | Purpose |
| --- | --- |
| `client_id` | Client the consent is for. |
| `consent_type_id` | FK to `consent_types`; drives validity period when materialised. |
| `requested_by_user_id` | Staff member who composed the ask. |
| `recipient_user_id` | Family-portal user who must respond. |
| `recipient_relationship` | Snapshot of the relation at request time (survives pivot churn). |
| `triggering_subject_type` / `triggering_subject_id` | Polymorphic pointer to the draft entity (v1: `DeviceAssignment`). Nullable for standalone consents. |
| `purpose` | Right-7(1)(b) — why we need it. Required. |
| `least_restrictive_justification` | Right-7(4) rationale; copied into `client_consents.best_interests_rationale` for substitute decisions. |
| `data_scope` | What data is touched. |
| `retention_period_days` | How long it's kept. |
| `withdrawal_method_text` | Plain-English instructions for the recipient. |
| `staff_notes` | Internal-only notes; never shown to recipient. |
| `status` | enum: `pending` \| `approved` \| `declined` \| `cancelled` \| `expired`. |
| `sent_at` / `viewed_at` / `responded_at` / `expires_at` | Lifecycle timestamps. Default validity is 14 days from creation. |
| `response_notes` / `response_ip_address` / `response_user_agent` | Captured on approve/decline for evidentiary trail. |
| `resulting_consent_id` | FK to the `ClientConsent` row spawned on approval. |
| `cancelled_by_user_id` / `cancellation_reason` | Set when staff withdraws the ask before response. |
| `audit_trail` | Append-only JSON `[{event, actor_id, at, meta}, ...]`. Events: `created`, `viewed`, `approved`, `declined`, `cancelled`, `expired`, `reminder_sent`. |

## State machine

All transitions go through [`ConsentRequestService`](../app/Services/ConsentRequestService.php) so that audit trail, notifications, and the materialised `ClientConsent` stay in lockstep.

- `pending` (initial; set by `create()`) → emits [`ConsentRequestCreatedNotification`](../app/Notifications/Operations/ConsentRequestCreatedNotification.php) to the recipient.
- `pending` → `pending` (`markViewed()` records `viewed_at` once on first portal page view; no notification).
- `pending` → `approved` (`approve()`) → writes `ClientConsent`, links via `resulting_consent_id`, emits [`ConsentRequestRespondedNotification`](../app/Notifications/Operations/ConsentRequestRespondedNotification.php) to the requester.
- `pending` → `declined` (`decline()`) → emits `ConsentRequestRespondedNotification` to the requester. No `ClientConsent` row is written.
- `pending` → `cancelled` (`cancel()`, staff-initiated, requires reason).
- `pending` → `expired` (`expireStale()` for any `pending` row past `expires_at`; idempotent bulk job; no notification).
- `pending` (intermediate) → emits [`ConsentRequestReminderNotification`](../app/Notifications/Operations/ConsentRequestReminderNotification.php) when 24-72h remain. Idempotent via `reminder_sent` audit event.

`approve` and `decline` both assert the request is actionable (pending and not past expiry) and that the caller is the designated recipient.

## Authority model

`recipient_relationship` is a snapshotted string. The model classifies it via [`ConsentRequest::authorityToConsent()`](../app/Models/ConsentRequest.php):

| Relationship constant | Authority class | Notes |
| --- | --- | --- |
| `RELATION_WELFARE_GUARDIAN` | `substitute` | PPPR Act 1988. |
| `RELATION_EPOA_PERSONAL_CARE` | `substitute` | PPPR Act 1988 EPOA — personal care & welfare. |
| `RELATION_PARENT_GUARDIAN` | `substitute` | For clients under 16. |
| `RELATION_COURT_APPOINTED` | `substitute` | Court order. |
| `RELATION_SELF` | `self` | The client themselves where capacity exists. |
| `RELATION_NEXT_OF_KIN` | `informational_only` | Can be informed but is **not** authority to consent on its own. |

The four `substitute` values are the canonical list at `ConsentRequest::AUTHORISED_SUBSTITUTE_RELATIONS`. When the responding relationship is in that set, the materialised `ClientConsent` is flagged `capacity_assessed=true`, `capacity_outcome='lacks_capacity'`, and `best_interests_decision=true`. `informational_only` requests still produce a `ClientConsent` if approved but without the substituted-decision flags — the policy layer decides whether such a row is sufficient for any given downstream gate.

## Routes

### Staff (Operations) — [`routes/consents.php`](../routes/consents.php)

| Method | URI | Name | Permission |
| --- | --- | --- | --- |
| GET | `/operations/clients/{client}/consent-requests` | `operations.clients.consent-requests.index` | `consents.viewAny` |
| GET | `/operations/clients/{client}/consent-requests/{consentRequest}` | `operations.clients.consent-requests.show` | `consents.viewAny` |
| GET | `/operations/clients/{client}/consent-requests/create` | `operations.clients.consent-requests.create` | `consents.request` |
| POST | `/operations/clients/{client}/consent-requests` | `operations.clients.consent-requests.store` | `consents.request` |
| POST | `/operations/clients/{client}/consent-requests/{consentRequest}/cancel` | `operations.clients.consent-requests.cancel` | `consents.request` |

Handler: [`Operations\ConsentRequestController`](../app/Http/Controllers/Operations/ConsentRequestController.php).

### Portal — [`routes/portal.php`](../routes/portal.php)

| Method | URI | Name | Authorisation |
| --- | --- | --- | --- |
| GET | `/portal/clients/{client}/consent-requests/{consentRequest}` | `portal.clients.consent-requests.show` | `auth` + recipient match + `canAccessClientPortal` |
| POST | `/portal/clients/{client}/consent-requests/{consentRequest}/approve` | `portal.clients.consent-requests.approve` | same |
| POST | `/portal/clients/{client}/consent-requests/{consentRequest}/decline` | `portal.clients.consent-requests.decline` | same |

Handler: [`Portal\ConsentRequestPortalController`](../app/Http/Controllers/Portal/ConsentRequestPortalController.php). Approve requires `acknowledge_authority=accepted` and optional notes; decline requires non-empty `response_notes`.

## DeviceAssignment enforcement

[`DeviceAssignmentController::enforceConsentForClientTracker()`](../app/Domain/SecurityDevices/Http/Controllers/DeviceAssignmentController.php) gates `POST /devices/{device}/assign`. It rejects the request when **all** of the following are true:

- `assignable_type === DeviceAssignment::TARGET_CLIENT`, **and**
- `device->domain === 'tracking'`, **and**
- `consent_id` is null **or** does not resolve to a `ClientConsent` belonging to that client with `status='given'` and either no expiry or expiry in the future.

The check lives in the controller, not the model, so unit tests can still build raw fixtures via `DeviceAssignment::create()`. The error message tells staff to obtain consent via the family portal first. Non-tracking devices, room/vehicle assignments, and non-client trackers are unaffected.

## Notifications

| Class | Channels | Trigger | Recipient |
| --- | --- | --- | --- |
| [`ConsentRequestCreatedNotification`](../app/Notifications/Operations/ConsentRequestCreatedNotification.php) | `mail`, `database` | `service->create()` | family-portal recipient |
| [`ConsentRequestRespondedNotification`](../app/Notifications/Operations/ConsentRequestRespondedNotification.php) | `mail`, `database` | `service->approve()` and `service->decline()` | original requester (staff) |
| [`ConsentRequestReminderNotification`](../app/Notifications/Operations/ConsentRequestReminderNotification.php) | `mail`, `database` | `service->sendReminder()` (called from scheduler when 24-72h remain) | family-portal recipient |

Mail uses bilingual greeting (`Kia ora …`) and signs off `Ngā mihi, Oblivion Findings`. Database notifications carry IDs and a deep-link `action_url` so the portal/staff inbox can render a card.

## Scheduled jobs

Defined in [`routes/console.php`](../routes/console.php), both pinned to `Pacific/Auckland`:

- `consent-requests:expire-stale` — hourly. Bulk-flips any `pending` row past `expires_at` to `expired` and appends an `expired` audit event. See [`ExpireStaleConsentRequests`](../app/Console/Commands/ExpireStaleConsentRequests.php).
- `consent-requests:send-reminders` — hourly. Sends one reminder per pending request whose `expires_at` is between 24h and 72h from now; idempotent via `reminder_sent` audit-trail check. See [`SendConsentRequestReminders`](../app/Console/Commands/SendConsentRequestReminders.php).

## Permissions

Defined in [`RbacSeeder`](../database/seeders/RbacSeeder.php):

| Key | Description | Roles |
| --- | --- | --- |
| `consents.request` | Request consent via family portal (new in PR 29) | `admin`, `coordinator` |
| `consents.viewAny` | List/view consent requests on the client profile | `admin`, `coordinator`, others |

Policy mapping is in [`ConsentRequestPolicy`](../app/Policies/ConsentRequestPolicy.php). Portal `respond` is a non-RBAC gate: it requires recipient match plus `canAccessClientPortal($client)`.

## Open extension points

The polymorphic `triggering_subject` morph and the generic Right-7 disclosure fields make these natural follow-ons — none of them are implemented yet:

- **Medication-cabinet consent** — gate `medications.administer.record` for high-risk classes (e.g. PRN antipsychotics) on a valid `ClientConsent` of the relevant type.
- **Photo / media consent** — required for `PortalPhotoController::store` and timeline images that include other clients' faces.
- **Telehealth consent** — required before initiating a remote appointment that records audio/video.
- **Withdrawal flow from the portal** — currently withdrawal lives only on the staff side at `consents.withdrawalRequests.*`; a mirror on the portal would let signatories revoke without phoning in.
- **Capacity reassessment trigger** — when a portal recipient with `RELATION_SELF` declines or repeatedly fails to respond, raise a clinical flag for a fresh capacity assessment under PPPR Act criteria.

Each of these would slot in by (a) adding a `ConsentType`, (b) calling `ConsentRequestService::create()` with the appropriate `triggering_subject_*`, and (c) writing a controller-level enforcement check mirroring `enforceConsentForClientTracker`.
