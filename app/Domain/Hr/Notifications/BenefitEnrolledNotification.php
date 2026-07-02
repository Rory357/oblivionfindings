<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrBenefitEnrollment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirms a benefit enrolment (or a material change to one — rate/status)
 * to the employee it covers. Best-effort: senders wrap this in try/catch so
 * a mail hiccup never rolls back the enrolment itself.
 */
class BenefitEnrolledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrBenefitEnrollment $enrollment,
    ) {
        // Deliver after the surrounding enrolment transaction commits.
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
        $plan = $this->enrollment->benefitPlan;
        $planName = $plan?->name ?? 'a benefit plan';
        $status = str_replace('_', ' ', (string) $this->enrollment->status);

        $mail = (new MailMessage)
            ->subject("Benefit enrolment update — {$planName}")
            ->greeting("Kia ora {$notifiable->name},")
            ->line("Your enrolment in **{$planName}** has been recorded/updated:")
            ->line("**Status:** {$status}")
            ->line("**Your contribution:** {$this->enrollment->employee_contribution_rate}%");

        if ($this->enrollment->employer_contribution_rate !== null) {
            $mail->line("**Employer contribution:** {$this->enrollment->employer_contribution_rate}%");
        }

        return $mail
            ->line('**Effective from:** '.($this->enrollment->enrollment_date?->toFormattedDateString() ?? '—'))
            ->action('View my benefits', url('/hr/my/benefits'))
            ->line('If anything looks wrong, contact your HR team.');
    }

    public function toArray(object $notifiable): array
    {
        $plan = $this->enrollment->benefitPlan;

        return [
            'type' => 'benefit_enrolled',
            'enrollment_id' => $this->enrollment->id,
            'plan_name' => $plan?->name,
            'plan_type' => $plan?->type,
            'status' => $this->enrollment->status,
            'employee_contribution_rate' => $this->enrollment->employee_contribution_rate,
            'employer_contribution_rate' => $this->enrollment->employer_contribution_rate,
            'effective_date' => $this->enrollment->enrollment_date?->toDateString(),
            'action_url' => '/hr/my/benefits',
        ];
    }
}
