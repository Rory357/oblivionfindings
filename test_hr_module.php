<?php
/**
 * HR Module Comprehensive Test Script
 * Run: php test_hr_module.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$passed = 0;
$failed = 0;
$errors = [];

function test(string $label, callable $fn): void
{
    global $passed, $failed, $errors;
    try {
        $result = $fn();
        if ($result === false) {
            $failed++;
            $errors[] = "FAIL: {$label}";
            echo "  FAIL  {$label}\n";
        } else {
            $passed++;
            echo "  PASS  {$label}\n";
        }
    } catch (\Throwable $e) {
        $failed++;
        $msg = get_class($e) . ': ' . $e->getMessage();
        $errors[] = "ERROR: {$label} -> {$msg}";
        echo "  ERROR {$label}\n        " . $msg . "\n";
    }
}

echo "\n========================================\n";
echo "  HR MODULE TEST SUITE\n";
echo "========================================\n\n";

// ─────────────────────────────────────────
// 1. Model Instantiation & Table Existence
// ─────────────────────────────────────────
echo "--- 1. MODEL INSTANTIATION & TABLE QUERIES ---\n";

$models = [
    'HrCandidate'              => \App\Domain\Hr\Models\HrCandidate::class,
    'HrApplication'            => \App\Domain\Hr\Models\HrApplication::class,
    'HrInterview'              => \App\Domain\Hr\Models\HrInterview::class,
    'HrReferenceCheck'         => \App\Domain\Hr\Models\HrReferenceCheck::class,
    'HrOffer'                  => \App\Domain\Hr\Models\HrOffer::class,
    'HrEmployeeProfile'        => \App\Domain\Hr\Models\HrEmployeeProfile::class,
    'HrEmployeeProfileVersion' => \App\Domain\Hr\Models\HrEmployeeProfileVersion::class,
    'HrComplianceRequirement'  => \App\Domain\Hr\Models\HrComplianceRequirement::class,
    'HrComplianceMatrix'       => \App\Domain\Hr\Models\HrComplianceMatrix::class,
    'HrStaffComplianceStatus'  => \App\Domain\Hr\Models\HrStaffComplianceStatus::class,
    'HrLeaveRequest'           => \App\Domain\Hr\Models\HrLeaveRequest::class,
    'HrLeaveBalance'           => \App\Domain\Hr\Models\HrLeaveBalance::class,
    'HrOnboardingTemplate'     => \App\Domain\Hr\Models\HrOnboardingTemplate::class,
    'HrOnboardingChecklist'    => \App\Domain\Hr\Models\HrOnboardingChecklist::class,
    'HrOnboardingTask'         => \App\Domain\Hr\Models\HrOnboardingTask::class,
    'HrOffboardingChecklist'   => \App\Domain\Hr\Models\HrOffboardingChecklist::class,
    'HrSupervisionNote'        => \App\Domain\Hr\Models\HrSupervisionNote::class,
    'HrPerformanceReview'      => \App\Domain\Hr\Models\HrPerformanceReview::class,
    'HrProbationReview'        => \App\Domain\Hr\Models\HrProbationReview::class,
    'HrCase'                   => \App\Domain\Hr\Models\HrCase::class,
    'HrCaseEvent'              => \App\Domain\Hr\Models\HrCaseEvent::class,
    'HrDisciplinaryAction'     => \App\Domain\Hr\Models\HrDisciplinaryAction::class,
    'HrPolicy'                 => \App\Domain\Hr\Models\HrPolicy::class,
    'HrPolicyVersion'          => \App\Domain\Hr\Models\HrPolicyVersion::class,
    'HrPolicyAttestation'      => \App\Domain\Hr\Models\HrPolicyAttestation::class,
    'HrDocument'               => \App\Domain\Hr\Models\HrDocument::class,
    'HrDocumentTemplate'       => \App\Domain\Hr\Models\HrDocumentTemplate::class,
    'HrPayrollRun'             => \App\Domain\Hr\Models\HrPayrollRun::class,
    'HrPayrollRunItem'         => \App\Domain\Hr\Models\HrPayrollRunItem::class,
    'HrDriverEligibility'      => \App\Domain\Hr\Models\HrDriverEligibility::class,
    'HrWellbeingIndicator'     => \App\Domain\Hr\Models\HrWellbeingIndicator::class,
];

foreach ($models as $name => $class) {
    test("Model {$name}: instantiate + count()", function () use ($class) {
        $instance = new $class();
        $count = $class::query()->count();
        return $instance instanceof \Illuminate\Database\Eloquent\Model;
    });
}

// ─────────────────────────────────────────
// 2. Service Resolution
// ─────────────────────────────────────────
echo "\n--- 2. SERVICE RESOLUTION ---\n";

$services = [
    'ComplianceMatrixService'    => \App\Domain\Hr\Services\ComplianceMatrixService::class,
    'LeaveService'               => \App\Domain\Hr\Services\LeaveService::class,
    'RecruitmentService'         => \App\Domain\Hr\Services\RecruitmentService::class,
    'WellbeingIndicatorService'  => \App\Domain\Hr\Services\WellbeingIndicatorService::class,
    'OnboardingService'          => \App\Domain\Hr\Services\OnboardingService::class,
    'PayrollExportService'       => \App\Domain\Hr\Services\PayrollExportService::class,
    'HrEvidencePackService'      => \App\Domain\Hr\Services\HrEvidencePackService::class,
    'HrDocumentMergeService'     => \App\Domain\Hr\Services\HrDocumentMergeService::class,
];

foreach ($services as $name => $class) {
    test("Service {$name}: resolve from container", function () use ($class) {
        $instance = app($class);
        return $instance !== null;
    });
}

// ─────────────────────────────────────────
// 3. Service Constants
// ─────────────────────────────────────────
echo "\n--- 3. SERVICE CONSTANTS ---\n";

test("RecruitmentService::STAGES exists and is array", function () {
    return is_array(\App\Domain\Hr\Services\RecruitmentService::STAGES)
        && count(\App\Domain\Hr\Services\RecruitmentService::STAGES) > 0;
});

test("LeaveService::LEAVE_TYPES exists and is array", function () {
    return is_array(\App\Domain\Hr\Services\LeaveService::LEAVE_TYPES)
        && count(\App\Domain\Hr\Services\LeaveService::LEAVE_TYPES) > 0;
});

// ─────────────────────────────────────────
// 4. Permission Keys in Database
// ─────────────────────────────────────────
echo "\n--- 4. PERMISSION KEY VERIFICATION ---\n";

$requiredPermissions = [
    'hr.recruitment.view', 'hr.recruitment.manage',
    'hr.employees.viewAny', 'hr.employees.viewOwn', 'hr.employees.manage',
    'hr.employees.viewFinancial', 'hr.employees.viewRestricted',
    'hr.compliance.view', 'hr.compliance.manage',
    'hr.training.view', 'hr.training.manage',
    'hr.vetting.view', 'hr.vetting.manage', 'hr.vetting.view_disclosures',
    'hr.leave.viewAny', 'hr.leave.viewOwn', 'hr.leave.approve', 'hr.leave.manage',
    'hr.performance.view', 'hr.performance.manage',
    'hr.cases.view', 'hr.cases.manage',
    'hr.disciplinary.view', 'hr.disciplinary.manage',
    'hr.policies.view', 'hr.policies.manage', 'hr.policies.attest',
    'hr.documents.view', 'hr.documents.manage',
    'hr.payroll.view', 'hr.payroll.export',
    'hr.reports.view', 'hr.reports.export',
    'hr.driver.view', 'hr.driver.manage',
    'hr.wellbeing.view',
    'hr.onboarding.view', 'hr.onboarding.manage',
];

$existingKeys = \App\Models\Permission::whereIn('key', $requiredPermissions)
    ->pluck('key')
    ->toArray();

foreach ($requiredPermissions as $key) {
    test("Permission '{$key}' exists in DB", function () use ($key, $existingKeys) {
        return in_array($key, $existingKeys);
    });
}

// ─────────────────────────────────────────
// 5. Admin User Permission Checks (canDo)
// ─────────────────────────────────────────
echo "\n--- 5. ADMIN canDo() CHECKS ---\n";

$admin = \App\Models\User::where('role', 'admin')->first();
if (!$admin) {
    echo "  SKIP  No admin user found — skipping canDo tests\n";
} else {
    // Check permission keys used in controllers (these are what actually get called)
    $controllerPermKeys = [
        'hr.recruitment.view', 'hr.recruitment.manage',
        'hr.employees.viewAny', 'hr.employees.manage', 'hr.employees.viewRestricted',
        'hr.compliance.view', 'hr.compliance.manage',
        'hr.training.view', 'hr.training.manage',
        'hr.vetting.view', 'hr.vetting.manage',
        'hr.driver.view', 'hr.driver.manage',
        'hr.leave.viewAny', 'hr.leave.manage', 'hr.leave.approve',
        'hr.onboarding.view', 'hr.onboarding.manage',
        'hr.performance.view', 'hr.performance.manage',
        'hr.cases.view', 'hr.cases.manage',
        'hr.disciplinary.manage',
        'hr.policies.view', 'hr.policies.manage', 'hr.policies.attest',
        'hr.documents.view', 'hr.documents.manage',
        'hr.payroll.view', 'hr.payroll.export',
        'hr.reports.view', 'hr.reports.export',
    ];

    foreach ($controllerPermKeys as $key) {
        test("Admin canDo('{$key}')", function () use ($admin, $key) {
            return $admin->canDo($key) === true;
        });
    }
}

// ─────────────────────────────────────────
// 6. Route Registration Verification
// ─────────────────────────────────────────
echo "\n--- 6. ROUTE REGISTRATION ---\n";

$expectedRoutes = [
    'GET hr/my'                     => 'hr.my.index',
    'GET hr/my/leave'               => 'hr.my.leave',
    'GET hr/my/training'            => 'hr.my.training',
    'GET hr/my/policies'            => 'hr.my.policies',
    'GET hr/my/profile'             => 'hr.my.profile',
    'GET hr/recruitment'            => 'hr.recruitment.index',
    'GET hr/people'                 => 'hr.people.index',
    'GET hr/compliance'             => 'hr.compliance.index',
    'GET hr/compliance/matrix'      => 'hr.compliance.matrix',
    'GET hr/compliance/training'    => 'hr.training.index',
    'GET hr/compliance/vetting'     => 'hr.vetting.index',
    'GET hr/compliance/drivers'     => 'hr.drivers.index',
    'GET hr/leave'                  => 'hr.leave.index',
    'GET hr/leave/balances'         => 'hr.leave.balances',
    'GET hr/onboarding'             => 'hr.onboarding.index',
    'GET hr/performance'            => 'hr.performance.index',
    'GET hr/performance/reviews'    => 'hr.reviews.index',
    'GET hr/performance/cases'      => 'hr.cases.index',
    'GET hr/policies'               => 'hr.policies.index',
    'GET hr/documents'              => 'hr.documents.index',
    'GET hr/payroll'                => 'hr.payroll.index',
    'GET hr/reports'                => 'hr.reports.index',
];

$routes = \Illuminate\Support\Facades\Route::getRoutes();

foreach ($expectedRoutes as $methodPath => $name) {
    test("Route '{$name}' ({$methodPath}) registered", function () use ($routes, $name) {
        return $routes->getByName($name) !== null;
    });
}

// ─────────────────────────────────────────
// 7. Controller HTTP Simulation (actingAs admin)
// ─────────────────────────────────────────
echo "\n--- 7. CONTROLLER HTTP SMOKE TEST ---\n";

if (!$admin) {
    echo "  SKIP  No admin user — skipping HTTP tests\n";
} else {
    $getRoutes = [
        '/hr/my'                  => 'My HR index',
        '/hr/my/leave'            => 'My Leave',
        '/hr/my/training'         => 'My Training',
        '/hr/my/policies'         => 'My Policies',
        '/hr/my/profile'          => 'My Profile',
        '/hr/recruitment'         => 'Recruitment index',
        '/hr/people'              => 'People index',
        '/hr/compliance'          => 'Compliance index',
        '/hr/compliance/training' => 'Training dashboard',
        '/hr/compliance/vetting'  => 'Vetting register',
        '/hr/compliance/drivers'  => 'Driver eligibility',
        '/hr/leave'               => 'Leave index',
        '/hr/leave/balances'      => 'Leave balances',
        '/hr/onboarding'          => 'Onboarding index',
        '/hr/performance'         => 'Performance index',
        '/hr/performance/reviews' => 'Performance reviews',
        '/hr/performance/cases'   => 'HR Cases index',
        '/hr/policies'            => 'Policies index',
        '/hr/documents'           => 'Documents index',
        '/hr/payroll'             => 'Payroll index',
        '/hr/reports'             => 'Reports index',
    ];

    // Simulate authenticated session for the admin user
    auth()->login($admin);

    foreach ($getRoutes as $uri => $label) {
        test("GET {$uri} ({$label}) returns 200", function () use ($uri, $admin) {
            try {
                $request = \Illuminate\Http\Request::create($uri, 'GET');
                $request->setUserResolver(fn () => $admin);

                // Use the kernel to handle the request
                $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
                $response = $kernel->handle($request);
                $status = $response->getStatusCode();
                $kernel->terminate($request, $response);

                if ($status !== 200) {
                    throw new \RuntimeException("Expected 200, got {$status}");
                }
                return true;
            } catch (\Throwable $e) {
                throw $e;
            }
        });
    }

    auth()->logout();
}

// ─────────────────────────────────────────
// 8. Model Relationship Existence
// ─────────────────────────────────────────
echo "\n--- 8. MODEL RELATIONSHIPS ---\n";

$relationshipChecks = [
    ['HrEmployeeProfile', \App\Domain\Hr\Models\HrEmployeeProfile::class, ['user', 'primarySite', 'documents', 'offer']],
    ['HrCandidate', \App\Domain\Hr\Models\HrCandidate::class, ['applications']],
    ['HrCase', \App\Domain\Hr\Models\HrCase::class, ['events', 'disciplinaryActions', 'subject', 'reportedBy', 'assignedTo']],
    ['HrPolicy', \App\Domain\Hr\Models\HrPolicy::class, ['versions', 'currentVersion', 'attestations']],
    ['HrLeaveRequest', \App\Domain\Hr\Models\HrLeaveRequest::class, ['user', 'reviewer']],
    ['HrOnboardingChecklist', \App\Domain\Hr\Models\HrOnboardingChecklist::class, ['tasks']],
    ['HrPayrollRun', \App\Domain\Hr\Models\HrPayrollRun::class, ['items']],
    ['HrPerformanceReview', \App\Domain\Hr\Models\HrPerformanceReview::class, ['employee', 'reviewer']],
    ['HrDisciplinaryAction', \App\Domain\Hr\Models\HrDisciplinaryAction::class, ['hrCase', 'employee', 'investigator']],
    ['HrSupervisionNote', \App\Domain\Hr\Models\HrSupervisionNote::class, ['employee', 'supervisor']],
];

foreach ($relationshipChecks as [$name, $class, $methods]) {
    foreach ($methods as $method) {
        test("Model {$name} has relationship: {$method}()", function () use ($class, $method) {
            return method_exists(new $class(), $method);
        });
    }
}

// ─────────────────────────────────────────
// 9. Frontend Page File Verification
// ─────────────────────────────────────────
echo "\n--- 9. FRONTEND PAGE FILES ---\n";

$expectedPages = [
    'resources/js/pages/hr/recruitment/index.tsx',
    'resources/js/pages/hr/employees/index.tsx',
    'resources/js/pages/hr/employees/show.tsx',
    'resources/js/pages/hr/employees/edit.tsx',
    'resources/js/pages/hr/compliance/index.tsx',
    'resources/js/pages/hr/compliance/matrix.tsx',
    'resources/js/pages/hr/compliance/staff-detail.tsx',
    'resources/js/pages/hr/training/index.tsx',
    'resources/js/pages/hr/vetting/index.tsx',
    'resources/js/pages/hr/vetting/show.tsx',
    'resources/js/pages/hr/drivers/index.tsx',
    'resources/js/pages/hr/leave/index.tsx',
    'resources/js/pages/hr/leave/balances.tsx',
    'resources/js/pages/hr/onboarding/index.tsx',
    'resources/js/pages/hr/onboarding/show.tsx',
    'resources/js/pages/hr/performance/index.tsx',
    'resources/js/pages/hr/performance/reviews.tsx',
    'resources/js/pages/hr/cases/index.tsx',
    'resources/js/pages/hr/cases/show.tsx',
    'resources/js/pages/hr/policies/index.tsx',
    'resources/js/pages/hr/policies/show.tsx',
    'resources/js/pages/hr/policies/attestations.tsx',
    'resources/js/pages/hr/documents/index.tsx',
    'resources/js/pages/hr/documents/templates.tsx',
    'resources/js/pages/hr/payroll/index.tsx',
    'resources/js/pages/hr/reports/index.tsx',
    'resources/js/pages/hr/reports/show.tsx',
    'resources/js/pages/hr/candidates/show.tsx',
    'resources/js/pages/hr/candidates/create.tsx',
    'resources/js/pages/hr/candidates/create-offer.tsx',
    'resources/js/pages/hr/my/index.tsx',
    'resources/js/pages/hr/my/leave.tsx',
    'resources/js/pages/hr/my/training.tsx',
    'resources/js/pages/hr/my/policies.tsx',
    'resources/js/pages/hr/my/profile.tsx',
];

foreach ($expectedPages as $page) {
    test("Frontend page: {$page}", function () use ($page) {
        return file_exists(__DIR__ . '/' . $page);
    });
}

// ─────────────────────────────────────────
// Summary
// ─────────────────────────────────────────
echo "\n========================================\n";
echo "  RESULTS: {$passed} passed, {$failed} failed\n";
echo "========================================\n";

if (!empty($errors)) {
    echo "\nFAILURES:\n";
    foreach ($errors as $err) {
        echo "  - {$err}\n";
    }
}

echo "\n";
exit($failed > 0 ? 1 : 0);
