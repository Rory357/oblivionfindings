<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\SecurityDevices\Data\NetworkMapFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Collection;

/** Receives the full authorized network query; it can only narrow it. */
final class NetworkMapInventoryQuery
{
    public const LIMIT = 100;

    /** @return array{Collection, array<string, mixed>, NetworkMapFilters} */
    public function page(Builder $scope, NetworkMapFilters $filters, Collection $sites): array
    {
        $filtered = clone $scope;
        if ($filters->search !== '') {
            $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters->search);
            $filtered->whereRaw("devices.name LIKE ? ESCAPE '!'", ['%'.$literal.'%']);
        }
        $filtered->reorder('id');
        $total = (clone $filtered)->count();
        $page = (clone $filtered)->cursorPaginate(self::LIMIT, ['*'], 'map_device_cursor', $filters->deviceCursor ?? '');
        $devices = $page->getCollection()->values();
        $positionReset = false;
        if ($filters->deviceCursor !== null && $devices->isEmpty()) {
            $filters = $filters->withDeviceCursor(null);
            $page = (clone $filtered)->cursorPaginate(self::LIMIT, ['*'], 'map_device_cursor', '');
            $devices = $page->getCollection()->values();
            $positionReset = true;
        }
        $hasPrevious = $devices->isNotEmpty() && (clone $filtered)->where('id', '<', $devices->first()->id)->exists();
        $hasNext = $devices->isNotEmpty() && (clone $filtered)->where('id', '>', $devices->last()->id)->exists();
        // A seek that lands on the complete first page is normalized to first-page context.
        if (! $hasPrevious && $devices->count() === min($total, self::LIMIT)) {
            $filters = $filters->withDeviceCursor(null);
        }
        $inventory = [
            'pageKey' => $filters->pageKey(), 'siteId' => $filters->siteId,
            'sites' => $sites->map(fn ($site): array => ['id' => (int) $site->id, 'name' => $site->name])->values(),
            'search' => $filters->search, 'shown' => $devices->count(), 'limit' => self::LIMIT, 'total' => $total,
            'hasMore' => $hasNext,
            'previousHref' => $hasPrevious ? $filters->withDeviceCursor(new Cursor(['id' => (int) $devices->first()->id], false))->href() : null,
            'nextHref' => $hasNext ? $filters->withDeviceCursor(new Cursor(['id' => (int) $devices->last()->id], true))->href() : null,
            'resetHref' => '/security-devices/network-it?tab=map', 'positionReset' => $positionReset,
            'isPartial' => $filters->search !== '' || $total > $devices->count() || $hasPrevious || $hasNext,
        ];

        return [$devices, $inventory, $filters];
    }
}
