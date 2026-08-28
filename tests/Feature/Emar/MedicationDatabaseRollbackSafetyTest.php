<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationIdempotencyResult;
use App\Models\MedicationPrescriberOrder;
use App\Models\MedicationRoundTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class MedicationDatabaseRollbackSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_idempotency_migration_refuses_to_drop_a_populated_replay_ledger(): void
    {
        $binding = MedicationIdempotencyResult::query()->create([
            'scope' => 'test:durable-medication-action',
            'request_uuid' => (string) Str::uuid(),
            'response_payload' => ['result_id' => 42],
            'expires_at' => null,
        ]);
        $migration = require database_path(
            'migrations/2026_08_14_230100_create_medication_idempotency_results.php',
        );

        try {
            try {
                $migration->down();
                $this->fail('Rollback must not discard medication replay bindings.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Cannot remove the medication idempotency replay ledger while retained request bindings exist.',
                    $exception->getMessage(),
                );
                $this->assertTrue(Schema::hasTable('medication_idempotency_results'));
                $this->assertSame(
                    1,
                    DB::table('medication_idempotency_results')->where('id', $binding->id)->count(),
                );
            }
        } finally {
            if (Schema::hasTable('medication_idempotency_results')) {
                DB::table('medication_idempotency_results')->where('id', $binding->id)->delete();
                $migration->down();
            }

            if (! Schema::hasTable('medication_idempotency_results')) {
                $migration->up();
            }
        }
    }

    public function test_correction_requester_migration_refuses_to_drop_attributed_provenance(): void
    {
        $client = Client::factory()->create();
        $medication = ClientMedication::factory()->create(['client_id' => $client->id]);
        $administrator = User::factory()->create();
        $requester = User::factory()->create();
        $administration = ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'administered_by' => $administrator->id,
            'administered_at' => now(),
            'status' => 'given',
            'correction_requested_by' => $requester->id,
        ]);
        $migration = require database_path(
            'migrations/2026_08_28_000100_add_correction_requester_to_medication_administrations.php',
        );

        try {
            try {
                $migration->down();
                $this->fail('Rollback must not discard medication-correction requester provenance.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Cannot remove medication-correction requester provenance while attributed correction evidence exists.',
                    $exception->getMessage(),
                );
                $this->assertTrue(Schema::hasColumn(
                    'client_medication_administrations',
                    'correction_requested_by',
                ));
                $this->assertSame(
                    $requester->id,
                    (int) DB::table('client_medication_administrations')
                        ->where('id', $administration->id)
                        ->value('correction_requested_by'),
                );
            }
        } finally {
            DB::table('client_medication_administrations')
                ->where('id', $administration->id)
                ->delete();

            if (Schema::hasColumn('client_medication_administrations', 'correction_requested_by')) {
                $migration->down();
            }

            if (! Schema::hasColumn('client_medication_administrations', 'correction_requested_by')) {
                $migration->up();
            }
        }
    }

    public function test_read_back_migration_refuses_one_sided_or_complete_verification_provenance(): void
    {
        $client = Client::factory()->create();
        $witness = User::factory()->create();
        $verifiedAtOnly = $this->prescriberOrder($client, $witness, [
            'read_back_verified_at' => now(),
            'read_back_verification_method' => null,
        ]);
        $methodOnly = $this->prescriberOrder($client, $witness, [
            'read_back_verified_at' => null,
            'read_back_verification_method' => MedicationPrescriberOrder::READ_BACK_VERIFICATION_METHOD_PASSWORD,
        ]);
        $migration = require database_path(
            'migrations/2026_08_28_000300_add_read_back_verification_provenance_to_prescriber_orders.php',
        );

        try {
            try {
                $migration->down();
                $this->fail('Rollback must not discard verified read-back provenance.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Cannot remove verified read-back provenance while retained verification evidence exists.',
                    $exception->getMessage(),
                );
                $this->assertTrue(Schema::hasColumn(
                    'medication_prescriber_orders',
                    'read_back_verified_at',
                ));
                $this->assertTrue(Schema::hasColumn(
                    'medication_prescriber_orders',
                    'read_back_verification_method',
                ));
                $this->assertNotNull(DB::table('medication_prescriber_orders')
                    ->where('id', $verifiedAtOnly->id)
                    ->value('read_back_verified_at'));
                $this->assertSame(
                    MedicationPrescriberOrder::READ_BACK_VERIFICATION_METHOD_PASSWORD,
                    DB::table('medication_prescriber_orders')
                        ->where('id', $methodOnly->id)
                        ->value('read_back_verification_method'),
                );
            }
        } finally {
            DB::table('medication_prescriber_orders')
                ->whereIn('id', [$verifiedAtOnly->id, $methodOnly->id])
                ->delete();

            if (Schema::hasColumn('medication_prescriber_orders', 'read_back_verified_at')) {
                $migration->down();
            }

            if (! Schema::hasColumn('medication_prescriber_orders', 'read_back_verified_at')) {
                $migration->up();
            }
        }
    }

    public function test_round_template_retirement_migration_refuses_one_sided_actor_provenance(): void
    {
        $actor = User::factory()->create();
        $template = MedicationRoundTemplate::query()->create([
            'name' => 'Rollback provenance template',
            'scheduled_time' => '08:00',
            'window_minutes' => 60,
            'days_of_week' => [1, 2, 3, 4, 5],
            'active' => true,
        ]);
        DB::table('medication_round_templates')
            ->where('id', $template->id)
            ->update(['retired_by_user_id' => $actor->id]);
        $migration = require database_path(
            'migrations/2026_08_28_000400_add_retirement_to_medication_round_templates.php',
        );

        try {
            try {
                $migration->down();
                $this->fail('Rollback must not discard one-sided round-template retirement actor provenance.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Cannot remove medication round-template retirement fields while retained retirement evidence exists.',
                    $exception->getMessage(),
                );
                $this->assertTrue(Schema::hasColumn('medication_round_templates', 'retired_at'));
                $this->assertTrue(Schema::hasColumn('medication_round_templates', 'retired_by_user_id'));
                $this->assertSame(
                    $actor->id,
                    (int) DB::table('medication_round_templates')
                        ->where('id', $template->id)
                        ->value('retired_by_user_id'),
                );
            }
        } finally {
            DB::table('medication_round_templates')
                ->where('id', $template->id)
                ->delete();

            if (Schema::hasColumn('medication_round_templates', 'retired_at')) {
                $migration->down();
            }

            if (! Schema::hasColumn('medication_round_templates', 'retired_at')) {
                $migration->up();
            }
        }
    }

    /** @param array<string, mixed> $overrides */
    private function prescriberOrder(Client $client, User $witness, array $overrides): MedicationPrescriberOrder
    {
        return MedicationPrescriberOrder::query()->create([
            'client_id' => $client->id,
            'order_type' => 'telephone',
            'status' => 'confirmed',
            'prescriber_name' => 'Dr Rollback',
            'medication_name' => 'Read-back rollback evidence',
            'dose' => '1 tablet',
            'route' => 'Oral',
            'frequency' => 'Once daily',
            'order_date' => today(),
            'requires_countersign' => true,
            'read_back_confirmed' => true,
            'read_back_witnessed_by' => $witness->id,
            'countersigned_at' => now(),
            ...$overrides,
        ]);
    }
}
