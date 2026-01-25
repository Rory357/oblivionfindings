<?php

namespace App\Console\Commands;

use App\Models\ClientBreakGlassAccess;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendDailyBreakGlassReport extends Command
{
    protected $signature = 'breakglass:daily-report {--date= : YYYY-MM-DD (defaults to yesterday)}';
    protected $description = 'Send a daily summary of break-glass access usage to managers.';

    public function handle(): int
    {
        $date = $this->option('date');
        $start = $date ? Carbon::parse($date)->startOfDay() : now()->subDay()->startOfDay();
        $end = $start->copy()->endOfDay();

        $items = ClientBreakGlassAccess::query()
            ->with(['client:id,first_name,last_name', 'user:id,name'])
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at')
            ->get();

        $count = $items->count();

        $lines = [];
        foreach ($items as $a) {
            $client = $a->client ? ($a->client->first_name . ' ' . $a->client->last_name) : ('Client #' . $a->client_id);
            $user = $a->user ? $a->user->name : ('User #' . $a->user_id);
            $expires = $a->expires_at ? $a->expires_at->format('Y-m-d H:i') : 'n/a';
            $lines[] = "• {$a->created_at->format('H:i')} — {$user} → {$client} (expires {$expires}) — {$a->reason}";
        }

        $title = "Break-glass daily report ({$start->toDateString()})";
        $body = $count === 0
            ? 'No break-glass access was used.'
            : "Total uses: {$count}\n\n" . implode("\n", $lines);

        // Notify managers via the existing internal notification stream.
        app(NotificationService::class)->notifyCrud(null, 'daily', 'break-glass report', null, null, [
            'title' => $title,
            'body' => $body,
            'url' => url('/medications/audit'),
        ]);

        $this->info($title);
        $this->line($body);

        return self::SUCCESS;
    }
}
