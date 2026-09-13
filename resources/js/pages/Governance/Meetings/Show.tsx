import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { PageTabs, type PageTabItem } from '@/components/page/page-tabs';
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
import { TabsContent } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { governanceStatusColor } from '@/lib/governance-status';
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
import { NewResolutionDialog } from '../Resolutions/_dialogs';

interface BoardMemberItem {
    id: number;
    user: { id: number; name: string };
}

interface Meeting {
    id: number;
    title: string;
    meeting_type: string;
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
    meetingCockpit,
    viewerCanRsvp,
    viewerRsvp,
}: Props) {
    const page = usePage();
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
    const validTabs = [
        'agenda',
        'attendance',
        'minutes',
        'resolutions',
        'workflow',
    ];
    const urlParams = new URLSearchParams(page.url.split('?')[1] ?? '');
    const parsedTab = urlParams.get('tab');
    const parsedPaper = urlParams.get('paper');
    const defaultTab =
        parsedTab && validTabs.includes(parsedTab)
            ? parsedTab
            : parsedPaper
              ? 'resolutions'
              : 'agenda';
    const [activeTab, setActiveTab] = useState(defaultTab);
    const [selectedPaperId, setSelectedPaperId] = useState<string | null>(parsedPaper);

    const agendaItems = meeting.agenda_items ?? [];
    const attendances = meeting.attendances ?? [];
    const resolutions = meeting.resolutions ?? [];
    const allBoardMembers = boardMembers ?? [];

    useEffect(() => {
        setActiveTab(defaultTab);
        if (parsedPaper) setSelectedPaperId(parsedPaper);
    }, [defaultTab, parsedPaper]);

    const handleTabChange = (newTab: string) => {
        setActiveTab(newTab);
        const url = new URL(window.location.href);
        url.searchParams.set('tab', newTab);
        window.history.replaceState({}, '', url.toString());
    };

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

    const getStatusColor = (status: string) => governanceStatusColor(status);

    const getChecklistStatusColor = (
        status: 'done' | 'in_progress' | 'todo' | 'blocked',
    ) => {
        return {
            done: 'bg-status-success-bg text-status-success border-status-success/30',
            in_progress:
                'bg-status-info-bg text-status-info border-status-info/30',
            todo: 'bg-status-warning-bg text-status-warning border-status-warning/30',
            blocked:
                'bg-status-critical-bg text-status-critical border-status-critical/30',
        }[status];
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

    const formatDate = (dateString: string) => {
        return new Date(dateString).toLocaleDateString('en-NZ', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric',
        });
    };

    const formatTime = (dateString: string) => {
        return new Date(dateString).toLocaleTimeString('en-NZ', {
            hour: 'numeric',
            minute: '2-digit',
        });
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Meetings', href: '/governance/meetings' },
                {
                    title: 'Meeting',
                    href: `/governance/meetings/${meeting.id}`,
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
                                variant={
                                    meeting.status === 'in_progress' || meeting.status === 'scheduled'
                                        ? 'info'
                                        : meeting.status === 'completed'
                                          ? 'success'
                                          : meeting.status === 'cancelled'
                                            ? 'critical'
                                            : 'neutral'
                                }
                            >
                                {meeting.status.replace('_', ' ')}
                            </PageHeaderStatusChip>
                        }
                        subline={
                            <span>
                                <span>{formatDate(meeting.scheduled_at)}</span>
                                <span> · </span>
                                <span>
                                    {formatTime(meeting.scheduled_at)} ({meeting.duration_minutes} mins)
                                </span>
                                {meeting.location && (
                                    <>
                                        <span> · </span>
                                        <span>{meeting.location}</span>
                                    </>
                                )}
                                {meeting.chair?.user?.name && (
                                    <>
                                        <span> · </span>
                                        <span>Chair: {meeting.chair.user.name}</span>
                                    </>
                                )}
                                {meeting.secretary?.user?.name && (
                                    <>
                                        <span> · </span>
                                        <span>Secretary: {meeting.secretary.user.name}</span>
                                    </>
                                )}
                            </span>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Workflow"
                                    href="#tab-workflow"
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
                                    onClick={() => handleTabChange('agenda')}
                                >
                                    <PageHeaderMeterBig>{agendaItems.length}</PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>Scheduled items</PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Resolutions"
                                    href="#tab-resolutions"
                                    onClick={() => handleTabChange('resolutions')}
                                >
                                    <PageHeaderMeterBig>{resolutions.length}</PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>Decisions filed</PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                {canEdit && (
                                    <Button variant="outline" asChild>
                                        <Link
                                            href={`/governance/meetings/${meeting.id}/edit`}
                                        >
                                            Edit
                                        </Link>
                                    </Button>
                                )}
                                {meeting.board_pack ? (
                                    <Button asChild>
                                        <Link
                                            href={showPack.url({
                                                pack: meeting.board_pack.id,
                                            })}
                                            dusk="view-pack"
                                        >
                                            <FileDown className="mr-2 h-4 w-4" />
                                            View Pack
                                        </Link>
                                    </Button>
                                ) : canEdit ? (
                                    <Button
                                        onClick={generatePack}
                                        disabled={generatingPack}
                                        dusk="generate-pack"
                                    >
                                        {generatingPack
                                            ? 'Generating...'
                                            : 'Generate Pack'}
                                    </Button>
                                ) : null}
                            </div>
                        }
                    />
                }
            >
                {packMessage && (
                    <div className="mb-4 rounded-lg border border-status-info/30 bg-status-info-bg px-4 py-2 text-sm text-status-info">
                        {packMessage}
                    </div>
                )}

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
                />

                {/* Member RSVP Callout */}
                {viewerCanRsvp && (
                    <div className="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4 rounded-lg border border-primary/20 bg-primary/5 p-4" data-test="meeting-rsvp-banner">
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
                                        ? `Recorded on ${new Date(viewerRsvp.responded_at || '').toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}${viewerRsvp.decline_reason ? ` • Apology reason: ${viewerRsvp.decline_reason}` : ''}${viewerRsvp.dietary_notes ? ` • Dietary notes: ${viewerRsvp.dietary_notes}` : ''}`
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

                <div className="space-y-6">
                    {/* Tabs (Sites-style PageTabs) */}
                    <PageTabs
                        value={activeTab}
                        onValueChange={handleTabChange}
                        items={
                            [
                                {
                                    value: 'agenda',
                                    label: `Agenda (${agendaItems.length})`,
                                    icon: FileText,
                                    'data-test': 'meeting-tab-agenda',
                                },
                                {
                                    value: 'attendance',
                                    label: 'Attendance',
                                    icon: Users,
                                    'data-test': 'meeting-tab-attendance',
                                },
                                {
                                    value: 'minutes',
                                    label: 'Minutes',
                                    icon: Pencil,
                                    'data-test': 'meeting-tab-minutes',
                                },
                                {
                                    value: 'resolutions',
                                    label: `Resolutions (${resolutions.length})`,
                                    icon: Vote,
                                    'data-test': 'meeting-tab-resolutions',
                                },
                                {
                                    value: 'workflow',
                                    label: 'Workflow',
                                    icon: ListChecks,
                                    'data-test': 'meeting-tab-workflow',
                                },
                            ] as PageTabItem[]
                        }
                    >
                        {/* ========== AGENDA TAB ========== */}
                        <TabsContent value="agenda">
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
                                            <p className="py-8 text-center text-muted-foreground">
                                                No agenda items yet. Add items
                                                to build the meeting agenda.
                                            </p>
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
                                                            <Badge
                                                                variant="outline"
                                                                className="border-primary text-primary"
                                                            >
                                                                Confidential
                                                            </Badge>
                                                        )}
                                                        <Badge variant="outline">
                                                            {item.item_type}
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
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        </TabsContent>

                        {/* ========== ATTENDANCE TAB ========== */}
                        {/* ========== ATTENDANCE TAB ========== */}
                        <TabsContent value="attendance" className="space-y-6">
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
                                                                                <Badge
                                                                                    variant="outline"
                                                                                    className={cn(
                                                                                        'text-[10px] uppercase tracking-wider',
                                                                                        memberRsvp.response === 'accepted' && 'border-emerald-500 text-emerald-700 dark:text-emerald-300',
                                                                                        memberRsvp.response === 'declined' && 'border-amber-500 text-amber-700 dark:text-amber-300',
                                                                                        memberRsvp.response === 'tentative' && 'border-blue-500 text-blue-700 dark:text-blue-300',
                                                                                    )}
                                                                                >
                                                                                    RSVP: {memberRsvp.response === 'accepted' ? 'Attending' : memberRsvp.response === 'declined' ? 'Apology' : 'Tentative'}
                                                                                </Badge>
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
                                            <div className="py-8 text-center text-muted-foreground">
                                                <Users className="mx-auto h-8 w-8 mb-2 opacity-40" />
                                                <p className="font-medium">Attendance is unrecorded.</p>
                                                <p className="text-sm">Awaiting board secretary or chair to record attendance.</p>
                                            </div>
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
                                                                    RSVP: {memberRsvp.response === 'accepted' ? 'Attending' : memberRsvp.response === 'declined' ? 'Apology' : 'Tentative'}
                                                                </span>
                                                            </div>
                                                        )}
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        <Badge
                                                            className={cn(
                                                                attendance.status ===
                                                                    'present' &&
                                                                    'bg-status-success-bg text-status-success',
                                                                attendance.status ===
                                                                    'apology' &&
                                                                    'bg-status-warning-bg text-status-warning',
                                                                attendance.status ===
                                                                    'no_show' &&
                                                                    'bg-status-critical-bg text-status-critical',
                                                                attendance.status ===
                                                                    'late' &&
                                                                    'bg-status-info-bg text-status-info',
                                                            )}
                                                        >
                                                            {attendance.status.replace(
                                                                '_',
                                                                ' ',
                                                            )}
                                                        </Badge>
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
                                        <p className="py-6 text-center text-muted-foreground">
                                            No RSVP responses recorded yet.
                                        </p>
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
                                                        <Badge
                                                            variant={
                                                                rsvp.response === 'accepted'
                                                                    ? 'default'
                                                                    : rsvp.response === 'declined'
                                                                      ? 'destructive'
                                                                      : 'secondary'
                                                            }
                                                        >
                                                            {rsvp.response === 'accepted'
                                                                ? 'Attending'
                                                                : rsvp.response === 'declined'
                                                                  ? 'Apology'
                                                                  : 'Tentative'}
                                                        </Badge>
                                                        {rsvp.responded_at && (
                                                            <span className="text-xs text-muted-foreground">
                                                                {new Date(rsvp.responded_at).toLocaleDateString()}
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </TabsContent>

                        {/* ========== MINUTES TAB ========== */}
                        <TabsContent value="minutes">
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
                                                    <Button size="sm" className="bg-status-success text-white hover:bg-status-success/90" dusk="sign-minutes-button">
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
                                                            className="bg-status-success text-white hover:bg-status-success/90"
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

                                <CardContent className="space-y-6">
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
                                                        <Badge
                                                            className={cn(
                                                                meeting.minutes.status === 'draft' && 'bg-status-warning-bg text-status-warning',
                                                                meeting.minutes.status === 'reviewed' && 'bg-status-info-bg text-status-info',
                                                                meeting.minutes.status === 'approved' && 'bg-purple-100 text-purple-800 dark:bg-purple-950/40 dark:text-purple-300',
                                                                meeting.minutes.status === 'signed' && 'bg-status-success-bg text-status-success',
                                                                meeting.minutes.status === 'archived' && 'bg-muted text-muted-foreground',
                                                            )}
                                                        >
                                                            {meeting.minutes.status === 'signed' ? 'Signed & Immutable' : meeting.minutes.status}
                                                        </Badge>
                                                        {meeting.minutes.status === 'signed' && <Lock className="h-3.5 w-3.5 text-status-success" />}
                                                    </div>
                                                </div>

                                                <div className="space-y-1">
                                                    <p className="font-semibold text-muted-foreground uppercase tracking-wider text-[10px]">Drafted By</p>
                                                    <p className="font-medium text-foreground">
                                                        {meeting.minutes.drafter_name || 'Recorded'}
                                                    </p>
                                                    <p className="text-[11px] text-muted-foreground">
                                                        {meeting.minutes.drafted_at ? formatDate(meeting.minutes.drafted_at) : 'Date not recorded'}
                                                    </p>
                                                </div>

                                                <div className="space-y-1">
                                                    <p className="font-semibold text-muted-foreground uppercase tracking-wider text-[10px]">Reviewed / Approved By</p>
                                                    <p className="font-medium text-foreground">
                                                        {meeting.minutes.reviewer_name || (meeting.minutes.reviewed_at ? 'Legacy attribution unavailable' : 'Pending review')}
                                                    </p>
                                                    <p className="text-[11px] text-muted-foreground">
                                                        {meeting.minutes.reviewed_at ? formatDate(meeting.minutes.reviewed_at) : 'Awaiting approval'}
                                                    </p>
                                                </div>

                                                <div className="space-y-1">
                                                    <p className="font-semibold text-muted-foreground uppercase tracking-wider text-[10px]">Signatory Attestation</p>
                                                    <p className="font-medium text-foreground">
                                                        {meeting.minutes.signer_name || (meeting.minutes.signed_at ? 'Legacy attribution unavailable' : 'Unsigned')}
                                                    </p>
                                                    <p className="text-[11px] text-muted-foreground">
                                                        {meeting.minutes.signed_at ? formatDate(meeting.minutes.signed_at) : 'Unsigned'}
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
                                                <div className="space-y-6 pt-2">
                                                    {meeting.minutes.content_blocks.map((block: { heading: string; content: string }, idx: number) => (
                                                        <div key={idx} className="rounded-lg border bg-card p-4 space-y-2">
                                                            <h3 className="text-base font-semibold text-foreground border-b pb-1.5">
                                                                {block.heading || `Section ${idx + 1}`}
                                                            </h3>
                                                            <p className="whitespace-pre-wrap text-sm text-foreground leading-relaxed">
                                                                {block.content || (
                                                                    <span className="italic text-muted-foreground">No content recorded for this section.</span>
                                                                )}
                                                            </p>
                                                        </div>
                                                    ))}
                                                </div>
                                            )}

                                            {/* Version History Accordion */}
                                            {meeting.minutes.version_history && meeting.minutes.version_history.length > 0 && (
                                                <div className="rounded-lg border border-border p-4 space-y-3">
                                                    <button
                                                        type="button"
                                                        onClick={() => setHistoryOpen(!historyOpen)}
                                                        className="flex w-full items-center justify-between text-left text-sm font-semibold text-foreground hover:text-primary transition"
                                                    >
                                                        <div className="flex items-center gap-2">
                                                            <History className="h-4 w-4 text-muted-foreground" />
                                                            <span>Version History & Audit Trail ({meeting.minutes.version_history.length})</span>
                                                        </div>
                                                        {historyOpen ? <ChevronDown className="h-4 w-4 text-muted-foreground" /> : <ChevronRight className="h-4 w-4 text-muted-foreground" />}
                                                    </button>

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
                                                                            {entry.updated_at || entry.archived_at || entry.timestamp || entry.created_at ? formatDate(entry.updated_at || entry.archived_at || entry.timestamp || entry.created_at!) : ''}
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
                                        <div className="py-12 text-center text-muted-foreground space-y-3">
                                            <FileText className="mx-auto h-10 w-10 text-muted-foreground/50" />
                                            <p className="text-sm">No minutes have been recorded for this meeting yet.</p>
                                            {canManageMinutes && (
                                                <Button size="sm" onClick={() => setMinutesDialogOpen(true)} dusk="create-first-minutes">
                                                    <Plus className="mr-1 h-4 w-4" /> Draft Minutes Now
                                                </Button>
                                            )}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </TabsContent>

                        {/* ========== RESOLUTIONS TAB ========== */}
                        <TabsContent value="resolutions">
                            <Card>
                                <CardHeader className="flex flex-row items-center justify-between">
                                    <CardTitle>Resolutions</CardTitle>
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            setNewResolutionOpen(true)
                                        }
                                        dusk="new-resolution-button"
                                    >
                                        <Plus className="mr-1 h-4 w-4" />
                                        New Resolution
                                    </Button>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-2">
                                        {resolutions.length === 0 && (
                                            <p className="py-8 text-center text-muted-foreground">
                                                No resolutions for this meeting.
                                            </p>
                                        )}
                                        {resolutions.map((resolution) => {
                                            const isSelected =
                                                selectedPaperId !== null &&
                                                String(resolution.id) === String(selectedPaperId);
                                            return (
                                                <div
                                                    key={resolution.id}
                                                    className={cn(
                                                        'flex items-center justify-between rounded-lg border p-3 transition-colors',
                                                        isSelected
                                                            ? 'border-primary bg-primary/5 ring-1 ring-primary/30'
                                                            : 'hover:bg-muted',
                                                    )}
                                                >
                                                    <div>
                                                        <div className="flex items-center gap-2">
                                                            <p className="font-medium text-foreground">
                                                                {resolution.title}
                                                            </p>
                                                            {isSelected && (
                                                                <Badge
                                                                    variant="outline"
                                                                    className="border-primary/40 text-[10px] text-primary"
                                                                >
                                                                    Selected Paper
                                                                </Badge>
                                                            )}
                                                        </div>
                                                        <p className="text-sm text-muted-foreground">
                                                            {
                                                                resolution.resolution_reference
                                                            }
                                                        </p>
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        <Badge>
                                                            {resolution.status}
                                                        </Badge>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={showResolution.url(
                                                                    {
                                                                        resolution:
                                                                            resolution.id,
                                                                    },
                                                                )}
                                                            >
                                                                View &rarr;
                                                            </Link>
                                                        </Button>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </CardContent>
                            </Card>
                        </TabsContent>
                        {/* ========== WORKFLOW TAB ========== */}
                        <TabsContent value="workflow">
                            <Card dusk="meeting-workflow-checklist-card">
                                <CardHeader className="pb-3">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <CardTitle>
                                                Meeting Workflow
                                            </CardTitle>
                                            <CardDescription>
                                                Step-by-step checklist for this
                                                meeting cycle.
                                            </CardDescription>
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            <Badge variant="outline">
                                                {workflowChecklist.counts.done}{' '}
                                                complete
                                            </Badge>
                                            {workflowChecklist.counts
                                                .remaining > 0 && (
                                                <Badge className="border-status-warning/30 bg-status-warning-bg text-status-warning">
                                                    {
                                                        workflowChecklist.counts
                                                            .remaining
                                                    }{' '}
                                                    remaining
                                                </Badge>
                                            )}
                                            {workflowChecklist.counts.blocked >
                                                0 && (
                                                <Badge className="border-status-critical/30 bg-status-critical-bg text-status-critical">
                                                    {
                                                        workflowChecklist.counts
                                                            .blocked
                                                    }{' '}
                                                    blocked
                                                </Badge>
                                            )}
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {workflowChecklist.next_step && (
                                        <div className="rounded-lg border border-primary/30 bg-primary/10 p-4">
                                            <p className="text-xs font-medium tracking-wide text-primary uppercase">
                                                Next Step
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

                                    <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
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
                                                    <Badge
                                                        className={cn(
                                                            'shrink-0',
                                                            getChecklistStatusColor(
                                                                item.status,
                                                            ),
                                                        )}
                                                        dusk={`workflow-status-${item.key}`}
                                                    >
                                                        {item.status.replace(
                                                            '_',
                                                            ' ',
                                                        )}
                                                    </Badge>
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
                                                                : item.status ===
                                                                    'blocked'
                                                                  ? 'outline'
                                                                  : 'outline'
                                                        }
                                                        asChild
                                                        className="w-full"
                                                        disabled={
                                                            item.status ===
                                                            'blocked'
                                                        }
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
                        </TabsContent>
                    </PageTabs>
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
            className="grid gap-3 md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-4"
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
