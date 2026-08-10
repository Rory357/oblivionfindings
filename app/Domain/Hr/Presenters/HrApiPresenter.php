<?php

namespace App\Domain\Hr\Presenters;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPosition;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Models\Site;
use App\Models\User;
use DateTimeInterface;

/**
 * Explicit minimum-necessary contracts for authenticated HR integrations.
 * Model serialization is deliberately forbidden at this boundary: adding a
 * private model field must never make it part of an API response implicitly.
 */
final class HrApiPresenter
{
    /** @return array<string, mixed> */
    public static function employee(HrEmployeeProfile $profile): array
    {
        return [
            'id' => (int) $profile->id,
            'user_id' => (int) $profile->user_id,
            'employee_number' => $profile->employee_number,
            'work_email' => $profile->work_email,
            'work_phone' => $profile->work_phone,
            'position_title' => $profile->position_title,
            'position_role' => $profile->position_role,
            'employment_type' => $profile->employment_type,
            'contract_type' => $profile->contract_type,
            'start_date' => self::date($profile->start_date),
            'end_date' => self::date($profile->end_date),
            'is_active' => (bool) $profile->is_active,
            'primary_site_id' => self::integer($profile->primary_site_id),
            'secondary_site_ids' => collect($profile->secondary_site_ids ?? [])
                ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all(),
            'position_id' => self::integer($profile->position_id),
            'manager_user_id' => self::integer($profile->manager_user_id),
            'department' => $profile->department,
            'department_id' => self::integer($profile->department_id),
            'team' => $profile->team,
            'preferred_name' => $profile->preferred_name,
            'user' => self::user($profile->user),
            'primary_site' => self::site($profile->primarySite),
        ];
    }

    /** @return array<string, mixed> */
    public static function leaveRequest(
        HrLeaveRequest $request,
        bool $reasonRestricted,
        bool $hasDocument,
    ): array {
        return [
            'id' => (int) $request->id,
            'user_id' => (int) $request->user_id,
            'leave_type' => $request->leave_type,
            'period' => $request->period,
            'starts_at' => self::timestamp($request->starts_at),
            'ends_at' => self::timestamp($request->ends_at),
            'hours_requested' => $request->hours_requested,
            'reason' => $reasonRestricted ? null : $request->reason,
            'reason_restricted' => $reasonRestricted,
            'has_doc' => $hasDocument,
            'status' => $request->status,
            'submitted_at' => self::timestamp($request->submitted_at),
            'approval_due_at' => self::timestamp($request->approval_due_at),
            'reviewed_by' => self::integer($request->reviewed_by),
            'reviewed_at' => self::timestamp($request->reviewed_at),
            'escalation_level' => self::integer($request->escalation_level),
            'escalated_at' => self::timestamp($request->escalated_at),
            'user' => self::user($request->user),
            'reviewer' => self::user($request->reviewer),
        ];
    }

    /** @return array<string, mixed> */
    public static function leaveBalance(HrLeaveBalance $balance): array
    {
        return [
            'id' => (int) $balance->id,
            'user_id' => (int) $balance->user_id,
            'leave_type' => $balance->leave_type,
            'balance_hours' => $balance->balance_hours,
            'accrued_hours' => $balance->accrued_hours,
            'used_hours' => $balance->used_hours,
            'pending_hours' => $balance->pending_hours,
            'year' => (int) $balance->year,
            'source' => $balance->source,
            'last_synced_at' => self::timestamp($balance->last_synced_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function position(HrPosition $position): array
    {
        return [
            'id' => (int) $position->id,
            'title' => $position->title,
            'code' => $position->code,
            'department' => $position->department,
            'team' => $position->team,
            'summary' => $position->summary,
            'employment_type' => $position->employment_type,
            'fte' => $position->fte,
            'headcount_budget' => (int) $position->headcount_budget,
            'current_headcount' => (int) $position->current_headcount,
            'vacancies' => $position->vacancies,
            'reports_to_position_id' => self::integer($position->reports_to_position_id),
            'is_active' => (bool) $position->is_active,
        ];
    }

    /** @return array<string, mixed> */
    public static function complianceStatus(HrStaffComplianceStatus $status): array
    {
        return [
            'id' => (int) $status->id,
            'user_id' => (int) $status->user_id,
            'requirement_id' => (int) $status->requirement_id,
            'status' => $status->status,
            'evidence_type' => $status->evidence_type,
            'evidence_category' => $status->evidence_category,
            'valid_from' => self::date($status->valid_from),
            'expires_at' => self::date($status->expires_at),
            'is_exempt' => $status->status === 'exempt' || $status->exempted_at !== null,
            'exempted_until' => self::date($status->exempted_until),
            'last_checked_at' => self::timestamp($status->last_checked_at),
            'next_check_at' => self::timestamp($status->next_check_at),
            'user' => self::user($status->user),
            'requirement' => $status->requirement ? [
                'id' => (int) $status->requirement->id,
                'code' => $status->requirement->code,
                'name' => $status->requirement->name,
                'category' => $status->requirement->category,
                'hard_stop' => (bool) $status->requirement->hard_stop,
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function timeEntry(HrTimeEntry $entry): array
    {
        return [
            'id' => (int) $entry->id,
            'user_id' => (int) $entry->user_id,
            'shift_id' => self::integer($entry->shift_id),
            'site_id' => self::integer($entry->site_id),
            'client_id' => self::integer($entry->client_id),
            'entry_date' => self::date($entry->entry_date),
            'clock_in' => self::timestamp($entry->clock_in),
            'clock_out' => self::timestamp($entry->clock_out),
            'break_minutes' => (int) $entry->break_minutes,
            'total_hours' => $entry->total_hours,
            'entry_type' => $entry->entry_type,
            'status' => $entry->status,
            'pay_type' => $entry->pay_type,
            'is_sleepover' => (bool) $entry->is_sleepover,
            'is_on_call' => (bool) $entry->is_on_call,
            'is_public_holiday' => (bool) $entry->is_public_holiday,
            'mileage_km' => $entry->mileage_km,
            'break_compliance_met' => $entry->break_compliance_met === null
                ? null
                : (bool) $entry->break_compliance_met,
            'approved_by' => self::integer($entry->approved_by),
            'approved_at' => self::timestamp($entry->approved_at),
            'user' => self::user($entry->user),
        ];
    }

    /** @return array<string, mixed> */
    public static function payrollRun(HrPayrollRun $run): array
    {
        return [
            'id' => (int) $run->id,
            'period_start' => self::date($run->period_start),
            'period_end' => self::date($run->period_end),
            'status' => $run->status,
            'locked_at' => self::timestamp($run->locked_at),
            'locked_by' => self::integer($run->locked_by),
            'exported_at' => self::timestamp($run->exported_at),
            'exported_by' => self::integer($run->exported_by),
            'export_format' => $run->export_format,
            'total_hours' => $run->total_hours,
            'total_gross' => $run->total_gross,
            'total_staff' => (int) $run->total_staff,
            'journal_id' => self::integer($run->journal_id),
            'gl_posted_at' => self::timestamp($run->gl_posted_at),
            'net_paid_at' => self::timestamp($run->net_paid_at),
            'created_by' => self::integer($run->created_by),
            'creator' => self::user($run->creator),
        ];
    }

    /** @return array{id: int, name: string, email: string}|null */
    private static function user(?User $user): ?array
    {
        return $user ? [
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ] : null;
    }

    /** @return array{id: int, name: string}|null */
    private static function site(?Site $site): ?array
    {
        return $site ? [
            'id' => (int) $site->id,
            'name' => $site->name,
        ] : null;
    }

    private static function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function date(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface
            ? $value->format('Y-m-d')
            : (filled($value) ? (string) $value : null);
    }

    private static function timestamp(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface
            ? $value->format(DATE_ATOM)
            : (filled($value) ? (string) $value : null);
    }
}
