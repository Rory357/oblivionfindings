<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationPrescriberOrder;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicationPrescriberOrderAuditVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_prescriber_order_snapshot_controls_audit_feed_and_direct_actions(): void
    {
        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $ordinaryMedication = $this->medication($client, 'Ordinary linked medication');
        $controlledMedication = $this->medication($client, 'Controlled linked medication', true);
        $ordinaryReader = $this->reader($site);
        $controlledReader = $this->reader($site, true);
        $this->coveringShift($ordinaryReader, $client, $site);
        $this->coveringShift($controlledReader, $client, $site);

        $linkedOrdinary = $this->order($client, 'VISIBLE LINKED ORDINARY', $ordinaryMedication, null);
        $unlinkedOrdinary = $this->order($client, 'VISIBLE UNLINKED ORDINARY', null, false);
        $unlinkedControlled = $this->order($client, 'RESTRICTED UNLINKED CONTROLLED', null, true);
        $unlinkedUnknown = $this->order($client, 'RESTRICTED UNLINKED UNKNOWN', null, null);
        $restrictiveLinkedSnapshot = $this->order(
            $client,
            'RESTRICTED LINKED SNAPSHOT',
            $ordinaryMedication,
            true,
        );
        $linkedControlled = $this->order(
            $client,
            'RESTRICTED LINKED CONTROLLED',
            $controlledMedication,
            false,
        );

        $ordinaryVisible = [$linkedOrdinary, $unlinkedOrdinary];
        $ordinaryConcealed = [
            $unlinkedControlled,
            $unlinkedUnknown,
            $restrictiveLinkedSnapshot,
            $linkedControlled,
        ];
        $allOrders = [...$ordinaryVisible, ...$ordinaryConcealed];

        $ordinaryFeedIds = collect($this->actingAs($ordinaryReader)
            ->get(route('emar.audit'))
            ->assertOk()
            ->inertiaProps('events'))
            ->where('event_type', 'prescriber_order')
            ->pluck('id')
            ->all();
        $this->assertEqualsCanonicalizing(
            collect($ordinaryVisible)->map(fn (MedicationPrescriberOrder $order) => 'order_'.$order->id)->all(),
            $ordinaryFeedIds,
        );

        $controlledFeedIds = collect($this->actingAs($controlledReader)
            ->get(route('emar.audit'))
            ->assertOk()
            ->inertiaProps('events'))
            ->where('event_type', 'prescriber_order')
            ->pluck('id')
            ->all();
        $this->assertEqualsCanonicalizing(
            collect($allOrders)->map(fn (MedicationPrescriberOrder $order) => 'order_'.$order->id)->all(),
            $controlledFeedIds,
        );

        foreach ($ordinaryVisible as $order) {
            $eventId = 'order_'.$order->id;
            $this->actingAs($ordinaryReader)
                ->getJson(route('emar.audit.event.integrity', ['id' => $eventId]))
                ->assertOk()
                ->assertExactJson(['backed' => true]);
            $this->actingAs($ordinaryReader)
                ->get(route('emar.audit.event.export', ['id' => $eventId]))
                ->assertOk();
        }

        foreach ($ordinaryConcealed as $order) {
            $eventId = 'order_'.$order->id;
            $this->actingAs($ordinaryReader)
                ->getJson(route('emar.audit.event.integrity', ['id' => $eventId]))
                ->assertNotFound();
            $this->actingAs($ordinaryReader)
                ->get(route('emar.audit.event.export', ['id' => $eventId]))
                ->assertNotFound();
            $this->actingAs($ordinaryReader)
                ->post(route('emar.audit.event.flag', ['id' => $eventId]), [
                    'note' => 'Must not disclose or mutate concealed prescriber order.',
                ])
                ->assertNotFound();
        }
        $this->assertDatabaseCount('medication_errors', 0);

        $this->actingAs($ordinaryReader)
            ->post(route('emar.audit.event.flag', ['id' => 'order_'.$unlinkedOrdinary->id]), [
                'note' => 'Visible ordinary unlinked order needs review.',
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('medication_errors', [
            'client_id' => $client->id,
            'client_medication_id' => null,
            'reported_by' => $ordinaryReader->id,
            'status' => 'reported',
        ]);

        $restrictedEventId = 'order_'.$restrictiveLinkedSnapshot->id;
        $this->actingAs($controlledReader)
            ->getJson(route('emar.audit.event.integrity', ['id' => $restrictedEventId]))
            ->assertOk()
            ->assertExactJson(['backed' => true]);
        $this->actingAs($controlledReader)
            ->get(route('emar.audit.event.export', ['id' => $restrictedEventId]))
            ->assertOk();
        $this->actingAs($controlledReader)
            ->post(route('emar.audit.event.flag', ['id' => $restrictedEventId]), [
                'note' => 'Controlled-authorized review of restrictive snapshot.',
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('medication_errors', [
            'client_id' => $client->id,
            'client_medication_id' => $ordinaryMedication->id,
            'reported_by' => $controlledReader->id,
            'status' => 'reported',
        ]);
        $this->assertDatabaseCount('medication_errors', 2);
    }

    private function medication(Client $client, string $name, bool $controlled = false): ClientMedication
    {
        return ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => $name,
            'controlled_drug' => $controlled,
            'is_prn' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
    }

    private function order(
        Client $client,
        string $medicationName,
        ?ClientMedication $medication,
        ?bool $controlledSnapshot,
    ): MedicationPrescriberOrder {
        return MedicationPrescriberOrder::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication?->id,
            'controlled_drug_snapshot' => $controlledSnapshot,
            'order_type' => 'new',
            'status' => 'pending',
            'prescriber_name' => 'Dr Audit Scope',
            'medication_name' => $medicationName,
            'dose' => '1 tablet',
            'route' => 'Oral',
            'frequency' => 'Once daily',
            'order_date' => today(),
        ]);
    }

    private function reader(Site $site, bool $controlled = false): User
    {
        $permissions = [
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            'medications.audit.view',
            'medications.reports.export',
            'medications.administer.record',
            ...($controlled ? [MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY] : []),
        ];
        $reader = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $permissionIds = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $this->assertCount(count($permissions), $permissionIds);
        $reader->permissionOverrides()->sync(
            $permissionIds->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $reader->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subDay(),
            'end_date' => null,
        ]);

        return $reader->fresh();
    }

    private function coveringShift(User $reader, Client $client, Site $site): void
    {
        Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $client->service_context_id,
            'user_id' => $reader->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'started_by' => $reader->id,
            'created_by' => $reader->id,
            'status' => 'in_progress',
        ]);
    }
}
