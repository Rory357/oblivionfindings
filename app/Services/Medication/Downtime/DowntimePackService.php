<?php

namespace App\Services\Medication\Downtime;

use App\Models\Client;
use App\Models\ClientMedicalProfile;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationAdminRule;
use App\Models\MedicationAllergy;
use App\Models\Site;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Medication\ClientAllergyRecordService;
use App\Services\Medication\DoseSlots\DoseAwaySources;
use App\Services\Medication\DoseSlots\DoseOrderTimelineFactory;
use App\Services\Medication\DoseSlots\DoseSlotProjection;
use App\Services\Medication\DoseSlots\DoseSlotReaderScope;
use App\Services\Medication\DoseSlots\DoseSlotRules;
use App\Services\Medication\DoseTimingSettings;
use App\Services\Medication\MedicationConcealment;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\Medication\Recording\DoseRecordingRequirements;
use App\Services\MedicationRuleService;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DowntimePackService
{
    public const PURPOSE = 'Downtime — a paper copy in case the system is down';

    public const MAX_PEOPLE = 100;

    public const MAX_ORDERS = 1000;

    public const MAX_DOSES = 2000;

    public function __construct(
        private readonly DowntimeAccess $access,
        private readonly DoseSlotProjection $projection,
        private readonly DoseOrderTimelineFactory $timelines,
        private readonly MedicationRuleService $rules,
        private readonly ClientAllergyRecordService $allergies,
    ) {}

    public function build(User $actor, int $siteId, string $day, ?CarbonImmutable $snapshotAt = null): array
    {
        $this->assertPackAuthority($actor);

        // Take current source locks before the first ordinary clinical read.
        // All common projection/rule/allergy readers then see this one source
        // snapshot; expensive PDF rendering happens after these locks release.
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actor, $siteId, $day, $snapshotAt): array {
            $evidence = $this->lockEvidence($reads, $siteId, $day);
            app(DoseTimingSettings::class)->forget();
            $pack = $this->buildSnapshot($actor, $siteId, $day, $snapshotAt);
            // A caller's enclosing transaction may already have an older RR
            // snapshot. Prove the ordinary readers consumed the locked source
            // evidence too; do not attach a fresh digest to older rendered facts.
            abort_unless(hash_equals(PaperReconciliationRules::fingerprint($evidence),
                PaperReconciliationRules::fingerprint($this->lockEvidence(null, $siteId, $day))), 409,
                'The complete print snapshot changed. Refresh before making the pack again.');
            $pack['_source']['evidence'] = $evidence;

            return $pack;
        }), 5);
    }

    private function buildSnapshot(User $actor, int $siteId, string $day, ?CarbonImmutable $snapshotAt): array
    {
        $currentTime = CarbonImmutable::now('UTC');
        $now = $snapshotAt ?? $currentTime;
        $today = $currentTime->setTimezone(PaperReconciliationRules::TIMEZONE)->toDateString();
        $tomorrow = $currentTime->setTimezone(PaperReconciliationRules::TIMEZONE)->addDay()->toDateString();
        if (! in_array($day, [$today, $tomorrow], true)) {
            throw ValidationException::withMessages(['nz_date' => 'The downtime pack is for today or tomorrow in New Zealand.']);
        }
        $clientIds = $this->access->clients($actor, $siteId, pack: true);
        sort($clientIds);
        abort_if(count($clientIds) > self::MAX_PEOPLE, 422, 'This house exceeds the supported downtime pack size. Ask the clinical lead for a smaller approved print scope.');
        // Private source identities bind every included person and medicine
        // classification to the rendered facts. They never enter the preview.
        $sourceOrders = ClientMedication::withTrashed()->whereIn('client_id', $clientIds)->orderBy('id')->limit(self::MAX_ORDERS + 1)
            ->get(['id', 'client_id', 'controlled_drug', 'deleted_at'])->toArray();
        abort_if(count($sourceOrders) > self::MAX_ORDERS, 422, 'This house exceeds the supported downtime pack medicine history size. Ask the clinical lead to review its print scope.');
        $rows = $this->projection->rows(DoseSlotReaderScope::forAuthorisedClients($actor, $clientIds), $day, $day, $now);
        abort_if($rows->count() > self::MAX_DOSES, 422, 'This day exceeds the supported downtime pack size. No truncated schedule was printed.');
        $orders = ClientMedication::query()->whereIn('client_id', $clientIds)->whereNull('superseded_by')->orderBy('id')->with(['client', 'stock'])->get()->keyBy('id');
        // Common rules prove coverage; projection supplies every printable slot.
        $projectedKeys = $rows->map(fn ($row) => $row['client_medication_id'].':'.$row['nz_date'].':'.$row['ordered_time'])->all();
        $concealment = MedicationConcealment::for($actor);
        foreach ($orders as $order) {
            if ($concealment->hides((bool) $order->controlled_drug)) {
                continue;
            }
            foreach (DoseSlotRules::forWorkerTimezone()->slotsOn($this->timelines->forOrder($order), $day) as $expected) {
                if (! in_array($expected->key(), $projectedKeys, true)) {
                    throw ValidationException::withMessages(['pack' => 'The scheduled-dose projection is incomplete for this day. Ask the house lead to restore it before making the pack; no incomplete paper schedule was printed.']);
                }
            }
        }
        $visible = $concealment->leaveOut($rows, fn ($row) => (bool) $row['controlled'] || (bool) $orders->get($row['client_medication_id'])?->controlled_drug);
        $scheduled = [];
        foreach ($visible['rows'] as $slot) {
            $order = $orders->get($slot['client_medication_id']);
            if (! $order) {
                throw ValidationException::withMessages(['pack' => 'An order changed while the pack was made. Refresh and try again.']);
            }
            $requirements = $this->rules->requirementsFor($order);
            $scheduled[] = [
                ...$slot, 'person' => $order->client->full_name, 'medicine' => $order->name,
                'dosage' => $order->dosage, 'route' => $order->route, 'instructions' => $order->instructions,
                'second_person_required' => $order->requiresWitness() || $requirements['requires_countersign'],
                'readings' => $this->readingLabels($requirements['required_observations']),
            ];
        }
        $people = Client::query()->whereIn('id', $clientIds)->orderBy('last_name')->orderBy('id')->get()->map(function (Client $client) use ($scheduled, $orders, $concealment, $day): array {
            $prn = $orders->filter(fn (ClientMedication $order) => (int) $order->client_id === (int) $client->id
                && $order->is_prn && $order->active && ! $concealment->hides((bool) $order->controlled_drug)
                && ($order->start_date === null || $order->start_date->toDateString() <= $day)
                && ($order->end_date === null || $order->end_date->toDateString() >= $day))
                ->map(function (ClientMedication $order): array {
                    $requirements = $this->rules->requirementsFor($order);

                    return [
                        'medicine' => $order->name, 'dosage' => $order->dosage, 'route' => $order->route,
                        'indication' => $order->prn_reason, 'max_per_day' => $order->max_per_day,
                        'min_hours_between_doses' => $order->min_hours_between_doses, 'instructions' => $order->instructions,
                        'verified' => $order->isVerifiedForAdministration(),
                        'second_person_required' => $order->requiresWitness() || $requirements['requires_countersign'],
                        'readings' => $this->readingLabels($requirements['required_observations']),
                    ];
                })->values()->all();

            return [
                'id' => (int) $client->id, 'name' => $client->full_name,
                'allergies' => array_map(fn ($allergy) => array_intersect_key($allergy, array_flip(['allergen', 'severity', 'reaction'])), $this->allergies->forClient($client)),
                'scheduled' => array_values(array_filter($scheduled, fn ($row) => $row['client_id'] === (int) $client->id)), 'prn' => $prn,
            ];
        })->all();
        $controlled = $concealment->canViewControlled() ? $orders->filter(fn ($order) => $order->active && $order->controlled_drug)->map(fn ($order) => [
            'person' => $order->client->full_name, 'medicine' => $order->name,
            'balance' => $order->stock?->on_hand, 'unit' => $order->stock?->unit,
        ])->values()->all() : [];

        return [
            'site' => Site::query()->findOrFail($siteId)->only(['id', 'name']),
            'nz_date' => $day, 'printed_at' => $now->toIso8601String(), 'printed_by' => $actor->name, 'purpose' => self::PURPOSE,
            'people' => $people, 'rounds' => $scheduled, 'controlled_registers' => $controlled,
            'controlled_pages_included' => $concealment->canViewControlled(),
            'controlled_notice' => $concealment->canViewControlled() ? null : 'Controlled medicine details and register pages are not shown in this pack. Ask the house lead with controlled-medicine access for the complete authorised pack.',
            '_source' => ['client_ids' => $clientIds, 'orders' => $sourceOrders],
        ];
    }

    /** P09-equivalent bounded release gate. Rendering stays outside its locks. */
    public function release(User $actor, array $pack, Closure $recordExport): void
    {
        $digest = PaperReconciliationRules::fingerprint($pack['_source']['evidence']);
        $siteId = (int) $pack['site']['id'];
        $sourcePeople = array_column($pack['_source']['evidence']['people'], 'id');
        $sourceOrders = array_column($pack['_source']['evidence']['orders'], 'id');
        DB::transaction(function () use ($actor, $pack, $digest, $siteId, $sourcePeople, $sourceOrders, $recordExport): void {
            $current = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($actor, ['*']);
            CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($current, $pack, $digest, $siteId, $sourcePeople, $sourceOrders, $recordExport): void {
                $this->assertPackAuthority($current);
                $approved = app(UserSiteAccessService::class)->accessibleSiteIds($current, MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS, $reads);
                abort_unless(in_array($siteId, $approved, true), 404);
                $fresh = $this->lockEvidence($reads, $siteId, $pack['nz_date'], $sourcePeople, $sourceOrders);
                abort_unless(hash_equals($digest, PaperReconciliationRules::fingerprint($fresh))
                    && $current->name === $pack['printed_by']
                    && $current->canDo('medications.controlled.view') === $pack['controlled_pages_included'], 409,
                    'The records or your access changed while the pack was being prepared. Refresh and make the pack again.');
                foreach ($pack['_source']['client_ids'] as $id) {
                    $person = $reads->query(Client::query()->whereKey($id))->first();
                    abort_unless($person && (int) $person->site_id === $siteId, 404);
                    app(MedicationRecordAccess::class)->assertReportable($current, $person);
                }
                // P09 is last. Any denial, changed evidence or event failure
                // keeps buffered bytes private and creates no export event.
                $recordExport($current);
            });
        }, 5);
    }

    /** P09's non-stock report/export boundary, including explicit finance overrides. */
    private function assertPackAuthority(User $actor): void
    {
        abort_unless($actor->approved_at !== null
            && $actor->canDo('medications.reports.view') && $actor->canDo('medications.reports.export'), 403);
        abort_if($actor->hasRole('finance') && ! $actor->hasRole('admin', 'provider_manager', 'coordinator', 'clinical_lead', 'team_lead', 'auditor'), 403);
    }

    /** Complete raw render sources; release never reuses a cached common reader. */
    private function lockEvidence(?CurrentAuthorizationReads $reads, int $siteId, string $day, array $originalPeople = [], array $originalOrders = []): array
    {
        $site = $this->boundedEvidence($reads, Site::query()->whereKey($siteId)->orderBy('id')->select(['id', 'name', 'is_active', 'archived', 'archived_at']), 1);
        abort_if($site === [], 404);
        $people = $this->boundedEvidence($reads, Client::withTrashed()->where(fn ($query) => $query->where('site_id', $siteId)->orWhereIn('id', $originalPeople))->orderBy('id')
            ->select(['id', 'site_id', 'first_name', 'last_name', 'preferred_name', 'status', 'deleted_at']), self::MAX_PEOPLE);
        $clientIds = array_column($people, 'id');
        $orders = $this->boundedEvidence($reads, ClientMedication::withTrashed()->where(fn ($query) => $query->whereIn('client_id', $clientIds)->orWhereIn('id', $originalOrders))->orderBy('id')
            ->select(['id', 'client_id', 'name', 'dosage', 'dose_amount', 'dose_unit', 'route', 'dose_times', 'frequency', 'frequency_code',
                'start_date', 'end_date', 'controlled_drug', 'is_prn', 'max_per_day', 'min_hours_between_doses', 'prn_reason', 'instructions',
                'witness_required', 'high_risk', 'nzulm_code', 'active', 'state', 'approval_status', 'verified_at', 'version',
                'paused_at', 'ceased_at', 'superseded_by', 'superseded_at', 'deleted_at', 'created_at', 'updated_at']), self::MAX_ORDERS);
        $orderIds = array_column($orders, 'id');
        $stays = $this->boundedEvidence($reads, DB::table('respite_stays')->whereIn('client_id', $clientIds)->orderBy('id')
            ->select(['id', 'client_id', 'booking_id', 'status', 'actual_start', 'actual_end', 'deleted_at']), self::MAX_DOSES);

        return [
            'site' => $site, 'people' => $people, 'orders' => $orders,
            'stocks' => $this->boundedEvidence($reads, ClientMedicationStock::query()->whereIn('client_medication_id', $orderIds)->orderBy('id')->select(['id', 'client_medication_id', 'on_hand', 'unit']), self::MAX_ORDERS),
            'rules' => $this->boundedEvidence($reads, MedicationAdminRule::query()->orderBy('id')->select(['id', 'site_id', 'match_type', 'match_value', 'requires_countersign', 'required_observations', 'active', 'updated_at']), self::MAX_ORDERS),
            'allergies' => $this->boundedEvidence($reads, MedicationAllergy::query()->whereIn('client_id', $clientIds)->orderBy('id')->select(['id', 'client_id', 'allergen', 'severity', 'reaction', 'notes', 'identified_date', 'identified_by']), self::MAX_DOSES),
            'profiles' => $this->boundedEvidence($reads, ClientMedicalProfile::query()->whereIn('client_id', $clientIds)->orderBy('id')->select(['id', 'client_id', 'allergies', 'allergy_records', 'allergies_canonical_at']), self::MAX_PEOPLE),
            'slots' => $this->boundedEvidence($reads, DB::table('medication_dose_slots')->whereIn('client_id', $clientIds)->where('nz_date', $day)->orderBy('id')
                ->select(['id', 'client_id', 'client_medication_id', 'schedule_version_id', 'nz_date', 'ordered_time', 'due_at', 'controlled', 'order_change_pending',
                    'dst_adjustment', 'self_managed', 'last_day', 'reconstructed', 'outcome', 'outcome_administration_id', 'outcome_at', 'superseded_at']), self::MAX_DOSES),
            'schedule_versions' => $this->boundedEvidence($reads, DB::table('medication_dose_schedule_versions')->whereIn('client_medication_id', $orderIds)->orderBy('id')
                ->select(['id', 'client_medication_id', 'dose_times', 'start_date', 'end_date', 'is_prn', 'self_managed', 'changed_at', 'verified_at', 'rejected_at']), self::MAX_DOSES),
            'pauses' => $this->boundedEvidence($reads, DB::table('medication_dose_order_pauses')->whereIn('client_medication_id', $orderIds)->orderBy('id')->select(['id', 'client_medication_id', 'paused_at', 'resumed_at']), self::MAX_DOSES),
            'timing' => $this->boundedEvidence($reads, DB::table('app_settings')->whereIn('key', array_keys(DoseTimingSettings::RANGES))->orderBy('id')->select(['id', 'key', 'value']), count(DoseTimingSettings::RANGES)),
            'respite_stays' => $stays,
            'respite_bookings' => $this->boundedEvidence($reads, DB::table('respite_bookings')->whereIn('id', array_column($stays, 'booking_id'))->orderBy('id')->select(['id', 'location_id', 'deleted_at']), self::MAX_DOSES),
            // Absence follows the person across house moves. Only canonical
            // presence facts belong here, never historical clinical narratives.
            'hospital' => $this->boundedEvidence($reads, DB::table('clinical_events')->whereIn('client_id', $clientIds)
                ->whereIn('event_type', ['hospital_admission', 'hospital_discharge'])->orderBy('id')
                ->select(['id', 'client_id', 'event_type', 'occurred_at', 'hospital_admitted_at', 'hospital_discharged_at', 'hospital_admission_id', 'deleted_at']), self::MAX_DOSES),
            'leave_counts' => DoseAwaySources::leaveCounts(),
            'leave' => DoseAwaySources::leaveCounts()
                ? $this->boundedEvidence($reads, DB::table('client_leave_requests')->whereIn('client_id', $clientIds)->orderBy('id')
                    ->select(['id', 'client_id', 'status', 'starts_on', 'ends_on', 'approved_at', 'approved_by', 'departed_at', 'departed_by',
                        'returned_at', 'returned_by', 'withdrawn_at', 'withdrawn_by', 'version', 'deleted_at']), self::MAX_DOSES) : [],
        ];
    }

    private function boundedEvidence(?CurrentAuthorizationReads $reads, EloquentBuilder|Builder $query, int $maximum): array
    {
        $query->limit($maximum + 1);
        $records = ($reads ? $reads->query($query) : $query)->get();
        abort_if($records->count() > $maximum, 422, 'The complete print evidence exceeds the supported pack size. No truncated pack can be released.');

        return $records->map(fn ($record) => $record instanceof Model ? $record->getRawOriginal() : (array) $record)->all();
    }

    private function readingLabels(array $keys): array
    {
        return array_map(fn ($key) => DoseRecordingRequirements::OBSERVATIONS[$key]['label'] ?? str_replace('_', ' ', $key), $keys);
    }
}
