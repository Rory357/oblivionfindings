<?php

namespace App\Services\Medication\Stock;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\Medication\Settings\MedicationSettingDefinition as Definition;
use App\Services\Medication\Settings\MedicationSettingGroup;
use App\Services\Medication\Settings\MedicationSettingsRegistry;

/**
 * Settings › Medication rules › Medicine photos › Taking photos (approved P11 v5 Q18 and
 * P06 Q6; Lane C11). Staff photos of the pack actually supplied are the
 * primary picture of a medicine everywhere.
 *
 * - Who can take or replace a photo: anyone who can receive stock (default),
 *   leads only, or anyone who records doses.
 * - Prompt at receipt: off, when there is no photo yet or the pack or brand
 *   changed (default, the approved P06 behaviour), or at every receipt.
 * - Whether a controlled delivery needs a photo before it can be received
 *   (default off: approved "prompted, never required").
 *
 * Organisation-wide only: a photo is evidence about a pack, not a house
 * practice. Nothing here ever blocks recording a dose. An unknown stored
 * value falls back to the default rather than guessing.
 */
final class PackPhotoReceiptSettings
{
    public const WHO = 'medications.pack_photo.who';

    public const PROMPT = 'medications.pack_photo.receipt_prompt';

    public const CONTROLLED_REQUIRED = 'medications.pack_photo.controlled_receipt_required';

    public const WHO_STOCK = 'stock';

    public const WHO_LEADS = 'leads';

    public const WHO_RECORDERS = 'recorders';

    public const PROMPT_OFF = 'off';

    public const PROMPT_NEEDED = 'needed';

    public const PROMPT_ALWAYS = 'always';

    public const OPTIONS = [
        self::WHO => [self::WHO_RECORDERS, self::WHO_STOCK, self::WHO_LEADS],
        self::PROMPT => [self::PROMPT_OFF, self::PROMPT_NEEDED, self::PROMPT_ALWAYS],
        self::CONTROLLED_REQUIRED => ['no', 'yes'],
    ];

    public const DEFAULTS = [
        self::WHO => self::WHO_STOCK,
        self::PROMPT => self::PROMPT_NEEDED,
        self::CONTROLLED_REQUIRED => 'no',
    ];

    public static function group(): MedicationSettingGroup
    {
        $definition = fn (string $key, string $storage, string $label, array $options, array $rank): Definition => new Definition(
            group: 'packPhotoReceipt', key: $key, storageKey: $storage, scope: Definition::SCOPE_ORGANISATION, section: 'photos',
            label: $label, options: $options, default: self::DEFAULTS[$storage], rank: $rank,
        );

        return new MedicationSettingGroup(
            key: 'packPhotoReceipt',
            view: MedicationSettingsRegistry::VIEW_RULES,
            effect: 'From the next delivery received; photos already taken are kept',
            auditEvent: 'medications.pack_photo.updated',
            definitions: [
                $definition('who', self::WHO, 'Who can take or replace a medicine photo', [
                    self::WHO_STOCK => 'Anyone who can receive stock',
                    self::WHO_LEADS => 'Leads only',
                    self::WHO_RECORDERS => 'Anyone who records doses',
                ], self::OPTIONS[self::WHO]),
                $definition('prompt', self::PROMPT, 'Prompt for a photo when stock is received', [
                    self::PROMPT_OFF => 'Off — don’t prompt',
                    self::PROMPT_NEEDED => 'When there’s no photo yet, or the pack or brand changed',
                    self::PROMPT_ALWAYS => 'At every delivery',
                ], self::OPTIONS[self::PROMPT]),
                $definition('controlled_required', self::CONTROLLED_REQUIRED, 'A controlled delivery needs a pack photo before it is received', [
                    'no' => 'Off — the photo is optional',
                    'yes' => 'On',
                ], self::OPTIONS[self::CONTROLLED_REQUIRED]),
            ],
        );
    }

    public function who(): string
    {
        return $this->value(self::WHO);
    }

    public function prompt(): string
    {
        return $this->value(self::PROMPT);
    }

    /** Whether receiving stock prompts for a photo at all (Lane A's dialog reads this). */
    public function receiptPromptOn(): bool
    {
        return $this->prompt() !== self::PROMPT_OFF;
    }

    public function controlledPhotoRequired(): bool
    {
        return $this->value(self::CONTROLLED_REQUIRED) === 'yes';
    }

    /** Whether $actor may take or replace a medicine photo (the "who" setting). */
    public function canPhotograph(User $actor): bool
    {
        $update = $actor->canDo('medications.stock.update');
        $receive = $update || $actor->canDo('medications.stock.receive');

        return match ($this->who()) {
            self::WHO_LEADS => $update,
            self::WHO_RECORDERS => $receive || $actor->canDo('medications.administer.record'),
            default => $receive,
        };
    }

    /**
     * Whether the receive dialog asks for a photo: never when prompting is
     * off (unless a controlled photo is required), always when it's set to
     * every delivery, otherwise when the medicine has no photo yet.
     */
    public function promptFor(bool $hasPhoto, bool $controlled): bool
    {
        if ($controlled && $this->controlledPhotoRequired()) {
            return true;
        }

        return match ($this->prompt()) {
            self::PROMPT_OFF => false,
            self::PROMPT_ALWAYS => true,
            default => ! $hasPhoto,
        };
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $stored = AppSetting::query()->whereIn('key', array_keys(self::DEFAULTS))->pluck('value', 'key');

        return collect(self::DEFAULTS)
            ->map(fn (string $default, string $key): string => $this->normalise($key, $stored[$key] ?? null))
            ->all();
    }

    private function value(string $key): string
    {
        return $this->normalise($key, AppSetting::query()->where('key', $key)->first()?->value);
    }

    private function normalise(string $key, mixed $value): string
    {
        $value = is_string($value) ? $value : null;

        return in_array($value, self::OPTIONS[$key], true) ? $value : self::DEFAULTS[$key];
    }
}
