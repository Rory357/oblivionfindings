import { existsSync, readFileSync } from 'node:fs';

/**
 * Locate the PHP CLI binary. Prefers the `PHP_BINARY` env var, falls back to
 * common Herd / system locations on Windows + macOS / Linux.
 *
 * On Windows, Herd ships `php.bat` — a cmd shim containing
 * `"…\bin\php84\php.exe" %*`. Spawning a `.bat` requires `shell: true`, and
 * Node's shell mode joins arguments into one cmd.exe string with **no
 * quoting**, so any argument containing spaces, `%` (expanded as an env var),
 * `^`, `&` or quotes is mangled before PHP ever sees it. Seeder runs through
 * the shim then die with an opaque
 * `Command failed: …\php.bat artisan db:seed …`. To avoid that whole class of
 * failure, a `.bat` candidate is resolved to the `php.exe` it wraps (tracking
 * whatever PHP version Herd has active) so callers can spawn the exe directly
 * without a shell.
 */
export function resolvePhpBinary(): string | null {
    const explicit = process.env.PHP_BINARY;
    if (explicit && existsSync(explicit)) {
        return resolveWindowsBatShim(explicit);
    }

    const candidates = [
        // Herd on Windows
        `${process.env.USERPROFILE ?? ''}\\.config\\herd\\bin\\php.bat`,
        `${process.env.USERPROFILE ?? ''}\\.config\\herd\\bin\\php.exe`,
        // Herd on macOS / Linux
        `${process.env.HOME ?? ''}/.config/herd-lite/bin/php`,
        `${process.env.HOME ?? ''}/Library/Application Support/Herd/bin/php`,
        // System PATH fallback
        'php',
    ];

    for (const candidate of candidates) {
        if (candidate && (candidate === 'php' || existsSync(candidate))) {
            return resolveWindowsBatShim(candidate);
        }
    }

    return null;
}

/**
 * `true` when the binary can only be spawned through a shell: bare `php`
 * needs PATH resolution, and a `.bat` that {@link resolveWindowsBatShim}
 * could not unwrap cannot be exec'd directly at all.
 */
export function phpRequiresShell(phpBin: string): boolean {
    return phpBin.toLowerCase().endsWith('.bat') || phpBin === 'php';
}

function resolveWindowsBatShim(phpBin: string): string {
    if (!phpBin.toLowerCase().endsWith('.bat')) {
        return phpBin;
    }

    try {
        const target = readFileSync(phpBin, 'utf8').match(
            /"([^"]+\.exe)"/i,
        )?.[1];
        if (target && existsSync(target)) {
            return target;
        }
    } catch {
        // Unreadable shim — fall through and keep the .bat (shell mode).
    }

    return phpBin;
}
