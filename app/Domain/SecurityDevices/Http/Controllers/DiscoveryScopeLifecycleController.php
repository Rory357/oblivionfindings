<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Discovery\Services\DiscoveryRunner;
use App\Domain\Monitoring\Services\NativeMonitoringDefinitionService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class DiscoveryScopeLifecycleController extends Controller
{
    public function __construct(
        private readonly NativeMonitoringDefinitionService $definitions,
        private readonly SecurityDevicesAccessService $access,
        private readonly DiscoveryRunner $runner,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $viewer = $this->manager($request);
        $validated = $request->validate($this->rules(false));
        $siteId = (int) $validated['site_id'];
        $this->access->assertCanViewSite($viewer, $siteId);
        $site = Site::query()
            ->whereKey($siteId)
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->firstOrFail();
        $scope = $this->definitions->createScope($viewer, $site, $validated);

        return response()->json(['scope' => $this->safeScope($scope)], 201);
    }

    public function update(Request $request, int $scope): JsonResponse
    {
        $viewer = $this->manager($request);
        $record = $this->accessibleScope($viewer, $scope);
        $updated = $this->definitions->updateScope(
            $viewer,
            $record,
            $request->validate($this->rules(true)),
        );

        return response()->json(['scope' => $this->safeScope($updated)]);
    }

    public function deactivate(Request $request, int $scope): JsonResponse
    {
        $viewer = $this->manager($request);
        $record = $this->accessibleScope($viewer, $scope);
        $validated = $request->validate([
            'reason_code' => ['required', 'string', Rule::in([
                'network_retired',
                'scope_replaced',
                'duplicate_scope',
                'site_connectivity_changed',
            ])],
        ]);
        $updated = $this->definitions->deactivateScope(
            $viewer,
            $record,
            (string) $validated['reason_code'],
        );

        return response()->json([
            'scope' => ['id' => (int) $updated->id, 'status' => $updated->status],
        ]);
    }

    public function apply(Request $request, int $scope): JsonResponse
    {
        $viewer = $this->manager($request);
        $record = $this->accessibleScope($viewer, $scope);
        try {
            $run = DB::transaction(function () use ($viewer, $record) {
                $run = $this->runner->start($record, "manual:user:{$viewer->id}");
                AuditLogger::logOrFail('monitoring.discovery.scope.applied', $record, [
                    'actor_id' => (int) $viewer->id,
                    'site_id' => (int) $record->site_id,
                    'run_id' => (int) $run->id,
                    'run_uuid' => $run->run_uuid,
                    'run_status' => $run->status,
                    'planned_targets' => (int) $run->planned_targets,
                    'collection_mode' => 'central_direct',
                ]);

                return $run;
            }, 3);
        } catch (UnexpectedValueException) {
            throw ValidationException::withMessages([
                'scope' => 'The governed discovery plan is not ready to run.',
            ]);
        }

        return response()->json([
            'run' => [
                'id' => (int) $run->id,
                'run_uuid' => $run->run_uuid,
                'status' => $run->status,
                'planned_targets' => (int) $run->planned_targets,
            ],
        ], 202);
    }

    private function manager(Request $request): User
    {
        $viewer = $request->user();
        abort_unless($viewer instanceof User && $viewer->canDo('securityDevices.integrations.manage'), 403);

        return $viewer;
    }

    private function accessibleScope(User $viewer, int $scopeId): DiscoveryScope
    {
        $siteIds = $this->access->accessibleSiteIds($viewer);

        return DiscoveryScope::query()
            ->whereKey($scopeId)
            ->whereNull('collector_id')
            ->when(
                $siteIds === [],
                fn ($query) => $query->whereRaw('1 = 0'),
                fn ($query) => $query->whereIn('site_id', $siteIds),
            )
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function rules(bool $updating): array
    {
        $sometimes = $updating ? ['sometimes'] : ['required'];
        $protocols = NativeMonitoringDefinitionService::discoveryProtocols();

        return [
            'site_id' => $updating ? ['prohibited'] : ['required', 'integer', 'min:1'],
            'name' => [...$sometimes, 'string', 'min:3', 'max:128', 'not_regex:/[\x00-\x1F\x7F]/'],
            'cidrs' => [...$sometimes, 'array', 'min:1', 'max:64'],
            'cidrs.*' => ['string', 'max:2048', 'not_regex:/[\x00-\x1F\x7F]/'],
            'seed_hosts' => ['sometimes', 'array', 'max:256'],
            'seed_hosts.*' => ['string', 'max:253', 'not_regex:/[\x00-\x1F\x7F]/'],
            'protocols' => [...$sometimes, 'array', 'min:1', 'max:6'],
            'protocols.*' => ['string', Rule::in($protocols)],
            'snmp_credential_reference' => ['sometimes', 'nullable', 'string', 'max:191', 'regex:/^[a-z][a-z0-9._-]{1,31}:[A-Za-z0-9._\/:@-]{1,158}$/'],
            'exclusions' => ['sometimes', 'array', 'max:1024'],
            'exclusions.*' => ['string', 'max:2048', 'not_regex:/[\x00-\x1F\x7F]/'],
            'port_bounds' => ['sometimes', 'array', 'max:5'],
            'port_bounds.*' => ['array', 'max:128'],
            'port_bounds.*.*' => ['integer', 'between:1,65535'],
            'max_targets_per_run' => ['sometimes', 'integer', 'between:1,65536'],
            'packets_per_second' => ['sometimes', 'integer', 'between:1,1000'],
        ];
    }

    /** @return array<string, mixed> */
    private function safeScope(DiscoveryScope $scope): array
    {
        return [
            'id' => (int) $scope->id,
            'name' => $scope->name,
            'site_id' => (int) $scope->site_id,
            'status' => $scope->status,
            'collection_mode' => 'central_direct',
            'protocols' => array_values($scope->protocols ?? []),
            'network_range_count' => count($scope->cidrs ?? []),
            'seed_host_count' => count($scope->seed_hosts ?? []),
            'exclusion_count' => count($scope->exclusions ?? []),
        ];
    }
}
