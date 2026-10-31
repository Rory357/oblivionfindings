<?php

use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptPolicy;
use App\Domain\SecurityDevices\Management\Services\CommandAssignmentFingerprint;
use App\Domain\SecurityDevices\Models\Device;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\OwnedTestDatabase;

it('binds script policy to current assignment even with an older repeatable read snapshot', function () {
    $manager = DB::getFacadeRoot();
    $default = $manager->getDefaultConnection();
    $configuration = config('database.connections.'.$default);
    expect($configuration['driver'])->toBe('mysql')
        ->and(app()->environment())->toBe('testing')
        ->and(str_ends_with($configuration['database'], '_'.getmypid())
            || OwnedTestDatabase::isOwnedBy($configuration['database'], getmypid()))->toBeTrue();
    $prefix = 'script_policy_rr_'.strtolower(Str::random(8)).'_';
    $configuration['prefix'] = $prefix;
    $configuration['isolation_level'] = 'REPEATABLE READ';
    config()->set('database.connections.script_policy_rr_a', $configuration);
    config()->set('database.connections.script_policy_rr_b', $configuration);
    $a = DB::connection('script_policy_rr_a');
    $b = DB::connection('script_policy_rr_b');
    $schema = $b->getSchemaBuilder();
    try {
        // This random, test-owned prefixed table has no production model observers.
        $schema->create('device_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('device_id');
            $table->string('assignable_type');
            $table->unsignedBigInteger('assignable_id');
            $table->string('assignment_type');
            $table->timestamp('assigned_at');
            $table->timestamp('released_at')->nullable();
        });
        $b->table('device_assignments')->insert(['device_id' => 1, 'assignable_type' => 'site', 'assignable_id' => 10, 'assignment_type' => 'permanent', 'assigned_at' => now()->subMinute()]);
        $manager->setDefaultConnection('script_policy_rr_a');
        $device = (new Device)->forceFill(['id' => 1]);
        $a->beginTransaction();
        $old = app(CommandAssignmentFingerprint::class)->forDevice($device);
        $b->table('device_assignments')->where('id', 1)->update(['assignable_id' => 20]);
        expect(app(CommandAssignmentFingerprint::class)->forDevice($device))->toBe($old);
        $manager->setDefaultConnection('script_policy_rr_b');
        $current = app(CommandAssignmentFingerprint::class)->forDevice($device);
        expect($current)->not->toBe($old);
        $manager->setDefaultConnection('script_policy_rr_a');
        $assignment = new ReflectionMethod(EndpointScriptPolicy::class, 'assignment');
        expect($assignment->invoke(app(EndpointScriptPolicy::class), $device))->toBe($current);
    } finally {
        while ($a->transactionLevel() > 0) {
            $a->rollBack();
        }
        $manager->setDefaultConnection($default);
        $schema->dropIfExists('device_assignments');
        DB::purge('script_policy_rr_a');
        DB::purge('script_policy_rr_b');
    }
});
