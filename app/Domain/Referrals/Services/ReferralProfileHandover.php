<?php

namespace App\Domain\Referrals\Services;

use App\Models\Client;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Optional self-identified facts; never inferred from a name, referrer or funding application. */
final class ReferralProfileHandover
{
    public const LABELS = [
        'ethnicity' => 'Ethnicity', 'iwi' => 'Iwi', 'hapu' => 'Hapū', 'marae' => 'Marae',
        'languages' => 'Languages', 'preferred_pronouns' => 'Pronouns', 'religion' => 'Religion or beliefs',
        'cultural_dietary_needs' => 'Cultural dietary needs',
    ];

    public function validate(array $input): array
    {
        if (is_string($input['verification_note'] ?? null)) {
            $input['verification_note'] = trim($input['verification_note']);
        }
        $rules = ['fields' => 'required|array:'.implode(',', array_keys(self::LABELS)),
            'verification_note' => 'required|string|min:10|max:2000', 'confirmed' => 'accepted'];
        foreach (self::LABELS as $key => $label) {
            $limit = match ($key) {
                'preferred_pronouns' => 50, 'cultural_dietary_needs' => 2000, default => 255
            };
            $rules['fields.'.$key] = $key === 'languages' ? 'sometimes|array|max:20' : 'sometimes|nullable|string|max:'.$limit;
        }
        $rules['fields.languages.*'] = 'required|string|max:100|distinct';
        $data = Validator::make($input, $rules)->validate();
        $fields = [];
        foreach ($data['fields'] as $key => $value) {
            $value = is_array($value) ? array_values(array_unique(array_filter(array_map('trim', $value), fn ($v) => $v !== ''))) : trim((string) $value);
            if (filled($value)) {
                $fields[$key] = $value;
            }
        }
        if ($fields === []) {
            throw ValidationException::withMessages(['profile_handover' => 'Add at least one verified detail, or leave the optional profile handover empty.']);
        }

        return ['fields' => $fields, 'verification_note' => trim($data['verification_note']), 'confirmed' => true];
    }

    public function preview(?Client $person): array
    {
        $fields = [];
        foreach (self::LABELS as $key => $label) {
            $current = $person?->getAttribute($key);
            $fields[] = ['key' => $key, 'label' => $label, 'current' => $current, 'can_add' => blank($current)];
        }

        return $fields;
    }

    /** Caller holds the conversion/person lock and has current canonical create/update permission. */
    public function apply(Client $person, ?array $review): array
    {
        if ($review === null) {
            return [];
        }
        $mappings = [];
        foreach ($review['fields'] as $key => $value) {
            if (filled($person->getAttribute($key))) {
                throw ValidationException::withMessages(['profile_handover' => self::LABELS[$key].' is already recorded. Keep the current value and review any correction through the person profile.']);
            }
            $mappings[] = ['key' => $key, 'label' => self::LABELS[$key], 'value' => $value, 'destination' => 'person_profile', 'source' => 'staff_verified_with_person'];
        }
        $person->fill($review['fields'])->save();

        return $mappings;
    }
}
