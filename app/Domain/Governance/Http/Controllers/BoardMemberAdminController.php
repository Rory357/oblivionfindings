<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\BoardMember;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class BoardMemberAdminController extends Controller
{
    public function index()
    {
        $boardMembers = BoardMember::with('user')
            ->orderBy('board_role')
            ->get();

        $existingUserIds = BoardMember::pluck('user_id');
        $availableUsers = User::staff()
            ->whereNotIn('id', $existingUserIds)
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return Inertia::render('Governance/Admin/BoardMembers', [
            'boardMembers' => $boardMembers,
            'availableUsers' => $availableUsers,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => [
                'required',
                'exists:users,id',
                Rule::unique('board_members', 'user_id')->whereNull('deleted_at'),
            ],
            'board_role' => 'required|in:chair,secretary,treasurer,member,observer',
            'term_start' => 'required|date',
            'term_end' => 'nullable|date|after:term_start',
        ]);

        $existing = BoardMember::withTrashed()
            ->where('user_id', $validated['user_id'])
            ->first();

        if ($existing) {
            $existing->restore();
            $existing->update([
                ...$validated,
                'is_active' => true,
                'is_independent' => true,
            ]);
        } else {
            BoardMember::create([
                ...$validated,
                'is_active' => true,
                'is_independent' => true,
            ]);
        }

        return redirect()->back()->with('success', 'Board member appointed.');
    }

    public function update(Request $request, BoardMember $boardMember)
    {
        $validated = $request->validate([
            'board_role' => 'sometimes|in:chair,secretary,treasurer,member,observer',
            'term_end' => 'sometimes|date|after:term_start',
            'is_active' => 'sometimes|boolean',
        ]);

        $boardMember->update($validated);

        return redirect()->back()->with('success', 'Board member updated.');
    }

    public function destroy(BoardMember $boardMember)
    {
        $boardMember->update(['is_active' => false]);
        $boardMember->delete();

        return redirect()->back()->with('success', 'Board member removed.');
    }
}
