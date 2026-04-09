<?php

namespace App\Services\Eligibility;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Immutable value object representing the outcome of a shift eligibility evaluation.
 *
 * Aggregates results from multiple rule checks into a single, structured result
 * that controllers and UI can consume.
 */
class EligibilityResult implements Arrayable, JsonSerializable
{
    /**
     * @param  bool   $is_allowed            Whether the assignment is permitted (no hard blocks).
     * @param  array  $blocking_reasons      Human-readable hard-block messages.
     * @param  array  $warnings              Human-readable soft-warning messages.
     * @param  array  $checked_rules         Every rule that was evaluated, with its result.
     * @param  array  $overrideable_warnings Subset of warnings a manager may override.
     */
    public function __construct(
        public readonly bool $is_allowed,
        public readonly array $blocking_reasons,
        public readonly array $warnings,
        public readonly array $checked_rules,
        public readonly array $overrideable_warnings,
    ) {}

    /**
     * Build an EligibilityResult from an array of individual rule-check results.
     *
     * Each element must be an associative array:
     *   rule:        string   — unique identifier (e.g. 'fatigue_daily')
     *   passed:      bool     — true = no issue
     *   severity:    string   — 'block' | 'warning' | 'info'
     *   overrideable: bool    — can a manager override this warning?
     *   message:     ?string  — human-readable detail (null when passed)
     *
     * @param  array<int, array{rule: string, passed: bool, severity: string, overrideable: bool, message: ?string}>  $ruleResults
     */
    public static function fromChecks(array $ruleResults): self
    {
        $blockingReasons = [];
        $warnings = [];
        $overrideableWarnings = [];

        foreach ($ruleResults as $result) {
            if ($result['passed']) {
                continue;
            }

            $message = $result['message'] ?? '';

            if ($result['severity'] === 'block') {
                $blockingReasons[] = $message;
            } elseif ($result['severity'] === 'warning') {
                $warnings[] = $message;

                if ($result['overrideable'] ?? false) {
                    $overrideableWarnings[] = [
                        'rule' => $result['rule'],
                        'message' => $message,
                        'overrideable' => true,
                    ];
                }
            }
        }

        return new self(
            is_allowed: $blockingReasons === [],
            blocking_reasons: array_values(array_unique($blockingReasons)),
            warnings: array_values(array_unique($warnings)),
            checked_rules: $ruleResults,
            overrideable_warnings: $overrideableWarnings,
        );
    }

    public function hasBlocks(): bool
    {
        return $this->blocking_reasons !== [];
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    /**
     * Backwards-compatible array representation matching the shape
     * previously returned by ShiftStaffEligibilityService::evaluate().
     */
    public function toArray(): array
    {
        return [
            'is_eligible' => $this->is_allowed,
            'is_allowed' => $this->is_allowed,
            'blocked_reasons' => $this->blocking_reasons,
            'warning_reasons' => $this->warnings,
            'checked_rules' => $this->checked_rules,
            'overrideable_warnings' => $this->overrideable_warnings,
            // Legacy fields for existing callers
            'has_time_off' => $this->ruleDidFail('time_off'),
            'has_staff_conflict' => $this->ruleDidFail('conflict'),
            'has_compliance_block' => $this->ruleDidFail('compliance'),
            'has_tight_turnaround' => $this->ruleDidFail('turnaround'),
            'compliance_warnings' => $this->extractComplianceWarnings(),
            'would_overfill_coverage' => $this->ruleDidFail('overfill'),
            'required_roles' => $this->extractRuleData('coverage_roles', 'required_roles', []),
            'matched_roles' => $this->extractRuleData('coverage_roles', 'matched_roles', []),
            'missing_roles' => $this->extractRuleData('coverage_roles', 'missing_roles', []),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Check whether a specific rule failed.
     */
    protected function ruleDidFail(string $ruleName): bool
    {
        foreach ($this->checked_rules as $rule) {
            if ($rule['rule'] === $ruleName && ! $rule['passed']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract compliance-specific warning detail for legacy callers.
     */
    protected function extractComplianceWarnings(): array
    {
        foreach ($this->checked_rules as $rule) {
            if ($rule['rule'] === 'compliance' && isset($rule['compliance_warnings'])) {
                return $rule['compliance_warnings'];
            }
        }

        return [];
    }

    /**
     * Extract extra data attached to a specific rule result.
     */
    protected function extractRuleData(string $ruleName, string $key, mixed $default = null): mixed
    {
        foreach ($this->checked_rules as $rule) {
            if ($rule['rule'] === $ruleName && array_key_exists($key, $rule)) {
                return $rule[$key];
            }
        }

        return $default;
    }
}
