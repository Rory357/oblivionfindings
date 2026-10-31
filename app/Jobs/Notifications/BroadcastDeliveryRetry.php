<?php

namespace App\Jobs\Notifications;

use RuntimeException;

/**
 * Thrown by DeliverBroadcastCommunicationJob when a text was definitely not
 * taken by the provider and the row has been put back to "pending", so the
 * queue's next try (after the job's backoff) sends it.
 */
final class BroadcastDeliveryRetry extends RuntimeException
{
}
