/**
 * The Respite workspace orchestrator: a rostering-style ops hero, the reused
 * rostering TabStrip, URL-synced tabs (?tab=), and the active pane. Detail
 * views open in a pop-up; nothing in here navigates to another page.
 */
import {
    PageHero,
    type PageHeroBadge,
    type PageHeroMetaItem,
    type PageHeroStat,
} from '@/components/page';
import { TabStrip, type RosterTabItem } from '@/components/rostering/tab-strip';
import { Button } from '@/components/ui/button';
import { router, usePage } from '@inertiajs/react';
import {
    BedDouble,
    CalendarCheck,
    CalendarDays,
    ClipboardCheck,
    Clock,
    Home,
    Inbox,
    LayoutDashboard,
    ListChecks,
    Plus,
    Users,
    Zap,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { respiteActions } from './actions';
import { RespiteDetailModal, type RespiteDetail } from './detail-modal';
import { OnboardModal } from './modals/onboard';
import { ReasonDialog } from './modals/reason-dialog';
import { ReferralIntakeModal } from './modals/referral-intake';
import { BookingsPane } from './panes/bookings';
import { CalendarPane } from './panes/calendar';
import { OverviewPane } from './panes/overview';
import { ReferralsPane } from './panes/referrals';
import { RequestsPane } from './panes/requests';
import { StaysPane } from './panes/stays';
import { TasksPane } from './panes/tasks';
import {
    RESPITE_TABS,
    type RespiteCan,
    type RespiteRequestRow,
    type RespiteTab,
    type RespiteWorkspaceData,
} from './types';

type ReasonKind =
    | 'decline'
    | 'reject'
    | 'discharge'
    | 'fundingOverride'
    | 'checkInOverride'
    | 'confirmOverride';

const REASON_CONFIG: Record<
    ReasonKind,
    { title: string; label: string; placeholder: string; confirmLabel: string }
> = {
    decline: {
        title: 'Decline referral',
        label: 'Reason for declining',
        placeholder: 'Why is this referral being declined?',
        confirmLabel: 'Decline referral',
    },
    reject: {
        title: 'Reject request',
        label: 'Decision notes',
        placeholder: 'Why is this request being rejected?',
        confirmLabel: 'Reject request',
    },
    discharge: {
        title: 'Discharge stay',
        label: 'Discharge summary',
        placeholder: 'Summarise the stay and any follow-up…',
        confirmLabel: 'Discharge stay',
    },
    fundingOverride: {
        title: 'Approve while funding is pending',
        label: 'Funding override reason',
        placeholder:
            'Why is this booking safe to approve before funding is verified?',
        confirmLabel: 'Approve request',
    },
    checkInOverride: {
        title: 'Check in before med-rec is complete',
        label: 'Medication reconciliation override reason',
        placeholder:
            'Why is it safe to check in before admission medication reconciliation is completed?',
        confirmLabel: 'Check in',
    },
    confirmOverride: {
        title: 'Confirm before readiness is complete',
        label: 'Readiness override reason',
        placeholder:
            'Why is this booking safe to confirm before all readiness checks are complete?',
        confirmLabel: 'Confirm booking',
    },
};

function readTab(): RespiteTab {
    if (typeof window === 'undefined') return 'overview';
    const t = new URLSearchParams(window.location.search).get('tab');
    return (RESPITE_TABS as string[]).includes(t ?? '')
        ? (t as RespiteTab)
        : 'overview';
}

export function RespiteWorkspace({
    data,
    can,
}: {
    data: RespiteWorkspaceData;
    can: RespiteCan;
}) {
    const page = usePage<{ auth?: { user?: { name?: string } } }>();
    const firstName =
        (page.props.auth?.user?.name ?? '').split(' ')[0] || 'there';

    const [tab, setTab] = useState<RespiteTab>(() => readTab());
    const [detail, setDetail] = useState<RespiteDetail | null>(null);
    const [intakeOpen, setIntakeOpen] = useState(false);
    const [onboardReq, setOnboardReq] = useState<RespiteRequestRow | null>(
        null,
    );
    const [reasonAction, setReasonAction] = useState<{
        kind: ReasonKind;
        id: number;
        client: string;
    } | null>(null);

    useEffect(() => {
        const onPop = () => setTab(readTab());
        window.addEventListener('popstate', onPop);
        return () => window.removeEventListener('popstate', onPop);
    }, []);

    const goTab = useCallback((next: RespiteTab) => {
        setTab(next);
        const url = new URL(window.location.href);
        url.searchParams.set('tab', next);
        window.history.replaceState(null, '', url);
    }, []);

    const runReason = (reason: string, done: () => void) => {
        if (!reasonAction) return;
        const { kind, id } = reasonAction;
        const opts = {
            preserveScroll: true,
            onSuccess: () => setReasonAction(null),
            onFinish: done,
        } as const;
        if (kind === 'decline')
            router.put(
                `/respite/referrals/${id}`,
                { status: 'declined', triage_notes: reason },
                opts,
            );
        else if (kind === 'reject')
            router.put(
                `/respite/requests/${id}`,
                { status: 'rejected', decision_notes: reason },
                opts,
            );
        else if (kind === 'fundingOverride')
            router.post(
                `/respite/requests/${id}/approve`,
                { funding_override_reason: reason },
                opts,
            );
        else if (kind === 'checkInOverride')
            router.post(
                `/respite/stays/${id}/check-in`,
                { med_rec_override_reason: reason },
                opts,
            );
        else if (kind === 'confirmOverride')
            router.post(
                `/respite/bookings/${id}/confirm`,
                { readiness_override_reason: reason },
                opts,
            );
        else
            router.post(
                `/respite/stays/${id}/discharge`,
                { discharge_summary: reason },
                opts,
            );
    };

    const stats = data.stats;
    const openTasks = data.tasks.filter(
        (t) =>
            !['completed', 'approved', 'skipped', 'rejected'].includes(
                t.status,
            ),
    ).length;

    const meta: PageHeroMetaItem[] = [
        {
            icon: Home,
            label: `${data.homes.length} home${data.homes.length === 1 ? '' : 's'}`,
        },
        {
            icon: BedDouble,
            label: `${stats.bedsOccupied}/${stats.bedsTotal} respite beds`,
        },
        { icon: Users, label: `${stats.inHouse} in house` },
    ];

    const badges: PageHeroBadge[] = [];
    if (stats.crisisOpen > 0)
        badges.push({
            icon: Zap,
            tone: 'critical',
            label: `${stats.crisisOpen} crisis`,
            onClick: () => goTab('referrals'),
        });
    if (stats.awaitingReview > 0)
        badges.push({
            icon: Clock,
            tone: 'warning',
            label: `${stats.awaitingReview} awaiting review`,
            onClick: () => goTab('requests'),
        });
    if (stats.newReferrals > 0)
        badges.push({
            icon: Inbox,
            tone: 'info',
            label: `${stats.newReferrals} new today`,
            onClick: () => goTab('referrals'),
        });

    const heroStats: PageHeroStat[] = [
        { label: 'New referrals', value: stats.newReferrals },
        { label: 'Awaiting review', value: stats.awaitingReview },
        { label: 'Confirmed', value: stats.confirmedUpcoming },
        { label: 'In house', value: stats.inHouse },
    ];

    const tabs: RosterTabItem[] = [
        {
            id: 'overview',
            label: 'Overview',
            icon: LayoutDashboard,
            tone: 'primary',
        },
        {
            id: 'referrals',
            label: 'Referrals',
            icon: Inbox,
            tone: 'info',
            badge: stats.newReferrals || undefined,
        },
        {
            id: 'requests',
            label: 'Booking Requests',
            icon: ClipboardCheck,
            tone: 'warning',
            badge: stats.awaitingReview || undefined,
        },
        {
            id: 'bookings',
            label: 'Approved Bookings',
            icon: CalendarCheck,
            tone: 'primary',
            badge: stats.confirmedUpcoming || undefined,
        },
        { id: 'calendar', label: 'Calendar', icon: CalendarDays, tone: 'info' },
        {
            id: 'stays',
            label: 'Stays',
            icon: Home,
            tone: 'success',
            badge: stats.inHouse || undefined,
        },
        {
            id: 'tasks',
            label: 'Tasks',
            icon: ListChecks,
            tone: 'violet',
            badge: openTasks || undefined,
        },
    ];

    return (
        <div className="flex flex-col gap-4 p-4 md:p-6">
            <PageHero
                category="ops"
                icon={Home}
                title={`Kia ora, ${firstName}`}
                description={`${stats.toTriage} referral${stats.toTriage === 1 ? '' : 's'} to triage, ${stats.awaitingReview} request${stats.awaitingReview === 1 ? '' : 's'} awaiting sign-off, and ${stats.inHouse} guest${stats.inHouse === 1 ? '' : 's'} in house.`}
                meta={meta}
                badges={badges}
                stats={heroStats}
                actions={
                    can.create ? (
                        <Button
                            onClick={() => setIntakeOpen(true)}
                            className="bg-primary-foreground text-primary hover:bg-primary-foreground/90"
                        >
                            <Plus className="h-4 w-4" /> New referral
                        </Button>
                    ) : undefined
                }
            />

            <TabStrip
                items={tabs}
                value={tab}
                onChange={(v) => goTab(v as RespiteTab)}
            />

            <div>
                {tab === 'overview' && (
                    <OverviewPane data={data} goTab={goTab} />
                )}
                {tab === 'referrals' && (
                    <ReferralsPane
                        referrals={data.referrals}
                        can={can}
                        onView={(row) => setDetail({ kind: 'referral', row })}
                        onNew={() => setIntakeOpen(true)}
                        onDecline={(row) =>
                            setReasonAction({
                                kind: 'decline',
                                id: row.id,
                                client: row.client,
                            })
                        }
                    />
                )}
                {tab === 'requests' && (
                    <RequestsPane
                        requests={data.requests}
                        can={can}
                        onView={(row) => setDetail({ kind: 'request', row })}
                        onApprove={(row) => {
                            if (row.fundingStatus === 'pending_approval') {
                                setReasonAction({
                                    kind: 'fundingOverride',
                                    id: row.id,
                                    client: row.client,
                                });
                                return;
                            }

                            respiteActions.approveRequest(row.id);
                        }}
                        onPromote={(row) =>
                            respiteActions.promoteRequest(row.id)
                        }
                        onOnboard={(row) => setOnboardReq(row)}
                        onReject={(row) =>
                            setReasonAction({
                                kind: 'reject',
                                id: row.id,
                                client: row.client,
                            })
                        }
                    />
                )}
                {tab === 'bookings' && (
                    <BookingsPane
                        bookings={data.bookings}
                        can={can}
                        onView={(row) => setDetail({ kind: 'booking', row })}
                        onConfirm={(row) => {
                            if (row.readiness < 100) {
                                setReasonAction({
                                    kind: 'confirmOverride',
                                    id: row.id,
                                    client: row.client,
                                });
                                return;
                            }

                            respiteActions.confirmBooking(row.id);
                        }}
                    />
                )}
                {tab === 'calendar' && (
                    <CalendarPane bookings={data.bookings} homes={data.homes} />
                )}
                {tab === 'stays' && (
                    <StaysPane
                        stays={data.stays}
                        can={can}
                        onView={(row) => setDetail({ kind: 'stay', row })}
                        onCheckIn={(row) => {
                            if (
                                row.requiresAdmissionMedRec &&
                                row.admissionMedRecStatus !== 'completed'
                            ) {
                                setReasonAction({
                                    kind: 'checkInOverride',
                                    id: row.id,
                                    client: row.client,
                                });
                                return;
                            }

                            respiteActions.checkInStay(row.id);
                        }}
                        onDischarge={(row) =>
                            setReasonAction({
                                kind: 'discharge',
                                id: row.id,
                                client: row.client,
                            })
                        }
                    />
                )}
                {tab === 'tasks' && <TasksPane tasks={data.tasks} can={can} />}
            </div>

            <RespiteDetailModal
                detail={detail}
                onClose={() => setDetail(null)}
            />
            <ReferralIntakeModal
                open={intakeOpen}
                onClose={() => setIntakeOpen(false)}
                clients={data.clients}
                homes={data.homes}
                fundingSources={data.fundingSources}
            />
            <OnboardModal
                request={onboardReq}
                onClose={() => setOnboardReq(null)}
            />
            {reasonAction ? (
                <ReasonDialog
                    open
                    onClose={() => setReasonAction(null)}
                    title={REASON_CONFIG[reasonAction.kind].title}
                    description={`For ${reasonAction.client}.`}
                    label={REASON_CONFIG[reasonAction.kind].label}
                    placeholder={REASON_CONFIG[reasonAction.kind].placeholder}
                    confirmLabel={REASON_CONFIG[reasonAction.kind].confirmLabel}
                    destructive={
                        ![
                            'fundingOverride',
                            'checkInOverride',
                            'confirmOverride',
                        ].includes(reasonAction.kind)
                    }
                    onConfirm={runReason}
                />
            ) : null}
        </div>
    );
}
