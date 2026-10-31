<?php

namespace App\Services\Clients;

use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Serializes canonical identity writes and referral duplicate decisions in one application. */
final class ClientIdentityWrites
{
    public function lock(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Identity decisions must be made inside their write transaction.');
        }
        $mutex = DB::table('client_identity_write_mutex')->where('id', 1);
        // The row is created by migration, but a regenerated schema dump carries no data rows.
        // Create it on demand only when missing, so the usual path never takes an insert lock.
        if ((clone $mutex)->lockForUpdate()->first() === null) {
            DB::table('client_identity_write_mutex')->insertOrIgnore(['id' => 1]);
            (clone $mutex)->lockForUpdate()->firstOrFail();
        }
    }

    public function save(Client $client, callable $write): bool
    {
        // Schema bootstrap and rollback can legitimately predate the new mutex migration.
        if (! Schema::hasTable('client_identity_write_mutex')) {
            return $write();
        }

        return DB::transaction(function () use ($client, $write): bool {
            $this->lock();
            $hash = Client::nhiHash($client->nhi_number);
            if ($hash && Client::withTrashed()->where('nhi_hash', $hash)
                ->when($client->exists, fn ($query) => $query->whereKeyNot($client->id))->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['nhi_number' => 'This identity needs an authorized review before it can be saved.']);
            }

            return $write();
        });
    }
}
