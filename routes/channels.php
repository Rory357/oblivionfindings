<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Register broadcast channel authorization callbacks. These channels are
| public (non-auth) since fleet data is shown to authenticated users
| via Inertia page loads. The channels use the Channel class (public)
| rather than PrivateChannel, so no authorization callback is needed.
|
| NOTE: Requires a broadcasting driver (Laravel Reverb or Pusher).
| Setup: composer require laravel/reverb && php artisan install:broadcasting
|
*/

// Public channels — no authorization needed
// 'fleet.vehicles'          — real-time vehicle position updates
// 'fleet.wandering-alerts'  — wandering/geofence breach alerts
// 'fleet.signals'           — all fleet signals (SOS, tamper, geofence, etc.)
