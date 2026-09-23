<?php

namespace App\Services\Fleet;

use App\Models\FleetCatalogueEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Persistent "Add new" choices for otherwise unowned labels. Presets shown in
 * the pickers are examples held by the interface; only additions are stored.
 * Outcomes, applicability, statuses, people, sites and Finance records are
 * never catalogue values.
 */
class FleetCatalogueService
{
    /** @var array<string, array{numeric: bool, unit?: string}> */
    public const KINDS = [
        'service_type' => ['numeric' => false],
        'document_type' => ['numeric' => false],
        'reminder_title' => ['numeric' => false],
        'vehicle_body_type' => ['numeric' => false],
        'vehicle_manufacturer' => ['numeric' => false],
        'vehicle_model' => ['numeric' => false],
        'vehicle_use_purpose' => ['numeric' => false],
        'ownership_arrangement' => ['numeric' => false],
        'interval_months' => ['numeric' => true, 'unit' => 'months'],
        'interval_km' => ['numeric' => true, 'unit' => 'km'],
        'reminder_days_before' => ['numeric' => true, 'unit' => 'days'],
        'reminder_km_before' => ['numeric' => true, 'unit' => 'km'],
        'repeat_months' => ['numeric' => true, 'unit' => 'months'],
        'seats' => ['numeric' => true, 'unit' => 'seats'],
    ];

    /** @return array<string, list<array{id:int,label:string}>> */
    public function options(): array
    {
        $options = array_fill_keys(array_keys(self::KINDS), []);
        FleetCatalogueEntry::query()->whereNull('archived_at')->orderBy('label')->get(['id', 'kind', 'label'])
            ->each(function (FleetCatalogueEntry $entry) use (&$options): void {
                if (isset($options[$entry->kind])) {
                    $options[$entry->kind][] = ['id' => (int) $entry->id, 'label' => $entry->label];
                }
            });

        return $options;
    }

    public function canAdd(User $actor): bool
    {
        return $actor->canDo('fleet.manage') || $actor->canDo('assets.documents.manage');
    }

    /** Adds a choice, or returns the existing one with the same meaning. */
    public function add(User $actor, string $kind, string $label): FleetCatalogueEntry
    {
        abort_unless(isset(self::KINDS[$kind]), 404);
        $current = User::query()->findOrFail($actor->id);
        abort_unless($this->canAdd($current), 403, 'Adding options needs fleet or document management access.');
        $label = self::clean($label);
        $numeric = self::KINDS[$kind]['numeric'];
        if ($label === '' || mb_strlen($label) > 80) {
            throw ValidationException::withMessages(['label' => 'Use 1 to 80 characters.']);
        }
        if ($numeric && (! ctype_digit($label) || (int) $label < 1 || (int) $label > 1000000)) {
            throw ValidationException::withMessages(['label' => 'Use a whole number from 1 to 1,000,000.']);
        }
        $normalised = mb_strtolower($numeric ? (string) (int) $label : $label);
        $existing = FleetCatalogueEntry::query()->where('kind', $kind)->where('normalised_label', $normalised)->first();
        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(fn (): FleetCatalogueEntry => FleetCatalogueEntry::query()->create([
                'kind' => $kind,
                'label' => $numeric ? (string) (int) $label : $label,
                'normalised_label' => $normalised,
                'value_json' => $numeric ? ['quantity' => (int) $label, 'unit' => self::KINDS[$kind]['unit']] : null,
                'created_by_user_id' => $current->id,
            ]));
        } catch (QueryException $exception) {
            // A concurrent identical addition won the unique key; reuse it.
            return FleetCatalogueEntry::query()->where('kind', $kind)->where('normalised_label', $normalised)->firstOr(
                fn () => throw $exception,
            );
        }
    }

    public static function clean(string $label): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $label));
    }
}
