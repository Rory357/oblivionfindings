<?php

namespace Tests\Support;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\ConsentType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Tracking\ClientLocationAccessService;

class ClientLocationWorkspaceFixture
{
    public static function make(bool $manage = true, bool $assignedOnly = false): array
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $actor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $role = Role::query()->create(['name' => 'zone_test_'.$actor->id, 'label' => 'Zone test', 'level' => 50, 'type' => 'custom']);
        $keys = [$assignedOnly ? 'clients.viewAssigned' : 'clients.viewAny', 'assets.telemetry.view', $assignedOnly ? 'assets.viewAssigned' : 'assets.viewAny', ...($manage ? ['assets.trackers.manage'] : [])];
        foreach ($keys as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'test', 'module' => 'Test']);
            $role->permissions()->attach($permission->id);
        }
        $actor->roles()->attach($role->id);
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        if ($assignedOnly) {
            $client->supportWorkers()->attach($actor->id);
        }
        $type = ConsentType::query()->firstOrCreate(['name' => 'Personal Tracker (Wandering Risk)'], [
            'category' => 'safety', 'description' => 'Synthetic tracking consent', 'purpose' => 'Client personal safety location tracking',
            'legal_basis' => 'consent', 'is_mandatory' => false, 'requires_capacity_assessment' => false, 'allows_withdrawal' => true,
            'validity_period_days' => 365, 'renewal_required' => true, 'renewal_reminder_days' => 30, 'version' => 1, 'active' => true,
        ]);
        $consent = AuthoritativeConsentFixture::manualSelf($client, $type, $actor, ['given_at' => now()->subDays(3)]);
        $device = Device::factory()->tracking()->create();
        $assignment = DeviceAssignment::query()->create([
            'device_id' => $device->id, 'assignable_type' => 'client', 'assignable_id' => $client->id,
            'assignment_type' => 'permanent', 'assigned_at' => now()->subDays(2), 'assigned_by_user_id' => $actor->id,
            'consent_id' => $consent->id, 'tracking_purpose' => $consent->decision_purpose,
            'authority_basis' => 'assignment_linked_client_consent', 'access_audience' => ['authorised_client_care'],
            'retention_days' => 30, 'collection_started_at' => now()->subDay(),
        ]);
        $fingerprint = app(ClientLocationAccessService::class)->fingerprint(app(ClientLocationAccessService::class)->resolve($actor, $client));
        $payload = [
            'name' => 'Library visit', 'purpose' => 'Agreed independent visit', 'classification' => 'agreed',
            'idempotency_key' => 'synthetic-draft-save-001', 'access_fingerprint' => $fingerprint,
            'geometry_source' => 'custom', 'geometry' => ['type' => 'circle', 'center' => ['lat' => -36.85, 'lng' => 174.76], 'radius_m' => 80],
            'schedule' => ['timezone' => 'Pacific/Auckland', 'weekdays' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '16:00', 'following_day' => false,
                'first_date' => '2026-09-21', 'last_date' => '2026-10-30', 'exception_dates' => []],
            'response_proposal' => 'Discuss the response plan at review.',
        ];

        return compact('site', 'actor', 'client', 'consent', 'device', 'assignment', 'fingerprint', 'payload');
    }
}
