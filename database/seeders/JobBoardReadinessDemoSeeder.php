<?php

namespace Database\Seeders;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\ShiftReplacementRequest;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class JobBoardReadinessDemoSeeder extends Seeder
{
    public function run(): void
    {
        $worker = User::query()->where('email', 'sw1@demo.test')->first();
        $currentStaff = User::query()->where('email', 'sw2@demo.test')->first();
        $admin = User::query()->where('role', 'admin')->first();
        $serviceContext = ServiceContext::query()->first();

        if (! $worker || ! $currentStaff || ! $admin || ! $serviceContext) {
            return;
        }

        $client = Client::query()
            ->whereHas('supportWorkers', fn ($query) => $query->where('users.id', $worker->id))
            ->first() ?? Client::query()->first();

        if (! $client) {
            return;
        }

        if (! $client->suburb) {
            $client->forceFill(['suburb' => 'Mount Eden'])->save();
        }

        foreach ([$worker, $currentStaff, $admin] as $staff) {
            $this->grantSiteAccess($staff, $client);
        }

        $this->seedOpenPosition($admin, $currentStaff, $client, $serviceContext);
        $this->seedPendingClaim($admin, $worker, $currentStaff, $client, $serviceContext);
    }

    private function seedOpenPosition(User $admin, User $currentStaff, Client $client, ServiceContext $serviceContext): void
    {
        $shift = $this->upsertShift(
            $admin,
            $currentStaff,
            $client,
            $serviceContext,
            'PW:job-board-open',
            Carbon::now()->addDays(3)->setTime(9, 0)->startOfMinute(),
        );

        $replacement = ShiftReplacementRequest::updateOrCreate(
            ['shift_id' => $shift->id],
            [
                'requested_by' => $admin->id,
                'current_staff_id' => $currentStaff->id,
                'replacement_user_id' => null,
                'status' => 'requested',
                'reason' => 'Playwright open job board cover',
                'notes' => 'Seeded for job board readiness tests.',
                'required_skills' => ['NZSL'],
                'requested_at' => Carbon::now()->subHours(2),
                'claimed_at' => null,
                'approved_by' => null,
                'approved_at' => null,
                'cancelled_by' => null,
                'cancelled_at' => null,
            ],
        );

        ShiftOpenPosition::updateOrCreate(
            ['replacement_request_id' => $replacement->id],
            [
                'shift_id' => $shift->id,
                'status' => 'open',
                'required_skills' => ['NZSL'],
                'coverage_roles' => [],
                'notes' => 'Playwright open job board cover',
                'claimed_by' => null,
                'claimed_at' => null,
                'approved_by' => null,
                'approved_at' => null,
                'expires_at' => Carbon::now()->addDays(2),
            ],
        );
    }

    private function seedPendingClaim(
        User $admin,
        User $worker,
        User $currentStaff,
        Client $client,
        ServiceContext $serviceContext,
    ): void {
        $claimedAt = Carbon::now()->subHour();
        $shift = $this->upsertShift(
            $admin,
            $currentStaff,
            $client,
            $serviceContext,
            'PW:job-board-claimed-by-sw1',
            Carbon::now()->addDays(4)->setTime(13, 0)->startOfMinute(),
        );

        $replacement = ShiftReplacementRequest::updateOrCreate(
            ['shift_id' => $shift->id],
            [
                'requested_by' => $admin->id,
                'current_staff_id' => $currentStaff->id,
                'replacement_user_id' => $worker->id,
                'status' => 'claimed',
                'reason' => 'Playwright claimed job board cover',
                'notes' => 'Seeded for job board readiness tests.',
                'required_skills' => ['NZSL'],
                'requested_at' => Carbon::now()->subHours(3),
                'claimed_at' => $claimedAt,
                'approved_by' => null,
                'approved_at' => null,
                'cancelled_by' => null,
                'cancelled_at' => null,
            ],
        );

        ShiftOpenPosition::updateOrCreate(
            ['replacement_request_id' => $replacement->id],
            [
                'shift_id' => $shift->id,
                'status' => 'claimed',
                'required_skills' => ['NZSL'],
                'coverage_roles' => [],
                'notes' => 'Playwright claimed job board cover',
                'claimed_by' => $worker->id,
                'claimed_at' => $claimedAt,
                'approved_by' => null,
                'approved_at' => null,
                'expires_at' => Carbon::now()->addDays(2),
            ],
        );
    }

    private function upsertShift(
        User $admin,
        User $currentStaff,
        Client $client,
        ServiceContext $serviceContext,
        string $notes,
        Carbon $startsAt,
    ): Shift {
        $shift = Shift::query()->where('notes', $notes)->first();
        $attributes = [
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $currentStaff->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(4),
            'location' => $client->city ?: 'Auckland',
            'status' => 'scheduled',
            'notes' => $notes,
            'created_by' => $admin->id,
        ];

        if ($shift) {
            $shift->forceFill($attributes)->save();
        } else {
            $shift = Shift::create($attributes);
        }

        return $shift->fresh() ?? $shift;
    }

    private function grantSiteAccess(User $worker, Client $client): void
    {
        if (! $client->site_id) {
            return;
        }

        $profile = HrEmployeeProfile::query()->where('user_id', $worker->id)->first();
        if (! $profile) {
            HrEmployeeProfile::create([
                'user_id' => $worker->id,
                'employee_number' => 'EMP-JOB-BOARD-'.$worker->id,
                'work_email' => $worker->email,
                'position_title' => 'Support Worker',
                'position_role' => 'support_worker',
                'employment_type' => 'full_time',
                'start_date' => Carbon::now()->subMonth()->toDateString(),
                'is_active' => true,
                'primary_site_id' => $client->site_id,
                'secondary_site_ids' => [],
            ]);

            return;
        }

        $secondary = is_array($profile->secondary_site_ids) ? $profile->secondary_site_ids : [];
        if ($profile->primary_site_id === $client->site_id || in_array($client->site_id, $secondary, true)) {
            $profile->update([
                'is_active' => true,
                'end_date' => null,
            ]);

            return;
        }

        $profile->update([
            'is_active' => true,
            'end_date' => null,
            'secondary_site_ids' => array_values(array_unique([...$secondary, $client->site_id])),
        ]);
    }
}
