<?php

namespace App\Http\Requests\Operations;

use App\Services\Tracking\ClientLocationAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreClientLocationZoneDraftRequest extends FormRequest
{
    /** Reuse the exact proposal contract for a persisted snapshot at activation. Authorization remains with the caller. */
    public static function validateSnapshot(array $data): void
    {
        $request = new self;
        $request->setMethod('POST');
        $request->replace($data);
        $validator = \Illuminate\Support\Facades\Validator::make($data, $request->rules());
        $request->withValidator($validator);
        $validator->validate();
    }

    public function authorize(): bool
    {
        app(ClientLocationAccessService::class)->resolve($this->user(), $this->route('client'), true);

        return true;
    }

    public function rules(): array
    {
        $custom = $this->input('geometry_source') === 'custom';

        return [
            'name' => ['required', 'string', 'max:120'],
            'purpose' => ['required', 'string', 'max:2000'],
            'classification' => ['required', Rule::in(['agreed', 'attention'])],
            'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,100}$/'],
            'access_fingerprint' => ['required', 'string', 'size:64'],
            'expected_revision' => [$this->isMethod('put') ? 'required' : 'prohibited', 'integer', 'min:1'],
            'geometry_source' => ['required', Rule::in(['custom', 'canonical'])],
            'source_change_reviewed' => ['sometimes', 'boolean'],
            'canonical_geofence_id' => [$custom ? 'prohibited' : 'required', 'integer', 'min:1'],
            'canonical_geometry_hash' => [$custom ? 'prohibited' : 'required', 'string', 'size:64'],
            'geometry' => [$custom ? 'required' : 'prohibited', 'array:type,center,radius_m,coordinates'],
            'geometry.type' => [Rule::requiredIf($custom), Rule::in(['circle', 'polygon'])],
            'geometry.center' => ['required_if:geometry.type,circle', 'prohibited_if:geometry.type,polygon', 'array:lat,lng'],
            'geometry.center.lat' => ['required_with:geometry.center', 'numeric', 'between:-90,90'],
            'geometry.center.lng' => ['required_with:geometry.center', 'numeric', 'between:-180,180'],
            'geometry.radius_m' => ['required_if:geometry.type,circle', 'prohibited_if:geometry.type,polygon', 'numeric', 'gt:0'],
            'geometry.coordinates' => ['required_if:geometry.type,polygon', 'prohibited_if:geometry.type,circle', 'array', 'min:3', 'max:200'],
            'geometry.coordinates.*' => ['array:lat,lng'],
            'geometry.coordinates.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'geometry.coordinates.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'schedule' => ['required', 'array:timezone,weekdays,start,end,following_day,first_date,last_date,exception_dates'],
            'schedule.timezone' => ['required', Rule::in(['Pacific/Auckland'])],
            'schedule.weekdays' => ['required', 'array', 'min:1', 'max:7'],
            'schedule.weekdays.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'schedule.start' => ['required', 'date_format:H:i'],
            'schedule.end' => ['required', 'date_format:H:i'],
            'schedule.following_day' => ['required', 'boolean'],
            'schedule.first_date' => ['required', 'date_format:Y-m-d'],
            'schedule.last_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:schedule.first_date'],
            'schedule.exception_dates' => ['present', 'array', 'max:366'],
            'schedule.exception_dates.*' => ['date_format:Y-m-d', 'distinct', 'after_or_equal:schedule.first_date', 'before_or_equal:schedule.last_date'],
            'response_proposal' => ['nullable', 'string', 'max:2000'],
            'status' => ['prohibited'], 'active' => ['prohibited'], 'is_active' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $schedule = $this->input('schedule');
            if ($this->input('geometry.type') === 'circle' && ! is_finite((float) $this->input('geometry.radius_m'))) {
                $validator->errors()->add('geometry.radius_m', 'Enter a finite radius in metres.');
            }
            if ((! $schedule['following_day'] && $schedule['end'] <= $schedule['start'])
                || ($schedule['following_day'] && $schedule['end'] > $schedule['start'])) {
                $validator->errors()->add('schedule.end', 'Choose a later finish, or mark an overnight finish on the following day (at most 24 hours).');
            }
            foreach ($schedule['exception_dates'] as $date) {
                if (! in_array(CarbonImmutable::parse($date)->isoWeekday(), array_map('intval', $schedule['weekdays']), true)) {
                    $validator->errors()->add('schedule.exception_dates', 'An exception must fall on a selected scheduled day.');
                }
            }
            if ($this->input('geometry.type') !== 'polygon') {
                return;
            }
            $points = $this->input('geometry.coordinates');
            $count = count($points);
            $area = 0;
            $cross = fn ($a, $b, $c) => ($b['lng'] - $a['lng']) * ($c['lat'] - $a['lat']) - ($b['lat'] - $a['lat']) * ($c['lng'] - $a['lng']);
            $on = fn ($a, $b, $p) => abs($cross($a, $b, $p)) < 1e-12
                && $p['lng'] >= min($a['lng'], $b['lng']) && $p['lng'] <= max($a['lng'], $b['lng'])
                && $p['lat'] >= min($a['lat'], $b['lat']) && $p['lat'] <= max($a['lat'], $b['lat']);
            for ($i = 0; $i < $count; $i++) {
                $a = $points[$i];
                $b = $points[($i + 1) % $count];
                if ($a == $b || abs($a['lng'] - $b['lng']) > 180) {
                    $validator->errors()->add('geometry', 'Use distinct corners without crossing the date line.');

                    return;
                }
                // Translate to the first point to avoid cancellation on small local boundaries.
                $area += ($a['lng'] - $points[0]['lng']) * ($b['lat'] - $points[0]['lat']) - ($b['lng'] - $points[0]['lng']) * ($a['lat'] - $points[0]['lat']);
                for ($j = $i + 2; $j < $count; $j++) {
                    if ($i === 0 && $j === $count - 1) {
                        continue;
                    }
                    $c = $points[$j];
                    $d = $points[($j + 1) % $count];
                    if (($cross($a, $b, $c) * $cross($a, $b, $d) < 0 && $cross($c, $d, $a) * $cross($c, $d, $b) < 0)
                        || $on($a, $b, $c) || $on($a, $b, $d) || $on($c, $d, $a) || $on($c, $d, $b)) {
                        $validator->errors()->add('geometry', 'The boundary must not cross or touch itself. Move the affected corners.');

                        return;
                    }
                }
            }
            if (abs($area) < 1e-12) {
                $validator->errors()->add('geometry', 'Spread the corners out to enclose an area.');
            }
        });
    }
}
