<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Models\MonitoringRetentionPolicy;
use App\Domain\SecurityDevices\Config\DeviceTaxonomy;
use App\Domain\SecurityDevices\Enums\DeviceDomain;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function profile(array $attributes): array
    {
        $values = Validator::make($attributes, [
            'name' => ['required', 'string', 'min:3', 'max:128'],
            'description' => ['nullable', 'string', 'max:2000'],
            'interval_seconds' => ['required', 'integer', 'between:30,86400'],
            'failure_confirmations' => ['required', 'integer', 'between:1,20'],
            'failure_duration_seconds' => ['required', 'integer', 'between:0,86400'],
            'recovery_confirmations' => ['required', 'integer', 'between:1,20'],
            'recovery_duration_seconds' => ['required', 'integer', 'between:0,86400'],
            'stale_after_seconds' => ['required', 'integer', 'between:30,604800'],
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
        if ($values['falling_threshold'] !== null && $values['rising_threshold'] === null) {
            $this->fail('falling_threshold', 'A falling threshold requires a rising threshold.');
        }
        if ($values['falling_threshold'] !== null
            && (float) $values['falling_threshold'] > (float) $values['rising_threshold']) {
            $this->fail('falling_threshold', 'The falling threshold cannot exceed the rising threshold.');
        }
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
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'recurrence' => ['nullable', Rule::in(['daily', 'weekly'])],
            'recurrence_until' => ['nullable', 'date'],
            'timezone' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ])->validate();

        if (($values['monitor_id'] ?? null) !== null && ($values['device_id'] ?? null) !== null) {
            $this->fail('monitor_id', 'Choose Site, Device, or Monitor scope, not more than one.');
        }
        if (! in_array($values['timezone'], DateTimeZone::listIdentifiers(), true)) {
            $this->fail('timezone', 'Choose a valid IANA timezone.');
        }

        $startsAt = CarbonImmutable::parse((string) $values['starts_at'])->utc();
        $endsAt = CarbonImmutable::parse((string) $values['ends_at'])->utc();
        $recurrence = $values['recurrence'] ?? null;
        $recurrenceUntil = isset($values['recurrence_until'])
            ? CarbonImmutable::parse((string) $values['recurrence_until'])->utc()
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
        $duration = $startsAt->diffInSeconds($endsAt);
        $maximum = $recurrence === 'weekly' ? 604800 : 86400;
        if ($recurrence !== null && $duration >= $maximum) {
            $this->fail('ends_at', 'A recurring occurrence must be shorter than its recurrence period.');
        }

        return [
            'site_id' => (int) $values['site_id'],
            'monitor_id' => isset($values['monitor_id']) ? (int) $values['monitor_id'] : null,
            'device_id' => isset($values['device_id']) ? (int) $values['device_id'] : null,
            'name' => trim((string) $values['name']),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'recurrence' => $recurrence,
            'recurrence_until' => $recurrenceUntil,
            'timezone' => (string) $values['timezone'],
            'policy' => 'suppress_notifications_and_ticketing',
            'reason' => trim((string) $values['reason']),
        ];
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

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
