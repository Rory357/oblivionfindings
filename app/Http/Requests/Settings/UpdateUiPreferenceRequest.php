<?php

namespace App\Http\Requests\Settings;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateUiPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $value = $this->input('value');
        if ($this->route('key') === 'fleet.overview.saved-views' && is_array($value)) {
            foreach ($value as &$view) {
                if (is_array($view) && is_array($view['filters'] ?? null)
                    && array_key_exists('q', $view['filters']) && $view['filters']['q'] === null) {
                    $view['filters']['q'] = '';
                }
            }
            unset($view);
        }
        $this->merge([
            'key' => $this->route('key'),
            'value' => $value,
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'key' => [
                'required',
                'string',
                'max:120',
                'regex:/^[a-z0-9][a-z0-9._-]{0,119}$/',
            ],
            'value' => $this->route('key') === 'fleet.overview.saved-views'
                ? ['present', 'array', 'max:6']
                : ['required', 'array', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        if ($this->route('key') !== 'fleet.overview.saved-views') {
            return;
        }

        $validator->after(function (Validator $validator): void {
            $views = $this->input('value');
            if (! is_array($views) || ! array_is_list($views)) {
                $validator->errors()->add('value', 'Saved views must be a list.');

                return;
            }

            $allowedSites = app(SecurityDevicesAccessService::class)->accessibleSiteIds($this->user());
            $choices = [
                'view' => ['overview', 'attention', 'upcoming', 'availability'],
                'period' => ['week', 'today'],
                'attention' => ['all', 'returns', 'restricted', 'unassigned', 'work', 'evidence'],
                'due' => ['all', 'overdue', 'today', 'undated'],
                'sort' => ['due', 'resource'],
                'availability' => ['all', 'Available now', 'In use', 'Restricted', 'Unknown'],
                'mapType' => ['all', 'vehicle', 'asset'],
                'mapFresh' => ['all', 'stale'],
                'agendaKind' => ['all', 'booking', 'appointment', 'due', 'work'],
            ];
            $keys = [...array_keys($choices), 'site', 'q', 'agendaDay'];
            $names = [];
            foreach ($views as $index => $view) {
                if (! is_array($view) || array_diff(array_keys($view), ['name', 'filters']) !== []
                    || array_diff(['name', 'filters'], array_keys($view)) !== []) {
                    $validator->errors()->add("value.{$index}", 'Saved view fields are invalid.');

                    continue;
                }
                $name = $view['name'];
                $filters = $view['filters'];
                if (! is_string($name) || trim($name) !== $name || $name === '' || mb_strlen($name) > 40) {
                    $validator->errors()->add("value.{$index}.name", 'Use a name of 1–40 characters.');
                } elseif (in_array(mb_strtolower($name), $names, true)) {
                    $validator->errors()->add("value.{$index}.name", 'Saved view names must be unique.');
                } else {
                    $names[] = mb_strtolower($name);
                }
                if (! is_array($filters) || array_diff(array_keys($filters), $keys) !== [] || array_diff($keys, array_keys($filters)) !== []) {
                    $validator->errors()->add("value.{$index}.filters", 'Saved view filters are invalid.');

                    continue;
                }
                foreach ($choices as $key => $values) {
                    if (! is_string($filters[$key]) || ! in_array($filters[$key], $values, true)) {
                        $validator->errors()->add("value.{$index}.filters.{$key}", 'Invalid filter value.');
                    }
                }
                $site = $filters['site'];
                if (! is_string($site) || ($site !== 'all' && (! ctype_digit($site) || ! in_array((int) $site, $allowedSites, true)))) {
                    $validator->errors()->add("value.{$index}.filters.site", 'Choose an approved Site.');
                }
                if (! is_string($filters['q']) || mb_strlen($filters['q']) > 120) {
                    $validator->errors()->add("value.{$index}.filters.q", 'Search must be 120 characters or fewer.');
                }
                $day = $filters['agendaDay'];
                if (! is_string($day) || (! in_array($day, ['all', 'today', 'tomorrow', 'rest'], true)
                    && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day))) {
                    $validator->errors()->add("value.{$index}.filters.agendaDay", 'Invalid agenda day.');
                }
            }
        });
    }
}
