<?php

namespace App\Services\Medication;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationCovertAuthorisation;
use App\Models\MedicationFollowup;
use App\Models\MedicationOrderAction;
use App\Models\MedicationOrderFile;
use App\Models\MedicationOrderRevision;
use App\Models\MedicationOrderVersion;
use App\Models\MedicationReviewItem;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MarScheduleService;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\Followups\MedicationFollowupService;
use App\Services\Medication\Reviews\MedicationReviewOrderAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * P04 order management: Client -> order -> current staff/RBAC/Site -> revisions.
 * The canonical chart row is ALWAYS the in-effect checked prescription. A
 * proposal changes only immutable version evidence until explicitly checked.
 * Covering-shift or emergency authority is retained (Main's 3 Oct decision).
 */
final class MedicationOrderWorkflow
{
    public function __construct(
        private readonly MedicationGovernanceScopeService $scope,
        private readonly MedicationRecordAccess $access,
        private readonly OrderAllergyMatcher $allergies,
        private readonly WitnessPinService $pins,
        private readonly MedicationScopeDecisionService $workScope,
    ) {}

    public function enterFromRecommendation(User $actor, int $clientId, ?int $medicationId, int $itemId, array $input, ?UploadedFile $file): MedicationOrderRevision
    {
        return DB::transaction(function () use ($actor, $clientId, $medicationId, $itemId, $input, $file) {
            $client = Client::query()->whereKey($clientId)->lockForUpdate()->firstOrFail();
            $this->access->assertReadable($actor, $client);
            $item = app(MedicationReviewOrderAdapter::class)->lockRecommendation($actor, $client, $itemId, $input['request_key'] ?? null);
            if ($item->outcome === 'stop' || ($item->outcome === 'change' && (int) $medicationId !== (int) $item->client_medication_id)
                || (in_array($item->outcome, ['swap', 'start'], true) && $medicationId !== null)) {
                $this->invalid('review_item', 'Use the recommended medicine change, stop or separate replacement order.');
            }
            if ($item->client_medication_id !== null) {
                ClientMedication::query()->whereKey($item->client_medication_id)->where('client_id', $client->id)->lockForUpdate()->firstOrFail();
            }

            return $this->enter($actor, $clientId, $medicationId, $input, $file, $item);
        }, 5);
    }

    public function stopFromRecommendation(User $actor, int $medicationId, int $itemId, array $input): void
    {
        DB::transaction(function () use ($actor, $medicationId, $itemId, $input) {
            $submitted = ClientMedication::query()->findOrFail($medicationId);
            $client = Client::query()->whereKey($submitted->client_id)->lockForUpdate()->firstOrFail();
            $this->access->assertReadable($actor, $client);
            $key = 'review-stop:'.$itemId.':'.hash('sha256', (string) ($input['request_key'] ?? ''));
            $item = app(MedicationReviewOrderAdapter::class)->lockRecommendation($actor, $client, $itemId, $key);
            if ($item->outcome !== 'stop' || (int) $item->client_medication_id !== $medicationId) {
                $this->invalid('review_item', 'This recommendation is not an agreed stop for this medicine.');
            }
            $this->forMedication($actor, $medicationId, 'medications.orders.manage', function ($client, $order, $locked) use ($input, $item, $key) {
                $alreadyStopped = $order->state === 'ceased';
                $ended = app(MedicationOrderLifecycleService::class)->discontinue($locked, $order, $input['reason'] ?? null, submittedClientId: $client->id, requestKey: $key);
                $version = $ended->versions()->where('version_number', $ended->version)->where('client_id', $client->id)->firstOrFail();
                app(MedicationReviewOrderAdapter::class)->linkStoppedVersion($locked, $item, $version);
                if (! $alreadyStopped) {
                    $this->action($locked, $ended, 'stopped', ['version' => $ended->version, 'reason' => $ended->ceased_reason, 'review_item_id' => $item->id]);
                }
            }, expectedClientId: (int) $client->id);
        }, 5);
    }

    public function enter(User $actor, int $clientId, ?int $medicationId, array $input, ?UploadedFile $file = null, ?MedicationReviewItem $recommendation = null): MedicationOrderRevision
    {
        $paths = [];
        try {
            $callback = function (Client $client, ?ClientMedication $medication, User $actor, Collection $users) use ($input, $file, $recommendation, &$paths): MedicationOrderRevision {
                $this->access->assertReadable($actor, $client);
                if ($medication !== null) {
                    $this->assertControlled($actor, $medication);
                    $this->assertOpen($medication);
                }
                $source = $this->validateSource($actor, $client, $input, $users, $file);
                $payload = $this->validatePrescription($input['prescription'] ?? [], $medication);
                $inspection = $this->allergies->inspect($client, $payload['name']);
                $source['allergy_matches'] = $inspection['matches'];
                $source['class_matching'] = $inspection['class_matching'];
                $this->assertControlled($actor, (bool) ($payload['controlled_drug'] ?? false));
                if ($medication !== null && ((bool) $payload['controlled_drug'] !== (bool) $medication->controlled_drug
                    || OrderAllergyMatcher::normalise($payload['name']) !== OrderAllergyMatcher::normalise($medication->name))) {
                    $this->invalid('prescription.name', 'A different medicine is a new order. Stop the previous order separately. Controlled classification cannot change.');
                }
                $key = Validator::make($input, ['request_key' => 'required|string|max:100|regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'])->validate()['request_key'];
                $sourceForHash = $source;
                unset($sourceForHash['read_back_at']);
                $hash = hash('sha256', json_encode([$client->id, $medication?->id, $actor->id, $payload, $sourceForHash, $recommendation?->id, $input['stop_reason'] ?? null], JSON_THROW_ON_ERROR));
                $replay = MedicationOrderVersion::query()->where('entry_request_key', $key)->first();
                if ($replay !== null) {
                    if (! hash_equals((string) $replay->entry_payload_sha256, $hash)) {
                        $this->invalid('request_key', 'This saved request belongs to different order details. Reload before saving.');
                    }

                    return MedicationOrderRevision::query()->where('medication_order_version_id', $replay->id)->firstOrFail();
                }
                if ($medication === null) {
                    if ($recommendation?->outcome === 'swap') {
                        $stopReason = Validator::make($input, ['stop_reason' => 'required|string|max:255', 'confirm_swap' => 'required|accepted'])->validate()['stop_reason'];
                        $old = ClientMedication::query()->whereKey($recommendation->client_medication_id)->where('client_id', $client->id)->firstOrFail();
                        $this->assertControlled($actor, $old);
                        if (OrderAllergyMatcher::normalise($old->name) === OrderAllergyMatcher::normalise($payload['name'])) {
                            $this->invalid('prescription.name', 'A swap uses a separate replacement medicine. Use a change for the same medicine.');
                        }
                        $ended = app(MedicationOrderLifecycleService::class)->discontinue($actor, $old, $stopReason, submittedClientId: $client->id, requestKey: 'review-swap:'.$recommendation->id.':'.hash('sha256', $key));
                        app(MedicationReviewOrderAdapter::class)->linkStoppedVersion($actor, $recommendation, $ended->versions()->where('version_number', $ended->version)->where('client_id', $client->id)->firstOrFail());
                        $this->action($actor, $ended, 'stopped', ['version' => $ended->version, 'reason' => $stopReason, 'review_item_id' => $recommendation->id]);
                    }
                    $medication = ClientMedication::query()->create(array_merge($payload, [
                        'client_id' => $client->id, 'created_by' => $actor->id,
                        'active' => true, 'state' => 'active', 'version' => 1,
                    ]));
                    $number = 1;
                } else {
                    if ((int) ($input['expected_version'] ?? 0) !== (int) $medication->version) {
                        $this->invalid('expected_version', 'This order changed while you were working. Reload and compare the latest version.');
                    }
                    $pending = MedicationOrderRevision::query()->where('client_medication_id', $medication->id)
                        ->where('status', 'pending')->lockForUpdate()->exists();
                    if ($pending) {
                        $this->invalid('order', 'A version is already waiting to be checked. Check it or send it back first.');
                    }
                    if ($medication->approval_status === 'verified' && $this->samePrescription($payload, $this->payload($medication))) {
                        $this->invalid('prescription', 'Nothing changed. The checked order is still in use.');
                    }
                    $this->snapshotExisting($medication);
                    $number = max((int) $medication->version, (int) $medication->versions()->max('version_number')) + 1;
                }
                $version = $this->snapshot($medication, $payload, $number, $actor->id, $source, $input['change_reason'] ?? 'New order', $key, $hash);
                if ($recommendation !== null) {
                    app(MedicationReviewOrderAdapter::class)->linkVersion($actor, $recommendation, $version);
                }
                $revision = MedicationOrderRevision::query()->create([
                    'client_medication_id' => $medication->id, 'client_id' => $client->id,
                    'medication_order_version_id' => $version->id, 'base_version' => $medication->version,
                    'entered_by' => $actor->id, 'read_back_witness_id' => $source['read_back_witness_id'] ?? null,
                    'written_due_at' => $source['type'] === 'written' ? null : self::nextDayDeadline(),
                ]);
                if ($file !== null) {
                    $this->attach($revision, $file, 'source', $actor, $paths);
                }
                $this->action($actor, $medication, 'entered', ['version' => $number], $revision);
                $this->requestFollowup('order-check', $revision, $client, $medication, null);
                if ($revision->written_due_at !== null) {
                    $this->requestFollowup('phone-written-confirmation', $revision, $client, $medication, $revision->written_due_at);
                }

                return $revision;
            };
            $witnessIds = array_filter([(int) ($input['source']['witness_id'] ?? 0)]);

            return $medicationId === null
                ? $this->forClient($actor, $clientId, 'medications.orders.manage',
                    fn (Client $client, User $locked, Collection $users) => $callback($client, null, $locked, $users),
                    authorizationUserIds: $witnessIds)
                : $this->forMedication($actor, $medicationId, 'medications.orders.manage', $callback,
                    expectedClientId: $clientId, authorizationUserIds: $witnessIds);
        } catch (\Throwable $exception) {
            foreach ($paths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    public function check(User $actor, int $revisionId, array $input): MedicationOrderRevision
    {
        return $this->withRevision($actor, $revisionId, 'medications.orders.verify', function ($client, $medication, $revision, $actor) use ($input) {
            $this->assertOpen($medication);
            $second = ($input['mode'] ?? '') === 'second';
            if ($second) {
                if ($revision->status !== 'checked_alone' || $revision->second_checked_at !== null) {
                    $this->invalid('order', 'A second check is not pending.');
                }
                if (in_array((int) $actor->id, [(int) $revision->entered_by, (int) $revision->read_back_witness_id, (int) $revision->checked_by], true)) {
                    $this->invalid('checker', 'Someone who did not enter, witness or check this version must do the second check.');
                }
            } else {
                if ($revision->status !== 'pending') {
                    $this->invalid('order', 'Only a waiting version can be checked.');
                }
                if ((int) $medication->version !== (int) $revision->base_version) {
                    $this->invalid('order', 'The in-effect order changed. Send this version back and compare again.');
                }
            }
            $validated = Validator::make($input, [
                'source_matches' => 'required|accepted', 'dose_route_times_checked' => 'required|accepted',
                'allergies_interactions_checked' => 'required|accepted', 'lone_reason' => 'nullable|string|max:2000',
            ])->validate();
            $inspection = $this->allergies->inspect($client, $revision->version->prescription_payload['name']);
            if ($inspection['matches'] !== [] && ! hash_equals($inspection['match_sha256'], (string) data_get($revision->allergy_confirmation, 'match_sha256', ''))) {
                $this->invalid('allergy', 'The prescriber must confirm this version is safe for the current allergy matches before it can be checked.');
            }
            $lone = ! $second && ($input['mode'] ?? '') === 'alone';
            $involved = in_array((int) $actor->id, [(int) $revision->entered_by, (int) $revision->read_back_witness_id], true);
            if ($lone) {
                abort_unless($actor->canDo('medications.orders.manage'), 403);
                if ((int) $actor->id !== (int) $revision->entered_by || blank($validated['lone_reason'] ?? null)) {
                    $this->invalid('lone_reason', 'The lead who entered this version must explain why nobody else can check today.');
                }
            } elseif ($involved) {
                $this->invalid('checker', 'You entered this version or witnessed its read-back. Someone else checks it.');
            }
            if ($second) {
                $revision->forceFill(['second_checked_by' => $actor->id, 'second_checked_at' => now(), 'status' => 'checked'])->save();
            } else {
                $medication->publishCheckedPrescription($revision->version->prescription_payload, $revision->version->version_number, $actor->id);
                $revision->forceFill([
                    'status' => $lone ? 'checked_alone' : 'checked', 'checked_by' => $actor->id, 'checked_at' => now(),
                    'lone_reason' => $lone ? trim($validated['lone_reason']) : null,
                    'second_due_at' => $lone ? self::nextDayDeadline() : null,
                ])->save();
            }
            $this->action($actor, $medication, $second ? 'second_checked' : ($lone ? 'checked_alone' : 'checked'), [
                'source_matches' => true, 'dose_route_times_checked' => true, 'allergies_interactions_checked' => true,
                'class_matching' => $inspection['class_matching'], 'lone_reason' => $lone ? $validated['lone_reason'] : null,
            ], $revision);
            if ($lone) {
                $this->requestFollowup('second-check', $revision, $client, $medication, $revision->second_due_at);
            }
            app(MedicationReconciliationWorkflow::class)->refreshRespiteForClient($client, $actor);

            return $revision;
        });
    }

    public function sendBack(User $actor, int $id, string $reason): MedicationOrderRevision
    {
        return $this->withRevision($actor, $id, 'medications.orders.verify', function ($client, $medication, $revision, $actor) use ($reason) {
            $this->assertOpen($medication);
            if ($revision->status !== 'pending') {
                $this->invalid('order', 'Only a waiting version can be sent back.');
            }
            if (in_array((int) $actor->id, [(int) $revision->entered_by, (int) $revision->read_back_witness_id], true)) {
                $this->invalid('checker', 'Someone else checks or sends back this version.');
            }
            $reason = $this->reason($reason);
            $revision->forceFill(['status' => 'sent_back', 'rejection_reason' => $reason])->save();
            if ($medication->approval_status !== 'verified') {
                $medication->forceFill(['approval_status' => 'rejected', 'rejection_reason' => $reason])->save();
            }
            $this->action($actor, $medication, 'sent_back', ['reason' => $reason], $revision);

            return $revision;
        });
    }

    public function confirmAllergy(User $actor, int $id, array $input): MedicationOrderRevision
    {
        $input = $this->normaliseInstant($input, 'confirmed_at');

        return $this->withRevision($actor, $id, 'medications.orders.manage', function ($client, $medication, $revision, $actor) use ($input) {
            $this->assertOpen($medication);
            if (! in_array($revision->status, ['pending', 'checked', 'checked_alone'], true)) {
                $this->invalid('order', 'This version cannot receive a prescriber confirmation.');
            }
            $evidence = Validator::make($input, [
                'prescriber' => 'required|string|max:255', 'method' => 'required|in:phone,written,in_person',
                'confirmed_at' => 'required|date|before_or_equal:now', 'instruction' => 'required|string|max:4000',
            ])->validate();
            $inspection = $this->allergies->inspect($client, $revision->version->prescription_payload['name']);
            if ($inspection['matches'] === []) {
                $this->invalid('allergy', 'No matching recorded allergy was found on this version.');
            }
            $evidence += ['recorded_by' => $actor->id, 'recorded_at' => now()->toIso8601String(), 'match_sha256' => $inspection['match_sha256'], 'matches' => $inspection['matches']];
            $revision->forceFill(['allergy_confirmation' => $evidence])->save();
            $this->action($actor, $medication, 'prescriber_confirmed_allergy', $evidence, $revision);

            return $revision;
        });
    }

    public function confirmWritten(User $actor, int $id, array $input, UploadedFile $file): MedicationOrderRevision
    {
        $input = $this->normaliseInstant($input, 'received_at');
        $paths = [];
        try {
            return $this->withRevision($actor, $id, 'medications.orders.manage', function ($client, $medication, $revision, $actor) use ($input, $file, &$paths) {
                if ($revision->written_due_at === null || $revision->written_confirmation !== null) {
                    $this->invalid('order', 'Written confirmation is not pending.');
                }
                $evidence = Validator::make($input, [
                    'method' => 'required|in:signed_prescription,email,e_prescription',
                    'received_at' => 'required|date|before_or_equal:now', 'matches' => 'required|accepted',
                ])->validate();
                $saved = $this->attach($revision, $file, 'written_confirmation', $actor, $paths);
                $evidence += ['file_id' => $saved->id, 'recorded_by' => $actor->id, 'recorded_at' => now()->toIso8601String()];
                $revision->forceFill(['written_confirmation' => $evidence])->save();
                $this->action($actor, $medication, 'written_confirmed', $evidence, $revision);

                return $revision;
            });
        } catch (\Throwable $exception) {
            foreach ($paths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    public function withRevision(User $actor, int $id, string $capability, \Closure $callback): mixed
    {
        $submitted = MedicationOrderRevision::query()->findOrFail($id);

        return $this->forMedication($actor, $submitted->client_medication_id, $capability,
            function (Client $client, ClientMedication $medication, User $actor) use ($id, $callback) {
                $this->access->assertReadable($actor, $client);
                $this->assertControlled($actor, $medication);
                $revision = MedicationOrderRevision::query()->whereKey($id)->where('client_id', $client->id)
                    ->where('client_medication_id', $medication->id)->lockForUpdate()->firstOrFail();
                $revision->load('version');
                abort_unless((int) $revision->version->client_id === (int) $client->id
                    && (int) $revision->version->client_medication_id === (int) $medication->id, 404);

                return $callback($client, $medication, $revision, $actor);
            }, expectedClientId: $submitted->client_id);
    }

    public function payload(ClientMedication $medication): array
    {
        return $medication->only(array_diff(ClientMedication::verificationSensitiveFields(), ['client_id', 'created_by']));
    }

    public function forClient(User $actor, int $clientId, string $capability, \Closure $callback, array $authorizationUserIds = [], ?\Closure $afterWrites = null): mixed
    {
        abort_unless($actor->canDo($capability), 403);

        return DB::transaction(function () use ($actor, $clientId, $capability, $callback, $authorizationUserIds, $afterWrites) {
            $marker = 0;
            $result = $this->workScope->forClient($actor, $clientId, now(), function (MedicationScopeDecision $decision) use ($actor, $clientId, $capability, $callback, $authorizationUserIds, &$marker) {
                $marker = (int) MedicationOrderAction::query()->max('id');
                $result = $this->scope->forClient($actor, $clientId, $capability, $callback,
                    authorizationUserIds: $authorizationUserIds, lockPresence: false);
                $this->workScope->recordBreakGlassUse($decision, 'managed_medication_order', 'P04 order workflow');

                return $result;
            });
            $actions = MedicationOrderAction::query()->where('id', '>', $marker)->whereIn('client_medication_id', ClientMedication::query()->where('client_id', $clientId)->select('id'))->orderBy('id')->get();
            if ($afterWrites !== null) {
                $afterWrites($result);
            }
            $this->finishActions($actions);

            return $result;
        }, 5);
    }

    public function forMedication(User $actor, int $medicationId, string $capability, \Closure $callback, ?int $expectedClientId = null, array $authorizationUserIds = []): mixed
    {
        abort_unless($actor->canDo($capability), 403);
        $submitted = ClientMedication::query()->findOrFail($medicationId);

        return DB::transaction(function () use ($actor, $submitted, $medicationId, $capability, $callback, $expectedClientId, $authorizationUserIds) {
            $marker = 0;
            $result = $this->workScope->forMedication($actor, $submitted, now(), function (MedicationScopeDecision $decision) use ($actor, $medicationId, $capability, $callback, $expectedClientId, $authorizationUserIds, &$marker) {
                $marker = (int) MedicationOrderAction::query()->max('id');
                $result = $this->scope->forMedication($actor, $medicationId, $capability, $callback,
                    expectedClientId: $expectedClientId, authorizationUserIds: $authorizationUserIds);
                $this->workScope->recordBreakGlassUse($decision, 'managed_medication_order', 'Medication '.$medicationId.' P04');

                return $result;
            }, submittedClientId: $expectedClientId, allowCeased: true);
            $this->finishActions(MedicationOrderAction::query()->where('id', '>', $marker)->where('client_medication_id', $medicationId)->orderBy('id')->get());

            return $result;
        }, 5);
    }

    private function requestFollowup(string $source, MedicationOrderRevision $revision, Client $client, ClientMedication $medication, $dueAt): void
    {
        app(MedicationFollowupService::class)->ensureForSource($source, $revision->id, $client, $medication, null, null, $dueAt,
            ['order_revision_id' => $revision->id, 'version' => $revision->version->version_number, 'source_url' => '/emar/prescriptions?order_id='.$medication->id]);
    }

    /** Covert evidence retains the existing office governance boundary. */
    public function forOfficeMedication(User $actor, int $medicationId, \Closure $callback): mixed
    {
        return DB::transaction(function () use ($actor, $medicationId, $callback) {
            $marker = 0;
            $result = $this->scope->forMedication($actor, $medicationId, 'medications.orders.manage', function ($client, $order, $locked) use ($callback, &$marker) {
                $marker = (int) MedicationOrderAction::query()->max('id');

                return $callback($client, $order, $locked);
            });
            $this->finishActions(MedicationOrderAction::query()->where('id', '>', $marker)->where('client_medication_id', $medicationId)->orderBy('id')->get());

            return $result;
        }, 5);
    }

    /** Domain writes, locks and break-glass evidence have all finished. */
    public function finishActions(Collection $actions): void
    {
        if ($actions->isEmpty()) {
            return;
        }
        $events = [];
        $completions = [];
        foreach ($actions as $action) {
            $medication = ClientMedication::query()->with('client')->findOrFail($action->client_medication_id);
            $events[] = new MedicationEventData(siteId: (int) $medication->client->site_id, kind: 'order.'.$action->action,
                subjectType: 'medication_order_action', subjectId: (string) $action->id, actorId: (int) $action->actor_id,
                occurredAt: CarbonImmutable::instance($action->occurred_at), summary: 'Medication order '.$action->action.'.',
                facts: ['order_id' => $medication->id, 'order_revision_id' => $action->medication_order_revision_id, ...$action->evidence],
                clientId: (int) $medication->client_id, controlled: (bool) $medication->controlled_drug);
            $source = match ($action->action) {
                'checked', 'checked_alone', 'sent_back' => 'order-check',
                'second_checked' => 'second-check', 'written_confirmed' => 'phone-written-confirmation', default => null,
            };
            if ($source !== null) {
                $completions[$source.':'.$action->medication_order_revision_id] = $action;
            }
            if ($action->action === 'sent_back') {
                $completions['phone-written-confirmation:'.$action->medication_order_revision_id] = $action;
            }
            if ($action->action === 'stopped') {
                foreach (MedicationOrderRevision::query()->where('client_medication_id', $medication->id)->where('client_id', $medication->client_id)->get() as $revision) {
                    $completions['order-check:'.$revision->id] = $action;
                    $completions['second-check:'.$revision->id] = $action;
                }
            }
            if (in_array($action->action, ['covert_authorised', 'covert_revoked'], true)) {
                $endedIds = MedicationCovertAuthorisation::query()->where('client_medication_id', $medication->id)->where('client_id', $medication->client_id)->where('status', 'revoked')->pluck('id');
                foreach ($endedIds as $id) {
                    $completions['covert-review:'.$id] = $action;
                }
            }
        }
        // Prelock the workflow set in ID order and collect its neutral events.
        // The event recorder takes head locks once, after every domain write.
        $rows = MedicationFollowup::query()->whereIn('source_key', array_keys($completions))->whereNull('completed_at')->orderBy('id')->lockForUpdate()->get();
        foreach ($rows as $row) {
            $action = $completions[$row->source_key];
            app(MedicationFollowupService::class)->completeFromSource($row->source_key, User::findOrFail($action->actor_id), $action->action,
                ['order_revision_id' => $action->medication_order_revision_id, 'order_action_id' => $action->id], $events);
        }
        app(MedicationEventRecorder::class)->appendMany($events);
    }

    public function snapshotExisting(ClientMedication $medication): void
    {
        if ($medication->versions()->where('version_number', $medication->version)->exists()) {
            return;
        }
        $version = $this->snapshot($medication, $this->payload($medication), (int) $medication->version, $medication->created_by, ['type' => 'legacy', 'description' => 'Existing chart entry; original source not attached here'], 'Existing chart snapshot');
        MedicationOrderRevision::query()->create([
            'client_id' => $medication->client_id, 'client_medication_id' => $medication->id,
            'medication_order_version_id' => $version->id, 'base_version' => $medication->version,
            'entered_by' => $medication->created_by,
            'status' => $medication->approval_status === 'verified' ? 'checked' : 'sent_back',
            'checked_by' => $medication->verified_by, 'checked_at' => $medication->verified_at,
        ]);
    }

    public function snapshot(ClientMedication $medication, array $payload, int $number, ?int $actorId, array $source, string $reason, ?string $key = null, ?string $hash = null): MedicationOrderVersion
    {
        $snapshot = array_intersect_key($payload, array_flip((new MedicationOrderVersion)->getFillable()));
        // The immutable old schema uses the shorter name; the JSON retains
        // every prescription field, including codes and monitoring context.
        $snapshot['min_hours_between_doses'] = $payload['min_hours_between_doses'] ?? null;

        return MedicationOrderVersion::query()->create(array_merge($snapshot, [
            'client_id' => $medication->client_id, 'client_medication_id' => $medication->id,
            'version_number' => $number, 'prescription_payload' => $payload, 'source_evidence' => $source,
            'state' => $medication->state, 'active' => $medication->active,
            'changed_by' => $actorId, 'changed_at' => now(), 'change_reason' => mb_substr($reason, 0, 255),
            'entry_request_key' => $key, 'entry_payload_sha256' => $hash,
        ]));
    }

    public function action(User $actor, ClientMedication $medication, string $action, array $evidence, ?MedicationOrderRevision $revision = null): void
    {
        MedicationOrderAction::query()->create([
            'client_medication_id' => $medication->id, 'medication_order_revision_id' => $revision?->id,
            'action' => $action, 'actor_id' => $actor->id, 'occurred_at' => now(), 'evidence' => $evidence,
        ]);
        AuditLogger::logOrFail('medication_order.'.$action, $medication, $evidence + [
            'actor_id' => $actor->id, 'client_id' => $medication->client_id, 'revision_id' => $revision?->id,
        ]);
    }

    public function assertControlled(User $actor, ClientMedication|bool $medication): void
    {
        $controlled = is_bool($medication) ? $medication : (bool) $medication->controlled_drug;
        abort_if($controlled && (! $actor->canDo('medications.controlled.view') || ! $actor->canDo('medications.controlled.record')), 404);
    }

    public function assertOpen(ClientMedication $medication): void
    {
        if ($medication->state === 'ceased' || $medication->ceased_at !== null) {
            $this->invalid('order', 'This order is stopped. Enter a new order if the prescriber restarts it.');
        }
    }

    public static function nextDayDeadline(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.worker_timezone', 'Pacific/Auckland'))->addDay()->endOfDay()->utc();
    }

    public function reason(string $reason): string
    {
        return trim(Validator::make(['reason' => trim($reason)], ['reason' => 'required|string|max:2000'])->validate()['reason']);
    }

    private function validateSource(User $actor, Client $client, array $input, Collection $users, ?UploadedFile $file): array
    {
        $sourceInput = $this->normaliseInstant($input['source'] ?? [], 'received_at');
        $source = Validator::make($sourceInput, [
            'type' => 'required|in:written,phone,verbal', 'prescriber' => 'required|string|max:255',
            'received_at' => 'required|date|before_or_equal:now', 'description' => 'nullable|string|max:4000',
            'read_back_confirmed' => 'required_if:type,phone,verbal|accepted',
            'witness_id' => 'required_if:type,phone,verbal|integer',
            'witness_pin' => 'required_if:type,phone,verbal|string|size:6',
        ])->validate();
        if ($source['type'] === 'written' && $file === null) {
            $this->invalid('source_file', 'Attach the written prescription before saving.');
        }
        if ($source['type'] !== 'written') {
            $witness = $users->get((int) $source['witness_id']);
            if (! $witness instanceof User || (int) $witness->id === (int) $actor->id
                || $witness->approved_at === null || ! $witness->canDo('medications.view')
                || ! app(HrCurrentStaffService::class)->isCurrent($witness)
                || ! in_array((int) $client->site_id, $this->scope->readerSiteIds($witness, 'medications.view'), true)) {
                $this->invalid('source.witness_pin', 'Choose a different authorised colleague who heard the read-back.');
            }
            $this->pins->verify($witness, $source['witness_pin'], 'source.witness_pin', ['site_id' => $client->site_id, 'surface' => 'order_read_back', 'actor_id' => $actor->id]);
            $source['read_back_witness_id'] = $witness->id;
            $source['read_back_at'] = now()->toIso8601String();
        }
        unset($source['witness_pin'], $source['witness_id']);
        $source['received_at'] = CarbonImmutable::parse($source['received_at'])->utc()->toIso8601String();
        $source['file_sha256'] = $file !== null ? hash_file('sha256', $file->getRealPath()) : null;

        return $source;
    }

    private function validatePrescription(array $input, ?ClientMedication $medication): array
    {
        $validated = Validator::make($input, [
            'name' => 'required|string|max:255', 'dosage' => 'required|string|max:100',
            'dose_amount' => 'nullable|numeric|gt:0', 'dose_unit' => 'nullable|string|max:50',
            'frequency' => 'required|string|max:100', 'frequency_code' => 'nullable|string|max:50',
            'dose_times' => 'nullable|array|max:24', 'dose_times.*' => 'required|date_format:H:i|distinct',
            'is_prn' => 'required|boolean', 'route' => 'required|string|max:50', 'form' => 'nullable|string|max:50',
            'prn_reason' => 'required_if:is_prn,1|nullable|string|max:500',
            'max_per_day' => 'required_if:is_prn,1|nullable|integer|min:1',
            'min_hours_between_doses' => 'required_if:is_prn,1|nullable|numeric|min:0|max:168',
            'start_date' => 'required|date_format:Y-m-d', 'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'instructions' => 'nullable|string|max:4000', 'indication' => 'required|string|max:500',
            'prescriber' => 'nullable|string|max:255', 'pharmacy' => 'nullable|string|max:255',
            'controlled_drug' => 'required|boolean', 'cd_schedule' => 'nullable|integer|min:1|max:5',
            'high_risk' => 'nullable|boolean', 'witness_required' => 'nullable|boolean',
            'pharmac_therapeutic_group' => 'nullable|string|max:255', 'pharmac_subgroup' => 'nullable|string|max:255',
            'review_date' => 'nullable|date_format:Y-m-d', 'barcode' => 'nullable|string|max:255', 'nzulm_code' => 'nullable|string|max:255',
        ])->validate();
        $payload = array_merge($medication !== null ? $this->payload($medication) : [], $validated);
        if (! $payload['is_prn'] && empty($payload['dose_times'])) {
            $this->invalid('prescription.dose_times', 'Enter the scheduled times from the prescription.');
        }

        return $payload;
    }

    private function samePrescription(array $a, array $b): bool
    {
        $normalise = function (array $payload): array {
            foreach ($payload as $key => &$value) {
                if ($value instanceof \DateTimeInterface) {
                    $value = $value->format('Y-m-d');
                }
                if (is_array($value)) {
                    sort($value);
                }
                if ($value === '') {
                    $value = null;
                }
                if (is_numeric($value)) {
                    $value = (float) $value;
                }
            }
            ksort($payload);

            return $payload;
        };

        return $normalise(array_merge($b, $a)) == $normalise($b);
    }

    private function attach(MedicationOrderRevision $revision, UploadedFile $file, string $purpose, User $actor, array &$paths): MedicationOrderFile
    {
        Validator::make(['file' => $file], ['file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240'])->validate();
        $path = $file->store('medication-orders/'.$revision->id, 'local');
        if (! is_string($path)) {
            throw new \RuntimeException('The prescription file could not be saved.');
        }
        $paths[] = $path;

        return MedicationOrderFile::query()->create([
            'medication_order_revision_id' => $revision->id, 'purpose' => $purpose,
            'file_name' => $file->getClientOriginalName(), 'file_path' => $path, 'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by' => $actor->id, 'created_at' => now(),
        ]);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function normaliseInstant(array $input, string $key): array
    {
        if (! is_string($input[$key] ?? null) || trim($input[$key]) === '') {
            return $input;
        }
        try {
            $input[$key] = app(MarScheduleService::class)->parseWorkerDateTime($input[$key])->utc()->toIso8601String();
        } catch (\Throwable) {
            $this->invalid($key, 'Enter a valid date and time in Pacific/Auckland.');
        }

        return $input;
    }
}
