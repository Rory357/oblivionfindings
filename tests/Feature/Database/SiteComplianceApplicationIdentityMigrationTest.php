<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function siteComplianceApplicationIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_08_02_000013_realign_site_compliance_application_identity.php',
    );
}

function withSiteComplianceIdentityDatabase(Closure $callback): void
{
    $connection = 'site_compliance_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-site-compliance-');

    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary Site compliance migration database.');
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
        Schema::create('site_certifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('site_id');
            $table->string('certification_type');
            $table->string('status');
            $table->date('expiry_date')->nullable();
            $table->date('next_review_date')->nullable();
            $table->index(['status', 'expiry_date']);
        });
        Schema::create('site_compliance_checks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('site_id');
            $table->string('check_type');
            $table->date('scheduled_date');
            $table->string('status');
            $table->string('risk_rating')->nullable();
            $table->date('follow_up_date')->nullable();
            $table->index(['status', 'scheduled_date']);
        });
        Schema::create('site_staff_requirements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('site_id');
            $table->string('requirement_name');
            $table->boolean('is_active');
            $table->string('category');
            $table->unique(['site_id', 'requirement_name']);
        });
        Schema::create('site_coverage_requirements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('site_id');
            $table->string('name');
            $table->string('day_of_week', 3);
            $table->string('starts_time', 5);
            $table->string('ends_time', 5);
        });
        Schema::create('site_feedback', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('site_id');
            $table->string('status')->index();
            $table->timestamps();
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('fails before Site compliance schema mutation when coverage identity collides', function (): void {
    withSiteComplianceIdentityDatabase(function (): void {
        DB::table('site_coverage_requirements')->insert([
            [
                'organization_id' => 11,
                'site_id' => 8,
                'name' => 'Overnight cover',
                'day_of_week' => 'mon',
                'starts_time' => '22:00',
                'ends_time' => '07:00',
            ],
            [
                'organization_id' => 22,
                'site_id' => 8,
                'name' => 'Overnight cover',
                'day_of_week' => 'mon',
                'starts_time' => '22:00',
                'ends_time' => '07:00',
            ],
        ]);

        expect(fn () => siteComplianceApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'canonical Site coverage identity');

        expect(Schema::hasIndex(
            'site_coverage_requirements',
            'site_coverage_requirements_organization_id_index',
        ))->toBeTrue()
            ->and(Schema::hasIndex(
                'site_coverage_requirements',
                'site_coverage_requirements_identity_uq',
            ))->toBeFalse()
            ->and(Schema::hasColumn('site_compliance_checks', 'notes'))->toBeFalse();
    });
});

it('uses Site compliance indexes and restores the exact compatibility shape', function (): void {
    withSiteComplianceIdentityDatabase(function (): void {
        DB::table('site_coverage_requirements')->insert([
            'organization_id' => 11,
            'site_id' => 8,
            'name' => 'Overnight cover',
            'day_of_week' => 'mon',
            'starts_time' => '22:00',
            'ends_time' => '07:00',
        ]);

        $migration = siteComplianceApplicationIdentityMigration();
        $migration->up();

        foreach ([
            ['site_certifications', 'site_certifications_site_status_expiry_idx'],
            ['site_certifications', 'site_certifications_site_review_idx'],
            ['site_compliance_checks', 'site_compliance_checks_site_status_schedule_idx'],
            ['site_compliance_checks', 'site_compliance_checks_site_follow_up_idx'],
            ['site_staff_requirements', 'site_staff_requirements_site_active_category_idx'],
            ['site_coverage_requirements', 'site_coverage_requirements_identity_uq'],
            ['site_feedback', 'site_feedback_site_status_created_idx'],
            ['site_feedback', 'site_feedback_site_created_idx'],
        ] as [$table, $index]) {
            expect(Schema::hasIndex($table, $index))->toBeTrue();
        }

        foreach ([
            'site_certifications',
            'site_compliance_checks',
            'site_staff_requirements',
            'site_coverage_requirements',
            'site_feedback',
        ] as $table) {
            expect(Schema::hasIndex($table, "{$table}_organization_id_index"))->toBeFalse();
        }

        expect(Schema::hasColumn('site_compliance_checks', 'notes'))->toBeTrue()
            ->and(fn () => DB::table('site_coverage_requirements')->insert([
                'organization_id' => 99,
                'site_id' => 8,
                'name' => 'Overnight cover',
                'day_of_week' => 'mon',
                'starts_time' => '22:00',
                'ends_time' => '07:00',
            ]))->toThrow(QueryException::class);

        $migration->down();

        foreach ([
            'site_certifications',
            'site_compliance_checks',
            'site_staff_requirements',
            'site_coverage_requirements',
            'site_feedback',
        ] as $table) {
            expect(Schema::hasIndex($table, "{$table}_organization_id_index"))->toBeTrue();
        }

        expect(Schema::hasIndex('site_certifications', 'site_certifications_status_expiry_date_index'))->toBeTrue()
            ->and(Schema::hasIndex('site_compliance_checks', 'site_compliance_checks_status_scheduled_date_index'))->toBeTrue()
            ->and(Schema::hasIndex('site_feedback', 'site_feedback_status_index'))->toBeTrue()
            ->and(Schema::hasIndex('site_coverage_requirements', 'site_coverage_requirements_identity_uq'))->toBeFalse()
            ->and(Schema::hasColumn('site_compliance_checks', 'notes'))->toBeFalse();
    });
});
