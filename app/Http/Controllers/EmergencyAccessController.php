<?php

namespace App\Http\Controllers;

use App\Models\BreakGlassFlagDismissal;
use App\Models\BreakGlassPolicy;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\MedicationEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Alerts\OnCallResolver;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\WitnessPinService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;

class EmergencyAccessController extends Controller
{
    public function __construct(private readonly UserSiteAccessService $siteAccess, private readonly WitnessPinService $pins, private readonly OnCallResolver $onCall) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('medications.breakglass') || $user->canDo('medications.audit.view')), 403);
        $reviewer = $user->canDo('medications.audit.view');
        $canStart = $user->canDo('medications.breakglass');
        $visible = $this->siteAccess->accessibleSiteIds($user, $reviewer ? MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS : []);
        $siteId = $request->integer('site_id') ?: null;
        abort_if($siteId !== null && ! in_array($siteId, $visible, true), 404);
        $scoped = $siteId ? [$siteId] : $visible;
        $base = ClientBreakGlassAccess::withTrashed()
            ->whereHas('client', fn ($q) => $q->whereIn('site_id', $scoped));
        $relations = ['client.site', 'user:id,name', 'coSignedBy:id,name', 'reviewedBy:id,name',
            'revokedBy:id,name', 'reviews.user:id,name', 'extensions', 'accessEvents'];
        $history = (clone $base)->with($relations)->orderByDesc('created_at')->paginate(50, ['*'], 'history_page')->withQueryString();
        $queue = (clone $base)->with($relations)->whereNull('review_outcome')
            ->where(fn ($w) => $w->whereNotNull('deleted_at')->orWhereNotNull('ended_at')->orWhere('expires_at', '<=', now()))
            ->orderByRaw('COALESCE(review_due_at, deleted_at, expires_at) ASC')->paginate(50, ['*'], 'review_page')->withQueryString();
        $running = (clone $base)->with($relations)->whereNull('deleted_at')->whereNull('ended_at')->where('expires_at', '>', now())->orderBy('expires_at')->get();
        $all = $history->getCollection();
        $openGrant = $request->integer('grant') ? (clone $base)->with($relations)->find($request->integer('grant')) : null;
        $eventGrants = $all->merge($queue->getCollection())->merge($running)->when($openGrant, fn ($rows) => $rows->push($openGrant))->keyBy('id');
        $chainEvents = MedicationEvent::query()->whereIn('site_id', $scoped)
            ->whereIn('client_id', $eventGrants->pluck('client_id'))
            ->where(function ($events) use ($eventGrants): void {
                $events->where(fn ($q) => $q->where('subject_type', 'emergency_access')->whereIn('subject_id', $eventGrants->keys()->map(fn ($id) => (string) $id)))
                    ->orWhereIn('facts->break_glass_access_id', $eventGrants->keys());
            })->when(! $user->canDo('medications.controlled.view'), fn ($q) => $q->where('controlled', false))
            ->orderBy('occurred_at')->get()
            ->filter(function ($event) use ($eventGrants): bool {
                $id = $event->facts['break_glass_access_id'] ?? ($event->subject_type === 'emergency_access' ? $event->subject_id : null);
                $grant = $eventGrants->get($id);

                return $grant && (int) $event->client_id === (int) $grant->client_id
                    && (int) $event->site_id === (int) $grant->client->site_id;
            })->groupBy(fn ($event) => $event->facts['break_glass_access_id'] ?? $event->subject_id);
        $present = function (ClientBreakGlassAccess $grant) use ($user, $reviewer, $chainEvents): array {
            $running = $grant->isRunning();
            $own = (int) $grant->user_id === (int) $user->id;
            $independent = ! in_array((int) $user->id, [(int) $grant->user_id, (int) $grant->co_signed_by], true);

            return [
                'id' => $grant->id, 'client_id' => $grant->client_id,
                'client_name' => $grant->client?->full_name ?? 'Person', 'site_name' => $grant->client?->site?->name,
                'staff' => $grant->user?->name ?? 'Staff member', 'granted_by' => $grant->user?->name,
                'reason' => $grant->reason, 'reason_category' => $grant->reason_category,
                'cosign_label' => $grant->authorizationLabel(),
                'created_at' => $grant->created_at?->toIso8601String(), 'expires_at' => $grant->expires_at?->toIso8601String(),
                'ended_at' => $grant->endedTime()?->toIso8601String(), 'ended_how' => $grant->ended_how,
                'end_reason' => $grant->end_reason, 'review_due_at' => $grant->reviewDueTime()?->toIso8601String(),
                'minutes' => $grant->created_at && $grant->expires_at ? (int) $grant->created_at->diffInMinutes($grant->expires_at) : null,
                'status' => $grant->trashed() ? 'revoked' : ($running ? 'active' : 'expired'),
                'can_revoke' => $running && ($own ? $user->canDo('medications.breakglass') : $user->canDo('medications.breakglass.end')),
                'can_extend' => $running && $own && $user->canDo('medications.breakglass')
                    && $grant->expires_at->lt($grant->created_at->copy()->addMinutes($grant->effectivePolicy()['max_minutes'])),
                'own' => $own, 'can_review' => ! $running && $reviewer && $independent,
                'can_report_error' => $user->canDo('medications.view') && $user->canDo('medications.administer.record'),
                'review_denial' => $independent ? null : ($own ? 'You used it — someone else reviews it' : 'You confirmed it — someone else reviews it'),
                'review_outcome' => $grant->review_outcome, 'reviewed_by' => $grant->reviewedBy?->name,
                'incident_report_id' => $grant->incident_report_id,
                'reviews' => $grant->reviews->map(fn ($r) => [
                    'id' => $r->id, 'outcome' => $r->outcome, 'notes' => $r->notes, 'by' => $r->user?->name,
                    'at' => $r->created_at->toIso8601String(), 'correction_reason' => $r->correction_reason,
                ])->values(),
                'extensions' => $grant->extensions,
                'events' => $grant->accessEvents->map(fn ($e) => [
                    'action' => $e->action,
                    // Old activity rows carry no controlled marker; do not disclose their free text to a reader without controlled view.
                    'detail' => $user->canDo('medications.controlled.view') ? $e->detail : null,
                    'at' => $e->created_at?->toIso8601String(),
                ])->concat(($chainEvents->get($grant->id) ?? collect())->map(fn ($e) => [
                    'id' => $e->id, 'action' => $e->summary, 'detail' => null,
                    'at' => $e->occurred_at->toIso8601String(),
                ]))->sortBy('at')->values(),
            ];
        };
        $q = trim((string) $request->query('q', ''));
        $discoverySites = $canStart ? $this->siteAccess->accessibleSiteIds($user) : [];
        $results = mb_strlen($q) >= 2 && $canStart ? Client::whereIn('site_id', array_intersect($scoped, $discoverySites))
            ->with('site:id,name')->where(fn ($w) => $w->where('first_name', 'like', '%'.$q.'%')->orWhere('last_name', 'like', '%'.$q.'%'))
            ->orderBy('last_name')->limit(25)->get(['id', 'first_name', 'last_name', 'date_of_birth', 'site_id'])->map(fn ($c) => $c->only(['id', 'first_name', 'last_name']) + ['date_of_birth' => $c->date_of_birth?->toDateString(), 'site' => $c->site?->only(['id', 'name'])]) : collect();
        $requestClient = $canStart && $request->integer('request_client') ? Client::whereIn('site_id', array_intersect($scoped, $discoverySites))
            ->with('site:id,name')->find($request->integer('request_client')) : null;
        $candidates = User::whereNotNull('approved_at')->where('id', '!=', $user->id)->with(['roles.permissions', 'hrEmployeeProfile'])
            ->orderBy('name')->get()->filter(fn (User $u) => ($u->canDo('medications.breakglass') || $u->canDo('medications.audit.view'))
                && array_intersect($scoped, $this->siteAccess->accessibleSiteIds($u)) !== []);
        $byId = $candidates->keyBy('id');
        $pinRows = $this->pins->pickerRows($candidates)->map(fn ($row) => $row + [
            'site_ids' => array_values(array_intersect($scoped, $this->siteAccess->accessibleSiteIds($byId->get($row['id'])))),
        ]);
        $onCallContacts = [];
        foreach (array_intersect($scoped, $discoverySites) as $houseId) {
            $contact = $this->onCall->at((int) $houseId, now());
            $onCallContacts[(int) $houseId] = [
                'name' => $contact['user']?->name,
                'phone' => $contact['user'] ? $this->onCall->phoneOf($contact['user']) : null,
                'how' => $contact['how'], 'warning' => $contact['warning'],
            ];
        }
        $policy = BreakGlassPolicy::current();
        $recent = (clone $base)->where('created_at', '>=', now()->subDays($policy->repeat_window_days))->with(['user:id,name', 'client.site'])->get();
        $dismissals = BreakGlassFlagDismissal::where('signal_type', 'repeat')->get()->keyBy('signal_key');
        $flags = $recent->groupBy(fn ($g) => $g->client->site_id.':'.$g->user_id)
            ->filter(fn ($g) => $g->count() >= $policy->repeat_threshold_count)
            ->reject(function ($g, $key) use ($dismissals): bool {
                $ack = $dismissals->get((string) $key) ?? $dismissals->get((string) $g->first()->user_id);

                return $ack && ($ack->dismissed_through_access_id !== null
                    ? (int) $ack->dismissed_through_access_id >= (int) $g->max('id')
                    : (bool) $ack->dismissed_through?->gt($g->max('created_at')));
            })
            ->map(fn ($g, $id) => [
                'type' => 'repeat', 'key' => (string) $id, 'severity' => 'warning', 'title' => 'Repeat emergency access',
                'detail' => ($g->first()->user?->name ?? 'Staff member').' — '.$g->count().' grants at '.($g->first()->client?->site?->name ?? 'this house').' within '.$policy->repeat_window_days.' days. Reported, never blocked.',
                'can_acknowledge' => $reviewer && (int) $g->first()->user_id !== (int) $user->id,
            ])->values();
        $rows = $all->map($present);
        $site = $siteId ? Site::find($siteId) : null;

        return inertia('emergency/access', [
            'query' => $q, 'results' => $results, 'activeAccesses' => $running->map($present)->values(),
            'auditLog' => $rows, 'reviewQueue' => $queue->getCollection()->map($present)->values(),
            'history_pagination' => collect($history->toArray())->except('data'),
            'review_pagination' => collect($queue->toArray())->except('data'), 'flaggedSignals' => $flags, 'approvers' => $pinRows,
            'on_call_contacts' => $onCallContacts,
            'open_grant' => $openGrant ? $present($openGrant) : null,
            'can_review' => $reviewer, 'can_start' => $canStart,
            'policy' => $policy->snapshot() + ['auto_revoke' => true],
            'can_edit_policy' => false, 'incidents_by_client' => [],
            'stats' => [
                'active' => (clone $base)->whereNull('deleted_at')->whereNull('ended_at')->where('expires_at', '>', now())->count(),
                'granted_week' => $recent->count(),
                'awaiting_review' => (clone $base)->whereNull('review_outcome')
                    ->where(fn ($w) => $w->whereNotNull('deleted_at')->orWhereNotNull('ended_at')->orWhere('expires_at', '<=', now()))->count(),
                'flagged' => $flags->count(),
                'month' => (clone $base)->where('created_at', '>=', now('Pacific/Auckland')->startOfMonth()->utc())->count(),
            ],

            'sites' => Site::whereIn('id', $visible)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'active_site' => $site?->only(['id', 'name']), 'site_brand_colour' => $site?->brand_colour,
            'request_client' => $requestClient ? $requestClient->only(['id', 'first_name', 'last_name']) + ['date_of_birth' => $requestClient->date_of_birth?->toDateString(), 'site' => $requestClient->site?->only(['id', 'name'])] : null,
        ]);
    }
}
