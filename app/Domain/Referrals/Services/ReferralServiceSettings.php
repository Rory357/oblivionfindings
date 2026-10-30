<?php

namespace App\Domain\Referrals\Services;

use Illuminate\Database\Eloquent\Builder;

/** Offered services and canonical delivery settings remain distinct, explicitly mapped concepts. */
final class ReferralServiceSettings
{
    /** Null means any available type; an empty list permits none (including disabled/unknown services). */
    public static function contextTypes(array $configuration, ?string $serviceId): ?array
    {
        foreach ($configuration['services'] ?? [] as $service) {
            if ($service['id'] === $serviceId && $service['enabled']) {
                // Previously published versions did not carry a mapping. Preserve their explicit availability rules.
                $types = $service['context_types'] ?? null;
                if ($types !== null) {
                    $types = array_values(array_unique($types));
                    sort($types);
                }

                return $types;
            }
        }

        return [];
    }

    public static function contexts(Builder $query, array $configuration, ?string $serviceId): Builder
    {
        $types = self::contextTypes($configuration, $serviceId);

        return $types === null ? $query : $query->whereIn('type', $types);
    }
}
