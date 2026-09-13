# Oblivion Care — Foundations Plan

*Agreed 2026-09-13. This is the working plan for turning the existing codebase into the
production app, and for shipping iOS/Android. Work top-to-bottom; each phase builds on
the one before. Update this doc as decisions change.*

## Decisions already made

| Decision | Choice |
|---|---|
| Codebase | **Evolve this Laravel codebase** — it is the backend + web app. No rebuild. |
| Mobile | **One React Native (Expo) app** for all roles, role-adaptive UI. Real store apps (Play Store + App Store). |
| Mobile v1 persona | **Support workers first** (shifts, care notes, tasks, incidents). Family, managers, clients follow in the same app. |
| Offline scope v1 | Notes, tasks, checklists + cached reading of today's rostered clients. **eMAR stays online-only in v1** (no offline med-dose conflicts). |
| Data visibility | Role/module walls **+** site assignment **+** client assignment **+** family hard-scoped to their relative with per-category consent. |
| Region | **New Zealand** — Privacy Act 2020, Health Information Privacy Code 2020 (HIPC), Ngā Paerewa NZS 8134:2021. |
| Team | Solo + Claude, steady pace. Foundations before features. |
| Design | Tokens-first, shared between web and mobile. Reusable primitives only — no one-off UI. |

## The one architectural rule

**Authorization is enforced in exactly one place: Laravel Policies + query scopes.**
Web controllers, API controllers, and background jobs all go through the same policies
and the same `visibleTo($user)` query scopes. The mobile app and the web UI merely
*reflect* permissions (hide buttons); they never *enforce* them. If a check exists only
in React, it doesn't exist.

---

## Phase 0 — Ground truth audit (know what's actually enforced)

Before adding anything, measure the gap between the RBAC that *exists* (RbacSeeder:
26 roles, grouped permissions, `rbac:sync`) and what's actually *enforced* per route.

- [ ] **Route enforcement inventory**: script/test that walks every registered route and
      reports its middleware. Flag any authenticated route with no permission/policy
      check beyond `auth`. Output: a checklist table in `docs/authorization-audit.md`.
- [ ] **Policy coverage inventory**: list every Eloquent model that holds client, staff,
      or financial data; note which have Policies and which don't.
- [ ] **Scoping inventory**: which queries already filter by site/assignment, and which
      return everything to any authenticated user.
- [ ] Write the **authorization matrix**: roles × modules × actions (view/create/edit/
      delete/approve) as a doc. This becomes the spec for Phase 1 and the source for
      permission tests.

**Exit criteria:** a written list of every enforcement gap, prioritised by data
sensitivity (client health data first, reference data last).

## Phase 1 — Authorization foundations (web first, mobile inherits)

The "each person sees only what they need" phase. All on the existing web app —
no mobile code yet.

### 1a. Assignment models (the scoping backbone)
- [ ] `site_user` — which sites a staff member works at (some of this may exist; formalise it).
- [ ] Client assignment — derived from rostered shifts + an explicit keyworker/care-team
      link. A support worker's visible clients = (clients on their shifts, past N days +
      today + upcoming) ∪ (clients they're keyworker for).
- [ ] `client_next_of_kin` — family links with **per-category consent flags**
      (daily notes / photos / medical / financial / incidents). Consent is data, not code:
      admins toggle it per family member.

### 1b. Enforcement machinery
- [ ] A `visibleTo(User $user)` query scope (or builder macro) per scoped model —
      one canonical implementation of "what can this user see", used everywhere.
- [ ] Laravel Policies for every model from the Phase 0 inventory, composing:
      module permission (existing RBAC) → site scope → client scope.
- [ ] Route middleware normalised: every route group declares its permission.
- [ ] Frontend: share the user's permission set via Inertia props (likely partially
      exists) so the UI hides what the policy would deny — reflection, not enforcement.

### 1c. Prove it and keep it proven
- [ ] **Pest authorization matrix tests**: for each role fixture, assert the key
      routes/queries return exactly the expected scope. These tests are the contract —
      any future module must add its rows.
- [ ] **Sensitive-access audit logging**: writes everywhere; reads for client health
      records (HIPC expects you to know who looked at what).

**Exit criteria:** a support worker account demonstrably cannot see unassigned clients,
other sites, finance, or HR — proven by tests, not by clicking around.

## Phase 2 — API layer (the mobile contract)

- [ ] **Laravel Sanctum** for token auth. One token per device, named, revocable
      (remote logout = delete token). 2FA (already in the stack via google2fa) honoured
      at login.
- [ ] `routes/api/v1/…` + **API Resources** for every payload — the resource classes are
      the stable contract the mobile app codes against. No raw models over the wire.
- [ ] v1 endpoint set (support-worker slice only):
      auth/device, `me` (profile + permissions + feature flags), today's + upcoming
      shifts, rostered clients (scoped, trimmed fields), care notes (create/list),
      tasks + checklist ticks, incident report drafts, sync.
- [ ] **Sync protocol** (designed once, before the app exists):
      - Client-generated UUIDs on offline-created records → idempotent upserts.
      - Pull: `updated_since` cursor per collection.
      - Push: queued mutations replayed in order; server validates against policies
        *again* (a stale offline client may have lost access to a client since caching).
      - Conflicts: care notes are **append-only** (no edits after sync = no conflicts);
        task ticks are idempotent; anything else rejects with a re-fetch instruction.
- [ ] API feature tests reusing the Phase 1 matrix fixtures — same scoping, new surface.

**Exit criteria:** you can drive the whole support-worker day (login → shifts → notes →
tasks → incident) from HTTP calls alone, correctly scoped.

## Phase 3 — Shared design system (the "no design headaches" phase)

- [ ] **Single token source**: extract the semantic tokens (colours, spacing, radii,
      type scale) to `design/tokens.(json|ts)`. Web CSS variables and the mobile theme
      are both *generated/derived* from it — change once, both platforms follow.
- [ ] Mobile styling via **NativeWind** (Tailwind for React Native) configured from the
      same tokens — the `bg-surface text-muted-foreground` vocabulary carries over.
- [ ] **Mobile primitives kit** mirroring `resources/js/components/ui/`:
      `Screen`, `AppHeader` (Event Horizon adapted for small screens), `Card`,
      `ListRow`, `StatusBadge`, `Field` controls, `EmptyState`, `SyncBadge`.
      Screens compose primitives only — the same rule DESIGN.md enforces on web.
- [ ] DESIGN.md gains a **Mobile section** + anti-pattern list (no inline styles, no raw
      colours, no one-off components), same governance as web.

## Phase 4 — Mobile app v1 (support worker)

- [ ] **Expo + TypeScript + expo-router + NativeWind**; monorepo folder (`mobile/`) or
      sibling repo — decide at kickoff (leaning `mobile/` in-repo for solo dev).
- [ ] Security baseline: tokens in SecureStore, biometric/PIN app lock, encrypted
      SQLite, no client health data in push notification bodies, token wipe on logout.
- [ ] **Offline core**: SQLite cache of today's scoped data + outbound mutation queue +
      background sync + visible sync-status UI (workers must trust it synced).
- [ ] Screens: Login (+ biometric unlock) → Today (shifts) → Client profile (scoped) →
      Care note composer (offline-first) → Tasks/checklists → Incident draft → Settings.
- [ ] Push notifications via Expo Push (server already does web-push; add a channel).
- [ ] **EAS Build** → TestFlight + Play internal track from week one; real devices early.

**Exit criteria:** a support worker does a full real shift — including a signal dead
zone — and every note/tick syncs correctly afterwards.

## Phase 5 — Expand personas (same app, role-adaptive)

Order: **Family portal** (consent-filtered feed, read-mostly — validates the consent
model) → **Managers** (approvals, alerts, dashboards) → **Clients**. Each is mostly
new API resources + new screens on the existing primitives; the foundations don't move.

---

## New Zealand compliance thread (cross-cutting, not a phase)

- **Privacy Act 2020 + Health Information Privacy Code 2020 (HIPC)**: health info
  collected for care purposes only; clients/families get access + correction rights
  (portal helps here); **notifiable privacy breach** regime → have a breach runbook.
- **Ngā Paerewa NZS 8134:2021** (Health & Disability Services Standard): informed
  consent, records quality, incident management — the consent flags and audit logs in
  Phase 1 are what make audits answerable.
- Hosting: keep production data in an NZ/AU region; document where backups live.
- Mobile: device loss is the realistic breach vector → biometric lock, encrypted local
  store, revocable device tokens, minimal cached data (today's clients, not the whole
  caseload).

## What we are deliberately NOT doing

- ❌ Rebuilding the backend or web frontend.
- ❌ Wrapping the website in Capacitor/PWA for the stores.
- ❌ Offline eMAR in v1.
- ❌ Separate apps per role — one app, role-adaptive.
- ❌ Building mobile screens before Phases 1–2 exist (an app on unscoped APIs is a
  privacy incident with a nice UI).
