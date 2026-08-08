<?php

use Symfony\Component\Process\Process;

/**
 * @param  list<string>  $command
 */
function runReleaseManifestFixtureCommand(array $command, string $workingDirectory): string
{
    $process = new Process($command, $workingDirectory);
    $process->setTimeout(30);
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException('Release manifest fixture command failed: '.$process->getErrorOutput());
    }

    return trim($process->getOutput());
}

/**
 * @return array{
 *     root: string,
 *     checkout: string,
 *     manifest_path: string,
 *     manifest: array<string, mixed>
 * }
 */
function makeReleaseManifestFixture(
    string $repositoryPath = 'app/Domain/It/ReleaseProof.php',
    ?string $candidateContents = null,
): array {
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oblivion-release-manifest-'.bin2hex(random_bytes(8));
    $checkout = $root.DIRECTORY_SEPARATOR.'candidate';
    $sourcePath = $checkout.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $repositoryPath);
    $sourceDirectory = dirname($sourcePath);

    if (! mkdir($sourceDirectory, 0777, true) && ! is_dir($sourceDirectory)) {
        throw new RuntimeException('Could not create the isolated release manifest fixture.');
    }

    runReleaseManifestFixtureCommand(['git', 'init', '--quiet'], $checkout);
    runReleaseManifestFixtureCommand(['git', 'config', 'user.email', 'release-manifest@example.invalid'], $checkout);
    runReleaseManifestFixtureCommand(['git', 'config', 'user.name', 'Release Manifest Test'], $checkout);
    runReleaseManifestFixtureCommand(['git', 'config', 'core.autocrlf', 'false'], $checkout);

    file_put_contents($sourcePath, "<?php\n\nreturn 'base';\n");
    runReleaseManifestFixtureCommand(['git', 'add', '--force', '--', $repositoryPath], $checkout);
    runReleaseManifestFixtureCommand(['git', 'commit', '--quiet', '-m', 'base'], $checkout);
    $baseRevision = runReleaseManifestFixtureCommand(['git', 'rev-parse', 'HEAD'], $checkout);

    file_put_contents($sourcePath, $candidateContents ?? "<?php\n\nreturn 'candidate';\n");
    runReleaseManifestFixtureCommand(['git', 'add', '--force', '--', $repositoryPath], $checkout);
    runReleaseManifestFixtureCommand(['git', 'commit', '--quiet', '-m', 'candidate'], $checkout);
    $candidateRevision = runReleaseManifestFixtureCommand(['git', 'rev-parse', 'HEAD'], $checkout);
    runReleaseManifestFixtureCommand(['git', 'update-ref', 'refs/remotes/origin/main', $candidateRevision], $checkout);

    return [
        'root' => $root,
        'checkout' => $checkout,
        'manifest_path' => $root.DIRECTORY_SEPARATOR.'release-source-manifest.json',
        'manifest' => [
            'schema_version' => 2,
            'base_revision' => $baseRevision,
            'candidate_revision' => $candidateRevision,
            'origin_main_revision' => $candidateRevision,
            'files' => [[
                'path' => $repositoryPath,
                'change' => 'modified',
                'sha256' => hash_file('sha256', $sourcePath),
                'owner' => 'IT',
                'requirement' => 'V10',
                'source_or_generated' => 'source',
                'review' => [
                    'decision' => 'approved',
                    'reviewer' => 'release-review@example.invalid',
                    'reviewed_at_utc' => '2026-08-08T10:00:00Z',
                ],
                'verification' => [
                    'result' => 'passed',
                    'evidence' => 'tests/Unit/ReleaseSourceManifestVerifierTest.php',
                    'observed_at_utc' => '2026-08-08T10:01:00Z',
                ],
            ]],
        ],
    ];
}

function removeReleaseManifestFixture(string $root): void
{
    if (! is_dir($root)) {
        return;
    }

    $resolvedRoot = realpath($root);
    $resolvedTemporaryDirectory = realpath(sys_get_temp_dir());
    $expectedPrefix = $resolvedTemporaryDirectory === false
        ? null
        : $resolvedTemporaryDirectory.DIRECTORY_SEPARATOR.'oblivion-release-manifest-';
    if ($resolvedRoot === false || $expectedPrefix === null || ! str_starts_with($resolvedRoot, $expectedPrefix)) {
        throw new RuntimeException('Refusing to remove a release manifest fixture outside its isolated temporary root.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        removeReleaseManifestFixturePath($item->getPathname(), $item->isDir() && ! $item->isLink());
    }

    removeReleaseManifestFixturePath($resolvedRoot, true);
}

function removeReleaseManifestFixturePath(string $path, bool $directory): void
{
    for ($attempt = 0; $attempt < 20; $attempt++) {
        clearstatcache(true, $path);
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }

        if (! $directory) {
            @chmod($path, 0666);
        }
        $removed = $directory ? @rmdir($path) : @unlink($path);
        if ($removed) {
            return;
        }
        usleep(10_000);
    }

    throw new RuntimeException('Could not remove the isolated release manifest fixture path.');
}

/** @param array<string, mixed> $fixture */
function executeReleaseManifestVerifier(array $fixture): Process
{
    $json = json_encode($fixture['manifest'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    file_put_contents($fixture['manifest_path'], $json.PHP_EOL);

    $process = new Process([
        PHP_BINARY,
        dirname(__DIR__, 2).'/scripts/release/verify-source-manifest.php',
        '--manifest='.$fixture['manifest_path'],
        '--checkout='.$fixture['checkout'],
    ]);
    $process->setTimeout(30);
    $process->run();

    return $process;
}

it('accepts an exact reviewed source manifest for a clean candidate revision', function (): void {
    $fixture = makeReleaseManifestFixture();

    try {
        $process = executeReleaseManifestVerifier($fixture);

        expect($process->isSuccessful())->toBeTrue()
            ->and($process->getOutput())->toContain('Release source manifest verified: 1 exact source entries')
            ->and($process->getErrorOutput())->toBe('');
    } finally {
        removeReleaseManifestFixture($fixture['root']);
    }
});

it('accepts only the sanitised legacy credential handoff as an exact security remediation', function (): void {
    $fixture = makeReleaseManifestFixture(
        'docs/hero-unification-v3-handoff.md',
        "# Legacy handoff\n\n- SSH: [removed; use approved secure deployment access]\n- Login: use an approved dev/demo application account from the secure credential channel.\n",
    );

    try {
        $process = executeReleaseManifestVerifier($fixture);

        expect($process->isSuccessful())->toBeTrue()
            ->and($process->getOutput())->toContain('Release source manifest verified: 1 exact source entries');
    } finally {
        removeReleaseManifestFixture($fixture['root']);
    }
});

it('rejects an unsanitised legacy credential handoff', function (): void {
    $fixture = makeReleaseManifestFixture(
        'docs/hero-unification-v3-handoff.md',
        "# Legacy handoff\n\n- SSH: access details still present\n- Login: access details still present\n",
    );

    try {
        $process = executeReleaseManifestVerifier($fixture);

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('credential-remediation handoff is not sanitised');
    } finally {
        removeReleaseManifestFixture($fixture['root']);
    }
});

it('rejects force-added Wayfinder output even when the manifest relabels it as source', function (string $generatedPath): void {
    $fixture = makeReleaseManifestFixture($generatedPath);

    try {
        $process = executeReleaseManifestVerifier($fixture);

        expect($fixture['manifest']['files'][0]['source_or_generated'])->toBe('source')
            ->and($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('candidate revision contains an excluded path');
    } finally {
        removeReleaseManifestFixture($fixture['root']);
    }
})->with([
    'resources/js/actions/App/Http/Controllers/GeneratedController.ts',
    'resources/js/routes/security-devices/index.ts',
    'resources/js/wayfinder/index.ts',
]);

it('rejects force-added browser evidence even when the manifest relabels it as source', function (string $evidencePath): void {
    $fixture = makeReleaseManifestFixture($evidencePath);

    try {
        $process = executeReleaseManifestVerifier($fixture);

        expect($fixture['manifest']['files'][0]['source_or_generated'])->toBe('source')
            ->and($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('candidate revision contains an excluded path');
    } finally {
        removeReleaseManifestFixture($fixture['root']);
    }
})->with([
    'tests/Browser/final-release.png',
    'docs/runbooks/final-release.webm',
    'tests/Browser/final-release.har',
]);

it('fails closed for invalid manifest source declarations', function (string $case, string $expectedFailure): void {
    $fixture = makeReleaseManifestFixture();

    try {
        $row = $fixture['manifest']['files'][0];

        switch ($case) {
            case 'duplicate':
                $fixture['manifest']['files'][] = $row;
                break;
            case 'glob':
                $fixture['manifest']['files'][0]['path'] = 'app/Domain/It/*.php';
                break;
            case 'excluded':
                $fixture['manifest']['files'][0]['path'] = '.env.dusk.local';
                break;
            case 'missing path':
                $fixture['manifest']['files'][0]['path'] = 'app/Domain/It/Missing.php';
                break;
            case 'missing hash':
                unset($fixture['manifest']['files'][0]['sha256']);
                break;
            case 'mismatched hash':
                $fixture['manifest']['files'][0]['sha256'] = str_repeat('0', 64);
                break;
            case 'generated':
                $fixture['manifest']['files'][0]['source_or_generated'] = 'generated';
                break;
            case 'pending review':
                $fixture['manifest']['files'][0]['review']['decision'] = 'pending';
                break;
            case 'placeholder reviewer':
                $fixture['manifest']['files'][0]['review']['reviewer'] = 'pending';
                break;
            case 'unverified evidence':
                $fixture['manifest']['files'][0]['verification']['result'] = 'pending';
                break;
            case 'future verification':
                $fixture['manifest']['files'][0]['verification']['observed_at_utc'] = '2999-01-01T00:00:00Z';
                break;
            case 'revision mismatch':
                $fixture['manifest']['candidate_revision'] = $fixture['manifest']['base_revision'];
                $fixture['manifest']['origin_main_revision'] = $fixture['manifest']['base_revision'];
                break;
        }

        $process = executeReleaseManifestVerifier($fixture);

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toStartWith('Release source manifest verification refused:')
            ->and($process->getErrorOutput())->toContain($expectedFailure);
    } finally {
        removeReleaseManifestFixture($fixture['root']);
    }
})->with([
    ['duplicate', 'manifest contains a duplicate path'],
    ['glob', 'exact relative path without a glob'],
    ['excluded', 'manifest contains an excluded path'],
    ['missing path', 'modified manifest path does not match the revisions'],
    ['missing hash', 'missing required fields'],
    ['mismatched hash', 'SHA-256 hash does not match'],
    ['generated', 'source_or_generated must be source'],
    ['pending review', 'review.decision must be approved'],
    ['placeholder reviewer', 'review.reviewer must identify the approving reviewer'],
    ['unverified evidence', 'verification.result must be passed'],
    ['future verification', 'verification.observed_at_utc cannot be in the future'],
    ['revision mismatch', 'candidate checkout revision does not match the manifest'],
]);

it('fails closed when the candidate checkout is dirty', function (string $case): void {
    $fixture = makeReleaseManifestFixture();

    try {
        if ($case === 'untracked') {
            file_put_contents($fixture['checkout'].DIRECTORY_SEPARATOR.'local-output.txt', "not release source\n");
        } else {
            file_put_contents(
                $fixture['checkout'].DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Domain'.DIRECTORY_SEPARATOR.'It'.DIRECTORY_SEPARATOR.'ReleaseProof.php',
                "<?php\n\nreturn 'dirty';\n",
            );
        }

        $process = executeReleaseManifestVerifier($fixture);

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('candidate checkout contains tracked or untracked changes');
    } finally {
        removeReleaseManifestFixture($fixture['root']);
    }
})->with(['untracked', 'tracked']);

it('fails closed when an otherwise valid manifest omits one revision change', function (): void {
    $fixture = makeReleaseManifestFixture();

    try {
        $omittedPath = $fixture['checkout'].DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Domain'.DIRECTORY_SEPARATOR.'It'.DIRECTORY_SEPARATOR.'OmittedProof.php';
        file_put_contents($omittedPath, "<?php\n\nreturn 'omitted';\n");
        runReleaseManifestFixtureCommand(['git', 'add', '--', 'app/Domain/It/OmittedProof.php'], $fixture['checkout']);
        runReleaseManifestFixtureCommand(['git', 'commit', '--quiet', '-m', 'omitted source'], $fixture['checkout']);
        $candidateRevision = runReleaseManifestFixtureCommand(['git', 'rev-parse', 'HEAD'], $fixture['checkout']);
        runReleaseManifestFixtureCommand(['git', 'update-ref', 'refs/remotes/origin/main', $candidateRevision], $fixture['checkout']);
        $fixture['manifest']['candidate_revision'] = $candidateRevision;
        $fixture['manifest']['origin_main_revision'] = $candidateRevision;

        $process = executeReleaseManifestVerifier($fixture);

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('manifest does not exactly match the revision diff');
    } finally {
        removeReleaseManifestFixture($fixture['root']);
    }
});
