<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrEmployeeProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmployeeInviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string $token,
        private HrEmployeeProfile $profile,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Welcome to the team — set up your staff account')
            ->greeting("Kia ora {$notifiable->name},")
            ->line('Your HR team has invited you to set up your secure staff account.')
            ->line('Use the link below to choose your password and finish signing in.')
            ->action('Set up my staff account', $this->actionUrl($notifiable))
            ->line('If you were not expecting this invitation, contact your HR team.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'employee_invite',
            'employee_profile_id' => $this->profile->id,
            'action_url' => $this->actionUrl($notifiable),
        ];
    }

    private function actionUrl(object $notifiable): string
    {
        return url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));
    }
}
