<?php

namespace App\Services\Sites;

use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Site;
use App\Models\SiteTypePlanPin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SiteTypePlanPinPayloadValidator
{
    public function validateBatch(Request $request, Site $site, bool $allowMode = true): array
    {
        $rules = [
            'replace' => ['nullable', 'boolean'],
            'pins' => ['required', 'array'],
            'pins.*.id' => ['nullable', 'integer'],
            'pins.*.kind' => ['required', 'string', Rule::in(SiteTypePlanPin::KINDS)],
            'pins.*.subkind' => ['nullable', 'string', 'max:64'],
            'pins.*.device_id' => ['nullable', 'integer', 'exists:devices,id'],
            'pins.*.room_ref_type' => ['nullable', 'string', 'max:64'],
            'pins.*.room_ref_id' => ['nullable', 'integer'],
            'pins.*.label' => ['nullable', 'string', 'max:120'],
            'pins.*.notes' => ['nullable', 'string', 'max:5000'],
            'pins.*.meta' => ['nullable', 'array'],
            'pins.*.x' => ['required', 'numeric', 'between:0,1'],
            'pins.*.y' => ['required', 'numeric', 'between:0,1'],
            'pins.*.rotation_deg' => ['nullable', 'integer', 'between:-360,360'],
            'pins.*.width' => ['nullable', 'numeric', 'between:0,1'],
            'pins.*.height' => ['nullable', 'numeric', 'between:0,1'],
            'pins.*.path_points' => ['nullable', 'array'],
            'pins.*.path_points.*.x' => ['required_with:pins.*.path_points', 'numeric', 'between:0,1'],
            'pins.*.path_points.*.y' => ['required_with:pins.*.path_points', 'numeric', 'between:0,1'],
            'pins.*.sort_order' => ['nullable', 'integer'],
        ];

        if ($allowMode) {
            $rules['mode'] = ['nullable', 'string', Rule::in(['full', 'emergency'])];
        }

        $validator = Validator::make($request->all(), $rules);
        $validator->after(function ($validator) use ($request, $site, $allowMode): void {
            $mode = $allowMode ? (string) $request->input('mode', 'full') : 'emergency';
            $this->validatePins((array) $request->input('pins', []), $site, $mode, 'pins', $validator);
        });

        return $validator->validate();
    }

    private function validatePins(array $pins, Site $site, string $mode, string $prefix, $validator): void
    {
        $taxonomy = config('site_plan_taxonomy.kinds', []);

        foreach (array_values($pins) as $index => $pin) {
            if (! is_array($pin)) {
                continue;
            }

            $kind = (string) ($pin['kind'] ?? '');
            $field = "{$prefix}.{$index}";

            if ($mode === 'emergency' && ! in_array($kind, SiteTypePlanPin::EMERGENCY_KINDS, true)) {
                $validator->errors()->add("{$field}.kind", 'Emergency plan edits can only include emergency, fire, or life-safety pins.');
            }

            $subkind = $pin['subkind'] ?? null;
            if ($subkind !== null && $subkind !== '') {
                $allowed = collect($taxonomy[$kind]['subkinds'] ?? [])
                    ->pluck('value')
                    ->all();

                if ($allowed === []) {
                    $validator->errors()->add("{$field}.subkind", 'This pin kind does not support a type.');
                } elseif (! in_array($subkind, $allowed, true)) {
                    $validator->errors()->add("{$field}.subkind", 'This type is not valid for the selected pin kind.');
                }
            }

            $deviceId = $pin['device_id'] ?? null;
            if ($kind !== SiteTypePlanPin::KIND_DEVICE && $deviceId !== null) {
                $validator->errors()->add("{$field}.device_id", 'Only device pins can link to a device.');
            }

            if ($kind === SiteTypePlanPin::KIND_DEVICE && $deviceId !== null && ! $this->deviceIsAssignedToSite((int) $deviceId, $site)) {
                $validator->errors()->add("{$field}.device_id", 'Pick a device currently assigned to this site.');
            }

            $pathPoints = $pin['path_points'] ?? null;
            $hasPathPoints = is_array($pathPoints) && count($pathPoints) > 0;
            if ($kind === SiteTypePlanPin::KIND_EVACUATION_ROUTE) {
                if (! is_array($pathPoints) || count($pathPoints) < 2) {
                    $validator->errors()->add("{$field}.path_points", 'Evacuation routes need at least two points.');
                }
            } elseif ($hasPathPoints) {
                $validator->errors()->add("{$field}.path_points", 'Path points are only valid for evacuation routes.');
            }
        }

        if ($validator->errors()->isNotEmpty()) {
            throw new ValidationException($validator);
        }
    }

    private function deviceIsAssignedToSite(int $deviceId, Site $site): bool
    {
        return DeviceAssignment::query()
            ->where('device_id', $deviceId)
            ->where('assignable_type', DeviceAssignment::TARGET_SITE)
            ->where('assignable_id', $site->id)
            ->whereNull('released_at')
            ->exists();
    }
}
