<?php

namespace App\Services\Medication\Settings;

use App\Models\BreakGlassPolicy;
use App\Services\Medication\Alerts\MedicationAlertCatalogue;
use App\Services\Medication\CompetencyPolicySettings;
use App\Services\Medication\Controlled\ControlledSettingsFragment;
use App\Services\Medication\DoseTimingSettings;
use App\Services\Medication\MedicationErrorWorkflow;
use App\Services\Medication\MedicationSafetyPolicySettings;
use App\Services\Medication\Reviews\MedicationReviewCadence;
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

    public const VIEW_ALERTS = 'alerts';

    /** Who gets each alert, organisation-wide: `medications.alerts.{alert}`. */
    public const ALERT_STORAGE_PREFIX = 'medications.alerts.';

    /** A house's extra people for each alert: `medications.alert_extras.{alert}` per Site. */
    public const ALERT_EXTRAS_STORAGE_PREFIX = 'medications.alert_extras.';

    /** Email and push leave out client names and medicines (P11 v5 Delivery › Email). */
    public const DELIVERY_PRIVATE = 'medications.alert_delivery.private';

    public const DELIVERY_PIN_UNATTENDED = 'medications.alert_delivery.pin_unattended';

    /** Follow-up (P11 v5 Delivery, B2 chunk 3): re-alerting, attended, escalation. */
    public const DELIVERY_REALERT_EVERY = 'medications.alert_delivery.realert_every';

    public const DELIVERY_REALERT_MAX = 'medications.alert_delivery.realert_max';

    public const DELIVERY_ATTENDED = 'medications.alert_delivery.attended';

    public const DELIVERY_ESCALATE_AFTER = 'medications.alert_delivery.escalate_after';

    public const DELIVERY_ESCALATE_TO = 'medications.alert_delivery.escalate_to';

    /**
     * Quiet hours (P11 v5 Delivery › Quiet hours, B2 chunk 5): the
     * organisation default, then each house follows it, sets its own or has
     * none (Q12). NZ wall-clock times; "off" when not set.
     */
    public const DELIVERY_QUIET_FROM = 'medications.alert_delivery.quiet_from';

    public const DELIVERY_QUIET_UNTIL = 'medications.alert_delivery.quiet_until';

    /** A house's quiet hours: `medications.alert_quiet_hours` per Site. */
    public const QUIET_HOUSE_STORAGE = 'medications.alert_quiet_hours';

    /** "Off" for the follow-up numbers: each alert is sent once, nobody else is told. */
    public const FOLLOW_UP_OFF = 'off';

    /** Who an escalation can add (v5 FOLLOW_TO; the on-call person since B2 chunk 4). */
    public const ESCALATE_TO_GROUPS = [
        MedicationAlertCatalogue::HOUSE_LEAD,
        MedicationAlertCatalogue::ON_CALL,
        MedicationAlertCatalogue::CLINICAL_LEAD,
        MedicationAlertCatalogue::PROVIDER_MANAGER,
    ];

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
        return [
            $this->safetyChecks(),
            $this->doseTiming(),
            $this->competencyPolicy(),
            $this->witnessPinRules(),
            $this->alertRecipients(),
            $this->alertExtras(),
            $this->alertDelivery(),
            $this->quietHouses(),
            $this->errorTriage(),
            MedicationReviewCadence::settingsGroup(),
            ...ControlledSettingsFragment::groups(),
            \App\Services\Medication\Reporting\RecordsReportingSettings::group(),
            $this->emergencyPolicy(),
        ];
    }

    /**
     * Who gets each medication alert (P11 v5 Alerts & access › Alerts): one
     * setting per alert something raises today, organisation-wide. Each
     * starts as v5's proposal (approved with P11 v5, B2 Q1), shown as
     * "Default — not yet reviewed".
     */
    private function alertRecipients(): MedicationSettingGroup
    {
        return new MedicationSettingGroup(
            key: 'alerts',
            view: self::VIEW_ALERTS,
            effect: 'From the next alert sent, at every house',
            auditEvent: 'medications.alert_recipients.updated',
            definitions: array_map(function (string $key): MedicationSettingDefinition {
                $codec = new AlertRecipientsCodec($key);
                $label = MedicationAlertCatalogue::ALERTS[$key]['label'];

                return new MedicationSettingDefinition(
                    group: 'alerts',
                    key: $key,
                    storageKey: self::ALERT_STORAGE_PREFIX.$key,
                    scope: MedicationSettingDefinition::SCOPE_ORGANISATION,
                    section: 'alerts',
                    label: 'Who gets “'.$label.'”',
                    options: [],
                    default: $codec->defaultValue(),
                    codec: $codec,
                    decideLabel: 'Alert: '.$label,
                );
            }, MedicationAlertCatalogue::built()),
        );
    }

    /**
     * Extra people a house adds to an alert, only when the alert is about
     * that house (P11 v5 "House extras"). House managers change their own
     * houses' extras (`medications.alerts.manage_house`, B2 Q3).
     */
    private function alertExtras(): MedicationSettingGroup
    {
        return new MedicationSettingGroup(
            key: 'alertExtra',
            view: self::VIEW_ALERTS,
            effect: 'From the next alert at that house',
            auditEvent: 'medications.alert_recipients.updated',
            definitions: array_map(fn (string $key): MedicationSettingDefinition => new MedicationSettingDefinition(
                group: 'alertExtra',
                key: $key,
                storageKey: self::ALERT_EXTRAS_STORAGE_PREFIX.$key,
                scope: MedicationSettingDefinition::SCOPE_SITE,
                section: 'alerts',
                label: MedicationAlertCatalogue::ALERTS[$key]['label'].' — extra people',
                options: [],
                default: '[]',
                codec: new PeopleListCodec,
                houseManaged: true,
            ), MedicationAlertCatalogue::built()),
        );
    }

    /**
     * How alerts reach people (P11 v5 Alerts & access › Delivery), for every
     * house. B2 chunk 2: whether email and push leave out client names and
     * medicines — on by default, so a lock screen or an inbox never shows
     * them. Switching it off is a loosening.
     *
     * B2 chunk 3, follow-up for alerts with Follow up on: re-alert everyone
     * told so far every N minutes up to M times, and escalate to more groups
     * after N minutes, until someone attends. Both start off (today: each
     * alert is sent once). What counts as attended runs dealt with >
     * acknowledged > opened; moving towards "opened" is a loosening.
     *
     * B2 chunk 5, the organisation's quiet hours: email and push for alerts
     * without Follow up wait until they end; the bell never waits.
     */
    private function alertDelivery(): MedicationSettingGroup
    {
        $off = self::FOLLOW_UP_OFF;
        $number = fn (string $key, string $storageKey, string $label, string $unit, array $range, string $direction, string $offLabel, ?string $pairedWith = null): MedicationSettingDefinition => new MedicationSettingDefinition(
            group: 'delivery',
            key: $key,
            storageKey: $storageKey,
            scope: MedicationSettingDefinition::SCOPE_ORGANISATION,
            section: 'delivery',
            label: $label,
            options: [],
            default: $off,
            numeric: ['direction' => $direction, 'off' => $off, 'off_is_loosest' => true],
            range: $range,
            unit: $unit,
            pairedWith: $pairedWith,
            offLabel: $offLabel,
        );

        return new MedicationSettingGroup(
            key: 'delivery',
            view: self::VIEW_ALERTS,
            effect: 'From the next alert sent, at every house',
            auditEvent: 'medications.alert_delivery.updated',
            definitions: [
                new MedicationSettingDefinition(
                    group: 'delivery',
                    key: 'private',
                    storageKey: self::DELIVERY_PRIVATE,
                    scope: MedicationSettingDefinition::SCOPE_ORGANISATION,
                    section: 'delivery',
                    label: 'Keep client names and medicines out of email and push',
                    options: ['no' => 'Off — email and push include client names and medicines', 'yes' => 'On'],
                    default: 'yes',
                    rank: ['no', 'yes'],
                ),
                new MedicationSettingDefinition(
                    group: 'delivery', key: 'pin_unattended', storageKey: self::DELIVERY_PIN_UNATTENDED,
                    scope: MedicationSettingDefinition::SCOPE_ORGANISATION, section: 'delivery',
                    label: 'Keep unattended alerts at the top of the bell', options: ['no' => 'Off', 'yes' => 'On — medication follow-up alerts only'], default: 'no',
                ),
                // Checked every 15 minutes, so 15 is the shortest interval.
                $number('realert_every', self::DELIVERY_REALERT_EVERY, 'Re-alert until someone attends', 'minutes between re-alerts', [15, 1440], MedicationSettingDefinition::HIGHER_IS_LOOSER, 'Off — each alert is sent once', 'realert_max'),
                $number('realert_max', self::DELIVERY_REALERT_MAX, 'Most re-alerts', 'times', [1, 10], MedicationSettingDefinition::HIGHER_IS_STRICTER, 'Off', 'realert_every'),
                new MedicationSettingDefinition(
                    group: 'delivery',
                    key: 'attended',
                    storageKey: self::DELIVERY_ATTENDED,
                    scope: MedicationSettingDefinition::SCOPE_ORGANISATION,
                    section: 'delivery',
                    label: 'An alert counts as attended when',
                    options: ['open' => 'Someone opens it', 'ack' => 'Someone acknowledges it', 'done' => 'It’s dealt with'],
                    default: 'ack',
                    rank: ['open', 'ack', 'done'],
                ),
                $number('escalate_after', self::DELIVERY_ESCALATE_AFTER, 'Escalate if still not attended', 'minutes', [15, 1440], MedicationSettingDefinition::HIGHER_IS_LOOSER, 'Off — nobody else is told'),
                new MedicationSettingDefinition(
                    group: 'delivery',
                    key: 'escalate_to',
                    storageKey: self::DELIVERY_ESCALATE_TO,
                    scope: MedicationSettingDefinition::SCOPE_ORGANISATION,
                    section: 'delivery',
                    label: 'Escalate to',
                    options: [],
                    default: '[]',
                    codec: new GroupListCodec(self::ESCALATE_TO_GROUPS),
                ),
                // B2 chunk 5: both off (today: every alert is sent straight
                // away) until someone chooses the times — no default times.
                new MedicationSettingDefinition(
                    group: 'delivery',
                    key: 'quiet_from',
                    storageKey: self::DELIVERY_QUIET_FROM,
                    scope: MedicationSettingDefinition::SCOPE_ORGANISATION,
                    section: 'delivery',
                    label: 'Hold non-urgent alerts overnight',
                    options: [],
                    default: TimeCodec::OFF,
                    pairedWith: 'quiet_until',
                    codec: new TimeCodec('Off — sent straight away', 'From'),
                ),
                new MedicationSettingDefinition(
                    group: 'delivery',
                    key: 'quiet_until',
                    storageKey: self::DELIVERY_QUIET_UNTIL,
                    scope: MedicationSettingDefinition::SCOPE_ORGANISATION,
                    section: 'delivery',
                    label: 'Quiet hours end',
                    options: [],
                    default: TimeCodec::OFF,
                    pairedWith: 'quiet_from',
                    codec: new TimeCodec('Off', 'Until'),
                ),
            ],
        );
    }

    /**
     * Each house's quiet hours (P11 v5 Q12): follow the organisation, its own
     * hours, or none. House managers change their own houses
     * (`medications.alerts.manage_house`); the organisation default needs
     * all-sites authority.
     */
    private function quietHouses(): MedicationSettingGroup
    {
        return new MedicationSettingGroup(
            key: 'quietHouse',
            view: self::VIEW_ALERTS,
            effect: 'From tonight, at that house',
            auditEvent: 'medications.alert_quiet_hours.updated',
            definitions: [
                new MedicationSettingDefinition(
                    group: 'quietHouse',
                    key: 'hours',
                    storageKey: self::QUIET_HOUSE_STORAGE,
                    scope: MedicationSettingDefinition::SCOPE_SITE,
                    section: 'delivery',
                    label: 'Quiet hours',
                    options: [],
                    default: QuietHoursCodec::DEFAULT,
                    codec: new QuietHoursCodec,
                    houseManaged: true,
                ),
            ],
        );
    }

    private function emergencyPolicy(): MedicationSettingGroup
    {
        $defaults = BreakGlassPolicy::defaults();
        $number = fn (string $key, string $label, string $unit, array $range, string $direction, ?string $pair = null): MedicationSettingDefinition => new MedicationSettingDefinition(
            group: 'ea', key: $key, storageKey: 'medications.emergency_policy.'.$key,
            scope: MedicationSettingDefinition::SCOPE_ORGANISATION, section: 'emergency', label: $label,
            options: [], default: (string) $defaults[$key], numeric: ['direction' => $direction, 'off' => null, 'off_is_loosest' => false], range: $range, unit: $unit, pairedWith: $pair,
        );

        return new MedicationSettingGroup(
            key: 'ea', view: self::VIEW_ALERTS, effect: 'From the next emergency-access grant or extension', auditEvent: 'medications.emergency_policy.updated',
            definitions: [
                $number('default_minutes', 'A grant lasts', 'minutes', [5, 1440], MedicationSettingDefinition::HIGHER_IS_LOOSER),
                $number('extend_minutes', 'Each extension adds', 'minutes', [5, 1440], MedicationSettingDefinition::HIGHER_IS_LOOSER),
                $number('max_minutes', 'Longest time in all', 'minutes', [5, 1440], MedicationSettingDefinition::HIGHER_IS_LOOSER),
                new MedicationSettingDefinition(group: 'ea', key: 'reason_required', storageKey: 'medications.emergency_policy.reason_required', scope: MedicationSettingDefinition::SCOPE_ORGANISATION, section: 'emergency', label: 'Ask for a reason', options: ['no' => 'Off — no reason is asked for', 'yes' => 'On'], default: 'yes', rank: ['no', 'yes']),
                $number('repeat_threshold_count', 'Flag repeat use', 'grants within', [1, 100], MedicationSettingDefinition::HIGHER_IS_LOOSER, 'repeat_window_days'),
                $number('repeat_window_days', 'Repeat-use window', 'days', [1, 90], MedicationSettingDefinition::HIGHER_IS_STRICTER, 'repeat_threshold_count'),
                // P10 owns the canonical fields/migration and per-grant snapshots.
                ...array_key_exists('second_person', $defaults) ? [
                    new MedicationSettingDefinition(group: 'ea', key: 'second_person', storageKey: 'medications.emergency_policy.second_person', scope: MedicationSettingDefinition::SCOPE_ORGANISATION, section: 'emergency', label: 'A second person confirms a grant', options: ['off' => 'Not asked', 'optional' => 'Optional — the person starting it chooses', 'required' => 'Required — it cannot start without one'], default: (string) $defaults['second_person'], rank: ['off', 'optional', 'required']),
                ] : [],
                ...array_key_exists('review_days', $defaults) ? [
                    new MedicationSettingDefinition(group: 'ea', key: 'review_days', storageKey: 'medications.emergency_policy.review_days', scope: MedicationSettingDefinition::SCOPE_ORGANISATION, section: 'emergency', label: 'A review is due within', options: ['1' => '1 day', '2' => '2 days', '3' => '3 days'], default: (string) $defaults['review_days'], rank: ['3', '2', '1']),
                ] : [],
            ],
        );
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

    private function errorTriage(): MedicationSettingGroup
    {
        return new MedicationSettingGroup(
            key: 'errorTriage', view: self::VIEW_ALERTS,
            effect: 'From the next medication error reported, at every house — existing reports keep their due time',
            auditEvent: 'medications.error_triage.updated',
            definitions: [new MedicationSettingDefinition(
                group: 'errorTriage', key: 'due', storageKey: MedicationErrorWorkflow::TRIAGE_DUE,
                scope: MedicationSettingDefinition::SCOPE_ORGANISATION, section: 'triage',
                label: 'Medication errors must be triaged', options: [
                    'fourHours' => 'Within 4 hours', 'endOfDay' => 'By the end of the day', 'nextDay' => 'By the end of the next day',
                ], default: 'nextDay', rank: ['nextDay', 'endOfDay', 'fourHours'],
            )],
        );
    }
}
