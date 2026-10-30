<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_medical_profiles', function (Blueprint $table) {
            $table->json('allergy_records')->nullable();
            $table->timestamp('allergies_canonical_at')->nullable();
            $table->timestamp('allergies_reviewed_at')->nullable();
            $table->foreignId('allergies_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('allergies_review_status', 30)->nullable();
            $table->text('allergies_review_method')->nullable();
            $table->string('allergies_review_digest', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('client_medical_profiles')->whereNotNull('allergies_canonical_at')->exists()) {
            throw new RuntimeException('Cannot remove retained canonical allergy evidence.');
        }
        Schema::table('client_medical_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('allergies_reviewed_by');
            $table->dropColumn(['allergy_records', 'allergies_canonical_at', 'allergies_reviewed_at', 'allergies_review_status', 'allergies_review_method', 'allergies_review_digest']);
        });
    }
};
