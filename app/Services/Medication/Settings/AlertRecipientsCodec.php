<?php

namespace App\Services\Medication\Settings;

use App\Models\User;
use App\Services\Medication\Alerts\MedicationAlertCatalogue;

/**
 * Who gets one medication alert, organisation-wide (P11 v5 Alerts table and
 * "Who gets it"): its channels, Follow up, the recipient groups switched on
 * and the people named for every house.
 *
 * Stored as canonical JSON, e.g.
 * {"inapp":true,"email":false,"push":false,"follow_up":true,"groups":["rostered","houseLead"],"people":[12]}.
 * Groups keep the catalogue's order, decided groups are always on (and so is
 * in-app for a decided alert), and people are unique ids in order.
 *
 * At least one channel that sends today is always on (B2 C1 review): a change
 * that switches every one off is refused, and a stored value with none reads
 * with in-app on — in-app stays on until email or push is.
 *
 * Loosening (v5 `loosens`): a channel or Follow up switched off, or any group
 * or named person dropped.
 */
final class AlertRecipientsCodec implements MedicationSettingCodec
{
    private const MAX_PEOPLE = 200;

    public function __construct(private readonly string $alertKey) {}

    public function kind(): string
    {
        return 'alert';
    }

    /** The default: in-app on, email and push off, the catalogue's groups. */
    public function defaultValue(): string
    {
        $alert = MedicationAlertCatalogue::get($this->alertKey);

        return $this->encode([
            'inapp' => true,
            'email' => false,
            'push' => false,
            'follow_up' => (bool) ($alert['follow_up'] ?? false),
            'groups' => $alert['default'] ?? [],
            'people' => [],
        ]);
    }

    public function accepts(string $value): bool
    {
        if (strlen($value) > 4000) {
            return false;
        }
        $setting = $this->decode($value, strict: true);

        return $setting !== null && ($this->hasLocked() || $this->sendsToday($setting));
    }

    public function normalise(mixed $value, string $default): string
    {
        $decoded = is_string($value) ? $this->decode($value, strict: false) : null;

        return $decoded === null ? $default : $this->encode($decoded);
    }

    /**
     * The words the change history and "Review changes" use, for the channels
     * that send today: "In-app on · Everyone rostered on a covering shift,
     * House lead, Hana Kereama".
     */
    public function format(string $value): string
    {
        $setting = $this->decode($value, strict: false);
        if ($setting === null) {
            return $value;
        }
        $channels = collect(['inapp' => 'In-app', 'email' => 'email', 'push' => 'push'])
            ->filter(fn (string $label, string $channel): bool => in_array($channel, MedicationAlertCatalogue::CHANNELS_BUILT, true))
            ->map(fn (string $label, string $channel): string => $label.' '.($setting[$channel] ? 'on' : 'off'))
            ->values()
            ->all();
        $names = User::query()->whereIn('id', $setting['people'])->pluck('name', 'id');
        $who = [
            ...array_map(fn (string $group): string => MedicationAlertCatalogue::groupLabel($group), $setting['groups']),
            ...array_map(fn (int $id): string => (string) ($names[$id] ?? 'A former staff member'), $setting['people']),
        ];

        return implode(' · ', [...$channels, $who === [] ? 'nobody' : implode(', ', $who)]);
    }

    public function loosens(string $from, string $to): bool
    {
        $before = $this->decode($from, strict: false);
        $after = $this->decode($to, strict: false);
        if ($before === null || $after === null || $from === $to) {
            return false;
        }
        foreach (['inapp', 'email', 'push', 'follow_up'] as $switch) {
            if ($before[$switch] && ! $after[$switch]) {
                return true;
            }
        }

        return array_diff($before['groups'], $after['groups']) !== []
            || array_diff($before['people'], $after['people']) !== [];
    }

    /** $label is the setting's ("Who gets …"); the message names the alert itself. */
    public function invalidMessage(string $label): string
    {
        $alert = MedicationAlertCatalogue::get($this->alertKey)['label'] ?? $label;

        return 'Choose who gets “'.$alert.'” from the listed groups and people, with at least one way to tell them switched on.';
    }

    public function toClient(): array
    {
        $alert = MedicationAlertCatalogue::get($this->alertKey) ?? [];

        $offered = MedicationAlertCatalogue::offeredGroups($this->alertKey);

        return ['alert' => [
            'key' => $this->alertKey,
            'label' => $alert['label'] ?? $this->alertKey,
            'subline' => $alert['subline'] ?? '',
            'groups' => $offered,
            'locked' => array_values(array_intersect($alert['locked'] ?? [], $offered)),
            'group_labels' => collect($offered)
                ->mapWithKeys(fn (string $group): array => [$group => MedicationAlertCatalogue::GROUPS[$group]])
                ->all(),
            'controlled' => (bool) ($alert['controlled'] ?? false),
            'until' => $alert['until'] ?? '',
            'channels' => MedicationAlertCatalogue::CHANNELS_BUILT,
        ]];
    }

    /**
     * Read a value. Strict: exactly the expected shape, only offered groups.
     * Lenient (stored values): unknown groups are dropped and decided groups
     * put back, so a catalogue change never loses the rest of a choice.
     *
     * @return array{inapp: bool, email: bool, push: bool, follow_up: bool, groups: list<string>, people: list<int>}|null
     */
    private function decode(string $value, bool $strict): ?array
    {
        $data = json_decode($value, true);
        if (! is_array($data)) {
            return null;
        }
        foreach (['inapp', 'email', 'push', 'follow_up'] as $switch) {
            if (! array_key_exists($switch, $data) || ! is_bool($data[$switch])) {
                return null;
            }
        }
        if (! is_array($data['groups'] ?? null) || ! is_array($data['people'] ?? null) || ! array_is_list($data['groups']) || ! array_is_list($data['people'])) {
            return null;
        }
        if ($strict && array_diff(array_keys($data), ['inapp', 'email', 'push', 'follow_up', 'groups', 'people']) !== []) {
            return null;
        }
        $offered = MedicationAlertCatalogue::offeredGroups($this->alertKey);
        foreach ($data['groups'] as $group) {
            if (! is_string($group) || ($strict && ! in_array($group, $offered, true))) {
                return null;
            }
        }
        if (count($data['people']) > self::MAX_PEOPLE) {
            return null;
        }
        foreach ($data['people'] as $id) {
            if (! is_int($id) || $id <= 0) {
                return null;
            }
        }

        return [
            'inapp' => $data['inapp'],
            'email' => $data['email'],
            'push' => $data['push'],
            'follow_up' => $data['follow_up'],
            'groups' => array_values(array_filter($data['groups'], fn (string $group): bool => in_array($group, $offered, true))),
            'people' => $data['people'],
        ];
    }

    /** @param array{inapp: bool, email: bool, push: bool, follow_up: bool, groups: list<string>, people: list<int>} $setting */
    private function sendsToday(array $setting): bool
    {
        foreach (MedicationAlertCatalogue::CHANNELS_BUILT as $channel) {
            if ($setting[$channel] ?? false) {
                return true;
            }
        }

        return false;
    }

    private function hasLocked(): bool
    {
        $alert = MedicationAlertCatalogue::get($this->alertKey) ?? [];

        return array_intersect($alert['locked'] ?? [], MedicationAlertCatalogue::offeredGroups($this->alertKey)) !== [];
    }

    /** @param array{inapp: bool, email: bool, push: bool, follow_up: bool, groups: list<string>, people: list<int>} $setting */
    private function encode(array $setting): string
    {
        $alert = MedicationAlertCatalogue::get($this->alertKey) ?? [];
        $offered = MedicationAlertCatalogue::offeredGroups($this->alertKey);
        $locked = array_values(array_intersect($alert['locked'] ?? [], $offered));
        $chosen = array_unique([...$setting['groups'], ...$locked]);
        $people = array_values(array_unique(array_map('intval', $setting['people'])));
        sort($people);

        return json_encode([
            // A decided alert is always in the bell (v5 "Always on"), and so
            // is any alert with no other channel that sends today.
            'inapp' => $locked !== [] || ! $this->sendsToday([...$setting, 'inapp' => false]) ? true : $setting['inapp'],
            'email' => $setting['email'],
            'push' => $setting['push'],
            'follow_up' => $setting['follow_up'],
            'groups' => array_values(array_filter($offered, fn (string $group): bool => in_array($group, $chosen, true))),
            'people' => $people,
        ], JSON_UNESCAPED_UNICODE);
    }
}
