# Site Profile corrective redesign post-audit

Date: 2026-07-22

Branch: `codex/site-profile-redesign`

Baseline capability commit: `b5b5df463ce788fbbf988c74f5142b7fcbb52628`

Approved specification: `docs/superpowers/specs/2026-07-22-site-profile-corrective-redesign-design.md`

## Outcome

The corrective redesign is complete. Site Profile now belongs to the actual Client Profile design family defined by `resources/js/pages/operations/clients/show.tsx` and `resources/js/components/clients/profile/hero.tsx`: a dedicated profile hero, alert ribbon, grouped hero navigation, matching spacing, second-tier tabs and full-depth tab work surfaces. It intentionally has no recent-sites strip.

Site branding is semantic. A configured Site `brand_colour` wins; otherwise the organisation primary colour is used. There is no hard-coded Claude-green default.

The earlier summary-card interpretation has been removed. Deduplication now applies only to implementation and workflow ownership: canonical services, policies, presenters, forms and dialogs are reused while Site Profile keeps its complete Site-context registers and actions.

## Capability preservation

The redesign preserves the pre-redesign capability baseline across every profile group:

- Overview: profile summary, readiness, attention, geofence, Site lines, notes and established hero actions.
- People: clients/residents, contacts, staff requirements and shift coverage with their complete registers and action intents.
- Safety: hazards, risk assessments, inspections, drills, first aid, PPE and the complete Emergency Plan.
- Operations: the complete embedded Calendar and event workflows; complete embedded Checklists workspace; complete House Meal Planner with Planner, Recipes, Shopping List, Inventory, Templates, dialogs and actions; Assets, Fleet and Hardware; and the full Floor Plan/Rooms builder, published plan, inventory, assignments, emergency layer and pins.
- Administration: Documents, Financials and House Ledger, Vendors, Credentials and Services with full Site-context data and permission-shaped management actions.

The obsolete duplicate `module-summary-panel.tsx` implementation was deleted. Canonical replacements do not remove user capability.

## Architecture and payload

The useful branch work was retained: attention aggregation, placement service, authorization shaping, brand payload, UI preferences and deferred loading.

Heavy content is split into one optional Inertia payload per tab rather than a single aggregate payload. The shell remains small, deep links load the correct data group, and each tab owns loading, error and retry behaviour. The established hyphenated `meal-planner` URL is preserved while the internal registry continues to use `meal_planner`.

Server presenters enforce permission and privacy shaping before data reaches React. Credential secrets remain owned by canonical reveal endpoints and are never included in the Site Profile payload.

## Durable ledger

`docs/site-profile-corrective-capability-ledger.md` is the exhaustive baseline ledger. It contains 149 evidence-backed rows and classifies every known pre-redesign control, register, sub-tab, dialog intent, endpoint and permission condition as Restored, Canonical replacement or Improved. Closure is 149/149, with zero Open and zero Removed entries.

## Accessibility and responsiveness

- Profile navigation supports keyboard arrow movement and visible focus indication.
- Interactive tabs and action buttons retain minimum 44px targets.
- Site-line and meal dialogs expose accessible descriptions, move focus into the dialog and close with Escape.
- Narrow-web evidence proves no horizontal overflow on Overview or the dense Meal Planner chart (`clientWidth = 375`, `scrollWidth = 375`).
- Dark mode and configured/fallback brand colours were verified in a real browser.

## Verification

| Gate | Result |
| --- | --- |
| Capability ledger contract | 149/149 classified; 0 Open; 0 Removed. |
| Frontend profile matrix | 14 test files, 61 tests passed. |
| Focused PHP authorization/payload/workflow matrix | 51 tests, 477 assertions passed. |
| Corrected vendor full-payload regression | 1 test, 49 assertions passed. |
| Broad Sites / Health & Safety / Fleet / Finance matrix | 1,082 tests, 6,733 assertions passed in 1,914.61s; existing asset-manifest notices were warnings and exit code was 0. |
| TypeScript | `npm run types` passed. |
| Client build | `npm run build` passed in 5m 16s. |
| SSR build | `npx vite build --ssr` passed in 54.07s. |
| PHP formatting | `vendor/bin/pint --dirty` passed. |
| Repository-wide Prettier check | Remains red on 61 pre-existing Site-area files outside this corrective diff; broad formatting churn was rejected. |
| Real browser | Passed desktop and 390×844 narrow comparison against actual Client Profile, restricted permissions, multiple Site types, archived state, deep links, keyboard/focus, dark mode and representative modal/action workflows. |

The complete browser matrix and images are recorded in `docs/evidence/site-profile-corrective-redesign/index.md`.

## Release boundary

No merge, push or deployment was performed. Browser fixtures and task-owned processes were restored or stopped, and unrelated sessions were left untouched.
