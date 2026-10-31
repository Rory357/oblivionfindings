<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Models\MonitoringRetentionPolicy;
use App\Domain\SecurityDevices\Config\DeviceTaxonomy;
use App\Domain\SecurityDevices\Enums\DeviceDomain;
use App\Support\ZonedLocalTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class MonitoringPolicyRules
{
    public const string RETENTION_CONFIRMATION = 'CONFIRM RETENTION CHANGE';

    /** @var array<string, MonitorKind> */
    private const array CAPABILITY_KINDS = [
        'reachability' => MonitorKind::Icmp,
        'service_port' => MonitorKind::Tcp,
        'dns_resolution' => MonitorKind::Dns,
        'web_endpoint' => MonitorKind::Http,
        'tls_certificate' => MonitorKind::Tls,
        'snmp_inventory' => MonitorKind::Snmp,
        'snmp_interface' => MonitorKind::SnmpInterface,
        'ssh_inventory' => MonitorKind::SshInventory,
        'winrm_inventory' => MonitorKind::WinRmInventory,
        'provider_health' => MonitorKind::Provider,
        'collector_health' => MonitorKind::Collector,
    ];

    /** @var list<string> */
    public const array ALLOWED_METRIC_KEYS = [
        'value',
        'latency_ms',
        'in_utilization_pct',
        'out_utilization_pct',
        'in_bps',
        'out_bps',
        'errors',
        'discards',
    ];

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function profile(array $attributes): array
    {
        $values = Validator::make([
            'threshold_direction' => 'above',
            'warning_rising_threshold' => null,
            'warning_falling_threshold' => null,
            ...$attributes,
        ], [
            'name' => ['required', 'string', 'min:3', 'max:128'],
            'description' => ['nullable', 'string', 'max:2000'],
            'interval_seconds' => ['required', 'integer', 'between:30,86400'],
            'failure_confirmations' => ['required', 'integer', 'between:1,20'],
            'failure_duration_seconds' => ['required', 'integer', 'between:0,86400'],
            'recovery_confirmations' => ['required', 'integer', 'between:1,20'],
            'recovery_duration_seconds' => ['required', 'integer', 'between:0,86400'],
            'stale_after_seconds' => ['required', 'integer', 'between:30,604800'],
            'threshold_direction' => ['required', Rule::in(['above', 'below'])],
            'warning_rising_threshold' => ['nullable', 'numeric'],
            'warning_falling_threshold' => ['nullable', 'numeric'],
            'rising_threshold' => ['nullable', 'numeric'],
            'falling_threshold' => ['nullable', 'numeric'],
            'baseline_window_seconds' => ['required', 'integer', 'between:60,604800'],
            'baseline_minimum_samples' => ['required', 'integer', 'between:2,10000'],
            'baseline_deviation_multiplier' => ['nullable', 'numeric', 'between:0.001,100'],
            'maintenance_policy' => ['required', Rule::in(['suppress_notifications_and_ticketing'])],
            'rollup_policy' => ['required', Rule::in(['worst_applicable'])],
            'retention_policy_id' => ['nullable', 'integer'],
        ])->validate();

        $values['name'] = trim((string) $values['name']);
        $values['description'] = isset($values['description']) ? trim((string) $values['description']) : null;
        if ((int) $values['stale_after_seconds'] < (int) $values['interval_seconds']) {
            $this->fail('stale_after_seconds', 'Stale time must be at least the monitoring interval.');
        }
        $this->validateThresholdBand(
            direction: (string) $values['threshold_direction'],
            warningRising: $values['warning_rising_threshold'],
            warningFalling: $values['warning_falling_threshold'],
            criticalRising: $values['rising_threshold'],
            criticalFalling: $values['falling_threshold'],
            criticalRisingField: 'rising_threshold',
            criticalFallingField: 'falling_threshold',
        );
        if ($values['baseline_deviation_multiplier'] !== null
            && (int) $values['baseline_window_seconds'] < (int) $values['interval_seconds'] * 2) {
            $this->fail('baseline_window_seconds', 'A baseline window must cover at least two monitoring intervals.');
        }
        if ($values['retention_policy_id'] !== null
            && ! MonitoringRetentionPolicy::query()
                ->whereKey((int) $values['retention_policy_id'])
                ->where('is_active', true)
                ->exists()) {
            $this->fail('retention_policy_id', 'The native monitoring retention policy is unavailable.');
        }

        return $values;
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function thresholdRule(array $attributes): array
    {
        $domains = array_map(fn (DeviceDomain $domain): string => $domain->value, DeviceDomain::cases());
        $monitorKinds = array_map(fn (MonitorKind $kind): string => $kind->value, MonitorKind::cases());

        $values = Validator::make([
            'site_id' => null,
            'device_id' => null,
            'monitor_id' => null,
            'device_domain' => null,
            'device_category' => null,
            'monitor_kind' => null,
            'metric_key' => null,
            'threshold_direction' => 'above',
            'warning_rising_threshold' => null,
            'warning_falling_threshold' => null,
            'critical_rising_threshold' => null,
            'critical_falling_threshold' => null,
            ...$attributes,
        ], [
            'name' => ['required', 'string', 'min:3', 'max:128'],
            'scope_kind' => ['required', Rule::in(['application', 'site', 'device', 'monitor'])],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
            'monitor_id' => ['nullable', 'integer', 'exists:monitors,id'],
            'device_domain' => ['nullable', Rule::in($domains)],
            'device_category' => ['nullable', 'string', 'max:64'],
            'monitor_kind' => ['nullable', Rule::in($monitorKinds)],
            'metric_key' => ['nullable', Rule::in(self::ALLOWED_METRIC_KEYS)],
            'threshold_direction' => ['required', Rule::in(['above', 'below'])],
            'warning_rising_threshold' => ['nullable', 'numeric'],
            'warning_falling_threshold' => ['nullable', 'numeric'],
            'critical_rising_threshold' => ['nullable', 'numeric'],
            'critical_falling_threshold' => ['nullable', 'numeric'],
        ])->validate();

        $this->assertScopedTargetShape(
            scope: (string) $values['scope_kind'],
            siteId: $values['site_id'],
            deviceId: $values['device_id'],
            monitorId: $values['monitor_id'],
        );

        $domain = isset($values['device_domain']) && $values['device_domain'] !== ''
            ? (string) $values['device_domain']
            : null;
        $category = isset($values['device_category']) && trim((string) $values['device_category']) !== ''
            ? strtolower(trim((string) $values['device_category']))
            : null;
        if ($category !== null && $domain === null) {
            $this->fail('device_category', 'Choose a device domain before filtering by device category.');
        }
        if ($category !== null && $domain !== null && ! array_key_exists($category, DeviceTaxonomy::categoriesFor($domain))) {
            $this->fail('device_category', 'The device category does not belong to the selected domain.');
        }

        if ($values['warning_rising_threshold'] === null && $values['critical_rising_threshold'] === null) {
            $this->fail('critical_rising_threshold', 'Set at least a warning or critical threshold.');
        }

        $this->validateThresholdBand(
            direction: (string) $values['threshold_direction'],
            warningRising: $values['warning_rising_threshold'],
            warningFalling: $values['warning_falling_threshold'],
            criticalRising: $values['critical_rising_threshold'],
            criticalFalling: $values['critical_falling_threshold'],
            criticalRisingField: 'critical_rising_threshold',
            criticalFallingField: 'critical_falling_threshold',
        );

        return [
            'name' => trim((string) $values['name']),
            'scope_kind' => (string) $values['scope_kind'],
            'site_id' => isset($values['site_id']) ? (int) $values['site_id'] : null,
            'device_id' => isset($values['device_id']) ? (int) $values['device_id'] : null,
            'monitor_id' => isset($values['monitor_id']) ? (int) $values['monitor_id'] : null,
            'device_domain' => $domain,
            'device_category' => $category,
            'monitor_kind' => isset($values['monitor_kind']) && $values['monitor_kind'] !== '' ? (string) $values['monitor_kind'] : null,
            'metric_key' => isset($values['metric_key']) && $values['metric_key'] !== '' ? (string) $values['metric_key'] : null,
            'threshold_direction' => (string) $values['threshold_direction'],
            'warning_rising_threshold' => $values['warning_rising_threshold'],
            'warning_falling_threshold' => $values['warning_falling_threshold'],
            'critical_rising_threshold' => $values['critical_rising_threshold'],
            'critical_falling_threshold' => $values['critical_falling_threshold'],
        ];
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function alertRoute(array $attributes): array
    {
        if (isset($attributes['device_domain'])
            && $attributes['device_domain'] !== ''
            && $attributes['device_domain'] !== DeviceDomain::ItInfrastructure->value) {
            $this->fail(
                'device_domain',
                'Life-safety, security, and healthcare checks are locked to Control Room and cannot be rerouted or muted.',
            );
        }

        $monitorKinds = array_map(fn (MonitorKind $kind): string => $kind->value, MonitorKind::cases());
        $values = Validator::make([
            'site_id' => null,
            'device_id' => null,
            'monitor_id' => null,
            'device_category' => null,
            'monitor_kind' => null,
            'warning_notify_in_app' => true,
            'warning_notify_email' => false,
            'warning_open_it_ticket' => false,
            'critical_notify_in_app' => true,
            'critical_notify_email' => true,
            'critical_open_it_ticket' => true,
            'critical_escalate_control_room' => false,
            'recipient_role' => null,
            ...$attributes,
        ], [
            'name' => ['required', 'string', 'min:3', 'max:128'],
            'scope_kind' => ['required', Rule::in(['application', 'site', 'device', 'monitor'])],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
            'monitor_id' => ['nullable', 'integer', 'exists:monitors,id'],
            'device_category' => ['nullable', 'string', 'max:64'],
            'monitor_kind' => ['nullable', Rule::in($monitorKinds)],
            'warning_notify_in_app' => ['required', 'boolean'],
            'warning_notify_email' => ['required', 'boolean'],
            'warning_open_it_ticket' => ['required', 'boolean'],
            'critical_notify_in_app' => ['required', 'boolean'],
            'critical_notify_email' => ['required', 'boolean'],
            'critical_open_it_ticket' => ['required', 'boolean'],
            'critical_escalate_control_room' => ['required', 'boolean'],
            'recipient_role' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/'],
        ])->validate();

        $this->assertScopedTargetShape(
            scope: (string) $values['scope_kind'],
            siteId: $values['site_id'],
            deviceId: $values['device_id'],
            monitorId: $values['monitor_id'],
        );

        $category = isset($values['device_category']) && trim((string) $values['device_category']) !== ''
            ? strtolower(trim((string) $values['device_category']))
            : null;
        if ($category !== null
            && ! array_key_exists($category, DeviceTaxonomy::categoriesFor(DeviceDomain::ItInfrastructure->value))) {
            $this->fail('device_category', 'Alert routes only apply to IT infrastructure categories.');
        }

        return [
            'name' => trim((string) $values['name']),
            'scope_kind' => (string) $values['scope_kind'],
            'site_id' => isset($values['site_id']) ? (int) $values['site_id'] : null,
            'device_id' => isset($values['device_id']) ? (int) $values['device_id'] : null,
            'monitor_id' => isset($values['monitor_id']) ? (int) $values['monitor_id'] : null,
            'device_domain' => DeviceDomain::ItInfrastructure->value,
            'device_category' => $category,
            'monitor_kind' => isset($values['monitor_kind']) && $values['monitor_kind'] !== '' ? (string) $values['monitor_kind'] : null,
            'warning_notify_in_app' => (bool) $values['warning_notify_in_app'],
            'warning_notify_email' => (bool) $values['warning_notify_email'],
            'warning_open_it_ticket' => (bool) $values['warning_open_it_ticket'],
            'critical_notify_in_app' => (bool) $values['critical_notify_in_app'],
            'critical_notify_email' => (bool) $values['critical_notify_email'],
            'critical_open_it_ticket' => (bool) $values['critical_open_it_ticket'],
            'critical_escalate_control_room' => (bool) $values['critical_escalate_control_room'],
            'recipient_role' => isset($values['recipient_role']) && trim((string) $values['recipient_role']) !== ''
                ? trim((string) $values['recipient_role'])
                : null,
        ];
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function coverage(array $attributes): array
    {
        $domains = array_map(fn (DeviceDomain $domain): string => $domain->value, DeviceDomain::cases());
        $values = Validator::make($attributes, [
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'device_domain' => ['required', Rule::in($domains)],
            'device_category' => ['nullable', 'string', 'max:64'],
            'capability' => ['required', Rule::in(array_keys(self::CAPABILITY_KINDS))],
            'minimum_count' => ['required', 'integer', 'between:1,100'],
            'support_status' => ['required', Rule::in(['supported', 'unsupported'])],
            'rationale' => ['required', 'string', 'min:10', 'max:500'],
        ])->validate();

        $domain = (string) $values['device_domain'];
        $category = isset($values['device_category']) ? strtolower(trim((string) $values['device_category'])) : null;
        if ($category !== null && ! array_key_exists($category, DeviceTaxonomy::categoriesFor($domain))) {
            $this->fail('device_category', 'The device category does not belong to the selected domain.');
        }
        $capability = (string) $values['capability'];

        return [
            'site_id' => isset($values['site_id']) ? (int) $values['site_id'] : null,
            'device_domain' => $domain,
            'device_category' => $category,
            'capability' => $capability,
            'monitor_kind' => self::CAPABILITY_KINDS[$capability],
            'minimum_count' => (int) $values['minimum_count'],
            'support_status' => (string) $values['support_status'],
            'support_evidence' => [
                'source' => 'settings_policy',
                'rationale' => trim((string) $values['rationale']),
                'contract_version' => 1,
            ],
        ];
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function dependency(array $attributes): array
    {
        $values = Validator::make($attributes, [
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'upstream_monitor_id' => ['required', 'integer', 'exists:monitors,id', 'different:downstream_monitor_id'],
            'downstream_monitor_id' => ['required', 'integer', 'exists:monitors,id'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
        ])->validate();

        return [
            'site_id' => (int) $values['site_id'],
            'upstream_monitor_id' => (int) $values['upstream_monitor_id'],
            'downstream_monitor_id' => (int) $values['downstream_monitor_id'],
            'policy' => 'suppress_notifications_and_ticketing',
            'source' => 'manual',
            'confidence' => (float) $values['confidence'],
            'topology_edge_id' => null,
        ];
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function maintenance(array $attributes): array
    {
        $values = Validator::make($attributes, [
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'monitor_id' => ['nullable', 'integer', 'exists:monitors,id'],
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
            'name' => ['required', 'string', 'min:3', 'max:128'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            'recurrence' => ['nullable', Rule::in(['daily', 'weekly'])],
            'recurrence_until' => ['nullable', 'date'],
            'timezone' => ['required', 'string', 'max:64'],
            'dst_overlap' => ['sometimes', Rule::in(['reject', 'earlier', 'later'])],
            'dst_gap' => ['sometimes', Rule::in(['reject', 'skip'])],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ])->validate();

        if (($values['monitor_id'] ?? null) !== null && ($values['device_id'] ?? null) !== null) {
            $this->fail('monitor_id', 'Choose Site, Device, or Monitor scope, not more than one.');
        }
        if (! in_array($values['timezone'], DateTimeZone::listIdentifiers(), true)) {
            $this->fail('timezone', 'Choose a valid IANA timezone.');
        }

        $overlap = $values['dst_overlap'] ?? 'reject';
        $gap = $values['dst_gap'] ?? 'reject';
        $startsAt = $this->maintenanceInstant($values['starts_at'], $values['timezone'], $overlap, 'starts_at');
        $endsAt = $this->maintenanceInstant($values['ends_at'], $values['timezone'], $overlap, 'ends_at');
        if ($endsAt <= $startsAt) {
            $this->fail('ends_at', 'Maintenance must end after it starts.');
        }
        $recurrence = $values['recurrence'] ?? null;
        $recurrenceUntil = isset($values['recurrence_until'])
            ? $this->maintenanceInstant($values['recurrence_until'], $values['timezone'], $overlap, 'recurrence_until')
            : null;

        if ($recurrence === null && $recurrenceUntil !== null) {
            $this->fail('recurrence_until', 'One-off maintenance cannot have a recurrence end.');
        }
        if ($recurrence !== null && ($recurrenceUntil === null || $recurrenceUntil <= $startsAt)) {
            $this->fail('recurrence_until', 'Recurring maintenance requires an end after its first occurrence.');
        }
        if ($recurrenceUntil !== null && $recurrenceUntil > $startsAt->addYear()) {
            $this->fail('recurrence_until', 'Recurring maintenance cannot extend beyond one year.');
        }
        $schedule = [
            'site_id' => (int) $values['site_id'],
            'monitor_id' => isset($values['monitor_id']) ? (int) $values['monitor_id'] : null,
            'device_id' => isset($values['device_id']) ? (int) $values['device_id'] : null,
            'name' => trim((string) $values['name']),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'recurrence' => $recurrence,
            'recurrence_until' => $recurrenceUntil,
            'timezone' => (string) $values['timezone'],
            'dst_overlap' => $overlap,
            'dst_gap' => $gap,
            'policy' => 'suppress_notifications_and_ticketing',
            'reason' => trim((string) $values['reason']),
        ];
        try {
            (new MaintenanceSchedule)->occurrences($schedule);
        } catch (InvalidArgumentException $exception) {
            $code = $exception->getMessage();
            $this->fail($code === 'ambiguous_local_time' ? 'dst_overlap' : ($code === 'nonexistent_local_time' ? 'dst_gap' : 'ends_at'), match ($code) {
                'ambiguous_local_time' => 'A repeated local hour occurs in this schedule. Choose its first or second occurrence.',
                'nonexistent_local_time' => 'A local time is skipped in this schedule. Choose another time or explicitly skip that occurrence.',
                'invalid_recurring_duration', 'overlapping_occurrences' => 'A repeated maintenance window must finish before its next occurrence.',
                default => 'The maintenance schedule could not be resolved. Review its dates and clock choices.',
            });
        }

        return $schedule;
    }

    private function maintenanceInstant(DateTimeInterface|string $value, string $zone, string $overlap, string $field): CarbonImmutable
    {
        try {
            $instant = (new ZonedLocalTime)->parse($value, $zone, $overlap);
        } catch (InvalidArgumentException $exception) {
            $this->fail($field, match ($exception->getMessage()) {
                'ambiguous_local_time' => 'This local time occurs twice. Choose its first or second occurrence or include the selected UTC offset.',
                'nonexistent_local_time' => 'This local time does not exist when clocks move forward. Choose another time.',
                'invalid_local_offset' => 'The selected UTC offset does not match this local time and timezone.',
                default => 'Enter an exact date and time in the selected timezone.',
            });
        }
        if ($instant->micro !== 0) {
            $this->fail($field, 'Maintenance times use whole seconds. Remove fractional seconds.');
        }

        return $instant;
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function retention(array $attributes): array
    {
        $values = Validator::make($attributes, [
            'name' => ['required', 'string', 'min:3', 'max:128'],
            'scope_kind' => ['required', Rule::in(['application', 'site', 'device', 'data_class', 'privacy'])],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
            'data_class' => ['nullable', Rule::in([
                'operational', 'tracking_telemetry', 'healthcare_telemetry',
                'security_telemetry', 'configuration',
            ])],
            'privacy_class' => ['nullable', Rule::in(['standard', 'sensitive', 'restricted'])],
            'raw_days' => ['required', 'integer', 'between:1,3650'],
            'hourly_days' => ['required', 'integer', 'between:1,3650'],
            'daily_days' => ['required', 'integer', 'between:1,3650'],
            'legal_hold' => ['required', 'boolean'],
        ])->validate();

        $scope = (string) $values['scope_kind'];
        $shape = [
            'site' => $values['site_id'] ?? null,
            'device' => $values['device_id'] ?? null,
            'data_class' => $values['data_class'] ?? null,
            'privacy' => $values['privacy_class'] ?? null,
        ];
        foreach ($shape as $target => $value) {
            $mustExist = $scope === $target;
            if (($mustExist && $value === null) || (! $mustExist && $value !== null)) {
                $this->fail('scope_kind', 'Retention scope targets do not match the selected scope.');
            }
        }
        if ((int) $values['raw_days'] > (int) $values['hourly_days']
            || (int) $values['hourly_days'] > (int) $values['daily_days']) {
            $this->fail('raw_days', 'Retention periods must increase from raw to hourly to daily.');
        }

        return [
            'name' => trim((string) $values['name']),
            'scope_kind' => $scope,
            'site_id' => isset($values['site_id']) ? (int) $values['site_id'] : null,
            'device_id' => isset($values['device_id']) ? (int) $values['device_id'] : null,
            'data_class' => $values['data_class'] ?? null,
            'privacy_class' => $values['privacy_class'] ?? null,
            'raw_days' => (int) $values['raw_days'],
            'hourly_days' => (int) $values['hourly_days'],
            'daily_days' => (int) $values['daily_days'],
            'legal_hold' => (bool) $values['legal_hold'],
        ];
    }

    public function operationalReason(string $reason, string $field = 'reason'): string
    {
        $values = Validator::make([$field => $reason], [
            $field => ['required', 'string', 'min:10', 'max:500'],
        ])->validate();

        return trim((string) $values[$field]);
    }

    public function requireRetentionConfirmation(?string $confirmation, string $reason): string
    {
        if (! hash_equals(self::RETENTION_CONFIRMATION, (string) $confirmation)) {
            $this->fail('confirmation', 'Type the retention confirmation exactly before continuing.');
        }

        return $this->operationalReason($reason);
    }

    private function assertScopedTargetShape(
        string $scope,
        mixed $siteId,
        mixed $deviceId,
        mixed $monitorId,
    ): void {
        if ($scope === 'application' && ($siteId !== null || $deviceId !== null || $monitorId !== null)) {
            $this->fail('scope_kind', 'Application-wide rules must not specify a Site, device, or monitor.');
        }
        if ($scope === 'site' && ($siteId === null || $deviceId !== null || $monitorId !== null)) {
            $this->fail('site_id', 'Site rules require a Site and must not specify a device or monitor.');
        }
        if ($scope === 'device' && ($deviceId === null || $monitorId !== null)) {
            $this->fail('device_id', 'Device rules require a device and must not specify a monitor.');
        }
        if ($scope === 'monitor' && $monitorId === null) {
            $this->fail('monitor_id', 'Monitor rules require a monitor.');
        }
    }

    private function validateThresholdBand(
        string $direction,
        mixed $warningRising,
        mixed $warningFalling,
        mixed $criticalRising,
        mixed $criticalFalling,
        string $criticalRisingField,
        string $criticalFallingField,
    ): void {
        if ($warningFalling !== null && $warningRising === null) {
            $this->fail('warning_falling_threshold', 'A warning recovery threshold requires a warning trigger threshold.');
        }
        if ($criticalFalling !== null && $criticalRising === null) {
            $this->fail($criticalFallingField, 'A falling threshold requires a rising threshold.');
        }

        if ($direction === 'below') {
            if ($warningFalling !== null && (float) $warningFalling < (float) $warningRising) {
                $this->fail('warning_falling_threshold', 'When alerting below a threshold, the warning recovery threshold must be at least the warning trigger threshold.');
            }
            if ($criticalFalling !== null && (float) $criticalFalling < (float) $criticalRising) {
                $this->fail($criticalFallingField, 'When alerting below a threshold, the recovery threshold must be at least the trigger threshold.');
            }
            if ($warningRising !== null && $criticalRising !== null && (float) $warningRising <= (float) $criticalRising) {
                $this->fail('warning_rising_threshold', 'The warning threshold must be higher than the critical threshold when alerting on falling values.');
            }

            return;
        }

        if ($warningFalling !== null && (float) $warningFalling > (float) $warningRising) {
            $this->fail('warning_falling_threshold', 'The warning falling threshold cannot exceed the warning rising threshold.');
        }
        if ($criticalFalling !== null && (float) $criticalFalling > (float) $criticalRising) {
            $this->fail($criticalFallingField, 'The falling threshold cannot exceed the rising threshold.');
        }
        if ($warningRising !== null && $criticalRising !== null && (float) $warningRising >= (float) $criticalRising) {
            $this->fail('warning_rising_threshold', 'The warning threshold must be lower than the critical threshold when evaluating rising values.');
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
