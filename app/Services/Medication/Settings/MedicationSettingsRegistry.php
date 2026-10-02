<?php

namespace App\Services\Medication\Settings;

use App\Services\Medication\CompetencyPolicySettings;
use App\Services\Medication\DoseTimingSettings;
use App\Services\Medication\MedicationSafetyPolicySettings;
use App\Services\Medication\WitnessPinSettings;

/**
 * Every setting on Medication Settings that is saved through drafts, "Review
 * changes" and the change history (eMAR P11 B1). The values each setting
 * accepts and its default come from the service that enforces it, so the
 * page can never save a value the rules don't understand.
 *
 * The words are the approved P11 v5 / P00 v5 wording; they are what the
 * change history shows before and after a change.
 */
class MedicationSettingsRegistry
{
    public const VIEW_RULES = 'rules';

    public const VIEW_ROUNDS = 'rounds';

    public const VIEW_STAFF = 'staff';

    /** @var array<string, MedicationSettingGroup>|null */
    private ?array $groups = null;

    /** @return array<string, MedicationSettingGroup> */
    public function groups(): array
    {
        return $this->groups ??= collect($this->build())
            ->keyBy(fn (MedicationSettingGroup $group): string => $group->key)
            ->all();
    }

    public function group(string $key): ?MedicationSettingGroup
    {
        return $this->groups()[$key] ?? null;
    }

    public function definition(string $group, string $key): ?MedicationSettingDefinition
    {
        return $this->group($group)?->definition($key);
    }

    /** @return list<MedicationSettingDefinition> */
    public function definitions(): array
    {
        return collect($this->groups())
            ->flatMap(fn (MedicationSettingGroup $group): array => $group->definitions)
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function views(): array
    {
        return collect($this->groups())
            ->map(fn (MedicationSettingGroup $group): string => $group->view)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array{groups: array<string, array<string, mixed>>, definitions: array<string, array<string, array<string, mixed>>>} */
    public function toClient(): array
    {
        return [
            'groups' => collect($this->groups())->map->toClient()->all(),
            'definitions' => collect($this->groups())
                ->map(fn (MedicationSettingGroup $group): array => collect($group->definitions)
                    ->mapWithKeys(fn (MedicationSettingDefinition $d): array => [$d->key => $d->toClient()])
                    ->all())
                ->all(),
        ];
    }

    /** @return list<MedicationSettingGroup> */
    protected function build(): array
    {
        return [$this->safetyChecks(), $this->doseTiming(), $this->competencyPolicy(), $this->witnessPinRules()];
    }

    private function safetyChecks(): MedicationSettingGroup
    {
        $safety = MedicationSafetyPolicySettings::class;
        $organisation = MedicationSettingDefinition::SCOPE_ORGANISATION;

        return new MedicationSettingGroup(
            key: 'safety',
            view: self::VIEW_RULES,
            effect: 'From the next dose signed, at every house',
            auditEvent: 'medications.safety_policy.updated',
            definitions: [
                new MedicationSettingDefinition(
                    group: 'safety',
                    key: 'profile_allergy_match',
                    storageKey: $safety::PROFILE_ALLERGY_MATCH,
                    scope: $organisation,
                    section: 'safety',
                    label: 'When a medicine matches a recorded allergy',
                    options: $this->words($safety::OPTIONS[$safety::PROFILE_ALLERGY_MATCH], [
                        'warn' => 'Warn — show the match; “given” can still be recorded',
                        'block' => 'Block — contact the prescriber or an authorised override',
                    ]),
                    default: $safety::DEFAULTS[$safety::PROFILE_ALLERGY_MATCH],
                    rank: ['warn', 'confirm', 'block'],
                ),
                new MedicationSettingDefinition(
                    group: 'safety',
                    key: 'restricted_competency',
                    storageKey: $safety::RESTRICTED_COMPETENCY,
                    scope: $organisation,
                    section: 'safety',
                    label: 'A worker’s medication competency is marked restricted',
                    options: $this->words($safety::OPTIONS[$safety::RESTRICTED_COMPETENCY], [
                        'off' => 'Off — no extra check',
                        'block' => 'Block — they can’t sign as given; the dialog shows who on shift can give it',
                        'cosigner' => 'Co-signer with witness PIN (recommended) — a present, qualified colleague confirms each dose',
                    ]),
                    default: $safety::DEFAULTS[$safety::RESTRICTED_COMPETENCY],
                    rank: ['off', 'cosigner', 'block'],
                ),
                new MedicationSettingDefinition(
                    group: 'safety',
                    key: 'competency_areas',
                    storageKey: $safety::COMPETENCY_AREAS,
                    scope: $organisation,
                    section: 'safety',
                    label: 'The controlled-drug or covert area wasn’t passed',
                    options: $this->words($safety::OPTIONS[$safety::COMPETENCY_AREAS], [
                        'off' => 'Off — no extra check',
                        'failed' => 'Block when the area was failed',
                        'failed_or_not_seen' => 'Block when failed or not seen at assessment',
                    ]),
                    default: $safety::DEFAULTS[$safety::COMPETENCY_AREAS],
                    rank: ['off', 'failed', 'failed_or_not_seen'],
                ),
            ],
        );
    }

    private function doseTiming(): MedicationSettingGroup
    {
        $timing = DoseTimingSettings::class;
        $numeric = fn (string $direction): array => ['direction' => $direction, 'off' => null, 'off_is_loosest' => false];
        $number = fn (string $key, string $storageKey, string $label, string $unit, ?array $loosening, ?string $pairedWith = null): MedicationSettingDefinition => new MedicationSettingDefinition(
            group: 'timing',
            key: $key,
            storageKey: $storageKey,
            scope: MedicationSettingDefinition::SCOPE_ORGANISATION,
            section: 'timing',
            label: $label,
            options: [],
            default: $timing::default($storageKey),
            numeric: $loosening,
            range: $timing::RANGES[$storageKey],
            unit: $unit,
            pairedWith: $pairedWith,
        );
        $looser = $numeric(MedicationSettingDefinition::HIGHER_IS_LOOSER);

        return new MedicationSettingGroup(
            key: 'timing',
            view: self::VIEW_ROUNDS,
            effect: 'From the next dose shown on Meds today, at every house — recording is never blocked',
            auditEvent: 'medications.mar_timing.updated',
            definitions: [
                $number('early', $timing::EARLY_MINUTES, 'Doses can be given from', 'minutes before the dose time', $looser),
                // Meds today, the MAR schedule and MAR rows read it (P01 C6(b)).
                // It only changes what's highlighted, so no change loosens a check.
                $number('due_soon', $timing::DUE_SOON_MINUTES, 'Doses show as due soon', 'minutes before the dose time', null),
                $number('late', $timing::LATE_MINUTES, 'Doses count as late', 'minutes after the dose time', $looser),
                $number('late_incident', $timing::LATE_INCIDENT_MINUTES, 'A late dose raises an incident', 'minutes after the dose time', $looser),
                $number('refusal_count', $timing::REFUSAL_COUNT, 'Repeated refusals escalate', 'refusals or withholds', $looser, 'refusal_days'),
                $number('refusal_days', $timing::REFUSAL_DAYS, 'Repeated refusals escalate — within', 'days', $numeric(MedicationSettingDefinition::HIGHER_IS_STRICTER), 'refusal_count'),
            ],
        );
    }

    private function competencyPolicy(): MedicationSettingGroup
    {
        $policy = CompetencyPolicySettings::class;
        $number = fn (string $key, string $storageKey, string $section, string $label, string $unit, string $direction): MedicationSettingDefinition => new MedicationSettingDefinition(
            group: 'elig',
            key: $key,
            storageKey: $storageKey,
            scope: MedicationSettingDefinition::SCOPE_ORGANISATION,
            section: $section,
            label: $label,
            options: [],
            default: $policy::default($storageKey),
            numeric: ['direction' => $direction, 'off' => null, 'off_is_loosest' => false],
            range: $policy::RANGES[$storageKey],
            unit: $unit,
        );
        $looser = MedicationSettingDefinition::HIGHER_IS_LOOSER;
        $stricter = MedicationSettingDefinition::HIGHER_IS_STRICTER;
        $organisation = MedicationSettingDefinition::SCOPE_ORGANISATION;

        return new MedicationSettingGroup(
            key: 'elig',
            view: self::VIEW_STAFF,
            effect: 'From the next assessment or exemption recorded, at every house — existing ones keep their end dates',
            auditEvent: 'medications.competency_policy.updated',
            definitions: [
                $number('validity', $policy::VALIDITY_MONTHS, 'competency', 'An assessment stays current for', 'months', $looser),
                $number('pass_mark', $policy::PASS_MARK, 'competency', 'Pass mark', 'of the 12 areas passed', $stricter),
                // P11 Q-B: off by default — only the pass mark counts.
                new MedicationSettingDefinition(
                    group: 'elig',
                    key: 'core_must_pass',
                    storageKey: $policy::CORE_AREAS_MUST_PASS,
                    scope: $organisation,
                    section: 'competency',
                    label: 'Every core area must be passed',
                    options: ['no' => 'Off — only the pass mark counts', 'yes' => 'On'],
                    default: 'no',
                    rank: ['no', 'yes'],
                ),
                // P11 Q-C: off until the organisation chooses a number; when on,
                // an assessment logging fewer isn't saved.
                new MedicationSettingDefinition(
                    group: 'elig',
                    key: 'observed_minimum',
                    storageKey: $policy::OBSERVED_MINIMUM,
                    scope: $organisation,
                    section: 'competency',
                    label: 'Minimum observed administrations',
                    options: [],
                    default: $policy::OBSERVED_MINIMUM_OFF,
                    numeric: ['direction' => $stricter, 'off' => $policy::OBSERVED_MINIMUM_OFF, 'off_is_loosest' => true],
                    range: $policy::OBSERVED_RANGE,
                    unit: 'observed administrations',
                    offLabel: 'Off — no minimum',
                    whenNotConfigured: 'No minimum is asked for',
                ),
                // More notice before an assessment ends is stricter.
                $number('reminder', $policy::RENEWAL_REMINDER_DAYS, 'competency', 'Renewal reminder', 'days before the end date', $stricter),
                $number('longest_exemption', $policy::LONGEST_EXEMPTION_DAYS, 'exemptions', 'Longest exemption', 'days', $looser),
            ],
        );
    }

    private function witnessPinRules(): MedicationSettingGroup
    {
        $pins = WitnessPinSettings::class;
        // P11 Q-F: whole numbers within each rule's range; renewal can be off.
        $number = fn (string $key, string $storageKey, string $label, string $unit, string $direction, ?string $off = null, ?string $offLabel = null): MedicationSettingDefinition => new MedicationSettingDefinition(
            group: 'pin',
            key: $key,
            storageKey: $storageKey,
            scope: MedicationSettingDefinition::SCOPE_ORGANISATION,
            section: 'pins',
            label: $label,
            options: [],
            default: $pins::DEFAULTS[$storageKey],
            numeric: ['direction' => $direction, 'off' => $off, 'off_is_loosest' => $off !== null],
            range: $pins::RANGES[$storageKey],
            unit: $unit,
            offLabel: $offLabel,
        );

        return new MedicationSettingGroup(
            key: 'pin',
            view: self::VIEW_STAFF,
            effect: 'From the next dose signed or witnessed, at every house',
            auditEvent: 'medications.witness_pin_rules.updated',
            definitions: [
                $number('max_attempts', $pins::MAX_ATTEMPTS, 'Wrong attempts before a PIN locks', 'attempts', MedicationSettingDefinition::HIGHER_IS_LOOSER),
                $number('lockout_minutes', $pins::LOCKOUT_MINUTES, 'How long a locked PIN stays locked', 'minutes', MedicationSettingDefinition::HIGHER_IS_STRICTER),
                $number('renewal_months', $pins::RENEWAL_MONTHS, 'PIN renewal (optional)', 'months', MedicationSettingDefinition::HIGHER_IS_LOOSER, $pins::RENEWAL_OFF, 'No renewal'),
            ],
        );
    }

    /**
     * The words for each value the enforcing service accepts, in its order.
     *
     * @param  list<string>  $accepted
     * @param  array<string, string>  $words
     * @return array<string, string>
     */
    private function words(array $accepted, array $words): array
    {
        return collect($accepted)
            ->mapWithKeys(fn (string $value): array => [$value => $words[$value] ?? $value])
            ->all();
    }
}
