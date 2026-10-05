<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Services\Medication\Downtime\DowntimeAccess;
use App\Services\Medication\Downtime\PaperEntryService;
use App\Services\Medication\Recording\RecordingContract;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    public function stockEvidence(Request $request, int $downtime, int $entry)
    {
        $data = $request->validate(['request_uuid' => 'required|uuid', 'settlement' => 'required|in:deduct_now,covered_by_count', 'closing_count_id' => 'required_if:settlement,covered_by_count|nullable|integer|min:1',
            'lines' => 'required|array|min:1|max:100', 'lines.*' => 'required|array:lot_id,revision,closing_quantity',
            'lines.*.lot_id' => 'required|integer|min:1', 'lines.*.revision' => 'required|integer|min:0',
            'lines.*.closing_quantity' => 'required|numeric|decimal:0,2|min:0|max:99999999.99', 'accountable_confirmation' => 'accepted']);
        $row = $this->access->downtime($request->user(), $downtime);
        $paper = $this->access->entry($request->user(), $row, $entry);
        $this->paper->settleStock($request->user(), $row, $paper, $data);

        return back()->with('success', 'Exact physical settlement reviewed. The dose still requires explicit reconciliation.');
    }

    public function recoveryAuthorization(Request $request, int $downtime, int $entry)
    {
        $data = $request->validate(['reason' => 'required|string|max:4000', 'accountable_confirmation' => 'accepted']);
        $row = $this->access->downtime($request->user(), $downtime);
        $paper = $this->access->entry($request->user(), $row, $entry);
        $this->paper->authorizeRecovery($request->user(), $row, $paper, $data);

        return back()->with('success', 'Historical authority reviewed. Emergency access remains closed; check the reconciliation preview.');
    }

    private function facts(Request $request): array
    {
        return $request->validate([
            'client_medication_id' => 'required|integer|min:1', 'downtime_dose_id' => 'nullable|integer|min:1',
            'given_at' => 'required|string|max:40', 'given_by' => 'required|integer|min:1', 'witness_id' => 'nullable|integer|min:1',
            'outcome' => 'required|in:given,refused,withheld', 'dose_on_paper' => 'required_if:outcome,given|nullable|string|max:255',
            'notes' => 'required_unless:outcome,given|nullable|string|max:4000',
            'clinical_facts' => 'nullable|array:quantity_given,amount_mode,amount_reason,late_reason,prn_reason,effect_check_due_at,more_severity,more_immediate_action',
            'clinical_facts.quantity_given' => 'nullable|numeric|decimal:0,2|gt:0|max:10000',
            'clinical_facts.amount_mode' => ['nullable', Rule::in(RecordingContract::AMOUNT_MODES)],
            'clinical_facts.amount_reason' => ['nullable', Rule::in(array_keys(RecordingContract::AMOUNT_REASONS))],
            'clinical_facts.late_reason' => ['nullable', Rule::in(array_keys(RecordingContract::LATE_REASONS))],
            'clinical_facts.prn_reason' => 'nullable|string|max:4000', 'clinical_facts.effect_check_due_at' => 'nullable|string|max:40',
            'clinical_facts.more_severity' => ['nullable', Rule::in(RecordingContract::MORE_SEVERITIES)],
            'clinical_facts.more_immediate_action' => 'nullable|string|max:2000',
            'stock_evidence' => 'nullable|array:stock_id,unit,quantity_removed,quantity_wasted,waste_reason,lines',
            'stock_evidence.stock_id' => 'nullable|integer|min:1', 'stock_evidence.unit' => 'nullable|string|max:50',
            'stock_evidence.quantity_removed' => 'nullable|numeric|gt:0|max:99999999.99',
            'stock_evidence.quantity_wasted' => 'nullable|numeric|min:0|max:99999999.99', 'stock_evidence.waste_reason' => 'nullable|string|max:4000',
            'stock_evidence.lines' => 'nullable|array|max:100', 'stock_evidence.lines.*' => 'array:lot_id,quantity,quantity_wasted',
            'stock_evidence.lines.*.lot_id' => 'nullable|integer|min:1', 'stock_evidence.lines.*.quantity' => 'nullable|numeric|gt:0|max:99999999.99',
            'stock_evidence.lines.*.quantity_wasted' => 'nullable|numeric|min:0|max:99999999.99',
            'observations' => 'nullable|array:blood_glucose_level,pulse_bpm,blood_pressure_systolic,blood_pressure_diastolic',
            'observations.blood_glucose_level' => 'nullable|numeric|min:0|max:100', 'observations.pulse_bpm' => 'nullable|integer|min:1|max:400',
            'observations.blood_pressure_systolic' => 'nullable|integer|min:1|max:400', 'observations.blood_pressure_diastolic' => 'nullable|integer|min:1|max:300',
        ]);
    }
}
