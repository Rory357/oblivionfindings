<?php

namespace App\Domain\Referrals\Services;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

class ReferralAcknowledgementTransport
{
    public const SUBJECT = 'Your enquiry has been received';

    public static function body(string $reference): string
    {
        return "We received your enquiry.\n\nYour reference is {$reference}.\n\nOur team will review it and contact you using the preferences you supplied. This message confirms receipt only. Please do not reply with personal documents.\n";
    }

    public function configured(): bool
    {
        $mailer = config('referral-delivery.mailer');

        return config('referral-delivery.enabled') === true
            && filter_var(config('referral-delivery.from_address'), FILTER_VALIDATE_EMAIL) !== false
            && filled(config('referral-delivery.from_name'))
            && config('mail.mailers.'.$mailer.'.transport') === 'smtp';
    }

    /** True means accepted by the transport, never confirmed delivery to the recipient. */
    public function send(string $recipient, string $reference): bool
    {
        if (! $this->configured()) {
            throw new \LogicException('Configure referral email delivery before sending an acknowledgement.');
        }
        $mailer = Mail::build([...config('mail.mailers.'.config('referral-delivery.mailer')), 'timeout' => 30]);
        $sent = $mailer->raw(self::body($reference), function (Message $message) use ($recipient, $reference): void {
            $message->to($recipient)->from(config('referral-delivery.from_address'), config('referral-delivery.from_name'))
                ->subject(self::SUBJECT);
            $domain = substr(strrchr(config('referral-delivery.from_address'), '@'), 1);
            $message->getSymfonyMessage()->getHeaders()->addIdHeader('Message-ID', 'referral-ack-'.strtolower($reference).'@'.$domain);
        });

        return $sent !== null;
    }
}
