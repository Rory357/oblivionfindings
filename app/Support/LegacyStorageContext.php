<?php

namespace App\Support;

/**
 * One inert compatibility value for legacy non-null partition columns.
 *
 * It is never derived from a user, request, Site, or record and must never be
 * used for authorization. Canonical ownership and Site access remain the
 * application boundary until the legacy columns can be removed safely.
 */
final class LegacyStorageContext
{
    public static function id(): int
    {
        return 1;
    }
}
