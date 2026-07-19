<?php

namespace App\Domain\SecurityDevices\Presenters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class WorkspacePresenter
{
    public function present(Builder $scope, array $config, array $activeTab): array
    {
        $deviceCount = (clone $scope)->count();
        $monitoredCount = (clone $scope)
            ->whereHas('monitors', fn (Builder $monitor) => $monitor->where('is_enabled', true))
            ->count();
        $latestObservation = (clone $scope)->max('last_seen_at');

        return [
            'slug' => $config['slug'],
            'title' => $config['title'],
            'description' => $config['description'],
            'canonicalHref' => $config['canonicalHref'],
            'activeTab' => $activeTab['key'],
            'activeTabState' => $activeTab['state'],
            'tabs' => collect($config['tabs'])->map(fn (array $tab) => [
                'key' => $tab['key'],
                'label' => $tab['label'],
                'description' => $tab['description'],
                'state' => $tab['state'],
                'stateLabel' => $tab['state'] === 'available' ? 'Available' : 'Not configured',
            ])->values(),
            'summary' => [
                'devices' => $deviceCount,
                'attention' => (clone $scope)->needingAttention()->count(),
                'monitored' => $monitoredCount,
                'unmonitored' => max(0, $deviceCount - $monitoredCount),
            ],
            'freshness' => [
                'state' => $this->freshnessState($latestObservation, $deviceCount),
                'label' => 'Latest device observation',
                'observedAt' => $latestObservation ? Carbon::parse($latestObservation)->toISOString() : null,
                'lastChangedAt' => ($latestChange = (clone $scope)->max('updated_at'))
                    ? Carbon::parse($latestChange)->toISOString()
                    : null,
            ],
        ];
    }

    private function freshnessState(mixed $latestObservation, int $deviceCount): string
    {
        if ($deviceCount === 0 || ! $latestObservation) {
            return 'unknown';
        }

        return Carbon::parse($latestObservation)->lt(now()->subMinutes(15))
            ? 'stale'
            : 'current';
    }
}
