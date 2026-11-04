<?php

use App\Enums\ClientGender;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * To-do #12: Gender becomes a dropdown (Stats NZ 2021: Female, Male, Another
 * gender with the person's own words, plus Prefer not to say).
 *
 * - `clients.gender` now holds a stable code from App\Enums\ClientGender.
 * - `clients.gender_description` (new, max 100) holds the words for Another gender.
 * - `clients.gender_recorded_as` (new) keeps exactly what was typed before, for
 *   every row this migration changes, so nothing is lost and down() can put it back.
 *
 * Typed answers are mapped case-insensitively and trimmed (f / woman / girl →
 * Female, m / man / boy → Male, not stated / declined / unknown → Prefer not to
 * say). Non-binary, takatāpui, transgender and similar answers are real genders:
 * they map to Another gender with the person's words as the description, never
 * to Female or Male. Anything that does not map clearly is not guessed: gender
 * becomes empty, the text stays in gender_recorded_as, and the profile asks
 * someone to choose an option. Blank values are left as they are.
 *
 * Safe to run again: only rows whose gender is not yet a code are looked at,
 * and updated_at is not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'gender_description')) {
            Schema::table('clients', function (Blueprint $table): void {
                $table->string('gender_description', ClientGender::DESCRIPTION_MAX_LENGTH)->nullable()->after('gender');
            });
        }

        if (! Schema::hasColumn('clients', 'gender_recorded_as')) {
            Schema::table('clients', function (Blueprint $table): void {
                $table->string('gender_recorded_as')->nullable()->after('gender_description');
            });
        }

        $this->mapTypedGenders();
    }

    public function down(): void
    {
        if (! Schema::hasColumn('clients', 'gender_recorded_as') || ! Schema::hasColumn('clients', 'gender_description')) {
            return;
        }

        $this->restoreTypedGenders();

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['gender_recorded_as', 'gender_description']);
        });
    }

    /**
     * Map every typed answer to a code. Public so the feature test can check
     * the mapping inside its own transaction (no schema change needed).
     *
     * @return array<string, int> how many rows went to each option, plus 'flagged'
     */
    public function mapTypedGenders(): array
    {
        $counts = array_fill_keys([...ClientGender::values(), 'flagged'], 0);

        // Codes are compared in PHP, not SQL: the column's collation treats
        // "Female" and "female " as equal to the code "female".
        DB::table('clients')
            ->whereNotNull('gender')
            ->select(['id', 'gender', 'gender_recorded_as'])
            ->chunkById(500, function ($rows) use (&$counts): void {
                foreach ($rows as $row) {
                    $typed = (string) $row->gender;
                    if (trim($typed) === '' || in_array($typed, ClientGender::values(), true)) {
                        continue;
                    }

                    $mapped = ClientGender::fromLegacyText($typed);
                    $values = $mapped === null
                        ? ['gender' => null, 'gender_description' => null]
                        : [
                            'gender' => $mapped[0]->value,
                            'gender_description' => $mapped[0] === ClientGender::AnotherGender ? $mapped[1] : null,
                        ];
                    $values['gender_recorded_as'] = $row->gender_recorded_as ?? $typed;

                    // Only if nobody changed the row since it was read.
                    $updated = DB::table('clients')
                        ->where('id', $row->id)
                        ->where('gender', $typed)
                        ->update($values);

                    if ($updated > 0) {
                        $counts[$values['gender'] ?? 'flagged']++;
                    }
                }
            });

        return $counts;
    }

    /**
     * Put back what the free-text box held. Rows this migration changed get
     * their exact typed text back; rows saved with the dropdown since then get
     * the readable words the box would have held. Female and male stay as they
     * are (the same words the box accepted).
     */
    public function restoreTypedGenders(): void
    {
        DB::table('clients')
            ->whereNotNull('gender_recorded_as')
            ->update(['gender' => DB::raw('gender_recorded_as')]);

        DB::table('clients')
            ->whereNull('gender_recorded_as')
            ->where('gender', ClientGender::AnotherGender->value)
            ->whereNotNull('gender_description')
            ->update(['gender' => DB::raw('gender_description')]);

        DB::table('clients')
            ->whereNull('gender_recorded_as')
            ->where('gender', ClientGender::AnotherGender->value)
            ->update(['gender' => ClientGender::AnotherGender->label()]);

        DB::table('clients')
            ->whereNull('gender_recorded_as')
            ->where('gender', ClientGender::PreferNotToSay->value)
            ->update(['gender' => ClientGender::PreferNotToSay->label()]);
    }
};
