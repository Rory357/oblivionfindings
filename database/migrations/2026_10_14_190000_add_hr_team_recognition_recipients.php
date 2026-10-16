<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_kudos', function (Blueprint $table): void {
            $table->foreignId('to_user_id')->nullable()->change();
            $table->string('recipient_type', 10)->default('person');
            $table->foreignId('to_department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
        });
        Schema::create('hr_kudos_recipients', function (Blueprint $table): void {
            $table->foreignId('kudos_id')->constrained('hr_kudos')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['kudos_id', 'user_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('hr_kudos')->where('recipient_type', 'team')->orWhereNull('to_user_id')->exists()) {
            throw new RuntimeException('Remove team recognition through its audited workflow before rolling back recipient storage.');
        }
        Schema::dropIfExists('hr_kudos_recipients');
        Schema::table('hr_kudos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('to_department_id');
            $table->dropColumn('recipient_type');
            $table->foreignId('to_user_id')->nullable(false)->change();
        });
    }
};
