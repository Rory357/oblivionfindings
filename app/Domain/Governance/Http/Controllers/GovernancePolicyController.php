<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\GovernancePolicy;
use App\Domain\Governance\Models\PolicyAttestation;
use App\Domain\Governance\Services\GovernanceAuditService;
use App\Domain\Governance\Support\GovernanceLabels;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class GovernancePolicyController extends Controller
{
    private const POLICY_CATEGORIES = ['governance', 'financial', 'hr', 'health_safety', 'privacy', 'clinical', 'operational', 'other'];

    /** Statuses a manager can pick in Edit. Approval only happens through Approve. */
    private const EDITABLE_STATUSES = ['draft', 'under_review', 'archived'];

    public function index(Request $request)
    {
        $this->authorize('viewAny', GovernancePolicy::class);

        $search = trim((string) $request->query('search', ''));
        $today = GovernancePolicy::nzToday();
        $boardUserIds = $this->activeBoardUserIds();

        $policies = GovernancePolicy::query()
            ->with('attestations')
            ->when($request->category, fn ($q, $cat) => $q->where('category', $cat))
            // Filters use the presented statuses: "active" is stored as
            // approved and "archived" also covers replaced versions.
            ->when($request->status, fn ($q, $s) => match ($s) {
                'archived' => $q->whereIn('status', ['archived', 'superseded']),
                default => $q->where('status', $this->normalizeStatus((string) $s)),
            })
            ->when($request->query('review') === 'overdue', fn ($q) => $q
                ->where('status', 'approved')
                ->whereDate('next_review_date', '<', $today))
            ->when($request->query('confirm') === 'waiting', fn ($q) => $q
                ->whereIn('id', $this->policiesWaitingOnMembers($boardUserIds, $today)->pluck('id')))
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner
                ->where('title', 'like', "%{$search}%")
                ->orWhere('policy_code', 'like', "%{$search}%")))
            ->orderBy('title')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (GovernancePolicy $policy) => $this->presentPolicyListItem($policy, $boardUserIds, $today));

        return Inertia::render('Governance/Policies/Index', [
            'policies' => $policies,
            'filters' => [
                'search' => $search !== '' ? $search : null,
                'category' => $request->query('category'),
                'status' => $request->query('status'),
                'review' => $request->query('review'),
                'confirm' => $request->query('confirm'),
            ],
            'summary' => [
                'total' => GovernancePolicy::query()->count(),
                'active' => GovernancePolicy::query()->where('status', 'approved')->count(),
                'draft' => GovernancePolicy::query()->where('status', 'draft')->count(),
                'requires_attestation' => GovernancePolicy::query()
                    ->where('status', 'approved')
                    ->where('requires_attestation', true)
                    ->count(),
                'waiting_on_members' => $this->policiesWaitingOnMembers($boardUserIds, $today)->count(),
                'board_member_count' => $boardUserIds->count(),
                'review_overdue' => GovernancePolicy::query()
                    ->where('status', 'approved')
                    ->whereDate('next_review_date', '<', $today)
                    ->count(),
            ],
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function create()
    {
        $this->authorize('create', GovernancePolicy::class);

        // The full-page form was retired: the register opens the policy wizard.
        return redirect()->route('governance.policies.index', ['create' => 1]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', GovernancePolicy::class);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category' => 'required|string|in:'.implode(',', self::POLICY_CATEGORIES),
            'description' => 'nullable|string',
            'content' => 'required|string',
            'effective_date' => 'required|date',
            'review_date' => 'required|date|after:effective_date',
            'requires_attestation' => 'boolean',
            'attestation_frequency' => 'nullable|in:annual,biannual,quarterly',
        ], $this->messages());

        $policy = GovernancePolicy::create([
            'policy_code' => $this->generatePolicyCode(),
            'title' => $validated['title'],
            'category' => $validated['category'],
            'purpose' => $validated['description'] ?? null,
            'content' => $validated['content'],
            'version_number' => 1,
            'status' => 'draft',
            'owner_id' => auth()->id(),
            'created_by' => auth()->id(),
            'effective_from' => $validated['effective_date'],
            'review_due' => $validated['review_date'],
            'next_review_date' => $validated['review_date'],
            'requires_attestation' => $validated['requires_attestation'] ?? false,
            'attestation_frequency' => ($validated['requires_attestation'] ?? false)
                ? ($validated['attestation_frequency'] ?? null)
                : null,
        ]);

        return redirect()->route('governance.policies.show', $policy)
            ->with('success', 'Policy saved as a draft. Approve it to put it into effect.');
    }

    public function show(Request $request, GovernancePolicy $policy)
    {
        $this->authorize('view', $policy);

        $policy->load(['attestations.user:id,name', 'approvedBy:id,name', 'supersedes:id,title,version_number,status']);

        $user = $request->user();
        $canManage = (bool) $user?->canDo('governance.policies.manage');
        $today = GovernancePolicy::nzToday();
        $boardUserIds = $this->activeBoardUserIds();

        $newerVersion = GovernancePolicy::query()
            ->where('supersedes_policy_id', $policy->id)
            ->orderByDesc('version_number')
            ->first(['id', 'title', 'version_number', 'status']);

        $isApproved = $policy->status === 'approved';

        return Inertia::render('Governance/Policies/Show', [
            'policy' => $this->presentPolicy($policy),
            'confirmation' => $this->presentConfirmation($policy, $user, $boardUserIds, $today),
            // Names are for the people who manage policies; everyone else sees the count.
            'confirmations' => $canManage && $policy->needsConfirmation()
                ? $this->presentBoardConfirmations($policy, $today)
                : null,
            'versions' => [
                'previous' => $policy->supersedes ? [
                    'id' => $policy->supersedes->id,
                    'version' => (int) $policy->supersedes->version_number,
                    'status' => $this->presentStatus((string) $policy->supersedes->status),
                ] : null,
                'newer' => $newerVersion ? [
                    'id' => $newerVersion->id,
                    'version' => (int) $newerVersion->version_number,
                    'status' => $this->presentStatus((string) $newerVersion->status),
                ] : null,
            ],
            'canEdit' => $canManage && ! in_array($policy->status, ['superseded'], true),
            'canApprove' => $canManage && in_array($policy->status, ['draft', 'under_review'], true),
            // One draft of the next version at a time.
            'canStartVersion' => $canManage && $isApproved && $newerVersion === null,
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function edit(GovernancePolicy $policy)
    {
        $this->authorize('update', $policy);

        // The full-page form was retired: the record opens the policy wizard.
        return redirect()->route('governance.policies.show', ['policy' => $policy, 'edit' => 1]);
    }

    public function update(Request $request, GovernancePolicy $policy)
    {
        $this->authorize('update', $policy);

        if ($policy->status === 'superseded') {
            throw ValidationException::withMessages([
                'status' => 'A newer version has replaced this policy, so it can no longer be changed.',
            ]);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'category' => 'sometimes|string|in:'.implode(',', self::POLICY_CATEGORIES),
            'description' => 'nullable|string',
            'content' => 'sometimes|string',
            'review_date' => 'sometimes|date',
            'status' => 'sometimes|in:'.implode(',', self::EDITABLE_STATUSES),
            'requires_attestation' => 'boolean',
            'attestation_frequency' => 'nullable|in:annual,biannual,quarterly',
        ], $this->messages());

        $payload = [];

        if (array_key_exists('title', $validated)) {
            $payload['title'] = $validated['title'];
        }

        if (array_key_exists('category', $validated)) {
            $payload['category'] = $validated['category'];
        }

        if (array_key_exists('description', $validated)) {
            $payload['purpose'] = $validated['description'];
        }

        if (array_key_exists('content', $validated)) {
            if ($policy->status === 'approved' && $validated['content'] !== $policy->content) {
                throw ValidationException::withMessages([
                    'content' => "This policy is approved, so its wording can't be changed here. Start a new version to change the wording.",
                ]);
            }
            $payload['content'] = $validated['content'];
        }

        if (array_key_exists('review_date', $validated)) {
            $payload['review_due'] = $validated['review_date'];
            $payload['next_review_date'] = $validated['review_date'];
        }

        if (array_key_exists('status', $validated) && $validated['status'] !== $this->presentStatus($policy->status)) {
            if ($policy->status === 'approved' && $validated['status'] !== 'archived') {
                throw ValidationException::withMessages([
                    'status' => "An approved policy can't go back to draft or review. Start a new version to change it, or archive it.",
                ]);
            }
            $payload['status'] = $validated['status'];
        }

        if (array_key_exists('requires_attestation', $validated)) {
            $payload['requires_attestation'] = $validated['requires_attestation'];
            if (! $validated['requires_attestation']) {
                $payload['attestation_frequency'] = null;
            }
        }

        if (array_key_exists('attestation_frequency', $validated) && ($payload['requires_attestation'] ?? $policy->requires_attestation)) {
            $payload['attestation_frequency'] = $validated['attestation_frequency'];
        }

        $policy->update($payload);

        return redirect()->back()->with('success', 'Policy saved.');
    }

    public function approve(Request $request, GovernancePolicy $policy)
    {
        $this->authorize('approve', $policy);

        if (! in_array($policy->status, ['draft', 'under_review'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only a draft or a policy waiting for review can be approved.',
            ]);
        }

        $today = GovernancePolicy::nzToday();

        $policy->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'effective_from' => $policy->effective_from ?? $today,
            'next_review_date' => $policy->next_review_date ?? $policy->review_due ?? now()->addYear()->toDateString(),
        ]);

        // The version this one replaces stops being the version in effect.
        if ($policy->supersedes_policy_id) {
            GovernancePolicy::query()
                ->whereKey($policy->supersedes_policy_id)
                ->where('status', 'approved')
                ->get()
                ->each(fn (GovernancePolicy $previous) => $previous->update(['status' => 'superseded']));
        }

        GovernanceAuditService::log('policy.approved', 'GovernancePolicy', $policy->id, [
            'version_number' => (int) $policy->version_number,
            'replaces_policy_id' => $policy->supersedes_policy_id,
        ]);

        return redirect()->back()->with('success', $policy->needsConfirmation()
            ? 'Policy approved. Board members will be asked to confirm they have read it.'
            : 'Policy approved and in effect.');
    }

    public function attest(Request $request, GovernancePolicy $policy)
    {
        $this->authorize('view', $policy);

        $today = GovernancePolicy::nzToday();

        if ($policy->status === 'superseded') {
            throw ValidationException::withMessages([
                'acknowledged' => 'A newer version has replaced this policy. Open the current version to confirm you have read it.',
            ]);
        }

        if (! in_array($policy->status, ['approved', 'published', 'active'], true)) {
            throw ValidationException::withMessages([
                'acknowledged' => "This policy isn't approved yet, so there is nothing to confirm.",
            ]);
        }

        if (! $policy->needsConfirmation()) {
            throw ValidationException::withMessages([
                'acknowledged' => "This policy doesn't ask members to confirm they have read it.",
            ]);
        }

        if ($policy->comesIntoEffectLater($today)) {
            throw ValidationException::withMessages([
                'acknowledged' => 'This policy comes into effect on '.GovernanceLabels::date($policy->effective_from->toDateString()).'. You can confirm you have read it from then.',
            ]);
        }

        $this->authorize('attest', $policy);

        $validated = $request->validate([
            'acknowledged' => 'required|accepted',
            'notes' => 'nullable|string|max:500',
        ], [
            'acknowledged.required' => "Tick the box to confirm you've read this version of the policy.",
            'acknowledged.accepted' => "Tick the box to confirm you've read this version of the policy.",
            'notes.max' => 'Keep your note to 500 characters or fewer.',
        ]);

        $attestation = PolicyAttestation::updateOrCreate(
            [
                'governance_policy_id' => $policy->id,
                'user_id' => auth()->id(),
            ],
            [
                'acknowledged' => true,
                'policy_version' => $policy->version_number,
                'acknowledged_at' => now(),
                'notes' => $validated['notes'] ?? null,
            ]
        );

        GovernanceAuditService::log(
            'policy.attested',
            'GovernancePolicy',
            $policy->id,
            ['user_id' => auth()->id(), 'version_number' => $policy->version_number]
        );

        return redirect()->back()->with('success', sprintf(
            'You confirmed version %d on %s.',
            (int) $policy->version_number,
            GovernanceLabels::date($attestation->acknowledged_at),
        ));
    }

    /**
     * "Policies to confirm" — the viewer's own read-and-confirm list. Only
     * approved policies that ask members to confirm are listed; policies that
     * come into effect later are shown separately, without a confirm action.
     */
    public function attestations(Request $request)
    {
        $this->authorize('viewAny', GovernancePolicy::class);

        $user = $request->user();
        $canManage = (bool) $user?->canDo('governance.policies.manage');
        $today = GovernancePolicy::nzToday();
        $boardUserIds = $this->activeBoardUserIds();

        $policies = GovernancePolicy::query()
            ->with('attestations')
            ->where('status', 'approved')
            ->where('requires_attestation', true)
            ->orderBy('title')
            ->get();

        $rows = $policies->map(function (GovernancePolicy $policy) use ($user, $canManage, $today, $boardUserIds) {
            $mine = $policy->attestations->firstWhere('user_id', $user->id);
            $state = $this->confirmationState($policy, $mine, $today);

            return [
                'id' => $policy->id,
                'title' => $policy->title,
                'category' => $policy->category,
                'version' => (int) $policy->version_number,
                'effective_from' => $policy->effective_from?->toDateString(),
                'next_review_date' => $policy->next_review_date?->toDateString(),
                'state' => $state,
                'my_confirmation' => $this->presentMyConfirmation($policy, $mine),
                'confirmed_count' => $canManage ? $policy->currentBoardConfirmationCount($boardUserIds, $today) : null,
                'board_member_count' => $canManage ? $boardUserIds->count() : null,
            ];
        });

        $toConfirm = $rows->whereIn('state', ['to_confirm', 'due_again'])->values();
        $confirmed = $rows->where('state', 'confirmed')->values();
        $upcoming = $rows->where('state', 'not_yet_in_effect')->values();

        return Inertia::render('Governance/Policies/Attestations', [
            'toConfirm' => $toConfirm,
            'confirmed' => $confirmed,
            'upcoming' => $upcoming,
            'canManage' => $canManage,
            'summary' => [
                'to_confirm' => $toConfirm->count(),
                'confirmed' => $confirmed->count(),
                'upcoming' => $upcoming->count(),
                'board_member_count' => $boardUserIds->count(),
            ],
            'categories' => $this->categoryOptions(),
        ]);
    }

    /**
     * Draft the next version of an approved policy with its "What changed"
     * note. The approved version stays in effect until this one is approved.
     */
    public function newVersion(Request $request, GovernancePolicy $policy)
    {
        $this->authorize('newVersion', $policy);

        if ($policy->status !== 'approved') {
            throw ValidationException::withMessages([
                'change_summary' => 'Only an approved policy can have a new version. Edit this one instead.',
            ]);
        }

        $existingDraft = GovernancePolicy::query()
            ->where('supersedes_policy_id', $policy->id)
            ->first(['id', 'version_number']);

        if ($existingDraft) {
            throw ValidationException::withMessages([
                'change_summary' => 'Version '.(int) $existingDraft->version_number.' of this policy is already being drafted. Open it to carry on.',
            ]);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'category' => 'sometimes|string|in:'.implode(',', self::POLICY_CATEGORIES),
            'description' => 'nullable|string',
            'content' => 'required|string',
            'effective_date' => 'sometimes|nullable|date',
            'review_date' => 'sometimes|nullable|date',
            'requires_attestation' => 'boolean',
            'attestation_frequency' => 'nullable|in:annual,biannual,quarterly',
            'change_summary' => 'required|string|max:500',
        ], $this->messages());

        $newPolicy = $policy->createNewVersion(auth()->id());

        $requiresConfirmation = $validated['requires_attestation'] ?? (bool) $policy->requires_attestation;

        $newPolicy->update([
            'title' => $validated['title'] ?? $policy->title,
            'category' => $validated['category'] ?? $policy->category,
            'purpose' => array_key_exists('description', $validated) ? $validated['description'] : $policy->purpose,
            'content' => $validated['content'],
            'change_summary' => $validated['change_summary'],
            'effective_from' => $validated['effective_date'] ?? null,
            'review_due' => $validated['review_date'] ?? $policy->review_due,
            'next_review_date' => $validated['review_date'] ?? $policy->next_review_date,
            'requires_attestation' => $requiresConfirmation,
            'attestation_frequency' => $requiresConfirmation
                ? ($validated['attestation_frequency'] ?? $policy->attestation_frequency)
                : null,
        ]);

        return redirect()->route('governance.policies.show', $newPolicy)
            ->with('success', 'Version '.(int) $newPolicy->version_number.' saved as a draft. The current version stays in effect until you approve this one.');
    }

    /**
     * @param  Collection<int, int>  $boardUserIds
     */
    protected function presentPolicyListItem(GovernancePolicy $policy, Collection $boardUserIds, string $today): array
    {
        return [
            'id' => $policy->id,
            'title' => $policy->title,
            'category' => $policy->category,
            'version' => (int) $policy->version_number,
            'status' => $this->presentStatus($policy->status),
            'effective_date' => $policy->effective_from?->toDateString(),
            'review_date' => $policy->next_review_date?->toDateString() ?? $policy->review_due?->toDateString(),
            'requires_attestation' => (bool) $policy->requires_attestation,
            'attestation_frequency' => $policy->attestation_frequency,
            // "Confirmed: 3 of 7" — current version, active board members only.
            'confirmation' => $policy->needsConfirmation() && $policy->status === 'approved' ? [
                'confirmed' => $policy->currentBoardConfirmationCount($boardUserIds, $today),
                'total' => $boardUserIds->count(),
                'in_effect' => $policy->isInEffect($today),
            ] : null,
        ];
    }

    protected function presentPolicy(GovernancePolicy $policy): array
    {
        return [
            'id' => $policy->id,
            'title' => $policy->title,
            'reference' => $policy->policy_code,
            'category' => $policy->category,
            'description' => $policy->purpose,
            'content' => $policy->content,
            'change_summary' => $policy->change_summary,
            'version' => (int) $policy->version_number,
            'status' => $this->presentStatus($policy->status),
            'effective_date' => $policy->effective_from?->toDateString(),
            'review_date' => $policy->next_review_date?->toDateString() ?? $policy->review_due?->toDateString(),
            'requires_attestation' => (bool) $policy->requires_attestation,
            'attestation_frequency' => $policy->attestation_frequency,
            'approved_by_user' => $policy->approvedBy ? ['name' => $policy->approvedBy->name] : null,
            'approved_at' => $policy->approved_at?->toIso8601String(),
        ];
    }

    /**
     * The viewer's read-and-confirm state on the policy page.
     *
     * @param  Collection<int, int>  $boardUserIds
     */
    protected function presentConfirmation(GovernancePolicy $policy, ?User $user, Collection $boardUserIds, string $today): array
    {
        $mine = $user ? $policy->attestations->firstWhere('user_id', $user->id) : null;
        $state = $this->confirmationState($policy, $mine, $today);

        return [
            'required' => $policy->needsConfirmation(),
            'state' => $state,
            'effective_from' => $policy->effective_from?->toDateString(),
            'frequency' => $policy->attestation_frequency,
            'my_confirmation' => $this->presentMyConfirmation($policy, $mine),
            'can_confirm' => in_array($state, ['to_confirm', 'due_again'], true)
                && $user !== null
                && $user->can('attest', $policy),
            'board_confirmed' => $policy->needsConfirmation() ? $policy->currentBoardConfirmationCount($boardUserIds, $today) : 0,
            'board_total' => $boardUserIds->count(),
        ];
    }

    /**
     * Active board members and whether each has confirmed the current version.
     *
     * @return array<int, array{user_id: int, name: string, confirmed_at: ?string, confirmed: bool}>
     */
    protected function presentBoardConfirmations(GovernancePolicy $policy, string $today): array
    {
        return BoardMember::active()
            ->with('user:id,name')
            ->get()
            ->filter(fn (BoardMember $member) => $member->user !== null)
            ->unique('user_id')
            ->map(function (BoardMember $member) use ($policy, $today) {
                $attestation = $policy->attestations->firstWhere('user_id', $member->user_id);
                $current = $policy->isCurrentConfirmation($attestation, $today);

                return [
                    'user_id' => (int) $member->user_id,
                    'name' => (string) $member->user->name,
                    'confirmed' => $current,
                    'confirmed_at' => $current ? $attestation?->acknowledged_at?->toIso8601String() : null,
                ];
            })
            ->sortBy([['confirmed', 'desc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    /**
     * not_required · not_approved · replaced · not_yet_in_effect · to_confirm · due_again · confirmed
     */
    protected function confirmationState(GovernancePolicy $policy, ?PolicyAttestation $mine, string $today): string
    {
        if (! $policy->needsConfirmation()) {
            return 'not_required';
        }

        if ($policy->status === 'superseded') {
            return 'replaced';
        }

        if (! in_array($policy->status, ['approved', 'published', 'active'], true)) {
            return 'not_approved';
        }

        if ($policy->comesIntoEffectLater($today)) {
            return 'not_yet_in_effect';
        }

        if ($policy->isCurrentConfirmation($mine, $today)) {
            return 'confirmed';
        }

        // Confirmed this version before, but the confirmation frequency says it's due again.
        return $mine?->acknowledged
            && (int) $mine->policy_version === (int) $policy->version_number
            ? 'due_again'
            : 'to_confirm';
    }

    protected function presentMyConfirmation(GovernancePolicy $policy, ?PolicyAttestation $mine): ?array
    {
        if (! $mine || ! $mine->acknowledged || ! $mine->acknowledged_at) {
            return null;
        }

        return [
            'version' => (int) ($mine->policy_version ?? $policy->version_number),
            'confirmed_at' => $mine->acknowledged_at->toIso8601String(),
            'due_again_on' => (int) $mine->policy_version === (int) $policy->version_number
                ? $policy->confirmationDueAgainOn($mine)
                : null,
        ];
    }

    /**
     * Approved, in-effect policies that ask members to confirm and that not
     * every active board member has confirmed yet.
     *
     * @param  Collection<int, int>  $boardUserIds
     * @return Collection<int, GovernancePolicy>
     */
    protected function policiesWaitingOnMembers(Collection $boardUserIds, string $today): Collection
    {
        if ($boardUserIds->isEmpty()) {
            return collect();
        }

        return GovernancePolicy::query()
            ->with('attestations')
            ->where('status', 'approved')
            ->where('requires_attestation', true)
            ->get()
            ->filter(fn (GovernancePolicy $policy) => $policy->isInEffect($today)
                && $policy->currentBoardConfirmationCount($boardUserIds, $today) < $boardUserIds->count())
            ->values();
    }

    /** @return Collection<int, int> */
    protected function activeBoardUserIds(): Collection
    {
        return BoardMember::active()
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    /** @return array<int, array{value: string, label: string}> */
    protected function categoryOptions(): array
    {
        return collect(self::POLICY_CATEGORIES)
            ->map(fn (string $category) => [
                'value' => $category,
                'label' => GovernanceLabels::label('policy_category', $category),
            ])
            ->all();
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'title.required' => 'Give the policy a title.',
            'title.max' => 'Keep the title to 255 characters or fewer.',
            'category.required' => 'Choose a category.',
            'category.in' => 'Choose one of the listed categories.',
            'content.required' => 'Add the policy wording.',
            'effective_date.required' => 'Choose the date the policy comes into effect.',
            'effective_date.date' => 'Enter the date the policy comes into effect as a date.',
            'review_date.required' => 'Choose when the policy should next be reviewed.',
            'review_date.date' => 'Enter the review date as a date.',
            'review_date.after' => 'The review date must be after the date the policy comes into effect.',
            'status.in' => 'Choose draft, waiting for review or archived. Use Approve to put a policy into effect.',
            'attestation_frequency.in' => 'Choose how often members confirm: once a year, twice a year or every 3 months.',
            'change_summary.required' => 'Say what changed in this version.',
            'change_summary.max' => 'Keep "What changed" to 500 characters or fewer.',
        ];
    }

    protected function normalizeStatus(string $status): string
    {
        return match ($status) {
            'active' => 'approved',
            'archived' => 'archived',
            default => $status,
        };
    }

    protected function presentStatus(string $status): string
    {
        return match ($status) {
            'approved' => 'active',
            default => $status,
        };
    }

    protected function generatePolicyCode(): string
    {
        do {
            $code = 'POL-'.strtoupper(Str::random(6));
        } while (GovernancePolicy::query()->withTrashed()->where('policy_code', $code)->exists());

        return $code;
    }
}
