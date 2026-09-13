<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_setup_command_receipts', function (Blueprint $table): void {
            $table->string('resource', 32)->change();
            $table->foreignId('it_provisioning_template_id')->nullable()
                ->constrained('it_provisioning_templates')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('it_setup_command_receipts')->where('resource', 'provisioning-templates')
            ->orWhereRaw('CHAR_LENGTH(resource) > 16')->exists()) {
            throw new RuntimeException('Provisioning template command history must be retained; review its dependencies before rollback.');
        }
        Schema::table('it_setup_command_receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('it_provisioning_template_id');
            $table->string('resource', 16)->change();
        });
    }
};
