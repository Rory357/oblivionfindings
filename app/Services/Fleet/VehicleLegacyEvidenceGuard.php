<?php

namespace App\Services\Fleet;

use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Registration, WoF and CoF dates and the odometer on the Asset row are
 * projections of the versioned vehicle evidence. Generic register forms may
 * still post them unchanged; any attempt to change them is refused with a
 * pointer to the evidence workflow instead of being silently ignored.
 */
class VehicleLegacyEvidenceGuard
{
    public const DATE_FIELDS = ['registration_expires_at', 'wof_expires_at', 'cof_expires_at'];

    public const FIELDS = [...self::DATE_FIELDS, 'odometer_km'];

    /** Validation rules for the projection fields, so unchanged values can be compared. */
    public static function rules(): array
    {
        return [
            'registration_expires_at' => ['nullable', 'date'],
            'wof_expires_at' => ['nullable', 'date'],
            'cof_expires_at' => ['nullable', 'date'],
            'odometer_km' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /** @param Asset|null $asset the current vehicle, or null when creating one */
    public static function assertUnchanged(Request $request, ?Asset $asset): void
    {
        foreach (self::DATE_FIELDS as $field) {
            if (! $request->exists($field)) {
                continue;
            }
            $sent = self::blank($request->input($field)) ? null : Carbon::parse((string) $request->input($field))->toDateString();
            if ($sent !== $asset?->{$field}?->toDateString()) {
                self::refuse();
            }
        }
        if ($request->exists('odometer_km')) {
            $sent = self::blank($request->input('odometer_km')) ? null : round((float) $request->input('odometer_km'), 1);
            $current = $asset?->odometer_km === null ? null : round((float) $asset->odometer_km, 1);
            if ($sent !== $current) {
                self::refuse();
            }
        }
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public static function strip(array $data): array
    {
        return array_diff_key($data, array_flip(self::FIELDS));
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private static function refuse(): never
    {
        throw ValidationException::withMessages([
            'vehicle_evidence' => 'Registration, WoF and CoF dates and odometer readings are recorded on the vehicle\'s Service & compliance tab, where each change keeps its evidence and history.',
        ]);
    }
}
