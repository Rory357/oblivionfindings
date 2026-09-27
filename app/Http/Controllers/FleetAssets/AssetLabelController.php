<?php

namespace App\Http\Controllers\FleetAssets;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetLabelBatch;
use App\Models\Site;
use App\Models\User;
use App\Services\Assets\AssetLabelExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;

class AssetLabelController extends Controller
{
    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    public function workspace(Request $request)
    {
        $this->authorize('viewAny', Asset::class);
        $filters = $request->validate(['search' => 'nullable|string|max:120', 'site_id' => 'nullable|integer', 'category' => 'nullable|string|max:60', 'status' => 'nullable|in:active,out_of_service,retired', 'selected' => 'nullable|integer']);
        $query = $this->access->accessibleAssets($request->user())->with('site:id,name')
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($text) => $text->where('name', 'like', '%'.$search.'%')->orWhere('asset_tag', 'like', '%'.$search.'%')->orWhere('serial_number', 'like', '%'.$search.'%')))
            ->when($filters['site_id'] ?? null, fn ($q, $site) => $q->where('site_id', $site))
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        $map = fn ($asset) => ['id' => $asset->id, 'name' => $asset->name, 'tag' => $asset->asset_tag ?: 'AS-'.$asset->id, 'site' => $asset->site?->name, 'status' => $asset->status, 'has_qr' => (bool) $asset->qr_token];
        $selected = ! empty($filters['selected']) ? $this->access->accessibleAssets($request->user())->with('site:id,name')->find($filters['selected']) : null;

        return Inertia::render('fleet-assets/assets/labels', [
            'assets' => (clone $query)->orderBy('asset_tag')->paginate(30)->withQueryString()->through($map),
            'matching' => (clone $query)->orderBy('id')->limit(201)->get()->map($map)->values(),
            'filters' => $filters, 'initialSelection' => $selected ? [$map($selected)] : [],
            'sites' => Site::whereIn('id', $this->access->accessibleSiteIds($request->user()))->orderBy('name')->get(['id', 'name']),
            'categories' => $this->access->accessibleAssets($request->user())->select('category')->distinct()->orderBy('category')->pluck('category'),
            'batches' => AssetLabelBatch::where('created_by_user_id', $request->user()->id)->latest()->limit(20)->get()->map(fn ($batch) => [
                'id' => $batch->id, 'count' => count($batch->asset_ids), 'layout' => $batch->layout, 'status' => $batch->status,
                'created_at' => $batch->created_at->toISOString(), 'expired' => $batch->expires_at->isPast(), 'expires_at' => $batch->expires_at->toISOString(),
            ]),
        ]);
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Asset::class);

        return AssetLabelBatch::where('created_by_user_id', $request->user()->id)->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))->when($request->input('status') === 'ready', fn ($q) => $q->where('expires_at', '>', now()))->latest()->paginate(15);
    }

    public function store(Request $request)
    {
        $this->authorize('viewAny', Asset::class);
        $data = $request->validate(['request_id' => 'required|uuid', 'asset_ids' => 'required|array|min:1|max:200', 'asset_ids.*' => 'required|integer|distinct',
            'layout' => 'required|array:width,height,margin,gap,copies,start,paper,logo', 'layout.width' => 'required|numeric|min:50|max:190', 'layout.height' => 'required|numeric|min:46|max:277',
            'layout.paper' => 'sometimes|in:a4,label', 'layout.logo' => 'sometimes|boolean',
            'layout.margin' => 'required|numeric|min:0|max:30', 'layout.gap' => 'required|numeric|min:0|max:15',
            'layout.copies' => 'required|integer|min:1|max:20', 'layout.start' => 'required|integer|min:1|max:100']);
        $layout = ['paper' => $data['layout']['paper'] ?? 'a4', 'logo' => (bool) ($data['layout']['logo'] ?? true)];
        foreach (['width', 'height', 'margin', 'gap'] as $key) {
            $layout[$key] = (float) $data['layout'][$key];
        }
        foreach (['copies', 'start'] as $key) {
            $layout[$key] = (int) $data['layout'][$key];
        }
        $sheet = $layout['paper'] === 'a4';
        abort_if($sheet && $layout['margin'] < 5, 422, 'Use at least 5 mm sheet margins.');
        if (! $sheet) {
            $layout['margin'] = 0;
            $layout['gap'] = 0;
            $layout['start'] = 1;
        }
        $columns = $sheet ? (int) floor((210 - 2 * $layout['margin'] + $layout['gap']) / ($layout['width'] + $layout['gap'])) : 1;
        $rows = $sheet ? (int) floor((297 - 2 * $layout['margin'] + $layout['gap']) / ($layout['height'] + $layout['gap'])) : 1;
        abort_unless($columns > 0 && $rows > 0 && $layout['start'] <= $columns * $rows, 422, 'This label layout does not fit an A4 sheet.');
        abort_if(count($data['asset_ids']) * $layout['copies'] > 1000, 422, 'Generate up to 1,000 labels per batch.');
        $data['asset_ids'] = array_map('intval', $data['asset_ids']);
        sort($data['asset_ids']);

        return DB::transaction(function () use ($request, $data, $layout, $columns, $rows) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if ($batch = AssetLabelBatch::where('request_id', $data['request_id'])->first()) {
                abort_unless($batch->created_by_user_id === $request->user()->id, 404);
                abort_unless($batch->asset_ids === $data['asset_ids'] && $batch->layout == [...$layout, 'columns' => $columns, 'rows' => $rows], 409, 'This request was already used for a different selection or layout.');

                return $batch;
            }
            $assets = $this->access->accessibleAssets($request->user())->whereKey($data['asset_ids'])->orderBy('id')->lockForUpdate()->get();
            abort_unless($assets->count() === count($data['asset_ids']), 404);
            foreach ($assets as $asset) {
                if (! $asset->qr_token) {
                    Gate::forUser($request->user())->authorize('update', $asset);
                    $asset->update(['qr_token' => Str::random(32)]);
                }
            }

            return AssetLabelBatch::create(['request_id' => $data['request_id'], 'created_by_user_id' => $request->user()->id,
                'asset_ids' => $assets->modelKeys(), 'layout' => [...$layout, 'columns' => $columns, 'rows' => $rows],
                'downloads' => [], 'expires_at' => now()->addDays(7)]);
        }, 3);
    }

    public function download(Request $request, AssetLabelBatch $batch, string $format, AssetLabelExporter $exporter)
    {
        $this->authorize('viewAny', Asset::class);
        abort_unless($batch->created_by_user_id === $request->user()->id, 404);
        abort_if($batch->expires_at->isPast(), 410, 'This label batch expired. Generate a new batch from the current register.');
        abort_unless(in_array($format, ['pdf', 'zip']), 404);
        $assets = $this->access->accessibleAssets($request->user())->whereKey($batch->asset_ids)->orderBy('id')->get();
        abort_unless($assets->count() === count($batch->asset_ids), 404);
        try {
            $bytes = $exporter->bytes($assets, $batch->layout, $format);
            $this->record($batch, $format, 'generated');
        } catch (\Throwable $e) {
            $this->record($batch, $format, 'failed');
            throw $e;
        }

        return response($bytes, 200, ['Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/zip',
            'Content-Disposition' => 'attachment; filename="asset-labels-'.$batch->id.'.'.$format.'"', 'Cache-Control' => 'no-store, private']);
    }

    private function record(AssetLabelBatch $batch, string $format, string $status): void
    {
        DB::transaction(function () use ($batch, $format, $status) {
            $batch = AssetLabelBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $batch->downloads = array_slice([...$batch->downloads, ['format' => $format, 'status' => $status, 'at' => now()->toISOString()]], -100);
            $batch->status = $status === 'failed' ? 'failed' : 'ready';
            $batch->save();
        });
    }
}
