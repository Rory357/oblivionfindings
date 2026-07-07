<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrCourseAssignment;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Domain\Hr\Models\HrCourseSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Domain\Hr\Notifications\TrainingAssignedNotification;
use App\Models\StaffTrainingRecord;
use App\Models\TrainingCourse;
use App\Models\User;
use Illuminate\Support\Carbon as SupportCarbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TrainingService
{
    /** Columns the create/edit wizard may write to hr_courses. */
    private const COURSE_FIELDS = [
        'title', 'code', 'description', 'learning_outcomes', 'prerequisites',
        'category', 'delivery_method', 'duration_hours', 'provider',
        'provider_reference', 'cost', 'org_pays_provider', 'staff_can_claim',
        'is_mandatory', 'requires_renewal', 'validity_period_months',
        'renewal_reminder_months', 'requires_assessment', 'pass_mark_percentage',
        'cpd_points', 'mandatory_for_roles', 'compliance_requirement_id',
        'max_participants', 'is_active',
    ];

    /* ================================================================== */
    /*  Courses                                                            */
    /* ================================================================== */

    public function createCourse(array $data): HrCourse
    {
        return DB::transaction(function () use ($data) {
            $payload = ['tenant_id' => $data['tenant_id']]
                + array_intersect_key($data, array_flip(self::COURSE_FIELDS));

            $payload['delivery_method'] = $data['delivery_method'] ?? 'online';
            $payload['duration_hours'] = $data['duration_hours'] ?? 0;
            $payload['is_active'] = $data['is_active'] ?? true;

            return HrCourse::create($payload);
        });
    }

    public function updateCourse(HrCourse $course, array $data): HrCourse
    {
        return DB::transaction(function () use ($course, $data) {
            $course->update(array_intersect_key($data, array_flip(self::COURSE_FIELDS)));

            return $course->fresh();
        });
    }

    public function setCourseActive(HrCourse $course, bool $active): HrCourse
    {
        $course->update(['is_active' => $active]);

        return $course->fresh();
    }

    /* ================================================================== */
    /*  Sessions                                                          */
    /* ================================================================== */

    public function createSession(HrCourse $course, array $data): HrCourseSession
    {
        return $course->sessions()->create([
            'tenant_id' => $course->tenant_id,
            'session_date' => $data['session_date'],
            'start_time' => $data['start_time'] ?? null,
            'end_time' => $data['end_time'] ?? null,
            'location' => $data['location'] ?? null,
            'online_link' => $data['online_link'] ?? null,
            'trainer_id' => $data['trainer_id'] ?? null,
            'facilitator' => $data['facilitator'] ?? null,
            'max_participants' => $data['max_participants'] ?? $course->max_participants,
            'waitlist_enabled' => $data['waitlist_enabled'] ?? false,
            'status' => 'scheduled',
            'notes' => $data['notes'] ?? null,
        ]);
    }

    public function updateSession(HrCourseSession $session, array $data): HrCourseSession
    {
        $session->update(array_intersect_key($data, array_flip([
            'session_date', 'start_time', 'end_time', 'location', 'online_link',
            'trainer_id', 'facilitator', 'max_participants', 'waitlist_enabled',
            'status', 'notes',
        ])));

        return $session->fresh();
    }

    public function cancelSession(HrCourseSession $session, ?string $reason = null): HrCourseSession
    {
        $session->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        return $session->fresh();
    }

    /* ================================================================== */
    /*  Enrollments                                                       */
    /* ================================================================== */

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
     * Enroll many users at once (idempotent per user/course while still open).
     *
     * @param  array<int>  $userIds
     * @return Collection<int, HrCourseEnrollment>
     */
    public function enrollMany(?int $tenantId, array $userIds, int $courseId, ?int $sessionId = null, ?string $notes = null): Collection
    {
        return DB::transaction(function () use ($tenantId, $userIds, $courseId, $sessionId, $notes) {
            return collect($userIds)->unique()->map(function ($userId) use ($tenantId, $courseId, $sessionId, $notes) {
                $existing = HrCourseEnrollment::where('tenant_id', $tenantId)
                    ->where('user_id', $userId)
                    ->where('course_id', $courseId)
                    ->whereNotIn('status', ['completed'])
                    ->first();

                if ($existing) {
                    if ($sessionId) {
                        $existing->update(['session_id' => $sessionId]);
                    }

                    return $existing;
                }

                return HrCourseEnrollment::create([
                    'tenant_id' => $tenantId,
                    'user_id' => (int) $userId,
                    'course_id' => $courseId,
                    'session_id' => $sessionId,
                    'status' => 'enrolled',
                    'enrolled_at' => now(),
                    'notes' => $notes,
                ]);
            });
        });
    }

    /**
     * Mark an enrollment completed: stamp score/certificate, award CPD-derived
     * expiry, bridge the compliance training record, and close any matching
     * assignment row.
     */
    public function completeEnrollment(HrCourseEnrollment $enrollment, array $data = []): HrCourseEnrollment
    {
        $completed = DB::transaction(function () use ($enrollment, $data) {
            $completedAt = isset($data['completed_at'])
                ? SupportCarbon::parse($data['completed_at'])
                : now();

            $enrollment->update([
                'status' => 'completed',
                'completed_at' => $completedAt,
                'score' => $data['score'] ?? $enrollment->score,
                'certificate_path' => $data['certificate_path'] ?? $enrollment->certificate_path,
                'notes' => $data['notes'] ?? $enrollment->notes,
            ]);

            $freshEnrollment = $enrollment->fresh();
            $this->syncComplianceTrainingRecord($freshEnrollment);
            $this->closeAssignmentFor($freshEnrollment, $data['score'] ?? null);

            return $freshEnrollment;
        });

        // Cross-loop (best-effort, after commit): completing an induction
        // course ticks off the matching pending onboarding task so the
        // new-hire checklist stays in sync automatically.
        $this->completeLinkedOnboardingTask($completed);

        return $completed;
    }

    /**
     * Auto-complete the pending induction onboarding task that maps to this
     * enrollment's course, mirroring ExitInterviewController's exit-interview
     * seam. The linkage is resolved from the checklist's source template: an
     * induction task def carrying `course_code` maps by code to its cloned
     * task (matched by title); otherwise a title match against the course is
     * used. Tasks blocked by dependency or sign-off rules (LogicException)
     * are left for manual completion. Never throws.
     */
    private function completeLinkedOnboardingTask(HrCourseEnrollment $enrollment): void
    {
        try {
            $enrollment->loadMissing('course');
            $course = $enrollment->course;
            if (! $course || ! $enrollment->user_id) {
                return;
            }

            $profileIds = HrEmployeeProfile::query()
                ->where('tenant_id', $enrollment->tenant_id)
                ->where('user_id', $enrollment->user_id)
                ->pluck('id');
            if ($profileIds->isEmpty()) {
                return;
            }

            $checklists = HrOnboardingChecklist::query()
                ->where('tenant_id', $enrollment->tenant_id)
                ->whereIn('employee_profile_id', $profileIds)
                ->whereIn('status', ['pending', 'in_progress'])
                ->with(['tasks' => fn ($q) => $q->orderBy('sort_order')])
                ->get();

            foreach ($checklists as $checklist) {
                $task = $this->matchInductionTask($checklist, $course);
                if (! $task) {
                    continue;
                }

                $code = $course->code ?: $course->title;

                try {
                    app(OnboardingService::class)->completeTask($task, $enrollment->user_id, [
                        'notes' => trim(($task->notes ? $task->notes."\n" : '')."Auto-completed: course {$code} completed."),
                    ]);
                } catch (\LogicException) {
                    // Dependencies or sign-off requirements block auto-completion —
                    // leave the task for the checklist owner to complete manually.
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('Failed to auto-complete linked onboarding induction task', [
                'enrollment_id' => $enrollment->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Find the pending induction task on a checklist that corresponds to the
     * given course: template `course_code` mapping first, then a title match.
     */
    private function matchInductionTask(HrOnboardingChecklist $checklist, HrCourse $course): ?HrOnboardingTask
    {
        $candidates = $checklist->tasks
            ->filter(fn (HrOnboardingTask $t) => $t->category === 'induction' && $t->status !== 'completed')
            ->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        // Preferred: the source template's induction task defs carry an explicit
        // course_code; a cloned task shares the def's title.
        if ($course->code && str_contains((string) $checklist->template_key, ':')) {
            [$role, $siteType] = explode(':', (string) $checklist->template_key, 2);
            $template = HrOnboardingTemplate::query()
                ->where('tenant_id', $checklist->tenant_id)
                ->where('role', $role)
                ->where('site_type', $siteType)
                ->first();

            $codedTitles = collect($template?->tasks ?? [])
                ->filter(fn ($def) => ($def['category'] ?? null) === 'induction'
                    && ($def['course_code'] ?? null) === $course->code)
                ->pluck('title')
                ->filter()
                ->map(fn ($t) => mb_strtolower((string) $t));

            if ($codedTitles->isNotEmpty()) {
                $byCode = $candidates->first(fn (HrOnboardingTask $t) => $codedTitles->contains(mb_strtolower($t->title)));
                if ($byCode) {
                    return $byCode;
                }
            }
        }

        // Fallback: a task whose title references the course (either direction).
        $courseTitle = mb_strtolower($course->title);

        return $candidates->first(function (HrOnboardingTask $t) use ($courseTitle, $course) {
            $taskTitle = mb_strtolower($t->title);

            return str_contains($taskTitle, $courseTitle)
                || str_contains($courseTitle, $taskTitle)
                || ($course->code && str_contains($taskTitle, mb_strtolower($course->code)));
        });
    }

    private function closeAssignmentFor(HrCourseEnrollment $enrollment, $score): void
    {
        HrCourseAssignment::where('tenant_id', $enrollment->tenant_id)
            ->where('user_id', $enrollment->user_id)
            ->where('hr_course_id', $enrollment->course_id)
            ->whereNotIn('status', ['completed', 'waived'])
            ->get()
            ->each(function (HrCourseAssignment $assignment) use ($enrollment, $score) {
                $assignment->update([
                    'status' => 'completed',
                    'score' => $score ?? $enrollment->score,
                    'enrollment_id' => $enrollment->id,
                ]);
            });
    }

    /**
     * Mirror a completed catalog enrollment into the canonical compliance-facing
     * StaffTrainingRecord. HrCourse is the source of truth: the record is keyed by
     * (user, hr_course_id) so EVERY catalog completion is compliance-visible — not
     * only requirement-linked ones. When the course bridges to a legacy
     * TrainingCourse (via its compliance requirement) that id is also stamped so
     * legacy readers keep working during the transition.
     */
    private function syncComplianceTrainingRecord(HrCourseEnrollment $enrollment): void
    {
        $enrollment->loadMissing('course.complianceRequirement');
        $course = $enrollment->course;
        if (! $course) {
            return;
        }

        $requirement = $course->complianceRequirement;
        $legacyCourse = ($requirement && $requirement->check_type === 'training_course' && $requirement->reference_id)
            ? TrainingCourse::query()->find($requirement->reference_id)
            : null;

        $completedAt = $enrollment->completed_at ?? now();
        $validityMonths = $course->validity_period_months
            ?: ($requirement?->validity_months ?: $legacyCourse?->validity_period_months);
        $expiresAt = $validityMonths ? $completedAt->copy()->addMonths((int) $validityMonths) : null;

        StaffTrainingRecord::query()->updateOrCreate(
            [
                'user_id' => $enrollment->user_id,
                'hr_course_id' => $course->id,
            ],
            [
                'training_course_id' => $legacyCourse?->id,
                'status' => 'completed',
                'enrolled_at' => $enrollment->enrolled_at,
                'completed_at' => $completedAt,
                'completion_date' => $completedAt->toDateString(),
                'expires_at' => $expiresAt,
                'assessment_score' => $enrollment->score,
                'assessment_passed' => $course->pass_mark_percentage
                    ? ((float) $enrollment->score >= (float) $course->pass_mark_percentage)
                    : true,
                'certificate_path' => $enrollment->certificate_path,
                'provider' => $course->provider ?? $legacyCourse?->provider,
                'notes' => $enrollment->notes,
                'updated_by' => $enrollment->user_id,
            ]
        );
    }

    /* ================================================================== */
    /*  Assignments                                                       */
    /* ================================================================== */

    /**
     * Expand an audience selection into the concrete set of user IDs.
     *
     * @return array<int>
     */
    public function resolveAudience(int $tenantId, array $form): array
    {
        $type = $form['audience_type'] ?? 'individuals';

        if ($type === 'individuals') {
            return collect($form['user_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();
        }

        $query = HrEmployeeProfile::query()->where('tenant_id', $tenantId);

        if ($type === 'role' && ! empty($form['role'])) {
            $query->where('position_role', $form['role']);
        } elseif ($type === 'site' && ! empty($form['site_id'])) {
            $siteId = (int) $form['site_id'];
            $query->where(function ($q) use ($siteId) {
                $q->where('primary_site_id', $siteId)
                    ->orWhereJsonContains('secondary_site_ids', $siteId);
            });
        }
        // 'cohort' (and role/site with no value) falls through to all tenant staff.

        return $query->pluck('user_id')
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Live-preview headcount for the Assign wizard: how many people the current
     * audience resolves to, and how many already hold an active assignment.
     *
     * @return array{count:int, conflicts:int}
     */
    public function previewAudience(int $tenantId, array $form): array
    {
        $userIds = $this->resolveAudience($tenantId, $form);
        $courseIds = collect($form['course_ids'] ?? [])->map(fn ($id) => (int) $id)->all();

        $conflicts = 0;
        if ($userIds && $courseIds) {
            $conflicts = HrCourseAssignment::where('tenant_id', $tenantId)
                ->whereIn('user_id', $userIds)
                ->whereIn('hr_course_id', $courseIds)
                ->whereNotIn('status', ['completed', 'waived'])
                ->distinct()
                ->count('user_id');
        }

        return ['count' => count($userIds), 'conflicts' => $conflicts];
    }

    /**
     * Create assignment rows for the cross-product of resolved audience × courses.
     *
     * @return int  number of rows created/updated
     */
    public function createAssignments(int $tenantId, array $form, ?int $assignedBy = null): int
    {
        $userIds = $this->resolveAudience($tenantId, $form);
        $courseIds = collect($form['course_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->all();

        if (! $userIds || ! $courseIds) {
            return 0;
        }

        $source = $form['source'] ?? 'manual';
        if (! in_array($source, HrCourseAssignment::SOURCES, true)) {
            $source = 'manual';
        }
        $dueAt = $form['due_at'] ?? null;
        $sessionId = $form['session_id'] ?? null;

        /** @var array<int, HrCourseAssignment> $written */
        $written = [];

        $count = DB::transaction(function () use ($tenantId, $userIds, $courseIds, $source, $dueAt, $sessionId, $assignedBy, $form, &$written) {
            $count = 0;
            foreach ($courseIds as $courseId) {
                foreach ($userIds as $userId) {
                    // Don't resurrect a completed/waived record. Read the prior
                    // status BEFORE the write — after updateOrCreate's save(),
                    // getOriginal() reflects the just-written value (syncOriginal),
                    // so it can't be used to detect the pre-update state.
                    $existing = HrCourseAssignment::where('tenant_id', $tenantId)
                        ->where('user_id', $userId)
                        ->where('hr_course_id', $courseId)
                        ->first();

                    if ($existing && in_array($existing->status, ['completed', 'waived'], true)) {
                        continue;
                    }

                    $written[] = HrCourseAssignment::updateOrCreate(
                        ['tenant_id' => $tenantId, 'user_id' => $userId, 'hr_course_id' => $courseId],
                        [
                            'session_id' => $sessionId,
                            'source' => $source,
                            'source_ref' => $form['source_ref'] ?? null,
                            'assigned_by' => $assignedBy,
                            'assigned_at' => now(),
                            'due_at' => $dueAt,
                            'status' => 'assigned',
                        ]
                    );
                    $count++;
                }
            }

            return $count;
        });

        // After commit: tell each assignee about their new requirement.
        // Best-effort — a notification hiccup never rolls back the assignment.
        $this->notifyAssignees($written);

        return $count;
    }

    /**
     * Send TrainingAssignedNotification (mail + database) to the employee on
     * each freshly created/updated assignment row.
     *
     * @param  array<int, HrCourseAssignment>  $assignments
     */
    private function notifyAssignees(array $assignments): void
    {
        if ($assignments === []) {
            return;
        }

        $courses = HrCourse::query()
            ->whereIn('id', collect($assignments)->pluck('hr_course_id')->unique()->all())
            ->pluck('title', 'id');
        $users = User::query()
            ->whereIn('id', collect($assignments)->pluck('user_id')->unique()->all())
            ->get()
            ->keyBy('id');

        foreach ($assignments as $assignment) {
            $user = $users->get($assignment->user_id);
            if (! $user) {
                continue;
            }

            try {
                $user->notify(new TrainingAssignedNotification([
                    'assignment_id' => $assignment->id,
                    'course_title' => $courses->get($assignment->hr_course_id) ?? 'Training course',
                    'due_at' => $assignment->due_at?->toDateString(),
                ]));
            } catch (\Throwable $exception) {
                Log::warning('Failed to send training assigned notification', [
                    'assignment_id' => $assignment->id,
                    'user_id' => $assignment->user_id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    public function remindAssignment(HrCourseAssignment $assignment): void
    {
        $assignment->update(['reminded_at' => now()]);
    }

    public function waiveAssignment(HrCourseAssignment $assignment, string $reason): HrCourseAssignment
    {
        $assignment->update(['status' => 'waived', 'waived_reason' => $reason]);

        return $assignment->fresh();
    }

    /* ================================================================== */
    /*  Summaries / dashboard                                             */
    /* ================================================================== */

    public function getTrainingSummary(?int $tenantId): array
    {
        $totalCourses = HrCourse::forTenant($tenantId)->active()->count();
        $mandatoryCourses = HrCourse::forTenant($tenantId)->active()->mandatory()->count();
        $totalEnrollments = HrCourseEnrollment::forTenant($tenantId)->count();
        $completedEnrollments = HrCourseEnrollment::forTenant($tenantId)->completed()->count();
        $upcomingSessions = HrCourseSession::forTenant($tenantId)->upcoming()->count();

        $overdue = HrCourseAssignment::forTenant($tenantId)->overdue()->count();
        $expiring = $this->expiringEnrollmentCount($tenantId, 90);

        return [
            'total_courses' => $totalCourses,
            'mandatory_courses' => $mandatoryCourses,
            'total_enrollments' => $totalEnrollments,
            'completed_enrollments' => $completedEnrollments,
            'upcoming_sessions' => $upcomingSessions,
            'overdue_assignments' => $overdue,
            'expiring_soon' => $expiring,
            'completion_rate' => $totalEnrollments > 0 ? (int) round(($completedEnrollments / $totalEnrollments) * 100) : 0,
        ];
    }

    /**
     * Count of completed enrollments whose computed expiry (completed_at +
     * course validity months) falls within the next $days days.
     */
    public function expiringEnrollmentCount(?int $tenantId, int $days = 90): int
    {
        $horizon = now()->addDays($days);

        return HrCourseEnrollment::forTenant($tenantId)
            ->completed()
            ->whereNotNull('completed_at')
            ->with('course:id,validity_period_months,requires_renewal')
            ->get(['id', 'course_id', 'completed_at'])
            ->filter(function (HrCourseEnrollment $e) use ($horizon) {
                $months = $e->course?->validity_period_months;
                if (! $months) {
                    return false;
                }
                $expiry = $e->completed_at->copy()->addMonths((int) $months);

                return $expiry->isBetween(now(), $horizon);
            })
            ->count();
    }

    /**
     * Per-course catalog metrics used by the Catalog tab cards/table.
     *
     * @return array<int, array<string, int>>  keyed by course id
     */
    public function courseMetrics(?int $tenantId): array
    {
        $enrollments = HrCourseEnrollment::forTenant($tenantId)
            ->get(['id', 'course_id', 'status', 'completed_at']);

        $courses = HrCourse::forTenant($tenantId)->get(['id', 'validity_period_months'])->keyBy('id');
        $horizon = now()->addDays(90);

        return $enrollments->groupBy('course_id')->map(function (Collection $rows) use ($courses, $horizon) {
            $total = $rows->count();
            $completed = $rows->where('status', 'completed')->count();
            $expiring = $rows->where('status', 'completed')->filter(function ($e) use ($courses, $horizon) {
                $months = $courses->get($e->course_id)?->validity_period_months;
                if (! $months || ! $e->completed_at) {
                    return false;
                }

                return $e->completed_at->copy()->addMonths((int) $months)->isBetween(now(), $horizon);
            })->count();

            return [
                'enrol' => $total,
                'completion' => $total > 0 ? (int) round(($completed / $total) * 100) : 0,
                'expiring' => $expiring,
            ];
        })->toArray();
    }

    /**
     * Assemble the Dashboard tab payload.
     */
    public function getDashboardData(int $tenantId, array $staffUserIds): array
    {
        // Renewal pressure by course + site, from assignments.
        $assignments = HrCourseAssignment::forTenant($tenantId)
            ->with(['course:id,title,is_mandatory', 'user:id,name'])
            ->whereNotIn('status', ['waived'])
            ->get();

        $profiles = HrEmployeeProfile::where('tenant_id', $tenantId)
            ->get(['user_id', 'primary_site_id'])
            ->keyBy('user_id');
        $siteNames = \App\Models\Site::pluck('name', 'id');

        $now = now();
        // Overdue == strictly past-due: compare against the start of today so a
        // due-today assignment is "due", not "overdue" (matches scopeOverdue's
        // whereDate('due_at','<',today)).
        $today = now()->startOfDay();
        $in30 = now()->addDays(30);

        $byCourseSite = [];
        foreach ($assignments as $a) {
            if (in_array($a->status, ['completed'], true)) {
                continue;
            }
            $siteId = $profiles->get($a->user_id)?->primary_site_id;
            $siteName = $siteId ? ($siteNames[$siteId] ?? 'Unassigned') : 'Unassigned';
            $courseTitle = $a->course?->title ?? 'Unknown';
            $key = $courseTitle.'||'.$siteName;
            $byCourseSite[$key] ??= ['course' => $courseTitle, 'site' => $siteName, 'overdue' => 0, 'due_soon' => 0];

            if ($a->due_at && $a->due_at->lt($today)) {
                $byCourseSite[$key]['overdue']++;
            } elseif ($a->due_at && $a->due_at->lte($in30)) {
                $byCourseSite[$key]['due_soon']++;
            }
        }

        $renewals = collect($byCourseSite)
            ->filter(fn ($r) => $r['overdue'] > 0 || $r['due_soon'] > 0)
            ->sortByDesc('overdue')
            ->take(12)
            ->values()
            ->all();

        // Completion by site (completed enrollments / total enrollments per site).
        $enrollments = HrCourseEnrollment::forTenant($tenantId)->get(['user_id', 'status']);
        $siteAgg = [];
        foreach ($enrollments as $e) {
            $siteId = $profiles->get($e->user_id)?->primary_site_id;
            $siteName = $siteId ? ($siteNames[$siteId] ?? 'Unassigned') : 'Unassigned';
            $siteAgg[$siteName] ??= ['total' => 0, 'completed' => 0];
            $siteAgg[$siteName]['total']++;
            if ($e->status === 'completed') {
                $siteAgg[$siteName]['completed']++;
            }
        }
        $completionBySite = collect($siteAgg)->map(fn ($v, $name) => [
            'site' => $name,
            'completion' => $v['total'] > 0 ? (int) round(($v['completed'] / $v['total']) * 100) : 0,
        ])->sortByDesc('completion')->values()->all();

        // Upcoming sessions with remaining seats.
        $upcoming = HrCourseSession::forTenant($tenantId)
            ->upcoming()
            ->with('course:id,title')
            ->withCount('enrollments')
            ->orderBy('session_date')
            ->limit(8)
            ->get()
            ->map(fn (HrCourseSession $s) => [
                'id' => $s->id,
                'course' => $s->course?->title ?? 'Session',
                'date' => $s->session_date?->toDateString(),
                'seats' => $s->max_participants ? max(0, $s->max_participants - $s->enrollments_count) : null,
            ])->all();

        // Training spend YTD (completed enrollments × course cost). Qualify the
        // tenant column — both joined tables carry tenant_id.
        $spend = HrCourseEnrollment::query()
            ->where('hr_course_enrollments.tenant_id', $tenantId)
            ->where('hr_course_enrollments.status', 'completed')
            ->whereYear('hr_course_enrollments.completed_at', $now->year)
            ->join('hr_courses', 'hr_courses.id', '=', 'hr_course_enrollments.course_id')
            ->sum('hr_courses.cost');

        $mandatoryAssignments = $assignments->filter(fn ($a) => (bool) $a->course?->is_mandatory);
        $totalMand = $mandatoryAssignments->count();
        $currentMand = $mandatoryAssignments->filter(fn ($a) => $a->status === 'completed'
            || ! ($a->due_at && $a->due_at->lt($today)))->count();

        return [
            'mandatoryCurrentPct' => $totalMand > 0 ? (int) round(($currentMand / $totalMand) * 100) : 100,
            'overdueCount' => $assignments->filter(fn ($a) => $a->status !== 'completed' && $a->due_at && $a->due_at->lt($today))->count(),
            'expiringCount' => $this->expiringEnrollmentCount($tenantId, 90),
            'spendYtd' => (float) $spend,
            'renewals' => $renewals,
            'completionBySite' => $completionBySite,
            'upcomingSessions' => $upcoming,
        ];
    }
}
