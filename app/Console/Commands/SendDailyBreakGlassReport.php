<?php

namespace App\Console\Commands;

use App\Models\ClientBreakGlassAccess;
use App\Services\Medication\Alerts\MedicationAlertCatalogue;
use App\Services\Medication\Alerts\MedicationAlerts;
use App\Services\Medication\Alerts\MedicationAlertSubject;
use App\Services\Medication\DoseSlots\DoseSlotRules;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendDailyBreakGlassReport extends Command
{
    protected $signature = 'breakglass:daily-report {--date= : NZ calendar day YYYY-MM-DD (defaults to yesterday)}';

    protected $description = 'Send the NZ-day emergency-access report using saved reviewer groups and delivery channels.';

    public function handle(MedicationAlerts $alerts): int
    {
        $date = $this->option('date');
        if ($date && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || ! DoseSlotRules::isCalendarDate($date))) {
            $this->error('Use a valid NZ calendar day, YYYY-MM-DD.');

            return self::FAILURE;
        }
        $start = $date ? CarbonImmutable::parse($date, 'Pacific/Auckland')->startOfDay()
            : CarbonImmutable::now('Pacific/Auckland')->subDay()->startOfDay();
        $end = $start->addDay();
        $base = ClientBreakGlassAccess::withTrashed()->whereHas('client')->with(['client.site', 'user:id,name']);
        $used = (clone $base)->where('created_at', '>=', $start->utc())->where('created_at', '<', $end->utc())->get();
        $waiting = (clone $base)->whereNull('review_outcome')
            ->where(fn ($w) => $w->whereNotNull('deleted_at')->orWhereNotNull('ended_at')->orWhere('expires_at', '<=', now()))->get();
        $siteIds = $used->merge($waiting)->pluck('client.site_id')->filter()->unique();
        foreach ($siteIds as $siteId) {
            $items = $used->filter(fn ($g) => (int) $g->client->site_id === (int) $siteId);
            $reviews = $waiting->filter(fn ($g) => (int) $g->client->site_id === (int) $siteId);
            $overdue = $reviews->filter(fn ($g) => $g->reviewDueTime()?->lt(now()))->count();
            $counts = $items->count().' grants used on '.$start->format('D j M').' (midnight to midnight, NZ). '
                .$reviews->count().' still to review — '.$overdue.' overdue.';
            $lines = $items->merge($reviews)->unique('id')->sortBy('created_at')->map(fn ($g) => 'EA-'.$g->id.' · '.$g->client->full_name.' · '.($g->user?->name ?? 'Staff member')
                .' · '.$g->created_at->copy()->timezone('Pacific/Auckland')->format('D j M g:i a')
                .' · '.($g->isRunning() ? 'Running' : 'Ended')
                .' · '.($g->review_outcome ? str_replace('_', ' ', $g->review_outcome)
                    : 'Review due '.$g->reviewDueTime()?->copy()->timezone('Pacific/Auckland')->format('D j M g:i a')));
            // All configured groups/named people/fallbacks still require audit.view and this house.
            // A day/house identity de-duplicates retries using the P11 alert ledger.
            $alerts->raise(MedicationAlertCatalogue::BREAKGLASS, new MedicationAlertSubject(
                key: 'daily:'.$start->toDateString().':'.$siteId, siteId: (int) $siteId,
                title: 'Emergency access daily report', message: $counts."\n".$lines->implode("\n"),
                shortMessage: $counts, actionUrl: '/emar/emergency-access?view=review&site_id='.$siteId,
                severity: $overdue ? 'high' : 'info',
                context: ['emergency_access_review_report' => 1, 'nz_date' => $start->toDateString(),
                    'used_count' => $items->count(), 'awaiting_review' => $reviews->count(), 'overdue_review' => $overdue],
            ));
        }
        $this->info('NZ emergency access reports recorded for '.$start->toDateString().'.');

        return self::SUCCESS;
    }
}
