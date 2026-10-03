<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\MedicationFollowup;
use App\Services\Medication\ForgottenWitnessPinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MedicationSecondPersonConfirmationController extends Controller
{
    public function __construct(private readonly ForgottenWitnessPinService $confirmations) {}

    public function show(Request $request, int $confirmation): JsonResponse
    {
        $nomination = $this->confirmations->readable($request->user(), $confirmation);
        $dose = $nomination->administration;
        $followup = MedicationFollowup::query()->where('source_key', 'confirm:'.$nomination->id)
            ->where('type', 'confirm')->where('administration_id', $dose->id)->where('owner_id', $request->user()->id)->firstOrFail();
        $dose->loadMissing('administeredBy:id,name');

        return response()->json([
            'id' => (int) $nomination->id,
            'followup_id' => (int) $followup->id,
            'status' => $nomination->status,
            'due_at' => $nomination->due_at->toIso8601String(),
            'server_now' => now()->toIso8601String(),
            'person_name' => trim($dose->client->full_name),
            'medication_name' => $dose->medication->name,
            'given_at' => $dose->administered_at->toIso8601String(),
            'recorded_by' => $dose->administeredBy?->name ?? 'Staff member',
        ])->header('Cache-Control', 'private, no-store');
    }

    public function respond(Request $request, int $confirmation): JsonResponse
    {
        $data = $request->validate(['was_there' => ['required', 'boolean']]);
        // Immutable answer + named nomination is the replay binding. A retry
        // of the same answer returns its retained result; an opposite answer
        // cannot replace it. Exact/late deadline returns durable expired.
        $result = $this->confirmations->respond(
            $request->user(), $confirmation, (bool) $data['was_there'],
        );

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
