<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\ClientMedication;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\Medication\Stock\PackPhotoReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P02 person record › Medicines › Photos (approved P02 v1; Lane C11): every
 * staff photo of the packs supplied for this person's medicines, newest
 * first, the latest per medicine marked current (or "pack or brand
 * changed"). Same per-person gate as the rest of the record; a controlled
 * medicine's photos are left out, and counted, for a reader without
 * controlled view.
 */
final class PersonMedicationPhotosController extends Controller
{
    public function __construct(
        private readonly MedicationRecordAccess $access,
        private readonly PackPhotoReader $photos,
    ) {}

    public function index(Request $request, int $client): JsonResponse
    {
        $actor = $request->user();
        $person = $this->access->client($actor, $client);
        $request->validate(['medication_id' => ['nullable', 'integer', 'min:1']]);
        $orders = ClientMedication::withTrashed()->where('client_id', $person->id)
            ->when($request->filled('medication_id'), fn ($query) => $query->whereKey($request->integer('medication_id')))
            ->get();
        abort_if($request->filled('medication_id') && $orders->isEmpty(), 404, 'The requested medication record was not found.');

        return response()->json($this->photos->history($actor, $orders))->header('Cache-Control', 'private, no-store');
    }
}
