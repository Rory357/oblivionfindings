<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Support\GovernanceLabels;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class BoardMemberAdminController extends Controller
{
    /** Days ahead a term end counts as "ending soon". */
    private const ENDING_SOON_DAYS = 90;

    public function index(Request $request)
    {
        $this->authorize('viewAny', BoardMember::class);

        $today = $this->today();

        $boardMembers = BoardMember::with('user:id,name,email')
            ->orderBy('board_role')
            ->get()
            ->map(fn (BoardMember $member) => $this->presentMember($member, $today))
            ->values();

        // Board members are often volunteers, family or community members
        // rather than staff: anyone with an approved login who isn't already
        // on the board can be appointed (the appointment itself still needs
        // the board-management permission on this route).
        $existingUserIds = BoardMember::pluck('user_id');
        $availableUsers = User::query()
            ->whereNotNull('approved_at')
            ->whereNotIn('id', $existingUserIds)
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return Inertia::render('Governance/Admin/BoardMembers', [
            'boardMembers' => $boardMembers,
            'availableUsers' => $availableUsers,
            'today' => $today->toDateString(),
            'endingSoonDays' => self::ENDING_SOON_DAYS,
            'canInvitePeople' => (bool) $request->user()?->canDo('settings.access.manage'),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', BoardMember::class);

        $validated = $request->validate([
            'user_id' => [
                'required',
                Rule::exists('users', 'id')->whereNotNull('approved_at'),
                Rule::unique('board_members', 'user_id')->whereNull('deleted_at'),
            ],
            'board_role' => 'required|in:chair,secretary,treasurer,member,observer',
            'term_start' => 'required|date',
            'term_end' => 'nullable|date|after:term_start',
        ], $this->messages());

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

        $name = User::query()->whereKey($validated['user_id'])->value('name');

        return redirect()->back()->with('success', $name
            ? "{$name} was appointed to the board."
            : 'Board member appointed.');
    }

    public function update(Request $request, BoardMember $boardMember)
    {
        $this->authorize('update', $boardMember);

        $validated = $request->validate([
            'board_role' => 'sometimes|in:chair,secretary,treasurer,member,observer',
            // Nullable: clearing the end date makes the term ongoing.
            'term_end' => 'sometimes|nullable|date',
            'is_active' => 'sometimes|boolean',
        ], $this->messages());

        if (array_key_exists('term_end', $validated) && $validated['term_end'] !== null) {
            $end = CarbonImmutable::parse($validated['term_end'])->startOfDay();
            $start = $boardMember->term_start ? CarbonImmutable::parse($boardMember->term_start->toDateString()) : null;

            if ($start !== null && $end->lte($start)) {
                throw ValidationException::withMessages([
                    'term_end' => 'The term must end after it starts ('.GovernanceLabels::date($start->toDateString()).').',
                ]);
            }
        }

        $boardMember->update($validated);

        return redirect()->back()->with('success', 'Appointment saved.');
    }

    public function destroy(BoardMember $boardMember)
    {
        $this->authorize('delete', $boardMember);

        $name = $boardMember->user?->name;

        $boardMember->update(['is_active' => false]);
        $boardMember->delete();

        return redirect()->back()->with('success', $name
            ? "{$name} was removed from the board."
            : 'Board member removed.');
    }

    /** @return array<string, mixed> */
    private function presentMember(BoardMember $member, CarbonImmutable $today): array
    {
        $start = $member->term_start?->toDateString();
        $end = $member->term_end?->toDateString();
        $todayString = $today->toDateString();

        $standing = match (true) {
            ! $member->is_active => 'inactive',
            $start !== null && $start > $todayString => 'term_not_started',
            $end !== null && $end < $todayString => 'term_ended',
            default => 'active',
        };

        return [
            'id' => (int) $member->id,
            'user' => $member->user ? [
                'id' => (int) $member->user->id,
                'name' => $member->user->name,
                'email' => $member->user->email,
            ] : null,
            'board_role' => $member->board_role,
            'term_start' => $start,
            'term_end' => $end,
            'is_active' => (bool) $member->is_active,
            'standing' => $standing,
            'can_vote' => $member->canVote(),
            'ending_soon' => $standing === 'active'
                && $end !== null
                && $end <= $today->addDays(self::ENDING_SOON_DAYS)->toDateString(),
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'user_id.required' => 'Choose the person to appoint.',
            'user_id.exists' => "That person can't be appointed. They need an approved login first.",
            'user_id.unique' => 'That person is already on the board.',
            'board_role.required' => 'Choose a board role.',
            'board_role.in' => 'Choose a board role.',
            'term_start.required' => 'Enter the date the term starts.',
            'term_start.date' => 'Enter the start date as a date.',
            'term_end.date' => 'Enter the end date as a date.',
            'term_end.after' => 'The term must end after it starts.',
            'is_active.boolean' => 'Choose whether the member is active.',
        ];
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) (config('app.worker_timezone') ?: GovernanceLabels::TIMEZONE))->startOfDay();
    }
}
