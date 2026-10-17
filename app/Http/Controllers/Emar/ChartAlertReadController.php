<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\ClientMedicationAlert;
use App\Models\MedicationChartAlertRead;
use App\Services\AuditLogger;
use App\Services\MarScheduleService;
use App\Services\Medication\Recording\DoseRecordingRequirements;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * eMAR audit Lane A5 (EA-005): the worker read a "Prompt staff" chart alert
 * in the recording dialog. Kept once per worker per NZ day; the dialog asks
 * again the next day. Someone outside the person's access gets "not found".
 */
class ChartAlertReadController extends Controller
{
    public function __construct(
        private readonly DoseRecordingRequirements $requirements,
        private readonly MarScheduleService $schedule,
    ) {}

    public function store(Request $request, int $alert): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->canDo('medications.administer.record'), 403);

        $row = ClientMedicationAlert::query()->with('client')->find($alert);
        abort_unless(
            $row !== null && $row->client !== null && $row->enabled && $row->resolved_at === null && $row->prompt_on_open
                && $this->requirements->isPersonVisible($user, $row->client),
            404,
        );

        $readOn = CarbonImmutable::now($this->schedule->workerTimezone())->toDateString();
        $read = DB::transaction(function () use ($row, $user, $readOn): MedicationChartAlertRead {
            $read = MedicationChartAlertRead::query()->firstOrCreate(
                ['client_medication_alert_id' => $row->id, 'user_id' => $user->id, 'read_on' => $readOn],
                ['read_at' => now()],
            );
            if ($read->wasRecentlyCreated) {
                AuditLogger::logOrFail('medications.chart_alert.read', $read, [
                    'actor_id' => $user->id,
                    'client_id' => $row->client_id,
                    'client_medication_alert_id' => $row->id,
                    'read_on' => $readOn,
                ]);
            }

            return $read;
        });

        return response()->json([
            'read' => true,
            'read_at' => CarbonImmutable::instance($read->read_at)->utc()->toIso8601String(),
        ]);
    }
}
