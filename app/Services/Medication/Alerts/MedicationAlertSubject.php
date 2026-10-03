<?php

namespace App\Services\Medication\Alerts;

use App\Models\MedicationAlert;

/**
 * What one medication alert is about (eMAR P11 B2), as its source raises it.
 *
 * `$key` identifies the subject within its alert type (a stock row, an
 * order, a discrepancy): while an alert for it is open, raising it again
 * tells nobody. `$message` is what the bell shows; `$shortMessage` is what
 * email and push say when client names and medicines are kept out of them.
 * `$controlled`: only people with controlled-medicine access are told.
 */
final class MedicationAlertSubject
{
    /** @param array<string, int|string|null> $context Ids for links and the bell payload. */
    public function __construct(
        public readonly string $key,
        public readonly ?int $siteId,
        public readonly string $title,
        public readonly string $message,
        public readonly string $shortMessage,
        public readonly ?string $actionUrl = null,
        public readonly string $severity = 'warning',
        public readonly ?int $clientId = null,
        public readonly bool $controlled = false,
        public readonly ?int $staffUserId = null,
        public readonly array $context = [],
    ) {}

    /** The subject of an alert already raised, for a later step (follow-up, release after quiet hours). */
    public static function of(MedicationAlert $alert): self
    {
        return new self(
            key: '',
            siteId: $alert->site_id !== null ? (int) $alert->site_id : null,
            title: (string) $alert->title,
            message: (string) $alert->message,
            shortMessage: (string) $alert->short_message,
            actionUrl: $alert->action_url,
            severity: (string) $alert->severity,
            clientId: $alert->client_id !== null ? (int) $alert->client_id : null,
            controlled: (bool) $alert->controlled,
            staffUserId: $alert->staff_user_id !== null ? (int) $alert->staff_user_id : null,
            context: $alert->subject ?? [],
        );
    }
}
