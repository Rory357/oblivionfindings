<?php

namespace Database\Seeders;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinCashFlowForecast;
use App\Domain\Finance\Models\FinCreditNote;
use App\Domain\Finance\Models\FinDonorFund;
use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Finance\Models\FinGstReturn;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinPaymentRun;
use App\Domain\Finance\Models\FinPettyCashFund;
use App\Domain\Finance\Models\FinPurchaseOrder;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\GovernancePolicy;
use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Models\HrCase;
use App\Domain\Hr\Models\HrCompensationReview;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Models\HrGoal;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrPolicy;
use App\Domain\Hr\Models\HrPosition;
use App\Domain\Hr\Models\HrSuccessionPlan;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientMedicalProfile;
use App\Models\ClientMedication;
use App\Models\CompetencyFramework;
use App\Models\ControlRoom\Playbook;
use App\Models\ControlRoomAlert;
use App\Models\DataBreachLog;
use App\Models\DataRetentionPolicy;
use App\Models\DataSubjectRequest;
use App\Models\EmergencyDrill;
use App\Models\FleetTrip;
use App\Models\FleetVehicleBooking;
use App\Models\FleetWorkOrder;
use App\Models\GeofenceZone;
use App\Models\HazardousSubstance;
use App\Models\IncidentFollowup;
use App\Models\LegalHold;
use App\Models\Permission;
use App\Models\PriceBook;
use App\Models\PrivacyImpactAssessment;
use App\Models\ProcedureTemplate;
use App\Models\Quote;
use App\Models\RespiteBooking;
use App\Models\RespiteStay;
use App\Models\RespiteTask;
use App\Models\Role;
use App\Models\SafeguardingConcern;
use App\Models\SafeWorkProcedure;
use App\Models\ServiceAgreement;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Staff;
use App\Models\TimelineEvent;
use App\Models\Timesheet;
use App\Models\TrainingCourse;
use App\Models\User;
use App\Models\WorkplaceInjury;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class DuskDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ──────────────────────────────────────────────
        // Core users
        // ──────────────────────────────────────────────
        $admin = $this->seedUser([
            'name' => 'Test Admin',
            'email' => 'admin@test.com',
            'approved_at' => now(),
            'role' => 'admin',
        ]);

        $adminRole = Role::firstOrCreate(
            ['name' => 'admin'],
            $this->existingColumns('roles', [
                'label' => 'Administrator',
                'level' => 100,
                'type' => 'system',
                'description' => 'Administrator role for QA and browser testing',
            ])
        );
        $admin->roles()->syncWithoutDetaching([$adminRole->id]);

        // Seed ALL permission keys used by routes and assign to admin role
        $permissionKeys = collect([
            'assets.alerts.manage', 'assets.alerts.view', 'assets.assignments.manage', 'assets.create', 'assets.delete',
            'assets.documents.manage', 'assets.geofences.manage', 'assets.inspections.record', 'assets.maintenance.record',
            'assets.ownership.manage', 'assets.scan.record', 'assets.telemetry.export', 'assets.telemetry.ingest', 'assets.trackers.manage',
            'assets.update', 'assets.viewAny', 'assets.viewAssigned', 'audit.viewAny', 'billing.viewAny',
            'calendar.create', 'calendar.manage_recurring', 'calendar.view', 'calendar.viewAny',
            'care_note_templates.viewAny', 'care_plans.create', 'care_plans.delete', 'care_plans.update', 'care_plans.viewAny',
            'checklists.manage_templates', 'checklists.run', 'checklists.schedule', 'checklists.view',
            'clinical.dashboard',
            'clinical.events.record', 'clinical.events.review', 'clinical.events.viewAny', 'clinical.events.viewAssigned',
            'clinical.observations.correct', 'clinical.observations.record', 'clinical.observations.recordClinical',
            'clinical.observations.viewAny', 'clinical.observations.viewAssigned',
            'clinical.protocols.manage', 'clinical.protocols.viewAny',
            'client_funds.approve', 'client_funds.manage', 'client_funds.viewAllSites',
            'clients.assignments.update', 'clients.create', 'clients.onboarding.manage',
            'clients.update', 'clients.viewAny', 'clients.viewAssigned',
            'competency.assess', 'competency.manage', 'competency.viewAny',
            'compliance.view', 'consents.manage', 'consents.record', 'consents.viewAny', 'consents.withdraw',
            'controlRoom.alerts.assign', 'controlRoom.alerts.create', 'controlRoom.alerts.escalate',
            'controlRoom.alerts.manage', 'controlRoom.alerts.view', 'controlRoom.handovers.override',
            'controlRoom.viewAny',
            'credentials.manage', 'credentials.reveal', 'credentials.view',
            'custom_forms.create', 'custom_forms.submit', 'custom_forms.update', 'custom_forms.viewAny',
            'evv.record', 'evv.verify', 'evv.viewAny',
            'finance.admin', 'finance.ap.manage', 'finance.ap.view', 'finance.ar.manage', 'finance.ar.view',
            'finance.assets.manage', 'finance.assets.view', 'finance.bank.manage', 'finance.bank.view',
            'finance.dashboard', 'finance.insights.viewAllSites', 'finance.payments.viewAllSites', 'finance.payments.manageAllSites', 'finance.donorFunds.manageAllSites', 'finance.ledger.manage', 'finance.ledger.view',
            'finance.petty_cash.manage', 'finance.petty_cash.view', 'finance.reports.view',
            'finance.tax.manage', 'finance.tax.view',
            'fleet.driverSessions.manage', 'fleet.fuel.manage', 'fleet.reports.view', 'fleet.trips.manage', 'fleet.viewAny',
            'funding.claims.approve', 'funding.claims.create', 'funding.claims.submit', 'funding.viewAny',
            'governance.actions.manage', 'governance.actions.view',
            'governance.budgets.approve', 'governance.budgets.create', 'governance.budgets.manage',
            'governance.budgets.submit', 'governance.budgets.view',
            'governance.ceo-reports.manage', 'governance.ceo-reports.view',
            'governance.clinical.manage', 'governance.clinical.view',
            'governance.compliance.manage', 'governance.compliance.view',
            'governance.documents.manage', 'governance.documents.view',
            'governance.evaluations.manage', 'governance.evaluations.view',
            'governance.interests.manage', 'governance.interests.view',
            'governance.meetings.manage', 'governance.meetings.view',
            'governance.packs.manage', 'governance.packs.view',
            'governance.performance.manage', 'governance.performance.view',
            'governance.policies.manage', 'governance.policies.view',
            'governance.resolutions.manage', 'governance.resolutions.view', 'governance.resolutions.vote',
            'governance.risks.manage', 'governance.risks.view',
            'governance.strategy.manage', 'governance.strategy.view',
            'governance.te-tiriti.manage', 'governance.te-tiriti.view',
            'governance.view',
            'handovers.create', 'handovers.viewAny',
            'hazards.assign', 'hazards.close', 'hazards.create', 'hazards.manage', 'hazards.view',
            'hr.analytics.view', 'hr.announcements.manage', 'hr.approvals.manage', 'hr.approvals.view',
            'hr.announcements.view',
            'hr.assets.manage', 'hr.assets.view', 'hr.benefits.manage', 'hr.benefits.view',
            'hr.calendar.manage', 'hr.calendar.view',
            'hr.cases.manage', 'hr.cases.view', 'hr.compensation.manage', 'hr.compensation.view',
            'hr.compliance.manage', 'hr.compliance.view', 'hr.disciplinary.manage',
            'hr.documents.manage', 'hr.documents.view', 'hr.driver.manage', 'hr.driver.view',
            'hr.employees.manage', 'hr.employees.viewAny',
            'hr.expenses.approve', 'hr.expenses.manage', 'hr.expenses.view',
            'hr.exit-interviews.manage', 'hr.exit-interviews.view',
            'hr.goals.manage', 'hr.goals.view',
            'hr.leave.approve', 'hr.leave.manage', 'hr.leave.viewAny',
            'hr.onboarding.manage', 'hr.onboarding.view',
            'hr.payroll.export', 'hr.payroll.view',
            'hr.performance.manage', 'hr.performance.view',
            'hr.policies.attest', 'hr.policies.manage', 'hr.policies.view',
            'hr.positions.manage', 'hr.positions.view',
            'hr.recruitment.manage', 'hr.recruitment.view',
            'hr.reports.export', 'hr.reports.view', 'hr.settings.manage',
            'hr.skills.manage', 'hr.skills.view',
            'hr.surveys.manage', 'hr.surveys.view',
            'hr.training.manage', 'hr.training.view',
            'hr.vetting.manage', 'hr.vetting.view', 'hr.wellbeing.view',
            'incidents.approve', 'incidents.create', 'incidents.export',
            'incidents.followups.complete', 'incidents.followups.manage',
            'incidents.portal.manage', 'incidents.reopen', 'incidents.submit',
            'incidents.templates.manage', 'incidents.update', 'incidents.viewAny', 'incidents.viewAssigned',
            'integrations.manage_site_secrets', 'integrations.manage_secrets', 'integrations.view',
            'invoices.create', 'invoices.send', 'invoices.update', 'invoices.viewAny', 'invoices.void',
            'it.manage', 'it.organisationWide', 'it.request', 'it.view', 'it.viewSensitive',
            'medications.administer.correct', 'medications.administer.record', 'medications.audit.view',
            'medications.breakglass', 'medications.controlled.record',
            'medications.orders.manage', 'medications.reports.export', 'medications.stock.update', 'medications.view',
            'mileage.approve', 'mileage.create', 'mileage.viewAny', 'mileage.viewOwn',
            'operations.reports.view', 'payroll.export',
            'price_books.create', 'price_books.update', 'price_books.viewAny',
            'privacy.conductDPIA', 'privacy.manageLegalHolds', 'privacy.manageRetention',
            'privacy.processRequests', 'privacy.reportBreaches', 'privacy.viewRequests',
            'progress_notes.create', 'progress_notes.delete', 'progress_notes.update', 'progress_notes.viewAny',
            'quotes.create', 'quotes.update', 'quotes.viewAny',
            'reports.sites.export', 'reports.sites.view', 'reports.viewAny',
            'respite.bookings.manage', 'respite.calendar.view', 'respite.communications.manage',
            'respite.communications.view', 'respite.create', 'respite.daily', 'respite.daily-notes.manage', 'respite.daily-notes.view',
            'respite.evidence.manage', 'respite.evidence.seal', 'respite.evidence.view',
            'respite.handovers.manage', 'respite.handovers.view',
            'respite.procedures.manage', 'respite.procedures.run',
            'respite.resources.manage', 'respite.risk', 'respite.risk-plans.manage', 'respite.risk-plans.view', 'respite.stays.manage',
            'respite.tasks.approve', 'respite.tasks.manage', 'respite.tasks.view',
            'respite.update', 'respite.viewAny',
            'risks.create', 'risks.delete', 'risks.update', 'risks.viewAny', 'risks.viewAssigned',
            'roadmap.approve', 'roadmap.budget.manage', 'roadmap.decisions.manage', 'roadmap.decisions.view',
            'roadmap.manage', 'roadmap.reports.export', 'roadmap.view',
            'roster_templates.create', 'roster_templates.delete', 'roster_templates.update', 'roster_templates.viewAny',
            'rostering.autoSchedule', 'rostering.viewAny',
            'safeguarding.create', 'safeguarding.investigate', 'safeguarding.report.external',
            'safeguarding.update', 'safeguarding.viewAny',
            'service_agreements.create', 'service_agreements.delete', 'service_agreements.update', 'service_agreements.viewAny',
            'settings.access.manage', 'settings.branding.manage', 'settings.service_contexts.manage',
            'settings.templates.manage', 'settings.terminology.manage',
            'shifts.create', 'shifts.manageAny', 'shifts.tasks.updateSelf', 'shifts.update', 'shifts.viewAny', 'shifts.viewAssigned',
            'siteHardware.manage', 'siteHardware.view', 'sites.create', 'sites.update', 'sites.viewAll', 'sites.viewAny',
            'staff.assignments.update', 'staff.availability.updateAny', 'staff.availability.updateSelf',
            'staff.credentials.updateAny', 'staff.credentials.updateSelf', 'staff.credentials.viewAny',
            'staff.update', 'staff.viewAny',
            'summaries.generate', 'timeline.create', 'timeline.pin',
            'timesheets.approve', 'timesheets.create', 'timesheets.manageAny', 'timesheets.submit',
            'timesheets.update', 'timesheets.viewAny', 'timesheets.viewAssigned',
            'training.enrol', 'training.exempt', 'training.manageCourses', 'training.record', 'training.viewAny',
            'securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.devices.create',
            'securityDevices.devices.update', 'securityDevices.devices.delete', 'securityDevices.devices.assign',
            'securityDevices.groups.manage', 'securityDevices.events.view', 'securityDevices.cctv.media.view',
            'securityDevices.accessControl.view', 'securityDevices.accessControl.manage',
            'securityDevices.maintenance.view', 'securityDevices.maintenance.manage',
            'securityDevices.integrations.view', 'securityDevices.integrations.manage',
            'securityDevices.reports.view', 'securityDevices.commands.observe',
            'securityDevices.commands.operate', 'securityDevices.commands.manage',
            'securityDevices.commands.control', 'securityDevices.commands.approve',
            'securityDevices.commands.admin',
            'unifi.manage', 'vendors.manage', 'vendors.view',
            'workers.viewAny',
        ]);

        $permIds = [];
        foreach ($permissionKeys as $key) {
            $perm = Permission::firstOrCreate(
                ['key' => $key],
                $this->existingColumns('permissions', [
                    'description' => str_replace('.', ' ', $key),
                    'group' => explode('.', $key)[0],
                    'module' => explode('.', $key)[0],
                ])
            );
            $permIds[] = $perm->id;
        }
        $adminRole->permissions()->syncWithoutDetaching($permIds);

        $permissionIdByKey = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id', 'key');

        $syncRolePermissions = function (Role $role, array $keys) use ($permissionIdByKey): void {
            $ids = collect($keys)
                ->map(fn (string $key) => $permissionIdByKey->get($key))
                ->filter()
                ->values()
                ->all();

            if ($ids !== []) {
                $role->permissions()->syncWithoutDetaching($ids);
            }
        };

        $supportWorkerRole = Role::firstOrCreate(
            ['name' => 'support_worker'],
            $this->existingColumns('roles', [
                'label' => 'Support Worker',
                'level' => 10,
                'type' => 'system',
                'description' => 'Support worker role for QA and browser testing',
            ])
        );

        $managerRole = Role::firstOrCreate(
            ['name' => 'manager'],
            $this->existingColumns('roles', [
                'label' => 'Manager',
                'level' => 50,
                'type' => 'system',
                'description' => 'Manager role for QA and browser testing',
            ])
        );

        $supportWorkerPermissionKeys = [
            'calendar.view',
            'clinical.events.record',
            'clinical.events.viewAssigned',
            'clinical.observations.record',
            'clinical.observations.viewAssigned',
            'clients.viewAssigned',
            'controlRoom.alerts.view',
            'controlRoom.viewAny',
            'handovers.create',
            'handovers.viewAny',
            'incidents.create',
            'incidents.followups.complete',
            'incidents.submit',
            'incidents.viewAssigned',
            'medications.administer.record',
            'medications.view',
            'shifts.tasks.updateSelf',
            'shifts.viewAssigned',
            'staff.credentials.updateSelf',
            'staff.availability.updateSelf',
            'timesheets.create',
            'timesheets.submit',
            'timesheets.viewAssigned',
        ];

        $managerPermissionKeys = array_values(array_unique(array_merge(
            $supportWorkerPermissionKeys,
            [
                'clinical.dashboard',
                'clinical.events.review',
                'clinical.events.viewAny',
                'clinical.observations.correct',
                'clinical.observations.recordClinical',
                'clinical.observations.viewAny',
                'clinical.protocols.manage',
                'clinical.protocols.viewAny',
                'clients.assignments.update',
                'clients.update',
                'clients.viewAny',
                'controlRoom.alerts.assign',
                'controlRoom.alerts.escalate',
                'timesheets.approve',
                'incidents.approve',
                'incidents.followups.manage',
                'incidents.reopen',
                'incidents.update',
                'incidents.viewAny',
                'medications.administer.correct',
                'medications.orders.manage',
                'reports.viewAny',
                'shifts.manageAny',
                'shifts.update',
                'shifts.viewAny',
                'staff.assignments.update',
                'staff.credentials.updateAny',
                'staff.credentials.viewAny',
                'staff.viewAny',
                'timesheets.approve',
                'timesheets.manageAny',
                'timesheets.viewAny',
            ],
        )));

        $syncRolePermissions($supportWorkerRole, $supportWorkerPermissionKeys);
        $syncRolePermissions($managerRole, $managerPermissionKeys);

        $staffUser = $this->seedUser([
            'name' => 'Test Staff',
            'email' => 'staff@test.com',
            'approved_at' => now(),
            'role' => 'support_worker',
        ]);

        $managerUser = $this->seedUser([
            'name' => 'Test Manager',
            'email' => 'manager@test.com',
            'approved_at' => now(),
            'role' => 'manager',
        ]);

        // ──────────────────────────────────────────────
        // Staff profile
        // ──────────────────────────────────────────────
        $this->seed(function () use ($staffUser) {
            if (! Schema::hasTable('staff')) {
                return null;
            }

            $lookup = $this->existingColumns('staff', [
                'employee_id' => 'EMP-QA-0003',
            ]);

            if ($lookup === []) {
                return null;
            }

            return Staff::query()->updateOrCreate(
                $lookup,
                $this->existingColumns('staff', [
                    'user_id' => $staffUser->id,
                    'job_title' => 'Support Worker',
                    'department' => 'Operations',
                    'hire_date' => now()->subYear()->toDateString(),
                    'mobile_phone' => '0210000000',
                    'status' => 'active',
                ])
            );
        });

        // ──────────────────────────────────────────────
        // Sites & Service Contexts
        // ──────────────────────────────────────────────
        $site = Site::query()->withoutGlobalScopes()->updateOrCreate(
            ['name' => 'QA Main Site'],
            $this->existingColumns('sites', [
                'type' => 'house',
                'city' => 'Auckland',
                'region' => 'Auckland',
                'country' => 'New Zealand',
                'is_active' => true,
            ])
        );

        $context = ServiceContext::query()->firstOrCreate(
            ['name' => 'QA Service Context'],
            $this->existingColumns('service_contexts', [
                'type' => 'residential',
                'site_id' => $site->id,
                'is_active' => true,
            ])
        );

        foreach ([
            [
                'user' => $admin,
                'employee_number' => 'EMP0001',
                'position_title' => 'Administrator',
                'position_role' => 'admin',
                'employment_type' => 'full_time',
                'work_email' => 'admin@test.com',
                'manager_user_id' => null,
            ],
            [
                'user' => $managerUser,
                'employee_number' => 'EMP0002',
                'position_title' => 'Manager',
                'position_role' => 'manager',
                'employment_type' => 'full_time',
                'work_email' => 'manager@test.com',
                'manager_user_id' => $admin->id,
            ],
            [
                'user' => $staffUser,
                'employee_number' => 'EMP0003',
                'position_title' => 'Support Worker',
                'position_role' => 'support_worker',
                'employment_type' => 'full_time',
                'work_email' => 'staff@test.com',
                'manager_user_id' => $managerUser->id,
            ],
        ] as $profileData) {
            HrEmployeeProfile::firstOrCreate(
                ['user_id' => $profileData['user']->id],
                $this->existingColumns('hr_employee_profiles', [
                    'employee_number' => $profileData['employee_number'],
                    'work_email' => $profileData['work_email'],
                    'position_title' => $profileData['position_title'],
                    'position_role' => $profileData['position_role'],
                    'employment_type' => $profileData['employment_type'],
                    'contract_type' => 'individual',
                    'pay_frequency' => 'fortnightly',
                    'start_date' => now()->subYear()->toDateString(),
                    'is_active' => true,
                    'primary_site_id' => $site->id,
                    'manager_user_id' => $profileData['manager_user_id'],
                    'department' => 'HR',
                    'team' => 'People',
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                ])
            );
        }

        BoardMember::firstOrCreate(
            ['user_id' => $admin->id],
            [
                'board_role' => 'chair',
                'term_start' => now()->subMonths(6)->toDateString(),
                'term_end' => now()->addYears(2)->toDateString(),
                'is_independent' => true,
                'is_active' => true,
            ]
        );

        // ──────────────────────────────────────────────
        // Clients
        // ──────────────────────────────────────────────
        $client = Client::query()->withoutGlobalScopes()->firstOrCreate(
            [
                'first_name' => 'Test',
                'last_name' => 'Client',
            ],
            $this->existingColumns('clients', [
                'status' => 'active',
                'date_of_birth' => now()->subYears(30)->toDateString(),
                'nhi_number' => null,
                'phone' => null,
                'email' => null,
                'address_line_1' => '1 QA Street',
                'city' => 'Auckland',
                'region' => 'Auckland',
                'postcode' => '1010',
            ])
        );

        $this->seed(fn () => ClientMedicalProfile::factory()->create(['client_id' => $client->id]));
        $this->seed(fn () => ClientMedication::factory()->create(['client_id' => $client->id]));

        // ──────────────────────────────────────────────
        // Assets
        // ──────────────────────────────────────────────
        $asset = $this->seed(fn () => Asset::factory()->create(['site_id' => $site->id]));
        $vehicleAsset = $this->seed(fn () => Asset::factory()->create([
            'site_id' => $site->id,
            'category' => 'vehicle',
        ]));

        // ──────────────────────────────────────────────
        // Incidents & Safeguarding
        // ──────────────────────────────────────────────
        $incident = $this->seed(fn () => ClientIncident::factory()->create([
            'client_id' => $client->id,
            'reported_by' => $admin->id,
        ]));

        if ($incident) {
            $this->seed(fn () => IncidentFollowup::factory()->create([
                'client_incident_id' => $incident->id,
                'assigned_to_user_id' => $staffUser->id,
                'created_by' => $admin->id,
            ]));
        }

        $this->seed(fn () => SafeguardingConcern::factory()->create([
            'reported_by_user_id' => $admin->id,
        ]));

        // ──────────────────────────────────────────────
        // Rostering & Timesheets
        // ──────────────────────────────────────────────
        $this->seed(fn () => Shift::factory()->create([
            'client_id' => $client->id,
            'user_id' => $staffUser->id,
            'created_by' => $admin->id,
        ]));

        $this->seed(fn () => Timesheet::factory()->create([
            'user_id' => $staffUser->id,
            'client_id' => $client->id,
            'created_by' => $admin->id,
        ]));

        $this->seed(fn () => TimelineEvent::factory()->create([
            'actor_user_id' => $admin->id,
        ]));

        // ──────────────────────────────────────────────
        // Respite
        // ──────────────────────────────────────────────
        $booking = $this->seed(fn () => RespiteBooking::factory()->create([
            'client_id' => $client->id,
        ]));
        $this->seed(fn () => RespiteStay::firstOrCreate(
            ['booking_id' => $booking->id],
            [
                'client_id' => $client->id,
                'status' => 'active',
                'actual_start' => now()->subHours(4),
                'arrival_checklist' => [],
                'arrival_checklist_complete' => true,
                'transport_arrangements' => [],
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]
        ));
        $this->seed(fn () => ProcedureTemplate::firstOrCreate(
            ['name' => 'QA Medication Handover', 'domain' => 'respite'],
            [
                'version' => '1.0',
                'trigger_event' => 'manual',
                'description' => 'Ensure respite medication handover is documented and signed off.',
                'steps_json' => [
                    [
                        'name' => 'Confirm medications on arrival',
                        'instructions' => 'Check medication packs against the booking paperwork.',
                        'sla_minutes' => 30,
                    ],
                    [
                        'name' => 'Record storage and handover',
                        'instructions' => 'Document where medicines are stored and who accepted them.',
                        'sla_minutes' => 45,
                    ],
                ],
                'required_roles' => ['support_worker'],
                'active' => true,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]
        ));
        $this->seed(fn () => RespiteTask::factory()->create());

        // ──────────────────────────────────────────────
        // Fleet
        // ──────────────────────────────────────────────
        if ($vehicleAsset) {
            $this->seed(fn () => FleetVehicleBooking::factory()->create([
                'asset_id' => $vehicleAsset->id,
                'user_id' => $staffUser->id,
            ]));
            $this->seed(fn () => FleetWorkOrder::factory()->create([
                'asset_id' => $vehicleAsset->id,
                'reported_by_user_id' => $staffUser->id,
            ]));
            $this->seed(fn () => FleetTrip::factory()->create([
                'asset_id' => $vehicleAsset->id,
            ]));
        }
        $this->seed(fn () => GeofenceZone::factory()->create());

        // ──────────────────────────────────────────────
        // Health & Safety
        // ──────────────────────────────────────────────
        $this->seed(fn () => EmergencyDrill::factory()->create(['site_id' => $site->id]));
        $this->seed(fn () => WorkplaceInjury::factory()->create(['user_id' => $staffUser->id]));
        $this->seed(fn () => SafeWorkProcedure::factory()->create());
        $this->seed(fn () => HazardousSubstance::factory()->create());

        // ──────────────────────────────────────────────
        // Privacy & Legal
        // ──────────────────────────────────────────────
        $this->call(StandardConsentTypesSeeder::class);
        $this->seed(fn () => DataBreachLog::factory()->create());
        $this->seed(fn () => DataSubjectRequest::factory()->create());
        $this->seed(fn () => DataRetentionPolicy::factory()->create());
        $this->seed(fn () => PrivacyImpactAssessment::factory()->create());
        $this->seed(fn () => LegalHold::factory()->create());

        // ──────────────────────────────────────────────
        // Training & Competency
        // ──────────────────────────────────────────────
        $this->seed(fn () => TrainingCourse::factory()->create());
        $this->seed(fn () => CompetencyFramework::factory()->create());

        // ──────────────────────────────────────────────
        // Service Agreements, Quotes & Price Books
        // ──────────────────────────────────────────────
        $this->seed(fn () => ServiceAgreement::factory()->create([
            'client_id' => $client->id,
        ]));
        $this->seed(fn () => Quote::factory()->create());
        $this->seed(fn () => PriceBook::factory()->create());

        // ──────────────────────────────────────────────
        // Control Room
        // ──────────────────────────────────────────────
        $this->seed(fn () => ControlRoomAlert::factory()->create());
        $this->seed(fn () => Playbook::factory()->create());

        // ──────────────────────────────────────────────
        // HR
        // ──────────────────────────────────────────────
        $this->seed(fn () => HrPosition::factory()->create());
        $this->seed(fn () => HrPolicy::factory()->create());
        $this->seed(fn () => HrLeaveRequest::factory()->create([
            'user_id' => $staffUser->id,
        ]));
        $this->seed(fn () => HrExpenseClaim::factory()->create([
            'user_id' => $staffUser->id,
        ]));
        $this->seed(fn () => HrGoal::factory()->create([
            'user_id' => $staffUser->id,
            'created_by' => $admin->id,
        ]));
        $this->seed(fn () => HrPerformanceReview::factory()->create([
            'employee_user_id' => $staffUser->id,
            'reviewer_user_id' => $managerUser->id,
        ]));
        $this->seed(fn () => HrCase::factory()->create([
            'user_id' => $staffUser->id,
        ]));
        $this->seed(fn () => HrAnnouncement::factory()->create([
            'created_by' => $admin->id,
        ]));
        // Skipped: HrExitInterview requires employee_profile_id FK
        // $this->seed(fn () => \App\Domain\Hr\Models\HrExitInterview::factory()->create([
        //     'interviewer_user_id' => $managerUser->id,
        //     'created_by' => $admin->id,
        // ]));
        // Skipped: HrOnboardingChecklist requires employee_profile_id FK
        // $this->seed(fn () => \App\Domain\Hr\Models\HrOnboardingChecklist::factory()->create());
        $this->seed(fn () => HrCompensationReview::factory()->create());
        $this->seed(fn () => HrSuccessionPlan::factory()->create([
            'site_id' => $site->id,
            'current_holder_user_id' => $staffUser->id,
            'created_by' => $admin->id,
        ]));

        // ──────────────────────────────────────────────
        // Finance
        // ──────────────────────────────────────────────
        $vendor = $this->seed(fn () => FinVendor::factory()->create());
        $this->seed(fn () => FinAccount::factory()->create());
        $this->seed(fn () => FinInvoice::factory()->create());
        $this->seed(fn () => FinCreditNote::factory()->create());

        if ($vendor) {
            $this->seed(fn () => FinBill::factory()->create([
                'vendor_id' => $vendor->id,
            ]));
            $this->seed(fn () => FinPurchaseOrder::factory()->create([
                'vendor_id' => $vendor->id,
            ]));
        }

        $this->seed(fn () => FinBankAccount::factory()->create());
        $this->seed(fn () => FinJournal::factory()->create());
        $this->seed(fn () => FinPaymentRun::factory()->create());
        $this->seed(fn () => FinPettyCashFund::factory()->create());
        $this->seed(fn () => FinFixedAsset::factory()->create());
        $this->seed(fn () => FinGstReturn::factory()->create());
        $this->seed(fn () => FinCashFlowForecast::factory()->create());
        $this->seed(fn () => FinDonorFund::factory()->create());

        // ──────────────────────────────────────────────
        // Governance
        // ──────────────────────────────────────────────
        $this->seed(fn () => GovernancePolicy::factory()->create([
            'created_by' => $admin->id,
        ]));
        $this->seed(fn () => GovernanceMeeting::factory()->create([
            'created_by' => $admin->id,
        ]));

        User::query()
            ->where('role', 'admin')
            ->get()
            ->each(fn (User $user) => $user->roles()->syncWithoutDetaching([$adminRole->id]));

        User::query()
            ->where('role', 'manager')
            ->get()
            ->each(fn (User $user) => $user->roles()->syncWithoutDetaching([$managerRole->id]));

        User::query()
            ->where('role', 'support_worker')
            ->get()
            ->each(fn (User $user) => $user->roles()->syncWithoutDetaching([$supportWorkerRole->id]));
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

    /**
     * Create or refresh a deterministic QA user so the Dusk seed can be rerun safely.
     */
    private function seedUser(array $overrides): User
    {
        $attributes = User::factory()
            ->withoutTwoFactor()
            ->make($overrides)
            ->getAttributes();

        return User::query()->updateOrCreate(
            ['email' => $attributes['email']],
            $attributes
        );
    }

    /**
     * Filter a payload down to columns that actually exist in the current schema.
     */
    private function existingColumns(string $table, array $attributes): array
    {
        return collect($attributes)
            ->filter(fn (mixed $value, string $column): bool => Schema::hasColumn($table, $column))
            ->all();
    }
}
