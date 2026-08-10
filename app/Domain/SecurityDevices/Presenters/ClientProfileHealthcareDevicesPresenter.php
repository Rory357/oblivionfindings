<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Minimum-necessary, read-only healthcare Device context for Client Profile.
 *
 * Canonical Device, monitoring, maintenance, and IT ownership stays in
 * Security & Devices and IT. Clinical readings remain in Client Health.
 */
class ClientProfileHealthcareDevicesPresenter
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly HealthcareWorkspacePresenter $healthcare,
    ) {}

    public function canView(User $viewer, Client $client): bool
    {
        return $viewer->canDo('securityDevices.devices.view')
            && Gate::forUser($viewer)->allows('view', $client)
            && $this->access->assignableClient($viewer, (int) $client->getKey()) !== null;
    }

    public function present(User $viewer, Client $client, bool $canViewClinical): ?array
    {
        if (! $this->canView($viewer, $client)) {
            return null;
        }

        $scope = $this->access->visibleDevices($viewer)
            ->byDomain('iot_healthcare')
            ->whereHas('assignments', fn (Builder $assignment): Builder => $assignment
                ->active()
                ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
                ->where('assignable_id', $client->getKey()));

        $workspace = $this->healthcare->present($viewer, $scope, [
            'key' => 'client-devices',
            'label' => 'Client devices',
            'description' => 'Technical status for healthcare-connected devices assigned to this client.',
        ]);
        $overview = $workspace['overview'];

        return [
            'boundary' => [
                'title' => 'Technical device status only',
                'description' => 'This projection never includes readings, vital values, thresholds, diagnoses, medications, or clinical notes. Those remain in Client Health Monitoring.',
            ],
            'summary' => [
                'total' => $overview['inventory']['total'],
                'offline' => $overview['attention']['offline'],
                'data_flow_issues' => $overview['attention']['data_flow_issues'],
                'overdue_calibration' => $overview['attention']['overdue_calibration'],
                'maintenance_due' => $overview['attention']['maintenance_due'],
            ],
            'devices' => collect($workspace['activeTab']['devices'])
                ->map(fn (array $device): array => [
                    'id' => $device['id'],
                    'name' => $device['name'],
                    'category' => $device['category'],
                    'subcategory' => $device['subcategory'],
                    'provider' => $device['provider'],
                    'status' => $device['status'],
                    'health' => $device['health'],
                    'last_seen_at' => $device['lastSeenAt'],
                    'href' => $device['deviceHref'],
                    'assignment' => $device['assignment'],
                    'technical' => $device['technical'],
                    'monitoring' => $device['monitoring'],
                    'maintenance' => $device['maintenance'],
                    'it_tickets' => $device['itTickets'],
                ])
                ->values(),
            'truncated' => $workspace['activeTab']['inventoryTruncated'],
            'permissions' => $workspace['permissions'],
            'links' => [
                'healthcare' => '/security-devices/healthcare?tab=client-devices',
                'clinical' => $canViewClinical
                    ? "/operations/clients/{$client->getKey()}?tab=health_monitoring"
                    : null,
            ],
        ];
    }
}
