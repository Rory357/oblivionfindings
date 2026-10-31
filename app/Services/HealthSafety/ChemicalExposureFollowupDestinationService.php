<?php

namespace App\Services\HealthSafety;

use App\Models\ChemicalExposureFollowup;
use App\Models\ChemicalExposureSourceDelivery;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\User;
use App\Notifications\HealthSafety\ChemicalExposureFollowupAssignedNotification;
use App\Services\CurrentAuthorizationReads;
use App\Support\HealthSafety\ChemicalExposureReturn;
use Illuminate\Notifications\DatabaseNotification;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Unmounted database-inbox destination. A persisted source-derived task is not a dispatch receipt. */
final class ChemicalExposureFollowupDestinationService
{
    public const EVENT_KEY = 'health_safety.chemical_exposure_followup_assigned';
    public function __construct(private readonly ChemicalExposureCommandAccess $commands,
        private readonly ChemicalExposureAccessService $access, private readonly ChemicalExposureSourceService $source,
        private readonly ChemicalExposureDeliveryService $delivery) {}

    public function deliverForViewer(User $viewer, int $deliveryId, ?int $expectedExposureId = null): array
    {
        if (\Illuminate\Support\Facades\DB::transactionLevel() !== 0) {
            throw new LogicException('Inbox delivery must start outside an existing transaction so confirmation follows its physical commit.');
        }
        $attempt = $this->delivery->deliverForViewer($viewer, $deliveryId,
            function ($root, $proof, $current, $reads, $sites, $membership, $kind, $recipient) use ($deliveryId) {
                if ($kind !== 'followup' || ! is_int($recipient)) {
                    throw new LogicException('This destination accepts named follow-up delivery only.');
                }
                $this->admitAttempt($deliveryId, $current, $reads, $sites);
                $receiver = $this->source->assertPrivateReceiver($recipient, (int) $root->site_id, $reads, true);
                // Prepare the receiver's full original-context permissions independently. Assignment grants nothing.
                $descriptor = $this->commands->withCurrentViewer($receiver, function ($recipientCurrent, $recipientReads, $recipientSites) use ($deliveryId) {
                    return $this->admitAttempt($deliveryId, $recipientCurrent, $recipientReads, $recipientSites);
                });
                // Canonical digest, not array identity: the stored JSON comes back with MySQL's key order.
                if (ChemicalCommandValues::digest($descriptor['membership']) !== ChemicalCommandValues::digest($membership)
                    || $descriptor['recipient_id'] !== $recipient) {
                    throw new LogicException('Private follow-up destination membership changed.');
                }
                $id = $descriptor['notification_id'];

                $existing = $reads->query(DatabaseNotification::query())->whereKey($id)->first();
                if ($existing) {
                    $this->assertNotification($existing, $descriptor);
                } else {
                    $enabled = $this->commands->withCurrentViewer($receiver,
                        fn ($actor, $activeReads) => $this->inboxEnabled($actor, $activeReads));
                    if (! $enabled) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'delivery' => 'The named recipient has disabled this inbox notification. The source follow-up remains open.']);
                    }
                    $notification = new DatabaseNotification(['id' => $id,
                        'type' => ChemicalExposureFollowupAssignedNotification::class,
                        'notifiable_type' => $receiver->getMorphClass(), 'notifiable_id' => $recipient,
                        'data' => $descriptor['payload'], 'read_at' => null]);
                    if (! $notification->save()) { throw new LogicException('Private follow-up inbox persistence was refused.'); }
                    $persisted = $reads->query(DatabaseNotification::query())->whereKey($id)->first();
                    if (! $persisted) { throw new LogicException('Private follow-up inbox persistence is unconfirmed.'); }
                    $this->assertNotification($persisted, $descriptor);
                }
                // Do not let a save listener/interleaving replace the original source under a genuine inbox row.
                $fresh = $this->admitAttempt($deliveryId, $current, $reads, $sites);
                if ($fresh !== $descriptor
                    || ChemicalCommandValues::digest($fresh['membership']) !== ChemicalCommandValues::digest($membership)) {
                    throw new LogicException('Private follow-up source changed during inbox persistence.');
                }
                $recipientFresh = $this->commands->withCurrentViewer($receiver,
                    fn ($actor, $activeReads, $approvedSites) => $this->admitAttempt($deliveryId, $actor, $activeReads, $approvedSites));
                if ($recipientFresh !== $descriptor) { throw new LogicException('Private follow-up receiver changed during inbox persistence.'); }
                return ['delivered' => true, 'recipient_id' => $recipient,
                    'source_revision' => $membership['source_revision'], 'source_version_id' => $membership['source_version_id']];
            }, $expectedExposureId);
        return [...$attempt, 'destination_confirmation' => $attempt['state'] === 'delivered' ? 'inbox_present' : 'unconfirmed',
            'channel' => 'database', 'external_delivery' => 'not_attempted'];
    }

    /** Reconcile a lost response without resending or trusting the outbox's reported state. */
    public function confirmationForViewer(User $viewer, int $id): array
    {
        return $this->commands->withCurrentViewer($viewer, fn ($current, $reads, $sites) =>
            array_intersect_key($this->confirmationInReads($id, $current, $reads, $sites), array_flip(['id','destination_confirmation','channel','external_delivery','read','actioned'])));
    }
    /** Same caller callback; the independently different named recipient still prepares its own current evidence. */
    public function confirmationInContext(User $viewer, int $id, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);
        return $context->consumeViewer(ChemicalExposureCommandAccess::PERMISSIONS, fn ($current, $reads, $sites) =>
            $this->confirmationInReads($id, $current, $reads, $sites));
    }

    private function confirmationInReads(int $id, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $descriptor = $this->admitAttempt($id, $current, $reads, $sites);
        $receiver = $this->source->assertPrivateReceiver($descriptor['recipient_id'], $descriptor['membership']['site_id'], $reads, true);
        $recipient = $this->commands->withCurrentViewer($receiver, fn ($actor, $activeReads, $approvedSites) => [
            'descriptor' => $this->admitAttempt($id, $actor, $activeReads, $approvedSites), 'enabled' => $this->inboxEnabled($actor, $activeReads)]);
        if ($descriptor !== $recipient['descriptor']) { throw new LogicException('Private follow-up recipient source changed.'); }
        $notification = $reads->query(DatabaseNotification::query())->whereKey($descriptor['notification_id'])->first();
        if ($notification) { $this->assertNotification($notification, $descriptor); }
        return ['id' => $id, 'destination_confirmation' => $notification ? 'inbox_present' : 'unconfirmed',
            'channel' => 'database', 'external_delivery' => 'not_attempted',
            'read' => $notification ? $notification->read_at !== null : null, 'actioned' => false,
            'can_deliver' => $notification !== null || $recipient['enabled'], 'notification_preference' => $recipient['enabled'] ? 'enabled' : 'disabled',
            'blocked_reason' => $notification === null && ! $recipient['enabled'] ? 'recipient_opted_out' : null];
    }

    /** Shared inbox MUST use this before count/get, plus projectNotification for its outward DTO. */
    public function visibleNotificationIdsInContext(User $viewer, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);
        return $context->consumeViewer(ChemicalExposureCommandAccess::PERMISSIONS, function ($current, $reads, $sites) {
            if (! $this->access->canRead($current)) { return []; }
            $ids = [];
            $query = $reads->query(DatabaseNotification::query())->where('type', ChemicalExposureFollowupAssignedNotification::class)
                ->where('notifiable_type', $current->getMorphClass())->where('notifiable_id', $current->id);
            $query->select(['id'])->selectRaw("JSON_EXTRACT(data, '$.delivery_id') AS delivery_identity")
                ->whereRaw("JSON_TYPE(JSON_EXTRACT(data, '$.delivery_id')) = 'INTEGER'");
            foreach ($query->get() as $identity) {
                $id = filter_var($identity->getRawOriginal('delivery_identity'), FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
                if (! is_int($id)) { continue; }
                try {
                    $descriptor = $this->admitAttempt($id, $current, $reads, $sites, true);
                    $row = $reads->query(DatabaseNotification::query())->whereKey($identity->id)
                        ->where('type', ChemicalExposureFollowupAssignedNotification::class)->where('notifiable_type', $current->getMorphClass())
                        ->where('notifiable_id', $current->id)->firstOrFail();
                    $this->assertNotification($row, $descriptor);
                    $ids[] = $row->id;
                } catch (LogicException) { continue; }
                catch (\Illuminate\Validation\ValidationException $error) {
                    if (array_keys($error->errors()) !== ['source']) { throw $error; }
                    // Sealed original source cannot abort unrelated healthy own notifications.
                }
                catch (HttpExceptionInterface $error) {
                    if (! in_array($error->getStatusCode(), [403, 404], true)) { throw $error; }
                } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) { continue; }
            }
            return $ids;
        });
    }

    /** Exact notification selection first; current recipient/source proof before projecting its useful link. */
    public function projectNotification(User $viewer, string $id, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);
        return $context->consumeViewer(ChemicalExposureCommandAccess::PERMISSIONS, function ($current, $reads, $sites) use ($id) {
            $this->access->assertRead($current, $reads);
            $identity = $reads->query(DatabaseNotification::query())->where('type', ChemicalExposureFollowupAssignedNotification::class)
                ->where('notifiable_type', $current->getMorphClass())->where('notifiable_id', $current->id)->whereKey($id)
                ->select(['id'])->selectRaw("JSON_EXTRACT(data, '$.delivery_id') AS delivery_identity")
                ->whereRaw("JSON_TYPE(JSON_EXTRACT(data, '$.delivery_id')) = 'INTEGER'")->firstOrFail();
            $delivery = filter_var($identity->getRawOriginal('delivery_identity'), FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
            if (! is_int($delivery)) { throw new LogicException('Private inbox identity is damaged.'); }
            $descriptor = $this->admitAttempt($delivery, $current, $reads, $sites, true);
            $row = $reads->query(DatabaseNotification::query())->whereKey($id)
                ->where('type', ChemicalExposureFollowupAssignedNotification::class)->where('notifiable_type', $current->getMorphClass())
                ->where('notifiable_id', $current->id)->firstOrFail();
            $this->assertNotification($row, $descriptor);
            return ['id' => $row->id, 'type' => $row->type,
                'data' => [...$descriptor['payload'], 'url' => ChemicalExposureReturn::record($descriptor['membership']['exposure_id'], null, 'followups')],
                'read_at' => $row->read_at?->toISOString(), 'created_at' => $row->created_at?->toISOString()];
        });
    }

    /** Internal consuming proof only; never an outward DTO, cached whitelist or permission token. */
    public function notificationAdmissionInContext(User $viewer, string $id, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);
        return $context->consumeViewer(ChemicalExposureCommandAccess::PERMISSIONS, function ($current, $reads, $sites) use ($id, $context): array {
            // The existing projection admits the own UUID + typed delivery identity before its body.
            $projection = $this->projectNotification($current, $id, $context);
            $deliveryId = $projection['data']['delivery_id'];
            $accepted = $this->admitAttempt($deliveryId, $current, $reads, $sites, true);
            $membership = $accepted['membership'];
            $root = $this->access->assertExposure($membership['exposure_id'], $current, $reads, $sites);
            $delivery = $reads->query(ChemicalExposureSourceDelivery::query())->whereKey($deliveryId)->firstOrFail(['id']);
            $version = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $root->id)
                ->whereKey($membership['source_version_id'])->firstOrFail(['id', 'source_id', 'content_digest']);
            $followup = $reads->query(ChemicalExposureFollowup::query())->where('exposure_id', $root->id)
                ->where('assigned_to', $current->id)->whereKey($version->source_id)->firstOrFail(['id', 'source_revision']);
            $receipt = $reads->query(\App\Models\ChemicalExposureCommandReceipt::query())
                ->where('exposure_id', $root->id)->where('source_version_id', $version->id)->sole(['id', 'intent_id', 'audit_log_id']);
            $intent = $reads->query(\App\Models\ChemicalCommandIntent::query())->where('source_type', 'exposure')
                ->whereKey($receipt->intent_id)->firstOrFail(['id']);
            $audit = $reads->query(\App\Models\AuditLog::query())->whereKey($receipt->audit_log_id)->firstOrFail(['id']);
            $rows = ['root' => $root, 'delivery' => $delivery, 'version' => $version, 'followup' => $followup,
                'receipt' => $receipt, 'intent' => $intent, 'audit' => $audit,
                'site' => $reads->query(\App\Models\Site::query())->whereKey($root->site_id)->firstOrFail(['id']),
                'worker' => $reads->query(User::query())->whereKey($root->user_id)->firstOrFail(['id']),
                'substance' => $reads->query(\App\Models\HazardousSubstance::query())->whereKey($root->hazardous_substance_id)->firstOrFail(['id'])];
            // The source reader admits ALL retained reported-map Sites/contexts, including earlier
            // field acceptance at A after latest root/child/delivery facts have moved to B.
            $reportedIds = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $root->id)
                ->whereJsonContainsKey('content->facts->reported_facts')->orderBy('id')->pluck('id')->all();
            $historical = [];
            foreach ($reportedIds as $reportedId) {
                // Identity-only candidate discovery, then canonical original metadata before body.
                $metadata = $this->source->admitVersionMetadata((int) $reportedId, $current, $reads, $sites);
                $recorded = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $root->id)
                    ->whereKey($reportedId)->firstOrFail();
                $this->source->assertAdmittedMetadata($recorded, $metadata);
                $originalReceipt = $reads->query(\App\Models\ChemicalExposureCommandReceipt::query())
                    ->where('exposure_id', $root->id)->where('source_version_id', $recorded->id)->sole();
                $originalIntent = $reads->query(\App\Models\ChemicalCommandIntent::query())->where('source_type', 'exposure')
                    ->whereKey($originalReceipt->intent_id)->firstOrFail();
                $originalAudit = $reads->query(\App\Models\AuditLog::query())->whereKey($originalReceipt->audit_log_id)->firstOrFail();
                array_push($historical, ['family' => 'version', 'row' => $recorded], ['family' => 'receipt', 'row' => $originalReceipt],
                    ['family' => 'intent', 'row' => $originalIntent], ['family' => 'audit', 'row' => $originalAudit]);
                foreach ([$metadata['site_id'], $metadata['before_site']] as $siteId) {
                    if ($siteId !== null) { $historical[] = ['family' => 'site',
                        'row' => $reads->query(\App\Models\Site::query())->whereKey($siteId)->firstOrFail(['id'])]; }
                }
                foreach ([$metadata['before_context'], $metadata['after_context']] as $originalContext) {
                    if ($originalContext === null) { continue; } // Only already canonically admitted genuine absence.
                    if ($originalContext['incident_id'] !== null) {
                        $historical[] = ['family' => 'incident', 'row' => $reads->query(\App\Models\ClientIncident::query())
                            ->whereKey($originalContext['incident_id'])->firstOrFail(['id'])];
                        $historical[] = ['family' => 'client', 'row' => $reads->query(\App\Models\Client::query())
                            ->whereKey($originalContext['client_id'])->firstOrFail(['id'])];
                    }
                    if ($originalContext['shift_id'] !== null) { $historical[] = ['family' => 'shift',
                        'row' => $reads->query(\App\Models\Shift::query())->whereKey($originalContext['shift_id'])->firstOrFail(['id'])]; }
                    if ($originalContext['service_context_id'] !== null) { $historical[] = ['family' => 'context',
                        'row' => $reads->query(\App\Models\ServiceContext::query())->whereKey($originalContext['service_context_id'])->firstOrFail(['id'])]; }
                    if ($originalContext['stay_id'] !== null) {
                        $originalStay = $reads->query(\App\Models\RespiteStay::query())->whereKey($originalContext['stay_id'])->firstOrFail(['id', 'booking_id']);
                        $historical[] = ['family' => 'stay', 'row' => $originalStay];
                        if ($originalStay->booking_id !== null) { $historical[] = ['family' => 'booking',
                            'row' => $reads->query(\App\Models\RespiteBooking::query())->whereKey($originalStay->booking_id)->firstOrFail(['id'])]; }
                    }
                }
            }
            $original = $membership['original_context'];
            if ($original !== null && (! is_array($original) || ($original['schema_version'] ?? null) !== 1)) {
                throw new LogicException('Private notification original context is unconfirmed.');
            }
            if ($original === null && $root->related_incident_id !== null) {
                throw new LogicException('Private notification linked origin is sealed.');
            }
            // Canonically admitted genuinely unlinked legacy origin stays unknown; never infer today's context.
            if ($original !== null && $original['incident_id'] !== null) {
                // Original-context permissions/association were already asserted by admitAttempt.
                $rows['incident'] = $reads->query(\App\Models\ClientIncident::query())->whereKey($original['incident_id'])->firstOrFail(['id']);
                $rows['client'] = $reads->query(\App\Models\Client::query())->whereKey($original['client_id'])->firstOrFail(['id']);
            }
            if ($original !== null && $original['shift_id'] !== null) {
                $rows['shift'] = $reads->query(\App\Models\Shift::query())->whereKey($original['shift_id'])->firstOrFail(['id']);
            }
            if ($original !== null && $original['service_context_id'] !== null) {
                $rows['context'] = $reads->query(\App\Models\ServiceContext::query())->whereKey($original['service_context_id'])->firstOrFail(['id']);
            }
            if ($original !== null && $original['stay_id'] !== null) {
                $rows['stay'] = $reads->query(\App\Models\RespiteStay::query())->whereKey($original['stay_id'])->firstOrFail(['id', 'booking_id']);
                if ($rows['stay']->booking_id !== null) {
                    $rows['booking'] = $reads->query(\App\Models\RespiteBooking::query())->whereKey($rows['stay']->booking_id)->firstOrFail(['id']);
                }
            }
            $guards = [];
            $records = [...array_map(fn ($family, $row) => ['family' => $family, 'row' => $row], array_keys($rows), array_values($rows)), ...$historical];
            foreach ($records as $record) {
                $family = $record['family']; $row = $record['row'];
                $table = self::notificationBindingTable($family);
                if ($row->getTable() !== $table) { throw new LogicException('Unknown notification proof table.'); }
                // Select only the opaque fingerprint; never return a private source/version/audit body.
                $identity = $reads->query($row->newQuery())->whereKey($row->id)->select(['id'])
                    ->selectRaw(self::notificationBindingFingerprintSql($family, $table).' AS admission_fingerprint')->firstOrFail();
                $fingerprint = $identity->getRawOriginal('admission_fingerprint');
                if (! is_string($fingerprint) || ! preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
                    throw new LogicException('Private notification fingerprint is unconfirmed.');
                }
                $guards[$family.'|'.$row->id] = ['family' => $family, 'id' => (int) $row->id, 'fingerprint' => $fingerprint];
            }
            // A listener/interleaving during fingerprint collection must not certify unproved current facts.
            $fresh = $this->admitAttempt($deliveryId, $current, $reads, $sites, true);
            if ($fresh !== $accepted) { throw new LogicException('Private notification source changed during admission.'); }
            $row = $reads->query(DatabaseNotification::query())->whereKey($id)
                ->where('type', ChemicalExposureFollowupAssignedNotification::class)
                ->where('notifiable_type', $current->getMorphClass())->where('notifiable_id', $current->id)->firstOrFail();
            $this->assertNotification($row, $fresh);
            return ['notification_id' => $id, 'notifiable_type' => $current->getMorphClass(), 'recipient_id' => (int) $current->id,
                'delivery_id' => $deliveryId, 'membership' => $membership,
                'followup_id' => (int) $followup->id, 'followup_revision' => (int) $followup->source_revision,
                'source_digest' => $version->content_digest, 'binding_guards' => array_values($guards),
                'reported_version_ids' => array_map('intval', $reportedIds)];
        });
    }

    /** Fixed physical schema only. These names are not supplied by a request or an admission descriptor. */
    public static function notificationBindingTable(string $family): string
    {
        return match ($family) {
            'root' => 'substance_exposure_records', 'delivery' => 'chemical_exposure_source_deliveries',
            'version' => 'chemical_exposure_source_versions', 'followup' => 'chemical_exposure_followups',
            'receipt' => 'chemical_exposure_command_receipts', 'intent' => 'chemical_command_intents', 'audit' => 'audit_logs',
            'site' => 'sites', 'worker' => 'users', 'substance' => 'hazardous_substances',
            'incident' => 'client_incidents', 'client' => 'clients', 'shift' => 'shifts', 'context' => 'service_contexts',
            'stay' => 'respite_stays', 'booking' => 'respite_bookings',
            default => throw new LogicException('Unknown private notification binding family.'),
        };
    }

    /** Pure fixed-column expression, not a compiled query or returned authority-bearing builder. */
    public static function notificationBindingFingerprintSql(string $family, string $alias): string
    {
        self::notificationBindingTable($family);
        if (! preg_match('/^[a-z][a-z0-9_]*$/D', $alias)) { throw new LogicException('Unknown notification binding alias.'); }
        $columns = match ($family) {
            'root' => ['id', 'hazardous_substance_id', 'user_id', 'site_id', 'source_schema_version', 'source_revision', 'original_context',
                'related_incident_id', 'exposed_at', 'exposure_type', 'exposure_duration', 'circumstances', 'symptoms', 'first_aid_given',
                'medical_attention_sought', 'medical_treatment', 'medical_outcome', 'incident_reported', 'created_by', 'updated_by', 'deleted_at'],
            'delivery' => ['id', 'exposure_id', 'source_version_id', 'actor_id', 'kind', 'recipient_id', 'membership'],
            'followup' => ['id', 'exposure_id', 'source_schema_version', 'source_revision', 'assigned_to', 'assignee_snapshot',
                'instruction', 'due_at', 'recorded_by', 'completed_at', 'completed_by', 'completion_notes'],
            'version' => ['id', 'exposure_id', 'source_type', 'source_id', 'substance_id', 'worker_id', 'site_id', 'recorder_id', 'actor_id',
                'intent_id', 'aggregate_revision', 'expected_version', 'source_schema_version', 'command', 'idempotency_key',
                'actor_snapshot', 'content', 'content_digest', 'recorded_at'],
            'receipt' => ['id', 'idempotency_key', 'intent_id', 'actor_id', 'exposure_id', 'source_type', 'source_id', 'command',
                'expected_version', 'fingerprint', 'source_version_id', 'source_content_digest', 'source_tuple', 'source_tuple_digest',
                'audit_log_id', 'accepted_result', 'created_at'],
            'intent' => ['id', 'idempotency_key', 'actor_id', 'actor_snapshot', 'source_type', 'source_id', 'parent_id', 'command',
                'expected_version', 'parent_expected_version', 'fingerprint', 'created_at'],
            'audit' => ['id', 'user_id', 'client_id', 'action', 'auditable_type', 'auditable_id', 'meta'],
            'site' => ['id', 'is_active', 'archived', 'archived_at'], 'worker' => ['id'], 'substance' => ['id', 'deleted_at'],
            'incident' => ['id', 'client_id', 'site_id', 'shift_id', 'service_context_id', 'respite_stay_id', 'is_hr_confidential'],
            'client' => ['id', 'site_id', 'deleted_at'], 'shift' => ['id', 'site_id', 'client_id'],
            'context' => ['id', 'site_id', 'is_active'], 'stay' => ['id', 'client_id', 'booking_id', 'deleted_at'],
            'booking' => ['id', 'client_id', 'location_id', 'deleted_at'],
        };
        $parts = array_map(fn ($column) => "'".$column."', `".$alias.'`.`'.$column.'`', $columns);
        return 'SHA2(CAST(JSON_OBJECT('.implode(', ', $parts).') AS CHAR CHARACTER SET utf8mb4), 256)';
    }
    /** Named work only; no source prose, people, Site names or files enter a general task DTO. */
    public function taskRows(User $viewer, array $filters = [], ?HealthSafetyReadContext $context = null, bool $navigation = false): array
    {
        $operation = function ($current, $reads, $sites) use ($filters, $navigation) {
            if (! $this->access->canRead($current)) { return []; }
            $parents = $this->access->readableQuery($current, $reads, $sites)->select('id');
            $query = $reads->query(ChemicalExposureFollowup::query())->where('assigned_to', $current->id)->whereIn('exposure_id', $parents)
                ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
                ->when(empty($filters['include_done']), fn ($q) => $q->whereNull('completed_at'))->orderBy('id');
            $pending = [];
            foreach ($query->get(['id', 'exposure_id', 'source_schema_version', 'source_revision']) as $identity) {
                try {
                    $root = $this->access->assertExposure((int) $identity->exposure_id, $current, $reads, $sites);
                    $proof = $this->source->sourceProof($root, $current, $reads, $sites);
                    if ($proof['attribution_state'] !== 'recorded') { continue; }
                    $this->source->assertSourceIdentityBeforeBody($root, $identity, 'followup', $reads, $current, $sites);
                    $versionIdentity = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $root->id)
                        ->where('source_type', 'followup')->where('source_id', $identity->id)->orderByDesc('aggregate_revision')->first(['id']);
                    if (! $versionIdentity) { continue; }
                    $this->source->admitVersionMetadata((int) $versionIdentity->id, $current, $reads, $sites);
                    $child = $reads->query(ChemicalExposureFollowup::query())->where('exposure_id', $root->id)
                        ->where('assigned_to', $current->id)->whereKey($identity->id)
                        ->where('source_schema_version', $identity->source_schema_version)
                        ->where('source_revision', $identity->source_revision)->firstOrFail();
                    try { $version = $this->source->currentVersion($root, $child, 'followup', $reads, $current, $sites); }
                    catch (LogicException) { continue; }
                    if (! $version) { continue; }
                    $binding = $this->taskBindingGuards($root, $child, $proof['source_version_id'], $version, $current, $reads, $sites);
                    // Re-prove after fingerprint collection. Root and child revisions are independent:
                    // an unchanged assigned child remains valid after another accepted aggregate command.
                    $freshRoot = $this->access->assertExposure((int) $root->id, $current, $reads, $sites);
                    $freshProof = $this->source->sourceProof($freshRoot, $current, $reads, $sites);
                    $freshIdentity = $reads->query(ChemicalExposureFollowup::query())->where('exposure_id', $root->id)
                        ->where('assigned_to', $current->id)->whereKey($child->id)
                        ->firstOrFail(['id', 'exposure_id', 'source_schema_version', 'source_revision']);
                    $this->source->assertSourceIdentityBeforeBody($freshRoot, $freshIdentity, 'followup', $reads, $current, $sites);
                    $freshChild = $reads->query(ChemicalExposureFollowup::query())->where('exposure_id', $root->id)
                        ->where('assigned_to', $current->id)->whereKey($freshIdentity->id)
                        ->where('source_schema_version', $freshIdentity->source_schema_version)
                        ->where('source_revision', $freshIdentity->source_revision)->firstOrFail();
                    $freshVersion = $this->source->currentVersion($freshRoot, $freshChild, 'followup', $reads, $current, $sites);
                    if ($freshProof !== $proof || ! $freshVersion || $freshVersion->id !== $version->id
                        || $freshChild->source_revision !== $child->source_revision) { continue; }
                    $pending[$child->id] = ['root_id' => (int) $root->id, 'root_revision' => (int) $root->source_revision,
                        'child_revision' => (int) $child->source_revision, 'guards' => $binding['guards'],
                        'reported_version_ids' => $binding['reported_version_ids'],
                        'row' => ['id' => 'chemical_exposure_followup-'.$child->id, 'source' => 'chemical_exposure_followup',
                        'sourceLabel' => 'Private follow-ups', 'ref' => null, 'title' => $navigation ? '' : 'Assigned follow-up',
                        'status' => $child->completed_at ? 'completed' : 'open', 'bucket' => $child->completed_at ? 'done' : 'open',
                        'severity' => 'info', 'assignee' => ['id' => (int) $current->id, 'name' => 'You'], 'client' => null, 'site' => null,
                        'dueAt' => $child->due_at?->toISOString(), 'createdAt' => $child->created_at?->toISOString(),
                        // The exposure page opens straight on this follow-up (it finds the right page itself) and
                        // re-checks the viewer's current access; the link carries ids only.
                        'link' => $navigation ? null : '/health-safety/chemical-exposures/'.(int) $root->id.'?section=followups&followup='.(int) $child->id,
                        'description' => null, 'actionLabel' => 'Open follow-up',
                        'displayState' => null, 'actionHelp' => null]];
                } catch (LogicException) { continue; }
                catch (\Illuminate\Validation\ValidationException $error) {
                    if (array_keys($error->errors()) !== ['source']) { throw $error; }
                    // Sealed original source is unavailable, never a failure of healthy neighbouring work.
                }
                catch (HttpExceptionInterface $error) {
                    if (! in_array($error->getStatusCode(), [403, 404], true)) { throw $error; }
                } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) { continue; }
            }
            if ($pending === []) { return []; }
            // ONE final statement after ALL candidate hydration/accumulation. Only identities are selected;
            // exact root/child/provenance/original-context snapshots must still match before any DTO leaves.
            $final = $reads->query(ChemicalExposureFollowup::query())->where('assigned_to', $current->id)
                ->where('source_schema_version', 1)
                ->when(empty($filters['include_done']), fn ($q) => $q->whereNull('completed_at'))
                ->where(function (\Illuminate\Database\Eloquent\Builder $allowed) use ($pending): void {
                    foreach ($pending as $id => $candidate) {
                        $allowed->orWhere(function (\Illuminate\Database\Eloquent\Builder $row) use ($id, $candidate): void {
                            $row->where('chemical_exposure_followups.id', $id)
                                ->where('chemical_exposure_followups.exposure_id', $candidate['root_id'])
                                ->where('chemical_exposure_followups.source_revision', $candidate['child_revision']);
                            // Canonical root/child preflight rejects ANY foreign original aggregate.
                            // A new retained acceptance cannot join this saved final proof.
                            $row->whereNotExists(fn (\Illuminate\Database\Query\Builder $foreign) => $foreign->selectRaw('1')
                                ->from('chemical_exposure_source_versions as chemical_task_foreign_version')
                                ->where('chemical_task_foreign_version.exposure_id', '!=', $candidate['root_id'])
                                ->where(fn (\Illuminate\Database\Query\Builder $identity) => $identity
                                    ->where(fn (\Illuminate\Database\Query\Builder $child) => $child
                                        ->where('chemical_task_foreign_version.source_type', 'followup')->where('chemical_task_foreign_version.source_id', $id))
                                    ->orWhere(fn (\Illuminate\Database\Query\Builder $root) => $root
                                        ->where('chemical_task_foreign_version.source_type', 'exposure')->where('chemical_task_foreign_version.source_id', $candidate['root_id']))))
                                ->whereNotExists(fn (\Illuminate\Database\Query\Builder $foreign) => $foreign->selectRaw('1')
                                    ->from('chemical_exposure_command_receipts as chemical_task_foreign_receipt')
                                    ->where('chemical_task_foreign_receipt.exposure_id', '!=', $candidate['root_id'])
                                    ->where(fn (\Illuminate\Database\Query\Builder $identity) => $identity
                                        ->where(fn (\Illuminate\Database\Query\Builder $child) => $child
                                            ->where('chemical_task_foreign_receipt.source_type', 'followup')->where('chemical_task_foreign_receipt.source_id', $id))
                                        ->orWhere(fn (\Illuminate\Database\Query\Builder $root) => $root
                                            ->where('chemical_task_foreign_receipt.source_type', 'exposure')->where('chemical_task_foreign_receipt.source_id', $candidate['root_id']))));
                            // Root proof admits EVERY retained reported-map origin, including earlier
                            // Sites/field acceptances no longer represented by the latest root or child.
                            $row->whereNotExists(function (\Illuminate\Database\Query\Builder $reported) use ($candidate): void {
                                $reported->selectRaw('1')->from('chemical_exposure_source_versions as chemical_task_reported')
                                    ->where('chemical_task_reported.exposure_id', $candidate['root_id'])
                                    ->whereJsonContainsKey('chemical_task_reported.content->facts->reported_facts');
                                if ($candidate['reported_version_ids'] !== []) {
                                    $reported->whereNotIn('chemical_task_reported.id', $candidate['reported_version_ids']);
                                }
                            })->whereNotExists(fn (\Illuminate\Database\Query\Builder $later) => $later->selectRaw('1')
                                ->from('chemical_exposure_source_versions as chemical_task_later')
                                ->where('chemical_task_later.exposure_id', $candidate['root_id'])
                                ->where('chemical_task_later.aggregate_revision', '>', $candidate['root_revision']));
                            foreach ($candidate['guards'] as $ordinal => $guard) {
                                $alias = 'chemical_task_guard_'.$ordinal;
                                $expression = $this->taskFingerprintSql($guard['columns'], $alias);
                                $row->whereExists(fn (\Illuminate\Database\Query\Builder $same) => $same->selectRaw('1')
                                    ->from($guard['table'].' as '.$alias)->where($alias.'.id', $guard['id'])
                                    ->whereRaw($expression.' = ?', [$guard['fingerprint']]));
                            }
                        });
                    }
                });
            $ids = $final->pluck('chemical_exposure_followups.id')->all();
            return array_values(array_map(fn ($id) => $pending[$id]['row'], $ids));
        };
        if ($context !== null) {
            $context->assertViewer($viewer);
            return $context->consumeViewer(ChemicalExposureCommandAccess::PERMISSIONS, $operation);
        }
        return $this->commands->withCurrentViewer($viewer, $operation);
    }

    /** Internal models stay in this callback. This does not admit inbox/outbox or external delivery. */
    private function taskBindingGuards(\App\Models\SubstanceExposureRecord $root, ChemicalExposureFollowup $child,
        int $rootVersionId, ChemicalExposureSourceVersion $childVersion, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        // This exact ID came from sourceProof; make original metadata admission explicit before this local body read too.
        $rootMetadata = $this->source->admitVersionMetadata($rootVersionId, $current, $reads, $sites);
        $versions = [$rootVersionId => $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $root->id)
            ->whereKey($rootVersionId)->firstOrFail(), $childVersion->id => $childVersion];
        $reportedIds = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $root->id)
            ->whereJsonContainsKey('content->facts->reported_facts')->orderBy('id')->pluck('id')->all();
        foreach ($reportedIds as $id) {
            // Discovery is identity-only. Source.currentVersion already admits this exact family;
            // repeat its original metadata admission BEFORE loading each historical private body.
            $metadata = $this->source->admitVersionMetadata((int) $id, $current, $reads, $sites);
            $recorded = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $root->id)->whereKey($id)->firstOrFail();
            $this->source->assertAdmittedMetadata($recorded, $metadata);
            $versions[(int) $id] = $recorded;
        }
        $rows = [$root, $child, $reads->query(\App\Models\User::query())->whereKey($root->user_id)->firstOrFail(['id']),
            $reads->query(\App\Models\HazardousSubstance::query())->whereKey($root->hazardous_substance_id)->firstOrFail(['id', 'deleted_at'])];
        foreach ($versions as $version) {
            $metadata = (int) $version->id === $rootVersionId ? $rootMetadata
                : $this->source->admitVersionMetadata((int) $version->id, $current, $reads, $sites);
            $this->source->assertAdmittedMetadata($version, $metadata);
            $receipt = $reads->query(\App\Models\ChemicalExposureCommandReceipt::query())->where('exposure_id', $root->id)
                ->where('source_version_id', $version->id)->sole();
            $intent = $reads->query(\App\Models\ChemicalCommandIntent::query())->where('source_type', 'exposure')->whereKey($receipt->intent_id)->firstOrFail();
            $audit = $reads->query(\App\Models\AuditLog::query())->whereKey($receipt->audit_log_id)->firstOrFail();
            array_push($rows, $version, $receipt, $intent, $audit);
            foreach ([$metadata['site_id'], $metadata['before_site']] as $siteId) {
                if ($siteId !== null) { $rows[] = $reads->query(\App\Models\Site::query())->whereKey($siteId)->firstOrFail(['id', 'is_active', 'archived', 'archived_at']); }
            }
            foreach ([$metadata['before_context'], $metadata['after_context']] as $original) {
                if ($original === null) { continue; } // Only already-admitted genuine absence, never inferred context.
                if ($original['incident_id'] !== null) {
                    $rows[] = $reads->query(\App\Models\ClientIncident::query())->whereKey($original['incident_id'])->firstOrFail([
                        'id', 'site_id', 'client_id', 'shift_id', 'service_context_id', 'respite_stay_id', 'is_hr_confidential']);
                    $rows[] = $reads->query(\App\Models\Client::query())->whereKey($original['client_id'])->firstOrFail(['id', 'site_id', 'deleted_at']);
                }
                if ($original['shift_id'] !== null) { $rows[] = $reads->query(\App\Models\Shift::query())->whereKey($original['shift_id'])->firstOrFail(['id', 'site_id', 'client_id']); }
                if ($original['service_context_id'] !== null) { $rows[] = $reads->query(\App\Models\ServiceContext::query())->whereKey($original['service_context_id'])->firstOrFail(['id', 'site_id', 'is_active']); }
                if ($original['stay_id'] !== null) {
                    $stay = $reads->query(\App\Models\RespiteStay::query())->whereKey($original['stay_id'])->firstOrFail(['id', 'client_id', 'booking_id', 'deleted_at']);
                    $rows[] = $stay;
                    if ($stay->booking_id !== null) { $rows[] = $reads->query(\App\Models\RespiteBooking::query())->whereKey($stay->booking_id)->firstOrFail(['id', 'client_id', 'location_id', 'deleted_at']); }
                }
            }
        }
        $guards = [];
        foreach ($rows as $row) {
            $table = $row->getTable();
            $columns = array_keys($row->getAttributes());
            $fingerprint = $reads->query($row->newQuery())->whereKey($row->id)->select(['id'])
                ->selectRaw($this->taskFingerprintSql($columns, $table).' AS task_fingerprint')->firstOrFail()->getRawOriginal('task_fingerprint');
            if (! is_string($fingerprint) || ! preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) { throw new LogicException('Task source stability is unconfirmed.'); }
            $guards[$table.'|'.$row->id] = ['table' => $table, 'columns' => $columns, 'id' => (int) $row->id, 'fingerprint' => $fingerprint];
        }
        return ['guards' => array_values($guards), 'reported_version_ids' => array_map('intval', $reportedIds)];
    }

    /** Actual admitted model columns only; no request/caller table or field may enter this pure expression. */
    private function taskFingerprintSql(array $columns, string $alias): string
    {
        if ($columns === [] || ! preg_match('/^[a-z][a-z0-9_]*$/D', $alias)) { throw new LogicException('Task source expression is invalid.'); }
        foreach ($columns as $column) {
            if (! is_string($column) || ! preg_match('/^[a-z][a-z0-9_]*$/D', $column)) { throw new LogicException('Task source column is invalid.'); }
        }
        $parts = array_map(fn ($column) => "'".$column."', `".$alias.'`.`'.$column.'`', $columns);
        return 'SHA2(CAST(JSON_OBJECT('.implode(', ', $parts).') AS CHAR CHARACTER SET utf8mb4), 256)';
    }

    private function admitAttempt(int $id, User $current, CurrentAuthorizationReads $reads, array $sites, bool $own = false): array
    {
        $this->access->assertRead($current, $reads);
        $identity = $reads->query(ChemicalExposureSourceDelivery::query())->where('kind', 'followup')
            ->when($own || ! $current->canDo('hazards.manage'), fn ($q) => $q->where('recipient_id', $current->id))
            ->whereKey($id)->firstOrFail(['id', 'exposure_id']);
        $root = $this->access->assertExposure((int) $identity->exposure_id, $current, $reads, $sites);
        $proof = $this->source->sourceProofForAuditSubject(ChemicalExposureSourceDelivery::class, $id, $current, $reads, $sites);
        if (($proof['attribution_state'] ?? null) !== 'recorded') { throw new LogicException('Private follow-up acceptance is unconfirmed.'); }
        $row = $reads->query(ChemicalExposureSourceDelivery::query())->where('exposure_id', $root->id)->where('kind', 'followup')->whereKey($id)->firstOrFail();
        $membership = ['exposure_id' => (int) $root->id, 'substance_id' => (int) $root->hazardous_substance_id,
            'worker_id' => (int) $root->user_id, 'site_id' => (int) $root->site_id,
            'source_revision' => $proof['source_revision'], 'source_version_id' => $proof['source_version_id'], 'original_context' => $root->original_context];
        if (ChemicalCommandValues::digest($membership) !== ChemicalCommandValues::digest($row->membership)
            || $row->source_version_id !== $proof['source_version_id']) {
            throw new LogicException('Private follow-up delivery is superseded by current source facts.');
        }
        if ($own || ! $current->canDo('hazards.manage')) { abort_unless($row->recipient_id === (int) $current->id, 404); }
        $version = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $root->id)
            ->where('source_type', 'followup')->whereKey($row->source_version_id)->firstOrFail(['id', 'source_id']);
        $followupIdentity = $reads->query(ChemicalExposureFollowup::query())->where('exposure_id', $root->id)
            ->where('assigned_to', $row->recipient_id)->whereKey($version->source_id)
            ->firstOrFail(['id', 'exposure_id', 'source_schema_version', 'source_revision']);
        $this->source->assertSourceIdentityBeforeBody($root, $followupIdentity, 'followup', $reads, $current, $sites);
        $followup = $reads->query(ChemicalExposureFollowup::query())->where('exposure_id', $root->id)
            ->where('assigned_to', $row->recipient_id)->whereKey($followupIdentity->id)
            ->where('source_schema_version', $followupIdentity->source_schema_version)
            ->where('source_revision', $followupIdentity->source_revision)->firstOrFail();
        $this->source->currentVersion($root, $followup, 'followup', $reads, $current, $sites);
        if ($followup->completed_at !== null) { throw new LogicException('This assigned follow-up is already completed.'); }
        $notification = $this->notificationId($id, $row->source_version_id, $row->recipient_id);
        return ['notification_id' => $notification, 'recipient_id' => $row->recipient_id, 'notifiable_type' => $current->getMorphClass(),
            'membership' => $membership, 'payload' => (new ChemicalExposureFollowupAssignedNotification($id))->toArray(null)];
    }

    /** Same user-over-role precedence as NotificationService, using locked current rows; database channel only. */
    private function inboxEnabled(User $current, CurrentAuthorizationReads $reads): bool
    {
        $override = $reads->query(\App\Models\UserNotificationPreference::query())
            ->where('user_id', $current->id)->where('key', self::EVENT_KEY)->first(['id', 'enabled', 'channel_inapp']);
        if ($override) { return $override->enabled && $override->channel_inapp; }
        // AuthorizationEvidenceLockService prepared the current role membership, not a stale caller relation.
        $roles = $current->roles->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($roles === [] && is_string($current->role) && $current->role !== '') {
            $legacy = $reads->query(\App\Models\Role::query())->where('name', $current->role)->first(['id']);
            if ($legacy) { $roles = [(int) $legacy->id]; }
        }
        if ($roles === []) { return true; }
        $preferences = $reads->query(\App\Models\RoleNotificationPreference::query())->whereIn('role_id', $roles)
            ->where('key', self::EVENT_KEY)->get(['id', 'enabled', 'channel_inapp']);
        return $preferences->isEmpty() || $preferences->contains(fn ($preference) => $preference->enabled && $preference->channel_inapp);
    }
    private function notificationId(int $id, int $version, int $recipient): string
    {
        $hex = hash('sha256', 'oblivion-private-followup-v1:'.$id.':'.$version.':'.$recipient);
        // Stable RFC UUID-shaped database identity, not a new request/acceptance identity.
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-5'.substr($hex, 13, 3).'-a'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }

    private function assertNotification(DatabaseNotification $row, array $descriptor): void
    {
        if ($row->id !== $descriptor['notification_id']
            || ChemicalCommandValues::digest($row->data) !== ChemicalCommandValues::digest($descriptor['payload'])
            || ($row->type !== ChemicalExposureFollowupAssignedNotification::class
                || $row->notifiable_type !== $descriptor['notifiable_type'] || (int) $row->notifiable_id !== $descriptor['recipient_id']
                || $row->created_at === null || $row->updated_at === null)) {
            throw new LogicException('Private follow-up destination identity or payload is damaged.');
        }
    }
}
