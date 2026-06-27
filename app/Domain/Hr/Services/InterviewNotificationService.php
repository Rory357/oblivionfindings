<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrInterview;
use App\Domain\Hr\Notifications\InterviewInviteNotification;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * Sends interview calendar invites (and day-before reminders) to the candidate
 * (on-demand mail) and each panellist. Used by CandidateController::storeInterview
 * and the recruitment:send-interview-reminders command, so the dispatch logic
 * lives in one place and stays idempotent via invite_sent_at / reminder_sent_at.
 */
class InterviewNotificationService
{
    public function sendInvites(HrInterview $interview, bool $isReminder = false): void
    {
        $application = $interview->application()->with('candidate')->first();
        if (! $application) {
            return;
        }

        $candidate = $application->candidate;
        $roleTitle = $application->requisition()->value('title') ?: ($application->position_title ?: 'an upcoming role');
        $candidateName = $candidate?->full_name ?? 'the candidate';

        if ($candidate && $candidate->personal_email) {
            Notification::route('mail', $candidate->personal_email)
                ->notify(new InterviewInviteNotification($interview, $candidateName, $roleTitle, $isReminder));
        }

        $panel = collect($interview->interviewers ?? [])->filter()->all();
        if ($panel !== []) {
            User::query()->whereIn('id', $panel)->get()->each(
                fn (User $user) => $user->notify(new InterviewInviteNotification($interview, $candidateName, $roleTitle, $isReminder))
            );
        }

        $interview->forceFill($isReminder ? ['reminder_sent_at' => now()] : ['invite_sent_at' => now()])->save();
    }
}
