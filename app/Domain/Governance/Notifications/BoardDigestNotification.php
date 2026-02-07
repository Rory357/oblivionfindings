<?php

namespace App\Domain\Governance\Notifications;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Services\DashboardAggregatorService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BoardDigestNotification extends Notification
{
    use Queueable;

    public function __construct(
        public BoardMember $boardMember
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $aggregator = new DashboardAggregatorService();
        $metrics = $aggregator->captureSnapshot('week');
        $data = $metrics->snapshot_data['widgets'] ?? [];

        return (new MailMessage)
            ->subject('Weekly Board Digest — ' . now()->format('j F Y'))
            ->markdown('governance.emails.board-digest', [
                'boardMember' => $this->boardMember,
                'metrics' => $data,
                'decisionsCount' => $data['decisions_required']['count'] ?? 0,
                'overdueActions' => \App\Domain\Governance\Models\ActionItem::overdue()->count(),
                'upcomingMeetings' => \App\Domain\Governance\Models\GovernanceMeeting::upcoming()->limit(3)->get(),
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'board_digest',
            'title' => 'Weekly Board Digest',
            'sent_at' => now()->toIso8601String(),
        ];
    }
}
