<?php

namespace App\Http\Controllers;

use App\Services\Medication\ClientAllergyRecordCommands;
use App\Services\Medication\MedicationRecordAccess;
use Illuminate\Http\Request;

final class ClientAllergyRecordController extends Controller
{
    public function show(Request $request, int $client)
    {
        $person = app(MedicationRecordAccess::class)->client($request->user(), $client);

        return response()->json(app(ClientAllergyRecordCommands::class)->payload($person, $request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, int $client)
    {
        $actor = $request->user();
        app(MedicationRecordAccess::class)->client($actor, $client);
        $data = $request->validate([
            'request_uuid' => ['required', 'uuid'], 'digest' => ['required', 'string', 'size:64'],
            'action' => ['required', 'in:edit,review'], 'method' => ['required_if:action,review', 'nullable', 'string', 'max:2000'],
            'no_known' => ['sometimes', 'boolean'], 'records' => ['present_if:action,edit', 'array', 'max:100'],
            'records.*.key' => ['required', 'string', 'max:100', 'distinct'],
            'records.*.allergen' => ['required', 'string', 'max:255'],
            'records.*.severity' => ['nullable', 'in:mild,moderate,severe,life_threatening'],
            'records.*.reaction' => ['nullable', 'string', 'max:2000'], 'records.*.notes' => ['nullable', 'string', 'max:5000'],
            'records.*.identified_date' => ['nullable', 'date_format:Y-m-d'], 'records.*.identified_by' => ['nullable', 'string', 'max:255'],
        ]);
        $result = app(ClientAllergyRecordCommands::class)->execute($actor, $client, $data);

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
