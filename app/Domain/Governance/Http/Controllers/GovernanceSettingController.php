<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\GovernanceResolutionBinding;
use App\Domain\Governance\Models\GovernanceSetting;
use App\Domain\Governance\Models\GovernanceVotingProfile;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Services\GovernanceAuditService;
use App\Domain\Governance\Services\GovernanceVotingProfileService;
use App\Domain\Governance\Support\GovernanceLabels;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GovernanceSettingController extends Controller
{
    private const LEGAL_FORMS = ['charitable_trust', 'incorporated_society', 'company'];

    private const QUORUM_MODES = ['majority_floor_plus_one', 'percentage', 'fixed_count'];

    /** Voting rule fields a person can change in "How the board votes". */
    private const RULE_FIELDS = [
        'legal_form',
        'governing_document_reference',
        'governing_document_version',
        'quorum_mode',
        'quorum_formula',
        'written_voting_permitted',
        'written_unanimity_required',
    ];

    /**
     * Setting definitions — every editable governance value the app actually
     * reads. Labels and descriptions are what board members see
     * (vocabulary.md); keys are never shown.
     *
     * @var array<int, array{key: string, label: string, category: string, type: 'number', format: 'money'|'count'|'person', default: int|float|null, description: string}>
     */
    private array $definitions;

    public function __construct()
    {
        $this->definitions = [
            // ── Spending that needs board approval (SpendApproval::thresholdFor) ──
            [
                'key' => 'spend_approval.threshold.capex',
                'label' => 'Equipment, vehicles and buildings',
                'category' => GovernanceSetting::CATEGORY_SPEND_APPROVAL,
                'type' => 'number',
                'format' => 'money',
                'default' => 5000,
                'description' => 'Buying equipment, vehicles or buildings costing this much or more needs board approval.',
            ],
            [
                'key' => 'spend_approval.threshold.opex',
                'label' => 'Single bills outside the budget',
                'category' => GovernanceSetting::CATEGORY_SPEND_APPROVAL,
                'type' => 'number',
                'format' => 'money',
                'default' => 10000,
                'description' => 'A single bill or supplier cost outside the budget of this much or more needs board approval.',
            ],
            [
                'key' => 'spend_approval.threshold.supplier_contract',
                'label' => 'Supplier contracts',
                'category' => GovernanceSetting::CATEGORY_SPEND_APPROVAL,
                'type' => 'number',
                'format' => 'money',
                'default' => 10000,
                'description' => 'A supplier contract worth this much or more a year needs board approval.',
            ],
            [
                'key' => 'spend_approval.threshold.donor_restricted',
                'label' => 'Spending donated money set aside for a purpose',
                'category' => GovernanceSetting::CATEGORY_SPEND_APPROVAL,
                'type' => 'number',
                'format' => 'money',
                'default' => 25000,
                'description' => 'Spending this much or more of money a donor gave for a set purpose needs board approval.',
            ],

            // ── Overdue compliance reminders (ComplianceEngineService) ──
            [
                'key' => 'compliance.escalation.max_level',
                'label' => 'How far overdue reminders go',
                'category' => GovernanceSetting::CATEGORY_ESCALATION,
                'type' => 'number',
                'format' => 'count',
                'default' => 3,
                'description' => 'When a compliance requirement is overdue, reminders go to the owner, then their backup, and so on — this many steps in total.',
            ],
            [
                'key' => 'compliance.escalation.final_notify_user_id',
                'label' => 'Who is told at the last step',
                'category' => GovernanceSetting::CATEGORY_ESCALATION,
                'type' => 'number',
                'format' => 'person',
                'default' => null,
                'description' => 'Usually the CEO or the board chair. If nobody is chosen, the board chair is told.',
            ],
        ];
    }

    public function index(): Response
    {
        /** @var User $user */
        $user = auth()->user();
        $canManage = $user->canDo('governance.settings.manage');

        $people = $canManage
            ? User::query()->whereNotNull('approved_at')->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $person) => ['id' => $person->id, 'name' => $person->name])->all()
            : [];

        $settings = collect($this->definitions)->map(function (array $def) {
            $value = GovernanceSetting::get($def['key'], $def['default']);

            return [
                ...$def,
                'value' => $value,
                'default_label' => $this->formatValue($def, $def['default']),
                // Read-only viewers see who is chosen without the user directory.
                'value_label' => $this->formatValue($def, $value),
            ];
        })->values();

        return Inertia::render('Governance/Settings/Index', [
            'settings' => $settings,
            'categories' => [
                GovernanceSetting::CATEGORY_SPEND_APPROVAL => 'Spending that needs board approval',
                GovernanceSetting::CATEGORY_ESCALATION => 'Overdue compliance reminders',
            ],
            'people' => $people,
            'rulesProfile' => $this->votingRulesPayload($user, $canManage),
            'canManage' => $canManage,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $payload = $request->input('settings', []);
        if (! is_array($payload)) {
            $payload = [];
        }

        $allowedKeys = collect($this->definitions)->pluck('key')->toArray();
        $validatedEntries = [];
        $errors = [];

        foreach ($payload as $key => $value) {
            if (! in_array($key, $allowedKeys, true)) {
                continue;
            }

            $def = collect($this->definitions)->firstWhere('key', $key);
            $category = $def['category'] ?? GovernanceSetting::CATEGORY_GENERAL;
            $description = $def['description'] ?? null;
            $format = $def['format'] ?? 'count';

            if ($format === 'person') {
                if ($value !== null && $value !== '') {
                    if (! is_numeric($value) || (string) (int) $value !== (string) $value
                        || ! User::where('id', (int) $value)->exists()) {
                        $errors["settings.{$key}"] = 'Choose a person from the list.';

                        continue;
                    }
                    $value = (int) $value;
                } else {
                    $value = null;
                }
            } elseif ($value !== null && $value !== '') {
                if (! is_numeric($value)) {
                    $errors["settings.{$key}"] = $format === 'money' ? 'Enter an amount in dollars, like 5000.' : 'Enter a number.';

                    continue;
                }
                $num = is_float($value + 0) ? (float) $value : (int) $value;
                if ($num < 0) {
                    $errors["settings.{$key}"] = $format === 'money' ? 'Enter an amount of $0 or more.' : 'Enter 0 or more.';

                    continue;
                }
                if ($format === 'count' && (float) $num !== floor((float) $num)) {
                    $errors["settings.{$key}"] = 'Enter a whole number.';

                    continue;
                }
                $value = $num;
            } else {
                $value = null;
            }

            $validatedEntries[] = [
                'key' => $key,
                'label' => $def['label'],
                'value' => $value,
                'default' => $def['default'],
                'category' => $category,
                'description' => $description,
            ];
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        $changedKeys = [];
        DB::transaction(function () use ($validatedEntries, &$changedKeys) {
            foreach ($validatedEntries as $entry) {
                $current = GovernanceSetting::get($entry['key'], $entry['default']);
                if ($this->sameValue($current, $entry['value'])) {
                    continue;
                }

                GovernanceSetting::set($entry['key'], $entry['value'], $entry['category'], $entry['description']);
                $changedKeys[] = $entry['key'];
            }

            if ($changedKeys !== []) {
                GovernanceAuditService::log('settings.updated', 'GovernanceSetting', 0, [
                    'changed_keys' => $changedKeys,
                    'change_count' => count($changedKeys),
                ]);
            }
        });

        $count = count($changedKeys);

        return back()->with('success', match (true) {
            $count === 0 => 'No changes to save.',
            $count === 1 => 'Saved 1 change.',
            default => "Saved {$count} changes.",
        });
    }

    public function updateRules(Request $request): RedirectResponse
    {
        $mode = (string) $request->input('quorum_mode');
        $formulaRules = match ($mode) {
            'percentage' => ['required', 'numeric', 'min:1', 'max:100'],
            'fixed_count' => ['required', 'integer', 'min:1', 'max:100'],
            default => ['nullable'],
        };

        $validated = $request->validate([
            'legal_form' => 'required|string|in:'.implode(',', self::LEGAL_FORMS),
            'governing_document_reference' => 'nullable|string|max:255',
            'governing_document_version' => 'nullable|string|max:50',
            'quorum_mode' => 'required|string|in:'.implode(',', self::QUORUM_MODES),
            'quorum_formula' => $formulaRules,
            'written_voting_permitted' => 'boolean',
            'written_unanimity_required' => 'boolean',
        ], [
            'legal_form.required' => 'Choose what kind of organisation this is.',
            'legal_form.in' => 'Choose what kind of organisation this is.',
            'governing_document_reference.max' => 'Keep the governing document name under 255 characters.',
            'governing_document_version.max' => 'Keep the version under 50 characters.',
            'quorum_mode.required' => 'Choose how many voting members must take part.',
            'quorum_mode.in' => 'Choose how many voting members must take part.',
            'quorum_formula.required' => $mode === 'percentage'
                ? 'Enter the percentage of voting members who must take part.'
                : 'Enter how many voting members must take part.',
            'quorum_formula.numeric' => 'Enter a number.',
            'quorum_formula.integer' => 'Enter a whole number.',
            'quorum_formula.min' => $mode === 'percentage' ? 'Enter a percentage from 1 to 100.' : 'Enter at least 1.',
            'quorum_formula.max' => $mode === 'percentage' ? 'Enter a percentage from 1 to 100.' : 'Enter 100 or fewer.',
        ]);

        $validated['quorum_formula'] = match ($validated['quorum_mode']) {
            'percentage' => rtrim(rtrim(number_format((float) $validated['quorum_formula'], 2, '.', ''), '0'), '.'),
            'fixed_count' => (string) (int) $validated['quorum_formula'],
            default => 'floor(N/2)+1',
        };
        foreach (['governing_document_reference', 'governing_document_version'] as $field) {
            $validated[$field] = isset($validated[$field]) && trim((string) $validated[$field]) !== ''
                ? trim((string) $validated[$field])
                : null;
        }

        $profileService = app(GovernanceVotingProfileService::class);
        $profile = $profileService->getOrCreateCandidateDefault('board');

        $changed = collect(self::RULE_FIELDS)
            ->filter(fn (string $field) => array_key_exists($field, $validated)
                && ! $this->sameValue($profile->{$field}, $validated[$field]))
            ->values()
            ->all();

        if ($changed === []) {
            return back()->with('success', 'No changes to save.');
        }

        $liveOrApproved = $profile->is_active || $profile->approved_at || $profile->approved_by_resolution_id;

        if ($liveOrApproved) {
            // Rules in use are never edited in place: the changes become
            // proposed rules the board has to approve.
            $profile = GovernanceVotingProfile::create([
                ...$profile->toArray(),
                ...$validated,
                'id' => null,
                'is_active' => false,
                'approved_at' => null,
                'approved_by_user_id' => null,
                'approved_by_resolution_id' => null,
                'approval_source' => null,
                'approval_minutes_reference' => null,
                'approval_meeting_id' => null,
                'effective_from' => null,
                'effective_to' => null,
                'created_by' => auth()->id(),
            ]);
        } else {
            $profile->update($validated);
        }

        GovernanceAuditService::log('governance_rules.updated', 'GovernanceVotingProfile', $profile->id, [
            'updated_by' => auth()->id(),
            'fields' => $changed,
        ]);

        return back()->with('success', $profileService->hasEverBeenActive('board')
            ? "Your changes are saved as proposed voting rules. They'll be used once the board passes a resolution that approves them."
            : 'Voting rules saved.');
    }

    /**
     * First switch-on (owner decision 14 September 2026): the chair or
     * secretary records the board's EXISTING approval of its voting rules.
     * Only possible while no voting rules have ever been switched on; the
     * service refuses otherwise.
     */
    public function recordRulesApproval(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'approved_on' => 'required|date_format:Y-m-d',
            'approval_minutes_reference' => 'required|string|max:255',
            'approval_meeting_id' => 'nullable|integer|exists:governance_meetings,id',
        ], [
            'approved_on.required' => 'Enter the date the board approved these voting rules.',
            'approved_on.date_format' => 'Enter the date the board approved these voting rules.',
            'approval_minutes_reference.required' => 'Enter where the approval is recorded, such as the minutes of the meeting.',
            'approval_minutes_reference.max' => 'Keep the minutes reference under 255 characters.',
            'approval_meeting_id.exists' => "The meeting you chose doesn't exist any more.",
        ]);

        $zone = config('app.worker_timezone', 'Pacific/Auckland');
        $approvedOn = Carbon::createFromFormat('!Y-m-d', $validated['approved_on'], $zone);

        $profileService = app(GovernanceVotingProfileService::class);
        $profile = $profileService->getOrCreateCandidateDefault('board');

        if ($profile->realDocumentReference() === null) {
            return back()->with('error', "Add your governing document's name (such as your trust deed or constitution) under \"How the board votes\" and save it, then record the board's approval.");
        }

        try {
            $profileService->recordBoardApproval($profile, $request->user(), [
                'governing_document_reference' => (string) $profile->governing_document_reference,
                'governing_document_version' => $profile->governing_document_version,
                'approved_on' => $approvedOn,
                'approval_minutes_reference' => $validated['approval_minutes_reference'],
                'approval_meeting_id' => $validated['approval_meeting_id'] ?? null,
            ]);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Board voting is switched on. The board's approval of these voting rules is recorded.");
    }

    /**
     * Switch on voting rules approved by a passed resolution linked to them
     * (the path for every change after the first switch-on).
     */
    public function activateRules(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'governing_document_reference' => 'nullable|string|max:255',
            'governing_document_version' => 'nullable|string|max:50',
            'approved_by_resolution_id' => 'nullable|integer|exists:resolutions,id',
        ], [
            'approved_by_resolution_id.exists' => "The resolution you chose doesn't exist any more.",
        ]);

        $profileService = app(GovernanceVotingProfileService::class);
        $profile = $profileService->getOrCreateCandidateDefault('board');

        // The governing document is the one saved with these rules — the same
        // one a linked resolution approved.
        $reference = $validated['governing_document_reference'] ?? $profile->governing_document_reference;
        $version = array_key_exists('governing_document_version', $validated) && $validated['governing_document_version'] !== null
            ? $validated['governing_document_version']
            : $profile->governing_document_version;

        try {
            $resolution = ! empty($validated['approved_by_resolution_id'])
                ? Resolution::find($validated['approved_by_resolution_id'])
                : null;

            $profileService->activateProfile(
                $profile,
                $request->user(),
                $resolution,
                $reference,
                $version,
            );

            GovernanceAuditService::log('governance_rules.activated', 'GovernanceVotingProfile', $profile->id, [
                'method' => $resolution ? GovernanceVotingProfileService::APPROVAL_SOURCE_RESOLUTION : 'reactivated',
                'activated_by' => auth()->id(),
                'resolution_id' => $resolution?->id,
                'document_reference' => $reference,
            ]);

            return back()->with('success', 'The new voting rules are switched on. They apply to resolutions opened for voting from now on.');
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Everything the "How the board votes" card shows.
     *
     * @return array<string, mixed>
     */
    private function votingRulesPayload(User $user, bool $canManage): array
    {
        $profileService = app(GovernanceVotingProfileService::class);
        $active = $profileService->getActiveProfile('board');

        // Managers get a real draft to edit and switch on; viewers never
        // cause a write just by opening the page.
        $latest = $canManage
            ? $profileService->getOrCreateCandidateDefault('board')
            : GovernanceVotingProfile::query()->where('governing_body', 'board')->whereNull('board_committee_id')->latest('id')->first();

        $draft = $latest
            && ! $latest->is_active
            && empty($latest->approved_at)
            && empty($latest->approved_by_resolution_id)
            && (! $active || $latest->id !== $active->id)
                ? $latest
                : null;

        $formProfile = $draft ?? $active ?? $latest ?? new GovernanceVotingProfile(GovernanceVotingProfile::candidateDefaults('board'));
        $inForce = $active && $active->isConfirmed() ? $active : null;
        $everActive = $profileService->hasEverBeenActive('board');

        $members = BoardMember::with('user:id,name')->get()
            ->map(fn (BoardMember $member) => [
                'id' => $member->id,
                'name' => $member->user?->name ?? 'Former user',
                'role' => $member->board_role,
                'role_label' => GovernanceLabels::label('board_role', $member->board_role),
                'can_vote' => $member->canVote(),
                'why_not' => $member->canVote() ? null : $this->whyCannotVote($member),
                'term_start' => $member->term_start?->toDateString(),
                'term_end' => $member->term_end?->toDateString(),
            ])
            ->sortBy([['can_vote', 'desc'], ['name', 'asc']])
            ->values();

        $eligibleCount = $members->where('can_vote', true)->count();
        $rulesForSummary = $inForce ?? $formProfile;

        $activationTarget = $canManage ? $latest : null;
        $activationMode = match (true) {
            ! $activationTarget => null,
            ! $everActive => 'record_first_approval',
            $draft !== null => 'resolution',
            default => 'none',
        };

        return [
            'status' => $inForce ? 'on' : 'off',
            'isConfirmed' => $inForce !== null,
            'hasEverBeenActive' => $everActive,
            'hasPendingChanges' => $inForce !== null && $draft !== null,
            'rules' => $this->rulesFields($formProfile),
            'inForce' => $inForce ? [
                ...$this->rulesFields($inForce),
                'approved_on' => $inForce->approved_at ? GovernanceLabels::date($inForce->approved_at) : null,
                'approval_source' => $inForce->approval_source
                    ?? ($inForce->approved_by_resolution_id ? GovernanceVotingProfileService::APPROVAL_SOURCE_RESOLUTION : null),
                'approval_minutes_reference' => $inForce->approval_minutes_reference,
                'approval_meeting_title' => $inForce->approval_meeting_id
                    ? GovernanceMeeting::query()->whereKey($inForce->approval_meeting_id)->value('title')
                    : null,
                'approved_by_name' => $inForce->approvedByUser()->value('name'),
                'approved_by_resolution' => $inForce->approved_by_resolution_id
                    ? Resolution::query()->whereKey($inForce->approved_by_resolution_id)->first(['id', 'title', 'resolution_reference'])?->only(['id', 'title', 'resolution_reference'])
                    : null,
            ] : null,
            'eligibleVoterCount' => $eligibleCount,
            'memberCount' => $members->count(),
            'quorumRequired' => $profileService->calculateQuorumRequired($eligibleCount, $rulesForSummary),
            'members' => $members->all(),
            'activation' => $activationTarget ? [
                'profile_id' => $activationTarget->id,
                'is_active' => (bool) $activationTarget->is_active,
                'mode' => $activationMode,
                'governing_document_reference' => $activationTarget->realDocumentReference(),
                'governing_document_version' => $activationTarget->governing_document_version,
            ] : null,
            // Only passed resolutions linked to the exact rules being switched
            // on (and not yet used) can approve them.
            'approvalResolutions' => $activationTarget
                ? Resolution::query()
                    ->where('outcome', 'carried')
                    ->whereIn('status', ['closed', 'implemented', 'archived'])
                    ->whereHas('authorityBindings', fn ($q) => $q
                        ->where('subject_type', GovernanceResolutionBinding::SUBJECT_VOTING_PROFILE)
                        ->where('subject_id', $activationTarget->id)
                        ->whereNull('consumed_at'))
                    ->orderByDesc('closed_at')
                    ->get(['id', 'resolution_reference', 'title', 'closed_at'])
                : [],
            // Meetings a first approval can be linked to (managers only).
            'approvalMeetings' => $canManage && $activationMode === 'record_first_approval'
                ? GovernanceMeeting::query()
                    ->where('scheduled_at', '<=', now())
                    ->orderByDesc('scheduled_at')
                    ->limit(50)
                    ->get(['id', 'title', 'scheduled_at'])
                    ->map(fn (GovernanceMeeting $meeting) => [
                        'id' => $meeting->id,
                        'title' => $meeting->title,
                        'scheduled_at' => $meeting->scheduled_at?->toIso8601String(),
                    ])
                    ->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesFields(GovernanceVotingProfile $profile): array
    {
        return [
            'legal_form' => $profile->legal_form ?: 'charitable_trust',
            'governing_document_reference' => $profile->realDocumentReference(),
            'governing_document_version' => $profile->governing_document_version,
            'quorum_mode' => in_array($profile->quorum_mode, self::QUORUM_MODES, true) ? $profile->quorum_mode : 'majority_floor_plus_one',
            'quorum_formula' => $profile->quorum_mode === 'majority_floor_plus_one' ? '' : (string) $profile->quorum_formula,
            'written_voting_permitted' => (bool) $profile->written_voting_permitted,
            'written_unanimity_required' => (bool) $profile->written_unanimity_required,
        ];
    }

    /** Why a board member isn't on the voting list ("Why not?" column). */
    private function whyCannotVote(BoardMember $member): string
    {
        $today = today();

        return match (true) {
            ! $member->is_active => 'Not an active board member',
            $member->isObserver() => "Observer (observers can't vote)",
            $member->term_start !== null && $member->term_start->isAfter($today) => 'Term starts '.GovernanceLabels::date($member->term_start->toDateString()),
            $member->term_end !== null && $member->term_end->isBefore($today) => 'Term ended '.GovernanceLabels::date($member->term_end->toDateString()),
            $member->board_role === 'secretary' => "Secretary without a voting seat",
            default => 'No voting seat',
        };
    }

    /**
     * @param  array{format?: string}  $def
     */
    private function formatValue(array $def, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return ($def['format'] ?? null) === 'person' ? 'Nobody chosen — the board chair is told' : null;
        }

        return match ($def['format'] ?? null) {
            'money' => GovernanceLabels::money($value),
            'person' => User::query()->whereKey((int) $value)->value('name') ?? 'A person who no longer has an account',
            default => (string) $value,
        };
    }

    private function sameValue(mixed $current, mixed $new): bool
    {
        $normalise = static function (mixed $value): ?string {
            if ($value === null || $value === '') {
                return null;
            }
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }
            if (is_numeric($value)) {
                return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
            }

            return trim((string) $value);
        };

        return $normalise($current) === $normalise($new);
    }
}
