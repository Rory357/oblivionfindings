<?php

namespace App\Domain\SecurityDevices\Management\Data;

use App\Domain\SecurityDevices\Management\Enums\CommandConfirmationMode;
use App\Domain\SecurityDevices\Management\Enums\CommandRisk;
use App\Domain\SecurityDevices\Management\Enums\ManagementLevel;

final readonly class CommandCapabilityDefinition
{
    /**
     * @param  array<string, array<string, mixed>>  $parameters
     * @param  list<string>  $safeSummaryFields
     * @param  list<string>  $allowedCurrentStates
     * @param  list<string>  $deviceDomains
     * @param  list<string>  $deviceCategories
     * @param  list<string>  $requiredPermissions
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $domain,
        public ManagementLevel $level,
        public CommandRisk $risk,
        public bool $requiresStepUp,
        public bool $requiresApproval,
        public bool $requiresChange,
        public bool $allowsBreakGlass,
        public int $expiresAfterSeconds,
        public string $reconciliation,
        public string $retryPolicy,
        public bool $requiresFreshObservation,
        public bool $requiresMfa,
        public string $impact,
        public string $expectedResult,
        public CommandConfirmationMode $confirmationMode,
        public array $parameters,
        public array $safeSummaryFields,
        public array $allowedCurrentStates,
        public array $deviceDomains,
        public array $deviceCategories,
        public array $requiredPermissions,
        public string $sensitivity,
    ) {}

    /** @param array<string, mixed> $definition */
    public static function fromArray(string $key, array $definition): self
    {
        return new self(
            key: $key,
            label: (string) $definition['label'],
            domain: (string) $definition['domain'],
            level: ManagementLevel::from((string) $definition['level']),
            risk: CommandRisk::from((string) $definition['risk']),
            requiresStepUp: (bool) $definition['requires_step_up'],
            requiresApproval: (bool) $definition['requires_approval'],
            requiresChange: (bool) $definition['requires_change'],
            allowsBreakGlass: (bool) $definition['allows_break_glass'],
            expiresAfterSeconds: (int) $definition['expires_after_seconds'],
            reconciliation: (string) $definition['reconciliation'],
            retryPolicy: (string) $definition['retry_policy'],
            requiresFreshObservation: (bool) $definition['requires_fresh_observation'],
            requiresMfa: (bool) $definition['requires_mfa'],
            impact: trim((string) $definition['impact']),
            expectedResult: trim((string) $definition['expected_result']),
            confirmationMode: CommandConfirmationMode::from((string) $definition['confirmation_mode']),
            parameters: (array) $definition['parameters'],
            safeSummaryFields: array_values((array) $definition['safe_summary_fields']),
            allowedCurrentStates: array_values((array) $definition['allowed_current_states']),
            deviceDomains: array_values((array) $definition['device_domains']),
            deviceCategories: array_values((array) $definition['device_categories']),
            requiredPermissions: array_values((array) $definition['required_permissions']),
            sensitivity: (string) $definition['sensitivity'],
        );
    }

    public function isHighRisk(): bool
    {
        return $this->risk->isHighRisk();
    }
}
