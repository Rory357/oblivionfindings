<?php

namespace App\Console\Commands;

use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationRound;
use App\Models\User;
use App\Notifications\MedicationCompetencyExpiringNotification;
use App\Notifications\MedicationOverdueNotification;
use App\Notifications\MedicationRefusalClusterNotification;
use App\Notifications\MedicationStockLowNotification;
use App\Services\MarScheduleService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\UserSiteAccessService;
use App\Support\Medication\MedicationStockQuantity;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SendMedicationAlerts extends Command
{
    protected $signature = 'emar:send-alerts';

    protected $description = 'Check for overdue medications, low stock, expiring competencies, and refusal clusters and send notifications';

    public function handle(): int
    {
        $this->info('Running eMAR medication alerts...');

        $this->checkOverdueMedications();
        $this->checkLowStock();
        $this->checkExpiringCompetencies();
        $this->checkRefusalClusters();

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
     * Check for low stock: stocks where on_hand <= reorder_level
     * AND last_reorder_alert_at is null or > 24h ago.
     * Notify users with 'medications.view' permission.
     */
    protected function checkLowStock(): void
    {
        $this->info('Checking for low medication stock...');

        $lowStocks = ClientMedicationStock::whereColumn('on_hand', '<=', 'reorder_level')
            ->where('reorder_level', '>', 0)
            ->where(function ($query) {
                $query->whereNull('last_reorder_alert_at')
                    ->orWhere('last_reorder_alert_at', '<', now()->subHours(24));
            })
            ->with('medication.client:id,first_name,last_name,site_id')
            ->get();

        if ($lowStocks->isEmpty()) {
            $this->info('No low stock alerts to send.');

            return;
        }

        // Candidate recipients: users who hold medications.view via a role OR a
        // per-user override, with canDo() making the authoritative call so
        // deny-overrides are honoured (the previous role-only query missed
        // per-user grants and ignored overrides).
        $siteAccess = app(UserSiteAccessService::class);
        $recipients = User::query()
            ->where(function ($q) {
                $q->whereHas('roles.permissions', fn ($p) => $p->where('key', 'medications.view'))
                    ->orWhereHas('permissionOverrides', fn ($p) => $p->where('permissions.key', 'medications.view'));
            })
            ->get()
            ->filter(fn (User $user) => $user->canDo('medications.view'))
            ->map(fn (User $user) => [
                'user' => $user,
                'sites' => $siteAccess->accessibleSiteIds(
                    $user,
                    MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
                ),
            ])
            ->filter(fn (array $entry) => $entry['sites'] !== [])
            ->values();

        $count = 0;
        foreach ($lowStocks as $stock) {
            $medicationName = $stock->medication?->name ?? 'Unknown medication';
            $client = $stock->medication?->client;
            $clientName = $client ? trim($client->first_name.' '.$client->last_name) : 'Unknown client';
            $stockSiteId = $client?->site_id;
            $isControlled = (bool) $stock->medication?->controlled_drug;

            foreach ($recipients as $entry) {
                if ($stockSiteId === null || ! in_array((int) $stockSiteId, $entry['sites'], true)) {
                    continue;
                }

                if ($isControlled && ! $entry['user']->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)) {
                    continue;
                }

                $entry['user']->notify(new MedicationStockLowNotification(
                    medication: $medicationName,
                    clientName: $clientName,
                    count: MedicationStockQuantity::display($stock->on_hand ?? 0),
                    unit: $stock->unit ?? 'units',
                    reorderLevel: (int) $stock->reorder_level,
                ));
            }

            // Update the last alert timestamp to prevent re-alerting within 24 hours
            $stock->update(['last_reorder_alert_at' => now()]);
            $count++;
        }

        $this->info("Sent low stock alerts for {$count} medications.");
    }

    /**
     * Check for expiring competency: assessments where expiry_date <= today + 30 days.
     * Notify the assessed user.
     */
    protected function checkExpiringCompetencies(): void
    {
        $this->info('Checking for expiring medication competencies...');

        $expiringAssessments = MedicationCompetencyAssessment::expiringSoon(30)
            ->with('user')
            ->get();

        $count = 0;
        foreach ($expiringAssessments as $assessment) {
            $user = $assessment->user;
            if (! $user) {
                continue;
            }

            $user->notify(new MedicationCompetencyExpiringNotification(
                staffName: $user->name,
                expiryDate: $assessment->expiry_date->format('d/m/Y'),
                assessmentId: $assessment->id,
            ));
            $count++;
        }

        $this->info("Sent {$count} competency expiry alerts.");
    }

    /**
     * Check for refusal clusters: clients with 3+ refusals of the same medication
     * in the last 7 days. Notify team leaders.
     */
    protected function checkRefusalClusters(): void
    {
        $this->info('Checking for medication refusal clusters...');

        $clusters = ClientMedicationAdministration::query()
            ->effectiveClinicalEvidence()
            ->where('status', 'refused')
            ->where('scheduled_for', '>=', now()->subDays(7))
            ->select('client_id', 'client_medication_id', DB::raw('COUNT(*) as refusal_count'))
            ->groupBy('client_id', 'client_medication_id')
            ->having('refusal_count', '>=', 3)
            ->tap(fn ($query) => app(MedicationGovernanceScopeService::class)
                ->scopeCanonicalClientMedicationRows($query, null, false))
            ->get();

        if ($clusters->isEmpty()) {
            $this->info('No refusal clusters detected.');

            return;
        }

        // Notify team leaders (users with team_leader role)
        $siteAccess = app(UserSiteAccessService::class);
        $teamLeaders = User::whereHas('roles', function ($q) {
            $q->where('slug', 'team-leader')
                ->orWhere('name', 'Team Leader');
        })->get()
            ->filter(fn (User $leader) => $leader->canDo('medications.view'))
            ->map(fn (User $leader) => [
                'user' => $leader,
                'sites' => $siteAccess->accessibleSiteIds(
                    $leader,
                    MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
                ),
            ])
            ->filter(fn (array $entry) => $entry['sites'] !== [])
            ->values();

        $count = 0;
        foreach ($clusters as $cluster) {
            $medication = ClientMedication::with('client')
                ->whereKey($cluster->client_medication_id)
                ->where('client_id', $cluster->client_id)
                ->first();
            $siteId = $medication?->client?->site_id;
            if (! $medication || $siteId === null) {
                continue;
            }

            $clientName = $medication->client?->full_name ?? 'Unknown client';
            $medicationName = $medication->name ?? 'Unknown medication';

            foreach ($teamLeaders as $entry) {
                if (! in_array((int) $siteId, $entry['sites'], true)) {
                    continue;
                }
                if ($medication->controlled_drug
                    && ! $entry['user']->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)) {
                    continue;
                }
                $entry['user']->notify(new MedicationRefusalClusterNotification(
                    clientName: $clientName,
                    medication: $medicationName,
                    count: (int) $cluster->refusal_count,
                    clientId: $cluster->client_id,
                ));
            }
            $count++;
        }

        $this->info("Sent refusal cluster alerts for {$count} medication/client combinations.");
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
