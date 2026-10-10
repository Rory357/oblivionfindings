<?php

namespace App\Services\Medication\Recording;

use App\Models\AppSetting;

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

    /**
     * Review item 6: the settings mean nothing until photos reach the
     * dialog. Lane C serves the latest staff photo per order
     * (Stock\PackPhotoReader); until it lands, the settings stay hidden.
     */
    public static function served(): bool
    {
        return class_exists(\App\Services\Medication\Stock\PackPhotoReader::class);
    }

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

    /** @param  list<string>  $accepted */
    private function value(string $key, array $accepted, string $default): string
    {
        $stored = AppSetting::query()->where('key', $key)->value('value');

        return in_array($stored, $accepted, true) ? $stored : $default;
    }
}
