<?php

namespace App\Jobs;

use App\Models\CalendarSync;
use App\Services\GoogleCalendarService;
use App\Services\MicrosoftGraphService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncCalendarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public CalendarSync $calendarSync) {}

    public function handle(): void
    {
        $sync = $this->calendarSync;
        $user = $sync->user;

        if (!$user) {
            return;
        }

        $providerKey = $sync->provider === 'outlook' ? 'microsoft' : $sync->provider;
        $identity = $user->identities()->where('provider', $providerKey)->first();

        if (!$identity) {
            Log::warning("No identity for calendar sync #{$sync->id}");
            return;
        }

        try {
            $from = now()->subDays(7)->toIso8601String();
            $to = now()->addDays(30)->toIso8601String();

            if ($sync->provider === 'outlook' || $sync->provider === 'microsoft') {
                $service = new MicrosoftGraphService($identity);
                $events = $service->getCalendarEvents($from, $to);
            } elseif ($sync->provider === 'google') {
                $service = new GoogleCalendarService($identity);
                $events = $service->getCalendarEvents($from, $to);
            } else {
                return;
            }

            $sync->update(['last_synced_at' => now()]);
            Log::info("Calendar sync #{$sync->id} completed: " . count($events) . ' events');
        } catch (\Exception $e) {
            Log::error("Calendar sync #{$sync->id} failed: " . $e->getMessage());
        }
    }
}
