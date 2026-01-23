<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\ShiftSeries;
use App\Services\NotificationService;
use App\Models\ShiftTask;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ShiftSeriesController extends Controller
{
    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.create'), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'by_weekday' => ['required', 'array', 'min:1'],
            'by_weekday.*' => ['in:mon,tue,wed,thu,fri,sat,sun'],
            'starts_time' => ['required', 'date_format:H:i'],
            'ends_time' => ['required', 'date_format:H:i'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:scheduled,completed,cancelled'],
            'tasks' => ['sometimes', 'array'],
            'tasks.*.label' => ['required_with:tasks', 'string', 'max:255'],
        ]);

        // Validate time window
        $tz = $data['timezone'] ?? 'Pacific/Auckland';
        $startT = CarbonImmutable::createFromFormat('H:i', $data['starts_time'], $tz);
        $endT = CarbonImmutable::createFromFormat('H:i', $data['ends_time'], $tz);
        abort_unless($endT->greaterThan($startT), 422, 'ends_time must be after starts_time.');

        $conflicts = [];
        $occurrences = $this->expandWeeklyOccurrences(
            CarbonImmutable::parse($data['start_date'], $tz),
            CarbonImmutable::parse($data['end_date'], $tz),
            $data['by_weekday']
        );

        foreach ($occurrences as $date) {
            [$startsAt, $endsAt] = $this->combineDateAndTimes($date, $data['starts_time'], $data['ends_time'], $tz);
            $existing = $this->findConflicts(
                userId: (int) $data['user_id'],
                clientId: (int) $data['client_id'],
                startsAt: $startsAt,
                endsAt: $endsAt,
                ignoreShiftId: null
            );

            if ($existing->isNotEmpty()) {
                $conflicts[] = [
                    'date' => $date->toDateString(),
                    'starts_at' => $startsAt->toDateTimeString(),
                    'ends_at' => $endsAt->toDateTimeString(),
                    'conflicting_shift_ids' => $existing->pluck('id')->values(),
                ];
            }
        }

        if (!empty($conflicts)) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'Conflicting shifts detected for one or more occurrences.',
                    'conflicts' => $conflicts,
                ], 422);
            }

            return back()->withErrors([
                'repeat' => 'Conflicting shifts detected for one or more occurrences. Please adjust times/staff/client.',
            ])->with('conflicts', $conflicts);
        }

        $result = DB::transaction(function () use ($auth, $data, $tz, $occurrences) {
            $series = ShiftSeries::create([
                ...Arr::except($data, ['tasks']),
                'timezone' => $tz,
                'status' => $data['status'] ?? 'scheduled',
                'created_by' => $auth->id,
            ]);

            $tasks = collect($data['tasks'] ?? [])
                ->map(fn ($t, $i) => ['label' => (string) ($t['label'] ?? ''), 'sort_order' => $i])
                ->filter(fn ($t) => trim($t['label']) !== '')
                ->values();

            foreach ($occurrences as $date) {
                [$startsAt, $endsAt] = $this->combineDateAndTimes($date, $data['starts_time'], $data['ends_time'], $tz);

                $shift = Shift::create([
                    'shift_series_id' => $series->id,
                    'client_id' => $data['client_id'],
                    'user_id' => $data['user_id'],
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'location' => $data['location'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'status' => $data['status'] ?? 'scheduled',
                    'created_by' => $auth->id,
                ]);

                foreach ($tasks as $t) {
                    ShiftTask::create([
                        'shift_id' => $shift->id,
                        'label' => $t['label'],
                        'sort_order' => $t['sort_order'],
                    ]);
                }
            }

            return [
                'series_id' => $series->id,
                'count' => count($occurrences),
            ];
        });


        $seriesModel = \App\Models\ShiftSeries::query()->find($result['series_id'] ?? null);
        $client = \App\Models\Client::query()->find($data['client_id'] ?? null);
        app(NotificationService::class)->notifyCrud($request->user(), 'created', 'shift series', $seriesModel, $client, [
            'title' => 'Recurring shifts created',
            'body' => 'Created ' . ($result['count'] ?? 0) . ' shifts.',
            'url' => url('/shifts'),
            'target_user_ids' => [$data['user_id'] ?? null],
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                ...$result,
            ], 201);
        }

        return redirect()->route('shifts.index')->with('success', 'Recurring shifts created (' . $result['count'] . ').');
    }

    private function expandWeeklyOccurrences(CarbonImmutable $start, CarbonImmutable $end, array $byWeekday): array
    {
        $map = [
            'mon' => 1,
            'tue' => 2,
            'wed' => 3,
            'thu' => 4,
            'fri' => 5,
            'sat' => 6,
            'sun' => 7,
        ];
        $wanted = collect($byWeekday)->map(fn ($d) => $map[$d] ?? null)->filter()->unique()->values()->all();
        $out = [];
        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            if (in_array((int) $d->dayOfWeekIso, $wanted, true)) {
                $out[] = $d;
            }
        }
        return $out;
    }

    private function combineDateAndTimes(CarbonImmutable $date, string $startsTime, string $endsTime, string $tz): array
    {
        $start = CarbonImmutable::parse($date->toDateString() . ' ' . $startsTime, $tz);
        $end = CarbonImmutable::parse($date->toDateString() . ' ' . $endsTime, $tz);
        return [$start, $end];
    }

    private function findConflicts(int $userId, int $clientId, CarbonImmutable $startsAt, CarbonImmutable $endsAt, ?int $ignoreShiftId)
    {
        return Shift::query()
            ->when($ignoreShiftId, fn ($q) => $q->where('id', '!=', $ignoreShiftId))
            ->where(function ($q) use ($userId, $clientId) {
                $q->where('user_id', $userId)
                  ->orWhere('client_id', $clientId);
            })
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->get(['id', 'user_id', 'client_id', 'starts_at', 'ends_at']);
    }
}
