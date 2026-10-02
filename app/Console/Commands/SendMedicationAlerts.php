<?php

namespace App\Console\Commands;

use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationRound;
use App\Models\User;
use App\Notifications\MedicationOverdueNotification;
use App\Services\MarScheduleService;
use App\Services\Medication\Alerts\MedicationAlertSources;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\UserSiteAccessService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class SendMedicationAlerts extends Command
{
    protected $signature = 'emar:send-alerts';

    protected $description = 'Check for overdue medications, low stock, expiring competencies, refusal clusters and overdue follow-ups and send notifications';

    public function handle(MedicationAlertSources $alerts): int
    {
        $this->info('Running eMAR medication alerts...');

        $this->checkOverdueMedications();
        // Who is told comes from Medication Settings › Alerts & access (P11 B2).
        $alerts->lowStock();
        $alerts->renewals();
        $alerts->refusalClusters();
        $alerts->overdueFollowUps();

        $this->info('eMAR medication alerts complete.');

        return self::SUCCESS;
    }

    protected function checkOverdueMedications(): void
    {
        $this->info('Checking for overdue medications...');

        $scheduleService = app(MarScheduleService::class);
        $now = Carbon::now($scheduleService->workerTimezone());
        $lookbackStart = $now->copy()->subDay()->startOfDay();
        $lookbackEnd = $now->copy()->startOfDay();

        $medications = ClientMedication::query()
            ->active()
            ->where('is_prn', false)
            ->whereHas('client', fn ($client) => $client->whereNotNull('site_id'))
            ->where(function ($query) {
                $query->whereNotNull('dose_times')
                    ->orWhereNotNull('frequency');
            })
            ->with('client:id,first_name,last_name,site_id,service_context_id,suppress_med_admin_alerts')
            ->get();

        $count = 0;
        foreach ($medications as $medication) {
            $client = $medication->client;
            if (! $client || $client->suppress_med_admin_alerts) {
                continue;
            }

            $day = $lookbackStart->copy();

            while ($day->lessThanOrEqualTo($lookbackEnd)) {
                foreach ($scheduleService->scheduledTimesForDate($medication, $day) as $scheduledFor) {
                    [, $slotWindowEnd] = $scheduleService->windowForScheduled($scheduledFor);
                    if ($slotWindowEnd->greaterThanOrEqualTo($now)) {
                        continue;
                    }

                    [$slotStartUtc, $slotEndUtc] = $scheduleService->utcSlotWindow($scheduledFor);
                    $hasAdministration = ClientMedicationAdministration::query()
                        ->effectiveClinicalEvidence()
                        ->where('client_id', $client->id)
                        ->where('client_medication_id', $medication->id)
                        ->whereBetween('scheduled_for', [$slotStartUtc, $slotEndUtc])
                        ->exists();

                    if ($hasAdministration) {
                        continue;
                    }

                    $round = $this->roundForSlot($medication, $scheduledFor, $scheduleService);
                    $staff = $round?->assignedTo;
                    if (! $staff || ! $this->canReceiveMedicationEvidence(
                        $staff,
                        (int) $client->site_id,
                        (bool) $medication->controlled_drug,
                    )) {
                        continue;
                    }

                    $alertKey = sprintf(
                        'emar:overdue-alert:user-%d.med-%d.%s',
                        $staff->id,
                        $medication->id,
                        $scheduledFor->copy()->utc()->format('YmdHi'),
                    );

                    if (! Cache::add($alertKey, true, now()->addDay())) {
                        continue;
                    }

                    $clientName = trim(($client->first_name ?? '').' '.($client->last_name ?? ''));

                    $staff->notify(new MedicationOverdueNotification(
                        medication: $medication->name ?? 'Unknown medication',
                        clientName: $clientName !== '' ? $clientName : 'Unknown client',
                        scheduledTime: $scheduledFor->format('H:i'),
                        clientId: $client->id,
                    ));
                    $count++;
                }

                $day->addDay();
            }
        }

        $this->info("Sent {$count} overdue medication alerts.");
    }

    protected function roundForSlot(ClientMedication $medication, Carbon $scheduledFor, MarScheduleService $scheduleService): ?MedicationRound
    {
        $client = $medication->client;
        if (! $client) {
            return null;
        }

        return MedicationRound::query()
            ->whereDate('round_date', $scheduledFor->toDateString())
            ->whereNotNull('assigned_to')
            ->whereNotNull('site_id')
            ->where('site_id', $client->site_id)
            ->with('assignedTo')
            ->when($client->service_context_id, fn ($query) => $query->where(function ($scope) use ($client) {
                $scope->whereNull('service_context_id')->orWhere('service_context_id', $client->service_context_id);
            }))
            ->get()
            ->first(function (MedicationRound $round) use ($scheduledFor, $scheduleService) {
                if (! $round->scheduled_time) {
                    return false;
                }

                $roundDate = $scheduleService->dateFromInput($round->round_date?->toDateString());
                $roundTime = $roundDate->copy()->setTimeFromTimeString($round->scheduled_time);
                $windowMinutes = max(0, (int) ($round->window_minutes ?? 60));

                return $scheduledFor->betweenIncluded(
                    $roundTime->copy()->subMinutes($windowMinutes),
                    $roundTime->copy()->addMinutes($windowMinutes),
                );
            });
    }

    /**
     * Whether this recipient already holds the stored notification. The
     * database row is the de-duplication record because cache keys are
     * cleared on every deploy (optimize:clear) and these alerts span days.
     * Kept for the overdue check's per-dose de-duplication (P01 C6(f)); the
     * other alerts de-duplicate on their open alert-log record (P11 B2).
     *
     * @param  array<string, int|string>  $data
     */
    private function alreadyNotified(User $recipient, string $type, array $data, ?Carbon $since = null): bool
    {
        $query = $recipient->notifications()->where('type', $type);
        foreach ($data as $key => $value) {
            $query->where("data->{$key}", $value);
        }

        return $query
            ->when($since, fn ($sent) => $sent->where('created_at', '>=', $since))
            ->exists();
    }

    private function canReceiveMedicationEvidence(User $recipient, int $siteId, bool $controlled): bool
    {
        if ($siteId <= 0 || ! $recipient->canDo('medications.view')) {
            return false;
        }
        if ($controlled && ! $recipient->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)) {
            return false;
        }

        return in_array(
            $siteId,
            app(UserSiteAccessService::class)->accessibleSiteIds(
                $recipient,
                MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
            ),
            true,
        );
    }
}
