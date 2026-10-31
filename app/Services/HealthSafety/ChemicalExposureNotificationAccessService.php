<?php

namespace App\Services\HealthSafety;

use App\Domain\Governance\Services\BoardPackAccessService;
use App\Models\User;
use App\Notifications\HealthSafety\ChemicalExposureFollowupAssignedNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Unmounted shared-inbox candidate; named source work is never completed by notification reading. */
final class ChemicalExposureNotificationAccessService
{
    // Existing transitive inbox policies consume these flags; preparation does not grant any of them.
    public const PERMISSIONS = [...ChemicalExposureCommandAccess::PERMISSIONS,
        'governance.packs.view', 'governance.packs.manage', 'governance.executive.view',
        'governance.meetings.view', 'governance.resolutions.view', 'governance.documents.view',
        'medications.view', 'medications.administer.record', 'medications.controlled.record',
        'medications.controlled.view', 'medications.audit.view', 'medications.breakglass',
        'medications.stock.update', 'medications.reports.export', 'medications.reports.view'];

    public function __construct(private readonly ChemicalExposureFollowupDestinationService $destination) {}

    public function materialize(User $viewer, callable $consumer, ?HealthSafetyReadContext $context = null): mixed
    {
        $operation = function (HealthSafetyReadContext $active) use ($viewer, $consumer): mixed {
            $active->assertViewer($viewer);
            return $active->consumeSourceViewer(self::PERMISSIONS, function ($current) use ($consumer, $active): mixed {
                $result = $consumer($current, $active);
                $active->assertActive();
                $this->detached($result);
                return $result;
            });
        };
        return $context === null ? HealthSafetyReadContext::within($viewer, self::PERMISSIONS, $operation) : $operation($context);
    }

    /** Keep the canonical relation and all BoardPack/medication predicates; only exposure adds this intersection. */
    public function scope(MorphMany $query, User $viewer, ?HealthSafetyReadContext $context = null): MorphMany
    {
        if (! ($query->getRelated() instanceof DatabaseNotification)) {
            throw new LogicException('Private notification scope requires the actual database inbox relation.');
        }
        $allowed = [];
        $privateIdentities = [];
        if ($context !== null) {
            $context->assertViewer($viewer);
            // Reject a foreign physical relation before any private destination discovery.
            $context->query($query->getQuery());
            $context->consumeSourceViewer(self::PERMISSIONS, function ($current) use ($context, $query, &$allowed, &$privateIdentities): void {
                // Identity only: a discovered private row cannot later fall through as ordinary
                // if its type and markers are replaced before the consuming SQL executes.
                $identities = (clone $query->getQuery())->setEagerLoads([])
                    ->where('notifications.notifiable_type', $current->getMorphClass())
                    ->where('notifications.notifiable_id', $current->id)
                    ->where(function (Builder $private): void {
                        $private->where('notifications.type', ChemicalExposureFollowupAssignedNotification::class)
                            ->orWhere('notifications.data->kind', 'private_followup')
                            ->orWhere('notifications.data->event_key', ChemicalExposureFollowupDestinationService::EVENT_KEY);
                    });
                $privateIdentities = $identities->select(['notifications.id'])->pluck('notifications.id')->all();
                if (! $current->canDo('hazards.view') || ! $current->canDo('hr.wellbeing.view')) { return; }
                try {
                    foreach ($this->destination->visibleNotificationIdsInContext($current, $context) as $id) {
                        try {
                            // Separate B-owned UNAPPLIED seam: admission proves the original
                            // source/acceptance before returning detached final-SQL binding facts.
                            $allowed[$id] = $this->destination->notificationAdmissionInContext($current, $id, $context);
                        } catch (LogicException|ModelNotFoundException) { continue; }
                        catch (\Illuminate\Validation\ValidationException $denial) {
                            if (array_keys($denial->errors()) !== ['source']) { throw $denial; }
                        }
                        catch (HttpExceptionInterface $denial) {
                            if (! in_array($denial->getStatusCode(), [403, 404], true)) { throw $denial; }
                        }
                    }
                } catch (HttpExceptionInterface $denial) {
                    if (! in_array($denial->getStatusCode(), [403, 404], true)) { throw $denial; }
                    // Private staff/Site denial must not deny otherwise valid unrelated inbox work.
                }
            });
        }
        $query->where('notifications.notifiable_type', $viewer->getMorphClass())
            ->where('notifications.notifiable_id', $viewer->id)
            ->where(function (Builder $visible) use ($allowed, $privateIdentities, $viewer): void {
                $visible->where(function (Builder $ordinary) use ($privateIdentities, $allowed): void {
                    $knownPrivate = array_values(array_unique([...$privateIdentities, ...array_keys($allowed)]));
                    if ($knownPrivate !== []) { $ordinary->whereNotIn('notifications.id', $knownPrivate); }
                    $ordinary->where(fn (Builder $type) => $type->whereNull('notifications.type')
                        ->orWhere('notifications.type', '!=', ChemicalExposureFollowupAssignedNotification::class))
                        ->where(fn (Builder $kind) => $kind->whereNull('notifications.data->kind')
                            ->orWhere('notifications.data->kind', '!=', 'private_followup'))
                        ->where(fn (Builder $event) => $event->whereNull('notifications.data->event_key')
                            ->orWhere('notifications.data->event_key', '!=', ChemicalExposureFollowupDestinationService::EVENT_KEY));
                });
                if ($allowed !== []) {
                    $visible->orWhere(function (Builder $private) use ($allowed, $viewer): void {
                        $private->whereRaw('BINARY notifications.type = ?', [ChemicalExposureFollowupAssignedNotification::class])
                            ->whereRaw('BINARY notifications.notifiable_type = ?', [$viewer->getMorphClass()])
                            ->where('notifications.notifiable_id', $viewer->id)
                            ->whereNotNull('notifications.created_at')->whereNotNull('notifications.updated_at')
                            ->whereRaw("JSON_TYPE(notifications.data) = 'OBJECT' AND JSON_LENGTH(notifications.data) = 5")
                            ->whereRaw("JSON_TYPE(JSON_EXTRACT(notifications.data, '$.kind')) = 'STRING'")
                            ->whereRaw("BINARY JSON_UNQUOTE(JSON_EXTRACT(notifications.data, '$.kind')) = ?", ['private_followup'])
                            ->whereRaw("JSON_TYPE(JSON_EXTRACT(notifications.data, '$.title')) = 'STRING'")
                            ->whereRaw("BINARY JSON_UNQUOTE(JSON_EXTRACT(notifications.data, '$.title')) = ?", ['Assigned follow-up'])
                            ->whereRaw("JSON_TYPE(JSON_EXTRACT(notifications.data, '$.body')) = 'NULL'")
                            ->whereRaw("JSON_TYPE(JSON_EXTRACT(notifications.data, '$.url')) = 'NULL'")
                            ->whereRaw("JSON_TYPE(JSON_EXTRACT(notifications.data, '$.delivery_id')) = 'INTEGER'")
                            ->where(function (Builder $bindings) use ($allowed): void {
                                foreach ($allowed as $id => $admission) {
                                    $bindings->orWhere(function (Builder $binding) use ($id, $admission): void {
                                        $binding->whereRaw('BINARY notifications.id = ?', [$id])
                                            ->whereRaw("JSON_EXTRACT(notifications.data, '$.delivery_id') = CAST(? AS JSON)",
                                                [json_encode($admission['delivery_id'], JSON_THROW_ON_ERROR)]);
                                        $this->correlateAdmission($binding, $admission);
                                    });
                                }
                            });
                    });
                }
            });
        // No default private whitelist. Supplied relations cannot execute after the current callback expires.
        return $query;
    }

    /** Every guard executes in the SAME final count/body/update statement, on the bound physical connection. */
    private function correlateAdmission(Builder $query, array $admission): void
    {
        $membership = $admission['membership'];
        // Exact historical reported-origin membership was admitted metadata-first by the
        // descriptor. A newly inserted origin or later aggregate cannot join that proof.
        $query->whereNotExists(function (\Illuminate\Database\Query\Builder $reported) use ($admission, $membership): void {
            $reported->selectRaw('1')->from('chemical_exposure_source_versions as notification_reported')
                ->where('notification_reported.exposure_id', $membership['exposure_id'])
                ->whereJsonContainsKey('notification_reported.content->facts->reported_facts');
            if ($admission['reported_version_ids'] !== []) {
                $reported->whereNotIn('notification_reported.id', $admission['reported_version_ids']);
            }
        })->whereNotExists(fn (\Illuminate\Database\Query\Builder $later) => $later->selectRaw('1')
            ->from('chemical_exposure_source_versions as notification_later')
            ->where('notification_later.exposure_id', $membership['exposure_id'])
            ->where('notification_later.aggregate_revision', '>', $membership['source_revision']));
        $query->whereExists(function (\Illuminate\Database\Query\Builder $source) use ($admission, $membership): void {
            $source->selectRaw('1')->from('substance_exposure_records as notification_source')
                ->join('chemical_exposure_source_deliveries as notification_delivery', 'notification_delivery.exposure_id', '=', 'notification_source.id')
                ->join('chemical_exposure_source_versions as notification_version', 'notification_version.id', '=', 'notification_delivery.source_version_id')
                ->join('chemical_exposure_followups as notification_followup', 'notification_followup.id', '=', 'notification_version.source_id')
                ->where('notification_source.id', $membership['exposure_id'])
                ->where('notification_source.hazardous_substance_id', $membership['substance_id'])
                ->where('notification_source.user_id', $membership['worker_id'])->where('notification_source.site_id', $membership['site_id'])
                ->where('notification_source.source_schema_version', 1)->where('notification_source.source_revision', $membership['source_revision'])
                ->whereNull('notification_source.deleted_at')
                ->where('notification_delivery.id', $admission['delivery_id'])->where('notification_delivery.kind', 'followup')
                ->where('notification_delivery.recipient_id', $admission['recipient_id'])
                ->whereRaw('notification_delivery.membership = CAST(? AS JSON)', [json_encode($membership, JSON_THROW_ON_ERROR)])
                ->where('notification_version.id', $membership['source_version_id'])->where('notification_version.source_type', 'followup')
                ->whereColumn('notification_version.exposure_id', 'notification_source.id')
                ->whereColumn('notification_version.worker_id', 'notification_source.user_id')
                ->whereColumn('notification_version.site_id', 'notification_source.site_id')
                ->whereColumn('notification_version.substance_id', 'notification_source.hazardous_substance_id')
                ->whereColumn('notification_version.aggregate_revision', 'notification_source.source_revision')
                ->where('notification_version.source_schema_version', 1)->where('notification_version.content_digest', $admission['source_digest'])
                ->where('notification_followup.id', $admission['followup_id'])
                ->whereColumn('notification_followup.exposure_id', 'notification_source.id')
                ->where('notification_followup.assigned_to', $admission['recipient_id'])
                ->where('notification_followup.source_schema_version', 1)->where('notification_followup.source_revision', $admission['followup_revision'])
                ->whereNull('notification_followup.completed_at');
            // Root and selected child must still retain ONLY this original aggregate after discovery.
            // Neither a foreign root acceptance nor a foreign child acceptance may join saved guards.
            $source->whereNotExists(fn (\Illuminate\Database\Query\Builder $foreign) => $foreign->selectRaw('1')
                ->from('chemical_exposure_source_versions as notification_foreign_version')
                ->whereColumn('notification_foreign_version.exposure_id', '!=', 'notification_source.id')
                ->where(fn (\Illuminate\Database\Query\Builder $identity) => $identity
                    ->where(fn (\Illuminate\Database\Query\Builder $child) => $child
                        ->where('notification_foreign_version.source_type', 'followup')->whereColumn('notification_foreign_version.source_id', 'notification_followup.id'))
                    ->orWhere(fn (\Illuminate\Database\Query\Builder $root) => $root
                        ->where('notification_foreign_version.source_type', 'exposure')->whereColumn('notification_foreign_version.source_id', 'notification_source.id'))))
                ->whereNotExists(fn (\Illuminate\Database\Query\Builder $foreign) => $foreign->selectRaw('1')
                    ->from('chemical_exposure_command_receipts as notification_foreign_receipt')
                    ->whereColumn('notification_foreign_receipt.exposure_id', '!=', 'notification_source.id')
                    ->where(fn (\Illuminate\Database\Query\Builder $identity) => $identity
                        ->where(fn (\Illuminate\Database\Query\Builder $child) => $child
                            ->where('notification_foreign_receipt.source_type', 'followup')->whereColumn('notification_foreign_receipt.source_id', 'notification_followup.id'))
                        ->orWhere(fn (\Illuminate\Database\Query\Builder $root) => $root
                            ->where('notification_foreign_receipt.source_type', 'exposure')->whereColumn('notification_foreign_receipt.source_id', 'notification_source.id'))));
            if ($membership['original_context'] === null) {
                $source->whereNull('notification_source.original_context')->whereNull('notification_source.related_incident_id');
            } else {
                $source->whereRaw('notification_source.original_context = CAST(? AS JSON)', [json_encode($membership['original_context'], JSON_THROW_ON_ERROR)]);
            }
        });
        foreach ($admission['binding_guards'] as $guard) {
            $family = $guard['family'];
            $table = ChemicalExposureFollowupDestinationService::notificationBindingTable($family);
            $alias = 'notification_guard_'.$family;
            $expression = ChemicalExposureFollowupDestinationService::notificationBindingFingerprintSql($family, $alias);
            $query->whereExists(fn (\Illuminate\Database\Query\Builder $current) => $current
                ->selectRaw('1')->from($table.' as '.$alias)->where($alias.'.id', $guard['id'])
                ->whereRaw($expression.' = ?', [$guard['fingerprint']]));
        }
    }

    /** Current canonical read, never a permission token for an already hydrated payload. */
    public function dataForPresentation(DatabaseNotification $candidate, User $viewer, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);
        return $context->consumeSourceViewer(self::PERMISSIONS, function ($current) use ($candidate, $context): array {
            $row = app(BoardPackAccessService::class)->visibleNotificationQuery($current, false, $context)
                ->where('notifications.id', (string) $candidate->id)->firstOrFail();
            if ($row->type === ChemicalExposureFollowupAssignedNotification::class) {
                $data = $this->destination->projectNotification($current, (string) $row->id, $context)['data'];
                if (! Route::has('health-safety.chemical-exposures.show')) { $data['url'] = null; }
                return $data;
            }
            return is_array($row->data) ? $row->data : [];
        });
    }

    /** Reading means only a retained own inbox flag; never source completion, acknowledgement or external delivery. */
    public function assertReadPersisted(DatabaseNotification $candidate, User $viewer, HealthSafetyReadContext $context): void
    {
        $context->assertViewer($viewer);
        if ($candidate->type !== ChemicalExposureFollowupAssignedNotification::class) { return; }
        $projected = $this->destination->projectNotification($viewer, (string) $candidate->id, $context);
        if ($projected['read_at'] === null) { throw new LogicException('Private notification read persistence was refused.'); }
    }

    private function detached(mixed $value): void
    {
        if (is_array($value)) { foreach ($value as $part) { $this->detached($part); } }
        elseif ($value !== null && ! is_scalar($value)) { throw new LogicException('Inbox callbacks must return detached scalar data only.'); }
    }
}
