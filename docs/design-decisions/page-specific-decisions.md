# Page- and package-specific design decisions

Moved out of the design rules on 26 Oct 2026 (Stephan: "i dont want any dedicated page items in the design rules"). These are decisions Stephan approved for particular pages or packages; they still bind those pages. The general rules they contained now live in the design guides (searchable dropdowns and lists people can add to: `design_styles/POPUP_STYLE_GUIDE.md` › Dropdowns; maps: `design_styles/MAP_GEOFENCING_STYLE_GUIDE.md`). Text below is unchanged.

## From DESIGN.md

### User-authorized additions · 22 September 2026

- Maps and cross-profile geofencing follow [MAP_GEOFENCING_STYLE_GUIDE.md](design_styles/MAP_GEOFENCING_STYLE_GUIDE.md), based on the inspected Client Location implementation. Greyscale base tiles, coloured overlays, canonical shared boundaries, separate profile assignments and separate monitoring authority are required.
- Structured form choices follow the searchable catalog and interval amendment in [POPUP_STYLE_GUIDE.md](design_styles/POPUP_STYLE_GUIDE.md). Service types and service interval presets use searchable choices with explicit **Add custom** where the field represents a configurable catalog. Service recurrence is expressed in calendar months by default, with distance as an independent trigger.
- Vehicle checks require a reusable library owned with Maintenance checklists, editable questions and evidence requirements, profile/category assignment, controlled versions, and immutable submitted question/answer snapshots. A changed template must not rewrite previous checks. Workflow previews demonstrate configuration; approval of operational checklist content remains with its responsible owner.

## From design_styles/POPUP_STYLE_GUIDE.md

### User-authorized catalog and interval amendment · 22 September 2026

Apply this alongside the searchable record-selector contract below. Use shared Popover + Command controls for growing configurable catalogs such as service types, checklist purposes and interval presets. Search existing values before offering a clearly labelled **Add custom: [value]** action; preserve the selected value, trim/normalize input, reject duplicates and invalid numeric values, and support keyboard selection. Creation must be explicit and permission-aware, with a returned catalog identity and audit trail in the implemented application. A prototype may demonstrate local creation if it clearly states that scope.

This is not a blanket free-text substitute for existing-record relationships. Staff, clients, vehicles, sites and providers must resolve to permitted canonical records. Creating such a record requires its owning workflow and appropriate permission. Small fixed enums (status, yes/no, answer type) remain concise selects or choices; do not add arbitrary custom approval states.

Service recurrence uses **calendar months**, not a fixed conversion to days. Offer searchable month presets and positive whole-number custom months; clamp month-end recurrence to the target month's last valid date. Offer distance presets plus a positive custom kilometre interval. Either trigger may apply; show both and say which is first due. Recalculate suggested next triggers from actual completion date/odometer and require review. Reminder lead times may still use days because they represent offsets rather than service recurrence.

Every structured form must show meaningful initial choices, concise guidance, optional/required state, validation and a useful review. Preserve selected custom values through step changes and retry. Do not leave large blank free-text fields where users should choose a known catalog option. Keep the shared wizard rail/header/footer contract and avoid nested scrolling in the page body.

### User-requested catalogue and document follow-up · PKG-02B v7

An explicit Add new action must return a selectable value and make it available in future uses of the same catalogue. Normalize whitespace, reject case-insensitive duplicates, bound name length, and surface creation/storage errors. Preserve optional-field identity: changing a label to include “optional” must not create a separate catalogue. Do not save arbitrary search text automatically. A local mockup may use browser persistence when it states that scope; the application uses canonical IDs, central persistence, creation history and catalogue-management permission.

Catalogue creation and form save are separate actions. If creating a catalogue entry survives cancelling the parent form, make that behavior clear. Report-only users choose existing options and can explain an unlisted concern in notes; controlled approval/lifecycle enums do not gain arbitrary new states. Person/provider/Finance relationships use the owning record workflow.

Vehicle document forms must support the actual files, classification, reference, optional expiry, version replacement and a visible source owner. Offer a renewal reminder where useful. Preserve original files and require an archive reason. Flag conflicts between a document expiry and profile metadata rather than silently replacing one with the other. Reuse existing evidence by reference where possible.

Review steps show readable record names, not only opaque IDs. Finance handoffs show the source, evidence, receiving owner and pending status; editing a vehicle or completing work does not approve or post Finance transactions.

## Module details and migration notes moved from the rules (26 Oct 2026)

The general rule each came from stays in the guide; only the module-, page- or migration-specific sentences moved here, unchanged.

### Page header — reference migration (from `DESIGN.md`)

Reference migration: `pages/sites/index.tsx`.

Original text:

> / `.eh-meter` utilities in `app.css`; reference migration
> `pages/sites/index.tsx`. The sky

### Page header — profile hero migration (from `DESIGN.md`)

> Both profile heroes are now migrated and deleted (sites 2026-09-06,
> clients 2026-09-07 — `pages/operations/clients/show.tsx` is on the
> `PageHeader` profile variant with its alert ribbon folded into the
> meter row).

### Rail — reference page (from `DESIGN.md`)

`PageHeaderRail` is used by `pages/sites/index.tsx`.

Original text:

> Reference implementation: `PageHeaderRail` in
>   `components/page/page-header.tsx` (used by `pages/sites/index.tsx`).

### Lists — reference migration (from `DESIGN.md`)

Reference migration: `pages/sites/index.tsx`.

Original text:

> shared `entity-menu.tsx`, `ListCaption`); reference migration
>   `pages/sites/index.tsx`.

### Control Room — header wrapper (from `DESIGN.md`)

> Fix a header that looks wrong by keeping it compact (title,
>   ≤ 4 linked numbers, filters and view tabs inside it), not by giving one
>   module its own header. Control Room pages use `ControlRoomPage`
>   (`components/control-room/control-room-page.tsx`), which wraps the shared
>   `PageHeader` and the destination's view tabs.

### Control Room — live response marks and tabs (from `DESIGN.md`)

> Use Control Room's marks
>   (`components/control-room/live-response/`): the **countdown lens**
>   (`TargetLens`, `.cr-lens` — the pop-up kit's round lens with a ring that
>   empties toward the target; grey "waiting" when there is no target), the
>   **urgency bar** (`UrgencyBar`, fixed safety colours), the **Live mark**
>   (`LiveMark`) in the header subline, and the **"Needs you now"** card
>   for an urgent top alert (only when it is urgent). Reference numbers stay
>   small and last. Hide empty fields; say a thing once; plain tab names
>   (Needs action · Mine · Escalated · Snoozed · Incident reports · Done).

### Sidebar hubs — Governance and Workforce (from `DESIGN.md`)

> Keep the config in one place
>   (reference: `lib/governance-sections.ts` + `GovernanceSectionRail`, with
>   the sidebar entry staying lit across the hub). Don't orphan a module
>   that has no other entry (Roadmap became a Strategy hub tab), and never
>   use hidden nav as the security boundary.
>   Reinforced 2026-10-12 for **Workforce**: six destinations — Rostering,
>   Available shifts, Attendance, Timesheets, Handovers, Settings. The Shifts
>   list, Conflict queue, Qualifications and Availability open from
>   Rostering's header (the Rostering entry stays lit on them); Shift notes
>   is a tab in the Handovers header. Their routes, permissions and command
>   search entries stay; someone who can't open Rostering (or the Shift notes
>   tab) keeps a direct entry instead (`buildWorkforceSubPanelGroups`).

### Governance — vocabulary and labels (from `DESIGN.md`)

Governance follows `docs/audits/2026-09-14-governance-plain-language-ux/vocabulary.md`, `refSuffix()`, every enum through `lib/governance-labels.ts` (server: `GovernanceLabels`) and a `GovernanceTermHint` for any term that needs explaining.

Original text:

> Write for a volunteer: follow
>   `docs/audits/2026-09-14-governance-plain-language-ux/vocabulary.md` —
>   sentence case, NZ English, the record title first with the code as a
>   muted `refSuffix()`, every enum through `lib/governance-labels.ts`
>   (server: `GovernanceLabels`), a `GovernanceTermHint` for any term that
>   needs explaining, "Not available" instead of a green 0 when data is
>   missing,

### Approved mockups — the vehicle calendar example (from `DESIGN.md`)

> left as restyled legacy "interim" content (the vehicle Calendar shipped
>   as a bookings list instead of the approved five-view calendar). Every

### Conformance probe 1 — known legacy wizards (from `DESIGN.md`)

> (known: `pages/sites/create.tsx`, `pages/sites/edit.tsx`,
>    `pages/sites/_wizard.tsx`) and any routed full-page create/edit wizard.
>    Target state: a `WizardShell` add/edit dialog on the index page (the
>    Add Site dialog `components/sites/add-site-dialog.tsx` already exists —
>    check the *edit* path uses it too).

### Conformance probe — main view tabs below the hero (from `DESIGN.md`)

> (b) pages whose main view tabs still render below
>    the hero as tinted-pill strips (`ViewTabs` in
>    `pages/sites/calendar/SiteCalendar.tsx`; the clients index migrated
>    2026-09-07) — migrate to the

### Conformance probe 18 — migrated list pages (from `DESIGN.md`)

> and any
>     remaining readiness/onboarding displays. `pages/sites/index.tsx`
>     (2026-09-06, the reference) and
>     `pages/operations/clients/index.tsx` (2026-09-07) are migrated.

### Pop-up guide — simple dialog reference implementations (from `design_styles/POPUP_STYLE_GUIDE.md`)

> 1. **Simple dialog** — one screen of fields, or a confirmation/detail viewer.
>    Reference implementations:
>    [`resources/js/pages/sites/contacts/_dialogs.tsx`](../resources/js/pages/sites/contacts/_dialogs.tsx)
>    (Site Contact dialogs) and
>    [`resources/js/pages/Governance/Resolutions/_dialogs.tsx`](../resources/js/pages/Governance/Resolutions/_dialogs.tsx)
>    (New Resolution dialog).

### Pop-up guide — wizard reference implementations (from `design_styles/POPUP_STYLE_GUIDE.md`)

> Reference implementations:
>    [`resources/js/components/clients/add-client-dialog.tsx`](../resources/js/components/clients/add-client-dialog.tsx)
>    (the original contract) and
>    [`resources/js/components/sites/add-site-dialog.tsx`](../resources/js/components/sites/add-site-dialog.tsx).

### Pop-up guide — remaining full-page wizards (from `design_styles/POPUP_STYLE_GUIDE.md`)

> entity records are wizard dialogs opened from the index page. (The remaining
> full-page wizards — e.g. `pages/sites/create.tsx` / `edit.tsx` on the legacy
> `wizard-stepper` — are migration targets, not precedents.)

### Full-page wizards — legacy holdouts (from `DESIGN.md`)

> (`pages/sites/create.tsx` + `edit.tsx` on `wizard-stepper` are the last
> legacy holdouts — migration targets, not precedents.)

### Rail filters and Find chip — reference pages (from `DESIGN.md`)

> Reference: IT & Support
> (`pages/it/index.tsx`), all seven views

> exactly as the Sites profile
> renders it.
