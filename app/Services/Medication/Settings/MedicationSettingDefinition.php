<?php

namespace App\Services\Medication\Settings;

/**
 * One Medication Settings value (eMAR P11): where it is stored, the values it
 * accepts with the words the change history uses for each, its default, and
 * which changes loosen a check.
 *
 * Loosening is one rule for the whole page (P11 v5 AUDIT §5): the same
 * definition decides the destructive confirmation before saving, the label in
 * the change history and the "loosens a check" warning when an earlier value
 * is put back. The page receives it through toClient().
 */
final class MedicationSettingDefinition
{
    public const SCOPE_ORGANISATION = 'organisation';

    public const SCOPE_SITE = 'site';

    public const HIGHER_IS_LOOSER = 'higher_is_looser';

    public const HIGHER_IS_STRICTER = 'higher_is_stricter';

    /**
     * `$rank` lists option values from loosest to strictest. `$numeric` is for
     * number values: whether a higher number is looser, which value means
     * "off", and whether switching off is the loosest choice.
     *
     * A setting with no `$options` and a `$range` is typed as a whole number
     * within that range (P11 v5 number inputs); `$unit` is the words after it
     * ("minutes before the dose time"). `$pairedWith` names a setting decided
     * together with this one: it is reviewed, kept and listed as one. A number
     * whose `$numeric['off']` is set can also be switched off; `$offLabel` is
     * the words for off ("No renewal"). `$whenNotConfigured` marks a setting
     * whose off value means "Not configured" — screens give no value until the
     * organisation chooses one — and says what happens meanwhile.
     *
     * @param  array<string, string>  $options  Accepted value => the words for it.
     * @param  list<string>|null  $rank
     * @param  array{direction: string, off: string|null, off_is_loosest: bool}|null  $numeric
     * @param  array{0: int, 1: int}|null  $range
     */
    public function __construct(
        public readonly string $group,
        public readonly string $key,
        public readonly string $storageKey,
        public readonly string $scope,
        public readonly string $section,
        public readonly string $label,
        public readonly array $options,
        public readonly string $default,
        public readonly ?array $rank = null,
        public readonly ?array $numeric = null,
        public readonly ?array $range = null,
        public readonly ?string $unit = null,
        public readonly ?string $pairedWith = null,
        public readonly ?string $offLabel = null,
        public readonly ?string $whenNotConfigured = null,
    ) {}

    public function isNumber(): bool
    {
        return $this->options === [] && $this->range !== null;
    }

    /** The value meaning "switched off", for a number that can be off. */
    public function offValue(): ?string
    {
        return $this->isNumber() ? ($this->numeric['off'] ?? null) : null;
    }

    public function id(): string
    {
        return $this->group.'.'.$this->key;
    }

    public function isSiteScoped(): bool
    {
        return $this->scope === self::SCOPE_SITE;
    }

    public function accepts(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        if ($this->isNumber()) {
            if ($this->offValue() !== null && $value === $this->offValue()) {
                return true;
            }

            return preg_match('/^\d{1,6}$/', $value) === 1
                && (int) $value >= $this->range[0]
                && (int) $value <= $this->range[1];
        }

        return array_key_exists($value, $this->options);
    }

    /** What to say when a value isn't accepted. */
    public function invalidMessage(): string
    {
        return $this->isNumber()
            ? 'Enter a whole number from '.number_format($this->range[0]).' to '.number_format($this->range[1]).' for “'.$this->label.'”'.($this->offValue() !== null ? ', or switch it off.' : '.')
            : 'Choose one of the listed values for “'.$this->label.'”.';
    }

    /**
     * A stored value the setting no longer accepts reads as its default. A
     * number saved as a JSON number reads as its digits.
     */
    public function normalise(mixed $value): string
    {
        if ($this->isNumber() && is_int($value)) {
            $value = (string) $value;
        }

        return $this->accepts($value) ? $value : $this->default;
    }

    public function format(string $value): string
    {
        if ($this->isNumber()) {
            if ($this->offValue() !== null && $value === $this->offValue()) {
                return $this->offLabel ?? 'Off';
            }

            return trim($value.' '.($this->unit ?? ''));
        }

        return $this->options[$value] ?? $value;
    }

    /** Does changing from one value to another turn a check off or make it less strict? */
    public function loosens(string $from, string $to): bool
    {
        if ($from === $to) {
            return false;
        }

        if ($this->rank !== null) {
            $before = array_search($from, $this->rank, true);
            $after = array_search($to, $this->rank, true);

            return $before !== false && $after !== false && $after < $before;
        }

        if ($this->numeric !== null) {
            $off = $this->numeric['off'];
            if ($from !== $off && $to === $off) {
                return $this->numeric['off_is_loosest'];
            }
            // Switching a check on is never looser.
            if ($from === $off || ! is_numeric($from) || ! is_numeric($to)) {
                return false;
            }
            $difference = (float) $to - (float) $from;

            return $this->numeric['direction'] === self::HIGHER_IS_LOOSER
                ? $difference > 0
                : $difference < 0;
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toClient(): array
    {
        return [
            'group' => $this->group,
            'key' => $this->key,
            'scope' => $this->scope,
            'section' => $this->section,
            'label' => $this->label,
            'options' => collect($this->options)
                ->map(fn (string $label, string $value): array => ['value' => (string) $value, 'label' => $label])
                ->values()
                ->all(),
            'default' => $this->default,
            'range' => $this->range,
            'unit' => $this->unit,
            'paired_with' => $this->pairedWith,
            'off_label' => $this->offLabel,
            'when_not_configured' => $this->whenNotConfigured,
            'rank' => $this->rank,
            'numeric' => $this->numeric,
        ];
    }
}
