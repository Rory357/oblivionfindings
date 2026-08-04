<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\User;

class IntegrationSiteCredentialsPresenter
{
    public const STATE_CONNECTED = 'connected';

    public const STATE_UNTESTED = 'untested';

    public const STATE_DISABLED = 'disabled';

    public const STATE_ERROR = 'error';

    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    /** @return array<int, array<string, mixed>> */
    public function present(User $viewer, string $provider): array
    {
        $siteIds = $this->access->accessibleSiteIds($viewer);

        return IntegrationSiteSecret::query()
            ->where('provider', $provider)
            ->whereIn('site_id', $siteIds)
            ->whereHas('site')
            ->with('site:id,name')
            ->orderBy('site_id')
            ->orderBy('capability')
            ->get()
            ->map(fn (IntegrationSiteSecret $credential): array => $this->project($credential))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function project(IntegrationSiteSecret $credential): array
    {
        $state = self::state($credential);

        return [
            'id' => $credential->id,
            'site_id' => (int) $credential->site_id,
            'site_name' => $credential->site?->name ?? 'Unknown site',
            'provider' => $credential->provider,
            'capability' => $credential->capability,
            'configured' => true,
            'enabled' => (bool) $credential->is_enabled,
            'tested' => $credential->last_tested_at !== null,
            'state' => $state,
            'failure_category' => $state === self::STATE_ERROR ? 'provider_failure' : null,
            'last_tested_at' => $credential->last_tested_at?->toISOString(),
        ];
    }

    public static function state(IntegrationSiteSecret $credential): string
    {
        return match (true) {
            filled($credential->last_error) => self::STATE_ERROR,
            ! $credential->is_enabled => self::STATE_DISABLED,
            $credential->last_tested_at === null => self::STATE_UNTESTED,
            default => self::STATE_CONNECTED,
        };
    }
}
