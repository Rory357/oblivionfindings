<?php

namespace App\Domain\It\Services;

use App\Models\ItServiceIdentity;
use App\Models\ItTicket;
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
