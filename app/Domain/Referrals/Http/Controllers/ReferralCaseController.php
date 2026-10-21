<?php

namespace App\Domain\Referrals\Http\Controllers;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Services\ReferralAccess;
use App\Domain\Referrals\Services\ReferralAssessment;
use App\Domain\Referrals\Services\ReferralCasework;
use App\Domain\Referrals\Services\ReferralDates;
use App\Domain\Referrals\Services\ReferralDuplicateClosure;
use App\Domain\Referrals\Services\ReferralForms;
use App\Domain\Referrals\Services\ReferralIntakeCorrection;
use App\Domain\Referrals\Services\ReferralIntakeDrafts;
use App\Domain\Referrals\Services\ReferralIntakeFunding;
use App\Domain\Referrals\Services\ReferralLifecycle;
use App\Domain\Referrals\Services\ReferralOnboardingOwners;
use App\Domain\Referrals\Services\ReferralPresenter;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Domain\Referrals\Services\ReferralWorkQueue;
use App\Domain\Referrals\Services\ReferralWorkspace;
use App\Domain\Referrals\Services\StaffReferralIntake;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class ReferralCaseController extends Controller
{
    public function __construct(private readonly ReferralAccess $access, private readonly ReferralPresenter $presenter, private readonly ReferralCasework $casework) {}

    public function workspace(Request $request, ReferralWorkspace $workspace): Response
    {
        $actor = $request->user();
        $filters = $workspace->filters($request->query());
        $focus = $filters['focus'] ?? null;
        // Props resolve lazily so a partial reload (e.g. only the focused referral) runs only what it asks for.
        $memo = null;
        $payload = function () use (&$memo, $request, $workspace): array {
            return $memo ??= $this->workspacePayload($request, $workspace);
        };
        $props = [];
        foreach (['view', 'referrals', 'pipeline', 'funding', 'followUps', 'followUpGroups', 'stats', 'counts', 'savedViews', 'permissions'] as $key) {
            $props[$key] = fn () => $payload()[$key];
        }

        return Inertia::render('operations/referrals/index', [...$props,
            'filters' => $filters,
            'options' => fn () => [...$this->options($request), ...$workspace->options($actor)],
            'submissionToken' => (string) Str::uuid(),
            'drafts' => fn () => app(ReferralIntakeDrafts::class)->list($actor),
            // Assign owner / Log contact open the profile's own dialog in place; the record
            // goes through the same scope check and presenter as the profile (foreign = 404).
            'referral' => fn () => $focus ? $this->presenter->detail($actor, $this->record($request, $focus)) : null,
        ])->toResponse($request)->header('Cache-Control', 'no-store, private');
    }

    public function profile(Request $request, string $reference): Response
    {
        $data = $this->show($request, $reference)->getData(true);
        $input = $request->validate(['work' => 'nullable|integer|min:1', 'tab' => ['nullable', Rule::in(['summary', 'assessment', 'people-contacts', 'work', 'funding', 'proposals', 'handover', 'service-start', 'documents', 'communications', 'activity', 'configuration'])], 'plan_at' => 'nullable|date']);
        $actor = $request->user();
        // Each linkable tab keeps the same permission gate as the profile shows it with.
        abort_unless(match ($input['tab'] ?? 'summary') {
            'assessment', 'people-contacts' => $this->access->can($actor, 'assessment.view'),
            'funding', 'proposals' => $this->access->can($actor, 'funding.view'),
            'handover' => $this->access->can($actor, 'convert') && $this->access->can($actor, 'assessment.view') && $this->access->can($actor, 'funding.view'),
            'documents' => $this->access->can($actor, 'assessment.view') || $this->access->can($actor, 'funding.view'),
            'configuration' => $this->access->can($actor, 'settings.governance') && $this->access->can($actor, 'settings.view') && $this->access->can($actor, 'edit')
                && $this->access->can($actor, 'assessment.manage') && $this->access->can($actor, 'funding.manage'),
            default => true,
        }, 403);
        if (($input['tab'] ?? null) === 'service-start') {
            abort_unless($this->access->can($request->user(), 'commence') && $this->access->can($request->user(), 'assessment.view')
                && $this->access->can($request->user(), 'funding.view') && $request->user()->canDo('care_plans.viewAny')
                && $request->user()->canDo('service_agreements.viewAny'), 403);
        }
        $selected = isset($input['work']) ? app(ReferralWorkQueue::class)->work($request->user())->whereHas('referral', fn ($q) => $q->where('reference', $reference))->with(['owner:id,name', 'fundingCase'])->findOrFail($input['work']) : null;
        if (isset($input['plan_at'])) {
            abort_unless($this->access->can($request->user(), 'tasks.manage') && $data['referral']['stage'] !== 'closed', 403);
        }

        return Inertia::render('operations/referrals/show', [...$data, 'options' => $this->options($request),
            'selectedWork' => $selected ? $this->presenter->work($selected) : null,
            'initialTab' => $selected || isset($input['plan_at']) ? 'work' : ($input['tab'] ?? 'summary'),
            'initialPlanAt' => isset($input['plan_at']) ? ReferralDates::parse($input['plan_at'], 'plan_at')->toISOString() : null,
        ])->toResponse($request)->header('Cache-Control', 'no-store, private');
    }

    public function index(Request $request, ReferralWorkspace $workspace): JsonResponse
    {
        return $this->json($this->workspacePayload($request, $workspace));
    }

    /** One payload for the workspace page and its JSON twin: only the active view's rows are built. */
    private function workspacePayload(Request $request, ReferralWorkspace $workspace): array
    {
        $actor = $request->user();
        abort_unless($this->access->can($actor, 'viewAny') || $this->access->can($actor, 'viewAssigned'), 403);
        $filters = $workspace->filters($request->query());
        if ($filters['view'] === 'funding') {
            abort_unless($this->access->can($actor, 'funding.view'), 403);
        }
        $stats = $workspace->stats($actor);
        $empty = ['data' => [], 'total' => 0, 'current_page' => 1, 'last_page' => 1, 'links' => []];
        $followUps = $filters['view'] === 'follow_ups' ? $workspace->followUpPage($actor, $filters) : null;

        return [
            'view' => $filters['view'],
            'referrals' => $filters['view'] === 'referrals' && $filters['layout'] !== 'pipeline' ? $workspace->referralPage($actor, $filters) : $empty,
            'pipeline' => $filters['view'] === 'referrals' && $filters['layout'] === 'pipeline' ? $workspace->pipeline($actor, $filters, $this->stages()) : [],
            'funding' => $filters['view'] === 'funding' ? $workspace->fundingPage($actor, $filters) : $empty,
            'followUps' => $followUps['page'] ?? $empty,
            'followUpGroups' => $followUps['groups'] ?? null,
            'stats' => $stats,
            'counts' => $workspace->railCounts($actor, $stats),
            'savedViews' => $workspace->savedViews($actor, $stats),
            'filters' => $filters,
            'permissions' => $this->access->permissions($actor),
        ];
    }

    /** Configured stage names win over stable keys ("Funding help" rather than "Funding assistance"). */
    private function stages(): array
    {
        $configured = array_column(app(ReferralSettings::class)->effective()?->configuration['stages'] ?? [], 'label', 'id');

        return array_map(fn ($stage) => ['id' => $stage, 'label' => $configured[$stage] ?? ucfirst(str_replace('_', ' ', $stage))], ReferralSettings::STAGES);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $record = $this->record($request, $reference);
        $pages = $request->validate(['work_page' => 'nullable|integer|min:1', 'activity_page' => 'nullable|integer|min:1', 'funding_page' => 'nullable|integer|min:1']);

        return $this->json(['referral' => $this->presenter->detail($request->user(), $record, (int) ($pages['work_page'] ?? 1), (int) ($pages['activity_page'] ?? 1), (int) ($pages['funding_page'] ?? 1)), 'permissions' => $this->access->permissions($request->user())]);
    }

    public function duplicatePreview(Request $request, string $reference, ReferralDuplicateClosure $duplicates): JsonResponse
    {
        $record = $this->record($request, $reference);
        $input = $request->validate(['original_reference' => 'required|ulid']);

        return $this->json(['original' => $duplicates->preview($request->user(), $record, $input['original_reference'])]);
    }

    public function store(Request $request, StaffReferralIntake $intake): JsonResponse
    {
        $record = $intake->create($request->user(), $request->all());
        // The submitted call no longer needs its resumable draft.
        app(ReferralIntakeDrafts::class)->consumed($request->user(), is_string($request->input('draft_reference')) ? $request->input('draft_reference') : null);

        return $this->json(['saved' => true, 'reference' => $record->reference]);
    }

    public function assign(Request $request, string $reference): JsonResponse
    {
        $record = $this->record($request, $reference);
        $this->casework->assign($request->user(), $record->id, $this->version($request), $request->all());

        return $this->json(['saved' => true, 'can_view' => $this->access->query($request->user()->fresh())->whereKey($record->id)->exists()]);
    }

    public function contact(Request $request, string $reference): JsonResponse
    {
        $record = $this->record($request, $reference);
        $this->casework->contact($request->user(), $record->id, $this->version($request), $request->all());

        return $this->json(['saved' => true]);
    }

    public function nextAction(Request $request, string $reference): JsonResponse
    {
        $record = $this->record($request, $reference);
        $this->casework->nextAction($request->user(), $record->id, $this->version($request), $request->all());

        return $this->json(['saved' => true]);
    }

    public function saveWork(Request $request, string $reference, ?int $work = null): JsonResponse
    {
        $record = $this->record($request, $reference);
        $item = $this->casework->saveWork($request->user(), $record->id, $this->version($request), $request->all(), $work);

        return $this->json(['saved' => true, 'work' => $this->presenter->work($item->fresh())]);
    }

    public function finishWork(Request $request, string $reference, int $work): JsonResponse
    {
        $record = $this->record($request, $reference);
        $this->casework->finishWork($request->user(), $record->id, $this->version($request), $work, $request->all());

        return $this->json(['saved' => true]);
    }

    public function lifecycle(Request $request, string $reference, string $operation, ReferralLifecycle $lifecycle): JsonResponse
    {
        $record = $this->record($request, $reference);
        $method = match ($operation) {
            'transition' => 'move', 'hold' => 'hold', 'close' => 'close', 'reopen' => 'reopen', 'priority' => 'priority',
            default => abort(404),
        };
        $lifecycle->{$method}($request->user(), $record->id, $this->version($request), $request->all());

        return $this->json(['saved' => true]);
    }

    public function review(Request $request, string $reference, string $review, ReferralAssessment $assessment): JsonResponse
    {
        $record = $this->record($request, $reference);
        $method = match ($review) {
            'authority' => 'authority', 'assessment' => 'assess', default => abort(404)
        };
        $assessment->{$method}($request->user(), $record->id, $this->version($request), $request->all());

        return $this->json(['saved' => true]);
    }

    public function correct(Request $request, string $reference, ReferralIntakeCorrection $correction): JsonResponse
    {
        $record = $this->record($request, $reference);
        $correction->save($request->user(), $record->id, $this->version($request), $request->all());

        return $this->json(['saved' => true]);
    }

    private function record(Request $request, string $reference): CareReferral
    {
        return $this->access->query($request->user())->where('reference', $reference)->firstOrFail();
    }

    public function staffOptions(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($this->access->can($actor, 'assign') || $this->access->can($actor, 'tasks.manage') || $this->access->can($actor, 'funding.manage') || $this->access->can($actor, 'convert'), 403);
        $input = $request->validate(['reference' => 'required|ulid', 'site_id' => 'present|nullable|integer|min:1', 'q' => 'nullable|string|max:80', 'purpose' => ['required', Rule::in(['owner', 'work', 'funding', 'onboarding'])], 'audience' => ['nullable', Rule::in(['intake', 'assessment', 'funding'])], 'onboarding_target_id' => 'nullable|integer|min:1']);
        abort_unless($this->access->can($actor, match ($input['purpose']) {
            'owner' => 'assign', 'work' => 'tasks.manage', 'funding' => 'funding.manage', 'onboarding' => 'convert'
        }), 403);
        $referral = $this->record($request, $input['reference']);
        $siteId = $input['site_id'] === null ? null : (int) $input['site_id'];
        abort_unless($this->access->site($actor, $siteId), 403);
        $target = null;
        if ($input['purpose'] === 'onboarding' && ! empty($input['onboarding_target_id'])) {
            $target = Client::query()->findOrFail($input['onboarding_target_id']);
            abort_unless($actor->can('view', $target), 404);
            abort_unless($actor->can('update', $target), 403);
        }
        $query = app(UserSiteAccessService::class)->applyStaffScope(User::query(), $actor, ['referrals.viewAllSites', 'referrals.centralIntake']);
        if (! empty($input['q'])) {
            $query->where('name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $input['q']).'%');
        }
        $matches = $query->orderBy('name')->limit(100)->get()->filter(function (User $candidate) use ($siteId, $referral, $input, $target): bool {
            if ($input['purpose'] === 'onboarding') {
                return app(ReferralOnboardingOwners::class)->eligible($candidate, $siteId, $referral->restricted, $target);
            }

            return $this->access->eligibleForSite($candidate, $siteId)
                && (! $referral->restricted || $this->access->can($candidate, 'restricted'))
                && $this->access->audience($candidate, $input['audience'] ?? 'intake')
                && ($input['purpose'] !== 'funding' || $this->access->can($candidate, 'funding.manage'))
                && ($input['purpose'] !== 'work' || $this->access->query($candidate)->whereKey($referral->id)->exists());
        })->take(30)->map(fn (User $candidate) => ['id' => $candidate->id, 'name' => $candidate->name])->values();

        return $this->json(['staff' => $matches, 'hint' => 'Search by name to narrow eligible staff. Eligibility is checked again when you save.']);
    }

    private function options(Request $request): array
    {
        $actor = $request->user();
        $ids = app(UserSiteAccessService::class)->accessibleSiteIds($actor, ['referrals.viewAllSites']);
        $version = app(ReferralSettings::class)->effective();
        $configuration = $version?->configuration;

        return [
            'sites' => Site::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])->toArray(),
            'stages' => $this->stages(),
            'services' => array_values(array_filter($configuration['services'] ?? [], fn ($service) => $service['enabled'])),
            'pathways' => $this->access->can($actor, 'funding.view') ? array_values(array_filter($configuration['pathways'] ?? [], fn ($pathway) => $pathway['enabled'])) : [],
            'intake' => $version && $this->access->can($actor, 'create') ? [
                'settings_version_id' => $version->id, 'notice_version' => $configuration['public']['notice_version'], 'notice' => $configuration['public']['notice'],
                'fields' => app(ReferralForms::class)->publicFields($configuration),
                'pathways' => ReferralIntakeFunding::options($configuration),
                'services' => array_values(array_filter($configuration['services'], fn ($service) => $service['enabled'])),
            ] : null,
        ];
    }

    private function version(Request $request): int
    {
        return (int) $request->validate(['version' => 'required|integer|min:1'])['version'];
    }

    private function json(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }
}
