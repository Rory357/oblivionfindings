<?php

namespace Database\Seeders;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\Shift;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\MedicationError;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

final class IncidentHandoverE2ESeeder extends Seeder
{
    public const MARKER = 'INCIDENT-HANDOVER-E2E-2026-07-16';

    public const SITE_ID = 9401;

    public const CLIENT_ID = 9401;

    public const OPERATOR_EMAIL = 'incident-e2e-operator@demo.test';

    public const INCOMING_EMAIL = 'incident-e2e-incoming@demo.test';

    public const SHIFT_NAME = '[INCIDENT-HANDOVER-E2E] Fresh Control Room shift';

    public const REQUIRED_ALERT_REFERENCES = [
        'CR-E2E-HANDOVER-01',
        'CR-E2E-HANDOVER-02',
    ];

    public function run(): void
    {
        $site = $this->site();
        $client = $this->client($site);

        $operator = $this->staff(self::OPERATOR_EMAIL, 'Playwright Control Room Operator', 'coordinator', $site);
        $incoming = $this->staff(self::INCOMING_EMAIL, 'Playwright Incoming Control Room Operator', 'coordinator', $site);
        $worker = $this->staff('incident-e2e-worker@demo.test', 'Playwright Support Worker', 'support_worker', $site);
        $reviewer = $this->staff('incident-e2e-reviewer@demo.test', 'Playwright Incident Reviewer', 'provider_manager', $site);
        $owner = $this->staff('incident-e2e-owner@demo.test', 'Playwright H&S Owner', 'coordinator', $site);
        $verifier = $this->staff('incident-e2e-verifier@demo.test', 'Playwright H&S Verifier', 'coordinator', $site);

        $this->grant($operator, [
            'controlRoom.viewAny',
            'controlRoom.alerts.view',
            'controlRoom.alerts.create',
            'controlRoom.alerts.manage',
            'controlRoom.alerts.assign',
            'controlRoom.alerts.escalate',
            'incidents.create',
            'incidents.submit',
            'incidents.viewAny',
            'incidents.update',
            'incidents.approve',
            'incidents.followups.manage',
            'hazards.view',
            'hazards.manage',
            'medications.view',
            'medications.administer.record',
        ]);
        $this->deny($operator, ['reports.viewAny']);
        $this->grant($incoming, [
            'controlRoom.viewAny',
            'controlRoom.alerts.view',
            'controlRoom.alerts.manage',
            'controlRoom.alerts.assign',
            'controlRoom.alerts.escalate',
        ]);
        $this->deny($incoming, ['reports.viewAny']);
        $this->grant($worker, [
            'clients.viewAssigned',
            'incidents.create',
            'incidents.submit',
            'incidents.viewAssigned',
        ]);
        $this->grant($reviewer, [
            'incidents.viewAny',
            'incidents.update',
            'incidents.approve',
            'incidents.followups.manage',
            'hazards.view',
            'hazards.manage',
        ]);
        $this->grant($owner, ['hazards.view', 'hazards.manage', 'healthSafety.viewAllSites']);
        $this->grant($verifier, ['hazards.view', 'hazards.manage', 'healthSafety.viewAllSites']);

        $this->assertNoUnrelatedActiveShift();
        $client->supportWorkers()->syncWithoutDetaching([$worker->id]);
        $this->clearPriorJourneys($client);
        $this->retirePriorFixtureShifts();
        $shift = $this->freshShift($operator, $incoming);
        $requiredAlerts = $this->requiredAlerts($site, $client, $operator);

        $manifest = [
            'marker' => self::MARKER,
            'site' => ['id' => $site->id, 'name' => $site->name],
            'client' => ['id' => $client->id, 'name' => trim($client->first_name.' '.$client->last_name)],
            'shift' => [
                'id' => $shift->id,
                'name' => $shift->name,
                'starts_at' => $shift->starts_at->toIso8601String(),
                'required_alert_count' => $requiredAlerts->count(),
            ],
            'records' => [
                'required_alert_ids' => $requiredAlerts->pluck('id')->all(),
                'required_alert_references' => $requiredAlerts->pluck('reference_number')->all(),
            ],
            'users' => [
                'operator' => $this->userManifest($operator),
                'incoming' => $this->userManifest($incoming),
                'worker' => $this->userManifest($worker),
                'reviewer' => $this->userManifest($reviewer),
                'owner' => $this->userManifest($owner),
                'verifier' => $this->userManifest($verifier),
            ],
        ];

        $this->command?->line('INCIDENT_HANDOVER_MANIFEST='.json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    private function site(): Site
    {
        $site = Site::query()->find(self::SITE_ID) ?? new Site(['id' => self::SITE_ID]);
        $site->forceFill([
            'id' => self::SITE_ID,
            'tenant_id' => 1,
            'name' => 'Playwright Incident Handover House',
            'type' => 'house',
            'address_line_1' => '9401 Handover Lane',
            'suburb' => 'Te Aro',
            'city' => 'Wellington',
            'region' => 'Wellington',
            'postcode' => '6011',
            'phone' => '04 555 9401',
            'email' => 'incident-handover-house@demo.test',
            'is_active' => true,
        ])->save();

        return $site;
    }

    private function client(Site $site): Client
    {
        $client = Client::query()->find(self::CLIENT_ID) ?? new Client(['id' => self::CLIENT_ID]);
        $client->forceFill([
            'id' => self::CLIENT_ID,
            'organization_id' => 1,
            'site_id' => $site->id,
            'first_name' => 'Playwright',
            'last_name' => 'Aroha Handover',
            'preferred_name' => 'Aroha',
            'nhi_number' => 'E2EH9401',
            'date_of_birth' => '1988-01-01',
            'phone' => '021 555 9401',
            'email' => 'aroha-handover@demo.test',
            'address_line_1' => '9401 Handover Lane',
            'city' => 'Wellington',
            'postcode' => '6011',
            'status' => 'active',
        ])->save();

        return $client;
    }

    private function staff(string $email, string $name, string $roleName, Site $site): User
    {
        $user = User::query()->updateOrCreate(['email' => $email], [
            'name' => $name,
            'password' => Hash::make('password'),
            'role' => $roleName,
            'organization_id' => 1,
            'approved_at' => now(),
            'email_verified_at' => now(),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);

        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->sync([$role->id]);
        }

        HrEmployeeProfile::withTrashed()->updateOrCreate(['user_id' => $user->id], [
            'tenant_id' => 1,
            'employee_number' => 'PW-IH-'.$user->id,
            'work_email' => $user->email,
            'position_title' => $name,
            'position_role' => $roleName,
            'employment_type' => 'full_time',
            'contract_type' => 'permanent',
            'start_date' => now()->subYear()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'deleted_at' => null,
        ]);

        return $user;
    }

    /** @param list<string> $permissionKeys */
    private function grant(User $user, array $permissionKeys): void
    {
        $permissions = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
        $user->permissionOverrides()->syncWithoutDetaching(
            $permissions->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])->all(),
        );
    }

    /** @param list<string> $permissionKeys */
    private function deny(User $user, array $permissionKeys): void
    {
        $permissions = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
        $user->permissionOverrides()->syncWithoutDetaching(
            $permissions->mapWithKeys(fn (int $id) => [$id => ['allowed' => false]])->all(),
        );
    }

    private function retirePriorFixtureShifts(): void
    {
        Shift::query()
            ->where('name', self::SHIFT_NAME)
            ->where('status', 'active')
            ->get()
            ->each(fn (Shift $shift) => $shift->forceFill([
                'status' => 'completed',
                'ends_at' => now(),
            ])->save());
    }

    private function assertNoUnrelatedActiveShift(): void
    {
        $blockingIds = Shift::query()
            ->active()
            ->where(fn ($query) => $query
                ->whereNull('name')
                ->orWhere('name', '!=', self::SHIFT_NAME))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($blockingIds !== []) {
            throw new \RuntimeException(
                'Refusing to replace an unrelated active Control Room shift. '
                .'Complete it through its real handover first. Blocking shift IDs: '
                .implode(', ', $blockingIds),
            );
        }
    }

    private function freshShift(User $operator, User $incoming): Shift
    {
        return Shift::query()->create([
            'name' => self::SHIFT_NAME,
            'starts_at' => now()->subMinute(),
            'status' => 'active',
            'shift_lead_user_id' => $operator->id,
            'team_members' => [$operator->id, $incoming->id],
            'open_alerts_at_start' => 0,
        ]);
    }

    /** @return Collection<int, ControlRoomAlert> */
    private function requiredAlerts(
        Site $site,
        Client $client,
        User $operator,
    ): Collection {
        return collect([
            [
                'reference_number' => self::REQUIRED_ALERT_REFERENCES[0],
                'alert_type' => 'E2E welfare escalation',
                'severity' => 'high',
                'notes' => 'Deterministic serious alert for the seven-persona closure relay.',
            ],
            [
                'reference_number' => self::REQUIRED_ALERT_REFERENCES[1],
                'alert_type' => 'E2E follow-up decision',
                'severity' => 'medium',
                'notes' => 'Deterministic decision-relevant handover alert.',
            ],
        ])->map(fn (array $attributes): ControlRoomAlert => ControlRoomAlert::query()->create([
            ...$attributes,
            'source' => 'manual',
            'status' => ControlRoomAlert::STATUS_OPEN,
            'site_id' => $site->id,
            'client_id' => $client->id,
            'triggered_at' => now(),
            'created_by_user_id' => $operator->id,
            'context' => [
                'fixture_marker' => self::MARKER,
                'site_id' => $site->id,
            ],
        ]));
    }

    private function clearPriorJourneys(Client $client): void
    {
        MedicationError::withTrashed()->where('client_id', $client->id)->forceDelete();
        ControlRoomAlert::query()->where('client_id', $client->id)->delete();
        HsEvent::withTrashed()->where('client_id', $client->id)->get()->each->forceDelete();
        ClientIncident::query()->where('client_id', $client->id)->delete();
    }

    /** @return array{id: int, email: string, name: string} */
    private function userManifest(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
        ];
    }
}
