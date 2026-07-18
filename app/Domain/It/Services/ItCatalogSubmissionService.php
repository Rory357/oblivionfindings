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
    /**
     * @param  array<string, mixed>  $input
     * @return array{submission: ItCatalogSubmission, result: Model, created: bool}
     */
    public function submit(ItCatalogItem $catalogItem, User $actor, int $tenantId, array $input): array
    {
        return DB::transaction(function () use ($catalogItem, $actor, $tenantId, $input): array {
            $item = ItCatalogItem::query()
                ->whereKey($catalogItem->id)
                ->where('tenant_id', $tenantId)
                ->where('is_published', true)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = ItCatalogSubmission::query()
                ->where('tenant_id', $tenantId)
                ->where('requester_user_id', $actor->id)
                ->where('idempotency_key', (string) $input['idempotency_key'])
                ->first();
            if ($existing) {
                if ((int) $existing->catalog_item_id !== (int) $item->id) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'That submission key has already been used for another request.',
                    ]);
                }

                return [
                    'submission' => $existing,
                    'result' => $existing->result()->firstOrFail(),
                    'created' => false,
                ];
            }

            if ((int) $input['schema_version'] !== (int) $item->form_schema_version) {
                throw ValidationException::withMessages([
                    'schema_version' => 'This request form has changed. Refresh it before submitting.',
                ]);
            }

            $values = $this->validateValues(
                $item,
                (array) ($input['values'] ?? []),
                $actor->canDo('it.manage'),
            );
            $result = match ($item->outcome_type) {
                'service_request', 'security_request' => $this->createTicket($item, $actor, $tenantId, $values),
                'provisioning' => $this->createProvisioning($item, $actor, $tenantId, $values),
                default => throw ValidationException::withMessages([
                    'catalog_item' => 'This catalogue item has an unsupported outcome.',
                ]),
            };

            $submission = ItCatalogSubmission::query()->create([
                'tenant_id' => $tenantId,
                'catalog_item_id' => $item->id,
                'requester_user_id' => $actor->id,
                'schema_version' => $item->form_schema_version,
                'schema_snapshot' => $item->form_schema,
                'submitted_values' => $values,
                'idempotency_key' => (string) $input['idempotency_key'],
                'result_type' => $result->getMorphClass(),
                'result_id' => $result->getKey(),
                'submitted_at' => now(),
            ]);

            return ['submission' => $submission, 'result' => $result, 'created' => true];
        });
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function validateValues(ItCatalogItem $item, array $values, bool $canUseInternalFields): array
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
            if (! $canUseInternalFields && in_array($visibility, ['internal', 'restricted'], true)) {
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

        return collect($validated['values'] ?? [])
            ->only($fields->keys()->all())
            ->all();
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
            $rules[] = 'max:'.(int) ($field['max'] ?? ($type === 'textarea' ? 5000 : 255));
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function createTicket(ItCatalogItem $item, User $actor, int $tenantId, array $values): ItTicket
    {
        $ticket = ItTicket::createWithReference([
            'tenant_id' => $tenantId,
            'title' => $item->name,
            'description' => $this->description($item, $values),
            'requester_user_id' => $actor->id,
            'requested_for_user_id' => $actor->id,
            'it_service_id' => $item->it_service_id,
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

        return $ticket;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function createProvisioning(ItCatalogItem $item, User $actor, int $tenantId, array $values): ItProvisioningRequest
    {
        $profileQuery = HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true);
        $profile = isset($values['employee_profile_id'])
            ? $profileQuery->whereKey((int) $values['employee_profile_id'])->first()
            : $profileQuery->where('user_id', $actor->id)->first();

        if (! $profile) {
            throw ValidationException::withMessages([
                'values.employee_profile_id' => 'Choose an active employee profile in this organisation.',
            ]);
        }

        $provisioning = ItProvisioningRequest::query()->create([
            'tenant_id' => $tenantId,
            'employee_profile_id' => $profile->id,
            'type' => $item->provisioning_type ?: 'other',
            'item' => $item->name,
            'status' => 'pending',
            'priority' => $item->default_priority,
            'notes' => $this->description($item, $values),
            'created_by' => $actor->id,
        ]);

        ItTicketEvent::record($provisioning, 'created', $actor->id, [
            'source' => 'catalog',
            'catalog_item_id' => $item->id,
            'form_schema_version' => $item->form_schema_version,
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
