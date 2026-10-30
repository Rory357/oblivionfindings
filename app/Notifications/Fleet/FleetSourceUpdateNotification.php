<?php

namespace App\Notifications\Fleet;

use App\Models\Asset;
use App\Models\AssetImportBatch;
use App\Models\FleetShiftHandover;
use App\Models\User;
use App\Services\Fleet\FleetNotificationPreferences;
use App\Services\UserSiteAccessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** A minimal optional copy; never includes location, import rows or handover notes. */
class FleetSourceUpdateNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $key, public int $sourceId)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $this->canReadSource($notifiable) ? app(FleetNotificationPreferences::class)->channels($notifiable, $this->key) : [];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        // Queued copies re-check the current source authority and choices at delivery.
        return in_array($channel, $this->via($notifiable), true);
    }

    private function canReadSource(object $user): bool
    {
        if (! $user instanceof User || ! ($user->canDo('fleet.viewAny') || $user->canDo('assets.viewAny'))) {
            return false;
        }
        if ($this->key === 'fleet.import_results') {
            return Gate::forUser($user)->allows('create', Asset::class)
                && AssetImportBatch::whereKey($this->sourceId)->where('created_by_user_id', $user->id)->exists();
        }
        if ($this->key !== 'fleet.handover_updates' || ! $user->canDo('fleet.viewAny')) {
            return false;
        }
        $handover = FleetShiftHandover::find($this->sourceId);
        if (! $handover || ! in_array((int) $user->id, [(int) $handover->incoming_user_id, (int) $handover->outgoing_user_id], true)) {
            return false;
        }
        try {
            app(UserSiteAccessService::class)->assertCanAccessFleetHandover($user, $handover, ['fleet.manage']);
        } catch (HttpException $exception) {
            if (in_array($exception->getStatusCode(), [403, 404], true)) {
                return false;
            }
            throw $exception;
        }

        return true;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->key === 'fleet.import_results' ? 'Your asset import has results' : 'Your handover has an update',
            'message' => 'Open the source record to review the current result and any work still required.',
            'module' => 'fleet', 'event_key' => $this->key, 'source_id' => $this->sourceId,
            'url' => $this->key === 'fleet.import_results' ? '/fleet-assets/assets?view=imports' : '/fleet-assets/handovers/'.$this->sourceId,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);

        return (new MailMessage)->subject($data['title'])->line($data['message'])->action('Review source record', url($data['url']));
    }
}
