<?php

namespace App\Domain\Hr\Notifications;

use Illuminate\Notifications\Notification;

/**
 * A single HR asset attention alert (warranty expiring, return overdue, repair
 * overdue, or an asset held by a leaver) delivered to the HR notification centre.
 * Generic by design so one class serves every kind — `data->kind` distinguishes
 * them and `data->dedupe_key` lets the sender suppress repeats.
 */
class HrAssetAlertNotification extends Notification
{
    /**
     * @param  array{kind:string,title:string,message:string,asset_id:?int,action_url:string,dedupe_key:string}  $payload
     */
    public function __construct(public array $payload) {}

    /**
     * @return array<int,string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'hr_asset_alert',
            'kind' => $this->payload['kind'],
            'title' => $this->payload['title'],
            'message' => $this->payload['message'],
            'asset_id' => $this->payload['asset_id'] ?? null,
            'action_url' => $this->payload['action_url'],
            'dedupe_key' => $this->payload['dedupe_key'],
        ];
    }
}
