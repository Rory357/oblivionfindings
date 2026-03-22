<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_employee_profiles', function (Blueprint $table) {
            $table->string('profile_photo_path')->nullable()->after('notes');
            $table->text('bio')->nullable()->after('profile_photo_path');
            $table->string('preferred_name')->nullable()->after('bio');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employee_profiles', function (Blueprint $table) {
            $table->dropColumn(['profile_photo_path', 'bio', 'preferred_name']);
        });
    }
};
