# Client Profile Redesign — Implementation Plan

Source: `.design-drops/client-profile-redesign/` (README.md + WORKFLOW_AUDIT.md + design_files/).
Target: `/operations/clients/{id}` (`resources/js/pages/operations/clients/show.tsx`).

## Scope
1. Page shell: gradient hero (identity, chips, badges, Add-note split menu, Chat, Edit, More,
   next-shift tile, safety strip, vitals strip, group-pill footer), tier-2 underline tabs,
   tab-search palette (`/` + ⌘K), alert ribbon.
2. Nav registry: 6 groups × ~35 first-class tabs (previously 20 top + 14 folded).
   Keys unchanged → deep links keep working.
3. Wizard system: generic config-driven `WorkflowWizard` on the shared `WizardShell`
   (components/wizard/shell.tsx + primitives.tsx) with auto Review step.
4. 27 workflows — mapping (design key → backend):

| Flow | Endpoint | Notes |
|---|---|---|
| edit_profile | existing `ClientEditDialog` | reuse (8-step) |
| daily_note / quick_note / comm_note | `daily-notes.store` | reuse existing wizard + dialog |
| log_incident | `operations.clients.incidents.store` | NEW wizard |
| add_risk / edit_risk | `clients.risks.*` | wizard + keep tab CRUD |
| record_obs | health bowel/fluid/seizure + clinical observations | NEW wizard, type-routed |
| abc_entry | `clients.clinical.observations.store` (type abc) | NEW wizard |
| add_goal | `operations.care_plans.goals.store` | NEW wizard (needs active plan) |
| plan_review | `care_plans.start-review` → `complete-review` | NEW wizard |
| upload_doc | `clients.documents.store` | wizard for photos/agreements; documents tab keeps full manager |
| transaction | `client_funds.transactions.store` | NEW wizard (fund-scoped) |
| add_onboarding_step | **NEW endpoint** | wizard |
| add_assessment | `clients.assessments.store` | NEW wizard |
| add_asset | `clients.personal-assets.store` | NEW wizard |
| meal_pref | `clients.meal-preferences.*` | keep existing + CTA |
| appointment | **routes registered** for existing `ClientCalendarController@storeAppointment` | NEW wizard |
| request_leave / plan_excursion | `clients.leave.store` / `clients.excursions.store` | wizards |
| transport_booking | **NEW module** `client_transport_bookings` | wizard |
| respite_booking | `respite.requests.store` (client preset) | NEW wizard |
| consent_record | `clients.consents.store` | NEW wizard; withdraw keeps dialog |
| consent request | dedicated Create page (compliance fields) | keep page, CTA links |
| add_relationship | `clients.medical.emergency-contacts.store` (**validation extended**) | NEW wizard |
| portal_invite | `clients.portal-users.store` | NEW wizard |
| add_action | `clients.notes.store` w/ follow_up fields | NEW wizard |
| add_note (timeline) | `clients.notes.store` | NEW wizard |
| edit_rhythms | `clients.routines.upsert` | NEW wizard + keep inline edit |
| emar | client-scoped administration store / meds today record | bespoke eMAR popup |
| assign_workers | existing `AssignWorkerDialog` | reuse |

5. Bespoke surfaces: eMAR sign popup, family chat (OpsConversation, client-scoped,
   conversation_type `family` — **new staff endpoints**), assign-workers editor, detail popups.
6. Retire standalone progress-note page: nav → Daily Notes tab (`?tab=progress_notes&type=progress`),
   redirect route, keep store/update/destroy endpoints (used by daily notes).

## Backend gaps being built
- Register appointment routes (store/update/destroy) — controller methods already exist.
- `POST /operations/onboarding/{workflow}/steps` (+ migration: `category`, `assigned_to` on
  client_onboarding_steps).
- `client_transport_bookings` migration + model + controller + routes; bookings join the
  lazy `transport` prop.
- Family chat: `GET|POST /operations/clients/{client}/family-chat` (get-or-create
  OpsConversation conversation_type=family for client; portal participants = client portal users).
- `consent_requests` list prop in ClientController@show (embedded tab).
- Extended emergency-contact validation: alternate_phone, address, can_view_* flags.
- No new permissions (reuse clients.update etc.) → no seeder runs needed on deploy.

## Feature preservation rules
- All existing tab content paths stay; folded tabs become first-class.
- Existing dialogs (risk CRUD, leave/excursion, documents manager, meal prefs, assign workers,
  client edit, daily/quick/communication notes) keep working; new wizards are additive.
- Tab keys & `?tab=` deep links unchanged. `progress_notes` key remains for Daily Notes.
