<?php

namespace Tests\Support;

use PDO;
use Throwable;

/**
 * Only approved disposable test bases with the exact owning process PID, or
 * the kept test database slot that process holds (see KeptTestDatabase).
 *
 * Self-contained: child processes load this file before any autoloader.
 */
final class OwnedTestDatabase
{
    public static function isOwnedBy(string $database, int $owner): bool
    {
        if ($owner <= 0) {
            return false;
        }

        if (preg_match(
            '/\A(?:oblivion_findings_codex_test(?:_packpaper)?|oblivion_workforce_[A-Za-z0-9_]+)_'.$owner.'\z/D',
            $database,
        ) === 1) {
            return true;
        }

        if (preg_match('/\Aoblivion_findings_codex_test_k[0-9a-f]{6}_\d+\z/D', $database) !== 1) {
            return false;
        }

        // A kept slot is the owner's when the session holding the slot's lock
        // also holds the `<slot>:<pid>` lock KeptTestDatabase::claim() takes.
        try {
            $server = new PDO(
                sprintf('mysql:host=%s;port=%s', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306'),
                (string) getenv('DB_USERNAME'),
                (string) getenv('DB_PASSWORD'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $statement = $server->prepare('SELECT IS_USED_LOCK(?) IS NOT NULL AND IS_USED_LOCK(?) = IS_USED_LOCK(?)');
            $statement->execute([$database, $database, $database.':'.$owner]);

            return (int) $statement->fetchColumn() === 1;
        } catch (Throwable) {
            return false;
        }
    }
}
