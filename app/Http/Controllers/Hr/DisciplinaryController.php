<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrCase;
use App\Domain\Hr\Models\HrDisciplinaryAction;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DisciplinaryController extends Controller
{
    /**
     * Disciplinary process stages in order.
     */
    private const STAGES = [
        'allegation_raised',
        'investigation',
        'notice_issued',
        'meeting_scheduled',
        'meeting_held',
        'response_period',
        'outcome_decided',
        'outcome_communicated',
        'appeal',
        'closed',
    ];

    /**
     * Show form to create a new disciplinary action.
     */
    public function create(Request $request, HrCase $case)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.disciplinary.manage'), 403);

        $staff = User::orderBy('name')
            ->get(['id', 'name', 'email']);

        return Inertia::render('hr/cases/create-disciplinary', [
            'hrCase' => $case->load('subject:id,name'),
            'staff' => $staff,
            'actionTypes' => [
                ['value' => 'verbal_warning', 'label' => 'Verbal Warning'],
                ['value' => 'written_warning', 'label' => 'Written Warning'],
                ['value' => 'final_warning', 'label' => 'Final Warning'],
                ['value' => 'suspension', 'label' => 'Suspension'],
                ['value' => 'dismissal', 'label' => 'Dismissal'],
                ['value' => 'other', 'label' => 'Other'],
            ],
        ]);
    }

    /**
     * Store a new disciplinary action linked to an HR case.
     */
    public function store(Request $request, HrCase $case)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.disciplinary.manage'), 403);

        $data = $request->validate([
            'employee_user_id' => ['required', 'integer', 'exists:users,id'],
            'action_type' => ['required', 'string', 'in:verbal_warning,written_warning,final_warning,suspension,dismissal,other'],
            'allegation_summary' => ['required', 'string', 'max:10000'],
            'investigation_notes' => ['nullable', 'string', 'max:10000'],
            'investigator_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'meeting_scheduled_at' => ['nullable', 'date'],
            'meeting_location' => ['nullable', 'string', 'max:255'],
            'support_person_advised' => ['boolean'],
            'response_deadline' => ['nullable', 'date'],
            'good_faith_checklist' => ['nullable', 'array'],
        ]);

        HrDisciplinaryAction::create([
            'tenant_id' => $user->tenant_id,
            'case_id' => $case->id,
            'stage' => 'allegation_raised',
            'created_by' => $user->id,
            ...$data,
        ]);

        return redirect()->back()->with('success', 'Disciplinary action created.');
    }

    /**
     * Update an existing disciplinary action.
     */
    public function update(Request $request, HrDisciplinaryAction $action)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.disciplinary.manage'), 403);

        $data = $request->validate([
            'action_type' => ['sometimes', 'string', 'in:verbal_warning,written_warning,final_warning,suspension,dismissal,other'],
            'allegation_summary' => ['sometimes', 'string', 'max:10000'],
            'investigation_notes' => ['nullable', 'string', 'max:10000'],
            'investigator_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'notice_issued_at' => ['nullable', 'date'],
            'notice_document_path' => ['nullable', 'string', 'max:500'],
            'meeting_scheduled_at' => ['nullable', 'date'],
            'meeting_location' => ['nullable', 'string', 'max:255'],
            'support_person_advised' => ['boolean'],
            'meeting_held_at' => ['nullable', 'date'],
            'meeting_notes' => ['nullable', 'string', 'max:10000'],
            'meeting_attendees' => ['nullable', 'array'],
            'meeting_attendees.*' => ['string', 'max:255'],
            'employee_response' => ['nullable', 'string', 'max:10000'],
            'response_deadline' => ['nullable', 'date'],
            'outcome' => ['nullable', 'string', 'max:5000'],
            'outcome_rationale' => ['nullable', 'string', 'max:5000'],
            'outcome_document_path' => ['nullable', 'string', 'max:500'],
            'good_faith_checklist' => ['nullable', 'array'],
            'appeal_received' => ['nullable', 'boolean'],
            'appeal_notes' => ['nullable', 'string', 'max:10000'],
            'appeal_outcome' => ['nullable', 'string', 'max:5000'],
        ]);

        $data['updated_by'] = $user->id;

        // Auto-set timestamps for outcome fields
        if (isset($data['outcome']) && $data['outcome'] && ! $action->outcome_decided_at) {
            $data['outcome_decided_at'] = now();
            $data['outcome_decided_by'] = $user->id;
        }

        if (isset($data['appeal_received']) && $data['appeal_received'] && ! $action->appeal_received_at) {
            $data['appeal_received_at'] = now();
        }

        $action->update($data);

        return redirect()->back()->with('success', 'Disciplinary action updated.');
    }

    /**
     * Advance a disciplinary action to the next stage.
     *
     * Validates the good faith checklist before allowing outcome stages.
     */
    public function advanceStage(Request $request, HrDisciplinaryAction $action)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.disciplinary.manage'), 403);

        $currentIndex = array_search($action->stage, self::STAGES, true);

        if ($currentIndex === false || $currentIndex >= count(self::STAGES) - 1) {
            return redirect()->back()->withErrors(['stage' => 'Cannot advance beyond the final stage.']);
        }

        $nextStage = self::STAGES[$currentIndex + 1];

        // Validate good faith checklist before outcome-related stages
        $outcomeStages = ['outcome_decided', 'outcome_communicated', 'closed'];
        if (in_array($nextStage, $outcomeStages, true)) {
            $checklist = $action->good_faith_checklist ?? [];

            $requiredChecks = [
                'allegation_communicated',
                'opportunity_to_respond',
                'response_genuinely_considered',
                'support_person_offered',
            ];

            $missingChecks = array_diff($requiredChecks, array_keys(array_filter($checklist)));

            if (! empty($missingChecks)) {
                return redirect()->back()->withErrors([
                    'good_faith' => 'The following good faith requirements must be completed before proceeding: ' . implode(', ', $missingChecks),
                ]);
            }
        }

        $updateData = [
            'stage' => $nextStage,
            'updated_by' => $user->id,
        ];

        // Auto-set milestone timestamps when advancing
        if ($nextStage === 'outcome_communicated' && ! $action->outcome_communicated_at) {
            $updateData['outcome_communicated_at'] = now();
        }

        $action->update($updateData);

        return redirect()->back()->with('success', "Disciplinary action advanced to: {$nextStage}.");
    }
}
