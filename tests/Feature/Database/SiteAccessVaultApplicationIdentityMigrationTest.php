<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function siteAccessVaultApplicationIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_08_01_000010_realign_site_access_vault_application_identity.php',
    );
}

function withSiteAccessVaultIdentityDatabase(Closure $callback): void
{
    $connection = 'site_access_vault_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-site-vault-identity-');

    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary Site access-vault migration database.');
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
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('credential_types', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('key', 50);
            $table->string('label', 100);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unique(['tenant_id', 'key']);
        });
        Schema::create('site_vendors', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('site_id');
            $table->string('service_type');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('site_credentials', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('site_id');
            $table->string('label');
            $table->string('credential_type', 30);
            $table->timestamp('last_rotated_at')->nullable();
        });
        Schema::create('site_credential_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('credential_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('action');
            $table->timestamp('created_at');
            $table->index('created_at');
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('fails before access-vault schema mutation when an application type key collides', function (): void {
    withSiteAccessVaultIdentityDatabase(function (): void {
        DB::table('credential_types')->insert([
            ['tenant_id' => 11, 'key' => 'password', 'label' => 'Password', 'active' => true, 'sort_order' => 0],
            ['tenant_id' => 22, 'key' => 'password', 'label' => 'Password duplicate', 'active' => true, 'sort_order' => 0],
        ]);

        expect(fn () => siteAccessVaultApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'credential type identity');

        expect(Schema::hasColumn('site_credential_audit_logs', 'site_id'))->toBeFalse()
            ->and(Schema::hasIndex('credential_types', 'credential_types_key_uq'))->toBeFalse();
    });
});

it('enforces application identities and backfills durable Site audit provenance', function (): void {
    withSiteAccessVaultIdentityDatabase(function (): void {
        $siteId = DB::table('sites')->insertGetId(['name' => 'Hamilton House']);
        DB::table('credential_types')->insert([
            'tenant_id' => 11,
            'key' => 'password',
            'label' => 'Password',
            'active' => true,
            'sort_order' => 0,
        ]);
        $credentialId = DB::table('site_credentials')->insertGetId([
            'tenant_id' => 11,
            'site_id' => $siteId,
            'label' => 'Router admin',
            'credential_type' => 'password',
            'last_rotated_at' => null,
        ]);
        $auditId = DB::table('site_credential_audit_logs')->insertGetId([
            'tenant_id' => 11,
            'credential_id' => $credentialId,
            'user_id' => 7,
            'action' => 'reveal',
            'created_at' => now(),
        ]);

        $migration = siteAccessVaultApplicationIdentityMigration();
        $migration->up();

        foreach ([
            ['credential_types', 'credential_types_key_uq'],
            ['credential_types', 'credential_types_active_sort_idx'],
            ['site_credentials', 'site_credentials_site_rotation_idx'],
            ['site_vendors', 'site_vendors_site_active_service_idx'],
            ['site_credential_audit_logs', 'site_credential_audit_site_created_idx'],
            ['site_credential_audit_logs', 'site_credential_audit_credential_created_idx'],
        ] as [$table, $index]) {
            expect(Schema::hasIndex($table, $index))->toBeTrue();
        }

        foreach ([
            ['credential_types', 'credential_types_tenant_id_key_unique'],
            ['credential_types', 'credential_types_tenant_id_index'],
            ['site_credentials', 'site_credentials_tenant_id_index'],
            ['site_vendors', 'site_vendors_tenant_id_index'],
            ['site_credential_audit_logs', 'site_credential_audit_logs_tenant_id_index'],
        ] as [$table, $index]) {
            expect(Schema::hasIndex($table, $index))->toBeFalse();
        }

        expect(DB::table('site_credential_audit_logs')->find($auditId))
            ->site_id->toBe($siteId)
            ->credential_label->toBe('Router admin')
            ->credential_type->toBe('password');

        expect(fn () => DB::table('credential_types')->insert([
            'tenant_id' => 22,
            'key' => 'password',
            'label' => 'Duplicate',
            'active' => true,
            'sort_order' => 1,
        ]))->toThrow(QueryException::class);

        $migration->down();

        expect(Schema::hasColumn('site_credential_audit_logs', 'site_id'))->toBeFalse()
            ->and(Schema::hasColumn('site_credential_audit_logs', 'credential_label'))->toBeFalse()
            ->and(Schema::hasColumn('site_credential_audit_logs', 'credential_type'))->toBeFalse();

        foreach ([
            ['credential_types', 'credential_types_tenant_id_key_unique'],
            ['credential_types', 'credential_types_tenant_id_index'],
            ['site_credentials', 'site_credentials_tenant_id_index'],
            ['site_vendors', 'site_vendors_tenant_id_index'],
            ['site_credential_audit_logs', 'site_credential_audit_logs_tenant_id_index'],
        ] as [$table, $index]) {
            expect(Schema::hasIndex($table, $index))->toBeTrue();
        }
    });
});
