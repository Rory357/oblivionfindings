<?php

use App\Domain\Finance\Models\FinBill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function spendApprovalAuthorityMigration(): Migration
{
    return require database_path('migrations/2026_08_21_000001_govern_spend_approval_authority.php');
}

function withSpendApprovalMigrationDatabase(Closure $callback): void
{
    $connection = 'spend_approval_authority_migration_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-spend-authority-');
    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary spend-authority migration database.');
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
        createSpendApprovalMigrationSchema();
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Requester'],
            ['id' => 2, 'name' => 'Decision maker'],
        ]);
        DB::table('sites')->insert([
            'id' => 10,
            'name' => 'Canonical Site',
            'is_active' => true,
            'archived' => false,
        ]);
        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

function createSpendApprovalMigrationSchema(): void
{
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    Schema::create('sites', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->boolean('is_active')->default(true);
        $table->boolean('archived')->default(false);
        $table->timestamp('archived_at')->nullable();
        $table->softDeletes();
    });
    Schema::create('resolutions', function (Blueprint $table): void {
        $table->id();
        $table->softDeletes();
    });
    Schema::create('fin_cost_centres', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('site_id')->nullable();
    });
    Schema::create('fin_funding_streams', fn (Blueprint $table) => $table->id());
    Schema::create('fin_donor_funds', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('funding_stream_id')->nullable();
        $table->softDeletes();
    });
    Schema::create('budgets', function (Blueprint $table): void {
        $table->id();
        $table->softDeletes();
    });
    Schema::create('budget_line_items', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('budget_id');
    });
    Schema::create('fin_bills', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('site_id')->nullable();
        $table->unsignedBigInteger('vendor_id');
        $table->unsignedBigInteger('purchase_order_id')->nullable();
        $table->string('bill_number');
        $table->string('status');
        $table->decimal('total_amount', 14, 2);
        $table->timestamp('deleted_at')->nullable();
    });
    Schema::create('fin_purchase_orders', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('vendor_id');
        $table->unsignedBigInteger('cost_centre_id')->nullable();
        $table->string('po_number');
        $table->string('status');
        $table->decimal('total_amount', 14, 2);
        $table->timestamp('deleted_at')->nullable();
    });
    Schema::create('fin_payment_runs', function (Blueprint $table): void {
        $table->id();
        $table->string('run_number');
        $table->string('status');
        $table->decimal('total_amount', 14, 2);
        $table->unsignedInteger('item_count');
    });
    Schema::create('fin_payment_run_items', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('payment_run_id');
        $table->unsignedBigInteger('site_id')->nullable();
        $table->unsignedBigInteger('bill_id')->nullable();
        $table->decimal('amount', 14, 2);
        $table->string('status');
    });
    Schema::create('spend_approvals', function (Blueprint $table): void {
        $table->id();
        $table->string('reference')->unique();
        $table->string('title');
        $table->text('description')->nullable();
        $table->string('category');
        $table->decimal('amount', 14, 2);
        $table->string('currency', 3);
        $table->string('source_type')->nullable();
        $table->unsignedBigInteger('source_id')->nullable();
        $table->unsignedBigInteger('site_id')->nullable();
        $table->unsignedBigInteger('cost_centre_id')->nullable();
        $table->unsignedBigInteger('funding_stream_id')->nullable();
        $table->unsignedBigInteger('donor_fund_id')->nullable();
        $table->unsignedBigInteger('budget_id')->nullable();
        $table->unsignedBigInteger('budget_line_item_id')->nullable();
        $table->string('status');
        $table->unsignedBigInteger('requested_by');
        $table->timestamp('submitted_at')->nullable();
        $table->unsignedBigInteger('decided_by')->nullable();
        $table->timestamp('decided_at')->nullable();
        $table->text('decision_notes')->nullable();
        $table->unsignedBigInteger('resolution_id')->nullable();
        $table->boolean('requires_board')->default(false);
        $table->json('attachments')->nullable();
        $table->date('valid_until')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
}

/** @return array<string, mixed> */
function legacySpendApproval(array $overrides = []): array
{
    return array_merge([
        'reference' => 'SA-2026-0001',
        'title' => 'Legacy governed spend',
        'category' => 'opex',
        'amount' => 12000,
        'currency' => 'NZD',
        'site_id' => 10,
        'status' => 'draft',
        'requested_by' => 1,
        'requires_board' => true,
        'created_at' => '2026-08-01 00:00:00',
        'updated_at' => '2026-08-01 00:00:00',
    ], $overrides);
}

it('rejects malformed submission provenance before any spend-authority DDL', function (): void {
    withSpendApprovalMigrationDatabase(function (): void {
        DB::table('spend_approvals')->insert(legacySpendApproval([
            'status' => 'submitted',
            'submitted_at' => null,
        ]));

        expect(fn () => spendApprovalAuthorityMigration()->up())
            ->toThrow(RuntimeException::class, 'without submission provenance');
        expect(Schema::hasColumn('spend_approvals', 'version'))->toBeFalse()
            ->and(Schema::hasTable('spend_approval_decisions'))->toBeFalse();
    });
});

it('rejects terminal provenance and blank reasons before any spend-authority DDL', function (array $overrides, string $message): void {
    withSpendApprovalMigrationDatabase(function () use ($overrides, $message): void {
        DB::table('spend_approvals')->insert(legacySpendApproval(array_merge([
            'status' => 'approved',
            'submitted_at' => '2026-08-02 00:00:00',
            'decided_by' => 2,
            'decided_at' => '2026-08-03 00:00:00',
            'decision_notes' => 'Approved with recorded reasons.',
        ], $overrides)));

        expect(fn () => spendApprovalAuthorityMigration()->up())
            ->toThrow(RuntimeException::class, $message);
        expect(Schema::hasColumn('spend_approvals', 'version'))->toBeFalse()
            ->and(Schema::hasTable('spend_approval_decisions'))->toBeFalse();
    });
})->with([
    'missing terminal actor' => [['decided_by' => null], 'without decision provenance'],
    'blank terminal reason' => [['decision_notes' => '   '], 'without a meaningful decision reason'],
]);

it('rejects requester self-decisions before any spend-authority DDL', function (): void {
    withSpendApprovalMigrationDatabase(function (): void {
        DB::table('spend_approvals')->insert(legacySpendApproval([
            'status' => 'approved',
            'submitted_at' => '2026-08-02 00:00:00',
            'decided_by' => 1,
            'decided_at' => '2026-08-03 00:00:00',
            'decision_notes' => 'The requester cannot decide their own request.',
        ]));

        expect(fn () => spendApprovalAuthorityMigration()->up())
            ->toThrow(RuntimeException::class, 'decided by its requester');
        expect(Schema::hasColumn('spend_approvals', 'version'))->toBeFalse()
            ->and(Schema::hasTable('spend_approval_decisions'))->toBeFalse();
    });
});

it('rejects malformed attachment evidence before any spend-authority DDL', function (string $attachments): void {
    withSpendApprovalMigrationDatabase(function () use ($attachments): void {
        DB::table('spend_approvals')->insert(legacySpendApproval([
            'attachments' => $attachments,
        ]));

        expect(fn () => spendApprovalAuthorityMigration()->up())
            ->toThrow(RuntimeException::class, 'malformed attachment evidence');
        expect(Schema::hasColumn('spend_approvals', 'version'))->toBeFalse()
            ->and(Schema::hasTable('spend_approval_decisions'))->toBeFalse();
    });
})->with([
    'object instead of a list' => [json_encode(['id' => 'legacy-document'])],
    'scalar list entry' => [json_encode(['legacy-document'])],
]);

it('rejects missing orphan and inactive submitted or terminal Sites before any DDL', function (string $case, string $status): void {
    withSpendApprovalMigrationDatabase(function () use ($case, $status): void {
        $siteId = 10;
        if ($case === 'missing') {
            $siteId = null;
        } elseif ($case === 'orphan') {
            $siteId = 2147483647;
        } else {
            DB::table('sites')->where('id', 10)->update(['is_active' => false]);
        }
        $approval = [
            'site_id' => $siteId,
            'status' => $status,
            'submitted_at' => '2026-08-02 00:00:00',
        ];
        if (in_array($status, ['approved', 'rejected'], true)) {
            $approval += [
                'decided_by' => 2,
                'decided_at' => '2026-08-03 00:00:00',
                'decision_notes' => 'A meaningful independently recorded decision reason.',
            ];
        }
        DB::table('spend_approvals')->insert(legacySpendApproval($approval));

        expect(fn () => spendApprovalAuthorityMigration()->up())
            ->toThrow(RuntimeException::class, 'no current canonical Site');
        expect(Schema::hasColumn('spend_approvals', 'version'))->toBeFalse()
            ->and(Schema::hasTable('spend_approval_decisions'))->toBeFalse();
    });
})->with([
    'missing submitted Site' => ['missing', 'submitted'],
    'orphan approved Site' => ['orphan', 'approved'],
    'inactive rejected Site' => ['inactive', 'rejected'],
]);

it('rejects missing orphan inactive and deleted draft Sites before any DDL', function (string $case): void {
    withSpendApprovalMigrationDatabase(function () use ($case): void {
        $siteId = 10;
        if ($case === 'missing') {
            $siteId = null;
        } elseif ($case === 'orphan') {
            $siteId = 2147483647;
        } elseif ($case === 'inactive') {
            DB::table('sites')->where('id', 10)->update(['is_active' => false]);
        } else {
            DB::table('sites')->where('id', 10)->update(['deleted_at' => '2026-08-02 00:00:00']);
        }
        DB::table('spend_approvals')->insert(legacySpendApproval([
            'site_id' => $siteId,
            'status' => 'draft',
        ]));

        expect(fn () => spendApprovalAuthorityMigration()->up())
            ->toThrow(RuntimeException::class, 'no current canonical Site');
        expect(Schema::hasColumn('spend_approvals', 'version'))->toBeFalse()
            ->and(Schema::hasTable('spend_approval_decisions'))->toBeFalse();
    });
})->with(['missing', 'orphan', 'inactive', 'deleted']);

it('rejects missing and Site-mismatched legacy Finance sources before any DDL', function (int $sourceId, bool $createSource): void {
    withSpendApprovalMigrationDatabase(function () use ($sourceId, $createSource): void {
        if ($createSource) {
            DB::table('fin_bills')->insert([
                'id' => $sourceId,
                'site_id' => 11,
                'vendor_id' => 30,
                'bill_number' => 'BILL-FOREIGN',
                'status' => 'approved',
                'total_amount' => 12000,
            ]);
        }
        DB::table('spend_approvals')->insert(legacySpendApproval([
            'source_type' => FinBill::class,
            'source_id' => $sourceId,
        ]));

        expect(fn () => spendApprovalAuthorityMigration()->up())
            ->toThrow(RuntimeException::class, 'missing or Site-mismatched Finance source');
        expect(Schema::hasColumn('spend_approvals', 'version'))->toBeFalse()
            ->and(Schema::hasTable('spend_approval_decisions'))->toBeFalse();
    });
})->with([
    'missing source' => [404, false],
    'Site-mismatched source' => [405, true],
]);

it('rejects missing and mismatched legacy linked parents before any DDL', function (string $case, array $approval): void {
    withSpendApprovalMigrationDatabase(function () use ($case, $approval): void {
        if ($case === 'cost_centre_mismatch') {
            DB::table('fin_cost_centres')->insert(['id' => 20, 'site_id' => 11]);
        } elseif ($case === 'donor_stream_mismatch') {
            DB::table('fin_funding_streams')->insert([['id' => 31], ['id' => 32]]);
            DB::table('fin_donor_funds')->insert(['id' => 30, 'funding_stream_id' => 31]);
        } elseif ($case === 'budget_line_mismatch') {
            DB::table('budgets')->insert([['id' => 41], ['id' => 42]]);
            DB::table('budget_line_items')->insert(['id' => 40, 'budget_id' => 41]);
        }
        DB::table('spend_approvals')->insert(legacySpendApproval($approval));

        expect(fn () => spendApprovalAuthorityMigration()->up())
            ->toThrow(RuntimeException::class, 'missing or mismatched canonical');
        expect(Schema::hasColumn('spend_approvals', 'version'))->toBeFalse()
            ->and(Schema::hasTable('spend_approval_decisions'))->toBeFalse();
    });
})->with([
    'cost centre belongs to another Site' => ['cost_centre_mismatch', ['cost_centre_id' => 20]],
    'donor and explicit funding stream disagree' => ['donor_stream_mismatch', ['donor_fund_id' => 30, 'funding_stream_id' => 32]],
    'budget line and explicit budget disagree' => ['budget_line_mismatch', ['budget_line_item_id' => 40, 'budget_id' => 42]],
    'resolution is missing' => ['missing_resolution', ['resolution_id' => 2147483647]],
]);

it('canonically infers unambiguous legacy parent identities before hashing evidence', function (): void {
    withSpendApprovalMigrationDatabase(function (): void {
        DB::table('fin_funding_streams')->insert(['id' => 51]);
        DB::table('fin_donor_funds')->insert(['id' => 50, 'funding_stream_id' => 51]);
        DB::table('budgets')->insert(['id' => 61]);
        DB::table('budget_line_items')->insert(['id' => 60, 'budget_id' => 61]);
        DB::table('spend_approvals')->insert(legacySpendApproval([
            'status' => 'approved',
            'donor_fund_id' => 50,
            'budget_line_item_id' => 60,
            'submitted_at' => '2026-08-02 00:00:00',
            'decided_by' => 2,
            'decided_at' => '2026-08-03 00:00:00',
            'decision_notes' => 'Approved against canonical linked parents.',
        ]));

        spendApprovalAuthorityMigration()->up();

        $approval = DB::table('spend_approvals')->sole();
        $evidence = json_decode(
            DB::table('spend_approval_decisions')->value('parent_evidence'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        expect($approval->funding_stream_id)->toBe(51)
            ->and($approval->budget_id)->toBe(61)
            ->and($evidence['funding_stream_id'])->toBe(51)
            ->and($evidence['budget_id'])->toBe(61);
    });
});

it('backfills source evidence and survives down then re-up', function (): void {
    withSpendApprovalMigrationDatabase(function (): void {
        DB::table('fin_bills')->insert([
            'id' => 20,
            'site_id' => 10,
            'vendor_id' => 30,
            'bill_number' => 'BILL-LEGACY-20',
            'status' => 'approved',
            'total_amount' => 12000,
        ]);
        DB::table('spend_approvals')->insert(legacySpendApproval([
            'status' => 'approved',
            'source_type' => FinBill::class,
            'source_id' => 20,
            'submitted_at' => '2026-08-02 00:00:00',
            'decided_by' => 2,
            'decided_at' => '2026-08-03 00:00:00',
            'decision_notes' => 'Approved against the canonical bill.',
        ]));

        $migration = spendApprovalAuthorityMigration();
        $migration->up();
        $approval = DB::table('spend_approvals')->sole();
        $decision = DB::table('spend_approval_decisions')->sole();
        $evidence = json_decode($decision->parent_evidence, true, flags: JSON_THROW_ON_ERROR);
        $digest = $approval->content_digest;
        expect($approval->submitted_by)->toBe(1)
            ->and($approval->submission_version)->toBe(1)
            ->and($decision->reason)->toBe('Approved against the canonical bill.')
            ->and($evidence['source']['type'])->toBe(FinBill::class)
            ->and($evidence['source']['id'])->toBe(20)
            ->and($evidence['source']['reference'])->toBe('BILL-LEGACY-20');

        $migration->down();
        expect(Schema::hasColumn('spend_approvals', 'version'))->toBeFalse()
            ->and(Schema::hasTable('spend_approval_decisions'))->toBeFalse()
            ->and(DB::table('spend_approvals')->value('decision_notes'))->toBe('Approved against the canonical bill.');

        $migration->up();
        expect(DB::table('spend_approvals')->value('content_digest'))->toBe($digest)
            ->and(DB::table('spend_approval_decisions')->count())->toBe(1)
            ->and(Schema::hasTable('spend_approval_reference_sequences'))->toBeTrue();
    });
});
