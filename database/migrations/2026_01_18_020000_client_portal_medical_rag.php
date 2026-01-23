<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_portal_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('relation'); // client | next_of_kin
            $table->timestamps();
            $table->unique(['client_id', 'user_id', 'relation']);
        });

        Schema::create('client_medical_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->text('medical_history')->nullable();
            $table->text('disabilities')->nullable();
            $table->text('allergies')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique('client_id');
        });

        Schema::create('client_medications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('name');
            $table->string('dosage')->nullable();
            $table->string('frequency')->nullable();
            $table->string('route')->nullable();
            $table->string('prescriber')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('instructions')->nullable();
            $table->timestamps();
        });

        Schema::table('clients', function (Blueprint $table) {
            if (!Schema::hasColumn('clients', 'openai_vector_store_id')) {
                $table->string('openai_vector_store_id')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'openai_vector_store_id')) {
                $table->dropColumn('openai_vector_store_id');
            }
        });

        Schema::dropIfExists('client_medications');
        Schema::dropIfExists('client_medical_profiles');
        Schema::dropIfExists('client_portal_users');
    }
};
