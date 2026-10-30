<?php

namespace Database\Seeders;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use DomainException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class MyDayPreShiftBriefingE2ESeeder extends Seeder
{
    public const MARKER = 'MY-DAY-PRE-SHIFT-E2E';

    public const WORKER_EMAIL = 'my-day-briefing-e2e@demo.test';

    public const SITE_NAME = 'Playwright Pre-Shift Briefing House';

    public const NOTES = '[MY-DAY-PRE-SHIFT-E2E] Read the fluids chart before breakfast and confirm the planned family visit.';

    public function run(): void
    {
        $this->seedFixture();
    }

    /** @return array{workerEmail: string, workerId: int, personId: int, personName: string, siteId: int, siteName: string, shiftId: int, notes: string} */
    public function seedFixture(): array
    {
        return DB::transaction(function (): array {
            $role = Role::query()->where('name', 'support_worker')->firstOrFail();
            $site = Site::query()->firstOrCreate(['name' => self::SITE_NAME], [
                'notes' => self::MARKER,
                'type' => 'house',
                'city' => 'Auckland',
                'country' => 'New Zealand',
                'is_active' => true,
                'archived' => false,
            ]);
            if ($site->notes !== self::MARKER || ! $site->is_active || $site->archived || $site->archived_at) {
                throw new DomainException('The exact active synthetic briefing Site is required.');
            }

            $worker = User::query()->firstOrCreate(['email' => self::WORKER_EMAIL], [
                'name' => 'Playwright Briefing Worker',
                'password' => Hash::make('password'),
                'role' => 'support_worker',
                'approved_at' => now(),
                'email_verified_at' => now(),
            ]);
            if ($worker->role !== 'support_worker'
                || $worker->name !== 'Playwright Briefing Worker'
                || $worker->permissionOverrides()->exists()
                || $worker->roles()->where('roles.id', '!=', $role->id)->exists()) {
                throw new DomainException('The synthetic briefing worker must retain only the standard support worker role.');
            }
            $worker->roles()->syncWithoutDetaching([$role->id]);
            HrEmployeeProfile::query()->updateOrCreate([
                'user_id' => $worker->id,
                'employee_number' => self::MARKER,
            ], [
                'work_email' => $worker->email,
                'position_title' => 'Support Worker',
                'position_role' => 'support_worker',
                'employment_type' => 'full_time',
                'start_date' => today()->subYear()->toDateString(),
                'end_date' => null,
                'is_active' => true,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
            ]);

            $context = ServiceContext::query()->firstOrCreate([
                'name' => self::MARKER,
                'site_id' => $site->id,
            ], ['type' => 'residential', 'is_active' => true]);
            $person = Client::query()->firstOrCreate(['funding_notes' => self::MARKER], [
                'first_name' => 'Playwright',
                'last_name' => 'Briefing',
                'preferred_name' => 'PW Briefing',
                'date_of_birth' => '1986-04-28',
                'gender' => 'not_stated',
                'site_id' => $site->id,
                'service_context_id' => $context->id,
                'status' => 'active',
                'city' => 'Auckland',
                'funding_type' => 'demo',
            ]);
            if ((int) $person->site_id !== (int) $site->id
                || (int) $person->service_context_id !== (int) $context->id
                || $person->status !== 'active') {
                throw new DomainException('The marked briefing person must retain the canonical fixture Site and context.');
            }
            $person->supportWorkers()->syncWithoutDetaching([$worker->id]);

            $shift = Shift::query()->firstOrNew(['notes' => self::NOTES]);
            if (($shift->exists && (
                (int) $shift->user_id !== (int) $worker->id
                || (int) $shift->client_id !== (int) $person->id
                || (int) $shift->site_id !== (int) $site->id
                || $shift->actual_starts_at || $shift->actual_ends_at
                || $shift->attendanceSessions()->exists() || $shift->timesheets()->exists()
            )) || HrAttendanceSession::query()->where('user_id', $worker->id)->open()->exists()
                || Shift::query()->where('user_id', $worker->id)
                    ->where(fn ($query) => $query->whereNull('notes')->orWhere('notes', '!=', self::NOTES))
                    ->where('ends_at', '>=', now())->whereIn('status', ['scheduled', 'draft', 'in_progress'])->exists()) {
                throw new DomainException('The briefing fixture cannot replace attendance, payroll, or unrelated live shift evidence.');
            }

            $start = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))
                ->addDay()->setTime(7, 30)->utc();
            $shift->fill([
                'user_id' => $worker->id,
                'client_id' => $person->id,
                'site_id' => $site->id,
                'service_context_id' => $context->id,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addHours(4),
                'status' => 'scheduled',
                'location' => self::SITE_NAME,
                'created_by' => $worker->id,
            ])->save();
            // This named scenario starts with an already rostered shift.
            // Its stamp also keeps the fixture visible with publication enabled.
            $shift->forceFill(['published_at' => now(), 'publish_dirty_at' => null])->save();

            return [
                'workerEmail' => $worker->email,
                'workerId' => (int) $worker->id,
                'personId' => (int) $person->id,
                'personName' => trim($person->first_name.' '.$person->last_name),
                'siteId' => (int) $site->id,
                'siteName' => $site->name,
                'shiftId' => (int) $shift->id,
                'notes' => self::NOTES,
            ];
        });
    }
}
