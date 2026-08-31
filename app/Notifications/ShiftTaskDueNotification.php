<?php

namespace App\Notifications;

use App\Models\ShiftTask;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\Channels\PushChannel;
use App\Services\UserSiteAccessService;
use App\Support\ShiftTaskSupport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ShiftTaskDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public string $pushPreferenceKey = 'shift_task_due';

    public function __construct(private ShiftTask $task)
    {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        $recipient = $this->currentAuthorizedRecipient($notifiable);
        if (! $recipient) {
            return [];
        }

        $preference = $this->preference($recipient);
        if ($preference && ! $preference->enabled) {
            return [];
        }

        $channels = [];
        if (! $preference || $preference->channel_inapp) {
            $channels[] = 'database';
        }
        if (! $preference || $preference->channel_email) {
            $channels[] = 'mail';
        }
        if (! $preference || $preference->channel_push) {
            $channels[] = PushChannel::class;
        }

        return $channels;
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $this->currentAuthorizedRecipient($notifiable) !== null;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->task->loadMissing('shift.client');
        $scheduled = $this->task->scheduledFor()?->timezone(config('app.worker_timezone', 'Pacific/Auckland'));

        return (new MailMessage)
            ->subject(trans('my-day.shift_task_due_title'))
            ->line($this->body())
            ->line('Client: '.$this->clientName())
            ->line('Due: '.($scheduled?->format('H:i') ?? ShiftTaskSupport::normalizeTime($this->task->scheduled_time) ?? 'now'))
            ->action('Open My Day', url('/my-day'));
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->toArray($notifiable);
    }

    public function toArray(object $notifiable): array
    {
        $this->task->loadMissing('shift.client');

        return [
            'type' => 'shift_task_due',
            'title' => trans('my-day.shift_task_due_title'),
            'message' => $this->body(),
            'shift_id' => $this->task->shift_id,
            'shift_task_id' => $this->task->id,
            'client_id' => $this->task->shift?->client_id,
            'scheduled_for' => $this->task->scheduledFor()?->toIso8601String(),
            'action_url' => '/my-day',
        ];
    }

    public function toPush(object $notifiable): array
    {
        return [
            'title' => trans('my-day.shift_task_due_title'),
            'body' => $this->body(),
            'data' => [
                'url' => '/my-day',
                'type' => 'shift_task_due',
                'shift_id' => $this->task->shift_id,
                'shift_task_id' => $this->task->id,
            ],
        ];
    }

    private function body(): string
    {
        return trans('my-day.shift_task_due_message', ['task' => $this->task->label]);
    }

    private function clientName(): string
    {
        $client = $this->task->shift?->client;

        return $client ? trim($client->first_name.' '.$client->last_name) : 'Current shift';
    }

    private function preference(object $notifiable): ?UserNotificationPreference
    {
        if (! isset($notifiable->id)) {
            return null;
        }

        return UserNotificationPreference::query()
            ->where('user_id', $notifiable->id)
            ->where('key', $this->pushPreferenceKey)
            ->first();
    }

    private function currentAuthorizedRecipient(object $notifiable): ?User
    {
        $notifiableId = method_exists($notifiable, 'getKey')
            ? $notifiable->getKey()
            : ($notifiable->id ?? null);
        if (! is_numeric($notifiableId) || (int) $notifiableId <= 0) {
            return null;
        }

        $recipient = User::query()->find((int) $notifiableId);
        $task = ShiftTask::query()->with([
            'shift.client:id,first_name,last_name,site_id',
            'shift.site:id,name',
        ])->find($this->task->getKey());
        $shift = $task?->shift;

        if (
            ! $recipient
            || ! $task
            || $task->is_completed
            || ! $shift
            || ! in_array($shift->status, ['scheduled', 'in_progress'], true)
            || (int) $shift->user_id !== (int) $recipient->id
        ) {
            return null;
        }

        $dueAt = $task->scheduledFor();
        if (! $dueAt || $dueAt->isFuture()) {
            return null;
        }

        try {
            (new UserSiteAccessService)->assertCanAccessShift($recipient, $shift);
        } catch (HttpExceptionInterface) {
            return null;
        }

        $this->task = $task;

        return $recipient;
    }
}
