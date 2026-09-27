<?php

namespace App\Services\Assets;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\AssetCustodyMovement;
use App\Models\AssetDocument;
use App\Models\AssetKitItem;
use App\Models\AssetProfileEvent;
use App\Models\FleetFinanceReviewRequest;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\Fleet\MaintenanceAccessService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class AssetProfilePresenter
{
    public function __construct(private readonly SecurityDevicesAccessService $access, private readonly AssetProfileService $profiles, private readonly MaintenanceAccessService $maintenance) {}

    public function present(User $actor, Asset $asset, ?int $historyBefore = null): array
    {
        Gate::forUser($actor)->authorize('view', $asset);
        $ready = Schema::hasTable('asset_profile_events');
        $sites = $this->access->accessibleSiteIds($actor);
        $documents = $asset->documents()->with('documentSet', 'uploadedBy:id,name')->whereNull('source_type')->latest()->get();
        $movements = $ready ? AssetCustodyMovement::where('asset_id', $asset->id)->with('originSite:id,name', 'destinationSite:id,name', 'destinationRoom:id,name', 'recipient:id,name', 'receivedBy:id,name')->latest()->get()->map(function ($move) use ($sites) {
            $permitted = in_array((int) $move->origin_site_id, $sites, true) && in_array((int) $move->destination_site_id, $sites, true);

            return [
                'id' => $move->id, 'kind' => $move->kind, 'state' => $move->state,
                'origin' => $permitted ? $move->originSite?->name : 'Restricted site',
                'destination' => $permitted ? $move->destinationSite?->name : 'Restricted site',
                'destination_site_id' => $permitted ? $move->destination_site_id : null,
                'origin_site_id' => $permitted ? $move->origin_site_id : null,
                'room' => $permitted ? $move->destinationRoom?->name : null,
                'recipient' => $permitted ? $move->recipient?->name : 'Restricted',
                'received_by' => $permitted ? $move->receivedBy?->name : null,
                'dispatched_at' => $move->dispatched_at?->toISOString(), 'received_at' => $move->received_at?->toISOString(),
                'return_due_on' => $move->return_due_on?->toDateString(), 'returned_at' => $move->returned_at?->toISOString(),
                'reason' => $permitted ? $move->reason : null, 'receipt_note' => $permitted ? $move->receipt_note : null,
                'kit' => $permitted ? $move->kit_snapshot : [], 'received_kit' => $permitted ? $move->received_kit ?? [] : [], 'can_receive' => $permitted,
            ];
        })->values() : collect();
        $kit = $ready ? AssetKitItem::where('asset_id', $asset->id)->latest()->get()->map(function ($item) use ($actor) {
            $component = $item->component_asset_id ? $this->access->assignableAsset($actor, (int) $item->component_asset_id) : null;

            return ['id' => $item->id, 'name' => $item->component_asset_id && ! $component ? 'Restricted component' : $item->name, 'component_id' => $component?->id, 'removed_at' => $item->removed_at?->toISOString(), 'reason' => $item->component_asset_id && ! $component ? null : $item->removal_reason, 'added_at' => $item->created_at?->toISOString()];
        })->values() : collect();
        $events = $ready ? AssetProfileEvent::where('asset_id', $asset->id)->when($historyBefore, fn ($query) => $query->where('id', '<', $historyBefore))->latest('id')->limit(100)->get()->map(fn ($event) => [
            'id' => $event->id, 'action' => $event->action, 'actor' => User::whereKey($event->actor_user_id)->value('name'),
            'at' => $event->occurred_at?->toISOString(), 'message' => $event->payload['message'] ?? null,
            'reason' => (isset($event->payload['recorded_site_id']) && ! in_array((int) $event->payload['recorded_site_id'], $sites, true)) || (isset($event->payload['movement_id']) && ! $movements->contains(fn ($move) => $move['id'] === $event->payload['movement_id'] && $move['can_receive'])) ? null : ($event->payload['reason'] ?? null),
        ])->values() : collect();
        $permissions = [];
        foreach (['update', 'manageOwnership', 'manageDocuments', 'manageAssignments', 'recordInspection', 'recordScan', 'delete'] as $ability) {
            $permissions[$ability] = Gate::forUser($actor)->allows($ability, $asset);
        }
        $permissions['report'] = $this->maintenance->canReport($actor);
        $permissions['maintenance'] = $this->maintenance->canRead($actor);
        $permissions['assess'] = $this->maintenance->canManage($actor);
        $permissions['finance_review'] = $actor->canDo('finance.assets.view') && $permissions['update'];
        $financeView = $actor->canDo('finance.assets.view');
        $financeReviews = $financeView ? FleetFinanceReviewRequest::where('asset_id', $asset->id)->when(! $actor->canDo('finance.ap.view'), fn ($query) => $query->whereNotIn('source_type', ['bill', 'purchase_order']))->with('requestedBy:id,name')->latest('id')->limit(50)->get()->map(fn ($review) => [
            'id' => $review->id, 'reference' => $review->reference_number, 'type' => $review->typeLabel(), 'status' => $review->status, 'note' => $review->note, 'amount' => $review->amount, 'by' => $review->requestedBy?->name, 'at' => $review->created_at?->toISOString(), 'decision' => $review->decision_note, 'url' => '/finance/vehicle-reviews?request='.$review->id,
        ])->values() : [];
        $financeSources = [['value' => 'vehicle', 'label' => $asset->asset_tag.' · '.$asset->name]];
        if ($permissions['finance_review'] && $permissions['maintenance']) {
            foreach ($this->maintenance->scopedWorkOrders($actor)->where('asset_id', $asset->id)->latest('id')->limit(50)->get() as $work) {
                $financeSources[] = ['value' => 'work_order:'.$work->id, 'label' => ($work->reference_number ?: 'WO-'.$work->id).' · '.$work->title];
            }
        }

        return [
            'sources' => app(AssetProfileSources::class)->present($actor, $asset),
            'photo_url' => ($photo = $documents->firstWhere('id', $asset->profile_photo_document_id)) && ! $photo->archived_at && $photo->state === 'available' && in_array($photo->detected_mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true) ? '/assets/'.$asset->id.'/documents/'.$photo->id.'/download?inline=1' : null,
            'ready' => $ready, 'version' => (int) ($asset->asset_profile_version ?? 1), 'condition' => $asset->condition,
            'permissions' => $permissions, 'documents' => $documents->map(fn ($document) => $this->document($asset, $document))->values(),
            'movements' => $movements, 'kit' => $kit, 'events' => $events,
            'finance_reviews' => $financeReviews, 'finance_sources' => $permissions['finance_review'] ? $financeSources : [],
            'finance_types' => $permissions['finance_review'] ? collect(FleetFinanceReviewRequest::TYPES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values() : [],
            'history_next' => $ready && $events->isNotEmpty() && AssetProfileEvent::where('asset_id', $asset->id)->where('id', '<', $events->last()['id'])->exists() ? $events->last()['id'] : null,
            'manual_location' => $ready ? AssetProfileEvent::where('asset_id', $asset->id)->where('action', 'verify_location')->whereIn('payload->site_id', $sites)->latest('id')->first()?->payload : null,
            'retirement_blockers' => $ready ? $this->profiles->retirementBlockers($asset) : ['The Asset Profile migration must be applied before recording lifecycle changes.'],
            'work' => $this->maintenance->canRead($actor) ? $this->maintenance->scopedWorkOrders($actor)->where('asset_id', $asset->id)->with('assignedTo:id,name')->latest()->limit(100)->get()->map(fn ($work) => [
                'id' => $work->id, 'reference' => $work->reference_number ?: 'WO-'.$work->id, 'title' => $work->title,
                'status' => $work->status, 'priority' => $work->priority, 'owner' => $work->assignedTo?->name,
                'next_action' => $work->next_action, 'due_at' => $work->due_at?->toISOString(), 'url' => '/fleet-assets/maintenance/work-orders/'.$work->id,
            ])->values() : [],
            'checks' => $asset->inspections()->with('inspectedBy:id,name')->latest('inspected_at')->limit(100)->get()->map(fn ($check) => ['id' => $check->id, 'at' => $check->inspected_at?->toISOString(), 'by' => $check->inspectedBy?->name, 'result' => $check->result, 'notes' => $check->notes, 'due' => $check->next_due_at?->toDateString()])->values(),
            'qr' => $asset->qr_token ? ['image' => '/assets/'.$asset->id.'/qr.png', 'svg' => '/assets/'.$asset->id.'/qr.svg', 'download' => '/assets/'.$asset->id.'/qr.png/download', 'label' => '/assets/'.$asset->id.'/qr/labels'] : null,
            'room' => $asset->site_room_id ? SiteRoom::where('site_id', $asset->site_id)->whereKey($asset->site_room_id)->value('name') : null,
        ];
    }

    private function document(Asset $asset, AssetDocument $file): array
    {
        $exists = $file->isOpenable() && Storage::disk($file->storage_disk ?: 'private')->exists($file->storage_path);
        $mime = $file->detected_mime;
        if (! $mime && $exists) {
            $mime = Storage::disk($file->storage_disk ?: 'private')->mimeType($file->storage_path) ?: null;
        }
        $state = $file->isOpenable() ? ($exists ? ($file->archived_at ? 'archived' : ($file->state === 'available' ? 'available' : 'legacy_unverified')) : 'unavailable') : $file->state;
        $version = $file->revision ?: $file->version ?: 1;
        $current = ! $file->archived_at && (! $file->documentSet || $file->revision === $file->documentSet->current_revision);
        $url = '/assets/'.$asset->id.'/documents/'.$file->id.'/download';

        return [
            'id' => $file->id, 'name' => $file->title ?: $file->original_name, 'filename' => $file->original_name,
            'category' => $file->category ?: 'Document', 'mime' => $mime, 'bytes' => $file->size_bytes,
            'version' => $version, 'set_id' => $file->document_set_id, 'set_version' => $file->documentSet?->lock_version ?? 0,
            'current' => $current, 'archived' => $file->archived_at !== null, 'state' => $state,
            'added_at' => $file->created_at?->toISOString(), 'added_by' => $file->uploadedBy?->name,
            'expiry_date' => $file->expiry_date?->toDateString(), 'reason' => $file->archive_reason,
            'source' => 'Asset document'.($file->document_set_id ? ' set DS-'.$file->document_set_id : ''),
            'previewUrl' => $exists ? $url.'?inline=1' : null, 'downloadUrl' => $exists ? $url : null,
            'unavailableReason' => match ($state) {
                'quarantined' => 'This file failed its virus check. Contact the source owner.', 'unavailable', 'storage_failed' => 'The original file is unavailable. Its record remains in history.', default => $exists ? null : 'This file is awaiting a successful file check. It cannot be opened yet.'
            },
        ];
    }
}
