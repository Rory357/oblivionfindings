<?php

namespace App\Domain\Referrals;

final class ReferralPermissions
{
    public const DEFINITIONS = [
        'viewAny' => 'View referrals within approved sites',
        'viewAssigned' => 'View assigned referrals and funding cases within approved sites',
        'viewAllSites' => 'Access referrals across approved active sites',
        'centralIntake' => 'Access the unallocated central intake queue',
        'restricted' => 'Access privacy-restricted referrals within permitted scope',
        'create' => 'Record phone and staff referrals',
        'edit' => 'Correct referral identity and general intake information',
        'assign' => 'Assign referral sites and accountable owners',
        'transition' => 'Change referral stages and holds',
        'close' => 'Close referrals with a recorded disposition',
        'reopen' => 'Reopen closed referrals with a dated next step',
        'contact' => 'Log contact attempts and successful conversations',
        'communications.manage' => 'Review and retry referral communications within current contact authority',
        'communications.send' => 'Send approved message templates by email or text within saved contact preferences',
        'assessment.view' => 'View assessment and safe-start evidence',
        'assessment.manage' => 'Review assessment, authority and readiness',
        'funding.view' => 'View funding cases and financial information',
        'funding.manage' => 'Assist with funding preparation and record external decisions',
        'funding.verify' => 'Independently verify external funding evidence',
        'proposals.manage' => 'Prepare versioned support proposals and person acceptance',
        'proposals.approve' => 'Independently approve support proposals within delegation',
        'tasks.manage' => 'Manage follow-ups and assessment appointments',
        'evidence.manage' => 'Upload and review referral evidence',
        'disclose' => 'Request or share information within recorded authority',
        'duplicates.manage' => 'Resolve potential referral and person identity matches',
        'convert' => 'Create or link a canonical person and prepare onboarding',
        'commence' => 'Approve scheduled support and confirm actual commencement',
        'reports.view' => 'View scoped referral reports',
        'reports.export' => 'Export permitted referral information and report measures',
        'settings.view' => 'View referral configuration and publication history',
        'settings.manage' => 'Draft referral configuration changes',
        'settings.publish' => 'Publish reviewed referral configuration',
        'settings.governance' => 'Approve changes that weaken referral safeguards',
    ];

    public const INDEPENDENT = [
        'referrals.funding.verify',
        'referrals.proposals.approve',
        'referrals.commence',
        'referrals.settings.governance',
    ];

    private const SETTINGS = ['referrals.settings.view', 'referrals.settings.manage', 'referrals.settings.publish', 'referrals.settings.governance'];

    private const COORDINATOR = ['viewAny', 'viewAssigned', 'create', 'edit', 'assign', 'transition', 'close', 'reopen', 'contact', 'communications.send',
        'tasks.manage', 'evidence.manage', 'assessment.view', 'assessment.manage', 'funding.view', 'funding.manage', 'proposals.manage', 'convert', 'reports.view'];

    private const FINANCE_REVIEWER = ['viewAny', 'funding.view', 'funding.verify', 'proposals.approve', 'reports.view'];

    /**
     * Suggested role presets (specification §10), applied by RbacSeeder and the deploy grant
     * migration. Independent keys are granted only where named here. Settings governance (approving
     * changes that loosen a safeguard) is held by the CEO, admin, provider manager and compliance lead
     * (Stephan, 21 Oct 2026); a holder also needs settings view and publish to act on it, and the
     * publisher must differ from the drafter. Support workers and portal roles get nothing.
     *
     * @return array<string, list<string>>
     */
    public static function rolePresets(): array
    {
        $all = array_column(self::definitions(), 'key');
        $serviceManager = array_values(array_diff($all, self::INDEPENDENT, self::SETTINGS));
        $prefixed = fn (array $suffixes): array => array_map(fn (string $suffix): string => 'referrals.'.$suffix, $suffixes);
        $governance = ['referrals.settings.view', 'referrals.settings.publish', 'referrals.settings.governance'];

        return [
            'admin' => [...$serviceManager, 'referrals.settings.manage', ...$governance],
            'ceo' => [...$serviceManager, ...$governance],
            'coo' => $serviceManager,
            'provider_manager' => [...$serviceManager, 'referrals.commence', 'referrals.proposals.approve', ...$governance],
            'compliance_lead' => $governance,
            'coordinator' => $prefixed(self::COORDINATOR),
            'finance' => $prefixed(self::FINANCE_REVIEWER),
            'auditor' => ['referrals.reports.view'],
        ];
    }

    public static function definitions(): array
    {
        $definitions = [];
        foreach (self::DEFINITIONS as $suffix => $description) {
            $definitions[] = ['key' => 'referrals.'.$suffix, 'description' => $description, 'group' => 'referrals', 'module' => 'Operations'];
        }

        return $definitions;
    }
}
