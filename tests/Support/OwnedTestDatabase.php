<?php

namespace Tests\Support;

/** Only approved disposable test bases with the exact owning process PID. */
final class OwnedTestDatabase
{
    public static function isOwnedBy(string $database, int $owner): bool
    {
        return $owner > 0
            && preg_match(
                '/\A(?:oblivion_findings_codex_test(?:_packpaper)?|oblivion_workforce_[A-Za-z0-9_]+)_'.$owner.'\z/D',
                $database,
            ) === 1;
    }
}
