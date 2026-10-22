<?php

namespace App\Support\Fixtures;

use App\Models\Client;

/** Explicit non-production fixture seeding, never a service activation or an import API. */
final class HistoricalClientFixture
{
    public static function save(Client $client): bool
    {
        if (app()->environment('production') || $client->exists) {
            throw new \LogicException('Historical client fixtures require a new synthetic record outside production.');
        }

        return $client->getConnection()->transaction(function () use ($client): bool {
            $historical = ['status' => $client->status, 'service_start_date' => $client->getAttributes()['service_start_date'] ?? null];
            // Run normal identity, encryption and audit hooks before restoring fictional historical state.
            $client->forceFill(['status' => 'onboarding', 'service_start_date' => null]);
            $saved = $client->save();
            if ($saved) {
                $client->getConnection()->table($client->getTable())->where('id', $client->id)->update($historical);
                $client->forceFill($historical)->syncOriginalAttributes(array_keys($historical));
            }

            return $saved;
        });
    }
}
