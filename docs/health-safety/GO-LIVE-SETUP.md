# Health & Safety: go-live setup on a fresh database

Stephan decided on 6 Nov 2026 that real go-live starts with **a fresh, empty database**. This checklist covers what
H&S needs on that database before real people use it. Each step says who does it.

Work through it in order. Steps 1–3 are for whoever runs the server. Steps 4–9 are for an administrator in the app,
using the real staff and Sites. Step 10 is a check before the first real report.

---

## 1. Build the database (server)

1. Create the empty database and point `.env` at it. Set `DEMO_SITE=false`.
2. Run the migrations: `php artisan migrate --force`.
   - The migrations also apply every permission grant and revoke decided so far, for example the 6 Nov removal of
     restraint access from maintenance coordinators.
3. **Don't run `php artisan db:seed` on its own.** The default `DatabaseSeeder` loads the full demo dataset: demo staff,
   clients, incidents, safeguarding concerns, the H&S demo records and more. Run only the seeders in step 2.
4. **Never run** `health-safety:demo`, `HealthSafetyDemoSeeder`, `HealthSafetyBillingDemoSeeder`, `PpeSeeder`,
   `SafeguardingSeeder`, `SystemIncidentsSeeder` or any seeder with "Demo" in its name.

## 2. Seed what the system needs (server)

Run each of these with `php artisan db:seed --class=<Name> --force`, in this order:

| Seeder | What it gives H&S |
|---|---|
| `RbacSeeder` | Roles, including `health_safety_officer`, and every H&S permission with its role defaults. |
| `GovernancePermissionsSeeder` | `governance.view` and related keys, needed for the H&S governance reports. |
| `RoleCatalogSeeder` | Role labels and descriptions shown in Settings › Roles. |
| `SeedAllPermissionsToAdminSeeder` | Gives the admin role every ordinary permission. It never gives the restricted independent keys. |
| `ConsentTypesSeeder`, `StandardConsentTypesSeeder` | Consent types, used by family portal sharing and resident tracking. |
| `NzComplianceObligationsSeeder` | The NZ compliance obligations register, including HSWA duties. |
| `TrainingCoursesSeeder` | Training courses that H&S training requirements and HSR training can point to. |

Other modules' system seeders (HR, Finance, IT and so on) belong to their own go-live lists.

**Needs no seeding:** the WorkSafe notifiable-event questions. They are part of the app's code
(`NotifiableEventClassifier`). Their content has a **next mandatory review date of 1 April 2027**, linked to the 2027
law changes, so put that date in a calendar now. Nothing in the app reminds anyone.

## 3. Turn on the background jobs (server)

H&S relies on the scheduler and the queue worker:

- `php artisan schedule:run` every minute (cron). It runs, among others: overdue hazards, HSWA participation reminders
  (`participation:sync-obligations`), the weekly retention clean-up (`oblivion:prune-retention`), notification
  escalation and the Control Room jobs.
- A queue worker that listens on **both** the `default` and `notifications` queues. `HazardOverdueJob` and other H&S
  notices use `notifications`.
- Deploy outside working hours, or set up a no-downtime deploy. A normal deploy shows users errors for 1–2 minutes.

## 4. Sites (administrator)

- Add every real Site, with its type and address. Leave out any Site that isn't open yet, or keep it inactive.
- Check each Site's H&S basics. The Site profile offers recommended hazards to add. They now use NZ wording: hot water
  above 45 °C at the tap, and hazardous substances under the Hazardous Substances Regulations.

## 5. Staff HR profiles (HR)

H&S finds people through their HR profile, not their login. For every staff member:

- **Active, with a start date**, and no end date unless they have left.
- **Primary Site, plus any secondary Sites** where they work. This decides which Sites' H&S records, pickers and notices
  they get. With no Site, the first aid and injury forms can't list them, and they get no H&S notices.
- **"First aider" ticked** for everyone trained in first aid. Without it, the first aid form's first-aider list is empty
  at that Site (it now says why).

## 6. Roles (administrator, Settings › Roles)

- Give the **`health_safety_officer`** role to the real H&S staff.
  - Overdue hazards go to the hazard's assignee and the H&S officers at that Site. With nobody holding the role, an
    overdue hazard with no assignee reaches no one. It is logged and retried daily until someone can get it.
  - HSWA participation reminders (committee meetings, HSR terms, HSR training) go to the first approved H&S officer,
    or an admin if there is none.
- Check that each role holder also has the right HR profile Sites (step 5). A role doesn't widen Sites; only
  `healthSafety.viewAllSites` does, and only a few people should have it.
- **Restricted keys are granted one person at a time**, never by role or to admin automatically. For H&S:
  `healthSafety.events.close`, `healthSafety.events.closeAny`, `healthSafety.closureExceptions.request`,
  `healthSafety.closureExceptions.approve` (close anyway), `restraints.approve` and
  `safeguarding.declassification.approve`. Give them to named people only (Settings › Users › permissions).

## 7. An approved H&S owner at every Site (administrator + HR)

A safety review can only be taken on by an **approved H&S owner for its Site**: someone who holds H&S manage access
(`hazards.manage` and `hazards.view`, which H&S officers and provider managers have) **and** whose HR profile lists
that Site. Admin isn't one unless their HR profile lists the Site.

- For each Site, make sure at least one such person exists, and ideally two for leave cover.
- Without one, "Take it on" and accepting an incident handover are refused at that Site.

## 8. Safeguarding leads (administrator)

- Decide who leads safeguarding at each Site. They need safeguarding access, and their HR profile must list the Site.
- Decide who may see need-to-know (sensitive) concerns (`safeguarding.viewSensitive`). Keep it to named leads.
- Decide who may close any concern, or close with open work (`safeguarding.closeAny`, `safeguarding.terminalOverride`).
  The provider manager role has both by default. Take them off the role in Settings › Roles if only named leads should
  have them.

## 9. HSWA participation (H&S officer, Health & Safety › Worker participation)

If the participation reminders are wanted:

- Enter each **H&S committee**: its Site, when it was set up, and how often it meets. A committee must meet at least
  every 3 months. Only meetings marked held count; a cancelled one doesn't.
- Enter each **H&S representative**: elected date, term end (up to 3 years) and training days so far. The app expects
  2 days of training for each year in office.

## 10. Before the first real report (H&S officer)

- Report a test incident at one Site as a support worker, on a test client set up for this check, and follow it
  through to its safety review. Check that:
  - the right people got the notice, and only them (Site and access);
  - an approved H&S owner can take the safety review on;
  - first aid and injury forms list that Site's first aiders and staff.
- Then mark the test records entered in error, or withdraw them, with the reason "Go-live check".
- Check Settings › Data › Retention. Audit logs default to 5 years. H&S record history (incidents, restraints,
  safeguarding, hazards, first aid, injuries, exposures and safety reviews) is always kept at least 10 years,
  whatever that setting says.

---

**Still for Stephan's legal check (not changed by the app):** the WorkSafe notifiable-event questions and their
guidance, and the HSR line "NZQA US 29315 required before issuing PINs / cease-work".
