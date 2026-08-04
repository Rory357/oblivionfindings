<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function customFieldApplicationIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_27_000002_enforce_hr_custom_field_application_identity.php',
    );
}

function withCustomFieldIdentityDatabase(Closure $callback): void
{
    $connection = 'custom_field_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-custom-field-identity-');

    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary custom-field migration database.');
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
        Schema::create('hr_custom_field_definitions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('field_key');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('name');
            $table->unique(['tenant_id', 'field_key']);
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('fails before custom field schema mutation when an application key collides', function (): void {
    withCustomFieldIdentityDatabase(function (): void {
        DB::table('hr_custom_field_definitions')->insert([
            ['tenant_id' => 11, 'field_key' => 'uniform_size', 'name' => 'Uniform size'],
            ['tenant_id' => 22, 'field_key' => 'uniform_size', 'name' => 'Uniform size'],
        ]);
        $before = collect(Schema::getIndexes('hr_custom_field_definitions'))
            ->mapWithKeys(fn (array $index): array => [$index['name'] => $index['columns']])
            ->all();

        expect(fn () => customFieldApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'custom-field key');

        $after = collect(Schema::getIndexes('hr_custom_field_definitions'))
            ->mapWithKeys(fn (array $index): array => [$index['name'] => $index['columns']])
            ->all();
        expect($after)->toBe($before)
            ->and(Schema::hasIndex('hr_custom_field_definitions', 'hr_custom_fields_field_key_uq'))->toBeFalse();
    });
});

it('enforces and rolls back the application custom field identity and read index', function (): void {
    withCustomFieldIdentityDatabase(function (): void {
        DB::table('hr_custom_field_definitions')->insert([
            'tenant_id' => 11,
            'field_key' => 'uniform_size',
            'name' => 'Uniform size',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $migration = customFieldApplicationIdentityMigration();
        $migration->up();

        expect(Schema::hasIndex('hr_custom_field_definitions', 'hr_custom_fields_field_key_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_custom_field_definitions', 'hr_custom_fields_active_sort_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_custom_field_definitions', 'hr_custom_field_definitions_tenant_id_field_key_unique'))->toBeFalse()
            ->and(Schema::hasIndex('hr_custom_field_definitions', 'hr_custom_field_definitions_tenant_id_index'))->toBeFalse();

        expect(fn () => DB::table('hr_custom_field_definitions')->insert([
            'tenant_id' => 22,
            'field_key' => 'uniform_size',
            'name' => 'Duplicate uniform size',
        ]))->toThrow(QueryException::class);

        $migration->down();

        expect(Schema::hasIndex('hr_custom_field_definitions', 'hr_custom_fields_field_key_uq'))->toBeFalse()
            ->and(Schema::hasIndex('hr_custom_field_definitions', 'hr_custom_fields_active_sort_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_custom_field_definitions', 'hr_custom_field_definitions_tenant_id_field_key_unique'))->toBeTrue()
            ->and(Schema::hasIndex('hr_custom_field_definitions', 'hr_custom_field_definitions_tenant_id_index'))->toBeTrue();
    });
});
