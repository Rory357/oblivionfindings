<?php

namespace App\Domain\SecurityDevices\Services;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DeviceFieldOwnershipService
{
    /** @var list<string> */
    private const PROVIDER_MANAGED_FIELDS = [
        'manufacturer',
        'model',
        'serial_number',
        'mac_address',
        'imei',
        'firmware_version',
        'ip_address',
        'status',
        'health_status',
        'provider',
    ];

    /** @var list<string> */
    private const LOCAL_CANONICAL_FIELDS = [
        'name',
        'domain',
        'category',
        'subcategory',
    ];

    /** @var list<string> */
    private const LOCAL_EVIDENCE_FIELDS = [
        'name',
        'domain',
        'category',
        'subcategory',
        'manufacturer',
        'model',
        'serial_number',
        'mac_address',
        'imei',
        'asset_tag',
        'firmware_version',
        'ip_address',
        'status',
        'health_status',
        'provider',
        'location_description',
        'notes',
        'next_service_due',
    ];

    /** @var list<string> */
    private const ALWAYS_OBSERVED_FIELDS = [
        'last_seen_at',
        'battery_level',
        'battery_updated_at',
    ];

    /** @var list<string> */
    private const MERGED_PROVIDER_FIELDS = [
        'external_ref',
        'meta',
        'config',
    ];

    /** @var list<string> */
    private const PROVIDER_ATTRIBUTE_FIELDS = [
        ...self::MERGED_PROVIDER_FIELDS,
        'device_uid',
        'legacy_location_hardware_id',
        'legacy_asset_tracker_id',
    ];

    private const FORBIDDEN_PROVIDER_ATTRIBUTE_KEY =
        '/password|passwd|passphrase|secret|token|credential|authorization|cookie|private[_-]?key|api[_-]?key|^raw(?:_|$)/i';

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function recordLocalRegistration(Device $device, array $attributes, User $actor): Device
    {
        return DB::transaction(function () use ($device, $attributes, $actor): Device {
            $locked = Device::query()
                ->whereKey($device->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $intended = is_array($locked->local_intended_state)
                ? $locked->local_intended_state
                : [];
            $recordedAt = CarbonImmutable::now()->toIso8601String();

            foreach (self::LOCAL_EVIDENCE_FIELDS as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                $intended[$field] = [
                    'value' => $this->normalise($attributes[$field]),
                    'recorded_at' => $recordedAt,
                    'source' => 'local_registry_registration',
                    'quality' => 'operator_intent',
                    'recorded_by_user_id' => (int) $actor->getKey(),
                ];
            }

            $locked->local_intended_state = $intended;
            if (! is_array($locked->provider_field_overrides)) {
                $locked->provider_field_overrides = ['active' => [], 'history' => []];
            }
            $locked->save();

            return $locked->fresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function recordImportedLocalState(
        Device $device,
        array $attributes,
        string $source = 'legacy_import',
    ): Device {
        $source = trim($source);
        if ($source === '') {
            throw new \InvalidArgumentException('Imported local device state requires a source.');
        }

        return DB::transaction(function () use ($device, $attributes, $source): Device {
            $locked = Device::query()
                ->whereKey($device->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $intended = is_array($locked->local_intended_state)
                ? $locked->local_intended_state
                : [];
            $recordedAt = ($locked->updated_at ?? CarbonImmutable::now())->toIso8601String();

            foreach (self::LOCAL_EVIDENCE_FIELDS as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                $intended[$field] = [
                    'value' => $this->normalise($attributes[$field]),
                    'recorded_at' => $recordedAt,
                    'source' => $source,
                    'quality' => 'legacy_inferred',
                    'recorded_by_user_id' => null,
                ];
            }

            $locked->local_intended_state = $intended;
            if (! is_array($locked->provider_field_overrides)) {
                $locked->provider_field_overrides = ['active' => [], 'history' => []];
            }
            $locked->save();

            return $locked->fresh();
        }, 3);
    }

    /**
     * Apply an operator edit without allowing a stale form to overwrite newer
     * provider evidence. Provider-owned changes require an expiring, attributed
     * override and remain separate from the latest observed values.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateFromLocal(
        Device $device,
        array $attributes,
        User $actor,
        ?string $overrideReason,
        ?CarbonInterface $overrideExpiresAt,
    ): Device {
        $unsupported = array_diff(array_keys($attributes), self::LOCAL_EVIDENCE_FIELDS);
        if ($unsupported !== []) {
            throw new \InvalidArgumentException(
                'Unsupported local device attributes: '.implode(', ', $unsupported),
            );
        }

        return DB::transaction(function () use (
            $device,
            $attributes,
            $actor,
            $overrideReason,
            $overrideExpiresAt,
        ): Device {
            $locked = Device::query()
                ->whereKey($device->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $lockedActor = User::query()->whereKey($actor->getKey())->firstOrFail();
            abort_unless($lockedActor->canDo('securityDevices.devices.update'), 403);
            $this->access->assertCanViewDevice($lockedActor, $locked);
            $providerManaged = $this->isProviderManaged($locked);
            $changes = $this->changedValues($locked, $attributes);
            $providerChanges = array_intersect_key(
                $changes,
                array_flip(self::PROVIDER_MANAGED_FIELDS),
            );

            if ($providerManaged && array_key_exists('provider', $providerChanges)) {
                throw ValidationException::withMessages([
                    'provider' => 'Provider linkage is owned by the active integration and cannot be changed in the generic device editor.',
                ]);
            }

            if ($providerManaged && $providerChanges !== []) {
                $overrideReason = trim((string) $overrideReason);
                $errors = [];
                if (Str::length($overrideReason) < 10 || Str::length($overrideReason) > 1000) {
                    $errors['override_reason'] = 'Explain why the provider value must be overridden (10 to 1,000 characters).';
                }
                if ($overrideExpiresAt === null
                    || ! $overrideExpiresAt->isFuture()
                    || $overrideExpiresAt->isAfter(now()->addYear())) {
                    $errors['override_expires_at'] = 'Choose a future expiry within one year for the provider override.';
                }
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
            }

            $intended = is_array($locked->local_intended_state)
                ? $locked->local_intended_state
                : [];
            $overrideState = $this->overrideState($locked);
            $now = CarbonImmutable::now();

            foreach ($changes as $field => $value) {
                $isOverride = $providerManaged && array_key_exists($field, $providerChanges);
                $intended[$field] = [
                    'value' => $this->normalise($value),
                    'recorded_at' => $now->toIso8601String(),
                    'source' => 'local_registry',
                    'quality' => $isOverride ? 'governed_override' : 'operator_intent',
                    'recorded_by_user_id' => (int) $lockedActor->getKey(),
                ];

                if ($isOverride) {
                    $overrideState = $this->replaceActiveOverride(
                        $overrideState,
                        $field,
                        $value,
                        (string) $overrideReason,
                        $overrideExpiresAt,
                        $lockedActor,
                        $now,
                    );
                }
            }

            // Unchanged provider fields submitted by an old form are ignored;
            // only an explicitly governed changed value can alter the scalar
            // projection while provider ownership is active.
            $allowed = $changes;
            if ($providerManaged) {
                foreach (self::PROVIDER_MANAGED_FIELDS as $field) {
                    if (! array_key_exists($field, $providerChanges)) {
                        unset($allowed[$field]);
                    }
                }
            }

            $locked->fill($allowed);
            $locked->local_intended_state = $intended;
            $locked->provider_field_overrides = $overrideState;
            $locked->save();

            return $locked->fresh();
        }, 3);
    }

    /**
     * Persist bounded provider evidence and project it only when a governed
     * local override is not active. Local classification stays locally owned.
     *
     * @param  array<string, mixed>  $observed
     * @param  array<string, mixed>  $providerAttributes
     */
    public function applyProviderObservation(
        Device $device,
        string $source,
        array $observed,
        ?CarbonInterface $observedAt = null,
        string $quality = 'authoritative_provider',
        array $providerAttributes = [],
    ): Device {
        $source = strtolower(trim($source));
        $quality = trim($quality);
        if ($source === '' || $quality === '') {
            throw new \InvalidArgumentException('Provider observations require a source and quality.');
        }

        return DB::transaction(function () use (
            $device,
            $source,
            $observed,
            $observedAt,
            $quality,
            $providerAttributes,
        ): Device {
            $isNew = ! $device->exists;
            $locked = $isNew
                ? $device
                : Device::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();
            $at = CarbonImmutable::instance($observedAt ?? now())->setMicrosecond(0);
            $observedState = is_array($locked->provider_observed_state)
                ? $locked->provider_observed_state
                : [];
            $overrideState = $this->overrideState($locked);
            $processedAt = CarbonImmutable::now();
            [$overrideState, $projection] = $this->expireOverrides(
                $overrideState,
                $observedState,
                $processedAt,
            );
            $latestObservedAt = null;

            foreach ($observedState as $evidence) {
                $evidenceAt = is_array($evidence)
                    ? $this->date($evidence['observed_at'] ?? null)
                    : null;
                if ($evidenceAt !== null
                    && ($latestObservedAt === null || $evidenceAt->isAfter($latestObservedAt))) {
                    $latestObservedAt = $evidenceAt;
                }
            }
            $providerAttributesAreCurrent = $latestObservedAt === null
                || $latestObservedAt->isBefore($at);

            $unsupportedAttributes = array_diff(
                array_keys($providerAttributes),
                self::PROVIDER_ATTRIBUTE_FIELDS,
            );
            if ($unsupportedAttributes !== []) {
                throw new \InvalidArgumentException(
                    'Unsupported provider projection attributes: '.implode(', ', $unsupportedAttributes),
                );
            }
            $providerAttributes = $this->minimumNecessaryProviderAttributes($providerAttributes);

            foreach ($observed as $field => $value) {
                if (! in_array($field, [
                    ...self::PROVIDER_MANAGED_FIELDS,
                    ...self::LOCAL_CANONICAL_FIELDS,
                    ...self::ALWAYS_OBSERVED_FIELDS,
                ], true)) {
                    continue;
                }

                $previousEvidence = is_array($observedState[$field] ?? null)
                    ? $observedState[$field]
                    : null;
                $previousObservedAt = $this->date($previousEvidence['observed_at'] ?? null);
                // First evidence at an observed timestamp wins. Exact replays
                // therefore remain idempotent, while conflicting duplicates
                // cannot rewrite the projection without a newer observation.
                if (($latestObservedAt !== null && $at->isBefore($latestObservedAt))
                    || ($previousObservedAt !== null && ! $previousObservedAt->isBefore($at))) {
                    continue;
                }

                $normalised = $this->normalise($value);
                $exactDuplicate = $previousEvidence !== null
                    && ($previousEvidence['source'] ?? null) === $source
                    && ($previousEvidence['quality'] ?? null) === $quality
                    && $this->normalise($previousEvidence['value'] ?? null) === $normalised;
                if (! $exactDuplicate) {
                    $observedState[$field] = [
                        'value' => $normalised,
                        'observed_at' => $at->toIso8601String(),
                        'source' => $source,
                        'quality' => $quality,
                    ];
                }

                if (in_array($field, self::LOCAL_CANONICAL_FIELDS, true)) {
                    if ($isNew || blank($this->normalise($locked->{$field}))) {
                        $projection[$field] = $normalised;
                    }

                    continue;
                }

                if (in_array($field, self::PROVIDER_MANAGED_FIELDS, true)) {
                    [$activeOverride, $overrideState] = $this->activeOverride(
                        $overrideState,
                        $field,
                        $processedAt,
                    );
                    if ($activeOverride === null) {
                        $projection[$field] = $normalised;
                    }

                    continue;
                }

                if ($normalised !== null) {
                    $projection[$field] = $normalised;
                }
            }

            foreach (self::MERGED_PROVIDER_FIELDS as $field) {
                if (! $providerAttributesAreCurrent || ! array_key_exists($field, $providerAttributes)) {
                    continue;
                }
                $incoming = is_array($providerAttributes[$field]) ? $providerAttributes[$field] : [];
                $current = is_array($locked->{$field}) ? $locked->{$field} : [];
                $projection[$field] = $this->compactProviderMap(
                    array_replace_recursive($current, $incoming),
                );
            }
            foreach ($providerAttributes as $field => $value) {
                if ($providerAttributesAreCurrent
                    && ! in_array($field, self::MERGED_PROVIDER_FIELDS, true)) {
                    $projection[$field] = $value;
                }
            }

            $locked->fill($projection);
            $locked->provider_observed_state = $observedState;
            $locked->provider_field_overrides = $overrideState;
            if ($locked->isDirty()) {
                $locked->save();
            }

            return $locked->fresh();
        }, 3);
    }

    /** @return array<string, mixed> */
    public function snapshot(Device $device): array
    {
        $observed = is_array($device->provider_observed_state)
            ? $device->provider_observed_state
            : [];
        $overrideState = $this->overrideState($device);
        $active = [];
        $conflicts = [];
        $now = CarbonImmutable::now();

        foreach ($overrideState['active'] as $field => $override) {
            if (! is_array($override)) {
                continue;
            }
            $expiresAt = $this->date($override['expires_at'] ?? null);
            if ($expiresAt === null || ! $expiresAt->isAfter($now)) {
                continue;
            }
            $active[$field] = $override;
            if (array_key_exists($field, $observed)
                && $this->normalise(data_get($observed, "{$field}.value")) !== $this->normalise($override['value'] ?? null)) {
                $conflicts[] = $field;
            }
        }

        return [
            'provider_managed' => $this->isProviderManaged($device),
            'provider_fields' => self::PROVIDER_MANAGED_FIELDS,
            'observed' => $observed,
            'local_intended' => is_array($device->local_intended_state)
                ? $device->local_intended_state
                : [],
            'active_overrides' => $active,
            'conflicts' => $conflicts,
            'override_history_count' => count($overrideState['history']),
        ];
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    private function changedValues(Device $device, array $attributes): array
    {
        $changed = [];
        foreach ($attributes as $field => $value) {
            if ($this->normalise($device->{$field}) !== $this->normalise($value)) {
                $changed[$field] = $value;
            }
        }

        return $changed;
    }

    private function isProviderManaged(Device $device): bool
    {
        $providers = collect([
            data_get($device->external_ref, 'provider'),
            data_get($device->provider_observed_state, 'provider.value'),
        ]);

        foreach ((array) $device->provider_observed_state as $evidence) {
            if (is_array($evidence)) {
                $providers->push($evidence['source'] ?? null);
            }
        }

        return $providers
            ->filter(fn (mixed $provider): bool => is_scalar($provider))
            ->map(fn (mixed $provider): string => strtolower(trim((string) $provider)))
            ->contains(fn (string $provider): bool => $provider !== ''
                && ! in_array($provider, ['manual', 'local'], true));
    }

    /** @return array{active: array<string, mixed>, history: list<mixed>} */
    private function overrideState(Device $device): array
    {
        $state = is_array($device->provider_field_overrides)
            ? $device->provider_field_overrides
            : [];

        return [
            'active' => is_array($state['active'] ?? null) ? $state['active'] : [],
            'history' => is_array($state['history'] ?? null) ? array_values($state['history']) : [],
        ];
    }

    /**
     * @param  array{active: array<string, mixed>, history: list<mixed>}  $state
     * @return array{active: array<string, mixed>, history: list<mixed>}
     */
    private function replaceActiveOverride(
        array $state,
        string $field,
        mixed $value,
        string $reason,
        CarbonInterface $expiresAt,
        User $actor,
        CarbonInterface $recordedAt,
    ): array {
        if (is_array($state['active'][$field] ?? null)) {
            $state['history'][] = [
                ...$state['active'][$field],
                'ended_at' => $recordedAt->toIso8601String(),
                'end_reason' => 'superseded',
            ];
        }

        $state['active'][$field] = [
            'id' => (string) Str::uuid(),
            'value' => $this->normalise($value),
            'reason' => $reason,
            'expires_at' => $expiresAt->toIso8601String(),
            'recorded_at' => $recordedAt->toIso8601String(),
            'recorded_by_user_id' => (int) $actor->getKey(),
        ];

        return $state;
    }

    /**
     * @param  array{active: array<string, mixed>, history: list<mixed>}  $state
     * @return array{0: array<string, mixed>|null, 1: array{active: array<string, mixed>, history: list<mixed>}}
     */
    private function activeOverride(array $state, string $field, CarbonInterface $at): array
    {
        $override = $state['active'][$field] ?? null;
        if (! is_array($override)) {
            return [null, $state];
        }

        $expiresAt = $this->date($override['expires_at'] ?? null);
        if ($expiresAt !== null && $expiresAt->isAfter($at)) {
            return [$override, $state];
        }

        $state['history'][] = [
            ...$override,
            'ended_at' => $at->toIso8601String(),
            'end_reason' => 'expired',
        ];
        unset($state['active'][$field]);

        return [null, $state];
    }

    /**
     * A subsequent provider sync is the canonical reconciliation boundary for
     * every expired override, including fields omitted by a partial payload.
     *
     * @param  array{active: array<string, mixed>, history: list<mixed>}  $state
     * @param  array<string, mixed>  $observedState
     * @return array{0: array{active: array<string, mixed>, history: list<mixed>}, 1: array<string, mixed>}
     */
    private function expireOverrides(
        array $state,
        array $observedState,
        CarbonInterface $at,
    ): array {
        $projection = [];

        foreach (array_keys($state['active']) as $field) {
            [$active, $state] = $this->activeOverride($state, (string) $field, $at);
            if ($active !== null
                || ! in_array($field, self::PROVIDER_MANAGED_FIELDS, true)
                || ! is_array($observedState[$field] ?? null)
                || ! array_key_exists('value', $observedState[$field])) {
                continue;
            }

            $projection[$field] = $this->normalise($observedState[$field]['value']);
        }

        return [$state, $projection];
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    private function minimumNecessaryProviderAttributes(array $attributes): array
    {
        $minimum = [];

        foreach ($attributes as $field => $value) {
            if (in_array($field, self::MERGED_PROVIDER_FIELDS, true)) {
                if (! is_array($value)) {
                    continue;
                }

                $compacted = $this->compactProviderMap($value);
                if ($compacted !== []) {
                    $minimum[$field] = $compacted;
                }

                continue;
            }

            if ($value === null || $value === '' || ! is_scalar($value)) {
                continue;
            }
            if (! is_string($value) || Str::length($value) <= 4096) {
                $minimum[$field] = $value;
            }
        }

        return $minimum;
    }

    /** @param array<mixed> $values @return array<mixed> */
    private function compactProviderMap(array $values, int $depth = 0): array
    {
        if ($depth >= 6) {
            return [];
        }

        $compacted = [];
        $count = 0;
        foreach ($values as $key => $value) {
            if (++$count > 256) {
                break;
            }
            if (is_string($key)
                && (Str::length($key) > 128
                    || preg_match(self::FORBIDDEN_PROVIDER_ATTRIBUTE_KEY, $key) === 1)) {
                continue;
            }
            if (is_array($value)) {
                $child = $this->compactProviderMap($value, $depth + 1);
                if ($child !== []) {
                    $compacted[$key] = $child;
                }

                continue;
            }
            if ($value === null || $value === '' || ! is_scalar($value)) {
                continue;
            }
            if (is_string($value) && Str::length($value) > 4096) {
                continue;
            }

            $compacted[$key] = $value;
        }

        return array_is_list($values) ? array_values($compacted) : $compacted;
    }

    private function normalise(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return $value;
    }
}
