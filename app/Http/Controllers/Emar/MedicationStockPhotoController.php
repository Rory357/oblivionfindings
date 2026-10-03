<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationStockLot;
use App\Models\MedicationStockPhoto;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use Carbon\CarbonImmutable;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class MedicationStockPhotoController extends Controller
{
    public function __construct(private readonly MedicationGovernanceScopeService $scope, private readonly MedicationRecordAccess $access) {}

    public function store(Request $request, int $lot)
    {
        // Existing stock-update authority only until the exact receive-role
        // grant has been approved and integrated. Do not broaden role access.
        abort_unless($request->user()->canDo('medications.stock.update'), 403);
        $data = $request->validate([
            'request_uuid' => 'required|uuid',
            'photo' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:8192|dimensions:max_width=12000,max_height=12000',
            'description' => 'nullable|string|max:1000',
        ]);
        $submitted = MedicationStockLot::with('stock')->findOrFail($lot);
        $writtenPath = null;
        try {
            $response = DB::transaction(function () use ($request, $submitted, $lot, $data, &$writtenPath) {
                return $this->scope->forMedication($request->user(), $submitted->stock->client_medication_id, 'medications.stock.update',
                function (Client $client, ClientMedication $med, User $actor) use ($lot, $data, $request, &$writtenPath) {
                    $this->access->assertReadable($actor, $client);
                    abort_if($med->controlled_drug && ! $actor->canDo('medications.controlled.view'), 404);
                    $stock = $med->stock()->lockForUpdate()->firstOrFail();
                    $pack = MedicationStockLot::where('client_medication_stock_id', $stock->id)->lockForUpdate()->findOrFail($lot);
                    $file = $request->file('photo');
                    $hash = hash_file('sha256', $file->getRealPath());
                    $existing = MedicationStockPhoto::where('request_uuid', $data['request_uuid'])->lockForUpdate()->first();
                    if ($existing) {
                        if ((int) $existing->medication_stock_lot_id !== (int) $pack->id || ! hash_equals($existing->sha256, $hash)
                            || (int) $existing->taken_by !== (int) $actor->id || $existing->description !== ($data['description'] ?? null)) {
                            throw ValidationException::withMessages(['photo' => 'This request was used for a different photo. Choose the intended photo and retry with a new request.']);
                        }
                        return response()->json(['success' => true, 'photo_id' => $existing->id, 'duplicate' => true]);
                    }
                    // A transaction retry reuses this call's file. It must not
                    // leave an orphan for each failed attempt.
                    $writtenPath ??= $file->store('medication-stock/'.$pack->id, 'private');
                    if (! is_string($writtenPath) || $writtenPath === '') {
                        throw new RuntimeException('The photo could not be stored. Keep it on this form and try again.');
                    }
                    $photo = MedicationStockPhoto::create([
                        'medication_stock_lot_id' => $pack->id, 'request_uuid' => $data['request_uuid'],
                        'path' => $writtenPath, 'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                        'mime' => $file->getMimeType(), 'bytes' => $file->getSize(), 'sha256' => $hash,
                        'description' => $data['description'] ?? null, 'taken_by' => $actor->id, 'taken_at' => now(),
                    ]);
                    AuditLogger::logOrFail('medications.stock.photo_added', $photo, ['actor_id' => $actor->id, 'lot_id' => $pack->id, 'client_medication_id' => $med->id]);
                    app(MedicationEventRecorder::class)->append(new MedicationEventData(
                        siteId: (int) $client->site_id, kind: 'stock.photo_added', subjectType: 'stock_photo', subjectId: (string) $photo->id,
                        actorId: $actor->id, occurredAt: CarbonImmutable::now('UTC'), summary: 'Medicine pack photo added',
                        facts: ['lot_id' => $pack->id, 'request_uuid' => $data['request_uuid']], clientId: $client->id, controlled: (bool) $med->controlled_drug,
                    ));
                    return response()->json(['success' => true, 'photo_id' => $photo->id]);
                });
            }, 5);
            if ($writtenPath && ($response->getData(true)['duplicate'] ?? false)) {
                Storage::disk('private')->delete($writtenPath);
            }
            return $response;
        } catch (Throwable $error) {
            // Rollback removes the database row, not filesystem bytes. Delete
            // only this call's new file, never a previously saved photo.
            if ($writtenPath) {
                Storage::disk('private')->delete($writtenPath);
            }
            throw $error;
        }
    }

    public function view(Request $request, int $photo)
    {
        return $this->bytes($request, $photo, false);
    }

    public function download(Request $request, int $photo)
    {
        return $this->bytes($request, $photo, true);
    }

    private function bytes(Request $request, int $id, bool $download)
    {
        abort_unless($request->user()->canDo('medications.view'), 403);
        $photo = MedicationStockPhoto::with('lot.stock')->findOrFail($id);
        $med = ClientMedication::withTrashed()->findOrFail($photo->lot->stock->client_medication_id);
        $this->access->client($request->user(), $med->client_id);
        abort_if($med->controlled_drug && ! $request->user()->canDo('medications.controlled.view'), 404);
        abort_unless(in_array($photo->mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);
        $disk = Storage::disk('private');
        abort_unless($disk->exists($photo->path), 404, 'This photo is unavailable.');
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Type' => $photo->mime];
        return $download
            ? $disk->download($photo->path, $photo->original_name, $headers)
            : $disk->response($photo->path, $photo->original_name, $headers, 'inline');
    }
}
