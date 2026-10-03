<?php

namespace App\Support\Medication;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Laravel's dontFlash list only strips top-level input keys. Forms that post
 * witness PINs inside arrays (fleet transport `medications.*.witness_credential`)
 * would otherwise flash the PIN back into the session as old input when
 * validation fails. This removes those keys at any depth.
 */
final class ScrubNestedWitnessSecrets
{
    /** Witness secrets that may appear inside arrays. Mirrors the dontFlash entries. */
    public const KEYS = [
        'witness_credential',
        'second_witness_credential',
        'witness_1_credential',
        'witness_2_credential',
        'read_back_witness_credential',
        'waiver_approver_credential',
        'cd_witness_credential',
        'current_pin',
        'pin',
        'pin_confirmation',
    ];

    public static function fromFlashedInput(Throwable $exception, Request $request): void
    {
        if (! $exception instanceof ValidationException || ! $request->hasSession()) {
            return;
        }

        $session = $request->session();
        $old = $session->get('_old_input');
        if (! is_array($old)) {
            return;
        }

        $scrubbed = self::scrub($old);
        if ($scrubbed !== $old) {
            $session->flash('_old_input', $scrubbed);
        }
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    public static function scrub(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_string($key) && in_array($key, self::KEYS, true)) {
                unset($input[$key]);

                continue;
            }
            if (is_array($value)) {
                $input[$key] = self::scrub($value);
            }
        }

        return $input;
    }
}
