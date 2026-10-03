<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Services\Medication\Downtime\DowntimeAccess;
use App\Services\Medication\Downtime\PaperEntryService;
use Illuminate\Http\Request;

class MedicationPaperEntryController extends Controller
{
    public function __construct(private readonly DowntimeAccess $access, private readonly PaperEntryService $paper) {}

    public function preview(Request $request, int $downtime)
    {
        $data = $this->facts($request);
        $row = $this->access->downtime($request->user(), $downtime);

        return response()->json($this->paper->preview($request->user(), $row, $data))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, int $downtime)
    {
        $data = $this->facts($request) + $request->validate(['request_uuid' => 'required|uuid', 'preview_token' => 'required|string|size:64', 'accountable_confirmation' => 'accepted']);
        $row = $this->access->downtime($request->user(), $downtime);
        $this->paper->capture($request->user(), $row, $data);

        return back()->with('success', 'Paper facts saved. The dose is not posted until confirmed and reconciled.');
    }

    public function confirm(Request $request, int $downtime, int $entry)
    {
        $data = $request->validate(['kind' => 'required|in:giver,witness', 'accountable_confirmation' => 'accepted', 'witness_pin' => 'nullable|string|regex:/^[0-9]{6}$/']);
        $row = $this->access->downtime($request->user(), $downtime);
        $paper = $this->access->entry($request->user(), $row, $entry);
        $this->paper->confirm($request->user(), $row, $paper, $data['kind'], $data['witness_pin'] ?? null);

        return back()->with('success', 'Your accountable paper confirmation is saved. Check reconciliation before posting.');
    }

    public function reconciliationPreview(Request $request, int $downtime, int $entry)
    {
        $row = $this->access->downtime($request->user(), $downtime);
        $paper = $this->access->entry($request->user(), $row, $entry);

        return response()->json($this->paper->reconciliationPreview($request->user(), $row, $paper))->header('Cache-Control', 'private, no-store');
    }

    public function reconcile(Request $request, int $downtime, int $entry)
    {
        $data = $request->validate(['preview_token' => 'required|string|size:64', 'accountable_confirmation' => 'accepted']);
        $row = $this->access->downtime($request->user(), $downtime);
        $paper = $this->access->entry($request->user(), $row, $entry);
        $result = $this->paper->reconcile($request->user(), $row, $paper, $data['preview_token']);
        if (! $result['success']) {
            return back()->withErrors(['reconciliation' => $result['error']]);
        }

        return back()->with('success', 'Entered from paper. The canonical eMAR record is saved.');
    }

    private function facts(Request $request): array
    {
        return $request->validate([
            'client_medication_id' => 'required|integer|min:1', 'downtime_dose_id' => 'nullable|integer|min:1',
            'given_at' => 'required|string|max:40', 'given_by' => 'required|integer|min:1', 'witness_id' => 'nullable|integer|min:1',
            'outcome' => 'required|in:given,refused,withheld', 'dose_on_paper' => 'required_if:outcome,given|nullable|string|max:255',
            'notes' => 'required_unless:outcome,given|nullable|string|max:4000',
            'observations' => 'nullable|array:blood_glucose_level,pulse_bpm,blood_pressure_systolic,blood_pressure_diastolic',
            'observations.blood_glucose_level' => 'nullable|numeric|min:0|max:100', 'observations.pulse_bpm' => 'nullable|integer|min:1|max:400',
            'observations.blood_pressure_systolic' => 'nullable|integer|min:1|max:400', 'observations.blood_pressure_diastolic' => 'nullable|integer|min:1|max:300',
        ]);
    }
}
