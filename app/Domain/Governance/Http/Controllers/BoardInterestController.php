<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\BoardMemberInterest;
use App\Domain\Governance\Models\BoardMember;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BoardInterestController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', BoardMemberInterest::class);

        $interests = BoardMemberInterest::with('boardMember.user')
            ->where('is_current', true)
            ->orderBy('board_member_id')
            ->get()
            ->groupBy('board_member_id')
            ->map(fn ($memberInterests) => $memberInterests->map(fn (BoardMemberInterest $interest) => $this->presentInterest($interest)));

        $boardMembers = BoardMember::with('user')->active()->get();

        return Inertia::render('Governance/Interests/Index', [
            'interestsByMember' => $interests,
            'boardMembers' => $boardMembers,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', BoardMemberInterest::class);

        // Ensure the user can only declare interests for their own board member record
        $userBoardMember = $request->user()->boardMember;
        abort_unless(
            $userBoardMember && (int) $request->input('board_member_id') === $userBoardMember->id,
            403,
            'You may only declare interests for your own board member record.'
        );

        $validated = $request->validate([
            'board_member_id' => 'required|exists:board_members,id',
            'interest_type' => 'required|in:financial,personal,professional,family,other',
            'description' => 'required|string',
            'organization_name' => 'nullable|string|max:255',
            'nature_of_interest' => 'required|string|max:255',
            'date_from' => 'required|date',
            'date_to' => 'nullable|date|after:date_from',
            'is_active' => 'boolean',
        ]);

        BoardMemberInterest::create([
            'board_member_id' => $validated['board_member_id'],
            'interest_type' => $validated['interest_type'],
            'entity_name' => $validated['organization_name'] ?? null,
            'description' => $validated['description'],
            'nature' => $validated['nature_of_interest'],
            'declared_at' => $validated['date_from'],
            'ceased_at' => $validated['date_to'] ?? null,
            'is_current' => $validated['is_active'] ?? true,
            'notes' => null,
            'recorded_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Interest declared.');
    }

    public function update(Request $request, BoardMemberInterest $interest)
    {
        $this->authorize('update', $interest);

        $validated = $request->validate([
            'description' => 'sometimes|string',
            'is_active' => 'sometimes|boolean',
            'date_to' => 'nullable|date',
        ]);

        $interest->update([
            'description' => $validated['description'] ?? $interest->description,
            'is_current' => $validated['is_active'] ?? $interest->is_current,
            'ceased_at' => array_key_exists('date_to', $validated) ? $validated['date_to'] : $interest->ceased_at,
        ]);

        return redirect()->back()->with('success', 'Interest updated.');
    }

    public function myInterests()
    {
        $this->authorize('viewAny', BoardMemberInterest::class);

        $boardMember = auth()->user()->boardMember;

        $interests = collect();

        if ($boardMember) {
            $interests = BoardMemberInterest::where('board_member_id', $boardMember->id)
                ->orderByDesc('declared_at')
                ->get()
                ->map(fn (BoardMemberInterest $interest) => $this->presentInterest($interest));
        }

        return Inertia::render('Governance/Interests/MyInterests', [
            'interests' => $interests,
            'boardMember' => $boardMember,
            'canDeclare' => $boardMember !== null,
        ]);
    }

    protected function presentInterest(BoardMemberInterest $interest): array
    {
        return [
            'id' => $interest->id,
            'interest_type' => $interest->interest_type,
            'description' => $interest->description,
            'organization_name' => $interest->entity_name,
            'nature_of_interest' => $interest->nature,
            'date_from' => $interest->declared_at?->toDateString(),
            'date_to' => $interest->ceased_at?->toDateString(),
            'is_active' => (bool) $interest->is_current,
            'declared_at' => $interest->declared_at?->toDateString(),
        ];
    }
}
