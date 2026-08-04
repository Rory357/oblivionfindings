<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function siteProfileApplicationIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_08_02_000015_realign_site_profile_application_identity.php',
    );
}

function withSiteProfileIdentityDatabase(Closure $callback): void
{
    $connection = 'site_profile_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-site-profile-');

    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary Site Profile migration database.');
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
        Schema::create('site_contacts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('site_id');
            $table->string('type')->nullable();
            $table->string('name');
            $table->string('role')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'type']);
        });
        Schema::create('site_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('site_id');
            $table->date('expiry_date')->nullable();
        });
        Schema::create('site_checklist_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('template_id');
            $table->string('frequency');
            $table->boolean('is_active')->default(true);
            $table->unique(['site_id', 'template_id']);
        });
        Schema::create('site_checklist_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('site_id');
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('description')->nullable();
            $table->string('group')->nullable();
            $table->string('module')->nullable();
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
        Schema::create('role_permission', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->primary(['role_id', 'permission_id']);
        });

        DB::table('roles')->insert([
            ['name' => 'admin', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'provider_manager', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'team_lead', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('fails before Site Profile schema mutation when canonical contact identity collides', function (): void {
    withSiteProfileIdentityDatabase(function (): void {
        DB::table('site_contacts')->insert([
            [
                'tenant_id' => 11,
                'site_id' => 8,
                'type' => 'Site Lead',
                'name' => ' Taylor Lead ',
                'is_primary' => false,
            ],
            [
                'tenant_id' => 22,
                'site_id' => 8,
                'type' => 'site-lead',
                'name' => 'taylor lead',
                'is_primary' => false,
            ],
        ]);

        expect(fn () => siteProfileApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'canonical Site contact identity');

        expect(Schema::hasIndex('site_contacts', 'site_contacts_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('site_contacts', 'site_contacts_site_type_name_uq'))->toBeFalse()
            ->and(DB::table('permissions')->where('key', 'sites.viewAll')->exists())->toBeFalse();
    });
});

it('enforces Site contact identities and installs explicit application-wide Site access', function (): void {
    withSiteProfileIdentityDatabase(function (): void {
        DB::table('site_contacts')->insert([
            'tenant_id' => 11,
            'site_id' => 8,
            'type' => 'Site Lead',
            'name' => ' Taylor Lead ',
            'is_primary' => true,
        ]);

        $migration = siteProfileApplicationIdentityMigration();
        $migration->up();

        expect(DB::table('site_contacts')->value('type'))->toBe('site_lead')
            ->and(DB::table('site_contacts')->value('name'))->toBe('Taylor Lead')
            ->and(Schema::hasIndex('site_contacts', 'site_contacts_site_type_name_uq'))->toBeTrue()
            ->and(Schema::hasIndex('site_contacts', 'site_contacts_one_primary_uq'))->toBeTrue()
            ->and(Schema::hasIndex('site_documents', 'site_documents_site_expiry_idx'))->toBeTrue()
            ->and(Schema::hasIndex(
                'site_checklist_assignments',
                'site_checklist_assignments_site_active_frequency_idx',
            ))->toBeTrue();

        foreach ([
            'site_contacts',
            'site_documents',
            'site_checklist_assignments',
            'site_checklist_runs',
        ] as $table) {
            expect(Schema::hasIndex($table, "{$table}_tenant_id_index"))->toBeFalse();
        }

        expect(fn () => DB::table('site_contacts')->insert([
            'site_id' => 8,
            'type' => 'site_lead',
            'name' => 'taylor lead',
            'is_primary' => false,
        ]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('site_contacts')->insert([
                'site_id' => 8,
                'type' => 'manager',
                'name' => 'Another primary',
                'is_primary' => true,
            ]))->toThrow(QueryException::class);

        $permissionId = DB::table('permissions')->where('key', 'sites.viewAll')->value('id');
        $grantedRoles = DB::table('role_permission')
            ->join('roles', 'roles.id', '=', 'role_permission.role_id')
            ->where('permission_id', $permissionId)
            ->orderBy('roles.name')
            ->pluck('roles.name')
            ->all();
        expect($grantedRoles)->toBe(['admin', 'provider_manager']);

        $migration->down();

        expect(Schema::hasIndex('site_contacts', 'site_contacts_site_type_name_uq'))->toBeFalse()
            ->and(Schema::hasIndex('site_contacts', 'site_contacts_one_primary_uq'))->toBeFalse()
            ->and(Schema::hasIndex('site_documents', 'site_documents_site_expiry_idx'))->toBeFalse()
            ->and(Schema::hasIndex(
                'site_checklist_assignments',
                'site_checklist_assignments_site_active_frequency_idx',
            ))->toBeFalse();

        foreach ([
            'site_contacts',
            'site_documents',
            'site_checklist_assignments',
            'site_checklist_runs',
        ] as $table) {
            expect(Schema::hasIndex($table, "{$table}_tenant_id_index"))->toBeTrue();
        }
    });
});
