import { ConfirmDialog } from '@/components/confirm-dialog';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import { GovernanceTermHint } from '@/components/governance/GovernanceTermHint';
import {
    MeetingPaperWorkspace,
    type PaperResolution,
} from '@/components/governance/MeetingPaperWorkspace';
import {
    meetingWorkspaceUrl,
    withQueryParams,
    type MeetingWorkspaceFocus,
} from '@/components/governance/meeting-workspace-links';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
    PageLayout,
    type PageHeaderMeterTone,
} from '@/components/page';
import {
    TierTwoTabs,
    type GroupedProfileNavTab,
} from '@/components/page/grouped-profile-nav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { InfoCard } from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import {
    formatDateLong,
    formatDateTimeLong,
    formatDurationMinutes,
    formatTime,
} from '@/lib/datetime';
import {
    agendaItemTypeLabel,
    governanceStatus,
    meetingTypeLabel,
    refSuffix,
    resolutionChip,
} from '@/lib/governance-labels';
import { canDoGovernance } from '@/lib/governance-permissions';
import { cn } from '@/lib/utils';
import {
    generate as generatePackRoute,
    show as showPack,
} from '@/routes/governance/packs';
import { show as showResolution } from '@/routes/governance/resolutions';
import { PageProps } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertTriangle,
    Archive,
    Calendar,
    CheckCircle,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    FileCheck,
    FileDown,
    FileText,
    History,
    ListChecks,
    Lock,
    MessageSquare,
    PenLine,
    Pencil,
    Plus,
    RotateCcw,
    Send,
    ShieldCheck,
    Users,
    Vote,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import {
    type AuthoritySubjectGroup,
    type AuthoritySubjects,
    type CommitteeOption,
    NewResolutionDialog,
    type UserOption,
} from '../Resolutions/_dialogs';
import { MeetingWizardDialog, type MeetingFormOptions } from './_dialogs';
import {
    attendanceMeter,
    checklistStatusChip,
    conflictsMeter,
    heldMeetingPrompt,
    isMeetingTab,
    MEETING_TAB_LABELS,
    meetingDayReached,
    meetingHasHappened,
    meetingStatusChip,
    minutesHistoryLabel,
    minutesStatusChip,
    orderResolutions,
    packMeter,
    quorumMeter,
    readinessVariant,
    readResolutionLabel,
    readWorkspaceLocation,
    recordedName,
    repliesMeter,
    resolutionVoteNote,
    votesMeter,
    workspaceTabKeys,
    type MeetingTab,
    type MeterReading,
    type MinutesHistoryEntry,
    type PackReading,
} from './_workspace';
import {
    AgendaItemDialog,
    AttendanceDialog,
    CorrectionDialog,
    flashErrorText,
    MinutesEditorDialog,
    RsvpDialog,
    SignMinutesDialog,
} from './_workspace-dialogs';

interface BoardMemberItem {
    id: number;
    user: { id: number; name: string };
}

interface AgendaItem {
    id: number;
    order: number;
    title: string;
    description: string | null;
    presenter: { name: string } | null;
    duration_minutes: number;
    item_type: string;
    is_confidential: boolean;
    resolution_id?: number | null;
}

interface ViewerRsvp {
    id: number;
    board_member_id: number;
    response: string;
    decline_reason: string | null;
    dietary_requirements: boolean;
    dietary_notes: string | null;
    responded_at: string | null;
    /** The reference of the stored reply (server-derived). */
    receipt_id?: string | null;
}

interface Meeting {
    id: number;
    title: string;
    meeting_type: string;
    board_committee_id?: number | null;
    chair_id?: number | null;
    secretary_id?: number | null;
    scheduled_at: string;
    duration_minutes: number;
    location: string | null;
    virtual_link: string | null;
    notes: string | null;
    status: string;
    quorum_met: boolean;
    quorum_required: number;
    chair: { user: { name: string }; id: number } | null;
    secretary: { user: { name: string }; id: number } | null;
    agenda_items: AgendaItem[];
    attendances: Array<{
        id: number;
        board_member_id: number;
        board_member?: { user?: { name: string } | null } | null;
        status: string;
        /** Only sent to the people running the meeting, or for the viewer's own row. */
        apology_reason?: string | null;
    }>;
    rsvps?: Array<{
        id: number;
        board_member_id: number;
        response: string;
        /** Only sent to the people running the meeting, or for the viewer's own reply. */
        decline_reason?: string | null;
        dietary_requirements?: boolean;
        dietary_notes?: string | null;
        responded_at: string | null;
        board_member?: { user?: { name: string } | null } | null;
    }>;
    minutes: {
        id: number;
        status: string;
        version_number: number;
        content_blocks: Array<{ heading: string; content: string }> | null;
        version_history?: MinutesHistoryEntry[] | null;
        /** Only for auditors and the people who approve or sign the minutes. */
        content_hash?: string;
        drafted_at?: string | null;
        reviewed_at?: string | null;
        signed_at?: string | null;
        drafter_name?: string | null;
        reviewer_name?: string | null;
        signer_name?: string | null;
    } | null;
    board_pack: {
        id: number;
        distributed_at: string | null;
    } | null;
}

interface ChecklistStep {
    key: string;
    label: string;
    status: string;
    status_label?: string | null;
    detail: string;
    action_label: string;
    action_url: string;
    blocked_by: string | null;
}

interface Props extends PageProps {
    meeting: Meeting;
    boardMembers: BoardMemberItem[];
    quorum: {
        present: number;
        required: number;
        total?: number;
        met: boolean;
    };
    canEdit: boolean;
    canManageMinutes: boolean;
    canApproveMinutes: boolean;
    canSignMinutes: boolean;
    /** Audit access: sees the minutes' record details (integrity codes). */
    canViewRecordDetails?: boolean;
    workflowChecklist: {
        counts: { done: number; remaining: number; blocked: number };
        next_step: ChecklistStep | null;
        items: ChecklistStep[];
    };
    /** Manager-only readiness summary (empty for members). */
    meetingCockpit: {
        cards: Array<{
            key: string;
            title: string;
            status: string;
            value: string | number;
            detail: string;
            href: string;
        }>;
    };
    /** The viewer's reading of the board pack they can see. */
    packReading?: PackReading | null;
    viewerCanRsvp?: boolean;
    viewerRsvp?: ViewerRsvp | null;
    /** Committee meetings: links to that committee's risk view and report. */
    committeeOversight?: {
        name: string;
        risks_href: string;
        report_href: string;
    } | null;
    /** Decision-paper wizard options — empty unless the viewer authors papers. */
    users?: UserOption[];
    committees?: CommitteeOption[];
    authoritySubjects?: AuthoritySubjects | null;
    authoritySubjectGroups?: AuthoritySubjectGroup[];
    canPublishPapers?: boolean;
    resolutions?: PaperResolution[];
    /** Edit wizard options; null unless the viewer may edit this meeting. */
    formOptions?: MeetingFormOptions | null;
}

const TAB_ICONS: Record<MeetingTab, LucideIcon> = {
    agenda: FileText,
    resolutions: Vote,
    attendance: Users,
    minutes: Pencil,
    workflow: ListChecks,
};

const READINESS_LINKS: Record<string, string> = {
    ceo_report: 'Open CEO report',
    pack_readiness: 'Open board pack',
    quorum: 'Open attendance',
    resolutions: 'Open resolutions',
    minutes: 'Open minutes',
    follow_through: 'Open actions',
};

function plural(count: number, one: string, many = `${one}s`): string {
    return `${count} ${count === 1 ? one : many}`;
}

function lowerFirst(text: string): string {
    return text.charAt(0).toLowerCase() + text.slice(1);
}

/** "?tab=minutes" links back into this workspace switch tabs instead of reloading. */
function inPageTab(href: string, meetingId: number): MeetingTab | null {
    const [path, query = ''] = href.split('?');
    if (path !== `/governance/meetings/${meetingId}`) return null;
    const tab = new URLSearchParams(query).get('tab');
    return isMeetingTab(tab) ? tab : null;
}

function Meter({
    label,
    reading,
    onClick,
    href,
    ariaLabel,
    bar,
}: {
    label: string;
    reading: MeterReading;
    onClick?: () => void;
    href?: string;
    ariaLabel: string;
    bar?: number;
}) {
    return (
        <PageHeaderMeterBlock
            label={label}
            href={href}
            onClick={onClick}
            ariaLabel={ariaLabel}
            tone={reading.tone as PageHeaderMeterTone}
        >
            <PageHeaderMeterBig>{reading.value}</PageHeaderMeterBig>
            {bar !== undefined ? <PageHeaderMeterBar percent={bar} /> : null}
            <PageHeaderMeterCaption>{reading.caption}</PageHeaderMeterCaption>
        </PageHeaderMeterBlock>
    );
}

export default function MeetingShow({
    auth,
    meeting,
    boardMembers,
    quorum,
    canEdit,
    canManageMinutes,
    canApproveMinutes,
    canSignMinutes,
    canViewRecordDetails = false,
    workflowChecklist,
    meetingCockpit,
    packReading = null,
    viewerCanRsvp,
    viewerRsvp,
    committeeOversight = null,
    users = [],
    committees = [],
    authoritySubjects = null,
    authoritySubjectGroups = [],
    canPublishPapers = false,
    resolutions: propResolutions,
    formOptions = null,
}: Props) {
    const page = usePage();
    const governancePermissions =
        (auth as { can?: { governance?: Record<string, unknown> } })?.can
            ?.governance ?? null;
    const canCreateResolution = canDoGovernance(
        governancePermissions,
        'resolutions',
        'manage',
    );
    const canViewResolutionRecords = canDoGovernance(
        governancePermissions,
        'resolutions',
        'view',
    );
    const canOpenEdit = canEdit && formOptions !== null;
    // Retired /meetings/{id}/edit deep links arrive as ?edit=1.
    const [editOpen, setEditOpen] = useDialogDeepLink('edit', canOpenEdit);

    // The meeting checklist and readiness summary are the chair and
    // secretary's work; members get meters about their own preparation.
    const canRunMeeting =
        canEdit || canManageMinutes || canApproveMinutes || canSignMinutes;

    const initialLocation = readWorkspaceLocation(page.url);
    const [activeTab, setActiveTab] = useState<MeetingTab>(
        initialLocation.tab === 'workflow' && !canRunMeeting
            ? 'agenda'
            : initialLocation.tab,
    );
    const [selectedPaperId, setSelectedPaperId] = useState<string | null>(
        initialLocation.paper,
    );
    const [paperFocus, setPaperFocus] = useState<MeetingWorkspaceFocus | null>(
        initialLocation.focus,
    );
    const [lastClosedPaperId, setLastClosedPaperId] = useState<string | null>(null);

    const [generatingPack, setGeneratingPack] = useState(false);
    const [packMessage, setPackMessage] = useState<string | null>(null);
    const [agendaDialogOpen, setAgendaDialogOpen] = useState(false);
    const [removingItem, setRemovingItem] = useState<AgendaItem | null>(null);
    const [attendanceDialogOpen, setAttendanceDialogOpen] = useState(false);
    const [rsvpDialogOpen, setRsvpDialogOpen] = useState(false);
    const [replySaved, setReplySaved] = useState(false);
    const [minutesDialogOpen, setMinutesDialogOpen] = useState(false);
    const [minutesConfirm, setMinutesConfirm] = useState<'review' | 'approve' | 'archive' | null>(null);
    const [signDialogOpen, setSignDialogOpen] = useState(false);
    const [correctionDialogOpen, setCorrectionDialogOpen] = useState(false);
    const [minutesError, setMinutesError] = useState<string | null>(null);
    const [historyOpen, setHistoryOpen] = useState(false);
    const [openVersion, setOpenVersion] = useState<number | null>(null);
    const [newResolutionOpen, setNewResolutionOpen] = useState(false);

    const agendaItems = meeting.agenda_items ?? [];
    const attendances = meeting.attendances ?? [];
    const rsvps = meeting.rsvps ?? [];
    // Agenda order — the same order "Next resolution" follows in a paper.
    const resolutions = useMemo(
        () => orderResolutions(meeting.agenda_items ?? [], propResolutions ?? []),
        [meeting.agenda_items, propResolutions],
    );
    const resolutionsById = new Map(resolutions.map((resolution) => [resolution.id, resolution]));
    const minutes = meeting.minutes;

    const happened = meetingHasHappened(meeting.scheduled_at, meeting.duration_minutes);
    const dayReached = meetingDayReached(meeting.scheduled_at);
    const heldPrompt = canRunMeeting
        ? heldMeetingPrompt({
              status: meeting.status,
              happened,
              attendanceRecorded: attendances.length > 0,
              minutesStarted: Boolean(minutes),
              canRecordAttendance: canEdit,
              canWriteMinutes: canManageMinutes,
          })
        : null;

    const replaceLocation = (patch: Record<string, string | null>) => {
        // router.replace keeps Inertia's history state intact, so browser Back
        // from an opened record lands on this exact tab and paper.
        router.replace({
            url: withQueryParams(page.url, patch),
            preserveState: true,
            preserveScroll: true,
        });
    };

    // Re-sync when the URL changes underneath us (history navigation, or a
    // return from a follow-up action).
    useEffect(() => {
        const location = readWorkspaceLocation(page.url);
        const hashTab = window.location.hash.replace('#tab-', '').replace('#', '');
        const next =
            new URLSearchParams(page.url.split('?')[1] ?? '').get('tab') === null &&
            !location.paper &&
            isMeetingTab(hashTab)
                ? hashTab
                : location.tab;
        setActiveTab(next === 'workflow' && !canRunMeeting ? 'agenda' : next);
        setSelectedPaperId(location.paper);
        if (location.focus) {
            setPaperFocus(location.focus);
            // Land once; a later reload should not jump to the follow-ups again.
            router.replace({
                url: withQueryParams(page.url, { focus: null }),
                preserveState: true,
                preserveScroll: true,
            });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [page.url]);

    const handleTabChange = (newTab: string) => {
        if (!isMeetingTab(newTab)) return;
        if (newTab === 'workflow' && !canRunMeeting) return;
        setActiveTab(newTab);
        replaceLocation({ tab: newTab });
    };

    const openPaper = (paperId: number | string) => {
        setSelectedPaperId(String(paperId));
        setPaperFocus(null);
        setActiveTab('resolutions');
        replaceLocation({ tab: 'resolutions', paper: String(paperId), focus: null });
    };

    const closePaper = () => {
        setLastClosedPaperId(selectedPaperId);
        setSelectedPaperId(null);
        setPaperFocus(null);
        replaceLocation({ paper: null, focus: null });
    };

    // Back from a paper: return the member to that paper's row in the list.
    useEffect(() => {
        if (!lastClosedPaperId || selectedPaperId) return;
        const row = document.getElementById(`meeting-paper-row-${lastClosedPaperId}`);
        if (row && typeof row.scrollIntoView === 'function') {
            row.scrollIntoView({ block: 'center' });
            row.focus({ preventScroll: true });
        }
    }, [lastClosedPaperId, selectedPaperId]);

    const openAttendanceDialog = () => {
        handleTabChange('attendance');
        setAttendanceDialogOpen(true);
    };

    const openMinutesEditor = () => {
        handleTabChange('minutes');
        setMinutesDialogOpen(true);
    };

    const generatePack = async () => {
        setGeneratingPack(true);
        setPackMessage(null);
        try {
            const response = await axios.post(
                generatePackRoute.url({ meeting: meeting.id }),
            );
            if (response?.data?.status === 'generated') {
                router.reload();
            } else {
                setPackMessage(
                    'The draft pack is being generated. Nothing is sent to members until it is distributed. Refresh the page in a minute to see it.',
                );
            }
        } catch {
            setPackMessage("The draft pack couldn't be generated. Please try again.");
        } finally {
            setGeneratingPack(false);
        }
    };

    const removeAgendaItem = (itemId: number) => {
        router.delete(`/governance/meetings/${meeting.id}/agenda/${itemId}`, {
            preserveScroll: true,
        });
    };

    /** Minutes steps that need only a confirmation; problems show inline. */
    const postMinutesStep = (
        path: string,
        data: Record<string, string | number | null> = {},
    ) => {
        setMinutesError(null);
        router.post(`/governance/meetings/${meeting.id}/${path}`, data, {
            preserveScroll: true,
            onSuccess: (visited) => {
                const error = flashErrorText(visited);
                if (error !== null) setMinutesError(error);
            },
            onError: (errors) => {
                const first = Object.values(errors ?? {}).find(Boolean);
                setMinutesError(
                    first ? String(first) : "That didn't work. Refresh the page and try again.",
                );
            },
        });
    };

    const scheduleLine = [
        `${formatDateLong(meeting.scheduled_at)}, ${formatTime(meeting.scheduled_at)}`,
        formatDurationMinutes(meeting.duration_minutes),
        meeting.location,
        meeting.virtual_link ? 'Video link available' : null,
    ]
        .filter(Boolean)
        .join(' · ');
    const peopleLine = [
        meetingTypeLabel(meeting.meeting_type),
        meeting.chair?.user?.name ? `Chair: ${meeting.chair.user.name}` : null,
        meeting.secretary?.user?.name ? `Secretary: ${meeting.secretary.user.name}` : null,
    ]
        .filter(Boolean)
        .join(' · ');
    const statusChip = meetingStatusChip(meeting.status);

    const workspaceTabs: GroupedProfileNavTab[] = workspaceTabKeys(canRunMeeting).map((key) => ({
        key,
        label: MEETING_TAB_LABELS[key],
        icon: TAB_ICONS[key],
        count:
            key === 'agenda'
                ? agendaItems.length
                : key === 'resolutions'
                  ? resolutions.length
                  : key === 'workflow'
                    ? workflowChecklist.counts.remaining
                    : undefined,
    }));

    const selectedResolution = selectedPaperId
        ? (resolutions.find((r) => String(r.id) === String(selectedPaperId)) ?? null)
        : null;
    const selectedIndex = selectedResolution ? resolutions.indexOf(selectedResolution) : -1;
    const nextPaper = selectedIndex >= 0 ? (resolutions[selectedIndex + 1] ?? null) : null;

    /* ---------------------------------------------------------------- */
    /*  Header: meters and the one primary action                        */
    /* ---------------------------------------------------------------- */

    const checklistTotal =
        workflowChecklist.counts.done + workflowChecklist.counts.remaining + workflowChecklist.counts.blocked;
    const meters: ReactNode[] = [];
    if (canRunMeeting && checklistTotal > 0) {
        meters.push(
            <Meter
                key="workflow"
                label="Workflow"
                ariaLabel="Open the meeting workflow"
                onClick={() => handleTabChange('workflow')}
                bar={Math.round((workflowChecklist.counts.done / checklistTotal) * 100)}
                reading={{
                    value: `${workflowChecklist.counts.done} of ${checklistTotal}`,
                    caption: 'Steps done',
                    tone: 'brand',
                }}
            />,
        );
    }
    if (viewerCanRsvp) {
        meters.push(
            <Meter
                key="pack"
                label="Board pack"
                ariaLabel={meeting.board_pack ? 'Open the board pack' : 'Open the agenda'}
                href={meeting.board_pack ? showPack.url({ pack: meeting.board_pack.id }) : undefined}
                onClick={meeting.board_pack ? undefined : () => handleTabChange('agenda')}
                reading={packMeter(packReading)}
            />,
            <Meter
                key="votes"
                label="Your votes"
                ariaLabel="Open the resolutions"
                onClick={() => handleTabChange('resolutions')}
                reading={votesMeter(resolutions)}
            />,
            <Meter
                key="conflicts"
                label="Conflicts"
                ariaLabel="Open the resolutions to declare a conflict of interest"
                onClick={() => handleTabChange('resolutions')}
                reading={conflictsMeter(resolutions)}
            />,
            <Meter
                key="attendance"
                label="Attendance"
                ariaLabel="Open attendance and your reply"
                onClick={() => handleTabChange('attendance')}
                reading={attendanceMeter(viewerRsvp?.response, happened)}
            />,
        );
    } else {
        meters.push(
            <Meter
                key="agenda"
                label="Agenda"
                ariaLabel="Open the agenda"
                onClick={() => handleTabChange('agenda')}
                reading={{
                    value: String(agendaItems.length),
                    caption: agendaItems.length === 0 ? 'Nothing on the agenda yet' : 'Items on the agenda',
                    tone: 'brand',
                }}
            />,
            <Meter
                key="resolutions"
                label="Resolutions"
                ariaLabel="Open the resolutions"
                onClick={() => handleTabChange('resolutions')}
                reading={{
                    value: String(resolutions.length),
                    caption: `${resolutions.filter((r) => r.status === 'open').length} open for voting`,
                    tone: 'brand',
                }}
            />,
            <Meter
                key="replies"
                label="Replies"
                ariaLabel="Open attendance and replies"
                onClick={() => handleTabChange('attendance')}
                reading={repliesMeter(rsvps)}
            />,
        );
    }
    if (dayReached) {
        meters.push(
            <Meter
                key="quorum"
                label="Quorum"
                ariaLabel="Open attendance and the quorum"
                onClick={() => handleTabChange('attendance')}
                reading={quorumMeter(quorum)}
            />,
        );
    }

    let primaryAction: ReactNode = null;
    if (heldPrompt?.primary === 'attendance') {
        primaryAction = (
            <PageHeaderPrimaryButton icon={Users} onClick={openAttendanceDialog}>
                Record attendance
            </PageHeaderPrimaryButton>
        );
    } else if (heldPrompt?.primary === 'minutes') {
        primaryAction = (
            <PageHeaderPrimaryButton icon={PenLine} onClick={openMinutesEditor}>
                Write the minutes
            </PageHeaderPrimaryButton>
        );
    } else if (meeting.board_pack) {
        primaryAction = (
            <PageHeaderPrimaryButton
                icon={FileDown}
                onClick={() => router.visit(showPack.url({ pack: meeting.board_pack!.id }))}
                dusk="view-pack"
            >
                View pack
            </PageHeaderPrimaryButton>
        );
    } else if (canEdit && !happened) {
        primaryAction = (
            <PageHeaderPrimaryButton
                icon={FileDown}
                onClick={generatePack}
                disabled={generatingPack}
                dusk="generate-pack"
            >
                {generatingPack ? 'Generating…' : 'Generate draft pack'}
            </PageHeaderPrimaryButton>
        );
    }

    /* ---------------------------------------------------------------- */
    /*  Checklist step actions                                           */
    /* ---------------------------------------------------------------- */

    const stepAction = (step: ChecklistStep, primary = false): ReactNode => {
        const variant = primary ? 'default' : 'outline';
        if (step.key === 'pack_generated' && canEdit && !meeting.board_pack && !happened) {
            return (
                <Button size="sm" variant={variant} onClick={generatePack} disabled={generatingPack}>
                    {generatingPack ? 'Generating…' : 'Generate draft pack'}
                </Button>
            );
        }
        if (step.key === 'quorum' && canEdit) {
            return (
                <Button size="sm" variant={variant} onClick={openAttendanceDialog}>
                    Record attendance
                </Button>
            );
        }
        if (step.key === 'minutes_drafted' && canManageMinutes && !minutes) {
            return (
                <Button size="sm" variant={variant} onClick={openMinutesEditor}>
                    Write the minutes
                </Button>
            );
        }
        if (!step.action_url) return null;
        const tab = inPageTab(step.action_url, meeting.id);
        if (tab) {
            return (
                <Button size="sm" variant={variant} onClick={() => handleTabChange(tab)}>
                    {step.action_label}
                </Button>
            );
        }
        if (step.action_url === `/governance/meetings/${meeting.id}`) return null;
        return (
            <Button asChild size="sm" variant={variant}>
                <Link href={step.action_url}>{step.action_label}</Link>
            </Button>
        );
    };

    const minutesChip = minutes ? minutesStatusChip(minutes.status) : null;
    const minutesLocked = Boolean(minutes && ['approved', 'signed', 'archived'].includes(minutes.status));
    const history = minutes?.version_history ?? [];

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Meetings', href: '/governance/meetings' },
                { title: meeting.title, href: meetingWorkspaceUrl(meeting.id) },
            ]}
        >
            <Head title={meeting.title} />

            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref="/governance/meetings"
                        icon={Calendar}
                        title={meeting.title}
                        titleDusk="meeting-title"
                        wrapTitle
                        titleChip={
                            <PageHeaderStatusChip variant={statusChip.variant}>
                                {statusChip.label}
                            </PageHeaderStatusChip>
                        }
                        subline={
                            <>
                                <span className="block">{scheduleLine}</span>
                                <span className="block">{peopleLine}</span>
                            </>
                        }
                        meters={<>{meters}</>}
                        actions={
                            <>
                                {committeeOversight ? (
                                    <>
                                        <PageHeaderGlassButton
                                            icon={ShieldCheck}
                                            onClick={() => router.visit(committeeOversight.risks_href)}
                                            aria-label={`Committee risks: ${committeeOversight.name}`}
                                        >
                                            Committee risks
                                        </PageHeaderGlassButton>
                                        <PageHeaderGlassButton
                                            icon={FileText}
                                            onClick={() => router.visit(committeeOversight.report_href)}
                                            aria-label={`Committee report: ${committeeOversight.name}`}
                                        >
                                            Committee report
                                        </PageHeaderGlassButton>
                                    </>
                                ) : null}
                                {canOpenEdit ? (
                                    <PageHeaderGlassButton
                                        icon={Pencil}
                                        onClick={() => setEditOpen(true)}
                                        dusk="edit-meeting"
                                    >
                                        Edit meeting
                                    </PageHeaderGlassButton>
                                ) : null}
                                {primaryAction}
                            </>
                        }
                    />
                }
                tabs={
                    <TierTwoTabs
                        tabs={workspaceTabs}
                        activeTab={activeTab}
                        onTab={handleTabChange}
                        testIdPrefix="meeting"
                        ariaLabel="Meeting workspace sections"
                        panelId="meeting-workspace-panel"
                        renderLink={() => null}
                    />
                }
            >
                <div className="flex flex-col gap-5">
                    {packMessage ? (
                        <p
                            role="status"
                            className="rounded-lg border border-border bg-card px-4 py-3 text-sm text-foreground"
                        >
                            {packMessage}
                        </p>
                    ) : null}

                    {heldPrompt ? (
                        <div
                            role="note"
                            className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-status-warning/35 bg-status-warning-bg p-4"
                            data-test="meeting-held-prompt"
                        >
                            <div className="flex min-w-0 items-start gap-3">
                                <AlertTriangle className="mt-0.5 size-4 shrink-0 text-status-warning" aria-hidden="true" />
                                <div className="min-w-0">
                                    <p className="text-section-title">{heldPrompt.title}</p>
                                    <p className="text-subtle mt-0.5">{heldPrompt.body}</p>
                                </div>
                            </div>
                            {heldPrompt.secondary === 'minutes' ? (
                                <Button size="sm" variant="outline" onClick={openMinutesEditor}>
                                    <PenLine className="h-4 w-4" />
                                    Write the minutes
                                </Button>
                            ) : null}
                        </div>
                    ) : null}

                    {viewerCanRsvp && !viewerRsvp && !happened ? (
                        <Card
                            className="flex-row flex-wrap items-center justify-between gap-3 p-4"
                            data-test="meeting-rsvp-banner"
                        >
                            <div className="flex min-w-0 items-start gap-3">
                                <span className="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                    <MessageSquare className="size-4" aria-hidden="true" />
                                </span>
                                <div className="min-w-0">
                                    <p className="text-section-title">Will you be at this meeting?</p>
                                    <p className="text-subtle mt-0.5">
                                        Let the secretary know whether you're attending or sending apologies.
                                    </p>
                                </div>
                            </div>
                            <Button
                                size="sm"
                                onClick={() => setRsvpDialogOpen(true)}
                                dusk="meeting-rsvp-trigger"
                                data-test="meeting-rsvp-trigger"
                            >
                                Reply to the invitation
                            </Button>
                        </Card>
                    ) : null}

                    {viewerRsvp && replySaved ? (
                        <div className="rounded-lg border border-status-success/30 bg-status-success-bg p-4">
                            <ReplyReceipt rsvp={viewerRsvp} />
                        </div>
                    ) : null}

                    <section
                        id="meeting-workspace-panel"
                        role="tabpanel"
                        aria-labelledby={`meeting-tab-${activeTab}`}
                        className="flex flex-col gap-5"
                    >
                        {/* ========== AGENDA ========== */}
                        {activeTab === 'agenda' ? (
                            <Card>
                                <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <CardTitle className="text-section-title">Agenda</CardTitle>
                                        <CardDescription>
                                            {agendaItems.length === 0
                                                ? 'Nothing on the agenda yet'
                                                : `${plural(agendaItems.length, 'item')} · about ${formatDurationMinutes(
                                                      agendaItems.reduce((sum, item) => sum + (item.duration_minutes ?? 0), 0),
                                                  )}`}
                                        </CardDescription>
                                    </div>
                                    {canEdit ? (
                                        <Button size="sm" onClick={() => setAgendaDialogOpen(true)}>
                                            <Plus className="h-4 w-4" />
                                            Add agenda item
                                        </Button>
                                    ) : null}
                                </CardHeader>
                                <CardContent>
                                    {agendaItems.length === 0 ? (
                                        <EmptyState
                                            icon={FileText}
                                            title="No agenda items yet"
                                            description={
                                                canEdit
                                                    ? 'Add the topics for this meeting so the board pack has something in it.'
                                                    : "The secretary hasn't added the agenda yet."
                                            }
                                        />
                                    ) : (
                                        <ol className="flex flex-col gap-3">
                                            {agendaItems.map((item) => {
                                                const paper = item.resolution_id
                                                    ? resolutionsById.get(item.resolution_id)
                                                    : undefined;
                                                return (
                                                    <li
                                                        key={item.id}
                                                        className={cn(
                                                            'flex flex-wrap items-start gap-4 rounded-lg border p-4',
                                                            item.is_confidential
                                                                ? 'border-primary/40 bg-primary/5'
                                                                : 'border-border',
                                                        )}
                                                    >
                                                        <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-sm font-semibold text-muted-foreground">
                                                            {item.order}
                                                        </span>
                                                        <div className="min-w-0 flex-1">
                                                            <div className="flex flex-wrap items-center gap-2">
                                                                {item.item_type === 'decision' ? (
                                                                    <Vote className="size-4 text-primary" aria-hidden="true" />
                                                                ) : item.item_type === 'consent' ? (
                                                                    <CheckCircle className="size-4 text-muted-foreground" aria-hidden="true" />
                                                                ) : (
                                                                    <FileText className="size-4 text-muted-foreground" aria-hidden="true" />
                                                                )}
                                                                <h3 className="text-sm font-semibold text-foreground">{item.title}</h3>
                                                                {item.is_confidential ? (
                                                                    <StatusBadge variant="warning">
                                                                        <Lock className="size-3" aria-hidden="true" />
                                                                        Confidential
                                                                    </StatusBadge>
                                                                ) : null}
                                                                <Badge variant="outline">{agendaItemTypeLabel(item.item_type)}</Badge>
                                                            </div>
                                                            {item.description ? (
                                                                <p className="text-subtle mt-1 whitespace-pre-wrap">{item.description}</p>
                                                            ) : null}
                                                            <p className="text-caption mt-1">
                                                                {[
                                                                    item.presenter ? `Presented by ${item.presenter.name}` : null,
                                                                    formatDurationMinutes(item.duration_minutes),
                                                                ]
                                                                    .filter(Boolean)
                                                                    .join(' · ')}
                                                            </p>
                                                        </div>
                                                        <div className="flex shrink-0 flex-wrap items-center gap-2">
                                                            {paper ? (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    onClick={() => openPaper(paper.id)}
                                                                    data-test="agenda-open-resolution"
                                                                >
                                                                    <Vote className="h-4 w-4" aria-hidden="true" />
                                                                    {readResolutionLabel(paper)}
                                                                </Button>
                                                            ) : null}
                                                            {canEdit ? (
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() => setRemovingItem(item)}
                                                                    aria-label={`Remove ${item.title} from the agenda`}
                                                                >
                                                                    Remove
                                                                </Button>
                                                            ) : null}
                                                        </div>
                                                    </li>
                                                );
                                            })}
                                        </ol>
                                    )}
                                </CardContent>
                            </Card>
                        ) : null}

                        {/* ========== RESOLUTIONS ========== */}
                        {activeTab === 'resolutions' &&
                            (selectedResolution ? (
                                <MeetingPaperWorkspace
                                    key={selectedResolution.id}
                                    resolution={selectedResolution}
                                    meetingId={meeting.id}
                                    meetingTitle={meeting.title}
                                    onClose={closePaper}
                                    nextPaper={nextPaper}
                                    onOpenPaper={openPaper}
                                    focus={paperFocus}
                                    fullRecordHref={
                                        canViewResolutionRecords
                                            ? showResolution.url({ resolution: selectedResolution.id })
                                            : null
                                    }
                                />
                            ) : (
                                <Card>
                                    <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <CardTitle className="text-section-title">Resolutions</CardTitle>
                                            <CardDescription>
                                                {resolutions.length === 0
                                                    ? 'No resolutions for this meeting yet'
                                                    : [
                                                          plural(resolutions.length, 'resolution'),
                                                          viewerCanRsvp
                                                              ? votesMeter(resolutions).caption.toLowerCase()
                                                              : null,
                                                      ]
                                                          .filter(Boolean)
                                                          .join(' · ')}
                                            </CardDescription>
                                        </div>
                                        {canCreateResolution ? (
                                            <Button
                                                size="sm"
                                                onClick={() => setNewResolutionOpen(true)}
                                                dusk="new-resolution-button"
                                            >
                                                <Plus className="h-4 w-4" />
                                                New resolution
                                            </Button>
                                        ) : null}
                                    </CardHeader>
                                    <CardContent>
                                        {resolutions.length === 0 ? (
                                            <EmptyState
                                                icon={Vote}
                                                title="No resolutions yet"
                                                description="Resolutions appear here when the secretary adds them to the agenda."
                                            />
                                        ) : (
                                            <ul className="flex flex-col gap-2">
                                                {resolutions.map((resolution) => {
                                                    const chip = resolutionChip(resolution.status, resolution.outcome);
                                                    const note = resolutionVoteNote(resolution);
                                                    return (
                                                        <li
                                                            key={resolution.id}
                                                            id={`meeting-paper-row-${resolution.id}`}
                                                            tabIndex={-1}
                                                            onClick={() => openPaper(resolution.id)}
                                                            className={cn(
                                                                'flex cursor-pointer flex-wrap items-center justify-between gap-3 rounded-lg border p-3 transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                                                String(resolution.id) === lastClosedPaperId
                                                                    ? 'border-primary/40 bg-primary/5'
                                                                    : 'border-border hover:bg-muted',
                                                            )}
                                                            data-test="meeting-paper-row"
                                                        >
                                                            <div className="min-w-0">
                                                                <p className="font-medium text-foreground">{resolution.title}</p>
                                                                <p className="text-caption">
                                                                    {[note, refSuffix(resolution.resolution_reference)]
                                                                        .filter(Boolean)
                                                                        .join(' · ')}
                                                                </p>
                                                            </div>
                                                            <div className="flex shrink-0 items-center gap-2">
                                                                <StatusBadge variant={chip.variant}>{chip.label}</StatusBadge>
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    onClick={(event) => {
                                                                        event.stopPropagation();
                                                                        openPaper(resolution.id);
                                                                    }}
                                                                    aria-label={`${readResolutionLabel(resolution)}: ${resolution.title}`}
                                                                >
                                                                    {readResolutionLabel(resolution)}
                                                                    <ChevronRight className="h-4 w-4" aria-hidden="true" />
                                                                </Button>
                                                            </div>
                                                        </li>
                                                    );
                                                })}
                                            </ul>
                                        )}
                                    </CardContent>
                                </Card>
                            ))}

                        {/* ========== ATTENDANCE ========== */}
                        {activeTab === 'attendance' ? (
                            <>
                                {viewerCanRsvp ? (
                                    <Card data-test="meeting-your-reply">
                                        <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
                                            <div className="min-w-0">
                                                <CardTitle className="text-section-title">Your reply</CardTitle>
                                                <CardDescription>
                                                    {viewerRsvp
                                                        ? 'What you told the secretary about this meeting.'
                                                        : happened
                                                          ? "You didn't reply to the invitation for this meeting."
                                                          : "You haven't replied to the invitation yet."}
                                                </CardDescription>
                                            </div>
                                            {!happened ? (
                                                <Button
                                                    size="sm"
                                                    variant={viewerRsvp ? 'outline' : 'default'}
                                                    onClick={() => setRsvpDialogOpen(true)}
                                                >
                                                    {viewerRsvp ? 'Change reply' : 'Reply to the invitation'}
                                                </Button>
                                            ) : null}
                                        </CardHeader>
                                        {viewerRsvp ? (
                                            <CardContent className="flex flex-col gap-2">
                                                <ReplyReceipt rsvp={viewerRsvp} />
                                                {viewerRsvp.decline_reason ? (
                                                    <p className="text-subtle">{`Your reason: ${viewerRsvp.decline_reason}`}</p>
                                                ) : null}
                                                {viewerRsvp.dietary_notes ? (
                                                    <p className="text-subtle">{`Dietary or access needs: ${viewerRsvp.dietary_notes}`}</p>
                                                ) : null}
                                            </CardContent>
                                        ) : null}
                                    </Card>
                                ) : null}

                                <Card>
                                    <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
                                        <div className="min-w-0">
                                            <CardTitle className="text-section-title">Attendance</CardTitle>
                                            <CardDescription>
                                                {dayReached
                                                    ? 'Who was at the meeting. The secretary or chair records this.'
                                                    : 'Attendance is recorded at the meeting.'}
                                            </CardDescription>
                                        </div>
                                        {canEdit ? (
                                            <Button size="sm" onClick={() => setAttendanceDialogOpen(true)} dusk="record-attendance">
                                                <Users className="h-4 w-4" />
                                                Record attendance
                                            </Button>
                                        ) : null}
                                    </CardHeader>
                                    <CardContent className="flex flex-col gap-4">
                                        <div className="flex flex-wrap items-center gap-2 rounded-lg border border-border p-3 text-sm">
                                            {dayReached ? (
                                                <StatusBadge variant={quorum.met ? 'success' : 'warning'}>
                                                    {quorum.met ? 'Quorum met' : 'Quorum not met yet'}
                                                </StatusBadge>
                                            ) : null}
                                            <span className="text-foreground">
                                                {dayReached
                                                    ? `${quorum.present} of the ${quorum.required} members needed for decisions to be valid are recorded as present.`
                                                    : `At least ${quorum.required}${
                                                          quorum.total !== undefined ? ` of the ${quorum.total}` : ''
                                                      } members must be present for decisions to be valid.`}
                                            </span>
                                            <GovernanceTermHint term="quorum" />
                                        </div>
                                        {canRunMeeting && !canEdit && attendances.length === 0 && dayReached ? (
                                            <p className="text-subtle">
                                                {`Attendance can no longer be changed for this meeting (${statusChip.label.toLowerCase()}). An administrator can help if it still needs recording.`}
                                            </p>
                                        ) : null}
                                        {attendances.length === 0 ? (
                                            <EmptyState
                                                icon={Users}
                                                title={dayReached ? "Attendance hasn't been recorded" : 'Recorded on the day'}
                                                description={
                                                    dayReached
                                                        ? canEdit
                                                            ? 'Record who was present so the quorum and the minutes are right.'
                                                            : 'The secretary or chair records who was present.'
                                                        : 'The secretary or chair records who is present at the meeting.'
                                                }
                                            />
                                        ) : (
                                            <ul className="flex flex-col divide-y divide-border">
                                                {attendances.map((attendance) => {
                                                    const chip = governanceStatus('attendance_status', attendance.status);
                                                    return (
                                                        <li
                                                            key={attendance.id}
                                                            className="flex flex-wrap items-center justify-between gap-3 py-2.5"
                                                        >
                                                            <div className="min-w-0">
                                                                <p className="text-sm font-medium text-foreground">
                                                                    {attendance.board_member?.user?.name ?? 'Board member'}
                                                                </p>
                                                                {attendance.apology_reason ? (
                                                                    <p className="text-caption">{`Reason: ${attendance.apology_reason}`}</p>
                                                                ) : null}
                                                            </div>
                                                            <StatusBadge variant={chip.variant}>{chip.label}</StatusBadge>
                                                        </li>
                                                    );
                                                })}
                                            </ul>
                                        )}
                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-section-title">Replies to the invitation</CardTitle>
                                        <CardDescription>
                                            {rsvps.length === 0
                                                ? 'Nobody has replied yet'
                                                : plural(rsvps.length, 'reply', 'replies')}
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        {rsvps.length === 0 ? (
                                            <EmptyState
                                                icon={MessageSquare}
                                                title="No replies yet"
                                                description="Members' replies to the invitation appear here."
                                            />
                                        ) : (
                                            <ul className="flex flex-col divide-y divide-border">
                                                {rsvps.map((rsvp) => {
                                                    const chip = governanceStatus('rsvp_response', rsvp.response);
                                                    return (
                                                        <li
                                                            key={rsvp.id}
                                                            className="flex flex-wrap items-center justify-between gap-3 py-2.5"
                                                        >
                                                            <div className="min-w-0">
                                                                <p className="text-sm font-medium text-foreground">
                                                                    {rsvp.board_member?.user?.name ?? 'Board member'}
                                                                </p>
                                                                {rsvp.decline_reason ? (
                                                                    <p className="text-caption">{`Reason: ${rsvp.decline_reason}`}</p>
                                                                ) : null}
                                                                {rsvp.dietary_notes ? (
                                                                    <p className="text-caption">{`Dietary or access needs: ${rsvp.dietary_notes}`}</p>
                                                                ) : null}
                                                            </div>
                                                            <div className="flex items-center gap-2">
                                                                <StatusBadge variant={chip.variant}>{chip.label}</StatusBadge>
                                                                {rsvp.responded_at ? (
                                                                    <span className="text-caption">{formatDateLong(rsvp.responded_at)}</span>
                                                                ) : null}
                                                            </div>
                                                        </li>
                                                    );
                                                })}
                                            </ul>
                                        )}
                                    </CardContent>
                                </Card>
                            </>
                        ) : null}

                        {/* ========== MINUTES ========== */}
                        {activeTab === 'minutes' ? (
                            <Card>
                                <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div className="min-w-0">
                                        <CardTitle className="text-section-title flex flex-wrap items-center gap-2">
                                            Minutes
                                            {minutesChip ? (
                                                <StatusBadge variant={minutesChip.variant}>{minutesChip.label}</StatusBadge>
                                            ) : null}
                                        </CardTitle>
                                        <CardDescription>
                                            {minutes ? `Version ${minutes.version_number}` : 'No minutes yet'}
                                        </CardDescription>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        {canManageMinutes && (!minutes || ['draft', 'reviewed'].includes(minutes.status)) ? (
                                            <Button
                                                size="sm"
                                                variant={minutes ? 'outline' : 'default'}
                                                onClick={() => setMinutesDialogOpen(true)}
                                                dusk="edit-minutes"
                                            >
                                                {minutes ? <Pencil className="h-4 w-4" /> : <PenLine className="h-4 w-4" />}
                                                {minutes ? 'Edit draft' : 'Write the minutes'}
                                            </Button>
                                        ) : null}
                                        {minutes?.status === 'draft' && canManageMinutes ? (
                                            <Button
                                                size="sm"
                                                variant={canApproveMinutes ? 'outline' : 'default'}
                                                onClick={() => setMinutesConfirm('review')}
                                                dusk="submit-review-minutes"
                                            >
                                                <Send className="h-4 w-4" />
                                                Send for approval
                                            </Button>
                                        ) : null}
                                        {minutes && ['draft', 'reviewed'].includes(minutes.status) && canApproveMinutes ? (
                                            <Button size="sm" onClick={() => setMinutesConfirm('approve')} dusk="approve-minutes">
                                                <FileCheck className="h-4 w-4" />
                                                Approve minutes
                                            </Button>
                                        ) : null}
                                        {minutes?.status === 'approved' && canSignMinutes ? (
                                            <Button size="sm" onClick={() => setSignDialogOpen(true)} dusk="sign-minutes-button">
                                                <ShieldCheck className="h-4 w-4" />
                                                Sign minutes
                                            </Button>
                                        ) : null}
                                        {minutesLocked && canManageMinutes ? (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => setCorrectionDialogOpen(true)}
                                                dusk="correction-minutes-button"
                                            >
                                                <RotateCcw className="h-4 w-4" />
                                                Start a correction
                                            </Button>
                                        ) : null}
                                        {minutes?.status === 'signed' && canApproveMinutes ? (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => setMinutesConfirm('archive')}
                                                dusk="archive-minutes-button"
                                            >
                                                <Archive className="h-4 w-4" />
                                                Archive minutes
                                            </Button>
                                        ) : null}
                                    </div>
                                </CardHeader>

                                <CardContent className="flex flex-col gap-5">
                                    {minutesError ? (
                                        <div
                                            role="alert"
                                            className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical"
                                        >
                                            <span className="flex items-center gap-2">
                                                <AlertTriangle className="h-4 w-4 shrink-0" aria-hidden="true" />
                                                {minutesError}
                                            </span>
                                            <Button size="sm" variant="outline" onClick={() => router.reload()}>
                                                Refresh page
                                            </Button>
                                        </div>
                                    ) : null}

                                    {minutes ? (
                                        <>
                                            <dl className="grid gap-4 rounded-lg border border-border p-4 sm:grid-cols-2 lg:grid-cols-4">
                                                <div>
                                                    <dt className="text-caption font-semibold">Status</dt>
                                                    <dd className="mt-1">
                                                        {minutesChip ? (
                                                            <StatusBadge variant={minutesChip.variant}>{minutesChip.label}</StatusBadge>
                                                        ) : null}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="text-caption font-semibold">Written by</dt>
                                                    <dd className="mt-1 text-sm text-foreground">
                                                        {recordedName(minutes.drafter_name) ?? 'Name not recorded'}
                                                    </dd>
                                                    {minutes.drafted_at ? (
                                                        <dd className="text-caption">{formatDateTimeLong(minutes.drafted_at)}</dd>
                                                    ) : null}
                                                </div>
                                                <div>
                                                    <dt className="text-caption font-semibold">Approved by</dt>
                                                    <dd className="mt-1 text-sm text-foreground">
                                                        {minutes.reviewed_at
                                                            ? (recordedName(minutes.reviewer_name) ?? 'Name not recorded')
                                                            : 'Not approved yet'}
                                                    </dd>
                                                    {minutes.reviewed_at ? (
                                                        <dd className="text-caption">{formatDateTimeLong(minutes.reviewed_at)}</dd>
                                                    ) : null}
                                                </div>
                                                <div>
                                                    <dt className="text-caption font-semibold">Signed by</dt>
                                                    <dd className="mt-1 text-sm text-foreground">
                                                        {minutes.signed_at
                                                            ? (recordedName(minutes.signer_name) ?? 'Name not recorded')
                                                            : 'Not signed yet'}
                                                    </dd>
                                                    {minutes.signed_at ? (
                                                        <dd className="text-caption">{formatDateTimeLong(minutes.signed_at)}</dd>
                                                    ) : null}
                                                </div>
                                            </dl>

                                            {minutesLocked ? (
                                                <InfoCard icon={Lock}>
                                                    Approved minutes can't be edited. The secretary can start a correction if something is wrong.
                                                </InfoCard>
                                            ) : null}

                                            {minutes.content_blocks && minutes.content_blocks.length > 0 ? (
                                                <div className="flex flex-col gap-5">
                                                    {minutes.content_blocks.map((block, index) => (
                                                        <Card key={index} className="gap-2 p-4 shadow-none">
                                                            <h3 className="text-section-title border-b border-border pb-1.5">
                                                                {block.heading || `Section ${index + 1}`}
                                                            </h3>
                                                            <p className="text-sm leading-relaxed whitespace-pre-wrap text-foreground">
                                                                {block.content || (
                                                                    <span className="text-muted-foreground italic">
                                                                        Nothing written under this heading.
                                                                    </span>
                                                                )}
                                                            </p>
                                                        </Card>
                                                    ))}
                                                </div>
                                            ) : null}

                                            {history.length > 0 ? (
                                                <div className="rounded-lg border border-border p-4">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        onClick={() => setHistoryOpen((open) => !open)}
                                                        aria-expanded={historyOpen}
                                                        className="w-full justify-between"
                                                    >
                                                        <span className="flex items-center gap-2">
                                                            <History className="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                                                            {`Version history (${history.length})`}
                                                        </span>
                                                        {historyOpen ? (
                                                            <ChevronDown className="h-4 w-4" aria-hidden="true" />
                                                        ) : (
                                                            <ChevronRight className="h-4 w-4" aria-hidden="true" />
                                                        )}
                                                    </Button>
                                                    {historyOpen ? (
                                                        <ol className="mt-3 flex flex-col gap-3 border-t border-border pt-3">
                                                            {history.map((entry, index) => {
                                                                const blocks = entry.content_blocks ?? [];
                                                                const isOpen = openVersion === index;
                                                                return (
                                                                    <li key={index} className="rounded-md border border-border p-3 text-sm">
                                                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                                                            <span className="font-medium text-foreground">
                                                                                {`${entry.version ? `Version ${entry.version}` : 'Earlier version'} · ${minutesHistoryLabel(entry)}`}
                                                                            </span>
                                                                            <span className="text-caption">
                                                                                {[
                                                                                    entry.at ? formatDateTimeLong(entry.at) : null,
                                                                                    entry.actor_name ? `by ${entry.actor_name}` : null,
                                                                                ]
                                                                                    .filter(Boolean)
                                                                                    .join(' · ')}
                                                                            </span>
                                                                        </div>
                                                                        {entry.reason_for_correction ? (
                                                                            <p className="text-subtle mt-1">
                                                                                {`What needed correcting: ${entry.reason_for_correction}`}
                                                                            </p>
                                                                        ) : null}
                                                                        {blocks.length > 0 ? (
                                                                            <div className="mt-2">
                                                                                <Button
                                                                                    type="button"
                                                                                    variant="ghost"
                                                                                    size="sm"
                                                                                    aria-expanded={isOpen}
                                                                                    onClick={() => setOpenVersion(isOpen ? null : index)}
                                                                                >
                                                                                    {isOpen ? 'Hide this version' : 'Show this version'}
                                                                                </Button>
                                                                                {isOpen ? (
                                                                                    <Card className="mt-2 gap-2 p-3 shadow-none">
                                                                                        {blocks.map((block, blockIndex) => (
                                                                                            <div key={blockIndex}>
                                                                                                <p className="font-medium text-foreground">{block.heading}</p>
                                                                                                <p className="text-subtle whitespace-pre-wrap">
                                                                                                    {block.content || 'Nothing written under this heading.'}
                                                                                                </p>
                                                                                            </div>
                                                                                        ))}
                                                                                    </Card>
                                                                                ) : null}
                                                                            </div>
                                                                        ) : null}
                                                                    </li>
                                                                );
                                                            })}
                                                        </ol>
                                                    ) : null}
                                                </div>
                                            ) : null}

                                            {canViewRecordDetails ? (
                                                <details className="rounded-lg border border-border p-4" data-test="minutes-record-details">
                                                    <summary className="cursor-pointer text-sm font-medium text-foreground">
                                                        Record details
                                                    </summary>
                                                    <p className="text-subtle mt-2">
                                                        For audits: integrity codes that show whether the minutes changed after each step.
                                                    </p>
                                                    <dl className="mt-3 flex flex-col gap-2 text-sm">
                                                        {minutes.content_hash ? (
                                                            <div>
                                                                <dt className="text-caption font-semibold">
                                                                    {`Version ${minutes.version_number} (current) · integrity code (SHA-256)`}
                                                                </dt>
                                                                <dd className="font-mono text-xs break-all text-foreground">{minutes.content_hash}</dd>
                                                            </div>
                                                        ) : null}
                                                        {history
                                                            .filter((entry) => entry.content_hash)
                                                            .map((entry, index) => (
                                                                <div key={index}>
                                                                    <dt className="text-caption font-semibold">
                                                                        {`${entry.version ? `Version ${entry.version}` : 'Earlier version'} · ${minutesHistoryLabel(entry)}`}
                                                                    </dt>
                                                                    <dd className="font-mono text-xs break-all text-foreground">{entry.content_hash}</dd>
                                                                </div>
                                                            ))}
                                                    </dl>
                                                </details>
                                            ) : null}
                                        </>
                                    ) : (
                                        <EmptyState
                                            icon={FileText}
                                            title="No minutes yet"
                                            description={
                                                happened
                                                    ? 'The secretary writes the minutes after the meeting.'
                                                    : 'Minutes are written after the meeting.'
                                            }
                                            action={
                                                canManageMinutes ? (
                                                    <Button size="sm" onClick={() => setMinutesDialogOpen(true)} dusk="create-first-minutes">
                                                        <PenLine className="h-4 w-4" />
                                                        Write the minutes
                                                    </Button>
                                                ) : undefined
                                            }
                                        />
                                    )}
                                </CardContent>
                            </Card>
                        ) : null}

                        {/* ========== WORKFLOW (people running the meeting) ========== */}
                        {activeTab === 'workflow' && canRunMeeting ? (
                            <Card dusk="meeting-workflow-checklist-card">
                                <CardHeader>
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <CardTitle className="text-section-title">Workflow</CardTitle>
                                            <CardDescription>
                                                The steps to prepare, hold and finish this meeting. Only the people running it see this.
                                            </CardDescription>
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            <StatusBadge variant="success">{`${workflowChecklist.counts.done} done`}</StatusBadge>
                                            <StatusBadge variant="neutral">{`${workflowChecklist.counts.remaining} to do`}</StatusBadge>
                                            {workflowChecklist.counts.blocked > 0 ? (
                                                <StatusBadge variant="neutral">
                                                    {`${workflowChecklist.counts.blocked} waiting on an earlier step`}
                                                </StatusBadge>
                                            ) : null}
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-5">
                                    {workflowChecklist.next_step ? (
                                        <div className="rounded-lg border border-primary/30 bg-primary/5 p-4">
                                            <p className="text-caption font-semibold">Next step</p>
                                            <p className="text-section-title mt-1">{workflowChecklist.next_step.label}</p>
                                            <p className="text-subtle mt-0.5">{workflowChecklist.next_step.detail}</p>
                                            <div className="mt-3">{stepAction(workflowChecklist.next_step, true)}</div>
                                        </div>
                                    ) : null}

                                    {meetingCockpit.cards.length > 0 ? (
                                        <section aria-labelledby="meeting-readiness-heading" className="flex flex-col gap-3">
                                            <h3 id="meeting-readiness-heading" className="text-section-title">
                                                At a glance
                                            </h3>
                                            <ul className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                                                {meetingCockpit.cards.map((card) => {
                                                    const tab = inPageTab(card.href, meeting.id);
                                                    const linkLabel = READINESS_LINKS[card.key] ?? 'Open';
                                                    return (
                                                        <li
                                                            key={card.key}
                                                            className="flex flex-col gap-2 rounded-lg border border-border p-4"
                                                            data-test={`meeting-readiness-${card.key}`}
                                                        >
                                                            <div className="flex items-start justify-between gap-2">
                                                                <span className="text-sm font-medium text-foreground">{card.title}</span>
                                                                <StatusBadge variant={readinessVariant(card.status)}>
                                                                    {String(card.value)}
                                                                </StatusBadge>
                                                            </div>
                                                            <p className="text-subtle">{card.detail}</p>
                                                            <div className="mt-auto">
                                                                {tab ? (
                                                                    <Button size="sm" variant="ghost" onClick={() => handleTabChange(tab)}>
                                                                        {linkLabel}
                                                                    </Button>
                                                                ) : (
                                                                    <Button asChild size="sm" variant="ghost">
                                                                        <Link href={card.href}>{linkLabel}</Link>
                                                                    </Button>
                                                                )}
                                                            </div>
                                                        </li>
                                                    );
                                                })}
                                            </ul>
                                        </section>
                                    ) : null}

                                    <section aria-labelledby="meeting-steps-heading" className="flex flex-col gap-3">
                                        <h3 id="meeting-steps-heading" className="text-section-title">
                                            Steps
                                        </h3>
                                        <ol className="flex flex-col divide-y divide-border rounded-lg border border-border">
                                            {workflowChecklist.items.map((item) => {
                                                const chip = checklistStatusChip(item.status, item.status_label);
                                                const waiting = item.blocked_by
                                                    ? item.blocked_by.startsWith('Waiting')
                                                        ? `${item.blocked_by}.`
                                                        : `Waiting because ${lowerFirst(item.blocked_by)}.`
                                                    : null;
                                                return (
                                                    <li
                                                        key={item.key}
                                                        className="flex flex-wrap items-start justify-between gap-3 p-4"
                                                        dusk={`workflow-item-${item.key}`}
                                                    >
                                                        <div className="min-w-0 flex-1">
                                                            <p className="text-sm font-medium text-foreground">{item.label}</p>
                                                            <p className="text-subtle">{item.detail}</p>
                                                            {waiting ? <p className="text-caption mt-1">{waiting}</p> : null}
                                                        </div>
                                                        <div className="flex shrink-0 flex-wrap items-center gap-2">
                                                            <StatusBadge variant={chip.variant} dusk={`workflow-status-${item.key}`}>
                                                                {chip.label}
                                                            </StatusBadge>
                                                            {!['done', 'not_applicable', 'blocked'].includes(item.status)
                                                                ? stepAction(item)
                                                                : null}
                                                        </div>
                                                    </li>
                                                );
                                            })}
                                        </ol>
                                    </section>
                                </CardContent>
                            </Card>
                        ) : null}
                    </section>
                </div>
            </PageLayout>

            {/* ========== Dialogs ========== */}
            {canOpenEdit && formOptions ? (
                <MeetingWizardDialog
                    isOpen={editOpen}
                    onClose={() => setEditOpen(false)}
                    options={formOptions}
                    meeting={meeting}
                />
            ) : null}

            {canCreateResolution ? (
                <NewResolutionDialog
                    isOpen={newResolutionOpen}
                    onClose={() => setNewResolutionOpen(false)}
                    meetings={[{ id: meeting.id, title: meeting.title, scheduled_at: meeting.scheduled_at }]}
                    meetingId={meeting.id}
                    lockMeeting
                    users={users}
                    committees={committees}
                    authoritySubjects={authoritySubjects}
                    authoritySubjectGroups={authoritySubjectGroups}
                    canPublish={canPublishPapers}
                />
            ) : null}

            {viewerCanRsvp ? (
                <RsvpDialog
                    isOpen={rsvpDialogOpen}
                    onClose={() => setRsvpDialogOpen(false)}
                    onSaved={() => setReplySaved(true)}
                    meetingId={meeting.id}
                    meetingTitle={meeting.title}
                    meetingWhen={formatDateTimeLong(meeting.scheduled_at)}
                    existing={viewerRsvp ?? null}
                />
            ) : null}

            {canEdit ? (
                <>
                    <AgendaItemDialog
                        isOpen={agendaDialogOpen}
                        onClose={() => setAgendaDialogOpen(false)}
                        meetingId={meeting.id}
                        presenters={boardMembers}
                    />
                    <AttendanceDialog
                        isOpen={attendanceDialogOpen}
                        onClose={() => setAttendanceDialogOpen(false)}
                        meetingId={meeting.id}
                        members={boardMembers}
                        attendances={attendances}
                        rsvps={rsvps}
                    />
                    <ConfirmDialog
                        open={removingItem !== null}
                        onClose={() => setRemovingItem(null)}
                        onConfirm={() => {
                            if (removingItem) removeAgendaItem(removingItem.id);
                        }}
                        title="Remove this agenda item?"
                        description={`“${removingItem?.title ?? ''}” will be taken off the agenda. This can't be undone.`}
                        confirmText="Remove item"
                    />
                </>
            ) : null}

            {canManageMinutes ? (
                <MinutesEditorDialog
                    isOpen={minutesDialogOpen}
                    onClose={() => setMinutesDialogOpen(false)}
                    meetingId={meeting.id}
                    minutes={minutes ? { version_number: minutes.version_number, content_blocks: minutes.content_blocks } : null}
                />
            ) : null}

            {minutes && canSignMinutes ? (
                <SignMinutesDialog
                    isOpen={signDialogOpen}
                    onClose={() => setSignDialogOpen(false)}
                    meetingId={meeting.id}
                    meetingTitle={meeting.title}
                    minutes={{ version_number: minutes.version_number, content_hash: minutes.content_hash ?? null }}
                />
            ) : null}

            {minutes && canManageMinutes ? (
                <CorrectionDialog
                    isOpen={correctionDialogOpen}
                    onClose={() => setCorrectionDialogOpen(false)}
                    meetingId={meeting.id}
                    versionNumber={minutes.version_number}
                />
            ) : null}

            {minutes ? (
                <ConfirmDialog
                    open={minutesConfirm !== null}
                    onClose={() => setMinutesConfirm(null)}
                    variant="default"
                    title={
                        minutesConfirm === 'review'
                            ? 'Send the minutes for approval?'
                            : minutesConfirm === 'approve'
                              ? 'Approve these minutes?'
                              : 'Archive these minutes?'
                    }
                    description={
                        minutesConfirm === 'review'
                            ? `The chair will be asked to approve version ${minutes.version_number}. You can still edit the draft until it's approved.`
                            : minutesConfirm === 'approve'
                              ? `Version ${minutes.version_number} will be approved and can't be edited afterwards. If something is wrong later, the secretary can start a correction.`
                              : 'The signed minutes move to the records. They stay readable, and the secretary can still start a correction.'
                    }
                    confirmText={
                        minutesConfirm === 'review'
                            ? 'Send for approval'
                            : minutesConfirm === 'approve'
                              ? 'Approve minutes'
                              : 'Archive minutes'
                    }
                    onConfirm={() => {
                        if (minutesConfirm === 'review') {
                            postMinutesStep('minutes/submit-for-review');
                        } else if (minutesConfirm === 'approve') {
                            postMinutesStep('minutes/approve', {
                                expected_version: minutes.version_number,
                                expected_hash: minutes.content_hash ?? null,
                            });
                        } else if (minutesConfirm === 'archive') {
                            postMinutesStep('minutes/archive');
                        }
                    }}
                />
            ) : null}
        </AppLayout>
    );
}

/** "Your reply is recorded" — from the stored reply, with its server reference. */
function ReplyReceipt({ rsvp }: { rsvp: ViewerRsvp }) {
    const chip = governanceStatus('rsvp_response', rsvp.response);
    return (
        <div className="flex flex-wrap items-center gap-2 text-sm" data-test="meeting-rsvp-receipt">
            <CheckCircle2 className="size-4 text-status-success" aria-hidden="true" />
            <span className="font-medium text-foreground">Your reply is recorded</span>
            <StatusBadge variant={chip.variant}>{chip.label}</StatusBadge>
            <span className="text-caption">
                {[
                    rsvp.responded_at ? formatDateTimeLong(rsvp.responded_at) : null,
                    refSuffix(rsvp.receipt_id),
                ]
                    .filter(Boolean)
                    .join(' · ')}
            </span>
        </div>
    );
}
