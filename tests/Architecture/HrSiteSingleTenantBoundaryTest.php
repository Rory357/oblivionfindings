<?php

it('keeps active HR and Site partition behavior at absolute zero', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));

    expect(hrSiteSingleTenantDebtSnapshot($root))->toHaveCount(0)
        ->and(hrSiteApprovedSingleTenantDebt())->toBe([])
        ->and(hrSiteLegacyStorageDeclarationDriftSnapshot($root))->toBe([]);
});

it('detects legacy storage values laundered back into an HR or Site query', function () {
    $violations = hrSiteScanSingleTenantSource(
        'app/Http/Controllers/Hr/InjectedLegacyRead.php',
        <<<'PHP'
            <?php
            $storageColumn = LegacyStorageContext::column();
            Employee::query()->where(LegacyStorageContext::attributes())->get();
            $storageAttributes = LegacyStorageContext::attributes();
            Employee::query()->where($storageAttributes)->get();
            PHP,
    );

    expect($violations)->toHaveKey('legacy_storage_read')
        ->and($violations['legacy_storage_read'])->toHaveCount(3);

    $aliasedAndDistant = <<<'PHP'
        <?php
        $storage = LegacyStorageContext::attributes();
        $criteria = $storage;
        PHP;
    $aliasedAndDistant .= str_repeat("// unrelated application work\n", 100);
    $aliasedAndDistant .= 'Employee::query()->where($criteria)->get();';

    expect(hrSiteScanSingleTenantSource(
        'app/Http/Controllers/Hr/InjectedDistantLegacyRead.php',
        $aliasedAndDistant,
    ))->toHaveKey('legacy_storage_read');

    expect(hrSiteScanSingleTenantSource(
        'app/Http/Controllers/Hr/InjectedOrganizationColumnRead.php',
        '<?php $column = LegacyStorageContext::organizationColumn();',
    ))->toHaveKey('legacy_storage_read');
});

it('allows only the fingerprinted model write compatibility helper', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $path = 'app/Models/Concerns/WritesLegacyStorageContext.php';
    $contents = file_get_contents($root.'/'.$path);

    expect($contents)->toBeString()
        ->and(hrSiteScanSingleTenantSource($path, $contents))->not->toHaveKey('legacy_storage_read')
        ->and(hrSiteScanSingleTenantSource(
            $path,
            str_replace('LegacyStorageContext::attributes()', 'LegacyStorageContext::id()', $contents),
        ))->toHaveKey('legacy_storage_read');
});

it('allows the exact HR Site and Calendar storage contracts only with the fingerprinted writer concern', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));

    foreach (hrSiteLegacyStorageModelFingerprints() as $relativePath => $expectedFingerprint) {
        $contents = file_get_contents($root.'/'.$relativePath);

        expect($contents)->toBeString()
            ->and(hrSiteLegacyStorageModelContractFingerprint($relativePath, $contents))->toBe($expectedFingerprint)
            ->and(hrSiteLegacyStorageModelContractFingerprint(
                $relativePath,
                str_replace('use App\\Models\\Concerns\\WritesLegacyStorageContext;', '', $contents),
            ))->toBeNull()
            ->and(hrSiteLegacyStorageModelContractFingerprint(
                $relativePath,
                str_replace("'tenant_id',", "'legacy_partition_id',", $contents),
            ))->toBeNull()
            ->and(hrSiteLegacyStorageModelContractFingerprint(
                $relativePath,
                $contents."\nprotected \$legacyPartition = ['tenant_id'];\n",
            ))->toBeNull();

        $violations = hrSiteScanSingleTenantSource($relativePath, $contents);
        $expectedActivePartitionOccurrences = hrSiteLegacyStorageModelTenantOccurrenceCounts()[$relativePath] - 1;
        if ($expectedActivePartitionOccurrences > 0) {
            expect($violations)->toHaveKey('partition_field')
                ->and($violations['partition_field'])->toHaveCount($expectedActivePartitionOccurrences);
        } else {
            expect($violations)->not->toHaveKey('partition_field');
        }
    }
});

it('detects injected HR partition laundering and tenant shaped contracts', function () {
    $violations = hrSiteScanSingleTenantSource(
        'app/Http/Controllers/Hr/InjectedController.php',
        <<<'PHP'
            <?php
            $tenantId = hrApplicationStorageContextId($request->user());
            assertHrOrganisationAccess($tenantId, organisationId: $request->integer('organization_id'));
            return Employee::forTenant($tenantId)->where('tenant_id', $tenantId)->get();
            $status = 'tenant_only';
            $permission = 'integrations.manage_tenant_secrets';
            PHP,
    );

    expect(array_keys($violations))
        ->toContain('partition_field')
        ->toContain('partition_parameter')
        ->toContain('tenant_runtime_identifier')
        ->toContain('tenant_permission_contract')
        ->toContain('tenant_query_or_bypass')
        ->toContain('hr_partition_laundering');
});

it('covers HR trees Site access dependencies Site UI integration seams and browser fixtures', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));

    expect(hrSiteSingleTenantScopedFiles($root))
        ->toContain($root.'/app/Http/Controllers/Hr/EmployeeProfileController.php')
        ->toContain($root.'/app/Http/Controllers/Api/HrApiController.php')
        ->toContain($root.'/app/Services/Tasks/Providers/HrCaseProvider.php')
        ->toContain($root.'/app/Http/Controllers/SiteController.php')
        ->toContain($root.'/app/Services/Sites/SiteTypePlanService.php')
        ->toContain($root.'/app/Domain/Hr/Models/HrAnnouncement.php')
        ->toContain($root.'/app/Models/SiteComplianceTemplate.php')
        ->toContain($root.'/app/Policies/SitePolicy.php')
        ->toContain($root.'/app/Domain/Finance/Http/Controllers/IrdFilingController.php')
        ->toContain($root.'/app/Services/UserSiteAccessService.php')
        ->toContain($root.'/app/Models/Site.php')
        ->toContain($root.'/app/Http/Controllers/Sites/SiteIntegrationController.php')
        ->toContain($root.'/resources/js/pages/sites/show.tsx')
        ->toContain($root.'/tests/Feature/Services/UserSiteAccessCanonicalIntegrityTest.php')
        ->toContain($root.'/tests/e2e/hr-live-gap-closeout.spec.ts');
});

it('allows only the exact Site storage declaration and still rejects active Site partition behavior', function () {
    $storageOnly = <<<'PHP'
        <?php
        class Site {
            protected $fillable = [
                'tenant_id',
            ];
        }
        PHP;
    $activeScope = $storageOnly."\npublic function scopeForTenant(Builder \$query, int \$tenantId): Builder {}";
    $duplicateStorage = $storageOnly."\nprotected \$legacy = ['tenant_id'];";

    expect(hrSiteScanSingleTenantSource('app/Models/Site.php', $storageOnly))
        ->not->toHaveKey('partition_field')
        ->and(hrSiteScanSingleTenantSource('app/Models/NewSite.php', $storageOnly))
        ->toHaveKey('partition_field')
        ->and(hrSiteScanSingleTenantSource('app/Models/Site.php', $activeScope))
        ->toHaveKey('tenant_query_or_bypass')
        ->and(hrSiteScanSingleTenantSource('app/Models/Site.php', $duplicateStorage))
        ->toHaveKey('partition_field');
});

/** @return list<string> */
function hrSiteSingleTenantDebtSnapshot(string $root): array
{
    $snapshot = [];

    foreach (hrSiteSingleTenantScopedFiles($root) as $absolutePath) {
        $relativePath = ltrim(substr($absolutePath, strlen($root)), '/');
        $contents = file_get_contents($absolutePath);
        if (! is_string($contents)) {
            throw new RuntimeException("Unable to read scoped HR/Site boundary file {$relativePath}.");
        }

        foreach (hrSiteScanSingleTenantSource($relativePath, $contents) as $rule => $matches) {
            $snapshot[] = implode('|', [
                $relativePath,
                $rule,
                count($matches),
                substr(hash('sha256', json_encode($matches, JSON_THROW_ON_ERROR)), 0, 16),
            ]);
        }
    }

    sort($snapshot, SORT_STRING);

    return $snapshot;
}

/** @return array<string, list<string>> */
function hrSiteScanSingleTenantSource(string $relativePath, string $contents): array
{
    $patterns = [
        'partition_field' => '/\b(?:tenant_id|organization_id|organisation_id)\b/iu',
        'partition_parameter' => '/\b(?:tenantId|organizationId|organisationId)\b/u',
        'tenant_product_word' => '/(?<![A-Za-z0-9_])tenants?(?:[\'’]s)?(?![A-Za-z0-9_])/iu',
        'tenant_runtime_identifier' => '/(?<![A-Za-z0-9])tenant_(?!id\b)[A-Za-z0-9_]+|\b(?:[A-Za-z][A-Za-z0-9]*Tenant[A-Za-z0-9]*|tenant[A-Z][A-Za-z0-9]*)\b/u',
        'tenant_permission_contract' => '/\bintegrations\.manage_tenant_secrets\b|\bmanageTenantSecrets\b/u',
        'tenant_query_or_bypass' => '/\b(?:scopeForTenant(?:OrSystem)?|forTenant(?:OrSystem)?|[A-Za-z0-9_]+ForTenant|can(?:Skip|View)[A-Za-z0-9_]*Tenant[A-Za-z0-9_]*)\b/u',
        'hr_partition_laundering' => '/\b(?:hrApplicationStorageContextId|assertHrOrganisationAccess|applicationRecipientRule)\b/u',
        'legacy_storage_read' => '/\bLegacyStorageContext::(?:column|organizationColumn|id|attributes)\s*\(/u',
    ];
    $violations = [];

    foreach ($patterns as $rule => $pattern) {
        preg_match_all($pattern, $contents, $rawMatches, PREG_OFFSET_CAPTURE);
        $matches = [];

        foreach ($rawMatches[0] ?? [] as [$token, $offset]) {
            if ($rule === 'partition_field'
                && (hrSiteIsAllowedSiteStorageDeclaration($relativePath, $contents, (int) $offset)
                    || hrSiteIsAllowedLegacyStorageModelDeclaration($relativePath, $contents, (int) $offset)
                    || hrSiteIsAllowedLegacyOrganizationStorageWriter($relativePath, $contents, (int) $offset))
            ) {
                continue;
            }

            if ($rule === 'legacy_storage_read'
                && hrSiteIsAllowedLegacyStorageWriter($relativePath, $contents)
            ) {
                continue;
            }

            if ($rule === 'tenant_runtime_identifier'
                && hrSiteIsAllowedCompatibilityIdentifierOccurrence($relativePath, $contents, (int) $offset)
            ) {
                continue;
            }

            $matches[] = hrSiteNormalizedSingleTenantContext($contents, (int) $offset, (string) $token);
        }

        if ($matches !== []) {
            sort($matches, SORT_STRING);
            $violations[$rule] = $matches;
        }
    }

    ksort($violations, SORT_STRING);

    return $violations;
}

function hrSiteIsAllowedCompatibilityIdentifierOccurrence(string $relativePath, string $contents, int $offset): bool
{
    $lineStart = strrpos(substr($contents, 0, $offset), "\n");
    $lineStart = $lineStart === false ? 0 : $lineStart + 1;
    $lineEnd = strpos($contents, "\n", $offset);
    $line = trim(substr($contents, $lineStart, $lineEnd === false ? null : $lineEnd - $lineStart));

    return $relativePath === 'app/Models/Integration/IntegrationProviderConnection.php'
        && $line === "protected \$table = 'integration_tenant_secrets';";
}

function hrSiteIsAllowedSiteStorageDeclaration(string $relativePath, string $contents, int $offset): bool
{
    if ($relativePath !== 'app/Models/Site.php') {
        return false;
    }

    $lineStart = strrpos(substr($contents, 0, $offset), "\n");
    $lineStart = $lineStart === false ? 0 : $lineStart + 1;
    $lineEnd = strpos($contents, "\n", $offset);
    $line = trim(substr($contents, $lineStart, $lineEnd === false ? null : $lineEnd - $lineStart));
    $prefix = substr($contents, 0, $offset);
    $fillable = strrpos($prefix, 'protected $fillable = [');
    $close = strrpos($prefix, '];');

    return substr_count($contents, 'tenant_id') === 1
        && hash_equals('2fa734176cb7052d2a0bd191536723623fb2634ff710e2a10118b9334e2e2421', hash('sha256', $line))
        && $fillable !== false
        && ($close === false || $fillable > $close);
}

function hrSiteIsAllowedLegacyOrganizationStorageWriter(string $relativePath, string $contents, int $offset): bool
{
    if ($relativePath !== 'app/Models/Concerns/WritesLegacyOrganizationStorageContext.php'
        || substr_count($contents, 'organization_id') !== 1
        || ! hash_equals(
            '265b76912c184888fc137988ccd30ba72d43ee89eb7167ba5f6fdcd31ea99118',
            hash('sha256', $contents),
        )
    ) {
        return false;
    }

    return $offset === strpos($contents, 'organization_id');
}

function hrSiteIsAllowedLegacyStorageWriter(string $relativePath, string $contents): bool
{
    return $relativePath === 'app/Models/Concerns/WritesLegacyStorageContext.php'
        && hash_equals(
            '2812597f6a8ab6880cb60678a9d94bd3c22beaa07c6bc545ffeafc27c947800e',
            hash('sha256', $contents),
        );
}

/** @return array<string, string> */
function hrSiteLegacyStorageModelFingerprints(): array
{
    return [
        'app/Domain/Hr/Models/HrApplication.php' => '53e14205d1699e21cb73b0b9be86fdd67de7d364f173b5e456f7382991b678b6',
        'app/Domain/Hr/Models/HrAnnouncement.php' => '5b7f522f110fae31d09d318d7ce063d3873863ea5324ce8c5acfa1ec210ed754',
        'app/Domain/Hr/Models/HrAutomationRule.php' => '876a7ab6a18e9790a87d3ff344156080dc042e2e9fb6e1885ca11c398fe8cf6e',
        'app/Domain/Hr/Models/HrAutomationRun.php' => '275f867a003dd2881c24c329c1c6d1a2ec22ff9afdb52af70ad8f95db505822e',
        'app/Domain/Hr/Models/HrAttendanceSession.php' => '3fad72bc6b59e3bf655710044a9a2786d0d5ab62a15f914cbbf81d9fe94a9050',
        'app/Domain/Hr/Models/HrApprovalChain.php' => '16c8da654a625943c0b4d30b9f0e77963212fbc249a5def6a92cba14d09abd9e',
        'app/Domain/Hr/Models/HrApprovalInstance.php' => '3ef8e18b7bef2ec4a794b44bbd3753472cb1a15fd520200a790209b9f1de05ed',
        'app/Domain/Hr/Models/HrBenefitEnrollment.php' => 'bdc919eb56ed2150263053e3b19c1a220b6bff0b07e413e83095bb6d88c578da',
        'app/Domain/Hr/Models/HrBenefitPlan.php' => 'e71c46592ebdd22c3fbbac3956d1745e515cd0eb7d70aeea6291219a581c7cd8',
        'app/Domain/Hr/Models/HrBonusPayment.php' => '8bc630c3e53f4f12e6ee570e07d6b55ec93bb894a73b56fb2cdc8af4c972e755',
        'app/Domain/Hr/Models/HrCalendarEvent.php' => '3f6e96c27f5b41fe2473339dbfb7e8cefb63b7192ef7b615cadc4ea8e11ac1bd',
        'app/Domain/Hr/Models/HrCalendarEventAttachment.php' => 'f907e9caa1b78175d3b199fe48dcde7a8e418549472fc7c6ec8339f90742fb0e',
        'app/Domain/Hr/Models/HrCalendarEventCategory.php' => 'a9646c006da6c793cc7c7ddc92c5ebe865dc48ed5bf772d347df4dcadbe6eeee',
        'app/Domain/Hr/Models/HrCandidate.php' => '0ae49a8a5552c9fa8656a861e346c3733e2333bff11f3edcfcb1a5ec7f889fec',
        'app/Domain/Hr/Models/HrCandidateDocument.php' => 'd212c7e764f6f506ee572993f105d92ae5e52402998420242730a7e2a72669df',
        'app/Domain/Hr/Models/HrCandidateEmailTemplate.php' => '4510cd6d36f892f5417541b99dbcef8fad1f8518444dd4e656a5298b4fbac019',
        'app/Domain/Hr/Models/HrCase.php' => '3649fbb49773f35656cc112238dba65aced23772681e741d34eec36004d25234',
        'app/Domain/Hr/Models/HrCompensationHistory.php' => '70f7297cdcc2193115389fe25272bc109bb548db7db4f47efe5a4a1d2f661e9e',
        'app/Domain/Hr/Models/HrCompensationReview.php' => '4e4c79f2b40a8a630d7320e99eb6085a552f1ba7b8faa712d46dcc4a795463a8',
        'app/Domain/Hr/Models/HrCompetency.php' => 'fe9ae24d4b9f449bd7f9f3f80985d92078150185d73d396eea9866ce6e94ee0b',
        'app/Domain/Hr/Models/HrCompetencyAssessment.php' => '961acf83e876ce98860e18a157ce0ff78dea0651a7d6c606b1d5464f8c98f895',
        'app/Domain/Hr/Models/HrCourse.php' => '489f1cdea089239f6226b924fd64f1b8921899a384e22b157a2caca3815b7e7a',
        'app/Domain/Hr/Models/HrCourseAssignment.php' => '63841e8b062a325d73261ab0fa8c555655bd87a649ba3c4e994808e80df82135',
        'app/Domain/Hr/Models/HrCourseEnrollment.php' => '5724e9609bde6c04972d84f8ab7560ebb2b33b70243a1823172c615ed1013582',
        'app/Domain/Hr/Models/HrCourseSession.php' => 'b856120a13056baef91cc77af2e42bfa5b5ff77a62350cdfd65219b0867bc1cb',
        'app/Domain/Hr/Models/HrCustomFieldDefinition.php' => '954b96c1cab04702ff869fc92886c515bae582a01af6dda7da92c1ff3d0304bf',
        'app/Domain/Hr/Models/HrDevelopmentGoal.php' => '40138bb736a44bf278565fdb9f746ce0ccb405ba7d7ce009f15db99ae953380d',
        'app/Domain/Hr/Models/HrDisciplinaryAction.php' => '02b385b0e672674633955d94c21de11ca2ceeb93af94b0200dc6fdb8dda8e0c7',
        'app/Domain/Hr/Models/HrDriverEligibility.php' => '0a96254cb311affab525bd3a648894f978c4315422da401896b96ac8459017d1',
        'app/Domain/Hr/Models/HrDocument.php' => 'd71f09726abfe23b718950ae32daaca16d1a2127a7cb34ea9661c3d1d1d9b610',
        'app/Domain/Hr/Models/HrDocumentSignature.php' => 'fda5ac0e5e7f140b919add5498021845050ba66690470964e856548c7ec42ba5',
        'app/Domain/Hr/Models/HrDocumentTemplate.php' => '776a0c7b4c642b92b234be9b61d0308daedbdf100069d5220e687157da6d101c',
        'app/Domain/Hr/Models/HrEmployeeSkill.php' => '859094988602f6d5d0758ef71dbd09cb1becf89da0d174c0a51ecf1bca36ef33',
        'app/Domain/Hr/Models/HrEngagementActionPlan.php' => 'dc2935bfc9f54e6febfa23ec6e52e56b97ba10344533fcc2ce7cd8c8f7d5dc8a',
        'app/Domain/Hr/Models/HrEngagementSurvey.php' => '908b4b0ba33f6b92e931428c36b46bba63b9165bb9151f7c7e4746d2e5af7dba',
        'app/Domain/Hr/Models/HrEapReferral.php' => 'a98e8c661da687e6acda18fe3180c1ed9a95767717c4b00801fa7fbe64d79743',
        'app/Domain/Hr/Models/HrExpenseClaim.php' => '94bbe21850df7c73cfe341d02e4e4ee5cdc1082a4392e1bb644a5fc1582aaa76',
        'app/Domain/Hr/Models/HrFeedAttachment.php' => 'b6b55650f017e69fd51906368eb0ec961370f74369d743c29530b320ea1d766c',
        'app/Domain/Hr/Models/HrFeedPost.php' => '46b014ec0dfcde6ebf8eb3b8772db7a84fd3fa3320265a7d1e4b305cebab7c1b',
        'app/Domain/Hr/Models/HrFeedReaction.php' => 'e7252e038529308e8dbcedc2fdf19f03f9d07eb54c1a6639b6fe8264f58805da',
        'app/Domain/Hr/Models/HrFeedReply.php' => '37dbeeda9120c9727e498ae3b97525e6b03b7c59360b8fa80e759c3c237af11d',
        'app/Domain/Hr/Models/HrFeedbackRequest.php' => '0225bd9277d1f4e212cef53fb64f63e27def3df6b7993486ddaebb68af1f7412',
        'app/Domain/Hr/Models/HrFeedbackTemplate.php' => 'acf8eb95bee25a5681c18ea583a85b8fd7fed43468898d856db4b26ae4a79211',
        'app/Domain/Hr/Models/HrGoal.php' => '2efe1153d54abbf27053ca35e873defdc0e00d71742fa1efa27ef00a73ba64b2',
        'app/Domain/Hr/Models/HrGoalCycle.php' => '28221d6c125a2e1ecd933240c0cf1d26741eb43916dc0a4435cb4d5f7f9132b2',
        'app/Domain/Hr/Models/HrGoalTemplate.php' => 'af39a3153d767700c9d9f52a45a9da9bb2cedf1fbc08a5085717533395d5f381',
        'app/Domain/Hr/Models/HrJobRequisition.php' => 'a4036406044737cf7ff5ac3e870adf3c92b3339d972a29366df10e65fcb91f95',
        'app/Domain/Hr/Models/HrInterviewKit.php' => 'cc7642a54573391fed490a9f72626efa9704a69e8fa06fd8c38375c24810ca47',
        'app/Domain/Hr/Models/HrKeyResult.php' => '613b035784d89780a718fc8f926612a81a500a44952e7de11333e7a626228d8c',
        'app/Domain/Hr/Models/HrKudos.php' => '81ad9114db4bed3d10cf83d605927918e9f809fabeb62f5426f4aa3f8de143a4',
        'app/Domain/Hr/Models/HrKudosReaction.php' => '36da5a5d77ba2d27beb48ef530d9ed2e7e1daa67803775bd854f3295ffbb82c3',
        'app/Domain/Hr/Models/HrKudosReply.php' => 'd698d0fe70abfad669ef7841baa70d6e846170c4b171630ff209e2cb572fc4c0',
        'app/Domain/Hr/Models/HrLeaveApprovalChain.php' => '3d5eb604f2ef0b4bc4bce3c1a78e65dc9a719a0615912888e8c366332b93b313',
        'app/Domain/Hr/Models/HrLeaveBalance.php' => '7088fd8a6ee736fe98cd8a2759f0cda73ab902f3f0821db28e27c51ab08366f9',
        'app/Domain/Hr/Models/HrLeaveBalanceLedger.php' => '3dd41e4bf8a4755b3b13dfcc5b1fedda3d88e784478eea0efedea2046f13a96e',
        'app/Domain/Hr/Models/HrLeaveRequest.php' => '51ae5e73c339cfd45081afc1a31a5c50aa1a1f5afcd676e3a3b302da8beef5e0',
        'app/Domain/Hr/Models/HrOffboardingChecklist.php' => 'ce1334a19da3a3b4bd13da50cb9b24e0c7a2278d7853cb383fc009ce6dd41e20',
        'app/Domain/Hr/Models/HrOnboardingChecklist.php' => '6fe41fa987baa3d7fc7e319d8798e73cdfb7d5bcaffb3aa76618145f2f1580f5',
        'app/Domain/Hr/Models/HrOnboardingEmail.php' => 'a0bbf96a55c3c4bbfc3e47886ea03ea4634a10ccf08a3670bd1dcc5cd1f03434',
        'app/Domain/Hr/Models/HrOnboardingTemplate.php' => '6c12f7b0c0f5cb984a10ce1a24aa96d8e20837b38a9fb7ba167070439cc2886b',
        'app/Domain/Hr/Models/HrPayrollExportProfile.php' => 'd577b38ebd88286af7c2f0ef8838473b7e94785e42bf4d2876e8d2510dce6021',
        'app/Domain/Hr/Models/HrPayrollRun.php' => 'c6d5328a6e4c742592f42f03da5deb1c6a9850e57e6a6c972881d03f9d28f957',
        'app/Domain/Hr/Models/HrPayslip.php' => 'c93d0b167758c253af30c1b451158a496d323a87b3d0f3cd17801152acaedd08',
        'app/Domain/Hr/Models/HrPerformanceImprovementPlan.php' => '5765e122c851fa16eb838ae1ba06f36a5a10551546c9f9390db4e1c61b50d0e2',
        'app/Domain/Hr/Models/HrPerformanceReview.php' => '3a816fbde73bbf6684cf813ec381eb49648b42056ccec454b8cb7a0c49f27fac',
        'app/Domain/Hr/Models/HrPolicy.php' => '73577c0e6e96d18d0c01d4419cf101e36b064a4f6443ba529c3c5b7bc72d0445',
        'app/Domain/Hr/Models/HrPolicyAttestation.php' => '5168f2ac25ecc4f8aca7d0806563768d86f4e514f2c6382879f399aa2a8dca0a',
        'app/Domain/Hr/Models/HrPosition.php' => '567393aa932cbfd7e869eeb86656b89e3ba705ae28e7cdd55d7048be0c1f2c15',
        'app/Domain/Hr/Models/HrProbationReview.php' => '0cbf329bd7ecb350521cedb4581bad984fe817e9111c20f6de193bec60eadd3f',
        'app/Domain/Hr/Models/HrPublicHoliday.php' => '465aa49f47d9ed791c11116fde842a8570008700eeb47e9212c1e875222b5bf6',
        'app/Domain/Hr/Models/HrReportExport.php' => '5ea6bc598a0ddd9678d8ed300564966f84863c7645b161a6491335200f4c161b',
        'app/Domain/Hr/Models/HrReportSubscription.php' => 'aef2dca39ac7a28d886228cab5cab9cb72c7f10a4e49200bf73d026fb736e939',
        'app/Domain/Hr/Models/HrReviewGoal.php' => 'a49c1ecfcb7d9f58811aa57fb277e49f169a936e5308c7281d79cc3a98e6a8a0',
        'app/Domain/Hr/Models/HrSalaryBand.php' => '6ab4339ae2ac140691159e7d9915e69338147c5751c4f6d1368774be7074f73a',
        'app/Domain/Hr/Models/HrSkill.php' => '33414c4d77dc972b4d5aae04960f761296a525da5d4dee8258309cfd5a269ef0',
        'app/Domain/Hr/Models/HrStaffComplianceStatus.php' => '8c972f3c19e3a34607830becbfb894090e4a87db19a100b06424cc3a7cb8e126',
        'app/Domain/Hr/Models/HrSupervisionNote.php' => 'f5d64c22f892fa87f4d2d4539d063cc8003f796e337dcd69bc4dd6a0388be6d0',
        'app/Domain/Hr/Models/HrTalentPool.php' => 'a565dcc032d3dcf224f8303c3b96b58be61940931dff98ea5a1a63de9fff6f8a',
        'app/Domain/Hr/Models/HrTimeEntry.php' => '1677eb6300b4c161bbfa550b50441cd4b39bc1e530ef5f037704ad7676df9b4d',
        'app/Domain/Hr/Models/HrTimeEntryAmendment.php' => '51e89cb826ce14ffdd4e56b2ce6d0757d7e7e0118341fe4dc3a2b28106570a10',
        'app/Domain/Hr/Models/HrWellbeingCheckin.php' => '3aca52ff87c88d5a78e30e6b30e8d4c18f69d6aa428feb2b7424140fd8ef3a35',
        'app/Domain/Hr/Models/HrWellbeingFlagAction.php' => '32b1cb937ba3605a82fc422d40c876a6d4abea538bfc38294be0cd7277ade5e7',
        'app/Domain/Hr/Models/HrWellbeingIndicator.php' => '52361d14d02542f5ef5152407e45ffd553f62d1b8ef09e6704e89d0360e07464',
        'app/Domain/Hr/Models/HrWebhookDelivery.php' => '54cabd20f45d0920471c724151e1cf11b7e28b24920b462a78ab80cbfb6c496f',
        'app/Domain/Hr/Models/HrWebhookEndpoint.php' => 'ac60aa6afa0bab1a149e2ba2ee4cf5d67e714a97ab80c2ce794ea3d90594399f',
        'app/Models/CalendarSyncBusyBlock.php' => '999c28eea57564704f5de15e1cdafefd674a662ee311110776359ce63433921b',
        'app/Models/CalendarSyncConnection.php' => '5dd1cf87ff63aa04bc9f9e111f51c99e1237bbf02c7a6add4cdc855880584632',
        'app/Models/CalendarSyncEventLink.php' => 'bcfa7d0dd22ce1fb639b93dcc9f1f43eda20474d19b9b71c9979a45c6dc567dc',
        'app/Models/CalendarSyncMapping.php' => '54e9a8d1ab120dcc3d6a3b94f1afbcbc1085b42e16c9d9105d58a9d8dc8dd52b',
        'app/Models/CredentialType.php' => '2bf10a03a30bcc5ab3c2736d7e1dc56783ad154374d34c6ddea48f085d13a865',
        'app/Models/LocationHardware.php' => '33a71d7de7e0ff35fad5cc50848c23a188c472d35e004ef4c4070f701ccef980',
        'app/Models/SiteContact.php' => 'bcadf762bcce952fc6d26a79038a6c29a6e82b60263886a35e2d8913d4038f5b',
        'app/Models/SiteCredential.php' => 'a2116ff359dedac8c1fe12998203ea33ba4a203055949850de8a61a77d25e1d3',
        'app/Models/SiteCredentialAuditLog.php' => '83d476f6d36a0f7675e65a0123883515df1ca8365db98d878bc5821db5d19a35',
        'app/Models/SiteFacilityZone.php' => '2fdc3bf263ad9853fcbb727cd66fdf30e714ad20c0af9b21ffc8c27f11f79be6',
        'app/Models/SiteHouseRoom.php' => '99a67c557c944ac5d80cf646a89a01217d1bfbd52a5b6f4078621ffa8d16d8f4',
        'app/Models/SiteHouseRoomHistory.php' => 'bcc2f84e74f70ce4c3fd51e91560930f3aeb07308a4b8b6135b79af7b7de9ce6',
        'app/Models/SiteHoResource.php' => 'd23cfe2790cec743619610ff8c520d1d6c42527e9785efda4c594a02a4513bf3',
        'app/Models/SiteDocument.php' => 'dd29562403fe9e1815f5fe2a7bc1dc515e14837536db3962be96eb69a9bcba71',
        'app/Models/SiteRoom.php' => 'f0f547ba80e863e31b4c77c83879c11f1f7c62dd2e64cdd8efef9929fff73146',
        'app/Models/SiteTypePlan.php' => '64d098a0f6bab5485f4c4bc2402dfb0472598ebc115808fd5ed7cd8e2c65c232',
        'app/Models/SiteTypePlanPin.php' => '48fcb9235432afe13510521f7cf2ed1c4ac5ab282cb5bffe5676893ea1e6ab2a',
        'app/Models/SiteVendor.php' => 'af250df44badf545ead4a2abbcc325ff764a961f5298a1600ba9267b57938a5d',
        'app/Models/StaffTimeOff.php' => '72c10304ff2f42b22115c72083f12b586ddc97c23805d90487c249005b79e1ce',
    ];
}

/** @return array<string, int> */
function hrSiteLegacyStorageModelTenantOccurrenceCounts(): array
{
    return array_fill_keys(array_keys(hrSiteLegacyStorageModelFingerprints()), 1);
}

function hrSiteLegacyStorageModelContractFingerprint(string $relativePath, string $contents): ?string
{
    if (! array_key_exists($relativePath, hrSiteLegacyStorageModelFingerprints())) {
        return null;
    }

    preg_match_all('/\btenant_id\b/u', $contents, $matches, PREG_OFFSET_CAPTURE);
    $occurrences = $matches[0] ?? [];
    $expectedOccurrenceCount = hrSiteLegacyStorageModelTenantOccurrenceCounts()[$relativePath] ?? null;
    if ($expectedOccurrenceCount === null || count($occurrences) !== $expectedOccurrenceCount) {
        return null;
    }

    $storageOccurrences = array_values(array_filter(
        $occurrences,
        function (array $occurrence) use ($contents): bool {
            $offset = (int) $occurrence[1];
            $lineStart = strrpos(substr($contents, 0, $offset), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;
            $lineEnd = strpos($contents, "\n", $offset);

            return trim(substr($contents, $lineStart, $lineEnd === false ? null : $lineEnd - $lineStart))
                === "'tenant_id',";
        },
    ));
    if (count($storageOccurrences) !== 1) {
        return null;
    }

    $offset = (int) $storageOccurrences[0][1];
    $lineStart = strrpos(substr($contents, 0, $offset), "\n");
    $lineStart = $lineStart === false ? 0 : $lineStart + 1;
    $lineEnd = strpos($contents, "\n", $offset);
    $storageLine = trim(substr($contents, $lineStart, $lineEnd === false ? null : $lineEnd - $lineStart));
    if ($storageLine !== "'tenant_id',"
        || substr_count($contents, 'use App\\Models\\Concerns\\WritesLegacyStorageContext;') !== 1
    ) {
        return null;
    }

    $classOffset = strpos($contents, 'class ');
    if ($classOffset === false) {
        return null;
    }

    $classBody = substr($contents, $classOffset);
    if (preg_match('/^\s*use\s+([^;]*\bWritesLegacyStorageContext\b[^;]*);/mu', $classBody, $traitMatch) !== 1) {
        return null;
    }

    $traitStatement = preg_replace('/\s+/u', '', (string) $traitMatch[0]);
    if (! is_string($traitStatement)) {
        return null;
    }

    return hash('sha256', implode('|', [
        $relativePath,
        $storageLine,
        'use App\\Models\\Concerns\\WritesLegacyStorageContext;',
        $traitStatement,
    ]));
}

function hrSiteIsAllowedLegacyStorageModelDeclaration(string $relativePath, string $contents, int $offset): bool
{
    $expectedFingerprint = hrSiteLegacyStorageModelFingerprints()[$relativePath] ?? null;
    if ($expectedFingerprint === null) {
        return false;
    }

    preg_match_all('/\btenant_id\b/u', $contents, $matches, PREG_OFFSET_CAPTURE);
    $occurrences = $matches[0] ?? [];
    $expectedOccurrenceCount = hrSiteLegacyStorageModelTenantOccurrenceCounts()[$relativePath] ?? null;
    $storageOffsets = array_values(array_map(
        fn (array $occurrence): int => (int) $occurrence[1],
        array_filter(
            $occurrences,
            function (array $occurrence) use ($contents): bool {
                $occurrenceOffset = (int) $occurrence[1];
                $lineStart = strrpos(substr($contents, 0, $occurrenceOffset), "\n");
                $lineStart = $lineStart === false ? 0 : $lineStart + 1;
                $lineEnd = strpos($contents, "\n", $occurrenceOffset);

                return trim(substr($contents, $lineStart, $lineEnd === false ? null : $lineEnd - $lineStart))
                    === "'tenant_id',";
            },
        ),
    ));

    return $expectedOccurrenceCount !== null
        && count($occurrences) === $expectedOccurrenceCount
        && $storageOffsets === [$offset]
        && hash_equals(
            $expectedFingerprint,
            hrSiteLegacyStorageModelContractFingerprint($relativePath, $contents) ?? '',
        );
}

function hrSiteNormalizedSingleTenantContext(string $contents, int $offset, string $token): string
{
    $start = max(0, $offset - 240);
    $length = min(strlen($contents) - $start, strlen($token) + 480);
    $context = substr($contents, $start, $length);
    $normalized = preg_replace('/\s+/u', '', $context);

    return strtolower($token).'@'.substr(hash('sha256', is_string($normalized) ? $normalized : $token), 0, 24);
}

/** @return list<string> */
function hrSiteSingleTenantScopedFiles(string $root): array
{
    $files = [];

    foreach ([
        'app/Http/Controllers/Hr',
        'app/Http/Requests/Hr',
        'app/Http/Controllers/Sites',
        'app/Services/Sites',
        'app/Models',
        'app/Policies',
        'resources/js/pages/hr',
        'resources/js/pages/sites',
        'resources/js/components/sites',
        'tests/Feature/Hr',
        'tests/Feature/Sites',
        'tests/Unit/Hr',
        'tests/e2e',
    ] as $directory) {
        $files = [...$files, ...hrSiteRecursiveSingleTenantFiles($root.'/'.$directory)];
    }

    $files = [...$files, ...hrSiteRecursiveSingleTenantFiles($root.'/app/Domain/Hr')];

    foreach ([
        'app/Domain/Finance/Http/Controllers/IrdFilingController.php',
        'app/Http/Controllers/SiteController.php',
        'app/Http/Controllers/Api/HrApiController.php',
        'app/Models/Site.php',
        'app/Services/Tasks/Providers/HrCaseProvider.php',
        'app/Services/UserSiteAccessService.php',
        'tests/Feature/Services/UserSiteAccessCanonicalIntegrityTest.php',
    ] as $relativePath) {
        $absolutePath = $root.'/'.$relativePath;
        if (is_file($absolutePath)) {
            $files[] = $absolutePath;
        }
    }

    $files = array_values(array_unique($files));
    sort($files, SORT_STRING);

    return $files;
}

/** @return list<string> */
function hrSiteRecursiveSingleTenantFiles(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && in_array(strtolower($file->getExtension()), ['php', 'ts', 'tsx'], true)) {
            $files[] = str_replace('\\', '/', $file->getPathname());
        }
    }

    return $files;
}

/** @return list<string> */
function hrSiteLegacyStorageDeclarationDriftSnapshot(string $root): array
{
    $drift = [];
    $sitePath = 'app/Models/Site.php';
    $siteContents = file_get_contents($root.'/'.$sitePath);
    if (! is_string($siteContents)) {
        $drift[] = $sitePath.'|missing';
    } else {
        preg_match_all('/\btenant_id\b/u', $siteContents, $matches, PREG_OFFSET_CAPTURE);
        $occurrences = $matches[0] ?? [];
        $allowed = array_filter(
            $occurrences,
            fn (array $match): bool => hrSiteIsAllowedSiteStorageDeclaration(
                $sitePath,
                $siteContents,
                (int) $match[1],
            ),
        );

        if (count($occurrences) !== 1 || count($allowed) !== 1) {
            $drift[] = $sitePath.'|expected_one_fillable_declaration|'.count($occurrences).'|'.count($allowed);
        }
    }

    $helperPath = 'app/Support/LegacyStorageContext.php';
    $helperContents = file_get_contents($root.'/'.$helperPath);
    $expectedHelperHash = 'c8e014cf0dd3757f141e237211c908efb7b22efdb67b65fce957a9c6889e6ab6';
    if (! is_string($helperContents) || ! hash_equals($expectedHelperHash, hash('sha256', $helperContents))) {
        $drift[] = $helperPath.'|storage_helper_fingerprint_mismatch';
    }

    foreach (hrSiteLegacyStorageModelFingerprints() as $relativePath => $expectedFingerprint) {
        $contents = file_get_contents($root.'/'.$relativePath);
        $actualFingerprint = is_string($contents)
            ? hrSiteLegacyStorageModelContractFingerprint($relativePath, $contents)
            : null;
        if ($actualFingerprint === null || ! hash_equals($expectedFingerprint, $actualFingerprint)) {
            $drift[] = $relativePath.'|storage_model_contract_fingerprint_mismatch';
        }
    }

    sort($drift, SORT_STRING);

    return $drift;
}

/** @return list<string> */
function hrSiteApprovedSingleTenantDebt(): array
{
    return [];
}
