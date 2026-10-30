<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\CompanyHub\Services\HubNotificationPreferences;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Services\FeedService;
use App\Models\User;
use App\Services\UserSiteAccessService;
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
        if (! $this->shouldSend($notifiable, 'database')) {
            return [];
        }

        return ['database', 'mail'];
    }

    /** Laravel invokes this again when a queued channel is actually delivered. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $notifiable instanceof User && app(HubNotificationPreferences::class)->allows($notifiable, 'recognition') && $this->currentTeamRecipient($notifiable);
    }

    private function currentTeamRecipient(object $notifiable): bool
    {
        $current = HrKudos::find($this->kudos->id);
        $viewer = User::find($notifiable->id);
        if (! $current || ! $viewer || ! app(FeedService::class)->canViewKudos($current, $viewer)
            || ! (new UserSiteAccessService)->applyStaffScope(User::query()->whereKey($viewer->id), $viewer)->exists()
            || ($current->isTeamRecognition() ? ! $current->recipients()->whereKey($viewer->id)->exists() : (int) $current->to_user_id !== (int) $viewer->id)) {
            return false;
        }
        $this->kudos = $current;

        return true;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $category = $this->kudos->categoryLabel();

        $team = $this->kudos->isTeamRecognition();
        $recognised = $team ? 'your team, '.($this->kudos->toDepartment?->name ?? 'the team') : 'you';

        return (new MailMessage)
            ->subject($team ? "Your team received kudos from {$this->senderName} 🎉" : "You've received kudos from {$this->senderName} 🎉")
            ->greeting("Kia ora {$notifiable->name},")
            ->line('**'.$this->kudos->impactLabel().'**')
            ->line("{$this->senderName} just recognised {$recognised}".($category === null ? ':' : " for **{$category}**:"))
            ->line('"'.Str::limit((string) $this->kudos->message, 300).'"')
            ->action('View your shout-outs', url('/hr/my/shoutouts'))
            ->line('Reply on the thread to say thanks!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'kudos_received',
            'kudos_id' => $this->kudos->id,
            'recipient_type' => $this->kudos->isTeamRecognition() ? 'team' : 'person',
            'to_department_id' => $this->kudos->to_department_id,
            'team_name' => $this->kudos->isTeamRecognition() ? $this->kudos->toDepartment?->name : null,
            'from_name' => $this->senderName,
            'category' => $this->kudos->category,
            'category_label' => $this->kudos->categoryLabel(),
            'impact' => $this->kudos->impactKey(),
            'impact_label' => $this->kudos->impactLabel(),
            'message_excerpt' => Str::limit((string) $this->kudos->message, 120),
            'action_url' => '/hr/my/shoutouts',
        ];
    }
}
