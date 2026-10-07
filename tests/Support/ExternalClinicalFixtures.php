<?php

namespace Tests\Support;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationExternalClinician;
use App\Models\MedicationExternalGrant;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

trait ExternalClinicalFixtures
{
    protected Site $site;

    protected Client $person;

    protected User $manager;

    protected User $clinician;

    protected MedicationExternalClinician $identity;

    protected MedicationExternalGrant $grant;

    protected function connectedFixtures(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 00:00:00', 'UTC'));
        Storage::fake('local');
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $context = ServiceContext::factory()->create(['type' => 'residential', 'is_active' => true]);
        $this->person = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $context->id, 'status' => 'active']);
        $this->manager = $this->connectedStaff();
        $this->clinician = User::factory()->create(['name' => 'Dr Verified', 'role' => 'external_clinician',
            'external_clinical_account' => true, 'approved_at' => now(), 'email_verified_at' => now(),
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->identity = MedicationExternalClinician::create(['user_id' => $this->clinician->id,
            'provider_name' => 'Named medical practice', 'registration_authority' => 'Clinical register',
            'registration_number' => 'REGISTER-123', 'identity_evidence' => 'Named identity checked against clinical register',
            'verified_by' => $this->manager->id, 'identity_verified_at' => now(), 'expires_at' => now()->addDays(100)]);
        $this->grant = MedicationExternalGrant::create(['clinician_id' => $this->identity->id, 'client_id' => $this->person->id,
            'site_id' => $this->site->id, 'purpose' => 'Current medication review', 'can_propose' => true, 'include_controlled' => false,
            'granted_by' => $this->manager->id, 'expires_at' => now()->addDays(30)]);
    }

    protected function connectedStaff(array $extra = []): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        foreach (array_merge(['medications.view', 'medications.orders.manage', 'medications.orders.verify',
            'medications.external.manage', 'medications.transfers.manage', 'medications.reports.export'], $extra) as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'medications']);
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);
        Shift::factory()->create(['client_id' => $this->person->id, 'site_id' => $this->site->id, 'user_id' => $user->id,
            'starts_at' => now()->subHours(2), 'ends_at' => now()->addHours(2), 'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null, 'started_by' => $user->id, 'status' => 'in_progress']);

        return $user;
    }

    protected function prescription(array $changes = []): array
    {
        return array_merge(['name' => 'Example medicine', 'dosage' => '10 mg', 'dose_amount' => 1, 'dose_unit' => 'tablet',
            'frequency' => 'Once daily', 'dose_times' => ['09:00'], 'is_prn' => false, 'route' => 'oral',
            'start_date' => '2026-10-07', 'indication' => 'Current clinical indication', 'controlled_drug' => false,
            'prescriber' => $this->clinician->name], $changes);
    }

    protected function chart(array $changes = []): ClientMedication
    {
        $order = ClientMedication::create(array_merge($this->prescription(), ['client_id' => $this->person->id,
            'created_by' => $this->manager->id, 'state' => 'active', 'active' => true, 'version' => 1], $changes));
        $order->forceFill(['approval_status' => 'verified', 'verified_by' => $this->manager->id, 'verified_at' => now()->subDay()])->saveQuietly();

        return $order;
    }

    protected function sourceInput(): array
    {
        return ['type' => 'written', 'prescriber' => $this->clinician->name, 'received_at' => now()->toIso8601String(), 'description' => 'Verified source prescription'];
    }
}
