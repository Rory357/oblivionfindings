<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Courses
        Schema::create('hr_courses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('title');
            $table->string('code');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->string('delivery_method'); // online, in_person, blended, self_paced
            $table->decimal('duration_hours', 5, 1)->default(0);
            $table->string('provider')->nullable();
            $table->decimal('cost', 8, 2)->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->foreignId('compliance_requirement_id')->nullable()->constrained('hr_compliance_requirements')->nullOnDelete();
            $table->integer('max_participants')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'category']);
        });

        // Course sessions
        Schema::create('hr_course_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('hr_courses')->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->date('session_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('location')->nullable();
            $table->string('facilitator')->nullable();
            $table->integer('max_participants')->nullable();
            $table->string('status')->default('scheduled'); // scheduled, in_progress, completed, cancelled
            $table->timestamps();

            $table->index(['tenant_id', 'session_date']);
        });

        // Course enrollments
        Schema::create('hr_course_enrollments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('hr_courses')->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('hr_course_sessions')->nullOnDelete();
            $table->string('status')->default('enrolled'); // enrolled, in_progress, completed, withdrawn, failed
            $table->timestamp('enrolled_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('score', 5, 2)->nullable();
            $table->string('certificate_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_course_enrollments');
        Schema::dropIfExists('hr_course_sessions');
        Schema::dropIfExists('hr_courses');
    }
};
