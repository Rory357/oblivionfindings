<?php

namespace Tests\Feature\Database;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationOrderVersion;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class MedicationCessationPrecisionMigrationTest extends TestCase
{
    public function test_safe_midnight_cessations_round_trip_down_and_up_without_loss(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        [$user, $client, $medication, $version] = $this->cessationEvidence('2026-08-21 00:00:00');
        $migration = $this->migration();

        try {
            $migration->down();

            $this->assertSame('date', $this->columnType('client_medications'));
            $this->assertSame('date', $this->columnType('medication_order_versions'));
            $this->assertSame(
                '2026-08-21',
                DB::table('client_medications')->where('id', $medication->id)->value('ceased_at'),
            );
            $this->assertSame(
                '2026-08-21',
                DB::table('medication_order_versions')->where('id', $version->id)->value('ceased_at'),
            );

            $migration->up();

            $this->assertSame('datetime', $this->columnType('client_medications'));
            $this->assertSame('datetime', $this->columnType('medication_order_versions'));
            $this->assertSame(
                '2026-08-21 00:00:00',
                DB::table('client_medications')->where('id', $medication->id)->value('ceased_at'),
            );
            $this->assertSame(
                '2026-08-21 00:00:00',
                DB::table('medication_order_versions')->where('id', $version->id)->value('ceased_at'),
            );
        } finally {
            $this->restoreDateTimeColumns($migration);
            $this->deleteEvidence($user, $client, $medication, $version);
        }
    }

    public function test_down_refuses_each_non_midnight_cessation_before_either_column_changes(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        [$user, $client, $medication, $version] = $this->cessationEvidence('2026-08-21 00:00:00');
        $migration = $this->migration();

        try {
            foreach ([
                'client_medications' => $medication->id,
                'medication_order_versions' => $version->id,
            ] as $table => $id) {
                DB::table('client_medications')->where('id', $medication->id)->update(['ceased_at' => '2026-08-21 00:00:00']);
                DB::table('medication_order_versions')->where('id', $version->id)->update(['ceased_at' => '2026-08-21 00:00:00']);
                DB::table($table)->where('id', $id)->update(['ceased_at' => '2026-08-21 14:35:12']);

                try {
                    $migration->down();
                    $this->fail("Non-midnight cessation in {$table} did not block rollback.");
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString($table, $exception->getMessage());
                }

                $this->assertSame('datetime', $this->columnType('client_medications'));
                $this->assertSame('datetime', $this->columnType('medication_order_versions'));
                $this->assertSame(
                    '2026-08-21 14:35:12',
                    DB::table($table)->where('id', $id)->value('ceased_at'),
                );
            }
        } finally {
            $this->restoreDateTimeColumns($migration);
            $this->deleteEvidence($user, $client, $medication, $version);
        }
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_14_000064_preserve_medication_cessation_time.php');
    }

    /** @return array{0: User, 1: Client, 2: ClientMedication, 3: MedicationOrderVersion} */
    private function cessationEvidence(string $ceasedAt): array
    {
        $user = User::factory()->create(['approved_at' => now()]);
        $client = Client::withoutEvents(
            fn (): Client => Client::factory()->create(['status' => 'active']),
        );
        $medication = ClientMedication::withoutEvents(
            fn (): ClientMedication => ClientMedication::query()->create([
                'client_id' => $client->id,
                'created_by' => $user->id,
                'name' => 'Migration precision medication',
                'active' => false,
                'state' => 'ceased',
                'ceased_at' => $ceasedAt,
                'ceased_reason' => 'Migration precision evidence',
                'ceased_by' => $user->id,
            ]),
        );
        $version = MedicationOrderVersion::withoutEvents(
            fn (): MedicationOrderVersion => MedicationOrderVersion::query()->create([
                'client_medication_id' => $medication->id,
                'client_id' => $client->id,
                'version_number' => 2,
                'name' => $medication->name,
                'ceased_at' => $ceasedAt,
                'ceased_reason' => $medication->ceased_reason,
                'state' => 'ceased',
                'active' => false,
                'change_reason' => 'Migration precision evidence',
                'changed_by' => $user->id,
                'changed_at' => $ceasedAt,
            ]),
        );

        return [$user, $client, $medication, $version];
    }

    private function columnType(string $table): string
    {
        return (string) DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', 'ceased_at')
            ->value('DATA_TYPE');
    }

    private function restoreDateTimeColumns(Migration $migration): void
    {
        if ($this->columnType('client_medications') !== 'datetime'
            || $this->columnType('medication_order_versions') !== 'datetime') {
            $migration->up();
        }
    }

    private function deleteEvidence(
        User $user,
        Client $client,
        ClientMedication $medication,
        MedicationOrderVersion $version,
    ): void {
        DB::table('medication_order_versions')->where('id', $version->id)->delete();
        DB::table('client_medications')->where('id', $medication->id)->delete();
        DB::table('clients')->where('id', $client->id)->delete();
        DB::table('users')->where('id', $user->id)->delete();
    }
}
