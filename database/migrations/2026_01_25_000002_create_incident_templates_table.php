<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('incident_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('type')->nullable()->index();
            $table->string('severity')->nullable()->index();
            $table->text('default_description')->nullable();
            $table->json('prompts')->nullable();
            $table->json('checklist')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('client_incidents', function (Blueprint $table) {
            $table->foreignId('template_id')
                ->nullable()
                ->after('shift_id')
                ->constrained('incident_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('client_incidents') && Schema::hasColumn('client_incidents', 'template_id')) {
            Schema::table('client_incidents', function (Blueprint $table) {
                $table->dropConstrainedForeignId('template_id');
            });
        }

        Schema::dropIfExists('incident_templates');
    }
};
