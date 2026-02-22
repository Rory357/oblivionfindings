<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_payroll_export_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name', 150);
            $table->string('provider_key', 100)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('delimiter', 4)->default(',');
            $table->string('enclosure', 4)->default('"');
            $table->string('line_ending', 8)->default("\n");
            $table->boolean('include_headers')->default(true);
            $table->boolean('is_default')->default(false);
            $table->json('mappings');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_default']);
        });

        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_payroll_runs', 'export_profile_id')) {
                $table->foreignId('export_profile_id')
                    ->nullable()
                    ->after('exported_by')
                    ->constrained('hr_payroll_export_profiles')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            if (Schema::hasColumn('hr_payroll_runs', 'export_profile_id')) {
                $table->dropConstrainedForeignId('export_profile_id');
            }
        });

        Schema::dropIfExists('hr_payroll_export_profiles');
    }
};

