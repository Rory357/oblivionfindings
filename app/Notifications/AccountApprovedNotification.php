<?php

namespace App\Notifications;

use App\Models\NotificationTemplate;
use App\Services\TemplateRenderService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class AccountApprovedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $approverName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $template = NotificationTemplate::findByKey('account_approved');

        if ($template && $template->is_active) {
            $service = app(TemplateRenderService::class);
            $context = [
                'approver' => $this->approverName,
            ];

            $body = $service->render($template, $notifiable, $context);
            $subject = $service->renderSubject($template, $notifiable, $context);

            return (new MailMessage)
                ->subject($subject)
                ->line(new HtmlString(nl2br(e($body))));
        }

        // Fallback to hardcoded content
        return (new MailMessage)
            ->subject('Your Account Has Been Approved')
            ->greeting('Hello ' . ($notifiable->name ?? 'there') . ',')
            ->line('Your account has been reviewed and approved. You now have full access to the platform.')
            ->action('Get Started', url('/dashboard'))
            ->line('If you have any questions, please contact your administrator.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Account Approved',
            'message' => "Your account has been approved by {$this->approverName}. You now have full access.",
            'approver_name' => $this->approverName,
        ];
    }
}
