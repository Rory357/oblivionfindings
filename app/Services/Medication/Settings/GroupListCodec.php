<?php

namespace App\Services\Medication\Settings;

use App\Services\Medication\Alerts\MedicationAlertCatalogue;

/**
 * A list of recipient groups (P11 v5 Delivery › "Escalate to"): canonical
 * JSON of offered group keys, in the catalogue's order, e.g.
 * ["houseLead","clinicalLead"].
 *
 * Loosening: dropping a group.
 */
final class GroupListCodec implements MedicationSettingCodec
{
    /** @param list<string> $offered */
    public function __construct(private readonly array $offered) {}

    public function kind(): string
    {
        return 'groups';
    }

    public function accepts(string $value): bool
    {
        $groups = json_decode($value, true);
        if (! is_array($groups) || ! array_is_list($groups) || count($groups) > count($this->offered)) {
            return false;
        }
        foreach ($groups as $group) {
            if (! is_string($group) || ! in_array($group, $this->offered, true)) {
                return false;
            }
        }

        return true;
    }

    public function normalise(mixed $value, string $default): string
    {
        $groups = is_string($value) ? json_decode($value, true) : null;
        if (! is_array($groups)) {
            return $default;
        }

        return json_encode(array_values(array_filter(
            $this->offered,
            fn (string $group): bool => in_array($group, $groups, true),
        )));
    }

    public function format(string $value): string
    {
        $groups = json_decode($value, true);
        $labels = is_array($groups)
            ? array_map(fn (string $group): string => MedicationAlertCatalogue::groupLabel($group), array_values(array_filter($groups, 'is_string')))
            : [];

        return $labels === [] ? 'Nobody chosen' : implode(', ', $labels);
    }

    public function loosens(string $from, string $to): bool
    {
        $before = json_decode($from, true);
        $after = json_decode($to, true);

        return is_array($before) && is_array($after) && array_diff($before, $after) !== [];
    }

    public function invalidMessage(string $label): string
    {
        return 'Choose who “'.$label.'” goes to from the listed groups.';
    }

    public function toClient(): array
    {
        return ['group_options' => array_map(fn (string $group): array => [
            'value' => $group,
            'label' => MedicationAlertCatalogue::groupLabel($group),
        ], $this->offered)];
    }
}
