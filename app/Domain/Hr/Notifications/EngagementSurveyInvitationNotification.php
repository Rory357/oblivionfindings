<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrEngagementSurvey;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EngagementSurveyInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly HrEngagementSurvey $survey,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New Engagement Survey: ' . $this->survey->title)
            ->line('A new engagement survey is available and ready for your response.')
            ->line('Survey: ' . $this->survey->title)
            ->action('Open Survey', url('/hr/wellbeing/surveys/' . $this->survey->id));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'hr_engagement_survey_invitation',
            'survey_id' => $this->survey->id,
            'title' => 'Engagement Survey: ' . $this->survey->title,
            'message' => 'A new ' . strtoupper($this->survey->survey_type) . ' survey is available for you to complete.',
            'survey_type' => $this->survey->survey_type,
            'url' => '/hr/wellbeing/surveys/' . $this->survey->id,
        ];
    }
}
