<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Services\FeedService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Tells a colleague they've just been recognised on the feed. Sent to the
 * recipient only (never the sender) whenever a kudos is created — from the
 * feed wizard, the My-HR shout-outs flow or a bulk multi-recipient send.
 */
class KudosReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrKudos $kudos,
        private string $senderName,
    ) {
        // The kudos row is created inside a transaction — deliver after commit.
        // NB: assigned here rather than redeclared as a typed property —
        // redeclaring Queueable::$afterCommit with a type is a PHP fatal
        // ("definition differs") the moment the class is composed.
        $this->afterCommit = true;
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $category = FeedService::KUDOS_CATEGORIES[$this->kudos->category] ?? ucfirst((string) $this->kudos->category);

        return (new MailMessage)
            ->subject("You've received kudos from {$this->senderName} 🎉")
            ->greeting("Kia ora {$notifiable->name},")
            ->line("{$this->senderName} just recognised you for **{$category}**:")
            ->line('"'.Str::limit((string) $this->kudos->message, 300).'"')
            ->action('View your shout-outs', url('/hr/my/shoutouts'))
            ->line('Reply on the thread to say thanks!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'kudos_received',
            'kudos_id' => $this->kudos->id,
            'from_name' => $this->senderName,
            'category' => $this->kudos->category,
            'impact' => $this->kudos->impact,
            'message_excerpt' => Str::limit((string) $this->kudos->message, 120),
            'action_url' => '/hr/my/shoutouts',
        ];
    }
}
