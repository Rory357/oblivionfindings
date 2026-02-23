<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\BoardEvaluation;
use App\Domain\Governance\Models\BoardEvaluationResponse;
use App\Domain\Governance\Models\BoardMember;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BoardEvaluationController extends Controller
{
    public function index()
    {
        $evaluations = BoardEvaluation::withCount('responses')
            ->orderByDesc('created_at')
            ->paginate(15);

        return Inertia::render('Governance/Evaluations/Index', [
            'evaluations' => $evaluations,
        ]);
    }

    public function create()
    {
        return Inertia::render('Governance/Evaluations/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'evaluation_type' => 'required|in:board,committee,chair,individual',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after:period_start',
            'questions' => 'required|array|min:1',
            'questions.*.text' => 'required|string',
            'questions.*.type' => 'required|in:rating,text,yes_no',
            'due_date' => 'required|date|after:today',
        ]);

        $evaluation = BoardEvaluation::create([
            ...$validated,
            'status' => 'draft',
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('governance.evaluations.show', $evaluation)
            ->with('success', 'Board evaluation created.');
    }

    public function show(BoardEvaluation $evaluation)
    {
        $evaluation->load('responses.boardMember.user');

        $boardMembers = BoardMember::with('user')->active()->get();
        $myResponse = null;

        if (auth()->user()->boardMember) {
            $myResponse = $evaluation->responses()
                ->where('board_member_id', auth()->user()->boardMember->id)
                ->first();
        }

        return Inertia::render('Governance/Evaluations/Show', [
            'evaluation' => $evaluation,
            'boardMembers' => $boardMembers,
            'myResponse' => $myResponse,
            'responseRate' => [
                'total' => $boardMembers->count(),
                'completed' => $evaluation->responses()->where('is_complete', true)->count(),
            ],
        ]);
    }

    public function launch(BoardEvaluation $evaluation)
    {
        $evaluation->update([
            'status' => 'active',
            'launched_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Evaluation launched. Board members can now respond.');
    }

    public function respond(Request $request, BoardEvaluation $evaluation)
    {
        $boardMember = auth()->user()->boardMember;
        if (!$boardMember) {
            return redirect()->back()->with('error', 'You are not a board member.');
        }

        $validated = $request->validate([
            'answers' => 'required|array',
            'overall_comments' => 'nullable|string',
        ]);

        BoardEvaluationResponse::updateOrCreate(
            [
                'board_evaluation_id' => $evaluation->id,
                'board_member_id' => $boardMember->id,
            ],
            [
                'answers' => $validated['answers'],
                'overall_comments' => $validated['overall_comments'] ?? null,
                'is_complete' => true,
                'submitted_at' => now(),
            ]
        );

        return redirect()->back()->with('success', 'Response submitted.');
    }

    public function close(BoardEvaluation $evaluation)
    {
        $evaluation->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Evaluation closed.');
    }

    public function results(BoardEvaluation $evaluation)
    {
        $evaluation->load('responses.boardMember.user');

        return Inertia::render('Governance/Evaluations/Results', [
            'evaluation' => $evaluation,
        ]);
    }
}
