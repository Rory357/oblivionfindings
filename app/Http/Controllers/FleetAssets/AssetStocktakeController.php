<?php

namespace App\Http\Controllers\FleetAssets;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetStocktake;
use App\Models\SiteRoom;
use App\Services\Assets\AssetStocktakeExporter;
use App\Services\Assets\AssetStocktakeService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class AssetStocktakeController extends Controller
{
    public function __construct(private readonly AssetStocktakeService $stocktakes, private readonly SecurityDevicesAccessService $access) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Asset::class);
        $request->validate(['site_id' => 'nullable|integer|min:1', 'status' => 'nullable|in:draft,completed,followups',
            'workspace' => 'nullable|in:counts,followups,coverage', 'search' => 'nullable|string|max:160']);
        $visible = $this->stocktakes->visibleQuery($request->user());
        $summary = ['counts' => (clone $visible)->count(), 'followups' => 0, 'sites' => $this->access->accessibleSites($request->user())->count()];
        $coverage = $this->access->accessibleSites($request->user())->orderBy('name')->get(['id', 'name'])
            ->mapWithKeys(fn ($site) => [$site->id => ['id' => $site->id, 'name' => $site->name, 'counts' => 0, 'checked_room_ids' => [], 'last_completed_at' => null]]);
        $followups = collect();
        $workspace = $request->input('workspace', 'counts');
        $page = max(1, $request->integer('page', 1));
        $matchedFollowups = 0;
        $matches = fn ($row) => (! $request->integer('site_id') || ($row['site_id'] ?? $row['id']) === $request->integer('site_id'))
            && (! $request->filled('search') || str_contains(mb_strtolower(implode(' ', array_filter($row, fn ($v) => is_scalar($v)))), mb_strtolower($request->input('search'))));
        // Stream snapshots without activity histories; retain only this page's
        // follow-ups so a long-running multi-site register stays memory bounded.
        foreach ((clone $visible)->select(['id', 'site_id', 'scope', 'entries', 'title', 'follow_up_name', 'review_note', 'completed_at'])->where('status', 'completed')->latest('completed_at')->cursor() as $count) {
            $scope = $count->scope;
            $site = $coverage->get($count->site_id);
            if ($site) {
                $site['counts']++;
                $site['last_completed_at'] ??= $count->completed_at?->toISOString();
                if (empty($scope['selected_only'])) {
                    $site['checked_room_ids'] = array_values(array_unique([...$site['checked_room_ids'], ...collect($scope['rooms'] ?? [])->pluck('id')->all()]));
                }
                $coverage->put($count->site_id, $site);
            }
            foreach ($count->entries as $entry) {
                if ($entry['result'] !== 'missing' && $entry['expected'] && ! $entry['changed']) {
                    continue;
                }
                $summary['followups']++;
                if ($workspace !== 'followups') {
                    continue;
                }
                $row = ['id' => $count->id.'-'.$entry['key'], 'stocktake_id' => $count->id,
                    'title' => $count->title, 'site_id' => $count->site_id, 'site' => $scope['site'], 'room' => $entry['room'] ?? $scope['room'],
                    'name' => $entry['name'], 'asset_tag' => $entry['asset_tag'] ?? null,
                    'reason' => $entry['changed'] ? 'Assignment changed' : (! $entry['expected'] ? 'Unexpected item' : 'Not found'),
                    'owner' => $count->follow_up_name, 'note' => $count->review_note, 'completed_at' => $count->completed_at?->toISOString()];
                if ($matches($row)) {
                    $matchedFollowups++;
                    if ($matchedFollowups > ($page - 1) * 20 && $matchedFollowups <= $page * 20) {
                        $followups->push($row);
                    }
                }
            }
        }
        $rooms = SiteRoom::whereIn('site_id', $coverage->keys())->get(['id', 'site_id'])->groupBy('site_id');
        $coverage = $coverage->map(function ($site) use ($rooms) {
            $ids = $rooms->get($site['id'], collect())->pluck('id');
            $site['rooms'] = $ids->count();
            $site['checked_rooms'] = $ids->intersect($site['checked_room_ids'])->count();
            unset($site['checked_room_ids']);

            return $site;
        });
        $resume = (clone $visible)->where('status', 'draft')->latest('updated_at')->first();
        if ($workspace === 'followups') {
            $result = new LengthAwarePaginator($followups, $matchedFollowups, 20, $page);
        } elseif ($workspace === 'coverage') {
            $rows = $coverage->values()->filter($matches)->values();
            $result = new LengthAwarePaginator($rows->forPage($page, 20)->values(), $rows->count(), 20, $page);
        } else {
            $query = (clone $visible)
                ->when($request->integer('site_id'), fn ($q, $id) => $q->where('site_id', $id))
                ->when($request->input('status') === 'followups', fn ($q) => $q->where('status', 'completed')->where(fn ($q) => $q->whereJsonContains('entries', ['result' => 'missing'])->orWhereJsonContains('entries', ['expected' => false])->orWhereJsonContains('entries', ['changed' => true])),
                    fn ($q) => $q->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status'))))
                ->when($request->string('search')->toString(), fn ($q, $search) => $q->where(function ($q) use ($search) {
                    $q->where('title', 'like', '%'.$search.'%')->orWhere('scope->site', 'like', '%'.$search.'%')->orWhere('scope->counter', 'like', '%'.$search.'%');
                    if (preg_match('/^(?:ST-)?(\d+)$/i', $search, $match)) {
                        $q->orWhere('id', (int) $match[1]);
                    }
                }))->latest('updated_at');
            $result = $query->paginate(20)->through(fn ($count) => $this->summary($count));
        }

        return response()->json([...$result->toArray(), 'summary' => $summary, 'resume' => $resume ? $this->summary($resume) : null]);
    }

    private function summary(AssetStocktake $count): array
    {
        return [
            'id' => $count->id, 'title' => $count->title, 'site' => $count->scope['site'], 'room' => $count->scope['room'],
            'status' => $count->status, 'updated_at' => $count->updated_at, 'counter' => $count->scope['counter'],
            'total' => count($count->entries), 'answered' => collect($count->entries)->where('result', '!=', 'pending')->count(),
            'differences' => collect($count->entries)->filter(fn ($e) => $e['result'] === 'missing' || ! $e['expected'] || $e['changed'])->count(),
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate(['request_id' => 'required|uuid', 'title' => 'nullable|string|max:160', 'site_id' => 'required|integer',
            'site_room_id' => 'nullable|integer', 'counted_at' => 'nullable|date|before_or_equal:now',
            'asset_ids' => 'sometimes|array|max:2000', 'asset_ids.*' => 'integer|distinct']);
        $data['title'] ??= '';

        return response()->json($this->stocktakes->present($this->stocktakes->create($request->user(), $data)), 201);
    }

    public function checklist(Request $request)
    {
        $this->authorize('viewAny', Asset::class);
        $data = $request->validate(['site_id' => 'required|integer|min:1', 'site_room_id' => 'nullable|integer|min:1', 'asset_ids' => 'sometimes|array|max:2000', 'asset_ids.*' => 'integer|distinct']);
        $site = $this->access->accessibleSites($request->user())->findOrFail($data['site_id']);
        if (! empty($data['site_room_id'])) {
            SiteRoom::where('site_id', $site->id)->findOrFail($data['site_room_id']);
        }
        $query = $this->access->accessibleAssets($request->user())->where('site_id', $site->id)->where('status', '!=', 'retired')
            ->when(! empty($data['site_room_id']), fn ($q) => $q->where('site_room_id', $data['site_room_id']))
            ->when(! empty($data['asset_ids']), fn ($q) => $q->whereKey($data['asset_ids']));

        return ['total' => (clone $query)->count(), 'assets' => $query->orderBy('name')->limit(8)->get(['id', 'name', 'asset_tag'])];
    }

    public function show(Request $request, AssetStocktake $stocktake)
    {
        $this->stocktakes->visible($request->user(), $stocktake);

        return $this->stocktakes->present($stocktake);
    }

    public function update(Request $request, AssetStocktake $stocktake)
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'command_id' => 'required|uuid',
            'action' => 'required|in:found,missing,undo,duplicate,unknown,note,review,finish', 'key' => 'nullable|string|max:80',
            'asset_id' => 'nullable|integer', 'confirm_extra' => 'boolean', 'source' => 'nullable|in:Manual,USB scanner,Keyboard / scanner,Camera,QR image',
            'note' => 'nullable|string|max:2000', 'review_note' => 'nullable|string|max:5000', 'follow_up_user_id' => 'nullable|integer',
            'confirmed_room_ids' => 'sometimes|array|max:2000', 'confirmed_room_ids.*' => 'integer', 'confirm_empty' => 'boolean', 'acknowledge_changes' => 'boolean']);

        return $this->stocktakes->present($this->stocktakes->change($request->user(), $stocktake, $data));
    }

    public function resolve(Request $request, AssetStocktake $stocktake)
    {
        $data = $request->validate(['payload' => 'required|string|max:2048']);

        return $this->stocktakes->resolve($request->user(), $stocktake, $data['payload']);
    }

    public function export(Request $request, AssetStocktake $stocktake, string $format, AssetStocktakeExporter $exporter)
    {
        $this->stocktakes->visible($request->user(), $stocktake);
        abort_unless($stocktake->status === 'completed', 422, 'Finish the stocktake before exporting its report.');
        abort_unless(in_array($format, ['pdf', 'xlsx']), 404);

        return $exporter->download($stocktake, $format);
    }
}
