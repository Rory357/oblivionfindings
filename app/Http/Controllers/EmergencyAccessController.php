<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\Site;
use Illuminate\Http\Request;

class EmergencyAccessController extends Controller
{
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

        $q = trim((string) $request->get('q', ''));

        $results = collect();
        if (mb_strlen($q) >= 2) {
            $results = Client::query()
                ->with('site:id,name')
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

        $siteId = $request->integer('site_id') ?: null;
        $orgScope = fn ($query) => $query->when($user->organization_id, fn ($x) => $x->whereHas('user', fn ($u) => $u->where('organization_id', $user->organization_id)));
        $bySite = fn ($query) => $query->when($siteId, fn ($x) => $x->whereHas('client', fn ($c) => $c->where('site_id', $siteId)));
        $clientName = fn ($a) => $a->client ? trim(($a->client->first_name ?? '').' '.($a->client->last_name ?? '')) : 'Unknown';

        // Live grants (org-wide oversight) — not revoked, not expired.
        $activeAccesses = ClientBreakGlassAccess::query()
            ->tap($orgScope)
            ->tap($bySite)
            ->with(['client:id,first_name,last_name,site_id', 'client.site:id,name', 'user:id,name'])
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
                'granted_by' => $a->user?->name,
                'created_at' => $a->created_at?->toIso8601String(),
                'expires_at' => $a->expires_at?->toIso8601String(),
                'can_revoke' => $this->canRevoke($user, $a),
            ])
            ->values();

        // Audit log — every activation, including revoked (soft-deleted) ones.
        $auditLog = ClientBreakGlassAccess::withTrashed()
            ->tap($orgScope)
            ->tap($bySite)
            ->with(['client:id,first_name,last_name,site_id', 'client.site:id,name', 'user:id,name', 'revokedBy:id,name'])
            ->orderByDesc('created_at')
            ->limit(150)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'staff' => $a->user?->name ?? 'Unknown',
                'client_name' => $clientName($a),
                'site_name' => $a->client?->site?->name,
                'reason' => $a->reason,
                'created_at' => $a->created_at?->toIso8601String(),
                'expires_at' => $a->expires_at?->toIso8601String(),
                'status' => $a->deleted_at ? 'revoked' : ($a->expires_at && $a->expires_at->isPast() ? 'expired' : 'active'),
                'revoked_by' => $a->revokedBy?->name,
            ])
            ->values();

        // Flagged: repeat break-glass — the same user activating ≥4 times in 7 days.
        $weekAgo = now()->subDays(7);
        $recent = ClientBreakGlassAccess::withTrashed()->tap($orgScope)->where('created_at', '>=', $weekAgo)->with('user:id,name')->get();
        $flaggedSignals = $recent->groupBy('user_id')
            ->filter(fn ($g) => $g->count() >= 4)
            ->map(fn ($g) => [
                'type' => 'repeat',
                'severity' => 'critical',
                'title' => 'Repeat break-glass — same user',
                'detail' => ($g->first()->user?->name ?? 'A staff member').' activated break-glass '.$g->count().' times in the last 7 days.',
            ])
            ->values();

        $activeSite = $siteId ? Site::find($siteId) : null;

        return inertia('emergency/access', [
            'query' => $q,
            'results' => $results,
            'activeAccesses' => $activeAccesses,
            'auditLog' => $auditLog,
            'flaggedSignals' => $flaggedSignals,
            'policy' => [
                'default_minutes' => 60,
                'max_minutes' => 1440,
                'auto_revoke' => true,
                'reason_required' => true,
            ],
            'stats' => [
                'active' => $activeAccesses->count(),
                'granted_week' => $recent->count(),
                'revoked_week' => $recent->filter(fn ($a) => $a->deleted_at !== null)->count(),
                'flagged' => $flaggedSignals->count(),
            ],
            'sites' => Site::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'active_site' => $activeSite ? ['id' => $activeSite->id, 'name' => $activeSite->name] : null,
            'site_brand_colour' => $activeSite?->brand_colour,
        ]);
    }
}
