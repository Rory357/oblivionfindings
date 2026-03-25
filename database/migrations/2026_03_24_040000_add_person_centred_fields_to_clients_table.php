<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (!Schema::hasColumn('clients', 'ethnicity')) {
                $table->string('ethnicity', 255)->nullable();
            }
            if (!Schema::hasColumn('clients', 'languages')) {
                $table->json('languages')->nullable();
            }
            if (!Schema::hasColumn('clients', 'preferred_pronouns')) {
                $table->string('preferred_pronouns', 50)->nullable();
            }
            if (!Schema::hasColumn('clients', 'religion')) {
                $table->string('religion', 255)->nullable();
            }
            if (!Schema::hasColumn('clients', 'interests_hobbies')) {
                $table->text('interests_hobbies')->nullable();
            }
            if (!Schema::hasColumn('clients', 'strengths_abilities')) {
                $table->text('strengths_abilities')->nullable();
            }
            if (!Schema::hasColumn('clients', 'life_story')) {
                $table->text('life_story')->nullable();
            }
            if (!Schema::hasColumn('clients', 'education_level')) {
                $table->string('education_level', 255)->nullable();
            }
            if (!Schema::hasColumn('clients', 'employment_status')) {
                $table->string('employment_status', 255)->nullable();
            }
            if (!Schema::hasColumn('clients', 'mobility_needs')) {
                $table->string('mobility_needs', 255)->nullable();
            }
            if (!Schema::hasColumn('clients', 'sensory_needs')) {
                $table->text('sensory_needs')->nullable();
            }
            if (!Schema::hasColumn('clients', 'cognitive_needs')) {
                $table->text('cognitive_needs')->nullable();
            }
            if (!Schema::hasColumn('clients', 'dietary_requirements')) {
                $table->text('dietary_requirements')->nullable();
            }
            if (!Schema::hasColumn('clients', 'sleep_preferences')) {
                $table->text('sleep_preferences')->nullable();
            }
            if (!Schema::hasColumn('clients', 'service_start_date')) {
                $table->date('service_start_date')->nullable();
            }
            if (!Schema::hasColumn('clients', 'key_worker_id')) {
                $table->unsignedBigInteger('key_worker_id')->nullable();
                $table->foreign('key_worker_id')->references('id')->on('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('clients', 'risk_level')) {
                $table->string('risk_level', 20)->nullable();
            }
            if (!Schema::hasColumn('clients', 'safeguarding_flag')) {
                $table->boolean('safeguarding_flag')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['key_worker_id']);
            $table->dropColumn([
                'ethnicity',
                'languages',
                'preferred_pronouns',
                'religion',
                'interests_hobbies',
                'strengths_abilities',
                'life_story',
                'education_level',
                'employment_status',
                'mobility_needs',
                'sensory_needs',
                'cognitive_needs',
                'dietary_requirements',
                'sleep_preferences',
                'service_start_date',
                'key_worker_id',
                'risk_level',
                'safeguarding_flag',
            ]);
        });
    }
};
