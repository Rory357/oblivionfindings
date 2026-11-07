<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedReplay;
use Tests\TestCase;

function realSeed(TestCase $test, string $seeder): Closure
{
    return fn () => $test->artisan('db:seed', ['--class' => $seeder, '--no-interaction' => true])->run();
}

it('leaves every table exactly as the real seeder does', function (string $seeder): void {
    $result = SeedReplay::compare(realSeed($this, $seeder));

    expect($result)->not->toBeNull()
        ->and($result['replayed'])->toBe($result['real']);
})->with(SeedReplay::SEEDERS);

it('replays the role links of users that exist before seeding', function (): void {
    User::factory()->create(['role' => 'coordinator']);
    User::factory()->create(['role' => 'finance']);

    $result = SeedReplay::compare(realSeed($this, RbacSeeder::class));

    expect($result)->not->toBeNull()
        ->and($result['replayed'])->toBe($result['real']);
});

it('seeds by replay once the seeder has run in this process', function (): void {
    DB::beginTransaction();
    $this->seed(RbacSeeder::class);
    DB::rollBack();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    $this->seed(RbacSeeder::class);

    expect($queries)->toBe(0)
        ->and(Role::where('name', 'admin')->exists())->toBeTrue();
})->skip(fn (): bool => ! SeedReplay::enabled(), 'TEST_SEED_REPLAY is off');

it('runs the real seeder when a test seeds outside its transaction', function (): void {
    DB::rollBack(0);

    try {
        expect(SeedReplay::seed(RbacSeeder::class, fn () => null))->toBeFalse();
    } finally {
        DB::beginTransaction();
    }
});
