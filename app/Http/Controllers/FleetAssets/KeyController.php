<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetKeyLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class KeyController extends Controller
{
    private const SITE_BYPASS_PERMISSIONS = ['fleet.manage'];

    private const ACTIONS = ['checked_out', 'returned', 'transferred'];

    /** @var array<string, bool> */
    private array $staffEligibilityCache = [];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user() ?? abort(403);
        $tableExists = Schema::hasTable('fleet_key_logs');

        $vehicleQuery = $this->visibleVehicleQuery($user)
            ->with('client:id,site_id');
        if ($tableExists) {
            $vehicleQuery->with([
                'latestKeyLog.user:id,name',
                'latestKeyLog.transferredToUser:id,name',
            ]);
        }

        $visibleVehicles = $vehicleQuery
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $currentHolders = $visibleVehicles
            ->map(function (Asset $vehicle) use ($tableExists): array {
                $latestLog = $tableExists ? $vehicle->latestKeyLog : null;
                $log = $latestLog && $this->canUseCurrentCustody($vehicle, $latestLog)
                    ? $latestLog
                    : null;
                $holder = null;
                $since = null;
                $location = null;
                $keyNumber = null;

                if ($log) {
                    if ($log->action === 'checked_out') {
                        $holder = $log->user;
                        $since = $log->created_at;
                    } elseif ($log->action === 'transferred') {
                        $holder = $log->transferredToUser;
                        $since = $log->created_at;
                    }
                    $location = $log->location;
                    $keyNumber = $log->key_number;
                }

                return [
                    'vehicle_id' => $vehicle->id,
                    'vehicle_name' => $vehicle->name,
                    'asset_tag' => $vehicle->asset_tag,
                    'holder_id' => $holder?->id,
                    'holder_name' => $holder?->name,
                    'since' => $since?->toISOString(),
                    'location' => $location,
                    'key_number' => $keyNumber,
                    'status' => $log?->action ?? 'unknown',
                ];
            })
            ->values();

        $recentLogs = collect();
        $activityToday = 0;
        if ($tableExists) {
            $recentLogs = $this->visibleLogQuery($user)
                ->with([
                    'asset:id,name',
                    'user:id,name',
                    'transferredToUser:id,name',
                ])
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (FleetKeyLog $log): array => [
                    'id' => $log->id,
                    'vehicle' => $log->asset?->name,
                    'action' => $log->action,
                    'user' => $this->isCurrentVisibleStaff($user, (int) $log->user_id)
                        ? $log->user?->name
                        : null,
                    'transferred_to' => $log->transferred_to_user_id
                        && $this->isCurrentVisibleStaff($user, (int) $log->transferred_to_user_id)
                            ? $log->transferredToUser?->name
                            : null,
                    'key_number' => $log->key_number,
                    'location' => $log->location,
                    'notes' => $log->notes,
                    'created_at' => $log->created_at?->toISOString(),
                ])
                ->values();

            $activityToday = $this->visibleLogQuery($user)
                ->where('created_at', '>=', now()->startOfDay())
                ->where('created_at', '<=', now()->endOfDay())
                ->count();
        }

        $canManage = $user->canDo('fleet.manage');
        $users = collect();
        $vehicles = collect();
        if ($canManage) {
            $staffQuery = User::query()
                ->whereNotNull('name')
                ->orderBy('name')
                ->orderBy('id')
                ->limit(200);
            $this->siteAccess->applyStaffScope(
                $staffQuery,
                $user,
                self::SITE_BYPASS_PERMISSIONS,
            );
            $this->applyCurrentStaffSiteAssignment(
                $staffQuery,
                $this->siteAccess->accessibleSiteIds($user, self::SITE_BYPASS_PERMISSIONS),
            );

            $users = $staffQuery->get(['id', 'name']);
            $vehicles = $visibleVehicles
                ->map(fn (Asset $vehicle): array => [
                    'id' => $vehicle->id,
                    'name' => $vehicle->name,
                    'asset_tag' => $vehicle->asset_tag,
                ])
                ->values();
        }

        $hero = [
            'tracked' => $currentHolders->count(),
            'checked_out' => $currentHolders
                ->filter(fn (array $holder): bool => in_array($holder['status'], ['checked_out', 'transferred'], true))
                ->count(),
            'in_safe' => $currentHolders
                ->filter(fn (array $holder): bool => $holder['status'] === 'returned' || $holder['location'] === 'key_safe')
                ->count(),
            'activity_today' => $activityToday,
        ];

        return Inertia::render('fleet-assets/keys/index', [
            'hero' => $hero,
            'current_holders' => $currentHolders,
            'recent_logs' => $recentLogs,
            'users' => $users,
            'vehicles' => $vehicles,
            'can' => [
                'manage' => $canManage,
            ],
        ]);
    }

    public function checkout(Request $request)
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer', 'min:1'],
            'user_id' => ['required', 'integer', 'min:1'],
            'key_number' => ['nullable', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            DB::transaction(function () use ($request, $data): void {
                $actor = $this->lockedCurrentFleetManager($request);
                $vehicle = $this->lockedVisibleVehicle($actor, (int) $data['asset_id']);
                $siteId = $this->authoritativeSiteId($vehicle) ?? abort(404);
                $holder = $this->lockedEligibleVehicleStaff((int) $data['user_id'], $siteId) ?? abort(404);
                $latest = $this->lockedLatestCustody($vehicle, $siteId);
                if ($latest && $latest->action !== 'returned') {
                    throw new ConflictHttpException('This vehicle key is already checked out. Return it before another checkout.');
                }

                $log = FleetKeyLog::query()->create([
                    'asset_id' => $vehicle->id,
                    'site_id' => $siteId,
                    'user_id' => $holder->id,
                    'action' => 'checked_out',
                    'key_number' => $data['key_number'] ?? $latest?->key_number,
                    'location' => $data['location'] ?? 'with_driver',
                    'notes' => $data['notes'] ?? null,
                ]);

                AuditLogger::logOrFail('fleet-assets.keys.checkout', $log, [
                    'actor_id' => $actor->id,
                    'asset_id' => $vehicle->id,
                    'user_id' => $holder->id,
                    'site_id' => $siteId,
                ]);
            }, 3);
        } catch (ConflictHttpException $exception) {
            return $this->conflictResponse($request, $exception);
        }

        return back()->with('success', 'Key checked out successfully.');
    }

    public function returnKey(Request $request)
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer', 'min:1'],
            'key_number' => ['nullable', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            DB::transaction(function () use ($request, $data): void {
                $actor = $this->lockedCurrentFleetManager($request);
                $vehicle = $this->lockedVisibleVehicle($actor, (int) $data['asset_id']);
                $siteId = $this->authoritativeSiteId($vehicle) ?? abort(404);
                $latest = $this->lockedLatestCustody($vehicle, $siteId);
                $holderId = $latest ? $this->activeHolderId($latest) : null;
                if ($holderId === null) {
                    throw new ConflictHttpException('This vehicle key is not currently checked out.');
                }
                $holder = $this->lockedEligibleVehicleStaff($holderId, $siteId);
                if (! $holder) {
                    throw new ConflictHttpException('The current key holder is no longer eligible at this Site. Reconcile custody before returning the key.');
                }
                $keyNumber = $this->continuingKeyNumber($latest, $data['key_number'] ?? null);

                $log = FleetKeyLog::query()->create([
                    'asset_id' => $vehicle->id,
                    'site_id' => $siteId,
                    'user_id' => $holder->id,
                    'action' => 'returned',
                    'key_number' => $keyNumber,
                    'location' => $data['location'] ?? 'key_safe',
                    'notes' => $data['notes'] ?? null,
                ]);

                AuditLogger::logOrFail('fleet-assets.keys.return', $log, [
                    'actor_id' => $actor->id,
                    'asset_id' => $vehicle->id,
                    'holder_user_id' => $holder->id,
                    'site_id' => $siteId,
                ]);
            }, 3);
        } catch (ConflictHttpException $exception) {
            return $this->conflictResponse($request, $exception);
        }

        return back()->with('success', 'Key returned successfully.');
    }

    public function transfer(Request $request)
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer', 'min:1'],
            'transferred_to_user_id' => ['required', 'integer', 'min:1'],
            'key_number' => ['nullable', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            DB::transaction(function () use ($request, $data): void {
                $actor = $this->lockedCurrentFleetManager($request);
                $vehicle = $this->lockedVisibleVehicle($actor, (int) $data['asset_id']);
                $siteId = $this->authoritativeSiteId($vehicle) ?? abort(404);
                $recipient = $this->lockedEligibleVehicleStaff(
                    (int) $data['transferred_to_user_id'],
                    $siteId,
                ) ?? abort(404);
                $latest = $this->lockedLatestCustody($vehicle, $siteId);
                $holderId = $latest ? $this->activeHolderId($latest) : null;
                if ($holderId === null) {
                    throw new ConflictHttpException('This vehicle key is not currently checked out, so it cannot be transferred.');
                }
                if ((int) $recipient->id === $holderId) {
                    throw new ConflictHttpException('The selected staff member already holds this vehicle key.');
                }
                $holder = $this->lockedEligibleVehicleStaff($holderId, $siteId);
                if (! $holder) {
                    throw new ConflictHttpException('The current key holder is no longer eligible at this Site. Reconcile custody before transferring the key.');
                }
                $keyNumber = $this->continuingKeyNumber($latest, $data['key_number'] ?? null);

                $log = FleetKeyLog::query()->create([
                    'asset_id' => $vehicle->id,
                    'site_id' => $siteId,
                    'user_id' => $holder->id,
                    'action' => 'transferred',
                    'transferred_to_user_id' => $recipient->id,
                    'key_number' => $keyNumber,
                    'location' => $data['location'] ?? 'with_driver',
                    'notes' => $data['notes'] ?? null,
                ]);

                AuditLogger::logOrFail('fleet-assets.keys.transfer', $log, [
                    'actor_id' => $actor->id,
                    'asset_id' => $vehicle->id,
                    'from_user_id' => $holder->id,
                    'transferred_to' => $recipient->id,
                    'site_id' => $siteId,
                ]);
            }, 3);
        } catch (ConflictHttpException $exception) {
            return $this->conflictResponse($request, $exception);
        }

        return back()->with('success', 'Key transferred successfully.');
    }

    private function visibleVehicleQuery(User $user): Builder
    {
        $query = Asset::query()->vehicles();
        $this->applyVehicleSiteScope(
            $query,
            $this->siteAccess->accessibleSiteIds($user, self::SITE_BYPASS_PERMISSIONS),
        );

        return $query;
    }

    /** @param array<int, int> $siteIds */
    private function applyVehicleSiteScope(Builder $query, array $siteIds): void
    {
        if ($siteIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $assetSite = $query->qualifyColumn('site_id');
        $assetHomeSite = $query->qualifyColumn('home_site_id');
        $assetClient = $query->qualifyColumn('client_id');

        $query->where(function (Builder $provenance) use (
            $siteIds,
            $assetSite,
            $assetHomeSite,
            $assetClient,
        ): void {
            $provenance->where(function (Builder $directSite) use ($siteIds, $assetSite, $assetClient): void {
                $directSite
                    ->whereIn($assetSite, $siteIds)
                    ->where(function (Builder $clientAgreement) use ($assetSite, $assetClient): void {
                        $clientAgreement
                            ->whereNull($assetClient)
                            ->orWhereHas('client', fn (Builder $client): Builder => $client
                                ->whereColumn($client->qualifyColumn('site_id'), $assetSite));
                    });
            })->orWhere(function (Builder $homeSite) use (
                $siteIds,
                $assetSite,
                $assetHomeSite,
                $assetClient,
            ): void {
                $homeSite
                    ->whereNull($assetSite)
                    ->whereIn($assetHomeSite, $siteIds)
                    ->where(function (Builder $clientAgreement) use ($assetHomeSite, $assetClient): void {
                        $clientAgreement
                            ->whereNull($assetClient)
                            ->orWhereHas('client', fn (Builder $client): Builder => $client
                                ->whereColumn($client->qualifyColumn('site_id'), $assetHomeSite));
                    });
            })->orWhere(function (Builder $clientFallback) use (
                $siteIds,
                $assetSite,
                $assetHomeSite,
                $assetClient,
            ): void {
                $clientFallback
                    ->whereNull($assetSite)
                    ->whereNull($assetHomeSite)
                    ->whereNotNull($assetClient)
                    ->whereHas('client', fn (Builder $client): Builder => $client->whereIn('site_id', $siteIds));
            });
        });
    }

    private function lockedCurrentFleetManager(Request $request): User
    {
        $requestUser = $request->user() ?? abort(403);
        $query = User::query()->whereKey($requestUser->id);
        $this->siteAccess->applyStaffScope(
            $query,
            $requestUser,
            self::SITE_BYPASS_PERMISSIONS,
        );
        $this->applyCurrentStaffSiteAssignment(
            $query,
            $this->siteAccess->accessibleSiteIds($requestUser, self::SITE_BYPASS_PERMISSIONS),
        );
        $actor = $query->lockForUpdate()->first();

        abort_unless($actor && $actor->canDo('fleet.manage'), 403);

        return $actor;
    }

    private function lockedVisibleVehicle(User $actor, int $assetId): Asset
    {
        $vehicle = $this->visibleVehicleQuery($actor)
            ->whereKey($assetId)
            ->with('client:id,site_id')
            ->lockForUpdate()
            ->first();

        return $vehicle ?? abort(404);
    }

    private function lockedEligibleVehicleStaff(int $userId, int $siteId): ?User
    {
        $query = User::query()->whereKey($userId);
        $this->siteAccess->applyFleetRecipientEligibility($query, $siteId);

        return $query->lockForUpdate()->first();
    }

    private function authoritativeSiteId(Asset $vehicle): ?int
    {
        $siteId = $vehicle->site_id ?: $vehicle->home_site_id ?: $vehicle->client?->site_id;

        return is_numeric($siteId) && (int) $siteId > 0 ? (int) $siteId : null;
    }

    private function lockedLatestCustody(Asset $vehicle, int $siteId): ?FleetKeyLog
    {
        $latest = FleetKeyLog::query()
            ->where('asset_id', $vehicle->id)
            ->latest('id')
            ->lockForUpdate()
            ->first();
        if (! $latest) {
            return null;
        }

        if ((int) $latest->site_id !== $siteId || ! $this->hasValidLogShape($latest)) {
            throw new ConflictHttpException('The latest key record has unresolved Site or custody provenance. Reconcile it before recording another action.');
        }

        return $latest;
    }

    private function canUseCurrentCustody(Asset $vehicle, FleetKeyLog $log): bool
    {
        $siteId = $this->authoritativeSiteId($vehicle);
        if ($siteId === null
            || (int) $log->asset_id !== (int) $vehicle->id
            || (int) $log->site_id !== $siteId
            || ! $this->hasValidLogShape($log)) {
            return false;
        }

        $holderId = $this->activeHolderId($log);

        return $holderId === null || $this->isEligibleVehicleStaff($holderId, $siteId);
    }

    private function hasValidLogShape(FleetKeyLog $log): bool
    {
        if (! in_array($log->action, self::ACTIONS, true)
            || ! is_numeric($log->user_id)
            || (int) $log->user_id < 1) {
            return false;
        }

        return $log->action === 'transferred'
            ? is_numeric($log->transferred_to_user_id) && (int) $log->transferred_to_user_id > 0
            : $log->transferred_to_user_id === null;
    }

    private function activeHolderId(FleetKeyLog $log): ?int
    {
        return match ($log->action) {
            'checked_out' => (int) $log->user_id,
            'transferred' => (int) $log->transferred_to_user_id,
            default => null,
        };
    }

    private function continuingKeyNumber(FleetKeyLog $latest, ?string $requested): ?string
    {
        if ($requested !== null
            && $latest->key_number !== null
            && $requested !== $latest->key_number) {
            throw new ConflictHttpException('The supplied key number does not match the key currently in custody.');
        }

        return $requested ?? $latest->key_number;
    }

    private function isEligibleVehicleStaff(int $userId, int $siteId): bool
    {
        $cacheKey = "vehicle:{$siteId}:{$userId}";

        if (array_key_exists($cacheKey, $this->staffEligibilityCache)) {
            return $this->staffEligibilityCache[$cacheKey];
        }

        $query = User::query()->whereKey($userId);
        $this->siteAccess->applyFleetRecipientEligibility($query, $siteId);

        return $this->staffEligibilityCache[$cacheKey] = $query->exists();
    }

    private function isCurrentVisibleStaff(User $viewer, int $userId): bool
    {
        $cacheKey = "viewer:{$viewer->id}:{$userId}";
        if (array_key_exists($cacheKey, $this->staffEligibilityCache)) {
            return $this->staffEligibilityCache[$cacheKey];
        }

        $query = User::query()->whereKey($userId);
        $this->siteAccess->applyStaffScope(
            $query,
            $viewer,
            self::SITE_BYPASS_PERMISSIONS,
        );
        $this->applyCurrentStaffSiteAssignment(
            $query,
            $this->siteAccess->accessibleSiteIds($viewer, self::SITE_BYPASS_PERMISSIONS),
        );

        return $this->staffEligibilityCache[$cacheKey] = $query->exists();
    }

    /** @param array<int, int> $siteIds */
    private function applyCurrentStaffSiteAssignment(Builder $query, array $siteIds): void
    {
        if ($siteIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHas('hrEmployeeProfile', function (Builder $profile) use ($siteIds): void {
            $profile->where(function (Builder $siteAssignment) use ($siteIds): void {
                $siteAssignment->whereIn('primary_site_id', $siteIds);

                foreach ($siteIds as $siteId) {
                    $siteAssignment->orWhereJsonContains('secondary_site_ids', $siteId);
                }
            });
        });
    }

    private function visibleLogQuery(User $viewer): Builder
    {
        $siteIds = $this->siteAccess->accessibleSiteIds($viewer, self::SITE_BYPASS_PERMISSIONS);
        $query = FleetKeyLog::query()
            ->whereNotNull('site_id')
            ->where(function (Builder $valid): void {
                $valid->where(function (Builder $checkout): void {
                    $checkout->where('action', 'checked_out')->whereNull('transferred_to_user_id');
                })->orWhere(function (Builder $returned): void {
                    $returned->where('action', 'returned')->whereNull('transferred_to_user_id');
                })->orWhere(function (Builder $transferred): void {
                    $transferred->where('action', 'transferred')->whereNotNull('transferred_to_user_id');
                });
            });

        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('site_id', $siteIds);
    }

    private function conflictResponse(Request $request, ConflictHttpException $exception)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return back()
            ->with('error', $exception->getMessage())
            ->withErrors(['custody' => $exception->getMessage()]);
    }
}
