<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Real attendee model for committee meetings — replaces the old hack of writing
 * one HrCalendarEvent per attendee with `created_by` set to the attendee. The
 * pivot tracks RSVP (invited/accepted/declined) and attendance, which the JSON
 * `attendees` / `confirmed_attendees` columns could not.
 *
 * The legacy JSON columns are left in place (additive, non-destructive); they
 * are backfilled into the pivot here and the new code treats the pivot as the
 * source of truth. The belongsToMany relation is named `attendeeUsers()` to
 * avoid shadowing the cast `attendees` attribute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hs_meeting_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('hs_committee_meetings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('response', ['invited', 'accepted', 'declined'])->default('invited');
            $table->boolean('attended')->default(false);
            $table->timestamps();

            $table->unique(['meeting_id', 'user_id']);
        });

        // Denormalised counter so the index hero/flags don't N+1 over action_items.
        Schema::table('hs_committee_meetings', function (Blueprint $table) {
            if (! Schema::hasColumn('hs_committee_meetings', 'actions_due_count')) {
                $table->unsignedInteger('actions_due_count')->default(0)->after('action_items');
            }
        });

        $this->backfillAttendees();
        $this->backfillActionsDueCount();
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_meeting_attendees');
        Schema::table('hs_committee_meetings', function (Blueprint $table) {
            if (Schema::hasColumn('hs_committee_meetings', 'actions_due_count')) {
                $table->dropColumn('actions_due_count');
            }
        });
    }

    /**
     * Backfill the pivot from the legacy JSON attendees / confirmed_attendees
     * columns. Done with the query builder (not Eloquent) so it does not depend
     * on the new relation being loaded, and FK-filtered so a stale user id in
     * the JSON cannot break the insert.
     */
    private function backfillAttendees(): void
    {
        $userIds = DB::table('users')->pluck('id')->flip();
        $now = now();

        foreach (DB::table('hs_committee_meetings')->select('id', 'attendees', 'confirmed_attendees')->cursor() as $meeting) {
            $invited = $this->ids($meeting->attendees);
            $confirmed = $this->ids($meeting->confirmed_attendees);

            $rows = [];
            foreach ($invited as $uid) {
                $rows[$uid] = ['response' => 'invited', 'attended' => false];
            }
            foreach ($confirmed as $uid) {
                $rows[$uid] = ['response' => 'accepted', 'attended' => true];
            }

            $insert = [];
            foreach ($rows as $uid => $pivot) {
                if (! $userIds->has($uid)) {
                    continue;
                }
                $insert[] = [
                    'meeting_id' => $meeting->id,
                    'user_id' => $uid,
                    'response' => $pivot['response'],
                    'attended' => $pivot['attended'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($insert) {
                DB::table('hs_meeting_attendees')->insertOrIgnore($insert);
            }
        }
    }

    private function backfillActionsDueCount(): void
    {
        foreach (DB::table('hs_committee_meetings')->select('id', 'action_items', 'status')->cursor() as $meeting) {
            if ($meeting->status === 'cancelled') {
                continue;
            }
            $items = json_decode($meeting->action_items ?? '[]', true) ?: [];
            $due = collect($items)->filter(fn ($i) => ($i['status'] ?? null) !== 'done')->count();
            if ($due > 0) {
                DB::table('hs_committee_meetings')->where('id', $meeting->id)->update(['actions_due_count' => $due]);
            }
        }
    }

    /**
     * @return int[]
     */
    private function ids(?string $json): array
    {
        $decoded = json_decode($json ?? '[]', true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_filter($decoded, 'is_numeric'))));
    }
};
