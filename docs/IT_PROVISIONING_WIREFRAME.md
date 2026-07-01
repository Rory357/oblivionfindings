# IT & Provisioning — wireframe build-out spec

> **Status: BUILT (2026-07-02).** The wireframe has been replaced by the real
> feature: `it_provisioning_requests` + `it_tickets` tables,
> `App\Http\Controllers\It\ItProvisioningController`, the
> `OnboardingService::createItProvisioningRequests()` bridge, `it.view` /
> `it.manage` permissions (RbacSeeder + grant migration), and a live
> `resources/js/pages/it/index.tsx` (filters, context menus, Log-ticket /
> Fulfil / Assign wizards). Tests: `tests/Feature/It/ItProvisioningTest.php`.
> The spec below is kept for the record; §5 (external integrations on fulfil)
> remains the only deferred item.

`/it` (top-level nav: **IT & Provisioning**) previously rendered a
design-preview wireframe with static mock data, agreed before the backend was
built. This note lists exactly what had to be built to make it real.

## Why it exists
Onboarding checklists produce IT-category tasks like *"Create Microsoft 365
account"*, *"Provision care-app login"*, *"Issue laptop"*. Two of these are
already automatable on the onboarding side:
- **Equipment** → `OnboardingService::provisionAssetForTask()` issues an `Asset`
  (assign or auto-pick) and completes the task.
- **Everything else** (accounts, access grants) has **no fulfilment path** — it's
  just a checkbox someone ticks. This surface is where those would be worked.

## What must be built

### 1. Schema (two tables)
```
it_provisioning_requests
  id, tenant_id
  employee_profile_id            FK hr_employee_profiles (the new hire)
  onboarding_task_id             FK hr_onboarding_tasks NULLABLE (source task)
  type                           enum: account | access | equipment | other
  item                           string  (e.g. "Microsoft 365 account")
  assigned_to_user_id            FK users NULLABLE (IT owner)
  status                         enum: pending | in_progress | done | cancelled
  external_ref                   string NULLABLE (ticket id / account id)
  fulfilled_at, fulfilled_by
  notes, created_by, timestamps

it_tickets                       (general helpdesk — not onboarding-driven)
  id, tenant_id, title, description
  requester_user_id              FK users
  assigned_to_user_id            FK users NULLABLE
  category                       string (hardware | account | network | other)
  priority                       enum: low | normal | high | urgent
  status                         enum: open | in_progress | resolved | closed
  resolved_at, timestamps
```

### 2. Onboarding → provisioning bridge
In `OnboardingService::generateChecklist()`, after tasks are created, for each
`category === 'it'` task that is **not** an equipment/asset task, create an
`it_provisioning_request` (pending). Mark the task complete automatically when
the linked request is fulfilled (mirror `provisionAssetForTask`'s
`completeTask()` call). Keep it idempotent (skip if a request already exists for
the task).

### 3. Endpoints (all gated `it.manage`, a NEW permission — ship a grant migration)
- `GET  /it` → real controller returning both queues (replace the Inertia
  closure route in `routes/web.php`).
- `POST /it/provisioning/{request}/assign` · `/fulfil` · `/cancel`
- `POST /it/tickets` (create) · `PATCH /it/tickets/{ticket}` · `/resolve`
- Optional: `POST /it/provisioning/{request}/fulfil` completes the source
  onboarding task via `OnboardingService`.

### 4. Permissions
Add `it.view` / `it.manage` to `RbacSeeder` **and ship a grant migration**
(deploys skip seeders — see the health-clinical rule). Until then the nav item
and route are gated on `hr.onboarding.manage` as a stand-in.

### 5. Integrations (later, optional)
`fulfil` on an `account`/`access` request is where a real integration would hook
in — e.g. Microsoft Graph to create the M365 account, or an internal directory
API. Model `external_ref` for the created account/id. Until an integration
exists, `fulfil` is a manual "IT did it" confirmation.

### 6. UI (replace the wireframe)
The wireframe already shows the intended layout: hero + `HrTabs`
(Provisioning / Tickets) + tables. Turn the disabled buttons into real actions,
add a create-ticket dialog, filters, and an assignee picker. Reuse the
onboarding hub's context-menu + StatusBadge patterns.

## Files touched by the wireframe (to remove/replace when building for real)
- `routes/web.php` — the `/it` closure route.
- `resources/js/pages/it/index.tsx` — static mock page.
- `resources/js/components/app-sidebar.tsx` — the `it-provisioning` nav item
  (currently gated on `hr.onboarding.manage`).
