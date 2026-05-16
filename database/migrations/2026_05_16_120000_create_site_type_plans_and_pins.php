<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_type_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->index();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('site_type', 32);
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->json('layout');
            $table->text('notes')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['site_id', 'status']);
            $table->index(['tenant_id', 'site_id']);
        });

        Schema::create('site_type_plan_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->index();
            $table->foreignId('site_type_plan_id')->constrained('site_type_plans')->cascadeOnDelete();
            $table->string('kind', 64);
            $table->string('subkind', 64)->nullable();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('room_ref_type', 64)->nullable();
            $table->unsignedBigInteger('room_ref_id')->nullable();
            $table->string('label', 120)->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->decimal('x', 10, 4);
            $table->decimal('y', 10, 4);
            $table->smallInteger('rotation_deg')->default(0);
            $table->decimal('width', 10, 4)->nullable();
            $table->decimal('height', 10, 4)->nullable();
            $table->json('path_points')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['site_type_plan_id', 'kind']);
            $table->index('device_id');
            $table->index(['tenant_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_type_plan_pins');
        Schema::dropIfExists('site_type_plans');
    }
};

