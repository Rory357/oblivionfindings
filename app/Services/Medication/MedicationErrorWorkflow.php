<?php

namespace App\Services\Medication;

use App\Models\AppSetting;
use App\Models\MedicationError;
use App\Models\MedicationErrorEntry;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

final class MedicationErrorWorkflow
{
    public const MANAGE = 'medications.errors.manage';

    public const TRIAGE_DUE = 'medications.errors.triage_due';

    public const HARMS = ['none', 'minor', 'moderate', 'severe', 'death', 'unknown'];

    /** P08b approved default: by the end of the next NZ day, not yet reviewed. */
    public function triageRule(): string
    {
        $value = AppSetting::query()->where('key', self::TRIAGE_DUE)->first()?->value;

        return in_array($value, ['fourHours', 'endOfDay', 'nextDay'], true) ? $value : 'nextDay';
    }

    public function triageDue(): Carbon
    {
        $now = now('Pacific/Auckland');

        return match ($this->triageRule()) {
            'fourHours' => $now->addHours(4)->utc(),
            'endOfDay' => $now->endOfDay()->utc(),
            default => $now->addDay()->endOfDay()->utc(),
        };
    }

    public function incidentRequired(MedicationError $error): bool
    {
        return $error->report_source === 'more_than_ordered'
            || ($error->reached_client !== 'no' && in_array($error->harm_level, ['moderate', 'severe', 'death'], true));
    }

    public function severity(string $reach, ?string $harm): string
    {
        if ($reach === 'no') {
            return 'near_miss';
        }

        return match ($harm) {
            'death', 'severe' => 'critical', 'moderate' => 'major', 'minor' => 'moderate', default => 'minor',
        };
    }

    /** Local form input is interpreted as NZ wall time, never the host timezone. */
    public function time(string $value, string $field): Carbon
    {
        try {
            $at = Carbon::createFromFormat('!Y-m-d\TH:i', $value, 'Pacific/Auckland');
            if ($at === false || $at->format('Y-m-d\TH:i') !== $value) {
                throw new \InvalidArgumentException;
            }

            return $at->utc();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Choose a valid New Zealand date and time.']);
        }
    }

    public function append(MedicationError $error, User $actor, string $kind, ?string $text = null, array $data = []): MedicationErrorEntry
    {
        $entry = $error->entries()->create([
            'actor_id' => $actor->id, 'kind' => $kind, 'text' => $text,
            'data' => $data, 'created_at' => now(),
        ]);
        // Free text stays in the protected record. Audit metadata carries identity only.
        AuditLogger::logOrFail('medications.error.'.$kind, $error, [
            'actor_id' => (int) $actor->id, 'entry_id' => (int) $entry->id, 'stage' => $error->stage(),
        ]);

        return $entry;
    }

    /** @return list<string> */
    public function closeBlockers(MedicationError $error, User $actor): array
    {
        $blockers = [];
        if ((int) $error->reported_by === (int) $actor->id) {
            $blockers[] = 'Someone other than the reporter must close this error.';
        }
        if ($error->stage() === 'triage' || $error->owner_id === null) {
            $blockers[] = 'Triage the error and assign an owner first.';
        }
        if ($error->actions()->whereNull('completed_at')->exists()) {
            $blockers[] = 'Complete all error actions first.';
        }
        $disclosure = $error->entries()->where('kind', 'disclosure')->reorder()->latest('id')->first();
        if ($error->reached_client !== 'no' && ($disclosure?->data['state'] ?? null) !== 'told') {
            $blockers[] = 'Record telling the person before closing.';
        }
        if ($this->incidentRequired($error) && $error->client_incident_id === null) {
            $blockers[] = 'Create the required linked incident first.';
        }

        return $blockers;
    }

    public function assertOpen(MedicationError $error): void
    {
        if ($error->stage() === 'closed') {
            throw ValidationException::withMessages(['status' => 'Reopen this error with a reason before adding to it.']);
        }
    }
}
