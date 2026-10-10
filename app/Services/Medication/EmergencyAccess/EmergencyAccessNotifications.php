<?php

namespace App\Services\Medication\EmergencyAccess;

use App\Models\ClientBreakGlassAccess;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\NotificationService;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Collection;

/** Explicit eligible recipients: generic manager routing must never disclose these grants to HR or finance. */
class EmergencyAccessNotifications
{
    public function __construct(private readonly UserSiteAccessService $sites, private readonly NotificationService $preferences) {}

    public function reviewers(int $siteId): Collection
    {
        return User::query()->whereNotNull('approved_at')->with(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile'])
            ->get()->filter(fn (User $user): bool => $user->canDo('medications.audit.view')
                && in_array($siteId, $this->sites->accessibleSiteIds($user, MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS), true));
    }

    public function opened(ClientBreakGlassAccess $grant): void
    {
        $users = $this->reviewers((int) $grant->client->site_id);
        if ($grant->co_signed_by) {
            $users = $users->push(User::find($grant->co_signed_by))->filter();
        }
        $this->send($users->unique('id')->reject(fn ($user) => (int) $user->id === (int) $grant->user_id), [
            'event_key' => 'break_glass_access.created', 'title' => 'Emergency access started',
            'body' => $grant->user->name.' started emergency access for '.$grant->client->full_name.'.',
            'access_id' => $grant->id, 'client_id' => $grant->client_id,
        ]);
    }

    public function endedBySomeoneElse(ClientBreakGlassAccess $grant): void
    {
        $this->send(collect([$grant->user]), [
            'event_key' => 'break_glass_access.ended', 'title' => 'Your emergency access ended',
            'body' => $grant->end_reason, 'access_id' => $grant->id,
        ]);
    }

    public function send(Collection $recipients, array $payload): void
    {
        $notification = new AppEventNotification($payload + [
            'kind' => 'medication', 'url' => url('/emar/emergency-access?view=review'),
        ]);
        $this->preferences->applyPreferences($recipients, $payload['event_key'])->each(fn (User $user) => $user->notify($notification));
    }
}
