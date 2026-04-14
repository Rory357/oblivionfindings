<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class DuskDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ──────────────────────────────────────────────
        // Core users
        // ──────────────────────────────────────────────
        $admin = User::factory()->withoutTwoFactor()->create([
            'name' => 'Test Admin',
            'email' => 'admin@test.com',
            'approved_at' => now(),
            'role' => 'admin',
        ]);

        $adminRole = Role::firstOrCreate(
            ['name' => 'admin'],
            ['label' => 'Administrator', 'level' => 100, 'type' => 'system']
        );
        $admin->roles()->syncWithoutDetaching([$adminRole->id]);

        // Seed ALL permission keys used by routes and assign to admin role
        $permissionKeys = collect([
            'assets.alerts.manage','assets.alerts.view','assets.assignments.manage','assets.create','assets.delete',
            'assets.documents.manage','assets.geofences.manage','assets.inspections.record','assets.maintenance.record',
            'assets.ownership.manage','assets.scan.record','assets.telemetry.ingest','assets.trackers.manage',
            'assets.update','assets.viewAny','assets.viewAssigned','audit.viewAny','billing.viewAny',
            'calendar.create','calendar.manage_recurring','calendar.view','calendar.viewAny',
            'care_note_templates.viewAny','care_plans.create','care_plans.delete','care_plans.update','care_plans.viewAny',
            'checklists.manage_templates','checklists.run','checklists.schedule','checklists.view',
            'client_funds.manage','clients.assignments.update','clients.create','clients.onboarding.manage',
            'clients.update','clients.viewAny','clients.viewAssigned',
            'competency.assess','competency.manage','competency.viewAny',
            'compliance.view','consents.export','consents.manage','consents.record','consents.viewAny','consents.withdraw',
            'controlRoom.alerts.assign','controlRoom.alerts.create','controlRoom.alerts.escalate',
            'controlRoom.alerts.manage','controlRoom.alerts.view','controlRoom.viewAny',
            'credentials.manage','credentials.reveal','credentials.view',
            'custom_forms.create','custom_forms.submit','custom_forms.update','custom_forms.viewAny',
            'evv.record','evv.verify','evv.viewAny',
            'finance.admin','finance.ap.manage','finance.ap.view','finance.ar.manage','finance.ar.view',
            'finance.assets.manage','finance.assets.view','finance.bank.manage','finance.bank.view',
            'finance.dashboard','finance.ledger.manage','finance.ledger.view',
            'finance.petty_cash.manage','finance.petty_cash.view','finance.reports.view',
            'finance.tax.manage','finance.tax.view',
            'fleet.driverSessions.manage','fleet.fuel.manage','fleet.reports.view','fleet.trips.manage','fleet.viewAny',
            'funding.claims.approve','funding.claims.create','funding.claims.submit','funding.viewAny',
            'governance.actions.view','governance.budgets.manage','governance.budgets.view',
            'governance.compliance.manage','governance.compliance.view',
            'governance.meetings.manage','governance.meetings.view',
            'governance.packs.manage','governance.packs.view',
            'governance.performance.manage','governance.performance.view',
            'governance.resolutions.manage','governance.resolutions.view','governance.resolutions.vote',
            'governance.risks.manage','governance.risks.view',
            'governance.strategy.manage','governance.strategy.view','governance.view',
            'handovers.create','handovers.viewAny',
            'hazards.assign','hazards.close','hazards.create','hazards.view',
            'hr.analytics.view','hr.announcements.manage','hr.approvals.manage','hr.approvals.view',
            'hr.assets.manage','hr.assets.view','hr.benefits.manage','hr.benefits.view',
            'hr.cases.manage','hr.cases.view','hr.compensation.manage','hr.compensation.view',
            'hr.compliance.manage','hr.compliance.view','hr.disciplinary.manage',
            'hr.documents.manage','hr.documents.view','hr.driver.manage','hr.driver.view',
            'hr.employees.manage','hr.employees.viewAny',
            'hr.expenses.approve','hr.expenses.manage','hr.expenses.view',
            'hr.leave.approve','hr.leave.manage','hr.leave.viewAny',
            'hr.onboarding.manage','hr.onboarding.view',
            'hr.payroll.export','hr.payroll.view',
            'hr.performance.manage','hr.performance.view',
            'hr.policies.attest','hr.policies.manage','hr.policies.view',
            'hr.recruitment.manage','hr.recruitment.view',
            'hr.reports.export','hr.reports.view','hr.settings.manage',
            'hr.surveys.manage','hr.surveys.view',
            'hr.time.approveTeam','hr.time.manage','hr.training.view',
            'hr.vetting.manage','hr.vetting.view','hr.wellbeing.view',
            'incidents.approve','incidents.create','incidents.export',
            'incidents.followups.complete','incidents.followups.manage',
            'incidents.portal.manage','incidents.reopen','incidents.submit',
            'incidents.templates.manage','incidents.update','incidents.viewAny','incidents.viewAssigned',
            'integrations.manage_site_secrets','integrations.manage_tenant_secrets','integrations.view',
            'invoices.create','invoices.send','invoices.update','invoices.viewAny','invoices.void',
            'medications.administer.correct','medications.administer.record','medications.audit.view',
            'medications.breakglass','medications.controlled.record',
            'medications.reports.export','medications.stock.update','medications.view',
            'mileage.approve','mileage.create','mileage.viewAny','mileage.viewOwn',
            'operations.reports.view','payroll.export',
            'price_books.create','price_books.update','price_books.viewAny',
            'privacy.conductDPIA','privacy.manageLegalHolds','privacy.manageRetention',
            'privacy.processRequests','privacy.reportBreaches','privacy.viewRequests',
            'progress_notes.create','progress_notes.delete','progress_notes.update','progress_notes.viewAny',
            'quotes.create','quotes.update','quotes.viewAny',
            'reports.sites.export','reports.sites.view','reports.viewAny',
            'respite.bookings.manage','respite.calendar.view','respite.communications.manage',
            'respite.communications.view','respite.create','respite.daily',
            'respite.evidence.manage','respite.evidence.seal','respite.evidence.view',
            'respite.handovers.manage','respite.handovers.view',
            'respite.procedures.manage','respite.procedures.run',
            'respite.resources.manage','respite.risk','respite.stays.manage',
            'respite.tasks.approve','respite.tasks.manage','respite.tasks.view',
            'respite.update','respite.viewAny',
            'risks.create','risks.delete','risks.update','risks.viewAny','risks.viewAssigned',
            'roadmap.approve','roadmap.budget.manage','roadmap.decisions.manage','roadmap.decisions.view',
            'roadmap.manage','roadmap.reports.export','roadmap.view',
            'roster_templates.create','roster_templates.delete','roster_templates.update','roster_templates.viewAny',
            'rostering.autoSchedule','rostering.viewAny',
            'safeguarding.create','safeguarding.investigate','safeguarding.report.external',
            'safeguarding.update','safeguarding.viewAny',
            'service_agreements.create','service_agreements.delete','service_agreements.update','service_agreements.viewAny',
            'settings.access.manage','settings.branding.manage','settings.service_contexts.manage',
            'settings.templates.manage','settings.terminology.manage',
            'shifts.create','shifts.manageAny','shifts.tasks.updateSelf','shifts.update','shifts.viewAny','shifts.viewAssigned',
            'siteHardware.manage','siteHardware.view','sites.create','sites.update','sites.viewAny',
            'staff.assignments.update','staff.availability.updateAny','staff.availability.updateSelf',
            'staff.update','staff.viewAny',
            'summaries.generate','timeline.create','timeline.pin',
            'timesheets.approve','timesheets.create','timesheets.manageAny','timesheets.submit',
            'timesheets.update','timesheets.viewAny','timesheets.viewAssigned',
            'training.enrol','training.exempt','training.manageCourses','training.record','training.viewAny',
            'securityDevices.viewAny','securityDevices.devices.view','securityDevices.devices.create',
            'securityDevices.devices.update','securityDevices.devices.delete','securityDevices.devices.assign',
            'securityDevices.groups.manage','securityDevices.events.view',
            'securityDevices.maintenance.view','securityDevices.maintenance.manage',
            'securityDevices.integrations.view','securityDevices.integrations.manage',
            'securityDevices.reports.view',
            'unifi.manage','vendors.manage','vendors.view',
            'vetting.assessRisk','vetting.manage','vetting.verify','vetting.viewAny','workers.viewAny',
        ]);

        $permIds = [];
        foreach ($permissionKeys as $key) {
            $perm = \App\Models\Permission::firstOrCreate(
                ['key' => $key],
                ['description' => str_replace('.', ' ', $key), 'group' => explode('.', $key)[0]]
            );
            $permIds[] = $perm->id;
        }
        $adminRole->permissions()->syncWithoutDetaching($permIds);

        $staffUser = User::factory()->withoutTwoFactor()->create([
            'name' => 'Test Staff',
            'email' => 'staff@test.com',
            'approved_at' => now(),
        ]);

        $managerUser = User::factory()->withoutTwoFactor()->create([
            'name' => 'Test Manager',
            'email' => 'manager@test.com',
            'approved_at' => now(),
        ]);

        // ──────────────────────────────────────────────
        // Staff profile
        // ──────────────────────────────────────────────
        $staff = Staff::factory()->create(['user_id' => $staffUser->id]);

        // ──────────────────────────────────────────────
        // Sites & Service Contexts
        // ──────────────────────────────────────────────
        $site = Site::factory()->create();
        $context = ServiceContext::factory()->create();

        // ──────────────────────────────────────────────
        // Clients
        // ──────────────────────────────────────────────
        $client = Client::factory()->create();

        $this->seed(fn () => \App\Models\ClientMedicalProfile::factory()->create(['client_id' => $client->id]));
        $this->seed(fn () => \App\Models\ClientMedication::factory()->create(['client_id' => $client->id]));

        // ──────────────────────────────────────────────
        // Assets
        // ──────────────────────────────────────────────
        $asset = Asset::factory()->create(['site_id' => $site->id]);
        $vehicleAsset = Asset::factory()->create([
            'site_id' => $site->id,
            'category' => 'vehicle',
        ]);

        // ──────────────────────────────────────────────
        // Incidents & Safeguarding
        // ──────────────────────────────────────────────
        $incident = $this->seed(fn () => \App\Models\ClientIncident::factory()->create([
            'client_id' => $client->id,
            'reported_by' => $admin->id,
        ]));

        if ($incident) {
            $this->seed(fn () => \App\Models\IncidentFollowup::factory()->create([
                'client_incident_id' => $incident->id,
                'assigned_to_user_id' => $staffUser->id,
                'created_by' => $admin->id,
            ]));
        }

        $this->seed(fn () => \App\Models\SafeguardingConcern::factory()->create([
            'reported_by_user_id' => $admin->id,
        ]));

        // ──────────────────────────────────────────────
        // Rostering & Timesheets
        // ──────────────────────────────────────────────
        $this->seed(fn () => \App\Models\Shift::factory()->create([
            'client_id' => $client->id,
            'user_id' => $staffUser->id,
            'created_by' => $admin->id,
        ]));

        $this->seed(fn () => \App\Models\Timesheet::factory()->create([
            'user_id' => $staffUser->id,
            'client_id' => $client->id,
            'created_by' => $admin->id,
        ]));

        $this->seed(fn () => \App\Models\TimelineEvent::factory()->create([
            'actor_user_id' => $admin->id,
        ]));

        // ──────────────────────────────────────────────
        // Respite
        // ──────────────────────────────────────────────
        $this->seed(fn () => \App\Models\RespiteBooking::factory()->create([
            'client_id' => $client->id,
        ]));
        $this->seed(fn () => \App\Models\RespiteTask::factory()->create());

        // ──────────────────────────────────────────────
        // Fleet
        // ──────────────────────────────────────────────
        $this->seed(fn () => \App\Models\FleetVehicleBooking::factory()->create([
            'asset_id' => $vehicleAsset->id,
            'user_id' => $staffUser->id,
        ]));
        $this->seed(fn () => \App\Models\FleetWorkOrder::factory()->create([
            'asset_id' => $vehicleAsset->id,
            'reported_by_user_id' => $staffUser->id,
        ]));
        $this->seed(fn () => \App\Models\FleetTrip::factory()->create([
            'asset_id' => $vehicleAsset->id,
        ]));
        $this->seed(fn () => \App\Models\GeofenceZone::factory()->create());

        // ──────────────────────────────────────────────
        // Health & Safety
        // ──────────────────────────────────────────────
        $this->seed(fn () => \App\Models\EmergencyDrill::factory()->create(['site_id' => $site->id]));
        $this->seed(fn () => \App\Models\WorkplaceInjury::factory()->create(['user_id' => $staffUser->id]));
        $this->seed(fn () => \App\Models\SafeWorkProcedure::factory()->create());
        $this->seed(fn () => \App\Models\HazardousSubstance::factory()->create());

        // ──────────────────────────────────────────────
        // Privacy & Legal
        // ──────────────────────────────────────────────
        $this->seed(fn () => \App\Models\DataBreachLog::factory()->create());
        $this->seed(fn () => \App\Models\DataSubjectRequest::factory()->create());
        $this->seed(fn () => \App\Models\DataRetentionPolicy::factory()->create());
        $this->seed(fn () => \App\Models\PrivacyImpactAssessment::factory()->create());
        $this->seed(fn () => \App\Models\LegalHold::factory()->create());

        // ──────────────────────────────────────────────
        // Training & Competency
        // ──────────────────────────────────────────────
        $this->seed(fn () => \App\Models\TrainingCourse::factory()->create());
        $this->seed(fn () => \App\Models\CompetencyFramework::factory()->create());

        // ──────────────────────────────────────────────
        // Service Agreements, Quotes & Price Books
        // ──────────────────────────────────────────────
        $this->seed(fn () => \App\Models\ServiceAgreement::factory()->create([
            'client_id' => $client->id,
        ]));
        $this->seed(fn () => \App\Models\Quote::factory()->create());
        $this->seed(fn () => \App\Models\PriceBook::factory()->create());

        // ──────────────────────────────────────────────
        // Control Room
        // ──────────────────────────────────────────────
        $this->seed(fn () => \App\Models\ControlRoomAlert::factory()->create());
        $this->seed(fn () => \App\Models\ControlRoom\Playbook::factory()->create());

        // ──────────────────────────────────────────────
        // HR
        // ──────────────────────────────────────────────
        $this->seed(fn () => \App\Domain\Hr\Models\HrPosition::factory()->create());
        $this->seed(fn () => \App\Domain\Hr\Models\HrPolicy::factory()->create());
        $this->seed(fn () => \App\Domain\Hr\Models\HrJobPosting::factory()->create([
            'created_by' => $admin->id,
        ]));
        $this->seed(fn () => \App\Domain\Hr\Models\HrLeaveRequest::factory()->create([
            'user_id' => $staffUser->id,
        ]));
        $this->seed(fn () => \App\Domain\Hr\Models\HrExpenseClaim::factory()->create([
            'user_id' => $staffUser->id,
        ]));
        $this->seed(fn () => \App\Domain\Hr\Models\HrGoal::factory()->create([
            'user_id' => $staffUser->id,
            'created_by' => $admin->id,
        ]));
        $this->seed(fn () => \App\Domain\Hr\Models\HrPerformanceReview::factory()->create([
            'employee_user_id' => $staffUser->id,
            'reviewer_user_id' => $managerUser->id,
        ]));
        $this->seed(fn () => \App\Domain\Hr\Models\HrCase::factory()->create([
            'user_id' => $staffUser->id,
        ]));
        $this->seed(fn () => \App\Domain\Hr\Models\HrAnnouncement::factory()->create([
            'created_by' => $admin->id,
        ]));
        // Skipped: HrExitInterview requires employee_profile_id FK
        // $this->seed(fn () => \App\Domain\Hr\Models\HrExitInterview::factory()->create([
        //     'interviewer_user_id' => $managerUser->id,
        //     'created_by' => $admin->id,
        // ]));
        // Skipped: HrOnboardingChecklist requires employee_profile_id FK
        // $this->seed(fn () => \App\Domain\Hr\Models\HrOnboardingChecklist::factory()->create());
        $this->seed(fn () => \App\Domain\Hr\Models\HrCompensationReview::factory()->create());
        $this->seed(fn () => \App\Domain\Hr\Models\HrSuccessionPlan::factory()->create());
        $this->seed(fn () => \App\Domain\Hr\Models\HrSurvey::factory()->create());

        // ──────────────────────────────────────────────
        // Finance
        // ──────────────────────────────────────────────
        $vendor = $this->seed(fn () => \App\Domain\Finance\Models\FinVendor::factory()->create());
        $this->seed(fn () => \App\Domain\Finance\Models\FinAccount::factory()->create());
        $this->seed(fn () => \App\Domain\Finance\Models\FinInvoice::factory()->create());
        $this->seed(fn () => \App\Domain\Finance\Models\FinCreditNote::factory()->create());

        if ($vendor) {
            $this->seed(fn () => \App\Domain\Finance\Models\FinBill::factory()->create([
                'vendor_id' => $vendor->id,
            ]));
            $this->seed(fn () => \App\Domain\Finance\Models\FinPurchaseOrder::factory()->create([
                'vendor_id' => $vendor->id,
            ]));
        }

        $this->seed(fn () => \App\Domain\Finance\Models\FinBankAccount::factory()->create());
        $this->seed(fn () => \App\Domain\Finance\Models\FinJournal::factory()->create());
        $this->seed(fn () => \App\Domain\Finance\Models\FinPaymentRun::factory()->create());
        $this->seed(fn () => \App\Domain\Finance\Models\FinPettyCashFund::factory()->create());
        $this->seed(fn () => \App\Domain\Finance\Models\FinFixedAsset::factory()->create());
        $this->seed(fn () => \App\Domain\Finance\Models\FinGstReturn::factory()->create());
        $this->seed(fn () => \App\Domain\Finance\Models\FinCashFlowForecast::factory()->create());
        $this->seed(fn () => \App\Domain\Finance\Models\FinDonorFund::factory()->create());

        // ──────────────────────────────────────────────
        // Governance
        // ──────────────────────────────────────────────
        $this->seed(fn () => \App\Domain\Governance\Models\GovernancePolicy::factory()->create([
            'created_by' => $admin->id,
        ]));
        $this->seed(fn () => \App\Domain\Governance\Models\GovernanceMeeting::factory()->create([
            'created_by' => $admin->id,
        ]));
    }

    private function seed(\Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            $this->command?->warn("Seeder warning: {$e->getMessage()}");
            Log::warning("DuskDatabaseSeeder: {$e->getMessage()}");
            return null;
        }
    }
}
