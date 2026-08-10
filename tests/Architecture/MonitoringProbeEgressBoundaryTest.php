<?php

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Services\EgressPolicy;

it('keeps authorised target construction behind the egress policy', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $appRoot = $root.'/app';
    $policyPath = $appRoot.'/Domain/Monitoring/Services/EgressPolicy.php';
    $factoryPath = $appRoot.'/Domain/Monitoring/Data/AuthorizedProbeTarget.php';
    $constructionSites = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appRoot));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        $contents = file_get_contents($path);
        if (str_contains($contents, 'fromEgressPolicy(')) {
            $constructionSites[] = $path;
        }

        expect($contents)->not->toContain('new AuthorizedProbeTarget(');
    }

    $authorised = new ReflectionClass(AuthorizedProbeTarget::class);
    $raw = new ReflectionClass(ProbeTarget::class);
    $authorise = new ReflectionMethod(EgressPolicy::class, 'authorise');

    expect($authorised->getConstructor()?->isPrivate())->toBeTrue()
        ->and($raw->getConstructor()?->isPrivate())->toBeTrue()
        ->and($constructionSites)->toHaveCount(2)
        ->and($constructionSites)->toEqualCanonicalizing([$factoryPath, $policyPath])
        ->and($authorise->getReturnType()?->getName())->toBe(AuthorizedProbeTarget::class)
        ->and(is_a(ProbeTarget::class, AuthorizedProbeTarget::class, true))->toBeFalse();
});
