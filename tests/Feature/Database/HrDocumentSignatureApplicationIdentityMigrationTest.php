<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function hrDocumentSignatureApplicationIdentityMigration(): Migration
{
    return require database_path(
        'migrations/2026_08_02_000019_realign_hr_documents_signatures_application_identity.php',
    );
}

function withHrDocumentSignatureIdentityDatabase(Closure $callback): void
{
    $connection = 'hr_document_signature_identity_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-hr-document-signature-');

    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary HR document and signature migration database.');
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
        Schema::create('hr_document_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->string('category');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'category', 'is_active']);
        });
        Schema::create('hr_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employee_profile_id');
            $table->string('category');
            $table->timestamps();
            $table->index(['tenant_id', 'category']);
        });
        Schema::create('hr_document_signatures', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('signer_user_id');
            $table->string('status')->default('pending');
            $table->date('due_at')->nullable();
            $table->dateTime('requested_at');
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['document_id', 'signer_user_id']);
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('fails before schema mutation when document template or active signer identities collide', function (): void {
    withHrDocumentSignatureIdentityDatabase(function (): void {
        DB::table('hr_document_templates')->insert([
            ['tenant_id' => 11, 'name' => 'Employment Agreement', 'category' => 'contract'],
            ['tenant_id' => 22, 'name' => ' employment agreement ', 'category' => 'contract'],
        ]);
        $beforeTemplates = Schema::getIndexes('hr_document_templates');
        $beforeDocuments = Schema::getIndexes('hr_documents');
        $beforeSignatures = Schema::getIndexes('hr_document_signatures');

        expect(fn () => hrDocumentSignatureApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'document template identity');

        expect(Schema::getIndexes('hr_document_templates'))->toBe($beforeTemplates)
            ->and(Schema::getIndexes('hr_documents'))->toBe($beforeDocuments)
            ->and(Schema::getIndexes('hr_document_signatures'))->toBe($beforeSignatures)
            ->and(Schema::hasColumn('hr_document_templates', 'application_name_key'))->toBeFalse()
            ->and(Schema::hasColumn('hr_document_signatures', 'active_document_signer_key'))->toBeFalse();
    });

    withHrDocumentSignatureIdentityDatabase(function (): void {
        DB::table('hr_document_templates')->insert([
            'tenant_id' => 11,
            'name' => 'Employment Agreement',
            'category' => 'contract',
        ]);
        DB::table('hr_documents')->insert([
            'id' => 1,
            'tenant_id' => 11,
            'employee_profile_id' => 91,
            'category' => 'contract',
        ]);
        DB::table('hr_document_signatures')->insert([
            [
                'tenant_id' => 11,
                'document_id' => 1,
                'signer_user_id' => 501,
                'status' => 'pending',
                'requested_at' => now(),
            ],
            [
                'tenant_id' => 22,
                'document_id' => 1,
                'signer_user_id' => 501,
                'status' => 'declined',
                'requested_at' => now(),
            ],
        ]);

        expect(fn () => hrDocumentSignatureApplicationIdentityMigration()->up())
            ->toThrow(RuntimeException::class, 'active document signer identity');

        expect(Schema::hasColumn('hr_document_templates', 'application_name_key'))->toBeFalse()
            ->and(Schema::hasColumn('hr_document_signatures', 'active_document_signer_key'))->toBeFalse();
    });
});

it('enforces application document identities and restores the exact compatibility indexes', function (): void {
    withHrDocumentSignatureIdentityDatabase(function (): void {
        DB::table('hr_document_templates')->insert([
            'tenant_id' => 11,
            'name' => ' Employment Agreement ',
            'category' => 'contract',
        ]);
        DB::table('hr_documents')->insert([
            'id' => 1,
            'tenant_id' => 11,
            'employee_profile_id' => 91,
            'category' => 'contract',
        ]);
        DB::table('hr_document_signatures')->insert([
            [
                'tenant_id' => 11,
                'document_id' => 1,
                'signer_user_id' => 501,
                'status' => 'pending',
                'requested_at' => now(),
            ],
            [
                'tenant_id' => 22,
                'document_id' => 1,
                'signer_user_id' => 501,
                'status' => 'cancelled',
                'requested_at' => now(),
            ],
        ]);

        $migration = hrDocumentSignatureApplicationIdentityMigration();
        $migration->up();

        expect(DB::table('hr_document_templates')->value('name'))->toBe('Employment Agreement')
            ->and(Schema::hasIndex('hr_document_templates', 'hr_document_templates_name_key_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_document_templates', 'hr_document_templates_active_name_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_documents', 'hr_documents_profile_created_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_document_signatures', 'hr_document_signatures_active_signer_uq'))->toBeTrue()
            ->and(Schema::hasIndex('hr_document_signatures', 'hr_document_signatures_signer_status_requested_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_document_signatures', 'hr_document_signatures_status_due_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_document_templates', 'hr_document_templates_tenant_id_index'))->toBeFalse()
            ->and(Schema::hasIndex('hr_document_signatures', 'hr_document_signatures_document_id_signer_user_id_index'))->toBeTrue();

        expect(fn () => DB::table('hr_document_templates')->insert([
            'tenant_id' => 22,
            'name' => ' employment agreement ',
            'category' => 'contract',
        ]))->toThrow(QueryException::class);
        expect(fn () => DB::table('hr_document_signatures')->insert([
            'tenant_id' => 22,
            'document_id' => 1,
            'signer_user_id' => 501,
            'status' => 'signed',
            'requested_at' => now(),
        ]))->toThrow(QueryException::class);

        DB::table('hr_document_signatures')->insert([
            'tenant_id' => 33,
            'document_id' => 1,
            'signer_user_id' => 501,
            'status' => 'cancelled',
            'requested_at' => now(),
        ]);

        $migration->down();

        expect(Schema::hasColumn('hr_document_templates', 'application_name_key'))->toBeFalse()
            ->and(Schema::hasColumn('hr_document_signatures', 'active_document_signer_key'))->toBeFalse()
            ->and(Schema::hasIndex('hr_document_templates', 'hr_document_templates_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_document_templates', 'hr_document_templates_tenant_id_category_is_active_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_documents', 'hr_documents_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_documents', 'hr_documents_tenant_id_category_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_document_signatures', 'hr_document_signatures_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_document_signatures', 'hr_document_signatures_tenant_id_status_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_document_signatures', 'hr_document_signatures_document_id_signer_user_id_index'))->toBeTrue();
    });
});
