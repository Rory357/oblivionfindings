<?php

namespace App\Services\Medication\Reviews;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationReview;
use App\Models\MedicationReviewItem;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use App\Support\WorkerClock;
use Illuminate\Database\Eloquent\Builder;

/** Shared scoped read model for hub, person-record adapters, Tasks and profile. */
final class MedicationReviewReader
{
    public function __construct(
        private readonly MedicationGovernanceScopeService $scope,
        private readonly MedicationRecordAccess $access,
        private readonly MedicationReviewCadence $cadence,
    ) {}

    public function clientIds(User $actor, ?int $siteId = null, ?int $clientId = null): array
    {
        $sites = $this->scope->readerSiteIds($actor, 'medications.view', $siteId, $clientId);
        $query = Client::query()->whereIn('site_id', $sites)->when($siteId, fn ($q) => $q->where('site_id', $siteId));
        $ids = $this->access->readableClientIds($actor, $query->pluck('id'));
        if ($clientId !== null) {
            abort_unless(in_array($clientId, $ids, true), 404);

            return [$clientId];
        }

        return $ids;
    }

    public function query(User $actor, ?int $siteId = null, ?int $clientId = null): Builder
    {
        return MedicationReview::query()->whereIn('client_id', $this->clientIds($actor, $siteId, $clientId))
            ->with(['client.site:id,name', 'owner:id,name', 'items.medication:id,controlled_drug', 'items.linkedVersion']);
    }

    public function detail(User $actor, int $reviewId): MedicationReview
    {
        $review = MedicationReview::query()->findOrFail($reviewId);
        $this->access->client($actor, (int) $review->client_id);

        return $review->load(['client.site:id,name', 'owner:id,name', 'items.medication:id,controlled_drug', 'items.linkedVersion', 'events.actor:id,name']);
    }

    public function person(User $actor, int $clientId): array
    {
        $client = $this->access->client($actor, $clientId);

        return ['id' => $client->id, 'name' => trim($client->first_name.' '.$client->last_name), 'cadence' => $this->cadence->forClient($client),
            'next_review_date' => MedicationReview::query()->where('client_id', $clientId)->whereIn('review_type', ['regular', 'routine', 'Routine'])->whereIn('status', ['scheduled', 'overdue', 'in_progress'])->min('scheduled_date')];
    }

    public function serialize(MedicationReview $review, User $actor, bool $detail = false): array
    {
        $controlled = $actor->canDo('medications.controlled.view');
        $items = $review->items;
        $orders = $detail ? ClientMedication::query()->current()->active()->where('client_id', $review->client_id)->get() : collect();
        // Legacy summary/actions were unstructured and cannot be reliably scrubbed.
        $unstructuredLegacy = $items->isEmpty() && $review->status === 'completed';
        $summary = $this->canReadSource($review, $actor);
        $workflow = app(MedicationReviewWorkflow::class);

        return [
            'id' => $review->id, 'client_id' => $review->client_id,
            'client_name' => trim($review->client->first_name.' '.$review->client->last_name),
            'site_id' => $review->client->site_id, 'site_name' => $review->client->site?->name,
            'review_type' => $workflow->isRegular($review) ? 'regular' : 'triggered',
            'status' => in_array($review->status, ['scheduled', 'overdue', 'in_progress'], true) ? 'scheduled' : $review->status,
            'scheduled_date' => $review->scheduled_date?->toDateString(), 'completed_date' => $review->completed_date?->toDateString(),
            'completed_time' => $summary ? $review->completed_time : null, 'happened_at' => $summary ? $review->happened_at?->toIso8601String() : null,
            'reviewer_registration_number' => $summary ? $review->reviewer_registration_number : null,
            'owner_id' => $review->owner_id, 'owner_name' => $review->owner?->name,
            'trigger_code' => $review->trigger_code, 'trigger_reason' => $summary ? $review->trigger_reason : null,
            'reviewer_name' => $review->reviewer_name, 'reviewer_role' => $review->reviewer_role,
            'clinician_practice' => $review->clinician_practice,
            'appointment_date' => $review->appointment_date?->toDateString(), 'appointment_time' => $review->appointment_time,
            'appointment_location' => $summary ? $review->appointment_location : null, 'review_location' => $review->review_location,
            'clinical_summary' => $summary ? $review->clinical_summary : null,
            'participants' => $summary ? $review->participants : null,
            'drug_burden_index' => $summary ? $review->drug_burden_index : null, 'falls_last_quarter' => $summary ? $review->falls_last_quarter : null,
            'next_review_date' => $review->next_review_date?->toDateString(), 'revision' => (int) $review->revision,
            'next_regular_review_date' => $detail ? MedicationReview::query()->where('client_id', $review->client_id)
                ->whereKeyNot($review->id)->whereIn('review_type', ['regular', 'routine', 'Routine'])
                ->whereIn('status', ['scheduled', 'overdue', 'in_progress'])->min('scheduled_date') : null,
            'cadence' => $this->cadence->forClient($review->client),
            'source' => $summary && $review->source_path ? $this->source($review->source_name, $review->source_mime, $review->source_size, '/emar/reviews/'.$review->id.'/source') : null,
            'items' => $items->filter(fn ($item) => $detail || $controlled || ! $item->isRestricted())
                ->map(fn ($item) => $this->item($item, $actor))->values()->all(),
            'controlled_rows_hidden' => ! $controlled && $items->contains(fn ($item) => $item->isRestricted()),
            'legacy_outcomes' => $summary && $unstructuredLegacy ? $review->actions : null,
            'legacy_recommendations' => $summary && $unstructuredLegacy ? $review->recommendations : null,
            'legacy_medications_reviewed' => $summary && $unstructuredLegacy ? $review->medications_reviewed : null,
            'legacy_whanau' => $summary && $unstructuredLegacy ? ['involved' => $review->whanau_involved, 'notes' => $review->whanau_notes] : null,
            'current_orders' => $orders->map(fn ($order) => [
                'id' => $order->id, 'name' => $order->controlled_drug && ! $controlled ? 'Controlled medicine' : $order->name,
                'dosage' => $order->controlled_drug && ! $controlled ? null : $order->dosage,
                'frequency' => $order->controlled_drug && ! $controlled ? null : $order->frequency,
                'controlled_hidden' => (bool) $order->controlled_drug && ! $controlled,
            ])->all(),
            'history' => $detail ? $review->events->map(function ($event) use ($summary): array {
                // Never send stored source paths, clinician letter or per-medicine snapshots.
                $keys = ['from_date', 'to_date', 'due_date', 'completed_date', 'next_date', 'kind', 'automatic', 'from_review_id'];
                if ($summary) {
                    $keys = [...$keys, 'reason_code', 'reason'];
                }

                return ['id' => $event->id, 'event' => $event->event, 'actor_name' => $event->actor?->name ?? 'System',
                    'created_at' => $event->created_at->toIso8601String(), 'details' => collect($event->details)->only($keys)->all()];
            })->all() : [],
        ];
    }

    public function item(MedicationReviewItem $item, User $actor): array
    {
        $hidden = $item->isRestricted() && ! $actor->canDo('medications.controlled.view');
        $summary = $actor->canDo(MedicationReviewWorkflow::MANAGE) && ! $hidden;
        $followup = ! $hidden ? app(MedicationReviewFollowupAdapter::class)->state($item, $actor) : null;

        return ['id' => $item->id, 'client_medication_id' => $hidden ? null : $item->client_medication_id,
            'name' => $hidden ? ($item->classification_pending ? 'New medicine — classification to check' : 'Controlled medicine') : $item->name_snapshot, 'classification_pending' => (bool) $item->classification_pending, 'controlled_hidden' => $hidden,
            'outcome' => $hidden ? 'pending_controlled' : $item->outcome,
            'recommendation' => $hidden ? null : $item->recommendation,
            'watch_text' => $hidden ? null : $item->watch_text, 'watch_until' => $hidden ? null : $item->watch_until?->toDateString(),
            'decision' => $hidden ? null : $item->decision, 'prescriber_name' => $summary ? $item->prescriber_name : null,
            'decision_date' => $summary ? ($item->decision_at ? $item->decision_at->setTimezone(config('app.worker_timezone', 'Pacific/Auckland'))->format('Y-m-d\TH:i') : $item->decision_date?->toDateString()) : null,
            'decision_method' => $summary ? $item->decision_method : null, 'decision_note' => $summary ? $item->decision_note : null,
            'decision_source' => $summary && $item->decision_source_path ? $this->source($item->decision_source_name, $item->decision_source_mime, $item->decision_source_size, '/emar/reviews/'.$item->review_id.'/items/'.$item->id.'/decision-source') : null,
            'linked_order_version_id' => $hidden ? null : $item->linked_order_version_id,
            // This is a handoff link, never an "implemented" action or prescription write.
            'order_url' => ! $hidden && $item->isChange() && $item->decision === 'agreed' && $actor->canDo('medications.orders.manage')
                ? '/emar/prescriptions?client_id='.$item->client_id.'&review_item='.$item->id : null,
            'followup_url' => $followup['url'] ?? null, 'watch_completed' => $followup['completed'] ?? false,
        ];
    }

    public function canReadSource(MedicationReview $review, User $actor): bool
    {
        if (! $actor->canDo(MedicationReviewWorkflow::MANAGE)) {
            return false;
        }
        if ($actor->canDo('medications.controlled.view')) {
            return true;
        }

        return ! $review->items()->get()->contains(fn ($item) => $item->isRestricted())
            && ! ClientMedication::query()->where('client_id', $review->client_id)->where('controlled_drug', true)->exists()
            && ! ($review->status === 'completed' && ! $review->items()->exists());
    }

    public function meters(Builder $query, User $actor): array
    {
        $today = WorkerClock::today()->toDateString();
        $open = fn () => (clone $query)->whereIn('status', ['scheduled', 'overdue', 'in_progress']);
        $items = MedicationReviewItem::query()->whereIn('review_id', (clone $query)->select('medication_reviews.id')->reorder());
        if (! $actor->canDo('medications.controlled.view')) {
            $items->where('controlled_snapshot', false)->where('classification_pending', false)->whereDoesntHave('medication', fn ($q) => $q->where('controlled_drug', true));
        }

        return ['overdue' => $open()->whereDate('scheduled_date', '<', $today)->count(),
            'due_30' => $open()->whereBetween('scheduled_date', [$today, WorkerClock::today()->addDays(30)->toDateString()])->count(),
            'booked' => $open()->whereDate('scheduled_date', '>', WorkerClock::today()->addDays(30)->toDateString())->count(), 'recorded' => (clone $query)->where('status', 'completed')->count(),
            'changes' => (clone $items)->whereIn('outcome', ['change', 'stop', 'swap', 'start', 'watch', 'pending_controlled'])->count(),
            'pending_outcomes' => (clone $items)->where('outcome', 'pending_controlled')->count(),
            'waiting_prescriber' => (clone $items)->whereIn('outcome', ['change', 'stop', 'swap', 'start'])->where('decision', 'waiting')->count(),
            'changes_to_make' => (clone $items)->whereIn('outcome', ['change', 'stop', 'swap', 'start'])->where('decision', 'agreed')->whereNull('linked_order_version_id')->count()];
    }

    private function source(?string $name, ?string $mime, ?int $size, string $url): array
    {
        return ['name' => $name, 'mime' => $mime, 'size' => $size, 'view_url' => $url, 'download_url' => $url.'?download=1'];
    }
}
