<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chat calling settings kept on Settings › Chat instead of the server's .env (Rory, 3 Nov 2026):
 * the Oblivion Relay connection and the self-hosted voice-call server. One row, its settings
 * encrypted with the app key. Changing them needs chat.calling.manage, granted to the admin
 * role only, plus the person's password on every save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_calling_settings', function (Blueprint $table) {
            $table->id();
            // Encrypted JSON of the values saved on the page. A field that isn't there uses .env.
            $table->text('settings');
            $table->unsignedInteger('version')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        if (! Schema::hasTable('permissions')) {
            return;
        }
        DB::table('permissions')->insertOrIgnore([
            'key' => 'chat.calling.manage',
            'description' => 'Change Chat calling settings: the call relay and voice-call server addresses and keys',
            'group' => 'chat',
            'module' => 'System',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $adminId = Schema::hasTable('roles') ? DB::table('roles')->where('name', 'admin')->value('id') : null;
        $permissionId = DB::table('permissions')->where('key', 'chat.calling.manage')->value('id');
        if ($adminId && $permissionId && Schema::hasTable('role_permission')) {
            DB::table('role_permission')->insertOrIgnore(['role_id' => $adminId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_calling_settings');
        // The permission row and any assignments stay, like the other Chat permissions.
    }
};
