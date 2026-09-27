<?php

namespace App\Http\Controllers\FleetAssets;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetLabelBatch;
use App\Models\User;
use App\Services\Assets\AssetLabelExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AssetLabelController extends Controller
{
    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Asset::class);

        return AssetLabelBatch::where('created_by_user_id', $request->user()->id)->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))->when($request->input('status') === 'ready', fn ($q) => $q->where('expires_at', '>', now()))->latest()->paginate(15);
    }

    public function store(Request $request)
    {
        $this->authorize('viewAny', Asset::class);
        $data = $request->validate(['request_id' => 'required|uuid', 'asset_ids' => 'required|array|min:1|max:200', 'asset_ids.*' => 'required|integer|distinct',
            'layout' => 'required|array:width,height,margin,gap,copies,start', 'layout.width' => 'required|numeric|min:40|max:190', 'layout.height' => 'required|numeric|min:40|max:277',
            'layout.margin' => 'required|numeric|min:5|max:30', 'layout.gap' => 'required|numeric|min:0|max:15',
            'layout.copies' => 'required|integer|min:1|max:20', 'layout.start' => 'required|integer|min:1|max:100']);
        $layout = $data['layout'];
        foreach (['width', 'height', 'margin', 'gap'] as $key) {
            $layout[$key] = (float) $layout[$key];
        }
        foreach (['copies', 'start'] as $key) {
            $layout[$key] = (int) $layout[$key];
        }
        $data['asset_ids'] = array_map('intval', $data['asset_ids']);
        sort($data['asset_ids']);
        $columns = (int) floor((210 - 2 * $layout['margin'] + $layout['gap']) / ($layout['width'] + $layout['gap']));
        $rows = (int) floor((297 - 2 * $layout['margin'] + $layout['gap']) / ($layout['height'] + $layout['gap']));
        abort_unless($columns > 0 && $rows > 0 && $layout['start'] <= $columns * $rows, 422, 'This label layout does not fit an A4 sheet.');
        abort_if(count($data['asset_ids']) * $layout['copies'] > 1000, 422, 'Generate up to 1,000 labels per batch.');

        return DB::transaction(function () use ($request, $data, $layout, $columns, $rows) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if ($batch = AssetLabelBatch::where('request_id', $data['request_id'])->first()) {
                abort_unless($batch->created_by_user_id === $request->user()->id, 404);
                abort_unless($batch->asset_ids === $data['asset_ids'] && $batch->layout == [...$layout, 'columns' => $columns, 'rows' => $rows], 409, 'This request was already used for different labels. Start a new batch.');

                return $batch;
            }
            $assets = $this->access->accessibleAssets($request->user())->whereKey($data['asset_ids'])->orderBy('id')->lockForUpdate()->get();
            abort_unless($assets->count() === count($data['asset_ids']), 404);
            foreach ($assets as $asset) {
                if (! $asset->qr_token) {
                    $this->authorize('update', $asset);
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
