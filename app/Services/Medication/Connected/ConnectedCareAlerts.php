<?php

namespace App\Services\Medication\Connected;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationBackupSchedule;
use App\Models\MedicationExternalProposal;
use App\Models\MedicationPharmacyDispatch;
use App\Models\Site;
use App\Services\Medication\Alerts\MedicationAlertCatalogue;
use App\Services\Medication\Alerts\MedicationAlerts;
use App\Services\Medication\Alerts\MedicationAlertSubject;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * B10 (EA-138, EA-142): connected-care failures reach people instead of
 * waiting inside one order's dialog or a scheduler counter. Raised through
 * the medication alerts, so Settings › Alerts decides who is told; offered
 * only while the feature runs. An alert never stops the record or job that
 * raised it, and it is raised only after that record commits.
 */
final class ConnectedCareAlerts
{
    /** Dispatch states and codes someone has to act on. */
    public const DISPATCH_NEEDS_CHECK = ['rejected', 'unknown'];

    public function __construct(private readonly MedicationAlerts $alerts) {}

    /** After a dispatch changes: alert while it needs checking, close once settled. */
    public function dispatchChanged(int $dispatchId): void
    {
        DB::afterCommit(fn () => $this->safely(function () use ($dispatchId): void {
            $dispatch = MedicationPharmacyDispatch::query()->find($dispatchId);
            if ($dispatch === null) {
                return;
            }
            $key = 'dispatch:'.$dispatch->id;
            $contradicts = $dispatch->acknowledgment_code === 'contradicts_recorded_check';
            if (! $contradicts && ! in_array($dispatch->state, self::DISPATCH_NEEDS_CHECK, true)) {
                $this->alerts->resolve(MedicationAlertCatalogue::PHARMACY_ORDER, $key, 'Settled');

                return;
            }
            $client = Client::query()->with('site:id,name')->find($dispatch->client_id);
            $medication = ClientMedication::query()->find($dispatch->client_medication_id);
            if ($client === null || $medication === null || $client->site_id === null) {
                return;
            }
            $house = (string) ($client->site?->name ?? 'their house');
            $person = $this->person($client);
            [$title, $what] = match (true) {
                $contradicts => ['Pharmacy answered a settled order', 'the pharmacy accepted an order staff had already settled by hand — check for a duplicate supply'],
                $dispatch->state === 'rejected' => ['Pharmacy rejected an order', 'the pharmacy rejected the order — arrange supply another way'],
                default => ['Pharmacy order result unknown', 'the order’s send has an unknown result — phone the pharmacy before sending again'],
            };
            $this->alerts->raise(MedicationAlertCatalogue::PHARMACY_ORDER, new MedicationAlertSubject(
                key: $key,
                siteId: (int) $client->site_id,
                title: $title,
                message: sprintf('%s for %s: %s. %s.', $medication->name, $person, $what, $house),
                shortMessage: "A pharmacy order at {$house} needs checking.",
                actionUrl: '/emar/stock?view=orders',
                severity: $dispatch->state === 'rejected' || $contradicts ? 'critical' : 'warning',
                clientId: (int) $client->id,
                controlled: (bool) $medication->controlled_drug,
                context: ['client_id' => (int) $client->id, 'client_medication_id' => (int) $medication->id, 'pharmacy_order_id' => (int) $dispatch->pharmacy_order_id, 'dispatch_id' => (int) $dispatch->id],
            ));
        }));
    }

    /**
     * A house's backup did not happen today. One alert per house per NZ day
     * (raising again while it is open tells nobody); `$reason` is one of
     * failed, uncertain, authority_lapsed, not_configured.
     */
    public function backupProblem(MedicationBackupSchedule|int $schedule, string $day, string $reason, ?string $reference = null): void
    {
        $scheduleId = $schedule instanceof MedicationBackupSchedule ? (int) $schedule->id : $schedule;
        DB::afterCommit(fn () => $this->safely(function () use ($scheduleId, $day, $reason, $reference): void {
            $schedule = MedicationBackupSchedule::query()->find($scheduleId);
            // The job runs every minute: an open alert for today stays the one alert.
            if ($schedule === null || $this->alerts->isOpen(MedicationAlertCatalogue::BACKUP_FAILED, 'backup:'.$schedule->site_id.':'.$day)) {
                return;
            }
            $house = (string) (Site::query()->whereKey($schedule->site_id)->value('name') ?? 'a house');
            $what = match ($reason) {
                'uncertain' => 'was sent, but whether it arrived is unknown. It won’t be resent automatically — ask the recipients, then decide',
                'authority_lapsed' => 'didn’t run: the person who set the schedule can no longer manage backups. Save the schedule again as someone who can',
                'not_configured' => 'didn’t run: strong PDF encryption isn’t set up',
                default => 'failed and wasn’t emailed',
            };
            $this->alerts->raise(MedicationAlertCatalogue::BACKUP_FAILED, new MedicationAlertSubject(
                key: 'backup:'.$schedule->site_id.':'.$day,
                siteId: (int) $schedule->site_id,
                title: 'Protected backup failed',
                message: sprintf('Today’s protected chart backup for %s%s %s.', $house, $reference ? ' ('.$reference.')' : '', $what),
                shortMessage: "Today’s protected chart backup for {$house} needs checking.",
                actionUrl: '/emar/backups',
                severity: 'warning',
                context: ['site_id' => (int) $schedule->site_id, 'nz_date' => $day, 'reason' => $reason],
            ));
        }));
    }

    /** Today's backup for the house was delivered: close its alert. */
    public function backupDelivered(int $siteId, string $day): void
    {
        DB::afterCommit(fn () => $this->safely(fn () => $this->alerts->resolve(MedicationAlertCatalogue::BACKUP_FAILED, 'backup:'.$siteId.':'.$day, 'Backup delivered')));
    }

    /** EA-025: an outside prescriber's request is waiting for a decision. */
    public function prescriberRequest(int $proposalId): void
    {
        DB::afterCommit(fn () => $this->safely(function () use ($proposalId): void {
            $proposal = MedicationExternalProposal::query()->find($proposalId);
            $client = $proposal ? Client::query()->with('site:id,name')->find($proposal->client_id) : null;
            if ($proposal === null || $client === null || $client->site_id === null || $proposal->status !== 'submitted') {
                return;
            }
            $house = (string) ($client->site?->name ?? 'their house');
            $kind = match ($proposal->kind) {
                'stop' => 'stop a medicine',
                'change' => 'change a medicine',
                default => 'start a medicine',
            };
            $this->alerts->raise(MedicationAlertCatalogue::PRESCRIBER_REQUEST, new MedicationAlertSubject(
                key: 'proposal:'.$proposal->id,
                siteId: (int) $client->site_id,
                title: 'Prescriber request waiting',
                message: sprintf('An outside prescriber asked to %s for %s. The chart hasn’t changed — decide the request in Connected care. %s.', $kind, $this->person($client), $house),
                shortMessage: "A prescriber request at {$house} is waiting for a decision.",
                actionUrl: '/emar/connected-care?'.http_build_query(['client_id' => $client->id, 'tab' => 'requests']),
                severity: $proposal->kind === 'stop' ? 'critical' : 'warning',
                clientId: (int) $client->id,
                controlled: (bool) $proposal->controlled,
                context: ['client_id' => (int) $client->id, 'proposal_id' => (int) $proposal->id],
            ));
        }));
    }

    public function prescriberRequestDecided(int $proposalId): void
    {
        DB::afterCommit(fn () => $this->safely(fn () => $this->alerts->resolve(MedicationAlertCatalogue::PRESCRIBER_REQUEST, 'proposal:'.$proposalId, 'Decided')));
    }

    private function person(Client $client): string
    {
        $first = trim((string) $client->first_name);
        $last = trim((string) $client->last_name);

        return trim($first.($last !== '' ? ' '.mb_substr($last, 0, 1).'.' : '')) ?: 'Someone';
    }

    private function safely(callable $raise): void
    {
        try {
            $raise();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
