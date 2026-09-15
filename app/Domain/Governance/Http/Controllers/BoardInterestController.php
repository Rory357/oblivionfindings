<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\BoardMemberInterest;
use App\Domain\Governance\Support\GovernanceLabels;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class BoardInterestController extends Controller
{
    private const INTEREST_TYPES = ['financial', 'personal', 'professional', 'family', 'other'];

    public function index(Request $request)
    {
        $this->authorize('viewAny', BoardMemberInterest::class);

        $viewer = $request->user();

        $interests = BoardMemberInterest::with('boardMember.user')
            ->where('is_current', true)
            ->orderBy('board_member_id')
            ->get()
            ->groupBy('board_member_id')
            ->map(fn ($memberInterests) => $memberInterests->map(fn (BoardMemberInterest $interest) => $this->presentInterest($interest, $viewer)));

        $boardMembers = BoardMember::with('user:id,name')
            ->active()
            ->get()
            ->map(fn (BoardMember $member) => [
                'id' => (int) $member->id,
                'user' => $member->user ? ['id' => (int) $member->user->id, 'name' => $member->user->name] : null,
            ])
            ->values();
        $myBoardMember = $viewer?->boardMember;

        return Inertia::render('Governance/Interests/Index', [
            'interestsByMember' => $interests,
            'boardMembers' => $boardMembers,
            // Declarations are self-service only (store() rejects other
            // members' records), so the register offers "Declare interest"
            // for the viewer's own board-member record when they have one.
            'myBoardMemberId' => $myBoardMember?->id,
            'today' => $this->today()->toDateString(),
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
            'You can only declare interests for yourself.'
        );

        $validated = $request->validate([
            'board_member_id' => 'required|exists:board_members,id',
            ...$this->detailRules(),
            'date_to' => 'nullable|date|after:date_from',
            'is_active' => 'boolean',
        ], $this->messages());

        $today = $this->today();
        // Calendar dates compare as NZ days, never as UTC instants.
        $ended = isset($validated['date_to']) && $validated['date_to'] !== null
            && CarbonImmutable::parse($validated['date_to'])->toDateString() <= $today->toDateString();

        BoardMemberInterest::create([
            'board_member_id' => $validated['board_member_id'],
            'interest_type' => $validated['interest_type'],
            'entity_name' => $validated['organization_name'],
            'description' => $validated['description'],
            'nature' => $validated['nature_of_interest'],
            // "From" is when the interest started; the declaration is today.
            'started_on' => $validated['date_from'],
            'declared_at' => $today->toDateString(),
            'ceased_at' => $validated['date_to'] ?? null,
            'is_current' => $ended ? false : ($validated['is_active'] ?? true),
            'notes' => null,
            'recorded_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Interest declared. It is now on the board’s interests register.');
    }

    /** "Update details" — fix or refresh a declaration. */
    public function update(Request $request, BoardMemberInterest $interest)
    {
        $this->authorize('update', $interest);

        $validated = $request->validate($this->detailRules(sometimes: true), $this->messages());

        $interest->update(array_filter([
            'interest_type' => $validated['interest_type'] ?? null,
            'entity_name' => $validated['organization_name'] ?? null,
            'nature' => $validated['nature_of_interest'] ?? null,
            'description' => $validated['description'] ?? null,
            'started_on' => $validated['date_from'] ?? null,
        ], fn ($value) => $value !== null));

        return redirect()->back()->with('success', 'Declaration updated.');
    }

    /** "This interest has ended" — record when it stopped. */
    public function end(Request $request, BoardMemberInterest $interest)
    {
        $this->authorize('update', $interest);

        $today = $this->today();

        $validated = $request->validate([
            'ended_on' => ['required', 'date', 'before_or_equal:'.$today->toDateString()],
        ], [
            'ended_on.required' => 'Enter the date the interest ended.',
            'ended_on.date' => 'Enter the end date as a date.',
            'ended_on.before_or_equal' => "The end date can't be in the future. Come back on the day it ends.",
        ]);

        $started = $interest->started_on ?? $interest->declared_at;
        if ($started !== null && CarbonImmutable::parse($validated['ended_on'])->toDateString() < $started->toDateString()) {
            throw ValidationException::withMessages([
                'ended_on' => 'The end date must be on or after the date the interest started ('.GovernanceLabels::date($started->toDateString()).').',
            ]);
        }

        $interest->update([
            'ceased_at' => $validated['ended_on'],
            'is_current' => false,
        ]);

        return redirect()->back()->with('success', 'Interest marked as ended. It stays in your declarations for the record.');
    }

    public function myInterests(Request $request)
    {
        $this->authorize('viewAny', BoardMemberInterest::class);

        $viewer = $request->user();
        $boardMember = $viewer->boardMember;

        $interests = collect();

        if ($boardMember) {
            $interests = BoardMemberInterest::with('boardMember')
                ->where('board_member_id', $boardMember->id)
                ->orderByDesc('declared_at')
                ->get()
                ->map(fn (BoardMemberInterest $interest) => $this->presentInterest($interest, $viewer));
        }

        return Inertia::render('Governance/Interests/MyInterests', [
            'interests' => $interests,
            'boardMember' => $boardMember ? ['id' => (int) $boardMember->id] : null,
            'canDeclare' => $boardMember !== null,
            'today' => $this->today()->toDateString(),
        ]);
    }

    protected function presentInterest(BoardMemberInterest $interest, ?User $viewer = null): array
    {
        return [
            'id' => $interest->id,
            'board_member_id' => $interest->board_member_id,
            'member_name' => $interest->relationLoaded('boardMember')
                ? $interest->boardMember?->user?->name
                : null,
            'interest_type' => $interest->interest_type,
            'description' => $interest->description,
            'organization_name' => $interest->entity_name,
            'nature_of_interest' => $interest->nature,
            'date_from' => ($interest->started_on ?? $interest->declared_at)?->toDateString(),
            'date_to' => $interest->ceased_at?->toDateString(),
            'is_active' => (bool) $interest->is_current,
            'declared_at' => $interest->declared_at?->toDateString(),
            'updated_at' => $interest->updated_at?->toIso8601String(),
            'can_update' => $viewer !== null && $viewer->can('update', $interest),
        ];
    }

    /** @return array<string, mixed> */
    private function detailRules(bool $sometimes = false): array
    {
        $required = $sometimes ? 'sometimes' : 'required';

        return [
            'interest_type' => [$required, Rule::in(self::INTEREST_TYPES)],
            'organization_name' => "{$required}|string|max:255",
            'nature_of_interest' => "{$required}|string|max:255",
            'description' => "{$required}|string|max:2000",
            'date_from' => "{$required}|date",
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'board_member_id.required' => 'You can only declare interests for yourself.',
            'board_member_id.exists' => 'You can only declare interests for yourself.',
            'interest_type.required' => 'Choose the kind of interest.',
            'interest_type.in' => 'Choose the kind of interest.',
            'organization_name.required' => 'Say which organisation or person the interest is with.',
            'organization_name.max' => 'Keep the organisation or person under 255 characters.',
            'nature_of_interest.required' => 'Say what your role or connection is — for example "Director" or "My sister works there".',
            'nature_of_interest.max' => 'Keep your role or connection under 255 characters.',
            'description.required' => 'Say how this could affect board decisions.',
            'description.max' => 'Keep this under 2,000 characters.',
            'date_from.required' => 'Enter when the interest started.',
            'date_from.date' => 'Enter when the interest started as a date.',
            'date_to.date' => 'Enter the end date as a date.',
            'date_to.after' => 'The end date must be after the start date.',
        ];
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) (config('app.worker_timezone') ?: GovernanceLabels::TIMEZONE))->startOfDay();
    }
}
