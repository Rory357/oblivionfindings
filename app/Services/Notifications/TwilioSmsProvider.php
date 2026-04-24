<?php

namespace App\Services\Notifications;

use Twilio\Exceptions\RestException;
use Twilio\Rest\Client;

final class TwilioSmsProvider implements SmsProvider
{
    public function __construct(
        private readonly ?string $accountSid,
        private readonly ?string $authToken,
        private readonly ?string $from,
    ) {
    }

    public function send(string $to, string $message): SmsSendResult
    {
        if (blank($this->accountSid) || blank($this->authToken) || blank($this->from)) {
            return SmsSendResult::failed('Twilio SMS credentials are not configured.');
        }

        try {
            $response = (new Client($this->accountSid, $this->authToken))
                ->messages
                ->create($to, [
                    'from' => $this->from,
                    'body' => $message,
                ]);
        } catch (RestException $e) {
            return SmsSendResult::failed($this->formatRestError($e));
        }

        return SmsSendResult::sent($response->sid ?? null);
    }

    private function formatRestError(RestException $e): string
    {
        $message = trim($e->getMessage());

        if ($e->getCode()) {
            $message = sprintf('Twilio error %d: %s', $e->getCode(), $message);
        }

        return substr($message, 0, 240);
    }
}
