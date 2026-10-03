<?php

namespace App\Services\Medication\Settings;

/**
 * A house's quiet hours (P11 v5 Q12, B2 chunk 5): follow the organisation,
 * its own hours, or none. One JSON value, so drafts, review and the change
 * history treat it like any other setting:
 *   {"mode":"org"} · {"mode":"off"} · {"mode":"own","from":"21:00","until":"07:00"}
 *
 * "Own hours" may be stored without both times only as a draft would show
 * it; saving checks both are chosen and differ (with the house's name), and
 * a window without both times holds nothing. Quiet hours never loosen a
 * check: they only hold email and push, never the bell or Follow up alerts.
 */
final class QuietHoursCodec implements MedicationSettingCodec
{
    public const ORG = 'org';

    public const OWN = 'own';

    public const NONE = 'off';

    public const DEFAULT = '{"mode":"org"}';

    public function kind(): string
    {
        return 'quiet';
    }

    public function accepts(string $value): bool
    {
        return $this->decode($value) !== null;
    }

    public function normalise(mixed $value, string $default): string
    {
        $decoded = is_string($value) ? $this->decode($value) : (is_array($value) ? $this->fromArray($value) : null);

        return $decoded === null ? $default : $this->encode($decoded);
    }

    public function format(string $value): string
    {
        $q = $this->decode($value);

        return match (true) {
            $q === null, $q['mode'] === self::ORG => 'Follows the organisation',
            $q['mode'] === self::NONE => 'No quiet hours at this house',
            $q['from'] !== '' && $q['until'] !== '' => 'Own hours: '.TimeCodec::clock($q['from']).' to '.TimeCodec::clock($q['until']),
            default => 'Own hours — times not chosen',
        };
    }

    public function loosens(string $from, string $to): bool
    {
        return false;
    }

    public function invalidMessage(string $label): string
    {
        return 'Choose whether this house follows the organisation, has its own quiet hours, or none.';
    }

    public function toClient(): array
    {
        return [];
    }

    /** @return array{mode: string, from: string, until: string}|null */
    public function decode(string $value): ?array
    {
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $this->fromArray($decoded) : null;
    }

    /** @param array{mode: string, from: string, until: string} $q */
    public function encode(array $q): string
    {
        return json_encode($q['mode'] === self::OWN
            ? ['mode' => self::OWN, 'from' => $q['from'], 'until' => $q['until']]
            : ['mode' => $q['mode']]);
    }

    /**
     * @param  array<mixed>  $value
     * @return array{mode: string, from: string, until: string}|null
     */
    private function fromArray(array $value): ?array
    {
        $mode = $value['mode'] ?? null;
        if (! in_array($mode, [self::ORG, self::OWN, self::NONE], true)) {
            return null;
        }
        if ($mode !== self::OWN) {
            return ['mode' => $mode, 'from' => '', 'until' => ''];
        }
        $time = fn (mixed $t): ?string => $t === null || $t === ''
            ? ''
            : (is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) === 1 ? $t : null);
        $from = $time($value['from'] ?? '');
        $until = $time($value['until'] ?? '');

        return $from === null || $until === null ? null : ['mode' => self::OWN, 'from' => $from, 'until' => $until];
    }
}
