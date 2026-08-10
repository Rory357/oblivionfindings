<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function policyApplicationIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_27_000003_enforce_hr_policy_application_identity.php',
    );
}

function withPolicyIdentityDatabase(Closure $callback): void
{
    $connection = 'policy_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-policy-identity-');

    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary policy migration database.');
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
        Schema::create('hr_policies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('slug');
            $table->string('category');
            $table->boolean('is_active')->default(true);
            $table->string('title');
            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'category', 'is_active']);
        });
        Schema::create('hr_policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('policy_id');
            $table->unsignedInteger('version_number');
            $table->boolean('is_current')->default(true);
            $table->index(['policy_id', 'is_current']);
        });
        Schema::create('hr_policy_attestations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('policy_id');
            $table->unsignedBigInteger('policy_version_id');
            $table->timestamp('attested_at')->nullable();
            $table->index(['user_id', 'policy_id']);
            $table->index(['tenant_id', 'policy_id']);
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('fails before policy schema mutation when :identity collides', function (
    string $identity,
    string $table,
    array $rows,
): void {
    withPolicyIdentityDatabase(function () use ($identity, $table, $rows): void {
        DB::table($table)->insert($rows);
        $before = collect(['hr_policies', 'hr_policy_versions', 'hr_policy_attestations'])
            ->mapWithKeys(fn (string $name): array => [$name => collect(Schema::getIndexes($name))
                ->mapWithKeys(fn (array $index): array => [$index['name'] => $index['columns']])
                ->all()])
            ->all();

        expect(fn () => policyApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, $identity);

        $after = collect(['hr_policies', 'hr_policy_versions', 'hr_policy_attestations'])
            ->mapWithKeys(fn (string $name): array => [$name => collect(Schema::getIndexes($name))
                ->mapWithKeys(fn (array $index): array => [$index['name'] => $index['columns']])
                ->all()])
            ->all();
        expect($after)->toBe($before);
    });
})->with([
    'policy slug' => [
        'policy slug',
        'hr_policies',
        [
            ['tenant_id' => 11, 'slug' => 'conduct', 'category' => 'general', 'title' => 'Conduct'],
            ['tenant_id' => 22, 'slug' => 'conduct', 'category' => 'general', 'title' => 'Conduct'],
        ],
    ],
    'policy version' => [
        'policy version',
        'hr_policy_versions',
        [
            ['policy_id' => 91, 'version_number' => 1],
            ['policy_id' => 91, 'version_number' => 1],
        ],
    ],
    'current policy version' => [
        'current policy version',
        'hr_policy_versions',
        [
            ['policy_id' => 91, 'version_number' => 1, 'is_current' => true],
            ['policy_id' => 91, 'version_number' => 2, 'is_current' => true],
        ],
    ],
    'policy attestation' => [
        'policy attestation',
        'hr_policy_attestations',
        [
            ['tenant_id' => 11, 'user_id' => 7, 'policy_id' => 91, 'policy_version_id' => 101],
            ['tenant_id' => 22, 'user_id' => 7, 'policy_id' => 91, 'policy_version_id' => 101],
        ],
    ],
]);

it('enforces and rolls back policy identities and application indexes', function (): void {
    withPolicyIdentityDatabase(function (): void {
        DB::table('hr_policies')->insert([
            'tenant_id' => 11,
            'slug' => 'conduct',
            'category' => 'general',
            'title' => 'Conduct',
        ]);
        DB::table('hr_policy_versions')->insert([
            'policy_id' => 91,
            'version_number' => 1,
        ]);
        DB::table('hr_policy_attestations')->insert([
            'tenant_id' => 11,
            'user_id' => 7,
            'policy_id' => 91,
            'policy_version_id' => 101,
        ]);

        $migration = policyApplicationIdentityMigration();
        $migration->up();

        foreach ([
            ['hr_policies', 'hr_policies_slug_uq'],
            ['hr_policies', 'hr_policies_catalogue_idx'],
            ['hr_policy_versions', 'hr_policy_versions_number_uq'],
            ['hr_policy_attestations', 'hr_policy_attestations_user_version_uq'],
            ['hr_policy_attestations', 'hr_policy_attestations_policy_date_idx'],
        ] as [$table, $index]) {
            expect(Schema::hasIndex($table, $index))->toBeTrue();
        }
        foreach ([
            ['hr_policies', 'hr_policies_tenant_id_slug_unique'],
            ['hr_policies', 'hr_policies_tenant_id_index'],
            ['hr_policies', 'hr_policies_tenant_id_category_is_active_index'],
            ['hr_policy_attestations', 'hr_policy_attestations_tenant_id_index'],
            ['hr_policy_attestations', 'hr_policy_attestations_tenant_id_policy_id_index'],
        ] as [$table, $index]) {
            expect(Schema::hasIndex($table, $index))->toBeFalse();
        }

        expect(fn () => DB::table('hr_policies')->insert([
            'tenant_id' => 22,
            'slug' => 'conduct',
            'category' => 'general',
            'title' => 'Duplicate conduct',
        ]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('hr_policy_versions')->insert([
                'policy_id' => 91,
                'version_number' => 1,
            ]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('hr_policy_attestations')->insert([
                'tenant_id' => 22,
                'user_id' => 7,
                'policy_id' => 91,
                'policy_version_id' => 101,
            ]))->toThrow(QueryException::class);

        $migration->down();

        foreach ([
            ['hr_policies', 'hr_policies_tenant_id_slug_unique'],
            ['hr_policies', 'hr_policies_tenant_id_index'],
            ['hr_policies', 'hr_policies_tenant_id_category_is_active_index'],
            ['hr_policy_attestations', 'hr_policy_attestations_tenant_id_index'],
            ['hr_policy_attestations', 'hr_policy_attestations_tenant_id_policy_id_index'],
        ] as [$table, $index]) {
            expect(Schema::hasIndex($table, $index))->toBeTrue();
        }
    });
});
