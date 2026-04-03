<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_feedback_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('questions'); // [{key: string, question: string}, ...]
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('hr_feedback_requests', function (Blueprint $table) {
            $table->foreignId('template_id')->nullable()->after('performance_review_id')
                ->constrained('hr_feedback_templates')->nullOnDelete();
            $table->json('questions_snapshot')->nullable()->after('template_id');
        });

        // Seed the default template for each existing tenant
        $tenantIds = DB::table('hr_employee_profiles')
            ->whereNotNull('tenant_id')
            ->distinct()
            ->pluck('tenant_id');

        $defaultQuestions = json_encode([
            ['key' => 'communication', 'question' => 'How effectively does this person communicate?'],
            ['key' => 'teamwork', 'question' => 'How well does this person collaborate with others?'],
            ['key' => 'leadership', 'question' => 'How would you rate their leadership qualities?'],
            ['key' => 'technical', 'question' => 'How strong are their technical/role-specific skills?'],
            ['key' => 'initiative', 'question' => 'How well do they take initiative and drive results?'],
            ['key' => 'overall', 'question' => 'Overall, how would you rate their performance?'],
        ]);

        foreach ($tenantIds as $tenantId) {
            DB::table('hr_feedback_templates')->insert([
                'tenant_id' => $tenantId,
                'name' => 'Standard 360 Review',
                'description' => 'Default 360-degree feedback template covering communication, teamwork, leadership, technical skills, initiative, and overall performance.',
                'questions' => $defaultQuestions,
                'is_default' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('hr_feedback_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('template_id');
            $table->dropColumn('questions_snapshot');
        });

        Schema::dropIfExists('hr_feedback_templates');
    }
};
