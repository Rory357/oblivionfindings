<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Domain\Hr\Models\HrCourseSession;
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

            return $enrollment->fresh();
        });
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
