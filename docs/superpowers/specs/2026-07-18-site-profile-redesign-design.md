# Site Profile Redesign Design

**Date:** 2026-07-18

**Status:** Approved

**Reference:** `C:\Users\steph\Downloads\Site profile page redesign.zip`

## 1. Outcome

Rebuild `/sites/{site}` as the organisation's canonical Site Profile: a branded, grouped, permission-aware hub that exposes site readiness, occupancy, key people, attention items, and the modules attached to a site without creating parallel records, workflows, or modal implementations.

The supplied Claude Design mock is the visual and content reference. Repository ownership and current production behaviour are authoritative where the mock proposes a duplicate or stale workflow.

## 2. Confirmed decisions

1. Use the site's configured `brand_colour` for the hero when present. Fall back to the organisation's `--primary` brand token. Do not use the green `--category-sites` hue as the Site Profile hero default.
2. Preserve semantic status colours for success, warning, critical, and informational states. A site's brand colour must not recolour status meaning.
3. Use the mock's two-tier navigation, tab search, readiness prominence, attention digest, key-contact treatment, and quick-action layout.
4. Keep one authoritative write workflow for each business concept. The Site Profile may open a shared canonical dialog or link to the owning module; it must not recreate that module's form or persistence logic.
5. Retain existing deep links such as `?tab=hazards`. Unknown, retired, or unauthorized tabs resolve safely to the first visible tab without revealing protected counts or data.
6. Support house, day-service hub, and head-office variants through one typed registry. Labels, occupancy nouns, plan labels, and tab visibility derive from the site type rather than separate pages.

## 3. Existing-state corrections to the handoff

The implementation must not repeat work already completed in the repository:

- `PageHero` already supports an explicit `brandColour` override.
- `Site.brand_colour`, validation, storage, and edit controls already exist.
- Legacy `/clients` entry points already converge on the canonical Operations Client Profile.
- Credential reveal actions already have an audited server-side workflow.
- A reusable grouped Client Profile navigation pattern already exists.
- The canonical Checklists design is a compact Site Profile summary with an entry point to the full site Checklists workspace. A later change reintroduced the full workspace payload and must be corrected.

## 4. Architecture

### 4.1 Site Profile shell

Create a slim `resources/js/pages/sites/show.tsx` shell responsible only for:

- page metadata and breadcrumbs;
- branded hero composition;
- grouped navigation state and URL synchronization;
- lazy group-data loading and loading/error states;
- quick-action routing;
- rendering the active tab component;
- hosting Site-owned dialogs that are not owned by another module.

Move tab bodies into focused components under `resources/js/pages/sites/tabs/`. Each tab receives a typed, permission-shaped payload and callbacks to canonical actions. A tab component must not query unrelated groups or define a second version of an owning module's form.

### 4.2 Shared grouped navigation

Generalize the existing Client Profile navigation components into a shared page-level grouped navigation primitive. Preserve a compatibility re-export for the Client Profile so that the refactor does not fork or regress its behaviour.

The shared primitive provides:

- first-tier group pills inside the hero footer;
- second-tier sticky tab buttons;
- per-tab count or warning badges;
- group-level warning totals;
- keyboard-accessible tab search opened by `/` or the visible Find control;
- remembered tab per group during the session;
- optional pin/unpin controls;
- configurable test-id prefixes rather than Client-specific identifiers.

The Site Profile registry lives in `resources/js/pages/sites/tabs/registry.ts` and is the sole mapping for group membership, labels, icons, site-type visibility, permission state, lazy-data group, and warning source.

### 4.3 Backend boundary

Move Site Profile assembly out of the broad CRUD controller into a focused Site Profile controller and data services while preserving the existing `sites.show` route name and URL.

- `SiteProfileController` authorizes the site and returns the Inertia page.
- `SiteProfileData` builds the small eager shell payload and permission-shaped optional group payloads.
- `SiteProfileAttentionService` aggregates counts and a bounded list of actionable rows without shipping entire registers.
- Existing module services and presenters remain authoritative for module-specific calculations.

The initial response includes only data needed for the hero, grouped navigation, Overview, Readiness, permissions, attention digest, and site-type metadata. Heavy payloads are exposed as the four Inertia optional props `peopleData`, `safetyData`, `operationsData`, and `adminData`, loaded when the active tab needs their group.

Deep-linking directly to a deferred tab renders a stable skeleton and immediately requests that tab's data group. Failed partial loads show an inline retry state without discarding the active tab.

### 4.4 Attention digest

The attention service returns:

```text
summary: total, critical, warning
groups: warning count by navigation group
items: id, source, severity, title, detail, due date, tab, href or canonical action
```

Sources are limited to established records that have a real resolution path:

- overdue hazard reviews and hazard actions;
- overdue inspection schedules and failed-inspection follow-ups;
- due or overdue drills;
- expired or soon-expiring Site documents;
- staff requirement and shift coverage gaps;
- assets or PPE needing service, inspection, or expiry action;
- overdue Checklists runs;
- offline or degraded Site hardware when the Security Devices module already classifies it as needing attention.

Attention rows deep-link to the owning tab or module. They do not create a new corrective-action store. Counts and data are filtered by the viewer's permissions before aggregation. Ship the service uncached behind explicit query-count ceilings; do not add a cache until every contributing model has a tested invalidation path. This avoids a fast but stale safety summary.

## 5. Hero and overview design

The hero uses the full shared `PageHero` contract:

- `brandColour={site.brand_colour}` with primary-token fallback;
- back link to `/sites`;
- site-type-aware description;
- resident or attendee avatar stack where the viewer may see people;
- address, region, site type, active state, and high-risk/high-needs badges;
- readiness score control that switches to the Readiness tab without `scrollIntoView`;
- occupancy and attention stats;
- key-contact chips for manager, site lead, and after-hours contact;
- permission-aware quick actions;
- grouped navigation and Find control in the hero footer.

The Overview tab contains the setup/readiness banner, occupancy band, attention digest, contact summary, location/access/map, safety summary, services, notes, and recent activity. Information is not repeated when it already appears in the hero; the Overview provides detail and resolution paths.

Empty states use one shared pattern: icon, clear explanation, and either a canonical action or module link. Colour is never the only status cue.

## 6. Navigation groups

| Group | Site Profile tabs |
| --- | --- |
| Overview | Overview, Readiness |
| People | Residents/Attendees/Clients, Contacts, Staff Requirements, Shift Coverage |
| Safety | Hazards, Risk Assessments, Inspections, Drills, First Aid, PPE, Emergency Plan |
| Operations | Calendar, Checklists, Meal Planner, Assets, Fleet, Hardware, Plan & Rooms |
| Admin | Documents, Financials, Vendors & Credentials, Services |

Head office hides resident, shift-coverage, and meal-planner surfaces when they are not applicable. The registry derives the people noun, occupancy noun, and plan label from the site type. Permission-restricted module tabs may render a neutral locked explanation, but protected counts and record data are never sent.

## 7. Canonical workflow ownership

| User intent | Canonical owner and Site Profile behaviour |
| --- | --- |
| Create a resident/client | Shared `components/clients/add-client-dialog.tsx`; Site Profile opens it with the site and service-context defaults. |
| Link an existing resident to this site | Site-owned placement wizard. It links an existing Client, assigns room/service context/key worker as authorized, and does not contain a quick-create form. |
| Unlink a resident | Site placement endpoint plus shared small destructive confirmation. It explains room/assignment effects before submission. |
| Add/edit Site contact, room, note, location, access, or Site safety metadata | Sites module; focused Site-owned wizard/dialog using shared wizard primitives. |
| Report or manage a hazard | Canonical Health & Safety hazard workflow, prefilled with `site_id`; Site Profile shows a summary and opens or links to that workflow. |
| Create a risk assessment | Canonical H&S risk-assessment wizard with the Site as the assessable subject. |
| Schedule inspections or log drills/first aid/PPE actions | Owning H&S module workflow; no Site-specific copy. |
| Run or manage Checklists | Compact Site Profile summary; full work occurs at `/sites/{site}/checklists`. |
| Plan meals | Existing canonical Meal Planner component in embedded mode, with its established sub-tabs and a clear full-module entry point. |
| Manage assets, fleet, or hardware | Owning Asset/Fleet/Security Devices workflow filtered to the Site. Site Profile shows summaries and canonical actions only. |
| Manage financials | Canonical Finance Site Dashboard. Site Profile renders a permission-shaped summary and link. |
| Manage vendors or credentials | Canonical `/vendors` workspace filtered to the Site, including its existing audited credential dialogs. The Site Profile does not host a second management register. |
| Add/manage Site documents | Existing Site document storage and endpoints, standardized on shared wizard and confirm primitives. |

Quick actions are an orchestration layer over this table. A quick action is omitted when the viewer lacks both the relevant module permission and Site access.

## 8. Modal and form standard

All multi-step Site-owned forms use the shared wizard primitives and the dimensions, rail, progress, scroll containment, footer, keyboard handling, and validation presentation defined in `design_styles/POPUP_STYLE_GUIDE.md`.

The implementation removes the duplicate quick-create Client form from `pages/sites/clients/_dialogs.tsx`. Creating a new Client always uses the complete shared Add Client wizard. Linking an existing Client remains a separate placement operation because it has different data, authorization, and side effects.

Destructive actions use the shared small confirmation pattern. Browser `alert()` and `confirm()` are not introduced.

## 9. Data, authorization, and performance

- Site policy authorization occurs before any Site Profile data is assembled.
- Each optional group applies its own module permissions before querying.
- Cross-tenant and out-of-scope Site records are rejected at route binding or policy level.
- Contact, Client, document, hazard, inspection, and related list queries eager-load only relations used by their presenters.
- The implementation audits indexes supporting `site_id`, status, due/review/expiry dates, and common attention filters. New indexes are added only where schema inspection and query plans show a real gap.
- The initial response must not include full register payloads for unopened deferred groups.
- Query-count tests set explicit ceilings for the shell and each group request, with fixtures representative of multiple related rows.
- Credential secrets never enter the Site Profile payload. Reveal remains a separate authorized and audited server action.
- Pinned Site tabs persist per authenticated user through a generic `user_ui_preferences` record keyed by `sites.profile.pinned-tabs`, with a unique user/key constraint and a small authorized JSON preference endpoint. The Site Profile must not add another page-specific `localStorage` convention.

## 10. Error and state handling

- Deferred tabs show a labelled skeleton, not an empty panel.
- Failed partial reloads retain the current navigation state and expose Retry.
- Empty records are distinguished from loading and authorization states.
- Validation errors keep the owning wizard open on the first invalid step.
- Successful mutations refresh only the affected shell summary, attention digest, and active tab group.
- A stale or unauthorized `?tab=` value is normalized without a redirect loop.

## 11. Testing and acceptance

### Automated

1. Backend feature tests prove authorization, tenant/Site isolation, brand-colour payload, attention aggregation, canonical links/actions, deferred-prop exclusion, partial-prop inclusion, query ceilings, and credential-data non-disclosure.
2. Frontend tests prove group resolution, type visibility, URL deep links, tab search, pinning, grouped warning totals, branded hero fallback, readiness navigation, empty/loading/locked states, and canonical modal ownership.
3. Red-green tests cover every new behaviour before production implementation.
4. Run focused existing Site, Checklists, Client, H&S, Fleet, Finance, and Credentials tests affected by the orchestration changes.
5. Run formatting, TypeScript, `npm run build`, and `npx vite build --ssr`.

### Browser

Verify the real application at the intended worktree/host for:

- house, day-service hub, and head-office variants;
- desktop layout at 1440 x 900 and a narrow responsive smoke check;
- Site brand colour and organisation-primary fallback;
- grouped navigation, search, deep links, readiness, attention rows, and representative quick actions;
- shared Client creation versus existing-client placement;
- locked states and a restricted user;
- keyboard focus, Escape handling, and visible focus treatment;
- no new console or runtime errors.

### Completion artifacts

- implementation plan with checked progress;
- focused automated and build evidence;
- real-browser evidence tied to the correct worktree;
- `docs/site-profile-redesign-post-audit.md` ranking remaining frontend/backend improvements by severity, impact, and effort;
- explicit distinction between implemented/verified work and any unresolved acceptance gap.

## 12. Non-goals

- Do not create a third store for hazards, corrective actions, maintenance, credentials, Clients, or any other cross-module record.
- Do not replace full owning-module workspaces with Site Profile copies.
- Do not redesign unrelated module index pages.
- Do not rename backend enums merely to change presentation wording.
- Do not claim native-mobile coverage; this goal covers the responsive web Site Profile.
