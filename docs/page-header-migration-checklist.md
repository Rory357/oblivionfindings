# Page header migration checklist — Event Horizon

Generated 2026-09-07 from every `Inertia::render()` call in `app/` and `routes/`.
The only permitted page-top pattern is `PageHeader` from `resources/js/components/page/page-header.tsx`
(see `design_styles/PAGE_HEADER_STYLE_GUIDE.md`). Tags below show what the page uses today:

- **PageHero** — the superseded banner system (`components/page/page-hero.tsx`)
- **custom** — no shared hero at all (hand-rolled header, plain heading, or module-specific hero)

**Probably out of scope (decide before migrating):** the public marketing pages
(`about`, `contact`, `features`, `home`, `pricing`, `terms`), the public applicant
pages under `careers/`, and the client/family `portal/` pages don't run inside the
app shell, so the Event Horizon header may not apply — they're listed in their
module sections below but flagged here so the real migration count isn't inflated.
Auth pages (`auth/*`) never appear because they aren't rendered via
`Inertia::render` in `app/`/`routes/` (Fortify config) and are out of scope anyway.

## Done (4)

- [x] `operations/clients/index`
- [x] `operations/clients/show`
- [x] `sites/index`
- [x] `sites/show`

## To migrate, by module

### Governance (68)

- [ ] `Governance/Actions/Index` — PageHero
- [ ] `Governance/Actions/Show` — PageHero
- [ ] `Governance/Admin/BoardMembers` — PageHero
- [ ] `Governance/AuditLog/Index` — PageHero
- [ ] `Governance/Budgets/Create` — custom
- [ ] `Governance/Budgets/Edit` — PageHero
- [ ] `Governance/Budgets/Index` — PageHero
- [ ] `Governance/Budgets/Show` — PageHero
- [ ] `Governance/CeoReports/Create` — PageHero
- [ ] `Governance/CeoReports/Index` — PageHero
- [ ] `Governance/CeoReports/Show` — PageHero
- [ ] `Governance/Clinical/Dashboard` — PageHero
- [ ] `Governance/Clinical/Trends` — PageHero
- [ ] `Governance/Compliance/Calendar` — PageHero
- [ ] `Governance/Compliance/Create` — PageHero
- [ ] `Governance/Compliance/Edit` — PageHero
- [ ] `Governance/Compliance/Index` — PageHero
- [ ] `Governance/Compliance/Show` — PageHero
- [ ] `Governance/Dashboard` — PageHero
- [ ] `Governance/Documents/Index` — PageHero
- [ ] `Governance/Documents/Show` — PageHero
- [ ] `Governance/Evaluations/Create` — PageHero
- [ ] `Governance/Evaluations/Index` — PageHero
- [ ] `Governance/Evaluations/Results` — PageHero
- [ ] `Governance/Evaluations/Show` — PageHero
- [ ] `Governance/Interests/Index` — PageHero
- [ ] `Governance/Interests/MyInterests` — PageHero
- [ ] `Governance/Meetings/Calendar` — PageHero
- [ ] `Governance/Meetings/Create` — custom
- [ ] `Governance/Meetings/Edit` — custom
- [ ] `Governance/Meetings/Index` — PageHero
- [ ] `Governance/Meetings/Show` — PageHero
- [ ] `Governance/Packs/Index` — PageHero
- [ ] `Governance/Packs/Show` — PageHero
- [ ] `Governance/Performance/Create` — PageHero
- [ ] `Governance/Performance/Edit` — PageHero
- [ ] `Governance/Performance/Index` — PageHero
- [ ] `Governance/Performance/Show` — PageHero
- [ ] `Governance/Policies/Attestations` — PageHero
- [ ] `Governance/Policies/Create` — PageHero
- [ ] `Governance/Policies/Edit` — PageHero
- [ ] `Governance/Policies/Index` — PageHero
- [ ] `Governance/Policies/Show` — PageHero
- [ ] `Governance/Reports/BoardMonthly` — PageHero
- [ ] `Governance/Reports/Committee` — PageHero
- [ ] `Governance/Reports/ComplianceStatus` — PageHero
- [ ] `Governance/Reports/RiskNarrative` — PageHero
- [ ] `Governance/Resolutions/Create` — PageHero
- [ ] `Governance/Resolutions/Index` — PageHero
- [ ] `Governance/Resolutions/Show` — PageHero
- [ ] `Governance/Risks/Committee` — PageHero
- [ ] `Governance/Risks/Create` — PageHero
- [ ] `Governance/Risks/Edit` — PageHero
- [ ] `Governance/Risks/Heatmap` — PageHero
- [ ] `Governance/Risks/Index` — PageHero
- [ ] `Governance/Risks/Show` — PageHero
- [ ] `Governance/Risks/Trends` — PageHero
- [ ] `Governance/Settings/Index` — PageHero
- [ ] `Governance/SpendApprovals/Create` — PageHero
- [ ] `Governance/SpendApprovals/Edit` — PageHero
- [ ] `Governance/SpendApprovals/Index` — PageHero
- [ ] `Governance/SpendApprovals/Show` — PageHero
- [ ] `Governance/Strategy/Changes` — PageHero
- [ ] `Governance/Strategy/Create` — PageHero
- [ ] `Governance/Strategy/Edit` — PageHero
- [ ] `Governance/Strategy/Index` — PageHero
- [ ] `Governance/Strategy/Show` — PageHero
- [ ] `Governance/TeTiriti/Index` — PageHero

### Roadmap (6)

- [ ] `Roadmap/Dashboard` — PageHero
- [ ] `Roadmap/Decisions/Index` — PageHero
- [ ] `Roadmap/Initiatives/Index` — PageHero
- [ ] `Roadmap/QuarterlyPlans/Index` — PageHero
- [ ] `Roadmap/QuarterlyPlans/Show` — PageHero
- [ ] `Roadmap/Suggestions/Index` — PageHero

### about (1)

- [ ] `about` — custom

### attendance (1)

- [ ] `attendance/index` — PageHero

### audit (1)

- [ ] `audit/index` — custom

### calendar (1)

- [ ] `calendar/global` — custom

### careers (5)

- [ ] `careers/application-status` — PageHero
- [ ] `careers/apply` — PageHero
- [ ] `careers/index` — PageHero
- [ ] `careers/offer-response` — PageHero
- [ ] `careers/reference-questionnaire` — PageHero

### catering (1)

- [ ] `catering/meal-planner` — custom

### checklists (1)

- [ ] `checklists/index` — custom

### clients (1)

- [ ] `clients/Financials` — PageHero

### compliance (2)

- [ ] `compliance/hazards/index` — custom
- [ ] `compliance/index` — custom

### contact (1)

- [ ] `contact` — custom

### control-room (21)

- [ ] `control-room/alerts/index` — PageHero
- [ ] `control-room/broadcast` — custom
- [ ] `control-room/broadcast-show` — custom
- [ ] `control-room/devices/index` — custom
- [ ] `control-room/devices/show` — custom
- [ ] `control-room/escalations` — custom
- [ ] `control-room/incidents` — custom
- [ ] `control-room/index` — custom
- [ ] `control-room/map` — custom
- [ ] `control-room/messaging` — custom
- [ ] `control-room/my-tasks` — custom
- [ ] `control-room/playbooks/index` — custom
- [ ] `control-room/playbooks/show` — custom
- [ ] `control-room/reports` — custom
- [ ] `control-room/settings` — custom
- [ ] `control-room/shifts` — custom
- [ ] `control-room/shifts/handover` — PageHero
- [ ] `control-room/show` — custom
- [ ] `control-room/sla/breaches` — custom
- [ ] `control-room/sla/index` — custom
- [ ] `control-room/stats` — custom

### dashboard (2)

- [ ] `dashboard` — custom
- [ ] `dashboard/today` — PageHero

### emar (17)

- [ ] `emar/AuditLog` — PageHero
- [ ] `emar/Competency` — PageHero
- [ ] `emar/ControlledDrugs` — PageHero
- [ ] `emar/Destructions` — PageHero
- [ ] `emar/Handovers` — PageHero
- [ ] `emar/Index` — PageHero
- [ ] `emar/MarCharts` — PageHero
- [ ] `emar/MedicationErrors` — PageHero
- [ ] `emar/Medications` — PageHero
- [ ] `emar/Prescriptions` — PageHero
- [ ] `emar/PrnRecords` — PageHero
- [ ] `emar/Reports` — PageHero
- [ ] `emar/Reviews` — PageHero
- [ ] `emar/Rounds` — PageHero
- [ ] `emar/SelfAdmin` — PageHero
- [ ] `emar/Settings` — PageHero
- [ ] `emar/StockManagement` — PageHero

### emergency (1)

- [ ] `emergency/access` — PageHero

### features (1)

- [ ] `features` — custom

### finance (92)

- [ ] `finance/Calendar` — PageHero
- [ ] `finance/CashFlowForecast/Index` — PageHero
- [ ] `finance/CashFlowForecast/Show` — PageHero
- [ ] `finance/Consolidation/Index` — PageHero
- [ ] `finance/Consolidation/RunResults` — PageHero
- [ ] `finance/Consolidation/Show` — PageHero
- [ ] `finance/Dashboard` — PageHero
- [ ] `finance/Integrations/Index` — PageHero
- [ ] `finance/Integrations/Mapping` — PageHero
- [ ] `finance/Intercompany/Index` — PageHero
- [ ] `finance/IrdFilings/Index` — PageHero
- [ ] `finance/IrdFilings/Show` — PageHero
- [ ] `finance/accounts/Create` — PageHero
- [ ] `finance/accounts/Edit` — PageHero
- [ ] `finance/accounts/Index` — PageHero
- [ ] `finance/accounts/Show` — PageHero
- [ ] `finance/audit-exports/Index` — PageHero
- [ ] `finance/bank-accounts/Index` — PageHero
- [ ] `finance/bank-accounts/Show` — PageHero
- [ ] `finance/bank-feeds/Index` — PageHero
- [ ] `finance/bank-feeds/Logs` — PageHero
- [ ] `finance/bank-reconciliation/Create` — PageHero
- [ ] `finance/bank-reconciliation/Index` — PageHero
- [ ] `finance/bank-reconciliation/Reconcile` — PageHero
- [ ] `finance/bank-transactions/Index` — PageHero
- [ ] `finance/billing/Entries` — PageHero
- [ ] `finance/billing/Index` — PageHero
- [ ] `finance/bills/Create` — PageHero
- [ ] `finance/bills/Edit` — PageHero
- [ ] `finance/bills/Index` — PageHero
- [ ] `finance/bills/Show` — PageHero
- [ ] `finance/cash-position/Index` — PageHero
- [ ] `finance/cost-centres/Index` — PageHero
- [ ] `finance/credit-notes/Index` — PageHero
- [ ] `finance/credit-notes/Show` — PageHero
- [ ] `finance/currencies/Index` — PageHero
- [ ] `finance/donor-funds/Index` — PageHero
- [ ] `finance/donor-funds/Show` — PageHero
- [ ] `finance/eftpos/BatchDetail` — PageHero
- [ ] `finance/eftpos/Batches` — PageHero
- [ ] `finance/eftpos/Terminals` — PageHero
- [ ] `finance/executive-dashboard/Index` — PageHero
- [ ] `finance/fiscal-periods/Index` — PageHero
- [ ] `finance/fixed-assets/Index` — PageHero
- [ ] `finance/fixed-assets/Show` — PageHero
- [ ] `finance/funding-streams/Index` — PageHero
- [ ] `finance/fx-revaluations/Create` — PageHero
- [ ] `finance/fx-revaluations/Index` — PageHero
- [ ] `finance/gst-returns/Index` — PageHero
- [ ] `finance/gst-returns/Prepare` — PageHero
- [ ] `finance/gst-returns/Show` — PageHero
- [ ] `finance/invoices/Create` — PageHero
- [ ] `finance/invoices/Edit` — PageHero
- [ ] `finance/invoices/Index` — PageHero
- [ ] `finance/invoices/Show` — PageHero
- [ ] `finance/journals/Create` — PageHero
- [ ] `finance/journals/Index` — PageHero
- [ ] `finance/journals/Show` — PageHero
- [ ] `finance/match-rules/Index` — PageHero
- [ ] `finance/payment-allocations/Index` — PageHero
- [ ] `finance/payment-matching/Index` — PageHero
- [ ] `finance/payment-runs/Create` — PageHero
- [ ] `finance/payment-runs/Index` — PageHero
- [ ] `finance/payment-runs/Show` — PageHero
- [ ] `finance/petty-cash/Index` — PageHero
- [ ] `finance/petty-cash/Show` — PageHero
- [ ] `finance/price-books/Index` — PageHero
- [ ] `finance/price-books/Show` — PageHero
- [ ] `finance/purchase-orders/Create` — PageHero
- [ ] `finance/purchase-orders/Edit` — PageHero
- [ ] `finance/purchase-orders/Index` — PageHero
- [ ] `finance/purchase-orders/Show` — PageHero
- [ ] `finance/quotes/Index` — PageHero
- [ ] `finance/quotes/Show` — PageHero
- [ ] `finance/receivables/Aging` — PageHero
- [ ] `finance/receivables/Index` — PageHero
- [ ] `finance/receivables/Statements` — PageHero
- [ ] `finance/recurring-charges/Index` — PageHero
- [ ] `finance/reports/AgedPayables` — PageHero
- [ ] `finance/reports/AgedReceivables` — PageHero
- [ ] `finance/reports/BalanceSheet` — PageHero
- [ ] `finance/reports/BudgetVsActuals` — PageHero
- [ ] `finance/reports/CashFlow` — PageHero
- [ ] `finance/reports/FundingStreamSummary` — PageHero
- [ ] `finance/reports/ProfitAndLoss` — PageHero
- [ ] `finance/reports/TrialBalance` — PageHero
- [ ] `finance/site-dashboard/Show` — PageHero
- [ ] `finance/sites-overview/Show` — PageHero
- [ ] `finance/vendors/Create` — PageHero
- [ ] `finance/vendors/Edit` — PageHero
- [ ] `finance/vendors/Index` — PageHero
- [ ] `finance/vendors/Show` — PageHero

### fleet-assets (46)

- [ ] `fleet-assets/alerts/index` — custom
- [ ] `fleet-assets/assets/index` — custom
- [ ] `fleet-assets/assets/show` — custom
- [ ] `fleet-assets/bookings/index` — custom
- [ ] `fleet-assets/bookings/show` — custom
- [ ] `fleet-assets/compliance/index` — custom
- [ ] `fleet-assets/daily-check` — custom
- [ ] `fleet-assets/dashboard` — custom
- [ ] `fleet-assets/devices/index` — custom
- [ ] `fleet-assets/drivers/index` — custom
- [ ] `fleet-assets/drivers/show` — custom
- [ ] `fleet-assets/fuel/index` — custom
- [ ] `fleet-assets/geofences/index` — custom
- [ ] `fleet-assets/handovers/index` — custom
- [ ] `fleet-assets/handovers/show` — custom
- [ ] `fleet-assets/incidents/index` — custom
- [ ] `fleet-assets/inspections/index` — custom
- [ ] `fleet-assets/inspections/show` — custom
- [ ] `fleet-assets/keys/index` — custom
- [ ] `fleet-assets/maintenance/checklists/index` — custom
- [ ] `fleet-assets/maintenance/checklists/run` — custom
- [ ] `fleet-assets/maintenance/dashboard` — custom
- [ ] `fleet-assets/maintenance/schedules/index` — custom
- [ ] `fleet-assets/maintenance/work-orders/index` — custom
- [ ] `fleet-assets/maintenance/work-orders/show` — custom
- [ ] `fleet-assets/map` — custom
- [ ] `fleet-assets/mileage/index` — custom
- [ ] `fleet-assets/outings/index` — custom
- [ ] `fleet-assets/outings/show` — custom
- [ ] `fleet-assets/reports/by-house` — custom
- [ ] `fleet-assets/reports/community-access` — custom
- [ ] `fleet-assets/reports/cost-allocation` — custom
- [ ] `fleet-assets/reports/index` — custom
- [ ] `fleet-assets/reports/reimbursement` — custom
- [ ] `fleet-assets/resident-tracking/history` — custom
- [ ] `fleet-assets/resident-tracking/index` — custom
- [ ] `fleet-assets/settings/notifications` — custom
- [ ] `fleet-assets/transports/index` — custom
- [ ] `fleet-assets/transports/medications` — custom
- [ ] `fleet-assets/transports/pre-check` — custom
- [ ] `fleet-assets/transports/show` — custom
- [ ] `fleet-assets/trips/index` — custom
- [ ] `fleet-assets/trips/playback` — custom
- [ ] `fleet-assets/vehicles/alerts-config` — custom
- [ ] `fleet-assets/vehicles/index` — custom
- [ ] `fleet-assets/vehicles/show` — custom

### fleet-management (1)

- [ ] `fleet-management/maps-usage` — custom

### health-clinical (13)

- [ ] `health-clinical/Assessments` — custom
- [ ] `health-clinical/Behaviour` — custom
- [ ] `health-clinical/CarePlans` — custom
- [ ] `health-clinical/ClientSummary` — PageHero
- [ ] `health-clinical/ClientTrends` — PageHero
- [ ] `health-clinical/Events` — custom
- [ ] `health-clinical/HealthMonitoring` — custom
- [ ] `health-clinical/Protocols` — custom
- [ ] `health-clinical/Trends` — custom
- [ ] `health-clinical/index` — custom
- [ ] `health-clinical/observations` — custom
- [ ] `health-clinical/protocols/Create` — PageHero
- [ ] `health-clinical/protocols/Edit` — PageHero

### health-safety (16)

- [ ] `health-safety/analytics` — custom
- [ ] `health-safety/corrective-actions/index` — custom
- [ ] `health-safety/dashboard` — custom
- [ ] `health-safety/drills/index` — custom
- [ ] `health-safety/drills/show` — custom
- [ ] `health-safety/events/index` — custom
- [ ] `health-safety/events/show` — custom
- [ ] `health-safety/first-aid/index` — custom
- [ ] `health-safety/injuries/index` — custom
- [ ] `health-safety/lone-workers/index` — custom
- [ ] `health-safety/ppe/index` — custom
- [ ] `health-safety/procedures/index` — custom
- [ ] `health-safety/restraints/index` — custom
- [ ] `health-safety/risk-assessments/index` — custom
- [ ] `health-safety/substances/index` — custom
- [ ] `health-safety/worker-participation/index` — custom

### home (1)

- [ ] `home` — custom

### hr (25)

- [ ] `hr/analytics/index` — custom
- [ ] `hr/announcements/index` — custom
- [ ] `hr/announcements/show` — custom
- [ ] `hr/approvals/chains` — PageHero
- [ ] `hr/approvals/pending` — PageHero
- [ ] `hr/assets/index` — custom
- [ ] `hr/assets/show` — custom
- [ ] `hr/calendar/index` — custom
- [ ] `hr/candidates/create` — PageHero
- [ ] `hr/candidates/create-offer` — PageHero
- [ ] `hr/candidates/show` — PageHero
- [ ] `hr/compensation/bands` — custom
- [ ] `hr/compensation/benefits/index` — custom
- [ ] `hr/compensation/benefits/plans` — PageHero
- [ ] `hr/compensation/bonuses` — custom
- [ ] `hr/compensation/history` — PageHero
- [ ] `hr/compensation/history-index` — custom
- [ ] `hr/compensation/review-detail` — PageHero
- [ ] `hr/compensation/reviews` — custom
- [ ] `hr/compensation/settings` — custom
- [ ] `hr/performance/competencies/assess` — PageHero
- [ ] `hr/performance/competencies/index` — custom
- [ ] `hr/performance/competencies/profile` — PageHero
- [ ] `hr/settings/audit-log` — PageHero
- [ ] `hr/settings/custom-fields` — PageHero

### incidents (4)

- [ ] `incidents/index` — custom
- [ ] `incidents/show` — custom
- [ ] `incidents/templates/edit` — PageHero
- [ ] `incidents/templates/index` — PageHero

### internal (1)

- [ ] `internal/_design/page-hero` — PageHero

### it (10)

- [ ] `it/changes/index` — custom
- [ ] `it/changes/show` — custom
- [ ] `it/index` — custom
- [ ] `it/major-incidents/index` — custom
- [ ] `it/major-incidents/show` — custom
- [ ] `it/major-incidents/status` — custom
- [ ] `it/problems/index` — custom
- [ ] `it/problems/show` — custom
- [ ] `it/setup/index` — custom
- [ ] `it/tickets/show` — custom

### medications (1)

- [ ] `medications/audit` — PageHero

### meds (1)

- [ ] `meds/today/index` — PageHero

### my-calendar (1)

- [ ] `my-calendar` — custom

### my-day (1)

- [ ] `my-day/index` — custom

### my-roster (1)

- [ ] `my-roster/index` — custom

### notifications (1)

- [ ] `notifications/index` — PageHero

### operations (57)

- [ ] `operations/activity/Index` — PageHero
- [ ] `operations/calendar-sync/Create` — PageHero
- [ ] `operations/calendar-sync/Index` — PageHero
- [ ] `operations/care-plans/Create` — PageHero
- [ ] `operations/care-plans/Edit` — PageHero
- [ ] `operations/care-plans/Index` — PageHero
- [ ] `operations/care-plans/Show` — PageHero
- [ ] `operations/client-funds/Create` — PageHero
- [ ] `operations/client-funds/Index` — PageHero
- [ ] `operations/client-funds/Show` — PageHero
- [ ] `operations/clients/assignments` — PageHero
- [ ] `operations/clients/calendar` — PageHero
- [ ] `operations/clients/consents/Index` — PageHero
- [ ] `operations/clients/documents` — PageHero
- [ ] `operations/clients/incidents` — PageHero
- [ ] `operations/clients/portal-users` — custom
- [ ] `operations/clients/risks` — PageHero
- [ ] `operations/clients/visit-requests` — PageHero
- [ ] `operations/family-portal/Index` — PageHero
- [ ] `operations/forms/Create` — PageHero
- [ ] `operations/forms/Edit` — PageHero
- [ ] `operations/forms/Index` — PageHero
- [ ] `operations/forms/Show` — PageHero
- [ ] `operations/forms/Submissions` — PageHero
- [ ] `operations/funding/Index` — PageHero
- [ ] `operations/funding/claims/Create` — PageHero
- [ ] `operations/funding/claims/Index` — PageHero
- [ ] `operations/funding/claims/Show` — PageHero
- [ ] `operations/note-templates/Create` — PageHero
- [ ] `operations/note-templates/Edit` — PageHero
- [ ] `operations/note-templates/Index` — PageHero
- [ ] `operations/notifications/Index` — PageHero
- [ ] `operations/onboarding/Create` — PageHero
- [ ] `operations/onboarding/Index` — PageHero
- [ ] `operations/onboarding/Show` — PageHero
- [ ] `operations/qualifications/CheckShift` — PageHero
- [ ] `operations/qualifications/Index` — PageHero
- [ ] `operations/reports/Index` — PageHero
- [ ] `operations/reports/Shifts` — PageHero
- [ ] `operations/reports/Show` — PageHero
- [ ] `operations/review-queue/index` — PageHero
- [ ] `operations/rostering/conflicts` — PageHero
- [ ] `operations/rostering/index` — PageHero
- [ ] `operations/rostering/publish/Diff` — PageHero
- [ ] `operations/rostering/publish/Review` — PageHero
- [ ] `operations/rostering/suggestions/Show` — PageHero
- [ ] `operations/service-agreements/Create` — PageHero
- [ ] `operations/service-agreements/Edit` — PageHero
- [ ] `operations/service-agreements/Index` — PageHero
- [ ] `operations/service-agreements/Show` — PageHero
- [ ] `operations/shift-notes/Index` — custom
- [ ] `operations/shifts/index` — custom
- [ ] `operations/shifts/series/Index` — PageHero
- [ ] `operations/shifts/series/Show` — PageHero
- [ ] `operations/shifts/show` — PageHero
- [ ] `operations/timesheets/index` — custom
- [ ] `operations/timesheets/payroll-adjustments` — custom

### portal (16)

- [ ] `portal/calendar` — PageHero
- [ ] `portal/client` — custom
- [ ] `portal/consent-requests/Show` — custom
- [ ] `portal/documents` — PageHero
- [ ] `portal/family-dashboard` — PageHero
- [ ] `portal/family-notes` — PageHero
- [ ] `portal/health` — custom
- [ ] `portal/index` — PageHero
- [ ] `portal/location` — PageHero
- [ ] `portal/login` — custom
- [ ] `portal/messages` — PageHero
- [ ] `portal/notifications` — PageHero
- [ ] `portal/photos` — PageHero
- [ ] `portal/preferences` — PageHero
- [ ] `portal/schedule` — custom
- [ ] `portal/timeline` — custom

### pricing (1)

- [ ] `pricing` — custom

### privacy (16)

- [ ] `privacy` — PageHero
- [ ] `privacy/breaches` — PageHero
- [ ] `privacy/breaches/show` — PageHero
- [ ] `privacy/dashboard` — custom
- [ ] `privacy/deletion-logs` — PageHero
- [ ] `privacy/dpia` — PageHero
- [ ] `privacy/dpia/edit` — PageHero
- [ ] `privacy/dpia/show` — PageHero
- [ ] `privacy/legal-holds` — PageHero
- [ ] `privacy/legal-holds/edit` — PageHero
- [ ] `privacy/reports/compliance` — PageHero
- [ ] `privacy/requests` — PageHero
- [ ] `privacy/requests/show` — PageHero
- [ ] `privacy/retention` — PageHero
- [ ] `privacy/retention/edit` — PageHero
- [ ] `privacy/retention/review` — PageHero

### quality (1)

- [ ] `quality/checklist` — custom

### reports (6)

- [ ] `reports/assets` — custom
- [ ] `reports/combined` — custom
- [ ] `reports/incidents` — PageHero
- [ ] `reports/index` — PageHero
- [ ] `reports/medications` — custom
- [ ] `reports/module` — custom

### respite (51)

- [ ] `respite/bookings/create` — PageHero
- [ ] `respite/bookings/index` — PageHero
- [ ] `respite/bookings/show` — PageHero
- [ ] `respite/calendar/index` — PageHero
- [ ] `respite/communication-logs/create` — PageHero
- [ ] `respite/communication-logs/for-stay` — PageHero
- [ ] `respite/communication-logs/index` — PageHero
- [ ] `respite/communication-logs/show` — PageHero
- [ ] `respite/daily-notes/create` — PageHero
- [ ] `respite/daily-notes/for-stay` — PageHero
- [ ] `respite/daily-notes/index` — PageHero
- [ ] `respite/daily-notes/show` — PageHero
- [ ] `respite/daily-notes/with-concerns` — PageHero
- [ ] `respite/daily-notes/with-incidents` — PageHero
- [ ] `respite/evidence-packs/create` — PageHero
- [ ] `respite/evidence-packs/for-stay` — PageHero
- [ ] `respite/evidence-packs/index` — PageHero
- [ ] `respite/evidence-packs/show` — PageHero
- [ ] `respite/handover-notes/create` — PageHero
- [ ] `respite/handover-notes/for-stay` — PageHero
- [ ] `respite/handover-notes/index` — PageHero
- [ ] `respite/handover-notes/show` — PageHero
- [ ] `respite/handover-notes/unacknowledged` — PageHero
- [ ] `respite/index` — custom
- [ ] `respite/procedure-runs/create` — PageHero
- [ ] `respite/procedure-runs/index` — PageHero
- [ ] `respite/procedure-runs/my-active` — PageHero
- [ ] `respite/procedure-runs/overdue` — PageHero
- [ ] `respite/procedure-runs/show` — PageHero
- [ ] `respite/procedures/create` — PageHero
- [ ] `respite/procedures/index` — PageHero
- [ ] `respite/procedures/show` — PageHero
- [ ] `respite/referrals/create` — PageHero
- [ ] `respite/referrals/show` — PageHero
- [ ] `respite/requests/create` — PageHero
- [ ] `respite/requests/index` — PageHero
- [ ] `respite/requests/show` — PageHero
- [ ] `respite/resources/index` — PageHero
- [ ] `respite/risk-plan-activations/create` — PageHero
- [ ] `respite/risk-plan-activations/for-client` — PageHero
- [ ] `respite/risk-plan-activations/for-stay` — PageHero
- [ ] `respite/risk-plan-activations/index` — PageHero
- [ ] `respite/risk-plan-activations/needing-acknowledgment` — PageHero
- [ ] `respite/risk-plan-activations/show` — PageHero
- [ ] `respite/stays/index` — PageHero
- [ ] `respite/stays/show` — PageHero
- [ ] `respite/tasks/awaiting-approval` — PageHero
- [ ] `respite/tasks/index` — PageHero
- [ ] `respite/tasks/my-tasks` — PageHero
- [ ] `respite/tasks/overdue` — PageHero
- [ ] `respite/tasks/show` — PageHero

### safeguarding (2)

- [ ] `safeguarding/concern` — custom
- [ ] `safeguarding/index` — custom

### security-devices (12)

- [ ] `security-devices/command-batches/show` — PageHero
- [ ] `security-devices/integrations` — PageHero
- [ ] `security-devices/integrations/milesight` — PageHero
- [ ] `security-devices/integrations/queclink-hub` — PageHero
- [ ] `security-devices/integrations/unifi` — PageHero
- [ ] `security-devices/maintenance` — PageHero
- [ ] `security-devices/maintenance-health` — PageHero
- [ ] `security-devices/monitoring` — PageHero
- [ ] `security-devices/reports` — PageHero
- [ ] `security-devices/settings` — PageHero
- [ ] `security-devices/sites/index` — PageHero
- [ ] `security-devices/sites/show` — PageHero

### settings (26)

- [ ] `settings/access` — custom
- [ ] `settings/api` — custom
- [ ] `settings/appearance` — custom
- [ ] `settings/audit-logs` — PageHero
- [ ] `settings/branding` — custom
- [ ] `settings/calendar-sync` — custom
- [ ] `settings/data` — custom
- [ ] `settings/email-settings` — custom
- [ ] `settings/it-mailbox` — custom
- [ ] `settings/modules` — custom
- [ ] `settings/notification-defaults` — custom
- [ ] `settings/notification-escalations` — PageHero
- [ ] `settings/notifications` — custom
- [ ] `settings/password` — custom
- [ ] `settings/profile` — PageHero
- [ ] `settings/roles/edit` — custom
- [ ] `settings/roles/index` — custom
- [ ] `settings/security` — PageHero
- [ ] `settings/service-contexts` — custom
- [ ] `settings/sso-config` — PageHero
- [ ] `settings/sso-groups` — PageHero
- [ ] `settings/templates` — PageHero
- [ ] `settings/terminology` — custom
- [ ] `settings/two-factor` — custom
- [ ] `settings/users/index` — PageHero
- [ ] `settings/users/show` — PageHero

### sites (28)

- [ ] `sites/calendar/index` — custom
- [ ] `sites/checklists/index` — custom
- [ ] `sites/compliance/Index` — PageHero
- [ ] `sites/create` — PageHero
- [ ] `sites/credentials/audit` — PageHero
- [ ] `sites/damages/index` — PageHero
- [ ] `sites/documents` — PageHero
- [ ] `sites/edit` — PageHero
- [ ] `sites/emergency-plan/index` — PageHero
- [ ] `sites/feedback/Index` — PageHero
- [ ] `sites/hardware/index` — PageHero
- [ ] `sites/hazards/index` — custom
- [ ] `sites/hazards/show` — custom
- [ ] `sites/inspections/global` — PageHero
- [ ] `sites/inspections/index` — PageHero
- [ ] `sites/ledger/index` — PageHero
- [ ] `sites/reports/asset-condition` — PageHero
- [ ] `sites/reports/checklist-trends` — PageHero
- [ ] `sites/reports/facilities` — PageHero
- [ ] `sites/reports/head-office` — PageHero
- [ ] `sites/reports/houses` — PageHero
- [ ] `sites/reports/index` — PageHero
- [ ] `sites/reports/overdue-actions` — PageHero
- [ ] `sites/reports/site-detail` — PageHero
- [ ] `sites/resources/index` — PageHero
- [ ] `sites/rooms/index` — PageHero
- [ ] `sites/vendors-credentials/global` — PageHero
- [ ] `sites/zones/index` — PageHero

### smart-monitoring (1)

- [ ] `smart-monitoring` — custom

### staff (6)

- [ ] `staff/assignments` — PageHero
- [ ] `staff/availability` — PageHero
- [ ] `staff/credentials` — PageHero
- [ ] `staff/edit` — PageHero
- [ ] `staff/index` — PageHero
- [ ] `staff/show` — PageHero

### summaries (1)

- [ ] `summaries/index` — custom

### system (5)

- [ ] `system/access/Assignments` — PageHero
- [ ] `system/access/Dashboard` — PageHero
- [ ] `system/access/Matrix` — PageHero
- [ ] `system/access/Roles` — PageHero
- [ ] `system/users/Create` — PageHero

### tasks (2)

- [ ] `tasks/index` — custom
- [ ] `tasks/reports` — custom

### terms (1)

- [ ] `terms` — custom

### timeline (1)

- [ ] `timeline/index` — PageHero

## Referenced in PHP but no page file exists (8)

These are rendered by controllers but have no `.tsx` file — dead routes or unbuilt frontend:

- `operations/timesheets/approvals`
- `training/competencies/index`
- `training/competencies/show`
- `training/inductions/index`
- `training/inductions/show`
- `training/records/index`
- `training/records/show`
- `training/records/user`
