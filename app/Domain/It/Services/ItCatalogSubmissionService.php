<?php

namespace App\Domain\It\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ItCatalogItem;
use App\Models\ItCatalogSubmission;
use App\Models\ItProvisioningRequest;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ItCatalogSubmissionService
{
    public function __construct(
        private readonly ItTicketRoutingService $routingService,
        private readonly ItWorkAccessService $workAccess,
        private readonly ItProvisioningAccessService $provisioningAccess,
        private readonly ItCatalogFieldOptionService $fieldOptions,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{submission: ItCatalogSubmission, result: Model, created: bool}
     */
    public function submit(ItCatalogItem $catalogItem, User $actor, array $input): array
    {
        return DB::transaction(function () use ($catalogItem, $actor, $input): array {
            // Serialize the actor's global submission key, including competing
            // submissions for different catalogue items.
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->approved_at !== null && ($actor->canDo('it.request') || $actor->canDo('it.manage')), 403);
            $existing = ItCatalogSubmission::query()
                ->where('requester_user_id', $actor->id)
                ->where('idempotency_key', (string) $input['idempotency_key'])
                ->first();
            if ($existing) {
                $result = $existing->result()->firstOrFail();
                $visible = $result instanceof ItTicket
                    ? $this->workAccess->canView($actor, $result)
                    : ($result instanceof ItProvisioningRequest && ($this->provisioningAccess->canView($actor, $result)
                        || $this->provisioningAccess->canTrack($actor, $result)));
                abort_unless($visible, 404);
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

            $item = ItCatalogItem::query()->whereKey($catalogItem->id)->published()->lockForUpdate()->firstOrFail()->publishedContract();
            abort_unless(app(ItCatalogAccessService::class)->canDiscover($actor, $item), 404);

            if ((int) $input['schema_version'] !== (int) $item->form_schema_version) {
                throw ValidationException::withMessages([
                    'schema_version' => 'This request form has changed. Refresh it before submitting.',
                ]);
            }

            $validated = $this->validateValues(
                $item,
                (array) ($input['values'] ?? []),
                $actor,
            );
            $values = $validated['values'];
            $result = match ($item->outcome_type) {
                'service_request', 'security_request' => $this->createTicket(
                    $item,
                    $actor,
                    $values,
                    $validated['display_values'],
                    isset($input['site_id']) ? (int) $input['site_id'] : null,
                ),
                'provisioning' => $this->createProvisioning(
                    $item,
                    $actor,
                    $values,
                    $validated['display_values'],
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

            return ['submission' => $submission, 'result' => $result, 'created' => true];
        });
    }

    private function inputHash(int $itemId, array $input): string
    {
        return hash('sha256', $this->canonicalJson([
            'catalog_item_id' => $itemId,
            'schema_version' => (int) $input['schema_version'],
            'values' => $input['values'] ?? [],
            ...(array_key_exists('site_id', $input) ? ['site_id' => isset($input['site_id']) ? (int) $input['site_id'] : null] : []),
        ]));
    }

    private function canonicalJson(mixed $value): string
    {
        $normalise = function (mixed $value) use (&$normalise): mixed {
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
    private function validateValues(ItCatalogItem $item, array $values, User $actor): array
    {
        $fields = collect($item->form_schema['fields'] ?? [])
            ->filter(fn (mixed $field) => is_array($field) && isset($field['key']))
            ->keyBy(fn (array $field) => (string) $field['key']);

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
            $labels["values.{$key}"] = (string) ($field['label'] ?? $key);
        }

        $validated = Validator::make(['values' => $values], $rules, [], $labels)->validate();

        $clean = collect($validated['values'] ?? [])
            ->only($fields->keys()->all())
            ->all();
        $entityTypes = $fields
            ->pluck('type')
            ->filter(fn (mixed $type): bool => in_array($type, ItCatalogFieldOptionService::TYPES, true))
            ->unique()
            ->values()
            ->all();
        $options = $entityTypes !== []
            ? $this->fieldOptions->forTypes($actor, $entityTypes)
            : ['employee' => [], 'user' => [], 'asset' => []];
        $displayValues = $clean;
        $errors = [];
        foreach ($fields as $key => $field) {
            $type = (string) ($field['type'] ?? 'text');
            if (! in_array($type, ItCatalogFieldOptionService::TYPES, true)
                || ! array_key_exists($key, $clean)
                || $clean[$key] === null
                || $clean[$key] === '') {
                continue;
            }

            $option = collect($options[$type] ?? [])->firstWhere('id', (int) $clean[$key]);
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

        return ['values' => $clean, 'display_values' => $displayValues];
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
            'multiselect' => 'array',
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
        ?int $requestedSiteId = null,
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

        $ticket = ItTicket::createWithReference([
            'title' => $item->name,
            'description' => $this->description($item, $displayValues),
            'requester_user_id' => $actor->id,
            'requested_for_user_id' => $actor->id,
            'it_service_id' => $item->it_service_id,
            'site_id' => $siteId,
            'is_organisation_wide' => false,
            'category' => $item->category,
            'priority' => $item->default_priority,
            'impact' => 'individual',
            'urgency' => match ($item->default_priority) {
                'urgent' => 'critical',
                'high' => 'high',
                'low' => 'low',
                default => 'normal',
            },
            'work_type' => $item->outcome_type,
            'workflow_state' => 'submitted',
            'requires_approval' => $item->requires_approval || ItTicket::categoryNeedsApproval($item->category),
            'source' => $actor->canDo('it.manage') ? 'agent' : 'portal',
            'status' => 'open',
        ]);
        $ticket->stampSlaDueDates();
        $ticket->save();

        ItTicketEvent::record($ticket, 'created', $actor->id, [
            'source' => 'catalog',
            'catalog_item_id' => $item->id,
            'form_schema_version' => $item->form_schema_version,
        ]);

        $ticket = $this->routingService->route($ticket, $actor->id);

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
    ): ItProvisioningRequest {
        $profileQuery = HrEmployeeProfile::query()
            ->where('is_active', true);
        $profile = isset($values['employee_profile_id'])
            ? $profileQuery->whereKey((int) $values['employee_profile_id'])->first()
            : $profileQuery->where('user_id', $actor->id)->first();

        if (! $profile || ! $this->provisioningAccess->canRequestForProfile($actor, $profile)) {
            throw ValidationException::withMessages([
                'values.employee_profile_id' => 'Choose an active employee profile within your approved Site scope.',
            ]);
        }

        if (! app(ItCatalogAccessService::class)->allowsSite($item, $profile->primary_site_id)) {
            throw ValidationException::withMessages(['values.employee_profile_id' => 'This form is not available at the selected employee’s Site.']);
        }

        $provisioning = ItProvisioningRequest::query()->create([
            'employee_profile_id' => $profile->id,
            'type' => $item->provisioning_type ?: 'other',
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
