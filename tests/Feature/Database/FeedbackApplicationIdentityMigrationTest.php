<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function feedbackApplicationIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_27_000005_enforce_hr_feedback_application_identity.php',
    );
}

function withFeedbackIdentityDatabase(Closure $callback): void
{
    $connection = 'feedback_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-feedback-identity-');
    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary feedback migration database.');
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
        Schema::create('hr_feedback_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
        });
        Schema::create('hr_feedback_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('subject_user_id');
            $table->unsignedBigInteger('reviewer_user_id');
            $table->string('status');
            $table->date('due_date')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['tenant_id', 'reviewer_user_id', 'status']);
        });
        Schema::create('hr_feedback_responses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('feedback_request_id');
            $table->string('question_key');
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('fails before feedback schema mutation when an application template name collides', function (): void {
    withFeedbackIdentityDatabase(function (): void {
        DB::table('hr_feedback_templates')->insert([
            ['tenant_id' => 11, 'name' => 'Standard review'],
            ['tenant_id' => 22, 'name' => 'Standard review'],
        ]);
        $before = Schema::getIndexes('hr_feedback_templates');

        expect(fn () => feedbackApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'feedback template name');
        expect(Schema::getIndexes('hr_feedback_templates'))->toBe($before)
            ->and(Schema::hasIndex('hr_feedback_templates', 'hr_feedback_templates_name_uq'))->toBeFalse();
    });
});

it('fails before feedback schema mutation when a response question collides', function (): void {
    withFeedbackIdentityDatabase(function (): void {
        DB::table('hr_feedback_responses')->insert([
            ['feedback_request_id' => 7, 'question_key' => 'teamwork'],
            ['feedback_request_id' => 7, 'question_key' => 'teamwork'],
        ]);
        $before = Schema::getIndexes('hr_feedback_responses');

        expect(fn () => feedbackApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'feedback response question');
        expect(Schema::getIndexes('hr_feedback_responses'))->toBe($before)
            ->and(Schema::hasIndex('hr_feedback_responses', 'hr_feedback_responses_request_question_uq'))->toBeFalse();
    });
});

it('enforces and rolls back feedback application identities and read paths', function (): void {
    withFeedbackIdentityDatabase(function (): void {
        DB::table('hr_feedback_templates')->insert([
            'tenant_id' => 11,
            'name' => 'Standard review',
        ]);
        DB::table('hr_feedback_responses')->insert([
            'feedback_request_id' => 7,
            'question_key' => 'teamwork',
        ]);

        $migration = feedbackApplicationIdentityMigration();
        $migration->up();

        expect(Schema::hasIndex('hr_feedback_templates', 'hr_feedback_templates_name_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_feedback_templates', 'hr_feedback_templates_active_default_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_feedback_requests', 'hr_feedback_requests_reviewer_status_due_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_feedback_requests', 'hr_feedback_requests_subject_status_created_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_feedback_responses', 'hr_feedback_responses_request_question_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_feedback_templates', 'hr_feedback_templates_tenant_id_index'))->toBeFalse()
            ->and(Schema::hasIndex('hr_feedback_requests', 'hr_feedback_requests_tenant_id_index'))->toBeFalse()
            ->and(Schema::hasIndex('hr_feedback_requests', 'hr_feedback_requests_tenant_id_reviewer_user_id_status_index'))->toBeFalse();

        expect(fn () => DB::table('hr_feedback_templates')->insert([
            'tenant_id' => 22,
            'name' => 'Standard review',
        ]))->toThrow(QueryException::class);
        expect(fn () => DB::table('hr_feedback_responses')->insert([
            'feedback_request_id' => 7,
            'question_key' => 'teamwork',
        ]))->toThrow(QueryException::class);

        $migration->down();

        expect(Schema::hasIndex('hr_feedback_templates', 'hr_feedback_templates_name_uq'))->toBeFalse()
            ->and(Schema::hasIndex('hr_feedback_requests', 'hr_feedback_requests_reviewer_status_due_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_feedback_responses', 'hr_feedback_responses_request_question_uq'))->toBeFalse()
            ->and(Schema::hasIndex('hr_feedback_templates', 'hr_feedback_templates_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_feedback_requests', 'hr_feedback_requests_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_feedback_requests', 'hr_feedback_requests_tenant_id_reviewer_user_id_status_index'))->toBeTrue();
    });
});
