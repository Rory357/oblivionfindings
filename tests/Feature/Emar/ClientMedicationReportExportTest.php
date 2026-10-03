<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P02-1b: the profile MAR tab's Report uses the exports that exist — the
 * MAR chart PDF and the dose history CSV — for one person and a range of NZ
 * days, with an "include as-needed" option, behind the exports' permission
 * and the per-person record gate.
 */
class ClientMedicationReportExportTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $aroha;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00', 'Pacific/Auckland')->utc());
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->aroha = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $this->reader = $this->staff(['medications.view', 'medications.reports.export', 'clients.viewAny']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_dose_history_counts_nz_days_and_can_leave_out_as_needed_doses(): void
    {
        $metformin = $this->order('Metformin', prn: false);
        $paracetamol = $this->order('Paracetamol', prn: true);
        // 7:00 am NZ on the 15th is still the 14th in UTC.
        $this->given($metformin, '2026-06-15 07:00', 'early-morning dose');
        $this->given($paracetamol, '2026-06-15 21:00', 'evening as-needed dose');
        // 0:30 am NZ on the 16th is still the 15th in UTC: not that NZ day.
        $this->given($metformin, '2026-06-16 00:30', 'after-midnight dose');

        $csv = $this->csv(['date_from' => '2026-06-15', 'date_to' => '2026-06-15']);
        $this->assertStringContainsString('early-morning dose', $csv);
        $this->assertStringContainsString('evening as-needed dose', $csv);
        $this->assertStringNotContainsString('after-midnight dose', $csv);

        $scheduledOnly = $this->csv(['date_from' => '2026-06-15', 'date_to' => '2026-06-15', 'include_prn' => '0']);
        $this->assertStringContainsString('early-morning dose', $scheduledOnly);
        $this->assertStringNotContainsString('evening as-needed dose', $scheduledOnly);
    }

    public function test_the_exports_follow_their_permission_and_the_per_person_gate(): void
    {
        $elsewhere = Client::factory()->create(['site_id' => Site::factory()->create(['is_active' => true])->id]);
        foreach (['emar.pdf.mar', 'emar.reports.export_mar'] as $route) {
            $this->actingAs($this->reader)
                ->get(route($route, ['client_id' => $elsewhere->id]))
                ->assertNotFound();
        }

        // Without the exports' permission there is no report.
        $noExport = $this->staff(['medications.view', 'clients.viewAny']);
        $this->actingAs($noExport)
            ->get(route('emar.reports.export_mar', ['client_id' => $this->aroha->id]))
            ->assertForbidden();

        $this->actingAs($this->reader)
            ->get(route('emar.pdf.mar', [
                'client_id' => $this->aroha->id,
                'date_from' => '2026-06-15',
                'date_to' => '2026-06-16',
                'include_prn' => '0',
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_the_mar_chart_reads_a_reoffered_dose_as_its_latest_record_and_keeps_the_refusal(): void
    {
        $order = $this->order('Metformin', prn: false);
        $due = Carbon::parse('2026-06-15 07:00', 'Pacific/Auckland')->utc();
        $refused = ClientMedicationAdministration::query()->create([
            'client_id' => $this->aroha->id, 'client_medication_id' => $order->id,
            'administered_by' => $this->reader->id, 'scheduled_for' => $due,
            'administered_at' => $due, 'status' => 'refused',
        ]);
        $given = ClientMedicationAdministration::query()->create([
            'client_id' => $this->aroha->id, 'client_medication_id' => $order->id,
            'administered_by' => $this->reader->id, 'scheduled_for' => $due,
            'administered_at' => $due->copy()->addMinutes(25), 'status' => 'given',
        ]);
        $given->forceFill(['reoffer_of_id' => $refused->id])->save();

        $this->actingAs($this->reader); // the chart names who generated it
        $html = view('pdf.mar-chart', [
            'client' => $this->aroha,
            'scheduledMedications' => ClientMedication::query()->whereKey($order->id)
                ->with(['administrations' => fn ($query) => $query->effectiveClinicalEvidence()])
                ->get(),
            'prnMedications' => collect(),
            'allergies' => collect(),
            'dates' => ['2026-06-15'],
            'dateFrom' => '2026-06-15',
            'dateTo' => '2026-06-15',
        ])->render();

        // The slot reads Given; the refusal stays, greyed, as history.
        $this->assertStringContainsString('<span class="status-given">G</span><span style="color: #999;">(R)</span>', $html);
    }

    /** @param  array<string, string>  $query */
    private function csv(array $query): string
    {
        return $this->actingAs($this->reader)
            ->get(route('emar.reports.export_mar', ['client_id' => $this->aroha->id, ...$query]))
            ->assertOk()
            ->streamedContent();
    }

    private function order(string $name, bool $prn): ClientMedication
    {
        return ClientMedication::query()->create([
            'client_id' => $this->aroha->id,
            'name' => $name,
            'dosage' => '1 tablet',
            'frequency' => $prn ? 'As needed' : 'Daily',
            'dose_times' => $prn ? [] : ['07:00'],
            'is_prn' => $prn,
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'start_date' => '2026-06-01',
        ]);
    }

    private function given(ClientMedication $order, string $atNz, string $note): void
    {
        $at = Carbon::parse($atNz, 'Pacific/Auckland')->utc();
        ClientMedicationAdministration::query()->create([
            'client_id' => $this->aroha->id,
            'client_medication_id' => $order->id,
            'administered_by' => $this->reader->id,
            'scheduled_for' => $order->is_prn ? null : $at,
            'administered_at' => $at,
            'status' => 'given',
            'notes' => $note,
        ]);
    }

    /** @param  list<string>  $permissions */
    private function staff(array $permissions): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', $permissions)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => '2026-01-01',
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user->fresh();
    }
}
