<?php

namespace App\Domain\SecurityDevices\Hubs\Services;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointReleaseCatalogue;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointReleaseUnavailable;
use App\Domain\SecurityDevices\Hubs\Models\SetupCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Windows installer for one setup code, named for its Site with the code in the name:
 * "Oblivion-Setup (Tōtara House) [oblivionfindings.com T6WD-3KQN].exe". Everyone gets the same
 * signed file; only the name differs, so signing stays valid.
 */
final readonly class SetupDownloads
{
    public function __construct(private SetupCodeService $codes) {}

    public function windowsInstaller(SetupCode $code): Response
    {
        abort_unless($code->platform === 'windows', 404);
        $plain = $this->codes->plainCode($code);
        abort_if($plain === null, 410, 'This setup code can no longer be used. Make a new download in Oblivion.');

        try {
            $catalogue = EndpointReleaseCatalogue::configured();
            $artifact = collect($catalogue->catalogue()['artifacts'])
                ->filter(fn (array $item): bool => $item['platform'] === 'windows' && str_ends_with($item['filename'], '.exe')
                    && in_array($code->role === 'hub' ? 'site' : 'computer', $item['roles'], true))
                ->sortByDesc('release_revision')
                ->first();
            abort_if($artifact === null, 409, 'No Windows installer has been published yet.');
            $download = $catalogue->download((string) $artifact['id'], (string) $artifact['sha256']);
        } catch (EndpointReleaseUnavailable) {
            abort(409, 'No Windows installer has been published yet.');
        }
        $stream = $download['stream'];

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $this->codes->installerFileName($code, $plain), [
            'Content-Type' => 'application/vnd.microsoft.portable-executable',
            'Content-Length' => (string) $download['artifact']['size'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
