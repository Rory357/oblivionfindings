<?php

namespace App\Http\Controllers\FleetAssets;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetImportBatch;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AssetImportController extends Controller
{
    private const FIELDS = ['name', 'asset_tag', 'serial_number', 'category', 'site_id', 'site_room_id', 'manufacturer', 'model', 'purchase_date', 'warranty_expires_at', 'notes'];

    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    public function index(Request $request)
    {
        $this->authorize('create', Asset::class);

        return AssetImportBatch::where('created_by_user_id', $request->user()->id)->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))->latest()->paginate(15, ['id', 'filename', 'status', 'created_at', 'updated_at']);
    }

    public function show(Request $request, AssetImportBatch $batch)
    {
        $this->authorize('create', Asset::class);
        abort_unless($batch->created_by_user_id === $request->user()->id, 404);

        return $batch;
    }

    public function store(Request $request)
    {
        $this->authorize('create', Asset::class);
        $request->validate(['file' => 'required|file|max:1024']);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        try {
            $headers = fgetcsv($handle, 65536, ',', '"', '');
            abort_unless(is_array($headers) && count($headers) <= 40, 422, 'Choose a CSV with a header row and at most 40 columns.');
            $headers = array_map(fn ($v) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $v)), $headers);
            foreach ($headers as $header) {
                abort_unless(mb_check_encoding($header, 'UTF-8') && mb_strlen($header) <= 255, 422, 'Use UTF-8 column headings no longer than 255 characters.');
            }
            abort_if(count(array_unique($headers)) !== count($headers) || in_array('', $headers, true), 422, 'Column headings must be unique and non-empty.');
            $rows = [];
            while (($values = fgetcsv($handle, 65536, ',', '"', '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                abort_if(count($rows) >= 200, 422, 'Import up to 200 rows at a time. Split larger files into batches.');
                abort_unless(count($values) === count($headers), 422, 'Every row must have the same number of columns as the header.');
                foreach ($values as $v) {
                    abort_unless(mb_check_encoding((string) $v, 'UTF-8') && mb_strlen((string) $v) <= 5000, 422, 'Use UTF-8 text with cells no longer than 5,000 characters.');
                }
                $rows[] = ['number' => count($rows) + 2, 'raw' => array_combine($headers, $values), 'data' => [], 'errors' => [], 'status' => 'pending', 'asset_id' => null];
            }
            abort_if(empty($rows), 422, 'The file has no data rows.');
        } finally {
            fclose($handle);
        }
        $mapping = array_combine(self::FIELDS, array_map(fn ($f) => in_array($f, $headers, true) ? $f : '', self::FIELDS));

        return AssetImportBatch::create(['created_by_user_id' => $request->user()->id, 'filename' => mb_substr(basename($request->file('file')->getClientOriginalName()), 0, 255),
            'headers' => $headers, 'mapping' => $mapping, 'rows' => $rows]);
    }

    public function update(Request $request, AssetImportBatch $batch)
    {
        $this->show($request, $batch);
        $data = $request->validate(['version' => 'required|integer', 'action' => 'required|in:validate,import', 'mapping' => 'sometimes|array', 'mapping.*' => 'nullable|string|max:255',
            'row_numbers' => 'sometimes|array|max:200', 'row_numbers.*' => 'integer|distinct']);

        return DB::transaction(function () use ($request, $batch, $data) {
            $batch = AssetImportBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            abort_unless($batch->version === $data['version'], 409, 'This import changed. Reload its saved results before retrying.');
            $rows = $batch->rows;
            if ($data['action'] === 'validate') {
                abort_if(collect($rows)->contains('status', 'imported'), 422, 'Mapping is fixed once rows have been imported. Start a new batch for corrected source data.');
                $mapping = array_intersect_key($data['mapping'] ?? [], array_flip(self::FIELDS));
                abort_unless(! empty($mapping['name']) && ! empty($mapping['site_id']), 422, 'Map the asset name and site ID columns.');
                foreach ($mapping as $column) {
                    abort_if($column && ! in_array($column, $batch->headers, true), 422, 'Choose a column from this file.');
                }
                $seen = [];
                foreach ($rows as &$row) {
                    $row['data'] = [];
                    foreach ($mapping as $field => $column) {
                        $value = $column !== null && $column !== '' ? trim((string) ($row['raw'][$column] ?? '')) : '';
                        $row['data'][$field] = $value === '' ? null : $value;
                    }
                    $row['errors'] = $this->errors($request->user(), $row['data']);
                    foreach (['asset_tag', 'serial_number'] as $field) {
                        $key = $field.':'.mb_strtolower($row['data'][$field] ?? '');
                        if (! empty($row['data'][$field])) {
                            if (isset($seen[$key])) {
                                $row['errors'][] = 'Repeated '.$field.' in this file.';
                            }
                            $seen[$key] = true;
                        }
                    }
                    $row['status'] = empty($row['errors']) ? 'ready' : 'invalid';
                }
                unset($row);
                $batch->mapping = $mapping;
                $batch->status = 'validated';
            } else {
                abort_unless($batch->status !== 'mapping', 422, 'Validate the mapping first.');
                abort_if(empty($data['row_numbers']), 422, 'Select at least one valid row.');
                foreach ($rows as &$row) {
                    if (! in_array($row['number'], $data['row_numbers'], true) || $row['status'] === 'imported') {
                        continue;
                    }
                    abort_unless(in_array($row['status'], ['ready', 'failed']), 422, 'Only validated rows can be imported.');
                    $row['errors'] = $this->errors($request->user(), $row['data'], true);
                    if ($row['errors']) {
                        $row['status'] = 'failed';

                        continue;
                    }
                    try {
                        $asset = DB::transaction(function () use ($request, $row) {
                            $asset = Asset::create([...$row['data'], 'status' => 'active', 'risk_level' => 'medium',
                                'created_by_user_id' => $request->user()->id, 'updated_by_user_id' => $request->user()->id]);
                            AuditLogger::logOrFail('assets.create', $asset, ['source' => 'inventory_import', 'site_id' => $asset->site_id]);

                            return $asset;
                        });
                        $row['asset_id'] = $asset->id;
                        $row['status'] = 'imported';
                    } catch (\Throwable $e) {
                        report($e);
                        $row['status'] = 'failed';
                        $row['errors'] = ['This row could not be saved. Retry this row; successful rows will be kept.'];
                    }
                }
                unset($row);
                $batch->status = collect($rows)->every(fn ($r) => $r['status'] === 'imported') ? 'completed' : 'partial';
            }
            $batch->rows = $rows;
            $batch->version++;
            $batch->save();

            return $batch;
        }, 3);
    }

    private function errors(User $user, array $data, bool $lock = false): array
    {
        $errors = Validator::make($data, ['name' => 'required|string|max:255', 'asset_tag' => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:255', 'category' => 'nullable|string|max:120', 'site_id' => 'required|integer',
            'site_room_id' => 'nullable|integer', 'manufacturer' => 'nullable|string|max:255', 'model' => 'nullable|string|max:255',
            'purchase_date' => 'nullable|date_format:Y-m-d', 'warranty_expires_at' => 'nullable|date_format:Y-m-d', 'notes' => 'nullable|string|max:5000'])->errors()->all();
        $site = $this->access->accessibleSites($user)->whereKey($data['site_id'] ?? 0)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if (! $site) {
            $errors[] = 'Choose a permitted site ID.';
        }
        if (! empty($data['site_room_id']) && (! $site || ! SiteRoom::where('site_id', $site->id)->whereKey($data['site_room_id'])->exists())) {
            $errors[] = 'The room must belong to the selected site.';
        }
        foreach (['asset_tag', 'serial_number'] as $field) {
            if (! empty($data[$field]) && Asset::where($field, $data[$field])->when($lock, fn ($q) => $q->lockForUpdate())->first(['id'])) {
                $errors[] = 'This '.$field.' is already registered. Review the existing record.';
            }
        }

        return $errors;
    }
}
