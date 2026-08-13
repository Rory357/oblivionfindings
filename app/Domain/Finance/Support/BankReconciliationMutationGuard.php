<?php

namespace App\Domain\Finance\Support;

use Closure;

final class BankReconciliationMutationGuard
{
    private static int $depth = 0;

    public static function allowsCanonicalMutation(): bool
    {
        return self::$depth > 0;
    }

    public static function run(Closure $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }
}
