<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetShiftHandover;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserSiteAccessService;
use App\Support\LegacyStorageContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class HandoverController extends Controller
{
    protected const BYPASS_PERMISSIONS = ['fleet.manage'];

    public function index(Request $request)
    {
        $auth = $request->user();
        $siteAccess = app(UserSiteAccessService::class);

        if (! Schema::hasTable('fleet_shift_handovers')) {
            $vehicleQuery = Asset::query()->where('category', 'vehicle')->orderBy('name');
            $this->applyAssetSiteScope($vehicleQuery, $auth, $siteAccess);

            $vehicles = $vehicleQuery
                ->get(['id', 'name'])
                ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])
                ->values();

            return Inertia::render('fleet-assets/handovers/index', [
                'handovers' => collect(),
                'vehicles' => $vehicles,
                'filters' => $request->only(['vehicle_id', 'status', 'date_from', 'date_to']),
                'stats' => [
                    'total' => 0,
                    'pending' => 0,
                    'disputed' => 0,
                    'completed_7d' => 0,
                ],
                'wizard' => $request->boolean('new') ? $this->wizardPayload($auth, $siteAccess) : null,
                'can' => [
                    'manage' => (bool) $auth?->canDo('fleet.manage'),
                ],
            ]);
        }

        $query = FleetShiftHandover::query()
            ->with([
                'asset' => fn ($assetQuery) => $assetQuery->select($this->handoverAssetColumns()),
                'outgoingUser:id,name',
                'incomingUser:id,name',
            ]);

        $siteAccess->applyFleetHandoverScope($query, $auth, self::BYPASS_PERMISSIONS);

        if ($request->filled('vehicle_id')) {
            $query->where('asset_id', $request->input('vehicle_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('handed_over_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('handed_over_at', '<=', $request->input('date_to'));
        }

        $paginator = $query
            ->latest('handed_over_at')
            ->paginate(25)
            ->withQueryString();

        $handovers = [
            'data' => $paginator->getCollection()->map(fn ($h) => [
                'id' => $h->id,
                'asset' => $h->asset ? [
                    'id' => $h->asset->id,
                    'name' => $h->asset->name,
                    'registration_number' => $h->asset->registration_number,
                ] : null,
                'outgoing_user' => $h->outgoingUser ? ['id' => $h->outgoingUser->id, 'name' => $h->outgoingUser->name] : null,
                'incoming_user' => $h->incomingUser ? ['id' => $h->incomingUser->id, 'name' => $h->incomingUser->name] : null,
                'odometer_km' => $h->odometer_km,
                'fuel_level' => $h->fuel_level,
                'exterior_condition' => $h->exterior_condition,
                'interior_condition' => $h->interior_condition,
                'status' => $h->status,
                'handed_over_at' => optional($h->handed_over_at)->toISOString(),
                'accepted_at' => optional($h->accepted_at)->toISOString(),
            ])->values(),
            'links' => $paginator->linkCollection()->toArray(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ];

        $vehicleQuery = Asset::query()->where('category', 'vehicle')->orderBy('name');
        $this->applyAssetSiteScope($vehicleQuery, $auth, $siteAccess);

        $vehicles = $vehicleQuery
            ->get(['id', 'name'])
            ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])
            ->values();

        // Hero stats — same site scope as the list.
        $statsBase = FleetShiftHandover::query();
        $siteAccess->applyFleetHandoverScope($statsBase, $auth, self::BYPASS_PERMISSIONS);

        $stats = [
            'total' => (clone $statsBase)->count(),
            'pending' => (clone $statsBase)->where('status', 'pending_acceptance')->count(),
            'disputed' => (clone $statsBase)->where('status', 'disputed')->count(),
            'completed_7d' => (clone $statsBase)
                ->where('status', 'accepted')
                ->where('accepted_at', '>=', now()->subDays(7))
                ->count(),
        ];

        return Inertia::render('fleet-assets/handovers/index', [
            'handovers' => $handovers,
            'vehicles' => $vehicles,
            'filters' => $request->only(['vehicle_id', 'status', 'date_from', 'date_to']),
            'stats' => $stats,
            // New-handover wizard payload (retired /handovers/create page) —
            // only when opened via ?new=1.
            'wizard' => $request->boolean('new') ? $this->wizardPayload($auth, $siteAccess) : null,
            'can' => [
                'manage' => (bool) $auth?->canDo('fleet.manage'),
            ],
        ]);
    }

    /**
     * The standalone create page is retired — deep links reopen the wizard on
     * the index via ?new=1.
     */
    public function create(Request $request)
    {
        return redirect()->route(
            'fleet-assets.handovers.index',
            array_merge($request->query(), ['new' => 1]),
        );
    }

    /**
     * Options for the new-handover wizard modal (ported from the retired
     * create page).
     */
    protected function wizardPayload(User $auth, UserSiteAccessService $siteAccess): array
    {
        $vehicleQuery = Asset::query()->where('category', 'vehicle')->orderBy('name');
        $this->applyAssetSiteScope($vehicleQuery, $auth, $siteAccess);

        $vehicles = $vehicleQuery
            ->get($this->vehicleOptionColumns())
            ->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'registration_number' => $a->registration_number,
            ])
            ->values();

        $userQuery = User::query()
            ->staff()
            ->whereNotNull('approved_at')
            ->whereHas('hrEmployeeProfile', fn (Builder $profileQuery) => $profileQuery->where('is_active', true))
            ->orderBy('name');
        $siteAccess->applyStaffScope($userQuery, $auth, self::BYPASS_PERMISSIONS);

        $users = $userQuery
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->values();

        return [
            'vehicles' => $vehicles,
            'users' => $users,
            'current_user_id' => $auth->id,
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'incoming_user_id' => ['required', 'integer', 'exists:users,id'],
            'odometer_km' => ['nullable', 'integer', 'min:0'],
            'fuel_level' => ['nullable', 'string', 'in:full,3/4,1/2,1/4,empty'],
            'exterior_condition' => ['required', 'string', 'in:good,minor_damage,significant_damage'],
            'interior_condition' => ['required', 'string', 'in:clean,acceptable,needs_cleaning'],
            'keys_present' => ['boolean'],
            'documents_present' => ['boolean'],
            'first_aid_kit' => ['boolean'],
            'fire_extinguisher' => ['boolean'],
            'damage_notes' => ['nullable', 'array'],
            'damage_notes.*.area' => ['required_with:damage_notes', 'string'],
            'damage_notes.*.description' => ['required_with:damage_notes', 'string'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $handover = DB::transaction(function () use ($request, $data): FleetShiftHandover {
            $siteAccess = app(UserSiteAccessService::class);
            $actor = User::query()
                ->whereKey($request->user()->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($actor->canDo('fleet.manage'), 403);

            $assetQuery = Asset::query()
                ->whereKey($data['asset_id'])
                ->where('category', 'vehicle');
            $this->applyAssetSiteScope($assetQuery, $actor, $siteAccess);
            $asset = $assetQuery->lockForUpdate()->first();
            abort_unless(
                $asset,
                403,
                'You are not authorized to create handovers for vehicles at this site.',
            );

            $siteId = $asset->site_id ?: $asset->home_site_id;
            $site = $siteId
                ? Site::query()->active()->notArchived()->whereKey($siteId)->lockForUpdate()->first()
                : null;
            abort_unless(
                $site,
                403,
                'You are not authorized to create handovers for vehicles at this site.',
            );
            $siteAccess->assertCanAccessSiteId(
                $actor,
                (int) $site->id,
                self::BYPASS_PERMISSIONS,
                'You are not authorized to create handovers for vehicles at this site.',
            );
            $outgoingQuery = User::query()->whereKey($actor->id);
            $siteAccess->applyFleetRecipientEligibility($outgoingQuery, (int) $site->id);
            abort_unless(
                $outgoingQuery->lockForUpdate()->exists(),
                403,
                'You must be current staff assigned to this Site to hand over its vehicle.',
            );

            $incomingUserId = (int) $data['incoming_user_id'];
            $incomingQuery = User::query()->whereKey($incomingUserId);
            $siteAccess->applyFleetRecipientEligibility(
                $incomingQuery,
                (int) $site->id,
            );
            $incoming = $incomingQuery->lockForUpdate()->first();
            abort_unless(
                $incoming,
                403,
                'You are not authorized to hand this vehicle over to that user.',
            );

            $handover = FleetShiftHandover::query()->create([
                ...LegacyStorageContext::attributes(),
                'asset_id' => $asset->id,
                'outgoing_user_id' => $actor->id,
                'incoming_user_id' => $incomingUserId,
                'odometer_km' => $data['odometer_km'] ?? null,
                'fuel_level' => $data['fuel_level'] ?? null,
                'exterior_condition' => $data['exterior_condition'],
                'interior_condition' => $data['interior_condition'],
                'keys_present' => $data['keys_present'] ?? true,
                'documents_present' => $data['documents_present'] ?? true,
                'first_aid_kit' => $data['first_aid_kit'] ?? true,
                'fire_extinguisher' => $data['fire_extinguisher'] ?? true,
                'damage_notes' => $data['damage_notes'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'pending_acceptance',
                'handed_over_at' => now(),
            ]);

            AuditLogger::logOrFail('fleet.handover.create', $handover, [
                'actor_id' => $actor->id,
                'asset_id' => $asset->id,
                'outgoing_user_id' => $actor->id,
                'incoming_user_id' => $incomingUserId,
            ]);

            return $handover;
        }, 3);

        return redirect()
            ->route('fleet-assets.handovers.show', $handover)
            ->with('success', 'Shift handover created successfully.');
    }

    public function show(FleetShiftHandover $handover)
    {
        $this->assertCanAccessSpecificHandover(
            request()->user(),
            $handover,
            'You are not authorized to view handovers for vehicles at this site.',
        );

        $handover->load([
            'asset' => fn ($assetQuery) => $assetQuery->select($this->vehicleOptionColumns()),
            'outgoingUser:id,name',
            'incomingUser:id,name',
        ]);

        return Inertia::render('fleet-assets/handovers/show', [
            'handover' => [
                'id' => $handover->id,
                'asset' => $handover->asset ? [
                    'id' => $handover->asset->id,
                    'name' => $handover->asset->name,
                    'registration_number' => $handover->asset->registration_number,
                ] : null,
                'outgoing_user' => $handover->outgoingUser ? [
                    'id' => $handover->outgoingUser->id,
                    'name' => $handover->outgoingUser->name,
                ] : null,
                'incoming_user' => $handover->incomingUser ? [
                    'id' => $handover->incomingUser->id,
                    'name' => $handover->incomingUser->name,
                ] : null,
                'odometer_km' => $handover->odometer_km,
                'fuel_level' => $handover->fuel_level,
                'exterior_condition' => $handover->exterior_condition,
                'interior_condition' => $handover->interior_condition,
                'keys_present' => $handover->keys_present,
                'documents_present' => $handover->documents_present,
                'first_aid_kit' => $handover->first_aid_kit,
                'fire_extinguisher' => $handover->fire_extinguisher,
                'damage_notes' => $handover->damage_notes,
                'notes' => $handover->notes,
                'status' => $handover->status,
                'handed_over_at' => optional($handover->handed_over_at)->toISOString(),
                'accepted_at' => optional($handover->accepted_at)->toISOString(),
                'created_at' => optional($handover->created_at)->toISOString(),
            ],
            'current_user_id' => request()->user()->id,
        ]);
    }

    public function accept(Request $request, FleetShiftHandover $handover)
    {
        $accepted = DB::transaction(function () use ($request, $handover): bool {
            [$lockedHandover, $actor] = $this->lockedTransitionContext(
                $request,
                $handover,
                'You are not authorized to accept handovers for vehicles at this site.',
            );

            if ($lockedHandover->status !== 'pending_acceptance') {
                return false;
            }

            $lockedHandover->update([
                'status' => 'accepted',
                'accepted_at' => now(),
            ]);

            AuditLogger::logOrFail('fleet.handover.accept', $lockedHandover, [
                'actor_id' => $actor->id,
                'accepted_by' => $actor->id,
            ]);

            return true;
        }, 3);

        if (! $accepted) {
            return back()->with('error', 'This handover has already been processed.');
        }

        return back()->with('success', 'Handover accepted.');
    }

    public function dispute(Request $request, FleetShiftHandover $handover)
    {
        $data = $request->validate([
            'dispute_reason' => ['required', 'string', 'max:2000'],
        ]);

        $disputed = DB::transaction(function () use ($request, $handover, $data): bool {
            [$lockedHandover, $actor] = $this->lockedTransitionContext(
                $request,
                $handover,
                'You are not authorized to dispute handovers for vehicles at this site.',
            );

            if ($lockedHandover->status !== 'pending_acceptance') {
                return false;
            }

            $lockedHandover->update([
                'status' => 'disputed',
                'notes' => ($lockedHandover->notes ? $lockedHandover->notes."\n\n" : '')
                    .'DISPUTE: '.$data['dispute_reason'],
            ]);

            AuditLogger::logOrFail('fleet.handover.dispute', $lockedHandover, [
                'actor_id' => $actor->id,
                'disputed_by' => $actor->id,
                'reason' => $data['dispute_reason'],
            ]);

            return true;
        }, 3);

        if (! $disputed) {
            return back()->with('error', 'This handover has already been processed.');
        }

        return back()->with('success', 'Handover disputed. Management has been notified.');
    }

    /**
     * @return array{FleetShiftHandover, User}
     */
    private function lockedTransitionContext(
        Request $request,
        FleetShiftHandover $handover,
        string $message,
    ): array {
        $actor = User::query()
            ->whereKey($request->user()->id)
            ->lockForUpdate()
            ->firstOrFail();
        $lockedHandover = FleetShiftHandover::query()
            ->whereKey($handover->id)
            ->lockForUpdate()
            ->firstOrFail();
        $asset = Asset::query()
            ->whereKey($lockedHandover->asset_id)
            ->lockForUpdate()
            ->first();
        $siteId = $asset?->site_id ?: $asset?->home_site_id;
        $site = $siteId
            ? Site::query()->active()->notArchived()->whereKey($siteId)->lockForUpdate()->first()
            : null;
        $outgoing = User::query()
            ->whereKey($lockedHandover->outgoing_user_id)
            ->lockForUpdate()
            ->first();
        $incoming = User::query()
            ->whereKey($lockedHandover->incoming_user_id)
            ->lockForUpdate()
            ->first();
        abort_unless(
            $asset
                && $site
                && $outgoing
                && $incoming,
            403,
            $message,
        );

        $siteAccess = app(UserSiteAccessService::class);
        foreach ([$outgoing->id, $incoming->id] as $participantId) {
            $eligibility = User::query()->whereKey($participantId);
            $siteAccess->applyFleetRecipientEligibility($eligibility, (int) $site->id);
            abort_unless($eligibility->exists(), 403, $message);
        }

        $lockedHandover->setRelation('asset', $asset);
        $lockedHandover->setRelation('outgoingUser', $outgoing);
        $lockedHandover->setRelation('incomingUser', $incoming);
        $this->assertCanAccessSpecificHandover($actor, $lockedHandover, $message);
        abort_unless(
            (int) $lockedHandover->incoming_user_id === (int) $actor->id,
            403,
            'Only the incoming user can process this handover.',
        );

        return [$lockedHandover, $actor];
    }

    protected function applyAssetSiteScope(
        Builder $query,
        User $user,
        UserSiteAccessService $siteAccess,
    ): void {
        $siteIds = $siteAccess->accessibleSiteIds($user, self::BYPASS_PERMISSIONS);
        if ($siteIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($nested) use ($siteIds) {
            $nested->whereIn('site_id', $siteIds)
                ->orWhere(function ($homeSite) use ($siteIds) {
                    $homeSite->whereNull('site_id')
                        ->whereIn('home_site_id', $siteIds);
                });
        });
    }

    protected function assertCanAccessSpecificHandover(
        User $user,
        FleetShiftHandover $handover,
        string $message,
    ): void {
        app(UserSiteAccessService::class)->assertCanAccessFleetHandover(
            $user,
            $handover,
            self::BYPASS_PERMISSIONS,
            $message,
        );
    }

    /**
     * @return array<int, string>
     */
    protected function vehicleOptionColumns(): array
    {
        $columns = ['id', 'name'];

        if (Schema::hasColumn('assets', 'registration_number')) {
            $columns[] = 'registration_number';
        }

        return $columns;
    }

    /**
     * @return array<int, string>
     */
    protected function handoverAssetColumns(): array
    {
        return array_merge(
            $this->vehicleOptionColumns(),
            ['site_id', 'home_site_id'],
        );
    }
}
