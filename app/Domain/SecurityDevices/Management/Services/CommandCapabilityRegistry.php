<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Enums\DeviceDomain;
use App\Domain\SecurityDevices\Management\Data\CommandCapabilityDefinition;
use App\Domain\SecurityDevices\Management\Enums\CommandConfirmationMode;
use App\Domain\SecurityDevices\Management\Enums\CommandRisk;
use DomainException;
use Illuminate\Support\Collection;

final class CommandCapabilityRegistry
{
    /** @return Collection<string, CommandCapabilityDefinition> */
    public function all(): Collection
    {
        return collect(config('security_devices.command_capabilities', []))
            ->map(fn (array $definition, string $key): CommandCapabilityDefinition => $this->make($key, $definition));
    }

    public function definition(string $capability): CommandCapabilityDefinition
    {
        $definitions = config('security_devices.command_capabilities', []);
        $definition = is_array($definitions) ? ($definitions[$capability] ?? null) : null;

        if (! is_array($definition)) {
            throw new DomainException('The requested device capability is not recognised.');
        }

        return $this->make($capability, $definition);
    }

    /** @param array<string, mixed> $definition */
    private function make(string $key, array $definition): CommandCapabilityDefinition
    {
        $required = [
            'label', 'domain', 'level', 'risk', 'requires_step_up', 'requires_approval',
            'requires_change', 'allows_break_glass', 'expires_after_seconds', 'reconciliation',
            'retry_policy', 'requires_fresh_observation', 'requires_mfa', 'parameters',
            'safe_summary_fields', 'allowed_current_states', 'impact', 'expected_result',
            'confirmation_mode', 'device_domains', 'device_categories',
            'required_permissions', 'sensitivity',
        ];

        if (array_diff($required, array_keys($definition)) !== []) {
            throw new DomainException("Device capability {$key} has an incomplete policy definition.");
        }

        $capability = CommandCapabilityDefinition::fromArray($key, $definition);
        if ($capability->expiresAfterSeconds < 30 || $capability->expiresAfterSeconds > 3600) {
            throw new DomainException("Device capability {$key} has an unsafe expiry.");
        }
        if ($capability->isHighRisk()
            && (! $capability->requiresStepUp
                || (! $capability->requiresApproval && ! $capability->requiresChange)
                || ! $capability->requiresFreshObservation
                || $capability->level->value !== 'control'
                || $capability->confirmationMode === CommandConfirmationMode::None
                || $capability->retryPolicy !== 'reconcile_before_retry')) {
            throw new DomainException("High-risk device capability {$key} is missing mandatory safeguards.");
        }
        if ($capability->risk === CommandRisk::Critical
            && (! $capability->requiresMfa
                || $capability->expiresAfterSeconds > 120
                || $capability->confirmationMode !== CommandConfirmationMode::TypeDeviceName)) {
            throw new DomainException("Critical device capability {$key} is missing mandatory safeguards.");
        }
        if ($capability->impact === '' || $capability->expectedResult === '') {
            throw new DomainException("Device capability {$key} is missing operator guidance.");
        }
        if (array_diff($capability->safeSummaryFields, array_keys($capability->parameters)) !== []) {
            throw new DomainException("Device capability {$key} exposes an unknown summary field.");
        }
        $knownDomains = array_map(
            fn (DeviceDomain $domain): string => $domain->value,
            DeviceDomain::cases(),
        );
        if ($capability->deviceDomains === []
            || array_diff($capability->deviceDomains, $knownDomains) !== []
            || collect($capability->deviceDomains)->contains(
                fn (mixed $domain): bool => ! is_string($domain) || trim($domain) === '',
            )) {
            throw new DomainException("Device capability {$key} has an invalid workspace boundary.");
        }
        if (collect($capability->deviceCategories)->contains(
            fn (mixed $category): bool => ! is_string($category) || trim($category) === '',
        )) {
            throw new DomainException("Device capability {$key} has an invalid Device-class boundary.");
        }
        if (collect($capability->requiredPermissions)->contains(
            fn (mixed $permission): bool => ! is_string($permission)
                || preg_match('/^[A-Za-z0-9_.:-]{1,160}$/', $permission) !== 1,
        )) {
            throw new DomainException("Device capability {$key} has an invalid source-permission boundary.");
        }
        if (! in_array($capability->sensitivity, [
            'standard',
            'personal_location',
            'privileged_remote',
            'destructive_endpoint',
            'security_control',
            'cctv_media',
            'availability_control',
            'broad_availability',
            'healthcare_technical',
            'facilities_control',
        ], true)) {
            throw new DomainException("Device capability {$key} has an invalid sensitivity boundary.");
        }

        return $capability;
    }
}
