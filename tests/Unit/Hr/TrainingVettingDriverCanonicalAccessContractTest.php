<?php

function canonicalHrSliceSource(string $relativePath): string
{
    $source = file_get_contents(dirname(__DIR__, 3).'/'.$relativePath);
    expect($source)->not->toBeFalse();

    return (string) $source;
}

/** @return array<string, string> */
function canonicalHrForbiddenSourceFragments(): array
{
    $partition = 'ten'.'ant';
    $partitionClass = 'Ten'.'ant';
    $organization = 'organi'.'zation';
    $organisation = 'organi'.'sation';

    return [
        'storage_scope' => 'hrApplicationStorage'.'ContextId()',
        'partition_where' => "where('{$partition}_id'",
        'organization_where' => "where('{$organization}_id'",
        'recipient_rule' => 'applicationRecipient'.'Rule()',
        'record_scope' => 'for'.$partitionClass.'(',
        'partition_write' => "'{$partition}_id' =>",
        'model_scope' => 'scopeFor'.$partitionClass,
        'recognition_guard' => '$this->assertHr'.'OrganisationAccess($'.$organisation.'Id, $kudos->'.$partition.'_id)',
    ];
}

test('driver and vetting use one current staff Site scope for reads and locked mutations', function () {
    $forbidden = canonicalHrForbiddenSourceFragments();

    foreach ([
        'app/Http/Controllers/Hr/DriverEligibilityController.php',
        'app/Http/Controllers/Hr/VettingController.php',
    ] as $path) {
        $source = canonicalHrSliceSource($path);

        expect($source)
            ->toContain('applyStaffScope')
            ->toContain('PeopleMutationLockService')
            ->toContain('lockForUpdate()')
            ->toContain('abort_unless($lockedActor instanceof User')
            ->not->toContain('ResolvesHrOrganisationContext')
            ->not->toContain($forbidden['storage_scope'])
            ->not->toContain($forbidden['partition_where'])
            ->not->toContain($forbidden['organization_where'])
            ->not->toContain('hrApplicationStaffUserIds()');
    }
});

test('training applies visible staff to hub detail exports and every recipient mutation', function () {
    $controller = canonicalHrSliceSource('app/Http/Controllers/Hr/TrainingController.php');
    $service = canonicalHrSliceSource('app/Domain/Hr/Services/TrainingService.php');
    $forbidden = canonicalHrForbiddenSourceFragments();

    expect($controller)
        ->toContain('visibleStaffUserIds')
        ->toContain('competencySummary($staffUserIds)')
        ->toContain('inductionSummary($staffUserIds)')
        ->toContain("->whereHas('employeeProfile'")
        ->toContain("withCount('enrollments')")
        ->toContain('assertVisibleUserIds')
        ->toContain('assertAccessibleAudienceSite')
        ->toContain('assertSessionMatchesCourses')
        ->toContain('lockVisibleEnrollment')
        ->toContain('lockVisibleAssignment')
        ->toContain('PeopleMutationLockService')
        ->toContain('DB::afterRollBack')
        ->toContain('DB::afterCommit')
        ->toContain('deleteTrainingCertificate')
        ->toContain('Upload a certificate for one employee at a time.')
        ->toContain("'certificate_number' => \$data['certificate_number'] ?? null")
        ->toContain('HrCurrentStaffService')
        ->not->toContain('LegacyStorageContext')
        ->not->toContain('ResolvesHrOrganisationContext')
        ->not->toContain($forbidden['storage_scope'])
        ->not->toContain($forbidden['recipient_rule'])
        ->not->toContain($forbidden['record_scope'])
        ->not->toContain($forbidden['partition_where'])
        ->not->toContain($forbidden['organization_where']);

    expect($service)
        ->toContain('?array $allowedUserIds = null')
        ->toContain("->whereIn('user_id', \$staffUserIds ?: [0])")
        ->toContain('public function assertSessionMatchesCourses')
        ->toContain("'certificate_number' => \$enrollment->certificate_number")
        ->not->toContain('LegacyStorageContext')
        ->not->toContain('storageContextId')
        ->not->toContain($forbidden['partition_write'])
        ->not->toContain($forbidden['record_scope'])
        ->not->toContain($forbidden['partition_where']);

    foreach ([
        'app/Domain/Hr/Models/HrCourse.php',
        'app/Domain/Hr/Models/HrCourseSession.php',
        'app/Domain/Hr/Models/HrCourseAssignment.php',
        'app/Domain/Hr/Models/HrCourseEnrollment.php',
    ] as $path) {
        expect(canonicalHrSliceSource($path))
            ->toContain('WritesLegacyStorageContext')
            ->not->toContain($forbidden['model_scope']);
    }

    expect(canonicalHrSliceSource('app/Domain/Hr/Models/HrDriverEligibility.php'))
        ->toContain('WritesLegacyStorageContext')
        ->not->toContain($forbidden['model_scope']);

    $enrollment = canonicalHrSliceSource('app/Domain/Hr/Models/HrCourseEnrollment.php');
    $certificateService = canonicalHrSliceSource('app/Domain/Hr/Services/CertificateService.php');
    $migration = canonicalHrSliceSource('database/migrations/2026_07_23_000004_add_certificate_number_to_hr_course_enrollments.php');
    expect($enrollment)->toContain("'certificate_number'")
        ->and($certificateService)->toContain("'certificate_number' => \$certificateNumber")
        ->and($certificateService)->toContain('StaffTrainingRecord::query()')
        ->and($certificateService)->toContain('DB::afterRollBack')
        ->and($migration)->toContain("string('certificate_number', 120)");
});

test('the retained training dashboard exposes only accessible Sites and current staff', function () {
    $source = canonicalHrSliceSource('app/Http/Controllers/Hr/TrainingDashboardController.php');
    $forbidden = canonicalHrForbiddenSourceFragments();

    expect($source)
        ->toContain('applyStaffScope')
        ->toContain('applySiteScope')
        ->toContain('accessibleSiteIds')
        ->not->toContain('ResolvesHrOrganisationContext')
        ->not->toContain($forbidden['partition_where'])
        ->not->toContain('hrApplicationStaffUserIds()');
});

test('My HR recognition mutations require the canonical permission and current staff boundary', function () {
    $controller = canonicalHrSliceSource('app/Http/Controllers/Hr/MyHrController.php');
    $routes = canonicalHrSliceSource('routes/hr.php');
    $forbidden = canonicalHrForbiddenSourceFragments();

    expect($controller)
        ->toContain("canDo('hr.recognition.give')")
        ->toContain('$this->currentStaff->isCurrent($user)')
        ->toContain('$this->feedService->canViewKudos($kudos, $user)')
        ->not->toContain($forbidden['recognition_guard']);
    expect(substr_count($routes, "->middleware('permission:hr.recognition.give')"))->toBeGreaterThanOrEqual(3);
});
