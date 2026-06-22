# Org Chart — Research & Design

Research + plan for the Org Chart rebuild (connected top-down tree + a "Build org
chart" drag-to-reassign modal). Phase 5 of the `/hr/people` redesign.

## Current state (file:line in the research-agent transcript; summary)

- **`org-chart-pane.tsx`**: renders a backend-built `OrgNode[]` tree (recursive `OrgNodeCard`, CSS
  connectors, per-card collapse [not persisted], search-filter). Node card = photo/initials circle on
  top + name + position + department. **No drag-and-drop.** `ReassignManagerDialog` (a `PeoplePicker`)
  → `PUT /hr/orgchart/{profile.id}` `{ manager_user_id }`.
- **`OrgChartService`**: `getHierarchy` → `buildNode` returns `id, user_id, name, email,
  position_title, department, profile_photo_path (raw), children`. `wouldCreateCycle` is a robust
  visited-set ancestor walk. `updateManager` = one-line update.
- **`OrgChartController@update`**: `PUT /hr/orgchart/{profile}` (gate `hr.employees.manage`),
  cycle-guarded, flash on cycle. **No bulk endpoint.**
- **`EmployeeProfileController@index`**: builds `orgHierarchy` (nodes w/ photo) + `orgPeople`
  (**only `user_id/name/position_title`** — no photo/site/manager).
- **`@dnd-kit` is already a dependency** (core/sortable/utilities); reference pattern
  `resources/js/pages/sites/rooms/index.tsx`. Use `useDraggable` + `useDroppable` for reassign.
- ⚠️ `profile_photo_path` is passed **raw** (no `Storage::url()` accessor) → won't resolve as an
  `<img src>` if it's a bare disk path; initials fallback only triggers on a falsy value.
- Tests: `OrgChartReassignTest` (4 — index redirect, hierarchy prop, reassign, self-report reject).

## Market research (concise)

Drag-first builders (Workday Org Studio, ChartHop, Pingboard Planning) model reorgs as **drafts you
publish** with mass-apply — justified at enterprise scale. HRIS-native tools (BambooHR, HiBob, Deel)
**save live** because each change is one employee's manager field. All keep a **reports-to picker /
search** alongside drag for off-screen targets. Readability at scale: collapse, depth cap,
search-to-center, focus-subtree. Guardrails: no cycles (we have it), single manager (we have it),
span-of-control warnings (nice-to-have), confirm large subtree moves.

## Decisions

1. **Save live, per-move** — NOT a draft/scenario engine. Our scale (~20–200 staff) makes reorgs a
   handful of individual reassignments; we already have an atomic, cycle-guarded `orgchart.update`.
   Each drop fires one `PUT /hr/orgchart/{profile}` `{ manager_user_id }`.
2. **Drag-on-canvas builder + reports-to picker fallback** (the dual-mode every tool ships). Use
   **`@dnd-kit/core`** (`DndContext` + `useDraggable` person cards + `useDroppable` manager/root
   targets), matching `sites/rooms/index.tsx`. Keep the existing `PeoplePicker` reassign for
   off-screen targets + top-level (`null`) moves.
3. **No bulk endpoint for v1** — per-move PUT (atomic, already cycle-guarded). A
   `POST /hr/orgchart/bulk` is a fast-follow only if batching/draft is added later.
4. **Client cycle pre-check** mirrors the server guard (block dropping a node onto its own
   descendant / itself) for instant feedback; the server stays the source of truth.
5. **Widen payloads (additive):** `buildNode` → add `site` + `manager_user_id` + a resolved
   `photo_url`; `orgPeople` → add `photo_url` + `site` + `manager_user_id`. Fix the raw-photo bug by
   resolving the URL server-side.
6. **View rebuild:** connected top-down tree with **colour-coded title bars** (keyed to department),
   square photo-left node cards, name + site; toolbar with search, expand/collapse all, print, and
   the **Build org chart** button.
7. **Span-of-control**: show a subtle direct-reports count on cards; warn (non-blocking) past a
   threshold in the builder — nice-to-have, include if cheap.

## Build order
- **5a (backend + view):** widen `buildNode` (site, manager_user_id, photo_url) + `orgPeople`
  (photo_url, site, manager_user_id); rebuild `org-chart-pane` node card (colour-coded title bar,
  photo-left, site) + toolbar (expand/collapse all, print). Keep the per-person reassign dialog.
- **5b (builder modal):** `org-chart-builder-dialog.tsx` — dnd-kit drag-to-reassign (cycle-safe
  client pre-check) writing live via `orgchart.update`, + reports-to picker fallback. Wire the
  "Build org chart" button. Extend `OrgChartReassignTest`.
