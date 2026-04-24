<?php

namespace App\Mail;

use App\Models\ControlRoom\Communication;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email transport for a Control Room broadcast. Renders the plain-text
 * broadcast content using the organisation's branding (header colour, app
 * name, footer) pulled from `app_settings`.
 */
class BroadcastMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Communication $communication)
    {
    }

    public function envelope(): Envelope
    {
        $label = $this->communication->template_used
            ? str($this->communication->template_used)->replace('_', ' ')->title()->toString()
            : null;

        return new Envelope(
            subject: $label
                ? '[Broadcast] ' . $label
                : 'Broadcast from ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        $label = $this->communication->template_used
            ? str($this->communication->template_used)->replace('_', ' ')->title()->toString()
            : null;

        return new Content(
            view: 'emails.broadcast',
            with: [
                'body' => $this->communication->content,
                'templateLabel' => $label,
                'subject' => 'Broadcast',
            ],
        );
    }
}
