<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            // Link to users table
            $table->foreignId('user_id')
                ->nullable()
                ->unique()
                ->after('id')
                ->constrained('users')
                ->nullOnDelete();
            
            // Employment details
            $table->string('employee_id')->nullable()->unique()->after('user_id');
            $table->string('job_title')->nullable()->after('employee_id');
            $table->string('department')->nullable()->after('job_title');
            $table->date('hire_date')->nullable()->after('department');
            $table->date('termination_date')->nullable()->after('hire_date');
            
            // Contact info (in addition to user's email)
            $table->string('work_phone')->nullable()->after('termination_date');
            $table->string('mobile_phone')->nullable()->after('work_phone');
            
            // Status
            $table->enum('status', ['active', 'on_leave', 'suspended', 'terminated'])
                ->default('active')
                ->after('mobile_phone');
            
            // Emergency contact
            $table->string('emergency_contact_name')->nullable()->after('status');
            $table->string('emergency_contact_phone')->nullable()->after('emergency_contact_name');
            $table->string('emergency_contact_relationship')->nullable()->after('emergency_contact_phone');
            
            // Additional fields
            $table->text('notes')->nullable()->after('emergency_contact_relationship');
            $table->softDeletes();
            
            // Indexes
            $table->index('status');
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['status']);
            $table->dropIndex(['employee_id']);
            $table->dropColumn([
                'user_id',
                'employee_id',
                'job_title',
                'department',
                'hire_date',
                'termination_date',
                'work_phone',
                'mobile_phone',
                'status',
                'emergency_contact_name',
                'emergency_contact_phone',
                'emergency_contact_relationship',
                'notes',
            ]);
        });
    }
};
