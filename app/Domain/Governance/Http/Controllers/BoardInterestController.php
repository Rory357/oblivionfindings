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
        $interests = BoardMemberInterest::with('boardMember.user')
            ->where('is_active', true)
            ->orderBy('board_member_id')
            ->get()
            ->groupBy('board_member_id');

        $boardMembers = BoardMember::with('user')->active()->get();

        return Inertia::render('Governance/Interests/Index', [
            'interestsByMember' => $interests,
            'boardMembers' => $boardMembers,
        ]);
    }

    public function store(Request $request)
    {
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
            ...$validated,
            'declared_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Interest declared.');
    }

    public function update(Request $request, BoardMemberInterest $interest)
    {
        $validated = $request->validate([
            'description' => 'sometimes|string',
            'is_active' => 'boolean',
            'date_to' => 'nullable|date',
        ]);

        $interest->update($validated);

        return redirect()->back()->with('success', 'Interest updated.');
    }

    public function myInterests()
    {
        $boardMember = auth()->user()->boardMember;

        if (!$boardMember) {
            return redirect()->route('governance.dashboard')
                ->with('error', 'You are not a board member.');
        }

        $interests = BoardMemberInterest::where('board_member_id', $boardMember->id)
            ->orderByDesc('declared_at')
            ->get();

        return Inertia::render('Governance/Interests/MyInterests', [
            'interests' => $interests,
            'boardMember' => $boardMember,
        ]);
    }
}
