<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\CarePlan;
use App\Models\CarePlanGoal;
use App\Models\CarePlanSignOff;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\ClientOnboardingStep;
use App\Models\ClientOnboardingWorkflow;
use App\Models\ServiceAgreement;
use App\Models\User;
use App\Services\Timeline\TimelineEmitter;
use App\Services\UserSiteAccessService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CarePlanController extends Controller
{
    private const CLIENT_SITE_BYPASS_PERMISSIONS = ['clients.viewAny'];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.viewAny'), 403);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string'],
            'plan_type' => ['nullable', 'string'],
            'client_id' => ['nullable', 'integer'],
            'review_due' => ['nullable', 'boolean'],
        ]);

        $baseQuery = $this->visibleCarePlans($auth);

        // Stats
        $stats = [
            'total' => (clone $baseQuery)->count(),
            'active' => (clone $baseQuery)->where('status', 'active')->count(),
            'review_due' => (clone $baseQuery)->where('status', 'active')
                ->where(function ($q) {
                    $q->whereNull('next_review_at')
                        ->orWhere('next_review_at', '<=', now());
                })->count(),
            'draft' => (clone $baseQuery)->where('status', 'draft')->count(),
            'in_review' => (clone $baseQuery)->where('status', 'review')->count(),
            'plans_without_goals' => (clone $baseQuery)->whereDoesntHave('goals')->where('status', '!=', 'archived')->count(),
            'overdue_goals' => CarePlanGoal::query()
                ->whereHas('carePlan', function ($q) use ($auth) {
                    $this->applyCarePlanVisibility($q->where('status', 'active'), $auth);
                })
                ->where('status', '!=', 'completed')
                ->whereNotNull('target_date')
                ->where('target_date', '<', now())
                ->count(),
        ];

        // Charts
        $plans_by_status = (clone $baseQuery)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Filtered query for listing
        $carePlans = $this->visibleCarePlans($auth)
            ->when(! empty($data['q']), fn ($q) => $q->where('title', 'like', '%'.$data['q'].'%'))
            ->when(! empty($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when(! empty($data['plan_type']), fn ($q) => $q->where('plan_type', $data['plan_type']))
            ->when(! empty($data['client_id']), fn ($q) => $q->where('client_id', $data['client_id']))
            ->when(! empty($data['review_due']), fn ($q) => $q->where('status', 'active')->where(function ($q2) {
                $q2->whereNull('next_review_at')->orWhere('next_review_at', '<=', now());
            }))
            ->with(['client:id,first_name,last_name', 'creator:id,name'])
            ->withCount(['goals', 'goals as goals_achieved_count' => fn ($q) => $q->where('status', 'completed')])
            ->orderByDesc('updated_at')
            ->paginate(15)
            ->withQueryString();

        $clients = $this->visibleClients($auth)
            ->select('id', 'first_name', 'last_name')
            ->orderBy('last_name')
            ->get();

        return inertia('operations/care-plans/Index', [
            'carePlans' => $carePlans,
            'clients' => $clients,
            'filters' => $data,
            'stats' => $stats,
            'plans_by_status' => $plans_by_status,
        ]);
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.create'), 403);

        $clients = $this->visibleClients($auth)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        return inertia('operations/care-plans/Create', [
            'clients' => $clients,
        ]);
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.create'), 403);

        $this->validateStructuredDomains($request->input('content'));
        $clientId = $request->integer('client_id');

        $data = $request->validate(array_merge([
            'client_id' => [
                'required',
                'integer',
                Rule::exists('clients', 'id'),
            ],
            'title' => ['required', 'string', 'max:255'],
            'plan_type' => ['required', 'string', 'max:100'],
            'content' => ['nullable', 'array'],
            'content.domains' => ['nullable', 'array'],
            'content.domains.*.key' => ['nullable', 'string', 'max:80'],
            'content.domains.*.label' => ['required_with:content.domains', 'filled', 'string', 'max:120'],
            'content.domains.*.status' => ['nullable', Rule::in(['on_track', 'active', 'review'])],
            'content.domains.*.strategies' => ['nullable', 'array'],
            'content.domains.*.strategies.*.text' => ['required_with:content.domains.*.strategies', 'string', 'max:500'],
            'content.domains.*.strategies.*.owner' => ['nullable', 'string', 'max:120'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'next_review_at' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:draft,active'],
        ], $this->planContentRules($clientId)));

        $this->siteAccess->assertCanAccessClientId(
            $auth,
            (int) $data['client_id'],
            self::CLIENT_SITE_BYPASS_PERMISSIONS,
        );
        $client = Client::query()->findOrFail($data['client_id']);

        if (($data['status'] ?? 'draft') === 'active'
            && ! $this->hasStructuredDomains($data['content'] ?? [])) {
            throw ValidationException::withMessages([
                'goals' => 'Cannot create an active care plan without at least one support domain.',
            ]);
        }

        $carePlan = CarePlan::create([
            'client_id' => $data['client_id'],
            'title' => $data['title'],
            'plan_type' => $data['plan_type'],
            'content' => $data['content'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'next_review_at' => $data['next_review_at'] ?? null,
            'status' => $data['status'] ?? 'draft',
            'created_by' => $auth->id,
            'version' => 1,
        ]);

        app(TimelineEmitter::class)->record([
            'source_type' => CarePlan::class,
            'source_id' => $carePlan->id,
            'occurred_at' => now(),
            'type' => 'care_plan_created',
            'actor_user_id' => $auth->id,
            'client_id' => $data['client_id'],
            'site_id' => $client?->site_id,
            'subject' => 'Care plan created: '.$data['title'],
            'body' => null,
            'meta' => array_filter([
                'plan_type' => $data['plan_type'],
                'status' => $data['status'] ?? 'draft',
            ]),
            'visibility' => 'internal',
            'is_pinned' => false,
            'created_by' => $auth->id,
        ]);

        // Auto-complete onboarding step if from_onboarding
        if ($request->boolean('from_onboarding')) {
            $workflow = ClientOnboardingWorkflow::where('client_id', $data['client_id'])
                ->where('status', 'in_progress')
                ->first();

            if ($workflow) {
                $step = ClientOnboardingStep::where('workflow_id', $workflow->id)
                    ->where('step_name', 'Care Plan Created')
                    ->where('status', '!=', 'completed')
                    ->first();

                if ($step) {
                    $step->update([
                        'status' => 'completed',
                        'completed_at' => now(),
                        'completed_by' => $auth->id,
                        'notes' => 'Auto-completed: Care plan #'.$carePlan->id.' created.',
                    ]);
                }
            }

            return redirect("/operations/clients/{$data['client_id']}?tab=onboarding")
                ->with('success', 'Care plan created and onboarding step completed.');
        }

        return redirect("/operations/clients/{$data['client_id']}?tab=care_plans")
            ->with('success', 'Care plan created.');
    }

    public function show(Request $request, $carePlan)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.viewAny'), 403);

        $carePlan = CarePlan::query()
            ->with([
                'client:id,first_name,last_name',
                'creator:id,name',
                'reviewer:id,name',
                'goals' => fn ($q) => $q->orderBy('priority')->orderBy('title'),
                'goals.progressNotes' => fn ($q) => $q
                    ->with(['author:id,name', 'carePlanGoal:id,title'])
                    ->orderByDesc('occurred_at')
                    ->limit(5),
            ])
            ->withCount([
                'goals',
                'goals as goals_completed' => fn ($q) => $q->where('status', 'completed'),
                'goals as goals_in_progress' => fn ($q) => $q->where('status', 'in_progress'),
            ])
            ->findOrFail($carePlan);
        $this->authorize('view', $carePlan);

        $progressStats = [
            'total_goals' => $carePlan->goals_count,
            'completed' => $carePlan->goals_completed,
            'in_progress' => $carePlan->goals_in_progress,
            'average_progress' => $carePlan->goals->count() > 0
                ? round($carePlan->goals->avg('progress_percentage'), 1)
                : 0,
        ];

        $carePlan->goals->each(function ($goal) {
            $goal->setRelation(
                'progressNotes',
                $goal->progressNotes->map(
                    fn (ClientNote $note) => $this->legacyNotePayload($note),
                ),
            );
        });

        // Canonical client notes linked to this plan's goals. The explicit map
        // preserves the payload consumed by the existing care-plan page.
        $progressNotes = ClientNote::query()
            ->whereHas('carePlanGoal', fn ($q) => $q->where('care_plan_id', $carePlan->id))
            ->where('type', 'progress_note')
            ->with(['author:id,name', 'carePlanGoal:id,title'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ClientNote $note) => $this->legacyNotePayload($note));

        // Review history via parent_id chain
        $reviewHistory = CarePlan::query()
            ->where('client_id', $carePlan->client_id)
            ->where(function ($q) use ($carePlan) {
                $q->where('parent_id', $carePlan->parent_id ?? $carePlan->id)
                    ->orWhere('id', $carePlan->parent_id ?? $carePlan->id);
            })
            ->where('id', '!=', $carePlan->id)
            ->with(['reviewer:id,name'])
            ->orderByDesc('version')
            ->get();

        // Current staff at Sites visible to the viewer for reviewer assignment.
        $staff = $this->siteAccess->applyStaffScope(
            User::query(),
            $auth,
            self::CLIENT_SITE_BYPASS_PERMISSIONS,
        )
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return inertia('operations/care-plans/Show', [
            'care_plan' => $carePlan,
            'progressStats' => $progressStats,
            'progressNotes' => $progressNotes,
            'reviewHistory' => $reviewHistory,
            'staff' => $staff,
        ]);
    }

    public function edit(Request $request, $carePlan)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.update'), 403);

        $carePlan = CarePlan::query()
            ->with(['client:id,first_name,last_name'])
            ->findOrFail($carePlan);
        $this->authorize('update', $carePlan);

        $clients = $this->visibleClients($auth)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        return inertia('operations/care-plans/Edit', [
            'care_plan' => $carePlan,
            'clients' => $clients,
        ]);
    }

    public function update(Request $request, $carePlan)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.update'), 403);

        $carePlan = CarePlan::query()->findOrFail($carePlan);
        $this->authorize('update', $carePlan);

        $this->ensureMutableCarePlan($carePlan);

        $this->validateStructuredDomains($request->input('content'));

        $data = $request->validate(array_merge([
            'client_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::in([(int) $carePlan->client_id]),
            ],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'plan_type' => ['sometimes', 'required', 'string', 'max:100'],
            'content' => ['nullable', 'array'],
            'content.domains' => ['nullable', 'array'],
            'content.domains.*.key' => ['nullable', 'string', 'max:80'],
            'content.domains.*.label' => ['required_with:content.domains', 'filled', 'string', 'max:120'],
            'content.domains.*.status' => ['nullable', Rule::in(['on_track', 'active', 'review'])],
            'content.domains.*.strategies' => ['nullable', 'array'],
            'content.domains.*.strategies.*.text' => ['required_with:content.domains.*.strategies', 'string', 'max:500'],
            'content.domains.*.strategies.*.owner' => ['nullable', 'string', 'max:120'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'next_review_at' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:draft,active,review,archived'],
        ], $this->planContentRules((int) $carePlan->client_id)));

        if (! $carePlan->allowsGenericTransitionTo($data['status'] ?? null)) {
            throw ValidationException::withMessages([
                'status' => 'Use the care plan review actions to change this plan status.',
            ]);
        }

        // Prevent activating a plan with no goals
        $becomingActive = ($data['status'] ?? null) === 'active' && $carePlan->status !== 'active';
        $content = $data['content'] ?? $carePlan->content ?? [];
        if ($becomingActive && $carePlan->goals()->count() === 0 && ! $this->hasStructuredDomains($content)) {
            return back()->withErrors(['goals' => 'Cannot activate a care plan without at least one goal or support domain.']);
        }

        $carePlan->update($data);

        return redirect("/operations/clients/{$carePlan->client_id}?tab=care_plans")
            ->with('success', 'Care plan updated.');
    }

    public function startReview(Request $request, CarePlan $carePlan)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.update'), 403);
        $this->authorize('update', $carePlan);

        $reviewCreated = DB::transaction(function () use ($auth, $carePlan): bool {
            $source = CarePlan::query()
                ->where('client_id', $carePlan->client_id)
                ->lockForUpdate()
                ->findOrFail($carePlan->id);

            if ($source->status !== 'active') {
                throw ValidationException::withMessages([
                    'status' => 'Only an active care plan can start a review.',
                ]);
            }

            $rootId = $source->parent_id ?? $source->id;
            $existingReview = CarePlan::query()
                ->where('client_id', $source->client_id)
                ->where('status', 'review')
                ->where(function ($query) use ($rootId) {
                    $query->whereKey($rootId)->orWhere('parent_id', $rootId);
                })
                ->exists();
            if ($existingReview) {
                return false;
            }

            $source->load(['goals.steps', 'signOffs']);
            $content = $source->content ?? [];
            data_set($content, 'review_context.source_plan_id', $source->id);
            data_set($content, 'review_context.source_version', $source->version);
            data_set(
                $content,
                'review_context.prior_sign_offs',
                $source->signOffs->map(fn (CarePlanSignOff $signOff) => [
                    'party_role' => $signOff->party_role,
                    'party_name' => $signOff->party_name,
                    'relationship' => $signOff->relationship,
                    'agreed_on' => $signOff->agreed_on?->toDateString(),
                    'method' => $signOff->method,
                    'acknowledgement' => $signOff->acknowledgement,
                ])->values()->all(),
            );

            $newVersion = $source->replicate([
                ...$source->getHidden(),
                'id',
                'created_at',
                'updated_at',
                'deleted_at',
            ]);
            $newVersion->version = $source->version + 1;
            $newVersion->parent_id = $rootId;
            $newVersion->status = 'review';
            $newVersion->reviewed_at = null;
            $newVersion->reviewed_by = null;
            $newVersion->created_by = $auth->id;
            $newVersion->content = $content;
            $newVersion->save();

            foreach ($source->goals as $goal) {
                $newGoal = $goal->replicate([
                    ...$goal->getHidden(),
                    'id',
                    'created_at',
                    'updated_at',
                    'deleted_at',
                ]);
                $newGoal->care_plan_id = $newVersion->id;
                $newGoal->created_by = $auth->id;
                $newGoal->save();

                foreach ($goal->steps as $step) {
                    $newStep = $step->replicate([
                        ...$step->getHidden(),
                        'id',
                        'created_at',
                        'updated_at',
                        'deleted_at',
                    ]);
                    $newStep->care_plan_goal_id = $newGoal->id;
                    $newStep->created_by = $auth->id;
                    $newStep->save();
                }
            }

            return true;
        });

        if (! $reviewCreated) {
            return redirect("/operations/clients/{$carePlan->client_id}?tab=care_plans")
                ->with('status', 'A review is already in progress.');
        }

        // Stay inside the client profile — the Care & Support Plan tab surfaces the
        // in-progress review version (care_plans_summary.review_plan) for editing.
        return redirect("/operations/clients/{$carePlan->client_id}?tab=care_plans")
            ->with('success', 'Review started. Update the plan and complete the review when ready.');
    }

    public function completeReview(Request $request, CarePlan $carePlan)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.update'), 403);
        $this->authorize('update', $carePlan);

        $data = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($auth, $carePlan, $data): void {
            $locked = CarePlan::query()
                ->where('client_id', $carePlan->client_id)
                ->lockForUpdate()
                ->findOrFail($carePlan->id);
            if ($locked->status !== 'review') {
                throw ValidationException::withMessages([
                    'status' => 'Only an in-progress review can be completed.',
                ]);
            }
            if ($locked->goals()->count() === 0 && ! $this->hasStructuredDomains($locked->content ?? [])) {
                throw ValidationException::withMessages([
                    'goals' => 'Cannot activate a care plan without at least one goal or support domain. Please add goals or domains before completing the review.',
                ]);
            }
            if (! $locked->signOffs()->exists()) {
                throw ValidationException::withMessages([
                    'sign_offs' => 'Record at least one new sign-off on this review before completing it.',
                ]);
            }

            $rootId = $locked->parent_id ?? $locked->id;

            CarePlan::query()
                ->where('client_id', $locked->client_id)
                ->where('id', '!=', $locked->id)
                ->where(function ($query) use ($rootId) {
                    $query->whereKey($rootId)->orWhere('parent_id', $rootId);
                })
                ->where('status', 'active')
                ->update(['status' => 'archived']);

            $content = $locked->content ?? [];
            if (filled($data['review_notes'] ?? null)) {
                data_set($content, 'review_context.review_notes', $data['review_notes']);
            }
            data_set($content, 'review_context.completed_at', now()->toISOString());
            data_set($content, 'review_context.completed_by', $auth->id);

            $locked->update([
                'status' => 'active',
                'reviewed_at' => now(),
                'reviewed_by' => $auth->id,
                'next_review_at' => $locked->next_review_at ?? now()->addMonths(3),
                'content' => $content,
            ]);
        });

        return redirect("/operations/clients/{$carePlan->client_id}?tab=care_plans")
            ->with('success', 'Review completed. Plan is now active.');
    }

    public function destroy(Request $request, $carePlan)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.delete'), 403);

        $carePlan = CarePlan::query()->findOrFail($carePlan);

        $this->authorize('delete', $carePlan);
        $this->ensureMutableCarePlan($carePlan);

        $carePlan->delete();

        return redirect()->route('operations.care_plans.index')
            ->with('success', 'Care plan deleted.');
    }

    public function storeSignOff(Request $request, $carePlan)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.update'), 403);

        $carePlan = CarePlan::query()->findOrFail($carePlan);
        $this->authorize('update', $carePlan);

        $this->ensureMutableCarePlan($carePlan);

        $data = $request->validate([
            'party_role' => ['required', Rule::in(CarePlanSignOff::PARTY_ROLES)],
            'party_name' => ['required', 'string', 'max:160'],
            'relationship' => ['nullable', 'string', 'max:120'],
            'agreed_on' => ['required', 'date'],
            'method' => ['nullable', Rule::in(CarePlanSignOff::METHODS)],
            'acknowledgement' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($auth, $carePlan, $data): void {
            $signOff = $carePlan->signOffs()->create([
                'party_role' => $data['party_role'],
                'party_name' => $data['party_name'],
                'relationship' => $data['relationship'] ?? null,
                'agreed_on' => $data['agreed_on'],
                'method' => $data['method'] ?? null,
                'acknowledgement' => $data['acknowledgement'] ?? null,
                'recorded_by' => $auth->id,
            ]);

            app(TimelineEmitter::class)->record([
                'source_type' => CarePlanSignOff::class,
                'source_id' => $signOff->id,
                'occurred_at' => now(),
                'type' => 'care_plan_signed_off',
                'actor_user_id' => $auth->id,
                'client_id' => $carePlan->client_id,
                'site_id' => $carePlan->client?->site_id,
                'subject' => 'Care plan agreed by '.$signOff->party_name,
                'body' => null,
                'meta' => array_filter([
                    'care_plan_id' => $carePlan->id,
                    'party_role' => $signOff->party_role,
                    'method' => $signOff->method,
                ]),
                'visibility' => 'internal',
                'is_pinned' => false,
                'created_by' => $auth->id,
            ]);
        });

        return back()->with('success', 'Sign-off recorded.');
    }

    public function destroySignOff(Request $request, $carePlan, $signOff)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.update'), 403);

        $carePlan = CarePlan::query()->findOrFail($carePlan);
        $this->authorize('update', $carePlan);

        $this->ensureMutableCarePlan($carePlan);

        DB::transaction(function () use ($carePlan, $signOff): void {
            $signOff = $carePlan->signOffs()->findOrFail($signOff);
            app(TimelineEmitter::class)->retract($signOff);
            $signOff->delete();
        });

        return back()->with('success', 'Sign-off removed.');
    }

    public function exportPdf(Request $request, $carePlan)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.viewAny'), 403);

        $carePlan = CarePlan::query()
            ->with([
                'client:id,first_name,last_name,date_of_birth',
                'creator:id,name',
                'reviewer:id,name',
                'goals' => fn ($q) => $q->orderByDesc('progress_percentage'),
                'signOffs' => fn ($q) => $q->orderBy('party_role'),
                'signOffs.recorder:id,name',
            ])
            ->findOrFail($carePlan);
        $this->authorize('view', $carePlan);

        $content = is_array($carePlan->content) ? $carePlan->content : [];

        $agreement = null;
        $agreementId = data_get($content, 'funding.service_agreement_id');
        if ($agreementId) {
            $agreement = ServiceAgreement::query()
                ->where('client_id', $carePlan->client_id)
                ->find($agreementId);
        }

        $pdf = Pdf::loadView('pdf.care-plan', [
            'plan' => $carePlan,
            'content' => $content,
            'agreement' => $agreement,
            'generatedAt' => now(),
        ])->setPaper('A4');

        $clientName = trim(($carePlan->client?->first_name ?? '').' '.($carePlan->client?->last_name ?? ''));
        $filename = 'care-plan-'.Str::slug($clientName !== '' ? $clientName : 'client').'-v'.($carePlan->version ?? 1).'.pdf';

        return $pdf->download($filename);
    }

    /**
     * Keep the care-plan frontend contract stable while ClientNote is the
     * canonical persistence model.
     *
     * @return array<string, mixed>
     */
    private function legacyNotePayload(ClientNote $note): array
    {
        $author = $note->author ? [
            'id' => $note->author->id,
            'name' => $note->author->name,
        ] : null;
        $goal = $note->carePlanGoal ? [
            'id' => $note->carePlanGoal->id,
            'title' => $note->carePlanGoal->title,
        ] : null;

        return [
            'id' => $note->id,
            'client_id' => $note->client_id,
            'shift_id' => $note->shift_id,
            'care_plan_goal_id' => $note->care_plan_goal_id,
            'author_id' => $note->user_id,
            'note_type' => $note->category ?: $note->type,
            'content' => $note->body,
            'mood_rating' => $note->mood_rating,
            'emotions' => $note->behaviour_tags ?? [],
            'is_flagged' => (bool) $note->is_flagged,
            'flagged_reason' => $note->flagged_reason,
            'ai_summary' => $note->ai_summary,
            'visibility' => $note->is_private
                ? 'private'
                : ($note->visibility === 'portal' ? 'include_family' : 'staff_only'),
            'created_at' => optional($note->occurred_at ?? $note->created_at)->toISOString(),
            'updated_at' => optional($note->updated_at)->toISOString(),
            'deleted_at' => optional($note->deleted_at)->toISOString(),
            'author' => $author,
            'user' => $author,
            'goal' => $goal,
        ];
    }

    /**
     * Validation rules for the structured `content` JSON beyond the domains
     * (which are validated inline + by validateStructuredDomains). Shared by
     * store() and update().
     *
     * @return array<string, array<int, mixed>>
     */
    private function planContentRules(int $clientId): array
    {
        return [
            'content.about_me' => ['nullable', 'array'],
            'content.about_me.dreams' => ['nullable', 'string', 'max:2000'],
            'content.about_me.important_to_me' => ['nullable', 'string', 'max:2000'],
            'content.about_me.important_for_me' => ['nullable', 'string', 'max:2000'],
            'content.about_me.ideal_day' => ['nullable', 'string', 'max:2000'],
            'content.about_me.likes' => ['nullable', 'string', 'max:2000'],
            'content.about_me.dislikes' => ['nullable', 'string', 'max:2000'],
            'content.about_me.how_to_support' => ['nullable', 'string', 'max:2000'],
            'content.support_needs' => ['nullable', 'array'],
            'content.risk_factors' => ['nullable', 'string', 'max:5000'],
            'content.support_strategies' => ['nullable', 'string', 'max:5000'],
            'content.communication_preferences' => ['nullable', 'string', 'max:5000'],
            'content.review_schedule' => ['nullable', 'array'],
            'content.review_schedule.frequency_months' => ['nullable', 'integer', 'in:1,3,6,12'],
            'content.egl' => ['nullable', 'array'],
            'content.egl.vision' => ['nullable', 'string', 'max:2000'],
            'content.egl.principles' => ['nullable', 'array'],
            'content.egl.principles.*' => ['nullable', 'string', 'max:120'],
            'content.funding' => ['nullable', 'array'],
            'content.funding.nasc_organisation' => ['nullable', 'string', 'max:160'],
            'content.funding.needs_assessment_ref' => ['nullable', 'string', 'max:160'],
            'content.funding.needs_assessment_date' => ['nullable', 'date'],
            'content.funding.service_agreement_id' => [
                'nullable',
                'integer',
                Rule::exists('service_agreements', 'id')->where(
                    fn ($query) => $query
                        ->where('client_id', $clientId)
                        ->whereNull('deleted_at'),
                ),
            ],
            'content.funding.allocated_hours' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'content.funding.funding_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $content
     */
    private function hasStructuredDomains(?array $content): bool
    {
        return collect($content['domains'] ?? [])
            ->contains(fn ($domain) => is_array($domain) && filled($domain['label'] ?? null));
    }

    /**
     * @param  array<string, mixed>|null  $content
     */
    private function validateStructuredDomains(?array $content): void
    {
        $errors = [];

        foreach (($content['domains'] ?? []) as $domainIndex => $domain) {
            if (! is_array($domain)) {
                continue;
            }

            if (blank($domain['label'] ?? null)) {
                $errors["content.domains.{$domainIndex}.label"] = 'The domain label field is required.';
            }

            foreach (($domain['strategies'] ?? []) as $strategyIndex => $strategy) {
                if (! is_array($strategy)) {
                    continue;
                }

                if (blank($strategy['text'] ?? null)) {
                    $errors["content.domains.{$domainIndex}.strategies.{$strategyIndex}.text"] = 'The strategy text field is required.';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function ensureMutableCarePlan(CarePlan $carePlan): void
    {
        if (! $carePlan->isMutableVersion()) {
            throw ValidationException::withMessages([
                'care_plan' => 'Only the current working care plan version can be changed.',
            ]);
        }
    }

    private function visibleCarePlans(User $user): Builder
    {
        return $this->applyCarePlanVisibility(CarePlan::query(), $user);
    }

    private function applyCarePlanVisibility(Builder $query, User $user): Builder
    {
        return $query->whereHas('client', fn (Builder $clientQuery) => $this->siteAccess->applyClientScope(
            $clientQuery,
            $user,
            self::CLIENT_SITE_BYPASS_PERMISSIONS,
        ));
    }

    private function visibleClients(User $user): Builder
    {
        return $this->siteAccess->applyClientScope(
            Client::query(),
            $user,
            self::CLIENT_SITE_BYPASS_PERMISSIONS,
        );
    }
}
