<?php

namespace App\Http\Controllers;

use App\Models\BreakGlassFlagDismissal;
use App\Models\BreakGlassPolicy;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientIncident;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class EmergencyAccessController extends Controller
{
    private const SITE_BYPASS_PERMISSIONS = ['medications.audit.view'];

    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    private function canRevoke($user, ClientBreakGlassAccess $access): bool
    {
        $isManager = $user->hasRole('admin', 'provider_manager') || $user->canDo('medications.audit.view');

        return $isManager || (int) $access->user_id === (int) $user->id;
    }

    /**
     * Break-glass discovery flow.
     *
     * This endpoint intentionally returns only minimal client identity fields.
     * It exists so authorised staff can request emergency access without
     * broadening the normal Clients list permissions.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('medications.breakglass'), 403);

        $policy = BreakGlassPolicy::current();

        $visibleSiteIds = $this->siteAccess->accessibleSiteIds(
            $user,
            self::SITE_BYPASS_PERMISSIONS,
        );
        $siteId = $request->integer('site_id') ?: null;
        if ($siteId !== null) {
            abort_unless(in_array($siteId, $visibleSiteIds, true), 403);
        }
        $scopedSiteIds = $siteId === null ? $visibleSiteIds : [$siteId];
        $accessScope = fn (Builder $query): Builder => $query->whereHas(
            'client',
            fn (Builder $clients): Builder => $clients->whereIn('site_id', $scopedSiteIds),
        );

        $q = trim((string) $request->get('q', ''));

        $results = collect();
        if (mb_strlen($q) >= 2) {
            $results = Client::query()
                ->with('site:id,name')
                ->whereIn('site_id', $scopedSiteIds)
                ->where(function ($query) use ($q) {
                    $searchTerm = '%'.$q.'%';
                    $query
                        ->where('first_name', 'like', $searchTerm)
                        ->orWhere('last_name', 'like', $searchTerm)
                        ->orWhereRaw("concat(first_name, ' ', last_name) like ?", [$searchTerm]);
                })
                ->orderBy('last_name')
                ->limit(25)
                ->get(['id', 'first_name', 'last_name', 'date_of_birth', 'status', 'site_id'])
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'first_name' => $c->first_name,
                    'last_name' => $c->last_name,
                    'date_of_birth' => optional($c->date_of_birth)->format('Y-m-d'),
                    'status' => $c->status,
                    'site' => $c->site?->only(['id', 'name']),
                ]);
        }

        $clientName = fn ($a) => $a->client ? trim(($a->client->first_name ?? '').' '.($a->client->last_name ?? '')) : 'Unknown';

        // Live grants visible through canonical Site access — not revoked, not expired.
        $activeAccesses = ClientBreakGlassAccess::query()
            ->tap($accessScope)
            ->with(['client:id,first_name,last_name,site_id', 'client.site:id,name', 'user:id,name', 'coSignedBy:id,name'])
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('created_at')
            ->limit(60)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'client_id' => $a->client_id,
                'client_name' => $clientName($a),
                'site_name' => $a->client?->site?->name,
                'reason' => $a->reason,
                'reason_category' => $a->reason_category,
                'cosign_label' => $a->authorizationLabel(),
                'granted_by' => $a->user?->name,
                'created_at' => $a->created_at?->toIso8601String(),
                'expires_at' => $a->expires_at?->toIso8601String(),
                'can_revoke' => $this->canRevoke($user, $a),
            ])
            ->values();

        // Audit log — every activation, including revoked (soft-deleted) ones.
        $auditLog = ClientBreakGlassAccess::withTrashed()
            ->tap($accessScope)
            ->with(['client:id,first_name,last_name,site_id', 'client.site:id,name', 'user:id,name', 'revokedBy:id,name', 'reviewedBy:id,name', 'accessEvents'])
            ->orderByDesc('created_at')
            ->limit(150)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'client_id' => $a->client_id,
                'staff' => $a->user?->name ?? 'Unknown',
                'client_name' => $clientName($a),
                'site_name' => $a->client?->site?->name,
                'reason' => $a->reason,
                'reason_category' => $a->reason_category,
                'minutes' => $a->created_at && $a->expires_at ? $a->created_at->diffInMinutes($a->expires_at) : null,
                'created_at' => $a->created_at?->toIso8601String(),
                'expires_at' => $a->expires_at?->toIso8601String(),
                'status' => $a->deleted_at ? 'revoked' : ($a->expires_at && $a->expires_at->isPast() ? 'expired' : 'active'),
                'revoked_by' => $a->revokedBy?->name,
                'review_outcome' => $a->review_outcome,
                'reviewed_by' => $a->reviewedBy?->name,
                'incident_report_id' => $a->incident_report_id,
                'events' => $a->accessEvents->sortBy('created_at')->values()->map(fn ($e) => [
                    'action' => $e->action,
                    'detail' => $e->detail,
                    'at' => $e->created_at?->toIso8601String(),
                ])->all(),
            ])
            ->values();

        // Acknowledged signals are suppressed until newer activity appears (re-surface).
        $dismissals = BreakGlassFlagDismissal::query()
            ->get()
            ->keyBy(fn ($d) => $d->signal_type.':'.$d->signal_key);
        $isDismissed = function (string $type, string $key, ?Carbon $freshAt) use ($dismissals): bool {
            $d = $dismissals->get($type.':'.$key);

            return $d && $d->dismissed_through && $freshAt && $d->dismissed_through->gte($freshAt);
        };

        // Flagged: repeat break-glass — one user activating ≥ the policy threshold within its window.
        $windowStart = now()->subDays($policy->repeat_window_days);
        $recent = ClientBreakGlassAccess::withTrashed()
            ->tap($accessScope)
            ->where('created_at', '>=', $windowStart)
            ->with('user:id,name')
            ->get();
        $flaggedSignals = $recent->groupBy('user_id')
            ->filter(fn ($g) => $g->count() >= $policy->repeat_threshold_count)
            ->reject(fn ($g) => $isDismissed('repeat', (string) $g->first()->user_id, $g->max('created_at')))
            ->map(fn ($g) => [
                'type' => 'repeat',
                'key' => (string) $g->first()->user_id,
                'severity' => 'critical',
                'title' => 'Repeat break-glass — same user',
                'detail' => ($g->first()->user?->name ?? 'A staff member').' activated break-glass '.$g->count().' times in the last '.$policy->repeat_window_days.' days.',
            ])
            ->values();

        // Oversight gap: activations that have ended (expired) without a post-event review.
        $awaitingBase = ClientBreakGlassAccess::query()
            ->tap($accessScope)
            ->whereNotNull('expires_at')->where('expires_at', '<', now())
            ->whereNull('review_outcome');
        $awaitingReview = (clone $awaitingBase)->count();
        $awaitingFresh = $awaitingReview > 0 ? Carbon::parse((clone $awaitingBase)->max('expires_at')) : null;

        if ($awaitingReview > 0 && ! $isDismissed('awaiting_review', 'awaiting_review', $awaitingFresh)) {
            $flaggedSignals->push([
                'type' => 'awaiting_review',
                'key' => 'awaiting_review',
                'severity' => 'warning',
                'title' => 'Activations awaiting review',
                'detail' => $awaitingReview.' expired break-glass activation'.($awaitingReview === 1 ? ' has' : 's have').' not had a post-event review.',
            ]);
        }

        // Co-sign approver pool: approved staff who can access at least one
        // currently visible Site and hold break-glass or audit permission.
        $approvers = User::query()
            ->where('id', '!=', $user->id)
            ->whereNotNull('approved_at')
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'role'])
            ->filter(function (User $candidate) use ($scopedSiteIds): bool {
                if (! $candidate->canDo('medications.breakglass') && ! $candidate->canDo('medications.audit.view')) {
                    return false;
                }

                return array_intersect(
                    $scopedSiteIds,
                    // Co-signing is a care authorisation action, so audit
                    // visibility cannot replace a current HR Site assignment.
                    $this->siteAccess->accessibleSiteIds($candidate),
                ) !== [];
            })
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role])
            ->values();

        $activeSite = $siteId ? Site::query()->whereIn('id', $visibleSiteIds)->find($siteId) : null;

        // Deep-link from the MAR interstitial: pre-open the request wizard for this client.
        $requestClientId = $request->integer('request_client') ?: null;
        $requestClient = null;
        if ($requestClientId) {
            $rc = Client::query()
                ->whereIn('site_id', $scopedSiteIds)
                ->with('site:id,name')
                ->find($requestClientId);
            if ($rc) {
                $requestClient = [
                    'id' => $rc->id,
                    'first_name' => $rc->first_name,
                    'last_name' => $rc->last_name,
                    'date_of_birth' => optional($rc->date_of_birth)->format('Y-m-d'),
                    'status' => $rc->status,
                    'site' => $rc->site?->only(['id', 'name']),
                ];
            }
        }

        // Incidents for the audit-log clients, for the review modal's link picker.
        $incidentsByClient = ClientIncident::query()
            ->whereIn('client_id', $auditLog->pluck('client_id')->unique()->values())
            ->orderByDesc('occurred_at')
            ->get(['id', 'client_id', 'type', 'title', 'occurred_at'])
            ->groupBy('client_id')
            ->map(fn ($g) => $g->map(fn ($i) => [
                'id' => $i->id,
                'label' => $i->title ?: ucfirst((string) $i->type),
                'date' => $i->occurred_at?->toDateString(),
            ])->values());

        return inertia('emergency/access', [
            'query' => $q,
            'results' => $results,
            'activeAccesses' => $activeAccesses,
            'auditLog' => $auditLog,
            'flaggedSignals' => $flaggedSignals,
            'approvers' => $approvers,
            'can_review' => $user->hasRole('admin', 'provider_manager') || $user->canDo('medications.audit.view'),
            'policy' => [
                'default_minutes' => $policy->default_minutes,
                'max_minutes' => $policy->max_minutes,
                'extend_minutes' => $policy->extend_minutes,
                'auto_revoke' => true,
                'reason_required' => $policy->reason_required,
                'repeat_threshold_count' => $policy->repeat_threshold_count,
                'repeat_window_days' => $policy->repeat_window_days,
            ],
            'can_edit_policy' => $user->hasRole('admin', 'provider_manager'),
            'stats' => [
                'active' => $activeAccesses->count(),
                'granted_week' => $recent->count(),
                'awaiting_review' => $awaitingReview,
                'flagged' => $flaggedSignals->count(),
            ],
            'sites' => Site::query()
                ->whereIn('id', $visibleSiteIds)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'active_site' => $activeSite ? ['id' => $activeSite->id, 'name' => $activeSite->name] : null,
            'site_brand_colour' => $activeSite?->brand_colour,
            'request_client' => $requestClient,
            'incidents_by_client' => $incidentsByClient,
        ]);
    }
}
