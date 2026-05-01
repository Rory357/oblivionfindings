<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | 1. Roles with Levels and Types
        |--------------------------------------------------------------------------
        */
        $roleDefinitions = [
            // System Admin (highest level)
            ['name' => 'admin', 'label' => 'Administrator', 'level' => 100, 'type' => 'system', 'description' => 'Full system access across all sites'],

            // C-Suite / Executive
            ['name' => 'ceo', 'label' => 'CEO', 'level' => 95, 'type' => 'system', 'description' => 'Chief Executive Officer'],
            ['name' => 'coo', 'label' => 'COO', 'level' => 94, 'type' => 'system', 'description' => 'Chief Operating Officer'],
            ['name' => 'cfo', 'label' => 'CFO', 'level' => 93, 'type' => 'system', 'description' => 'Chief Financial Officer'],

            // Board/Governance roles
            ['name' => 'board_chair', 'label' => 'Board Chair', 'level' => 90, 'type' => 'system', 'description' => 'Board chairperson with governance oversight'],
            ['name' => 'board_secretary', 'label' => 'Board Secretary', 'level' => 88, 'type' => 'system', 'description' => 'Board secretary with meeting management'],
            ['name' => 'board_member', 'label' => 'Board Member', 'level' => 85, 'type' => 'system', 'description' => 'Board member with governance access'],
            ['name' => 'board_observer', 'label' => 'Board Observer', 'level' => 80, 'type' => 'system', 'description' => 'Board observer with read-only access'],
            ['name' => 'board_trustee', 'label' => 'Board Trustee', 'level' => 75, 'type' => 'system', 'description' => 'Board trustee with read-only reports access'],

            // Management roles
            ['name' => 'provider_manager', 'label' => 'Provider Manager', 'level' => 70, 'type' => 'system', 'description' => 'Manages daily operations and staff'],
            ['name' => 'compliance_lead', 'label' => 'Compliance Lead', 'level' => 68, 'type' => 'system', 'description' => 'Leads compliance and regulatory matters'],
            ['name' => 'risk_lead', 'label' => 'Risk Lead', 'level' => 66, 'type' => 'system', 'description' => 'Manages organizational risk'],
            ['name' => 'it_manager', 'label' => 'IT Manager', 'level' => 65, 'type' => 'system', 'description' => 'Manages IT systems and integrations'],
            ['name' => 'facilities_manager', 'label' => 'Facilities Manager', 'level' => 64, 'type' => 'system', 'description' => 'Manages facilities and maintenance'],
            ['name' => 'roadmap_manager', 'label' => 'Roadmap Manager', 'level' => 62, 'type' => 'system', 'description' => 'Manages organizational roadmap'],

            // Department leads
            ['name' => 'coordinator', 'label' => 'Coordinator', 'level' => 60, 'type' => 'system', 'description' => 'Coordinates care and operations'],
            ['name' => 'team_lead', 'label' => 'Team Lead', 'level' => 55, 'type' => 'system', 'description' => 'Leads a team of support workers'],
            ['name' => 'clinical_lead', 'label' => 'Clinical Lead', 'level' => 58, 'type' => 'system', 'description' => 'Clinical oversight and medication authority'],
            ['name' => 'health_safety_officer', 'label' => 'Health & Safety Officer', 'level' => 54, 'type' => 'system', 'description' => 'Manages health and safety compliance'],
            ['name' => 'maintenance_coordinator', 'label' => 'Maintenance Coordinator', 'level' => 52, 'type' => 'system', 'description' => 'Coordinates maintenance activities'],

            // Staff roles
            ['name' => 'support_worker', 'label' => 'Support Worker', 'level' => 40, 'type' => 'system', 'description' => 'Regular staff with limited access'],
            ['name' => 'finance', 'label' => 'Finance', 'level' => 50, 'type' => 'system', 'description' => 'Finance department access'],
            ['name' => 'hr', 'label' => 'HR', 'level' => 50, 'type' => 'system', 'description' => 'Human Resources department access'],
            ['name' => 'auditor', 'label' => 'Auditor (Read only)', 'level' => 45, 'type' => 'system', 'description' => 'Read-only audit and reporting access'],

            // Portal roles (lowest level)
            ['name' => 'client', 'label' => 'Client (Portal)', 'level' => 20, 'type' => 'system', 'description' => 'Client portal access'],
            ['name' => 'next_of_kin', 'label' => 'Next of Kin / Guardian (Portal)', 'level' => 15, 'type' => 'system', 'description' => 'Family member portal access'],
        ];

        foreach ($roleDefinitions as $roleDef) {
            Role::firstOrCreate(
                ['name' => $roleDef['name']],
                [
                    'label' => $roleDef['label'],
                    'level' => $roleDef['level'],
                    'type' => $roleDef['type'],
                    'description' => $roleDef['description'],
                ]
            );
        }

        // Remove any roles we are not using right now (but only if they are not assigned).
        $activeRoleNames = array_column($roleDefinitions, 'name');
        Role::query()
            ->whereNotIn('name', $activeRoleNames)
            ->doesntHave('users')
            ->delete();

        // Get role models for later use
        $admin = Role::where('name', 'admin')->first();
        $providerManager = Role::where('name', 'provider_manager')->first();
        $coordinator = Role::where('name', 'coordinator')->first();
        $supportWorker = Role::where('name', 'support_worker')->first();
        $finance = Role::where('name', 'finance')->first();
        $hr = Role::where('name', 'hr')->first();
        $auditor = Role::where('name', 'auditor')->first();
        $teamLead = Role::where('name', 'team_lead')->first();
        $healthSafetyOfficer = Role::where('name', 'health_safety_officer')->first();
        $maintenanceCoordinator = Role::where('name', 'maintenance_coordinator')->first();
        $roadmapManager = Role::where('name', 'roadmap_manager')->first();
        $itManager = Role::where('name', 'it_manager')->first();
        $facilitiesManager = Role::where('name', 'facilities_manager')->first();
        $clientRole = Role::where('name', 'client')->first();
        $nextOfKinRole = Role::where('name', 'next_of_kin')->first();
        $boardChair = Role::where('name', 'board_chair')->first();
        $boardSecretary = Role::where('name', 'board_secretary')->first();
        $boardMemberRole = Role::where('name', 'board_member')->first();
        $boardObserver = Role::where('name', 'board_observer')->first();
        $boardTrustee = Role::where('name', 'board_trustee')->first();
        $ceo = Role::where('name', 'ceo')->first();
        $cfo = Role::where('name', 'cfo')->first();
        $coo = Role::where('name', 'coo')->first();
        $complianceLead = Role::where('name', 'compliance_lead')->first();
        $riskLead = Role::where('name', 'risk_lead')->first();
        $clinicalLead = Role::where('name', 'clinical_lead')->first();

        /*
        |--------------------------------------------------------------------------
        | 2. Permissions with Groups and Modules
        |--------------------------------------------------------------------------
        */
        $permissionDefinitions = [
            // Access Control
            ['key' => 'settings.access.manage', 'description' => 'Manage user access (roles & overrides)', 'group' => 'access_control', 'module' => 'System'],
            ['key' => 'settings.access.view_roles', 'description' => 'View roles and permissions', 'group' => 'access_control', 'module' => 'System'],
            ['key' => 'settings.access.create_roles', 'description' => 'Create custom roles', 'group' => 'access_control', 'module' => 'System'],
            ['key' => 'settings.access.edit_roles', 'description' => 'Edit role permissions', 'group' => 'access_control', 'module' => 'System'],
            ['key' => 'settings.access.delete_roles', 'description' => 'Delete custom roles', 'group' => 'access_control', 'module' => 'System'],
            ['key' => 'settings.access.impersonate', 'description' => 'Impersonate other users', 'group' => 'access_control', 'module' => 'System'],

            // Sites
            ['key' => 'sites.viewAny', 'description' => 'View sites', 'group' => 'sites', 'module' => 'Operations'],
            ['key' => 'sites.create', 'description' => 'Create sites', 'group' => 'sites', 'module' => 'Operations'],
            ['key' => 'sites.update', 'description' => 'Update sites', 'group' => 'sites', 'module' => 'Operations'],
            ['key' => 'sites.archive', 'description' => 'Archive/soft-delete sites', 'group' => 'sites', 'module' => 'Operations'],
            ['key' => 'sites.type.head_office.view', 'description' => 'View Head Office sites', 'group' => 'sites', 'module' => 'Operations'],
            ['key' => 'sites.type.house.view', 'description' => 'View House sites', 'group' => 'sites', 'module' => 'Operations'],
            ['key' => 'sites.type.facility.view', 'description' => 'View Facility sites', 'group' => 'sites', 'module' => 'Operations'],

            // Assets
            ['key' => 'assets.viewAny', 'description' => 'View all assets', 'group' => 'assets', 'module' => 'Resources'],
            ['key' => 'assets.viewAssigned', 'description' => 'View assigned assets only', 'group' => 'assets', 'module' => 'Resources'],
            ['key' => 'assets.create', 'description' => 'Create assets', 'group' => 'assets', 'module' => 'Resources'],
            ['key' => 'assets.update', 'description' => 'Update assets', 'group' => 'assets', 'module' => 'Resources'],
            ['key' => 'assets.delete', 'description' => 'Delete assets', 'group' => 'assets', 'module' => 'Resources'],
            ['key' => 'assets.inspections.record', 'description' => 'Record asset inspections', 'group' => 'assets', 'module' => 'Resources'],
            ['key' => 'assets.maintenance.record', 'description' => 'Record asset maintenance', 'group' => 'assets', 'module' => 'Resources'],
            ['key' => 'assets.documents.manage', 'description' => 'Manage asset documents', 'group' => 'assets', 'module' => 'Resources'],
            ['key' => 'assets.qr.download', 'description' => 'Download asset QR codes', 'group' => 'assets', 'module' => 'Resources'],
            ['key' => 'assets.ownership.manage', 'description' => 'Manage asset ownership', 'group' => 'assets', 'module' => 'Resources'],
            ['key' => 'assets.assignments.manage', 'description' => 'Manage asset assignments', 'group' => 'assets', 'module' => 'Resources'],
            ['key' => 'assets.trackers.manage', 'description' => 'Manage asset trackers', 'group' => 'assets', 'module' => 'Resources'],
            ['key' => 'assets.telemetry.ingest', 'description' => 'Ingest asset telemetry', 'group' => 'assets', 'module' => 'Resources'],
            ['key' => 'assets.telemetry.view', 'description' => 'View asset telemetry', 'group' => 'assets', 'module' => 'Resources'],
            ['key' => 'assets.alerts.view', 'description' => 'View asset alerts', 'group' => 'assets', 'module' => 'Resources'],
            ['key' => 'assets.alerts.manage', 'description' => 'Manage asset alerts', 'group' => 'assets', 'module' => 'Resources'],
            ['key' => 'assets.scan.record', 'description' => 'Record asset scans', 'group' => 'assets', 'module' => 'Resources'],
            ['key' => 'assets.geofences.manage', 'description' => 'Manage asset geofences and policies', 'group' => 'assets', 'module' => 'Resources'],
            ['key' => 'assets.view_register', 'description' => 'View site asset register', 'group' => 'assets', 'module' => 'Resources'],
            ['key' => 'assets.manage_register', 'description' => 'Manage site asset register', 'group' => 'assets', 'module' => 'Resources'],

            // Staff
            ['key' => 'staff.viewAny', 'description' => 'View staff', 'group' => 'staff', 'module' => 'HR'],
            ['key' => 'staff.create', 'description' => 'Create staff', 'group' => 'staff', 'module' => 'HR'],
            ['key' => 'staff.update', 'description' => 'Update staff', 'group' => 'staff', 'module' => 'HR'],
            ['key' => 'staff.invite', 'description' => 'Invite staff', 'group' => 'staff', 'module' => 'HR'],
            ['key' => 'staff.assignments.update', 'description' => 'Assign clients to staff', 'group' => 'staff', 'module' => 'HR'],
            ['key' => 'staff.credentials.viewAny', 'description' => 'View staff credentials', 'group' => 'staff', 'module' => 'HR'],
            ['key' => 'staff.credentials.updateAny', 'description' => 'Manage staff credentials', 'group' => 'staff', 'module' => 'HR'],
            ['key' => 'staff.credentials.updateSelf', 'description' => 'Manage own credentials', 'group' => 'staff', 'module' => 'HR'],
            ['key' => 'staff.availability.updateAny', 'description' => 'Manage staff availability', 'group' => 'staff', 'module' => 'HR'],
            ['key' => 'staff.availability.updateSelf', 'description' => 'Manage own availability', 'group' => 'staff', 'module' => 'HR'],
            ['key' => 'staff.vetting.view', 'description' => 'View background checks', 'group' => 'staff', 'module' => 'HR'],
            ['key' => 'staff.vetting.manage', 'description' => 'Manage background checks (DBS, references)', 'group' => 'staff', 'module' => 'HR'],
            ['key' => 'staff.induction.manage', 'description' => 'Manage staff induction process', 'group' => 'staff', 'module' => 'HR'],

            // Workers / modules
            ['key' => 'workers.viewAny', 'description' => 'View workers', 'group' => 'general', 'module' => 'System'],
            ['key' => 'reports.viewAny', 'description' => 'View reports', 'group' => 'reports', 'module' => 'System'],
            ['key' => 'rostering.viewAny', 'description' => 'View rostering', 'group' => 'staff', 'module' => 'HR'],

            // Fleet
            ['key' => 'fleet.viewAny', 'description' => 'View fleet management', 'group' => 'fleet', 'module' => 'Resources'],
            ['key' => 'fleet.manage', 'description' => 'Full fleet management access', 'group' => 'fleet', 'module' => 'Resources'],
            ['key' => 'fleet.driverSessions.manage', 'description' => 'Start/end driver sessions', 'group' => 'fleet', 'module' => 'Resources'],
            ['key' => 'fleet.signals.view', 'description' => 'View fleet signals', 'group' => 'fleet', 'module' => 'Resources'],
            ['key' => 'fleet.trips.manage', 'description' => 'Manage fleet trips (edit/delete)', 'group' => 'fleet', 'module' => 'Resources'],
            ['key' => 'fleet.fuel.manage', 'description' => 'Manage fleet fuel records', 'group' => 'fleet', 'module' => 'Resources'],
            ['key' => 'fleet.reports.view', 'description' => 'View fleet reports', 'group' => 'fleet', 'module' => 'Resources'],
            ['key' => 'fleet.bookings.approve', 'description' => 'Approve/reject vehicle bookings', 'group' => 'fleet', 'module' => 'Resources'],
            ['key' => 'fleet.incidents.manage', 'description' => 'Manage fleet incidents', 'group' => 'fleet', 'module' => 'Resources'],
            ['key' => 'fleet.maintenance.manage', 'description' => 'Manage fleet maintenance', 'group' => 'fleet', 'module' => 'Resources'],
            ['key' => 'fleet.medication.manage', 'description' => 'Pack/administer medications during transport', 'group' => 'fleet', 'module' => 'Resources'],
            ['key' => 'fleet.mileage.approve', 'description' => 'Approve/reject mileage claims', 'group' => 'fleet', 'module' => 'Resources'],
            ['key' => 'fleet.outings.manage', 'description' => 'Manage fleet outings', 'group' => 'fleet', 'module' => 'Resources'],

            // Control Room
            // `controlRoom.viewAny` is the full operator dashboard permission.
            // `controlRoom.alerts.view` is intentionally narrower: it grants
            // read-only access to alert lists used by team-lead-tier roles and
            // does not imply manage, assign, escalate, or create capability.
            ['key' => 'controlRoom.viewAny', 'description' => 'View Control Room', 'group' => 'control_room', 'module' => 'System'],
            ['key' => 'controlRoom.alerts.view', 'description' => 'View Control Room alerts', 'group' => 'control_room', 'module' => 'System'],
            ['key' => 'controlRoom.alerts.manage', 'description' => 'Manage alerts (acknowledge, triage, resolve, close)', 'group' => 'control_room', 'module' => 'System'],
            ['key' => 'controlRoom.alerts.assign', 'description' => 'Assign alerts to staff', 'group' => 'control_room', 'module' => 'System'],
            ['key' => 'controlRoom.alerts.escalate', 'description' => 'Escalate alerts', 'group' => 'control_room', 'module' => 'System'],
            ['key' => 'controlRoom.alerts.create', 'description' => 'Create alerts manually or via API', 'group' => 'control_room', 'module' => 'System'],
            ['key' => 'controlRoom.reports.view', 'description' => 'View Control Room reports', 'group' => 'control_room', 'module' => 'System'],

            // Calendar
            ['key' => 'calendar.viewAny', 'description' => 'View calendar', 'group' => 'calendar', 'module' => 'Operations'],
            ['key' => 'calendar.view', 'description' => 'View calendars', 'group' => 'calendar', 'module' => 'Operations'],
            ['key' => 'calendar.create', 'description' => 'Create calendar events', 'group' => 'calendar', 'module' => 'Operations'],
            ['key' => 'calendar.approve', 'description' => 'Approve calendar events', 'group' => 'calendar', 'module' => 'Operations'],
            ['key' => 'calendar.manage', 'description' => 'Edit and delete calendar events', 'group' => 'calendar', 'module' => 'Operations'],
            ['key' => 'calendar.manage_recurring', 'description' => 'Manage recurring events', 'group' => 'calendar', 'module' => 'Operations'],

            // Shifts
            ['key' => 'shifts.viewAny', 'description' => 'View shifts', 'group' => 'shifts', 'module' => 'Operations'],
            ['key' => 'shifts.viewAssigned', 'description' => 'View assigned shifts only', 'group' => 'shifts', 'module' => 'Operations'],
            ['key' => 'shifts.create', 'description' => 'Create shifts', 'group' => 'shifts', 'module' => 'Operations'],
            ['key' => 'shifts.update', 'description' => 'Update shifts', 'group' => 'shifts', 'module' => 'Operations'],
            ['key' => 'shifts.manageAny', 'description' => 'Manage any staff shifts', 'group' => 'shifts', 'module' => 'Operations'],
            ['key' => 'shifts.overrideEligibility', 'description' => 'Override eligibility warnings for shift assignment', 'group' => 'shifts', 'module' => 'Operations'],
            ['key' => 'shifts.tasks.updateSelf', 'description' => 'Complete tasks on own shifts', 'group' => 'shifts', 'module' => 'Operations'],

            // Timesheets
            ['key' => 'timesheets.viewAny', 'description' => 'View timesheets', 'group' => 'timesheets', 'module' => 'Operations'],
            ['key' => 'timesheets.viewAssigned', 'description' => 'View assigned timesheets only', 'group' => 'timesheets', 'module' => 'Operations'],
            ['key' => 'timesheets.create', 'description' => 'Create timesheets', 'group' => 'timesheets', 'module' => 'Operations'],
            ['key' => 'timesheets.update', 'description' => 'Update timesheets', 'group' => 'timesheets', 'module' => 'Operations'],
            ['key' => 'timesheets.submit', 'description' => 'Submit timesheets for approval', 'group' => 'timesheets', 'module' => 'Operations'],
            ['key' => 'timesheets.approve', 'description' => 'Approve/reject timesheets', 'group' => 'timesheets', 'module' => 'Operations'],
            ['key' => 'timesheets.manageAny', 'description' => 'Manage any staff timesheets', 'group' => 'timesheets', 'module' => 'Operations'],

            // Clients
            ['key' => 'clients.viewAny', 'description' => 'View clients', 'group' => 'clients', 'module' => 'Operations'],
            ['key' => 'clients.viewAssigned', 'description' => 'View assigned clients only', 'group' => 'clients', 'module' => 'Operations'],
            ['key' => 'clients.create', 'description' => 'Create clients', 'group' => 'clients', 'module' => 'Operations'],
            ['key' => 'clients.update', 'description' => 'Update clients', 'group' => 'clients', 'module' => 'Operations'],
            ['key' => 'clients.assignments.update', 'description' => 'Manage client assignments', 'group' => 'clients', 'module' => 'Operations'],
            ['key' => 'clients.onboarding.manage', 'description' => 'Manage client onboarding checklist', 'group' => 'clients', 'module' => 'Operations'],

            // Medications
            ['key' => 'medications.view', 'description' => 'View medications module', 'group' => 'medications', 'module' => 'Clinical'],
            ['key' => 'medications.orders.manage', 'description' => 'Create/update medication orders', 'group' => 'medications', 'module' => 'Clinical'],
            ['key' => 'medications.administer.record', 'description' => 'Record medication administrations (MAR)', 'group' => 'medications', 'module' => 'Clinical'],
            ['key' => 'medications.administer.correct', 'description' => 'Correct medication administrations', 'group' => 'medications', 'module' => 'Clinical'],
            ['key' => 'medications.stock.update', 'description' => 'Update medication stock counts', 'group' => 'medications', 'module' => 'Clinical'],
            ['key' => 'medications.controlled.view', 'description' => 'View controlled drug register entries', 'group' => 'medications', 'module' => 'Clinical'],
            ['key' => 'medications.controlled.record', 'description' => 'Record controlled drug register entries', 'group' => 'medications', 'module' => 'Clinical'],
            ['key' => 'medications.controlled.witness', 'description' => 'Witness controlled drug administrations', 'group' => 'medications', 'module' => 'Clinical'],
            ['key' => 'medications.controlled.override', 'description' => 'Override controlled drug discrepancy blocks', 'group' => 'medications', 'module' => 'Clinical'],
            ['key' => 'medications.audit.view', 'description' => 'View medication-focused audit log', 'group' => 'medications', 'module' => 'Clinical'],
            ['key' => 'medications.reports.export', 'description' => 'Export MAR/audit/medications reports', 'group' => 'medications', 'module' => 'Clinical'],
            ['key' => 'medications.breakglass', 'description' => 'Use break-glass emergency access', 'group' => 'medications', 'module' => 'Clinical'],

            // Health & Clinical
            ['key' => 'clinical.observations.view', 'description' => 'View clinical observations', 'group' => 'clinical', 'module' => 'Clinical'],
            ['key' => 'clinical.observations.viewAny', 'description' => 'View all clinical observations', 'group' => 'clinical', 'module' => 'Health & Clinical'],
            ['key' => 'clinical.observations.viewAssigned', 'description' => 'View observations for assigned clients', 'group' => 'clinical', 'module' => 'Health & Clinical'],
            ['key' => 'clinical.observations.record', 'description' => 'Record clinical observations', 'group' => 'clinical', 'module' => 'Clinical'],
            ['key' => 'clinical.observations.recordClinical', 'description' => 'Record clinical observations (vitals, pain)', 'group' => 'clinical', 'module' => 'Health & Clinical'],
            ['key' => 'clinical.observations.correct', 'description' => 'Submit observation corrections', 'group' => 'clinical', 'module' => 'Health & Clinical'],
            ['key' => 'clinical.events.view', 'description' => 'View clinical events', 'group' => 'clinical', 'module' => 'Clinical'],
            ['key' => 'clinical.events.viewAny', 'description' => 'View all clinical events', 'group' => 'clinical', 'module' => 'Health & Clinical'],
            ['key' => 'clinical.events.viewAssigned', 'description' => 'View clinical events for assigned clients', 'group' => 'clinical', 'module' => 'Health & Clinical'],
            ['key' => 'clinical.events.record', 'description' => 'Record clinical events', 'group' => 'clinical', 'module' => 'Clinical'],
            ['key' => 'clinical.events.review', 'description' => 'Review and close clinical events', 'group' => 'clinical', 'module' => 'Health & Clinical'],
            ['key' => 'clinical.protocols.viewAny', 'description' => 'View clinical protocols', 'group' => 'clinical', 'module' => 'Health & Clinical'],
            ['key' => 'clinical.protocols.manage', 'description' => 'Manage clinical protocols', 'group' => 'clinical', 'module' => 'Clinical'],
            ['key' => 'clinical.dashboard', 'description' => 'Access the Health & Clinical dashboard', 'group' => 'clinical', 'module' => 'Health & Clinical'],

            // Incidents
            ['key' => 'incidents.viewAny', 'description' => 'View all incidents', 'group' => 'incidents', 'module' => 'Compliance'],
            ['key' => 'incidents.viewAssigned', 'description' => 'View incidents for assigned clients', 'group' => 'incidents', 'module' => 'Compliance'],
            ['key' => 'incidents.create', 'description' => 'Create incident reports', 'group' => 'incidents', 'module' => 'Compliance'],
            ['key' => 'incidents.update', 'description' => 'Update incident reports', 'group' => 'incidents', 'module' => 'Compliance'],
            ['key' => 'incidents.submit', 'description' => 'Submit standalone incident reports', 'group' => 'incidents', 'module' => 'Compliance'],
            ['key' => 'incidents.approve', 'description' => 'Review/close incidents', 'group' => 'incidents', 'module' => 'Compliance'],
            ['key' => 'incidents.reopen', 'description' => 'Reopen closed incidents', 'group' => 'incidents', 'module' => 'Compliance'],
            ['key' => 'incidents.export', 'description' => 'Export incidents', 'group' => 'incidents', 'module' => 'Compliance'],
            ['key' => 'incidents.templates.manage', 'description' => 'Manage incident templates', 'group' => 'incidents', 'module' => 'Compliance'],
            ['key' => 'incidents.portal.manage', 'description' => 'Control portal visibility for incidents', 'group' => 'incidents', 'module' => 'Compliance'],
            ['key' => 'incidents.view.portal', 'description' => 'View incidents in portal', 'group' => 'incidents', 'module' => 'Compliance'],
            ['key' => 'incidents.attachments.view.portal', 'description' => 'Download incident attachments in portal', 'group' => 'incidents', 'module' => 'Compliance'],
            ['key' => 'incidents.followups.manage', 'description' => 'Create/assign incident follow-ups', 'group' => 'incidents', 'module' => 'Compliance'],
            ['key' => 'incidents.followups.complete', 'description' => 'Complete assigned incident follow-ups', 'group' => 'incidents', 'module' => 'Compliance'],

            // Respite
            ['key' => 'respite.viewAny', 'description' => 'View all respite records', 'group' => 'respite', 'module' => 'Operations'],
            ['key' => 'respite.create', 'description' => 'Create respite referrals and requests', 'group' => 'respite', 'module' => 'Operations'],
            ['key' => 'respite.update', 'description' => 'Update respite records', 'group' => 'respite', 'module' => 'Operations'],
            ['key' => 'respite.bookings.manage', 'description' => 'Manage respite bookings', 'group' => 'respite', 'module' => 'Operations'],
            ['key' => 'respite.stays.manage', 'description' => 'Manage respite stays', 'group' => 'respite', 'module' => 'Operations'],
            ['key' => 'respite.resources.manage', 'description' => 'Manage respite resource allocations', 'group' => 'respite', 'module' => 'Operations'],
            ['key' => 'respite.procedures.manage', 'description' => 'Manage respite procedures and tasks', 'group' => 'respite', 'module' => 'Operations'],
            ['key' => 'respite.calendar.view', 'description' => 'View respite calendar', 'group' => 'respite', 'module' => 'Operations'],
            ['key' => 'respite.evidence.view', 'description' => 'View respite evidence packs', 'group' => 'respite', 'module' => 'Operations'],

            // Risks
            ['key' => 'risks.viewAny', 'description' => 'View all client risks', 'group' => 'risks', 'module' => 'Compliance'],
            ['key' => 'risks.viewAssigned', 'description' => 'View client risks for assigned clients', 'group' => 'risks', 'module' => 'Compliance'],
            ['key' => 'risks.create', 'description' => 'Create client risks', 'group' => 'risks', 'module' => 'Compliance'],
            ['key' => 'risks.update', 'description' => 'Update client risks', 'group' => 'risks', 'module' => 'Compliance'],
            ['key' => 'risks.delete', 'description' => 'Delete client risks', 'group' => 'risks', 'module' => 'Compliance'],

            // Audit logs
            ['key' => 'audit.viewAny', 'description' => 'View audit logs', 'group' => 'audit', 'module' => 'System'],

            // Compliance
            ['key' => 'compliance.view', 'description' => 'View compliance dashboard', 'group' => 'compliance', 'module' => 'Compliance'],

            // Timeline / notes
            ['key' => 'timeline.viewAny', 'description' => 'View timelines', 'group' => 'general', 'module' => 'System'],
            ['key' => 'timeline.create', 'description' => 'Create timeline events', 'group' => 'general', 'module' => 'System'],
            ['key' => 'timeline.pin', 'description' => 'Pin/unpin handover notes', 'group' => 'general', 'module' => 'System'],

            // AI summaries
            ['key' => 'summaries.viewAny', 'description' => 'View AI summaries', 'group' => 'general', 'module' => 'System'],
            ['key' => 'summaries.generate', 'description' => 'Generate AI summaries', 'group' => 'general', 'module' => 'System'],

            // Integrations
            ['key' => 'integrations.view', 'description' => 'View integrations hub', 'group' => 'integrations', 'module' => 'System'],
            ['key' => 'integrations.manage_tenant_secrets', 'description' => 'Manage tenant integration API keys', 'group' => 'integrations', 'module' => 'System'],
            ['key' => 'integrations.manage_site_secrets', 'description' => 'Manage site integration credentials', 'group' => 'integrations', 'module' => 'System'],
            ['key' => 'integrations.view_face_tags', 'description' => 'View face recognition tags', 'group' => 'integrations', 'module' => 'System'],
            ['key' => 'integrations.view_person_links', 'description' => 'View tracker-person links', 'group' => 'integrations', 'module' => 'System'],
            ['key' => 'unifi.manage', 'description' => 'Manage UniFi integration settings', 'group' => 'integrations', 'module' => 'System'],

            // Site Hardware
            ['key' => 'siteHardware.view', 'description' => 'View site hardware & configuration', 'group' => 'sites', 'module' => 'Operations'],
            ['key' => 'siteHardware.manage', 'description' => 'Manage site hardware', 'group' => 'sites', 'module' => 'Operations'],

            // LLM
            ['key' => 'llm.generate_client_narrative', 'description' => 'Generate LLM client narratives', 'group' => 'general', 'module' => 'System'],
            ['key' => 'llm.generate_staff_summary', 'description' => 'Generate LLM staff summaries', 'group' => 'general', 'module' => 'System'],

            // HR Module
            ['key' => 'hr.recruitment.view', 'description' => 'View recruitment pipeline', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.recruitment.manage', 'description' => 'Manage recruitment', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.employees.viewAny', 'description' => 'View all employee profiles', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.employees.viewOwn', 'description' => 'View own employee profile', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.employees.manage', 'description' => 'Manage employee profiles', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.employees.viewFinancial', 'description' => 'View employee financial details', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.employees.viewRestricted', 'description' => 'View restricted HR notes', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.compliance.view', 'description' => 'View HR compliance dashboard', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.compliance.manage', 'description' => 'Manage compliance matrix', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.training.view', 'description' => 'View HR training dashboard', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.training.manage', 'description' => 'Manage HR training assignments', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.vetting.view', 'description' => 'View vetting register', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.vetting.manage', 'description' => 'Manage vetting records', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.vetting.view_disclosures', 'description' => 'View vetting disclosures', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.leave.viewAny', 'description' => 'View all leave requests', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.leave.viewOwn', 'description' => 'View own leave requests', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.leave.approve', 'description' => 'Approve/decline leave requests', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.leave.manage', 'description' => 'Manage leave requests and balances', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.performance.view', 'description' => 'View performance reviews', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.performance.manage', 'description' => 'Manage performance reviews', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.cases.view', 'description' => 'View HR cases', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.cases.manage', 'description' => 'Manage HR cases', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.disciplinary.view', 'description' => 'View disciplinary actions', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.disciplinary.manage', 'description' => 'Manage disciplinary actions', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.policies.view', 'description' => 'View HR policy library', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.policies.manage', 'description' => 'Manage HR policies', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.policies.attest', 'description' => 'Attest to HR policies', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.documents.view', 'description' => 'View HR documents', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.documents.manage', 'description' => 'Manage HR documents and templates', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.payroll.view', 'description' => 'View payroll runs', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.payroll.export', 'description' => 'Export payroll data', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.reports.view', 'description' => 'View HR reports', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.reports.export', 'description' => 'Export HR reports', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.driver.view', 'description' => 'View driver eligibility register', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.driver.manage', 'description' => 'Manage driver eligibility', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.time.viewAny', 'description' => 'Legacy alias for timesheets.viewAny', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.time.manage', 'description' => 'Legacy alias for timesheets.manageAny', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.time.approveTeam', 'description' => 'Legacy alias for timesheets.approve', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.wellbeing.view', 'description' => 'View wellbeing dashboard', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.onboarding.view', 'description' => 'View onboarding checklists', 'group' => 'hr', 'module' => 'HR'],
            ['key' => 'hr.onboarding.manage', 'description' => 'Manage onboarding checklists', 'group' => 'hr', 'module' => 'HR'],

            // Settings
            ['key' => 'settings.terminology.manage', 'description' => 'Manage UI terminology', 'group' => 'settings', 'module' => 'System'],
            ['key' => 'settings.branding.manage', 'description' => 'Manage organisation branding', 'group' => 'settings', 'module' => 'System'],
            ['key' => 'settings.service_contexts.manage', 'description' => 'Manage service contexts', 'group' => 'settings', 'module' => 'System'],
            ['key' => 'settings.sites.manage', 'description' => 'Manage site settings', 'group' => 'settings', 'module' => 'System'],
            ['key' => 'settings.templates.manage', 'description' => 'Manage templates', 'group' => 'settings', 'module' => 'System'],
            ['key' => 'settings.rbac.manage', 'description' => 'Manage RBAC settings', 'group' => 'settings', 'module' => 'System'],

            // RAG / AI Query
            ['key' => 'rag.ask.any', 'description' => 'Ask AI about any client', 'group' => 'general', 'module' => 'System'],
            ['key' => 'rag.ask.assigned', 'description' => 'Ask AI about assigned clients', 'group' => 'general', 'module' => 'System'],
            ['key' => 'rag.ask.self', 'description' => 'Ask AI about own / linked client', 'group' => 'general', 'module' => 'System'],

            // Safeguarding
            ['key' => 'safeguarding.viewAny', 'description' => 'View all safeguarding concerns', 'group' => 'safeguarding', 'module' => 'Compliance'],
            ['key' => 'safeguarding.create', 'description' => 'Create safeguarding concerns', 'group' => 'safeguarding', 'module' => 'Compliance'],
            ['key' => 'safeguarding.update', 'description' => 'Update safeguarding concerns', 'group' => 'safeguarding', 'module' => 'Compliance'],
            ['key' => 'safeguarding.investigate', 'description' => 'Conduct safeguarding investigations', 'group' => 'safeguarding', 'module' => 'Compliance'],
            ['key' => 'safeguarding.report.external', 'description' => 'Report to external authorities', 'group' => 'safeguarding', 'module' => 'Compliance'],
            ['key' => 'safeguarding.viewSensitive', 'description' => 'View sensitive allegations', 'group' => 'safeguarding', 'module' => 'Compliance'],

            // Consent Management
            ['key' => 'consents.viewAny', 'description' => 'View consent records', 'group' => 'consents', 'module' => 'Compliance'],
            ['key' => 'consents.manage', 'description' => 'Manage consent types', 'group' => 'consents', 'module' => 'Compliance'],
            ['key' => 'consents.record', 'description' => 'Record client consent', 'group' => 'consents', 'module' => 'Compliance'],
            ['key' => 'consents.withdraw', 'description' => 'Process consent withdrawal', 'group' => 'consents', 'module' => 'Compliance'],
            ['key' => 'consents.export', 'description' => 'Export consent reports', 'group' => 'consents', 'module' => 'Compliance'],
            ['key' => 'consents.request', 'description' => 'Request consent via family portal', 'group' => 'consents', 'module' => 'Compliance'],

            // Data Privacy & GDPR
            ['key' => 'privacy.viewRequests', 'description' => 'View data subject requests', 'group' => 'privacy', 'module' => 'Compliance'],
            ['key' => 'privacy.processRequests', 'description' => 'Process GDPR requests', 'group' => 'privacy', 'module' => 'Compliance'],
            ['key' => 'privacy.manageRetention', 'description' => 'Manage data retention policies', 'group' => 'privacy', 'module' => 'Compliance'],
            ['key' => 'privacy.manageLegalHolds', 'description' => 'Manage legal holds', 'group' => 'privacy', 'module' => 'Compliance'],
            ['key' => 'privacy.reportBreaches', 'description' => 'Report data breaches', 'group' => 'privacy', 'module' => 'Compliance'],
            ['key' => 'privacy.conductDPIA', 'description' => 'Conduct DPIAs', 'group' => 'privacy', 'module' => 'Compliance'],

            // Hazards
            ['key' => 'hazards.view', 'description' => 'View hazards', 'group' => 'hazards', 'module' => 'Compliance'],
            ['key' => 'hazards.create', 'description' => 'Log new hazards', 'group' => 'hazards', 'module' => 'Compliance'],
            ['key' => 'hazards.assign', 'description' => 'Assign hazards', 'group' => 'hazards', 'module' => 'Compliance'],
            ['key' => 'hazards.close', 'description' => 'Close/resolve hazards', 'group' => 'hazards', 'module' => 'Compliance'],
            ['key' => 'hazards.manage', 'description' => 'Edit and update hazards', 'group' => 'hazards', 'module' => 'Compliance'],
            ['key' => 'hazards.manage_types', 'description' => 'Manage hazard type catalog', 'group' => 'hazards', 'module' => 'Compliance'],

            // Checklists
            ['key' => 'checklists.view', 'description' => 'View checklists', 'group' => 'checklists', 'module' => 'Compliance'],
            ['key' => 'checklists.run', 'description' => 'Run/complete checklist', 'group' => 'checklists', 'module' => 'Compliance'],
            ['key' => 'checklists.schedule', 'description' => 'Schedule checklist runs', 'group' => 'checklists', 'module' => 'Compliance'],
            ['key' => 'checklists.manage_templates', 'description' => 'Manage checklist templates', 'group' => 'checklists', 'module' => 'Compliance'],

            // Vendors
            ['key' => 'vendors.view', 'description' => 'View vendors', 'group' => 'vendors', 'module' => 'Operations'],
            ['key' => 'vendors.manage', 'description' => 'Manage vendors', 'group' => 'vendors', 'module' => 'Operations'],

            // Credentials
            ['key' => 'credentials.view', 'description' => 'View credential list', 'group' => 'credentials', 'module' => 'Operations'],
            ['key' => 'credentials.reveal', 'description' => 'Reveal credential values', 'group' => 'credentials', 'module' => 'Operations'],
            ['key' => 'credentials.manage', 'description' => 'Manage credentials', 'group' => 'credentials', 'module' => 'Operations'],

            // Site Damages
            ['key' => 'sites.damages.view', 'description' => 'View site damage reports', 'group' => 'sites', 'module' => 'Operations'],
            ['key' => 'sites.damages.create', 'description' => 'Create site damage reports', 'group' => 'sites', 'module' => 'Operations'],
            ['key' => 'sites.damages.manage', 'description' => 'Manage site damage reports (update, delete)', 'group' => 'sites', 'module' => 'Operations'],

            // House Ledger
            ['key' => 'sites.ledger.view', 'description' => 'View house ledger', 'group' => 'sites', 'module' => 'Operations'],
            ['key' => 'sites.ledger.create', 'description' => 'Create house ledger entries', 'group' => 'sites', 'module' => 'Operations'],
            ['key' => 'sites.ledger.manage', 'description' => 'Manage house ledger (reconcile, adjust)', 'group' => 'sites', 'module' => 'Operations'],

            // Reports - Sites
            ['key' => 'reports.sites.view', 'description' => 'View site reports', 'group' => 'reports', 'module' => 'System'],
            ['key' => 'reports.sites.export', 'description' => 'Export site reports', 'group' => 'reports', 'module' => 'System'],

            // Finance
            ['key' => 'finance.dashboard', 'description' => 'View finance dashboard', 'group' => 'finance', 'module' => 'Finance'],
            ['key' => 'finance.ledger.view', 'description' => 'View general ledger', 'group' => 'finance', 'module' => 'Finance'],
            ['key' => 'finance.ledger.manage', 'description' => 'Manage general ledger', 'group' => 'finance', 'module' => 'Finance'],
            ['key' => 'finance.ap.view', 'description' => 'View accounts payable', 'group' => 'finance', 'module' => 'Finance'],
            ['key' => 'finance.ap.manage', 'description' => 'Manage accounts payable', 'group' => 'finance', 'module' => 'Finance'],
            ['key' => 'finance.ar.view', 'description' => 'View accounts receivable', 'group' => 'finance', 'module' => 'Finance'],
            ['key' => 'finance.ar.manage', 'description' => 'Manage accounts receivable', 'group' => 'finance', 'module' => 'Finance'],
            ['key' => 'finance.bank.view', 'description' => 'View banking', 'group' => 'finance', 'module' => 'Finance'],
            ['key' => 'finance.bank.manage', 'description' => 'Manage banking', 'group' => 'finance', 'module' => 'Finance'],
            ['key' => 'finance.tax.view', 'description' => 'View tax & GST', 'group' => 'finance', 'module' => 'Finance'],
            ['key' => 'finance.tax.manage', 'description' => 'Manage tax & GST', 'group' => 'finance', 'module' => 'Finance'],
            ['key' => 'finance.assets.view', 'description' => 'View fixed assets', 'group' => 'finance', 'module' => 'Finance'],
            ['key' => 'finance.assets.manage', 'description' => 'Manage fixed assets', 'group' => 'finance', 'module' => 'Finance'],
            ['key' => 'finance.petty_cash.view', 'description' => 'View petty cash', 'group' => 'finance', 'module' => 'Finance'],
            ['key' => 'finance.petty_cash.manage', 'description' => 'Manage petty cash', 'group' => 'finance', 'module' => 'Finance'],
            ['key' => 'finance.reports.view', 'description' => 'View financial reports', 'group' => 'finance', 'module' => 'Finance'],
            ['key' => 'finance.admin', 'description' => 'Finance administration', 'group' => 'finance', 'module' => 'Finance'],
        ];

        foreach ($permissionDefinitions as $permDef) {
            Permission::firstOrCreate(
                ['key' => $permDef['key']],
                [
                    'description' => $permDef['description'],
                    'group' => $permDef['group'],
                    'module' => $permDef['module'],
                ]
            );
        }

        Permission::query()
            ->whereIn('key', [
                'staff.training.viewAny',
                'staff.training.manage',
                'staff.competency.assess',
            ])
            ->delete();

        /*
        |--------------------------------------------------------------------------
        | 3. Attach permissions to roles
        |--------------------------------------------------------------------------
        */

        // Admin gets EVERYTHING
        $admin?->permissions()->sync(Permission::pluck('id'));

        // Helper function to sync permissions by key
        $syncPermissions = function ($role, $keys) {
            if (! $role) {
                return;
            }
            $ids = Permission::whereIn('key', $keys)->pluck('id');
            $role->permissions()->sync($ids);
        };

        // Provider Manager
        $syncPermissions($providerManager, [
            'sites.viewAny', 'sites.create', 'sites.update',
            'sites.type.head_office.view', 'sites.type.house.view', 'sites.type.facility.view',
            'staff.viewAny', 'staff.create', 'staff.update', 'staff.invite', 'staff.assignments.update',
            'staff.credentials.viewAny', 'staff.credentials.updateAny', 'staff.availability.updateAny',
            'workers.viewAny', 'reports.viewAny', 'rostering.viewAny',
            'fleet.viewAny', 'fleet.manage', 'fleet.driverSessions.manage', 'fleet.signals.view', 'fleet.trips.manage',
            'fleet.fuel.manage', 'fleet.reports.view', 'fleet.bookings.approve', 'fleet.incidents.manage',
            'fleet.maintenance.manage', 'fleet.medication.manage', 'fleet.mileage.approve', 'fleet.outings.manage',
            'controlRoom.viewAny', 'controlRoom.alerts.manage', 'controlRoom.alerts.assign',
            'controlRoom.alerts.escalate', 'controlRoom.alerts.create', 'controlRoom.reports.view',
            'calendar.viewAny', 'compliance.view', 'timeline.viewAny', 'timeline.create', 'timeline.pin',
            'summaries.viewAny', 'summaries.generate', 'unifi.manage',
            'shifts.viewAny', 'shifts.create', 'shifts.update', 'shifts.manageAny', 'shifts.overrideEligibility',
            'timesheets.viewAny', 'timesheets.create', 'timesheets.update', 'timesheets.approve', 'timesheets.manageAny',
            'clients.viewAny', 'clients.create', 'clients.update', 'clients.assignments.update', 'clients.onboarding.manage',
            'medications.view', 'medications.orders.manage', 'medications.administer.record',
            'medications.administer.correct', 'medications.stock.update', 'medications.controlled.view',
            'medications.controlled.record', 'medications.controlled.witness', 'medications.controlled.override',
            'medications.audit.view', 'medications.reports.export', 'medications.breakglass',
            'incidents.viewAny', 'incidents.create', 'incidents.update', 'incidents.submit',
            'incidents.approve', 'incidents.reopen', 'incidents.followups.manage', 'incidents.export',
            'incidents.portal.manage', 'incidents.view.portal', 'incidents.attachments.view.portal',
            'respite.viewAny', 'respite.create', 'respite.update', 'respite.bookings.manage',
            'respite.stays.manage', 'respite.resources.manage', 'respite.procedures.manage',
            'respite.calendar.view', 'respite.evidence.view',
            'risks.viewAny', 'risks.create', 'risks.update', 'risks.delete',
            'settings.terminology.manage', 'settings.service_contexts.manage',
            'audit.viewAny', 'rag.ask.any',
            'assets.viewAny', 'assets.create', 'assets.update', 'assets.delete',
            'assets.inspections.record', 'assets.maintenance.record', 'assets.documents.manage',
            'assets.qr.download', 'assets.ownership.manage', 'assets.assignments.manage',
            'assets.trackers.manage', 'assets.telemetry.ingest', 'assets.telemetry.view',
            'assets.alerts.view', 'assets.alerts.manage', 'assets.scan.record', 'assets.geofences.manage',
            'safeguarding.viewAny', 'safeguarding.create', 'safeguarding.update',
            'safeguarding.investigate', 'safeguarding.report.external', 'safeguarding.viewSensitive',
            'consents.viewAny', 'consents.manage', 'consents.record', 'consents.withdraw', 'consents.export', 'consents.request',
            'staff.vetting.view', 'staff.vetting.manage', 'staff.induction.manage',
            'privacy.viewRequests', 'privacy.processRequests', 'privacy.manageRetention',
            'privacy.manageLegalHolds', 'privacy.reportBreaches', 'privacy.conductDPIA',
            'integrations.view', 'integrations.manage_tenant_secrets', 'integrations.manage_site_secrets',
            'siteHardware.view', 'siteHardware.manage', 'controlRoom.alerts.view',
            'llm.generate_client_narrative', 'llm.generate_staff_summary',
            'hr.recruitment.view', 'hr.recruitment.manage', 'hr.employees.viewAny', 'hr.employees.manage',
            'hr.employees.viewFinancial', 'hr.compliance.view', 'hr.compliance.manage',
            'hr.training.view', 'hr.training.manage', 'hr.vetting.view', 'hr.vetting.manage',
            'hr.leave.viewAny', 'hr.leave.approve', 'hr.leave.manage',
            'hr.performance.view', 'hr.performance.manage', 'hr.cases.view', 'hr.cases.manage',
            'hr.policies.view', 'hr.policies.manage', 'hr.policies.attest',
            'hr.documents.view', 'hr.documents.manage', 'hr.payroll.view', 'hr.payroll.export',
            'hr.reports.view', 'hr.reports.export', 'hr.driver.view', 'hr.driver.manage',
            'hr.wellbeing.view', 'hr.onboarding.view', 'hr.onboarding.manage',
            'sites.damages.view', 'sites.damages.create', 'sites.damages.manage',
            'sites.ledger.view', 'sites.ledger.create', 'sites.ledger.manage',
        ]);

        // Coordinator
        $syncPermissions($coordinator, [
            'sites.viewAny', 'sites.type.head_office.view', 'sites.type.house.view', 'sites.type.facility.view',
            'staff.viewAny', 'staff.credentials.viewAny', 'staff.credentials.updateAny',
            'staff.availability.updateAny', 'clients.viewAny', 'clients.assignments.update',
            'clients.onboarding.manage', 'medications.view', 'medications.orders.manage',
            'medications.administer.record', 'medications.administer.correct', 'medications.stock.update',
            'medications.controlled.view', 'medications.controlled.record', 'medications.controlled.witness',
            'medications.audit.view', 'medications.reports.export',
            'incidents.viewAny', 'incidents.create', 'incidents.update', 'incidents.approve',
            'incidents.reopen', 'incidents.followups.manage',
            'respite.viewAny', 'respite.create', 'respite.update', 'respite.bookings.manage',
            'respite.stays.manage', 'respite.resources.manage', 'respite.procedures.manage',
            'respite.calendar.view', 'respite.evidence.view',
            'risks.viewAny', 'risks.create', 'risks.update', 'risks.delete',
            'shifts.viewAny', 'shifts.create', 'shifts.update', 'shifts.overrideEligibility',
            'timesheets.viewAny', 'timesheets.approve',
            'timeline.viewAny', 'timeline.create', 'timeline.pin',
            'summaries.viewAny', 'summaries.generate', 'calendar.viewAny', 'rag.ask.any',
            'fleet.viewAny', 'fleet.manage', 'fleet.driverSessions.manage', 'fleet.signals.view',
            'fleet.trips.manage', 'fleet.fuel.manage', 'fleet.reports.view',
            'fleet.bookings.approve', 'fleet.incidents.manage', 'fleet.maintenance.manage',
            'fleet.medication.manage', 'fleet.mileage.approve', 'fleet.outings.manage',
            'controlRoom.viewAny', 'controlRoom.alerts.manage', 'controlRoom.alerts.assign',
            'controlRoom.alerts.escalate', 'controlRoom.reports.view',
            'assets.viewAny', 'assets.create', 'assets.update',
            'assets.inspections.record', 'assets.maintenance.record', 'assets.documents.manage',
            'assets.qr.download', 'assets.ownership.manage', 'assets.assignments.manage',
            'assets.trackers.manage', 'assets.telemetry.ingest', 'assets.telemetry.view',
            'assets.alerts.view', 'assets.alerts.manage', 'assets.scan.record', 'assets.geofences.manage',
            'safeguarding.viewAny', 'safeguarding.create', 'safeguarding.update', 'safeguarding.investigate',
            'consents.viewAny', 'consents.record', 'consents.withdraw', 'consents.request',
            'siteHardware.view', 'controlRoom.alerts.view',
            'hr.employees.viewAny', 'hr.compliance.view', 'hr.training.view',
            'hr.vetting.view', 'hr.leave.viewAny', 'hr.leave.approve',
            'hr.performance.view', 'hr.policies.view', 'hr.policies.attest', 'hr.onboarding.view',
            'sites.damages.view', 'sites.damages.create',
            'sites.ledger.view', 'sites.ledger.create',
            'clinical.observations.view', 'clinical.observations.record',
            'clinical.observations.viewAny', 'clinical.observations.recordClinical', 'clinical.observations.correct',
            'clinical.events.view', 'clinical.events.record',
            'clinical.events.viewAny', 'clinical.events.review',
            'clinical.protocols.viewAny', 'clinical.protocols.manage',
            'clinical.dashboard',
        ]);

        // Support Worker
        $syncPermissions($supportWorker, [
            'clients.viewAssigned', 'medications.view', 'timeline.create',
            'shifts.viewAssigned', 'shifts.tasks.updateSelf',
            'timesheets.viewAssigned', 'timesheets.create', 'timesheets.update', 'timesheets.submit',
            'incidents.viewAssigned', 'incidents.create', 'incidents.update', 'incidents.submit',
            'incidents.followups.complete', 'risks.viewAssigned',
            'respite.viewAny',
            'medications.administer.record', 'medications.administer.correct',
            'medications.controlled.view', 'medications.controlled.record', 'medications.controlled.witness',
            'staff.credentials.updateSelf', 'staff.availability.updateSelf',
            'rag.ask.assigned',
            'fleet.viewAny', 'fleet.driverSessions.manage', 'fleet.signals.view',
            'controlRoom.viewAny',
            'assets.viewAssigned', 'assets.create', 'assets.update',
            'assets.inspections.record', 'assets.maintenance.record', 'assets.documents.manage',
            'assets.qr.download', 'assets.assignments.manage', 'assets.telemetry.view',
            'assets.alerts.view', 'assets.scan.record',
            'safeguarding.create',
            'hr.employees.viewOwn', 'hr.leave.viewOwn', 'hr.policies.view', 'hr.policies.attest',
            'sites.damages.view', 'sites.damages.create',
            'sites.ledger.view',
            'clinical.observations.view', 'clinical.observations.record',
            'clinical.observations.viewAssigned',
            'clinical.events.view', 'clinical.events.record',
            'clinical.events.viewAssigned',
        ]);

        // Finance
        $syncPermissions($finance, [
            'timesheets.viewAny', 'timesheets.approve', 'reports.viewAny', 'audit.viewAny',
            'medications.view', 'medications.reports.export', 'medications.stock.update',
            'incidents.viewAny', 'incidents.export',
            // Finance module permissions
            'finance.dashboard', 'finance.ledger.view', 'finance.ledger.manage',
            'finance.ap.view', 'finance.ap.manage', 'finance.ar.view', 'finance.ar.manage',
            'finance.bank.view', 'finance.bank.manage', 'finance.tax.view', 'finance.tax.manage',
            'finance.assets.view', 'finance.assets.manage', 'finance.petty_cash.view', 'finance.petty_cash.manage',
            'finance.admin', 'finance.reports.view',
        ]);

        // HR
        $syncPermissions($hr, [
            'staff.viewAny', 'staff.update', 'staff.credentials.viewAny', 'staff.credentials.updateAny',
            'staff.availability.updateAny', 'reports.viewAny', 'audit.viewAny', 'compliance.view',
            'staff.vetting.view', 'staff.vetting.manage', 'staff.induction.manage',
            'safeguarding.viewAny', 'safeguarding.create',
            'hr.recruitment.view', 'hr.recruitment.manage', 'hr.employees.viewAny', 'hr.employees.manage',
            'hr.employees.viewFinancial', 'hr.employees.viewRestricted', 'hr.compliance.view', 'hr.compliance.manage',
            'hr.training.view', 'hr.training.manage', 'hr.vetting.view', 'hr.vetting.manage',
            'hr.vetting.view_disclosures', 'hr.leave.viewAny', 'hr.leave.approve', 'hr.leave.manage',
            'hr.performance.view', 'hr.performance.manage', 'hr.cases.view', 'hr.cases.manage',
            'hr.disciplinary.view', 'hr.disciplinary.manage', 'hr.policies.view', 'hr.policies.manage',
            'hr.policies.attest', 'hr.documents.view', 'hr.documents.manage', 'hr.payroll.view',
            'hr.payroll.export', 'hr.reports.view', 'hr.reports.export', 'hr.driver.view', 'hr.driver.manage',
            'timesheets.viewAny', 'timesheets.manageAny',
            'hr.wellbeing.view', 'hr.onboarding.view', 'hr.onboarding.manage',
        ]);

        // Auditor
        $syncPermissions($auditor, [
            'clients.viewAny', 'medications.view', 'medications.audit.view',
            'shifts.viewAny', 'timesheets.viewAny', 'reports.viewAny',
            'timeline.viewAny', 'summaries.viewAny', 'audit.viewAny', 'compliance.view',
            'incidents.viewAny', 'risks.viewAny', 'assets.viewAny',
            'respite.viewAny', 'respite.evidence.view',
            'assets.telemetry.view', 'assets.alerts.view',
            'safeguarding.viewAny', 'consents.viewAny',
            'staff.vetting.view',
            'privacy.viewRequests', 'hr.employees.viewAny', 'hr.compliance.view',
            'hr.training.view', 'hr.vetting.view', 'hr.performance.view',
            'hr.policies.view', 'hr.onboarding.view',
            // Finance view-only for auditors
            'finance.dashboard', 'finance.ledger.view', 'finance.ap.view', 'finance.ar.view',
            'finance.bank.view', 'finance.tax.view', 'finance.assets.view',
            'finance.petty_cash.view', 'finance.reports.view',
        ]);

        // Team Lead
        $syncPermissions($teamLead, [
            'sites.viewAny', 'sites.update',
            'sites.type.head_office.view', 'sites.type.house.view', 'sites.type.facility.view',
            'calendar.view', 'calendar.create', 'calendar.manage', 'calendar.approve',
            'hazards.view', 'hazards.create', 'hazards.manage', 'hazards.assign',
            'checklists.view', 'checklists.run', 'checklists.schedule',
            'vendors.view', 'credentials.view', 'reports.sites.view',
            'siteHardware.view', 'controlRoom.alerts.view',
            'hr.employees.viewAny', 'hr.compliance.view', 'hr.training.view',
            'hr.leave.viewAny', 'hr.leave.approve', 'hr.performance.view', 'hr.performance.manage',
            'hr.policies.view', 'hr.policies.attest', 'hr.onboarding.view',
            'timesheets.viewAny', 'timesheets.approve',
            'sites.damages.view', 'sites.damages.create', 'sites.damages.manage',
            'sites.ledger.view', 'sites.ledger.create',
            'clinical.observations.view', 'clinical.observations.record',
            'clinical.observations.viewAny', 'clinical.observations.recordClinical',
            'clinical.events.view', 'clinical.events.record',
            'clinical.events.viewAny',
            'clinical.protocols.viewAny',
            'clinical.dashboard',
        ]);

        // Health & Safety Officer
        $syncPermissions($healthSafetyOfficer, [
            'sites.viewAny', 'sites.type.head_office.view', 'sites.type.house.view', 'sites.type.facility.view',
            'calendar.view', 'hazards.view', 'hazards.create', 'hazards.manage', 'hazards.assign', 'hazards.close',
            'checklists.view', 'checklists.run', 'checklists.manage_templates',
            'vendors.view', 'credentials.view', 'reports.sites.view',
        ]);

        // Maintenance Coordinator
        $syncPermissions($maintenanceCoordinator, [
            'sites.viewAny', 'sites.type.head_office.view', 'sites.type.house.view', 'sites.type.facility.view',
            'calendar.view', 'calendar.create', 'calendar.manage', 'hazards.view',
            'checklists.view', 'checklists.run', 'checklists.schedule',
            'assets.view_register', 'assets.manage_register',
            'vendors.view', 'vendors.manage', 'credentials.view', 'credentials.reveal',
            'reports.sites.view',
            'sites.damages.view', 'sites.damages.create', 'sites.damages.manage',
            'sites.ledger.view', 'sites.ledger.create', 'sites.ledger.manage',
        ]);

        // Board Trustee
        $syncPermissions($boardTrustee, [
            'reports.viewAny', 'audit.viewAny', 'reports.sites.view', 'reports.sites.export',
        ]);

        // Client / Next-of-kin portal users
        $syncPermissions($clientRole, [
            'rag.ask.self', 'incidents.view.portal', 'incidents.attachments.view.portal',
        ]);

        $syncPermissions($nextOfKinRole, [
            'rag.ask.self', 'incidents.view.portal', 'incidents.attachments.view.portal',
        ]);

        // Clinical Lead: full clinical module access + medications view
        $syncPermissions($clinicalLead, [
            'clinical.observations.view', 'clinical.observations.record',
            'clinical.observations.viewAny', 'clinical.observations.recordClinical', 'clinical.observations.correct',
            'clinical.events.view', 'clinical.events.record',
            'clinical.events.viewAny', 'clinical.events.review',
            'clinical.protocols.viewAny', 'clinical.protocols.manage',
            'clinical.dashboard',
            'medications.view', 'medications.orders.manage',
            'medications.administer.record', 'medications.audit.view',
            'clients.viewAny',
        ]);

        /*
        |--------------------------------------------------------------------------
        | 4. Migrate existing users.role → RBAC roles
        |--------------------------------------------------------------------------
        */
        User::query()
            ->select('id', 'role')
            ->chunk(200, function ($users) use (
                $admin, $providerManager, $coordinator, $supportWorker,
                $finance, $hr, $auditor, $roadmapManager, $itManager,
                $facilitiesManager, $ceo, $cfo, $coo, $complianceLead, $riskLead
            ) {
                foreach ($users as $user) {
                    $roleName = $user->role ?? 'support_worker';

                    $role = match ($roleName) {
                        'admin' => $admin,
                        'provider_manager' => $providerManager,
                        'coordinator' => $coordinator,
                        'finance' => $finance,
                        'hr' => $hr,
                        'auditor' => $auditor,
                        'roadmap_manager' => $roadmapManager,
                        'it_manager' => $itManager,
                        'facilities_manager' => $facilitiesManager,
                        'ceo' => $ceo,
                        'cfo' => $cfo,
                        'coo' => $coo,
                        'compliance_lead' => $complianceLead,
                        'risk_lead' => $riskLead,
                        default => $supportWorker,
                    };

                    if ($role) {
                        $user->roles()->syncWithoutDetaching([$role->id]);
                    }
                }
            });
    }
}
