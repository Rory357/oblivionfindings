<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S&D Phase H, decision D15: clock-ins suggested from door badges. Off by default.
 *
 * - settings: one row, organisation-wide (the window around a rostered start, whether the badge
 *   time is offered, who hears about gaps) and the engine's place in the door-event stream.
 * - notices: what staff were told before it started at their Site (Privacy Act IPP3; door events
 *   used for a second purpose, IPP10). Each door counts only from its notice's start date, at
 *   least a week after the notice went out.
 * - doors: the entry doors that count, each with its notice and start date. No rows = off.
 * - clockins: one row per rostered start at a Site where it's on (its badge, what the person
 *   chose, the 15-minute gap check, a manager's review), and one per badge from someone not on
 *   the roster. A row is not a time record: confirmed clock-ins are attendance sessions with the
 *   source "door_badge". Rows go at 90 days, like the names on door events (D14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_door_clockin_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('before_minutes')->default(60);
            $table->unsignedSmallInteger('after_minutes')->default(30);
            $table->boolean('badge_time')->default(true);
            $table->boolean('gap_unconfirmed_app')->default(true);
            $table->boolean('gap_unconfirmed_email')->default(false);
            $table->boolean('gap_no_badge_app')->default(true);
            $table->boolean('gap_no_badge_email')->default(false);
            $table->boolean('gap_not_rostered_app')->default(true);
            // Off by default: badges when someone isn't rostered go to the house manager in the app only.
            $table->boolean('gap_not_rostered_email')->default(false);
            $table->unsignedBigInteger('cursor_event_id')->default(0);
            $table->timestamp('turned_off_at')->nullable();
            $table->foreignId('turned_off_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        // The one row, with its defaults: off (no doors count) until someone turns it on.
        DB::table('security_door_clockin_settings')->insert(['created_at' => now(), 'updated_at' => now()]);

        Schema::create('security_door_clockin_notices', function (Blueprint $table): void {
            $table->id();
            $table->text('body');
            $table->boolean('by_email')->default(false);
            $table->json('site_ids');
            $table->json('door_ids');
            $table->date('starts_on'); // NZ date
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at');
            $table->unsignedInteger('recipients')->default(0);
            $table->timestamps();
        });

        // Who was actually told, per Site (IPP3). Their badges there count only from a week after this.
        Schema::create('security_door_clockin_told', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notice_id')->constrained('security_door_clockin_notices')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('told_at');
            $table->boolean('by_email')->default(false);
            $table->timestamps();

            $table->unique(['notice_id', 'site_id', 'user_id'], 'sd_door_clockin_told_unique');
            $table->index(['user_id', 'site_id', 'told_at'], 'sd_door_clockin_told_user_site_idx');
        });

        Schema::create('security_door_clockin_doors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('door_id')->unique()->constrained('security_access_doors')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('notice_id')->nullable()->constrained('security_door_clockin_notices')->nullOnDelete();
            $table->date('starts_on'); // NZ date: badges before it never count
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('security_door_clockins', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16); // rostered | not_rostered
            $table->string('dedupe_key', 64)->unique(); // shift:{id}:{user} | badge:{user}:{site}:{day}
            $table->date('day'); // NZ date of the shift start or the badge
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->timestamp('shift_starts_at')->nullable();
            $table->timestamp('shift_ends_at')->nullable();
            $table->foreignId('event_id')->nullable()->constrained('security_access_events')->nullOnDelete();
            $table->foreignId('door_id')->nullable()->constrained('security_access_doors')->nullOnDelete();
            $table->string('method', 16)->nullable(); // card | pin | app (never face or fingerprint, D18)
            $table->timestamp('badge_at')->nullable();
            $table->timestamp('suggested_at')->nullable();
            $table->string('answer', 16)->nullable(); // badge | now | not_working
            $table->timestamp('answered_at')->nullable();
            $table->foreignId('attendance_session_id')->nullable()->constrained('hr_attendance_sessions')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 24)->nullable(); // turned_off | ended | shift_changed
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('manager_told_at')->nullable();
            $table->json('manager_told_user_ids')->nullable();
            $table->timestamp('gap_checked_at')->nullable();
            $table->string('review', 16)->nullable(); // kept | asked | not_work
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'day'], 'sd_door_clockins_site_day_idx');
            $table->index(['user_id', 'day'], 'sd_door_clockins_user_day_idx');
            $table->index('shift_id', 'sd_door_clockins_shift_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_door_clockins');
        Schema::dropIfExists('security_door_clockin_doors');
        Schema::dropIfExists('security_door_clockin_told');
        Schema::dropIfExists('security_door_clockin_notices');
        Schema::dropIfExists('security_door_clockin_settings');
    }
};
