<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** An explicitly approved family-facing service contact, never inferred from internal site records. */
final class ChatUrgentContact
{
    public function validate(mixed $input, string $path = 'urgent_contact'): ?array
    {
        if ($input === null) {
            return null;
        }
        if (is_array($input)) {
            foreach (['label', 'phone', 'instructions'] as $field) {
                if (isset($input[$field]) && is_string($input[$field])) {
                    $input[$field] = trim($input[$field]);
                }
            }
        }
        $value = Validator::make([$path => $input], [
            $path => ['required', 'array:label,phone,instructions,confirmed'],
            "$path.label" => ['required', 'string', 'max:100'],
            "$path.phone" => ['required', 'string', 'max:32', 'regex:/^\+?[0-9][0-9 ()-]*[0-9]$/'],
            "$path.instructions" => ['present', 'nullable', 'string', 'max:500'],
            "$path.confirmed" => ['required', 'accepted'],
        ])->validate()[$path];
        $digits = preg_replace('/[^0-9]/', '', $value['phone']);
        if (strlen($digits) < 6 || strlen($digits) > 15) {
            throw ValidationException::withMessages(["$path.phone" => 'Enter the full service phone number, including its area or country code.']);
        }

        return ['label' => trim($value['label']), 'phone' => trim($value['phone']),
            'instructions' => trim($value['instructions'] ?? ''), 'confirmed' => true];
    }

    public function publicContact(mixed $stored): ?array
    {
        try {
            $contact = $this->validate($stored);
        } catch (ValidationException) {
            return null;
        }
        if ($contact === null) {
            return null;
        }

        return ['label' => $contact['label'], 'phone' => $contact['phone'], 'instructions' => $contact['instructions'],
            'phone_uri' => 'tel:'.(str_starts_with($contact['phone'], '+') ? '+' : '').preg_replace('/[^0-9]/', '', $contact['phone'])];
    }
}
