<?php

namespace App\Services\Medication\Alerts;

use App\Services\Medication\Connected\ConnectedCareSettings;

/**
 * The medication alerts Medication Settings › Alerts & access controls (eMAR
 * P11 v5 `data.ts` ALERTS): what each is, which recipient groups it offers,
 * which are on by default and which are decided (locked on).
 *
 * Stephan, 29 Sep 2026: the organisation sets who gets each alert (one set
 * for every house) and house managers add extra people for their own houses;
 * the routing he decided stays locked on. Medication Settings owns who is
 * told; Control Room keeps its queue (P11 Q3).
 *
 * An alert is offered only once something real raises it (`built`); a group
 * only once it can be resolved to people (`GROUPS_BUILT`). The rest stay
 * hidden until their package builds them (hide-unbuilt).
 */
final class MedicationAlertCatalogue
{
    public const ROSTERED = 'rostered';

    public const HOUSE_LEAD = 'houseLead';

    public const CLINICAL_LEAD = 'clinicalLead';

    public const PROVIDER_MANAGER = 'providerManager';

    public const STOCK_STAFF = 'stockStaff';

    public const OVERRIDE_GRANTERS = 'overrideGranters';

    public const EA_REVIEWERS = 'eaReviewers';

    /** B10 (EA-138, EA-142): people who manage protected backups at the house. */
    public const BACKUP_MANAGERS = 'backupManagers';

    /** B10 (EA-025): people who manage medicine orders at the house. */
    public const ORDER_MANAGERS = 'orderManagers';

    public const STAFF_MEMBER = 'staffMember';

    public const ON_CALL = 'onCall';

    /** @var array<string, array{label: string, description: string}> */
    public const GROUPS = [
        self::ROSTERED => ['label' => 'Everyone rostered on a covering shift', 'description' => 'Checked against the roster at the time of the alert'],
        self::HOUSE_LEAD => ['label' => 'House lead', 'description' => 'The lead for the house the alert is about'],
        self::CLINICAL_LEAD => ['label' => 'Clinical lead', 'description' => 'Clinical leads with access to the house'],
        self::PROVIDER_MANAGER => ['label' => 'Provider manager', 'description' => 'Provider managers with access to the house'],
        self::STOCK_STAFF => ['label' => 'People who update stock here', 'description' => 'Anyone with “update stock” at the house'],
        self::OVERRIDE_GRANTERS => ['label' => 'People who can grant witness overrides', 'description' => 'The new witness-override permission, at the house'],
        self::EA_REVIEWERS => ['label' => 'People who review emergency access here', 'description' => 'Anyone who reviews emergency access at the house'],
        self::STAFF_MEMBER => ['label' => 'The staff member', 'description' => 'The person the alert is about'],
        self::BACKUP_MANAGERS => ['label' => 'People who manage protected backups here', 'description' => 'Anyone who manages protected backups at the house'],
        self::ORDER_MANAGERS => ['label' => 'People who manage orders here', 'description' => 'Anyone who manages medicine orders at the house'],
        // P11 Q9: off on every alert until a manager turns it on.
        self::ON_CALL => ['label' => 'On-call person (from the roster)', 'description' => 'Whoever is the house’s on-call contact at the time'],
    ];

    /**
     * Groups that resolve to people today. The on-call person arrives with
     * on-call contacts (P11 B2 chunk 4); override granters with PIN-2;
     * emergency-access reviewers with B3 / P10.
     */
    public const GROUPS_BUILT = [
        self::ROSTERED,
        self::HOUSE_LEAD,
        // B2 chunk 4: the house's on-call contact (off on every alert by default, Q9).
        self::ON_CALL,
        self::CLINICAL_LEAD,
        self::PROVIDER_MANAGER,
        self::STOCK_STAFF,
        self::EA_REVIEWERS,
        self::STAFF_MEMBER,
        self::BACKUP_MANAGERS,
        self::ORDER_MANAGERS,
    ];

    public const OVERDUE = 'overdue';

    public const FOLLOW_UPS = 'followups';

    public const OVERRIDE = 'override';

    public const STOCK = 'stock';

    public const EXPIRY = 'expiry';

    public const REFUSALS = 'refusals';

    public const RENEWALS = 'renewals';

    public const ERRORS = 'errors';

    public const BREAKGLASS = 'breakglass';

    public const CD_DISCREPANCY = 'cdDiscrepancy';

    public const PRN_LIMIT = 'prnLimit';

    public const OUT_OF_STOCK = 'outOfStock';

    public const CD_CHECK = 'cdCheck';

    public const REVIEW_DUE = 'reviewDue';

    /** B10 (EA-138): offered only while the pharmacy bridge runs. */
    public const PHARMACY_ORDER = 'pharmacyOrder';

    /** B10 (EA-138, EA-142): offered only while protected backups run. */
    public const BACKUP_FAILED = 'backupFailed';

    /** B10 (EA-025): offered only while the outside prescriber portal runs. */
    public const PRESCRIBER_REQUEST = 'prescriberRequest';

    /**
     * Every alert in v5's order. `controlled`: always about a controlled
     * medicine (only people with controlled-medicine access are told);
     * other alerts are controlled when their medicine is. `follow_up`: Follow
     * up is proposed on by default (v5 FOLLOW_UP_DEFAULT).
     *
     * Not built (hidden): witness override requests arrive with PIN-2; the
     * emergency-access daily report keeps today's routing until B3 / P10
     * define who reviews emergency access (P11 B2 Q5).
     *
     * @var array<string, array{label: string, subline: string, groups: list<string>, default: list<string>, locked: list<string>, controlled: bool, follow_up: bool, until: string, built: bool}>
     */
    public const ALERTS = [
        self::OVERDUE => [
            'label' => 'Overdue doses',
            'subline' => 'A scheduled dose passes its late time with no outcome',
            'groups' => [self::ROSTERED, self::HOUSE_LEAD, self::ON_CALL, self::CLINICAL_LEAD, self::PROVIDER_MANAGER],
            'default' => [self::ROSTERED, self::HOUSE_LEAD],
            'locked' => [self::ROSTERED, self::HOUSE_LEAD],
            'controlled' => false,
            'follow_up' => true,
            'until' => 'Until every dose has an outcome',
            // P01 C6(f) finds the overdue doses and owns when they're dealt with.
            'built' => true,
        ],
        // P11 B2 Q6: only refusal follow-ups have a due time today; effect
        // checks, second-person confirmations and phone instructions join with
        // P08a. The subline says exactly what it covers.
        self::FOLLOW_UPS => [
            'label' => 'Follow-ups overdue',
            'subline' => 'Refusal follow-ups past their due time',
            'groups' => [self::ROSTERED, self::HOUSE_LEAD, self::ON_CALL, self::CLINICAL_LEAD],
            'default' => [self::ROSTERED, self::HOUSE_LEAD],
            'locked' => [self::ROSTERED, self::HOUSE_LEAD],
            'controlled' => false,
            'follow_up' => true,
            'until' => 'Until resolved',
            'built' => true,
        ],
        self::OVERRIDE => [
            'label' => 'Witness override requests',
            'subline' => 'Someone on shift asks for a controlled-drug witness override',
            'groups' => [self::OVERRIDE_GRANTERS, self::HOUSE_LEAD, self::ON_CALL, self::CLINICAL_LEAD, self::PROVIDER_MANAGER],
            'default' => [self::OVERRIDE_GRANTERS, self::HOUSE_LEAD],
            'locked' => [self::OVERRIDE_GRANTERS, self::HOUSE_LEAD],
            'controlled' => true,
            'follow_up' => true,
            'until' => 'Until a manager answers',
            'built' => false,
        ],
        self::STOCK => [
            'label' => 'Stock running low',
            'subline' => 'A medicine drops below its reorder level',
            'groups' => [self::HOUSE_LEAD, self::STOCK_STAFF, self::CLINICAL_LEAD, self::ROSTERED],
            'default' => [self::HOUSE_LEAD, self::STOCK_STAFF],
            'locked' => [],
            'controlled' => false,
            'follow_up' => false,
            'until' => 'Until restocked',
            'built' => true,
        ],
        self::EXPIRY => [
            'label' => 'Stock expiring',
            'subline' => '30 days before, urgent at 7 days',
            'groups' => [self::STOCK_STAFF, self::HOUSE_LEAD, self::CLINICAL_LEAD],
            'default' => [self::STOCK_STAFF],
            'locked' => [],
            'controlled' => false,
            'follow_up' => false,
            'until' => 'Until removed or replaced',
            'built' => true,
        ],
        self::REFUSALS => [
            'label' => 'Repeated refusals',
            'subline' => 'From the escalation setting in Rounds & timing',
            'groups' => [self::HOUSE_LEAD, self::CLINICAL_LEAD, self::PROVIDER_MANAGER, self::ROSTERED],
            'default' => [self::HOUSE_LEAD, self::CLINICAL_LEAD],
            'locked' => [],
            'controlled' => false,
            'follow_up' => false,
            'until' => 'Until someone acknowledges',
            'built' => true,
        ],
        self::RENEWALS => [
            'label' => 'Competency renewals due',
            'subline' => 'From the renewal reminder in Staff & PINs',
            'groups' => [self::STAFF_MEMBER, self::HOUSE_LEAD, self::CLINICAL_LEAD],
            'default' => [self::STAFF_MEMBER, self::HOUSE_LEAD],
            'locked' => [],
            'controlled' => false,
            'follow_up' => false,
            'until' => 'Until renewed',
            'built' => true,
        ],
        self::ERRORS => [
            'label' => 'Medication errors reported',
            'subline' => 'Someone records a medication error',
            'groups' => [self::HOUSE_LEAD, self::ON_CALL, self::CLINICAL_LEAD, self::PROVIDER_MANAGER],
            'default' => [self::HOUSE_LEAD, self::CLINICAL_LEAD],
            'locked' => [],
            'controlled' => false,
            'follow_up' => true,
            'until' => 'Until triaged',
            'built' => true,
        ],
        self::BREAKGLASS => [
            'label' => 'Emergency access used (daily report)',
            'subline' => 'A daily summary of emergency access grants',
            'groups' => [self::EA_REVIEWERS, self::PROVIDER_MANAGER, self::CLINICAL_LEAD],
            'default' => [self::EA_REVIEWERS],
            'locked' => [],
            'controlled' => false,
            'follow_up' => false,
            'until' => 'Until reviewed',
            'built' => true,
        ],
        // The 29 Sep audit's five: alerts that existed only as Control Room
        // signals or dashboard tiles (P11 v2 AUDIT §1).
        self::CD_DISCREPANCY => [
            'label' => 'Controlled-drug count doesn’t match',
            'subline' => 'A balance check or transfer finds a difference, or a loss is reported',
            'groups' => [self::HOUSE_LEAD, self::ON_CALL, self::CLINICAL_LEAD, self::PROVIDER_MANAGER],
            'default' => [self::HOUSE_LEAD, self::CLINICAL_LEAD],
            'locked' => [],
            'controlled' => true,
            'follow_up' => true,
            'until' => 'Until investigated',
            'built' => true,
        ],
        self::PRN_LIMIT => [
            'label' => 'As-needed dose over the limit',
            'subline' => 'An as-needed dose goes past the order’s daily limit',
            'groups' => [self::HOUSE_LEAD, self::CLINICAL_LEAD, self::ROSTERED],
            'default' => [self::HOUSE_LEAD, self::CLINICAL_LEAD],
            'locked' => [],
            'controlled' => false,
            'follow_up' => false,
            'until' => 'Until someone acknowledges',
            'built' => true,
        ],
        self::OUT_OF_STOCK => [
            'label' => 'Out of stock or expired stock',
            // B2 C1 review: run-out stock alerts whenever it reaches 0; expired
            // stock still also notifies through Control Room (its signal carries
            // order end dates too), so the row says so.
            'subline' => 'Stock at 0, or expired (Control Room still notifies on expiry)',
            'groups' => [self::STOCK_STAFF, self::HOUSE_LEAD, self::CLINICAL_LEAD],
            'default' => [self::STOCK_STAFF, self::HOUSE_LEAD],
            'locked' => [],
            'controlled' => false,
            'follow_up' => false,
            'until' => 'Until restocked or removed',
            'built' => true,
        ],
        self::CD_CHECK => [
            'label' => 'Controlled-drug balance check overdue',
            'subline' => 'No balance check for 7 days',
            'groups' => [self::HOUSE_LEAD, self::CLINICAL_LEAD],
            'default' => [self::HOUSE_LEAD],
            'locked' => [],
            'controlled' => true,
            'follow_up' => false,
            'until' => 'Until the check is done',
            'built' => true,
        ],
        self::REVIEW_DUE => [
            'label' => 'Medication review due',
            'subline' => 'Chart or medicine review within 7 days; INR within 3 days',
            'groups' => [self::CLINICAL_LEAD, self::HOUSE_LEAD],
            'default' => [self::CLINICAL_LEAD],
            'locked' => [],
            'controlled' => false,
            'follow_up' => false,
            'until' => 'Until reviewed',
            'built' => true,
        ],
        // B10 (EA-138): a connected pharmacy rejected an order, or a send's result
        // is unknown — stock can run out before anyone opens the order. Offered
        // only while the pharmacy bridge runs (Settings › Connected services).
        self::PHARMACY_ORDER => [
            'label' => 'Pharmacy order needs checking',
            'subline' => 'A connected pharmacy rejects an order, its send has an unknown result, or it answers after staff settled the order by hand',
            'groups' => [self::STOCK_STAFF, self::HOUSE_LEAD, self::CLINICAL_LEAD],
            'default' => [self::STOCK_STAFF, self::HOUSE_LEAD],
            'locked' => [],
            'controlled' => false,
            'follow_up' => false,
            'until' => 'Until the order is settled',
            'built' => true,
            'feature' => 'pharmacy_bridge',
        ],
        // B10 (EA-138, EA-142): a house's daily protected backup failed, has an
        // unknown send result, or stopped because whoever runs it lost access.
        self::BACKUP_FAILED => [
            'label' => 'Protected backup failed',
            'subline' => 'A house’s daily protected chart backup fails, its email result is unknown, or the schedule’s owner lost access',
            'groups' => [self::BACKUP_MANAGERS, self::HOUSE_LEAD, self::PROVIDER_MANAGER],
            'default' => [self::BACKUP_MANAGERS],
            'locked' => [],
            'controlled' => false,
            'follow_up' => false,
            'until' => 'Until a backup is delivered or the schedule is fixed',
            'built' => true,
            'feature' => 'protected_backups',
        ],
        // B10 (EA-025): an outside prescriber asked to start, change or stop a
        // medicine. The chart doesn't change until someone decides, so the
        // people who manage orders at the house are told.
        self::PRESCRIBER_REQUEST => [
            'label' => 'Prescriber request waiting',
            'subline' => 'An outside prescriber asks to start, change or stop a medicine through the portal',
            'groups' => [self::ORDER_MANAGERS, self::CLINICAL_LEAD, self::HOUSE_LEAD],
            'default' => [self::ORDER_MANAGERS],
            'locked' => [],
            'controlled' => false,
            'follow_up' => false,
            'until' => 'Until someone decides the request',
            'built' => true,
            'feature' => 'prescriber_portal',
        ],
    ];

    /** Channels that send today (email and push since P11 B2 chunk 2). */
    public const CHANNELS_BUILT = ['inapp', 'email', 'push'];

    /** @return array{label: string, subline: string, groups: list<string>, default: list<string>, locked: list<string>, controlled: bool, follow_up: bool, until: string, built: bool}|null */
    public static function get(string $key): ?array
    {
        return self::ALERTS[$key] ?? null;
    }

    /** @return list<string> Alerts something real raises today, in v5's order. */
    public static function built(): array
    {
        return array_keys(array_filter(self::ALERTS, fn (array $alert, string $key): bool => self::isBuilt($key), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Raised by something real today. A connected-care alert counts only
     * while its feature is switched on in Settings › Connected services —
     * otherwise nothing can raise it, so it isn't offered. (Switched on but
     * not configured still alerts: that is when a backup silently stops.)
     */
    public static function isBuilt(string $key): bool
    {
        $alert = self::ALERTS[$key] ?? null;
        if ($alert === null || $alert['built'] !== true) {
            return false;
        }

        return ! isset($alert['feature']) || app(ConnectedCareSettings::class)->switchedOn($alert['feature']);
    }

    /** @return list<string> The groups an alert offers that resolve to people today. */
    public static function offeredGroups(string $key): array
    {
        return array_values(array_filter(
            self::ALERTS[$key]['groups'] ?? [],
            fn (string $group): bool => in_array($group, self::GROUPS_BUILT, true),
        ));
    }

    public static function groupLabel(string $group): string
    {
        return self::GROUPS[$group]['label'] ?? $group;
    }
}
