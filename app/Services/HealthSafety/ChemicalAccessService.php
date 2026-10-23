<?php

namespace App\Services\HealthSafety;

use App\Models\HazardousSubstance;
use App\Models\SafetyDataSheet;
use App\Models\SubstanceStorageLocation;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Organisation reference definitions do not grant private worker exposure authority. */
final class ChemicalAccessService
{
    public const PERMISSIONS = ['hazards.view', 'hazards.create', 'hazards.manage', 'healthSafety.viewAllSites'];

    public function withCurrentViewer(User $viewer, callable $operation): mixed
    {
        return HealthSafetyReadContext::within($viewer, self::PERMISSIONS,
            fn (HealthSafetyReadContext $context) => $this->withReadContext($context, $operation));
    }

    public function withReadContext(HealthSafetyReadContext $context, callable $operation): mixed
    {
        return $context->consumeViewer(self::PERMISSIONS, function ($current, $reads, $sites) use ($operation) {
            $result = $operation($current, $reads, $sites);
            $reads->assertActive();
            $this->assertDetached($result);

            return $result;
        });
    }

    public function assertOperation(User $current, string $operation = 'view'): void
    {
        $allowed = match ($operation) {
            'view' => $current->canDo('hazards.view'),
            'capture' => $current->canDo('hazards.create') || $current->canDo('hazards.manage'),
            'manage' => $current->canDo('hazards.manage'),
            default => false,
        };
        abort_unless($allowed, 403);
    }

    public function catalogueQuery(User $current, CurrentAuthorizationReads $reads): Builder
    {
        $this->assertOperation($current);

        return $reads->query(HazardousSubstance::query());
    }

    public function assertCatalogue(HazardousSubstance|int $source, User $current, CurrentAuthorizationReads $reads,
        string $operation = 'view', bool $forUpdate = false): HazardousSubstance
    {
        $this->assertOperation($current, $operation);
        $id = $this->sourceId($source);

        $query = $reads->query(HazardousSubstance::query())->whereKey($id);
        if ($forUpdate) {
            // Preserve canonical current-read admission, but take the aggregate
            // write lock on its first read instead of upgrading a shared lock.
            // This callback runs after the current-read connection/scope checks.
            $query->getQuery()->beforeQuery(function (\Illuminate\Database\Query\Builder $compiled) use ($reads) {
                $reads->assertActive();
                $compiled->lock('for update nowait');
            });
        }

        return $query->firstOrFail();
    }

    public function assertSite(int $id, CurrentAuthorizationReads $reads, array $sites): void
    {
        abort_unless($id > 0 && in_array($id, $sites, true), 404);
        abort_unless($reads->query(\App\Models\Site::query())->active()->notArchived()->whereNull('archived_at')->whereKey($id)->exists(), 404);
    }

    public function storageQuery(int $substanceId, User $current, CurrentAuthorizationReads $reads, array $sites): Builder
    {
        $this->assertCatalogue($substanceId, $current, $reads);

        return $reads->query(SubstanceStorageLocation::query()->where('hazardous_substance_id', $substanceId)
            ->whereIn('site_id', $sites)->whereHas('site', fn (Builder $site) => $site->active()->notArchived()->whereNull('archived_at')));
    }

    public function assertStorage(int $substanceId, SubstanceStorageLocation|int $source, User $current,
        CurrentAuthorizationReads $reads, array $sites): SubstanceStorageLocation
    {
        return $this->storageQuery($substanceId, $current, $reads, $sites)->whereKey($this->sourceId($source))->firstOrFail();
    }

    public function assertSds(int $substanceId, SafetyDataSheet|int $source, User $current, CurrentAuthorizationReads $reads): SafetyDataSheet
    {
        $this->assertCatalogue($substanceId, $current, $reads);

        return $reads->query(SafetyDataSheet::query())->where('hazardous_substance_id', $substanceId)->whereKey($this->sourceId($source))->firstOrFail();
    }

    private function sourceId(Model|int $source): int
    {
        if ($source instanceof Model && $source->getConnection() !== DB::connection()) {
            throw new LogicException('The chemical source belongs to a different physical connection.');
        }
        $id = $source instanceof Model ? (int) $source->id : $source;
        abort_unless($id > 0, 404);

        return $id;
    }

    private function assertDetached(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $nested) {
                $this->assertDetached($nested);
            }
        } elseif ($value !== null && ! is_scalar($value)) {
            throw new LogicException('Chemical reads may return detached scalar projections only.');
        }
    }
}
