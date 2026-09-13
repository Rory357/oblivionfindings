<?php

namespace App\Domain\It\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Enums\ItTicketDraftPurpose;
use App\Models\ItAttachment;
use App\Models\ItCatalogItem;
use App\Models\ItCatalogSubmission;
use App\Models\ItProvisioningRequest;
use App\Models\ItProvisioningTemplateVersion;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ItCatalogSubmissionService
{
    public function __construct(
        private readonly ItTicketIntakeService $intake,
        private readonly ItWorkAccessService $workAccess,
        private readonly ItProvisioningAccessService $provisioningAccess,
        private readonly ItCatalogFieldOptionService $fieldOptions,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{submission: ItCatalogSubmission, result: Model, created: bool}|array{cancelled: true, catalog_item_id: int, request_uuid: string}
     */
    public function submit(ItCatalogItem $catalogItem, User $actor, array $input): array
    {
        Validator::make($input, ['requested_for_user_id' => ['sometimes', 'required', 'integer', 'min:1']])->validate();

        return (new ItAttachmentWriteContext)->transaction(function (ItAttachmentWriteContext $attachmentContext) use ($catalogItem, $actor, $input): array {
            // Serialize the actor's global submission key, including competing
            // submissions for different catalogue items.
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->approved_at !== null && ($actor->canDo('it.request') || $actor->canDo('it.manage')), 403);
            $existing = ItCatalogSubmission::query()
                ->where('requester_user_id', $actor->id)
                ->where('idempotency_key', (string) $input['idempotency_key'])
                ->first();
            if ($existing) {
                $result = $this->visibleResult($existing, $actor);
                $matches = $existing->input_sha256 !== null
                    ? hash_equals($existing->input_sha256, $this->inputHash($catalogItem->id, $input))
                    : ((int) $existing->schema_version === (int) $input['schema_version']
                        && $this->canonicalJson($existing->submitted_values) === $this->canonicalJson($input['values'] ?? []));
                if ((int) $existing->catalog_item_id !== (int) $catalogItem->id || ! $matches) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'That submission key has already been used with different request details. Restore the original request or start a new one.',
                    ]);
                }

                return [
                    'submission' => $existing,
                    'result' => $result,
                    'created' => false,
                ];
            }

            if ($cancelled = $this->cancelledOutcome($catalogItem->id, $actor, (string) $input['idempotency_key'])) {
                return $cancelled;
            }

            $item = ItCatalogItem::query()->whereKey($catalogItem->id)->published()->lockForUpdate()->firstOrFail()->publishedContract();
            abort_unless(app(ItCatalogAccessService::class)->canDiscover($actor, $item), 404);

            if ((int) $input['schema_version'] !== (int) $item->form_schema_version) {
                throw ValidationException::withMessages([
                    'schema_version' => 'This request form has changed. Refresh it before submitting.',
                ]);
            }

            $stagedFiles = app(ItTicketDraftAttachmentService::class)->catalogueFiles($actor, $input, (int) $item->id, (int) $item->form_schema_version);
            $validated = $this->validateValues(
                $item,
                (array) ($input['values'] ?? []),
                $actor,
                $stagedFiles,
            );
            $values = $validated['values'];
            $result = match ($item->outcome_type) {
                'service_request', 'security_request' => $this->createTicket(
                    $item,
                    $actor,
                    $values,
                    $validated['display_values'],
                    isset($input['site_id']) ? (int) $input['site_id'] : null,
                    isset($input['requested_for_user_id']) ? (int) $input['requested_for_user_id'] : null,
                    $attachmentContext,
                ),
                'provisioning' => $this->createProvisioning(
                    $item,
                    $actor,
                    $values,
                    $validated['display_values'],
                    isset($input['requested_for_user_id']) ? (int) $input['requested_for_user_id'] : null,
                    (string) $input['idempotency_key'],
                ),
                default => throw ValidationException::withMessages([
                    'catalog_item' => 'This catalogue item has an unsupported outcome.',
                ]),
            };

            $submission = ItCatalogSubmission::query()->create([
                'catalog_item_id' => $item->id,
                'requester_user_id' => $actor->id,
                'schema_version' => $item->form_schema_version,
                'schema_snapshot' => $item->form_schema,
                'submitted_values' => $values,
                'idempotency_key' => (string) $input['idempotency_key'],
                'result_type' => $result->getMorphClass(),
                'result_id' => $result->getKey(),
                'submitted_at' => now(),
                'catalog_version_id' => $item->published_version_id,
                'contract_snapshot' => $item->only(ItCatalogItem::CONTRACT_FIELDS),
                'input_sha256' => $this->inputHash($item->id, $input),
            ]);

            app(ItCatalogAttachmentService::class)->store($submission, $actor, $validated['attachment_fields'], $attachmentContext);
            app(ItTicketDraftService::class)->consumeFromInput($actor, $input, ItTicketDraftPurpose::CatalogueRequest,
                requestUuid: (string) $input['idempotency_key'], attachmentTarget: $submission);

            return ['submission' => $submission, 'result' => $result, 'created' => true];
        });
    }

    /**
     * Read the canonical committed submission without resending its fields or
     * triggering fulfilment. The actor mutex waits for an in-flight submit.
     *
     * @return array{submission: ItCatalogSubmission, result: Model, created: bool}|array{cancelled: true, catalog_item_id: int, request_uuid: string}|null
     */
    public function recover(int $catalogItemId, User $actor, string $key, int $originalActorId): ?array
    {
        abort_unless(Str::isUuid($key), 422);

        return DB::transaction(function () use ($catalogItemId, $actor, $key, $originalActorId): ?array {
            $actor = $this->lockedCommandActor($actor, $originalActorId);
            $submission = ItCatalogSubmission::query()
                ->where('requester_user_id', $actor->id)
                ->where('idempotency_key', $key)
                ->lockForUpdate()->first();
            if ($submission) {
                abort_unless((int) $submission->catalog_item_id === $catalogItemId, 404);

                return ['submission' => $submission, 'result' => $this->visibleResult($submission, $actor), 'created' => false];
            }

            if ($cancelled = $this->cancelledOutcome($catalogItemId, $actor, $key)) {
                return $cancelled;
            }

            // Absence is actionable only while the original item is currently
            // available. A withdrawn form cannot invite a new submission.
            $item = ItCatalogItem::query()->whereKey($catalogItemId)->published()
                ->lockForUpdate()->firstOrFail()->publishedContract();
            abort_unless(app(ItCatalogAccessService::class)->canDiscover($actor, $item), 404);

            return null;
        });
    }

    /** Cancel only an uncommitted identity; completed work always wins. */
    public function cancel(int $catalogItemId, User $actor, string $key, int $originalActorId): array
    {
        abort_unless(Str::isUuid($key), 422);

        return DB::transaction(function () use ($catalogItemId, $actor, $key, $originalActorId): array {
            $actor = $this->lockedCommandActor($actor, $originalActorId);
            $submission = ItCatalogSubmission::query()->where('requester_user_id', $actor->id)
                ->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($submission) {
                abort_unless((int) $submission->catalog_item_id === $catalogItemId, 404);

                return ['submission' => $submission, 'result' => $this->visibleResult($submission, $actor), 'created' => false];
            }
            if ($cancelled = $this->cancelledOutcome($catalogItemId, $actor, $key)) {
                return $cancelled;
            }

            // A withdrawn publication can still have an in-flight request.
            // Check its retained audience before cancelling the identity;
            // unpublished author drafts never become requester-visible here.
            $item = ItCatalogItem::withTrashed()->whereKey($catalogItemId)
                ->whereNotNull('published_version_id')->lockForUpdate()->firstOrFail()->retainedPublishedContract();
            abort_unless(app(ItCatalogAccessService::class)->canDiscover($actor, $item), 404);
            $receipt = ItTicketCommandReceipt::query()->create([
                'actor_user_id' => $actor->id,
                'channel' => ItTicketCommandReceipt::CHANNEL,
                'operation' => ItTicketCommandReceipt::CATALOGUE_OPERATION,
                'request_uuid' => $key,
                'request_hash' => hash('sha256', 'cancelled:'.$catalogItemId.':'.$key),
                'result_metadata' => ['state' => 'cancelled', 'catalog_item_id' => $catalogItemId,
                    'cancelled_at' => now()->toIso8601String()],
            ]);
            AuditLogger::logOrFail('it.catalogue.submission.cancelled', $receipt, [
                'actor_id' => $actor->id, 'catalog_item_id' => $catalogItemId,
            ]);

            return ['cancelled' => true, 'catalog_item_id' => $catalogItemId, 'request_uuid' => $key];
        });
    }

    private function lockedCommandActor(User $actor, int $originalActorId): User
    {
        $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
        abort_unless((int) $actor->id === $originalActorId && $actor->isApproved()
            && ($actor->canDo('it.request') || $actor->canDo('it.manage')), 403);

        return $actor;
    }

    private function cancelledOutcome(int $itemId, User $actor, string $key): ?array
    {
        if (! Str::isUuid($key)) {
            return null;
        }
        $receipt = ItTicketCommandReceipt::query()->where('actor_user_id', $actor->id)
            ->where('channel', ItTicketCommandReceipt::CHANNEL)
            ->where('operation', ItTicketCommandReceipt::CATALOGUE_OPERATION)
            ->where('request_uuid', $key)->lockForUpdate()->first();
        if (! $receipt) {
            return null;
        }
        $metadata = $receipt->result_metadata ?? [];
        abort_unless(($metadata['catalog_item_id'] ?? null) === $itemId, 404);
        abort_unless(($metadata['state'] ?? null) === 'cancelled' && $receipt->committed_at === null
            && is_string($metadata['cancelled_at'] ?? null), 503, 'The request outcome cannot yet be confirmed.');

        return ['cancelled' => true, 'catalog_item_id' => $itemId, 'request_uuid' => $key];
    }

    private function visibleResult(ItCatalogSubmission $submission, User $actor): Model
    {
        $result = $submission->result()->firstOrFail();
        $visible = $result instanceof ItTicket
            ? $this->workAccess->canView($actor, $result)
            : ($result instanceof ItProvisioningRequest && ($this->provisioningAccess->canView($actor, $result)
                || $this->provisioningAccess->canTrack($actor, $result)));
        abort_unless($visible, 404);

        return $result;
    }

    private function inputHash(int $itemId, array $input): string
    {
        return hash('sha256', $this->canonicalJson([
            'catalog_item_id' => $itemId,
            'schema_version' => (int) $input['schema_version'],
            'values' => $input['values'] ?? [],
            ...(isset($input['draft_uuid']) ? [
                'draft_uuid' => $input['draft_uuid'], 'draft_revision' => (int) ($input['draft_revision'] ?? -1),
                'draft_actor_user_id' => (int) ($input['draft_actor_user_id'] ?? 0),
                'staged_attachment_ids' => array_map('intval', $input['staged_attachment_ids'] ?? []),
            ] : []),
            ...(array_key_exists('site_id', $input) ? ['site_id' => isset($input['site_id']) ? (int) $input['site_id'] : null] : []),
            ...(array_key_exists('requested_for_user_id', $input) ? ['requested_for_user_id' => isset($input['requested_for_user_id']) ? (int) $input['requested_for_user_id'] : null] : []),
        ]));
    }

    private function canonicalJson(mixed $value): string
    {
        $normalise = function (mixed $value) use (&$normalise): mixed {
            if ($value instanceof UploadedFile) {
                return ItCatalogAttachmentService::fingerprint($value);
            }
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($normalise, $value);
        };

        return json_encode($normalise($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{values: array<string, mixed>, display_values: array<string, mixed>}
     */
    private function validateValues(ItCatalogItem $item, array $values, User $actor, array $stagedFiles = []): array
    {
        $fields = collect($item->form_schema['fields'] ?? [])
            ->filter(fn (mixed $field) => is_array($field) && isset($field['key']))
            ->keyBy(fn (array $field) => (string) $field['key']);

        foreach ($stagedFiles as $key => $files) {
            if (($fields->get($key)['type'] ?? null) !== 'attachment') {
                abort(404);
            }
            if (isset($values[$key]) && ! is_array($values[$key])) {
                throw ValidationException::withMessages(['values.'.$key => 'Choose files for this field.']);
            }
            $values[$key] = [...($values[$key] ?? []), ...$files];
        }
        $unknown = array_diff(array_keys($values), $fields->keys()->all());
        if ($unknown !== []) {
            throw ValidationException::withMessages(collect($unknown)->mapWithKeys(
                fn (string $key) => ["values.{$key}" => 'This field is not part of the published request form.'],
            )->all());
        }

        $rules = [];
        $labels = [];
        foreach ($fields as $key => $field) {
            $visibility = $field['visibility'] ?? 'requester';
            if (! $actor->canDo('it.manage') && in_array($visibility, ['internal', 'restricted'], true)) {
                if (array_key_exists($key, $values)) {
                    throw ValidationException::withMessages([
                        "values.{$key}" => 'This field is reserved for IT staff.',
                    ]);
                }

                continue;
            }

            $rules["values.{$key}"] = $this->rulesForField($field);
            if (($field['type'] ?? null) === 'multiselect') {
                $rules["values.{$key}.*"] = [Rule::in((array) ($field['options'] ?? []))];
            }
            if (($field['type'] ?? null) === 'attachment') {
                $rules["values.{$key}.*"] = ['required', function ($attribute, $value, $fail) use ($stagedFiles, $key): void {
                    if ($value instanceof ItAttachment && in_array($value, $stagedFiles[$key] ?? [], true)) {
                        return;
                    }
                    $validation = Validator::make(['attachment' => $value], ['attachment' => ItAttachment::uploadRules()]);
                    if ($validation->fails()) {
                        $fail($validation->errors()->first('attachment'));
                    }
                }];
            }
            $labels["values.{$key}"] = (string) ($field['label'] ?? $key);
        }

        $validated = Validator::make(['values' => $values], $rules, [], $labels)->validate();

        $clean = collect($validated['values'] ?? [])
            ->only($fields->keys()->all())
            ->all();
        $displayValues = $clean;
        $attachmentFields = [];
        foreach ($fields as $key => $field) {
            if (($field['type'] ?? null) !== 'attachment' || empty($clean[$key])) {
                continue;
            }
            $allFiles = array_values($clean[$key]);
            $attachmentFields[$key] = array_values(array_filter($allFiles, fn ($file) => $file instanceof UploadedFile));
            $clean[$key] = $displayValues[$key] = array_map(
                fn (UploadedFile|ItAttachment $file): string => $file instanceof ItAttachment ? $file->original_name : $file->getClientOriginalName(), $allFiles);
        }
        if (array_sum(array_map('count', $attachmentFields)) + array_sum(array_map('count', $stagedFiles)) > 5) {
            throw ValidationException::withMessages(['values' => 'Attach no more than five files across this request.']);
        }
        $errors = [];
        foreach ($fields as $key => $field) {
            $type = (string) ($field['type'] ?? 'text');
            if (! in_array($type, ItCatalogFieldOptionService::TYPES, true)
                || ! array_key_exists($key, $clean)
                || $clean[$key] === null
                || $clean[$key] === '') {
                continue;
            }

            $option = $this->fieldOptions->find($actor, $type, (int) $clean[$key]);
            if (! is_array($option)) {
                $errors["values.{$key}"] = 'This choice is no longer available to you.';

                continue;
            }
            $displayValues[$key] = trim(implode(' — ', array_filter([
                $option['name'] ?? null,
                $option['detail'] ?? null,
            ])));
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['values' => $clean, 'display_values' => $displayValues, 'attachment_fields' => $attachmentFields];
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<int, mixed>
     */
    private function rulesForField(array $field): array
    {
        $rules = [($field['required'] ?? false) ? 'required' : 'nullable'];
        $type = (string) ($field['type'] ?? 'text');
        $rules[] = match ($type) {
            'integer', 'user', 'asset', 'employee' => 'integer',
            'number' => 'numeric',
            'boolean' => 'boolean',
            'email' => 'email',
            'date' => 'date',
            'multiselect', 'attachment' => 'array',
            default => 'string',
        };

        if (in_array($type, ['select'], true)) {
            $rules[] = Rule::in((array) ($field['options'] ?? []));
        }
        if (in_array($type, ['text', 'textarea', 'email'], true)) {
            if (isset($field['min'])) {
                $rules[] = 'min:'.(int) $field['min'];
            }
            $rules[] = 'max:'.(int) ($field['max'] ?? ($type === 'textarea' ? 5000 : 255));
        }
        if (in_array($type, ['integer', 'number'], true)) {
            if (isset($field['min'])) {
                $rules[] = 'min:'.(int) $field['min'];
            }
            if (isset($field['max'])) {
                $rules[] = 'max:'.(int) $field['max'];
            }
        }
        if ($type === 'multiselect') {
            if (isset($field['min'])) {
                $rules[] = 'min:'.(int) $field['min'];
            }
            if (isset($field['max'])) {
                $rules[] = 'max:'.(int) $field['max'];
            }
        }
        if ($type === 'attachment') {
            $rules[] = 'max:'.min(5, (int) ($field['max'] ?? 5));
            if (isset($field['min'])) {
                $rules[] = 'min:'.(int) $field['min'];
            }
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function createTicket(
        ItCatalogItem $item,
        User $actor,
        array $values,
        array $displayValues,
        ?int $requestedSiteId,
        ?int $requestedForId,
        ItAttachmentWriteContext $attachmentContext,
    ): ItTicket {
        $siteId = $requestedSiteId ?? $this->workAccess->defaultSiteId($actor);
        if (! app(ItCatalogAccessService::class)->allowsSite($item, $siteId)) {
            throw ValidationException::withMessages(['catalog_item' => 'This form is not available for the request Site. Choose a form available at that Site.']);
        }
        if (! $this->workAccess->canAssignScope($actor, $siteId, false)) {
            throw ValidationException::withMessages([
                'catalog_item' => 'An active approved Site is required before this request can be submitted.',
            ]);
        }
        if ($item->it_service_id !== null
            && ! $item->service()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'catalog_item' => 'The service for this request is not currently available.',
            ]);
        }

        $publicKeys = collect($item->form_schema['fields'] ?? [])
            ->filter(fn (mixed $field): bool => is_array($field) && ($field['visibility'] ?? 'requester') === 'requester')
            ->pluck('key')->all();
        $publicValues = array_intersect_key($displayValues, array_flip($publicKeys));
        $internalValues = array_filter(array_diff_key($displayValues, array_flip($publicKeys)),
            fn (mixed $value): bool => ! blank($value));

        $ticket = $this->intake->createFromCatalogue($actor, (int) $item->id, (int) $item->published_version_id, [
            'description' => $this->description($item, $publicValues),
            'requested_for_user_id' => $requestedForId ?? (int) $actor->id,
            'site_id' => $siteId,
        ], [], $attachmentContext);

        if ($internalValues !== []) {
            // The submission owns this transaction and idempotent identity.
            // Preserve technician answers through the existing internal-note
            // lifecycle, never in a description visible to the beneficiary.
            $outcome = app(ItTicketInteractionService::class)->addComment($ticket, $actor,
                'Catalogue fulfilment details'."\n\n".$this->description($item, $internalValues), true,
                attachmentContext: $attachmentContext);
            $ticket = $outcome['ticket'];
        }

        return $ticket;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function createProvisioning(
        ItCatalogItem $item,
        User $actor,
        array $values,
        array $displayValues,
        ?int $requestedForId = null,
        ?string $requestUuid = null,
    ): ItProvisioningRequest {
        if ($requestedForId !== null && $requestedForId !== (int) $actor->id && ! $actor->canDo('it.manage')) {
            abort(403, 'You cannot request IT work for another person.');
        }
        $profileQuery = HrEmployeeProfile::query()
            ->where('is_active', true);
        $profile = isset($values['employee_profile_id'])
            ? $profileQuery->whereKey((int) $values['employee_profile_id'])->first()
            : $profileQuery->where('user_id', $requestedForId ?? $actor->id)->first();

        if ($requestedForId !== null && $profile && (int) $profile->user_id !== $requestedForId) {
            throw ValidationException::withMessages(['requested_for_user_id' => 'The requested-for person must match the selected employee.']);
        }

        if (! $profile || ! $this->provisioningAccess->canRequestForProfile($actor, $profile)) {
            throw ValidationException::withMessages([
                'values.employee_profile_id' => 'Choose an active employee profile within your approved Site scope.',
            ]);
        }

        if ($requestedForId !== null && $requestedForId !== (int) $actor->id) {
            $person = User::query()->whereKey($requestedForId)->lockForUpdate()->first();
            $option = $person ? $this->intake->requesterOption($actor, $person) : null;
            if (! $option || ! in_array((int) $profile->primary_site_id, $option['site_ids'], true)) {
                throw ValidationException::withMessages(['requested_for_user_id' => 'Choose an eligible person within your approved Site scope.']);
            }
        }

        if (! app(ItCatalogAccessService::class)->allowsSite($item, $profile->primary_site_id)) {
            throw ValidationException::withMessages(['values.employee_profile_id' => 'This form is not available at the selected employee’s Site.']);
        }

        if ($item->provisioning_template_version_id !== null) {
            $version = ItProvisioningTemplateVersion::query()->findOrFail($item->provisioning_template_version_id);
            $lifecycle = (string) ($version->contract['lifecycle_type'] ?? '');
            abort_if(in_array($lifecycle, ['mover', 'leaver'], true) && ! $actor->canDo('it.manage'), 403);
            $workflow = app(ItProvisioningWorkflowService::class)->launch(profile: $profile, lifecycleType: $lifecycle,
                sourceType: 'catalogue', sourceId: (int) $item->id, sourceEventKey: 'catalogue:'.$actor->id.':'.$requestUuid,
                actorId: (int) $actor->id,
                effectiveAt: ! empty($values['effective_date']) ? Carbon::parse($values['effective_date']) : null,
                templateVersionId: (int) $version->id, retainedCatalogueVersion: true);
            $anchor = $workflow->requests()->orderBy('stage')->orderBy('id')->first();
            if (! $anchor) {
                throw ValidationException::withMessages(['catalog_item' => 'This workflow produced no work. Review its template before requesting it.']);
            }
            if ($item->requires_approval) {
                foreach ($workflow->requests()->whereNull('reversal_of_request_id')->get() as $task) {
                    $task->update(['approval_required' => true, 'approval_status' => 'pending']);
                    ItTicketEvent::record($task, 'catalogue_approval_required', $actor->id, [
                        'catalog_item_id' => $item->id, 'form_schema_version' => $item->form_schema_version,
                    ]);
                }
                $anchor->refresh();
            }
            ItTicketEvent::record($anchor, 'created', $actor->id, ['source' => 'catalog', 'catalog_item_id' => $item->id,
                'form_schema_version' => $item->form_schema_version, 'workflow_id' => $workflow->id]);

            return $anchor;
        }

        $provisioning = ItProvisioningRequest::query()->create([
            'employee_profile_id' => $profile->id,
            'type' => $item->provisioning_type ?: 'other',
            'category' => $item->provisioning_type ?: 'other',
            'action' => in_array($item->provisioning_type, ['account', 'access'], true) ? 'grant' : 'change',
            'stage' => 1,
            'evidence_required' => true,
            'item' => $item->name,
            'status' => 'pending',
            'priority' => $item->default_priority,
            'approval_required' => $item->requires_approval,
            'approval_status' => $item->requires_approval ? 'pending' : 'not_required',
            'notes' => $this->description($item, $displayValues),
            'created_by' => $actor->id,
        ]);

        ItTicketEvent::record($provisioning, 'created', $actor->id, [
            'source' => 'catalog',
            'catalog_item_id' => $item->id,
            'form_schema_version' => $item->form_schema_version,
            'approval_required' => $provisioning->approval_required,
        ]);

        return $provisioning;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function description(ItCatalogItem $item, array $values): ?string
    {
        $labels = collect($item->form_schema['fields'] ?? [])
            ->filter(fn (mixed $field) => is_array($field) && isset($field['key']))
            ->mapWithKeys(fn (array $field) => [(string) $field['key'] => (string) ($field['label'] ?? $field['key'])]);

        $lines = collect($values)->map(function (mixed $value, string $key) use ($labels): string {
            $display = is_array($value) ? implode(', ', $value) : (is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value);

            return ($labels[$key] ?? $key).': '.$display;
        })->values()->all();

        return $lines === [] ? $item->description : implode("\n", $lines);
    }
}
