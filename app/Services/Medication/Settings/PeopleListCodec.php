<?php

namespace App\Services\Medication\Settings;

use App\Models\User;

/**
 * A list of people (P11 v5 "House extras": the extra people a house adds to
 * an alert). Stored as canonical JSON — unique person ids in order, e.g.
 * [4,12]. Dropping anyone loosens the check.
 */
final class PeopleListCodec implements MedicationSettingCodec
{
    private const MAX_PEOPLE = 100;

    public function __construct(private readonly string $emptyLabel = 'Nobody extra') {}

    public function kind(): string
    {
        return 'people';
    }

    public function accepts(string $value): bool
    {
        return strlen($value) <= 2000 && $this->decode($value) !== null;
    }

    public function normalise(mixed $value, string $default): string
    {
        $ids = is_string($value) ? $this->decode($value) : null;

        return $ids === null ? $default : $this->encode($ids);
    }

    public function format(string $value): string
    {
        $ids = $this->decode($value) ?? [];
        if ($ids === []) {
            return $this->emptyLabel;
        }
        $names = User::query()->whereIn('id', $ids)->pluck('name', 'id');

        return implode(', ', array_map(fn (int $id): string => (string) ($names[$id] ?? 'A former staff member'), $ids));
    }

    public function loosens(string $from, string $to): bool
    {
        return array_diff($this->decode($from) ?? [], $this->decode($to) ?? []) !== [];
    }

    public function invalidMessage(string $label): string
    {
        return 'Choose the extra people for “'.$label.'” from the list.';
    }

    public function toClient(): array
    {
        return ['empty_label' => $this->emptyLabel];
    }

    /** @return list<int>|null */
    private function decode(string $value): ?array
    {
        $data = json_decode($value, true);
        if (! is_array($data) || ! array_is_list($data) || count($data) > self::MAX_PEOPLE) {
            return null;
        }
        foreach ($data as $id) {
            if (! is_int($id) || $id <= 0) {
                return null;
            }
        }

        return $data;
    }

    /** @param list<int> $ids */
    private function encode(array $ids): string
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        return json_encode($ids);
    }
}
