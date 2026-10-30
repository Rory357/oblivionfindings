<?php

namespace App\Services\Medication\PharmacyConnect;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationPharmacyOrder;

final class PharmacyOrderSnapshot
{
    public function capture(Client $client, ClientMedication $medication, MedicationPharmacyOrder $order, array $partner): array
    {
        $stock = ClientMedicationStock::where('client_medication_id', $medication->id)->lockForUpdate()->first(['unit']);
        if (trim((string) $client->nhi_number) === '' || $client->date_of_birth === null) {
            throw new PharmacyConnectionException('person_identifier_missing', 'Record the person’s NHI and date of birth before connected ordering.');
        }
        if (trim((string) $stock?->unit) === '') {
            throw new PharmacyConnectionException('stock_unit_missing', 'Record the counted stock unit before connected ordering.');
        }

        return [
            'site_id' => (int) $client->site_id, 'client_id' => (int) $client->id,
            'client_medication_id' => (int) $medication->id, 'medication_version' => (int) $medication->version,
            'payload' => [
                'protocol' => 'oblivion-json-v1', 'site_reference' => $partner['site_references'][$client->site_id],
                'person' => ['nhi' => $client->nhi_number, 'name' => trim($client->first_name.' '.$client->last_name),
                    'date_of_birth' => $client->date_of_birth->toDateString()],
                'medicine' => ['name' => $medication->name, 'dosage' => $medication->dosage, 'route' => $medication->route,
                    'frequency' => $medication->frequency, 'nzulm_code' => $medication->nzulm_code,
                    'controlled' => (bool) $medication->controlled_drug],
                'supply' => ['pharmacy_name' => $order->pharmacy_name, 'quantity' => (int) $order->quantity_ordered,
                    'unit' => $stock->unit, 'needed_by' => $order->needed_by?->toDateString(), 'notes' => $order->order_notes],
            ],
        ];
    }

    public function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }
}
