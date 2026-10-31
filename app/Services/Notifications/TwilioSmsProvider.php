<?php

namespace App\Services\Notifications;

use Twilio\Exceptions\RestException;
use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Client;

final class TwilioSmsProvider implements SmsProvider
{
    /**
     * cURL errors raised before the request left this server: Twilio never
     * saw the text, so trying again can't send it twice.
     */
    private const NEVER_REACHED = [
        'Could not resolve host',
        'Failed to connect',
        'Couldn\'t connect',
        'Connection refused',
        'Connection timed out',
    ];

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
            return $this->resultForRestError($e);
        } catch (TwilioException $e) {
            return $this->resultForConnectionError($e);
        }

        return SmsSendResult::sent($response->sid ?? null);
    }

    /**
     * Twilio answered with an error. 429 (too many requests) and 503 (busy)
     * mean it took nothing — try again later. Any other 5xx may have
     * happened after the message was created, so it isn't retried.
     */
    private function resultForRestError(RestException $e): SmsSendResult
    {
        $error = $this->formatRestError($e);
        $status = $e->getStatusCode();

        if (in_array($status, [429, 503], true)) {
            return SmsSendResult::retryLater($error);
        }
        if ($status >= 500) {
            return SmsSendResult::unconfirmed($error);
        }

        return SmsSendResult::failed($error);
    }

    /** No answer from Twilio: only a connection that never opened is safe to retry. */
    private function resultForConnectionError(TwilioException $e): SmsSendResult
    {
        $error = substr(trim($e->getMessage()) ?: 'The SMS service did not answer.', 0, 240);

        foreach (self::NEVER_REACHED as $marker) {
            if (stripos($error, $marker) !== false) {
                return SmsSendResult::retryLater($error);
            }
        }

        return SmsSendResult::unconfirmed($error);
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
