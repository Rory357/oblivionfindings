<?php

namespace App\Domain\Referrals\Services;

use App\Services\Notifications\SmsProvider;
use App\Services\Notifications\SmsSendResult;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

/**
 * Sends staff messages. Email uses the referral sender identity (config/referral-delivery.php); text
 * messages use the app's SMS provider (config/services.php `sms`), but only once referral texts are
 * deliberately switched on. Called only by ReferralDelivery, after its claim has committed.
 */
class ReferralMessageTransport
{
    public function emailConfigured(): bool
    {
        return app(ReferralAcknowledgementTransport::class)->configured();
    }

    /** A deliberate switch plus a real provider: a demo or missing provider never reads as "set up". */
    public function smsConfigured(): bool
    {
        return config('referral-delivery.sms_enabled') === true
            && config('services.sms.provider') === 'twilio'
            && filled(config('services.sms.twilio.account_sid'))
            && filled(config('services.sms.twilio.auth_token'))
            && filled(config('services.sms.twilio.from'));
    }

    public function configured(string $channel): bool
    {
        return $channel === 'sms' ? $this->smsConfigured() : $this->emailConfigured();
    }

    /** The sender people see: the configured email name and address, or the text sender. */
    public function sender(string $channel): ?string
    {
        if ($channel === 'sms') {
            return $this->smsConfigured() ? (string) config('services.sms.twilio.from') : null;
        }

        return $this->emailConfigured() ? config('referral-delivery.from_name').' <'.config('referral-delivery.from_address').'>' : null;
    }

    /** True means accepted by the mail service, never confirmed delivery to the recipient. */
    public function sendEmail(string $recipient, string $subject, string $body, string $messageKey): bool
    {
        if (! $this->emailConfigured()) {
            throw new \LogicException('Configure referral email delivery before sending a message.');
        }
        $mailer = Mail::build([...config('mail.mailers.'.config('referral-delivery.mailer')), 'timeout' => 30]);
        $sent = $mailer->raw($body, function (Message $message) use ($recipient, $subject, $messageKey): void {
            $message->to($recipient)->from(config('referral-delivery.from_address'), config('referral-delivery.from_name'))->subject($subject);
            $domain = substr(strrchr(config('referral-delivery.from_address'), '@'), 1);
            $message->getSymfonyMessage()->getHeaders()->addIdHeader('Message-ID', 'referral-message-'.$messageKey.'@'.$domain);
        });

        return $sent !== null;
    }

    public function sendSms(string $recipient, string $body): SmsSendResult
    {
        if (! $this->smsConfigured()) {
            throw new \LogicException('Configure referral text messages before sending one.');
        }

        return app(SmsProvider::class)->send($recipient, $body);
    }
}
