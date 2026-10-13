<?php

namespace App\Services\Medication\BackupDelivery;

use App\Mail\MailNotSubmitted;
use App\Mail\MailSubmissionRejected;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class BackupMailTransport
{
    public function __construct(private readonly BackupEmailSender $sender) {}

    /**
     * EA-140: someone approved for several houses can tell each email and
     * file apart in an outage — the subject and file name carry the house,
     * the NZ day and the delivery reference (never a resident's name).
     */
    public static function filename(?string $house, string $day, ?string $reference = null): string
    {
        $slug = $house !== null ? Str::slug($house) : '';

        return 'chart-backup-'.($slug !== '' ? $slug.'-' : '').$day.($reference !== null && $reference !== '' ? '-'.$reference : '').'.pdf';
    }

    public static function subject(?string $house, string $day, ?string $reference = null): string
    {
        if ($house === null || trim($house) === '') {
            return 'Protected chart backup';
        }

        return 'Protected chart backup — '.trim($house).' — '.$day.($reference !== null && $reference !== '' ? ' ('.$reference.')' : '');
    }

    /**
     * @param  array<string, string>  $personal  EA-141: address => that recipient's own copy,
     *                                         which opens with their backup passphrase.
     */
    public function send(array $addresses, string $bytes, string $day, ?string $house = null, ?string $reference = null, array $personal = []): void
    {
        if (! config('emar-catalogue-backups.send_enabled', false) || $addresses === []) {
            throw new MailNotSubmitted('backup_delivery_disabled');
        }
        $subject = self::subject($house, $day, $reference);
        $filename = self::filename($house, $day, $reference);
        $intro = 'A protected chart backup'.($house !== null && trim($house) !== '' ? ' for '.trim($house) : '').' ('.$day.($reference ? ', '.$reference : '').') is attached. ';
        // EA-141: a personal copy opens with the recipient's own passphrase; the app isn't needed.
        $personalBody = $intro.'It opens with your own backup passphrase — you don’t need the app. Do not forward this message.';
        $body = $intro.'Its password is shown separately in the app after your own password and authenticator check. '
            .'Set a backup passphrase in the app so future backups open without it, even when the app is down. Do not forward this message.';
        DB::transaction(function () use ($addresses, $bytes, $subject, $filename, $body, $personal, $personalBody): void {
            $mailer = $this->sender->make($addresses);
            $accepted = 0;
            foreach ($addresses as $address) {
                try {
                    $copy = $personal[$address] ?? null;
                    $sent = $mailer->raw($copy !== null ? $personalBody : $body, function (Message $message) use ($address, $bytes, $copy, $subject, $filename): void {
                        $message->to($address)->subject($subject)->attachData($copy ?? $bytes, $filename, ['mime' => 'application/pdf']);
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
