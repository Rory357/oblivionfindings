<?php

namespace App\Services\Medication\Downtime;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\ClientAllergyRecordService;
use App\Services\Medication\DoseSlots\DoseOrderTimelineFactory;
use App\Services\Medication\DoseSlots\DoseSlotProjection;
use App\Services\Medication\DoseSlots\DoseSlotReaderScope;
use App\Services\Medication\DoseSlots\DoseSlotRules;
use App\Services\Medication\MedicationConcealment;
use App\Services\Medication\Recording\DoseRecordingRequirements;
use App\Services\MedicationRuleService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class DowntimePackService
{
    public const PURPOSE = 'Downtime — a paper copy in case the system is down';

    public function __construct(
        private readonly DowntimeAccess $access,
        private readonly DoseSlotProjection $projection,
        private readonly DoseOrderTimelineFactory $timelines,
        private readonly MedicationRuleService $rules,
        private readonly ClientAllergyRecordService $allergies,
    ) {}

    public function build(User $actor, int $siteId, string $day): array
    {
        $now = CarbonImmutable::now('UTC');
        $today = $now->setTimezone(PaperReconciliationRules::TIMEZONE)->toDateString();
        $tomorrow = $now->setTimezone(PaperReconciliationRules::TIMEZONE)->addDay()->toDateString();
        if (! in_array($day, [$today, $tomorrow], true)) {
            throw ValidationException::withMessages(['nz_date' => 'The downtime pack is for today or tomorrow in New Zealand.']);
        }
        $clientIds = $this->access->clients($actor, $siteId, pack: true);
        $rows = $this->projection->rows(DoseSlotReaderScope::forAuthorisedClients($actor, $clientIds), $day, $day, $now);
        $orders = ClientMedication::query()->whereIn('client_id', $clientIds)->whereNull('superseded_by')->with(['client', 'stock'])->get()->keyBy('id');
        // Common rules prove coverage; projection supplies every printable slot.
        $projectedKeys = $rows->map(fn ($row) => $row['client_medication_id'].':'.$row['nz_date'].':'.$row['ordered_time'])->all();
        $concealment = MedicationConcealment::for($actor);
        foreach ($orders as $order) {
            if ($concealment->hides((bool) $order->controlled_drug)) {
                continue;
            }
            foreach (DoseSlotRules::forWorkerTimezone()->slotsOn($this->timelines->forOrder($order), $day) as $expected) {
                if (! in_array($expected->key(), $projectedKeys, true)) {
                    throw ValidationException::withMessages(['pack' => 'The scheduled-dose projection is incomplete for this day. Ask the house lead to restore it before making the pack; no incomplete paper schedule was printed.']);
                }
            }
        }
        $visible = $concealment->leaveOut($rows, fn ($row) => (bool) $row['controlled'] || (bool) $orders->get($row['client_medication_id'])?->controlled_drug);
        $scheduled = [];
        foreach ($visible['rows'] as $slot) {
            $order = $orders->get($slot['client_medication_id']);
            if (! $order) {
                throw ValidationException::withMessages(['pack' => 'An order changed while the pack was made. Refresh and try again.']);
            }
            $requirements = $this->rules->requirementsFor($order);
            $scheduled[] = [
                ...$slot, 'person' => $order->client->full_name, 'medicine' => $order->name,
                'dosage' => $order->dosage, 'route' => $order->route, 'instructions' => $order->instructions,
                'second_person_required' => $order->requiresWitness() || $requirements['requires_countersign'],
                'readings' => $this->readingLabels($requirements['required_observations']),
            ];
        }
        $people = Client::query()->whereIn('id', $clientIds)->orderBy('last_name')->get()->map(function (Client $client) use ($scheduled, $orders, $concealment, $day): array {
            $prn = $orders->filter(fn (ClientMedication $order) => (int) $order->client_id === (int) $client->id
                && $order->is_prn && $order->active && ! $concealment->hides((bool) $order->controlled_drug)
                && ($order->start_date === null || $order->start_date->toDateString() <= $day)
                && ($order->end_date === null || $order->end_date->toDateString() >= $day))
                ->map(function (ClientMedication $order): array {
                    $requirements = $this->rules->requirementsFor($order);

                    return [
                        'medicine' => $order->name, 'dosage' => $order->dosage, 'route' => $order->route,
                        'indication' => $order->prn_reason, 'max_per_day' => $order->max_per_day,
                        'min_hours_between_doses' => $order->min_hours_between_doses, 'instructions' => $order->instructions,
                        'verified' => $order->isVerifiedForAdministration(),
                        'second_person_required' => $order->requiresWitness() || $requirements['requires_countersign'],
                        'readings' => $this->readingLabels($requirements['required_observations']),
                    ];
                })->values()->all();

            return [
                'id' => (int) $client->id, 'name' => $client->full_name,
                'allergies' => array_map(fn ($allergy) => array_intersect_key($allergy, array_flip(['allergen', 'severity', 'reaction'])), $this->allergies->forClient($client)),
                'scheduled' => array_values(array_filter($scheduled, fn ($row) => $row['client_id'] === (int) $client->id)), 'prn' => $prn,
            ];
        })->all();
        $controlled = $concealment->canViewControlled() ? $orders->filter(fn ($order) => $order->active && $order->controlled_drug)->map(fn ($order) => [
            'person' => $order->client->full_name, 'medicine' => $order->name,
            'balance' => $order->stock?->on_hand, 'unit' => $order->stock?->unit,
        ])->values()->all() : [];

        return [
            'site' => Site::query()->findOrFail($siteId)->only(['id', 'name']),
            'nz_date' => $day, 'printed_at' => $now->toIso8601String(), 'printed_by' => $actor->name, 'purpose' => self::PURPOSE,
            'people' => $people, 'rounds' => $scheduled, 'controlled_registers' => $controlled,
            'controlled_pages_included' => $concealment->canViewControlled(),
            'controlled_notice' => $concealment->canViewControlled() ? null : 'Controlled register pages need controlled-medicine access — ask the house lead.',
        ];
    }

    private function readingLabels(array $keys): array
    {
        return array_map(fn ($key) => DoseRecordingRequirements::OBSERVATIONS[$key]['label'] ?? str_replace('_', ' ', $key), $keys);
    }
}
