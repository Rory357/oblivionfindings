# eMAR Audit Trail — cross-module parity gap analysis

Single source of truth for the `/emar/audit` parity `/loop`. Scope is **only** the
Audit Trail page, its filters, and its read-only detail drawer. This surface is
**append-only / immutable** — no create/edit/delete affordances may be added; the
immutability is a compliance feature (CQC / NICE SC1).

- **Page:** `resources/js/pages/emar/AuditLog.tsx`
- **Drawer:** `resources/js/components/emar/medication-event-drawer.tsx`
- **Controller:** `app/Http/Controllers/Emar/AuditLogController.php` → `index()`
- **Endpoints (all pre-existing — front-end only, no new endpoints):**
  integrity `GET /emar/audit/event/{id}/integrity`, per-event export
  `GET /emar/audit/event/{id}/export`, flag `POST /emar/audit/event/{id}/flag`,
  audit-pack export `GET /emar/audit/export`.

Reuse idioms (copy, don't hand-roll): `ShiftContextMenu`/`ShiftCtxItem`/`ShiftCtxState`
(`@/components/rostering/shift-context-menu`, template = PrnRecords `openRowCtx`);
`EntityFilter` (`@/components/rostering`); options-bar idiom = `prn-detail-dialog.tsx`;
client jump = `router.visit('/operations/clients/${id}/care')`; alert strip mirrors
`ControlledDrugs.tsx`. Semantic tokens only.

---

## A. Right-click context menu (read-only) + "View client"  — priority 1

- [x] A1. Added `onContextMenu` → `ShiftContextMenu` to all three event-row surfaces
      (timeline rows, table rows, compliance-gap cards) via `openRowCtx`. Read-only items only:
      View record (primary → drawer), View client (→ `/operations/clients/{id}/care`),
      Open on {MAR chart/CD register/…} (via `eventPrimaryLink`), Verify integrity (opens the
      drawer focused on the integrity panel — new `initialSection` prop on the drawer), sep,
      Export this event (→ `/event/{id}/export`), Copy event ID (clipboard + toast). Tag =
      event-type label pill (`CTX_TAG` token map); meta = client · medication · timestamp.
      Files: `resources/js/pages/emar/AuditLog.tsx`,
      `resources/js/components/emar/medication-event-drawer.tsx` (exports `eventPrimaryLink`,
      accepts `initialSection`). types + lint clean.

## B. Detail drawer — Options bar + Verify integrity action  — priority 2

- [x] B1. Rebuilt the `MedicationEventDrawer` footer as a read-only Options bar (prn-detail
      pattern): Close (left) · View client (`router.visit` → `/operations/clients/{id}/care`) ·
      Open on {MAR/CD register/…} (`router.visit` → primaryHref) · Verify integrity · Export event ·
      Flag for investigation (kept, append-only, gated on `integrity.backed`) · Resolve gap
      (contextual primary when flagged). New `verifyIntegrity()` focuses the integrity panel
      (`goToSection`) and re-fetches `/event/{id}/integrity` with a toast — no mutation.
      File: `resources/js/components/emar/medication-event-drawer.tsx`. types + lint clean.

## C. Convert secondary filters to EntityFilter  — priority 3

- [x] C1. Replaced the raw shadcn `Select`s for Client, Staff and Source with `EntityFilter`
      (search · count ▾, inline/light variant) — cross-module parity with PRN/Reviews. Staff
      and Source are indexed to `{id,name}` (EntityFilter is id-based) while the existing
      client-side query state (`clientId`/`staffName`/`source`) is preserved unchanged via the
      onChange adapters. Range stays a `Select`. File: `resources/js/pages/emar/AuditLog.tsx`.
      types + lint clean.

## D. Integrity / omission alert strip  — priority 4 (optional)

- [ ] D1. Add a dismissible (per-session) alert strip below the hero, mirroring
      `/emar/controlled`, surfacing the compliance signals already on the payload:
      **N MAR omissions in this window** (critical → gaps view) and **N CD entries missing a
      witness** (critical → controlled gaps). Each: icon + count + message + a "Review" jump +
      dismiss. `TODO(Gx)`: "N events edited since recording" is **not** on the index payload —
      `integrity.edit_count` is lazy-loaded per event from the integrity endpoint — so it is
      omitted (would need a payload/aggregate change, out of front-end scope).

---

## Loop exit (§6)
Every box `[x]`; `npm run types` / `npm run lint` / `npm run build` all pass; event rows
have a read-only right-click menu with View client; the drawer has an Options bar
(View client · Open MAR · Verify integrity · Export); Client/Staff/Source are EntityFilters;
the page remains strictly append-only (no create/edit/delete anywhere); interactions are
navigational/read-only with Inertia partial reloads where applicable.

## Pass log
- (pass 0) Created this file from the seed gaps; audited the live page + reference
  components. Confirmed all of A1/B1/C1 are still open (raw Selects present; no row context
  menu; drawer footer has Flag/Export/Resolve only — no View-client / Verify-integrity).
  Confirmed the `.design-drops` prototype is not checked into this worktree; using
  `docs/emar-redesign/audit-design-review.md` + the cross-module standard as the reference.
