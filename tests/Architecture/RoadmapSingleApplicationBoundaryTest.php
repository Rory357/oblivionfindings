<?php

it('keeps the Roadmap runtime application scoped', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $files = [];

    foreach ([
        'app/Domain/Roadmap/Http',
        'app/Domain/Roadmap/Jobs',
        'app/Domain/Roadmap/Policies',
        'app/Domain/Roadmap/Services',
    ] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory));
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
    }

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $relative = ltrim(substr($file, strlen($root)), '/');
        $contents = file_get_contents($file);

        expect($contents)
            ->and($relative)->toBeString()
            ->and($contents)->not->toMatch('/\btenant_id\b|\btenantId\b|\bforTenant\s*\(|\bsameTenant\b|\bassertTenant\b|Tenant scope/');
    }
});

it('keeps Roadmap compatibility storage write only', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $models = glob($root.'/app/Domain/Roadmap/Models/*.php') ?: [];

    expect($models)->not->toBeEmpty();

    foreach ($models as $file) {
        $contents = file_get_contents($file);

        expect($contents)
            ->and($file)->toBeString()
            ->and($contents)->toContain('WritesLegacyStorageContext')
            ->and($contents)->not->toMatch('/\bscopeForTenant\b|\bforTenant\s*\(|\bgenerateCode\s*\(\s*\?int/');
    }
});

it('keeps one registered Roadmap policy set', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));

    expect(glob($root.'/app/Policies/Roadmap/*.php') ?: [])->toBe([]);
});
