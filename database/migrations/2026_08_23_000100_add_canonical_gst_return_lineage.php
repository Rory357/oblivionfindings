<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_bill_lines', function (Blueprint $table) {
            $table->foreignId('tax_rate_id')
                ->nullable()
                ->after('gst_rate')
                ->constrained('fin_tax_rates')
                ->restrictOnDelete();
        });

        Schema::table('fin_credit_note_lines', function (Blueprint $table) {
            $table->foreignId('tax_rate_id')
                ->nullable()
                ->after('gst_rate')
                ->constrained('fin_tax_rates')
                ->restrictOnDelete();
        });

        Schema::table('fin_gst_returns', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1)->after('basis');
            $table->foreignId('supersedes_gst_return_id')
                ->nullable()
                ->after('revision')
                ->constrained('fin_gst_returns')
                ->restrictOnDelete();
            $table->char('source_digest', 64)->nullable()->after('supersedes_gst_return_id');
            $table->timestamp('prepared_at')->nullable()->after('source_digest');

            $table->dropUnique('fin_gst_returns_organization_id_period_start_period_end_unique');
            $table->unique(
                ['organization_id', 'period_start', 'period_end', 'revision'],
                'fin_gst_returns_period_revision_unique',
            );
            $table->unique('supersedes_gst_return_id', 'fin_gst_returns_supersedes_unique');
        });

        Schema::table('fin_gst_return_lines', function (Blueprint $table) {
            $table->string('side', 16)->nullable()->after('tax_rate_id');
            $table->string('source_type')->nullable()->after('side');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->string('source_line_type')->nullable()->after('source_id');
            $table->unsignedBigInteger('source_line_id')->nullable()->after('source_line_type');
            $table->string('recognition_type')->nullable()->after('source_line_id');
            $table->unsignedBigInteger('recognition_id')->nullable()->after('recognition_type');
            $table->date('recognition_date')->nullable()->after('recognition_id');
            $table->char('source_key', 64)->nullable()->after('recognition_date');

            $table->unique(
                ['gst_return_id', 'source_key'],
                'fin_gst_return_lines_source_unique',
            );
            $table->index(
                ['source_type', 'source_id'],
                'fin_gst_return_lines_source_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('fin_gst_return_lines', function (Blueprint $table) {
            $table->dropUnique('fin_gst_return_lines_source_unique');
            $table->dropIndex('fin_gst_return_lines_source_index');
            $table->dropColumn([
                'side',
                'source_type',
                'source_id',
                'source_line_type',
                'source_line_id',
                'recognition_type',
                'recognition_id',
                'recognition_date',
                'source_key',
            ]);
        });

        Schema::table('fin_gst_returns', function (Blueprint $table) {
            $table->dropUnique('fin_gst_returns_period_revision_unique');
            $table->dropUnique('fin_gst_returns_supersedes_unique');
            $table->dropForeign(['supersedes_gst_return_id']);
            $table->dropColumn([
                'revision',
                'supersedes_gst_return_id',
                'source_digest',
                'prepared_at',
            ]);
            $table->unique(['organization_id', 'period_start', 'period_end']);
        });

        Schema::table('fin_credit_note_lines', function (Blueprint $table) {
            $table->dropForeign(['tax_rate_id']);
            $table->dropColumn('tax_rate_id');
        });

        Schema::table('fin_bill_lines', function (Blueprint $table) {
            $table->dropForeign(['tax_rate_id']);
            $table->dropColumn('tax_rate_id');
        });
    }
};
