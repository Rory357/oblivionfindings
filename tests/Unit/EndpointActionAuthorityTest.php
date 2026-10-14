<?php

use App\Domain\SecurityDevices\Management\Http\Controllers\DeviceCommandController;
use App\Domain\SecurityDevices\Management\Http\Requests\StoreDeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandChangeEligibilityService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Permission;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('requires the reviewed actor for native forms and rejects account substitution before invoking the canonical writer', function () {
    $payload = ['capability' => 'endpoint.service.manage', 'parameters' => ['service_name' => 'Spooler', 'operation' => 'restart'], 'reason' => 'Reviewed service purpose.', 'idempotency_key' => 'same-immutable-request', 'impact_acknowledged' => true];
    $form = new StoreDeviceCommandRequest;
    expect(Validator::make($payload, $form->rules())->errors()->has('expected_actor_id'))->toBeTrue();
    expect(Validator::make([...$payload, 'expected_actor_id' => 41], $form->rules())->passes())->toBeTrue();
    expect(Validator::make([...$payload, 'capability' => 'diagnostics.ping'], $form->rules())->passes())->toBeTrue();
    $form = Mockery::mock(StoreDeviceCommandRequest::class)->makePartial();
    $form->shouldReceive('validated')->once()->andReturn([...$payload, 'expected_actor_id' => 41]);
    $actor = new User;
    $actor->forceFill(['id' => 42]);
    $form->shouldReceive('user')->andReturn($actor);
    // An uninitialised final writer makes any accidental call fail instead of
    // reaching persistence; the intended guard must throw HTTP conflict first.
    $writer = (new ReflectionClass(DeviceCommandRequestService::class))->newInstanceWithoutConstructor();
    expect(fn () => app(DeviceCommandController::class)->store($form, new Device, $writer))->toThrow(HttpException::class);
});

it('uses current optional change and approval evidence despite an established MySQL repeatable read snapshot', function () {
    // Only this tiny fictional schema is created. No application migrations or
    // existing data are touched, and the test runs with its app DB on SQLite.
    expect(DB::connection()->getDriverName())->toBe('sqlite');
    $settings = config('database.connections.mysql');
    expect($settings['host'])->toBeIn(['127.0.0.1', 'localhost', '::1']);
    $database = 'obli_action_rr_'.getmypid();
    expect($database)->toMatch('/\Aobli_action_rr_[0-9]+\z/');
    $server = new PDO('mysql:host='.$settings['host'].';port='.$settings['port'], $settings['username'], $settings['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $original = DB::getDefaultConnection();
    try {
        $settings['database'] = $database;
        config()->set('database.connections.obli_rr_reader', $settings);
        config()->set('database.connections.obli_rr_writer', $settings);
        DB::setDefaultConnection('obli_rr_reader');
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('users', fn (Blueprint $t) => $t->id());
        $schema->create('sites', function (Blueprint $t) {
            $t->id();
            $t->boolean('is_active');
            $t->boolean('archived');
            $t->timestamp('archived_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('hr_employee_profiles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('primary_site_id');
            $t->json('secondary_site_ids');
            $t->boolean('is_active');
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('it_tickets', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('site_id');
            $t->string('work_type');
            $t->string('workflow_state');
            $t->boolean('is_sensitive');
            $t->boolean('is_organisation_wide');
        });
        $schema->create('it_changes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('ticket_id');
            $t->string('change_type');
            $t->string('risk_level');
            $t->boolean('is_restricted');
            $t->timestamp('maintenance_starts_at');
            $t->timestamp('maintenance_ends_at');
        });
        $schema->create('it_ticket_links', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('ticket_id');
            $t->string('relationship');
            $t->string('linkable_type');
            $t->unsignedBigInteger('linkable_id');
        });
        $schema->create('it_ticket_approvals', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('it_ticket_id');
            $t->string('status');
            $t->timestamp('expires_at')->nullable();
        });
        DB::table('sites')->insert(['id' => 1, 'is_active' => true, 'archived' => false]);
        DB::table('hr_employee_profiles')->insert(['id' => 1, 'user_id' => 1, 'primary_site_id' => 1, 'secondary_site_ids' => '[]', 'is_active' => true]);
        DB::table('it_tickets')->insert(['id' => 1, 'site_id' => 1, 'work_type' => 'change', 'workflow_state' => 'scheduled', 'is_sensitive' => false, 'is_organisation_wide' => false]);
        DB::table('it_changes')->insert(['id' => 1, 'ticket_id' => 1, 'change_type' => 'normal', 'risk_level' => 'high', 'is_restricted' => false, 'maintenance_starts_at' => now()->subMinute(), 'maintenance_ends_at' => now()->addMinutes(10)]);
        $device = new Device;
        $device->forceFill(['id' => 7]);
        DB::table('it_ticket_links')->insert(['id' => 1, 'ticket_id' => 1, 'relationship' => 'affected_device', 'linkable_type' => $device->getMorphClass(), 'linkable_id' => 7]);
        DB::table('it_ticket_approvals')->insert(['id' => 1, 'it_ticket_id' => 1, 'status' => 'approved']);
        $actor = new User;
        $actor->forceFill(['id' => 1, 'role' => 'support_worker', 'external_clinical_account' => false]);
        $permission = new Permission;
        $permission->forceFill(['id' => 1, 'key' => 'it.manage']);
        $permission->setRelation('pivot', new Pivot(['allowed' => true]));
        $actor->setRelation('permissionOverrides', collect([$permission]));
        $actor->setRelation('roles', collect());
        $service = app(CommandChangeEligibilityService::class);
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        expect($service->isEligible(1, $actor, $device, 1))->toBeTrue();
        DB::connection('obli_rr_writer')->table('it_ticket_approvals')->where('id', 1)->update(['status' => 'cancelled']);
        expect($service->isEligible(1, $actor, $device, 1))->toBeTrue(); // Proves the stale snapshot exists.
        expect(CurrentAuthorizationReads::within(fn ($reads) => $service->forCurrentEvidence($reads)->isEligible(1, $actor, $device, 1)))->toBeFalse();
        DB::rollBack();
        DB::connection('obli_rr_writer')->table('it_ticket_approvals')->where('id', 1)->update(['status' => 'approved']);
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        expect($service->isEligible(1, $actor, $device, 1))->toBeTrue();
        DB::connection('obli_rr_writer')->table('it_tickets')->where('id', 1)->update(['workflow_state' => 'cancelled']);
        expect($service->isEligible(1, $actor, $device, 1))->toBeTrue();
        expect(CurrentAuthorizationReads::within(fn ($reads) => $service->forCurrentEvidence($reads)->isEligible(1, $actor, $device, 1)))->toBeFalse();
    } finally {
        while (DB::connection('obli_rr_reader')->transactionLevel() > 0) {
            DB::connection('obli_rr_reader')->rollBack();
        }
        DB::setDefaultConnection($original);
        DB::purge('obli_rr_reader');
        DB::purge('obli_rr_writer');
        $server->exec('DROP DATABASE `'.$database.'`');
    }
});
