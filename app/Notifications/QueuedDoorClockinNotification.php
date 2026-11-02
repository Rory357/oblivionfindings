<?php

namespace App\Notifications;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The email copy of a clock-ins-from-doors notice, sent from the queue so a slow or failing mail
 * server never holds up, or stops, the in-app copies to everyone else.
 */
class QueuedDoorClockinNotification extends DoorClockinNotification implements ShouldQueue {}
