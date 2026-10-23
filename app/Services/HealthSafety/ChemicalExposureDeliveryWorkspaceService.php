<?php

namespace App\Services\HealthSafety;

use App\Models\ChemicalExposureSourceDelivery;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Unmounted recovery composition. Inspecting accepted outbox work never dispatches a destination. */
final class ChemicalExposureDeliveryWorkspaceService
{
    public const PERMISSIONS = ChemicalExposureCommandAccess::PERMISSIONS;
    private const PER_PAGE = 25;

    public function __construct(private readonly ChemicalExposureAccessService $access,
        private readonly ChemicalExposureCommandAccess $commands, private readonly ChemicalExposureSourceService $source,
        private readonly ChemicalExposureLedger $ledger, private readonly ChemicalExposureHsEventDeliveryService $healthSafety,
        private readonly ChemicalExposureFollowupDestinationService $followup) {}

    public function index(User $viewer, int $exposureId, array $filters = [], ?HealthSafetyReadContext $context = null): array
    {
        $page = $this->page($filters['page'] ?? 1);
        return $this->within($viewer, $context, function ($active, $current, $reads, $sites) use ($exposureId, $page): array {
            [$root, $proof] = $this->root($exposureId, $current, $reads, $sites);
            if ($proof['attribution_state'] === 'repair_required') { return $this->withheld('repair_required', $page); }
            // Discover identity only. All accepted original Sites/contexts must be proved before totals are meaningful.
            $identities = $reads->query(ChemicalExposureSourceDelivery::query())->where('exposure_id', $root->id)
                ->orderByDesc('id')->get(['id']);
            $items = []; $repair = false;
            foreach ($identities as $identity) {
                try { $item = $this->attempt((int) $identity->id, $root, $proof, $current, $reads, $sites, $active); }
                catch (HttpExceptionInterface $denial) {
                    if (! in_array($denial->getStatusCode(), [403,404], true)) { throw $denial; }
                    return $this->withheld('restricted', $page);
                } catch (ModelNotFoundException) { return $this->withheld('unavailable', $page); }
                $repair = $repair || $item['acceptance_integrity'] === 'repair_required';
                $items[] = $item;
            }
            if (! $this->stableRoot($root, $proof, $current, $reads, $sites)) { return $this->withheld('repair_required', $page); }
            $total = $repair ? null : count($items);
            return ['source_integrity' => $repair ? 'repair_required' : $proof['attribution_state'],
                'attempts' => array_slice($items, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
                'page' => ['current_page' => $page, 'per_page' => self::PER_PAGE, 'total' => $total,
                    'last_page' => $total === null ? null : max(1, (int) ceil($total / self::PER_PAGE)),
                    'has_more' => $total === null ? null : $total > $page * self::PER_PAGE]];
        });
    }

    public function detail(User $viewer, int $exposureId, int $deliveryId, ?HealthSafetyReadContext $context = null): array
    {
        return $this->within($viewer, $context, function ($active, $current, $reads, $sites) use ($exposureId, $deliveryId): array {
            // Route root/FK must agree BEFORE the source or delivery private body is selected.
            $reads->query(ChemicalExposureSourceDelivery::query())->where('exposure_id', $exposureId)->whereKey($deliveryId)
                ->firstOrFail(['id','exposure_id']);
            [$root, $proof] = $this->root($exposureId, $current, $reads, $sites);
            if ($proof['attribution_state'] === 'repair_required') { return $this->repair($deliveryId); }
            return $this->attempt($deliveryId, $root, $proof, $current, $reads, $sites, $active);
        });
    }

    /** Explicit POST only. No caller transaction may survive the source commit or destination confirmation. */
    public function retry(User $viewer, int $exposureId, int $deliveryId): array
    {
        if (DB::transactionLevel() !== 0) { throw new LogicException('Exposure retry requires the source physical commit and no existing transaction.'); }
        // This fresh inspection is advisory. The approved expected-root patch binds the actual destination transaction again.
        $before = $this->within($viewer, null, function ($active, $current, $reads, $sites) use ($exposureId, $deliveryId): array {
            $this->commands->assertOperation($current, $reads, 'manage');
            $reads->query(ChemicalExposureSourceDelivery::query())->where('exposure_id', $exposureId)->whereKey($deliveryId)
                ->firstOrFail(['id','exposure_id']);
            [$root, $proof] = $this->root($exposureId, $current, $reads, $sites);
            return $proof['attribution_state'] === 'repair_required' ? $this->repair($deliveryId)
                : $this->attempt($deliveryId, $root, $proof, $current, $reads, $sites, $active);
        });
        if (! $before['can']['retry']) {
            throw ValidationException::withMessages(['delivery' => $before['blocked_reason'] ?? 'This attempt is not currently eligible for delivery.']);
        }
        if (DB::transactionLevel() !== 0) { throw new LogicException('Exposure destination must start after recovery preflight commits.'); }
        // Concrete services only. No supplied callback or delivered boolean can impersonate a destination.
        if ($before['kind'] === 'health_safety') { $this->healthSafety->deliver($viewer, $deliveryId, $exposureId); }
        elseif ($before['kind'] === 'followup') { $this->followup->deliverForViewer($viewer, $deliveryId, $exposureId); }
        else { throw new LogicException('Unknown exposure recovery purpose.'); }
        if (DB::transactionLevel() !== 0) { throw new LogicException('Exposure destination did not complete its physical transaction.'); }
        // A lost/uncertain response is reconciled by a NEW read proof, never by the consumer's returned delivered flag.
        return ['retry_requested_by' => (int) $viewer->id, 'inspection' => $this->detail($viewer, $exposureId, $deliveryId)];
    }

    private function within(User $viewer, ?HealthSafetyReadContext $context, callable $consumer): mixed
    {
        $operation = function (HealthSafetyReadContext $active) use ($viewer, $consumer): mixed {
            $active->assertViewer($viewer);
            return $active->consumeViewer(self::PERMISSIONS, function ($current, $reads, $sites) use ($active, $consumer) {
                $this->access->assertRead($current, $reads);
                $result = $consumer($active, $current, $reads, $sites); $active->assertActive();
                return $result;
            });
        };
        return $context === null ? HealthSafetyReadContext::within($viewer, self::PERMISSIONS, $operation) : $operation($context);
    }

    private function root(int $id, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $root = $this->access->assertExposure($id, $current, $reads, $sites);
        try {
            $this->source->currentVersion($root, $root, 'exposure', $reads, $current, $sites);
            $presented = $this->access->assertExposure($id, $current, $reads, $sites);
            if (ChemicalCommandValues::digest($this->ledger->snapshot($presented)) !== ChemicalCommandValues::digest($this->ledger->snapshot($root))) {
                throw new LogicException('Exposure changed between discovery and delivery presentation.');
            }
            $version = $this->source->currentVersion($presented, $presented, 'exposure', $reads, $current, $sites);
            $root = $presented;
            $original = $version === null ? null : $root->original_context;
            if ($original !== null && (! is_array($original) || ($original['site_id'] ?? null) !== (int) $root->site_id
                || ($original['incident_id'] ?? null) !== $root->related_incident_id)) { throw new LogicException('Delivery source original context differs from this exact root.'); }
            if ($original !== null) { $this->source->assertOriginalContext($original, $current, $reads, (int) $root->site_id); }
            if (! $this->unchangedRawRoot($root, $reads)) { throw new LogicException('Exposure changed after exact source proof.'); }
            return [$root, ['attribution_state' => $version === null ? 'legacy_unknown' : 'recorded',
                'source_id' => (int) $root->id, 'source_revision' => (int) $root->source_revision, 'source_version_id' => $version?->id]];
        } catch (LogicException) { $reads->assertActive(); return [$root, ['attribution_state' => 'repair_required', 'source_id' => (int) $root->id]]; }
    }

    private function stableRoot(SubstanceExposureRecord $root, array $proof, User $current, CurrentAuthorizationReads $reads, array $sites): bool
    {
        try {
            $canonical = $this->access->assertExposure((int) $root->id, $current, $reads, $sites);
            if (ChemicalCommandValues::digest($this->ledger->snapshot($canonical)) !== ChemicalCommandValues::digest($this->ledger->snapshot($root))) { return false; }
            $version = $this->source->currentVersion($canonical, $canonical, 'exposure', $reads, $current, $sites);
            if (($version?->id) !== $proof['source_version_id'] || (int) $canonical->source_revision !== $proof['source_revision']) { return false; }
            if ($version !== null) { $this->source->assertOriginalContext($canonical->original_context, $current, $reads, (int) $canonical->site_id); }
            return $this->unchangedRawRoot($root, $reads);
        } catch (LogicException) { $reads->assertActive(); return false; }
        catch (ModelNotFoundException) { return false; }
        catch (HttpExceptionInterface $denial) { if (! in_array($denial->getStatusCode(), [403,404], true)) { throw $denial; } return false; }
    }

    private function unchangedRawRoot(SubstanceExposureRecord $root, CurrentAuthorizationReads $reads): bool
    {
        // Final conditional SQL has no model hydration/listener gap and compares the same persisted facts, not another proof token.
        $query = SubstanceExposureRecord::query()->whereKey($root->id);
        foreach (array_diff_key($root->getAttributes(), ['created_at' => true,'updated_at' => true]) as $column => $value) {
            if ($column === 'original_context' && $value !== null) { $query->whereRaw('CAST(`original_context` AS CHAR) = ?', [$value]); }
            else { $query->where($column, $value); }
        }
        return $reads->query($query)->exists();
    }

    private function attempt(int $id, SubstanceExposureRecord $root, array $proof, User $current,
        CurrentAuthorizationReads $reads, array $sites, HealthSafetyReadContext $context): array
    {
        if (! $this->stableRoot($root, $proof, $current, $reads, $sites)) { return $this->repair($id); }
        try { $accepted = $this->source->sourceProofForAuditSubject(ChemicalExposureSourceDelivery::class, $id, $current, $reads, $sites); }
        catch (LogicException) { $context->assertActive(); return $this->repair($id); }
        if (($accepted['attribution_state'] ?? null) !== 'recorded' || ! $this->stableRoot($root, $proof, $current, $reads, $sites)) { return $this->repair($id); }
        $row = $reads->query(ChemicalExposureSourceDelivery::query())->where('exposure_id', $root->id)->whereKey($id)->firstOrFail([
            'id','exposure_id','kind','state','attempts','source_version_id','recipient_id','membership']);
        if (! in_array($row->kind, ['health_safety','followup'], true) || ! in_array($row->state, ['pending','delivered','superseded'], true)
            || $row->attempts < 0) { return $this->repair($id); }
        $membership = ['exposure_id' => (int) $root->id, 'substance_id' => (int) $root->hazardous_substance_id,
            'worker_id' => (int) $root->user_id, 'site_id' => (int) $root->site_id, 'source_revision' => (int) $root->source_revision,
            'source_version_id' => (int) $proof['source_version_id'], 'original_context' => $root->original_context];
        $stale = ChemicalCommandValues::digest($membership) !== ChemicalCommandValues::digest($row->membership)
            || $row->source_version_id !== (int) $proof['source_version_id'];
        $destination = $stale ? $this->destination('superseded', 'source_advanced') : $this->confirmation($row, $current, $context);
        if (! $this->stableRoot($root, $proof, $current, $reads, $sites)) { return $this->repair($id); }
        $manage = $current->canDo('hazards.manage');
        return ['id' => $id, 'kind' => $row->kind, 'attempt_state' => $row->state, 'currency' => $stale ? 'superseded' : 'current',
            'source_revision' => (int) $row->membership['source_revision'], 'attempts' => (int) $row->attempts,
            'acceptance_integrity' => 'recorded', 'destination' => $destination,
            'can' => ['retry' => $manage && ! $stale && $destination['can_deliver']],
            'blocked_reason' => ! $manage ? 'current_manage_required' : ($stale ? 'source_advanced' : $destination['blocked_reason'])];
    }

    private function confirmation(ChemicalExposureSourceDelivery $row, User $current, HealthSafetyReadContext $context): array
    {
        $service = $row->kind === 'health_safety' ? $this->healthSafety : $this->followup;
        if (! method_exists($service, 'confirmationInContext') || ! $this->hasExpectedRoot($service, $row->kind)) { return $this->destination('unavailable', 'confirmation_dependency_unmounted'); }
        try {
            $proof = $row->kind === 'health_safety'
                ? $service->confirmationInContext($current, (int) $row->exposure_id, (int) $row->id, $context)
                : $service->confirmationInContext($current, (int) $row->id, $context);
            $context->assertActive();
            // The exact extracted APIs return detached useful proof, not arbitrary destination callback output.
            if (! is_array($proof) || ! in_array($proof['destination_confirmation'] ?? null, ['hs_event_present','inbox_present','unconfirmed','superseded','repair_required'], true)
                || ! is_bool($proof['can_deliver'] ?? null)) { throw new LogicException('Destination inspection returned no strict confirmation.'); }
            return ['state' => $proof['destination_confirmation'], 'channel' => $row->kind === 'followup' ? 'database' : 'health_safety',
                'external_delivery' => 'not_attempted', 'href' => $row->kind === 'health_safety' && $proof['destination_confirmation'] === 'hs_event_present' ? $proof['href'] : null,
                'read' => $row->kind === 'followup' ? ($proof['read'] ?? null) : null, 'actioned' => false,
                'notification_preference' => $row->kind === 'followup' ? ($proof['notification_preference'] ?? null) : null,
                'href_reason' => $row->kind === 'health_safety' ? ($proof['href_reason'] ?? null) : null,
                'can_deliver' => $proof['can_deliver'], 'blocked_reason' => $proof['blocked_reason'] ?? null];
        } catch (HttpExceptionInterface $denial) {
            if (! in_array($denial->getStatusCode(), [403,404], true)) { throw $denial; }
            return $this->destination('restricted', 'destination_authority_unavailable');
        } catch (ModelNotFoundException) { return $this->destination('unavailable', 'destination_source_unavailable'); }
        catch (LogicException) { $context->assertActive(); return $this->destination('repair_required', 'destination_proof_unavailable'); }
    }

    private function hasExpectedRoot(object $service, string $kind): bool
    {
        // A preflight cannot replace the actual destination transaction's expected-root predicate.
        foreach ([new \ReflectionMethod($service, $kind === 'health_safety' ? 'deliver' : 'deliverForViewer'),
            new \ReflectionMethod(ChemicalExposureDeliveryService::class, 'deliverForViewer')] as $method) {
            $parameters = $method->getParameters(); $last = $parameters[count($parameters) - 1];
            if ($last->getName() !== 'expectedExposureId' || ! $last->isOptional()) { return false; }
        }
        return true;
    }

    private function destination(string $state, string $reason): array
    {
        return ['state' => $state, 'channel' => null, 'external_delivery' => 'not_attempted', 'href' => null, 'read' => null,
            'actioned' => false, 'notification_preference' => null, 'href_reason' => $reason, 'can_deliver' => false, 'blocked_reason' => $reason];
    }

    private function repair(int $id): array
    {
        return ['id' => $id, 'kind' => null, 'attempt_state' => 'unconfirmed', 'currency' => 'unknown', 'source_revision' => null,
            'attempts' => null, 'acceptance_integrity' => 'repair_required', 'destination' => $this->destination('repair_required', 'source_acceptance_unavailable'),
            'can' => ['retry' => false], 'blocked_reason' => 'source_acceptance_unavailable'];
    }

    private function withheld(string $state, int $page): array
    {
        return ['source_integrity' => $state, 'attempts' => null,
            'page' => ['current_page' => $page, 'per_page' => self::PER_PAGE, 'total' => null, 'last_page' => null, 'has_more' => null]];
    }

    private function page(mixed $page): int
    {
        $value = filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1,'max_range' => 100000]]);
        if (! is_int($value)) { throw ValidationException::withMessages(['page' => 'Choose a valid delivery page.']); }
        return $value;
    }
}
