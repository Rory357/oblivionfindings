<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function roadmapApplicationIdentityMigration(): Migration
{
    return require database_path('migrations/2026_08_03_000024_realign_roadmap_application_identity.php');
}

function withRoadmapApplicationIdentityDatabase(Closure $callback): void
{
    $connection = 'roadmap_application_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-roadmap-identity-');

    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary Roadmap migration database.');
    }

    config()->set("database.connections.{$connection}", [
        'driver' => 'sqlite',
        'database' => $databasePath,
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);
    DB::purge($connection);
    DB::setDefaultConnection($connection);

    try {
        Schema::create('roadmap_initiative_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('key', 64);
            $table->unique(['tenant_id', 'key'], 'rdmp_cat_tenant_key_uq');
        });
        Schema::create('roadmap_initiatives', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('code', 40);
            $table->string('status', 32)->default('draft');
            $table->decimal('priority_score', 8, 2)->nullable();
            $table->unsignedSmallInteger('target_fiscal_year')->nullable();
            $table->unsignedTinyInteger('target_quarter')->nullable();
            $table->unique(['tenant_id', 'code'], 'roadmap_initiatives_tenant_id_code_unique');
        });
        Schema::create('roadmap_initiative_site_scopes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('initiative_id');
            $table->unique(['tenant_id', 'initiative_id'], 'rdmp_scope_tenant_init_uq');
        });
        Schema::create('roadmap_initiative_site_scope_sites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('initiative_site_scope_id');
            $table->unsignedBigInteger('site_id');
            $table->string('status', 24)->default('not_started');
            $table->unique(['tenant_id', 'initiative_site_scope_id', 'site_id'], 'roadmap_scope_site_unique');
        });
        Schema::create('roadmap_initiative_budgets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('initiative_id');
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedTinyInteger('quarter')->nullable();
            $table->unique(['tenant_id', 'initiative_id', 'fiscal_year', 'quarter'], 'roadmap_budget_period_unique');
        });
        Schema::create('roadmap_initiative_risk_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('initiative_id');
            $table->unsignedBigInteger('risk_register_entry_id');
            $table->unique(['tenant_id', 'initiative_id', 'risk_register_entry_id'], 'roadmap_risk_link_unique');
        });
        Schema::create('roadmap_quarterly_plans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedTinyInteger('quarter');
            $table->unsignedInteger('revision_no')->default(1);
            $table->string('status', 24)->default('draft');
            $table->unique(['tenant_id', 'fiscal_year', 'quarter', 'revision_no'], 'roadmap_plan_revision_unique');
        });
        Schema::create('roadmap_quarterly_plan_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('quarterly_plan_id');
            $table->unsignedBigInteger('initiative_id');
            $table->unique(['tenant_id', 'quarterly_plan_id', 'initiative_id'], 'roadmap_plan_item_unique');
        });
        Schema::create('roadmap_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('dedupe_key', 191);
            $table->string('status', 24);
            $table->unsignedBigInteger('triage_owner_id')->nullable();
            $table->timestamp('last_seen_at')->nullable();
        });
        Schema::create('roadmap_decision_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 24);
            $table->date('due_date')->nullable();
        });
        Schema::create('roadmap_report_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('report_type', 64);
            $table->timestamp('generated_at');
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('fails before Roadmap schema mutation when an application identity collides', function (): void {
    withRoadmapApplicationIdentityDatabase(function (): void {
        DB::table('roadmap_initiative_categories')->insert([
            ['tenant_id' => 11, 'key' => 'operations'],
            ['tenant_id' => 22, 'key' => 'operations'],
        ]);

        expect(fn () => roadmapApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'category key identity');

        expect(Schema::hasIndex('roadmap_initiative_categories', 'roadmap_categories_key_uq'))->toBeFalse()
            ->and(Schema::hasColumn('roadmap_suggestions', 'active_dedupe_key'))->toBeFalse();
    });
});

it('enforces Roadmap application identities and restores the compatibility shape on rollback', function (): void {
    withRoadmapApplicationIdentityDatabase(function (): void {
        DB::table('roadmap_initiative_categories')->insert(['tenant_id' => 11, 'key' => 'operations']);
        DB::table('roadmap_initiatives')->insert(['tenant_id' => 11, 'code' => '2026-RI-0001']);
        DB::table('roadmap_initiative_site_scopes')->insert(['tenant_id' => 11, 'initiative_id' => 1]);
        DB::table('roadmap_initiative_site_scope_sites')->insert([
            'tenant_id' => 11,
            'initiative_site_scope_id' => 1,
            'site_id' => 7,
        ]);
        DB::table('roadmap_initiative_budgets')->insert([
            'tenant_id' => 11,
            'initiative_id' => 1,
            'fiscal_year' => 2026,
            'quarter' => null,
        ]);
        DB::table('roadmap_initiative_risk_links')->insert([
            'tenant_id' => 11,
            'initiative_id' => 1,
            'risk_register_entry_id' => 9,
        ]);
        DB::table('roadmap_quarterly_plans')->insert([
            'tenant_id' => 11,
            'fiscal_year' => 2026,
            'quarter' => 3,
            'revision_no' => 1,
        ]);
        DB::table('roadmap_quarterly_plan_items')->insert([
            'tenant_id' => 11,
            'quarterly_plan_id' => 1,
            'initiative_id' => 1,
        ]);
        DB::table('roadmap_suggestions')->insert([
            'tenant_id' => 11,
            'dedupe_key' => 'asset:server:site-7',
            'status' => 'triage_pending',
        ]);

        $migration = roadmapApplicationIdentityMigration();
        $migration->up();

        foreach ([
            ['roadmap_initiative_categories', 'roadmap_categories_key_uq'],
            ['roadmap_initiatives', 'roadmap_initiatives_code_uq'],
            ['roadmap_initiative_site_scopes', 'roadmap_scope_initiative_uq'],
            ['roadmap_initiative_site_scope_sites', 'roadmap_scope_site_uq'],
            ['roadmap_initiative_budgets', 'roadmap_budget_period_uq'],
            ['roadmap_initiative_risk_links', 'roadmap_risk_link_uq'],
            ['roadmap_quarterly_plans', 'roadmap_plan_revision_application_uq'],
            ['roadmap_quarterly_plan_items', 'roadmap_plan_item_application_uq'],
            ['roadmap_suggestions', 'roadmap_suggestions_active_dedupe_uq'],
            ['roadmap_decision_requests', 'roadmap_decisions_status_due_idx'],
            ['roadmap_report_snapshots', 'roadmap_reports_type_time_idx'],
        ] as [$table, $index]) {
            expect(Schema::hasIndex($table, $index))->toBeTrue();
        }

        expect(Schema::hasColumn('roadmap_initiative_budgets', 'application_period_key'))->toBeTrue()
            ->and(Schema::hasColumn('roadmap_suggestions', 'active_dedupe_key'))->toBeTrue();

        expect(fn () => DB::table('roadmap_initiative_categories')->insert([
            'tenant_id' => 22,
            'key' => 'operations',
        ]))->toThrow(QueryException::class);

        expect(fn () => DB::table('roadmap_initiative_budgets')->insert([
            'tenant_id' => 22,
            'initiative_id' => 1,
            'fiscal_year' => 2026,
            'quarter' => null,
        ]))->toThrow(QueryException::class);

        expect(fn () => DB::table('roadmap_suggestions')->insert([
            'tenant_id' => 22,
            'dedupe_key' => 'asset:server:site-7',
            'status' => 'accepted',
        ]))->toThrow(QueryException::class);

        DB::table('roadmap_suggestions')->insert([
            ['tenant_id' => 22, 'dedupe_key' => 'asset:server:site-7', 'status' => 'rejected'],
            ['tenant_id' => 33, 'dedupe_key' => 'asset:server:site-7', 'status' => 'rejected'],
        ]);

        $migration->down();

        foreach ([
            ['roadmap_initiative_categories', 'rdmp_cat_tenant_key_uq'],
            ['roadmap_initiatives', 'roadmap_initiatives_tenant_id_code_unique'],
            ['roadmap_initiative_site_scopes', 'rdmp_scope_tenant_init_uq'],
            ['roadmap_initiative_site_scope_sites', 'roadmap_scope_site_unique'],
            ['roadmap_initiative_budgets', 'roadmap_budget_period_unique'],
            ['roadmap_initiative_risk_links', 'roadmap_risk_link_unique'],
            ['roadmap_quarterly_plans', 'roadmap_plan_revision_unique'],
            ['roadmap_quarterly_plan_items', 'roadmap_plan_item_unique'],
        ] as [$table, $index]) {
            expect(Schema::hasIndex($table, $index))->toBeTrue();
        }

        expect(Schema::hasColumn('roadmap_initiative_budgets', 'application_period_key'))->toBeFalse()
            ->and(Schema::hasColumn('roadmap_suggestions', 'active_dedupe_key'))->toBeFalse();
    });
});
