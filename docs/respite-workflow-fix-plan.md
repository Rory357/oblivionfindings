# Respite workflow fix plan (for Codex)

**Date:** 2026-06-08
**Author:** Audit + Part A implementation by Claude. Parts B & C handed to Codex.
**Scope:** Fix the broken respite intake → booking pipeline and make the respite
"new person" flow gather full client information.

> ## ⚡ STATUS — read first
> **Part A (referral → booking-request bridge) is DONE** — implemented and
> verified this session (typecheck 0, `npm run build` OK, respite suite **50
> tests green** incl. 2 new). It is in the working tree, **not yet committed or
> deployed**. See the "✅ DONE" record inside §4.
>
> **Codex picks up Part B (full client intake) and Part C (polish).** Those are
> the remaining, actionable work. Do **not** re-do Part A — verify it instead
> (its acceptance criteria are at the end of §4).

---

## 0. TL;DR — two bugs, two root causes

The user reported:

1. **"I added a new referral (new client). It went through the workflow but
   nothing is showing."**
2. **"When adding a new client [via respite] it does not bring up the new-client
   pop-up from the clients page, when we need to gather all client information."**

Both are real. Neither is a persistence/500 bug — the referral *is* saved. The
causes are **missing UI seams**:

| # | Symptom | Root cause | Fix |
|---|---------|-----------|-----|
| 1 | Referral "goes nowhere" | **There is no action anywhere that turns a Referral into a `RespiteBookingRequest`.** "Accept" only sets `status='accepted'` and dead-ends. The whole Requests → Bookings → Stays pipeline can never start from a referral. | **Part A ✅ DONE** — added an in-workspace "Create booking request" flow (from a referral, and standalone). |
| 2 | New client only captures 4 fields | The respite intake creates a **lightweight 4-field client shell**. The documented design (`docs/respite-nz-gap-analysis.md:30-33`) said *onboard* should open the full **8-step Add-client wizard prefilled** — but the implemented `OnboardModal` replaced that with a thin consent dialog ("nothing to re-key here"). So full client info is never gathered through respite. | **Part B (Codex)** — reuse the existing 8-step `AddClientDialog` inside respite to complete the full profile. |

**Part A is done** (it was the actual blocker for "nothing shows"). **Part B is
the remaining "gather all client info" request** and is now reachable because the
pipeline flows. **Part C** is polish.

---

## 1. How to run & verify

- **Local (preferred for dev):** Herd site `https://oblivionfindings.test`
  (needs Herd Desktop running). Build assets with `npm run dev` or `npm run build`.
- **Remote test:** `https://oblivionfindings.com` (demo admin). Deploy webhook
  auto-pulls + builds (~5-8 min).
- **Tests:** run non-parallel and change-scoped, e.g.
  `php artisan test --filter=Respite` (do **NOT** use `--parallel` in this repo).
- **Workspace entry point:** `/respite` (tabbed workspace: Overview / Referrals /
  Booking Requests / Approved Bookings / Calendar / Stays / Tasks).

### Reproduce bug #1 before you start
1. `/respite` → **New referral** → "New person" → fill steps → **Submit**.
2. The referral appears under the **Referrals** tab (status "New"). ✅ persisted.
3. Click **Triage** → **Accept**. Status becomes "Accepted".
4. Go to **Booking Requests** tab → **empty.** There is no button anywhere to
   advance the accepted referral. ← this is the bug the user hit.

### Reproduce bug #2
- In the same intake, the "New person" step only asks first/last name, DOB, NHI,
  preferred home. Compare with `/operations/clients` → **Add client** (the
  8-step wizard). None of the cultural / support / health / about / care /
  contacts data is captured by respite.

---

## 2. Current architecture (what exists today)

### Frontend (respite workspace)
- `resources/js/pages/respite/index.tsx` → renders `RespiteWorkspace`.
- `resources/js/components/respite/workspace.tsx` — orchestrator. Holds all modal
  state. Modals mounted: `ReferralIntakeModal`, `OnboardModal`,
  `ConfirmBookingModal`, stay-action modals, `ReasonDialog`. **No request-create
  modal exists.**
- Panes: `panes/overview.tsx`, `panes/referrals.tsx`, `panes/requests.tsx`,
  `panes/bookings.tsx`, `panes/stays.tsx`, `panes/calendar.tsx`, `panes/tasks.tsx`.
- `components/respite/actions.ts` — thin Inertia `router` helpers
  (`triageReferral`, `acceptReferral`, `approveRequest`, `promoteRequest`,
  `confirmBooking`, …). **No `createRequest`.**
- `components/respite/modals/referral-intake.tsx` — the 4-step "New referral"
  pop-up. "New person" path sends `new_client: {first_name, last_name,
  date_of_birth, nhi_number, site_id}` only.
- `components/respite/modals/onboard.tsx` — opens from an **approved** request
  that already has a spawned booking; it only captures consent + confirms the
  booking (posts `/respite/bookings/{id}/confirm`). Its own comment says
  *"intake already created the client … there is nothing to re-key here"* — this
  is the deviation from the documented design.

### Frontend (full client intake) — reuse this for Part B
- `resources/js/pages/operations/clients/_create-dialog.tsx` — `AddClientDialog`,
  the 8-step wizard (Basics, Cultural identity, Support needs, Health & medical,
  About me, Care setup, Contacts & consent, Review). Posts
  `POST /operations/clients` (`forceFormData`, multipart for photo).
  Currently **create-only** (no edit mode).
- Wired on `resources/js/pages/operations/clients/index.tsx:1709` via `addOpen`.
- Form shape = `ClientWizardForm` (see file lines ~128-184). Required client-side:
  `first_name`, `last_name`, `date_of_birth`, `site_id`, `service_context_id`.

### Backend
- Routes: `routes/respite.php`. Key facts:
  - `POST /respite/referrals` → `RespiteReferralController@store`
    (`permission:respite.create`).
  - `POST /respite/requests` → `RespiteBookingRequestController@store`
    (**also `permission:respite.create`** — so no new permission is needed for
    Part A).
  - `POST /respite/requests/{request}/approve` → creates the `RespiteBooking`
    + syncs a staff `Shift` (`permission:respite.bookings.manage`).
  - `POST /respite/bookings/{booking}/confirm` → confirm (onboard).
- `RespiteReferralController@store` (`app/Http/Controllers/Respite/RespiteReferralController.php:56`):
  - Creates (or de-dupes by NHI hash) a `Client` with **only**
    `first_name,last_name,date_of_birth,nhi_number,site_id,funding_type,
    funding_notes,status='active'` (lines 114-123). No `service_context_id`.
  - Creates the `RespiteReferral` with default `status='received'`
    (migration `2026_01_29_000600_create_respite_tables.php:19`).
  - Returns `back()->with('success', …)`.
- `RespiteBookingRequestController@store`
  (`app/Http/Controllers/Respite/RespiteBookingRequestController.php:71`):
  - Already accepts `referral_id` (nullable) and `normaliseFunding()` already
    pulls funding + cultural + carer snapshot **from the referral** into
    `intake_snapshot` (lines 388-435). **This is the half that already works** —
    we just never call it from a referral.
  - Sets `status='submitted'`, redirects to `respite.requests.show`.
  - ⚠️ It does **not** currently link back to the referral
    (`respite_referrals.linked_booking_request_id` stays null and the referral
    status is unchanged). We will fix that in Part A.
- `RespiteWorkspaceController@index`
  (`app/Http/Controllers/Respite/RespiteWorkspaceController.php:42`) returns
  `referrals`, `requests`, `bookings`, `stays`, `homes`, `tasks`, `stats`,
  `clients`, `serviceContexts`, `serviceAgreements`, `fundingSources`.
  `mapReferral()` (line 289) is where we add the new "already has a request" flag.

### Data model facts (no migration needed for Part A)
- `respite_referrals.linked_booking_request_id` column **already exists**
  (migration line 23) and is in `RespiteReferral::$fillable`.
- `respite_booking_requests.referral_id` already exists and
  `RespiteBookingRequest::referral()` belongsTo is defined.
- `respite_referrals.status` enum: `received|triaged|accepted|declined`.
- The Clients index (`ClientController@index`) lists **all** clients with no
  site/context/org filter, so a respite-created shell **does** show on
  `/operations/clients` already (issue #2 is depth, not visibility).

---

## 3. Intended pipeline vs. actual

```
INTENDED:
  Referral ──(triage)──► Referral ──(accept / create request)──► Booking Request
     │                                                                  │
     │                                                            (approve)
     │                                                                  ▼
     └─────────────────────────────────────────────►  Booking ──(onboard/confirm)──► Stay
                                                          ▲
              full client profile captured here ─────────┘ (documented: onboard opens 8-step wizard)

ACTUAL TODAY:
  Referral ──(triage)──► Referral ──(accept)──► [DEAD END]      Booking Request (only via legacy
                                                                 /respite/requests/create page that
                                                                 the workspace never links to)
  full client profile: never captured (onboard modal only does consent)
```

Part A reconnects Referral → Booking Request. Part B restores full-profile
capture.

---

## 4. Part A — Referral → Booking Request bridge (fixes "nothing shows") ✅ DONE

Goal: from the workspace, a coordinator can turn a referral into a booking
request (and create a standalone request for an existing client), all via
pop-ups, consistent with the "pop-ups not pages" redesign.

> ### ✅ As-built record (implemented 2026-06-08, in working tree)
> **Backend**
> - `app/Http/Controllers/Respite/RespiteBookingRequestController.php@store` —
>   after creating the request, if a `referral_id` was supplied it loads the
>   referral, sets `linked_booking_request_id` + `status='accepted'` (+ fires
>   `respite.referral.updated`). Added a `_modal` branch: when the request is
>   posted with `_modal=true` it returns `back()` (stays on the workspace);
>   otherwise it keeps the legacy redirect to `respite.requests.show`.
> - `app/Models/RespiteReferral.php` — added `bookingRequests()` HasMany
>   (`referral_id`).
> - `app/Http/Controllers/Respite/RespiteWorkspaceController.php` — referrals
>   query now `->withCount('bookingRequests')`; `mapReferral()` exposes
>   `fundingSource`, `fundingReference`, `hasRequest` (count>0 OR column set),
>   `linkedRequestId`.
>
> **Frontend**
> - `resources/js/components/respite/modals/request-intake.tsx` — **new** pop-up.
>   Posts `/respite/requests` with `_modal:true`, `referral_id`, `client_id`,
>   `requested_start`, computed `requested_end` (start + nights), `priority`,
>   optional service context / funding / service agreement / notes. Client locked
>   when launched from a referral; funding + cultural carry server-side.
> - `workspace.tsx` — mounts `RequestIntakeModal`; `requestFor` state
>   (`RespiteReferralRow | 'standalone' | null`); passes `onCreateRequest` to the
>   Referrals pane and `onNew` to the Requests pane.
> - `panes/referrals.tsx` — "Create booking request" button on referrals where
>   `can.create && status==='accepted' && !hasRequest`.
> - `panes/requests.tsx` — standalone "New booking request" button in the header.
> - `panes/overview.tsx` — "Ready to book — {client}" attention row for accepted
>   referrals without a request.
> - `components/respite/types.ts` — `RespiteReferralRow` gained `fundingSource`,
>   `fundingReference`, `hasRequest`, `linkedRequestId`.
>
> **Tests** (`tests/Feature/Respite/RespiteActionsTest.php`): "creating a booking
> request from a referral links it and advances the referral" + "the legacy
> request create still lands on the request detail when not modal". Full respite
> suite **50 passed**; `npm run types` 0; `npm run build` OK.
>
> **Remaining for Part A (optional, deferred to Codex if wanted):** the Board
> (kanban) view of the Referrals pane doesn't render the new action (list view
> only) — fine, the list view is the default. Recurring/series requests not
> exposed in the modal.

The A1–A6 detail below is the original spec, kept for reference / review.

### A1. New component: `RequestIntakeModal`
Create `resources/js/components/respite/modals/request-intake.tsx`, styled like
`referral-intake.tsx` (same `Dialog`, `Field`, `Segmented`, `NativeSelect`
primitives — copy them or extract shared ones).

**Props:**
```ts
{
  open: boolean;
  onClose: () => void;
  clients: ClientOption[];          // from workspace data.clients
  serviceContexts: { id: number; name: string }[];   // data.serviceContexts
  serviceAgreements: ServiceAgreementSummary[];       // data.serviceAgreements
  fundingSources: FundingOption[];  // data.fundingSources
  homes: RespiteHome[];             // data.homes (optional, for context)
  // When launched from a referral, prefill + lock the client:
  referral?: RespiteReferralRow | null;
}
```

**Fields (single or 2-step pop-up):**
- Client: locked to `referral.clientId` when `referral` is set; otherwise a
  `<select>` over `clients`.
- Requested start (date) + either Requested end (date) **or** Nights → compute
  `requested_end`. (`requested_end` must be `after:requested_start`.)
- Service context (`service_context_id`, optional — defaults to the client's).
- Funding source + reference (prefill from `referral.fundingSource` /
  `referral.funding` when present).
- Service agreement (optional select, filtered to the client's active agreements
  from `serviceAgreements` where `clientId === selected`).
- Priority (`routine|priority|crisis`) — default from referral urgency
  (crisis→crisis else routine).
- Preference notes (textarea).
- (Optional) emergency toggle, recurrence — defer if you want a smaller first cut.

**Submit:** Inertia `useForm` → `post('/respite/requests', { preserveScroll:
true, onSuccess: onClose })` with payload:
```ts
{
  client_id, referral_id: referral?.id ?? null,
  requested_start, requested_end,
  service_context_id, funding_source, funding_reference,
  service_agreement_id, priority, preference_notes,
}
```
The controller's `normaliseFunding()` will auto-carry the referral's cultural /
carer / funding snapshot — you do **not** need to re-send those.

### A2. Wire the modal into the workspace
In `resources/js/components/respite/workspace.tsx`:
- Add state: `const [requestModal, setRequestModal] = useState<{ referral: RespiteRequestRow | RespiteReferralRow | null } | null>(null);`
  (simplest: `const [requestFor, setRequestFor] = useState<RespiteReferralRow | null | 'standalone'>(null)`).
- Mount `<RequestIntakeModal open={requestFor !== null} referral={requestFor === 'standalone' ? null : requestFor} … />`
  passing `data.clients`, `data.serviceContexts`, `data.serviceAgreements`,
  `data.fundingSources`, `data.homes`.
- Pass a handler into `ReferralsPane` (A3) and `RequestsPane` (A4).

### A3. "Create booking request" action on referrals
In `resources/js/components/respite/panes/referrals.tsx`:
- Add prop `onCreateRequest: (row: RespiteReferralRow) => void;`
- In `ReferralCard`, add a button shown when `can.create` and
  `r.status === 'accepted'` (and not already linked — see A5 `hasRequest`):
  ```tsx
  {can.create && r.status === 'accepted' && !r.hasRequest ? (
      <Button size="sm" onClick={() => onCreateRequest(r)}>
          <CalendarPlus className="h-3.5 w-3.5" /> Create booking request
      </Button>
  ) : null}
  ```
- Consider also allowing it on `triaged` (accepting and requesting in one go) —
  product call; `accepted` is the safe default.
- In `workspace.tsx`, pass `onCreateRequest={(row) => setRequestFor(row)}`.

### A4. Standalone "New booking request" button
In `resources/js/components/respite/panes/requests.tsx` `PaneHead`, add (when
`can.create`):
```tsx
<Button size="sm" onClick={onNew}><Plus className="h-3.5 w-3.5" /> New booking request</Button>
```
Thread `onNew` from `workspace.tsx` → `setRequestFor('standalone')`.
This covers existing clients who book respite without a fresh referral.

### A5. Backend: link the request back to the referral + advance status
In `RespiteBookingRequestController@store`, after `RespiteBookingRequest::create`
(line ~106), when a `referral_id` was supplied:
```php
if (! empty($validated['referral_id'])) {
    RespiteReferral::where('id', $validated['referral_id'])->update([
        'linked_booking_request_id' => $requestModel->id,
        // advance the referral out of the triage queue once it has a request
        'status' => 'accepted',
        'updated_by' => auth()->id(),
    ]);
}
```
(Or load the model and use `forceFill`/`save` to fire `AuditableChanges`.) Keep
it inside a transaction if you prefer.

Then expose the linkage to the UI in
`RespiteWorkspaceController::mapReferral()` (line 289):
```php
'hasRequest' => $r->linked_booking_request_id !== null,
'linkedRequestId' => $r->linked_booking_request_id,
```
Add `hasRequest?: boolean` / `linkedRequestId?: number|null` to the
`RespiteReferralRow` type in `resources/js/components/respite/types.ts`.

> Edge case: a referral may have multiple requests over time (recurring respite).
> If you want to support that, don't hard-block on `hasRequest`; instead show
> "Create another request". For the first cut, gating on `hasRequest` is fine.

### A6. Overview hint (nice-to-have, low effort)
In `panes/overview.tsx`, the "Needs your attention" list already surfaces new
referrals. Add an action when there are `accepted` referrals with `!hasRequest`:
"N accepted referral(s) ready to turn into a booking request" → `goTab('referrals')`.
This makes the next step discoverable and directly answers "nothing is showing".

### Part A acceptance criteria
- From an **accepted** referral, "Create booking request" opens the modal with the
  client locked and funding prefilled.
- Submitting creates a `RespiteBookingRequest` (status `submitted`) that appears
  in the **Booking Requests** tab immediately (Inertia refresh).
- The source referral now shows `hasRequest` and links to the request; it no
  longer offers "Create booking request".
- The request carries the referral's funding + cultural + carer snapshot
  (verify `intake_snapshot` JSON).
- Approving that request creates a booking (existing behaviour) → onboard →
  confirm → stay. End-to-end pipeline works from a brand-new referral.
- Standalone "New booking request" works for an existing client with no referral.

---

## 5. Part B — Full client intake through respite ("gather all client information")

Goal: restore the documented design — the full **8-step `AddClientDialog`** is
used inside respite to capture the complete person-centred profile, instead of
the 4-field shell being the end of the story.

### B1. Make `AddClientDialog` reusable (extract + add edit mode)
1. **Extract** `AddClientDialog` from
   `resources/js/pages/operations/clients/_create-dialog.tsx` to a shared
   location, e.g. `resources/js/components/clients/add-client-dialog.tsx`
   (re-export from the old path so existing imports keep working). Importing a
   page-private `_create-dialog` from the respite components dir is a smell;
   extracting fixes it.
2. **Add an optional edit/complete mode.** New props:
   ```ts
   clientId?: number;                       // when set → "complete profile" mode
   initialValues?: Partial<ClientWizardForm>; // prefill (from referral/client)
   onSaved?: (clientId: number) => void;    // callback after success
   ```
   - When `clientId` is set: seed the form from `initialValues`, change the
     submit to `form.put('/operations/clients/' + clientId, …)`
     (route `operations.clients.update`, `permission:clients.update`,
     `UpdateClientRequest`). Title becomes "Complete profile".
   - When `clientId` is absent: current create behaviour (`POST
     /operations/clients`).
   - Server-side `StoreClientRequest`/`UpdateClientRequest` keep `site_id`,
     `service_context_id`, `date_of_birth` **nullable**, so a partial prefill is
     accepted; the wizard's own client-side validation still nudges for the key
     fields.

   > If `UpdateClientRequest` doesn't already accept the full nested
   > `medical`/`conditions`/`emergency_contacts` payload + `forceFormData`,
   > verify and extend it to match `StoreClientRequest`. Confirm
   > `ClientController@update` persists the same relations `@store` does.

### B2. Restore "onboard opens the full wizard" (the documented flow)
Per `docs/respite-nz-gap-analysis.md:30-33`, onboarding an approved request should
open the full client wizard prefilled, **then** confirm the booking. Two ways —
pick one:

- **B2a (recommended, smaller):** Keep `OnboardModal` for consent + confirm, but
  add a prominent **"Complete full client profile"** button at the top of it that
  opens the extracted `AddClientDialog` in edit mode for `request.clientId`,
  prefilled from the referral/request `intake_snapshot`. The coordinator can fill
  the full record, save, then continue to confirm. Update the modal's misleading
  "nothing to re-key here" copy.
- **B2b (closer to the doc):** Replace the onboard step with the full wizard as
  step 1, consent as the final step.

### B3. "Complete profile" available any time the client is a shell
Don't make full capture *only* reachable at onboard (that's late). Surface it
wherever a respite client is still a shell:
- Backend: in `mapReferral()` (and optionally `mapRequest`/`mapBooking`), add
  `clientProfileComplete` — e.g. `true` when the client has
  `service_context_id` **and** `date_of_birth` **and** at least the Basics set.
  (Pick a concrete rule and document it.)
- Frontend: on referral/request cards, when `!clientProfileComplete`, show a
  subtle "Complete profile" action that opens `AddClientDialog` (edit mode)
  prefilled. This is the literal answer to *"bring up the new-client pop-up to
  gather all client information."*

### B4. Prefill source
Prefill `initialValues` from:
- The existing `Client` row (whatever the shell already has), **plus**
- The referral's cultural/carer fields (ethnicity, iwi/hapū/marae, interpreter,
  cultural dietary needs, primary carer) → map onto the wizard's
  `ethnicity`, `languages`, `dietary_requirements`, etc.

To feed this, either:
- Include a small `clientPrefill` bundle per referral in the workspace payload, **or**
- Add a lightweight `GET /operations/clients/{client}/wizard-data` (or reuse an
  existing client-edit data source) the modal fetches on open. Prefer reusing
  existing data already in the payload to avoid an extra endpoint.

### B5. Optional: capture-full-profile at referral time
On the referral intake "New person" step, add an optional checkbox **"Capture
full profile now"**. If ticked, after the referral POST succeeds, immediately
open `AddClientDialog` (edit mode) for the newly created `flash.created_*` /
referral's client. Keep it optional so crisis triage stays fast. (Lower priority
than B2/B3.)

### Part B acceptance criteria
- From respite (onboard and/or a "Complete profile" action), the full 8-step
  client wizard opens **prefilled** with the shell's data + referral cultural/carer
  data.
- Saving updates the existing client (no duplicate created) and the client's
  `/operations/clients/{id}` profile shows the full info.
- The respite-created shell becomes a complete client (`service_context_id`, DOB,
  cultural, contacts, etc. populated).
- No regression to the standalone `/operations/clients` → Add client flow.

---

## 6. Part C — polish / guardrails (optional but recommended)

- **Empty-state copy.** In `panes/requests.tsx` and `panes/bookings.tsx`, when
  empty but upstream items exist, hint the next action (e.g. "No requests yet —
  create one from an accepted referral on the Referrals tab"). Prevents the
  "nothing is showing" confusion recurring.
- **Duplicate-client hint at intake (D3 fast-follow, see
  `docs/respite-nz-finish-plan.md:187`).** When the NHI typed in referral intake
  matches an existing client, show "matches existing client — link instead?".
  Backend already de-dupes by `nhi_hash`; just surface it.
- **`service_context_id` on the shell.** `RespiteReferralController@store` creates
  the client without a service context. Consider defaulting it to
  `ServiceContext::defaultId()` (used elsewhere in
  `RespiteBookingRequestController`) so the shell isn't half-formed before Part B
  completion.

---

## 7. Files to touch (summary)

**Part A ✅ DONE** (see the as-built record in §4)
- `resources/js/components/respite/modals/request-intake.tsx` — new.
- `resources/js/components/respite/workspace.tsx` — mount + state + handlers.
- `resources/js/components/respite/panes/referrals.tsx` — `onCreateRequest`.
- `resources/js/components/respite/panes/requests.tsx` — `onNew` button.
- `resources/js/components/respite/panes/overview.tsx` — discoverability hint.
- `resources/js/components/respite/types.ts` — `fundingSource`, `fundingReference`,
  `hasRequest`, `linkedRequestId` on `RespiteReferralRow`.
- `app/Http/Controllers/Respite/RespiteBookingRequestController.php` — link
  referral + advance status + `_modal` branch.
- `app/Models/RespiteReferral.php` — `bookingRequests()` HasMany.
- `app/Http/Controllers/Respite/RespiteWorkspaceController.php` — `withCount` +
  `mapReferral()` flags.
- `tests/Feature/Respite/RespiteActionsTest.php` — 2 new cases.

> ℹ️ Part B will add `clientProfileComplete` to `mapReferral()` +
> `RespiteReferralRow` (not added yet).

**Part B (Codex — remaining)**
- `resources/js/pages/operations/clients/_create-dialog.tsx` → extract to
  `resources/js/components/clients/add-client-dialog.tsx` (+ re-export) and add
  `clientId`/`initialValues`/`onSaved` edit mode.
- `resources/js/components/respite/modals/onboard.tsx` — "Complete full profile"
  (B2a) + copy fix.
- `resources/js/components/respite/panes/{referrals,requests}.tsx` — "Complete
  profile" action when `!clientProfileComplete`.
- `app/Http/Controllers/Respite/RespiteWorkspaceController.php` —
  `clientProfileComplete` + any prefill bundle.
- Verify `app/Http/Requests/UpdateClientRequest.php` + `ClientController@update`
  accept the full nested payload (extend if needed).

---

## 8. Testing

**Automated (`php artisan test --filter=Respite`, non-parallel):**
- ✅ DONE: `POST /respite/requests` with a `referral_id` asserts the referral gets
  `linked_booking_request_id` + `status='accepted'` and `_modal` returns to the
  workspace; plus the legacy non-modal path still redirects to the request detail.
- ✅ Covered already: the request carries the referral's `intake_snapshot`
  (cultural/carer/funding) — `RespiteNzWorkflowCompletionTest`; approve→booking→
  shift — `RespiteReadinessTest`.
- TODO (Part B): `PUT /operations/clients/{id}` from the wizard payload updates
  the shell to a complete profile without creating a duplicate.

**Manual (run on `oblivionfindings.test`, then verify on `.com` after deploy):**
- Walk §1 "Reproduce" steps and confirm the referral now advances to a request,
  booking, and stay.
- Confirm "Complete profile" opens the prefilled 8-step wizard and saves onto the
  same client.

---

## 9. Deploy notes
- **No DB migration needed for Part A** (`linked_booking_request_id` and
  `referral_id` columns already exist).
- **No new permission needed** — `POST /respite/requests` already uses
  `respite.create` (same as referrals), and client update uses `clients.update`.
  So **no `*PermissionsSeeder` run is required** (cf.
  `docs/reference` note that deploys skip seeders). If you *do* add any new
  permission, you must run its seeder with `--force` on the server.
- Frontend changes require an asset build; the deploy webhook auto-builds.

---

## 10. Why the docs didn't catch this (context for reviewers)
- `docs/respite-nz-gap-analysis.md`, `respite-nz-implementation-plan.md`,
  `respite-nz-finish-plan.md`, and `rostering-portal-respite-readiness-plan.md`
  all **assume** `accept → request` already works and only audit the
  *funding/cultural/clinical payload* riding the pipeline. The finish plan's
  "orphaned endpoints / missing UI" audit lists missing stay-level modals but
  **never lists a missing "create booking request from referral" action** — it
  was overlooked.
- The full-intake-at-onboard design **was documented** (`respite-nz-gap-analysis.md:30-33`)
  but the implementation diverged (the `OnboardModal` "nothing to re-key"
  decision). And because onboard is unreachable without Part A, the documented
  full-capture path was doubly dead.

---

## 11. Out of scope / deferred
- Recurring/series respite requests UI beyond a single request (backend already
  has `series_id`/`recurrence_rule`).
- `RespiteEvent` listeners / downstream automation (events fire but mostly have no
  listeners — `respite-nz-finish-plan.md:194`).
- The secondary respite tabs (Procedures/Records/etc.) deferred earlier.
