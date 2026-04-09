<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffFatigueAlertNotification extends Notification
{
    use Queueable;

    /**
     * @param  string   $staffName
     * @param  string   $flagLevel       'red' or 'amber'
     * @param  string[] $triggeredRules  Human-readable descriptions of triggered thresholds.
     * @param  int      $userId
     */
    public function __construct(
        public string $staffName,
        public string $flagLevel,
        public array $triggeredRules,
        public int $userId,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $level = strtoupper($this->flagLevel);
        $ruleList = implode("\n", array_map(fn ($r) => "• {$r}", $this->triggeredRules));

        return (new MailMessage)
            ->subject("Staff Wellbeing Alert ({$level}): {$this->staffName}")
            ->greeting('Hello ' . ($notifiable->name ?? 'there') . ',')
            ->line("**{$this->staffName}** has been flagged at **{$level}** level for fatigue/wellbeing concerns.")
            ->line('**Triggered thresholds:**')
            ->line($ruleList)
            ->action('View Staff Profile', url("/hr/people/{$this->userId}"))
            ->line('Please review their upcoming roster and take appropriate action.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => "Staff Wellbeing Alert ({$this->flagLevel})",
            'message' => "{$this->staffName} has reached {$this->flagLevel} fatigue level.",
            'user_id' => $this->userId,
            'staff_name' => $this->staffName,
            'flag_level' => $this->flagLevel,
            'triggered_rules' => $this->triggeredRules,
            'url' => url("/hr/people/{$this->userId}"),
        ];
    }
}
