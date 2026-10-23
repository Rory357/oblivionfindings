<?php

namespace App\Console\Commands;

use App\Services\Incidents\IncidentRoutingService;
use App\Services\Incidents\IncidentTriageDeliveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Technical recovery only; care response and escalation times remain organisation policy. */
final class RecoverIncidentRouting extends Command
{
    protected $signature = 'incidents:recover-routing {--limit=100 : Maximum records per bounded recovery pass}';

    protected $description = 'Recover committed incident routing and due monitored owner-triage deliveries';

    private const CURSOR_KEY = 'health-safety.incident-routing.recovery-cursors';

    public function handle(IncidentRoutingService $routing, IncidentTriageDeliveryService $triage): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) {
            $this->error('The recovery limit must be a whole number between 1 and 100.');
            return self::INVALID;
        }
        $saved = Cache::get(self::CURSOR_KEY);
        $cursor = static function (string $key) use ($saved): int {
            $value = is_array($saved) ? ($saved[$key] ?? null) : null;
            return is_int($value) && $value >= 0 ? $value : 0;
        };
        $result = $routing->sweep($limit, afterIncidentId: $cursor('incident'), afterIntentId: $cursor('intent'));
        // Losing the cache restarts a repeat-safe scan; it cannot invent ownership or erase an intent.
        Cache::forever(self::CURSOR_KEY, [
            'incident' => $result['next_incident_cursor'] ?? 0,
            'intent' => $result['next_intent_cursor'] ?? 0,
        ]);
        $delivery = $triage->deliverDue($limit);
        $this->line(sprintf('Routing attempts: %d; routing failures: %d; triage attempts: %d; confirmed deliveries: %d; delivery failures: %d.',
            $result['attempted'], $result['failed'], $delivery['attempted'], $delivery['confirmed'], $delivery['failed']));

        return $result['failed'] + $delivery['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
