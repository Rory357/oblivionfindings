<?php

namespace App\Services\Medication\Controlled;

use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientMedication;
use App\Models\ControlledDrugLossReport;
use App\Models\Site;
use App\Support\Medication\MedicationStockQuantity as Quantity;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * EA-012 (Lane C2): controlled-drug stock a person or a house still holds
 * must stay accountable in the register until it is returned, destroyed or
 * resolved. Archiving that person, or archiving or closing that house,
 * would hide it from the register, the count schedule and every register
 * action, so it is refused with the list of what's still held and where to
 * clear it. This is a safety floor, not a setting.
 */
final class ControlledStockHolding
{
    /**
     * What a person still holds: controlled medicines with stock on hand,
     * an open discrepancy or an open loss investigation.
     *
     * @return Collection<int, string> one plain line per medicine
     */
    public function heldByClients(array $clientIds): Collection
    {
        if ($clientIds === []) {
            return collect();
        }
        $medicines = ClientMedication::withTrashed()->where('controlled_drug', true)
            ->whereIn('client_id', $clientIds)->with('stock')->get();
        $openDiscrepancies = ClientControlledDrugDiscrepancy::query()->whereIn('client_id', $clientIds)
            ->whereIn('status', ['open', 'under_review'])->pluck('client_medication_id')->map(fn ($id) => (int) $id)->all();
        $openLosses = ControlledDrugLossReport::query()->whereIn('client_id', $clientIds)->open()
            ->pluck('client_medication_id')->map(fn ($id) => (int) $id)->all();

        return $medicines->map(function (ClientMedication $medicine) use ($openDiscrepancies, $openLosses): ?string {
            $onHand = $medicine->stock?->on_hand;
            $reasons = array_values(array_filter([
                $onHand !== null && Quantity::greaterThan($onHand, 0) ? Quantity::normalize($onHand).' '.($medicine->stock->unit ?? '').' on hand' : null,
                in_array((int) $medicine->id, $openDiscrepancies, true) ? 'an open discrepancy' : null,
                in_array((int) $medicine->id, $openLosses, true) ? 'an open loss investigation' : null,
            ]));

            return $reasons === [] ? null : $medicine->name.' — '.implode(', ', array_map('trim', $reasons));
        })->filter()->values();
    }

    /** Refuse archiving a person whose controlled stock is not yet cleared. */
    public function assertClientCanBeArchived(Client $client): void
    {
        $held = $this->heldByClients([(int) $client->id]);
        if ($held->isNotEmpty()) {
            throw ValidationException::withMessages(['client' => trim($client->first_name.' '.$client->last_name)
                .' still has controlled-drug stock in the register: '.$held->implode('; ')
                .'. Return, destroy or resolve it in Medication › Stock & controlled drugs › Controlled register first, then archive.']);
        }
    }

    /** Refuse archiving or closing a house that still holds controlled stock. */
    public function assertSiteCanClose(Site $site): void
    {
        $clientIds = Client::withTrashed()->where('site_id', $site->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $held = $this->heldByClients($clientIds);
        if ($held->isNotEmpty()) {
            throw ValidationException::withMessages(['site' => $site->name.' still holds controlled-drug stock: '.$held->implode('; ')
                .'. Return, destroy or resolve it in the controlled register before this house is archived or made inactive.']);
        }
    }
}
