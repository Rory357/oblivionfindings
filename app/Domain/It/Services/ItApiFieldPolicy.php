<?php

namespace App\Domain\It\Services;

use App\Models\ItServiceIdentity;
use App\Models\ItTicket;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ItApiFieldPolicy
{
    public function __construct(
        private readonly ItApiWorkItemService $workItems,
    ) {}

    /** @param array<int, string> $submittedFields */
    public function assertCreateFields(ItServiceIdentity $identity, array $submittedFields): void
    {
        $errors = [];
        foreach ($submittedFields as $field) {
            if (! in_array($field, ItServiceIdentity::CREATE_FIELDS, true)) {
                $errors[$field] = 'This field is not available through the IT service API.';
            } elseif (! $identity->allowsField('create', $field)) {
                $errors[$field] = 'This service identity is not allowed to set this field.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param list<string> $submittedFields */
    public function assertUpdateFields(ItServiceIdentity $identity, array $submittedFields): void
    {
        $errors = [];
        foreach ($submittedFields as $field) {
            if ($field === 'expected_version') {
                continue;
            }
            $capabilityField = in_array($field, ['priority_reason', 'release_priority_override'], true) ? 'priority' : $field;
            if (! in_array($capabilityField, ItServiceIdentity::UPDATE_FIELDS, true)) {
                $errors[$field] = 'This field is not available through the IT service API.';
            } elseif (! $identity->allowsField('update', $capabilityField)) {
                $errors[$field] = 'This service identity is not allowed to update this field.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public static function updateRules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:1'],
            'category' => ['sometimes', 'required', Rule::in(ItTicket::CATEGORIES)],
            'subcategory' => ['sometimes', 'nullable', 'string', 'max:255'],
            'priority' => ['sometimes', 'required', Rule::in(ItTicket::PRIORITIES)],
            'impact' => ['sometimes', 'required', Rule::in(ItTicket::IMPACTS)],
            'urgency' => ['sometimes', 'required', Rule::in(ItTicket::URGENCIES)],
            'priority_reason' => ['required_with:priority', 'required_if:release_priority_override,true', 'nullable', 'string', 'max:1000'],
            'release_priority_override' => ['sometimes', 'boolean'],
        ];
    }

    public function validateUpdate(ItServiceIdentity $identity, array $data): void
    {
        $this->assertUpdateFields($identity, array_keys($data));
        Validator::make($data, self::updateRules())->validate();
        if (array_intersect(array_keys($data), [...ItServiceIdentity::UPDATE_FIELDS, 'release_priority_override']) === []) {
            throw ValidationException::withMessages(['form' => 'Supply at least one permitted property to update.']);
        }
    }

    /** @return array<int, string> */
    public function readableFields(ItServiceIdentity $identity): array
    {
        return array_values(array_intersect(
            ItServiceIdentity::READ_FIELDS,
            (array) ($identity->allowed_fields['read'] ?? []),
        ));
    }

    /** @return array<string, array<string, int|string>|null> */
    public function linkedContext(ItServiceIdentity $identity, ItTicket $ticket): array
    {
        $readable = $this->readableFields($identity);
        $context = [];

        if (in_array('site', $readable, true)
            && $ticket->site
            && (int) $ticket->site->id === (int) $ticket->site_id
            && $ticket->site->is_active
            && ! $ticket->site->archived
            && $ticket->site->archived_at === null) {
            $context['site'] = [
                'id' => (int) $ticket->site->id,
                'name' => (string) $ticket->site->name,
            ];
        }

        if (in_array('service', $readable, true)
            && $ticket->service
            && $this->workItems->linkedServiceIsVisible($identity, $ticket, $ticket->service)) {
            $context['service'] = [
                'id' => (int) $ticket->service->id,
                'name' => (string) $ticket->service->name,
            ];
        }

        if (in_array('asset', $readable, true)
            && $ticket->asset
            && $this->workItems->linkedAssetIsVisible($identity, $ticket, $ticket->asset)) {
            $context['asset'] = [
                'id' => (int) $ticket->asset->id,
                'name' => (string) $ticket->asset->name,
                'asset_tag' => (string) $ticket->asset->asset_tag,
            ];
        }

        return $context;
    }
}
