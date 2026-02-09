<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Permission;
use App\Models\User;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | 1. Roles
        |--------------------------------------------------------------------------
        */
        $admin = Role::firstOrCreate(
            ['name' => 'admin'],
            ['label' => 'Administrator']
        );

        $providerManager = Role::firstOrCreate(
            ['name' => 'provider_manager'],
            ['label' => 'Provider Manager']
        );

        $coordinator = Role::firstOrCreate(
            ['name' => 'coordinator'],
            ['label' => 'Coordinator']
        );

        $supportWorker = Role::firstOrCreate(
            ['name' => 'support_worker'],
            ['label' => 'Support Worker']
        );

        $finance = Role::firstOrCreate(
            ['name' => 'finance'],
            ['label' => 'Finance']
        );

        $hr = Role::firstOrCreate(
            ['name' => 'hr'],
            ['label' => 'HR']
        );

        $auditor = Role::firstOrCreate(
            ['name' => 'auditor'],
            ['label' => 'Auditor (Read only)']
        );

        $teamLead = Role::firstOrCreate(
            ['name' => 'team_lead'],
            ['label' => 'Team Lead']
        );

        $healthSafetyOfficer = Role::firstOrCreate(
            ['name' => 'health_safety_officer'],
            ['label' => 'Health & Safety Officer']
        );

        $maintenanceCoordinator = Role::firstOrCreate(
            ['name' => 'maintenance_coordinator'],
            ['label' => 'Maintenance Coordinator']
        );

        $clientRole = Role::firstOrCreate(
            ['name' => 'client'],
            ['label' => 'Client (Portal)']
        );

        $nextOfKinRole = Role::firstOrCreate(
            ['name' => 'next_of_kin'],
            ['label' => 'Next of Kin / Guardian (Portal)']
        );

        // Board/Governance roles
        $boardChair = Role::firstOrCreate(
            ['name' => 'board_chair'],
            ['label' => 'Board Chair']
        );

        $boardSecretary = Role::firstOrCreate(
            ['name' => 'board_secretary'],
            ['label' => 'Board Secretary']
        );

        $boardMemberRole = Role::firstOrCreate(
            ['name' => 'board_member'],
            ['label' => 'Board Member']
        );

        $boardObserver = Role::firstOrCreate(
            ['name' => 'board_observer'],
            ['label' => 'Board Observer']
        );

        $boardTrustee = Role::firstOrCreate(
            ['name' => 'board_trustee'],
            ['label' => 'Board Trustee (Read only)']
        );

        // Remove any roles we are not using right now (but only if they are not assigned).
        // This keeps the Access Control UI role list clean.
        $activeRoleNames = [
            'admin',
            'provider_manager',
            'coordinator',
            'support_worker',
            'finance',
            'hr',
            'auditor',
            'team_lead',
            'health_safety_officer',
            'maintenance_coordinator',
            'next_of_kin',
            'client',
            // Board/Governance roles
            'board_chair',
            'board_secretary',
            'board_member',
            'board_observer',
            'board_trustee',
        ];
        Role::query()
            ->whereNotIn('name', $activeRoleNames)
            ->doesntHave('users')
            ->delete();

        /*
        |--------------------------------------------------------------------------
        | 2. Permissions
        |--------------------------------------------------------------------------
        */
        $permissions = [
            // Sites
            ['key' => 'sites.viewAny', 'description' => 'View sites'],
            ['key' => 'sites.create', 'description' => 'Create sites'],
            ['key' => 'sites.update', 'description' => 'Update sites'],

            // Assets
            ['key' => 'assets.viewAny', 'description' => 'View all assets'],
            ['key' => 'assets.viewAssigned', 'description' => 'View assigned assets only'],
            ['key' => 'assets.create', 'description' => 'Create assets'],
            ['key' => 'assets.update', 'description' => 'Update assets'],
            ['key' => 'assets.delete', 'description' => 'Delete assets'],
            ['key' => 'assets.inspections.record', 'description' => 'Record asset inspections'],
            ['key' => 'assets.maintenance.record', 'description' => 'Record asset maintenance'],
            ['key' => 'assets.documents.manage', 'description' => 'Manage asset documents'],
            ['key' => 'assets.qr.download', 'description' => 'Download asset QR codes'],
            ['key' => 'assets.ownership.manage', 'description' => 'Manage asset ownership'],
            ['key' => 'assets.assignments.manage', 'description' => 'Manage asset assignments'],
            ['key' => 'assets.trackers.manage', 'description' => 'Manage asset trackers'],
            ['key' => 'assets.telemetry.ingest', 'description' => 'Ingest asset telemetry'],
            ['key' => 'assets.telemetry.view', 'description' => 'View asset telemetry'],
            ['key' => 'assets.alerts.view', 'description' => 'View asset alerts'],
            ['key' => 'assets.alerts.manage', 'description' => 'Manage asset alerts'],
            ['key' => 'assets.scan.record', 'description' => 'Record asset scans'],
            ['key' => 'assets.geofences.manage', 'description' => 'Manage asset geofences and policies'],

            // Staff / workers
            ['key' => 'staff.viewAny', 'description' => 'View staff'],
            ['key' => 'staff.create', 'description' => 'Create staff'],
            ['key' => 'staff.update', 'description' => 'Update staff'],
            ['key' => 'staff.invite', 'description' => 'Invite staff'],
            ['key' => 'staff.assignments.update', 'description' => 'Assign clients to staff'],
            ['key' => 'staff.credentials.viewAny', 'description' => 'View staff credentials'],
            ['key' => 'staff.credentials.updateAny', 'description' => 'Manage staff credentials'],
            ['key' => 'staff.credentials.updateSelf', 'description' => 'Manage own credentials'],
            ['key' => 'staff.availability.updateAny', 'description' => 'Manage staff availability'],
            ['key' => 'staff.availability.updateSelf', 'description' => 'Manage own availability'],

            // Workers / modules
            ['key' => 'workers.viewAny', 'description' => 'View workers'],
            ['key' => 'reports.viewAny', 'description' => 'View reports'],
            ['key' => 'rostering.viewAny', 'description' => 'View rostering'],
            ['key' => 'fleet.viewAny', 'description' => 'View fleet management'],
            ['key' => 'fleet.driverSessions.manage', 'description' => 'Start/end driver sessions'],
            ['key' => 'fleet.signals.view', 'description' => 'View fleet signals'],
            ['key' => 'fleet.trips.manage', 'description' => 'Manage fleet trips (edit/delete)'],
            ['key' => 'fleet.fuel.manage', 'description' => 'Manage fleet fuel records'],
            ['key' => 'fleet.reports.view', 'description' => 'View fleet reports'],

            // Control Room
            ['key' => 'controlRoom.viewAny', 'description' => 'View Control Room'],
            ['key' => 'controlRoom.alerts.manage', 'description' => 'Manage alerts (acknowledge, triage, resolve, close)'],
            ['key' => 'controlRoom.alerts.assign', 'description' => 'Assign alerts to staff'],
            ['key' => 'controlRoom.alerts.escalate', 'description' => 'Escalate alerts'],
            ['key' => 'controlRoom.alerts.create', 'description' => 'Create alerts manually or via API'],
            ['key' => 'controlRoom.reports.view', 'description' => 'View Control Room reports'],

            ['key' => 'calendar.viewAny', 'description' => 'View calendar'],
            // Shifts (appointments)
            ['key' => 'shifts.viewAny', 'description' => 'View shifts'],
            ['key' => 'shifts.viewAssigned', 'description' => 'View assigned shifts only'],
            ['key' => 'shifts.create', 'description' => 'Create shifts'],
            ['key' => 'shifts.update', 'description' => 'Update shifts'],
            ['key' => 'shifts.manageAny', 'description' => 'Manage any staff shifts'],
            ['key' => 'shifts.tasks.updateSelf', 'description' => 'Complete tasks on own shifts'],

            // Timesheets
            ['key' => 'timesheets.viewAny', 'description' => 'View timesheets'],
            ['key' => 'timesheets.viewAssigned', 'description' => 'View assigned timesheets only'],
            ['key' => 'timesheets.create', 'description' => 'Create timesheets'],
            ['key' => 'timesheets.update', 'description' => 'Update timesheets'],
            ['key' => 'timesheets.submit', 'description' => 'Submit timesheets for approval'],
            ['key' => 'timesheets.approve', 'description' => 'Approve/reject timesheets'],
            ['key' => 'timesheets.manageAny', 'description' => 'Manage any staff timesheets'],

            // Clients
            ['key' => 'clients.viewAny', 'description' => 'View clients'],
            ['key' => 'clients.viewAssigned', 'description' => 'View assigned clients only'],
            ['key' => 'clients.create', 'description' => 'Create clients'],
            ['key' => 'clients.update', 'description' => 'Update clients'],
            ['key' => 'clients.assignments.update', 'description' => 'Manage client assignments'],
            ['key' => 'clients.onboarding.manage', 'description' => 'Manage client onboarding checklist (mark sections as not applicable)'],

            // Medication / MAR
            ['key' => 'medications.view', 'description' => 'View medications module (central + per-client MAR)'],
            ['key' => 'medications.orders.manage', 'description' => 'Create/update medication orders'],
            ['key' => 'medications.administer.record', 'description' => 'Record medication administrations (MAR)'],
            ['key' => 'medications.administer.correct', 'description' => 'Correct medication administrations (audit safe)'],
            ['key' => 'medications.stock.update', 'description' => 'Update medication stock counts'],
            ['key' => 'medications.controlled.view', 'description' => 'View controlled drug register entries'],
            ['key' => 'medications.controlled.record', 'description' => 'Record controlled drug register entries (double-sign)'],
            ['key' => 'medications.controlled.witness', 'description' => 'Witness controlled drug administrations/stock counts'],
            ['key' => 'medications.controlled.override', 'description' => 'Override controlled drug discrepancy blocks'],
            ['key' => 'medications.audit.view', 'description' => 'View medication-focused audit log'],
            ['key' => 'medications.reports.export', 'description' => 'Export MAR/audit/medications reports'],
            ['key' => 'medications.breakglass', 'description' => 'Use break-glass emergency access for medications'],

            // Incidents
            ['key' => 'incidents.viewAny', 'description' => 'View all incidents'],
            ['key' => 'incidents.viewAssigned', 'description' => 'View incidents for assigned clients'],
            ['key' => 'incidents.create', 'description' => 'Create incident reports'],
            ['key' => 'incidents.update', 'description' => 'Update incident reports'],
            ['key' => 'incidents.submit', 'description' => 'Submit standalone incident reports'],
            ['key' => 'incidents.approve', 'description' => 'Review/close incidents'],
            ['key' => 'incidents.reopen', 'description' => 'Reopen closed incidents (requires reason)'],
            ['key' => 'incidents.export', 'description' => 'Export incidents'],
            ['key' => 'incidents.templates.manage', 'description' => 'Manage incident templates'],

            ['key' => 'incidents.portal.manage', 'description' => 'Control portal visibility for incidents and attachments'],
            ['key' => 'incidents.view.portal', 'description' => 'View incidents in the client/next-of-kin portal'],
            ['key' => 'incidents.attachments.view.portal', 'description' => 'Download incident attachments in the portal'],

            ['key' => 'incidents.followups.manage', 'description' => 'Create/assign incident follow-ups'],
            ['key' => 'incidents.followups.complete', 'description' => 'Complete assigned incident follow-ups'],

            // Respite
            ['key' => 'respite.viewAny', 'description' => 'View all respite records'],
            ['key' => 'respite.create', 'description' => 'Create respite referrals and requests'],
            ['key' => 'respite.update', 'description' => 'Update respite records'],
            ['key' => 'respite.bookings.manage', 'description' => 'Manage respite bookings'],
            ['key' => 'respite.stays.manage', 'description' => 'Manage respite stays (check-in/extend/discharge)'],
            ['key' => 'respite.resources.manage', 'description' => 'Manage respite resource allocations'],
            ['key' => 'respite.procedures.manage', 'description' => 'Manage respite procedures and tasks'],
            ['key' => 'respite.calendar.view', 'description' => 'View respite calendar'],
            ['key' => 'respite.evidence.view', 'description' => 'View respite evidence packs'],

            // Risks
            ['key' => 'risks.viewAny', 'description' => 'View all client risks'],
            ['key' => 'risks.viewAssigned', 'description' => 'View client risks for assigned clients'],
            ['key' => 'risks.create', 'description' => 'Create client risks'],
            ['key' => 'risks.update', 'description' => 'Update client risks'],
            ['key' => 'risks.delete', 'description' => 'Delete client risks'],

            // Audit logs
            ['key' => 'audit.viewAny', 'description' => 'View audit logs'],

            // Compliance dashboard
            ['key' => 'compliance.view', 'description' => 'View compliance dashboard'],

            // Timeline / notes
            ['key' => 'timeline.viewAny', 'description' => 'View timelines (staff/client activity)'],
            ['key' => 'timeline.create', 'description' => 'Create timeline events (notes/incidents)'],
            ['key' => 'timeline.pin', 'description' => 'Pin/unpin handover notes'],

            // AI summaries
            ['key' => 'summaries.viewAny', 'description' => 'View AI summaries'],
            ['key' => 'summaries.generate', 'description' => 'Generate AI summaries'],

            // Integrations
            ['key' => 'unifi.manage', 'description' => 'Manage UniFi integration settings'],

            // Settings
            ['key' => 'settings.access.manage', 'description' => 'Manage user access (roles & overrides)'],
            ['key' => 'settings.terminology.manage', 'description' => 'Manage UI terminology (labels)'],
            ['key' => 'settings.branding.manage', 'description' => 'Manage organisation branding (colors, logo)'],
            ['key' => 'settings.service_contexts.manage', 'description' => 'Manage service contexts (residential/home support/respite)'],

            // RAG / AI Query
            ['key' => 'rag.ask.any', 'description' => 'Ask AI about any client (within view permissions)'],
            ['key' => 'rag.ask.assigned', 'description' => 'Ask AI about assigned clients'],
            ['key' => 'rag.ask.self', 'description' => 'Ask AI about own / linked client (portal)'],

            // Safeguarding
            ['key' => 'safeguarding.viewAny', 'description' => 'View all safeguarding concerns'],
            ['key' => 'safeguarding.create', 'description' => 'Create safeguarding concerns'],
            ['key' => 'safeguarding.update', 'description' => 'Update safeguarding concerns'],
            ['key' => 'safeguarding.investigate', 'description' => 'Conduct safeguarding investigations'],
            ['key' => 'safeguarding.report.external', 'description' => 'Report to external authorities (police, CQC)'],
            ['key' => 'safeguarding.viewSensitive', 'description' => 'View sensitive allegations'],

            // Consent Management
            ['key' => 'consents.viewAny', 'description' => 'View consent records'],
            ['key' => 'consents.manage', 'description' => 'Manage consent types'],
            ['key' => 'consents.record', 'description' => 'Record client consent'],
            ['key' => 'consents.withdraw', 'description' => 'Process consent withdrawal'],
            ['key' => 'consents.export', 'description' => 'Export consent reports'],

            // Staff Vetting & Training
            ['key' => 'staff.vetting.view', 'description' => 'View background checks'],
            ['key' => 'staff.vetting.manage', 'description' => 'Manage background checks (DBS, references)'],
            ['key' => 'staff.training.viewAny', 'description' => 'View all training records'],
            ['key' => 'staff.training.manage', 'description' => 'Manage training (enroll, record completion)'],
            ['key' => 'staff.competency.assess', 'description' => 'Conduct competency assessments'],
            ['key' => 'staff.induction.manage', 'description' => 'Manage staff induction process'],

            // Data Privacy & GDPR
            ['key' => 'privacy.viewRequests', 'description' => 'View data subject requests and privacy dashboard'],
            ['key' => 'privacy.processRequests', 'description' => 'Process GDPR requests (access, erasure, etc.)'],
            ['key' => 'privacy.manageRetention', 'description' => 'Manage data retention policies'],
            ['key' => 'privacy.manageLegalHolds', 'description' => 'Manage legal holds on data'],
            ['key' => 'privacy.reportBreaches', 'description' => 'Report and manage data breaches'],
            ['key' => 'privacy.conductDPIA', 'description' => 'Conduct Data Protection Impact Assessments'],

            // Sites - Type Scoping
            ['key' => 'sites.type.head_office.view', 'description' => 'View Head Office sites'],
            ['key' => 'sites.type.house.view', 'description' => 'View House sites'],
            ['key' => 'sites.type.facility.view', 'description' => 'View Facility sites'],
            ['key' => 'sites.archive', 'description' => 'Archive/soft-delete sites'],

            // Calendar
            ['key' => 'calendar.view', 'description' => 'View calendars'],
            ['key' => 'calendar.create', 'description' => 'Create calendar events'],
            ['key' => 'calendar.approve', 'description' => 'Approve calendar events'],
            ['key' => 'calendar.manage_recurring', 'description' => 'Manage recurring events'],

            // Hazards
            ['key' => 'hazards.view', 'description' => 'View hazards'],
            ['key' => 'hazards.create', 'description' => 'Log new hazards'],
            ['key' => 'hazards.assign', 'description' => 'Assign hazards to H&S officer'],
            ['key' => 'hazards.close', 'description' => 'Close/resolve hazards'],
            ['key' => 'hazards.manage_types', 'description' => 'Manage hazard type catalog'],

            // Checklists
            ['key' => 'checklists.view', 'description' => 'View checklists'],
            ['key' => 'checklists.run', 'description' => 'Run/complete checklist'],
            ['key' => 'checklists.schedule', 'description' => 'Schedule checklist runs'],
            ['key' => 'checklists.manage_templates', 'description' => 'Manage checklist templates'],

            // Assets (Site Register context)
            ['key' => 'assets.view_register', 'description' => 'View site asset register'],
            ['key' => 'assets.manage_register', 'description' => 'Manage site asset register'],

            // Vendors
            ['key' => 'vendors.view', 'description' => 'View vendors'],
            ['key' => 'vendors.manage', 'description' => 'Manage vendors'],

            // Credentials (Vault)
            ['key' => 'credentials.view', 'description' => 'View credential list'],
            ['key' => 'credentials.reveal', 'description' => 'Reveal credential values'],
            ['key' => 'credentials.manage', 'description' => 'Manage credentials'],

            // Reports - Sites
            ['key' => 'reports.sites.view', 'description' => 'View site reports'],
            ['key' => 'reports.sites.export', 'description' => 'Export site reports'],

            // Settings
            ['key' => 'settings.sites.manage', 'description' => 'Manage site settings'],
            ['key' => 'settings.templates.manage', 'description' => 'Manage templates'],
            ['key' => 'settings.rbac.manage', 'description' => 'Manage RBAC settings'],
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(
                ['key' => $perm['key']],
                ['description' => $perm['description']]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Attach permissions to roles
        |--------------------------------------------------------------------------
        */

        // Admin gets EVERYTHING
        $admin->permissions()->sync(Permission::pluck('id'));

        // Provider Manager
        $providerManager->permissions()->sync(
            Permission::whereIn('key', [
                'sites.viewAny',
                'sites.create',
                'sites.update',

                'staff.viewAny',
                'staff.create',
                'staff.update',
                'staff.invite',
                'staff.assignments.update',
                'staff.credentials.viewAny',
                'staff.credentials.updateAny',
                'staff.availability.updateAny',

                'workers.viewAny',
                'reports.viewAny',
                'rostering.viewAny',
                'fleet.viewAny',
                'fleet.driverSessions.manage',
                'fleet.signals.view',
                'fleet.trips.manage',
                'fleet.fuel.manage',
                'fleet.reports.view',
                'controlRoom.viewAny',
                'controlRoom.alerts.manage',
                'controlRoom.alerts.assign',
                'controlRoom.alerts.escalate',
                'controlRoom.alerts.create',
                'controlRoom.reports.view',
                'calendar.viewAny',

                'compliance.view',

                'timeline.viewAny',
                'timeline.create',
                'timeline.pin',
                'summaries.viewAny',
                'summaries.generate',
                'unifi.manage',

                'shifts.viewAny',
                'shifts.create',
                'shifts.update',
                'shifts.manageAny',

                'timesheets.viewAny',
                'timesheets.create',
                'timesheets.update',
                'timesheets.approve',
                'timesheets.manageAny',

                'clients.viewAny',
                'clients.create',
                'clients.update',
                'clients.assignments.update',
                'clients.onboarding.manage',
                'clients.onboarding.manage',

                'medications.view',
                'medications.orders.manage',
                'medications.administer.record',
                'medications.administer.correct',
                'medications.stock.update',
                'medications.controlled.view',
                'medications.controlled.record',
                'medications.controlled.witness',
                'medications.controlled.override',
                'medications.audit.view',
                'medications.reports.export',
                'medications.breakglass',

                'incidents.viewAny',
                'incidents.create',
                'incidents.update',
                'incidents.submit',
                                'incidents.approve',
                                'incidents.reopen',
                                'incidents.followups.manage',
                'incidents.export',
                'incidents.portal.manage',
                'incidents.view.portal',
                'incidents.attachments.view.portal',

                'respite.viewAny',
                'respite.create',
                'respite.update',
                'respite.bookings.manage',
                'respite.stays.manage',
                'respite.resources.manage',
                'respite.procedures.manage',
                'respite.calendar.view',
                'respite.evidence.view',

                'risks.viewAny',
                'risks.create',
                'risks.update',
                'risks.delete',

                // Settings (adjust to taste)
                'settings.terminology.manage',
                'settings.service_contexts.manage',

                // Audit
                'audit.viewAny',

                // RAG
                'rag.ask.any',

                // Assets
                'assets.viewAny',
                'assets.create',
                'assets.update',
                'assets.delete',
                'assets.inspections.record',
                'assets.maintenance.record',
                'assets.documents.manage',
                'assets.qr.download',
                'assets.ownership.manage',
                'assets.assignments.manage',
                'assets.trackers.manage',
                'assets.telemetry.ingest',
                'assets.telemetry.view',
                'assets.alerts.view',
                'assets.alerts.manage',
                'assets.scan.record',
                'assets.geofences.manage',

                // Safeguarding
                'safeguarding.viewAny',
                'safeguarding.create',
                'safeguarding.update',
                'safeguarding.investigate',
                'safeguarding.report.external',
                'safeguarding.viewSensitive',

                // Consents
                'consents.viewAny',
                'consents.manage',
                'consents.record',
                'consents.withdraw',
                'consents.export',

                // Staff Vetting & Training
                'staff.vetting.view',
                'staff.vetting.manage',
                'staff.training.viewAny',
                'staff.training.manage',
                'staff.competency.assess',
                'staff.induction.manage',

                // Privacy & GDPR
                'privacy.viewRequests',
                'privacy.processRequests',
                'privacy.manageRetention',
                'privacy.manageLegalHolds',
                'privacy.reportBreaches',
                'privacy.conductDPIA',
])->pluck('id')
        );

        // Coordinator (global view, limited settings)
        $coordinator->permissions()->sync(
            Permission::whereIn('key', [
                'sites.viewAny',
                'staff.viewAny',
                'staff.credentials.viewAny',
                'staff.credentials.updateAny',
                'staff.availability.updateAny',
                'clients.viewAny',
                'clients.assignments.update',
                'clients.onboarding.manage',
                'clients.onboarding.manage',

                'medications.view',
                'medications.orders.manage',
                'medications.administer.record',
                'medications.administer.correct',
                'medications.stock.update',
                'medications.controlled.view',
                'medications.controlled.record',
                'medications.controlled.witness',
                'medications.audit.view',
                'medications.reports.export',

                'incidents.viewAny',
                'incidents.create',
                'incidents.update',
                'incidents.approve',
                                'incidents.reopen',
                'incidents.followups.manage',
                'respite.viewAny',
                'respite.create',
                'respite.update',
                'respite.bookings.manage',
                'respite.stays.manage',
                'respite.resources.manage',
                'respite.procedures.manage',
                'respite.calendar.view',
                'respite.evidence.view',
                'risks.viewAny',
                'risks.create',
                'risks.update',
                'risks.delete',
                'shifts.viewAny',
                'shifts.create',
                'shifts.update',
                'timesheets.viewAny',
                'timesheets.approve',
                'timeline.viewAny',
                'timeline.create',
                'timeline.pin',
                'summaries.viewAny',
                'summaries.generate',
                'calendar.viewAny',
                'rag.ask.any',
                'fleet.viewAny',
                'fleet.driverSessions.manage',
                'fleet.signals.view',
                'fleet.trips.manage',
                'fleet.fuel.manage',
                'fleet.reports.view',
                'controlRoom.viewAny',
                'controlRoom.alerts.manage',
                'controlRoom.alerts.assign',
                'controlRoom.alerts.escalate',
                'controlRoom.reports.view',

                // Assets
                'assets.viewAny',
                'assets.create',
                'assets.update',
                'assets.inspections.record',
                'assets.maintenance.record',
                'assets.documents.manage',
                'assets.qr.download',
                'assets.ownership.manage',
                'assets.assignments.manage',
                'assets.trackers.manage',
                'assets.telemetry.ingest',
                'assets.telemetry.view',
                'assets.alerts.view',
                'assets.alerts.manage',
                'assets.scan.record',
                'assets.geofences.manage',

                // Safeguarding
                'safeguarding.viewAny',
                'safeguarding.create',
                'safeguarding.update',
                'safeguarding.investigate',

                // Consents
                'consents.viewAny',
                'consents.record',
                'consents.withdraw',
])->pluck('id')
        );

        // Support Worker
        $supportWorker->permissions()->sync(
            Permission::whereIn('key', [
                'clients.viewAssigned',
                'medications.view',
                'timeline.create',
                'shifts.viewAssigned',
                'shifts.tasks.updateSelf',
                'timesheets.viewAssigned',
                'timesheets.create',
                'timesheets.update',
                'timesheets.submit',

                'incidents.viewAssigned',
                'incidents.create',
                'incidents.update',
                'incidents.submit',
                'incidents.followups.complete',
                'risks.viewAssigned',

                'respite.viewAny',

                'medications.administer.record',
                'medications.administer.correct',
                'medications.controlled.view',
                'medications.controlled.record',
                'medications.controlled.witness',

                'incidents.viewAssigned',
                'incidents.create',
                'incidents.update',
                'risks.viewAssigned',

                'staff.credentials.updateSelf',
                'staff.availability.updateSelf',

                'timeline.create',

                // RAG
                'rag.ask.assigned',

                // Fleet
                'fleet.viewAny',
                'fleet.driverSessions.manage',
                'fleet.signals.view',

                // Control Room (view only for support workers)
                'controlRoom.viewAny',

                // Assets
                'assets.viewAssigned',
                'assets.create',
                'assets.update',
                'assets.inspections.record',
                'assets.maintenance.record',
                'assets.documents.manage',
                'assets.qr.download',
                'assets.assignments.manage',
                'assets.telemetry.view',
                'assets.alerts.view',
                'assets.scan.record',

                // Safeguarding (can report concerns)
                'safeguarding.create',
])->pluck('id')
        );

        // Finance (timesheets + reports)
        $finance->permissions()->sync(
            Permission::whereIn('key', [
                'timesheets.viewAny',
                'timesheets.approve',
                'reports.viewAny',
                'audit.viewAny',
                'medications.view',
                'medications.reports.export',
                'medications.stock.update',
                'medications.view',
                'medications.reports.export',

                'incidents.viewAny',
                'incidents.export',
            ])->pluck('id')
        );

        // HR (staff + compliance)
        $hr->permissions()->sync(
            Permission::whereIn('key', [
                'staff.viewAny',
                'staff.update',
                'staff.credentials.viewAny',
                'staff.credentials.updateAny',
                'staff.availability.updateAny',
                'reports.viewAny',
                'audit.viewAny',

                'compliance.view',

                // Staff Vetting & Training (HR focus)
                'staff.vetting.view',
                'staff.vetting.manage',
                'staff.training.viewAny',
                'staff.training.manage',
                'staff.competency.assess',
                'staff.induction.manage',

                // Safeguarding (HR involvement)
                'safeguarding.viewAny',
                'safeguarding.create',
            ])->pluck('id')
        );

        // Auditor (read-only, audit + reporting + view)
        $auditor->permissions()->sync(
            Permission::whereIn('key', [
                'clients.viewAny',
                'medications.view',
                'medications.audit.view',
                'shifts.viewAny',
                'timesheets.viewAny',
                'reports.viewAny',
                'timeline.viewAny',
                'summaries.viewAny',
                'audit.viewAny',
                'compliance.view',
                'medications.view',
                'medications.audit.view',
                'incidents.viewAny',
                'risks.viewAny',
                'assets.viewAny',
                'respite.viewAny',
                'respite.evidence.view',
                'assets.telemetry.view',
                'assets.alerts.view',

                // New compliance modules (read-only)
                'safeguarding.viewAny',
                'consents.viewAny',
                'staff.vetting.view',
                'staff.training.viewAny',
                'privacy.viewRequests',
])->pluck('id')
        );

        // Client / Next-of-kin portal users
        // (Most access control is enforced via ClientPolicy + client_portal_users links.)
        $clientRole->permissions()->sync(
            Permission::whereIn('key', [
                'rag.ask.self',
                'incidents.view.portal',
                'incidents.attachments.view.portal',
            ])->pluck('id')
        );

        $nextOfKinRole->permissions()->sync(
            Permission::whereIn('key', [
                'rag.ask.self',
                'incidents.view.portal',
                'incidents.attachments.view.portal',
            ])->pluck('id')
        );

        // Team Lead
        $teamLead->permissions()->sync(
            Permission::whereIn('key', [
                'sites.viewAny',
                'sites.update',
                'calendar.view',
                'calendar.create',
                'calendar.approve',
                'hazards.view',
                'hazards.create',
                'hazards.assign',
                'checklists.view',
                'checklists.run',
                'checklists.schedule',
                'vendors.view',
                'credentials.view',
                'reports.sites.view',
            ])->pluck('id')
        );

        // Health & Safety Officer
        $healthSafetyOfficer->permissions()->sync(
            Permission::whereIn('key', [
                'sites.viewAny',
                'sites.type.head_office.view',
                'sites.type.house.view',
                'sites.type.facility.view',
                'calendar.view',
                'hazards.view',
                'hazards.create',
                'hazards.assign',
                'hazards.close',
                'checklists.view',
                'checklists.run',
                'checklists.manage_templates',
                'vendors.view',
                'credentials.view',
                'reports.sites.view',
            ])->pluck('id')
        );

        // Maintenance Coordinator
        $maintenanceCoordinator->permissions()->sync(
            Permission::whereIn('key', [
                'sites.viewAny',
                'sites.type.head_office.view',
                'sites.type.house.view',
                'sites.type.facility.view',
                'calendar.view',
                'calendar.create',
                'hazards.view',
                'checklists.view',
                'checklists.run',
                'checklists.schedule',
                'assets.view_register',
                'assets.manage_register',
                'vendors.view',
                'vendors.manage',
                'credentials.view',
                'credentials.reveal',
                'reports.sites.view',
            ])->pluck('id')
        );

        // Board Trustee (read-only reports and audit)
        $boardTrustee->permissions()->sync(
            Permission::whereIn('key', [
                'reports.viewAny',
                'audit.viewAny',
                'reports.sites.view',
                'reports.sites.export',
            ])->pluck('id')
        );

        /*
        |--------------------------------------------------------------------------
        | 4. Migrate existing users.role → RBAC roles
        |--------------------------------------------------------------------------
        | Keeps your current system working while you transition UI & routes.
        */
        User::query()
            ->select('id', 'role')
            ->chunk(200, function ($users) use ($admin, $providerManager, $coordinator, $supportWorker, $finance, $hr, $auditor) {
                foreach ($users as $user) {
                    $roleName = $user->role ?? 'support_worker';

                    $role = match ($roleName) {
                        'admin' => $admin,
                        'provider_manager' => $providerManager,
                        'coordinator' => $coordinator,
                        'finance' => $finance,
                        'hr' => $hr,
                        'auditor' => $auditor,
                        default => $supportWorker,
                    };

                    $user->roles()->syncWithoutDetaching([$role->id]);
                }
            });
    }
}
