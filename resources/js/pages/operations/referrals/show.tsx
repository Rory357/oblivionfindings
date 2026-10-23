import {
    EntityCard,
    EntityCardGrid,
    EntityChip,
    EntityContextMenu,
    EntityTable,
    initialsFromName,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterContacts,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageLayout,
    type PageHeaderRailItem,
} from '@/components/page';
import {
    TabSearchPalette,
    TierTwoTabs,
    useGroupedProfileSearchShortcut,
    type GroupedProfileNavGroup,
} from '@/components/page/grouped-profile-nav';
import { ReferralActivityDetail } from '@/components/referrals/activity-detail';
import {
    CaseActionDialog,
    type CaseAction,
} from '@/components/referrals/case-action-dialog';
import {
    IntakeReview,
    ReferralCaseOverview,
    coveragePercent,
    formatMoney,
    fundingHeadline,
    isOverdue,
    perPeriod,
    type OverviewHandlers,
} from '@/components/referrals/case-overview';
import { ChecklistPanel } from '@/components/referrals/checklist-panel';
import { CommunicationsPanel } from '@/components/referrals/communications-panel';
import { DocumentsPanel } from '@/components/referrals/documents-panel';
import { EnquiryMessage } from '@/components/referrals/enquiry-message';
import { FormAnswersPanel } from '@/components/referrals/form-answers-panel';
import { FundingPanel } from '@/components/referrals/funding-panel';
import { HandoverPanel } from '@/components/referrals/handover-panel';
import { IntakeCorrectionDialog } from '@/components/referrals/intake-correction-dialog';
import { PeopleContactsPanel } from '@/components/referrals/people-contacts';
import { ProposalsPanel } from '@/components/referrals/proposals-panel';
import { ReferralReviewDialog } from '@/components/referrals/review-dialog';
import { ServiceStartPanel } from '@/components/referrals/service-start-panel';
import { SettingsMigrationPanel } from '@/components/referrals/settings-migration-panel';
import { ReferralStageBadge } from '@/components/referrals/stage-badge';
import {
    label,
    referralHref,
    shownReference,
    type Activity,
    type Options,
    type Permissions,
    type ReferralProfileDetail,
    type WorkItem,
} from '@/components/referrals/types';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateTimeLong } from '@/lib/datetime';
import { Head } from '@inertiajs/react';
import {
    CalendarClock,
    Check,
    ClipboardCheck,
    ClipboardList,
    Eye,
    FileText,
    History,
    Inbox,
    LayoutDashboard,
    Mail,
    Pencil,
    Phone,
    Plus,
    RotateCcw,
    ShieldCheck,
    UserRound,
    UserRoundCheck,
    Users,
    Wallet,
    type LucideIcon,
} from 'lucide-react';
import { useCallback, useMemo, useState, type ReactNode } from 'react';

type Props = {
    referral: ReferralProfileDetail;
    permissions: Permissions;
    options: Options;
    selectedWork?: WorkItem | null;
    initialTab?: string;
    initialPlanAt?: string | null;
};

/** Every profile section keeps its original key so deep links and server tabs still resolve. */
type TabKey =
    | 'summary'
    | 'assessment'
    | 'people-contacts'
    | 'work'
    | 'funding'
    | 'proposals'
    | 'handover'
    | 'service-start'
    | 'documents'
    | 'communications'
    | 'activity'
    | 'configuration';

type Selection = {
    action: CaseAction;
    work?: WorkItem;
    closeReason?: string;
};

const when = (value: string | null) =>
    value ? formatDateTimeLong(value) : 'Not recorded';

function Fact({ title, children }: { title: string; children?: ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-caption text-muted-foreground">{title}</dt>
            <dd className="mt-1 text-sm break-words whitespace-pre-wrap">
                {children || (
                    <span className="text-muted-foreground">Not recorded</span>
                )}
            </dd>
        </div>
    );
}

const ACTIVITY_SUMMARY_KEYS = [
    'title',
    'summary',
    'note',
    'reason',
    'outcome',
    'next_action',
    'preference_deviation',
];
const activityTitle = (event: Activity) =>
    label(event.kind.replaceAll('.', '_'));
const activitySummary = (event: Activity) => {
    const key = ACTIVITY_SUMMARY_KEYS.find(
        (item) =>
            typeof event.details[item] === 'string' && event.details[item],
    );
    return key ? String(event.details[key]) : null;
};

export default function ReferralProfile({
    referral,
    permissions,
    options,
    selectedWork = null,
    initialTab = 'summary',
    initialPlanAt = null,
}: Props) {
    const [tab, setTab] = useState<string>(initialTab);
    const [paletteOpen, setPaletteOpen] = useState(false);
    const [correcting, setCorrecting] = useState(false);
    const [review, setReview] = useState<'authority' | 'assessment' | null>(
        null,
    );
    const [viewWork, setViewWork] = useState<WorkItem | null>(selectedWork);
    const [viewActivity, setViewActivity] = useState<Activity | null>(null);
    const [selection, setSelection] = useState<Selection | null>(
        initialPlanAt ? { action: 'work' } : null,
    );
    const workMenu = useEntityContextMenu<WorkItem>();
    const activityMenu = useEntityContextMenu<Activity>();
    const authority = referral.authority?.review as
        | Record<string, unknown>
        | undefined;
    const open = referral.stage !== 'closed';
    const late = open && isOverdue(referral.next_due_at);
    const dueSoon =
        open &&
        !late &&
        !!referral.next_due_at &&
        new Date(referral.next_due_at).getTime() - Date.now() < 86_400_000;
    const canAssessment = !!permissions['assessment.view'];
    const canFunding = !!permissions['funding.view'];
    const canHandover = !!permissions.convert && canAssessment && canFunding;
    const canStart = !!permissions.commence && canAssessment && canFunding;
    const canConfiguration =
        !!permissions['settings.governance'] &&
        !!permissions['settings.view'] &&
        !!permissions.edit &&
        !!permissions['assessment.manage'] &&
        !!permissions['funding.manage'];
    const readiness = referral.profile?.readiness ?? null;
    const confirmed =
        readiness?.filter((item) => item.state === 'confirmed').length ?? 0;
    const needsAttention =
        readiness?.filter((item) => item.state === 'attention').length ?? 0;
    const fundingTotal = fundingHeadline(referral);
    const fundingPercent = fundingTotal ? coveragePercent(fundingTotal) : null;
    const fundingGap = fundingTotal
        ? Number(fundingTotal.proposed) - Number(fundingTotal.covered)
        : 0;

    /* ── Two-tier navigation: ≤ 8 rail groups, Configuration review in More ── */
    const groups = useMemo<GroupedProfileNavGroup[]>(() => {
        const handover = [
            ...(canHandover
                ? [
                      {
                          key: 'handover',
                          label: 'Onboarding',
                          icon: UserRoundCheck,
                      },
                  ]
                : []),
            ...(canStart
                ? [
                      {
                          key: 'service-start',
                          label: 'Start support',
                          icon: CalendarClock,
                      },
                  ]
                : []),
        ];
        return [
            {
                key: 'overview',
                label: 'Overview',
                icon: LayoutDashboard,
                tabs: [
                    {
                        key: 'summary',
                        label: 'Overview',
                        icon: LayoutDashboard,
                    },
                ],
            },
            ...(canFunding
                ? [
                      {
                          key: 'funding',
                          label: 'Funding',
                          icon: Wallet,
                          tabs: [
                              {
                                  key: 'funding',
                                  label: 'Funding assistance',
                                  icon: Wallet,
                                  count: referral.funding?.cases.total,
                              },
                          ],
                      },
                      {
                          key: 'proposal',
                          label: 'Proposal',
                          icon: ClipboardList,
                          tabs: [
                              {
                                  key: 'proposals',
                                  label: 'Support proposal',
                                  icon: ClipboardList,
                              },
                          ],
                      },
                  ]
                : []),
            {
                key: 'follow-ups',
                label: 'Follow-ups',
                icon: CalendarClock,
                tabs: [
                    {
                        key: 'work',
                        label: 'Follow-ups',
                        icon: CalendarClock,
                        count: referral.work_counts.open,
                        warningCount: referral.work_counts.overdue,
                    },
                ],
            },
            ...(canAssessment
                ? [
                      {
                          key: 'contacts',
                          label: 'Contacts',
                          icon: Users,
                          tabs: [
                              {
                                  key: 'people-contacts',
                                  label: 'People involved',
                                  icon: Users,
                              },
                              {
                                  key: 'assessment',
                                  label: 'Authority & assessment',
                                  icon: ShieldCheck,
                              },
                          ],
                      },
                  ]
                : []),
            ...(canAssessment || canFunding
                ? [
                      {
                          key: 'evidence',
                          label: 'Evidence',
                          icon: FileText,
                          tabs: [
                              {
                                  key: 'documents',
                                  label: 'Evidence',
                                  icon: FileText,
                              },
                          ],
                      },
                  ]
                : []),
            {
                key: 'activity',
                label: 'Activity',
                icon: History,
                tabs: [
                    {
                        key: 'activity',
                        label: 'Activity history',
                        icon: History,
                    },
                    {
                        key: 'communications',
                        label: 'Communications',
                        icon: Mail,
                    },
                ],
            },
            ...(handover.length
                ? [
                      {
                          key: 'handover',
                          label: 'Handover',
                          icon: UserRoundCheck,
                          tabs: handover,
                      },
                  ]
                : []),
            ...(canConfiguration
                ? [
                      {
                          key: 'configuration',
                          label: 'Configuration review',
                          icon: ClipboardCheck,
                          tabs: [
                              {
                                  key: 'configuration',
                                  label: 'Configuration review',
                                  icon: ClipboardCheck,
                              },
                          ],
                      },
                  ]
                : []),
        ];
    }, [
        canAssessment,
        canConfiguration,
        canFunding,
        canHandover,
        canStart,
        referral.funding?.cases.total,
        referral.work_counts.open,
        referral.work_counts.overdue,
    ]);
    const visible = groups.flatMap((group) => group.tabs.map((t) => t.key));
    const activeTab = (visible.includes(tab) ? tab : 'summary') as TabKey;
    const activeGroup =
        groups.find((group) => group.tabs.some((t) => t.key === activeTab)) ??
        groups[0];
    const goTo = useCallback((key: string, anchor?: string) => {
        setTab(key);
        if (anchor) {
            requestAnimationFrame(() =>
                document
                    .getElementById(anchor)
                    ?.scrollIntoView({ block: 'start' }),
            );
        }
    }, []);
    const selectGroup = (groupKey: string) => {
        const group = groups.find((item) => item.key === groupKey);
        if (group && !group.tabs.some((t) => t.key === activeTab)) {
            setTab(group.tabs[0].key);
        }
    };
    const railItems: PageHeaderRailItem[] = groups.map((group) => ({
        key: group.key,
        label: group.label,
        icon: group.icon,
        ...(group.key === 'follow-ups'
            ? { count: referral.work_counts.open }
            : {}),
        ...(group.key === 'configuration'
            ? {
                  overflow: {
                      description:
                          'Compare this referral with the current published settings',
                  },
              }
            : {}),
    }));
    const openPalette = useCallback(() => setPaletteOpen(true), []);
    useGroupedProfileSearchShortcut(openPalette);

    /* ── One primary action that follows the case state (mockup app.jsx) ── */
    const primary = ((): {
        label: string;
        icon: LucideIcon;
        run: () => void;
    } | null => {
        if (!open)
            return permissions.reopen
                ? {
                      label: 'Reopen referral',
                      icon: RotateCcw,
                      run: () => setSelection({ action: 'reopen' }),
                  }
                : null;
        if (!referral.owner_id && permissions.assign)
            return {
                label: 'Assign coordinator',
                icon: UserRound,
                run: () => setSelection({ action: 'assign' }),
            };
        if (
            ['new', 'triage'].includes(referral.stage) &&
            !referral.first_human_contact_at &&
            permissions.contact
        )
            return {
                label: 'Make first contact',
                icon: Phone,
                run: () => setSelection({ action: 'contact' }),
            };
        if (referral.stage === 'converted') {
            if (canStart && !referral.profile?.service_start?.commenced)
                return {
                    label: 'Start support',
                    icon: CalendarClock,
                    run: () => goTo('service-start'),
                };
            if (canHandover)
                return {
                    label: 'View handover',
                    icon: UserRoundCheck,
                    run: () => goTo('handover'),
                };
        } else if (canHandover) {
            const ready =
                !!readiness && readiness.every((i) => i.state === 'confirmed');
            return {
                label: ready ? 'Review handover' : 'Review readiness',
                icon: ClipboardCheck,
                run: () => goTo('handover'),
            };
        } else if (permissions['assessment.manage'] && canAssessment) {
            return {
                label: 'Review assessment',
                icon: ClipboardCheck,
                run: () => setReview('assessment'),
            };
        }
        if (permissions['tasks.manage'])
            return {
                label: 'Update next action',
                icon: Pencil,
                run: () => setSelection({ action: 'next' }),
            };
        return null;
    })();
    const primaryIsContact = primary?.label === 'Make first contact';

    /* ── Follow-up and activity actions: one MenuItem[] per record ── */
    const canWork = (work: WorkItem) =>
        !!permissions['tasks.manage'] &&
        (work.audience === 'intake' ||
            !!permissions[`${work.audience}.manage`]);
    const workActions = (work: WorkItem): MenuItem[] => {
        const items: MenuItem[] = [
            {
                label: 'View details',
                icon: Eye,
                onClick: () => setViewWork(work),
            },
        ];
        if (!permissions['tasks.manage']) return items;
        const blocked =
            work.status !== 'open'
                ? `This follow-up is already ${label(work.status).toLowerCase()}`
                : !canWork(work)
                  ? `Needs permission to manage ${label(work.audience).toLowerCase()} work`
                  : undefined;
        return [
            ...items,
            {
                label: 'Edit follow-up',
                icon: Pencil,
                onClick: () => setSelection({ action: 'work', work }),
                disabled:
                    blocked ??
                    (open
                        ? undefined
                        : 'Reopen the referral to change its follow-ups'),
            },
            {
                label: 'Complete or cancel',
                icon: Check,
                onClick: () => setSelection({ action: 'finish', work }),
                disabled: blocked,
            },
        ];
    };
    const activityActions = (event: Activity): MenuItem[] => [
        {
            label: 'View recorded details',
            icon: Eye,
            onClick: () => setViewActivity(event),
        },
    ];
    const handlers: OverviewHandlers = {
        onTab: (key) => goTo(key),
        onAction: (action, extra) =>
            setSelection({ action, closeReason: extra?.closeReason }),
        onCorrect: () => setCorrecting(true),
        onReview: setReview,
        onViewWork: setViewWork,
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Referrals', href: '/operations/referrals' },
                {
                    title: referral.name,
                    href: referralHref(referral.reference),
                },
            ]}
        >
            <Head title={`${referral.name} · Referrals`} />
            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref="/operations/referrals"
                        icon={referral.general_enquiry ? Inbox : undefined}
                        mark={
                            referral.general_enquiry ? undefined : (
                                <span
                                    className="eh-mark-ring text-[17px] font-bold tracking-tight"
                                    aria-hidden="true"
                                >
                                    {initialsFromName(referral.name)}
                                </span>
                            )
                        }
                        title={referral.name}
                        wrapTitle
                        titleChip={
                            <ReferralStageBadge
                                stage={referral.stage}
                                stages={options.stages}
                            />
                        }
                        subline={[
                            shownReference(referral),
                            label(referral.source),
                            referral.site_name ?? 'Central intake',
                        ].join(' · ')}
                        mobileSummary={
                            open
                                ? `${referral.owner_name ?? 'No owner'} · ${referral.work_counts.overdue} overdue follow-ups`
                                : `Closed · ${referral.closure_reason_label ?? label(referral.closure_reason ?? 'other')}`
                        }
                        actions={
                            <>
                                {permissions.contact &&
                                    open &&
                                    !primaryIsContact && (
                                        <PageHeaderGlassButton
                                            icon={Phone}
                                            onClick={() =>
                                                setSelection({
                                                    action: 'contact',
                                                })
                                            }
                                        >
                                            Log contact
                                        </PageHeaderGlassButton>
                                    )}
                                {primary && (
                                    <PageHeaderPrimaryButton
                                        icon={primary.icon}
                                        onClick={primary.run}
                                    >
                                        {primary.label}
                                    </PageHeaderPrimaryButton>
                                )}
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Accountable owner"
                                    tone={
                                        open && !referral.owner_id
                                            ? 'warning'
                                            : 'brand'
                                    }
                                    ariaLabel="View the accountable owner and next step"
                                    onClick={() =>
                                        goTo('summary', 'referral-next-step')
                                    }
                                >
                                    <PageHeaderMeterContacts
                                        contacts={[
                                            {
                                                label: 'Owner',
                                                name: referral.owner_name,
                                            },
                                            {
                                                label: 'Team',
                                                name: referral.team,
                                            },
                                        ]}
                                    />
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Next follow-up"
                                    value={late ? 'Overdue' : undefined}
                                    tone={
                                        late
                                            ? 'critical'
                                            : dueSoon
                                              ? 'warning'
                                              : 'brand'
                                    }
                                    onClick={() => goTo('work')}
                                >
                                    <PageHeaderMeterBig>
                                        {!open
                                            ? 'Closed'
                                            : referral.next_due_at
                                              ? formatDate(referral.next_due_at)
                                              : 'Not planned'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {!open
                                            ? (referral.closure_reason_label ??
                                              label(
                                                  referral.closure_reason ??
                                                      'other',
                                              ))
                                            : referral.next_action ||
                                              'No next action recorded'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Funding position"
                                    onClick={() =>
                                        canFunding
                                            ? goTo('funding')
                                            : goTo(
                                                  'summary',
                                                  'referral-funding-glance',
                                              )
                                    }
                                >
                                    {!canFunding ? (
                                        <>
                                            <PageHeaderMeterBig>
                                                Not available
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                Needs funding access
                                            </PageHeaderMeterCaption>
                                        </>
                                    ) : fundingTotal &&
                                      fundingPercent !== null ? (
                                        <>
                                            <PageHeaderMeterBig>
                                                {formatMoney(
                                                    fundingTotal.covered,
                                                    fundingTotal.currency,
                                                )}
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterBar
                                                percent={fundingPercent}
                                            />
                                            <PageHeaderMeterCaption>
                                                verified of{' '}
                                                {formatMoney(
                                                    fundingTotal.proposed,
                                                    fundingTotal.currency,
                                                )}{' '}
                                                {perPeriod(fundingTotal.period)}
                                                {fundingGap > 0
                                                    ? ` · ${formatMoney(String(fundingGap), fundingTotal.currency)} to arrange`
                                                    : ' · fully covered'}
                                            </PageHeaderMeterCaption>
                                        </>
                                    ) : (
                                        <>
                                            <PageHeaderMeterBig>
                                                Not yet costed
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                {referral.profile?.funding
                                                    ?.proposal_version
                                                    ? 'The proposal has no priced lines'
                                                    : 'No support proposal yet'}
                                            </PageHeaderMeterCaption>
                                        </>
                                    )}
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Handover checks"
                                    value={
                                        readiness
                                            ? `${confirmed}/${readiness.length}`
                                            : undefined
                                    }
                                    tone={needsAttention ? 'warning' : 'brand'}
                                    onClick={() =>
                                        goTo('summary', 'referral-readiness')
                                    }
                                >
                                    {readiness ? (
                                        <PageHeaderMeterDonut
                                            percent={
                                                readiness.length
                                                    ? (confirmed /
                                                          readiness.length) *
                                                      100
                                                    : 0
                                            }
                                            caption={
                                                <>
                                                    {confirmed} of{' '}
                                                    {readiness.length} confirmed
                                                    {needsAttention
                                                        ? ` · ${needsAttention} need attention`
                                                        : ''}
                                                </>
                                            }
                                        />
                                    ) : (
                                        <>
                                            <PageHeaderMeterBig>
                                                Not available
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                Needs assessment access
                                            </PageHeaderMeterCaption>
                                        </>
                                    )}
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Follow-ups"
                                    tone={
                                        referral.work_counts.overdue
                                            ? 'warning'
                                            : 'brand'
                                    }
                                    onClick={() => goTo('work')}
                                >
                                    <PageHeaderMeterBig>
                                        {referral.work_counts.open}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {referral.work_counts.overdue
                                            ? `${referral.work_counts.overdue} overdue`
                                            : 'None overdue'}{' '}
                                        · open within your access
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        rail={
                            <PageHeaderRail
                                items={railItems}
                                value={activeGroup.key}
                                onSelect={selectGroup}
                                onFind={openPalette}
                                maxVisible={8}
                                ariaLabel="Referral sections"
                            />
                        }
                    />
                }
            >
                <div className="flex flex-col gap-5">
                    {referral.restricted && (
                        <Alert variant="warning">
                            <AlertTitle>Restricted referral</AlertTitle>
                            <AlertDescription>
                                Only specifically authorized staff can access
                                this record. Check the intended audience before
                                adding information.
                            </AlertDescription>
                        </Alert>
                    )}
                    {referral.retention && (
                        <Alert variant="info">
                            <AlertTitle>Personal details removed</AlertTitle>
                            <AlertDescription>
                                An approved retention policy removed this
                                referral’s personal details
                                {referral.retention.anonymised_at
                                    ? ` on ${formatDate(referral.retention.anonymised_at)}`
                                    : ''}
                                . Its reference, dates, stage and outcome are
                                kept so reports stay complete.
                            </AlertDescription>
                        </Alert>
                    )}
                    {activeGroup.tabs.length > 1 && (
                        <TierTwoTabs
                            tabs={activeGroup.tabs}
                            activeTab={activeTab}
                            onTab={(key) => goTo(key)}
                            renderLink={() => null}
                            testIdPrefix="referral"
                            ariaLabel={`${activeGroup.label} sections`}
                            panelId="referral-profile-panel"
                        />
                    )}
                    <div
                        id="referral-profile-panel"
                        role="tabpanel"
                        {...(activeGroup.tabs.length > 1
                            ? { 'aria-labelledby': `referral-tab-${activeTab}` }
                            : { 'aria-label': activeGroup.label })}
                        className="flex flex-col gap-5"
                    >
                        {activeTab === 'summary' && (
                            <>
                                {referral.is_general_enquiry && (
                                    <EnquiryMessage referral={referral} />
                                )}
                                {referral.general_enquiry &&
                                    !referral.is_general_enquiry && (
                                        <Alert variant="info">
                                            <AlertTitle>
                                                Process enquiry
                                            </AlertTitle>
                                            <AlertDescription>
                                                Authority to provide another
                                                person’s information has not
                                                been confirmed. Keep this
                                                enquiry minimal until
                                                participation and authority are
                                                reviewed.
                                            </AlertDescription>
                                        </Alert>
                                    )}
                                <ReferralCaseOverview
                                    referral={referral}
                                    permissions={permissions}
                                    options={options}
                                    handlers={handlers}
                                />
                                <IntakeReview
                                    referral={referral}
                                    permissions={permissions}
                                    options={options}
                                    onCorrect={() => setCorrecting(true)}
                                />
                                <FormAnswersPanel referral={referral} />
                                <ChecklistPanel
                                    key={referral.reference}
                                    referral={referral}
                                />
                            </>
                        )}
                        {activeTab === 'people-contacts' && canAssessment && (
                            <PeopleContactsPanel
                                referral={referral}
                                canManage={!!permissions['assessment.manage']}
                            />
                        )}
                        {activeTab === 'assessment' && canAssessment && (
                            <AuthorityAssessment
                                referral={referral}
                                authority={authority}
                                canManage={
                                    !!permissions['assessment.manage'] && open
                                }
                                onReview={setReview}
                            />
                        )}
                        {activeTab === 'work' && (
                            <>
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <h2 className="text-section-title">
                                            Follow-ups
                                        </h2>
                                        <p className="text-subtle">
                                            {referral.work.total} within your
                                            access · open work first · NZ time
                                        </p>
                                    </div>
                                    {permissions['tasks.manage'] && open && (
                                        <Button
                                            onClick={() =>
                                                setSelection({
                                                    action: 'work',
                                                })
                                            }
                                        >
                                            <Plus className="size-4" />
                                            Plan follow-up
                                        </Button>
                                    )}
                                </div>
                                {!referral.work.data.length ? (
                                    <EmptyState
                                        icon={CalendarClock}
                                        title="No follow-ups recorded"
                                        description="Plan a named action and due date so the next step is clear."
                                    />
                                ) : (
                                    <EntityCardGrid>
                                        {referral.work.data.map((work) => (
                                            <EntityCard
                                                key={work.reference}
                                                onOpen={() => setViewWork(work)}
                                                onContextMenu={(event) =>
                                                    workMenu.open(event, work)
                                                }
                                                name={
                                                    work.details.title ??
                                                    label(work.kind)
                                                }
                                                icon={CalendarClock}
                                                meridian={
                                                    work.status === 'open' &&
                                                    isOverdue(work.due_at)
                                                        ? 'warning'
                                                        : 'neutral'
                                                }
                                                subline={when(work.due_at)}
                                                actions={workActions(work)}
                                                chips={
                                                    <>
                                                        <StatusBadge
                                                            status={work.status}
                                                        />
                                                        <EntityChip>
                                                            {label(work.kind)}
                                                        </EntityChip>
                                                        <EntityChip>
                                                            {label(
                                                                work.audience,
                                                            )}
                                                        </EntityChip>
                                                        {referral.primary_work_item_id ===
                                                            work.id && (
                                                            <EntityChip>
                                                                Current next
                                                                action
                                                            </EntityChip>
                                                        )}
                                                    </>
                                                }
                                                footer={{
                                                    personName: work.owner_name,
                                                    primary:
                                                        work.owner_name ??
                                                        'No owner',
                                                    secondary:
                                                        work.details
                                                            .outcome_note ||
                                                        work.details
                                                            .description ||
                                                        'No additional notes',
                                                }}
                                            />
                                        ))}
                                    </EntityCardGrid>
                                )}
                                <LaravelPagination
                                    links={referral.work.links}
                                    lastPage={referral.work.last_page}
                                    preserveScroll
                                    only={['referral']}
                                />
                            </>
                        )}
                        {activeTab === 'funding' && canFunding && (
                            <FundingPanel
                                referral={referral}
                                permissions={permissions}
                                options={options}
                            />
                        )}
                        {activeTab === 'proposals' && canFunding && (
                            <ProposalsPanel
                                referral={referral}
                                permissions={permissions}
                                options={options}
                            />
                        )}
                        {activeTab === 'handover' && canHandover && (
                            <HandoverPanel
                                referral={referral}
                                onSection={goTo}
                            />
                        )}
                        {activeTab === 'service-start' && canStart && (
                            <ServiceStartPanel referral={referral} />
                        )}
                        {activeTab === 'documents' && (
                            <DocumentsPanel
                                referral={referral}
                                permissions={permissions}
                            />
                        )}
                        {activeTab === 'communications' && (
                            <CommunicationsPanel referral={referral} />
                        )}
                        {activeTab === 'activity' && (
                            <>
                                <div>
                                    <h2 className="text-section-title">
                                        Activity history
                                    </h2>
                                    <p className="text-subtle">
                                        {referral.activity.total} events within
                                        your access · newest first · NZ time
                                    </p>
                                </div>
                                {!referral.activity.data.length ? (
                                    <EmptyState
                                        icon={History}
                                        title="No visible activity"
                                        description="Recorded contact, reviews and changes appear here when your access allows."
                                    />
                                ) : (
                                    <EntityTable
                                        rows={referral.activity.data}
                                        rowKey={(event) => event.reference}
                                        identityLabel="Event"
                                        identity={(event) => ({
                                            icon: History,
                                            name: activityTitle(event),
                                            subline:
                                                event.actor_name ??
                                                (event.actor_id
                                                    ? 'Staff member'
                                                    : 'Website or system'),
                                        })}
                                        columns={[
                                            {
                                                key: 'when',
                                                label: 'When',
                                                width: '1.1fr',
                                                cell: (event) => (
                                                    <span className="text-[12.5px]">
                                                        {when(
                                                            event.occurred_at,
                                                        )}
                                                    </span>
                                                ),
                                            },
                                            {
                                                key: 'audience',
                                                label: 'Recorded in',
                                                width: '0.8fr',
                                                cell: (event) => (
                                                    <EntityChip>
                                                        {label(event.audience)}
                                                    </EntityChip>
                                                ),
                                            },
                                            {
                                                key: 'summary',
                                                label: 'Summary',
                                                width: '2fr',
                                                cell: (event) => {
                                                    const summary =
                                                        activitySummary(event);
                                                    return summary ? (
                                                        <span className="line-clamp-2 text-[12.5px] break-words">
                                                            {summary}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            —
                                                        </span>
                                                    );
                                                },
                                            },
                                        ]}
                                        actionsFor={activityActions}
                                        onOpen={setViewActivity}
                                        onRowContextMenu={activityMenu.open}
                                        rowHeight="content"
                                        minWidth={760}
                                    />
                                )}
                                <LaravelPagination
                                    links={referral.activity.links}
                                    lastPage={referral.activity.last_page}
                                    preserveScroll
                                    only={['referral']}
                                />
                            </>
                        )}
                        {activeTab === 'configuration' && canConfiguration && (
                            <SettingsMigrationPanel
                                reference={referral.reference}
                                version={referral.version}
                            />
                        )}
                    </div>
                </div>
            </PageLayout>
            <TabSearchPalette
                open={paletteOpen}
                onClose={() => setPaletteOpen(false)}
                groups={groups}
                onTab={(key) => goTo(key)}
                testIdPrefix="referral"
                searchLabel="Find a referral section"
            />
            {workMenu.ctx ? (
                <EntityContextMenu
                    x={workMenu.ctx.x}
                    y={workMenu.ctx.y}
                    icon={CalendarClock}
                    title={
                        workMenu.ctx.record.details.title ??
                        label(workMenu.ctx.record.kind)
                    }
                    items={workActions(workMenu.ctx.record)}
                    onClose={workMenu.close}
                />
            ) : null}
            {activityMenu.ctx ? (
                <EntityContextMenu
                    x={activityMenu.ctx.x}
                    y={activityMenu.ctx.y}
                    icon={History}
                    title={activityTitle(activityMenu.ctx.record)}
                    items={activityActions(activityMenu.ctx.record)}
                    onClose={activityMenu.close}
                />
            ) : null}
            {correcting && (
                <IntakeCorrectionDialog
                    referral={referral}
                    options={options}
                    onClose={() => setCorrecting(false)}
                />
            )}
            {viewActivity && (
                <ReferralActivityDetail
                    event={viewActivity}
                    onClose={() => setViewActivity(null)}
                />
            )}
            {review && (
                <ReferralReviewDialog
                    mode={review}
                    referral={referral}
                    onClose={() => setReview(null)}
                />
            )}
            <Dialog
                open={!!viewWork}
                onOpenChange={(value) => {
                    if (!value) setViewWork(null);
                }}
            >
                <DialogContent
                    className="max-h-[90vh] overflow-y-auto"
                    style={{
                        maxWidth: 'min(92vw, 480px)',
                        width: 'min(92vw, 480px)',
                    }}
                >
                    {viewWork && (
                        <>
                            <DialogHeader>
                                <DialogTitle className="flex items-center gap-2">
                                    <CalendarClock className="size-4 shrink-0 text-primary" />
                                    {viewWork.details.title ?? 'Follow-up'}
                                </DialogTitle>
                                <DialogDescription>
                                    {label(viewWork.audience)} ·{' '}
                                    {label(viewWork.kind)}
                                </DialogDescription>
                            </DialogHeader>
                            <dl className="grid gap-4 py-4">
                                <Fact title="Status">
                                    <StatusBadge status={viewWork.status} />
                                </Fact>
                                <Fact title="Owner">
                                    {viewWork.owner_name ?? 'No owner'}
                                </Fact>
                                <Fact title="Due / appointment start">
                                    {when(viewWork.due_at)}
                                </Fact>
                                {viewWork.ends_at && (
                                    <Fact title="Appointment end">
                                        {when(viewWork.ends_at)}
                                    </Fact>
                                )}
                                <Fact title="Details">
                                    {viewWork.details.description}
                                </Fact>
                                {viewWork.details.outcome_note && (
                                    <Fact title="Outcome">
                                        {viewWork.details.outcome_note}
                                    </Fact>
                                )}
                            </dl>
                            <DialogFooter>
                                <Button
                                    variant="outline"
                                    onClick={() => setViewWork(null)}
                                >
                                    Close
                                </Button>
                                {canWork(viewWork) &&
                                    viewWork.status === 'open' &&
                                    open && (
                                        <Button
                                            onClick={() => {
                                                setSelection({
                                                    action: 'work',
                                                    work: viewWork,
                                                });
                                                setViewWork(null);
                                            }}
                                        >
                                            Edit follow-up
                                        </Button>
                                    )}
                            </DialogFooter>
                        </>
                    )}
                </DialogContent>
            </Dialog>
            {selection && (
                <CaseActionDialog
                    action={selection.action}
                    work={selection.work}
                    initialDueAt={initialPlanAt}
                    initialCloseReason={selection.closeReason}
                    referral={referral}
                    permissions={permissions}
                    options={options}
                    onClose={() => setSelection(null)}
                />
            )}
        </AppLayout>
    );
}

/** Contacts › Authority & assessment: the reviewed authority and provider assessment. */
function AuthorityAssessment({
    referral,
    authority,
    canManage,
    onReview,
}: {
    referral: ReferralProfileDetail;
    authority: Record<string, unknown> | undefined;
    canManage: boolean;
    onReview: (mode: 'authority' | 'assessment') => void;
}) {
    const text = (value: unknown) =>
        typeof value === 'string' && value ? value : undefined;
    return (
        <>
            <p className="text-subtle">
                Reviewed information is separate from the original intake
                declaration. Expired or withdrawn authority does not permit new
                activity.
            </p>
            <div className="grid gap-5 xl:grid-cols-2">
                <Card>
                    <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
                        <CardTitle>Authority and participation</CardTitle>
                        {canManage && (
                            <Button
                                variant="outline"
                                onClick={() => onReview('authority')}
                            >
                                Review authority
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent className="space-y-5">
                        <dl className="grid gap-4 sm:grid-cols-2">
                            <Fact title="State">
                                {label(String(authority?.state ?? 'pending'))}
                            </Fact>
                            <Fact title="Review due">
                                {when(text(authority?.review_due_at) ?? null)}
                            </Fact>
                            <Fact title="Basis">
                                {text(authority?.basis) &&
                                    label(String(authority?.basis))}
                            </Fact>
                            <Fact title="Basis and evidence">
                                {text(authority?.basis_note)}
                            </Fact>
                            <Fact title="Representative">
                                {text(authority?.representative)}
                            </Fact>
                            <Fact title="Participation">
                                {text(authority?.participation)}
                            </Fact>
                            <Fact title="Collection notice">
                                {text(authority?.notice) &&
                                    label(String(authority?.notice))}
                            </Fact>
                            <Fact title="Notice evidence">
                                {text(authority?.notice_note)}
                            </Fact>
                        </dl>
                        <div>
                            <h3 className="text-section-title">
                                Currently permitted activities
                            </h3>
                            <dl className="mt-3 grid gap-4 sm:grid-cols-2">
                                {Object.entries({
                                    contact: 'Future contact',
                                    collect: 'Assessment collection',
                                    assist: 'Funding assistance',
                                    service: 'Proposed service',
                                }).map(([key, title]) => (
                                    <Fact key={key} title={title}>
                                        {referral.authority_scopes?.[key]
                                            ? 'Permission recorded'
                                            : 'Not currently permitted'}
                                    </Fact>
                                ))}
                            </dl>
                        </div>
                        <div>
                            <h3 className="text-section-title">
                                Current sharing permissions
                            </h3>
                            {referral.current_sharing?.length ? (
                                <div className="mt-3 space-y-4">
                                    {referral.current_sharing.map(
                                        (item, index) => (
                                            <dl
                                                key={index}
                                                className="space-y-3 rounded-lg bg-muted p-4"
                                            >
                                                <Fact title="Recipient">
                                                    {item.recipient}
                                                </Fact>
                                                <Fact title="Purpose">
                                                    {item.purpose}
                                                </Fact>
                                                <Fact title="Information">
                                                    {item.information}
                                                </Fact>
                                                <Fact title="Expires">
                                                    {when(item.expires_at)}
                                                </Fact>
                                            </dl>
                                        ),
                                    )}
                                </div>
                            ) : (
                                <EmptyState
                                    variant="inline"
                                    icon={ShieldCheck}
                                    title="No current sharing permission recorded"
                                    className="mt-3"
                                />
                            )}
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
                        <CardTitle>Provider assessment</CardTitle>
                        {canManage && (
                            <Button
                                variant="outline"
                                onClick={() => onReview('assessment')}
                            >
                                Review assessment
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent>
                        <dl className="grid gap-4">
                            {Object.entries({
                                identity_status: 'Identity review',
                                identity_note: 'Identity evidence',
                                intention: 'Person’s intention',
                                intention_note: 'Provider choice and wishes',
                                goals: 'Goals',
                                participation: 'Participation',
                                suitability: 'Provider suitability',
                                suitability_note: 'Suitability evidence',
                                capacity: 'Capacity',
                                capacity_note: 'Capacity evidence',
                                safety_needs:
                                    'Safety needs and outstanding information',
                            }).map(([key, title]) => {
                                const value = text(referral.assessment?.[key]);
                                return (
                                    <Fact key={key} title={title}>
                                        {value &&
                                            ([
                                                'identity_status',
                                                'intention',
                                                'suitability',
                                                'capacity',
                                            ].includes(key)
                                                ? label(value)
                                                : value)}
                                    </Fact>
                                );
                            })}
                            <Fact title="Next review">
                                {when(
                                    text(referral.assessment?.review_due_at) ??
                                        null,
                                )}
                            </Fact>
                        </dl>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
