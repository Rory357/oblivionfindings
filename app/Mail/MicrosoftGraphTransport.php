<?php

namespace App\Mail;

use App\Models\Identity;
use App\Services\MicrosoftGraphService;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;

class MicrosoftGraphTransport extends AbstractTransport
{
    public function __construct(private ?Identity $identity = null)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $to = collect($email->getTo())->first()?->getAddress();

        if (!$to || !$this->identity) {
            return;
        }

        $service = new MicrosoftGraphService($this->identity);
        $service->sendMail(
            $to,
            $email->getSubject() ?? '',
            $email->getHtmlBody() ?? $email->getTextBody() ?? ''
        );
    }

    public function __toString(): string
    {
        return 'microsoft-graph';
    }
}
