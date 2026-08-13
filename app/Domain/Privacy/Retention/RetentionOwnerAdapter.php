<?php

namespace App\Domain\Privacy\Retention;

use App\Models\DataRetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

final class RetentionOwnerAdapter
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, array<int, string>>  $conditionOperators
     * @param  array<string, 'clear'|'redact'>  $anonymizationFields
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $modelClass,
        public readonly array $conditionOperators,
        public readonly array $anonymizationFields,
        public readonly ?string $activeCaseRelation = null,
    ) {}

    public function model(): Model
    {
        return new $this->modelClass;
    }

    public function usesSoftDeletes(): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($this->modelClass), true);
    }

    public function validateNativeContract(DataRetentionPolicy $policy): void
    {
        $model = $this->model();
        $table = $model->getTable();

        if (! Schema::hasTable($table)) {
            throw new RetentionContractException('owner_table_unavailable', 'The supported retention owner is unavailable.');
        }

        if (! Schema::hasColumn($table, $model->getCreatedAtColumn())) {
            throw new RetentionContractException('cutoff_column_unavailable', 'The supported retention cutoff is unavailable.');
        }

        foreach (array_keys($this->conditionOperators) as $field) {
            if (! Schema::hasColumn($table, $field)) {
                throw new RetentionContractException('registry_column_unavailable', 'A registered retention condition is unavailable.');
            }
        }

        foreach (array_keys($this->anonymizationFields) as $field) {
            if (! Schema::hasColumn($table, $field)) {
                throw new RetentionContractException('registry_anonymization_column_unavailable', 'A registered anonymization field is unavailable.');
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
