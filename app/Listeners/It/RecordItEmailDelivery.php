<?php

namespace App\Listeners\It;

use App\Domain\It\Contracts\TracksItEmailDelivery;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Mail\MailNotSubmitted;
use App\Services\EmailConfiguration;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;

class RecordItEmailDelivery
{
    public function __construct(private readonly ItEmailDeliveryService $deliveries) {}

    // Explicitly registered below; `handle` would also be discovered by the
    // framework provider and claim the same sending event twice.
    public function record(NotificationSending|NotificationSent|NotificationFailed $event): ?bool
    {
        return $this->deliveries->recordNotificationEvent($event);
    }

    public function prepareMessage(MessageSending $event): void
    {
        // Laravel supplies this class from the notification, not message input.
        $notification = $event->data['__laravel_notification'] ?? null;
        if (! is_string($notification) || ! is_a($notification, TracksItEmailDelivery::class, true)) {
            return;
        }

        if (array_key_exists('__it_email_configuration_version', $event->data)) {
            $expected = $event->data['__it_email_configuration_version'];
            $settings = app(EmailConfiguration::class);
            $current = $settings->current();
            if (! is_int($expected) || $current['configuration_version'] !== $expected) {
                throw new MailNotSubmitted('Email settings changed before submission. Review the current settings before retrying.');
            }
            if ($current['support_enabled']) {
                try {
                    $settings->supportConnection($current);
                } catch (\DomainException) {
                    throw new MailNotSubmitted('The support mailbox changed before submission. Review the current settings before retrying.');
                }
            }
        }
        $this->deliveries->prepareMessageIdentity($event);

        $headers = $event->message->getHeaders();
        foreach (['Auto-Submitted' => 'auto-generated', 'X-Auto-Response-Suppress' => 'All'] as $name => $value) {
            $headers->remove($name);
            $headers->addTextHeader($name, $value);
        }
    }
}
