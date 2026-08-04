<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function hrAutomationWebhookApplicationIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_08_02_000016_realign_hr_automation_webhooks_application_identity.php',
    );
}

function withHrAutomationWebhookIdentityDatabase(Closure $callback): void
{
    $connection = 'hr_automation_webhook_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-hr-automation-webhook-');

    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary HR automation and webhook migration database.');
    }

    config()->set("database.connections.{$connection}", [
        'driver' => 'sqlite',
        'database' => $databasePath,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge($connection);
    DB::setDefaultConnection($connection);

    try {
        Schema::create('hr_webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'is_active'], 'hr_webhook_endpoint_tenant_active_idx');
            $table->unique(['tenant_id', 'name'], 'hr_webhook_endpoint_tenant_name_unique');
        });
        Schema::create('hr_webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('endpoint_id');
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('event_type');
            $table->dateTime('executed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'event_type'], 'hr_webhook_delivery_tenant_event_idx');
        });
        Schema::create('hr_automation_rules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('event_type');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'event_type', 'is_active'], 'hr_automation_rule_tenant_event_active_idx');
            $table->unique(['tenant_id', 'name'], 'hr_automation_rule_tenant_name_unique');
        });
        Schema::create('hr_automation_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('rule_id');
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('event_type');
            $table->dateTime('executed_at');
            $table->timestamps();
            $table->index(['tenant_id', 'event_type', 'executed_at'], 'hr_automation_run_tenant_event_executed_idx');
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('fails before schema mutation when application webhook or automation names collide', function (): void {
    withHrAutomationWebhookIdentityDatabase(function (): void {
        DB::table('hr_webhook_endpoints')->insert([
            ['tenant_id' => 11, 'name' => 'Operations hook'],
            ['tenant_id' => 22, 'name' => ' operations hook '],
        ]);
        DB::table('hr_automation_rules')->insert([
            ['tenant_id' => 11, 'name' => 'New starter', 'event_type' => 'employee.created'],
        ]);
        $beforeEndpoints = Schema::getIndexes('hr_webhook_endpoints');
        $beforeDeliveries = Schema::getIndexes('hr_webhook_deliveries');
        $beforeRules = Schema::getIndexes('hr_automation_rules');
        $beforeRuns = Schema::getIndexes('hr_automation_runs');

        expect(fn () => hrAutomationWebhookApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'webhook endpoint identity');

        expect(Schema::getIndexes('hr_webhook_endpoints'))->toBe($beforeEndpoints)
            ->and(Schema::getIndexes('hr_webhook_deliveries'))->toBe($beforeDeliveries)
            ->and(Schema::getIndexes('hr_automation_rules'))->toBe($beforeRules)
            ->and(Schema::getIndexes('hr_automation_runs'))->toBe($beforeRuns)
            ->and(Schema::hasColumn('hr_webhook_endpoints', 'application_name_key'))->toBeFalse()
            ->and(Schema::hasColumn('hr_webhook_deliveries', 'retry_of_id'))->toBeFalse();
    });

    withHrAutomationWebhookIdentityDatabase(function (): void {
        DB::table('hr_webhook_endpoints')->insert([
            ['tenant_id' => 11, 'name' => 'Operations hook'],
        ]);
        DB::table('hr_automation_rules')->insert([
            ['tenant_id' => 11, 'name' => 'New starter', 'event_type' => 'employee.created'],
            ['tenant_id' => 22, 'name' => ' new starter ', 'event_type' => 'employee.created'],
        ]);

        expect(fn () => hrAutomationWebhookApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'automation rule identity');

        expect(Schema::hasColumn('hr_webhook_endpoints', 'application_name_key'))->toBeFalse()
            ->and(Schema::hasColumn('hr_automation_rules', 'application_name_key'))->toBeFalse()
            ->and(Schema::hasColumn('hr_webhook_deliveries', 'retry_of_id'))->toBeFalse();
    });
});

it('enforces application identities and retry lineage then restores compatibility indexes', function (): void {
    withHrAutomationWebhookIdentityDatabase(function (): void {
        DB::table('hr_webhook_endpoints')->insert([
            'tenant_id' => 11,
            'name' => 'Operations hook',
        ]);
        DB::table('hr_automation_rules')->insert([
            'tenant_id' => 11,
            'name' => 'New starter',
            'event_type' => 'employee.created',
        ]);

        $migration = hrAutomationWebhookApplicationIdentityMigration();
        $migration->up();

        expect(Schema::hasIndex('hr_webhook_endpoints', 'hr_webhook_endpoints_name_key_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_webhook_endpoints', 'hr_webhook_endpoints_active_name_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_webhook_deliveries', 'hr_webhook_deliveries_event_created_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_webhook_deliveries', 'hr_webhook_deliveries_retry_of_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_automation_rules', 'hr_automation_rules_name_key_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_automation_rules', 'hr_automation_rules_event_active_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_automation_runs', 'hr_automation_runs_event_executed_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_webhook_endpoints', 'hr_webhook_endpoint_tenant_name_unique'))->toBeFalse()
            ->and(Schema::hasIndex('hr_automation_rules', 'hr_automation_rule_tenant_name_unique'))->toBeFalse();

        expect(fn () => DB::table('hr_webhook_endpoints')->insert([
            'tenant_id' => 22,
            'name' => ' operations hook ',
        ]))->toThrow(QueryException::class);
        expect(fn () => DB::table('hr_automation_rules')->insert([
            'tenant_id' => 22,
            'name' => 'NEW STARTER',
            'event_type' => 'employee.created',
        ]))->toThrow(QueryException::class);

        DB::table('hr_webhook_deliveries')->insert([
            'id' => 1,
            'endpoint_id' => 1,
            'tenant_id' => 11,
            'event_type' => 'employee.created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('hr_webhook_deliveries')->insert([
            'endpoint_id' => 1,
            'tenant_id' => 22,
            'retry_of_id' => 1,
            'event_type' => 'employee.created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        expect(fn () => DB::table('hr_webhook_deliveries')->insert([
            'endpoint_id' => 1,
            'tenant_id' => 33,
            'retry_of_id' => 1,
            'event_type' => 'employee.created',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);

        $migration->down();

        expect(Schema::hasColumn('hr_webhook_endpoints', 'application_name_key'))->toBeFalse()
            ->and(Schema::hasColumn('hr_automation_rules', 'application_name_key'))->toBeFalse()
            ->and(Schema::hasColumn('hr_webhook_deliveries', 'retry_of_id'))->toBeFalse();

        expect(Schema::hasIndex('hr_webhook_endpoints', 'hr_webhook_endpoint_tenant_name_unique'))->toBeTrue()
            ->and(Schema::hasIndex('hr_webhook_endpoints', 'hr_webhook_endpoint_tenant_active_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_webhook_deliveries', 'hr_webhook_delivery_tenant_event_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_automation_rules', 'hr_automation_rule_tenant_name_unique'))->toBeTrue()
            ->and(Schema::hasIndex('hr_automation_rules', 'hr_automation_rule_tenant_event_active_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_automation_runs', 'hr_automation_run_tenant_event_executed_idx'))->toBeTrue();
    });
});
