# Clients index — deferred menu actions

**Status:** backlog / handoff
**Created:** 2026-05-29
**Related commit:** `9ea8750e` — *fix(clients): assign workers via a popup from the index; drop dead menu stubs*

## Background

The redesigned Operations → Clients index (`resources/js/pages/operations/clients/index.tsx`)
shipped a per-client action menu (shared by the **right-click context menu** and the
**⋯ kebab**) plus a **bulk action bar** (visible in select mode). Several of those items
were never wired to real behaviour — they either silently `router.visit()`'d the bare
client profile (doing nothing) or showed a "coming soon" toast.

Per the team preference to **hide unbuilt actions rather than ship stubs**, commit
`9ea8750e` removed them and implemented the one that was explicitly requested
(*Assign workers* → popup). This file records what was removed so it can be built
deliberately later.

### What WAS implemented in that commit
- **Assign workers** (per-client) — opens `AssignWorkerDialog`
  (`resources/js/components/assign-worker-dialog.tsx`), loads/saves via
  `GET|PUT /operations/clients/{client}/assignments` (`?modal=1` → JSON).
  Use this component as the **template** for the dialog-based actions below.
- **Bulk Export** — kept (works; CSV of selected clients).

### Where the menu lives
- Per-client items: the `actionsFor(c)` function in `index.tsx` (builds the `MenuItem[]`
  consumed by both `ClientKebab` and `ClientContextMenu`).
- Bulk items: the `BulkBar` component + its handler (now `exportSelected`; the old
  multi-action `onBulkAct` was removed).

To re-add any action, add a `MenuItem` back into `actionsFor()` (gate it on the relevant
`can.*` flag) or a button back into `BulkBar`.

---

## Deferred per-client actions

### 1. Add daily note
- **Prior (broken) behaviour:** `router.visit('/operations/clients/{id}/care')` — just
  opened the care view; redundant with the existing "Open care view" item and never
  opened a note composer.
- **Intended:** open the daily-note composer directly for that client.
- **Implementation:** the component already exists — `DailyNoteWizard`
  (`resources/js/pages/operations/clients/dialogs/daily-note-wizard.tsx`), takes
  `clientId`, posts to `POST /operations/clients/{client}/daily-notes`. Mount it from
  the index the same way `ClientEditDialog` / `AssignWorkerDialog` are (open-state +
  `clientId`).
- **Permission gate:** `progress_notes.create | timeline.create`.
- **Effort:** small (reuse existing wizard).

### 2. Message family
- **Prior (broken) behaviour:** `router.visit('/operations/clients/{id}')`.
- **Intended:** start/open a conversation with the client's family / portal users.
- **Implementation:** messaging module exists — `MessageController` with
  `POST /operations/messages/create` (`createConversation`), `operations.messages.show`,
  `operations.messages.store`. Needs to resolve the client → its portal users
  (`client.portalUsers`) and create or open a conversation. Could be a compose dialog or
  a redirect into `/operations/messages/{conversation}`.
- **Open question:** compose-in-dialog vs. navigate to the messages thread? Which portal
  users are the default recipients (all NOK, or a primary contact)?
- **Effort:** medium (recipient resolution + compose UI).

### 3. Move to respite / Convert to permanent
- **Prior (broken) behaviour:** `router.visit('/operations/clients/{id}')`.
- **Intended (assumed):** flip the client between permanent and respite care.
- **Reality check:** "respite" is **not a simple status flag**. On the index,
  `has_respite` is derived from respite **booking** counts
  (`respite_bookings_count + respite_booking_requests_count > 0`), and respite is a full
  module (`routes/respite.php`: referrals, booking requests, bookings, calendar).
- **Implementation options:**
  - (a) Deep-link to respite **referral/booking-request create** prefilled for the client
    (`respite.requests.create` / `respite.referrals.create`).
  - (b) If the product wants a lightweight permanent⇄respite toggle, that needs a new
    concept/endpoint — not currently modelled.
- **Open question:** what does "move to respite" mean operationally — create a booking, or
  toggle a (not-yet-existing) status? **Needs product decision before building.**
- **Effort:** medium–large, blocked on the product decision above.

### 4. Designate / change key worker  *(related gap, not previously a menu item)*
- The `AssignWorkerDialog` **flags** the key worker with a star but cannot **set** it.
  `key_worker_id` is a single column on `clients`, updated via the client update form,
  which doesn't currently expose it either.
- **Intended:** let a manager pick which assigned worker is the key worker.
- **Implementation:** add `key_worker_id` to the client update payload (or a dedicated
  endpoint), then a "Make key worker" affordance in the assigned list of
  `AssignWorkerDialog`.
- **Effort:** small–medium.

---

## Deferred bulk actions (select mode)

All three previously showed a "coming soon" `toast.info(...)` and were removed; **Export**
was kept.

### 5. Bulk message
- **Intended:** message the families of all selected clients.
- **Depends on:** the same messaging plumbing as *Message family* (#2), applied to many
  clients (likely N conversations or one broadcast).
- **Effort:** medium (after #2).

### 6. Bulk set respite
- **Intended:** apply a respite change to all selected clients.
- **Depends on / blocked by:** the respite-semantics decision in #3.
- **Effort:** large, blocked.

### 7. Bulk assign staff
- **Intended:** assign one or more support workers to all selected clients at once.
- **Implementation:** reuse the assignments endpoint per client
  (`PUT /operations/clients/{client}/assignments`) in a batch, or add a bulk endpoint.
  A dialog like `AssignWorkerDialog` but picking workers to **add** across the selection.
- **Permission gate:** `clients.assignments.update`.
- **Effort:** medium.

---

## Suggested priority
1. **Add daily note** (#1) — quick win, component already exists.
2. **Designate key worker** (#4) — completes the assignment story already shipped.
3. **Message family** (#2) then **Bulk message** (#5).
4. **Respite** items (#3, #6) — only after the product decision on what permanent⇄respite means.
5. **Bulk assign staff** (#7).
