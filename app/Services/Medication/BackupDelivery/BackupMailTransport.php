<?php

namespace App\Services\Medication\BackupDelivery;

use App\Mail\MailNotSubmitted;
use App\Mail\MailSubmissionRejected;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BackupMailTransport
{
    public function __construct(private readonly BackupEmailSender $sender) {}

    public function send(array $addresses, string $bytes, string $day): void
    {
        if (! config('emar-catalogue-backups.send_enabled', false) || $addresses === []) {
            throw new MailNotSubmitted('backup_delivery_disabled');
        }
        DB::transaction(function () use ($addresses, $bytes, $day): void {
            $mailer = $this->sender->make($addresses);
            $accepted = 0;
            foreach ($addresses as $address) {
                try {
                    $sent = $mailer->raw('A protected chart backup is attached. Obtain its password separately through the approved application. Do not forward this message.', function (Message $message) use ($address, $bytes, $day): void {
                        $message->to($address)->subject('Protected chart backup')->attachData($bytes, 'chart-backup-'.$day.'.pdf', ['mime' => 'application/pdf']);
                    });
                } catch (MailNotSubmitted|MailSubmissionRejected) {
                    if ($accepted === 0) {
                        throw new MailNotSubmitted('backup_transport_not_submitted');
                    }
                    // A later preflight failure cannot make earlier accepted mail safe to retry.
                    throw new RuntimeException('backup_transport_incomplete');
                } catch (\Throwable) {
                    // Transport errors can contain mailbox or provider details; expose only a neutral code.
                    throw new RuntimeException('backup_transport_submission_unknown');
                }
                if ($sent === null) {
                    throw new RuntimeException('backup_transport_incomplete');
                }
                $accepted++;
            }
        }, 1);
    }
}
