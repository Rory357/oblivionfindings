<?php

namespace App\Services\Tracking;

use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class PeopleLocationPreferences
{
    public const DEFAULTS = ['population' => 'both', 'site' => 'all', 'peopleView' => 'cards', 'boundaries' => true];

    public function sites(User $actor): array
    {
        $ids = app(UserSiteAccessService::class)->accessibleSiteIds($actor, ['clinical.accessAllSites', 'sites.viewAll']);

        return Site::query()->whereKey($ids)->where('is_active', true)->where('archived', false)->whereNull('archived_at')->orderBy('name')->get(['id', 'name'])->toArray();
    }

    public function read(User $actor): array
    {
        $record = DB::table('people_location_preferences')->where('user_id', $actor->id)->first();
        $value = array_replace(self::DEFAULTS, $record ? json_decode($record->preferences, true) : []);
        $sites = $this->sites($actor);
        $invalidSite = $value['site'] !== 'all' && ! in_array((int) $value['site'], array_column($sites, 'id'), true);
        if ($invalidSite) {
            $value['site'] = 'all';
        }
        if ($value['population'] === 'staff' && ! $actor->canDo('hazards.manage')) {
            $value['population'] = 'both';
        }

        return ['value' => $value, 'revision' => $record?->revision ?? 0, 'siteUnavailable' => $invalidSite, 'sites' => $sites];
    }

    public function save(User $actor, array $input): array
    {
        return DB::transaction(function () use ($actor, $input) {
            $current = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(PeopleLocationService::class)->mayEnter($current), 403);
            $allowedSites = ['all', ...array_map(fn ($site) => (string) $site['id'], $this->sites($current))];
            $data = Validator::make($input, [
                'revision' => 'required|integer|min:0',
                'population' => ['required', Rule::in($current->canDo('hazards.manage') ? ['both', 'clients', 'staff'] : ['both', 'clients'])],
                'site' => ['required', Rule::in($allowedSites)],
                'peopleView' => 'required|in:cards,list', 'boundaries' => 'required|boolean',
            ])->validate();
            $existing = DB::table('people_location_preferences')->where('user_id', $current->id)->first();
            abort_unless(($existing?->revision ?? 0) === $data['revision'], 409, 'Preferences changed in another window. Reload saved preferences before trying again.');
            unset($data['revision']);
            DB::table('people_location_preferences')->updateOrInsert(['user_id' => $current->id], [
                'preferences' => json_encode($data), 'revision' => ($existing?->revision ?? 0) + 1,
                'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(),
            ]);

            return $this->read($current);
        });
    }
}
