<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * First Aid Register gold-standard upgrade — Step 1 (docs/first-aid-redesign §0).
 *
 * (a) Adds a nullable client_id FK so a treated *client* links to their profile —
 *     enables the read-only "First-aid treatments" panel and mirrors fleet's direct
 *     Fleet<->Client FK. treated_person_id stays the optional staff/user link.
 * (b) Normalises the treatment_outcome vocabulary to one canonical set. The column is
 *     a plain VARCHAR (no DB enum), so this is a pure, idempotent data UPDATE with zero
 *     schema risk. The ambulance_called boolean column is preserved untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('first_aid_records')) {
            return;
        }

        if (! Schema::hasColumn('first_aid_records', 'client_id')) {
            Schema::table('first_aid_records', function (Blueprint $table) {
                $table->foreignId('client_id')->nullable()->after('treated_person_id')
                    ->constrained('clients')->nullOnDelete();
                $table->index('client_id', 'far_client_idx');
            });
        }

        // Collapse duplicate-spelling outcomes to the canonical seven. Lossy by design —
        // not reversed in down(). ambulance_called rows keep the boolean flag + map to
        // the closest real outcome (sent_to_hospital).
        foreach ([
            'returned_to_work' => 'returned_to_activity',
            'sent_to_medical' => 'medical_centre',
            'hospital' => 'sent_to_hospital',
            'ambulance_called' => 'sent_to_hospital',
        ] as $old => $new) {
            DB::table('first_aid_records')->where('treatment_outcome', $old)->update(['treatment_outcome' => $new]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('first_aid_records') && Schema::hasColumn('first_aid_records', 'client_id')) {
            Schema::table('first_aid_records', function (Blueprint $table) {
                $table->dropForeign(['client_id']);
                $table->dropIndex('far_client_idx');
                $table->dropColumn('client_id');
            });
        }
    }
};
