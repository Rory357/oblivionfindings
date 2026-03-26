<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'cellphone')) {
                $table->string('cellphone', 50)->nullable()->after('email');
            }
            if (!Schema::hasColumn('users', 'work_phone')) {
                $table->string('work_phone', 50)->nullable()->after('cellphone');
            }
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('type', 10)->default('email');
            $table->string('key', 100)->unique();
            $table->string('name', 255);
            $table->string('category', 50);
            $table->string('subject', 500)->nullable();
            $table->text('body');
            $table->json('merge_fields');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'cellphone')) {
                $table->dropColumn('cellphone');
            }
            if (Schema::hasColumn('users', 'work_phone')) {
                $table->dropColumn('work_phone');
            }
        });
    }
};
