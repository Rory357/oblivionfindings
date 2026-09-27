<?php

namespace App\Services\Assets;

use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\AssetCustodyMovement;
use App\Models\AssetKitItem;
use App\Models\AssetProfileEvent;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AssetProfileService
{
    public function __construct(private readonly SecurityDevicesAccessService $access, private readonly AssetMutationIntegrityService $integrity) {}

    /** Every command locks the canonical asset and retains an idempotent, immutable result. */
    public function command(User $actor, Asset $asset, array $data): array
    {
        $fingerprint = hash('sha256', json_encode([$actor->id, $data], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $asset, $data, $fingerprint) {
            $actor = User::findOrFail($actor->id);
            $asset = $this->access->assignableAsset($actor, (int) $asset->id, true) ?? abort(404);
            abort_if(Asset::vehicles()->whereKey($asset->id)->exists(), 409, 'Use the canonical vehicle profile for this asset.');
            $action = $data['action'];
            Gate::forUser($actor)->authorize(match ($action) {
                'dispatch', 'receive', 'return', 'cancel_movement', 'exception', 'assign', 'release' => 'manageAssignments',
                'retire' => 'delete', 'verify_location' => 'recordScan', 'check' => 'recordInspection', 'ownership' => 'manageOwnership', default => 'update',
            }, $asset);
            $prior = AssetProfileEvent::where('asset_id', $asset->id)->where('request_key', $data['request_key'])->first();
            if ($prior) {
                abort_unless(hash_equals($prior->fingerprint, $fingerprint), 409, 'This request was already used for a different change.');

                return $prior->payload;
            }
            abort_unless((int) $asset->asset_profile_version === (int) $data['expected_version'], 409, 'This asset changed while you were working. Refresh it before trying again. Your draft is retained.');
            abort_if($asset->status === 'retired' && $action !== 'retire', 409, 'This asset is retired. Its history remains available.');
            $result = match ($action) {
                'dispatch', 'return' => $this->dispatch($actor, $asset, $data),
                'receive' => $this->receive($actor, $asset, $data),
                'cancel_movement' => $this->cancel($actor, $asset, $data),
                'kit_add' => $this->addKit($actor, $asset, $data),
                'kit_remove' => $this->removeKit($asset, $data),
                'verify_location' => $this->verifyLocation($actor, $asset, $data),
                'retire' => $this->retire($actor, $asset),
                'assign', 'release' => $this->responsibility($actor, $asset, $data),
                'check' => $this->check($actor, $asset, $data),
                'set_photo', 'remove_photo' => $this->photo($actor, $asset, $data),
                'generate_qr' => $this->qr($asset),
                'ownership' => $this->ownership($actor, $asset, $data),
                'exception' => ['message' => 'Custody exception recorded. Responsibility and any hold remain unchanged.', 'reason' => $data['reason']],
                default => abort(422),
            };
            $asset->refresh()->forceFill(['asset_profile_version' => (int) $asset->asset_profile_version + 1, 'updated_by_user_id' => $actor->id])->save();
            $payload = $result + ['reason' => $data['reason'], 'version' => $asset->asset_profile_version, 'recorded_site_id' => $asset->site_id];
            AssetProfileEvent::create(['asset_id' => $asset->id, 'actor_user_id' => $actor->id, 'action' => $action, 'request_key' => $data['request_key'], 'fingerprint' => $fingerprint, 'payload' => $payload, 'occurred_at' => now()]);
            AuditLogger::logOrFail('assets.profile.'.$action, $asset, $payload);

            return $payload;
        }, 3);
    }

    private function ownership(User $actor, Asset $asset, array $data): array
    {
        $owner = app(AssetOwnershipService::class)->change($actor, $asset, ['owner_type' => $data['owner_type'], 'owner_id' => $data['owner_id'], 'notes' => $data['reason']]);

        return ['ownership_id' => $owner->id, 'message' => 'Ownership recorded. Custody, placement and Finance recognition remain separate.'];
    }

    private function dispatch(User $actor, Asset $asset, array $data): array
    {
        abort_if(AssetKitItem::where('component_asset_id', $asset->id)->whereNull('removed_at')->exists(), 409, 'Dispatch this component with its parent kit, or remove it from the kit first.');
        $pending = AssetCustodyMovement::where('asset_id', $asset->id)->whereIn('state', ['pending_receipt', 'incomplete', 'disputed'])->lockForUpdate()->first();
        abort_if($pending, 409, 'Resolve the outstanding receipt before starting another movement.');
        $loan = null;
        if ($data['action'] === 'return') {
            $loan = AssetCustodyMovement::where('asset_id', $asset->id)->whereKey($data['movement_id'] ?? 0)->where('kind', 'loan')->where('state', 'acknowledged')->whereNull('returned_at')->lockForUpdate()->firstOrFail();
            $data['destination_site_id'] = $loan->origin_site_id;
            $data['kind'] = 'return';
        } else {
            abort_if(AssetCustodyMovement::where('asset_id', $asset->id)->where('kind', 'loan')->where('state', 'acknowledged')->whereNull('returned_at')->exists(), 409, 'Record the outstanding loan return before moving this asset again.');
        }
        $origin = (int) ($asset->site_id ?: $asset->home_site_id);
        $destination = (int) ($data['destination_site_id'] ?? 0);
        $sites = $this->access->accessibleSiteIds($actor);
        abort_unless(in_array($origin, $sites, true) && in_array($destination, $sites, true), 404);
        abort_if($asset->client_id && $origin !== $destination, 409, 'Client-owned equipment must retain its canonical client/site ownership. Resolve placement with the source owner first.');
        $this->integrity->assertPlacementChangeAllowed($asset, ['site_id' => $destination, 'home_site_id' => $asset->home_site_id === null ? null : $destination]);
        $recipient = $this->access->assignableStaffMember($actor, (int) ($data['recipient_user_id'] ?? 0), true) ?? abort(404);
        $profile = HrEmployeeProfile::where('user_id', $recipient->id)->where('is_active', true)->lockForUpdate()->first();
        $staffSites = array_map('intval', [$profile?->primary_site_id, ...($profile?->secondary_site_ids ?? [])]);
        abort_unless(in_array($destination, $staffSites, true), 404, 'Choose a current recipient at the destination site.');
        $room = ! empty($data['destination_room_id']) ? SiteRoom::where('site_id', $destination)->whereKey($data['destination_room_id'])->firstOrFail() : null;
        $kind = $data['kind'] ?? 'transfer';
        if ($kind === 'loan' && empty($data['return_due_on'])) {
            throw ValidationException::withMessages(['return_due_on' => 'Choose the expected return date.']);
        }
        $kit = AssetKitItem::where('asset_id', $asset->id)->whereNull('removed_at')->get(['id', 'name', 'component_asset_id'])->toArray();
        $this->movableComponents($actor, $asset, $kit, $destination);
        $movement = AssetCustodyMovement::create([
            'asset_id' => $asset->id, 'kind' => $kind, 'state' => 'pending_receipt',
            'origin_site_id' => $origin, 'destination_site_id' => $destination, 'destination_room_id' => $room?->id,
            'recipient_user_id' => $recipient->id, 'dispatched_by_user_id' => $actor->id,
            'returns_movement_id' => $loan?->id, 'reason' => $data['reason'], 'dispatched_at' => now(),
            'return_due_on' => $kind === 'loan' ? $data['return_due_on'] : null,
            'kit_snapshot' => $kit,
        ]);

        return ['movement_id' => $movement->id, 'message' => 'Dispatch recorded. The destination is expected until actual receipt is acknowledged.'];
    }

    private function responsibility(User $actor, Asset $asset, array $data): array
    {
        $service = app(AssetAssignmentService::class);
        if ($data['action'] === 'assign') {
            $assignment = $service->assign($actor, $asset, ['assignee_type' => 'staff', 'assignee_id' => $data['recipient_user_id'] ?? 0, 'purpose' => $data['reason']]);
        } else {
            $assignment = $asset->assignments()->whereKey($data['assignment_id'] ?? 0)->firstOrFail();
            $service->release($actor, $asset, $assignment);
        }

        return ['assignment_id' => $assignment->id, 'message' => $data['action'] === 'assign' ? 'Responsibility assigned. Physical custody remains separately recorded.' : 'Responsibility released. Physical custody remains separately recorded.'];
    }

    private function check(User $actor, Asset $asset, array $data): array
    {
        $inspection = $asset->inspections()->create(['inspected_by_user_id' => $actor->id, 'inspected_at' => now(), 'result' => $data['result'], 'notes' => $data['reason'], 'next_due_at' => $data['next_due_at'] ?? null]);
        if (! empty($data['next_due_at'])) {
            $asset->forceFill(['requires_inspection' => true, 'inspection_due_at' => $data['next_due_at']])->save();
        }
        if (! empty($data['condition'])) {
            $asset->forceFill(['condition' => $data['condition']])->save();
        }

        return ['inspection_id' => $inspection->id, 'message' => 'Check observation recorded. This does not release a Maintenance hold or certify the asset for use.'];
    }

    private function photo(User $actor, Asset $asset, array $data): array
    {
        Gate::forUser($actor)->authorize('manageDocuments', $asset);
        $file = null;
        if ($data['action'] === 'set_photo') {
            $file = $asset->documents()->whereKey($data['document_id'] ?? 0)->whereNull('archived_at')->whereNull('source_type')->where('state', 'available')->lockForUpdate()->firstOrFail();
            abort_unless(in_array($file->detected_mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true), 422, 'Choose a checked image from this asset’s document library.');
            abort_unless(Storage::disk($file->storage_disk)->exists($file->storage_path), 409, 'The original image is unavailable.');
        }
        $asset->forceFill(['profile_photo_document_id' => $file?->id])->save();

        return ['document_id' => $file?->id, 'message' => $file ? 'Profile photo updated. The original remains in the document library.' : 'Profile photo removed. The original remains in document history.'];
    }

    private function qr(Asset $asset): array
    {
        if (! $asset->qr_token) {
            $asset->forceFill(['qr_token' => Str::random(32)])->save();
        }

        return ['message' => 'Stable QR identity is ready. Existing identities are retained.'];
    }

    private function receive(User $actor, Asset $asset, array $data): array
    {
        $movement = AssetCustodyMovement::where('asset_id', $asset->id)->whereKey($data['movement_id'] ?? 0)->lockForUpdate()->firstOrFail();
        abort_unless(in_array($movement->state, ['pending_receipt', 'incomplete', 'disputed'], true), 409, 'This movement has already been resolved.');
        $sites = $this->access->accessibleSiteIds($actor);
        abort_unless(in_array((int) $movement->origin_site_id, $sites, true) && in_array((int) $movement->destination_site_id, $sites, true), 404);
        $expected = array_map('intval', array_column($movement->kit_snapshot, 'id'));
        $received = array_values(array_unique(array_map('intval', $data['received_kit'] ?? [])));
        abort_if(array_diff($received, $expected) !== [], 422, 'Select only items in the dispatched kit.');
        $outcome = $data['outcome'] ?? 'acknowledged';
        if ($outcome === 'acknowledged' && array_diff($expected, $received) !== []) {
            throw ValidationException::withMessages(['received_kit' => 'Confirm every kit item or record an incomplete receipt.']);
        }
        if ($outcome === 'acknowledged') {
            $this->integrity->assertPlacementChangeAllowed($asset, ['site_id' => $movement->destination_site_id, 'home_site_id' => $asset->home_site_id === null ? null : $movement->destination_site_id]);
            $components = $this->movableComponents($actor, $asset, $movement->kit_snapshot, (int) $movement->destination_site_id);
            $asset->forceFill(['site_id' => $movement->destination_site_id, 'home_site_id' => $asset->home_site_id === null ? null : $movement->destination_site_id, 'site_room_id' => $movement->destination_room_id, 'room_id' => null, 'location' => $movement->destination_room_id ? SiteRoom::whereKey($movement->destination_room_id)->value('name') : null])->save();
            foreach ($components as $component) {
                $component->forceFill(['site_id' => $asset->site_id, 'home_site_id' => $component->home_site_id === null ? null : $asset->site_id, 'site_room_id' => $asset->site_room_id, 'room_id' => null, 'location' => $asset->location, 'asset_profile_version' => (int) $component->asset_profile_version + 1, 'updated_by_user_id' => $actor->id])->save();
                AssetProfileEvent::create(['asset_id' => $component->id, 'actor_user_id' => $actor->id, 'action' => 'kit_receipt', 'request_key' => hash('sha256', $data['request_key'].':component:'.$component->id), 'fingerprint' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), 'payload' => ['message' => 'Actual receipt acknowledged with the parent kit. Maintenance restrictions remain unchanged.', 'parent_asset_id' => $asset->id, 'recorded_site_id' => $asset->site_id, 'reason' => $data['reason']], 'occurred_at' => now()]);
            }
            if ($movement->returns_movement_id) {
                AssetCustodyMovement::where('asset_id', $asset->id)->whereKey($movement->returns_movement_id)->lockForUpdate()->firstOrFail()->forceFill(['returned_at' => now(), 'state' => 'returned'])->save();
            }
        }
        $movement->forceFill(['state' => $outcome, 'received_kit' => $received, 'received_by_user_id' => $actor->id, 'received_at' => now(), 'receipt_note' => $data['reason']])->save();

        return ['movement_id' => $movement->id, 'outcome' => $outcome, 'received_kit' => $received, 'message' => $outcome === 'acknowledged' ? 'Actual receipt recorded. Any maintenance hold remains in place.' : 'Receipt exception recorded. Assigned location and responsibility remain unchanged.'];
    }

    private function cancel(User $actor, Asset $asset, array $data): array
    {
        $movement = AssetCustodyMovement::where('asset_id', $asset->id)->whereKey($data['movement_id'] ?? 0)->lockForUpdate()->firstOrFail();
        $sites = $this->access->accessibleSiteIds($actor);
        abort_unless(in_array((int) $movement->origin_site_id, $sites, true) && in_array((int) $movement->destination_site_id, $sites, true), 404);
        abort_unless($movement->state === 'pending_receipt', 409, 'Only an unacknowledged dispatch can be cancelled.');
        $movement->forceFill(['state' => 'cancelled', 'receipt_note' => $data['reason']])->save();

        return ['movement_id' => $movement->id, 'message' => 'Dispatch cancelled. No receipt or location change was recorded.'];
    }

    private function addKit(User $actor, Asset $asset, array $data): array
    {
        abort_if(AssetKitItem::where('component_asset_id', $asset->id)->whereNull('removed_at')->exists(), 409, 'A component already in a kit cannot contain another kit.');
        $componentId = $data['component_asset_id'] ?? null;
        if ($componentId) {
            abort_if((int) $componentId === (int) $asset->id, 422, 'An asset cannot contain itself.');
            $component = $this->access->assignableAsset($actor, (int) $componentId, true) ?? abort(404);
            Gate::forUser($actor)->authorize('update', $component);
            abort_if($component->status === 'retired' || Asset::vehicles()->whereKey($component->id)->exists(), 422, 'Choose an active equipment component. Vehicles retain their own profile and custody.');
            abort_unless($component->site_id === $asset->site_id, 422, 'Choose a component at this asset’s site.');
            abort_if($component->client_id !== $asset->client_id, 422, 'Components must have the same ownership context.');
            abort_if(AssetKitItem::where('component_asset_id', $componentId)->whereNull('removed_at')->exists(), 409, 'This component already belongs to a kit.');
            // Keep one level: no cycles or nested movements claiming receipt of other kits.
            abort_if(AssetKitItem::where('asset_id', $componentId)->whereNull('removed_at')->exists() || AssetKitItem::where('component_asset_id', $asset->id)->whereNull('removed_at')->exists(), 409, 'Nested kits require separate reconciliation.');
        }
        abort_if(AssetCustodyMovement::where('asset_id', $asset->id)->whereIn('state', ['pending_receipt', 'incomplete', 'disputed'])->exists(), 409, 'Resolve the receipt before changing its kit.');
        $item = AssetKitItem::create(['asset_id' => $asset->id, 'component_asset_id' => $componentId, 'name' => $data['name'], 'added_by_user_id' => $actor->id]);

        return ['kit_item_id' => $item->id, 'message' => 'Kit item added. A linked component keeps its own maintenance history.'];
    }

    private function removeKit(Asset $asset, array $data): array
    {
        abort_if(AssetCustodyMovement::where('asset_id', $asset->id)->whereIn('state', ['pending_receipt', 'incomplete', 'disputed'])->exists(), 409, 'Resolve the receipt before changing its kit.');
        $item = AssetKitItem::where('asset_id', $asset->id)->whereKey($data['kit_item_id'] ?? 0)->whereNull('removed_at')->lockForUpdate()->firstOrFail();
        $item->forceFill(['removed_at' => now(), 'removal_reason' => $data['reason']])->save();

        return ['kit_item_id' => $item->id, 'message' => 'Item removed from the current kit. Its original history is retained.'];
    }

    private function verifyLocation(User $actor, Asset $asset, array $data): array
    {
        return ['message' => 'Manual location observation recorded. Assigned and tracker-reported locations are unchanged.', 'site_id' => $asset->site_id, 'site_room_id' => $asset->site_room_id, 'location' => $data['location'] ?? $asset->location, 'observed_at' => now()->toISOString()];
    }

    /** Linked components move atomically with a fully acknowledged kit receipt. */
    private function movableComponents(User $actor, Asset $asset, array $kit, int $destination): array
    {
        $components = [];
        $ids = array_filter(array_column($kit, 'component_asset_id'));
        sort($ids);
        foreach ($ids as $id) {
            $component = $this->access->assignableAsset($actor, (int) $id, true) ?? abort(404);
            Gate::forUser($actor)->authorize('manageAssignments', $component);
            abort_unless(AssetKitItem::where('asset_id', $asset->id)->where('component_asset_id', $id)->whereNull('removed_at')->exists(), 409, 'The linked kit changed. Reconcile its contents before moving it.');
            abort_if($component->status === 'retired' || $component->site_id !== $asset->site_id || $component->client_id !== $asset->client_id, 409, 'A linked component has a different placement or lifecycle. Reconcile the kit before moving it.');
            abort_if(AssetCustodyMovement::where('asset_id', $id)->where(function ($query) {
                $query->whereIn('state', ['pending_receipt', 'incomplete', 'disputed'])->orWhere(fn ($loan) => $loan->where('kind', 'loan')->where('state', 'acknowledged')->whereNull('returned_at'));
            })->exists(), 409, 'Resolve the linked component’s outstanding custody movement first.');
            $this->integrity->assertPlacementChangeAllowed($component, ['site_id' => $destination, 'home_site_id' => $component->home_site_id === null ? null : $destination]);
            $components[] = $component;
        }

        return $components;
    }

    public function retirementBlockers(Asset $asset): array
    {
        $blockers = [];
        if (AssetKitItem::where('asset_id', $asset->id)->whereNull('removed_at')->exists()) {
            $blockers[] = 'Remove or reassign the current kit items.';
        }
        if ($asset->assignments()->whereNull('released_at')->exists()) {
            $blockers[] = 'Release the current assignment.';
        }
        if (AssetCustodyMovement::where('asset_id', $asset->id)->whereIn('state', ['pending_receipt', 'incomplete', 'disputed'])->exists()) {
            $blockers[] = 'Resolve the outstanding receipt.';
        }
        if (AssetCustodyMovement::where('asset_id', $asset->id)->where('kind', 'loan')->where('state', 'acknowledged')->whereNull('returned_at')->exists()) {
            $blockers[] = 'Record actual return of the outstanding loan.';
        }
        if ($asset->activeDeviceLinks()->exists()) {
            $blockers[] = 'Unlink active devices through Security & Devices.';
        }
        if ($asset->workOrders()->whereNotIn('status', ['completed', 'cancelled'])->exists()) {
            $blockers[] = 'Resolve open Maintenance work.';
        }
        if (DB::table('fleet_maintenance_restrictions')->where('asset_id', $asset->id)->where('state', 'active')->exists()) {
            $blockers[] = 'Resolve the active Maintenance restriction.';
        }
        if (FinFixedAsset::where('linked_asset_id', $asset->id)->where('status', '!=', 'disposed')->exists()) {
            $blockers[] = 'Finance must resolve the linked fixed asset before operational retirement.';
        }
        if (AssetKitItem::where('component_asset_id', $asset->id)->whereNull('removed_at')->exists()) {
            $blockers[] = 'Remove this component from its parent kit.';
        }

        return $blockers;
    }

    private function retire(User $actor, Asset $asset): array
    {
        $blockers = $this->retirementBlockers($asset);
        if ($blockers !== []) {
            throw ValidationException::withMessages(['asset' => $blockers]);
        }
        app(AssetLifecycleService::class)->retire($actor, $asset);
        $asset->refresh();

        return ['message' => 'Asset retired. History is retained; no financial disposal or journal was created.'];
    }
}
