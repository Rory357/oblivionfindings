<?php

namespace App\Services\Medication\Recording;

use App\Models\AppSetting;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Medication\Stock\PackPhotoReader;

/**
 * Medication settings › Medication rules › Medicine photos › At recording
 * (eMAR audit Lane A11, Stephan 9 Oct). Whether the recording dialog shows
 * the latest photo of the supplied pack, and what happens when there's no
 * photo: a warning ("No photo — check the label"), or an "I've checked the
 * label" tick before continuing. A photo is a guide only and never replaces
 * reading the label, so neither choice can switch label checking off.
 */
final class PackPhotoSettings
{
    public const SHOW = 'medications.pack_photo.show_at_recording';

    public const NO_PHOTO = 'medications.pack_photo.no_photo';

    public const SHOW_DEFAULT = 'yes';

    public const NO_PHOTO_DEFAULT = 'warn';

    public function showAtRecording(): bool
    {
        return $this->value(self::SHOW, ['yes', 'no'], self::SHOW_DEFAULT) === 'yes';
    }

    /** @return 'warn'|'tick' */
    public function noPhoto(): string
    {
        return $this->value(self::NO_PHOTO, ['warn', 'tick'], self::NO_PHOTO_DEFAULT) === 'tick' ? 'tick' : 'warn';
    }

    /** @return array{show: bool, no_photo: 'warn'|'tick'} What the recording dialog reads. */
    public function policy(): array
    {
        return ['show' => $this->showAtRecording(), 'no_photo' => $this->noPhoto()];
    }

    public const LABEL_TICK_FIELD = 'label_checked';

    public const LABEL_TICK_MESSAGE = 'There’s no photo of this pack, so tick “I’ve checked the label” once you’ve checked it against the order.';

    /**
     * A11 (Main, 13 Oct): the server's own check of the dialog's tick. A
     * "given" dose needs it when the organisation shows photos at recording,
     * asks for the tick when there's no photo, and the recorder sees no
     * photo of the pack (nor a reference image) for this order — exactly
     * when the dialog asks. Refusals and withheld doses never need it.
     *
     * @param  array<string, mixed>  $data  the dose as sent (status given)
     */
    public function labelTickRequired(User $recorder, ClientMedication $order, array $data): bool
    {
        return ($data['status'] ?? null) === 'given'
            && $this->showAtRecording()
            && $this->noPhoto() === 'tick'
            && app(PackPhotoReader::class)->forOrder($recorder, $order) === null;
    }

    /** @param  array<string, mixed>  $data */
    public static function labelTicked(array $data): bool
    {
        return filter_var($data[self::LABEL_TICK_FIELD] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The refusal for a required tick that's missing, shaped like every other
     * recording-contract error.
     *
     * @return array{success: false, error: string, error_field: string}
     */
    public static function labelTickMissing(): array
    {
        return ['success' => false, 'error' => self::LABEL_TICK_MESSAGE, 'error_field' => self::LABEL_TICK_FIELD];
    }

    /**
     * Keep the worker's tick with the dose it was given for (there was no
     * photo of the pack). Called inside the dose's transaction, so if it
     * can't be kept nothing is recorded. A replayed or someone else's dose
     * keeps the tick its own save kept.
     *
     * @param  array<string, mixed>  $result  EnhancedMarService::recordAdministration's answer
     */
    public static function keepLabelTick(User $recorder, array $result): void
    {
        $dose = $result['administration'] ?? null;
        if (! ($result['success'] ?? false) || ($result['duplicate'] ?? false) || ! $dose instanceof ClientMedicationAdministration) {
            return;
        }
        AuditLogger::logOrFail('medications.dose.label_checked', $dose, [
            'actor_id' => (int) $recorder->id,
            'client_id' => (int) $dose->client_id,
            'client_medication_id' => (int) $dose->client_medication_id,
            'reason' => 'no_pack_photo',
        ]);
    }

    /** @param  list<string>  $accepted */
    private function value(string $key, array $accepted, string $default): string
    {
        $stored = AppSetting::query()->where('key', $key)->value('value');

        return in_array($stored, $accepted, true) ? $stored : $default;
    }
}
