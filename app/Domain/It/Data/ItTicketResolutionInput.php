<?php

namespace App\Domain\It\Data;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Public resolution evidence; shared by HTTP, drafts and direct writers. */
final class ItTicketResolutionInput
{
    public const OUTCOMES = ['restored', 'workaround', 'fulfilled', 'guidance'];

    public static function rules(bool $partial = false): array
    {
        $presence = $partial ? ['sometimes', 'nullable'] : ['required'];

        return [
            'resolution_code' => [...$presence, 'string', Rule::in(self::OUTCOMES)],
            'note' => [...$presence, 'string', 'max:5000'],
            'resolution_verification' => [...$presence, 'string', 'max:5000'],
        ];
    }

    public static function normalize(array $input): array
    {
        $data = [];
        foreach (array_keys(self::rules()) as $key) {
            $value = $input[$key] ?? null;
            $data[$key] = is_string($value) ? trim($value) : $value;
        }

        return Validator::make($data, self::rules())->validate();
    }
}
