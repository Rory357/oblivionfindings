<?php

namespace App\Services\Portal;

use App\Models\Client;
use App\Models\ClientMedication;

/**
 * The medicines a family-portal viewer sees (EA-099): current, checked orders
 * only (ClientMedication::active() — never unchecked, sent-back, ceased or
 * paused orders), mapped to a plain allowlist (no raw model, no workflow or
 * staff fields).
 *
 * Controlled medicines: the person sees their own; whānau and other portal
 * viewers don't — controlled concealment is a privacy floor, and portal
 * viewers never hold controlled-medicine access.
 */
final class PortalMedicationList
{
    /** @return list<array{id:int,name:string,dosage:?string,frequency:?string,route:?string,instructions:?string,is_prn:bool}> */
    public function forViewer(Client $client, bool $isSelf): array
    {
        return ClientMedication::query()
            ->active()
            ->where('client_id', $client->id)
            ->when(! $isSelf, fn ($query) => $query->where('controlled_drug', false))
            ->orderBy('name')
            ->get(['id', 'name', 'dosage', 'frequency', 'route', 'instructions', 'is_prn'])
            ->map(fn (ClientMedication $medication): array => [
                'id' => (int) $medication->id,
                'name' => (string) $medication->name,
                'dosage' => $medication->dosage,
                'frequency' => $medication->frequency,
                'route' => $medication->route,
                'instructions' => $medication->instructions,
                'is_prn' => (bool) $medication->is_prn,
            ])
            ->values()
            ->all();
    }
}
