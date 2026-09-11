<?php

namespace App\Domain\It\Services;

use App\Models\ItEmailDelivery;

/** Public diagnostics never include provider bodies or exception messages. */
final class ItEmailDeliveryFailure
{
    public const MESSAGES = [
        'outcome_unknown' => 'Delivery outcome is unknown. Reconcile the provider result before retrying.',
        'access_revoked_uncertain' => 'Further delivery stopped because the recipient no longer has access. The earlier sending outcome is unknown; reconcile the provider result before retrying.',
        'access_revoked' => 'Delivery stopped because the recipient no longer has access.',
        'not_submitted' => 'The message was not submitted. Review the saved outgoing email configuration and recipient access before retrying.',
        'submission_rejected' => 'The provider rejected submission. Review the outgoing email connection before retrying.',
        'dispatch_failed' => 'Notification dispatch failed. Review the delivery record before retrying.',
        'provider_failed' => 'The provider reported a delivery failure. Review the provider result before retrying.',
        'provider_bounced' => 'The provider rejected delivery. Review the recipient address before retrying.',
        'legacy_failure' => 'A delivery failure was recorded without a safe category. Review the provider result before retrying.',
    ];

    /** Historical raw diagnostics remain stored, but are never redisclosed. */
    public static function category(ItEmailDelivery $delivery): ?string
    {
        if ($delivery->status === 'delivered') {
            return null;
        }
        $raw = $delivery->getRawOriginal('last_error');
        $known = array_search($raw, self::MESSAGES, true);
        if ($known !== false) {
            return $known;
        }
        if ($delivery->status === 'sending' && filled($raw)) {
            return 'outcome_unknown';
        }
        if ($delivery->status === 'bounced' || $delivery->bounced_at !== null) {
            return 'provider_bounced';
        }

        return filled($raw) || $delivery->status === 'failed' ? 'legacy_failure' : null;
    }

    public static function message(ItEmailDelivery $delivery): ?string
    {
        $category = self::category($delivery);

        return $category === null ? null : self::MESSAGES[$category];
    }
}
