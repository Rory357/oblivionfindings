<?php

namespace App\Support;

class ReportCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function modules(): array
    {
        return [
            [
                'key' => 'clients',
                'label' => 'Clients',
                'description' => 'Client records, status, site, and service context.',
                'route' => '/reports/modules/clients',
                'model' => \App\Models\Client::class,
                'date_field' => 'created_at',
                'columns' => [
                    'id' => 'ID',
                    'first_name' => 'First Name',
                    'last_name' => 'Last Name',
                    'status' => 'Status',
                    'site_id' => 'Site ID',
                    'service_context_id' => 'Service Context ID',
                    'email' => 'Email',
                    'phone' => 'Phone',
                    'created_at' => 'Created At',
                ],
                'search_columns' => ['first_name', 'last_name', 'email', 'phone'],
            ],
            [
                'key' => 'staff',
                'label' => 'Staff / Users',
                'description' => 'User accounts, roles, approvals, and verification state.',
                'route' => '/reports/modules/staff',
                'model' => \App\Models\User::class,
                'date_field' => 'created_at',
                'columns' => [
                    'id' => 'ID',
                    'name' => 'Name',
                    'email' => 'Email',
                    'role' => 'Role',
                    'email_verified_at' => 'Email Verified At',
                    'approved_at' => 'Approved At',
                    'created_at' => 'Created At',
                ],
                'search_columns' => ['name', 'email', 'role'],
            ],
            [
                'key' => 'sites',
                'label' => 'Sites',
                'description' => 'Site status and key contact/location information.',
                'route' => '/reports/modules/sites',
                'model' => \App\Models\Site::class,
                'date_field' => 'created_at',
                'columns' => [
                    'id' => 'ID',
                    'name' => 'Name',
                    'city' => 'City',
                    'postcode' => 'Postcode',
                    'is_active' => 'Active',
                    'email' => 'Email',
                    'created_at' => 'Created At',
                ],
                'search_columns' => ['name', 'city', 'postcode', 'email'],
            ],
            [
                'key' => 'shifts',
                'label' => 'Shifts',
                'description' => 'Shift roster records with staff/client and status.',
                'route' => '/reports/modules/shifts',
                'model' => \App\Models\Shift::class,
                'date_field' => 'starts_at',
                'columns' => [
                    'id' => 'ID',
                    'client_id' => 'Client ID',
                    'user_id' => 'Staff ID',
                    'service_context_id' => 'Service Context ID',
                    'status' => 'Status',
                    'starts_at' => 'Starts At',
                    'ends_at' => 'Ends At',
                    'created_at' => 'Created At',
                ],
                'search_columns' => ['status', 'location'],
            ],
            [
                'key' => 'timesheets',
                'label' => 'Timesheets',
                'description' => 'Timesheet lifecycle and approval outcomes.',
                'route' => '/reports/modules/timesheets',
                'model' => \App\Models\Timesheet::class,
                'date_field' => 'work_date',
                'columns' => [
                    'id' => 'ID',
                    'user_id' => 'Staff ID',
                    'client_id' => 'Client ID',
                    'shift_id' => 'Shift ID',
                    'status' => 'Status',
                    'work_date' => 'Work Date',
                    'starts_at' => 'Starts At',
                    'ends_at' => 'Ends At',
                    'approved_at' => 'Approved At',
                    'created_at' => 'Created At',
                ],
                'search_columns' => ['status', 'notes'],
            ],
            [
                'key' => 'incidents',
                'label' => 'Incidents',
                'description' => 'Client incident records with severity and review state.',
                'route' => '/reports/modules/incidents',
                'model' => \App\Models\ClientIncident::class,
                'date_field' => 'occurred_at',
                'columns' => [
                    'id' => 'ID',
                    'client_id' => 'Client ID',
                    'reported_by' => 'Reported By',
                    'type' => 'Type',
                    'severity' => 'Severity',
                    'status' => 'Status',
                    'occurred_at' => 'Occurred At',
                    'reviewed_at' => 'Reviewed At',
                    'created_at' => 'Created At',
                ],
                'search_columns' => ['type', 'severity', 'status', 'description'],
            ],
            [
                'key' => 'safeguarding',
                'label' => 'Safeguarding',
                'description' => 'Safeguarding concerns, severity, assignments, and closure.',
                'route' => '/reports/modules/safeguarding',
                'model' => \App\Models\SafeguardingConcern::class,
                'date_field' => 'reported_at',
                'columns' => [
                    'id' => 'ID',
                    'reference_number' => 'Reference',
                    'subject_type' => 'Subject Type',
                    'subject_id' => 'Subject ID',
                    'concern_type' => 'Concern Type',
                    'severity' => 'Severity',
                    'status' => 'Status',
                    'assigned_to_user_id' => 'Assigned To',
                    'reported_at' => 'Reported At',
                    'closed_at' => 'Closed At',
                    'created_at' => 'Created At',
                ],
                'search_columns' => ['reference_number', 'subject_name', 'concern_type', 'severity', 'status'],
            ],
            [
                'key' => 'assets',
                'label' => 'Assets',
                'description' => 'Asset inventory, status, risk, and due dates.',
                'route' => '/reports/modules/assets',
                'model' => \App\Models\Asset::class,
                'date_field' => 'created_at',
                'columns' => [
                    'id' => 'ID',
                    'asset_tag' => 'Asset Tag',
                    'name' => 'Name',
                    'category' => 'Category',
                    'status' => 'Status',
                    'risk_level' => 'Risk',
                    'site_id' => 'Site ID',
                    'client_id' => 'Client ID',
                    'inspection_due_at' => 'Inspection Due',
                    'maintenance_due_at' => 'Maintenance Due',
                    'warranty_expires_at' => 'Warranty Expires',
                    'created_at' => 'Created At',
                ],
                'search_columns' => ['asset_tag', 'name', 'category', 'status', 'risk_level'],
            ],
            [
                'key' => 'medication_administrations',
                'label' => 'Medication Administrations',
                'description' => 'MAR entries including outcomes and accountability.',
                'route' => '/reports/modules/medication_administrations',
                'model' => \App\Models\ClientMedicationAdministration::class,
                'date_field' => 'administered_at',
                'columns' => [
                    'id' => 'ID',
                    'client_id' => 'Client ID',
                    'client_medication_id' => 'Medication ID',
                    'shift_id' => 'Shift ID',
                    'service_context_id' => 'Service Context ID',
                    'status' => 'Status',
                    'scheduled_for' => 'Scheduled For',
                    'administered_at' => 'Administered At',
                    'administered_by' => 'Administered By',
                    'witnessed_by' => 'Witnessed By',
                    'created_at' => 'Created At',
                ],
                'search_columns' => ['status', 'reason', 'notes'],
            ],
            [
                'key' => 'controlled_drug_discrepancies',
                'label' => 'Controlled Drug Discrepancies',
                'description' => 'Controlled-drug discrepancy events and resolution state.',
                'route' => '/reports/modules/controlled_drug_discrepancies',
                'model' => \App\Models\ClientControlledDrugDiscrepancy::class,
                'date_field' => 'reported_at',
                'columns' => [
                    'id' => 'ID',
                    'client_id' => 'Client ID',
                    'client_medication_id' => 'Medication ID',
                    'service_context_id' => 'Service Context ID',
                    'status' => 'Status',
                    'difference' => 'Difference',
                    'reported_by' => 'Reported By',
                    'resolved_by' => 'Resolved By',
                    'reported_at' => 'Reported At',
                    'resolved_at' => 'Resolved At',
                    'created_at' => 'Created At',
                ],
                'search_columns' => ['status', 'reason', 'notes'],
            ],
            [
                'key' => 'fleet_trips',
                'label' => 'Fleet Trips',
                'description' => 'Trip starts/ends, status, and distance.',
                'route' => '/reports/modules/fleet_trips',
                'model' => \App\Models\FleetTrip::class,
                'date_field' => 'started_at',
                'columns' => [
                    'id' => 'ID',
                    'asset_id' => 'Asset ID',
                    'driver_session_id' => 'Driver Session ID',
                    'status' => 'Status',
                    'started_at' => 'Started At',
                    'ended_at' => 'Ended At',
                    'distance_km' => 'Distance (km)',
                    'duration_s' => 'Duration (s)',
                    'consent_blocked' => 'Consent Blocked',
                    'created_at' => 'Created At',
                ],
                'search_columns' => ['status', 'start_address', 'end_address'],
            ],
            [
                'key' => 'respite_bookings',
                'label' => 'Respite Bookings',
                'description' => 'Respite booking windows and coordination status.',
                'route' => '/reports/modules/respite_bookings',
                'model' => \App\Models\RespiteBooking::class,
                'date_field' => 'start_at',
                'columns' => [
                    'id' => 'ID',
                    'booking_request_id' => 'Request ID',
                    'client_id' => 'Client ID',
                    'status' => 'Status',
                    'start_at' => 'Start At',
                    'end_at' => 'End At',
                    'assigned_coordinator_id' => 'Coordinator ID',
                    'created_at' => 'Created At',
                ],
                'search_columns' => ['status', 'cancellation_reason'],
            ],
            [
                'key' => 'respite_requests',
                'label' => 'Respite Requests',
                'description' => 'Respite requests, approval state, and context.',
                'route' => '/reports/modules/respite_requests',
                'model' => \App\Models\RespiteBookingRequest::class,
                'date_field' => 'requested_start',
                'columns' => [
                    'id' => 'ID',
                    'referral_id' => 'Referral ID',
                    'client_id' => 'Client ID',
                    'service_context_id' => 'Service Context ID',
                    'status' => 'Status',
                    'requested_start' => 'Requested Start',
                    'requested_end' => 'Requested End',
                    'approved_by_user_id' => 'Approved By',
                    'approved_at' => 'Approved At',
                    'created_at' => 'Created At',
                ],
                'search_columns' => ['status', 'funding_reference', 'decision_notes'],
            ],
            [
                'key' => 'audit_logs',
                'label' => 'Audit Logs',
                'description' => 'Security/audit events with actor and context.',
                'route' => '/reports/modules/audit_logs',
                'model' => \App\Models\AuditLog::class,
                'date_field' => 'created_at',
                'columns' => [
                    'id' => 'ID',
                    'user_id' => 'User ID',
                    'client_id' => 'Client ID',
                    'action' => 'Action',
                    'auditable_type' => 'Auditable Type',
                    'auditable_id' => 'Auditable ID',
                    'ip_address' => 'IP Address',
                    'created_at' => 'Created At',
                ],
                'search_columns' => ['action', 'auditable_type', 'ip_address'],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_values(array_map(
            fn (array $module): string => (string) $module['key'],
            self::modules(),
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $key): ?array
    {
        foreach (self::modules() as $module) {
            if (($module['key'] ?? null) === $key) {
                return $module;
            }
        }

        return null;
    }
}
