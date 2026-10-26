<?php

namespace App\Domain\Referrals\Http\Controllers;

use App\Domain\Referrals\Models\ReferralConfiguration;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\ReferralPermissions;
use App\Domain\Referrals\Services\ReferralAccess;
use App\Domain\Referrals\Services\ReferralAcknowledgementTransport;
use App\Domain\Referrals\Services\ReferralDates;
use App\Domain\Referrals\Services\ReferralIntakeFunding;
use App\Domain\Referrals\Services\ReferralMessageTransport;
use App\Domain\Referrals\Services\ReferralMessaging;
use App\Domain\Referrals\Services\ReferralPresenter;
use App\Domain\Referrals\Services\ReferralRouting;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Domain\Referrals\Services\ReferralSettingsImpact;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class ReferralSettingsController extends Controller
{
    public function __construct(private readonly ReferralSettings $settings, private readonly ReferralAccess $access) {}

    private function authorizeSettings(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor && $this->access->can($actor, 'settings.view'), 403);
        abort_unless(app(UserSiteAccessService::class)->applyStaffScope(User::query()->whereKey($actor->id), $actor, ['referrals.settings.view'])->exists(), 403);

        return $actor;
    }

    /** Names and version numbers for the people and versions a page of history mentions. */
    private function context(Collection $versions): array
    {
        $versions = $versions->filter();
        $people = User::query()->whereIn('id', $versions->flatMap(fn ($v) => [$v->created_by, $v->published_by, $v->discarded_by])->filter()->unique())->pluck('name', 'id')->all();
        $numbers = ReferralSettingsVersion::query()->whereIn('id', $versions->flatMap(fn ($v) => [$v->base_version_id, $v->restored_from_version_id])->filter()->unique())->pluck('version', 'id')->all();

        return [$people, $numbers];
    }

    private function version(ReferralSettingsVersion $version, array $context = [[], []]): array
    {
        [$people, $numbers] = $context;

        return ['id' => $version->id, 'version' => $version->version, 'state' => $version->state, 'base_version_id' => $version->base_version_id,
            'base_version' => $numbers[$version->base_version_id] ?? null,
            'restored_from_version_id' => $version->restored_from_version_id, 'restored_from_version' => $numbers[$version->restored_from_version_id] ?? null,
            'created_by' => $version->created_by, 'created_by_name' => $people[$version->created_by] ?? null,
            'published_by' => $version->published_by, 'published_by_name' => $people[$version->published_by] ?? null,
            'discarded_by_name' => $people[$version->discarded_by] ?? null,
            'reason' => $version->reason, 'publication_note' => $version->publication_note, 'discard_reason' => $version->discard_reason,
            'created_at' => $version->created_at?->toISOString(), 'published_at' => $version->published_at?->toISOString(),
            'effective_at' => $version->effective_at?->toISOString(), 'discarded_at' => $version->discarded_at?->toISOString()];
    }

    public function index(Request $request): Response
    {
        $actor = $this->authorizeSettings($request);
        $input = $request->validate(['version' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1']);
        $latestId = ReferralConfiguration::find(1)?->published_version_id;
        $latest = $latestId ? ReferralSettingsVersion::findOrFail($latestId) : null;
        $selected = isset($input['version']) ? ReferralSettingsVersion::findOrFail($input['version']) : $latest;
        $base = $selected?->base_version_id ? ReferralSettingsVersion::findOrFail($selected->base_version_id)->configuration : ReferralSettings::defaults();
        $effective = $this->settings->effective();
        $history = ReferralSettingsVersion::orderByDesc('version')->paginate(20)->withQueryString();
        $pending = ReferralSettingsVersion::query()->where('state', 'draft')->orderByDesc('version')->limit(5)->get();
        $context = $this->context(collect([$latest, $selected, $effective, ...$history->items(), ...$pending->all()]));
        $grants = Role::query()->orderBy('name')->with(['permissions' => fn ($query) => $query->where('key', 'like', 'referrals.%')])->get()
            ->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->name,
                'keys' => $role->permissions->pluck('key')->map(fn ($key) => substr($key, strlen('referrals.')))->values()->all()]);
        $response = Inertia::render('settings/referrals/index', [
            'settings' => [
                'latest' => $latest ? $this->version($latest, $context) : null,
                'effective' => $effective ? $this->version($effective, $context) : null,
                'selected' => $selected ? [...$this->version($selected, $context), 'configuration' => ReferralSettings::presentable($selected->configuration),
                    'base_configuration' => ReferralSettings::presentable($base), 'governance_required' => $this->settings->requiresGovernance($base, $selected->configuration)] : null,
                'configuration' => ReferralSettings::presentable($latest?->configuration ?? ReferralSettings::defaults()),
                'impact' => $selected ? app(ReferralSettingsImpact::class)->inspect($actor, $selected) : null,
                'history' => $history->through(fn ($version) => $this->version($version, $context)),
                'pending_drafts' => $pending->map(fn ($version) => $this->version($version, $context))->values()->all(),
                'audit' => $this->audit(),
                'roles' => Role::orderBy('name')->get(['id', 'name']),
                'role_grants' => $grants,
                'protected_choices' => ReferralSettings::PROTECTED_CHOICES,
                'built_in_choices' => array_combine(ReferralSettings::CHOICE_LISTS, array_map(fn ($list) => ReferralSettings::builtInChoices($list), ReferralSettings::CHOICE_LISTS)),
                'delivery' => ['email_configured' => app(ReferralAcknowledgementTransport::class)->configured(),
                    // Staff messages: text sending status and the secure link length, for the template editor's length check.
                    'sms_configured' => app(ReferralMessageTransport::class)->smsConfigured(), 'link_length' => strlen(ReferralMessaging::linkBase()) + 64],
                'routing_staff' => app(UserSiteAccessService::class)->applyStaffScope(User::query(), $actor, ['referrals.viewAllSites', 'referrals.centralIntake'])->orderBy('name')->limit(300)->get(['id', 'name']),
                'routing_sites' => Site::query()->whereIn('id', app(UserSiteAccessService::class)->accessibleSiteIds($actor, ['referrals.viewAllSites']))->where('is_active', true)->where('archived', false)->orderBy('name')->get(['id', 'name']),
                'service_types' => array_map(fn ($type) => ['id' => $type->value, 'name' => $type->label()], ServiceType::cases()),
                'permissions' => ReferralPermissions::DEFINITIONS,
                'can_edit_roles' => $actor->canDo('settings.access.manage'),
                'can_manage' => $this->access->can($actor, 'settings.manage'), 'can_publish' => $this->access->can($actor, 'settings.publish'),
                'can_govern' => $this->access->can($actor, 'settings.governance'), 'actor_id' => $actor->id,
            ],
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** The configuration audit trail: who drafted, published, discarded or restored which version. */
    private function audit(): array
    {
        $events = ReferralEvent::query()->where('audience', 'settings')->where('subject_type', 'settings_version')
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(30)->with('actor:id,name')->get();

        return $events->map(fn (ReferralEvent $event) => ['id' => $event->id, 'kind' => $event->kind, 'version' => $event->payload['version'] ?? null,
            'restored_from_version' => $event->payload['restored_from_version'] ?? null, 'actor_name' => $event->actor?->name,
            'occurred_at' => $event->occurred_at?->toISOString()])->values()->all();
    }

    public function draft(Request $request): JsonResponse
    {
        $this->authorizeSettings($request);
        abort_if(strlen($request->getContent()) > 131072, 413);
        $input = $request->validate(['configuration' => 'required|array', 'base_version_id' => 'present|nullable|integer|min:1', 'reason' => 'required|string|min:5|max:2000', 'request_key' => 'required|uuid']);
        $version = $this->settings->draft($request->user(), $input['configuration'], $input['base_version_id'], $input['reason'], $input['request_key']);

        return response()->json(['version' => $this->version($version)], 201)->header('Cache-Control', 'no-store, private');
    }

    /** Validate a working configuration and show what it would change before anything is saved. */
    public function preview(Request $request): JsonResponse
    {
        $actor = $this->authorizeSettings($request);
        abort_if(strlen($request->getContent()) > 131072, 413);
        $input = $request->validate(['configuration' => 'required|array']);
        $configuration = $this->settings->validate($input['configuration']);
        $latestId = ReferralConfiguration::find(1)?->published_version_id;
        $base = $latestId ? ReferralSettingsVersion::findOrFail($latestId)->configuration : ReferralSettings::defaults();

        return response()->json(['governance_required' => $this->settings->requiresGovernance($base, $configuration),
            'impact' => app(ReferralSettingsImpact::class)->inspectConfiguration($actor, $configuration, $base)])->header('Cache-Control', 'no-store, private');
    }

    public function routingPreview(Request $request): JsonResponse
    {
        $this->authorizeSettings($request);
        abort_if(strlen($request->getContent()) > 131072, 413);
        $input = $request->validate(['configuration' => 'required|array', 'service' => 'required|string|max:80', 'region' => 'present|nullable|string|max:120',
            'funding_situation' => ['sometimes', 'nullable', Rule::in(ReferralIntakeFunding::SITUATIONS)],
            'funding_pathway_id' => 'sometimes|nullable|string|max:64']);
        $configuration = $this->settings->validate($input['configuration']);
        $pathway = ReferralIntakeFunding::snapshot($configuration, [...$input, 'authority_status' => 'self', 'purpose' => 'support', 'requested_service' => $input['service']]);

        return response()->json(['result' => app(ReferralRouting::class)->choose($configuration, $input['service'], $input['region'], fundingSituation: $input['funding_situation'] ?? null, pathwayId: $pathway['id'] ?? null), 'checked_at' => now()->toISOString()])->header('Cache-Control', 'no-store, private');
    }

    public function publish(Request $request, int $version): JsonResponse
    {
        $this->authorizeSettings($request);
        $input = $request->validate(['effective_at' => 'required|date', 'acknowledge_impact' => 'required|accepted', 'publication_note' => 'required|string|min:5|max:2000']);
        $result = $this->settings->publish($request->user(), $version, ReferralDates::parse($input['effective_at'], 'effective_at')->toISOString(), $input['publication_note']);

        return response()->json(['version' => $this->version($result)])->header('Cache-Control', 'no-store, private');
    }

    public function discard(Request $request, int $version): JsonResponse
    {
        $this->authorizeSettings($request);
        $input = $request->validate(['reason' => 'required|string|min:5|max:2000']);

        return response()->json(['version' => $this->version($this->settings->discard($request->user(), $version, $input['reason']))])->header('Cache-Control', 'no-store, private');
    }

    public function restore(Request $request, int $version): JsonResponse
    {
        $this->authorizeSettings($request);
        $input = $request->validate(['reason' => 'required|string|min:5|max:2000', 'request_key' => 'required|uuid']);
        $draft = $this->settings->restore($request->user(), $version, $input['reason'], $input['request_key']);

        return response()->json(['version' => $this->version($draft)], 201)->header('Cache-Control', 'no-store, private');
    }

    /** Open referrals still on an earlier version. Each one is migrated from its own profile, with its own review. */
    public function migrationCandidates(Request $request): JsonResponse
    {
        $actor = $this->authorizeSettings($request);
        $effective = $this->settings->effective();
        $allowed = $effective !== null && collect(['settings.governance', 'edit', 'assessment.manage', 'funding.manage'])->every(fn ($action) => $this->access->can($actor, $action));
        $query = $this->access->query($actor)->whereNotIn('stage', ['closed', 'converted'])->when($effective, fn ($q) => $q->where('settings_version_id', '!=', $effective->id));
        $total = $effective ? (clone $query)->count() : 0;
        $rows = $allowed ? (clone $query)->with(['site:id,name', 'owner:id,name'])->orderBy('created_at')->limit(50)->get() : collect();
        $numbers = ReferralSettingsVersion::query()->whereIn('id', $rows->pluck('settings_version_id')->unique())->pluck('version', 'id');
        $presenter = app(ReferralPresenter::class);

        return response()->json([
            'can_migrate' => $allowed, 'total' => $total, 'target_version' => $effective?->version,
            'data' => $rows->map(fn ($referral) => [...array_intersect_key($presenter->summary($referral), array_flip(['reference', 'name', 'stage', 'site_name', 'owner_name'])),
                'settings_version' => $numbers[$referral->settings_version_id] ?? null])->values()->all(),
        ])->header('Cache-Control', 'no-store, private');
    }
}
