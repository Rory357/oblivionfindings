# Performance & Development Hub — Permissions Architecture

This records the deliberate authorization design for the unified
`/hr/performance` hub and its constellation (reviews, supervision, goals,
development, competencies & skills, 360 feedback, PIPs, succession). It exists so
the next person doesn't "fix" something that was decided on purpose.

## Canonical ability set: `hr.performance.*`

The whole constellation is gated on two abilities:

- **`hr.performance.view`** — read the hub and any sub-list/detail.
- **`hr.performance.manage`** — every manager mutation (create/edit/transition/
  sign-off/assess/upload/delete).

We **intentionally did not** fragment this into `hr.feedback.*`,
`hr.succession.*`, `hr.goals.*`, `hr.competencies.*`, etc., even though the gap
audit floated it. Reasons:

1. **Deploy-seeder trap.** Permissions are *seeded*, not migrated, and deploys
   skip seeders (see `reference_deploy_seeders`). A brand-new ability ships as a
   route guard that nobody holds until a `*PermissionsSeeder --force` runs on the
   server — so the feature 403s for everyone post-deploy. New ability keys would
   each need a dedicated grant-migration. `hr.performance.*` already exists and is
   already granted to the right roles, so the hub works the moment code lands.
2. **No security benefit.** The app is effectively single-tenant
   (`project_clientpolicy_org_isolation`). One coherent "can this person manage
   performance?" ability is the real boundary; splitting it adds RBAC surface to
   maintain with no isolation gain.
3. **Consistency.** Reviews, supervision, competencies, PIPs already lived under
   `hr.performance.*`; goals/feedback/succession controllers already accept it via
   their `canView()/canManage()` helpers. Keeping one set matches the module.

## The one real bug that WAS fixed (P0)

360-feedback **write** routes (`request.store`, `templates.*`) were previously
nested only under `permission:hr.performance.view`, so any viewer could create
cycles and CRUD templates. These moved under a `permission:hr.performance.manage`
sub-group. Reviewer **response** routes deliberately stay at `.view` (a reviewer
giving feedback is not a manager). See `routes/hr.php` → 360 Feedback group.

## Authorization mechanism (the codebase convention)

This codebase authorizes with **custom RBAC `canDo()` checks**, not Laravel
Gate/Policy classes. The hub follows that convention rather than introducing
parallel Policy classes that would duplicate the same `canDo()` predicate (dead
code unless wired through `authorize()`, and inconsistent with every neighbouring
HR controller). Each request is guarded by three layers:

1. **Route middleware** — `permission:hr.performance.view|manage` on the group.
2. **Controller guard** — every action opens with
   `abort_unless($user->canDo('hr.performance.…'), 403)` (or the
   `canView()/canManage()` helper). Employee-side actions
   (review/supervision/PIP `acknowledge`) additionally allow the record's own
   subject: `abort_unless($record->employee_user_id === $user->id || canManage)`.
3. **Tenant scope** — `ResolvesHrTenant::assertHrTenantAccess()` rejects
   cross-tenant record access on every record-bound mutation.

Evidence attachments add a fourth layer: they are stored on the **private** disk
and streamed through `ServesPrivateAttachments` (CSP sandbox + mime allowlist),
never reachable at a public `/storage` URL.

## Governance performance reviews (separate module)

The board-level `governance.performance.*` reviews are a distinct surface with
their own abilities. The previously-unrouted model transitions
(`submitSelfAssessment`, `approve`) were wired to guarded controller actions under
`permission:governance.performance.manage` — they do **not** share the HR hub's
ability set.

## If you ever DO need finer-grained abilities

Ship them the safe way: add the key to the RBAC seeder **and** a grant-migration
that backfills the grant onto existing roles in the same deploy, then repoint the
routes. Do not add a route guard for an ungranted ability.
