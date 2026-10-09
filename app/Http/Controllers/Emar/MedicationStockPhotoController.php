<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationStockLot;
use App\Models\MedicationStockPhoto;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\Medication\Stock\PackPhotoReceiptSettings;
use App\Services\Medication\Stock\PackPhotoWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class MedicationStockPhotoController extends Controller
{
    public function __construct(private readonly MedicationGovernanceScopeService $scope, private readonly MedicationRecordAccess $access) {}

    public function store(Request $request, int $lot)
    {
        // Approved P06 Q5: staff who receive stock photograph the pack; the
        // Medicine photos "who" setting can narrow or widen that (P11 Q18).
        abort_unless(app(PackPhotoReceiptSettings::class)->canPhotograph($request->user()), 403);
        $capability = collect([
            MedicationGovernanceScopeService::STOCK_CAPABILITY,
            MedicationGovernanceScopeService::STOCK_RECEIVE_CAPABILITY,
            'medications.administer.record',
        ])->first(fn (string $key): bool => $request->user()->canDo($key)) ?? abort(403);
        $data = $request->validate([
            'request_uuid' => 'required|uuid',
            'photo' => 'required|'.PackPhotoWriter::RULES,
            'description' => 'nullable|string|max:1000',
            'pack_changed' => 'nullable|boolean',
        ]);
        $submitted = MedicationStockLot::with('stock')->findOrFail($lot);
        $writtenPath = null;
        try {
            $response = DB::transaction(function () use ($request, $submitted, $lot, $data, $capability, &$writtenPath) {
                return $this->scope->forMedication($request->user(), $submitted->stock->client_medication_id, $capability,
                function (Client $client, ClientMedication $med, User $actor) use ($lot, $data, $request, &$writtenPath) {
                    $this->access->assertReadable($actor, $client);
                    abort_if($med->controlled_drug && ! $actor->canDo('medications.controlled.view'), 404);
                    $stock = $med->stock()->lockForUpdate()->firstOrFail();
                    $pack = MedicationStockLot::where('client_medication_stock_id', $stock->id)->lockForUpdate()->findOrFail($lot);
                    $file = $request->file('photo');
                    $hash = hash_file('sha256', $file->getRealPath());
                    $existing = MedicationStockPhoto::where('request_uuid', $data['request_uuid'])->lockForUpdate()->first();
                    $packChanged = (bool) ($data['pack_changed'] ?? false);
                    if ($existing) {
                        if ((int) $existing->medication_stock_lot_id !== (int) $pack->id || ! hash_equals($existing->sha256, $hash)
                            || (int) $existing->taken_by !== (int) $actor->id || $existing->description !== ($data['description'] ?? null)
                            || (bool) $existing->pack_changed !== $packChanged) {
                            throw ValidationException::withMessages(['photo' => 'This request was used for a different photo. Choose the intended photo and retry with a new request.']);
                        }
                        return response()->json(['success' => true, 'photo_id' => $existing->id, 'duplicate' => true]);
                    }
                    $photo = app(PackPhotoWriter::class)->create(
                        $actor, $client, $med, $pack, $file, $data['request_uuid'], $data['description'] ?? null, $packChanged, $writtenPath,
                    );
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
