# Departments — Audit & Design

Audit + plan for a feature-complete New/Edit/View Department. Phase 4 of the
`/hr/people` redesign. (Market context: NZ multi-site supported-living provider;
app is single-tenant.)

## Findings (file:line in the audit agent transcript; summary here)

1. **`HrDepartment`** (`app/Domain/Hr/Models/HrDepartment.php`): name, code, description,
   manager_user_id, parent_id, is_active, sort_order. **No cost_centre, no site link, no
   soft-deletes.** `employees()` is on `department_id`. `name` unique per tenant; `code` not unique.
2. **Migration** `2026_03_31_000001_create_hr_departments_table.php` is the only one (also adds
   `hr_employee_profiles.department_id`).
3. **DepartmentController** store/update/destroy. **Self-parent guarded only on `update()` (not store)**;
   **no cycle check** (A→B→A possible); `destroy()` = soft-deactivate (`is_active=false`), blocks if
   active employees, **leaves children dangling**. Gate `hr.settings.manage|hr.employees.manage`
   (canDo + route middleware). Tenant resolved inline as `$user->tenant_id` (null) — NOT
   `ResolvesHrTenant` (inconsistent; departments end up tenant_id null).
4. **No `show`/View route or method.** No department detail surface exists at all.
5. **DepartmentDialog** is a **plain form**, not the WizardShell. departments-pane shows Edit +
   Deactivate; no View.
6. **Headcount roll-up: direct only** (`withCount(employees active)`); no subtree roll-up anywhere.
7. **Positions ↔ department = name STRING match** (`HrPosition.department == HrDepartment.name`).
   Employees use `department_id` FK (people filter) + `department` string (denormalised label,
   bulk-assign dual-writes both). So View "linked positions" = `HrPosition::where('department', name)`;
   roll-up headcount goes through `department_id`.
8. **Position "view" is a full page** (`positions/show.tsx` via `PositionController@show`), not a modal.
   The People hub is modal-first → Department View will be a **read-only modal** (mirrors that page's
   sections: hero, details, headcount stats, hierarchy, employees).
9. **Sites**: exists (type, tenant). **Defer** a department↔site link (out of scope; employees already
   carry primary_site_id).
10. **Tests**: only `PeopleHubDepartmentsTest` (index redirect + create); no update/destroy/cycle/view.

## Decisions

1. **cost_centre = plain nullable string** column (free-text payroll/GL label; do NOT FK to the Finance
   `fin_cost_centres` domain — kept separate). Add to fillable + request rules.
2. **Defer the site link** (clean seam left in the View's stats card).
3. **Fix integrity (do this before the roll-up):** self-parent guard on `store` too; **cycle-safe
   parent validation on update** (walk ancestor chain of the proposed parent; reject if it reaches the
   department). Both `descendantIds()`/ancestor walks use a visited-set so an *existing* bad cycle can't
   infinite-loop.
4. **Headcount roll-up:** `HrDepartment::descendantIds()` (cycle-safe) + a rolled-up active-employee
   count over `[self + descendants]` via `department_id`. Surfaced on the View modal.
5. **Deactivate semantics:** block if the department has **active employees** (existing); **reparent its
   active child departments to this department's parent** (so the tree stays connected, no dangling) —
   instead of today's silent-dangle. Keep deactivate-not-delete (no hard delete / soft-delete this phase).
6. **View = read-only modal** opened from the departments-pane (row click + a View action). A new
   `GET /hr/departments/{department}` returns JSON (department + head + parent + children + direct &
   rolled-up headcount + linked positions) for the modal to fetch.
7. **Wizard parity:** rebuild `DepartmentDialog` on the WizardShell — Details (name/code/cost_centre/
   description) → Structure (parent/head/sort order/status) → Review. Edit reuses it prefilled.
8. **Tenant consistency:** use `resolveHrTenantIdForUser` in the controller (like PositionController).
9. Permissions unchanged (`hr.settings.manage|hr.employees.manage`); View gated the same.

## Build order
- **4a (backend):** migration (`cost_centre`); model (cost_centre fillable + `descendantIds()` +
  `rolledUpEmployeeCount()`); controller (cost_centre in store/update, self-parent on store, cycle-safe
  update, reparent-children on deactivate, new `show()` JSON, tenant via resolver); `departments.show`
  route. Tests (cycle guard, reparent, roll-up, show).
- **4b (frontend):** DepartmentDialog → WizardShell (+ cost_centre); Department View modal; pane wiring
  (cost-centre column, row→View).
