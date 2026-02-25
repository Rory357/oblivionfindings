<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    /**
     * @throws \LogicException
     */
    public function clockIn(User $user, array $data = []): HrAttendanceSession
    {
        $openSession = HrAttendanceSession::query()
            ->where('user_id', $user->id)
            ->open()
            ->first();

        if ($openSession) {
            throw new \LogicException('You are already clocked in. Please clock out before starting another session.');
        }

        $clockInAt = isset($data['clock_in_at']) ? Carbon::parse($data['clock_in_at']) : now();
        $shift = $this->resolveShift($user, $data, $clockInAt);
        $siteId = $data['site_id'] ?? $shift?->client?->site_id;

        return DB::transaction(function () use ($user, $data, $clockInAt, $shift, $siteId) {
            $session = HrAttendanceSession::create([
                'tenant_id' => $user->tenant_id ?? null,
                'user_id' => $user->id,
                'shift_id' => $shift?->id,
                'site_id' => $siteId,
                'clock_in_at' => $clockInAt,
                'status' => 'open',
                'source' => $data['source'] ?? 'manual',
                'location' => $data['location'] ?? null,
                'notes' => $data['notes'] ?? null,
                'meta' => $data['meta'] ?? null,
                'created_by' => $data['created_by'] ?? $user->id,
            ]);

            if ($shift && in_array($shift->status, ['draft', 'scheduled'], true)) {
                $shift->update([
                    'status' => 'in_progress',
                    'actual_starts_at' => $shift->actual_starts_at ?? $clockInAt,
                    'started_by' => $shift->started_by ?? $user->id,
                ]);
            }

            return $session->fresh(['shift.client', 'timesheet']);
        });
    }

    /**
     * @throws \LogicException
     */
    public function clockOut(User $user, ?HrAttendanceSession $session = null, array $data = []): HrAttendanceSession
    {
        $session = $session ?: HrAttendanceSession::query()
            ->where('user_id', $user->id)
            ->open()
            ->latest('clock_in_at')
            ->first();

        if (! $session) {
            throw new \LogicException('No open attendance session found to clock out.');
        }

        if ((int) $session->user_id !== (int) $user->id) {
            throw new \LogicException('You can only clock out your own attendance session.');
        }

        $clockOutAt = isset($data['clock_out_at']) ? Carbon::parse($data['clock_out_at']) : now();
        if ($clockOutAt->lessThanOrEqualTo($session->clock_in_at)) {
            throw new \LogicException('Clock-out time must be after clock-in time.');
        }

        return DB::transaction(function () use ($session, $user, $clockOutAt, $data) {
            $session->update([
                'clock_out_at' => $clockOutAt,
                'break_minutes' => (int) ($data['break_minutes'] ?? $session->break_minutes ?? 0),
                'status' => 'closed',
                'notes' => $data['notes'] ?? $session->notes,
                'closed_by' => $user->id,
            ]);

            $this->syncTimesheetFromSession($session->fresh(['shift']), $user->id, $data);

            return $session->fresh(['shift.client', 'timesheet']);
        });
    }

    protected function resolveShift(User $user, array $data, Carbon $clockInAt): ?Shift
    {
        if (! empty($data['shift_id'])) {
            $shift = Shift::query()->with('client:id,site_id')->findOrFail($data['shift_id']);
            if ((int) $shift->user_id !== (int) $user->id) {
                throw new \LogicException('You cannot clock into a shift assigned to another staff member.');
            }

            return $shift;
        }

        return Shift::query()
            ->with('client:id,site_id')
            ->where('user_id', $user->id)
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->where('starts_at', '<=', $clockInAt->copy()->addHours(12))
            ->where('ends_at', '>=', $clockInAt->copy()->subHours(12))
            ->orderBy('starts_at')
            ->first();
    }

    protected function syncTimesheetFromSession(HrAttendanceSession $session, int $actorId, array $data = []): ?Timesheet
    {
        if (! $session->clock_out_at) {
            return null;
        }

        $timesheet = Timesheet::query()
            ->where('attendance_session_id', $session->id)
            ->first();

        $clientId = $session->shift?->client_id ?? ($data['client_id'] ?? null);
        if (! $clientId) {
            return null;
        }

        $payload = [
            'user_id' => $session->user_id,
            'client_id' => $clientId,
            'shift_id' => $session->shift_id,
            'work_date' => $session->clock_in_at->toDateString(),
            'starts_at' => $session->clock_in_at,
            'ends_at' => $session->clock_out_at,
            'break_minutes' => (int) ($session->break_minutes ?? 0),
            'status' => 'draft',
            'created_by' => $actorId,
        ];

        if (! $timesheet) {
            return Timesheet::query()->create([
                ...$payload,
                'attendance_session_id' => $session->id,
            ]);
        }

        if (! in_array($timesheet->status, ['draft', 'returned'], true)) {
            return $timesheet;
        }

        $timesheet->update($payload);

        return $timesheet->fresh();
    }
}
