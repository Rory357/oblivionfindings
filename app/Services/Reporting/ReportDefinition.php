<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ReportDefinition
{
    public function validate(array $input, array $sources): array
    {
        $source = is_string($input['source'] ?? null) ? $input['source'] : '';
        abort_unless(isset($sources[$source]), 403, 'This report source is unavailable.');
        $fields = array_keys($sources[$source]['fields']);
        $numeric = array_keys(array_filter($sources[$source]['fields'], fn ($f) => $f['type'] === 'number'));
        $data = Validator::make($input, [
            'version' => ['required', 'integer', 'in:1'],
            'name' => ['required', 'string', 'max:120'],
            'source' => ['required', Rule::in(array_keys($sources))],
            'columns' => ['required', 'array', 'min:1', 'max:30'],
            'columns.*' => ['required', 'string', 'distinct', Rule::in($fields)],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'site_ids' => ['present', 'array', 'max:100'], 'site_ids.*' => ['integer', 'min:1', 'distinct'],
            'resource_ids' => ['present', 'array', 'max:100'], 'resource_ids.*' => ['integer', 'min:1', 'distinct'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'match' => ['required', 'in:all,any'],
            'filters' => ['present', 'array', 'max:25'],
            'filters.*' => ['array:field,operator,value'],
            'filters.*.field' => ['required', Rule::in($fields)],
            'filters.*.operator' => ['required', 'in:eq,ne,gt,gte,lt,lte,contains,missing,known'],
            'filters.*.value' => ['nullable', 'string', 'max:200'],
            'filter_groups' => ['sometimes', 'array', 'max:8'],
            'filter_groups.*' => ['array:match,filters'],
            'filter_groups.*.match' => ['required', 'in:all,any'],
            'filter_groups.*.filters' => ['required', 'array', 'min:1', 'max:25'],
            'filter_groups.*.filters.*' => ['array:field,operator,value'],
            'filter_groups.*.filters.*.field' => ['required', Rule::in($fields)],
            'filter_groups.*.filters.*.operator' => ['required', 'in:eq,ne,gt,gte,lt,lte,contains,missing,known'],
            'filter_groups.*.filters.*.value' => ['nullable', 'string', 'max:200'],
            'date_bucket' => ['sometimes', 'in:day,week,month'],
            'detail_sort' => ['nullable', Rule::in($fields)],
            'detail_direction' => ['sometimes', 'in:asc,desc'],
            'highlight' => ['nullable', 'array:measure,operator,value'],
            'highlight.measure' => ['required_with:highlight', 'regex:/^m[1-8]$/'],
            'highlight.operator' => ['required_with:highlight', 'in:gt,gte,lt,lte'],
            'highlight.value' => ['required_with:highlight', 'numeric'],
            'groups' => ['present', 'array', 'max:2'],
            'groups.*' => ['string', 'distinct', Rule::in($fields)],
            'measures' => ['required', 'array', 'min:1', 'max:8'],
            'measures.*' => ['array:id,label,operation,field,decimals,unit,formula,where'],
            'measures.*.id' => ['required', 'regex:/^m[1-8]$/', 'distinct'],
            'measures.*.label' => ['required', 'string', 'max:80'],
            'measures.*.operation' => ['required', 'in:count,known,distinct,sum,avg,min,max,p50,p95,formula'],
            'measures.*.field' => ['nullable', Rule::in($fields)],
            'measures.*.where' => ['nullable', 'array:field,operator,value'],
            'measures.*.where.field' => ['required_with:measures.*.where', Rule::in($fields)],
            'measures.*.where.operator' => ['required_with:measures.*.where', 'in:eq,ne,gt,gte,lt,lte,contains,missing,known'],
            'measures.*.where.value' => ['nullable', 'string', 'max:200'],
            'measures.*.formula' => ['nullable', 'string', 'max:200'],
            'measures.*.decimals' => ['required', 'integer', 'between:0,6'],
            'measures.*.unit' => ['required', 'in:number,percent,NZD,km,hours,minutes,count'],
            'layout' => ['required', 'in:table,bar,line,donut,summary,pivot'],
            'sort' => ['required', 'in:group,value'], 'direction' => ['required', 'in:asc,desc'],
            'limit' => ['required', 'integer', 'between:1,500'],
            'precision' => ['required', 'in:exact,approximate,redacted'],
            'comparison' => ['required', 'boolean'],
        ])->validate();
        $from = CarbonImmutable::parse($data['date_from'], 'Pacific/Auckland');
        $to = CarbonImmutable::parse($data['date_to'], 'Pacific/Auckland');
        $maxDays = $sources[$source]['domain'] === 'fleet' ? 366 : 31;
        if ($from->diffInDays($to) >= $maxDays || $to->toDateString() > now('Pacific/Auckland')->toDateString()) {
            throw ValidationException::withMessages(['date_to' => "Choose up to {$maxDays} calendar days ending today or earlier."]);
        }
        $groupFilters = array_merge([], ...array_column($data['filter_groups'] ?? [], 'filters'));
        if (count($groupFilters) + count($data['filters']) > 25 || ($groupFilters !== [] && $data['filters'] !== [])) {
            throw ValidationException::withMessages(['filters' => 'Use up to 25 rules, either flat or in groups.']);
        }
        if (isset($data['highlight']) && (! in_array($data['highlight']['measure'], array_column($data['measures'], 'id')) || ! is_finite((float) $data['highlight']['value']))) {
            throw ValidationException::withMessages(['highlight' => 'Choose an existing measure and a finite threshold.']);
        }
        foreach (array_merge($data['filters'], $groupFilters, array_values(array_filter(array_column($data['measures'], 'where')))) as $filter) {
            if (in_array($filter['operator'], ['gt', 'gte', 'lt', 'lte'])) {
                $isDate = $sources[$source]['fields'][$filter['field']]['type'] === 'date';
                $valid = $isDate ? ! Validator::make(['date' => $filter['value'] ?? ''], ['date' => 'required|date_format:Y-m-d'])->fails()
                    : in_array($filter['field'], $numeric) && is_numeric($filter['value'] ?? null) && is_finite((float) $filter['value']);
                if (! $valid) {
                    throw ValidationException::withMessages(['filters' => 'Comparisons need a numeric value or a calendar date (YYYY-MM-DD) for a date field.']);
                }
            }
        }
        $ids = array_column(array_filter($data['measures'], fn ($m) => $m['operation'] !== 'formula'), 'id');
        foreach ($data['measures'] as $measure) {
            if (! in_array($measure['operation'], ['count', 'formula']) && empty($measure['field'])) {
                throw ValidationException::withMessages(['measures' => 'Choose a field for each measure.']);
            }
            if (in_array($measure['operation'], ['sum', 'avg', 'min', 'max', 'p50', 'p95']) && ! in_array($measure['field'], $numeric)) {
                throw ValidationException::withMessages(['measures' => 'This calculation needs a numeric field.']);
            }
            if ($measure['operation'] === 'formula') {
                if (isset($measure['where'])) {
                    throw ValidationException::withMessages(['measures' => 'Put conditions on base measures; formulas combine those results.']);
                }
                app(ReportFormula::class)->calculate($measure['formula'] ?? '', array_fill_keys(array_filter($ids, fn ($id) => $id !== $measure['id']), 1.0));
            }
        }
        if ($data['layout'] === 'line' && (($data['groups'][0] ?? '') !== 'date' || count($data['groups']) !== 1)) {
            throw ValidationException::withMessages(['groups' => 'Time series must group first by date.']);
        }

        if ($data['layout'] === 'pivot' && count($data['groups']) !== 2) {
            throw ValidationException::withMessages(['groups' => 'A pivot needs a row group and a column group.']);
        }

        return $data;
    }
}
