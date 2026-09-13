import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import {
    meetingWorkspaceUrl,
    withQueryParams,
    type MeetingWorkspaceFocus,
} from '@/components/governance/meeting-workspace-links';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import {
    TierTwoTabs,
    type GroupedProfileNavTab,
} from '@/components/page/grouped-profile-nav';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import {
    formatDateLong,
    formatDateTime,
    formatDateTimeLong,
    formatDurationMinutes,
    formatTime,
} from '@/lib/datetime';
import { canDoGovernance } from '@/lib/governance-permissions';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import {
    generate as generatePackRoute,
    show as showPack,
} from '@/routes/governance/packs';
import { show as showResolution } from '@/routes/governance/resolutions';
import { PageProps } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertTriangle,
    Archive,
    Calendar,
    Check,
    CheckCircle,
    ChevronDown,
    ChevronRight,
    Clock,
    FileCheck,
    FileDown,
    FileText,
    History,
    ListChecks,
    Lock,
    MapPin,
    Pencil,
    Plus,
    RotateCcw,
    Send,
    ShieldCheck,
    Users,
    Vote,
} from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';
import {
    type AuthoritySubjectGroup,
    type AuthoritySubjects,
    type CommitteeOption,
    NewResolutionDialog,
    type UserOption,
} from '../Resolutions/_dialogs';
import {
    MeetingPaperWorkspace,
    type PaperResolution,
} from '@/components/governance/MeetingPaperWorkspace';
import {
    MeetingWizardDialog,
    meetingStatusLabel,
    meetingStatusVariant,
    meetingTypeLabel,
    type MeetingFormOptions,
} from './_dialogs';

const VALID_TABS = [
    'agenda',
    'attendance',
    'minutes',
    'resolutions',
    'workflow',
] as const;
type MeetingTab = (typeof VALID_TABS)[number];

const isMeetingTab = (value: string | null | undefined): value is MeetingTab =>
    Boolean(value && (VALID_TABS as readonly string[]).includes(value));

function readWorkspaceLocation(url: string): {
    tab: MeetingTab;
    paper: string | null;
    focus: MeetingWorkspaceFocus | null;
} {
    const params = new URLSearchParams(url.split('#')[0]?.split('?')[1] ?? '');
    const tab = params.get('tab');
    const paper = params.get('paper');
    return {
        tab: isMeetingTab(tab) ? tab : paper ? 'resolutions' : 'agenda',
        paper,
        focus: params.get('focus') === 'follow-ups' ? 'follow-ups' : null,
    };
}

const RSVP_LABEL: Record<string, string> = {
    accepted: 'Attending',
    declined: 'Apology',
    tentative: 'Tentative',
};

const rsvpVariant = (response: string): StatusVariant =>
    response === 'accepted'
        ? 'success'
        : response === 'declined'
          ? 'warning'
          : 'info';

const ATTENDANCE_VARIANT: Record<string, StatusVariant> = {
    present: 'success',
    apology: 'warning',
    no_show: 'critical',
    late: 'info',
};
const attendanceVariant = (status: string): StatusVariant =>
    ATTENDANCE_VARIANT[status] ?? 'neutral';

const MINUTES_VARIANT: Record<string, StatusVariant> = {
    draft: 'warning',
    reviewed: 'info',
    approved: 'success',
    signed: 'success',
    archived: 'neutral',
};
const minutesVariant = (status: string): StatusVariant =>
    MINUTES_VARIANT[status] ?? 'neutral';

const CHECKLIST_VARIANT: Record<string, StatusVariant> = {
    done: 'success',
    in_progress: 'info',
    todo: 'warning',
    blocked: 'critical',
};
const checklistVariant = (status: string): StatusVariant =>
    CHECKLIST_VARIANT[status] ?? 'neutral';

const humanStatus = (value: string) => {
    const text = value.replace(/_/g, ' ');
    return text.charAt(0).toUpperCase() + text.slice(1);
};

interface BoardMemberItem {
    id: number;
    user: { id: number; name: string };
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
    agenda_items: Array<{
        id: number;
        order: number;
        title: string;
        description: string | null;
        presenter: { name: string } | null;
        duration_minutes: number;
        item_type: string;
        is_confidential: boolean;
        resolution_id?: number | null;
    }>;
    attendances: Array<{
        id: number;
        board_member_id: number;
        board_member: { user: { name: string } };
        status: string;
        apology_reason: string | null;
    }>;
    rsvps?: Array<{
        id: number;
        board_member_id: number;
        response: string;
        decline_reason: string | null;
        dietary_requirements: boolean;
        dietary_notes: string | null;
        responded_at: string | null;
        board_member?: { user: { name: string } };
    }>;
    minutes: {
        id: number;
        status: string;
        version_number: number;
        content_blocks: Array<{ heading: string; content: string }>;
        version_history?: Array<{
            version?: number;
            status?: string;
            event?: string;
            content_blocks?: Array<{ heading: string; content: string }>;
            content_hash?: string;
            updated_at?: string;
            created_at?: string;
            archived_at?: string;
            superseded_at?: string;
            note?: string;
            reason_for_correction?: string;
            actor_name?: string;
            created_by_name?: string;
            updated_by_name?: string;
            approver_user_name?: string;
            signer_user_name?: string;
            timestamp?: string;
        }> | null;
        content_hash?: string;
        drafted_at?: string | null;
        reviewed_at?: string | null;
        signed_at?: string | null;
        archived_at?: string | null;
        drafter_name?: string | null;
        reviewer_name?: string | null;
        signer_name?: string | null;
        review_notes?: string | null;
    } | null;
    board_pack: {
        id: number;
        distributed_at: string | null;
    } | null;
    resolutions: Array<{
        id: number;
        resolution_reference: string;
        title: string;
        status: string;
    }>;
}

interface Props extends PageProps {
    meeting: Meeting;
    boardMembers: BoardMemberItem[];
    quorum: {
        present: number;
        required: number;
        met: boolean;
    };
    canEdit: boolean;
    canManageMinutes: boolean;
    canApproveMinutes: boolean;
    canSignMinutes: boolean;
    workflowChecklist: {
        counts: {
            done: number;
            remaining: number;
            blocked: number;
        };
        next_step: {
            label: string;
            status: string;
            detail: string;
            action_label: string;
            action_url: string;
            blocked_by: string | null;
        } | null;
        items: Array<{
            key: string;
            label: string;
            status: 'done' | 'in_progress' | 'todo' | 'blocked';
            detail: string;
            action_label: string;
            action_url: string;
            blocked_by: string | null;
        }>;
    };
    meetingCockpit: {
        cards: Array<{
            key: string;
            title: string;
            status: 'done' | 'in_progress' | 'todo' | 'warning';
            value: string | number;
            detail: string;
            href: string;
        }>;
    };
    viewerCanRsvp?: boolean;
    viewerRsvp?: {
        id: number;
        board_member_id: number;
        response: string;
        decline_reason: string | null;
        dietary_requirements: boolean;
        dietary_notes: string | null;
        responded_at: string | null;
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

export default function MeetingShow({
    auth,
    meeting,
    boardMembers,
    quorum,
    canEdit,
    canManageMinutes,
    canApproveMinutes,
    canSignMinutes,
    workflowChecklist,
    viewerCanRsvp,
    viewerRsvp,
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
    const canOpenEdit = canEdit && formOptions !== null;
    // Retired /meetings/{id}/edit deep links arrive as ?edit=1.
    const [editOpen, setEditOpen] = useDialogDeepLink('edit', canOpenEdit);
    const [generatingPack, setGeneratingPack] = useState(false);
    const [packMessage, setPackMessage] = useState<string | null>(null);
    const [agendaDialogOpen, setAgendaDialogOpen] = useState(false);
    const [attendanceDialogOpen, setAttendanceDialogOpen] = useState(false);
    const [minutesDialogOpen, setMinutesDialogOpen] = useState(false);
    const [signDialogOpen, setSignDialogOpen] = useState(false);
    const [reviewDialogOpen, setReviewDialogOpen] = useState(false);
    const [correctionDialogOpen, setCorrectionDialogOpen] = useState(false);
    const [correctionReason, setCorrectionReason] = useState('');
    const [archiveDialogOpen, setArchiveDialogOpen] = useState(false);
    const [selectedHistoryVersion, setSelectedHistoryVersion] = useState<number | null>(null);
    const [historyOpen, setHistoryOpen] = useState(false);
    const [minutesError, setMinutesError] = useState<string | null>(null);
    const [newResolutionOpen, setNewResolutionOpen] = useState(false);
    // The workspace location (tab + selected paper) lives in the URL so reload,
    // back/forward and links from follow-up actions restore the same place.
    const initialLocation = readWorkspaceLocation(page.url);
    const [activeTab, setActiveTab] = useState<MeetingTab>(initialLocation.tab);
    const [selectedPaperId, setSelectedPaperId] = useState<string | null>(
        initialLocation.paper,
    );
    const [paperFocus, setPaperFocus] = useState<MeetingWorkspaceFocus | null>(
        initialLocation.focus,
    );
    const [lastClosedPaperId, setLastClosedPaperId] = useState<string | null>(
        null,
    );

    const agendaItems = meeting.agenda_items ?? [];
    const attendances = meeting.attendances ?? [];
    const resolutions: PaperResolution[] = propResolutions ?? meeting.resolutions ?? [];
    const allBoardMembers = boardMembers ?? [];

    const replaceLocation = (patch: Record<string, string | null>) => {
        // router.replace keeps Inertia's history state intact, so browser Back
        // from an opened record lands on this exact tab and paper.
        router.replace({
            url: withQueryParams(page.url, patch),
            preserveState: true,
            preserveScroll: true,
        });
    };

    // Re-sync when the URL changes underneath us (history navigation, a
    // `#tab-…` meter link, or a return from a follow-up action).
    useEffect(() => {
        const location = readWorkspaceLocation(page.url);
        const hashTab = window.location.hash.replace('#tab-', '').replace('#', '');
        setActiveTab(
            new URLSearchParams(page.url.split('?')[1] ?? '').get('tab') === null &&
                !location.paper &&
                isMeetingTab(hashTab)
                ? hashTab
                : location.tab,
        );
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
    }, [page.url]);

    const handleTabChange = (newTab: string) => {
        if (!isMeetingTab(newTab)) return;
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

    // Papers in agenda order first, then any remaining resolutions.
    const orderedPaperIds = [
        ...agendaItems
            .map((item) => item.resolution_id)
            .filter((id): id is number => typeof id === 'number'),
        ...resolutions.map((r) => r.id),
    ].filter(
        (id, index, all) =>
            all.indexOf(id) === index && resolutions.some((r) => r.id === id),
    );

    // Agenda Item Form
    const agendaForm = useForm({
        title: '',
        description: '',
        presenter_id: '',
        duration_minutes: '15',
        item_type: 'standard',
        is_confidential: false,
    });

    // Attendance Form - track status for each board member
    const [attendanceRecords, setAttendanceRecords] = useState<
        Record<number, { status: string; apology_reason: string }>
    >(() => {
        const initial: Record<
            number,
            { status: string; apology_reason: string }
        > = {};
        for (const member of allBoardMembers) {
            const existing = attendances.find(
                (a) => a.board_member_id === member.id,
            );
            initial[member.id] = {
                status: existing?.status || 'unrecorded',
                apology_reason: existing?.apology_reason || '',
            };
        }
        return initial;
    });
    const [attendanceSubmitting, setAttendanceSubmitting] = useState(false);

    // RSVP State & Form
    const [rsvpDialogOpen, setRsvpDialogOpen] = useState(false);
    const [rsvpSubmitting, setRsvpSubmitting] = useState(false);
    const [rsvpData, setRsvpData] = useState({
        response: viewerRsvp?.response || 'accepted',
        notes: viewerRsvp?.decline_reason || viewerRsvp?.dietary_notes || '',
        dietary_requirements: viewerRsvp?.dietary_requirements || false,
    });
    const [rsvpReceipt, setRsvpReceipt] = useState<string | null>(null);

    const submitRsvpForm = (e: React.FormEvent) => {
        e.preventDefault();
        setRsvpSubmitting(true);
        router.post(
            `/governance/meetings/${meeting.id}/rsvp`,
            {
                response: rsvpData.response,
                decline_reason: rsvpData.response === 'declined' ? rsvpData.notes : null,
                dietary_notes: rsvpData.response !== 'declined' ? rsvpData.notes : null,
                dietary_requirements: rsvpData.dietary_requirements,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setRsvpSubmitting(false);
                    setRsvpDialogOpen(false);
                    setRsvpReceipt(`RSVP-${meeting.id}-${new Date().getTime()}`);
                },
                onError: () => {
                    setRsvpSubmitting(false);
                },
            },
        );
    };

    // Minutes Form - structured blocks
    const defaultBlocks = [
        { heading: 'Welcome & Apologies', content: '' },
        { heading: 'Minutes of Previous Meeting', content: '' },
        { heading: 'Matters Arising', content: '' },
        { heading: 'General Business', content: '' },
        { heading: 'Next Meeting', content: '' },
    ];
    const [minutesBlocks, setMinutesBlocks] = useState<
        Array<{ heading: string; content: string }>
    >(
        meeting.minutes?.content_blocks &&
            Array.isArray(meeting.minutes.content_blocks)
            ? meeting.minutes.content_blocks
            : defaultBlocks,
    );
    const [minutesSubmitting, setMinutesSubmitting] = useState(false);

    const updateMinutesBlock = (
        index: number,
        field: 'heading' | 'content',
        value: string,
    ) => {
        setMinutesBlocks((prev) =>
            prev.map((block, i) =>
                i === index ? { ...block, [field]: value } : block,
            ),
        );
    };

    const addMinutesBlock = () => {
        setMinutesBlocks((prev) => [...prev, { heading: '', content: '' }]);
    };

    const removeMinutesBlock = (index: number) => {
        setMinutesBlocks((prev) => prev.filter((_, i) => i !== index));
    };

    const getItemTypeIcon = (type: string) => {
        switch (type) {
            case 'decision':
                return <Vote className="h-4 w-4 text-primary" />;
            case 'consent':
                return <CheckCircle className="h-4 w-4 text-status-success" />;
            default:
                return <FileText className="h-4 w-4 text-muted-foreground" />;
        }
    };

    const generatePack = async () => {
        setGeneratingPack(true);
        setPackMessage(null);
        try {
            const response = await axios.post(
                generatePackRoute.url({ meeting: meeting.id }),
            );
            const status = response?.data?.status ?? null;
            if (status === 'generated') {
                router.reload();
            } else {
                setPackMessage(
                    'Board pack generation started. Refresh in a moment to see it.',
                );
            }
        } catch (error) {
            console.error('Failed to generate pack:', error);
            setPackMessage(
                'Failed to generate the board pack. Please try again.',
            );
        } finally {
            setGeneratingPack(false);
        }
    };

    const submitAgendaItem = (e: FormEvent) => {
        e.preventDefault();
        agendaForm.post(`/governance/meetings/${meeting.id}/agenda`, {
            preserveScroll: true,
            onSuccess: () => {
                setAgendaDialogOpen(false);
                agendaForm.reset();
            },
        });
    };

    const submitAttendance = async () => {
        setAttendanceSubmitting(true);
        const attendance = Object.entries(attendanceRecords).map(
            ([id, record]) => ({
                board_member_id: Number(id),
                status: record.status,
                apology_reason: record.apology_reason || null,
            }),
        );

        router.post(
            `/governance/meetings/${meeting.id}/attendance`,
            { attendance },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setAttendanceDialogOpen(false);
                    setAttendanceSubmitting(false);
                },
                onError: () => {
                    setAttendanceSubmitting(false);
                },
            },
        );
    };

    const submitMinutes = (e: FormEvent) => {
        e.preventDefault();
        setMinutesSubmitting(true);
        setMinutesError(null);
        const method = meeting.minutes ? 'put' : 'post';

        router[method](
            `/governance/meetings/${meeting.id}/minutes`,
            {
                content_blocks: minutesBlocks,
                expected_version: meeting.minutes?.version_number,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setMinutesDialogOpen(false);
                    setMinutesSubmitting(false);
                },
                onError: (errors) => {
                    setMinutesSubmitting(false);
                    if (errors.error) {
                        setMinutesError(String(errors.error));
                    }
                },
            },
        );
    };

    const submitForReview = () => {
        setMinutesSubmitting(true);
        setMinutesError(null);
        router.post(
            `/governance/meetings/${meeting.id}/minutes/submit-for-review`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    setReviewDialogOpen(false);
                    setMinutesSubmitting(false);
                },
                onError: (errors) => {
                    setMinutesSubmitting(false);
                    if (errors.error) setMinutesError(String(errors.error));
                },
            },
        );
    };

    const submitApproveMinutes = () => {
        if (!meeting.minutes) return;
        setMinutesSubmitting(true);
        setMinutesError(null);
        router.post(
            `/governance/meetings/${meeting.id}/minutes/approve`,
            {
                expected_version: meeting.minutes.version_number,
                expected_hash: meeting.minutes.content_hash,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setMinutesSubmitting(false);
                },
                onError: (errors) => {
                    setMinutesSubmitting(false);
                    if (errors.error) setMinutesError(String(errors.error));
                },
            },
        );
    };

    const submitSignMinutes = () => {
        if (!meeting.minutes) return;
        setMinutesSubmitting(true);
        setMinutesError(null);
        router.post(
            `/governance/meetings/${meeting.id}/sign-minutes`,
            {
                expected_version: meeting.minutes.version_number,
                expected_hash: meeting.minutes.content_hash,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSignDialogOpen(false);
                    setMinutesSubmitting(false);
                },
                onError: (errors) => {
                    setMinutesSubmitting(false);
                    if (errors.error) setMinutesError(String(errors.error));
                },
            },
        );
    };

    const submitArchiveMinutes = () => {
        setMinutesSubmitting(true);
        setMinutesError(null);
        router.post(
            `/governance/meetings/${meeting.id}/minutes/archive`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    setArchiveDialogOpen(false);
                    setMinutesSubmitting(false);
                },
                onError: (errors) => {
                    setMinutesSubmitting(false);
                    if (errors.error) setMinutesError(String(errors.error));
                },
            },
        );
    };

    const submitCorrection = (e: FormEvent) => {
        e.preventDefault();
        setMinutesSubmitting(true);
        setMinutesError(null);
        router.post(
            `/governance/meetings/${meeting.id}/minutes/correction`,
            { reason: correctionReason },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setCorrectionDialogOpen(false);
                    setCorrectionReason('');
                    setMinutesSubmitting(false);
                },
                onError: (errors) => {
                    setMinutesSubmitting(false);
                    if (errors.error) setMinutesError(String(errors.error));
                },
            },
        );
    };

    const removeAgendaItem = (itemId: number) => {
        router.delete(`/governance/meetings/${meeting.id}/agenda/${itemId}`, {
            preserveScroll: true,
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
        meeting.secretary?.user?.name
            ? `Secretary: ${meeting.secretary.user.name}`
            : null,
    ]
        .filter(Boolean)
        .join(' · ');

    const workspaceTabs: GroupedProfileNavTab[] = [
        {
            key: 'agenda',
            label: 'Agenda',
            icon: FileText,
            count: agendaItems.length,
        },
        { key: 'attendance', label: 'Attendance', icon: Users },
        { key: 'minutes', label: 'Minutes', icon: Pencil },
        {
            key: 'resolutions',
            label: 'Papers & resolutions',
            icon: Vote,
            count: resolutions.length,
        },
        {
            key: 'workflow',
            label: 'Workflow',
            icon: ListChecks,
            warningCount: workflowChecklist.counts.blocked,
        },
    ];

    const selectedResolution = selectedPaperId
        ? (resolutions.find((r) => String(r.id) === String(selectedPaperId)) ??
          null)
        : null;
    const nextPaperId = selectedResolution
        ? orderedPaperIds[orderedPaperIds.indexOf(selectedResolution.id) + 1]
        : undefined;
    const nextPaper =
        nextPaperId !== undefined
            ? (resolutions.find((r) => r.id === nextPaperId) ?? null)
            : null;

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Meetings', href: '/governance/meetings' },
                {
                    title: meeting.title,
                    href: meetingWorkspaceUrl(meeting.id),
                },
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
                            <PageHeaderStatusChip
                                variant={meetingStatusVariant(meeting.status)}
                            >
                                {meetingStatusLabel(meeting.status)}
                            </PageHeaderStatusChip>
                        }
                        subline={
                            <>
                                <span className="block">{scheduleLine}</span>
                                <span className="block">{peopleLine}</span>
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Workflow"
                                    href="#tab-workflow"
                                    ariaLabel="View the meeting workflow"
                                    onClick={() => handleTabChange('workflow')}
                                >
                                    <PageHeaderMeterBig>
                                        {workflowChecklist.counts.done}/
                                        {workflowChecklist.counts.done +
                                            workflowChecklist.counts.remaining +
                                            workflowChecklist.counts.blocked}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>Tasks complete</PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Quorum"
                                    href="#tab-attendance"
                                    ariaLabel="View attendance and quorum"
                                    onClick={() => handleTabChange('attendance')}
                                    tone={quorum.met ? 'success' : 'warning'}
                                >
                                    <PageHeaderMeterBig>
                                        {quorum.present}/{quorum.required}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {quorum.met ? 'Quorum met' : 'Quorum pending'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Agenda"
                                    href="#tab-agenda"
                                    ariaLabel="View the agenda"
                                    onClick={() => handleTabChange('agenda')}
                                >
                                    <PageHeaderMeterBig>{agendaItems.length}</PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>Scheduled items</PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Resolutions"
                                    href="#tab-resolutions"
                                    ariaLabel="View papers and resolutions"
                                    onClick={() => handleTabChange('resolutions')}
                                >
                                    <PageHeaderMeterBig>{resolutions.length}</PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>Decisions filed</PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        actions={
                            <>
                                {canOpenEdit ? (
                                    <PageHeaderGlassButton
                                        icon={Pencil}
                                        onClick={() => setEditOpen(true)}
                                        dusk="edit-meeting"
                                    >
                                        Edit meeting
                                    </PageHeaderGlassButton>
                                ) : null}
                                {meeting.board_pack ? (
                                    <PageHeaderPrimaryButton
                                        icon={FileDown}
                                        onClick={() =>
                                            router.visit(
                                                showPack.url({
                                                    pack: meeting.board_pack!.id,
                                                }),
                                            )
                                        }
                                        dusk="view-pack"
                                    >
                                        View pack
                                    </PageHeaderPrimaryButton>
                                ) : canEdit ? (
                                    <PageHeaderPrimaryButton
                                        icon={FileDown}
                                        onClick={generatePack}
                                        disabled={generatingPack}
                                        dusk="generate-pack"
                                    >
                                        {generatingPack
                                            ? 'Generating…'
                                            : 'Generate pack'}
                                    </PageHeaderPrimaryButton>
                                ) : null}
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
                {packMessage && (
                    <div
                        role="status"
                        className="rounded-lg border border-status-info/30 bg-status-info-bg px-4 py-2 text-sm text-status-info"
                    >
                        {packMessage}
                    </div>
                )}

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
                        meetings={[
                            {
                                id: meeting.id,
                                title: meeting.title,
                                scheduled_at: meeting.scheduled_at,
                            },
                        ]}
                        meetingId={meeting.id}
                        lockMeeting
                        users={users}
                        committees={committees}
                        authoritySubjects={authoritySubjects}
                        authoritySubjectGroups={authoritySubjectGroups}
                        canPublish={canPublishPapers}
                    />
                ) : null}

                {/* Member RSVP Callout */}
                {viewerCanRsvp && (
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 rounded-lg border border-primary/20 bg-primary/5 p-4" data-test="meeting-rsvp-banner">
                        <div className="flex items-start gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10 text-primary">
                                <CheckCircle className="h-5 w-5" />
                            </div>
                            <div>
                                <h3 className="text-sm font-semibold text-foreground">
                                    {viewerRsvp ? (
                                        <>
                                            Your RSVP:{' '}
                                            <span className="capitalize font-bold text-primary">
                                                {viewerRsvp.response === 'accepted' ? 'Attending' : (viewerRsvp.response === 'declined' ? 'Apologies' : 'Tentative')}
                                            </span>
                                        </>
                                    ) : (
                                        'Meeting RSVP Required'
                                    )}
                                </h3>
                                <p className="text-xs text-muted-foreground mt-0.5">
                                    {viewerRsvp
                                        ? `Recorded ${formatDateTimeLong(viewerRsvp.responded_at, 'date not recorded')}${viewerRsvp.decline_reason ? ` • Apology reason: ${viewerRsvp.decline_reason}` : ''}${viewerRsvp.dietary_notes ? ` • Dietary notes: ${viewerRsvp.dietary_notes}` : ''}`
                                        : 'Please confirm whether you will attend this meeting or send apologies.'}
                                </p>
                            </div>
                        </div>
                        <Button
                            size="sm"
                            variant={viewerRsvp ? 'outline' : 'default'}
                            onClick={() => setRsvpDialogOpen(true)}
                            dusk="meeting-rsvp-trigger"
                            data-test="meeting-rsvp-trigger"
                        >
                            {viewerRsvp ? 'Update RSVP' : 'Submit RSVP'}
                        </Button>
                    </div>
                )}

                {/* RSVP Dialog */}
                <Dialog open={rsvpDialogOpen} onOpenChange={setRsvpDialogOpen}>
                    <DialogContent className="max-w-md">
                        <DialogHeader>
                            <DialogTitle>Meeting RSVP</DialogTitle>
                        </DialogHeader>
                        <form onSubmit={submitRsvpForm} className="space-y-4 pt-2">
                            <div className="space-y-2">
                                <Label htmlFor="rsvp-response">Attendance Response</Label>
                                <Select
                                    value={rsvpData.response}
                                    onValueChange={(val) => setRsvpData((prev) => ({ ...prev, response: val }))}
                                >
                                    <SelectTrigger id="rsvp-response" data-test="rsvp-response-select">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="accepted">Attending</SelectItem>
                                        <SelectItem value="declined">Apologies (Cannot Attend)</SelectItem>
                                        <SelectItem value="tentative">Unsure / Tentative</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            {rsvpData.response === 'declined' && (
                                <div className="space-y-2">
                                    <Label htmlFor="rsvp-decline-reason">Reason for Apology (Optional)</Label>
                                    <Input
                                        id="rsvp-decline-reason"
                                        placeholder="e.g. Schedule conflict, overseas, medical"
                                        value={rsvpData.notes}
                                        onChange={(e) => setRsvpData((prev) => ({ ...prev, notes: e.target.value }))}
                                        data-test="rsvp-decline-reason"
                                    />
                                </div>
                            )}

                            {rsvpData.response !== 'declined' && (
                                <div className="space-y-3">
                                    <div className="flex items-center space-x-2">
                                        <Checkbox
                                            id="rsvp-dietary"
                                            checked={rsvpData.dietary_requirements}
                                            onCheckedChange={(checked) =>
                                                setRsvpData((prev) => ({ ...prev, dietary_requirements: !!checked }))
                                            }
                                        />
                                        <Label htmlFor="rsvp-dietary" className="text-sm font-normal">
                                            I have specific dietary or accessibility requirements
                                        </Label>
                                    </div>
                                    {rsvpData.dietary_requirements && (
                                        <Input
                                            placeholder="Specify dietary requirements (e.g. Vegetarian, Gluten-Free)"
                                            value={rsvpData.notes}
                                            onChange={(e) => setRsvpData((prev) => ({ ...prev, notes: e.target.value }))}
                                            data-test="rsvp-dietary-notes"
                                        />
                                    )}
                                </div>
                            )}

                            {rsvpReceipt && (
                                <div className="rounded border border-status-success/30 bg-status-success-bg p-3 text-xs text-status-success">
                                    RSVP confirmed. Receipt ID: <span className="font-mono font-medium">{rsvpReceipt}</span>
                                </div>
                            )}

                            <div className="mt-4 flex justify-end gap-2">
                                <Button type="button" variant="outline" onClick={() => setRsvpDialogOpen(false)}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={rsvpSubmitting} dusk="save-rsvp" data-test="save-rsvp">
                                    {rsvpSubmitting ? 'Saving...' : 'Confirm RSVP'}
                                </Button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>

                {/* Meeting Status Strip — replaces the right-rail info cards. */}
                <MeetingStatusStrip
                    meeting={meeting}
                    quorum={quorum}
                    workflowChecklist={workflowChecklist}
                    resolutions={resolutions}
                    attendances={attendances}
                />

                <section
                    id="meeting-workspace-panel"
                    role="tabpanel"
                    aria-labelledby={`meeting-tab-${activeTab}`}
                    className="flex flex-col gap-5"
                >
                        {/* ========== AGENDA TAB ========== */}
                        {activeTab === 'agenda' && (
                            <Card>
                                <CardHeader className="flex flex-row items-center justify-between">
                                    <div>
                                        <CardTitle>Agenda Items</CardTitle>
                                        <CardDescription>
                                            {agendaItems.length} items
                                        </CardDescription>
                                    </div>
                                    {canEdit && (
                                        <Dialog
                                            open={agendaDialogOpen}
                                            onOpenChange={setAgendaDialogOpen}
                                        >
                                            <DialogTrigger asChild>
                                                <Button size="sm">
                                                    <Plus className="mr-1 h-4 w-4" />
                                                    Add Item
                                                </Button>
                                            </DialogTrigger>
                                            <DialogContent
                                                className="max-w-lg"
                                                aria-describedby={undefined}
                                            >
                                                <DialogHeader>
                                                    <DialogTitle>
                                                        Add Agenda Item
                                                    </DialogTitle>
                                                </DialogHeader>
                                                <form
                                                    onSubmit={submitAgendaItem}
                                                    className="space-y-4"
                                                >
                                                    <div>
                                                        <Label htmlFor="agenda-title">
                                                            Title
                                                        </Label>
                                                        <Input
                                                            id="agenda-title"
                                                            value={
                                                                agendaForm.data
                                                                    .title
                                                            }
                                                            onChange={(e) =>
                                                                agendaForm.setData(
                                                                    'title',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            required
                                                        />
                                                        {agendaForm.errors
                                                            .title && (
                                                            <p className="mt-1 text-sm text-status-critical">
                                                                {
                                                                    agendaForm
                                                                        .errors
                                                                        .title
                                                                }
                                                            </p>
                                                        )}
                                                    </div>
                                                    <div>
                                                        <Label htmlFor="agenda-description">
                                                            Description
                                                        </Label>
                                                        <Textarea
                                                            id="agenda-description"
                                                            value={
                                                                agendaForm.data
                                                                    .description
                                                            }
                                                            onChange={(e) =>
                                                                agendaForm.setData(
                                                                    'description',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            rows={3}
                                                        />
                                                    </div>
                                                    <div className="grid grid-cols-2 gap-4">
                                                        <div>
                                                            <Label>
                                                                Item Type
                                                            </Label>
                                                            <Select
                                                                value={
                                                                    agendaForm
                                                                        .data
                                                                        .item_type
                                                                }
                                                                onValueChange={(
                                                                    v,
                                                                ) =>
                                                                    agendaForm.setData(
                                                                        'item_type',
                                                                        v,
                                                                    )
                                                                }
                                                            >
                                                                <SelectTrigger>
                                                                    <SelectValue />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    <SelectItem value="standard">
                                                                        Standard
                                                                    </SelectItem>
                                                                    <SelectItem value="decision">
                                                                        Decision
                                                                        Required
                                                                    </SelectItem>
                                                                    <SelectItem value="consent">
                                                                        Consent
                                                                    </SelectItem>
                                                                    <SelectItem value="for_info">
                                                                        For
                                                                        Information
                                                                    </SelectItem>
                                                                </SelectContent>
                                                            </Select>
                                                        </div>
                                                        <div>
                                                            <Label htmlFor="agenda-duration">
                                                                Duration (mins)
                                                            </Label>
                                                            <Input
                                                                id="agenda-duration"
                                                                type="number"
                                                                min={5}
                                                                max={120}
                                                                value={
                                                                    agendaForm
                                                                        .data
                                                                        .duration_minutes
                                                                }
                                                                onChange={(e) =>
                                                                    agendaForm.setData(
                                                                        'duration_minutes',
                                                                        e.target
                                                                            .value,
                                                                    )
                                                                }
                                                                required
                                                            />
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <Label>Presenter</Label>
                                                        <Select
                                                            value={
                                                                agendaForm.data
                                                                    .presenter_id ||
                                                                undefined
                                                            }
                                                            onValueChange={(
                                                                v,
                                                            ) =>
                                                                agendaForm.setData(
                                                                    'presenter_id',
                                                                    v,
                                                                )
                                                            }
                                                        >
                                                            <SelectTrigger>
                                                                <SelectValue placeholder="Select presenter (optional)" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                {allBoardMembers.map(
                                                                    (m) => (
                                                                        <SelectItem
                                                                            key={
                                                                                m
                                                                                    .user
                                                                                    .id
                                                                            }
                                                                            value={String(
                                                                                m
                                                                                    .user
                                                                                    .id,
                                                                            )}
                                                                        >
                                                                            {
                                                                                m
                                                                                    .user
                                                                                    .name
                                                                            }
                                                                        </SelectItem>
                                                                    ),
                                                                )}
                                                            </SelectContent>
                                                        </Select>
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        <Checkbox
                                                            id="agenda-confidential"
                                                            checked={
                                                                agendaForm.data
                                                                    .is_confidential
                                                            }
                                                            onCheckedChange={(
                                                                v,
                                                            ) =>
                                                                agendaForm.setData(
                                                                    'is_confidential',
                                                                    !!v,
                                                                )
                                                            }
                                                        />
                                                        <Label htmlFor="agenda-confidential">
                                                            Confidential item
                                                        </Label>
                                                    </div>
                                                    <div className="flex justify-end gap-2">
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            onClick={() =>
                                                                setAgendaDialogOpen(
                                                                    false,
                                                                )
                                                            }
                                                        >
                                                            Cancel
                                                        </Button>
                                                        <Button
                                                            type="submit"
                                                            disabled={
                                                                agendaForm.processing
                                                            }
                                                        >
                                                            {agendaForm.processing
                                                                ? 'Adding...'
                                                                : 'Add Item'}
                                                        </Button>
                                                    </div>
                                                </form>
                                            </DialogContent>
                                        </Dialog>
                                    )}
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-4">
                                        {agendaItems.length === 0 && (
                                            <EmptyState
                                                icon={FileText}
                                                title="No agenda items yet"
                                                description={
                                                    canEdit
                                                        ? 'Add items to build the meeting agenda.'
                                                        : 'The agenda has not been published for this meeting yet.'
                                                }
                                            />
                                        )}
                                        {agendaItems.map((item) => (
                                            <div
                                                key={item.id}
                                                className={cn(
                                                    'flex items-start gap-4 rounded-lg border p-4',
                                                    item.is_confidential &&
                                                        'border-primary bg-primary/10',
                                                )}
                                            >
                                                <div className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-muted font-semibold text-muted-foreground">
                                                    {item.order}
                                                </div>
                                                <div className="flex-1">
                                                    <div className="mb-1 flex items-center gap-2">
                                                        {getItemTypeIcon(
                                                            item.item_type,
                                                        )}
                                                        <h4 className="font-semibold text-foreground">
                                                            {item.title}
                                                        </h4>
                                                        {item.is_confidential && (
                                                            <StatusBadge variant="warning">
                                                                <Lock className="size-3" aria-hidden="true" />
                                                                Confidential
                                                            </StatusBadge>
                                                        )}
                                                        <Badge variant="outline">
                                                            {humanStatus(item.item_type)}
                                                        </Badge>
                                                    </div>
                                                    {item.description && (
                                                        <p className="mb-2 text-sm text-muted-foreground">
                                                            {item.description}
                                                        </p>
                                                    )}
                                                    <div className="flex items-center gap-4 text-sm text-muted-foreground">
                                                        {item.presenter && (
                                                            <span>
                                                                Presenter:{' '}
                                                                {
                                                                    item
                                                                        .presenter
                                                                        .name
                                                                }
                                                            </span>
                                                        )}
                                                        <span>
                                                            {
                                                                item.duration_minutes
                                                            }{' '}
                                                            minutes
                                                        </span>
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    {item.resolution_id && (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => openPaper(item.resolution_id!)}
                                                            className="gap-1.5 text-xs"
                                                        >
                                                            <Vote className="h-3.5 w-3.5 text-primary" />
                                                            Read paper & vote
                                                        </Button>
                                                    )}
                                                    {canEdit && (
                                                    <AlertDialog>
                                                        <AlertDialogTrigger
                                                            asChild
                                                        >
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                className="text-status-critical hover:text-status-critical"
                                                            >
                                                                Remove
                                                            </Button>
                                                        </AlertDialogTrigger>
                                                        <AlertDialogContent>
                                                            <AlertDialogHeader>
                                                                <AlertDialogTitle>
                                                                    Remove
                                                                    Agenda Item
                                                                </AlertDialogTitle>
                                                                <AlertDialogDescription>
                                                                    Are you sure
                                                                    you want to
                                                                    remove "
                                                                    {item.title}
                                                                    " from the
                                                                    agenda? This
                                                                    action
                                                                    cannot be
                                                                    undone.
                                                                </AlertDialogDescription>
                                                            </AlertDialogHeader>
                                                            <AlertDialogFooter>
                                                                <AlertDialogCancel>
                                                                    Cancel
                                                                </AlertDialogCancel>
                                                                <AlertDialogAction
                                                                    onClick={() =>
                                                                        removeAgendaItem(
                                                                            item.id,
                                                                        )
                                                                    }
                                                                    className="bg-status-critical hover:bg-status-critical"
                                                                >
                                                                    Remove
                                                                </AlertDialogAction>
                                                            </AlertDialogFooter>
                                                        </AlertDialogContent>
                                                    </AlertDialog>
                                                )}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {/* ========== ATTENDANCE TAB ========== */}
                        {activeTab === 'attendance' && (
                        <>
                            <Card>
                                <CardHeader className="flex flex-row items-center justify-between">
                                    <div>
                                        <CardTitle>Attendance Record</CardTitle>
                                        <CardDescription>
                                            Official roll call recorded for legal quorum and minutes attribution.
                                        </CardDescription>
                                    </div>
                                    {canEdit && (
                                        <Dialog
                                            open={attendanceDialogOpen}
                                            onOpenChange={
                                                setAttendanceDialogOpen
                                            }
                                        >
                                            <DialogTrigger asChild>
                                                <Button
                                                    size="sm"
                                                    dusk="record-attendance"
                                                >
                                                    <Users className="mr-1 h-4 w-4" />
                                                    Record Attendance
                                                </Button>
                                            </DialogTrigger>
                                            <DialogContent
                                                className="max-h-[80vh] max-w-lg overflow-y-auto"
                                                aria-describedby={undefined}
                                            >
                                                <DialogHeader>
                                                    <DialogTitle>
                                                        Record Attendance
                                                    </DialogTitle>
                                                </DialogHeader>
                                                <div className="space-y-3">
                                                    {allBoardMembers.map(
                                                        (member) => {
                                                            const memberRsvp = meeting.rsvps?.find(
                                                                (r) => r.board_member_id === member.id,
                                                            );
                                                            return (
                                                                <div
                                                                    key={member.id}
                                                                    className="flex items-center gap-3 rounded-lg border p-3"
                                                                >
                                                                    <div className="min-w-0 flex-1 truncate">
                                                                        <div className="font-medium">
                                                                            {member.user.name}
                                                                        </div>
                                                                        <div className="mt-0.5">
                                                                            {memberRsvp ? (
                                                                                <StatusBadge
                                                                                    size="sm"
                                                                                    variant={rsvpVariant(memberRsvp.response)}
                                                                                >
                                                                                    RSVP: {RSVP_LABEL[memberRsvp.response] ?? humanStatus(memberRsvp.response)}
                                                                                </StatusBadge>
                                                                            ) : (
                                                                                <span className="text-[11px] text-muted-foreground">
                                                                                    No RSVP submitted
                                                                                </span>
                                                                            )}
                                                                        </div>
                                                                    </div>
                                                                    <Select
                                                                        value={
                                                                            attendanceRecords[
                                                                                member.id
                                                                            ]?.status ||
                                                                            'unrecorded'
                                                                        }
                                                                        onValueChange={(
                                                                            v,
                                                                        ) =>
                                                                            setAttendanceRecords(
                                                                                (prev) => ({
                                                                                    ...prev,
                                                                                    [member.id]: {
                                                                                        ...prev[member.id],
                                                                                        status: v,
                                                                                    },
                                                                                }),
                                                                            )
                                                                        }
                                                                    >
                                                                        <SelectTrigger className="w-36">
                                                                            <SelectValue />
                                                                        </SelectTrigger>
                                                                        <SelectContent>
                                                                            <SelectItem value="unrecorded">
                                                                                Unrecorded
                                                                            </SelectItem>
                                                                            <SelectItem value="present">
                                                                                Present
                                                                            </SelectItem>
                                                                            <SelectItem value="late">
                                                                                Late Arrival
                                                                            </SelectItem>
                                                                            <SelectItem value="apology">
                                                                                Apology
                                                                            </SelectItem>
                                                                            <SelectItem value="no_show">
                                                                                No Show
                                                                            </SelectItem>
                                                                        </SelectContent>
                                                                    </Select>
                                                                    {attendanceRecords[
                                                                        member.id
                                                                    ]?.status ===
                                                                        'apology' && (
                                                                        <Input
                                                                            placeholder="Reason"
                                                                            className="w-40"
                                                                            value={
                                                                                attendanceRecords[
                                                                                    member.id
                                                                                ]?.apology_reason ||
                                                                                ''
                                                                            }
                                                                            onChange={(
                                                                                e,
                                                                            ) =>
                                                                                setAttendanceRecords(
                                                                                    (prev) => ({
                                                                                        ...prev,
                                                                                        [member.id]: {
                                                                                            ...prev[member.id],
                                                                                            apology_reason:
                                                                                                e.target.value,
                                                                                        },
                                                                                    }),
                                                                                )
                                                                            }
                                                                        />
                                                                    )}
                                                                </div>
                                                            );
                                                        },
                                                    )}
                                                    {allBoardMembers.length ===
                                                        0 && (
                                                        <p className="py-4 text-center text-muted-foreground">
                                                            No active board
                                                            members found.
                                                        </p>
                                                    )}
                                                </div>
                                                <div className="mt-4 flex justify-end gap-2">
                                                    <Button
                                                        variant="outline"
                                                        onClick={() =>
                                                            setAttendanceDialogOpen(
                                                                false,
                                                            )
                                                        }
                                                    >
                                                        Cancel
                                                    </Button>
                                                    <Button
                                                        onClick={
                                                            submitAttendance
                                                        }
                                                        disabled={
                                                            attendanceSubmitting
                                                        }
                                                        dusk="save-attendance"
                                                    >
                                                        {attendanceSubmitting
                                                            ? 'Saving...'
                                                            : 'Save Attendance'}
                                                    </Button>
                                                </div>
                                            </DialogContent>
                                        </Dialog>
                                    )}
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-2">
                                        {attendances.length === 0 && (
                                            <EmptyState
                                                icon={Users}
                                                title="Attendance is unrecorded"
                                                description="Awaiting the board secretary or chair to record attendance."
                                            />
                                        )}
                                        {attendances.map((attendance) => {
                                            const memberRsvp = meeting.rsvps?.find(
                                                (r) => r.board_member_id === attendance.board_member_id,
                                            );
                                            return (
                                                <div
                                                    key={attendance.id}
                                                    className="flex items-center justify-between rounded-lg border p-3"
                                                >
                                                    <div>
                                                        <span className="font-medium">
                                                            {
                                                                attendance.board_member
                                                                    .user.name
                                                            }
                                                        </span>
                                                        {memberRsvp && (
                                                            <div className="mt-0.5">
                                                                <span className="text-xs text-muted-foreground">
                                                                    RSVP: {RSVP_LABEL[memberRsvp.response] ?? humanStatus(memberRsvp.response)}
                                                                </span>
                                                            </div>
                                                        )}
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        <StatusBadge
                                                            variant={attendanceVariant(attendance.status)}
                                                        >
                                                            {humanStatus(attendance.status)}
                                                        </StatusBadge>
                                                        {attendance.apology_reason && (
                                                            <span className="text-sm text-muted-foreground">
                                                                (
                                                                {
                                                                    attendance.apology_reason
                                                                }
                                                                )
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </CardContent>
                            </Card>

                            {/* RSVP Summary Card */}
                            <Card>
                                <CardHeader>
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <CardTitle>RSVP Responses</CardTitle>
                                            <CardDescription>
                                                Member pre-meeting intentions ({meeting.rsvps?.length || 0} response{(meeting.rsvps?.length || 0) === 1 ? '' : 's'})
                                            </CardDescription>
                                        </div>
                                        {viewerCanRsvp && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => setRsvpDialogOpen(true)}
                                            >
                                                {viewerRsvp ? 'Update My RSVP' : 'Submit RSVP'}
                                            </Button>
                                        )}
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    {(!meeting.rsvps || meeting.rsvps.length === 0) ? (
                                        <EmptyState
                                            icon={CheckCircle}
                                            title="No RSVP responses yet"
                                            description="Invited members' attendance intentions appear here."
                                        />
                                    ) : (
                                        <div className="divide-y divide-border">
                                            {meeting.rsvps.map((rsvp) => (
                                                <div
                                                    key={rsvp.id}
                                                    className="py-3 flex items-center justify-between"
                                                >
                                                    <div>
                                                        <span className="font-medium text-sm">
                                                            {rsvp.board_member?.user?.name || `Member #${rsvp.board_member_id}`}
                                                        </span>
                                                        {rsvp.decline_reason && (
                                                            <p className="text-xs text-muted-foreground mt-0.5">
                                                                Reason: {rsvp.decline_reason}
                                                            </p>
                                                        )}
                                                        {rsvp.dietary_requirements && rsvp.dietary_notes && (
                                                            <p className="text-xs text-muted-foreground mt-0.5">
                                                                Dietary: {rsvp.dietary_notes}
                                                            </p>
                                                        )}
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        <StatusBadge variant={rsvpVariant(rsvp.response)}>
                                                            {RSVP_LABEL[rsvp.response] ?? humanStatus(rsvp.response)}
                                                        </StatusBadge>
                                                        {rsvp.responded_at && (
                                                            <span className="text-xs text-muted-foreground">
                                                                {formatDateLong(rsvp.responded_at)}
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </>
                        )}

                        {/* ========== MINUTES TAB ========== */}
                        {activeTab === 'minutes' && (
                            <Card>
                                <CardHeader className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <CardTitle>Meeting Minutes</CardTitle>
                                            {meeting.minutes && (
                                                <Badge variant="outline" className="font-mono text-xs">
                                                    v{meeting.minutes.version_number}
                                                </Badge>
                                            )}
                                        </div>
                                        <CardDescription className="mt-1 flex flex-wrap items-center gap-2">
                                            {meeting.minutes ? (
                                                <>
                                                    <span className="capitalize">{meeting.minutes.status}</span>
                                                    {meeting.minutes.content_hash && (
                                                        <>
                                                            <span>&bull;</span>
                                                            <span
                                                                className="font-mono text-[11px] text-muted-foreground"
                                                                title={`Full SHA-256: ${meeting.minutes.content_hash}`}
                                                            >
                                                                SHA-256: {meeting.minutes.content_hash.substring(0, 10)}...
                                                            </span>
                                                        </>
                                                    )}
                                                </>
                                            ) : (
                                                'No minutes recorded yet'
                                            )}
                                        </CardDescription>
                                    </div>

                                    {/* Action Buttons */}
                                    <div className="flex flex-wrap items-center gap-2">
                                        {/* Edit Draft Dialog */}
                                        {canManageMinutes && (!meeting.minutes || meeting.minutes.status === 'draft' || meeting.minutes.status === 'reviewed') && (
                                            <Dialog
                                                open={minutesDialogOpen}
                                                onOpenChange={setMinutesDialogOpen}
                                            >
                                                <DialogTrigger asChild>
                                                    <Button
                                                        size="sm"
                                                        variant={meeting.minutes ? 'outline' : 'default'}
                                                        dusk="edit-minutes"
                                                    >
                                                        {meeting.minutes ? (
                                                            <>
                                                                <Pencil className="mr-1 h-4 w-4" /> Edit Draft
                                                            </>
                                                        ) : (
                                                            <>
                                                                <Plus className="mr-1 h-4 w-4" /> Draft Minutes
                                                            </>
                                                        )}
                                                    </Button>
                                                </DialogTrigger>
                                                <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto" aria-describedby={undefined}>
                                                    <DialogHeader>
                                                        <DialogTitle>
                                                            {meeting.minutes ? `Edit Minutes (v${meeting.minutes.version_number})` : 'Draft Meeting Minutes'}
                                                        </DialogTitle>
                                                    </DialogHeader>
                                                    <form onSubmit={submitMinutes} className="space-y-4">
                                                        <div className="space-y-4">
                                                            {minutesBlocks.map((block, idx) => (
                                                                <div key={idx} className="space-y-2 rounded-lg border p-4">
                                                                    <div className="flex items-center justify-between gap-2">
                                                                        <Input
                                                                            dusk={idx === 0 ? 'minutes-heading-0' : undefined}
                                                                            value={block.heading}
                                                                            onChange={(e) => updateMinutesBlock(idx, 'heading', e.target.value)}
                                                                            placeholder="Section heading"
                                                                            className="font-semibold"
                                                                        />
                                                                        {minutesBlocks.length > 1 && (
                                                                            <Button
                                                                                type="button"
                                                                                variant="ghost"
                                                                                size="sm"
                                                                                className="shrink-0 text-status-critical hover:text-status-critical"
                                                                                onClick={() => removeMinutesBlock(idx)}
                                                                            >
                                                                                Remove
                                                                            </Button>
                                                                        )}
                                                                    </div>
                                                                    <Textarea
                                                                        dusk={idx === 0 ? 'minutes-content-0' : undefined}
                                                                        value={block.content}
                                                                        onChange={(e) => updateMinutesBlock(idx, 'content', e.target.value)}
                                                                        placeholder="Enter minutes for this section..."
                                                                        rows={4}
                                                                    />
                                                                </div>
                                                            ))}
                                                        </div>
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            className="w-full"
                                                            onClick={addMinutesBlock}
                                                        >
                                                            <Plus className="mr-1 h-4 w-4" /> Add Section
                                                        </Button>
                                                        <div className="flex justify-end gap-2 pt-2">
                                                            <Button
                                                                type="button"
                                                                variant="outline"
                                                                onClick={() => setMinutesDialogOpen(false)}
                                                            >
                                                                Cancel
                                                            </Button>
                                                            <Button
                                                                type="submit"
                                                                disabled={minutesSubmitting}
                                                                dusk="save-minutes"
                                                            >
                                                                {minutesSubmitting
                                                                    ? 'Saving...'
                                                                    : meeting.minutes
                                                                      ? 'Save Changes'
                                                                      : 'Create Draft'}
                                                            </Button>
                                                        </div>
                                                    </form>
                                                </DialogContent>
                                            </Dialog>
                                        )}

                                        {/* Submit for Review (Secretary/Admin when draft) */}
                                        {meeting.minutes && meeting.minutes.status === 'draft' && canManageMinutes && (
                                            <AlertDialog open={reviewDialogOpen} onOpenChange={setReviewDialogOpen}>
                                                <AlertDialogTrigger asChild>
                                                    <Button size="sm" variant="outline" dusk="submit-review-minutes">
                                                        <Send className="mr-1 h-4 w-4" /> Submit for Review
                                                    </Button>
                                                </AlertDialogTrigger>
                                                <AlertDialogContent>
                                                    <AlertDialogHeader>
                                                        <AlertDialogTitle>Submit Minutes for Formal Review</AlertDialogTitle>
                                                        <AlertDialogDescription>
                                                            Are you ready to submit these draft minutes for review? This notifies the Chair that the minutes are ready for formal board review and approval.
                                                        </AlertDialogDescription>
                                                    </AlertDialogHeader>
                                                    <AlertDialogFooter>
                                                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                        <AlertDialogAction onClick={submitForReview} disabled={minutesSubmitting}>
                                                            {minutesSubmitting ? 'Submitting...' : 'Submit for Review'}
                                                        </AlertDialogAction>
                                                    </AlertDialogFooter>
                                                </AlertDialogContent>
                                            </AlertDialog>
                                        )}

                                        {/* Approve Minutes (Chair/Admin when draft or reviewed) */}
                                        {meeting.minutes && (meeting.minutes.status === 'draft' || meeting.minutes.status === 'reviewed') && canApproveMinutes && (
                                            <AlertDialog>
                                                <AlertDialogTrigger asChild>
                                                    <Button size="sm" dusk="approve-minutes">
                                                        <FileCheck className="mr-1 h-4 w-4" /> Approve Minutes
                                                    </Button>
                                                </AlertDialogTrigger>
                                                <AlertDialogContent>
                                                    <AlertDialogHeader>
                                                        <AlertDialogTitle>Approve Meeting Minutes</AlertDialogTitle>
                                                        <AlertDialogDescription>
                                                            Approving these minutes freezes them from in-place edits and marks them approved by the Chair. The approved version will be ready for signing.
                                                        </AlertDialogDescription>
                                                    </AlertDialogHeader>
                                                    <AlertDialogFooter>
                                                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                        <AlertDialogAction onClick={submitApproveMinutes} disabled={minutesSubmitting}>
                                                            {minutesSubmitting ? 'Approving...' : 'Approve Minutes'}
                                                        </AlertDialogAction>
                                                    </AlertDialogFooter>
                                                </AlertDialogContent>
                                            </AlertDialog>
                                        )}

                                        {/* Sign Approved Version (Chair/Signatory when approved) */}
                                        {meeting.minutes && meeting.minutes.status === 'approved' && canSignMinutes && (
                                            <Dialog open={signDialogOpen} onOpenChange={setSignDialogOpen}>
                                                <DialogTrigger asChild>
                                                    <Button size="sm" dusk="sign-minutes-button">
                                                        <ShieldCheck className="mr-1 h-4 w-4" /> Sign Approved Version
                                                    </Button>
                                                </DialogTrigger>
                                                <DialogContent aria-describedby={undefined}>
                                                    <DialogHeader>
                                                        <DialogTitle>Sign and Certify Meeting Minutes</DialogTitle>
                                                    </DialogHeader>
                                                    <div className="space-y-4 py-2">
                                                        <div className="rounded-lg border bg-muted/30 p-3 space-y-1 text-sm">
                                                            <div className="flex justify-between">
                                                                <span className="text-muted-foreground">Meeting:</span>
                                                                <span className="font-medium text-foreground">{meeting.title}</span>
                                                            </div>
                                                            <div className="flex justify-between">
                                                                <span className="text-muted-foreground">Minute Version:</span>
                                                                <span className="font-mono font-medium">v{meeting.minutes.version_number}</span>
                                                            </div>
                                                            {meeting.minutes.content_hash && (
                                                                <div className="flex justify-between">
                                                                    <span className="text-muted-foreground">SHA-256 Fingerprint:</span>
                                                                    <span className="font-mono text-xs">{meeting.minutes.content_hash.substring(0, 16)}...</span>
                                                                </div>
                                                            )}
                                                        </div>

                                                        <div className="rounded-md border border-status-info/30 bg-status-info-bg/40 p-3 text-xs text-foreground space-y-1">
                                                            <p className="font-semibold flex items-center gap-1 text-status-info">
                                                                <ShieldCheck className="h-4 w-4" /> Internal Attestation Notice
                                                            </p>
                                                            <p className="text-muted-foreground leading-relaxed">
                                                                By signing, you confirm on behalf of the governing body that these minutes are an accurate, true, and complete record of proceedings. This attestation represents internal organizational sign-off and does not constitute a qualified external digital signature.
                                                            </p>
                                                        </div>
                                                    </div>
                                                    <div className="flex justify-end gap-2 pt-2">
                                                        <Button type="button" variant="outline" onClick={() => setSignDialogOpen(false)}>
                                                            Cancel
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            onClick={submitSignMinutes}
                                                            disabled={minutesSubmitting}
                                                            dusk="confirm-sign-minutes"
                                                        >
                                                            {minutesSubmitting ? 'Signing...' : 'Confirm & Sign Minutes'}
                                                        </Button>
                                                    </div>
                                                </DialogContent>
                                            </Dialog>
                                        )}

                                        {/* Correction Draft (Secretary/Admin when approved or signed) */}
                                        {meeting.minutes && (meeting.minutes.status === 'approved' || meeting.minutes.status === 'signed' || meeting.minutes.status === 'archived') && canManageMinutes && (
                                            <Dialog open={correctionDialogOpen} onOpenChange={setCorrectionDialogOpen}>
                                                <DialogTrigger asChild>
                                                    <Button size="sm" variant="outline" dusk="correction-minutes-button">
                                                        <RotateCcw className="mr-1 h-4 w-4" /> Create Correction Draft
                                                    </Button>
                                                </DialogTrigger>
                                                <DialogContent aria-describedby={undefined}>
                                                    <DialogHeader>
                                                        <DialogTitle>Create Correction Draft</DialogTitle>
                                                    </DialogHeader>
                                                    <form onSubmit={submitCorrection} className="space-y-4">
                                                        <div className="rounded-md border border-status-warning/30 bg-status-warning-bg/30 p-3 text-xs text-muted-foreground leading-relaxed">
                                                            Approved and signed minutes cannot be modified in place. Creating a correction draft will preserve current version {meeting.minutes.version_number} immutably in version history and create version {meeting.minutes.version_number + 1} as an open draft.
                                                        </div>

                                                        <div className="space-y-2">
                                                            <Label htmlFor="correction-reason" className="text-sm font-medium">
                                                                Reason for Correction <span className="text-status-critical">*</span>
                                                            </Label>
                                                            <Textarea
                                                                id="correction-reason"
                                                                dusk="correction-reason-input"
                                                                value={correctionReason}
                                                                onChange={(e) => setCorrectionReason(e.target.value)}
                                                                placeholder="Detail why this correction is required (e.g., typographical amendment, omitted attendee)..."
                                                                rows={3}
                                                                required
                                                            />
                                                        </div>

                                                        <div className="flex justify-end gap-2 pt-2">
                                                            <Button type="button" variant="outline" onClick={() => setCorrectionDialogOpen(false)}>
                                                                Cancel
                                                            </Button>
                                                            <Button
                                                                type="submit"
                                                                disabled={minutesSubmitting || correctionReason.trim().length < 5}
                                                                dusk="confirm-create-correction"
                                                            >
                                                                {minutesSubmitting ? 'Creating...' : 'Create Correction Draft'}
                                                            </Button>
                                                        </div>
                                                    </form>
                                                </DialogContent>
                                            </Dialog>
                                        )}

                                        {/* Archive Minutes (Chair/Admin when signed) */}
                                        {meeting.minutes && meeting.minutes.status === 'signed' && canApproveMinutes && (
                                            <AlertDialog open={archiveDialogOpen} onOpenChange={setArchiveDialogOpen}>
                                                <AlertDialogTrigger asChild>
                                                    <Button size="sm" variant="outline" dusk="archive-minutes-button">
                                                        <Archive className="mr-1 h-4 w-4" /> Archive Minutes
                                                    </Button>
                                                </AlertDialogTrigger>
                                                <AlertDialogContent>
                                                    <AlertDialogHeader>
                                                        <AlertDialogTitle>Archive Signed Minutes</AlertDialogTitle>
                                                        <AlertDialogDescription>
                                                            Archiving moves these signed minutes into permanent records. The signed content and full audit trail will remain readable and downloadable.
                                                        </AlertDialogDescription>
                                                    </AlertDialogHeader>
                                                    <AlertDialogFooter>
                                                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                        <AlertDialogAction onClick={submitArchiveMinutes} disabled={minutesSubmitting}>
                                                            {minutesSubmitting ? 'Archiving...' : 'Archive Minutes'}
                                                        </AlertDialogAction>
                                                    </AlertDialogFooter>
                                                </AlertDialogContent>
                                            </AlertDialog>
                                        )}
                                    </div>
                                </CardHeader>

                                <CardContent className="flex flex-col gap-5">
                                    {/* Error Banner */}
                                    {minutesError && (
                                        <div className="rounded-lg border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical flex items-center justify-between gap-2">
                                            <div className="flex items-center gap-2">
                                                <AlertTriangle className="h-4 w-4 shrink-0" />
                                                <span>{minutesError}</span>
                                            </div>
                                            <Button size="sm" variant="ghost" onClick={() => router.reload()}>
                                                Refresh Page
                                            </Button>
                                        </div>
                                    )}

                                    {meeting.minutes ? (
                                        <>
                                            {/* Integrity & Attribution Metadata Grid */}
                                            <div className="grid grid-cols-1 gap-3 rounded-lg border bg-muted/20 p-4 sm:grid-cols-2 lg:grid-cols-4 text-xs">
                                                <div className="space-y-1">
                                                    <p className="font-semibold text-muted-foreground uppercase tracking-wider text-[10px]">Lifecycle Status</p>
                                                    <div className="flex items-center gap-2">
                                                        <StatusBadge variant={minutesVariant(meeting.minutes.status)}>
                                                            {meeting.minutes.status === 'signed' ? 'Signed & immutable' : humanStatus(meeting.minutes.status)}
                                                        </StatusBadge>
                                                        {meeting.minutes.status === 'signed' && <Lock className="h-3.5 w-3.5 text-status-success" />}
                                                    </div>
                                                </div>

                                                <div className="space-y-1">
                                                    <p className="font-semibold text-muted-foreground uppercase tracking-wider text-[10px]">Drafted By</p>
                                                    <p className="font-medium text-foreground">
                                                        {meeting.minutes.drafter_name || 'Recorded'}
                                                    </p>
                                                    <p className="text-[11px] text-muted-foreground">
                                                        {meeting.minutes.drafted_at ? formatDateTimeLong(meeting.minutes.drafted_at) : 'Date not recorded'}
                                                    </p>
                                                </div>

                                                <div className="space-y-1">
                                                    <p className="font-semibold text-muted-foreground uppercase tracking-wider text-[10px]">Reviewed / Approved By</p>
                                                    <p className="font-medium text-foreground">
                                                        {meeting.minutes.reviewer_name || (meeting.minutes.reviewed_at ? 'Legacy attribution unavailable' : 'Pending review')}
                                                    </p>
                                                    <p className="text-[11px] text-muted-foreground">
                                                        {meeting.minutes.reviewed_at ? formatDateTimeLong(meeting.minutes.reviewed_at) : 'Awaiting approval'}
                                                    </p>
                                                </div>

                                                <div className="space-y-1">
                                                    <p className="font-semibold text-muted-foreground uppercase tracking-wider text-[10px]">Signatory Attestation</p>
                                                    <p className="font-medium text-foreground">
                                                        {meeting.minutes.signer_name || (meeting.minutes.signed_at ? 'Legacy attribution unavailable' : 'Unsigned')}
                                                    </p>
                                                    <p className="text-[11px] text-muted-foreground">
                                                        {meeting.minutes.signed_at ? formatDateTimeLong(meeting.minutes.signed_at) : 'Unsigned'}
                                                    </p>
                                                </div>
                                            </div>

                                            {/* Immutability Notice for Signed/Approved */}
                                            {(meeting.minutes.status === 'signed' || meeting.minutes.status === 'approved') && (
                                                <div className="flex items-center gap-2 rounded-md border border-status-success/20 bg-status-success-bg/20 px-3 py-2 text-xs text-foreground">
                                                    <ShieldCheck className="h-4 w-4 text-status-success shrink-0" />
                                                    <span>
                                                        This version is locked and immutable. Direct in-place edits are prevented to protect governance record integrity.
                                                    </span>
                                                </div>
                                            )}

                                            {/* Minutes Content Blocks */}
                                            {meeting.minutes.content_blocks && Array.isArray(meeting.minutes.content_blocks) && (
                                                <div className="flex flex-col gap-5">
                                                    {meeting.minutes.content_blocks.map((block: { heading: string; content: string }, idx: number) => (
                                                        <Card key={idx} className="gap-2 p-4 shadow-none">
                                                            <h3 className="text-section-title border-b pb-1.5">
                                                                {block.heading || `Section ${idx + 1}`}
                                                            </h3>
                                                            <p className="whitespace-pre-wrap text-sm text-foreground leading-relaxed">
                                                                {block.content || (
                                                                    <span className="italic text-muted-foreground">No content recorded for this section.</span>
                                                                )}
                                                            </p>
                                                        </Card>
                                                    ))}
                                                </div>
                                            )}

                                            {/* Version History Accordion */}
                                            {meeting.minutes.version_history && meeting.minutes.version_history.length > 0 && (
                                                <div className="rounded-lg border border-border p-4 space-y-3">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        onClick={() => setHistoryOpen(!historyOpen)}
                                                        aria-expanded={historyOpen}
                                                        className="w-full justify-between px-2 text-sm font-semibold"
                                                    >
                                                        <span className="flex items-center gap-2">
                                                            <History className="h-4 w-4 text-muted-foreground" />
                                                            <span>Version history & audit trail ({meeting.minutes.version_history.length})</span>
                                                        </span>
                                                        {historyOpen ? <ChevronDown className="h-4 w-4 text-muted-foreground" /> : <ChevronRight className="h-4 w-4 text-muted-foreground" />}
                                                    </Button>

                                                    {historyOpen && (
                                                        <div className="space-y-3 pt-2 border-t">
                                                            {meeting.minutes.version_history.map((entry, idx) => (
                                                                <div key={idx} className="rounded-md border bg-muted/20 p-3 space-y-2 text-xs">
                                                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                                                        <div className="flex items-center gap-2 font-medium">
                                                                            <Badge variant="outline" className="font-mono">
                                                                                v{entry.version ?? idx + 1}
                                                                            </Badge>
                                                                            <span className="capitalize text-muted-foreground">{entry.status ?? entry.event ?? 'Snapshot'}</span>
                                                                        </div>
                                                                        <span className="text-muted-foreground">
                                                                            {entry.updated_at || entry.archived_at || entry.timestamp || entry.created_at ? formatDateTimeLong(entry.updated_at || entry.archived_at || entry.timestamp || entry.created_at!) : ''}
                                                                        </span>
                                                                    </div>

                                                                    {entry.reason_for_correction && (
                                                                        <p className="text-muted-foreground">
                                                                            <strong className="text-foreground">Correction reason:</strong> {entry.reason_for_correction}
                                                                        </p>
                                                                    )}

                                                                    {entry.note && (
                                                                        <p className="text-muted-foreground italic">{entry.note}</p>
                                                                    )}

                                                                    {entry.content_hash && (
                                                                        <p className="font-mono text-[10px] text-muted-foreground">
                                                                            SHA-256: {entry.content_hash}
                                                                        </p>
                                                                    )}

                                                                    {/* Toggle viewing preserved content blocks */}
                                                                    {entry.content_blocks && Array.isArray(entry.content_blocks) && (
                                                                        <div className="pt-1">
                                                                            <Button
                                                                                type="button"
                                                                                variant="ghost"
                                                                                size="sm"
                                                                                className="h-7 text-[11px]"
                                                                                onClick={() => setSelectedHistoryVersion(selectedHistoryVersion === (entry.version ?? idx + 1) ? null : (entry.version ?? idx + 1))}
                                                                            >
                                                                                {selectedHistoryVersion === (entry.version ?? idx + 1) ? 'Hide Preserved Content' : 'View Preserved Content'}
                                                                            </Button>

                                                                            {selectedHistoryVersion === (entry.version ?? idx + 1) && (
                                                                                <div className="mt-2 space-y-2 rounded border bg-background p-3">
                                                                                    {entry.content_blocks.map((b, bIdx) => (
                                                                                        <div key={bIdx} className="space-y-1">
                                                                                            <h4 className="font-semibold text-foreground">{b.heading}</h4>
                                                                                            <p className="whitespace-pre-wrap text-muted-foreground">{b.content || '(empty)'}</p>
                                                                                        </div>
                                                                                    ))}
                                                                                </div>
                                                                            )}
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            ))}
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                        </>
                                    ) : (
                                        <EmptyState
                                            icon={FileText}
                                            title="No minutes recorded yet"
                                            description="Minutes appear here once the secretary drafts them."
                                            action={
                                                canManageMinutes ? (
                                                    <Button size="sm" onClick={() => setMinutesDialogOpen(true)} dusk="create-first-minutes">
                                                        <Plus className="mr-1 h-4 w-4" /> Draft minutes now
                                                    </Button>
                                                ) : undefined
                                            }
                                        />
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {/* ========== PAPERS & RESOLUTIONS TAB ========== */}
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
                                />
                            ) : (
                                <Card>
                                    <CardHeader className="flex flex-row items-center justify-between">
                                        <div>
                                            <CardTitle>Papers & resolutions</CardTitle>
                                            <CardDescription>
                                                {resolutions.length === 1
                                                    ? '1 decision paper'
                                                    : `${resolutions.length} decision papers`}{' '}
                                                for this meeting
                                            </CardDescription>
                                        </div>
                                        {canCreateResolution ? (
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    setNewResolutionOpen(true)
                                                }
                                                dusk="new-resolution-button"
                                            >
                                                <Plus className="mr-1 h-4 w-4" />
                                                New resolution
                                            </Button>
                                        ) : null}
                                    </CardHeader>
                                    <CardContent>
                                        {resolutions.length === 0 ? (
                                            <EmptyState
                                                icon={Vote}
                                                title="No decision papers yet"
                                                description="Resolutions tabled for this meeting appear here."
                                            />
                                        ) : (
                                            <ul className="space-y-2">
                                                {resolutions.map((resolution) => (
                                                    <li
                                                        key={resolution.id}
                                                        id={`meeting-paper-row-${resolution.id}`}
                                                        tabIndex={-1}
                                                        className={cn(
                                                            'flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3 transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                                            String(resolution.id) === lastClosedPaperId
                                                                ? 'border-primary/40 bg-primary/5'
                                                                : 'hover:bg-muted',
                                                        )}
                                                        data-test="meeting-paper-row"
                                                    >
                                                        <div className="min-w-0">
                                                            <p className="font-medium text-foreground">
                                                                {resolution.title}
                                                            </p>
                                                            <p className="text-sm text-muted-foreground">
                                                                {resolution.resolution_reference}
                                                                {resolution.my_vote
                                                                    ? ' · You voted'
                                                                    : resolution.status === 'open' && resolution.can_vote
                                                                      ? ' · Your vote is open'
                                                                      : ''}
                                                            </p>
                                                        </div>
                                                        <div className="flex shrink-0 items-center gap-2">
                                                            <StatusBadge status={resolution.status} />
                                                            <Button
                                                                size="sm"
                                                                onClick={() => openPaper(resolution.id)}
                                                                aria-label={`Read paper ${resolution.resolution_reference}: ${resolution.title}`}
                                                            >
                                                                Read paper
                                                                {resolution.status === 'open' && resolution.can_vote && !resolution.my_vote
                                                                    ? ' & vote'
                                                                    : ''}
                                                                <ChevronRight className="h-4 w-4" aria-hidden="true" />
                                                            </Button>
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                asChild
                                                            >
                                                                <Link
                                                                    href={showResolution.url({
                                                                        resolution: resolution.id,
                                                                    })}
                                                                    aria-label={`Open the full resolution record ${resolution.resolution_reference}`}
                                                                >
                                                                    Full record
                                                                </Link>
                                                            </Button>
                                                        </div>
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </CardContent>
                                </Card>
                            ))}

                        {/* ========== WORKFLOW TAB ========== */}
                        {activeTab === 'workflow' && (
                            <Card dusk="meeting-workflow-checklist-card">
                                <CardHeader className="pb-3">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <CardTitle>
                                                Meeting workflow
                                            </CardTitle>
                                            <CardDescription>
                                                Step-by-step checklist for this
                                                meeting cycle.
                                            </CardDescription>
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            <StatusBadge variant="success">
                                                {workflowChecklist.counts.done}{' '}
                                                complete
                                            </StatusBadge>
                                            {workflowChecklist.counts
                                                .remaining > 0 && (
                                                <StatusBadge variant="warning">
                                                    {
                                                        workflowChecklist.counts
                                                            .remaining
                                                    }{' '}
                                                    remaining
                                                </StatusBadge>
                                            )}
                                            {workflowChecklist.counts.blocked >
                                                0 && (
                                                <StatusBadge variant="critical">
                                                    {
                                                        workflowChecklist.counts
                                                            .blocked
                                                    }{' '}
                                                    blocked
                                                </StatusBadge>
                                            )}
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-5">
                                    {workflowChecklist.next_step && (
                                        <div className="rounded-lg border border-primary/30 bg-primary/10 p-4">
                                            <p className="text-xs font-medium tracking-wide text-primary uppercase">
                                                Next step
                                            </p>
                                            <p className="mt-1 text-base font-semibold text-foreground">
                                                {
                                                    workflowChecklist.next_step
                                                        .label
                                                }
                                            </p>
                                            <p className="mt-0.5 text-sm text-muted-foreground">
                                                {
                                                    workflowChecklist.next_step
                                                        .detail
                                                }
                                            </p>
                                            {workflowChecklist.next_step
                                                .action_url && (
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    className="mt-3"
                                                >
                                                    <Link
                                                        href={
                                                            workflowChecklist
                                                                .next_step
                                                                .action_url
                                                        }
                                                    >
                                                        {
                                                            workflowChecklist
                                                                .next_step
                                                                .action_label
                                                        }
                                                    </Link>
                                                </Button>
                                            )}
                                        </div>
                                    )}

                                    <div className="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
                                        {workflowChecklist.items.map((item) => (
                                            <div
                                                key={item.key}
                                                className="flex h-full flex-col gap-2 rounded-lg border p-4"
                                                dusk={`workflow-item-${item.key}`}
                                            >
                                                <div className="flex items-start justify-between gap-2">
                                                    <p className="leading-snug font-medium text-foreground">
                                                        {item.label}
                                                    </p>
                                                    <StatusBadge
                                                        className="shrink-0"
                                                        variant={checklistVariant(item.status)}
                                                        dusk={`workflow-status-${item.key}`}
                                                    >
                                                        {humanStatus(item.status)}
                                                    </StatusBadge>
                                                </div>
                                                <p className="text-sm text-muted-foreground">
                                                    {item.detail}
                                                </p>
                                                {item.blocked_by && (
                                                    <p className="text-xs text-status-critical italic">
                                                        Blocked by:{' '}
                                                        {item.blocked_by}
                                                    </p>
                                                )}
                                                <div className="mt-auto pt-2">
                                                    <Button
                                                        size="sm"
                                                        variant={
                                                            item.status ===
                                                            'done'
                                                                ? 'ghost'
                                                                : 'outline'
                                                        }
                                                        asChild
                                                        className="w-full"
                                                    >
                                                        <Link
                                                            href={
                                                                item.action_url
                                                            }
                                                        >
                                                            {item.action_label}
                                                        </Link>
                                                    </Button>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                </section>
              </div>
            </PageLayout>
        </AppLayout>
    );
}

/**
 * Strip of meeting status mini-cards rendered under the hero. Surfaces
 * Chair, Secretary, CEO Report, Board Pack, Quorum, Pending Resolutions,
 * Minutes and Previous Follow-through so the board can scan the meeting
 * state in one row without scrolling.
 */
function MeetingStatusStrip({
    meeting,
    quorum,
    workflowChecklist,
    resolutions,
    attendances,
}: {
    meeting: {
        id: number;
        chair: { user: { name: string } } | null;
        secretary: { user: { name: string } } | null;
        board_pack: { distributed_at: string | null } | null;
        minutes: { status: string } | null;
    };
    quorum: { present: number; required: number; met: boolean };
    workflowChecklist: { items: Array<{ key: string; status: string }> };
    resolutions: Array<{ status: string }>;
    attendances: Array<{ status: string }>;
}) {
    const ceoStep = workflowChecklist.items.find((i) => i.key === 'ceo_report');
    const followStep = workflowChecklist.items.find(
        (i) => i.key === 'follow_through',
    );

    const minuteStatus = meeting.minutes?.status ?? null;
    const minuteValue = minuteStatus
        ? minuteStatus.charAt(0).toUpperCase() + minuteStatus.slice(1)
        : 'Not drafted';

    const packDistributed = Boolean(meeting.board_pack?.distributed_at);
    const packPresent = Boolean(meeting.board_pack);
    const packValue = packDistributed
        ? 'Distributed'
        : packPresent
          ? 'Generated'
          : 'Not started';
    const pendingResolutions = resolutions.filter((r) =>
        ['draft', 'open'].includes(r.status),
    ).length;

    const presentAttendees = attendances.filter(
        (a) => a.status === 'present',
    ).length;

    type Tile = {
        label: string;
        value: string;
        tone: 'success' | 'info' | 'warning' | 'critical' | 'muted';
    };
    const tiles: Tile[] = [
        {
            label: 'Chair',
            value: meeting.chair?.user.name ?? 'Unassigned',
            tone: meeting.chair ? 'info' : 'warning',
        },
        {
            label: 'Secretary',
            value: meeting.secretary?.user.name ?? 'Unassigned',
            tone: meeting.secretary ? 'info' : 'warning',
        },
        {
            label: 'CEO Report',
            value:
                ceoStep?.status === 'done'
                    ? 'Submitted'
                    : ceoStep?.status === 'blocked'
                      ? 'Blocked'
                      : 'Pending',
            tone:
                ceoStep?.status === 'done'
                    ? 'success'
                    : ceoStep?.status === 'blocked'
                      ? 'critical'
                      : 'warning',
        },
        {
            label: 'Board Pack',
            value: packValue,
            tone: packDistributed
                ? 'success'
                : packPresent
                  ? 'info'
                  : 'warning',
        },
        {
            label: 'Quorum',
            value: `${quorum.present}/${quorum.required}`,
            tone: quorum.met
                ? 'success'
                : presentAttendees > 0
                  ? 'info'
                  : 'warning',
        },
        {
            label: 'Pending Resolutions',
            value: String(pendingResolutions),
            tone: pendingResolutions > 0 ? 'warning' : 'success',
        },
        {
            label: 'Minutes',
            value: minuteValue,
            tone: ['signed', 'approved', 'archived'].includes(
                minuteStatus ?? '',
            )
                ? 'success'
                : minuteStatus
                  ? 'info'
                  : 'warning',
        },
        {
            label: 'Previous Follow-through',
            value: followStep?.status === 'done' ? 'Reviewed' : 'Open items',
            tone: followStep?.status === 'done' ? 'success' : 'warning',
        },
    ];

    const TONE_VALUE: Record<Tile['tone'], string> = {
        success: 'text-status-success',
        info: 'text-foreground',
        warning: 'text-status-warning',
        critical: 'text-status-critical',
        muted: 'text-muted-foreground',
    };

    return (
        <div
            className="grid gap-5 md:grid-cols-2 lg:grid-cols-4"
            dusk="meeting-status-strip"
        >
            {tiles.map((t) => (
                <Card key={t.label}>
                    <CardContent className="p-4">
                        <p className="text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                            {t.label}
                        </p>
                        <p
                            className={cn(
                                'mt-1 truncate text-sm leading-snug font-semibold',
                                TONE_VALUE[t.tone],
                            )}
                            title={t.value}
                        >
                            {t.value}
                        </p>
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}
