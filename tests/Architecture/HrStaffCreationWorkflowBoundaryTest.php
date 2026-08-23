<?php

test('staff creation stays owned by HR People with one source payload and asset contract', function (): void {
    $root = dirname(__DIR__, 2);
    $employeeController = file_get_contents($root.'/app/Http/Controllers/Hr/EmployeeProfileController.php');
    $systemController = file_get_contents($root.'/app/Http/Controllers/System/UsersController.php');
    $storeRequest = file_get_contents($root.'/app/Http/Requests/Hr/StoreEmployeeRequest.php');
    $intake = file_get_contents($root.'/app/Domain/Hr/Services/EmployeeIntakeService.php');
    $identityLinks = file_get_contents($root.'/app/Domain/Hr/Services/EmployeeIdentityLinkPolicy.php');
    $identityLineageMigration = file_get_contents($root.'/database/migrations/2026_08_23_000240_enforce_employee_identity_link_lineage.php');
    $recruitment = file_get_contents($root.'/app/Domain/Hr/Services/RecruitmentService.php');
    $roleAssignments = file_get_contents($root.'/app/Domain/Hr/Services/EmployeeRoleAssignmentService.php');
    $addDialog = file_get_contents($root.'/resources/js/components/hr/add-employee-dialog.tsx');
    $peoplePage = file_get_contents($root.'/resources/js/pages/hr/employees/index.tsx');
    $systemPage = file_get_contents($root.'/resources/js/pages/system/users/Create.tsx');
    $assetContract = file_get_contents($root.'/resources/js/lib/hr/staff-creation-workflow.ts');

    expect(is_file($root.'/app/Http/Requests/Hr/StoreEmployeeProfileRequest.php'))->toBeFalse()
        ->and($employeeController)
        ->toContain('EmployeeIntakeService $intake')
        ->toContain("'creationIntent'")
        ->toContain('EmployeeRoleAssignmentService::class')
        ->toContain('->active()')
        ->toContain('->notArchived()')
        ->and($systemController)
        ->toContain("\$request->query('type') === 'staff'")
        ->toContain("'create' => 'staff'")
        ->toContain("'user_type' => ['required', 'in:client,next_of_kin']")
        ->and($systemPage)
        ->toContain('STAFF_CREATION_INTENT')
        ->toContain('Create in HR People')
        ->not->toContain("userType === 'staff'")
        ->not->toContain("'staff.job_title'")
        ->and(substr_count($systemPage, 'href={staffLifecycleHref}'))->toBe(1)
        ->and($peoplePage)->toContain('creationIntent === STAFF_CREATION_INTENT')
        ->and($addDialog)
        ->toContain('employeeCreationIsComplete')
        ->toContain('primary_site_id')
        ->toContain('secondary_site_ids')
        ->toContain('required')
        ->not->toContain('All optional')
        ->and($assetContract)
        ->toContain("STAFF_CREATION_INTENT = 'staff'")
        ->toContain("role.trim() !== ''")
        ->toContain("primarySiteId !== ''")
        ->and($storeRequest)
        ->toContain('UserSiteAccessService::HR_EMPLOYEE_SITE_BYPASS_PERMISSIONS')
        ->toContain("'role' => ['required'")
        ->toContain("'primary_site_id' => [")
        ->toContain("'required'")
        ->toContain("'secondary_site_ids.*'")
        ->and($roleAssignments)
        ->toContain("canDo('hr.employees.manage')")
        ->toContain("CLINICAL_LEAD_GRANT_PERMISSION = 'hr.employees.assignClinicalLead'")
        ->toContain("where('name', '!=', 'clinical_lead')")
        ->and($intake)
        ->toContain("acquireIntakeLock('email:'.\$email)")
        ->toContain('existingUserIdForEmail')
        ->toContain('identityLinks->authorize')
        ->toContain('Existing employee identity lineage cannot be changed through intake.')
        ->toContain("acquireIntakeLock('employee-number-sequence')")
        ->toContain('assertSiteAssignmentIsAvailable')
        ->toContain("AuditLogger::logOrFail('user.employee_intake'")
        ->and($identityLinks)
        ->toContain('accepted_candidate_two_person_evidence')
        ->toContain('existing_recruitment_identity_replay')
        ->toContain('permissionOverrides()->exists()')
        ->toContain('portalClients()->withTrashed()->exists()')
        ->toContain("where('user_id', \$user->id)")
        ->toContain("(int) \$offer->approved_by !== (int) \$offer->created_by")
        ->toContain("\$signedName === \$candidateName")
        ->and($identityLineageMigration)
        ->toContain("unique('offer_id', 'hr_employee_profiles_offer_uq')")
        ->toContain("unique('candidate_id', 'hr_employee_profiles_candidate_uq')")
        ->toContain("'offer links missing candidate lineage'")
        ->and($recruitment)
        ->toContain('EmployeeIntakeService::class')->not->toContain('function generateEmployeeNumber(')
        ->and(strpos($intake, "AuditLogger::logOrFail('user.employee_intake'"))
        ->toBeLessThan(strpos($intake, 'DB::afterCommit('));
});
