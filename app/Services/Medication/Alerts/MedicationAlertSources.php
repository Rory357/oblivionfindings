<?php

namespace App\Services\Medication\Alerts;

use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\ControlledDrugLossReport;
use App\Models\MedicationAlert;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationError;
use App\Models\MedicationRefusalFollowup;
use App\Models\MedicationReview;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\CompetencyPolicySettings;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\RefusalEscalationPolicy;
use App\Support\Medication\MedicationStockQuantity;
use App\Support\WorkerClock;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Where each medication alert comes from (eMAR P11 B2): the checks and events
 * that raise the alerts Medication Settings › Alerts & access controls, worded
 * like v5's samples (`MESSAGE_SAMPLE`).
 *
 * Scheduled checks raise an alert for each subject still true and mark the
 * rest dealt with, so the same subject alerts again only after it was put
 * right. Events (a discrepancy, a loss report, an error, a blocked as-needed
 * dose) raise once, after the record is committed. An alert never stops the
 * record that raised it: a failure is logged, and the record stands.
 */
class MedicationAlertSources
{
    public function __construct(private readonly MedicationAlerts $alerts) {}

    /** Stock running low: on hand at or below its reorder level (every 15 minutes). */
    public function lowStock(): void
    {
        $this->safely('stock', function (): void {
            $low = ClientMedicationStock::query()
                ->whereColumn('on_hand', '<=', 'reorder_level')
                ->where('reorder_level', '>', 0)
                ->with('medication.client.site:id,name')
                ->get()
                ->filter(fn (ClientMedicationStock $stock): bool => $stock->medication?->client?->site_id !== null);

            foreach ($low as $stock) {
                $this->alerts->raise(MedicationAlertCatalogue::STOCK, $this->stockSubject(
                    $stock,
                    'stock:'.$stock->id,
                    'Stock running low',
                    fn (string $medicine, string $person, string $house): string => sprintf(
                        '%s for %s is below its reorder level (%s left). %s.',
                        $medicine,
                        $person,
                        MedicationStockQuantity::display($stock->on_hand ?? 0),
                        $house,
                    ),
                    fn (string $house): string => "A medicine at {$house} is running low.",
                ));
            }
            $this->alerts->reconcile(
                MedicationAlertCatalogue::STOCK,
                $low->map(fn (ClientMedicationStock $stock): string => 'stock:'.$stock->id)->values()->all(),
                'Restocked',
            );
        });
    }

    /**
     * The 6:00 am stock check: stock expiring within 30 days, expired stock,
     * and stock that has run out.
     *
     * @param  Collection<int, ClientMedicationStock>  $expiringSoon
     * @param  Collection<int, ClientMedicationStock>  $expired
     * @param  Collection<int, ClientMedicationStock>  $lowStock
     */
    public function stockCheck(Collection $expiringSoon, Collection $expired, Collection $lowStock): void
    {
        $this->safely('expiry', function () use ($expiringSoon): void {
            $keys = [];
            foreach ($expiringSoon as $stock) {
                if ($stock->expiry_date === null || $stock->medication?->client?->site_id === null) {
                    continue;
                }
                $key = 'expiry:'.$stock->id.':'.$stock->expiry_date->toDateString();
                $keys[] = $key;
                $days = WorkerClock::daysUntil($stock->expiry_date);
                $this->alerts->raise(MedicationAlertCatalogue::EXPIRY, $this->stockSubject(
                    $stock,
                    $key,
                    'Stock expiring',
                    fn (string $medicine, string $person, string $house): string => sprintf(
                        '%s for %s expires on %s. %s.',
                        $medicine,
                        $person,
                        $stock->expiry_date->format('j M Y'),
                        $house,
                    ),
                    fn (string $house): string => "A medicine at {$house} expires soon.",
                    $days <= 7 ? 'critical' : 'warning',
                ));
            }
            $this->alerts->reconcile(MedicationAlertCatalogue::EXPIRY, $keys, 'Removed or replaced');
        });

        $this->safely('outOfStock', function () use ($expired, $lowStock): void {
            $keys = [];
            foreach ($expired as $stock) {
                if ($stock->expiry_date === null || $stock->medication?->client?->site_id === null) {
                    continue;
                }
                $key = 'expired:'.$stock->id.':'.$stock->expiry_date->toDateString();
                $keys[] = $key;
                $this->alerts->raise(MedicationAlertCatalogue::OUT_OF_STOCK, $this->stockSubject(
                    $stock,
                    $key,
                    'Expired stock',
                    fn (string $medicine, string $person, string $house): string => sprintf(
                        '%s for %s expired on %s. %s.',
                        $medicine,
                        $person,
                        $stock->expiry_date->format('j M Y'),
                        $house,
                    ),
                    fn (string $house): string => "A medicine at {$house} has expired stock.",
                    'critical',
                ));
            }
            $out = $lowStock->filter(fn (ClientMedicationStock $stock): bool => MedicationStockQuantity::lessThanOrEqual($stock->on_hand ?? 0, 0)
                && $stock->medication?->client?->site_id !== null);
            foreach ($out as $stock) {
                $key = 'out:'.$stock->id;
                $keys[] = $key;
                $this->alerts->raise(MedicationAlertCatalogue::OUT_OF_STOCK, $this->stockSubject(
                    $stock,
                    $key,
                    'Out of stock',
                    fn (string $medicine, string $person, string $house): string => "{$medicine} for {$person} is out of stock. {$house}.",
                    fn (string $house): string => "A medicine at {$house} is out of stock.",
                    'critical',
                ));
            }
            $this->alerts->reconcile(MedicationAlertCatalogue::OUT_OF_STOCK, $keys, 'Restocked or removed');
        });
    }

    /** Competency renewals due: within the renewal reminder (Staff & PINs), not yet renewed. */
    public function renewals(): void
    {
        $this->safely('renewals', function (): void {
            $keys = [];
            $assessments = MedicationCompetencyAssessment::expiringSoon(app(CompetencyPolicySettings::class)->renewalReminderDays())
                ->with('user.hrEmployeeProfile')
                ->get();
            foreach ($assessments as $assessment) {
                $user = $assessment->user;
                if (! $user instanceof User || $assessment->expiry_date === null) {
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
                $key = 'renewal:'.$assessment->id.':'.$assessment->expiry_date->toDateString();
                $keys[] = $key;
                $siteId = $user->hrEmployeeProfile?->primary_site_id;
                $this->alerts->raise(MedicationAlertCatalogue::RENEWALS, new MedicationAlertSubject(
                    key: $key,
                    siteId: $siteId !== null ? (int) $siteId : null,
                    title: 'Competency renewal due',
                    message: "{$user->name}’s medication competency ends on {$assessment->expiry_date->format('j M Y')}. Book a reassessment.",
                    shortMessage: 'A competency renewal is due.',
                    actionUrl: '/emar/safety/eligibility?view=renewals',
                    staffUserId: (int) $user->id,
                    context: ['assessment_id' => (int) $assessment->id],
                ));
            }
            $this->alerts->reconcile(MedicationAlertCatalogue::RENEWALS, $keys, 'Renewed');
        });
    }

    /** Repeated refusals: refusals or withholds of one medicine, as Rounds & timing defines them. */
    public function refusalClusters(): void
    {
        $this->safely('refusals', function (): void {
            $escalation = app(RefusalEscalationPolicy::class);
            $clusters = $escalation->recent()
                ->select('client_id', 'client_medication_id', DB::raw('COUNT(*) as refusal_count'))
                ->groupBy('client_id', 'client_medication_id')
                ->having('refusal_count', '>=', $escalation->threshold())
                ->tap(fn ($query) => app(MedicationGovernanceScopeService::class)
                    ->scopeCanonicalClientMedicationRows($query, null, false))
                ->get();

            $keys = [];
            foreach ($clusters as $cluster) {
                $order = ClientMedication::query()
                    ->with('client.site:id,name')
                    ->whereKey($cluster->client_medication_id)
                    ->where('client_id', $cluster->client_id)
                    ->first();
                if (! $order || $order->client?->site_id === null) {
                    continue;
                }
                $key = 'refusals:'.$order->id;
                $keys[] = $key;
                $house = $this->house($order->client);
                $this->alerts->raise(MedicationAlertCatalogue::REFUSALS, new MedicationAlertSubject(
                    key: $key,
                    siteId: (int) $order->client->site_id,
                    title: 'Repeated refusals',
                    // The policy counts refusals and withholds (Rounds & timing).
                    message: sprintf(
                        '%s — %s refused or withheld %d times in %d days. %s.',
                        $this->person($order->client),
                        $order->name,
                        (int) $cluster->refusal_count,
                        $escalation->days(),
                        $house,
                    ),
                    shortMessage: "Repeated refusals at {$house}.",
                    actionUrl: '/emar/mar?client_id='.$order->client_id,
                    clientId: (int) $order->client_id,
                    controlled: (bool) $order->controlled_drug,
                    context: ['client_id' => (int) $order->client_id, 'client_medication_id' => (int) $order->id],
                ));
            }
            $this->alerts->reconcile(MedicationAlertCatalogue::REFUSALS, $keys, 'No longer repeating');
        });
    }

    /** Follow-ups overdue: refusal follow-ups past their due time and not completed (B2 Q6). */
    public function overdueFollowUps(): void
    {
        $this->safely('followups', function (): void {
            $keys = [];
            $followUps = MedicationRefusalFollowup::query()
                ->overdue()
                ->with(['client.site:id,name', 'administration.medication:id,name,controlled_drug'])
                ->get();
            foreach ($followUps as $followUp) {
                $client = $followUp->client;
                if (! $client instanceof Client || $client->site_id === null) {
                    continue;
                }
                $medicine = $followUp->administration?->medication;
                $key = 'refusal-followup:'.$followUp->id;
                $keys[] = $key;
                $house = $this->house($client);
                $this->alerts->raise(MedicationAlertCatalogue::FOLLOW_UPS, new MedicationAlertSubject(
                    key: $key,
                    siteId: (int) $client->site_id,
                    title: 'Follow-up overdue',
                    message: sprintf(
                        '%s — refusal follow-up%s was due %s. %s.',
                        $this->person($client),
                        $medicine ? ' for '.$medicine->name : '',
                        $followUp->follow_up_due_at->copy()->timezone(config('app.worker_timezone', 'Pacific/Auckland'))->format('j M Y, g:i a'),
                        $house,
                    ),
                    shortMessage: "A follow-up at {$house} is overdue.",
                    actionUrl: '/emar/mar?client_id='.$client->id,
                    clientId: (int) $client->id,
                    controlled: (bool) $medicine?->controlled_drug,
                    context: ['client_id' => (int) $client->id, 'refusal_followup_id' => (int) $followUp->id],
                ));
            }
            $this->alerts->reconcile(MedicationAlertCatalogue::FOLLOW_UPS, $keys, 'Resolved');
        });
    }

    /**
     * Controlled-drug balance check overdue, one alert per house (v5: "No
     * controlled-drug balance check at Rimu House for 7 days").
     *
     * @param  Collection<int, ClientMedication>  $overdue  Controlled orders with no check within the threshold.
     */
    public function controlledChecks(Collection $overdue, int $days): void
    {
        $this->safely('cdCheck', function () use ($overdue, $days): void {
            $keys = [];
            $bySite = $overdue
                ->filter(fn (ClientMedication $order): bool => $order->client?->site_id !== null)
                ->groupBy(fn (ClientMedication $order): int => (int) $order->client->site_id);
            foreach ($bySite as $siteId => $orders) {
                $key = 'cd-check:'.$siteId;
                $keys[] = $key;
                $house = (string) (Site::query()->whereKey($siteId)->value('name') ?? 'this house');
                $this->alerts->raise(MedicationAlertCatalogue::CD_CHECK, new MedicationAlertSubject(
                    key: $key,
                    siteId: (int) $siteId,
                    title: 'Controlled-drug balance check overdue',
                    message: sprintf(
                        'No controlled-drug balance check at %s for %d days (%d %s).',
                        $house,
                        $days,
                        $orders->count(),
                        $orders->count() === 1 ? 'medicine' : 'medicines',
                    ),
                    shortMessage: "A controlled-drug check at {$house} is overdue.",
                    actionUrl: '/emar/controlled-drugs',
                    controlled: true,
                    context: ['client_medication_ids' => $orders->pluck('id')->map(fn ($id): int => (int) $id)->implode(',')],
                ));
            }
            $this->alerts->reconcile(MedicationAlertCatalogue::CD_CHECK, $keys, 'Balance check done');
        });
    }

    /** A balance check was recorded: the house's alert is dealt with once no order there is overdue. */
    public function controlledCheckRecorded(int $siteId, int $days = 7): void
    {
        $this->safely('cdCheck', function () use ($siteId, $days): void {
            $orders = ClientMedication::query()
                ->active()
                ->controlled()
                ->whereHas('client', fn ($client) => $client->where('site_id', $siteId))
                ->pluck('id');
            $cutoff = now()->subDays($days);
            $checked = ClientControlledDrugEntry::query()
                ->where('entry_type', 'balance_check')
                ->whereIn('client_medication_id', $orders->all())
                ->where('recorded_at', '>=', $cutoff)
                ->distinct()
                ->pluck('client_medication_id');
            if ($orders->diff($checked)->isEmpty()) {
                $this->alerts->resolve(MedicationAlertCatalogue::CD_CHECK, 'cd-check:'.$siteId, 'Balance check done');
            }
        });
    }

    /**
     * One person's orders, when their alerts are refreshed (daily at 7:05 am
     * and after recording): as-needed doses at their daily limit, chart,
     * medication and INR reviews due, and open controlled-drug discrepancies.
     *
     * @param  Collection<int, ClientMedication>  $orders  Their active orders.
     */
    public function forClient(Client $client, Collection $orders): void
    {
        if ($client->site_id === null) {
            return;
        }
        $client->loadMissing('site:id,name');
        $house = $this->house($client);
        $person = $this->person($client);
        $scope = ['client_id' => (int) $client->id];

        $this->safely('prnLimit', function () use ($client, $orders, $house, $person, $scope): void {
            $keys = [];
            foreach ($orders->filter(fn (ClientMedication $order): bool => (bool) $order->is_prn && $order->max_per_day) as $order) {
                $limit = (int) filter_var($order->max_per_day, FILTER_SANITIZE_NUMBER_INT);
                if ($limit <= 0 || $order->prnCountLast24Hours < $limit) {
                    continue;
                }
                $key = 'prn:'.$order->id;
                $keys[] = $key;
                $this->alerts->raise(MedicationAlertCatalogue::PRN_LIMIT, $this->prnSubject($client, $order, $key, $house, $person));
            }
            $this->alerts->reconcile(MedicationAlertCatalogue::PRN_LIMIT, $keys, 'Back under the daily limit', $scope);
        });

        $this->safely('reviewDue', function () use ($client, $orders, $house, $person, $scope): void {
            $keys = [];
            $raise = function (string $key, string $what, CarbonInterface $due) use (&$keys, $client, $house, $person): void {
                $keys[] = $key;
                $overdue = WorkerClock::daysUntil($due) < 0;
                $this->alerts->raise(MedicationAlertCatalogue::REVIEW_DUE, new MedicationAlertSubject(
                    key: $key,
                    siteId: (int) $client->site_id,
                    title: 'Medication review due',
                    message: sprintf('%s’s %s is %s %s. %s.', $person, $what, $overdue ? 'overdue since' : 'due by', $due->format('j M Y'), $house),
                    shortMessage: 'A medication review is due.',
                    actionUrl: '/clients/'.$client->id,
                    severity: $overdue ? 'critical' : 'warning',
                    clientId: (int) $client->id,
                    context: ['client_id' => (int) $client->id],
                ));
            };
            if ($client->next_chart_review_date && WorkerClock::daysUntil($client->next_chart_review_date) <= 7) {
                $raise('chart-review:'.$client->id.':'.$client->next_chart_review_date->toDateString(), 'medication chart review', $client->next_chart_review_date);
            }
            $review = MedicationReview::query()
                ->where('client_id', $client->id)
                ->whereIn('status', ['scheduled', 'overdue'])
                ->get()
                ->map(fn (MedicationReview $r): array => [$r, $r->next_review_date ?? $r->scheduled_date])
                ->filter(fn (array $pair): bool => $pair[1] !== null && WorkerClock::daysUntil($pair[1]) <= 7)
                ->sortBy(fn (array $pair): string => $pair[1]->toDateString())
                ->first();
            if ($review) {
                $raise('medication-review:'.$review[0]->id.':'.$review[1]->toDateString(), 'medication review', $review[1]);
            }
            foreach ($orders->filter(fn (ClientMedication $order): bool => str_contains(strtolower((string) $order->name), 'warfarin')) as $order) {
                $latest = $client->inrRecords()
                    ->active()
                    ->where(fn ($query) => $query->whereNull('client_medication_id')->orWhere('client_medication_id', $order->id))
                    ->latest('tested_on')
                    ->first();
                if ($latest?->next_test_date && WorkerClock::daysUntil($latest->next_test_date) <= 3) {
                    $raise('inr:'.$order->id.':'.$latest->next_test_date->toDateString(), 'INR test', $latest->next_test_date);
                }
            }
            $this->alerts->reconcile(MedicationAlertCatalogue::REVIEW_DUE, $keys, 'Reviewed', $scope);
        });

        $this->safely('cdDiscrepancy', function () use ($client, $scope): void {
            $open = app(MedicationGovernanceScopeService::class)->scopeCanonicalClientMedicationRows(
                ClientControlledDrugDiscrepancy::query(),
                [(int) $client->site_id],
                false,
            )
                ->where('client_id', $client->id)
                ->whereIn('status', ['open', 'under_review'])
                ->with(['medication:id,name', 'client.site:id,name'])
                ->get();
            foreach ($open as $discrepancy) {
                $this->alerts->raise(MedicationAlertCatalogue::CD_DISCREPANCY, $this->discrepancySubject($discrepancy));
            }
            // Loss reports close through their own resolution, not this sweep.
            $keep = [
                ...$open->map(fn (ClientControlledDrugDiscrepancy $d): string => 'discrepancy:'.$d->id)->all(),
                ...$this->openLossKeys((int) $client->id),
            ];
            $this->alerts->reconcile(MedicationAlertCatalogue::CD_DISCREPANCY, $keep, 'Investigated', $scope);
        });
    }

    /** A balance check, transfer or count found a difference (after the record commits). */
    public function discrepancy(ClientControlledDrugDiscrepancy $discrepancy): void
    {
        $id = (int) $discrepancy->id;
        DB::afterCommit(fn () => $this->safely('cdDiscrepancy', function () use ($id): void {
            $fresh = ClientControlledDrugDiscrepancy::query()->with(['medication:id,name', 'client.site:id,name'])->find($id);
            if ($fresh?->client?->site_id !== null) {
                $this->alerts->raise(MedicationAlertCatalogue::CD_DISCREPANCY, $this->discrepancySubject($fresh));
            }
        }));
    }

    public function discrepancyResolved(ClientControlledDrugDiscrepancy $discrepancy): void
    {
        $this->safely('cdDiscrepancy', fn () => $this->alerts->resolve(MedicationAlertCatalogue::CD_DISCREPANCY, 'discrepancy:'.$discrepancy->id, 'Investigated'));
    }

    /** A controlled-drug loss was reported (after the record commits). */
    public function lossReport(ControlledDrugLossReport $report): void
    {
        $id = (int) $report->id;
        DB::afterCommit(fn () => $this->safely('cdDiscrepancy', function () use ($id): void {
            $fresh = ControlledDrugLossReport::query()->with(['medication:id,name', 'client.site:id,name'])->find($id);
            $client = $fresh?->client;
            if (! $client instanceof Client || $client->site_id === null) {
                return;
            }
            $house = $this->house($client);
            $this->alerts->raise(MedicationAlertCatalogue::CD_DISCREPANCY, new MedicationAlertSubject(
                key: 'loss:'.$fresh->id,
                siteId: (int) $client->site_id,
                title: 'Controlled-drug loss reported',
                message: sprintf('A controlled-drug loss was reported — %s at %s.', $fresh->medication?->name ?? 'a controlled medicine', $house),
                shortMessage: "A controlled-drug loss was reported at {$house}.",
                actionUrl: '/emar/controlled-drugs',
                severity: 'critical',
                clientId: (int) $client->id,
                controlled: true,
                context: ['client_id' => (int) $client->id, 'loss_report_id' => (int) $fresh->id],
            ));
        }));
    }

    public function lossReportResolved(ControlledDrugLossReport $report): void
    {
        $this->safely('cdDiscrepancy', fn () => $this->alerts->resolve(MedicationAlertCatalogue::CD_DISCREPANCY, 'loss:'.$report->id, 'Investigated'));
    }

    /** An as-needed dose was blocked at the order's daily limit (after the record commits). */
    public function prnOverLimit(Client $client, ClientMedication $order): void
    {
        $clientId = (int) $client->id;
        $orderId = (int) $order->id;
        DB::afterCommit(fn () => $this->safely('prnLimit', function () use ($clientId, $orderId): void {
            $client = Client::query()->with('site:id,name')->find($clientId);
            $order = ClientMedication::query()->find($orderId);
            if ($client?->site_id === null || $order === null) {
                return;
            }
            $this->alerts->raise(
                MedicationAlertCatalogue::PRN_LIMIT,
                $this->prnSubject($client, $order, 'prn:'.$order->id, $this->house($client), $this->person($client)),
            );
        }));
    }

    /** Someone recorded a medication error (after the record commits). */
    public function error(MedicationError $error): void
    {
        $id = (int) $error->id;
        DB::afterCommit(fn () => $this->safely('errors', function () use ($id): void {
            $fresh = MedicationError::query()->with(['client.site:id,name', 'medication:id,name,controlled_drug'])->find($id);
            $client = $fresh?->client;
            if (! $client instanceof Client || $client->site_id === null) {
                return;
            }
            $house = $this->house($client);
            $what = str_replace('_', ' ', (string) $fresh->error_type);
            $this->alerts->raise(MedicationAlertCatalogue::ERRORS, new MedicationAlertSubject(
                key: 'error:'.$fresh->id,
                siteId: (int) $client->site_id,
                title: 'Medication error reported',
                message: sprintf(
                    'A medication error was reported at %s: %s%s — %s.',
                    $house,
                    $this->person($client),
                    $fresh->medication ? ', '.$fresh->medication->name : '',
                    $what,
                ),
                shortMessage: "A medication error was reported at {$house}.",
                actionUrl: '/emar/errors',
                severity: in_array($fresh->severity, ['major', 'critical'], true) ? 'critical' : 'warning',
                clientId: (int) $client->id,
                controlled: (bool) $fresh->medication?->controlled_drug,
                context: ['client_id' => (int) $client->id, 'medication_error_id' => (int) $fresh->id],
            ));
        }));
    }

    private function discrepancySubject(ClientControlledDrugDiscrepancy $discrepancy): MedicationAlertSubject
    {
        $client = $discrepancy->client;
        $house = $this->house($client);
        $difference = (float) $discrepancy->difference;

        return new MedicationAlertSubject(
            key: 'discrepancy:'.$discrepancy->id,
            siteId: (int) $client->site_id,
            title: 'Controlled-drug count doesn’t match',
            message: sprintf(
                'Controlled-drug count doesn’t match — %s at %s: %s %s.',
                $discrepancy->medication?->name ?? 'a controlled medicine',
                $house,
                MedicationStockQuantity::display(abs($difference)),
                $difference < 0 ? 'short' : 'over',
            ),
            shortMessage: "A controlled-drug count doesn’t match at {$house}.",
            actionUrl: '/emar/controlled-drugs',
            severity: 'critical',
            clientId: (int) $client->id,
            controlled: true,
            context: ['client_id' => (int) $client->id, 'discrepancy_id' => (int) $discrepancy->id],
        );
    }

    private function prnSubject(Client $client, ClientMedication $order, string $key, string $house, string $person): MedicationAlertSubject
    {
        return new MedicationAlertSubject(
            key: $key,
            siteId: (int) $client->site_id,
            title: 'As-needed dose over the limit',
            message: "{$person} — as-needed {$order->name} has reached the order’s daily limit. {$house}.",
            shortMessage: "An as-needed dose at {$house} is over its limit.",
            actionUrl: '/emar/mar?client_id='.$client->id,
            severity: 'critical',
            clientId: (int) $client->id,
            controlled: (bool) $order->controlled_drug,
            context: ['client_id' => (int) $client->id, 'client_medication_id' => (int) $order->id],
        );
    }

    /**
     * @param  callable(string, string, string): string  $message  Medicine, person, house.
     * @param  callable(string): string  $short  House.
     */
    private function stockSubject(
        ClientMedicationStock $stock,
        string $key,
        string $title,
        callable $message,
        callable $short,
        string $severity = 'warning',
    ): MedicationAlertSubject {
        $order = $stock->medication;
        $client = $order->client;
        $house = $this->house($client);

        return new MedicationAlertSubject(
            key: $key,
            siteId: (int) $client->site_id,
            title: $title,
            message: $message((string) $order->name, $this->person($client), $house),
            shortMessage: $short($house),
            actionUrl: '/emar/stock',
            severity: $severity,
            clientId: (int) $client->id,
            controlled: (bool) $order->controlled_drug,
            context: ['client_id' => (int) $client->id, 'client_medication_id' => (int) $order->id, 'stock_id' => (int) $stock->id],
        );
    }

    /** @return list<string> */
    private function openLossKeys(int $clientId): array
    {
        return MedicationAlert::query()
            ->where('type', MedicationAlertCatalogue::CD_DISCREPANCY)
            ->where('client_id', $clientId)
            ->whereNotNull('open_key')
            ->where('open_key', 'like', MedicationAlertCatalogue::CD_DISCREPANCY.':loss:%')
            ->pluck('open_key')
            ->map(fn (string $key): string => substr($key, strlen(MedicationAlertCatalogue::CD_DISCREPANCY) + 1))
            ->all();
    }

    /** "Aroha N." — first name and surname initial (v5 samples). */
    private function person(?Client $client): string
    {
        if (! $client instanceof Client) {
            return 'Someone';
        }
        $first = trim((string) $client->first_name);
        $last = trim((string) $client->last_name);

        return trim($first.($last !== '' ? ' '.mb_substr($last, 0, 1).'.' : '')) ?: 'Someone';
    }

    private function house(?Client $client): string
    {
        return (string) ($client?->site?->name ?? 'their house');
    }

    /** An alert never stops the record or check that raised it. */
    private function safely(string $alert, callable $raise): void
    {
        try {
            $raise();
        } catch (Throwable $exception) {
            Log::error('Medication alert could not be raised', [
                'alert' => $alert,
                'exception' => $exception->getMessage(),
            ]);
            report($exception);
        }
    }
}
