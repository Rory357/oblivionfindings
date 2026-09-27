<?php

namespace App\Services\Reporting;

use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Client;
use App\Models\LoneWorkerSession;
use App\Models\User;
use App\Services\Fleet\FleetTripSiteScope;
use App\Services\Fleet\VehicleFinanceService;
use App\Services\HealthSafety\LoneWorkerSessionScope;
use App\Services\Tracking\ClientLocationAccessService;
use App\Services\UserSiteAccessService;

final class ReportAccess
{
    public function actor(User $user): User
    {
        $actor = $user->fresh();
        abort_unless($actor && $actor->approved_at, 403);

        return $actor;
    }

    public function sources(User $user, ?string $domain = null): array
    {
        $actor = $this->actor($user);

        return array_filter(config('operational-reports.sources'), function ($source, $key) use ($actor, $domain) {
            if ($domain && $source['domain'] !== $domain) {
                return false;
            }
            $allowed = match ($source['domain']) {
                'fleet' => $actor->canDo('fleet.reports.view') || $actor->canDo('fleet.viewAny'),
                'client' => $actor->canDo('assets.telemetry.view'),
                'staff' => $actor->canDo('hazards.view'),
                'self' => true,
                default => false,
            };
            if ($key === 'demand') {
                $allowed = $allowed && ($actor->canDo('fleet.viewAny') || $actor->canDo('assets.viewAny'));
            }

            return $allowed && (! $source['permission'] || $actor->canDo($source['permission']))
                && ($source['permission'] !== 'finance.ap.view' || $actor->canDo('finance.assets.view'));
        }, ARRAY_FILTER_USE_BOTH);
    }

    public function context(User $user, array $definition): array
    {
        $actor = $this->actor($user);
        $sources = $this->sources($actor);
        $source = $sources[$definition['source']] ?? null;
        abort_unless($source, 403);
        abort_if($source['domain'] !== 'fleet' && $definition['resource_ids'] !== [], 422, 'Resources apply to Fleet reports only.');
        abort_if(in_array($source['domain'], ['client', 'self']) && $definition['site_ids'] !== [], 422, 'The authorised person or own-session scope determines the Site.');
        $sites = new UserSiteAccessService;
        $context = ['actor' => $actor, 'domain' => $source['domain']];
        if ($source['domain'] === 'fleet') {
            $siteIds = $sites->accessibleSiteIds($actor, ['fleet.manage']);
            if (in_array($definition['source'], ['costs', 'finance_bills', 'resource_costs'])) {
                $siteIds = array_values(array_intersect($siteIds, app(VehicleFinanceService::class)->financeSiteIds($actor)));
                abort_unless($actor->canDo('finance.assets.view') && $actor->canDo('finance.ap.view'), 403);
            }
            abort_if(array_diff($definition['site_ids'], $siteIds), 403);
            $selected = $definition['site_ids'] ?: $siteIds;
            $ids = FleetTripSiteScope::vehicles($selected)->whereIn('assets.id',
                app(SecurityDevicesAccessService::class)->reportVehiclesForFleet($actor)->select('assets.id')
            )->orderBy('assets.id')->pluck('assets.id')->map(fn ($id) => (int) $id)->all();
            if (in_array($definition['source'], ['maintenance', 'resources', 'obligations', 'custody', 'stocktakes', 'downtime', 'finance_bills'])) {
                $ids = app(SecurityDevicesAccessService::class)->reportAssets($actor)
                    ->where(fn ($q) => $q->whereIn('site_id', $selected)->orWhere(fn ($fallback) => $fallback->whereNull('site_id')->whereIn('home_site_id', $selected)))
                    ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
            }
            abort_if(array_diff($definition['resource_ids'], $ids), 403);
            $context += ['site_ids' => $selected, 'asset_ids' => $definition['resource_ids'] ?: $ids];
            $authority = [$selected, $context['asset_ids']];
        } elseif ($source['domain'] === 'client') {
            abort_unless($definition['subject_id'] ?? null, 422, 'Choose an authorised client.');
            $client = Client::findOrFail($definition['subject_id']);
            $assignment = app(ClientLocationAccessService::class)->resolve($actor, $client);
            $context += ['client' => $client, 'assignment' => $assignment, 'site_ids' => [(int) $assignment->custody_site_id]];
            $authority = [$client->id, app(ClientLocationAccessService::class)->fingerprint($assignment)];
        } elseif ($source['domain'] === 'staff') {
            $siteIds = $sites->accessibleHealthSafetySiteIds($actor);
            abort_if(array_diff($definition['site_ids'], $siteIds), 403);
            $context['site_ids'] = $definition['site_ids'] ?: $siteIds;
            $query = app(LoneWorkerSessionScope::class)->apply(LoneWorkerSession::query(), $actor);
            if ($definition['subject_id'] ?? null) {
                $session = $query->findOrFail($definition['subject_id']);
                $sessionSite = $session->site_id ?? $session->client?->site_id ?? $session->shift?->site_id;
                abort_unless(in_array((int) $sessionSite, $context['site_ids'], true), 403);
                $context['session'] = $session;
                $authority = [$context['site_ids'], $session->only(['id', 'user_id', 'site_id', 'client_id', 'shift_id', 'started_at', 'ended_at', 'status', 'updated_at'])];
                if (in_array($definition['source'], ['staff_locations', 'staff_readiness'])) {
                    $assignments = DeviceAssignment::where('assignable_type', 'staff')->where('assignable_id', $session->user_id)->orderBy('id')->get();
                    $related = DeviceAssignment::whereIn('device_id', $assignments->pluck('device_id'))->orderBy('id')->get();
                    $authority[] = hash('sha256', json_encode($related->map(fn ($item) => $item->getAttributes())->all(), JSON_THROW_ON_ERROR));
                }
            } else {
                abort_if(in_array($definition['source'], ['staff_locations', 'staff_readiness']), 422, 'Choose an authorised safety session.');
                $authority = [$context['site_ids'], $query->orderBy('id')->get(['id', 'user_id', 'site_id', 'client_id', 'shift_id', 'updated_at'])->toArray()];
            }
        } else {
            $context['site_ids'] = [];
            $authority = [$actor->id];
        }
        // Includes all current source permissions, not a creator's credentials.
        $context['fingerprint'] = hash_hmac('sha256', json_encode([$definition['source'], array_keys($sources), $authority], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return $context;
    }

    public function recheck(User $actor, array $definition, string $fingerprint): void
    {
        abort_unless(hash_equals($fingerprint, $this->context($actor, $definition)['fingerprint']), 403, 'Report access changed. Run the report again.');
    }
}
