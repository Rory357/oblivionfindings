<?php

namespace App\Services\Assets;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\AssetStocktake;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class AssetStocktakeService
{
    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    public function visibleQuery(User $user)
    {
        return AssetStocktake::whereIn('site_id', $this->access->accessibleSiteIds($user))
            ->whereNotExists(function ($query) use ($user) {
                $query->selectRaw('1')->from('asset_stocktake_asset_refs')
                    ->whereColumn('asset_stocktake_asset_refs.asset_stocktake_id', 'asset_stocktakes.id')
                    ->whereNotIn('asset_id', $this->access->accessibleAssets($user)->select('assets.id'));
            });
    }

    private function syncReferences(AssetStocktake $count): void
    {
        DB::table('asset_stocktake_asset_refs')->where('asset_stocktake_id', $count->id)->delete();
        $rows = collect($count->entries)->pluck('asset_id')->filter()->unique()->map(fn ($id) => ['asset_stocktake_id' => $count->id, 'asset_id' => $id])->all();
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('asset_stocktake_asset_refs')->insert($chunk);
        }
    }

    public function visible(User $user, AssetStocktake $count, bool $write = false): void
    {
        Gate::forUser($user)->authorize('viewAny', Asset::class);
        abort_unless($this->access->accessibleSites($user)->whereKey($count->site_id)->exists(), 404);
        $ids = collect($count->entries)->pluck('asset_id')->filter()->unique()->values();
        abort_unless($this->access->accessibleAssets($user)->whereKey($ids)->count() === $ids->count(), 404);
        if ($write) {
            abort_unless($user->canDo('assets.scan.record'), 403);
        }
    }

    public function create(User $user, array $data): AssetStocktake
    {
        Gate::forUser($user)->authorize('viewAny', Asset::class);
        abort_unless($user->canDo('assets.scan.record'), 403);

        return DB::transaction(function () use ($user, $data) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($existing = AssetStocktake::where('request_id', $data['request_id'])->first()) {
                abort_unless($existing->created_by_user_id === $user->id, 404);
                $this->visible($user, $existing);

                return $existing;
            }
            $site = $this->access->accessibleSites($user)->whereKey($data['site_id'])->firstOrFail();
            $room = empty($data['site_room_id']) ? null : SiteRoom::where('site_id', $site->id)->findOrFail($data['site_room_id']);
            $assets = $this->access->accessibleAssets($user)->where('site_id', $site->id)->where('status', '!=', 'retired')
                ->when($room, fn ($q) => $q->where('site_room_id', $room->id))
                ->when(! empty($data['asset_ids']), fn ($q) => $q->whereKey($data['asset_ids']))
                ->with('canonicalRoom')->orderBy('id')->limit(2001)->get();
            if (! empty($data['asset_ids'])) {
                abort_unless($assets->count() === count(array_unique($data['asset_ids'])), 422, 'Some selected assets are outside this location.');
            }
            abort_if($assets->count() > 2000, 422, 'Choose a room or fewer than 2,001 assets for one count.');
            $count = AssetStocktake::create([
                'request_id' => $data['request_id'], 'site_id' => $site->id, 'site_room_id' => $room?->id,
                'created_by_user_id' => $user->id, 'title' => $data['title'] ?: ($room?->name ?? $site->name).' stocktake',
                'counted_at' => $data['counted_at'] ?? now(),
                'scope' => ['site' => $site->name, 'room' => $room?->name, 'counter' => $user->name,
                    'rooms' => $room ? [['id' => $room->id, 'name' => $room->name]] : SiteRoom::where('site_id', $site->id)
                        ->when(! empty($data['asset_ids']), fn ($q) => $q->whereIn('id', $assets->pluck('site_room_id')->filter()))
                        ->orderBy('name')->get(['id', 'name'])->toArray(),
                    'selected_only' => ! empty($data['asset_ids'])],
                'entries' => $assets->map(fn ($asset) => $this->snapshot($asset))->all(),
                'activity' => [['id' => $data['request_id'], 'action' => 'Started count', 'actor' => $user->name, 'at' => now()->toISOString()]],
            ]);
            AuditLogger::logOrFail('assets.stocktake.started', $count, ['site_id' => $site->id]);
            $this->syncReferences($count);

            return $count;
        }, 3);
    }

    public function snapshot(Asset $asset, bool $expected = true): array
    {
        return ['key' => 'asset-'.$asset->id, 'asset_id' => $asset->id, 'name' => $asset->name,
            'asset_tag' => $asset->asset_tag, 'serial_number' => $asset->serial_number,
            'site_id' => $asset->site_id, 'site_room_id' => $asset->site_room_id, 'room' => $asset->canonicalRoom?->name,
            'asset_status' => $asset->status, 'expected' => $expected, 'result' => 'pending', 'note' => '',
            'source' => null, 'observed_at' => null, 'actor' => null, 'changed' => false];
    }

    public function resolve(User $user, AssetStocktake $count, string $payload): array
    {
        $this->visible($user, $count, true);
        abort_unless($count->status === 'draft', 409, 'This stocktake is complete.');
        $payload = trim($payload);
        $token = $payload;
        $isUrl = str_contains($payload, '://');
        if ($isUrl) {
            $url = parse_url($payload);
            $origin = parse_url(config('app.url'));
            abort_unless($url && ($url['scheme'] ?? '') === ($origin['scheme'] ?? '') && strtolower($url['host'] ?? '') === strtolower($origin['host'] ?? '')
                && ($url['port'] ?? null) === ($origin['port'] ?? null) && ! isset($url['user']) && ! isset($url['pass'])
                && ! isset($url['query']) && ! isset($url['fragment'])
                && preg_match('#^/assets/qr/([A-Za-z0-9_-]{1,64})$#D', $url['path'] ?? '', $match), 422, 'Use an Asset QR label from this application.');
            $token = $match[1];
        }
        abort_unless($isUrl || preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.\-]{0,99}$/D', $token), 422, 'Use an Asset QR label or its printed tag.');
        $matches = $this->access->accessibleAssets($user)->with('canonicalRoom')
            ->where(fn ($q) => $q->where('qr_token', $token)->when(! $isUrl, fn ($q) => $q->orWhereRaw('LOWER(asset_tag) = ?', [mb_strtolower($token)])))
            ->limit(2)->get()->filter(fn ($asset) => $asset->qr_token === $token || (! $isUrl && mb_strtolower((string) $asset->asset_tag) === mb_strtolower($token)));
        abort_if($matches->count() > 1, 422, 'More than one asset uses this tag. Scan the unique QR label or choose the item from the checklist.');
        $asset = $matches->first();
        // Unknown and inaccessible labels deliberately have the same response.
        if (! $asset) {
            return ['state' => 'unknown'];
        }
        $entry = collect($count->entries)->firstWhere('asset_id', $asset->id);

        return ['state' => $entry ? ($entry['result'] === 'found' ? 'duplicate' : 'expected') : 'extra',
            'entry' => $entry ?? $this->snapshot($asset, false)];
    }

    public function change(User $user, AssetStocktake $count, array $data): AssetStocktake
    {
        return DB::transaction(function () use ($user, $count, $data) {
            $count = AssetStocktake::whereKey($count->id)->lockForUpdate()->firstOrFail();
            $this->visible($user, $count, true);
            if (collect($count->activity)->contains('id', $data['command_id'])) {
                return $count;
            }
            abort_unless($count->status === 'draft', 409, 'Completed stocktakes cannot be edited.');
            abort_unless($count->version === $data['version'], 409, 'This count changed in another window. Reload the saved count before continuing.');
            abort_if(count($count->activity) >= 10000, 422, 'This count has reached its activity limit. Finish it and start another count.');
            $entries = $count->entries;
            $action = $data['action'];
            $event = ['id' => $data['command_id'], 'action' => $action, 'actor' => $user->name, 'at' => now()->toISOString(), 'source' => $data['source'] ?? 'Manual'];
            if ($action === 'review' || $action === 'finish') {
                $count->review_note = $data['review_note'] ?? '';
                $owner = empty($data['follow_up_user_id']) ? null : $this->access->assignableStaff($user)->findOrFail($data['follow_up_user_id']);
                $count->follow_up_user_id = $owner?->id;
                $count->follow_up_name = $owner?->name;
            } elseif ($action === 'unknown') {
                abort_if(count($entries) >= 2500, 422, 'This count has reached its item limit.');
                $key = 'unknown-'.$data['command_id'];
                $entries[] = ['key' => $key, 'asset_id' => null, 'name' => 'Unrecognised label', 'asset_tag' => null, 'serial_number' => null,
                    'expected' => false, 'result' => 'review', 'note' => $data['note'] ?? '', 'source' => $data['source'] ?? 'Manual',
                    'observed_at' => now()->toISOString(), 'actor' => $user->name, 'changed' => false, 'room' => $count->scope['room']];
                $event['key'] = $key;
            } else {
                $index = array_search($data['key'] ?? '', array_column($entries, 'key'), true);
                if ($index === false && $action === 'found' && ! empty($data['asset_id']) && ($data['confirm_extra'] ?? false)) {
                    $asset = $this->access->accessibleAssets($user)->with('canonicalRoom')->findOrFail($data['asset_id']);
                    abort_unless(($data['key'] ?? '') === 'asset-'.$asset->id && ! collect($entries)->contains('asset_id', $asset->id), 422, 'This asset is already in the checklist.');
                    abort_if(count($entries) >= 2500, 422, 'This count has reached its item limit.');
                    $entries[] = $this->snapshot($asset, false);
                    $index = count($entries) - 1;
                }
                abort_if($index === false, 422, 'Choose an item in this count, or confirm an extra asset.');
                $event['key'] = $entries[$index]['key'];
                $event['name'] = $entries[$index]['name'];
                $event['previous'] = $entries[$index];
                if ($action === 'undo') {
                    $prior = collect($count->activity)->reverse()->first(fn ($e) => ($e['key'] ?? '') === $entries[$index]['key'] && $e['action'] !== 'duplicate');
                    abort_unless($prior && ($prior['action'] ?? '') !== 'undo', 422, 'There is no answer to undo.');
                    if ($prior['action'] === 'unknown' || (! $entries[$index]['expected'] && ($prior['previous']['result'] ?? '') === 'pending')) {
                        array_splice($entries, $index, 1);
                    } else {
                        $entries[$index] = $prior['previous'];
                    }
                } elseif ($action === 'note') {
                    abort_if($entries[$index]['result'] === 'pending', 422, 'Record an answer before adding an observation note.');
                    $entries[$index]['note'] = $data['note'] ?? '';
                } elseif ($action === 'duplicate') {
                    abort_unless($entries[$index]['result'] === 'found', 422);
                    unset($event['previous']);
                } else {
                    $entries[$index] = [...$entries[$index], 'result' => $action, 'note' => $data['note'] ?? $entries[$index]['note'],
                        'source' => $data['source'] ?? 'Manual', 'actor' => $user->name, 'observed_at' => now()->toISOString()];
                }
            }
            if ($action === 'finish') {
                if (collect($entries)->contains('result', 'pending')) {
                    throw ValidationException::withMessages(['count' => 'Mark every item Found or Not found before finishing.']);
                }
                $assets = $this->access->accessibleAssets($user)->whereKey(collect($entries)->pluck('asset_id')->filter())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                abort_unless($assets->count() === collect($entries)->pluck('asset_id')->filter()->unique()->count(), 404);
                foreach ($entries as &$entry) {
                    $asset = $assets->get($entry['asset_id']);
                    $entry['changed'] = $asset && ((int) $entry['site_id'] !== (int) $asset->site_id || (int) $entry['site_room_id'] !== (int) $asset->site_room_id || $entry['asset_status'] !== $asset->status);
                }
                unset($entry);
                $rooms = collect($count->scope['rooms'])->pluck('id');
                $observedRooms = collect($entries)->filter(fn ($e) => $e['expected'] && $e['result'] === 'found')->pluck('site_room_id');
                $uncheckedRooms = $rooms->diff($observedRooms)->diff($data['confirmed_room_ids'] ?? []);
                if ($uncheckedRooms->isNotEmpty() || (empty($entries) && ! ($data['confirm_empty'] ?? false))) {
                    throw ValidationException::withMessages(['rooms' => 'Confirm that the rooms with no found assets have been checked.']);
                }
                $issues = collect($entries)->contains(fn ($e) => $e['result'] !== 'found' || ! $e['expected'] || $e['changed']);
                if ($issues && (blank($count->review_note) || ! $count->follow_up_user_id)) {
                    throw ValidationException::withMessages(['review_note' => 'Add a review note and choose who will follow up the differences.']);
                }
                if (collect($entries)->contains('changed', true) && ! ($data['acknowledge_changes'] ?? false)) {
                    throw ValidationException::withMessages(['changes' => 'An asset assignment or status changed during this count. Review and acknowledge the changes before finishing.']);
                }
                $count->status = 'completed';
                $count->completed_at = now();
                $event['confirmed_rooms'] = collect($count->scope['rooms'])->whereIn('id', $data['confirmed_room_ids'] ?? [])->pluck('name')->values()->all();
                $event['confirmed_empty'] = (bool) ($data['confirm_empty'] ?? false);
                $event['acknowledged_changes'] = (bool) ($data['acknowledge_changes'] ?? false);
                AuditLogger::logOrFail('assets.stocktake.completed', $count, ['site_id' => $count->site_id, 'items' => count($entries)]);
            }
            $count->entries = $entries;
            $count->activity = [...$count->activity, $event];
            $count->version++;
            $count->save();
            $this->syncReferences($count);

            return $count;
        }, 3);
    }

    public function present(AssetStocktake $count): array
    {
        $data = $count->toArray();
        if ($count->status === 'draft') {
            $assets = Asset::whereKey(collect($count->entries)->pluck('asset_id')->filter())->get()->keyBy('id');
            $data['entries'] = array_map(function ($entry) use ($assets) {
                $asset = $assets->get($entry['asset_id']);
                $entry['changed'] = $asset && ((int) ($entry['site_id'] ?? 0) !== (int) $asset->site_id || (int) ($entry['site_room_id'] ?? 0) !== (int) $asset->site_room_id || ($entry['asset_status'] ?? '') !== $asset->status);

                return $entry;
            }, $count->entries);
        }
        // Internal undo snapshots are never needed by a viewer or report.
        $data['activity'] = array_map(fn ($e) => array_diff_key($e, ['previous' => true]), $count->activity);

        return $data;
    }
}
