<?php

require_once dirname(__DIR__, 2).'/scripts/test/fast.php';
require_once dirname(__DIR__, 2).'/scripts/test/changed.php';

it('runs the parallel suite with one worker per two cores, at most eight', function (int $cpus, ?string $configured, string $expected): void {
    $command = oblivionFastTestCommand([], $cpus, $configured);

    expect(array_slice($command, 1))->toBe(['artisan', 'test', '--parallel', '--without-databases', $expected, '--max-batch-size=20']);
})->with([
    'sixteen cores' => [16, null, '--processes=8'],
    'thirty-two cores' => [32, null, '--processes=8'],
    'four cores' => [4, null, '--processes=2'],
    'one core' => [1, null, '--processes=2'],
    'TEST_PROCESSES' => [16, '12', '--processes=12'],
    'a non-number in TEST_PROCESSES' => [16, 'lots', '--processes=8'],
]);

it('runs no more workers than MySQL\'s open-table cache holds databases for', function (?int $fit, ?string $configured, string $expected): void {
    $command = oblivionFastTestCommand([], 16, $configured, $fit);

    expect(preg_grep('/\A--processes=/', $command))->toBe([5 => $expected]);
})->with([
    'the default cache: three databases' => [3, null, '--processes=3'],
    'a cache for one database' => [1, null, '--processes=1'],
    'a raised cache' => [16, null, '--processes=8'],
    'MySQL out of reach' => [null, null, '--processes=8'],
    'TEST_PROCESSES still wins' => [3, '6', '--processes=6'],
]);

it('passes Pest arguments through and lets --processes and --max-batch-size override the defaults', function (): void {
    expect(array_slice(oblivionFastTestCommand(['--processes=3', 'tests/Feature/Chat', '--stop-on-failure'], 16, '12'), 1))->toBe([
        'artisan', 'test', '--parallel', '--without-databases', '--max-batch-size=20', '--processes=3', 'tests/Feature/Chat', '--stop-on-failure',
    ])->and(array_slice(oblivionFastTestCommand(['--max-batch-size=5'], 16, null), 1))->toBe([
        'artisan', 'test', '--parallel', '--without-databases', '--processes=8', '--max-batch-size=5',
    ]);
});

it('picks changed tests, tests naming a changed class, and the folders of a touched module', function (): void {
    $selection = oblivionChangedSelection(
        [
            'app/Domain/Chat/Services/ChatOffboarding.php',
            'app/Models/Role.php',
            'database/migrations/2026_10_31_000000_add_left_at.php',
            'resources/js/pages/chat/index.tsx',
            'tests/Feature/Auth/AuthenticationTest.php',
            'tests/Feature/Chat/ChatLockingTest.php',
        ],
        static fn (string $class): array => match ($class) {
            'ChatOffboarding' => ['tests/Feature/Chat/ChatOffboardingTest.php'],
            'Role' => ['tests/Feature/RbacTest.php', 'tests/Unit/RoleTest.php'],
            default => [],
        },
        static fn (string $directory): bool => $directory === 'tests/Feature/Chat',
    );

    expect($selection['tests'])->toBe([
        'tests/Feature/Auth/AuthenticationTest.php' => ['changed'],
        'tests/Feature/Chat' => ['module Chat'],
        'tests/Feature/RbacTest.php' => ['uses Role'],
        'tests/Unit/RoleTest.php' => ['uses Role'],
    ])->and($selection['notes'])->toBe([
        'Migrations changed: the kept test databases apply them on the next run.',
    ]);
});

it('points at the whole suite when the test setup itself changed', function (): void {
    $selection = oblivionChangedSelection(
        ['tests/TestCase.php', 'tests/Support/SeedReplay.php'],
        static fn (): array => [],
        static fn (): bool => false,
    );

    expect($selection['tests'])->toBe([])
        ->and($selection['notes'])->toHaveCount(1)
        ->and($selection['notes'][0])->toContain('composer test:fast runs everything');
});
