# P08a v1 — today’s code vs the design

This audit compares `origin/main` at `d9dc17fa5` (30 September 2026) with the design.

- **Verified**: I read the lines myself in this worktree.
- **Reported**: a code-search agent said so and I haven’t re-read it. Audit agents over-report, so treat these as leads.

Paths are relative to the repository root. Nothing was run against a live site.

## 1. As-needed effect checks

| # | Today | Status | P08a |
|---|---|---|---|
| 1.1 | Two dialogs post to the same endpoint, `/meds/today/prn/effect`:<br>• the worker’s `PrnEffectDialog` (`resources/js/pages/meds/today/components/prn-effect-dialog.tsx:47`);<br>• the three-step `PrnEffectivenessDialog` on PRN records (`resources/js/components/emar/prn-effectiveness-dialog.tsx:170`). | Verified | One “Did it help?” dialog (README §3) |
| 1.2 | The check time is hard-coded to one hour after the dose: `'check_at' => $givenAt?->copy()->addHour()` (`app/Http/Controllers/Emar/WorkerMedsController.php:728`). Nothing marks a check overdue. | Verified (time); reported (no overdue state) | Picked when recording, prefilled 1 hour (Q6). Overdue after that time, carried over at the shift change (Q1). |
| 1.3 | The effect accepts only `effective`, `partially_effective` or `not_effective` (`WorkerMedsController.php:529`). There’s no “couldn’t check”. | Verified | “Couldn’t check”, with a reason and a “check again at” time (Q2) |
| 1.4 | `escalation_needed` and `escalation_action` are stored (`WorkerMedsController.php:535-536`, `:560-561`), and there’s a `TODO(G5): structured "who was notified"` (`:510`). | Verified (stored, TODO); reported (nothing acts on them) | “Someone else needs to know”: who was told (a structured choice) and what was done |
| 1.5 | PRN records’ Reviews tab says “Within 4h of the dose” (`resources/js/pages/emar/PrnRecords.tsx:978`), which disagrees with the one-hour `check_at` (1.2). | Verified | One time, the one picked when recording |
| 1.6 | `PrnDetailDialog` is read-only (`resources/js/components/emar/prn-detail-dialog.tsx`). | Reported | The as-needed dose shows its effect check, owner and state |

## 2. Refusal follow-ups

| # | Today | Status | P08a |
|---|---|---|---|
| 2.1 | `RefusalFollowUpDialog` was deleted as unimported in `7a9a3285e` (29 September 2026, “remove six unimported medication dialogs and widgets”), which is in `origin/main`. No page imports a refusal follow-up UI. | Verified | Rebuilt as “Follow up a refusal” (README §4) |
| 2.2 | The backend still exists: `POST /refusal-followups`, `/refusal-followups/{followup}/complete` and `/refusal-followups/{followup}/notify-gp` (`routes/emar.php:397-405`). | Verified | The design uses these three actions |
| 2.3 | A refusal follow-up is created only by hand, through `RefusalFollowUpController::store` (`app/Http/Controllers/Emar/RefusalFollowUpController.php:121`). No other code creates one, so **recording a refusal creates no follow-up.** | Verified (grep for `MedicationRefusalFollowup::create`) | Created with every refusal (build note 2) |
| 2.4 | Refusal clusters raise `MedicationRefusalClusterNotification` from `SendMedicationAlerts` (`app/Console/Commands/SendMedicationAlerts.php:13`, `:37`). | Verified (call); reported (thresholds) | The 3-in-7-days escalation is P11’s setting. It opens the full refusal form (Q3). |

## 3. Handover

| # | Today | Status | P08a |
|---|---|---|---|
| 3.1 | The handover wizard has a step labelled “Meds, follow-ups & tasks” (`resources/js/pages/operations/handovers/components/handover-wizard.tsx:107`). Follow-ups there are **free-text lines** (`followups: string[]`, `:76`), saved as `follow_up_items_text` joined by new lines (`:717`). They have no owner, due time or link to a record. | Verified | The medication lens: live follow-ups, doses with no outcome, the controlled count and supply (README §7) |
| 3.2 | Only the incoming assigned worker can acknowledge: `canAcknowledge` requires a submitted handover, and the current incoming staff member with `shifts.update` or `shifts.viewAssigned` (`app/Services/ShiftHandoverService.php:1199-1213`). This is enforced at `app/Http/Controllers/Operations/HandoverController.php:311`. | Verified | Kept (Q5). Acknowledging now also sets the owner of carried-over follow-ups (Q1). |
| 3.3 | Nothing gives the house lead a heads-up when a handover isn’t acknowledged. | Reported | A lead follow-up 1 hour into the shift (Q5) |
| 3.4 | `/emar/handovers` isn’t a second handover. It is a “medication-focused eMAR view” of the same shift handovers: `routes/emar.php:286-298` creates, submits and acknowledges them through `ShiftHandoverService`, and `EmarController::handovers` (`app/Http/Controllers/Emar/EmarController.php:3817`) reuses the Operations handover presenter. `/emar/handovers/shift-medications` (`routes/emar.php:163`) already serves a live “Medications this shift” snapshot for the handover. | Verified | One handover. The medication lens is its medication section, and it builds on the existing snapshot endpoint. The `/emar/handovers` URL becomes the register. |
| 3.5 | `/emar/handovers` is a `PageHero` page (`resources/js/pages/emar/Handovers.tsx:5`, `:443`) greeting “Kia ora {firstName}, this week's medication …” (`:481`). | Verified | Safety & oversight › Handovers, with a PageHeader and the register (README §8) |

## 4. Tasks, permissions and alerts

| # | Today | Status | P08a |
|---|---|---|---|
| 4.1 | There are 31 provider files in `app/Services/Tasks/Providers/`. The only medication ones are `MedicationErrorProvider` and `CdLossReportProvider`. **Nothing projects effect checks, refusals or second-person follow-ups.** | Verified (directory listing) | One task per open follow-up (README §9, build note 5) |
| 4.2 | `medications.followups.manage` doesn’t exist: a search of `app/`, `database/`, `routes/` and `config/` finds nothing. | Verified | New key for team_lead, coordinator, clinical_lead and provider_manager (Q4), with a grant migration |
| 4.3 | team_lead has only `medications.view` and `medications.orders.verify` among medication keys (`database/seeders/RbacSeeder.php:889`, `:915`). | Verified | Gets `medications.followups.manage` (Q4) |
| 4.4 | `SendMedicationAlerts` sends overdue alerts for scheduled doses only (`->where('is_prn', false)`, `SendMedicationAlerts.php:55`). Nothing re-alerts an overdue effect check or refusal follow-up. | Verified (filter); reported (no follow-up alerts) | Re-alert and escalation through P11 v5 Delivery. “Not configured” until switched on. |

## 5. Live references (oblivionfindings.test, 30 September 2026, read only)

- **`/emar/handovers`:** a PageHero with the eyebrow “LIVE HANDOVERS · SYNCED” and the greeting “Kia ora Demo, this week’s medication handovers — 28 Sept – 4 Oct”. It has four hero counters (Total, Submitted, Ack’d, Open), a week strip, seven filter tabs, and “New handover”. The demo organisation has no handovers this week.
- **`/emar/prn`:** a PageHero, “PRN records for your services”, with Given, Reviews and Near limit counters, and the tabs Register, Reviews due, Near limit, Trends and History. There are no PRN doses in the demo data.
- Nothing was created or saved.

## 6. For the build (not decided by the mockup)

- **One follow-up record, or a projection over three stores:** the administration (`check_at`), `medication_refusal_followups`, and the handover’s free text. Each follow-up needs an owner, due time, state and history.
- **A grant migration for `medications.followups.manage`**, because deploys skip seeders.
- **Every All Tasks provider for follow-ups must apply EM-12** (controlled-medicine view) **and Site scope.**
- **Carry-over runs at the shift change,** and acknowledgement sets the owner. The original owner stays in the history.
