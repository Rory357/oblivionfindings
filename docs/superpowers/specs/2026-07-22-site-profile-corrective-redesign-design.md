# Site Profile Corrective Redesign Design

**Date:** 2026-07-22

**Status:** Approved

**Supersedes where conflicting:** `2026-07-18-site-profile-redesign-design.md`

**Visual reference:** the actual Client Profile at `resources/js/pages/operations/clients/show.tsx` and `resources/js/components/clients/profile/hero.tsx`

**Content reference:** `C:\Users\steph\Downloads\Site profile page redesign.zip`

**Capability baseline:** Site Profile at base commit `b5b5df463ce788fbbf988c74f5142b7fcbb52628`

## 1. Reason for the corrective design

The first redesign failed user acceptance. It used the generic `PageHero` rather than the actual Client Profile composition and replaced many complete Site Profile work surfaces with small summary cards and links to other modules.

The comparison against the capability baseline found approximately 9,856 removed lines across the Site Profile implementation scope. Sixteen replacement tab components are only 15 to 33 lines and render generic summary/link-out panels. Full registers and actions for hazards, inspections, drills, first aid, PPE, fleet, vendors and credentials, hardware, plans, documents, services, and other areas were reduced or removed from the Site Profile experience.

This corrective design adopts a preservation-first rule:

> Deduplicate implementation and data ownership, never user capability.

No existing control, register, sub-tab, dialog intent, embedded workspace, or backend mutation may disappear silently. Improvements may change presentation, performance, or which shared implementation performs an action, but must not reduce what an authorised user can accomplish from the Site Profile.

## 2. Approved outcome

Rebuild `/sites/{site}` in the same visual and interaction family as the actual Client Profile while retaining every pre-redesign Site Profile capability.

The approved direction is:

1. Use the Client Profile family without a recent-sites strip.
2. Build a Site-specific profile hero with the same hierarchy, spacing, actions, statistics, operational strips, alert ribbon, grouped navigation, and second-tier tab treatment as Client Profile.
3. Restore complete Site Profile work surfaces and actions.
4. Keep useful backend additions from the first redesign, including attention aggregation, permission-shaped payloads, placement logic, branded payloads, UI preferences, and deferred loading.
5. Replace genuine duplicate forms or modals with canonical shared implementations while retaining the action in its Site Profile context.
6. Use the Site's configured `brand_colour`; fall back to the organisation's primary colour. Do not use the mock's green hero as a default.

## 3. Visual composition

### 3.1 Site Profile hero

Create a dedicated `SiteProfileHero` that follows the structure of `ClientProfileHero` rather than wrapping the generic `PageHero`.

It contains:

- a back link to Sites and the Site identifier;
- a strong identity block with Site icon or Site identity avatar, name, status, type, region, and relevant identity chips;
- permission-shaped status badges for high risk, high needs, readiness, or other established states;
- a primary Add/Log menu for the most useful authorised actions;
- edit and more-action controls;
- three prominent statistic tiles for readiness, needs attention, and type-aware occupancy;
- an operational strip, such as next inspection, next scheduled event, or staffing/coverage status;
- a safety strip summarising current actionable risk without replacing the underlying registers;
- four compact indicators selected from residents or attendees, staffing, documents, checks, rooms or spaces, and other Site-type-relevant measures;
- `GroupPillRail` in the hero footer.

The Site's brand colour drives the hero background and foreground contrast. Success, warning, critical, and informational states continue to use semantic status tokens and are never recoloured as brand states.

### 3.2 Content rhythm

Render the attention ribbon immediately below the hero, matching Client Profile's placement and pill treatment. Do not add a recent-sites strip.

Render second-tier tabs with the same spacing and active treatment as Client Profile. The selected tab then renders its complete working surface. The page must not place generic link-out summaries where the baseline provided a register, editor, embedded workspace, or action set.

### 3.3 Site types and responsive behaviour

The shared composition supports houses, day-service hubs, facilities, residential Sites, and head office through typed labels and visibility rules. Occupancy nouns, people nouns, plan labels, indicators, and applicable tabs derive from the Site type.

Desktop is the primary visual reference. Narrow web layouts must remain operable without horizontal document overflow, clipped actions, or inaccessible navigation.

## 4. Complete capability ledger

Before implementation, create a durable ledger by comparing the baseline page and related components with the corrective branch. Each visible control, data surface, sub-tab, dialog intent, endpoint, and permission condition must be classified as:

- **Restore:** retain the existing capability in the new tab component;
- **Canonical replacement:** retain the action in Site Profile but invoke the authoritative shared workflow;
- **Improve:** preserve capability while improving presentation, loading, accessibility, or backend reuse;
- **Blocked:** state the exact unresolved dependency and user impact.

There is no `Removed` classification without new user approval.

The ledger must cover at least the following surfaces.

| Group | Surface | Minimum preserved capability |
| --- | --- | --- |
| Overview | Overview | Readiness banner, occupancy, attention, location and access, map and geofence, contacts, safety, services, notes, activity, Site lines, and contextual edit actions. |
| Overview | Readiness | Complete critical and recommended setup checklist with real resolution actions. |
| People | Residents, attendees, or clients | Full cards and records, create-client entry, link existing person, placement details, room or space assignment, service context, key worker, unlink confirmation, and profile navigation. |
| People | Contacts | Complete typed contact register with add, view, edit, delete, primary-contact treatment, phone, and email actions. |
| People | Staff Requirements | Full requirements and coverage controls, including add or edit workflows and rostering impact. |
| People | Shift Coverage | Complete per-shift coverage and minimum-staff configuration. |
| Safety | Hazards | Full Site hazard register, ratings, dates, owners, actions, procedures or chemical context where applicable, and canonical hazard workflows. |
| Safety | Risk Assessments | Full Site-linked risk-assessment register, review information, linkage to hazards, and canonical assessment workflows. |
| Safety | Inspections | Full schedules, results, history, pass or fail detail, follow-up actions, and canonical scheduling or recording workflows. |
| Safety | Drills | Full emergency-drill register, cadence and due state, history, and canonical log or schedule workflows. |
| Safety | First Aid | Complete kits or records, expiry cues, treatment events, follow-ups, and canonical first-aid workflows. |
| Safety | PPE | Complete PPE register, condition, inspections, expiry or condemnation states, and canonical PPE workflows. |
| Safety | Emergency Plan | Full emergency-plan information, published plan view, emergency layer and pins, evacuation or location detail, medication-storage detail, related controls, and established print or management actions. |
| Operations | Calendar | The complete embedded `SiteCalendar`, including established event views and create, edit, or related event workflows. |
| Operations | Checklists | The complete embedded `ChecklistsWorkspace`, including runs, exceptions, and established actions. It must not be reduced to a compact summary. |
| Operations | House Meal Planner | The complete embedded planner with Planner, Recipes, Shopping List, Inventory, Templates, and all established dialogs and actions. |
| Operations | Assets | Complete Site-linked asset register, ownership or assignment context, condition, service cues, and established actions. |
| Operations | Fleet | Complete Site Fleet work surface, including baseline charts, vehicle detail, status, dates, and established actions. |
| Operations | Hardware | Complete Site hardware or device register, state detail, offline or degraded cues, and established actions. |
| Operations | Floor Plan and Rooms | Published plan, complete designer or builder, room or space inventory, assignment actions, emergency layer, pins, thumbnails, and all related controls. |
| Admin | Documents | Complete Site document experience, including categories, versions or file detail, upload, download, expiry cues, and established actions. |
| Admin | Financials and House Ledger | Complete authorised financial summary and Site or house ledger experience with established detail and actions. |
| Admin | Vendors and Credentials | Full vendor and credential registers, add, show, edit, delete, TOTP state, secure audited reveal or management actions, and audit context. |
| Admin | Services | Complete Site service-context register and established authorised management actions. |

The ledger must also cover dialogs and components rendered outside the old tab bodies, including the floor-plan builder, Site lines, location, safety, notes, contacts, clients, rooms, vendors, credentials, geofence, and destructive confirmations.

## 5. Component architecture

### 5.1 Page coordinator

Keep `resources/js/pages/sites/show.tsx` as a small coordinator responsible for:

- breadcrumbs and metadata;
- Site Profile hero composition;
- grouped navigation and URL synchronisation;
- active-tab data loading and retry state;
- a typed dialog intent state;
- rendering the active tab and the shared Site Profile dialog host.

It must not become another multi-thousand-line implementation.

### 5.2 Full-depth tab components

Each tab under `resources/js/pages/sites/tabs/` owns the presentation of one complete baseline work surface. A tab component receives typed, permission-shaped data and raises typed navigation or dialog intents. It does not define a second persistence workflow.

Existing mature components are embedded or extracted rather than rewritten when they already provide the required capability. This specifically applies to Calendar, Checklists, Meal Planner, floor-plan tooling, maps, readiness, ledger, and established H&S presenters.

### 5.3 Dialog host and workflow ownership

Create a typed `SiteProfileDialogHost`, following the centralised pattern used by Client Profile. Tabs request an intent such as `link_client`, `add_contact`, `edit_room`, `report_hazard`, or `upload_document`. The host resolves that intent to the authoritative shared or Site-owned workflow.

Site-owned records retain Site-owned forms where the Site module is the authoritative owner: contacts, rooms or spaces, placements, location and access, Site lines, notes, geofence, and Site safety metadata.

Cross-module records retain their full Site Profile registers and contextual actions, but invoke the owning module's form, validation, authorisation, and persistence implementation. This includes H&S, Checklists, Fleet and Assets, Hardware, Finance, credentials, and client creation.

Destructive actions use one accessible confirmation pattern. Browser `alert()` and `confirm()` are not introduced.

## 6. Data flow and backend boundaries

### 6.1 Initial payload

The initial page response includes only data required for:

- Site identity and type;
- permissions and visible navigation;
- hero indicators;
- attention ribbon;
- Overview;
- Readiness;
- small tab counts that the viewer may access;
- UI preferences.

### 6.2 Per-tab deferred payloads

Full working data loads per tab, not as one whole navigation group. Opening Hazards requests Hazards data, not every Safety register. Direct links to deferred tabs render a labelled skeleton and immediately request the corresponding payload.

Complete per-tab presenters reuse the existing authoritative models, scopes, services, policies, and query builders. The corrective implementation must not invent parallel record stores or reconstruct domain rules in React.

### 6.3 Mutations and refreshes

After a successful mutation, refresh only:

- the affected tab payload;
- hero indicators changed by the action;
- readiness when setup completeness changed;
- attention and warning counts;
- occupancy or people counts when placement changed.

Do not discard the selected group, tab, scroll position, or unrelated deferred data.

### 6.4 Authorisation, privacy, and query quality

Authorise Site access before assembling profile data. Each tab presenter and mutation applies the required module permissions and record policies. Locked tabs do not expose protected rows or counts. Credential secrets never enter a Site Profile payload; reveal remains a separate authorised and audited server action.

Audit restored registers for N+1 queries and missing supporting indexes. Add an index only where schema and query-plan evidence demonstrate a real gap. Focused tests set representative query ceilings for the initial shell and high-volume tabs.

## 7. Loading, error, and empty states

- Deferred tabs show a labelled skeleton rather than a blank panel.
- Transport failures preserve the active tab and show an inline Retry control.
- Empty, loading, locked, and error states remain visually and semantically distinct.
- Validation errors keep the canonical dialog open, retain entered data, identify invalid fields, and focus the first invalid step or field.
- Successful mutations announce their result and update only the affected data.
- Unknown or unauthorised `?tab=` values normalise safely without disclosing protected navigation data.

## 8. Verification and acceptance

### 8.1 Capability proof

The capability ledger is a release gate. Every baseline row must be marked Restored, Canonical replacement, Improved, or Blocked with evidence. Automated green checks alone do not prove feature or visual parity.

### 8.2 Automated verification

Add or update tests for:

- Site Profile hero composition and brand fallback;
- alert ribbon, grouped navigation, second-tier tabs, tab search, pinning, and deep links;
- Site-type labels and visibility;
- every deferred tab payload and failure-retry path;
- retained controls and dialog intents in every full-depth tab;
- canonical workflow ownership and the absence of duplicate form implementations;
- Site access, module permission, direct-object denial, privacy, and credential non-disclosure;
- successful and invalid submissions for high-impact workflows;
- focused query ceilings;
- complete embedded Calendar, Checklists, Meal Planner, and floor-plan surfaces.

Run focused Site, Client, H&S, Checklists, Calendar, Meal Planner, Fleet, Hardware, Finance, Documents, Vendors, Credentials, and Rostering tests affected by the change. Run TypeScript checks, formatting, the client build, and the SSR build.

### 8.3 Real-browser verification

Verify against the actual Client Profile, not the Clients hub or the Claude mock alone. Cover:

- house, day-service hub or facility, and head-office variants;
- 1440 by 900 desktop composition and a narrow web smoke check;
- configured Site brand colour and primary-colour fallback;
- dark mode;
- keyboard navigation, visible focus, Escape behaviour, and dialog focus containment;
- no document-level horizontal overflow;
- complete Calendar, Checklists, House Meal Planner, Floor Plan and Rooms, Emergency Plan, and representative workflows from every other group;
- restricted-user behaviour without protected data leakage;
- no new runtime or console errors.

Screenshots must come from the intended worktree and must be tied to the route, role, viewport, and scenario they prove.

## 9. Crash-safe execution

Because the earlier Codex session operated under high memory pressure, implementation and verification must:

- run heavy builds and broad test groups sequentially;
- cap terminal output and retain concise evidence summaries;
- start only exact, scoped local server processes;
- record process identifiers and close only the processes started for this work;
- avoid reopening unnecessary browser-backed tasks;
- prefer focused tests during development and reserve broad checks for explicit checkpoints.

## 10. Completion conditions

The correction is complete only when:

1. the approved Client Profile visual family is implemented without a recent-sites strip;
2. the Site brand colour and semantic status colours are correct;
3. the capability ledger accounts for every baseline feature;
4. the named full surfaces—Floor Plan and Rooms, Emergency Plan, Calendar, Checklists, House Meal Planner, and all other baseline tabs—remain complete and working;
5. duplicate implementations are removed only after their Site Profile actions successfully invoke the canonical equivalent;
6. backend authorisation, payload, query, and privacy checks pass;
7. client and SSR builds pass;
8. real-browser evidence proves visual and functional acceptance;
9. any remaining acceptance gap is reported as Blocked rather than silently deferred.

## 11. Non-goals

- Do not reduce a baseline work surface to a generic module summary.
- Do not create parallel record stores, persistence workflows, or duplicate modal implementations.
- Do not redesign unrelated module index pages.
- Do not add a recent-sites strip.
- Do not use category green as the Site Profile hero default.
- Do not claim completion from builds and tests without the capability ledger and real-browser proof.
