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
import { ConfirmBookingModal } from './modals/booking-confirm';
import { OnboardModal } from './modals/onboard';
import { ReasonDialog } from './modals/reason-dialog';
import { ReferralIntakeModal } from './modals/referral-intake';
import { RequestIntakeModal } from './modals/request-intake';
import {
    CheckInModal,
    ComplaintModal,
    DischargeModal,
    IncidentModal,
    MedicationReconciliationModal,
    RestraintModal,
} from './modals/stay-actions';
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
    type RespiteBookingRow,
    type RespiteReferralRow,
    type RespiteRequestRow,
    type RespiteStayRow,
    type RespiteTab,
    type RespiteWorkspaceData,
} from './types';

type ReasonKind =
    | 'decline'
    | 'reject'
    | 'fundingOverride';

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
    fundingOverride: {
        title: 'Approve while funding is pending',
        label: 'Funding override reason',
        placeholder:
            'Why is this booking safe to approve before funding is verified?',
        confirmLabel: 'Approve request',
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
    // `null` = closed; a referral row = create a request from it; 'standalone' =
    // create a request for an existing client with no referral.
    const [requestFor, setRequestFor] = useState<
        RespiteReferralRow | 'standalone' | null
    >(null);
    const [onboardReq, setOnboardReq] = useState<RespiteRequestRow | null>(
        null,
    );
    const [confirmBooking, setConfirmBooking] =
        useState<RespiteBookingRow | null>(null);
    const [checkInStay, setCheckInStay] = useState<RespiteStayRow | null>(null);
    const [medRecStay, setMedRecStay] = useState<RespiteStayRow | null>(null);
    const [restraintStay, setRestraintStay] =
        useState<RespiteStayRow | null>(null);
    const [incidentStay, setIncidentStay] = useState<RespiteStayRow | null>(
        null,
    );
    const [dischargeStay, setDischargeStay] =
        useState<RespiteStayRow | null>(null);
    const [complaintStay, setComplaintStay] =
        useState<RespiteStayRow | null>(null);
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
                        onCreateRequest={(row) => setRequestFor(row)}
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
                        onNew={() => setRequestFor('standalone')}
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
                        onConfirm={(row) => setConfirmBooking(row)}
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
                        onCheckIn={(row) => setCheckInStay(row)}
                        onReconcile={(row) => setMedRecStay(row)}
                        onRecordRestraint={(row) => setRestraintStay(row)}
                        onLogIncident={(row) => setIncidentStay(row)}
                        onDischarge={(row) => setDischargeStay(row)}
                        onLogComplaint={(row) => setComplaintStay(row)}
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
            <RequestIntakeModal
                open={requestFor !== null}
                referral={requestFor === 'standalone' ? null : requestFor}
                onClose={() => setRequestFor(null)}
                clients={data.clients}
                serviceContexts={data.serviceContexts}
                serviceAgreements={data.serviceAgreements}
                fundingSources={data.fundingSources}
            />
            <OnboardModal
                request={onboardReq}
                onClose={() => setOnboardReq(null)}
            />
            <ConfirmBookingModal
                booking={confirmBooking}
                serviceAgreements={data.serviceAgreements}
                onClose={() => setConfirmBooking(null)}
            />
            <CheckInModal
                stay={checkInStay}
                onClose={() => setCheckInStay(null)}
            />
            <MedicationReconciliationModal
                stay={medRecStay}
                onClose={() => setMedRecStay(null)}
            />
            <RestraintModal
                stay={restraintStay}
                onClose={() => setRestraintStay(null)}
            />
            <IncidentModal
                stay={incidentStay}
                onClose={() => setIncidentStay(null)}
            />
            <DischargeModal
                stay={dischargeStay}
                onClose={() => setDischargeStay(null)}
            />
            <ComplaintModal
                stay={complaintStay}
                onClose={() => setComplaintStay(null)}
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
                        !['fundingOverride'].includes(reasonAction.kind)
                    }
                    onConfirm={runReason}
                />
            ) : null}
        </div>
    );
}
