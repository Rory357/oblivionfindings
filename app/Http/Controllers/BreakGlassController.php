<?php

namespace App\Http\Controllers;

use App\Models\BreakGlassFlagDismissal;
use App\Models\BreakGlassPolicy;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\EmarUrl;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

        $policy = BreakGlassPolicy::forOrganization($user->organization_id);

        $data = $request->validate([
            'reason' => [$policy->reason_required ? 'required' : 'nullable', 'string', 'max:255'],
            'reason_category' => ['nullable', 'string', 'max:100'],
            'minutes' => ['nullable', 'integer', 'min:5', 'max:'.$policy->max_minutes],
            'authorization_mode' => ['nullable', Rule::in(['self', 'co_sign'])],
            // Dual authorisation must be a *different* person.
            'co_signed_by' => ['nullable', 'integer', Rule::exists('users', 'id'), Rule::notIn([$user->id]), 'required_if:authorization_mode,co_sign'],
            'acknowledged_min_necessary' => ['nullable', 'boolean'],
            'acknowledged_incident_report' => ['nullable', 'boolean'],
        ]);

        // Default to the org policy duration unless explicitly set, capped at the policy max.
        $minutes = ! empty($data['minutes']) ? (int) $data['minutes'] : $policy->default_minutes;
        $minutes = min($minutes, $policy->max_minutes);
        $mode = $data['authorization_mode'] ?? 'self';

        $access = ClientBreakGlassAccess::create([
            'client_id' => $client->id,
            'user_id' => $user->id,
            'reason' => $data['reason'] ?? $data['reason_category'] ?? '',
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

        // Cap the total window (grant → new expiry) at the org policy maximum.
        $policy = BreakGlassPolicy::forOrganization($user->organization_id);
        $hardCap = $access->created_at?->copy()->addMinutes($policy->max_minutes);
        $proposed = $access->expires_at->copy()->addMinutes($policy->extend_minutes);
        $newExpiry = $hardCap && $proposed->greaterThan($hardCap) ? $hardCap : $proposed;

        if ($newExpiry->lessThanOrEqualTo($access->expires_at)) {
            return back()->with('error', 'Already at the maximum '.round($policy->max_minutes / 60, 1).'-hour duration.');
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

    public function updatePolicy(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $this->canEditPolicy($user), 403);
        abort_unless($user->organization_id, 422);

        $data = $request->validate([
            'default_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'max_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'extend_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'reason_required' => ['required', 'boolean'],
            'repeat_threshold_count' => ['required', 'integer', 'min:1', 'max:100'],
            'repeat_window_days' => ['required', 'integer', 'min:1', 'max:90'],
        ]);

        if ($data['default_minutes'] > $data['max_minutes']) {
            throw ValidationException::withMessages([
                'default_minutes' => 'Default duration cannot exceed the maximum.',
            ]);
        }

        BreakGlassPolicy::updateOrCreate(['organization_id' => $user->organization_id], $data);

        return back()->with('success', 'Break-glass policy updated.');
    }

    /** Only organisation admins / provider managers may change the policy. */
    private function canEditPolicy(User $user): bool
    {
        return $user->hasRole('admin', 'provider_manager');
    }

    public function dismissFlag(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('medications.audit.view'), 403);

        $data = $request->validate([
            'type' => ['required', Rule::in(['repeat', 'awaiting_review'])],
            'key' => ['required', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        // One acknowledgement per (org, type, key); dismissed_through advances on re-ack
        // so the signal re-surfaces only when newer activity appears.
        BreakGlassFlagDismissal::updateOrCreate(
            ['organization_id' => $user->organization_id, 'signal_type' => $data['type'], 'signal_key' => $data['key']],
            ['dismissed_by' => $user->id, 'reason' => $data['reason'] ?? null, 'dismissed_through' => now()],
        );

        return back()->with('success', 'Signal acknowledged.');
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
