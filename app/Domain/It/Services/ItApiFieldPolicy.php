<?php

namespace App\Domain\It\Services;

use App\Models\ItServiceIdentity;
use Illuminate\Validation\ValidationException;

final class ItApiFieldPolicy
{
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
}
