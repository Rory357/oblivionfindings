<?php

namespace App\Console\Commands;

use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationRound;
use App\Models\User;
use App\Notifications\MedicationCompetencyExpiringNotification;
use App\Notifications\MedicationOverdueNotification;
use App\Notifications\MedicationRefusalClusterNotification;
use App\Notifications\MedicationStockLowNotification;
use App\Services\MarScheduleService;
use App\Services\Medication\CompetencyPolicySettings;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\OverdueDoseAlerts;
use App\Services\Medication\RefusalEscalationPolicy;
use App\Services\UserSiteAccessService;
use App\Support\Medication\MedicationStockQuantity;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class SendMedicationAlerts extends Command
{
    protected $signature = 'emar:send-alerts';

    protected $description = 'Check for overdue medications, low stock, expiring competencies, and refusal clusters and send notifications';

    /** @var array<string, Collection<int, MedicationRound>> rounds per Site and day, for this run */
    private array $rounds = [];

    /** @var array<string, bool> whether a round assignee may be told about a Site's (controlled) dose, for this run */
    private array $recipients = [];

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

    /**
     * The overdue job (C6f): the doses the dose-slot projection calls overdue
     * — the window has ended with nothing recorded, yesterday and today —
     * raised in the Control Room (one signal per dose) with alerts for doses
     * no longer overdue resolved, and each dose's round assignee told once
     * per overdue spell.
     */
    protected function checkOverdueMedications(): void
    {
        $this->info('Checking for overdue medications...');

        $scheduleService = app(MarScheduleService::class);
        $now = Carbon::now($scheduleService->workerTimezone());
        $overdue = app(OverdueDoseAlerts::class)->sweep($now);

        $count = 0;
        foreach ($overdue as $dose) {
            $medication = $dose['order'];
            $client = $medication->client;
            $scheduledFor = Carbon::instance($dose['due_at']);

            $round = $this->roundForSlot($medication, $scheduledFor, $scheduleService);
            $staff = $round?->assignedTo;
            if (! $staff || ! ($this->recipients[$staff->id.'|'.$client->site_id.'|'.(int) $medication->controlled_drug] ??= $this->canReceiveMedicationEvidence(
                $staff,
                (int) $client->site_id,
                (bool) $medication->controlled_drug,
            ))) {
                continue;
            }

            // Once per overdue spell of a dose and person: the notification's
            // id is derived from both, so a primary-key lookup finds it after
            // a deploy's cache clear; the cache key covers notifications sent
            // before doses carried a key (a first spell only — a dose overdue
            // again is told again).
            $notificationId = Uuid::uuid5(Uuid::NAMESPACE_URL, "emar-overdue:{$staff->id}:{$dose['spell_key']}")->toString();
            if (DatabaseNotification::query()->whereKey($notificationId)->exists()) {
                continue;
            }
            $alertKey = sprintf(
                'emar:overdue-alert:user-%d.med-%d.%s',
                $staff->id,
                $medication->id,
                $scheduledFor->copy()->utc()->format('YmdHi'),
            );
            if (! Cache::add($alertKey, true, now()->addDay()) && str_ends_with($dose['spell_key'], '~1')) {
                continue;
            }

            $clientName = trim(($client->first_name ?? '').' '.($client->last_name ?? ''));

            $notification = new MedicationOverdueNotification(
                medication: $medication->name ?? 'Unknown medication',
                clientName: $clientName !== '' ? $clientName : 'Unknown client',
                scheduledTime: OverdueDoseAlerts::dueLabel($scheduledFor, $now),
                clientId: $client->id,
                doseKey: $dose['spell_key'],
            );
            $notification->id = $notificationId;
            $staff->notify($notification);
            $count++;
        }

        $this->info("Sent {$count} overdue medication alerts.");
    }

    protected function roundForSlot(ClientMedication $medication, Carbon $scheduledFor, MarScheduleService $scheduleService): ?MedicationRound
    {
        $client = $medication->client;
        if (! $client) {
            return null;
        }

        // One query per Site and day for the run.
        $rounds = $this->rounds[$client->site_id.'|'.$scheduledFor->toDateString()] ??= MedicationRound::query()
            ->whereDate('round_date', $scheduledFor->toDateString())
            ->whereNotNull('assigned_to')
            ->where('site_id', $client->site_id)
            ->with('assignedTo')
            ->get();

        return $rounds
            ->filter(fn (MedicationRound $round): bool => ! $client->service_context_id
                || $round->service_context_id === null
                || (int) $round->service_context_id === (int) $client->service_context_id)
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
     * Check for expiring competency: assessments ending within the renewal
     * reminder (Settings › Staff & PINs, default 30 days).
     * Notify the assessed user.
     */
    protected function checkExpiringCompetencies(): void
    {
        $this->info('Checking for expiring medication competencies...');

        $expiringAssessments = MedicationCompetencyAssessment::expiringSoon(app(CompetencyPolicySettings::class)->renewalReminderDays())
            ->with('user')
            ->get();

        $count = 0;
        foreach ($expiringAssessments as $assessment) {
            $user = $assessment->user;
            if (! $user) {
                continue;
            }

            // A renewal is a new assessment; the one it replaces stays 'passed'.
            $renewed = MedicationCompetencyAssessment::active()
                ->where('user_id', $user->id)
                ->where('expiry_date', '>', $assessment->expiry_date->toDateString())
                ->exists();
            if ($renewed) {
                continue;
            }

            $expiryDate = $assessment->expiry_date->format('d/m/Y');
            if ($this->alreadyNotified($user, MedicationCompetencyExpiringNotification::class, [
                'assessment_id' => $assessment->id,
                'expiry_date' => $expiryDate,
            ])) {
                continue;
            }

            $user->notify(new MedicationCompetencyExpiringNotification(
                staffName: $user->name,
                expiryDate: $expiryDate,
                assessmentId: $assessment->id,
            ));
            $count++;
        }

        $this->info("Sent {$count} competency expiry alerts.");
    }

    /**
     * Check for refusal clusters: repeated refusals or withholds of the same
     * medicine, as Rounds & timing defines them (RefusalEscalationPolicy).
     * Notify team leaders.
     */
    protected function checkRefusalClusters(): void
    {
        $this->info('Checking for medication refusal clusters...');

        $escalation = app(RefusalEscalationPolicy::class);
        $days = $escalation->days();
        $clusters = $escalation->recent()
            ->select('client_id', 'client_medication_id', DB::raw('COUNT(*) as refusal_count'))
            ->groupBy('client_id', 'client_medication_id')
            ->having('refusal_count', '>=', $escalation->threshold())
            ->tap(fn ($query) => app(MedicationGovernanceScopeService::class)
                ->scopeCanonicalClientMedicationRows($query, null, false))
            ->get();

        if ($clusters->isEmpty()) {
            $this->info('No refusal clusters detected.');

            return;
        }

        // Notify team leads (the seeded team_lead role; roles have no slug column)
        $siteAccess = app(UserSiteAccessService::class);
        $teamLeaders = User::whereHas('roles', fn ($q) => $q->where('name', 'team_lead'))
            ->get()
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
                // The cluster persists for days; remind each lead once a day.
                if ($this->alreadyNotified($entry['user'], MedicationRefusalClusterNotification::class, [
                    'client_id' => (int) $cluster->client_id,
                    'client_medication_id' => $medication->id,
                ], now()->subDay())) {
                    continue;
                }
                $entry['user']->notify(new MedicationRefusalClusterNotification(
                    clientName: $clientName,
                    medication: $medicationName,
                    count: (int) $cluster->refusal_count,
                    clientId: $cluster->client_id,
                    clientMedicationId: $medication->id,
                    days: $days,
                ));
            }
            $count++;
        }

        $this->info("Sent refusal cluster alerts for {$count} medication/client combinations.");
    }

    /**
     * Whether this recipient already holds the stored notification. The
     * database row is the de-duplication record because cache keys are
     * cleared on every deploy (optimize:clear) and these alerts span days.
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
