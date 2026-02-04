<?php

namespace App\Services\ControlRoom;

use App\Models\ControlRoom\Communication;
use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Notifications\ControlRoomAlertNotification;
use Illuminate\Support\Collection;

class ControlRoomNotificationService
{
    public function notifyAlert(ControlRoomAlert $alert, ?SignalRule $rule, ?TriageQueue $queue): void
    {
        $users = $this->resolveUsers($rule, $queue);
        if ($users->isEmpty()) {
            return;
        }

        foreach ($users as $user) {
            $user->notify(new ControlRoomAlertNotification($alert));

            Communication::create([
                'alert_id' => $alert->id,
                'channel' => 'in_app',
                'direction' => 'outbound',
                'purpose' => 'notification',
                'target_user_id' => $user->id,
                'content' => "Alert {$alert->alert_type} ({$alert->severity})",
                'status' => 'sent',
                'sent_at' => now(),
                'initiated_by_user_id' => null,
            ]);
        }
    }

    protected function resolveUsers(?SignalRule $rule, ?TriageQueue $queue): Collection
    {
        $userIds = collect();

        if ($rule?->notify_users) {
            $userIds = $userIds->merge($rule->notify_users);
        }

        $roles = collect();
        if ($rule?->notify_roles) {
            $roles = $roles->merge($rule->notify_roles);
        }
        if ($queue?->assigned_roles) {
            $roles = $roles->merge($queue->assigned_roles);
        }

        $users = User::query()
            ->when($userIds->isNotEmpty(), function ($q) use ($userIds) {
                $q->whereIn('id', $userIds->unique()->values());
            })
            ->when($roles->isNotEmpty(), function ($q) use ($roles) {
                $q->orWhereHas('roles', fn($rq) => $rq->whereIn('name', $roles->unique()->values()));
            })
            ->get(['id', 'name', 'email']);

        return $users->unique('id')->values();
    }
}
