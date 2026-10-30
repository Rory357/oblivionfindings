<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** No person, drug name or private contact details travel in a cupboard request. */
final class ControlledWitnessRequested extends Notification
{
    public function __construct(public int $requestId, public int $siteId) {}

    public function via(object $notifiable): array
    {
        return $notifiable->canDo('medications.controlled.view') && $notifiable->canDo('medications.controlled.witness') ? ['database'] : [];
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'controlled_witness_request', 'title' => 'You’ve been asked to witness',
            'message' => 'Open Controlled checks to answer. Your witness PIN is still entered at the cupboard.',
            'severity' => 'info', 'action_url' => '/meds/today?view=controlled', 'site_id' => $this->siteId,
            'requires_permission' => 'medications.controlled.view', 'request_id' => $this->requestId];
    }
}
