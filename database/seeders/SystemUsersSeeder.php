<?php

namespace Database\Seeders;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserWitnessPin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SystemUsersSeeder extends Seeder
{
    private const DEFAULT_TENANT_ID = 1;

    /** Demo witness PIN for the seeded demo staff (local and fresh demo installs only). */
    public const DEMO_WITNESS_PIN = '482915';

    public function run(): void
    {
        $password = Hash::make('password');

        $users = [
            [
                'email' => 'admin@demo.test',
                'name' => 'Demo Admin',
                'role' => 'admin',
                'staff_data' => ['job_title' => 'System Administrator', 'department' => 'IT'],
            ],
            [
                'email' => 'manager@demo.test',
                'name' => 'Demo Manager',
                'role' => 'provider_manager',
                'staff_data' => ['job_title' => 'Provider Manager', 'department' => 'Operations'],
            ],
            [
                'email' => 'coord@demo.test',
                'name' => 'Demo Coordinator',
                'role' => 'coordinator',
                'staff_data' => ['job_title' => 'Care Coordinator', 'department' => 'Clinical'],
            ],
            [
                'email' => 'finance@demo.test',
                'name' => 'Demo Finance',
                'role' => 'finance',
                'staff_data' => ['job_title' => 'Finance Officer', 'department' => 'Finance'],
            ],
            [
                'email' => 'hr@demo.test',
                'name' => 'Demo HR',
                'role' => 'hr',
                'staff_data' => ['job_title' => 'HR Manager', 'department' => 'HR'],
            ],
            [
                'email' => 'auditor@demo.test',
                'name' => 'Demo Auditor',
                'role' => 'auditor',
                'staff_data' => ['job_title' => 'Internal Auditor', 'department' => 'Compliance'],
            ],
            // Independent decisions (RbacSeeder::RESTRICTED_INDEPENDENT_AUTHORITY)
            // are withheld from admin, so these roles demo them instead.
            [
                'email' => 'safety@demo.test',
                'name' => 'Demo H&S Officer',
                'role' => 'health_safety_officer',
                'staff_data' => ['job_title' => 'Health & Safety Officer', 'department' => 'Health & Safety'],
            ],
            [
                'email' => 'compliance@demo.test',
                'name' => 'Demo Compliance Lead',
                'role' => 'compliance_lead',
                'staff_data' => ['job_title' => 'Compliance Lead', 'department' => 'Compliance'],
                // Safeguarding declassification still checks canonical Site
                // access, so the demo Compliance Lead covers every Site.
                'all_sites' => true,
            ],
        ];

        foreach ($users as $u) {
            $user = User::query()->firstOrNew(['email' => $u['email']]);
            $user->forceFill([
                'name' => $u['name'],
                'password' => $password,
                'role' => $u['role'],
                'approved_at' => now(),
                'email_verified_at' => now(),
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            $role = Role::query()->where('name', $u['role'])->first();
            if ($role) {
                $user->roles()->sync([$role->id]);
            }

            // Create staff record for staff users
            if (!empty($u['staff_data'])) {
                $staff = Staff::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'employee_id' => strtoupper(substr($u['role'], 0, 3)) . str_pad($user->id, 3, '0', STR_PAD_LEFT),
                        'job_title' => $u['staff_data']['job_title'],
                        'department' => $u['staff_data']['department'],
                        'status' => 'active',
                        'hire_date' => now()->subYears(rand(1, 5)),
                    ]
                );

                $this->upsertHrEmployeeProfile($user, $staff);
            }

            if (! empty($u['all_sites'])) {
                $this->assignEveryActiveSite($user);
            }
        }

        // Support workers (primary test actors)
        $supportRole = Role::query()->where('name', 'support_worker')->first();

        for ($i = 1; $i <= 8; $i++) {
            $w = User::query()->firstOrNew(['email' => "sw{$i}@demo.test"]);
            $w->forceFill([
                'name' => "Support Worker {$i}",
                'password' => $password,
                'role' => 'support_worker',
                'approved_at' => now(),
                'email_verified_at' => now(),
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            if ($supportRole) {
                $w->roles()->sync([$supportRole->id]);
            }

            // Create staff record for support worker
            $staff = Staff::updateOrCreate(
                ['user_id' => $w->id],
                [
                    'employee_id' => 'SW' . str_pad($i, 3, '0', STR_PAD_LEFT),
                    'job_title' => 'Support Worker',
                    'department' => 'Clinical',
                    'status' => 'active',
                    'hire_date' => now()->subMonths(rand(1, 24)),
                ]
            );

            $this->upsertHrEmployeeProfile($w, $staff);
        }

        // P11 B2 (b): a house lead for each demo house and a clinical lead, so
        // demo medication alerts reach the people they're routed to (house
        // lead, clinical lead) rather than only the safety net.
        $leads = $this->seedHouseLeadsAndClinicalLead($password);

        // Ensure every staff user has an HR profile, even if the legacy staff
        // record is missing or incomplete.
        User::staff()->with('staffProfile')->get()->each(function (User $staffUser): void {
            /** @var Staff|null $staff */
            $staff = $staffUser->staffProfile;
            $this->upsertHrEmployeeProfile($staffUser, $staff);
        });

        // PIN-1: demo staff can witness and co-sign straight away with the
        // documented demo witness PIN. A PIN someone already chose is kept.
        // Deploys skip seeders: on an existing site each person sets their own
        // PIN in Settings › Witness PIN.
        if (Schema::hasTable('user_witness_pins')) {
            $demoPinHash = Hash::make(self::DEMO_WITNESS_PIN);
            User::query()
                ->where('email', 'like', '%@demo.test')
                ->whereNotIn('role', ['client', 'next_of_kin'])
                ->get(['id'])
                ->each(fn (User $demoUser) => UserWitnessPin::query()->firstOrCreate(
                    ['user_id' => $demoUser->id],
                    ['pin_hash' => $demoPinHash, 'set_at' => now()],
                ));
        }

        // Create a board member
        $boardUser = User::query()->firstOrNew(['email' => 'board@demo.test']);
        $boardUser->forceFill([
            'name' => 'Demo Board Member',
            'password' => $password,
            'role' => 'board_member',
            'approved_at' => now(),
            'email_verified_at' => now(),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $boardRole = Role::query()->where('name', 'board_member')->first();
        if ($boardRole) {
            $boardUser->roles()->sync([$boardRole->id]);
        }

        $this->command?->info('Created ' . (count($users) + 8 + $leads + 1) . ' users with staff records.');
    }

    /**
     * One team_lead per demo house (their HR profile's primary site) and one
     * clinical_lead covering every house. Head office gets no house lead.
     *
     * @return int The users created or updated.
     */
    private function seedHouseLeadsAndClinicalLead(string $password): int
    {
        $people = [];
        $houses = Site::query()->active()->notArchived()
            ->where(fn ($sites) => $sites->where('type', '!=', 'head_office')->orWhereNull('type'))
            ->orderBy('id')
            ->get(['id', 'name']);
        foreach ($houses as $house) {
            $people[] = [
                'email' => 'lead.'.Str::slug((string) $house->name).'@demo.test',
                'name' => 'Demo Team Lead ('.$house->name.')',
                'role' => 'team_lead',
                'job_title' => 'Team Lead',
                'site_id' => (int) $house->id,
                'all_sites' => false,
            ];
        }
        $people[] = [
            'email' => 'clinical@demo.test',
            'name' => 'Demo Clinical Lead',
            'role' => 'clinical_lead',
            'job_title' => 'Clinical Lead',
            'site_id' => null,
            'all_sites' => true,
        ];

        foreach ($people as $person) {
            $user = User::query()->firstOrNew(['email' => $person['email']]);
            $user->forceFill([
                'name' => $person['name'],
                'password' => $password,
                'role' => $person['role'],
                'approved_at' => now(),
                'email_verified_at' => now(),
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();
            $role = Role::query()->where('name', $person['role'])->first();
            if ($role) {
                $user->roles()->sync([$role->id]);
            }
            $staff = Staff::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'employee_id' => strtoupper(substr($person['role'], 0, 3)) . str_pad($user->id, 3, '0', STR_PAD_LEFT),
                    'job_title' => $person['job_title'],
                    'department' => 'Clinical',
                    'status' => 'active',
                    'hire_date' => now()->subYears(2),
                ]
            );
            $this->upsertHrEmployeeProfile($user, $staff);
            if ($person['all_sites']) {
                $this->assignEveryActiveSite($user);
            } else {
                HrEmployeeProfile::query()->where('user_id', $user->id)->first()?->update([
                    'primary_site_id' => $person['site_id'],
                ]);
            }
        }

        return count($people);
    }

    private function upsertHrEmployeeProfile(User $user, ?Staff $staff): void
    {
        $employeeNumber = $this->employeeNumberFor($user, $staff);
        $positionTitle = trim((string) (($staff?->job_title) ?: $this->defaultJobTitleForRole($user->role)));
        $positionRole = trim((string) ($user->role ?: 'support_worker'));
        $startDate = $staff?->hire_date ? $staff->hire_date->toDateString() : now()->subMonths(6)->toDateString();

        HrEmployeeProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'tenant_id' => self::DEFAULT_TENANT_ID,
                'employee_number' => $employeeNumber,
                'work_email' => $user->email,
                'position_title' => $positionTitle,
                'position_role' => $positionRole,
                'employment_type' => 'full_time',
                'contract_type' => 'permanent',
                'start_date' => $startDate,
                'is_active' => ($staff?->status ?? 'active') !== 'terminated',
                'updated_by' => $user->id,
                'created_by' => $user->id,
            ]
        );
    }

    private function assignEveryActiveSite(User $user): void
    {
        $siteIds = Site::query()->active()->notArchived()->orderBy('id')->pluck('id')->all();
        if ($siteIds === []) {
            return;
        }

        HrEmployeeProfile::query()->where('user_id', $user->id)->first()?->update([
            'primary_site_id' => $siteIds[0],
            'secondary_site_ids' => array_slice($siteIds, 1),
        ]);
    }

    private function employeeNumberFor(User $user, ?Staff $staff): string
    {
        if ($staff?->employee_id) {
            return trim((string) $staff->employee_id);
        }

        $generated = 'EMP'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT);
        $isOwnedByAnotherProfile = HrEmployeeProfile::query()
            ->withTrashed()
            ->where('employee_number', $generated)
            ->where('user_id', '!=', $user->id)
            ->exists();

        return $isOwnedByAnotherProfile
            ? 'EMP-U'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT)
            : $generated;
    }

    private function defaultJobTitleForRole(?string $role): string
    {
        return match ($role) {
            'admin' => 'System Administrator',
            'provider_manager' => 'Provider Manager',
            'coordinator' => 'Care Coordinator',
            'finance' => 'Finance Officer',
            'hr' => 'HR Manager',
            'auditor' => 'Internal Auditor',
            'health_safety_officer' => 'Health & Safety Officer',
            'compliance_lead' => 'Compliance Lead',
            'team_lead' => 'Team Lead',
            'clinical_lead' => 'Clinical Lead',
            default => 'Support Worker',
        };
    }
}
