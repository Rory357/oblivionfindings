<?php

namespace App\Services\Medication\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class SupportTime
{
    public static function parse(string $value): CarbonImmutable
    {
        $wall = substr($value, 0, 16);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?:[+-]\d{2}:\d{2})?$/D', $value)) {
            throw ValidationException::withMessages(['occurred_at' => 'Choose the date and time in Pacific/Auckland.']);
        }
        $offsets = strlen($value) > 16 ? [substr($value, 16)] : ['+12:00', '+13:00'];
        $matches = [];
        foreach ($offsets as $offset) {
            try {
                $instant = CarbonImmutable::createFromFormat('!Y-m-d\TH:iP', $wall.$offset);
                if ($instant->setTimezone('Pacific/Auckland')->format('Y-m-d\TH:i') === $wall) {
                    $matches[] = $instant->utc();
                }
            } catch (\Throwable) {
            }
        }
        if (count($matches) !== 1) {
            throw ValidationException::withMessages(['occurred_at' => count($matches) > 1 ? 'This time occurs twice. Choose the first or second occurrence.' : 'That date and time does not exist in Pacific/Auckland.']);
        }
        if ($matches[0]->greaterThan(CarbonImmutable::now('UTC'))) {
            throw ValidationException::withMessages(['occurred_at' => 'The change cannot be recorded in the future.']);
        }

        return $matches[0];
    }
}
