<?php

namespace App\Console\Commands;

use App\Models\ClientMedication;
use App\Models\MedicationRound;
use App\Models\User;
use App\Notifications\MedicationOverdueNotification;
use App\Services\MarScheduleService;
use App\Services\Medication\Alerts\MedicationAlertSources;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\OverdueDoseAlerts;
use App\Services\UserSiteAccessService;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Ramsey\Uuid\Uuid;

class SendMedicationAlerts extends Command
{
    protected $signature = 'emar:send-alerts';

    protected $description = 'Check for overdue medications, low stock, expiring competencies, refusal clusters and overdue follow-ups and send notifications';

    /** @var array<string, Collection<int, MedicationRound>> rounds per Site and day, for this run */
    private array $rounds = [];

    /** @var array<string, bool> whether a round assignee may be told about a Site's (controlled) dose, for this run */
    private array $recipients = [];

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
