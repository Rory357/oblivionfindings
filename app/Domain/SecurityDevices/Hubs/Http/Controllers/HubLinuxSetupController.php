<?php

namespace App\Domain\SecurityDevices\Hubs\Http\Controllers;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointReleaseCatalogue;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointReleaseUnavailable;
use App\Domain\SecurityDevices\Hubs\Models\SetupCode;
use App\Domain\SecurityDevices\Hubs\Services\HubInstallationSettings;
use App\Domain\SecurityDevices\Hubs\Services\SetupCodeService;
use App\Domain\SecurityDevices\Hubs\Services\SetupDownloads;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Raspberry Pi or Linux hub, one command (pop-up 12, "Raspberry Pi or NUC"):
 *
 *     curl -fsSL https://oblivionfindings.com/h/7Q2M-K9TX/linux | sudo bash
 *
 * The script downloads the hub for this computer's architecture (checked against the signed
 * release list), installs it with its own runtime (nothing from apt) and enrols with the code.
 * The hub then waits for an admin's approval. The code works once, for 24 hours (D2).
 */
final class HubLinuxSetupController extends Controller
{
    /**
     * Pop-up 12, "Or send it to whoever will install it": https://<server>/h/<CODE> downloads
     * the Windows installer named for the Site. It works while the code does: until a computer
     * uses it, at most 24 hours (D2).
     */
    public function link(Request $request, string $code, SetupCodeService $codes, SetupDownloads $downloads): Response
    {
        abort_unless($request->isSecure() || app()->environment(['local', 'testing']), 400);
        $record = $codes->find($code);
        abort_if($record === null || $record->platform !== 'windows', 404);
        abort_unless($record->state() === 'waiting', 410, 'This link has been used, has expired or was cancelled. Ask for a new one.');

        return $downloads->windowsInstaller($record);
    }

    public function script(Request $request, string $code, SetupCodeService $codes, HubInstallationSettings $settings): HttpResponse
    {
        abort_unless($request->isSecure() || app()->environment(['local', 'testing']), 400);
        $record = $codes->find($code);
        $normalised = SetupCodeService::normalise($code);
        $refusal = match (true) {
            $record === null || $normalised === null || $record->role !== 'hub' || $record->platform !== 'linux' => 'That setup code isn’t for a Linux hub. Check the command in Oblivion › Setup › Hubs › Add a hub.',
            $record->state() === 'used' => 'This setup code was already used. Make a new one in Oblivion › Setup › Hubs › Add a hub.',
            $record->state() === 'expired' => 'This setup code has expired. Make a new one in Oblivion › Setup › Hubs › Add a hub.',
            $record->state() === 'cancelled' => 'This setup code was cancelled in Oblivion.',
            default => null,
        };
        $server = 'https://'.$settings->serverHost();
        $siteName = $record?->site?->name ?? '';

        return response($refusal === null ? $this->installer($server, (string) $normalised, $siteName) : $this->refuse($refusal), 200, [
            'Content-Type' => 'text/x-shellscript; charset=utf-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** The hub package for this architecture, only while the Linux code can still be used. */
    public function package(Request $request, SetupCodeService $codes): Response
    {
        abort_unless($request->isSecure() || app()->environment(['local', 'testing']), 400);
        $code = $request->input('code');
        $architecture = $request->input('architecture');
        $record = is_string($code) ? $codes->find($code) : null;
        if (! $record instanceof SetupCode || $record->role !== 'hub' || $record->platform !== 'linux'
            || $record->state() !== 'waiting' || ! in_array($architecture, ['amd64', 'arm64'], true)) {
            return response()->json(['message' => 'This setup code can’t be used for a Linux hub.'], 410);
        }
        try {
            $catalogue = EndpointReleaseCatalogue::configured();
            $artifact = collect($catalogue->catalogue()['artifacts'])
                ->filter(fn (array $item): bool => $item['platform'] === 'linux' && $item['architecture'] === $architecture && in_array('site', $item['roles'], true))
                ->sortByDesc('release_revision')
                ->first();
            if ($artifact === null) {
                return response()->json(['message' => 'No Linux hub has been published for '.$architecture.' yet.'], 409);
            }
            $download = $catalogue->download((string) $artifact['id'], (string) $artifact['sha256']);
        } catch (EndpointReleaseUnavailable) {
            return response()->json(['message' => 'No Linux hub has been published yet.'], 409);
        }
        $stream = $download['stream'];

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, (string) $artifact['filename'], [
            'Content-Type' => 'application/gzip',
            'Content-Length' => (string) $artifact['size'],
            'X-Oblivion-Sha256' => (string) $artifact['sha256'],
            'X-Oblivion-Version' => (string) $artifact['version'],
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function refuse(string $message): string
    {
        return "#!/bin/sh\necho ".self::shellQuote($message)." >&2\nexit 1\n";
    }

    /**
     * POSIX single quotes for the sh script the hub runs, whatever the server's own OS
     * (escapeshellarg uses cmd.exe double quotes on Windows, where sh would expand $(…)).
     */
    private static function shellQuote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    private function installer(string $server, string $code, string $siteName): string
    {
        $vars = 'SERVER='.self::shellQuote($server)."\nCODE=".self::shellQuote($code)."\nSITE=".self::shellQuote($siteName)."\n";

        return "#!/bin/sh\n# Oblivion hub setup. Downloads the hub, checks it, installs it with its own runtime\n# (nothing from apt), connects, then waits for an admin's approval in Oblivion.\nset -eu\n".$vars.<<<'SH'
say() { printf '%s\n' "$*"; }
fail() { printf 'Oblivion hub setup stopped: %s\n' "$*" >&2; exit 1; }
[ "$(id -u)" = "0" ] || fail "run it with sudo"
case "$(uname -m)" in
  x86_64|amd64) ARCH=amd64 ;;
  aarch64|arm64) ARCH=arm64 ;;
  *) fail "this computer's processor ($(uname -m)) isn't supported" ;;
esac
command -v curl >/dev/null 2>&1 || fail "curl is needed"
command -v sha256sum >/dev/null 2>&1 || fail "sha256sum is needed"
say "Oblivion hub for ${SITE}"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
HEADERS="$WORK/headers"
curl -fsS --proto '=https' --tlsv1.2 -D "$HEADERS" -o "$WORK/hub.tar.gz" \
  -H 'Content-Type: application/json' \
  --data "{\"code\":\"${CODE}\",\"architecture\":\"${ARCH}\"}" \
  "${SERVER}/api/monitoring/collectors/setup/package" || fail "couldn't download the hub (the code may be used, expired or cancelled)"
WANT=$(tr -d '\r' < "$HEADERS" | awk -F': ' 'tolower($1)=="x-oblivion-sha256"{print $2}')
VERSION=$(tr -d '\r' < "$HEADERS" | awk -F': ' 'tolower($1)=="x-oblivion-version"{print $2}')
GOT=$(sha256sum "$WORK/hub.tar.gz" | cut -d' ' -f1)
[ -n "$WANT" ] && [ "$WANT" = "$GOT" ] || fail "the download didn't match its checksum"
say "✓ Downloaded ${VERSION} for ${ARCH} (checked)"
mkdir -p "$WORK/hub"
tar -xzf "$WORK/hub.tar.gz" -C "$WORK/hub"
PKG=$(find "$WORK/hub" -mindepth 1 -maxdepth 1 -type d | head -n 1)
[ -n "$PKG" ] && [ -x "$PKG/install.sh" ] || fail "the download is damaged"
"$PKG/install.sh" --server "$SERVER" --setup-code "$CODE"
SH;
    }
}
