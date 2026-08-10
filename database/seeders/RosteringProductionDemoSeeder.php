<?php

namespace Database\Seeders;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Client;
use App\Models\HsTrainingRequirement;
use App\Models\Role;
use App\Models\RosterPeriod;
use App\Models\RosterTemplate;
use App\Models\RosterTemplateShift;
use App\Models\Shift;
use App\Models\Site;
use App\Models\StaffAvailability;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RosteringProductionDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->publishExistingAssignedDemoShifts();

        $manager = User::query()->firstOrNew(['email' => 'admin@demo.test']);
        $manager->forceFill([
            'name' => 'Demo Admin',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'organization_id' => 1,
            'approved_at' => now(),
            'email_verified_at' => now(),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $adminRole = Role::query()->where('name', 'admin')->first();
        if ($adminRole) {
            $manager->roles()->sync([$adminRole->id]);
        }

        $site = $this->site(9001, 'Rostering E2E House');
        $frontlineSite = $this->site(9002, 'Rostering E2E Frontline House');
        $client = $this->client(9001, $site, 'Rostering', 'Publish');
        $frontlineClient = $this->client(9002, $frontlineSite, 'Rostering', 'Frontline');
        $worker = $this->worker('roster-e2e-worker@demo.test', 'Rostering E2E Worker');
        $candidate = $this->worker('roster-e2e-candidate@demo.test', 'Rostering E2E Candidate');
        $frontlineWorker = $this->worker('roster-e2e-frontline@demo.test', 'Rostering E2E Frontline');

        $this->publishFlowFixture($site, $client, $worker, $manager);
        $this->suggestionFixture($site, $client, $candidate, $manager);
        $this->frontlineVisibilityFixture($frontlineSite, $frontlineClient, $frontlineWorker, $manager);
        $this->templateConflictFixture($client, $worker, $manager);
    }

    private function publishExistingAssignedDemoShifts(): void
    {
        Shift::query()
            ->whereNull('published_at')
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->chunkById(100, function ($shifts): void {
                foreach ($shifts as $shift) {
                    $shift->forceFill([
                        'published_at' => $shift->created_at ?? now(),
                        'publish_dirty_at' => null,
                    ])->saveQuietly();
                }
            });
    }

    private function site(int $id, string $name): Site
    {
        $site = Site::query()->find($id) ?? new Site(['id' => $id]);
        $site->forceFill([
            'id' => $id,
            'name' => $name,
            'type' => 'house',
            'address_line_1' => "{$id} Demo Street",
            'suburb' => 'Te Aro',
            'city' => 'Wellington',
            'region' => 'Wellington',
            'postcode' => '6011',
            'phone' => '04 555 0100',
            'email' => strtolower(str_replace(' ', '-', $name)).'@demo.test',
            'is_active' => true,
        ])->save();

        return $site;
    }

    private function client(int $id, Site $site, string $firstName, string $lastName): Client
    {
        $client = Client::query()->find($id) ?? new Client(['id' => $id]);
        $client->forceFill([
            'id' => $id,
            'organization_id' => 1,
            'site_id' => $site->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'nhi_number' => 'E2E'.str_pad((string) $id, 4, '0', STR_PAD_LEFT),
            'date_of_birth' => '1988-01-01',
            'phone' => '021 555 0100',
            'email' => "client-{$id}@demo.test",
            'address_line_1' => "{$id} Demo Street",
            'city' => 'Wellington',
            'postcode' => '6011',
            'status' => 'active',
        ])->save();

        return $client;
    }

    private function worker(string $email, string $name): User
    {
        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'role' => 'support_worker',
                'organization_id' => 1,
                'approved_at' => now(),
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
            ],
        );
    }

    private function publishFlowFixture(Site $site, Client $client, User $worker, User $manager): void
    {
        $weekStart = Carbon::parse('2026-05-04', 'Pacific/Auckland')->startOfDay();
        $this->period($site, $weekStart, $manager);
        $this->ensureSuggestionCandidateEligible($worker, $site);

        $this->shift(9101, $site, $client, $weekStart->copy()->setTime(9, 0), $worker, false);
    }

    private function suggestionFixture(Site $site, Client $client, User $candidate, User $manager): void
    {
        $weekStart = Carbon::parse('2026-05-11', 'Pacific/Auckland')->startOfDay();
        $this->period($site, $weekStart, $manager);
        $this->ensureSuggestionCandidateEligible($candidate, $site);

        $this->shift(9201, $site, $client, $weekStart->copy()->setTime(10, 0), null, false, ['caregiver']);
        $this->shift(9202, $site, $client, $weekStart->copy()->addDay()->setTime(15, 0), $candidate, false);
    }

    private function ensureSuggestionCandidateEligible(User $candidate, Site $site): void
    {
        $this->ensureSiteProfile($candidate, $site);
        $this->ensureCompliantStatuses($candidate, $site);
        $this->ensureAvailability($candidate);

        // The suggestion fixture only needs the legacy users.role value.
        // Dropping RBAC pivots avoids unrelated live hard-stop checks seeded elsewhere.
        $candidate->roles()->detach();
    }

    private function ensureAvailability(User $candidate): void
    {
        foreach ([0, 1, 2] as $dayOfWeek) {
            StaffAvailability::query()->updateOrCreate(
                [
                    'user_id' => $candidate->id,
                    'day_of_week' => $dayOfWeek,
                ],
                [
                    'starts_at' => '00:00:00',
                    'ends_at' => '23:59:59',
                ],
            );
        }
    }

    private function ensureSiteProfile(User $candidate, Site $site): void
    {
        HrEmployeeProfile::query()->updateOrCreate(
            ['user_id' => $candidate->id],
            [
                'tenant_id' => 1,
                'employee_number' => 'ROSTER-E2E-'.$candidate->id,
                'work_email' => $candidate->email,
                'position_title' => 'Support Worker',
                'position_role' => 'support_worker',
                'employment_type' => 'full_time',
                'contract_type' => 'permanent',
                'pay_frequency' => 'fortnightly',
                'start_date' => now()->subYear()->toDateString(),
                'is_active' => true,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
            ],
        );
    }

    private function ensureCompliantStatuses(User $candidate, Site $site): void
    {
        $hsRequirementIds = HsTrainingRequirement::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (HsTrainingRequirement $requirement) => $requirement->appliesTo($candidate->role, $site->id, null))
            ->pluck('hr_compliance_requirement_id')
            ->filter()
            ->values();

        $requirements = HrComplianceRequirement::query()
            ->where('is_active', true)
            ->where(function ($query) use ($candidate, $hsRequirementIds): void {
                $query->whereHas('matrixEntries', fn ($matrixQuery) => $matrixQuery->where('role', $candidate->role))
                    ->orWhereIn('id', $hsRequirementIds);
            })
            ->get();

        foreach ($requirements as $requirement) {
            HrStaffComplianceStatus::query()->updateOrCreate(
                [
                    'tenant_id' => $requirement->tenant_id,
                    'user_id' => $candidate->id,
                    'requirement_id' => $requirement->id,
                ],
                [
                    'status' => 'compliant',
                    'valid_from' => now()->subYear()->toDateString(),
                    'expires_at' => now()->addYear()->toDateString(),
                    'last_checked_at' => now(),
                    'next_check_at' => now()->addMonth(),
                ],
            );
        }
    }

    private function frontlineVisibilityFixture(Site $site, Client $client, User $worker, User $manager): void
    {
        $weekStart = Carbon::now('Pacific/Auckland')
            ->startOfWeek()
            ->addWeek()
            ->startOfDay();
        $this->period($site, $weekStart, $manager);
        $this->ensureSuggestionCandidateEligible($worker, $site);

        $this->shift(9301, $site, $client, $weekStart->copy()->addDays(2)->setTime(9, 0), $worker, false);
    }

    private function templateConflictFixture(Client $client, User $worker, User $manager): void
    {
        $template = RosterTemplate::query()->find(9001) ?? new RosterTemplate(['id' => 9001]);
        $template->forceFill([
            'id' => 9001,
            'organization_id' => 1,
            'name' => 'E2E Conflict Template',
            'description' => 'Deterministic conflict fixture for Playwright.',
            'template_type' => 'weekly',
            'is_active' => true,
            'created_by' => $manager->id,
        ])->save();

        RosterTemplateShift::query()
            ->where('roster_template_id', $template->id)
            ->delete();

        foreach ([1, 2] as $index) {
            RosterTemplateShift::query()->create([
                'organization_id' => 1,
                'roster_template_id' => $template->id,
                'client_id' => $client->id,
                'user_id' => $worker->id,
                'service_context_id' => null,
                'day_of_week' => 0,
                'start_time' => $index === 1 ? '09:00' : '10:00',
                'end_time' => $index === 1 ? '12:00' : '13:00',
                'shift_type' => 'standard',
                'is_sleepover' => false,
                'is_on_call' => false,
                'expected_break_minutes' => null,
                'required_skills' => [],
                'location' => 'Rostering E2E House',
                'notes' => 'E2E conflict row',
            ]);
        }
    }

    private function period(Site $site, Carbon $weekStart, User $manager): RosterPeriod
    {
        return RosterPeriod::query()->updateOrCreate(
            [
                'organization_id' => 1,
                'site_id' => $site->id,
                'week_start' => $weekStart->toDateString(),
                'version' => 1,
            ],
            [
                'week_end' => $weekStart->copy()->addDays(7)->toDateString(),
                'status' => RosterPeriod::STATUS_DRAFT,
                'created_by' => $manager->id,
                'shift_count' => 0,
            ],
        );
    }

    private function shift(
        int $id,
        Site $site,
        Client $client,
        Carbon $startsAt,
        ?User $worker,
        bool $published,
        array $coverageRoles = [],
    ): Shift {
        $shift = Shift::query()->find($id) ?? new Shift(['id' => $id]);
        $shift->forceFill([
            'id' => $id,
            'organization_id' => 1,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => null,
            'user_id' => $worker?->id,
            'starts_at' => $startsAt->copy()->utc(),
            'ends_at' => $startsAt->copy()->addHours(3)->utc(),
            'location' => $site->name,
            'notes' => 'Rostering E2E fixture',
            'status' => 'scheduled',
            'coverage_roles' => $coverageRoles,
            'created_by' => null,
            'published_at' => $published ? $startsAt->copy()->subDay()->utc() : null,
            'publish_dirty_at' => null,
        ])->saveQuietly();

        return $shift;
    }
}
