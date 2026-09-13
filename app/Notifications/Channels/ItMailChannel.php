<?php

namespace App\Notifications\Channels;

use App\Domain\It\Contracts\TracksItEmailDelivery;
use App\Domain\It\Services\ItOutboundMailer;
use App\Domain\It\Services\ItWorkAccessService;
use App\Mail\MailNotSubmitted;
use App\Models\ItTicketComment;
use App\Models\User;
use App\Services\EmailConfiguration;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/** Preserve Laravel's mail channel name/events and the canonical IT delivery ledger. */
class ItMailChannel extends MailChannel
{
    public function send($notifiable, Notification $notification)
    {
        if (! $notification instanceof TracksItEmailDelivery) {
            return parent::send($notifiable, $notification);
        }
        $configuration = app(EmailConfiguration::class)->current();
        $message = $notification->toMail($notifiable);
        if (! $message instanceof MailMessage || ! $notifiable instanceof User || ! $notifiable->routeNotificationFor('mail', $notification)) {
            throw new MailNotSubmitted('The IT notification does not have a supported message and recipient.');
        }

        $fullReply = null;
        $context = $notification->itEmailDeliveryContext();
        if ($configuration['support_enabled'] && $configuration['public_reply_mode'] === 'full_reply' && ($context['type'] ?? null) === 'ticket_replied') {
            $recipient = $notifiable->fresh();
            $comment = ItTicketComment::query()->useWritePdo()->with('ticket')->find($context['comment_id'] ?? null);
            if (! $comment || $comment->is_internal || ! $comment->ticket || ! $recipient
                || (int) $comment->ticket_id !== (int) ($context['ticket_id'] ?? 0)
                || $recipient->approved_at === null || ! $recipient->can('view', $comment->ticket)
                || ! app(ItWorkAccessService::class)->canView($recipient, $comment->ticket)) {
                throw new MailNotSubmitted('The public reply is no longer available to this recipient.');
            }
            $fullReply = $comment->body;
            $message->introLines[] = new HtmlString('<div>'.nl2br(e($fullReply)).'</div>');
            $message->outroLines[] = 'Open the ticket to view any files. Internal notes are never included in this email.';
        } elseif ($configuration['public_reply_mode'] !== 'link_only' && $configuration['public_reply_mode'] !== 'full_reply') {
            throw new MailNotSubmitted('The saved public-reply mode is not supported.');
        }
        $views = $this->buildView($message);
        if ($fullReply !== null) {
            // Keep literal reply text in the text alternative; HTML is escaped above.
            $views['text'] = static fn () => new HtmlString(implode("\n\n", [
                $message->greeting, $message->subject, $fullReply,
                $message->actionText.': '.$message->actionUrl, 'Open the ticket to view any files.',
            ]));
        }

        $mailer = $configuration['support_enabled'] ? app(ItOutboundMailer::class)->make($configuration)
            : $this->mailer->mailer($message->mailer ?? null);

        $transport = $configuration['support_enabled']
            ? (app(ItOutboundMailer::class)->captureMode() ?? $configuration['provider'])
            : config('mail.mailers.'.($message->mailer ?? config('mail.default')).'.transport');
        $snapshot = [
            'configuration_version' => $configuration['configuration_version'],
            'configured_provider' => $configuration['support_enabled'] ? $configuration['provider'] : null,
            'transport' => is_string($transport) ? $transport : 'application',
            // Class names identify a composite/custom transport without ever
            // serializing its DSN, credentials or provider request contents.
            'transport_class' => get_class($mailer->getSymfonyTransport()),
            'support_connection_id' => $configuration['support_enabled'] ? $configuration['support_connection_id'] : null,
            'support_connection_version' => $configuration['support_enabled'] ? $configuration['support_connection_version'] : null,
            'content_mode' => ($context['type'] ?? null) === 'ticket_replied'
                ? ($fullReply !== null ? 'full_reply' : 'link_only') : 'notification',
        ];

        return $mailer->send(
            $views,
            array_merge($message->data(), $this->additionalMessageData($notification), [
                '__it_email_configuration_version' => $configuration['configuration_version'],
                '__it_email_submission_snapshot' => $snapshot,
            ]),
            $this->messageBuilder($notifiable, $notification, $message),
        );
    }
}
