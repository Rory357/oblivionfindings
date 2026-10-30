<?php

namespace App\Services\Fleet;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * People who can own a vehicle's follow-ups: current, approved staff placed
 * at the vehicle's site. Used for reminder, schedule and vehicle owners.
 */
class VehicleStaffDirectory
{
    private const LIMIT = 50;

    /** @return Collection<int, array{id:int,name:string}> */
    public function candidates(Asset $asset, ?string $search = null, array $includeIds = []): Collection
    {
        $siteId = $this->siteId($asset);
        if (! $siteId) {
            return collect();
        }
        $search = trim((string) $search);
        $people = $this->query($siteId)
            ->when($search !== '', fn (Builder $query) => $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('name')->orderBy('id')->limit(self::LIMIT)->get(['id', 'name']);
        $missing = array_values(array_diff(array_filter(array_map('intval', $includeIds)), $people->pluck('id')->all()));
        if ($missing !== []) {
            $people = $people->concat($this->query($siteId)->whereKey($missing)->get(['id', 'name']));
        }

        return $people->map(fn (User $user): array => ['id' => (int) $user->id, 'name' => (string) $user->name])->values();
    }

    public function isCandidate(Asset $asset, int $userId): bool
    {
        $siteId = $this->siteId($asset);

        return $siteId !== null && $this->query($siteId)->whereKey($userId)->exists();
    }

    private function query(int $siteId): Builder
    {
        $today = today()->toDateString();

        return User::query()->whereNotNull('approved_at')
            ->whereIn('id', HrEmployeeProfile::query()->select('user_id')->where('is_active', true)
                ->where(fn ($dates) => $dates->whereNull('start_date')->orWhereDate('start_date', '<=', $today))
                ->where(fn ($dates) => $dates->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
                ->where(fn ($sites) => $sites->where('primary_site_id', $siteId)->orWhereJsonContains('secondary_site_ids', $siteId)));
    }

    private function siteId(Asset $asset): ?int
    {
        if ($asset->site_id || $asset->home_site_id) {
            return (int) ($asset->site_id ?: $asset->home_site_id);
        }

        return $asset->client_id
            ? ((int) DB::table('clients')->where('id', $asset->client_id)->value('site_id') ?: null)
            : null;
    }
}
