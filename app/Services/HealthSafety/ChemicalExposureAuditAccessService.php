<?php

namespace App\Services\HealthSafety;

use App\Models\AuditLog;
use App\Models\Client;
use App\Policies\ClientPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use App\Models\ChemicalCommandIntent;
use App\Models\ChemicalExposureCommandReceipt;
use App\Models\ChemicalExposureEvidence;
use App\Models\ChemicalExposureEvidenceCleanup;
use App\Models\ChemicalExposureEvidenceStaging;
use App\Models\ChemicalExposureFollowup;
use App\Models\ChemicalExposureHsProjection;
use App\Models\ChemicalExposureReview;
use App\Models\ChemicalExposureSourceDelivery;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRiskAssessment;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use App\Support\HealthSafety\QueryPaging;

/** Exposure audit discovery is identity-only; audit bodies remain behind current source proof. */
final class ChemicalExposureAuditAccessService
{
    public const PERMISSIONS = [...ChemicalExposureCommandAccess::PERMISSIONS, 'audit.viewAny',
        'governance.packs.view', 'governance.packs.manage'];

    private const PRIVATE_MODELS = [SubstanceExposureRecord::class, ChemicalExposureReview::class,
        ChemicalExposureFollowup::class, ChemicalExposureEvidence::class, ChemicalExposureSourceVersion::class,
        ChemicalExposureCommandReceipt::class, ChemicalExposureEvidenceStaging::class,
        ChemicalExposureEvidenceCleanup::class, ChemicalExposureSourceDelivery::class, ChemicalExposureHsProjection::class];

    private const META_LOCATIONS = ['', 'before', 'old', 'previous', 'after', 'attributes', 'new', 'changes', 'values'];

    private const IDENTITY_KEYS = ['source_type', 'source_id', 'hs_event_id', 'assessable_type', 'assessable_id',
        'exposure_id', 'site_id', 'staff_id', 'user_id', 'client_id', 'shift_id', 'asset_id', 'event_category',
        'hazardous_substance_id', 'related_incident_id', 'service_context_id', 'respite_stay_id'];

    public function __construct(private readonly ChemicalExposureAccessService $access,
        private readonly ChemicalExposureSourceService $sources,
        private readonly ChemicalExposureSourceProjectionService $projection,
        private readonly ChemicalExposureHsEventDeliveryService $destinations) {}

    /** Unrelated auditors do not inherit the private branch's staff, Site or wellbeing prerequisites. */
    public function materialize(User $viewer, callable $consumer, ?HealthSafetyReadContext $context = null): mixed
    {
        $operation = function (HealthSafetyReadContext $active) use ($viewer, $consumer): mixed {
            $active->assertViewer($viewer);
            return $active->consumeSourceViewer(self::PERMISSIONS, function (User $current) use ($active, $consumer): mixed {
                abort_unless($current->canDo('audit.viewAny'), 403);
                $result = $consumer($current, $active);
                $active->assertActive();
                $this->detached($result);
                return $result;
            });
        };
        return $context === null ? HealthSafetyReadContext::within($viewer, self::PERMISSIONS, $operation) : $operation($context);
    }

    /**
     * Caller predicates (subject/date/action/module/actor/Client) must already be on this independent builder.
     * Name/body search is added only AFTER this guard. An omitted discovery candidate can never widen denial.
     */
    public function scope(Builder $query, ?User $viewer = null, ?HealthSafetyReadContext $context = null): Builder
    {
        if (! $query->getModel() instanceof AuditLog) {
            throw new LogicException('Exposure audit scope requires an independent audit query.');
        }
        $allowed = []; $knownPrivate = [];
        if ($context !== null) {
            if ($viewer === null) {
                throw new LogicException('A supplied audit callback requires its viewer.');
            }
            $context->assertViewer($viewer);
            $context->consumeSourceViewer(self::PERMISSIONS, function (User $current) use ($query, $context, &$allowed, &$knownPrivate): void {
                abort_unless($current->canDo('audit.viewAny'), 403);
                if (! $this->access->canRead($current)) {
                    return;
                }
                try {
                    $discovery = $this->access->withReadContext($context,
                        fn ($actor, $reads, $sites): array => $this->allowedBindings($query, $actor, $reads, $sites, $context));
                    $allowed = $discovery['bindings']; $knownPrivate = $discovery['private_ids'];
                } catch (HttpExceptionInterface $denial) {
                    if (! in_array($denial->getStatusCode(), [403, 404], true)) {
                        throw $denial;
                    }
                }
            });
        }
        $query->where(function (Builder $visible) use ($allowed, $knownPrivate): void {
            $visible->where(function (Builder $ordinary) use ($knownPrivate): void {
                $ordinary->whereNot(fn (Builder $private) => $this->privateIdentity($private));
                if ($knownPrivate !== []) { $ordinary->whereNotIn('audit_logs.id', $knownPrivate); }
            });
            if ($allowed !== []) {
                $visible->orWhere(function (Builder $private) use ($allowed): void {
                    foreach ($allowed as $binding) {
                        $private->orWhere(function (Builder $exact) use ($binding): void {
                            $this->correlateBinding($exact, $binding);
                        });
                    }
                });
            }
        });
        // Legacy wrappers never retain a private whitelist. Supplied builders cannot execute after callback exit.
        return $context === null ? $query : $context->query($query);
    }

    /** Informational only: presentation must consume currentForPresentation(), never this boolean as a token. */
    public function canPresent(AuditLog $log, ?User $viewer = null, ?HealthSafetyReadContext $context = null): bool
    {
        return $this->scope(AuditLog::query()->whereKey((int) $log->id), $viewer, $context)->exists();
    }

    /** Internal presentation handle: immediately consume it in the same materializing callback. */
    public function currentForPresentation(AuditLog $candidate, ?User $viewer = null,
        ?HealthSafetyReadContext $context = null): AuditLog
    {
        // Never certify an already hydrated model: a changed persisted identity/body must not resurrect stale prose.
        return $this->scope(AuditLog::query()->whereKey((int) $candidate->id), $viewer, $context)
            ->with(['user:id,name', 'client:id,first_name,last_name'])->firstOrFail();
    }
    /** Candidate bounds never replace the complete SQL private-identity denial. */
    private function identityQuery(Builder $candidate): Builder
    {
        $query = (clone $candidate)->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...));
        $query->where(fn (Builder $private) => $this->privateIdentity($private));
        $query->select(['audit_logs.id', 'audit_logs.action', 'audit_logs.auditable_type',
            'audit_logs.auditable_id', 'audit_logs.user_id', 'audit_logs.client_id']);
        foreach (self::META_LOCATIONS as $position => $location) {
            foreach (self::IDENTITY_KEYS as $key) {
                $path = '$.'.($location === '' ? '' : $location.'.').$key;
                $query->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(audit_logs.meta, ?)) as identity_{$position}_{$key}", [$path]);
                $query->selectRaw("JSON_TYPE(JSON_EXTRACT(audit_logs.meta, ?)) as identity_type_{$position}_{$key}", [$path]);
            }
        }
        return $query;
    }

    private function allowedBindings(Builder $candidate, User $current, CurrentAuthorizationReads $reads,
        array $sites, HealthSafetyReadContext $context): array
    {
        $bindings = []; $knownPrivate = [];
        $reads->query($this->identityQuery($candidate))->chunkById(200,
            function ($rows) use ($candidate, $current, $reads, $sites, $context, &$bindings, &$knownPrivate): void {
                foreach ($rows as $identity) {
                    $knownPrivate[] = (int) $identity->id;
                    try {
                        $admission = $this->admitted($identity, $current, $reads, $sites, $context);
                        if ($admission === false) { continue; }
                        $graph = $this->bindingGraph($identity, $admission, $current, $reads, $sites);
                        $fresh = $reads->query($this->identityQuery($candidate))->whereKey($identity->id)->first();
                        if ($fresh === null || $fresh->getRawOriginal() !== $identity->getRawOriginal()
                            || $this->admitted($fresh, $current, $reads, $sites, $context) !== $admission) {
                            continue;
                        }
                        $bindings[] = ['identity' => $identity->getRawOriginal(), ...$graph];
                    } catch (ModelNotFoundException) {
                        // A disappeared identity or retained origin never becomes ordinary.
                    } catch (HttpExceptionInterface $denial) {
                        if (! in_array($denial->getStatusCode(), [403, 404], true)) { throw $denial; }
                    } catch (\Illuminate\Validation\ValidationException $refusal) {
                        if (! ChemicalExposureSourceProjectionService::isCanonicalSourceRefusal($refusal)) { throw $refusal; }
                        $context->assertActive();
                    } catch (LogicException) {
                        // Semantic integrity failures withhold this row; lifetime is checked by the caller.
                        $context->assertActive();
                    }
                }
            }, 'audit_logs.id', 'id');
        return ['bindings'=>$bindings,'private_ids'=>array_values(array_unique($knownPrivate))];
    }

    private function admitted(AuditLog $identity, User $current, CurrentAuthorizationReads $reads,
        array $sites, HealthSafetyReadContext $context): array|false
    {
        $references = [];
        $provenEventIds = [];
        $directEventProofs = [];
        $subject = $this->modelForType((string) $identity->auditable_type);
        $id = (int) $identity->auditable_id;
        if ($subject === ChemicalExposureHsProjection::class) {
            $proof = $this->destinations->sourceProofForAuditSubject($id, $current, $reads, $sites);
            if ($proof === null || ! $this->readableProof($proof)) {
                return false;
            }
            $references[] = $proof;
        } elseif (in_array($subject, self::PRIVATE_MODELS, true) || $subject === ChemicalCommandIntent::class) {
            // Writer-owned helper must validate the exact retained child/version/receipt/recovery lineage.
            // No alternate digest/acceptance/staging proof is reproduced in this consumer.
            $proof = $this->sources->sourceProofForAuditSubject((string) $identity->auditable_type, $id, $current, $reads, $sites);
            if ($proof === null || ! $this->readableProof($proof)) {
                return false;
            }
            $references[] = $proof;
        } elseif (in_array($subject, [HsEvent::class, HsCorrectiveAction::class, HsInvestigation::class, HsRiskAssessment::class], true)) {
            $eventIds = $this->copiedEventIds($subject, $id, $reads);
            foreach ($eventIds as $eventId) {
                if ($this->knownExposureEvent($eventId, $reads)) {
                    $proof = $this->eventProof($eventId, $current, $reads, $sites, $context);
                    if ($proof === null) {
                        return false;
                    }
                    $provenEventIds[] = $eventId;
                    $directEventProofs[] = $proof;
                    $references[] = $proof;
                }
            }
        }
        foreach (self::META_LOCATIONS as $position => $location) {
            $tuple = [];
            foreach (self::IDENTITY_KEYS as $key) {
                $value = $identity->getAttribute("identity_{$position}_{$key}");
                if ($value !== null) {
                    // JSON null is retained known-null evidence; a missing path is SQL null.
                    $tuple[$key] = $value === 'null' ? null : $value;
                }
            }
            if (in_array($tuple['source_type'] ?? null, $this->types(SubstanceExposureRecord::class), true)) {
                $proof = $this->sources->sourceProof((int) ($tuple['source_id'] ?? 0), $current, $reads, $sites);
                if (! $this->readableProof($proof) || ! $this->tupleMatches($tuple, $proof, true)) {
                    return false;
                }
                $references[] = $proof;
            }
            if (isset($tuple['exposure_id'])) {
                $proof = $this->sources->sourceProof((int) $tuple['exposure_id'], $current, $reads, $sites);
                if (! $this->readableProof($proof) || (in_array($subject, self::PRIVATE_MODELS, true)
                    && ($references[0]['source_id'] ?? null) !== $proof['source_id'])) {
                    return false;
                }
                $references[] = $proof;
            }
            // Historical private root audit Site/worker bindings cannot be replaced by today's placement.
            if ($subject === SubstanceExposureRecord::class && $references !== []
                && ! $this->tupleMatches($tuple, $references[0], false)) {
                return false;
            }
            $events = [];
            if (isset($tuple['hs_event_id'])) {
                $events[] = (int) $tuple['hs_event_id'];
            }
            if (in_array($tuple['assessable_type'] ?? null, $this->types(HsEvent::class), true)) {
                $events[] = (int) ($tuple['assessable_id'] ?? 0);
            }
            foreach (array_unique($events) as $eventId) {
                if ($this->knownExposureEvent($eventId, $reads)) {
                    $proof = $this->eventProof($eventId, $current, $reads, $sites, $context);
                    if ($proof === null || ! $this->tupleMatches($tuple, $proof, true)) {
                        return false;
                    }
                    $provenEventIds[] = $eventId;
                    $references[] = $proof;
                }
            }
            // A copied child can carry Site/worker/context IDs without repeating literal source identity.
            // Use its explicit historical event reference when present, otherwise the proved current parent.
            if ($events === []) {
                foreach ($directEventProofs as $proof) {
                    if (! $this->tupleMatches($tuple, $proof, true)) {
                        return false;
                    }
                }
            }
        }
        // Independently copied Client relation is not original exposure association evidence.
        if ($references !== [] && $identity->client_id !== null) {
            $client = $reads->query(Client::query())->whereKey((int) $identity->client_id)->first(['id', 'site_id']);
            if ($client === null || ! app(ClientPolicy::class)->viewFromCurrentEvidence($current, $client, $reads)) {
                return false;
            }
        }
        if ($references === []) { return false; }
        $proofs = [];
        foreach ($references as $proof) { $proofs[(int) $proof['source_id']] = $proof; }
        ksort($proofs);
        $provenEventIds = array_values(array_unique($provenEventIds)); sort($provenEventIds);
        return ['proofs' => $proofs, 'event_ids' => $provenEventIds];
    }


    /** Private graph stability only; canonical proof is required both before and after capture. */
    private function bindingGraph(AuditLog $identity, array $admission, User $current,
        CurrentAuthorizationReads $reads, array $sites): array
    {
        $guards = []; $sets = []; $subjectSets = [];
        $rememberSubject = function (string $kind, int $id, int $rootId) use ($reads, &$subjectSets): void {
            if (! in_array($kind, ['exposure', 'review', 'followup', 'evidence'], true) || $id < 1) {
                throw new LogicException('Exposure audit retained identity kind is unconfirmed.');
            }
            $set = ['kind' => $kind, 'source_id' => $id, 'root_id' => $rootId];
            foreach (['versions' => ChemicalExposureSourceVersion::class, 'receipts' => ChemicalExposureCommandReceipt::class] as $key => $model) {
                // Exactly the canonical SourceService ANY kind/ID ownership predicate, identity-only.
                $identities = $reads->query($model::query())->where('source_type', $kind)->where('source_id', $id)
                    ->get(['id', 'exposure_id']);
                foreach ($identities as $identity) {
                    if ((int) $identity->exposure_id !== $rootId) {
                        throw new LogicException('Exposure audit subject retains another original aggregate.');
                    }
                }
                $set[$key] = $identities->pluck('id')->map(fn ($value): int => (int) $value)->all();
            }
            $key = $kind.'|'.$id;
            if (isset($subjectSets[$key]) && $subjectSets[$key] !== $set) {
                throw new LogicException('Exposure audit retained ownership changed during capture.');
            }
            $subjectSets[$key] = $set;
        };
        $remember = function (Model $row, ?array $columns = null) use ($reads, &$guards): void {
            $family = $this->graphFamily($row::class);
            $columns ??= array_keys($row->getRawOriginal());
            $query = $row->newQuery();
            if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($row), true)) { $query->withTrashed(); }
            $this->exactRaw($query, array_intersect_key($row->getRawOriginal(), array_flip($columns)), $row->getTable());
            $hash = $reads->query($query)->select(['id'])
                ->selectRaw($this->fingerprintSql($columns, $row->getTable()).' AS audit_binding_fingerprint')
                ->firstOrFail()->getRawOriginal('audit_binding_fingerprint');
            if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new LogicException('Exposure audit source stability is unconfirmed.');
            }
            $key = $family.'|'.$row->id.'|'.implode(',', $columns);
            $guard = ['family' => $family, 'id' => (int) $row->id, 'columns' => $columns, 'fingerprint' => $hash];
            if (isset($guards[$key]) && $guards[$key] !== $guard) { throw new LogicException('Exposure audit evidence changed during capture.'); }
            $guards[$key] = $guard;
        };
        $rememberAssignment = function (int $clientId) use ($current,$reads,&$guards): void {
            $row = $reads->query(\Illuminate\Support\Facades\DB::table('client_user'))
                ->where('client_id',$clientId)->where('user_id',$current->id)->first(['id','client_id','user_id']);
            if ($row === null) { throw new LogicException('Current assigned Client evidence changed.'); }
            $fingerprint = $reads->query(\Illuminate\Support\Facades\DB::table('client_user'))->where('id',$row->id)
                ->where('client_id',$clientId)->where('user_id',$current->id)
                ->selectRaw($this->fingerprintSql(['id','client_id','user_id'],'client_user').' AS audit_binding_fingerprint')->value('audit_binding_fingerprint');
            if (! is_string($fingerprint) || ! preg_match('/^[a-f0-9]{64}$/D',$fingerprint)) { throw new LogicException('Assigned Client proof is unavailable.'); }
            $guards['assignment|'.$row->id] = ['family'=>'assignment','id'=>(int)$row->id,
                'columns'=>['id','client_id','user_id'],'fingerprint'=>$fingerprint];
        };
        $rememberContext = function (?array $tuple) use ($current,$reads,$remember,$rememberAssignment): void {
            if ($tuple === null) { return; } // Canonically admitted genuine absence is not today's association.
            $remember($reads->query(\App\Models\Site::query())->whereKey($tuple['site_id'])->firstOrFail(['id','is_active','archived','archived_at']));
            $original = $tuple['original_context'];
            if ($original === null) { return; }
            $models = ['incident_id' => \App\Models\ClientIncident::class, 'client_id' => Client::class,
                'shift_id' => \App\Models\Shift::class, 'service_context_id' => \App\Models\ServiceContext::class,
                'stay_id' => \App\Models\RespiteStay::class];
            $columns = ['incident_id' => ['id','client_id','site_id','shift_id','service_context_id','respite_stay_id','is_hr_confidential'],
                'client_id' => ['id','site_id','deleted_at'], 'shift_id' => ['id','client_id','site_id'],
                'service_context_id' => ['id','site_id','is_active'], 'stay_id' => ['id','client_id','booking_id','deleted_at']];
            foreach ($models as $key => $model) {
                if ($original[$key] === null) { continue; }
                $query = $model::query();
                $row = $reads->query($query)->whereKey($original[$key])->firstOrFail($columns[$key]);
                $remember($row);
                if ($key === 'client_id' && (! $current->canDo('incidents.viewAny') || ! $current->canDo('clients.viewAny'))) { $rememberAssignment((int)$row->id); }
                if ($key === 'stay_id' && $row->booking_id !== null) {
                    $remember($reads->query(\App\Models\RespiteBooking::query())->whereKey($row->booking_id)
                        ->firstOrFail(['id','client_id','location_id','deleted_at']));
                }
            }
        };
        foreach ($admission['proofs'] as $rootId => $proof) {
            $root = $this->access->assertExposure((int) $rootId, $current, $reads, $sites);
            $remember($root);
            $rememberSubject('exposure', (int) $root->id, (int) $root->id);
            $rememberContext(['site_id' => (int) $root->site_id, 'original_context' => $root->original_context]);
            $remember($reads->query(User::query())->whereKey($root->user_id)->firstOrFail(['id']));
            $remember($reads->query(\App\Models\HazardousSubstance::query())->whereKey($root->hazardous_substance_id)->firstOrFail(['id','deleted_at']));
            $versionIds = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $rootId)->pluck('id')->all();
            $receiptIds = $reads->query(ChemicalExposureCommandReceipt::query())->where('exposure_id', $rootId)->pluck('id')->all();
            foreach ($receiptIds as $receiptId) {
                // Historical context is admitted before UUID, actor, immutable version or audit body.
                $tuple = $this->sources->admitReceiptMetadata((int) $receiptId, $current, $reads, $sites);
                $rememberContext($tuple['before']); $rememberContext($tuple['after']);
                $receipt = $reads->query(ChemicalExposureCommandReceipt::query())->where('exposure_id', $rootId)->whereKey($receiptId)->firstOrFail();
                $remember($receipt);
                $remember($reads->query(ChemicalCommandIntent::query())->where('source_type','exposure')->whereKey($receipt->intent_id)->firstOrFail());
                $acceptedAudit = $reads->query(AuditLog::query())->whereKey($receipt->audit_log_id)
                    ->firstOrFail(['id','user_id','client_id','action','auditable_type','auditable_id']);
                $remember($acceptedAudit, ['id','user_id','client_id','action','auditable_type','auditable_id','meta']);
            }
            foreach ($versionIds as $versionId) {
                $metadata = $this->sources->admitVersionMetadata((int) $versionId, $current, $reads, $sites);
                $version = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $rootId)->whereKey($versionId)->firstOrFail();
                $this->sources->assertAdmittedMetadata($version, $metadata);
                $remember($version);
            }
            $markerIds = $reads->query(ChemicalExposureHsProjection::query())->where('exposure_id', $rootId)->pluck('id')->all();
            foreach ($markerIds as $markerId) {
                $marker = $reads->query(ChemicalExposureHsProjection::query())->where('exposure_id', $rootId)->whereKey($markerId)->firstOrFail();
                $remember($marker);
            }
            $sets[] = ['root_id' => (int) $rootId, 'versions' => array_map('intval',$versionIds),
                'receipts' => array_map('intval',$receiptIds), 'markers' => array_map('intval',$markerIds)];
        }
        foreach ($admission['event_ids'] as $eventId) {
            $remember($reads->query(HsEvent::withTrashed())->whereKey($eventId)->firstOrFail(
                ['id','source_type','source_id','event_category','staff_id','site_id','client_id','shift_id','asset_id',
                    'occurred_at','reported_at','idempotency_key','deleted_at']));
        }
        $subject = $this->modelForType((string) $identity->auditable_type);
        if ($subject !== null && ! in_array($subject, [AuditLog::class, SubstanceExposureRecord::class, HsEvent::class], true)) {
            $query = $subject::query();
            if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($subject), true)) { $query->withTrashed(); }
            $query->whereKey((int) $identity->auditable_id);
            if (in_array($subject, [HsCorrectiveAction::class, HsInvestigation::class, HsRiskAssessment::class], true)) {
                $columns = $subject === HsRiskAssessment::class
                    ? ['id','hs_event_id','assessable_type','assessable_id','deleted_at'] : ['id','hs_event_id','deleted_at'];
                $row = $reads->query($query)->firstOrFail($columns);
            } else {
                if ($subject !== ChemicalCommandIntent::class) { $query->whereIn('exposure_id',array_keys($admission['proofs'])); }
                if (in_array($subject,[ChemicalExposureReview::class,ChemicalExposureFollowup::class,ChemicalExposureEvidence::class],true)) {
                    $childIdentity = $reads->query(clone $query)->firstOrFail(['id','exposure_id','source_schema_version','source_revision']);
                    $parent = $this->access->assertExposure((int)$childIdentity->exposure_id,$current,$reads,$sites);
                    $kind = $subject === ChemicalExposureReview::class ? 'review' : ($subject === ChemicalExposureFollowup::class ? 'followup' : 'evidence');
                    $this->sources->assertSourceIdentityBeforeBody($parent,$childIdentity,$kind,$reads,$current,$sites);
                    $rememberSubject($kind, (int) $childIdentity->id, (int) $parent->id);
                    $query->where('source_schema_version',$childIdentity->source_schema_version)->where('source_revision',$childIdentity->source_revision);
                }
                $row = $reads->query($query)->firstOrFail();
            }
            $remember($row);
            if (in_array($subject, [ChemicalExposureSourceVersion::class, ChemicalExposureCommandReceipt::class], true)
                && in_array($row->source_type, ['exposure', 'review', 'followup', 'evidence'], true)) {
                $rememberSubject($row->source_type, (int) $row->source_id, (int) $row->exposure_id);
            }
        }
        if ($identity->client_id !== null) {
            $remember($reads->query(Client::query())->whereKey((int) $identity->client_id)->firstOrFail(['id','site_id','deleted_at']));
            if (! $current->canDo('clients.viewAny')) { $rememberAssignment((int)$identity->client_id); }
        }
        // Prepared current actor/profile snapshots remain the authority evidence, not a later reloaded profile.
        $remember($current, ['id','role','approved_at','external_clinical_account']);
        if ($current->hrEmployeeProfile !== null) { $remember($current->hrEmployeeProfile,
            ['id','user_id','primary_site_id','secondary_site_ids','is_active','start_date','end_date','deleted_at']); }
        // No full audit model is selected here. Hashing follows exact admitted identity predicates.
        $auditQuery = AuditLog::query();
        $this->exactIdentity($auditQuery, $identity->getRawOriginal());
        $auditHash = $reads->query($auditQuery)->select(['id'])
            ->selectRaw($this->fingerprintSql(['id','user_id','client_id','action','auditable_type','auditable_id','meta','created_at'], 'audit_logs').' AS audit_binding_fingerprint')
            ->firstOrFail()->getRawOriginal('audit_binding_fingerprint');
        if (! is_string($auditHash) || ! preg_match('/^[a-f0-9]{64}$/D', $auditHash)) { throw new LogicException('Audit row stability is unconfirmed.'); }
        $guards['audit|'.$identity->id] = ['family'=>'audit','id'=>(int)$identity->id,
            'columns'=>['id','user_id','client_id','action','auditable_type','auditable_id','meta','created_at'],'fingerprint'=>$auditHash];
        return ['guards'=>array_values($guards),'sets'=>$sets,'subject_sets'=>array_values($subjectSets)];
    }

    private function correlateBinding(Builder $query, array $binding): void
    {
        $this->exactIdentity($query, $binding['identity']);
        foreach ($binding['guards'] as $guard) {
            $table = $this->graphTable($guard['family']); $alias = 'exposure_audit_guard_'.$guard['family'];
            $query->whereExists(fn (QueryBuilder $fresh) => $fresh->selectRaw('1')->from($table.' as '.$alias)
                ->where($alias.'.id', $guard['id'])
                ->whereRaw($this->fingerprintSql($guard['columns'], $alias).' = ?', [$guard['fingerprint']]));
        }
        foreach ($binding['sets'] as $set) {
            foreach (['versions'=>'chemical_exposure_source_versions','receipts'=>'chemical_exposure_command_receipts',
                'markers'=>'chemical_exposure_hs_projections'] as $key=>$table) {
                $query->whereNotExists(function (QueryBuilder $novel) use ($set,$key,$table): void {
                    $novel->selectRaw('1')->from($table)->where('exposure_id',$set['root_id']);
                    if ($set[$key] !== []) { $novel->whereNotIn('id',$set[$key]); }
                });
            }
        }
        foreach ($binding['subject_sets'] as $set) {
            foreach (['versions' => 'chemical_exposure_source_versions', 'receipts' => 'chemical_exposure_command_receipts'] as $key => $table) {
                $query->whereNotExists(function (QueryBuilder $novel) use ($set, $key, $table): void {
                    $novel->selectRaw('1')->from($table)->where('source_type', $set['kind'])->where('source_id', $set['source_id'])
                        ->where(function (QueryBuilder $changed) use ($set, $key): void {
                            $changed->where('exposure_id', '!=', $set['root_id']);
                            if ($set[$key] !== []) { $changed->orWhereNotIn('id', $set[$key]); }
                            else { $changed->orWhereRaw('1 = 1'); }
                        });
                });
            }
        }
    }

    private function exactIdentity(Builder $query, array $identity): void
    {
        $facts = array_intersect_key($identity, array_flip(['id','action','auditable_type','auditable_id','user_id','client_id']));
        $this->exactRaw($query, $facts, 'audit_logs');
        foreach (self::META_LOCATIONS as $position=>$location) {
            foreach (self::IDENTITY_KEYS as $key) {
                $path = '$.'.($location === '' ? '' : $location.'.').$key;
                $query->whereRaw('JSON_TYPE(JSON_EXTRACT(audit_logs.meta, ?)) <=> ?', [$path,$identity["identity_type_{$position}_{$key}"]])
                    ->whereRaw('BINARY JSON_UNQUOTE(JSON_EXTRACT(audit_logs.meta, ?)) <=> BINARY ?', [$path,$identity["identity_{$position}_{$key}"]]);
            }
        }
    }

    private function exactRaw(Builder $query, array $facts, string $table): void
    {
        foreach ($facts as $column=>$value) {
            $field = $query->getQuery()->getGrammar()->wrap($table.'.'.$column);
            if ($value === null) { $query->whereNull($table.'.'.$column); }
            elseif (is_string($value)) { $query->whereRaw('BINARY CAST('.$field.' AS CHAR) = BINARY ?', [$value]); }
            elseif (is_scalar($value)) { $query->where($table.'.'.$column,$value); }
            else { throw new LogicException('Audit raw source facts are not persisted scalars.'); }
        }
    }

    private function fingerprintSql(array $columns, string $alias): string
    {
        if ($columns === [] || ! preg_match('/^[a-z][a-z0-9_]*$/D',$alias)) { throw new LogicException('Invalid audit graph expression.'); }
        $parts=[];
        foreach ($columns as $column) {
            if (! is_string($column) || ! preg_match('/^[a-z][a-z0-9_]*$/D',$column)) { throw new LogicException('Invalid audit graph column.'); }
            $parts[] = "'".$column."', ".chr(96).$alias.chr(96).'.'.chr(96).$column.chr(96);
        }
        return 'SHA2(CAST(JSON_OBJECT('.implode(', ',$parts).') AS CHAR CHARACTER SET utf8mb4),256)';
    }

    /** Actual admitted source family only; no request-defined table/model may enter final SQL. */
    private function graphFamily(string $model): string
    {
        return match ($model) {
            SubstanceExposureRecord::class=>'root', ChemicalExposureSourceVersion::class=>'version',
            ChemicalExposureCommandReceipt::class=>'receipt', ChemicalCommandIntent::class=>'intent',
            AuditLog::class=>'audit', ChemicalExposureHsProjection::class=>'marker', HsEvent::class=>'event',
            ChemicalExposureReview::class=>'review', ChemicalExposureFollowup::class=>'followup',
            ChemicalExposureEvidence::class=>'evidence', ChemicalExposureEvidenceStaging::class=>'staging',
            ChemicalExposureEvidenceCleanup::class=>'cleanup', ChemicalExposureSourceDelivery::class=>'delivery',
            HsCorrectiveAction::class=>'action', HsInvestigation::class=>'investigation', HsRiskAssessment::class=>'risk',
            \App\Models\Site::class=>'site', User::class=>'user', Client::class=>'client',
            \App\Models\ClientIncident::class=>'incident', \App\Models\Shift::class=>'shift',
            \App\Models\ServiceContext::class=>'context', \App\Models\RespiteStay::class=>'stay',
            \App\Models\RespiteBooking::class=>'booking', \App\Models\HazardousSubstance::class=>'substance',
            \App\Domain\Hr\Models\HrEmployeeProfile::class=>'profile',
            default=>throw new LogicException('Unknown exposure audit source graph.'),
        };
    }

    private function graphTable(string $family): string
    {
        return match ($family) {
            'root'=>'substance_exposure_records','version'=>'chemical_exposure_source_versions',
            'receipt'=>'chemical_exposure_command_receipts','intent'=>'chemical_command_intents','audit'=>'audit_logs',
            'marker'=>'chemical_exposure_hs_projections','event'=>'hs_events','review'=>'chemical_exposure_reviews',
            'followup'=>'chemical_exposure_followups','evidence'=>'chemical_exposure_evidence',
            'staging'=>'chemical_exposure_evidence_staging','cleanup'=>'chemical_exposure_evidence_cleanups',
            'delivery'=>'chemical_exposure_source_deliveries','action'=>'hs_corrective_actions',
            'investigation'=>'hs_investigations','risk'=>'hs_risk_assessments','site'=>'sites','user'=>'users',
            'assignment'=>'client_user','client'=>'clients','incident'=>'client_incidents','shift'=>'shifts','context'=>'service_contexts',
            'stay'=>'respite_stays','booking'=>'respite_bookings','substance'=>'hazardous_substances','profile'=>'hr_employee_profiles',
            default=>throw new LogicException('Unknown exposure audit graph table.'),
        };
    }

    private function readableProof(array $proof): bool
    {
        return ($proof['attribution_state'] ?? null) === 'recorded' && ($proof['context_binding'] ?? null) === 'recorded'
            || ($proof['attribution_state'] ?? null) === 'legacy_unknown' && ($proof['incident_binding'] ?? null) === 'absent';
    }

    private function tupleMatches(array $tuple, array $proof, bool $copied): bool
    {
        $bindings = ['site_id' => 'site_id', $copied ? 'staff_id' : 'user_id' => 'worker_id',
            'client_id' => 'client_id', 'shift_id' => 'shift_id', 'asset_id' => 'asset_id',
            'hazardous_substance_id' => 'substance_id', 'related_incident_id' => 'incident_id',
            'service_context_id' => 'service_context_id', 'respite_stay_id' => 'stay_id'];
        foreach ($bindings as $key => $proofKey) {
            if (array_key_exists($key, $tuple) && ($tuple[$key] === null ? null : (int) $tuple[$key]) !== ($proof[$proofKey] ?? null)) {
                return false;
            }
        }
        return ! array_key_exists('event_category', $tuple) || $tuple['event_category'] === HsEvent::CATEGORY_EXPOSURE;
    }

    private function eventProof(int $id, User $current, CurrentAuthorizationReads $reads, array $sites,
        HealthSafetyReadContext $context): ?array
    {
        if ($this->projection->readableHsEventIdsInContext($current, HsEvent::query()->whereKey($id), $context) !== [$id]) {
            return null;
        }
        $event = $reads->query(HsEvent::query())->whereKey($id)->first(['id', 'source_id']);
        if ($event === null) {
            return null;
        }
        $proof = $this->sources->sourceProof((int) $event->source_id, $current, $reads, $sites);
        return $this->readableProof($proof) ? $proof : null;
    }

    private function knownExposureEvent(int $id, CurrentAuthorizationReads $reads): bool
    {
        return $id > 0 && $reads->query($this->exposureEvents())->whereKey($id)->exists();
    }

    private function copiedEventIds(string $model, int $id, CurrentAuthorizationReads $reads): array
    {
        if ($id <= 0) {
            return [];
        }
        if ($model === HsEvent::class) {
            return [$id];
        }
        $row = $reads->query($model::withTrashed())->whereKey($id)->first($model === HsRiskAssessment::class
            ? ['id', 'hs_event_id', 'assessable_type', 'assessable_id'] : ['id', 'hs_event_id']);
        if ($row === null) {
            return [];
        }
        $ids = $row->hs_event_id === null ? [] : [(int) $row->hs_event_id];
        if ($model === HsRiskAssessment::class && in_array($row->assessable_type, $this->types(HsEvent::class), true)) {
            $ids[] = (int) $row->assessable_id;
        }
        return array_values(array_unique($ids));
    }

    /** SQL identity-only denial remains complete; candidate bounds affect materialized proof, never this predicate. */
    private function privateIdentity(Builder $query): void
    {
        $privateTypes = [];
        $prefixes = ['chemical.exposure'];
        foreach (self::PRIVATE_MODELS as $model) {
            $privateTypes = [...$privateTypes, ...$this->types($model)];
            $prefixes[] = strtolower(class_basename($model));
        }
        // Every nullable leaf is explicitly guarded: NOT(unknown) must not hide unrelated audit rows.
        $query->whereRaw('1 = 0')->orWhere(function (Builder $actions) use ($prefixes): void {
            $actions->whereNotNull('audit_logs.action')->where(function (Builder $matching) use ($prefixes): void {
                $matching->whereRaw('1 = 0');
                foreach ($prefixes as $prefix) {
                    $matching->orWhere('audit_logs.action', $prefix)->orWhere('audit_logs.action', 'like', $prefix.'.%');
                }
            });
        });
        $query->orWhere(fn (Builder $subjects) => $subjects->whereNotNull('audit_logs.auditable_type')
            ->whereIn('audit_logs.auditable_type', array_values(array_unique($privateTypes))));
        $query->orWhere(fn (Builder $intent) => $intent->whereNotNull('audit_logs.auditable_type')
            ->whereNotNull('audit_logs.auditable_id')->whereIn('audit_logs.auditable_type', $this->types(ChemicalCommandIntent::class))
            ->whereIn('audit_logs.auditable_id', ChemicalCommandIntent::query()->where('source_type', 'exposure')->select('id')));
        $query->orWhere(fn (Builder $events) => $events->whereNotNull('audit_logs.auditable_type')
            ->whereNotNull('audit_logs.auditable_id')->whereIn('audit_logs.auditable_type', $this->types(HsEvent::class))
            ->whereIn('audit_logs.auditable_id', $this->exposureEvents()->select('id')));
        foreach ([HsCorrectiveAction::class, HsInvestigation::class, HsRiskAssessment::class] as $child) {
            $query->orWhere(fn (Builder $copied) => $copied->whereNotNull('audit_logs.auditable_type')
                ->whereNotNull('audit_logs.auditable_id')->whereIn('audit_logs.auditable_type', $this->types($child))
                ->whereIn('audit_logs.auditable_id', $child::withTrashed()->where(function (Builder $parent) use ($child): void {
                    $parent->whereIn('hs_event_id', $this->exposureEvents()->select('id'));
                    if ($child === HsRiskAssessment::class) {
                        $parent->orWhere(fn (Builder $legacy) => $legacy->whereIn('assessable_type', $this->types(HsEvent::class))
                            ->whereIn('assessable_id', $this->exposureEvents()->select('id')));
                    }
                })->select('id')));
        }
        foreach (self::META_LOCATIONS as $location) {
            $prefix = 'meta->'.($location === '' ? '' : $location.'->');
            $query->orWhereNotNull($prefix.'exposure_id')
                ->orWhere(fn (Builder $source) => $source->whereNotNull($prefix.'source_type')
                    ->whereIn($prefix.'source_type', $this->types(SubstanceExposureRecord::class)))
                ->orWhere(fn (Builder $event) => $event->whereNotNull($prefix.'hs_event_id')
                    ->whereIn($prefix.'hs_event_id', $this->exposureEvents()->select('id')))
                ->orWhere(fn (Builder $risk) => $risk->whereNotNull($prefix.'assessable_type')->whereNotNull($prefix.'assessable_id')
                    ->whereIn($prefix.'assessable_type', $this->types(HsEvent::class))
                    ->whereIn($prefix.'assessable_id', $this->exposureEvents()->select('id')));
        }
    }
    private function exposureEvents(): Builder
    {
        return HsEvent::withTrashed()->where(function (Builder $family): void {
            $family->whereIn('source_type',$this->types(SubstanceExposureRecord::class))
                ->orWhereIn('id',ChemicalExposureHsProjection::query()->select('hs_event_id'));
        });
    }

    private function modelForType(string $type): ?string
    {
        foreach ([...self::PRIVATE_MODELS, ChemicalCommandIntent::class, HsEvent::class,
            HsCorrectiveAction::class, HsInvestigation::class, HsRiskAssessment::class] as $model) {
            if (in_array($type, $this->types($model), true)) {
                return $model;
            }
        }
        return null;
    }

    private function detached(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $part) {
                $this->detached($part);
            }
        } elseif ($value !== null && ! is_scalar($value)) {
            throw new LogicException('Audit reads may return detached scalar projections only.');
        }
    }
    private function types(string $model): array
    {
        return array_values(array_unique([$model, (new $model)->getMorphClass()]));
    }
}

