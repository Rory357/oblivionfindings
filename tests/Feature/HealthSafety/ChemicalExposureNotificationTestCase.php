<?php

namespace Tests\Feature\HealthSafety;

use App\Models\ChemicalExposureSourceDelivery;
use App\Models\ClientIncident;
use App\Models\User;
use App\Notifications\HealthSafety\ChemicalExposureFollowupAssignedNotification;
use App\Notifications\ShiftTaskDueNotification;
use App\Services\HealthSafety\ChemicalExposureFollowupDestinationService;
use App\Services\HealthSafety\ChemicalExposureNotificationAccessService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/** Actual physical source/destination fixtures; no outer RefreshDatabase transaction or fake delivery. */
abstract class ChemicalExposureNotificationTestCase extends ChemicalExposureCommandTestCase
{
    protected bool $physicalCommits = true;

    protected function inbox(): ChemicalExposureNotificationAccessService { return app(ChemicalExposureNotificationAccessService::class); }

    protected function notified(?ClientIncident $incident = null): array
    {
        $receiver = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']);
        if ($incident !== null) {
            foreach (['incidents.viewAny', 'clients.viewAny'] as $key) { $this->allow($receiver, $key); $this->allow($this->manager, $key); }
        }
        $root = $this->exposure(['symptoms' => 'PRIVATE-NOTIFICATION-SYMPTOMS',
            'circumstances' => 'PRIVATE-NOTIFICATION-CIRCUMSTANCES', 'related_incident_id' => $incident?->id]);
        $accepted = $this->exposureService()->assignFollowup($root, $this->manager, $this->privateCommand($root,
            ['assigned_to' => $receiver->id, 'instruction' => 'PRIVATE-NOTIFICATION-INSTRUCTION', 'due_at' => '2026-10-13T01:00:00Z']));
        $delivery = ChemicalExposureSourceDelivery::where('kind', 'followup')->where('source_version_id', $accepted['source_version_id'])->sole();
        app(ChemicalExposureFollowupDestinationService::class)->deliverForViewer($this->manager, $delivery->id);
        $notice = DatabaseNotification::where('type', ChemicalExposureFollowupAssignedNotification::class)
            ->where('notifiable_id', $receiver->id)->where('data->delivery_id', $delivery->id)->sole();
        return [$root, $receiver, $notice, $delivery, $accepted];
    }

    protected function ordinary(User $user, array $data = []): DatabaseNotification
    {
        return DatabaseNotification::create(['id' => (string) Str::uuid(), 'type' => ShiftTaskDueNotification::class,
            'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id,
            'data' => ['title' => 'Ordinary update', 'module' => 'operations', 'url' => '/my-day', ...$data],
            'read_at' => null, 'acknowledged_at' => null, 'escalation_count' => 0,
            'created_at' => now()->subMinute(), 'updated_at' => now()->subMinute()]);
    }

    protected function visible(User $user): array
    {
        return $this->inbox()->materialize($user, fn ($current, $context) => app(\App\Domain\Governance\Services\BoardPackAccessService::class)
            ->visibleNotificationQuery($current, false, $context)->orderBy('notifications.id')->pluck('notifications.id')->all());
    }

    protected function data(User $user, DatabaseNotification $notice): array
    {
        return $this->inbox()->materialize($user,
            fn ($current, $context) => $this->inbox()->dataForPresentation($notice, $current, $context));
    }
}
