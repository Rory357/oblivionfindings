<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\HazardousSubstance;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Private command admission; preparing permissions never grants public or private source access. */
final class ChemicalExposureCommandAccess
{
    public const PERMISSIONS = ['hazards.view', 'hazards.create', 'hazards.manage', 'hr.wellbeing.view',
        'healthSafety.viewAllSites', 'incidents.viewAny', 'incidents.viewAssigned', 'hr.cases.view',
        'clients.viewAny', 'clients.viewAssigned', 'clinical.accessAllSites', 'sites.viewAll', 'respite.viewAny'];

    public function __construct(private readonly ChemicalExposureAccessService $read) {}

    public function withCurrentViewer(User $actor, callable $callback): mixed
    {
        return HealthSafetyReadContext::within($actor, self::PERMISSIONS,
            fn (HealthSafetyReadContext $context) => $context->consumeViewer(self::PERMISSIONS, $callback));
    }

    public function assertOperation(User $current, CurrentAuthorizationReads $reads, string $operation): void
    {
        $reads->assertActive();
        if ($operation === 'capture') {
            abort_unless($current->canDo('hazards.create') || $current->canDo('hazards.manage'), 403);
            return;
        }
        $this->read->assertRead($current, $reads);
        abort_unless($operation === 'read' || $current->canDo('hazards.manage'), 403);
    }

    public function source(SubstanceExposureRecord|int $source, User $current, CurrentAuthorizationReads $reads,
        array $sites, string $operation = 'manage'): SubstanceExposureRecord
    {
        $this->assertOperation($current, $reads, $operation);
        $id = $this->id($source);
        $query = $this->read->readableQuery($current, $reads, $sites)->whereKey($id);
        // Keep the canonical predicate and current-read tree; override only the final root lock before SQL.
        $query->getQuery()->beforeQuery(fn ($compiled) => $compiled->lock('for update nowait'));
        $identity = (clone $query)->firstOrFail(ChemicalExposureSourceService::BODY_ADMISSION_FIELDS);
        $this->read->assertSite((int) $identity->site_id, $reads, $sites);
        app(ChemicalExposureSourceService::class)->admitExposureIdentityBeforeBody($identity, $current, $reads, $sites);
        $query->where('user_id', $identity->user_id)->where('site_id', $identity->site_id)
            ->where('hazardous_substance_id', $identity->hazardous_substance_id)
            ->where('source_schema_version', $identity->source_schema_version)->where('source_revision', $identity->source_revision)
            ->where('related_incident_id', $identity->related_incident_id);
        if ($identity->original_context === null) { $query->whereNull('original_context'); }
        else { $query->whereRaw('SHA2(CAST(original_context AS CHAR), 256) = ?', [hash('sha256', $identity->getRawOriginal('original_context'))]); }
        $row = $query->firstOrFail();
        app(ChemicalExposureSourceService::class)->admitExposureIdentityBeforeBody($row, $current, $reads, $sites);
        // Canonical BEFORE QUERY NOWAIT hook remains on the first identity query and the same row throughout.
        $this->read->assertSite((int) $row->site_id, $reads, $sites);
        abort_unless($reads->query(User::query())->whereKey($row->user_id)->exists(), 404);
        return $row;
    }

    public function substance(HazardousSubstance|int $source, CurrentAuthorizationReads $reads): HazardousSubstance
    {
        $reads->assertActive();
        return $reads->query(HazardousSubstance::query())->whereKey($this->id($source))->firstOrFail();
    }

    public function site(int $id, CurrentAuthorizationReads $reads, array $sites): void
    {
        $this->read->assertSite($id, $reads, $sites);
    }

    public function staff(int $id, int $siteId, CurrentAuthorizationReads $reads): User
    {
        $row = $reads->query(app(HrCurrentStaffService::class)->currentUsersQuery())
            ->where('external_clinical_account', false)->whereKey($id)->firstOrFail();
        abort_unless(in_array($siteId, app(UserSiteAccessService::class)->accessibleSiteIds($row, [], $reads), true), 404);
        return $row;
    }

    public function id(Model|int $source): int
    {
        if ($source instanceof Model && $source->getConnection() !== DB::connection()) {
            throw new LogicException('Exposure commands require the canonical physical connection.');
        }
        $id = $source instanceof Model ? (int) $source->id : $source;
        abort_unless($id > 0, 404);
        return $id;
    }
}
