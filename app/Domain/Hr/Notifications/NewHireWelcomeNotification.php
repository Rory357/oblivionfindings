<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrOffer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Branded welcome sent to a candidate once they are converted to an employee.
 * Delivered on-demand to the candidate's personal email (they have a work
 * login coming via a separate password-setup invite). Warm, not transactional —
 * the password-reset invite handles the "set your login" mechanics.
 */
class NewHireWelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrCandidate $candidate,
        private HrOffer $offer,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $role = $this->offer->position_title ?: 'your new role';
        $firstName = trim((string) ($this->candidate->first_name ?? '')) ?: 'there';

        $mail = (new MailMessage)
            ->subject("Welcome to the team, {$firstName}!")
            ->greeting("Kia ora {$firstName},")
            ->line("We're delighted to welcome you on board as **{$role}**. Everyone here is looking forward to working with you.");

        if ($this->offer->proposed_start_date) {
            $start = Carbon::parse($this->offer->proposed_start_date)->format('l j F Y');
            $mail->line("Your first day is set for **{$start}**.");
        }

        return $mail
            ->line('Over the next little while you\'ll receive a separate email to set up your work login and password, plus a few onboarding tasks to help you settle in.')
            ->line('If you have any questions before you start, just reply to this email — we\'re here to help.')
            ->salutation('Ngā mihi, The People & Culture Team');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'new_hire_welcome',
            'candidate_id' => $this->candidate->id,
            'candidate_name' => $this->candidate->full_name,
            'offer_id' => $this->offer->id,
            'position_title' => $this->offer->position_title,
        ];
    }
}
