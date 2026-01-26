<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            if (!Schema::hasColumn('sites', 'phone')) {
                $table->string('phone')->nullable()->after('name');
            }
            if (!Schema::hasColumn('sites', 'email')) {
                $table->string('email')->nullable()->after('phone');
            }
            if (!Schema::hasColumn('sites', 'manager_name')) {
                $table->string('manager_name')->nullable()->after('email');
            }
            if (!Schema::hasColumn('sites', 'manager_phone')) {
                $table->string('manager_phone')->nullable()->after('manager_name');
            }
            if (!Schema::hasColumn('sites', 'after_hours_phone')) {
                $table->string('after_hours_phone')->nullable()->after('manager_phone');
            }
            if (!Schema::hasColumn('sites', 'emergency_plan_location')) {
                $table->string('emergency_plan_location')->nullable()->after('after_hours_phone');
            }
            if (!Schema::hasColumn('sites', 'medication_storage_location')) {
                $table->string('medication_storage_location')->nullable()->after('emergency_plan_location');
            }
            if (!Schema::hasColumn('sites', 'notes')) {
                $table->text('notes')->nullable()->after('medication_storage_location');
            }
        });

        Schema::create('site_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('type')->nullable(); // e.g. emergency, after_hours, maintenance
            $table->string('name');
            $table->string('role')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'type']);
        });

        Schema::create('site_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title')->nullable();
            $table->string('category')->nullable();
            $table->string('version')->nullable();
            $table->date('effective_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();

            $table->string('storage_disk');
            $table->string('storage_path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->timestamps();

            $table->index(['site_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_documents');
        Schema::dropIfExists('site_contacts');

        Schema::table('sites', function (Blueprint $table) {
            foreach ([
                'phone',
                'email',
                'manager_name',
                'manager_phone',
                'after_hours_phone',
                'emergency_plan_location',
                'medication_storage_location',
                'notes',
            ] as $col) {
                if (Schema::hasColumn('sites', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
