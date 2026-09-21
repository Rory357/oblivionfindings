<?php

namespace App\Services\Tracking;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\DeviceCustodySiteResolver;
use App\Models\AssetGeofence;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ClientGeofenceMonitor;
use App\Models\ClientGeofenceRule;
use App\Models\ClientGeofenceRuleVersion;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClientLocationZoneDraftService
{
    public function __construct(private ClientLocationAccessService $access) {}

    public function read(User $actor, Client $client): array
    {
        $assignment = $this->access->resolve($actor, $client);
        $fingerprint = $this->access->fingerprint($assignment);
        abort_unless(Schema::hasTable('client_geofence_rule_versions'), 503, 'Zone drafts are not available yet.');
        $rules = ClientGeofenceRule::query()->where('client_id', $client->id)->where('site_id', $assignment->custody_site_id)
            ->where('status', 'draft')->orderByDesc('updated_at')->get();
        $versions = ClientGeofenceRuleVersion::query()->whereIn('rule_id', $rules->modelKeys())->orderByDesc('revision')->get()->groupBy('rule_id');
        $zones = $rules->map(fn ($rule) => $this->present($rule, $versions[$rule->id]->firstWhere('revision', $rule->current_revision), $fingerprint));
        $boundaries = $this->access->eligibleBoundaries($client, (int) $assignment->custody_site_id)->orderBy('name')->get()
            ->map(fn ($geometry) => ['id' => $geometry->id, 'name' => $geometry->name, 'geometry' => $this->geometry($geometry), 'hash' => $this->geometryHash($geometry)]);
        $this->access->recheck($actor, $client, $fingerprint);

        return ['zones' => $zones, 'boundaries' => $boundaries, 'access_fingerprint' => $fingerprint, 'checked_at' => now()->toISOString()];
    }

    public function save(User $actor, Client $client, array $data, ?int $ruleId = null): array
    {
        $candidate = $this->access->recheck($actor, $client, $data['access_fingerprint'], true);
        abort_unless(Schema::hasTable('client_geofence_rule_versions'), 503, 'Zone drafts are not available yet.');

        return DB::transaction(function () use ($actor, $client, $data, $ruleId, $candidate): array {
            ClientConsent::query()->whereKey($candidate->consent_id)->lockForUpdate()->firstOrFail();
            Device::query()->whereKey($candidate->device_id)->lockForUpdate()->firstOrFail();
            app(DeviceCustodySiteResolver::class)->resolve(DeviceAssignment::TARGET_CLIENT, (int) $client->id, true);
            DeviceAssignment::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $actor = app(AuthorizationEvidenceLockService::class)->lockForUser($actor, ['*']);
            $actor->setRelation('hrEmployeeProfile', HrEmployeeProfile::query()->where('user_id', $actor->id)->lockForUpdate()->first());
            $assignment = $this->access->recheck($actor, $client, $data['access_fingerprint'], true, true);
            $geometry = $data['geometry'] ?? null;
            $canonical = null;
            if ($data['geometry_source'] === 'canonical') {
                $canonical = $this->access->eligibleBoundaries($client, (int) $assignment->custody_site_id)
                    ->whereKey($data['canonical_geofence_id'])->lockForUpdate()->firstOrFail();
                abort_unless(hash_equals($this->geometryHash($canonical), $data['canonical_geometry_hash']), 409, 'This boundary changed. Reload and review it.');
                $geometry = $this->geometry($canonical);
            }
            $rule = $ruleId === null ? null : ClientGeofenceRule::query()
                ->where('client_id', $client->id)->where('site_id', $assignment->custody_site_id)->where('status', 'draft')
                ->whereKey($ruleId)->lockForUpdate()->firstOrFail();
            abort_if($rule && Schema::hasTable('client_geofence_monitors') && ClientGeofenceMonitor::query()
                ->where('rule_id', $rule->id)->whereNull('ended_at')->exists(), 409, 'Pause monitoring before editing this zone.');
            $key = hash('sha256', implode(':', [$client->id, $actor->id, $ruleId ?? 'create', $data['idempotency_key']]));
            $hash = $this->hash($data);
            $replay = ClientGeofenceRuleVersion::query()->where('operation_key', $key)->first();
            if ($replay) {
                abort_unless(hash_equals($replay->payload_hash, $hash), 409, 'This save key was already used for different changes.');

                return $this->present(ClientGeofenceRule::query()->findOrFail($replay->rule_id), $replay);
            }
            abort_if($rule && $rule->current_revision !== (int) $data['expected_revision'], 409, 'Someone changed this draft. Reload and review before saving.');
            $previous = $rule?->versions()->where('revision', $rule->current_revision)->first();
            if ($previous?->geometry_source === 'canonical') {
                $changedSource = $data['geometry_source'] !== 'canonical'
                    || (int) $previous->canonical_geofence_id !== (int) $canonical?->id
                    || $previous->canonical_geometry_hash !== $this->geometryHash($canonical);
                abort_if($changedSource && ! ($data['source_change_reviewed'] ?? false), 409, 'Review the changed or unavailable linked boundary before replacing its source.');
            }
            $rule ??= new ClientGeofenceRule(['client_id' => $client->id, 'site_id' => $assignment->custody_site_id, 'status' => 'draft', 'current_revision' => 0]);
            $rule->current_revision++;
            $rule->save();
            $version = $rule->versions()->create([
                'revision' => $rule->current_revision, 'name' => $data['name'], 'purpose' => $data['purpose'],
                'classification' => $data['classification'], 'geometry_source' => $data['geometry_source'],
                'geometry_proposal' => $geometry, 'canonical_geofence_id' => $canonical?->id,
                'canonical_geometry_hash' => $canonical ? $this->geometryHash($canonical) : null,
                'schedule_proposal' => $data['schedule'], 'response_proposal' => $data['response_proposal'] ?? null,
                'actor_id' => $actor->id, 'assignment_id' => $assignment->id, 'consent_id' => $assignment->consent_id,
                'access_fingerprint' => $data['access_fingerprint'], 'operation_key' => $key, 'payload_hash' => $hash, 'created_at' => now(),
            ]);
            $this->access->recheck($actor, $client, $data['access_fingerprint'], true, true);
            AuditLogger::logOrFail('client.location.zone_draft.saved', $client, ['actor_id' => $actor->id, 'rule_id' => $rule->id, 'revision' => $version->revision]);

            return $this->present($rule, $version);
        }, 3);
    }

    public function present(ClientGeofenceRule $rule, ClientGeofenceRuleVersion $version, ?string $fingerprint = null): array
    {
        $monitor = Schema::hasTable('client_geofence_monitors')
            ? ClientGeofenceMonitor::query()->where('rule_id', $rule->id)->latest('id')->first() : null;

        return ['id' => $rule->id, 'revision' => $version->revision, 'status' => $rule->status, 'name' => $version->name,
            'purpose' => $version->purpose, 'classification' => $version->classification, 'geometry_source' => $version->geometry_source,
            'geometry' => $version->geometry_proposal, 'canonical_geofence_id' => $version->canonical_geofence_id,
            'canonical_geometry_hash' => $version->canonical_geometry_hash, 'schedule' => $version->schedule_proposal,
            'response_proposal' => $version->response_proposal, 'saved_at' => $version->created_at->toISOString(),
            'monitoring' => $monitor ? ['id' => $monitor->id, 'status' => $monitor->ended_at ? 'paused' : 'active',
                'authority_current' => $fingerprint !== null && hash_equals($monitor->access_fingerprint, $fingerprint),
                'started_at' => $monitor->started_at->toISOString(), 'ended_at' => $monitor->ended_at?->toISOString(),
                'last_observed_at' => $monitor->last_observed_at?->toISOString(), 'position_status' => $monitor->position_status,
                'breach_count' => $monitor->breach_count, 'in_schedule' => app(ClientZoneSchedule::class)->window($version->schedule_proposal, now()) !== null,
                'destination' => 'Control Room'] : null];
    }

    private function geometry(AssetGeofence $fence): array
    {
        $shape = $fence->shape;
        $point = fn ($p) => ['lat' => $p['lat'] ?? $p['latitude'] ?? null, 'lng' => $p['lng'] ?? $p['lon'] ?? $p['longitude'] ?? null];

        return $fence->type === 'circle'
            ? ['type' => 'circle', 'center' => $point($shape['center'] ?? $shape), 'radius_m' => $shape['radius_m'] ?? $shape['radius'] ?? null]
            : ['type' => 'polygon', 'coordinates' => array_map($point, $shape['coordinates'] ?? $shape['points'] ?? [])];
    }

    public function geometryHash(AssetGeofence $fence): string
    {
        return $this->hash([$fence->id, $fence->site_id, $fence->type, $fence->scope, $fence->is_active, $fence->shape]);
    }

    private function hash(array $value): string
    {
        $sort = function (array $item) use (&$sort): array {
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map(fn ($child) => is_array($child) ? $sort($child) : $child, $item);
        };

        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
