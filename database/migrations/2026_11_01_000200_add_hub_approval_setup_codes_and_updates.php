<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S&D Phase B (plan v10): hubs wait for an admin's approval, setup codes travel in the
 * installer's file name (one computer, 24 hours, can be cancelled, D2), each hub's saved
 * logins are encrypted with a key only the server hands out, and hubs update themselves
 * overnight in rings with roll-back (D3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_collectors', function (Blueprint $table): void {
            $table->timestamp('approved_at')->nullable()->after('enrolled_at');
            $table->foreignId('approved_by_user_id')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            // The key that unlocks the hub's encrypted login store. Held here, encrypted at rest;
            // handed to the hub only while it is approved and not removed.
            $table->text('vault_key_ciphertext')->nullable()->after('approved_by_user_id');
            $table->timestamp('vault_key_rotated_at')->nullable()->after('vault_key_ciphertext');
            $table->string('update_ring', 16)->default('everyone')->after('vault_key_rotated_at');
            $table->string('update_window_start', 5)->default('02:00')->after('update_ring');
            $table->string('update_window_end', 5)->default('05:00')->after('update_window_start');
            // Last update the hub reported: target, outcome, rolled back from, when.
            $table->json('update_state')->nullable()->after('update_window_end');
        });

        // Hubs already working before approval existed keep working: they are counted as
        // approved from when they enrolled. Removed hubs stay removed.
        DB::table('monitoring_collectors')
            ->whereNull('revoked_at')
            ->whereNotNull('enrolled_at')
            ->update(['approved_at' => DB::raw('enrolled_at')]);

        Schema::create('security_device_setup_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('role', 16); // hub | computer
            $table->string('platform', 16)->default('windows'); // windows | linux
            // Computers only: "staff" installs Obli for the signed-in person, no admin needed;
            // "full" is IT's install with the service (pop-up 23).
            $table->string('install_mode', 16)->nullable();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('issued_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('collector_enrollment_id')->nullable()->constrained('monitoring_collector_enrollments')->nullOnDelete();
            $table->foreignId('replaces_collector_id')->nullable()->constrained('monitoring_collectors')->nullOnDelete();
            $table->string('intended_name', 120)->nullable();
            // The 8-character code, hashed for lookup and encrypted so the download can name the file.
            $table->char('code_hash', 64)->unique();
            $table->text('code_ciphertext')->nullable();
            // What the installer receives when it redeems the code. Cleared once used, cancelled or expired.
            $table->longText('profile_ciphertext')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('redeemed_at')->nullable();
            $table->unsignedSmallInteger('redeem_count')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'role', 'expires_at'], 'sd_setup_codes_site_role_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_device_setup_codes');
        Schema::table('monitoring_collectors', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropColumn([
                'approved_at', 'vault_key_ciphertext', 'vault_key_rotated_at',
                'update_ring', 'update_window_start', 'update_window_end', 'update_state',
            ]);
        });
    }
};
