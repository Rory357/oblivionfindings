<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function expenseApplicationIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_27_000008_enforce_hr_expense_application_identity.php',
    );
}

function withExpenseIdentityDatabase(Closure $callback): void
{
    $connection = 'expense_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-expense-identity-');
    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary expense migration database.');
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
        Schema::create('hr_expense_claims', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->string('claim_number');
            $table->string('status');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'claim_number']);
            $table->index(['tenant_id', 'status']);
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('fails before expense schema mutation when an application claim number collides', function (): void {
    withExpenseIdentityDatabase(function (): void {
        DB::table('hr_expense_claims')->insert([
            [
                'tenant_id' => 11,
                'user_id' => 7,
                'claim_number' => 'EXP-00001',
                'status' => 'draft',
            ],
            [
                'tenant_id' => 22,
                'user_id' => 9,
                'claim_number' => 'EXP-00001',
                'status' => 'submitted',
            ],
        ]);
        $before = Schema::getIndexes('hr_expense_claims');

        expect(fn () => expenseApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'expense claim number');
        expect(Schema::getIndexes('hr_expense_claims'))->toBe($before)
            ->and(Schema::hasIndex(
                'hr_expense_claims',
                'hr_expense_claims_claim_number_uq',
            ))->toBeFalse();
    });
});

it('enforces and rolls back expense application identity and read paths', function (): void {
    withExpenseIdentityDatabase(function (): void {
        DB::table('hr_expense_claims')->insert([
            'tenant_id' => 11,
            'user_id' => 7,
            'claim_number' => 'EXP-00001',
            'status' => 'draft',
        ]);

        $migration = expenseApplicationIdentityMigration();
        $migration->up();

        expect(Schema::hasIndex(
            'hr_expense_claims',
            'hr_expense_claims_claim_number_uq',
        ))->toBeTrue()
            ->and(Schema::hasIndex(
                'hr_expense_claims',
                'hr_expense_claims_status_submitted_idx',
            ))->toBeTrue()
            ->and(Schema::hasIndex(
                'hr_expense_claims',
                'hr_expense_claims_user_status_created_idx',
            ))->toBeTrue()
            ->and(Schema::hasIndex(
                'hr_expense_claims',
                'hr_expense_claims_tenant_id_claim_number_unique',
            ))->toBeFalse()
            ->and(Schema::hasIndex(
                'hr_expense_claims',
                'hr_expense_claims_tenant_id_status_index',
            ))->toBeFalse();

        expect(fn () => DB::table('hr_expense_claims')->insert([
            'tenant_id' => 22,
            'user_id' => 9,
            'claim_number' => 'EXP-00001',
            'status' => 'submitted',
        ]))->toThrow(QueryException::class);

        $migration->down();

        expect(Schema::hasIndex(
            'hr_expense_claims',
            'hr_expense_claims_claim_number_uq',
        ))->toBeFalse()
            ->and(Schema::hasIndex(
                'hr_expense_claims',
                'hr_expense_claims_tenant_id_claim_number_unique',
            ))->toBeTrue()
            ->and(Schema::hasIndex(
                'hr_expense_claims',
                'hr_expense_claims_tenant_id_status_index',
            ))->toBeTrue();
    });
});
