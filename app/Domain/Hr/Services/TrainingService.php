<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Domain\Hr\Models\HrCourseSession;
use App\Models\StaffTrainingRecord;
use App\Models\TrainingCourse;
use Illuminate\Support\Facades\DB;

class TrainingService
{
    /**
     * Create a new course.
     */
    public function createCourse(array $data): HrCourse
    {
        return DB::transaction(function () use ($data) {
            return HrCourse::create([
                'tenant_id' => $data['tenant_id'],
                'title' => $data['title'],
                'code' => $data['code'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? null,
                'delivery_method' => $data['delivery_method'],
                'duration_hours' => $data['duration_hours'] ?? 0,
                'provider' => $data['provider'] ?? null,
                'cost' => $data['cost'] ?? null,
                'is_mandatory' => $data['is_mandatory'] ?? false,
                'compliance_requirement_id' => $data['compliance_requirement_id'] ?? null,
                'max_participants' => $data['max_participants'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);
        });
    }

    /**
     * Enroll a user in a course (optionally in a specific session).
     */
    public function enroll(?int $tenantId, int $userId, int $courseId, ?int $sessionId = null, ?string $notes = null): HrCourseEnrollment
    {
        return DB::transaction(function () use ($tenantId, $userId, $courseId, $sessionId, $notes) {
            return HrCourseEnrollment::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'course_id' => $courseId,
                'session_id' => $sessionId,
                'status' => 'enrolled',
                'enrolled_at' => now(),
                'notes' => $notes,
            ]);
        });
    }

    /**
     * Mark an enrollment as completed.
     */
    public function completeEnrollment(HrCourseEnrollment $enrollment, array $data = []): HrCourseEnrollment
    {
        return DB::transaction(function () use ($enrollment, $data) {
            $enrollment->update([
                'status' => 'completed',
                'completed_at' => now(),
                'score' => $data['score'] ?? null,
                'certificate_path' => $data['certificate_path'] ?? null,
                'notes' => $data['notes'] ?? $enrollment->notes,
            ]);

            $freshEnrollment = $enrollment->fresh();
            $this->syncComplianceTrainingRecord($freshEnrollment);

            return $freshEnrollment;
        });
    }

    private function syncComplianceTrainingRecord(HrCourseEnrollment $enrollment): void
    {
        $enrollment->loadMissing('course.complianceRequirement');
        $course = $enrollment->course;
        $requirement = $course?->complianceRequirement;

        if (! $course || ! $requirement || $requirement->check_type !== 'training_course' || ! $requirement->reference_id) {
            return;
        }

        $legacyCourse = TrainingCourse::query()->find($requirement->reference_id);
        if (! $legacyCourse) {
            return;
        }

        $completedAt = $enrollment->completed_at ?? now();
        $validityMonths = $requirement->validity_months ?: $legacyCourse->validity_period_months;
        $expiresAt = $validityMonths ? $completedAt->copy()->addMonths((int) $validityMonths) : null;

        StaffTrainingRecord::query()->updateOrCreate(
            [
                'user_id' => $enrollment->user_id,
                'training_course_id' => $legacyCourse->id,
            ],
            [
                'status' => 'completed',
                'enrolled_at' => $enrollment->enrolled_at,
                'completed_at' => $completedAt,
                'completion_date' => $completedAt->toDateString(),
                'expires_at' => $expiresAt,
                'assessment_score' => $enrollment->score,
                'assessment_passed' => true,
                'certificate_path' => $enrollment->certificate_path,
                'provider' => $course->provider ?? $legacyCourse->provider,
                'notes' => $enrollment->notes,
                'updated_by' => $enrollment->user_id,
            ]
        );
    }

    /**
     * Get training summary statistics for a tenant.
     */
    public function getTrainingSummary(?int $tenantId): array
    {
        $totalCourses = HrCourse::forTenant($tenantId)->active()->count();
        $mandatoryCourses = HrCourse::forTenant($tenantId)->active()->mandatory()->count();
        $totalEnrollments = HrCourseEnrollment::forTenant($tenantId)->count();
        $completedEnrollments = HrCourseEnrollment::forTenant($tenantId)->completed()->count();
        $upcomingSessions = HrCourseSession::forTenant($tenantId)->upcoming()->count();

        return [
            'total_courses' => $totalCourses,
            'mandatory_courses' => $mandatoryCourses,
            'total_enrollments' => $totalEnrollments,
            'completed_enrollments' => $completedEnrollments,
            'upcoming_sessions' => $upcomingSessions,
            'completion_rate' => $totalEnrollments > 0 ? round(($completedEnrollments / $totalEnrollments) * 100, 1) : 0,
        ];
    }
}
