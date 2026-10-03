<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\ClientMedication;
use App\Services\MarScheduleService;
use App\Services\Medication\DoseSlots\ScheduledDoseStates;
use App\Services\Medication\Recording\DoseRecordingRequirements;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * eMAR P01 — what recording a dose needs and allows, for the recording
 * dialog. Read-only: saving re-checks everything under the record path's
 * locks. A dose outside the viewer's access is "not found".
 */
class DoseRequirementsController extends Controller
{
    public function __construct(
        private readonly DoseRecordingRequirements $requirements,
        private readonly MarScheduleService $schedule,
    ) {}

    /** One scheduled dose: GET /meds/today/doses/requirements?client_medication_id=&scheduled_for= */
    public function scheduled(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->canDo('medications.administer.record'), 403);

        $data = $request->validate([
            'client_medication_id' => ['required', 'integer', 'min:1'],
            'scheduled_for' => ['required', 'date'],
        ]);

        $order = ClientMedication::query()->with('client')->find($data['client_medication_id']);
        abort_unless($order !== null && ! $order->is_prn, 404);
        $this->requirements->assertVisible($user, $order);

        $scheduledFor = $this->schedule->parseWorkerDateTime((string) $data['scheduled_for']);
        $dueAt = $this->owedDueTime($order, $scheduledFor);
        abort_unless($dueAt !== null, 404);

        return response()->json($this->requirements->forScheduledDose($user, $order, $dueAt));
    }

    /** One as-needed order: GET /meds/today/prn/{medication}/requirements */
    public function asNeeded(Request $request, int $medication): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->canDo('medications.administer.record'), 403);

        $order = ClientMedication::query()->with('client')->find($medication);
        abort_unless($order !== null && $order->is_prn, 404);
        $this->requirements->assertVisible($user, $order);

        return response()->json($this->requirements->forAsNeeded($user, $order));
    }

    /** The due time the order owes on that NZ day, matched to the minute. */
    private function owedDueTime(ClientMedication $order, Carbon $scheduledFor): ?Carbon
    {
        $doses = app(ScheduledDoseStates::class)->dosesOn([$order], $scheduledFor, now())[(int) $order->id] ?? [];
        $dueTimes = array_map(fn (array $dose): Carbon => $dose['due_at'], $doses);
        // An order waiting for its check owes no dose yet, but the dialog
        // still explains why it can't be given: its own times decide.
        if ($dueTimes === [] && ! $order->isVerifiedForAdministration()) {
            $dueTimes = $this->schedule->scheduledTimesForDate(
                $order,
                $this->schedule->dateFromInput($scheduledFor->copy()->timezone($this->schedule->workerTimezone())->toDateString()),
            );
        }

        foreach ($dueTimes as $due) {
            if (abs($due->copy()->utc()->diffInSeconds($scheduledFor->copy()->utc(), false)) < 60) {
                return $due->copy();
            }
        }

        return null;
    }
}
