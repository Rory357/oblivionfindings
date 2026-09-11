<?php

namespace App\Domain\It\Services;

use App\Models\ItAutomationRun;

/** Public diagnostics never contain exception text, command arguments or record payloads. */
final class ItAutomationRunDiagnostics
{
    public static function failure(string $key): string
    {
        return match ($key) {
            'it.check-sla' => 'The SLA watchdog did not complete. An authorized operator can review the run and retry the bounded check.',
            'it.close-resolved' => 'Automatic ticket closure did not complete. An authorized operator must review eligible tickets before retrying.',
            'it.poll-mailbox' => 'Mailbox polling did not complete. An authorized operator can review connection recovery status.',
            'it.dispatch-notifications' => 'Notification recovery did not complete. Review delivery outcomes before retrying; accepted or uncertain messages must not be blindly resent.',
            'it.retry-attachment-cleanup' => 'Attachment cleanup did not complete. An authorized operator can review the recorded outcome and recovery runbook.',
            'it.check-approval-deadlines' => 'Approval timing did not complete. An authorized operator can review eligible approvers and retry the bounded check.',
            default => 'Automation did not complete. An authorized operator must review the recorded run before recovery.',
        };
    }

    public static function safeError(ItAutomationRun $run): ?string
    {
        // Old rows may still contain arbitrary private provider/exception text.
        return $run->status === 'failed' ? self::failure($run->automation_key) : null;
    }
}
