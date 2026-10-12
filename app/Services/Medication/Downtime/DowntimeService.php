<?php

namespace App\Services\Medication\Downtime;

use App\Models\ClientMedication;
use App\Models\MedicationDowntime;
use App\Models\MedicationDowntimeDose;
use App\Models\MedicationDowntimeSheet;
use App\Models\MedicationPaperEntry;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\DoseSlots\DoseOrderTimelineFactory;
use App\Services\Medication\DoseSlots\DoseSlotProjection;
use App\Services\Medication\DoseSlots\DoseSlotReaderScope;
use App\Services\Medication\DoseSlots\DoseSlotRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class DowntimeService
{
    public function __construct(private readonly DowntimeAccess $access, private readonly DoseSlotProjection $projection, private readonly PaperEntryService $paper, private readonly DowntimeEvents $events, private readonly DoseOrderTimelineFactory $timelines) {}

    /** Paths are privately staged; caller removes staged files when the transaction fails. */
    public function declare(User $actor, array $data, array $sheets = []): MedicationDowntime
    {
        abort_unless($this->access->manages($actor), 403);
        try {
            $start = PaperReconciliationRules::instant($data['started_at']);
            $end = PaperReconciliationRules::instant($data['ended_at']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['period' => $e->getMessage()]);
        }
        if ($start->greaterThan($end) || $end->isFuture()) {
            throw ValidationException::withMessages(['ended_at' => 'The downtime must end after it starts and cannot end in the future.']);
        }
        if ($start->diffInDays($end) > 31) {
            throw ValidationException::withMessages(['period' => 'Enter at most 31 days in one downtime record so its paper list can be reviewed safely.']);
        }
        $siteId = (int) $data['site_id'];
        $clientIds = $this->access->clients($actor, $siteId);
        $fingerprint = PaperReconciliationRules::fingerprint([$data, array_column($sheets, 'sha256')]);

        return DB::transaction(function () use ($actor, $data, $sheets, $start, $end, $siteId, $clientIds, $fingerprint): MedicationDowntime {
            Site::query()->whereKey($siteId)->lockForUpdate()->firstOrFail();
            $actor = $this->access->lockActor($actor, $siteId);
            abort_unless($this->access->manages($actor), 403);
            $clientIds = $this->access->clients($actor, $siteId);
            if ($replay = MedicationDowntime::query()->where('request_uuid', $data['request_uuid'])->first()) {
                abort_unless((int) $replay->created_by === (int) $actor->id, 404);
                if (! hash_equals($replay->request_fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages(['request_uuid' => 'This request was already used for different downtime facts.']);
                }

                return $replay;
            }
            $from = $start->setTimezone(PaperReconciliationRules::TIMEZONE)->toDateString();
            $to = $end->setTimezone(PaperReconciliationRules::TIMEZONE)->toDateString();
            $coverage = $this->projection->coverage($from);
            if (! $coverage['complete']) {
                throw ValidationException::withMessages(['period' => $coverage['notice'] ?? 'The scheduled-dose history is unavailable for this downtime.']);
            }
            // Internal snapshots preserve every canonical target, including
            // controlled doses. Readers are concealed separately, so a lead
            // cannot finish a falsely complete list after hidden rows vanish.
            $allRows = $this->projection->rows(DoseSlotReaderScope::internal($clientIds), $from, $to, CarbonImmutable::now('UTC'));
            $projectedKeys = $allRows->map(fn ($row) => $row['client_medication_id'].':'.$row['nz_date'].':'.$row['ordered_time'])->all();
            $currentOrders = ClientMedication::query()->whereIn('client_id', $clientIds)->whereNull('superseded_by')->with('client')->get();
            foreach ($currentOrders as $order) {
                $timeline = $this->timelines->forOrder($order);
                for ($day = CarbonImmutable::parse($from, PaperReconciliationRules::TIMEZONE); $day->toDateString() <= $to; $day = $day->addDay()) {
                    foreach (DoseSlotRules::forWorkerTimezone()->slotsOn($timeline, $day->toDateString()) as $expected) {
                        if ($expected->dueAt->betweenIncluded($start, $end) && ! in_array($expected->key(), $projectedKeys, true)) {
                            throw ValidationException::withMessages(['period' => 'The scheduled-dose projection is incomplete for this downtime. Ask the house lead to restore it before collecting paper; no missing dose was assumed.']);
                        }
                    }
                }
            }
            $rows = $allRows
                ->filter(fn ($row) => $row['outcome'] === null
                    && ! in_array($row['state'], ['self_managed', 'away', 'pending_check'], true)
                    && CarbonImmutable::parse($row['due_at'])->betweenIncluded($start, $end));
            $orders = ClientMedication::query()->whereIn('id', $rows->pluck('client_medication_id'))->with('client')->get()->keyBy('id');
            $downtime = MedicationDowntime::query()->create([
                'site_id' => $siteId, 'created_by' => $actor->id, 'started_at' => $start, 'ended_at' => $end,
                'description' => $data['description'], 'request_uuid' => $data['request_uuid'], 'request_fingerprint' => $fingerprint,
            ]);
            foreach ($rows as $row) {
                if ($order = $orders->get($row['client_medication_id'])) {
                    MedicationDowntimeDose::query()->create([
                        'downtime_id' => $downtime->id, 'dose_slot_id' => $row['id'], 'client_id' => $row['client_id'],
                        'client_medication_id' => $order->id, 'scheduled_for' => CarbonImmutable::parse($row['due_at'])->utc(),
                        'snapshot' => $this->paper->snapshot($order) + ['ordered_time' => $row['ordered_time'], 'projection_state' => $row['state']],
                    ]);
                }
            }
            foreach ($sheets as $sheet) {
                MedicationDowntimeSheet::query()->create(['downtime_id' => $downtime->id, 'uploaded_by' => $actor->id] + $sheet);
            }
            $this->events->record($downtime, 'declared', $actor, facts: ['started_at' => $start->toIso8601String(), 'ended_at' => $end->toIso8601String(), 'sheets' => count($sheets)]);

            return $downtime;
        }, 5);
    }

    public function finish(User $actor, MedicationDowntime $downtime): void
    {
        abort_unless($this->access->manages($actor), 403);
        DB::transaction(function () use ($actor, $downtime): void {
            $downtime = MedicationDowntime::query()->whereKey($downtime->id)->lockForUpdate()->firstOrFail();
            $actor = $this->access->lockActor($actor, (int) $downtime->site_id);
            abort_unless($this->access->manages($actor), 403);
            $this->access->downtime($actor, (int) $downtime->id);
            if ($downtime->finished_at) {
                return;
            }
            $entered = $downtime->entries()->whereNotNull('downtime_dose_id')->pluck('downtime_dose_id');
            $entered = $entered->merge($downtime->resolutions()->pluck('downtime_dose_id'));
            if ($downtime->doses()->whereNotIn('id', $entered)->exists()) {
                throw ValidationException::withMessages(['finish' => 'Collect the facts for every listed paper dose before finishing. No missing outcome is assumed.']);
            }
            // D2 (Lane C1): doses posted without pack evidence wait for their
            // counted recount when the organisation asks for it.
            if (app(DowntimeSettings::class)->recountBeforeFinish()) {
                $waiting = app(PaperStockTracking::class)->outstandingRecounts((int) $downtime->id);
                if ($waiting->isNotEmpty()) {
                    throw ValidationException::withMessages(['finish' => 'Count the stock first: '.PaperStockTracking::describe($waiting)
                        .'. Their paper doses were given before pack tracking started, so the count settles the stock. Then finish this downtime.']);
                }
            }
            $downtime->update(['finished_at' => CarbonImmutable::now('UTC'), 'finished_by' => $actor->id]);
            $this->events->record($downtime, 'collection_finished', $actor, facts: ['paper_entries' => $downtime->entries()->count()]);
        }, 5);
    }

    /**
     * EA-095 (Lane C1, approved P10): the list's three meters, counted across
     * everything the viewer can open — not just the page shown.
     *  - to_enter: listed paper doses with no facts yet (leads only; open downtimes)
     *  - to_confirm: entries waiting for the giver or witness — the viewer's
     *    own for staff, every readable entry for leads
     *  - this_month: downtimes started this NZ month
     * Controlled entries stay out for viewers without controlled access.
     *
     * @param  list<int>  $siteIds
     * @return array{to_enter: int, to_confirm: int, this_month: int}
     */
    public function listMeters(User $actor, array $siteIds): array
    {
        $manager = $this->access->manages($actor);
        $controlled = $actor->canDo('medications.controlled.view');
        $clientIds = collect($siteIds)->flatMap(fn ($id) => $this->access->clients($actor, (int) $id))->values()->all();
        $visible = fn ($row): bool => $controlled || ! ($row->snapshot['controlled'] ?? false);
        $own = fn ($q) => $q->where(fn ($q) => $q->where('given_by', $actor->id)->orWhere('witness_id', $actor->id));
        $downtimes = fn () => MedicationDowntime::query()->whereIn('site_id', $siteIds)
            ->when(! $manager, fn ($q) => $q->whereHas('entries', $own));

        $toEnter = $manager ? MedicationDowntimeDose::query()
            ->whereIn('downtime_id', $downtimes()->whereNull('finished_at')->select('id'))
            ->whereIn('client_id', $clientIds)
            ->whereNotIn('id', MedicationPaperEntry::query()->whereNotNull('downtime_dose_id')->select('downtime_dose_id'))
            ->whereNotIn('id', DB::table('medication_downtime_resolutions')->select('downtime_dose_id'))
            ->when(! $controlled, fn ($q) => $q->whereNotIn('client_medication_id', ClientMedication::withTrashed()->where('controlled_drug', true)->select('id')))
            ->get(['id', 'snapshot'])->filter($visible)->count() : 0;

        $toConfirm = MedicationPaperEntry::query()
            ->whereIn('downtime_id', $downtimes()->select('id'))
            ->whereIn('client_id', $clientIds)
            ->when(! $manager, $own)
            ->whereDoesntHave('posting')
            ->with('confirmations')->get()->filter($visible)
            ->filter(function (MedicationPaperEntry $entry) use ($manager, $actor): bool {
                $giverDue = ! $entry->confirmations->contains('kind', 'giver');
                $witnessDue = $entry->witness_id !== null && ! $entry->confirmations->contains('kind', 'witness');

                return $manager ? ($giverDue || $witnessDue)
                    : (($giverDue && (int) $entry->given_by === (int) $actor->id) || ($witnessDue && (int) $entry->witness_id === (int) $actor->id));
            })->count();

        $monthStart = CarbonImmutable::now('Pacific/Auckland')->startOfMonth()->utc();

        return ['to_enter' => $toEnter, 'to_confirm' => $toConfirm,
            'this_month' => $downtimes()->where('started_at', '>=', $monthStart)->count()];
    }

    /** P08a adapter: stable identity and deep link, without a second tasks store. */
    public function pendingConfirmations(User $actor): array
    {
        return MedicationPaperEntry::query()
            ->whereIn('downtime_id', MedicationDowntime::query()->whereIn('site_id', $this->access->siteIds($actor))->select('id'))
            ->where(fn ($q) => $q->where('given_by', $actor->id)->orWhere('witness_id', $actor->id))
            ->with(['confirmations', 'posting', 'downtime.site'])->get()->filter(function ($entry) use ($actor): bool {
                $kind = (int) $entry->given_by === (int) $actor->id ? 'giver' : 'witness';

                return ! $entry->posting && ! $entry->confirmations->contains('kind', $kind)
                    && $this->entryReadable($actor, $entry);
            })->map(fn ($entry) => [
                'identity' => 'medication.paper_confirmation:'.$entry->id.':'.$actor->id,
                'href' => '/emar/downtime/'.$entry->downtime_id, 'paper_entry_id' => (int) $entry->id,
                'label' => 'Confirm your paper medication record',
                'kind' => (int) $entry->given_by === (int) $actor->id ? 'giver' : 'witness',
                'client_id' => (int) $entry->client_id, 'client_name' => $entry->snapshot['person'],
                'site_id' => (int) $entry->downtime->site_id, 'site_name' => $entry->downtime->site?->name,
                'given_at' => $entry->given_at->toIso8601String(), 'entered_at' => $entry->created_at->toIso8601String(),
                'controlled' => (bool) ($entry->snapshot['controlled'] ?? false),
            ])->values()->all();
    }

    private function entryReadable(User $actor, MedicationPaperEntry $entry): bool
    {
        try {
            $downtime = $this->access->downtime($actor, (int) $entry->downtime_id);
            $this->access->entry($actor, $downtime, (int) $entry->id);

            return true;
        } catch (HttpExceptionInterface) {
            return false;
        }
    }
}
