<?php

namespace App\Services\Sites;

use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Site;
use App\Models\SiteFacilityZone;
use App\Models\SiteHoResource;
use App\Models\SiteHouseRoom;
use App\Models\SiteRoom;
use App\Models\SiteTypePlanPin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

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
            'pins.*.room_ref_type' => ['nullable', 'string', Rule::in(['house_room', 'ho_resource', 'facility_zone'])],
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

    public function validateSingle(Request $request, Site $site, SiteTypePlanPin $pin): array
    {
        $rules = [
            'kind' => ['sometimes', 'required', 'string', Rule::in(SiteTypePlanPin::KINDS)],
            'subkind' => ['sometimes', 'nullable', 'string', 'max:64'],
            'device_id' => ['sometimes', 'nullable', 'integer', 'exists:devices,id'],
            'room_ref_type' => ['sometimes', 'nullable', 'string', Rule::in(['house_room', 'ho_resource', 'facility_zone'])],
            'room_ref_id' => ['sometimes', 'nullable', 'integer'],
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'meta' => ['sometimes', 'nullable', 'array'],
            'x' => ['sometimes', 'required', 'numeric', 'between:0,1'],
            'y' => ['sometimes', 'required', 'numeric', 'between:0,1'],
            'rotation_deg' => ['sometimes', 'nullable', 'integer', 'between:-360,360'],
            'width' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
            'height' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
            'path_points' => ['sometimes', 'nullable', 'array'],
            'path_points.*.x' => ['required_with:path_points', 'numeric', 'between:0,1'],
            'path_points.*.y' => ['required_with:path_points', 'numeric', 'between:0,1'],
            'sort_order' => ['sometimes', 'nullable', 'integer'],
        ];

        $validator = Validator::make($request->all(), $rules);
        $validator->after(function ($validator) use ($request, $site, $pin): void {
            $candidate = array_merge($pin->only([
                'kind',
                'subkind',
                'device_id',
                'room_ref_type',
                'room_ref_id',
                'path_points',
            ]), $request->all());
            $this->validatePins([$candidate], $site, 'full', '', $validator);
        });

        return $validator->validate();
    }

    public function assertReferences(array $pins, Site $site, string $mode = 'full'): void
    {
        $validator = Validator::make(['pins' => $pins], []);
        $validator->after(function ($validator) use ($pins, $site, $mode): void {
            $this->validatePins($pins, $site, $mode, 'pins', $validator);
        });
        $validator->validate();
    }

    private function validatePins(array $pins, Site $site, string $mode, string $prefix, $validator): void
    {
        $taxonomy = config('site_plan_taxonomy.kinds', []);

        foreach (array_values($pins) as $index => $pin) {
            if (! is_array($pin)) {
                continue;
            }

            $kind = (string) ($pin['kind'] ?? '');
            $field = $prefix === '' ? '' : "{$prefix}.{$index}";
            $fieldName = fn (string $name): string => $field === '' ? $name : "{$field}.{$name}";

            if ($mode === 'emergency' && ! in_array($kind, SiteTypePlanPin::EMERGENCY_KINDS, true)) {
                $validator->errors()->add($fieldName('kind'), 'Emergency plan edits can only include emergency, fire, or life-safety pins.');
            }

            $subkind = $pin['subkind'] ?? null;
            if ($subkind !== null && $subkind !== '') {
                $allowed = collect($taxonomy[$kind]['subkinds'] ?? [])
                    ->pluck('value')
                    ->all();

                if ($allowed === []) {
                    $validator->errors()->add($fieldName('subkind'), 'This pin kind does not support a type.');
                } elseif (! in_array($subkind, $allowed, true)) {
                    $validator->errors()->add($fieldName('subkind'), 'This type is not valid for the selected pin kind.');
                }
            }

            $deviceId = $pin['device_id'] ?? null;
            if ($kind !== SiteTypePlanPin::KIND_DEVICE && $deviceId !== null) {
                $validator->errors()->add($fieldName('device_id'), 'Only device pins can link to a device.');
            }

            if ($kind === SiteTypePlanPin::KIND_DEVICE && $deviceId !== null && ! $this->deviceIsAssignedToSite((int) $deviceId, $site)) {
                $validator->errors()->add($fieldName('device_id'), 'Pick a device currently assigned to this site.');
            }

            $roomRefType = $pin['room_ref_type'] ?? null;
            $roomRefId = $pin['room_ref_id'] ?? null;
            if (($roomRefType === null) !== ($roomRefId === null)) {
                $validator->errors()->add($fieldName('room_ref_id'), 'Choose both a Site space type and its matching Site space.');
            } elseif ($roomRefType !== null && ! $this->roomReferenceBelongsToSite((string) $roomRefType, (int) $roomRefId, $site)) {
                $validator->errors()->add($fieldName('room_ref_id'), 'Pick a room, resource, or zone belonging to this site.');
            }

            $pathPoints = $pin['path_points'] ?? null;
            $hasPathPoints = is_array($pathPoints) && count($pathPoints) > 0;
            if ($kind === SiteTypePlanPin::KIND_EVACUATION_ROUTE) {
                if (! is_array($pathPoints) || count($pathPoints) < 2) {
                    $validator->errors()->add($fieldName('path_points'), 'Evacuation routes need at least two points.');
                }
            } elseif ($hasPathPoints) {
                $validator->errors()->add($fieldName('path_points'), 'Path points are only valid for evacuation routes.');
            }
        }

    }

    private function deviceIsAssignedToSite(int $deviceId, Site $site): bool
    {
        return DeviceAssignment::query()
            ->where('device_id', $deviceId)
            ->whereNull('released_at')
            ->where(function ($query) use ($site): void {
                $query->where(function ($siteAssignment) use ($site): void {
                    $siteAssignment
                        ->where('assignable_type', DeviceAssignment::TARGET_SITE)
                        ->where('assignable_id', $site->id);
                })->orWhere(function ($roomAssignment) use ($site): void {
                    $roomAssignment
                        ->where('assignable_type', DeviceAssignment::TARGET_ROOM)
                        ->whereIn('assignable_id', SiteRoom::query()->select('id')->where('site_id', $site->id));
                });
            })
            ->exists();
    }

    private function roomReferenceBelongsToSite(string $type, int $id, Site $site): bool
    {
        $query = match ($type) {
            'house_room' => SiteHouseRoom::query(),
            'ho_resource' => SiteHoResource::query(),
            'facility_zone' => SiteFacilityZone::query(),
            default => null,
        };

        return $query?->whereKey($id)->where('site_id', $site->id)->exists() ?? false;
    }
}
