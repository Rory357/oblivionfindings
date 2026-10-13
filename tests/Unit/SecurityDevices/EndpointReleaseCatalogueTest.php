<?php

use App\Domain\SecurityDevices\Endpoints\Services\EndpointReleaseCatalogue;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointReleaseUnavailable;

function releaseDownloadFixture(): array
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'obli-downloads-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $pair = sodium_crypto_sign_keypair();
    $bytes = 'fictional signed publisher artifact, never an executable';
    $artifact = [
        'id' => 'windows-computer-amd64', 'platform' => 'windows', 'architecture' => 'amd64', 'roles' => ['computer'],
        'version' => '1.2.3', 'release_revision' => 7, 'filename' => 'oblivion-1.2.3-amd64.exe',
        'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
    ];
    file_put_contents($directory.DIRECTORY_SEPARATOR.$artifact['filename'], $bytes);
    $catalogue = ['schema' => 1, 'product' => 'oblivion-downloads', 'revision' => 3,
        'issued_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 60), 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
        'artifacts' => [$artifact]];

    return compact('directory', 'pair', 'bytes', 'artifact', 'catalogue');
}
function releaseDownloadSign(array $fixture, ?array $catalogue = null, ?string $payload = null, string $domain = EndpointReleaseCatalogue::DOMAIN): void
{
    $payload ??= json_encode($catalogue ?? $fixture['catalogue'], JSON_THROW_ON_ERROR);
    file_put_contents($fixture['directory'].DIRECTORY_SEPARATOR.'downloads.json', json_encode([
        'payload' => base64_encode($payload),
        'signature' => base64_encode(sodium_crypto_sign_detached($domain.$payload, sodium_crypto_sign_secretkey($fixture['pair']))),
    ], JSON_THROW_ON_ERROR));
}
beforeEach(function () {
    $this->release = releaseDownloadFixture();
    releaseDownloadSign($this->release);
    $this->catalogue = new EndpointReleaseCatalogue($this->release['directory'], base64_encode(sodium_crypto_sign_publickey($this->release['pair'])), 3);
});
afterEach(function () {
    foreach (glob($this->release['directory'].DIRECTORY_SEPARATOR.'*') as $file) {
        unlink($file);
    }
    rmdir($this->release['directory']);
});

it('verifies publisher catalogue and streams only a hash-checked immutable copy', function () {
    expect($this->catalogue->catalogue()['artifacts'])->toBe([$this->release['artifact']]);
    $download = $this->catalogue->download($this->release['artifact']['id'], $this->release['artifact']['sha256']);
    file_put_contents($this->release['directory'].DIRECTORY_SEPARATOR.$this->release['artifact']['filename'], 'changed after verification');
    expect(stream_get_contents($download['stream']))->toBe($this->release['bytes']);
    fclose($download['stream']);
});
it('supports combined Windows roles and Linux site collectors without Linux computer management', function () {
    foreach ([['windows', ['computer', 'site'], 'both.zip'], ['linux', ['site'], 'collector.tar.gz']] as [$platform, $roles, $filename]) {
        $catalogue = $this->release['catalogue'];
        $catalogue['artifacts'][0] = [...$catalogue['artifacts'][0], 'platform' => $platform, 'roles' => $roles, 'filename' => $filename];
        releaseDownloadSign($this->release, $catalogue);
        expect($this->catalogue->catalogue()['artifacts'][0]['roles'])->toBe($roles);
    }
    $catalogue['artifacts'][0]['roles'] = ['computer'];
    releaseDownloadSign($this->release, $catalogue);
    expect(fn () => $this->catalogue->catalogue())->toThrow(EndpointReleaseUnavailable::class);
});
it('rejects altered missing or differently bound distribution bytes', function () {
    $id = $this->release['artifact']['id'];
    $digest = $this->release['artifact']['sha256'];
    expect(fn () => $this->catalogue->download($id, str_repeat('0', 64)))->toThrow(EndpointReleaseUnavailable::class);
    expect(fn () => $this->catalogue->download('unlisted-download', $digest))->toThrow(EndpointReleaseUnavailable::class);
    $path = $this->release['directory'].DIRECTORY_SEPARATOR.$this->release['artifact']['filename'];
    file_put_contents($path, str_repeat('X', strlen($this->release['bytes'])));
    expect(fn () => $this->catalogue->download($id, $digest))->toThrow(EndpointReleaseUnavailable::class);
    unlink($path);
    expect(fn () => $this->catalogue->download($id, $digest))->toThrow(EndpointReleaseUnavailable::class);
});
it('rejects unsigned wrong-key and wrong-domain catalogues', function () {
    $wrong = new EndpointReleaseCatalogue($this->release['directory'], base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())), 3);
    expect(fn () => $wrong->catalogue())->toThrow(EndpointReleaseUnavailable::class);
    releaseDownloadSign($this->release, domain: '');
    expect(fn () => $this->catalogue->catalogue())->toThrow(EndpointReleaseUnavailable::class);
    file_put_contents($this->release['directory'].DIRECTORY_SEPARATOR.'downloads.json', json_encode($this->release['catalogue']));
    expect(fn () => $this->catalogue->catalogue())->toThrow(EndpointReleaseUnavailable::class);
});
it('enforces expiry future publication and the configured revision floor', function () {
    foreach ([
        ['expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 1)],
        ['issued_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 600)],
        ['expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 91 * 86400)],
        ['issued_at' => '2026-02-30T00:00:00Z'],
        ['revision' => 2], ['revision' => 3.5], ['revision' => '3'],
    ] as $changes) {
        releaseDownloadSign($this->release, [...$this->release['catalogue'], ...$changes]);
        expect(fn () => $this->catalogue->catalogue())->toThrow(EndpointReleaseUnavailable::class);
    }
});
it('rejects ambiguous schemas duplicates bad roles paths unsupported files and unsafe sizes', function () {
    foreach ([
        ['filename' => '../private.env'], ['filename' => 'CON.exe'], ['filename' => 'agent.exe:stream'], ['filename' => 'agent.EXE'],
        ['roles' => ['computer', 'computer']], ['roles' => [null]], ['roles' => ['admin']], ['architecture' => 'x86'],
        ['size' => 0], ['size' => 536870913], ['size' => '12'], ['sha256' => 'anything'], ['version' => '1.0.0-dev'], ['extra' => true],
    ] as $changes) {
        $catalogue = $this->release['catalogue'];
        $catalogue['artifacts'][0] = [...$catalogue['artifacts'][0], ...$changes];
        releaseDownloadSign($this->release, $catalogue);
        expect(fn () => $this->catalogue->catalogue())->toThrow(EndpointReleaseUnavailable::class);
    }
    $payload = json_encode($this->release['catalogue'], JSON_THROW_ON_ERROR);
    releaseDownloadSign($this->release, payload: str_replace('"schema":1', '"schema":1,"schema":1', $payload));
    expect(fn () => $this->catalogue->catalogue())->toThrow(EndpointReleaseUnavailable::class);
    $catalogue = $this->release['catalogue'];
    $catalogue['artifacts'][] = $catalogue['artifacts'][0];
    releaseDownloadSign($this->release, $catalogue);
    expect(fn () => $this->catalogue->catalogue())->toThrow(EndpointReleaseUnavailable::class);
});
it('bounds catalogue reads and rejects invalid key configuration', function () {
    file_put_contents($this->release['directory'].DIRECTORY_SEPARATOR.'downloads.json', str_repeat('x', 65537));
    expect(fn () => $this->catalogue->catalogue())->toThrow(EndpointReleaseUnavailable::class);
    foreach (['', 'not-base64', base64_encode('short')] as $key) {
        expect(fn () => (new EndpointReleaseCatalogue($this->release['directory'], $key))->catalogue())->toThrow(EndpointReleaseUnavailable::class);
    }
});
it('refuses hardlinked release files instead of copying unrelated shared content', function () {
    $path = $this->release['directory'].DIRECTORY_SEPARATOR.$this->release['artifact']['filename'];
    if (! @link($path, $this->release['directory'].DIRECTORY_SEPARATOR.'second.exe')) {
        $this->markTestSkipped('Host does not permit a temporary hardlink.');
    }
    expect(fn () => $this->catalogue->download($this->release['artifact']['id'], $this->release['artifact']['sha256']))
        ->toThrow(EndpointReleaseUnavailable::class);
});
