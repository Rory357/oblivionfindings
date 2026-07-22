# Site Profile corrective redesign — browser evidence

Date: 2026-07-22

Branch: `codex/site-profile-redesign`

Worktree: `C:\Users\steph\.config\superpowers\worktrees\oblivionfindings\codex-site-profile-redesign`

Host: `http://127.0.0.1:8765`

Browser: Chromium controlled through Playwright CLI

## Acceptance result

The corrective Site Profile matches the actual Client Profile composition while preserving the complete pre-redesign Site capability surface. The comparison covers the dedicated branded hero, alert ribbon, grouped hero navigation, second-tier tabs, full-depth work surfaces, responsive behaviour, dark mode, keyboard access, loading/error behaviour, and permission-shaped actions. There is no recent-sites strip.

The hero uses the Site `brand_colour` when configured and the organisation primary colour otherwise. The browser fixtures demonstrated both paths: a teal configured Site and a purple organisation fallback. Claude's green is not used as a default.

## Reference and scenario matrix

| Evidence | Viewport | Result |
| --- | ---: | --- |
| [Actual Client Profile — desktop](./client-profile-desktop.png) | 1440 × 900 | Approved family reference: hero, alert ribbon, grouped navigation, spacing and work-surface depth. No horizontal overflow. |
| [Actual Client Profile — narrow](./client-profile-narrow.png) | 390 × 844 | Reference remains readable and scroll-safe at narrow web width. |
| [Site Profile — organisation fallback](./site-profile-org-fallback-house-desktop.png) | 1440 × 900 | House Site uses organisation purple when no Site colour is configured. |
| [Site Profile — configured brand](./site-profile-configured-brand-desktop.png) | 1440 × 900 | Site-specific teal is applied to the hero family. |
| [Head office Site](./site-profile-head-office-desktop.png) | 1440 × 900 | Site-type variant retains the same profile family and full navigation. |
| [Facility Site](./site-profile-facility-desktop.png) | 1440 × 900 | Site-type variant retains the same profile family and full navigation. |
| [Archived Site](./site-profile-archived-desktop.png) | 1440 × 900 | Archived state is visible and mutation actions are permission/state shaped. |
| [Restricted viewer](./site-profile-restricted-viewer-desktop.png) | 1440 × 900 | Protected management actions are absent while authorised read surfaces remain available. |
| [Calendar workspace](./site-profile-calendar-desktop.png) | 1440 × 900 | Complete embedded `SiteCalendar`, views and event actions are present. |
| [Checklists workspace](./site-profile-checklists-desktop.png) | 1440 × 900 | Complete embedded `ChecklistsWorkspace`, worklists, tabs and start workflow are present. |
| [Meal Planner workspace](./site-profile-meal-planner-desktop.png) | 1440 × 900 | Planner, Recipes, Shopping List, Inventory, Templates and their actions are present. |
| [Floor Plan workspace](./site-profile-plan-desktop.png) | 1440 × 900 | Full builder/designer, room and wall tools, published-plan surface and emergency layers remain available. |
| [Emergency Plan](./site-profile-emergency-plan-desktop.png) | 1440 × 900 | Correct prerequisite state shown for the empty fixture; complete configured-state depth is locked by focused tests. |
| [Site overview — narrow](./site-profile-overview-narrow.png) | 390 × 844 | `clientWidth = 375`, `scrollWidth = 375`; no horizontal overflow. |
| [Meal Planner — narrow](./site-profile-meal-planner-narrow.png) | 390 × 844 | Responsive guardrail/chart correction verified at `clientWidth = 375`, `scrollWidth = 375`. |
| [Site overview — dark](./site-profile-overview-dark-desktop.png) | 1440 × 900 | Dark theme renders without overflow; body background resolved to `oklch(0.1 0.012 277)`. |

## Workflow evidence

- Edit Site line opened the canonical dialog, moved focus inside it, exposed an accessible description, and closed with Escape.
- Add contact opened the canonical contact dialog, moved focus inside it, and closed with Escape.
- Report hazard resolved to the canonical `/sites/1/hazards/create` workflow.
- Plan a meal opened the meal dialog, moved focus inside it, exposed an accessible description, and closed with Escape.
- Start a checklist switched the complete workspace to its Due now worklist.
- Build Plan opened the full designer with room, wall, door, fire-door, layer and pin tooling.
- Add vendor opened the full Site-context vendor dialog, moved focus inside it, and closed with Escape.
- The hero action menu retained Edit Site, Add Client, Add Event and Report Hazard intents.
- Ctrl+K opened profile search with focus in the search field and Escape closed it.
- ArrowRight moved keyboard focus from Overview to Readiness in the second-tier tab list; the focused tab showed the visible two-pixel focus ring.
- The established `?tab=meal-planner` deep link remained in the address bar and resolved to the internal `meal_planner` tab.
- Final Site-line and Add-meal dialog passes produced zero console errors and zero console warnings.

## Authorization, privacy and loading proof

- A restricted manager fixture could load the Site Profile but could not see protected edit/manage actions.
- Server-side feature coverage proves direct-object denial, permission-shaped data, archived mutation denial, and that credential secrets never enter the profile payload.
- Every full work surface is owned by its own optional Inertia presenter (`clientsData` through `servicesData`). The initial shell does not ship the heavy tab payloads.
- Deferred tabs retain explicit loading, failure and retry states. Transport exceptions clear the loading state and surface retry rather than leaving a false spinner.
- Browser verification covered configured and fallback branding, multiple Site types, archived state, restricted permissions, desktop, narrow width and dark mode.

## Capability closure

The [durable capability ledger](../../site-profile-corrective-capability-ledger.md) contains 149 baseline controls, registers, sub-tabs, dialog intents, endpoints and permission conditions. All 149 are classified with evidence; there are zero Open and zero Removed entries. `Canonical replacement` means shared workflow ownership with full Site-context capability, never a summary-card or link-out replacement.

## Test and build record

| Gate | Result |
| --- | --- |
| Focused frontend matrix | 14 files, 61 tests passed. |
| Focused PHP Site Profile matrix | 51 tests, 477 assertions passed. |
| Corrected vendor deferred-payload contract | 1 test, 49 assertions passed. |
| Broad Sites / Health & Safety / Fleet / Finance matrix | 1,082 tests, 6,733 assertions passed in 1,914.61s. The existing asset-manifest notices were reported as warnings; exit code was 0. |
| TypeScript | `npm run types` passed. |
| Client production build | `npm run build` passed in 5m 16s. |
| SSR build | `npx vite build --ssr` passed in 54.07s. |
| PHP formatting | `vendor/bin/pint --dirty` passed. |
| Prettier | A repository-wide check remains red on 61 pre-existing Site-area files outside this corrective diff. No unrelated formatter churn was introduced; changed-file semantics and all compilation gates pass. |
| Browser | All scenarios above passed with task-owned fixtures and processes. |

## Cleanup and release boundary

The task-owned admin and restricted browser sessions were closed, the exact local PHP server process was stopped, its temporary environment file and Playwright task directory were removed, and all modified fixture values were restored and verified. Unrelated browser sessions and processes were not touched.

No merge, push or deployment was performed.
