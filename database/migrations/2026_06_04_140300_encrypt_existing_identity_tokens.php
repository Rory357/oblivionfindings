<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Encrypt OAuth tokens already stored in `identities` so the new `encrypted` cast on
 * App\Models\Identity (access_token / refresh_token) can decrypt them. Defensive: a
 * value that already decrypts is left as-is, so the migration is safe to re-run and
 * tolerant of partially-encrypted state. Uses the query builder (not the model) to
 * bypass the cast while migrating.
 */
return new class extends Migration
{
    private array $columns = ['access_token', 'refresh_token'];

    public function up(): void
    {
        foreach (DB::table('identities')->select('id', 'access_token', 'refresh_token')->cursor() as $row) {
            $updates = [];

            foreach ($this->columns as $column) {
                $value = $row->{$column} ?? null;
                if ($value === null || $value === '') {
                    continue;
                }

                // Already encrypted? leave it. Otherwise it's plaintext → encrypt.
                try {
                    Crypt::decryptString($value);
                } catch (\Throwable) {
                    $updates[$column] = Crypt::encryptString($value);
                }
            }

            if ($updates !== []) {
                DB::table('identities')->where('id', $row->id)->update($updates);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('identities')->select('id', 'access_token', 'refresh_token')->cursor() as $row) {
            $updates = [];

            foreach ($this->columns as $column) {
                $value = $row->{$column} ?? null;
                if ($value === null || $value === '') {
                    continue;
                }

                try {
                    $updates[$column] = Crypt::decryptString($value);
                } catch (\Throwable) {
                    // Already plaintext — nothing to do.
                }
            }

            if ($updates !== []) {
                DB::table('identities')->where('id', $row->id)->update($updates);
            }
        }
    }
};
