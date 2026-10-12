<?php

namespace App\Http\Requests;

use App\Services\Overview\OverviewAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class OverviewLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && in_array($this->route('role'), app(OverviewAccess::class)->roles($this->user()), true);
    }

    public function rules(): array
    {
        $access = app(OverviewAccess::class);
        $role = (string) $this->route('role');
        $ids = array_column($access->catalog($role)['blocks'], 'id');
        $sites = ['page', 'all', ...array_map('strval', $access->siteIds($this->user(), $role))];
        $rules = [
            'revision' => ['present', 'nullable', 'string', 'size:64'],
            'value' => ['required', 'array:blocks,favorites,copies,density,range'],
            'value.blocks' => ['present', 'array', 'max:20'],
            'value.favorites' => ['present', 'array', 'max:20'],
            'value.favorites.*' => ['string', 'distinct', Rule::in($ids)],
            'value.copies' => ['present', 'array', 'max:3'],
            'value.copies.*' => ['array:name,blocks,density'],
            'value.copies.*.name' => ['required', 'string', 'min:1', 'max:32'],
            'value.copies.*.blocks' => ['present', 'array', 'max:20'],
            'value.copies.*.density' => ['required', Rule::in(['balanced', 'compact'])],
            'value.density' => ['required', Rule::in(['balanced', 'compact'])],
            'value.range' => ['required', Rule::in(['today', 'week', 'month', 'quarter'])],
        ];
        foreach (['value.blocks.*', 'value.copies.*.blocks.*'] as $prefix) {
            $rules[$prefix] = ['array:id,span,height,site'];
            $rules[$prefix.'.id'] = ['required', 'string', Rule::in($ids)];
            $rules[$prefix.'.span'] = ['required', 'integer', Rule::in([4, 6, 8, 12])];
            $rules[$prefix.'.height'] = ['required', 'integer', 'min:0', 'max:720'];
            $rules[$prefix.'.site'] = ['required', 'string', Rule::in($sites)];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $value = $this->input('value');
            if (! is_array($value)) {
                return;
            }
            $names = [];
            foreach (is_array($value['copies'] ?? null) ? $value['copies'] : [] as $index => $copy) {
                $name = is_array($copy) && is_string($copy['name'] ?? null) ? trim($copy['name']) : '';
                if ($name === '' || in_array(mb_strtolower($name), $names, true)) {
                    $validator->errors()->add("value.copies.{$index}.name", 'Use a unique name for each saved arrangement.');
                }
                $names[] = mb_strtolower($name);
            }
            $layouts = [$value['blocks'] ?? [], ...array_map(fn ($copy) => is_array($copy) ? ($copy['blocks'] ?? []) : [], is_array($value['copies'] ?? null) ? $value['copies'] : [])];
            foreach ($layouts as $blocks) {
                if (! is_array($blocks) || ! array_is_list($blocks)) {
                    $validator->errors()->add('value.blocks', 'Blocks must be a list.');

                    continue;
                }
                $ids = array_filter(array_column(array_filter($blocks, 'is_array'), 'id'), 'is_string');
                if (count($ids) !== count(array_unique($ids))) {
                    $validator->errors()->add('value.blocks', 'Each block can appear once per arrangement.');
                }
            }
        }];
    }
}
