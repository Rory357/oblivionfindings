<?php

namespace App\Domain\Privacy\Retention;

use App\Models\DataRetentionPolicy;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

final class RetentionOwnerAdapter
{
    /** Field strategies: clear (null), redact ('[REDACTED]') or structured (the owner's own redactor). */
    public const STRATEGIES = ['clear', 'redact', 'structured'];

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, array<int, string>>  $conditionOperators
     * @param  array<string, 'clear'|'redact'|'structured'>  $anonymizationFields
     * @param  string|null  $cutoffColumn  The date a period counts from; the model's created date when null.
     * @param  array<string, array{label: string, scope: Closure(Builder, CarbonInterface): void}>  $protections
     *                                                                                                     Always-on disposal blockers, named for the approver. A record is only eligible when every scope keeps it.
     * @param  (Closure(string, mixed, Model): mixed)|null  $redactor  Replacement value for each structured field.
     * @param  (Closure(Model): array<string, int>)|null  $afterAnonymize  Related clean-up in the same transaction; returns counts for the evidence.
     * @param  array<string, mixed>  $guidance  Plain-language explanation of the owner's choices for the approver.
     * @param  string|null  $contractVersion  Bumped whenever the redactor or clean-up changes, so approvals must be renewed.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $modelClass,
        public readonly array $conditionOperators,
        public readonly array $anonymizationFields,
        public readonly ?string $activeCaseRelation = null,
        public readonly ?string $cutoffColumn = null,
        public readonly array $protections = [],
        public readonly ?Closure $redactor = null,
        public readonly ?Closure $afterAnonymize = null,
        public readonly array $guidance = [],
        public readonly ?string $contractVersion = null,
    ) {}

    public function model(): Model
    {
        return new $this->modelClass;
    }

    public function usesSoftDeletes(): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($this->modelClass), true);
    }

    public function cutoffColumn(): string
    {
        return $this->cutoffColumn ?? $this->model()->getCreatedAtColumn();
    }

    /** Apply every protection; each keeps only the records it allows to be disposed of. */
    public function applyProtections(Builder $query, CarbonInterface $cutoff): void
    {
        foreach ($this->protections as $protection) {
            ($protection['scope'])($query, $cutoff);
        }
    }

    /** The replacement value for one anonymised field. */
    public function anonymizedValue(string $field, mixed $value, Model $record): mixed
    {
        return match ($this->anonymizationFields[$field] ?? null) {
            'redact' => '[REDACTED]',
            'structured' => ($this->redactor)($field, $value, $record),
            default => null,
        };
    }

    /**
     * Owner contract parts beyond the original native contract. Only owners that use them add
     * keys, so the fingerprint of an owner registered before these options existed is unchanged.
     *
     * @return array<string, mixed>
     */
    public function extendedContract(): array
    {
        return array_filter([
            'cutoff_column' => $this->cutoffColumn,
            'protections' => $this->protections === [] ? null : array_keys($this->protections),
            'contract_version' => $this->contractVersion,
        ], fn (mixed $value): bool => $value !== null);
    }

    public function validateNativeContract(DataRetentionPolicy $policy): void
    {
        $model = $this->model();
        $table = $model->getTable();

        if (! Schema::hasTable($table)) {
            throw new RetentionContractException('owner_table_unavailable', 'The supported retention owner is unavailable.');
        }

        if (! Schema::hasColumn($table, $this->cutoffColumn())) {
            throw new RetentionContractException('cutoff_column_unavailable', 'The supported retention cutoff is unavailable.');
        }

        foreach (array_keys($this->conditionOperators) as $field) {
            if (! Schema::hasColumn($table, $field)) {
                throw new RetentionContractException('registry_column_unavailable', 'A registered retention condition is unavailable.');
            }
        }

        foreach ($this->anonymizationFields as $field => $strategy) {
            if (! Schema::hasColumn($table, $field)) {
                throw new RetentionContractException('registry_anonymization_column_unavailable', 'A registered anonymization field is unavailable.');
            }
            if (! in_array($strategy, self::STRATEGIES, true) || ($strategy === 'structured' && ! $this->redactor)) {
                throw new RetentionContractException('registry_anonymization_strategy_invalid', 'A registered anonymization field has no supported method.');
            }
        }

        $conditions = $policy->retention_conditions;
        if ($conditions === null || $conditions === []) {
            return;
        }

        if (! is_array($conditions) || array_is_list($conditions)) {
            throw new RetentionContractException('invalid_conditions', 'Retention conditions must be a keyed object.');
        }

        foreach ($conditions as $field => $value) {
            if (! is_string($field) || ! array_key_exists($field, $this->conditionOperators)) {
                throw new RetentionContractException('unknown_condition', 'The retention policy contains an unsupported condition.');
            }

            $operator = $this->operator($value);
            if (! in_array($operator, $this->conditionOperators[$field], true)) {
                throw new RetentionContractException('unsupported_condition_operator', 'The retention policy contains an unsupported condition operator.');
            }

            $this->validateConditionValue($operator, $value);
        }
    }

    public function applyConditions(Builder $query, DataRetentionPolicy $policy): void
    {
        $this->validateNativeContract($policy);
        $table = $this->model()->getTable();

        foreach ($policy->retention_conditions ?? [] as $field => $value) {
            $column = $table.'.'.$field;
            $operator = $this->operator($value);

            match ($operator) {
                'equals' => $value === null
                    ? $query->whereNull($column)
                    : $query->where($column, $value),
                'in' => $query->whereIn($column, array_is_list($value) ? $value : $value['in']),
                'not_in' => $query->whereNotIn($column, $value['not_in']),
                'null' => $value['null'] ? $query->whereNull($column) : $query->whereNotNull($column),
                'not_null' => $value['not_null'] ? $query->whereNotNull($column) : $query->whereNull($column),
            };
        }
    }

    private function operator(mixed $value): string
    {
        if (! is_array($value)) {
            return 'equals';
        }

        if (array_is_list($value)) {
            return 'in';
        }

        $operators = array_values(array_intersect(['in', 'not_in', 'null', 'not_null'], array_keys($value)));
        if (count($operators) !== 1 || count($value) !== 1) {
            throw new RetentionContractException('invalid_condition_operator', 'Each retention condition must use one supported operator.');
        }

        return $operators[0];
    }

    private function validateConditionValue(string $operator, mixed $value): void
    {
        $operand = is_array($value) && ! array_is_list($value) ? $value[$operator] : $value;

        if (in_array($operator, ['null', 'not_null'], true) && ! is_bool($operand)) {
            throw new RetentionContractException('invalid_condition_value', 'Null condition operators require a boolean value.');
        }

        if (in_array($operator, ['in', 'not_in'], true)) {
            if (! is_array($operand) || ! array_is_list($operand) || $operand === [] || count($operand) > 100) {
                throw new RetentionContractException('invalid_condition_value', 'Retention condition lists must contain between 1 and 100 values.');
            }

            foreach ($operand as $item) {
                if (! is_scalar($item) && $item !== null) {
                    throw new RetentionContractException('invalid_condition_value', 'Retention condition values must be scalar or null.');
                }
            }

            return;
        }

        if ($operator === 'equals' && ! is_scalar($operand) && $operand !== null) {
            throw new RetentionContractException('invalid_condition_value', 'Retention condition values must be scalar or null.');
        }
    }
}
