<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\EmarUrl;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BreakGlassController extends Controller
{
    /** Owner of the grant, or a manager/auditor, may revoke or extend. */
    private function canManage(User $user, ClientBreakGlassAccess $access): bool
    {
        $isManager = $user->hasRole('admin', 'provider_manager') || $user->canDo('medications.audit.view');

        return $isManager || (int) $access->user_id === (int) $user->id;
    }

    public function store(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('medications.breakglass'), 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'reason_category' => ['nullable', 'string', 'max:100'],
            'minutes' => ['nullable', 'integer', 'min:5', 'max:'.ClientBreakGlassAccess::MAX_MINUTES],
            'authorization_mode' => ['nullable', Rule::in(['self', 'co_sign'])],
            // Dual authorisation must be a *different* person.
            'co_signed_by' => ['nullable', 'integer', Rule::exists('users', 'id'), Rule::notIn([$user->id]), 'required_if:authorization_mode,co_sign'],
            'acknowledged_min_necessary' => ['nullable', 'boolean'],
            'acknowledged_incident_report' => ['nullable', 'boolean'],
        ]);

        // Default: expire after the policy default unless explicitly set, capped at the policy max.
        $minutes = ! empty($data['minutes']) ? (int) $data['minutes'] : ClientBreakGlassAccess::DEFAULT_MINUTES;
        $minutes = min($minutes, ClientBreakGlassAccess::MAX_MINUTES);
        $mode = $data['authorization_mode'] ?? 'self';

        $access = ClientBreakGlassAccess::create([
            'client_id' => $client->id,
            'user_id' => $user->id,
            'reason' => $data['reason'],
            'reason_category' => $data['reason_category'] ?? null,
            'authorization_mode' => $mode,
            'co_signed_by' => $mode === 'co_sign' ? ($data['co_signed_by'] ?? null) : null,
            'acknowledged_min_necessary' => (bool) ($data['acknowledged_min_necessary'] ?? false),
            'acknowledged_incident_report' => (bool) ($data['acknowledged_incident_report'] ?? false),
            'expires_at' => now()->addMinutes($minutes),
        ]);

        app(NotificationService::class)->notifyCrud($user, 'created', 'break-glass access', $access, $client, [
            'title' => 'Break-glass access used',
            'url' => url(EmarUrl::mar($client)),
        ]);

        return back()->with('success', 'Break-glass access granted.');
    }

    public function extend(Request $request, Client $client, ClientBreakGlassAccess $access)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('medications.breakglass') || $user->canDo('medications.audit.view')), 403);
        abort_unless((int) $access->client_id === (int) $client->id, 404);
        abort_unless($this->canManage($user, $access), 403);

        // Only a live grant can be extended; expired/revoked windows are closed.
        if (! $access->expires_at || $access->expires_at->isPast()) {
            return back()->with('error', 'This grant has already ended and cannot be extended.');
        }

        // Cap the total window (grant → new expiry) at the policy maximum.
        $hardCap = $access->created_at?->copy()->addMinutes(ClientBreakGlassAccess::MAX_MINUTES);
        $proposed = $access->expires_at->copy()->addMinutes(ClientBreakGlassAccess::EXTEND_MINUTES);
        $newExpiry = $hardCap && $proposed->greaterThan($hardCap) ? $hardCap : $proposed;

        if ($newExpiry->lessThanOrEqualTo($access->expires_at)) {
            return back()->with('error', 'Already at the maximum '.(ClientBreakGlassAccess::MAX_MINUTES / 60).'-hour duration.');
        }

        $access->forceFill(['expires_at' => $newExpiry])->save();

        return back()->with('success', 'Break-glass access extended.');
    }

    public function review(Request $request, Client $client, string $access)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('medications.audit.view'), 403);

        // Reviews apply to completed activations, which may be expired or revoked
        // (soft-deleted) — resolve including trashed so the audit log's Review
        // action always finds its row.
        $record = ClientBreakGlassAccess::withTrashed()
            ->where('client_id', $client->id)
            ->findOrFail((int) $access);

        $data = $request->validate([
            'review_outcome' => ['required', Rule::in(['justified', 'not_justified'])],
            'review_notes' => ['nullable', 'string', 'max:2000'],
            'incident_report_linked' => ['nullable', 'boolean'],
        ]);

        $record->forceFill([
            'reviewed_at' => now(),
            'reviewed_by' => $user->id,
            'review_outcome' => $data['review_outcome'],
            'review_notes' => $data['review_notes'] ?? null,
            'incident_report_linked' => (bool) ($data['incident_report_linked'] ?? false),
        ])->save();

        return back()->with('success', 'Break-glass review saved.');
    }

    public function destroy(Request $request, Client $client, ClientBreakGlassAccess $access)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('medications.breakglass') || $user->canDo('medications.audit.view')), 403);

        abort_unless((int) $access->client_id === (int) $client->id, 404);
        abort_unless($this->canManage($user, $access), 403);

        // Record the revoker, then soft-delete so the activation is retained for
        // the break-glass audit trail (never hard-erased).
        $access->forceFill(['revoked_by' => $user->id])->save();
        $access->delete();

        app(NotificationService::class)->notifyCrud($user, 'deleted', 'break-glass access', $access, $client, [
            'title' => 'Break-glass access revoked',
            'url' => url(EmarUrl::mar($client)),
        ]);

        return back()->with('success', 'Break-glass access revoked.');
    }
}
