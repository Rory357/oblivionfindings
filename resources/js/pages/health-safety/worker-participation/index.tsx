import AppLayout from '@/layouts/app-layout';
import FleetHero from '@/components/fleet-hero';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { TabsRoot, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import { Head, useForm, router } from '@inertiajs/react';
import { useState, useRef, useEffect } from 'react';
import {
    Users,
    Building2,
    CalendarDays,
    MessageSquare,
    Plus,
    ShieldCheck,
    GraduationCap,
    MapPin,
    Clock,
    CheckCircle2,
    XCircle,
    AlertTriangle,
    FileText,
    Trash2,
    ClipboardList,
    UserPlus,
    Megaphone,
    Download,
    Upload,
    Calendar,
    Paperclip,
    ChevronRight,
    Pencil,
} from 'lucide-react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

type Representative = {
    id: number;
    user: { id: number; name: string } | null;
    site: { id: number; name: string } | null;
    work_group: string | null;
    election_method: string | null;
    elected_date: string | null;
    training_days: number;
    status: string;
};

type ActionItem = {
    id?: number;
    description: string;
    assigned_to?: number | null;
    assignee_name?: string;
    due_date?: string | null;
    status: string;
};

type Attendee = {
    id: number;
    user_id: number;
    name: string;
    confirmed: boolean;
};

type Meeting = {
    id: number;
    committee_name: string;
    committee_id?: number;
    meeting_date: string;
    location: string | null;
    status: string;
    action_items_count: number;
    attendees_count?: number;
    confirmed_attendees?: Attendee[];
    minutes_document_path?: string | null;
    minutes_document_name?: string | null;
    action_items?: ActionItem[];
};

type Consultation = {
    id: number;
    title: string;
    consultation_type: string;
    consultation_date: string;
    workers_consulted: number;
    status: string;
    site?: { id: number; name: string } | null;
    description?: string;
    document_path?: string | null;
    document_name?: string | null;
    outcome_document_path?: string | null;
    outcome_document_name?: string | null;
    worker_feedback_summary?: string | null;
    outcome?: string | null;
    changes_made?: string | null;
};

type Props = {
    stats: {
        active_reps: number;
        active_committees: number;
        meetings_this_month: number;
        open_consultations: number;
    };
    representatives: Representative[];
    meetings: Meeting[];
    consultations: Consultation[];
    sites: Array<{ id: number; name: string }>;
    staff: Array<{ id: number; name: string }>;
    committees?: Array<{ id: number; name: string }>;
};

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const statusBadge = (status: string) => {
    switch (status) {
        case 'active':
            return <Badge className="bg-green-100 text-green-800 border-green-200">{status}</Badge>;
        case 'completed':
        case 'closed':
            return <Badge className="bg-slate-100 text-slate-700 border-slate-200">{status}</Badge>;
        case 'scheduled':
        case 'pending':
            return <Badge className="bg-blue-100 text-blue-800 border-blue-200">{status}</Badge>;
        case 'in_progress':
        case 'open':
            return <Badge className="bg-amber-100 text-amber-800 border-amber-200">{status}</Badge>;
        case 'feedback_received':
            return <Badge className="bg-indigo-100 text-indigo-800 border-indigo-200">feedback received</Badge>;
        case 'actioned':
            return <Badge className="bg-teal-100 text-teal-800 border-teal-200">actioned</Badge>;
        case 'inactive':
        case 'expired':
        case 'cancelled':
            return <Badge className="bg-red-100 text-red-800 border-red-200">{status}</Badge>;
        default:
            return <Badge className="bg-slate-100 text-slate-700 border-slate-200">{status}</Badge>;
    }
};

const meetingStatusBorder = (status: string) => {
    switch (status) {
        case 'scheduled':
            return 'border-l-blue-500';
        case 'completed':
            return 'border-l-green-500';
        case 'cancelled':
            return 'border-l-red-500';
        default:
            return 'border-l-slate-300';
    }
};

const consultationTypeLabel: Record<string, string> = {
    hazard_identified: 'Hazard Identified',
    risk_assessment: 'Risk Assessment',
    procedure_change: 'Procedure Change',
    policy_change: 'Policy Change',
    equipment_change: 'Equipment Change',
    other: 'Other',
    general: 'General',
    workplace_change: 'Workplace Change',
    ppe: 'PPE',
    training: 'Training',
};

const consultationTypeColor: Record<string, string> = {
    hazard_identified: 'bg-red-100 text-red-800 border-red-200',
    risk_assessment: 'bg-amber-100 text-amber-800 border-amber-200',
    procedure_change: 'bg-blue-100 text-blue-800 border-blue-200',
    policy_change: 'bg-purple-100 text-purple-800 border-purple-200',
    equipment_change: 'bg-cyan-100 text-cyan-800 border-cyan-200',
    other: 'bg-slate-100 text-slate-700 border-slate-200',
    general: 'bg-slate-100 text-slate-700 border-slate-200',
    workplace_change: 'bg-indigo-100 text-indigo-800 border-indigo-200',
    ppe: 'bg-orange-100 text-orange-800 border-orange-200',
    training: 'bg-teal-100 text-teal-800 border-teal-200',
};

const formatDate = (d: string) => {
    try {
        return new Date(d).toLocaleDateString('en-NZ', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });
    } catch {
        return d;
    }
};

const formatDateTime = (d: string) => {
    try {
        return new Date(d).toLocaleString('en-NZ', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return d;
    }
};

/* ------------------------------------------------------------------ */
/*  Consultation workflow steps                                        */
/* ------------------------------------------------------------------ */

const CONSULTATION_STEPS = ['open', 'feedback_received', 'actioned', 'closed'] as const;
const CONSULTATION_STEP_LABELS: Record<string, string> = {
    open: 'Open',
    feedback_received: 'Feedback Received',
    actioned: 'Actioned',
    closed: 'Closed',
};

function ConsultationProgressBar({ status }: { status: string }) {
    const currentIdx = CONSULTATION_STEPS.indexOf(status as any);
    return (
        <div className="flex items-center gap-1">
            {CONSULTATION_STEPS.map((step, idx) => {
                const isCompleted = currentIdx > idx;
                const isCurrent = currentIdx === idx;
                return (
                    <div key={step} className="flex items-center gap-1 flex-1">
                        <div className="flex flex-col items-center flex-1">
                            <div
                                className={`flex h-6 w-6 items-center justify-center rounded-full text-xs font-medium transition-colors ${
                                    isCompleted
                                        ? 'bg-green-500 text-white'
                                        : isCurrent
                                          ? 'bg-blue-500 text-white'
                                          : 'bg-slate-200 text-slate-500'
                                }`}
                            >
                                {isCompleted ? (
                                    <CheckCircle2 className="h-3.5 w-3.5" />
                                ) : (
                                    idx + 1
                                )}
                            </div>
                            <span
                                className={`mt-1 text-[10px] leading-tight text-center ${
                                    isCurrent ? 'font-semibold text-blue-700' : 'text-muted-foreground'
                                }`}
                            >
                                {CONSULTATION_STEP_LABELS[step]}
                            </span>
                        </div>
                        {idx < CONSULTATION_STEPS.length - 1 && (
                            <div
                                className={`h-0.5 flex-1 rounded-full mb-4 ${
                                    isCompleted ? 'bg-green-400' : 'bg-slate-200'
                                }`}
                            />
                        )}
                    </div>
                );
            })}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Consultation type card-button options                              */
/* ------------------------------------------------------------------ */

const consultationTypes = [
    { value: 'hazard_identified', label: 'Hazard Identified', icon: AlertTriangle, color: 'text-red-600 bg-red-50 border-red-200' },
    { value: 'risk_assessment', label: 'Risk Assessment', icon: ShieldCheck, color: 'text-amber-600 bg-amber-50 border-amber-200' },
    { value: 'procedure_change', label: 'Procedure Change', icon: ClipboardList, color: 'text-blue-600 bg-blue-50 border-blue-200' },
    { value: 'policy_change', label: 'Policy Change', icon: FileText, color: 'text-purple-600 bg-purple-50 border-purple-200' },
    { value: 'equipment_change', label: 'Equipment Change', icon: Building2, color: 'text-cyan-600 bg-cyan-50 border-cyan-200' },
    { value: 'other', label: 'Other', icon: MessageSquare, color: 'text-slate-600 bg-slate-50 border-slate-200' },
] as const;

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

export default function WorkerParticipationIndex({
    stats,
    representatives,
    meetings,
    consultations,
    sites,
    staff,
    committees = [],
}: Props) {
    const [activeTab, setActiveTab] = useState('representatives');
    const [repOpen, setRepOpen] = useState(false);
    const [meetingOpen, setMeetingOpen] = useState(false);
    const [consultationOpen, setConsultationOpen] = useState(false);
    const [meetingLocationMode, setMeetingLocationMode] = useState<'site' | 'custom'>('site');

    /* Consultation workflow dialogs */
    const [feedbackDialogId, setFeedbackDialogId] = useState<number | null>(null);
    const [outcomeDialogId, setOutcomeDialogId] = useState<number | null>(null);
    const [closeDialogId, setCloseDialogId] = useState<number | null>(null);
    const [consultDocUploadId, setConsultDocUploadId] = useState<number | null>(null);
    const [consultDocUploadType, setConsultDocUploadType] = useState<'document' | 'outcome'>('document');

    /* Meeting workflow dialogs */
    const [completeMeetingId, setCompleteMeetingId] = useState<number | null>(null);
    const [cancelMeetingId, setCancelMeetingId] = useState<number | null>(null);
    const [manageMembersId, setManageMembersId] = useState<number | null>(null);
    const [minutesUploadId, setMinutesUploadId] = useState<number | null>(null);
    const [meetingSuccessMessage, setMeetingSuccessMessage] = useState<string | null>(null);
    const [committeeDialogOpen, setCommitteeDialogOpen] = useState(false);

    /* Edit dialogs */
    const [editingConsultation, setEditingConsultation] = useState<Consultation | null>(null);
    const [editingMeeting, setEditingMeeting] = useState<Meeting | null>(null);
    const [editMeetingLocationMode, setEditMeetingLocationMode] = useState<'site' | 'custom'>('site');

    const tabsRef = useRef<HTMLDivElement>(null);

    /* ---- Forms ---- */

    const repForm = useForm({
        user_id: '',
        site_id: '',
        work_group: '',
        election_method: 'elected',
        elected_date: '',
        training_days: 0,
    });

    const meetingForm = useForm<{
        committee_id: string;
        meeting_date: string;
        location: string;
        agenda_items: Array<{ title: string; notes: string }>;
        attendees: number[];
    }>({
        committee_id: '',
        meeting_date: '',
        location: '',
        agenda_items: [{ title: '', notes: '' }],
        attendees: [],
    });

    const consultationForm = useForm({
        title: '',
        consultation_type: 'hazard_identified',
        consultation_date: '',
        site_id: '',
        description: '',
    });

    /* Consultation feedback form */
    const feedbackForm = useForm({
        status: 'feedback_received',
        worker_feedback_summary: '',
        workers_consulted: [] as number[],
    });

    /* Consultation outcome form */
    const outcomeForm = useForm({
        status: 'actioned',
        outcome: '',
        changes_made: '',
        document: null as File | null,
    });

    /* Consultation document upload form */
    const consultDocForm = useForm({
        document: null as File | null,
        type: 'document' as string,
    });

    /* Meeting complete form */
    const completeMeetingForm = useForm<{
        confirmed_attendees: number[];
        minutes: string;
        action_items: Array<{ description: string; assigned_to: string; due_date: string; status: string }>;
    }>({
        confirmed_attendees: [],
        minutes: '',
        action_items: [],
    });

    /* Meeting members form */
    const membersForm = useForm<{ user_ids: string[] }>({
        user_ids: [],
    });

    /* Committee creation form */
    const committeeForm = useForm({
        name: '',
        site_id: '',
    });

    /* Meeting minutes upload form */
    const minutesForm = useForm({
        document: null as File | null,
    });

    /* Edit consultation form */
    const editConsultationForm = useForm({
        title: '',
        consultation_type: '',
        description: '',
        site_id: '',
        consultation_date: '',
    });

    /* Edit meeting form */
    const editMeetingForm = useForm<{
        meeting_date: string;
        location: string;
        agenda_items: Array<{ title: string; notes: string }>;
    }>({
        meeting_date: '',
        location: '',
        agenda_items: [{ title: '', notes: '' }],
    });

    /* ---- Pre-fill edit forms when editing item changes ---- */

    useEffect(() => {
        if (editingConsultation) {
            editConsultationForm.setData({
                title: editingConsultation.title || '',
                consultation_type: editingConsultation.consultation_type || '',
                description: editingConsultation.description || '',
                site_id: String(editingConsultation.site?.id || ''),
                consultation_date: editingConsultation.consultation_date || '',
            });
        }
    }, [editingConsultation]);

    useEffect(() => {
        if (editingMeeting) {
            const loc = editingMeeting.location || '';
            const isSiteLocation = sites.some((s) => s.name === loc);
            setEditMeetingLocationMode(loc && !isSiteLocation ? 'custom' : 'site');
            editMeetingForm.setData({
                meeting_date: editingMeeting.meeting_date ? editingMeeting.meeting_date.slice(0, 16) : '',
                location: loc,
                agenda_items:
                    (editingMeeting as any).agenda_items?.length > 0
                        ? (editingMeeting as any).agenda_items.map((a: any) => ({ title: a.title || '', notes: a.notes || '' }))
                        : [{ title: '', notes: '' }],
            });
        }
    }, [editingMeeting]);

    /* ---- Agenda item helpers ---- */

    const addAgendaItem = () => {
        meetingForm.setData('agenda_items', [
            ...meetingForm.data.agenda_items,
            { title: '', notes: '' },
        ]);
    };

    const removeAgendaItem = (idx: number) => {
        meetingForm.setData(
            'agenda_items',
            meetingForm.data.agenda_items.filter((_, i) => i !== idx),
        );
    };

    const updateAgendaItem = (idx: number, field: 'title' | 'notes', value: string) => {
        const updated = [...meetingForm.data.agenda_items];
        updated[idx] = { ...updated[idx], [field]: value };
        meetingForm.setData('agenda_items', updated);
    };

    /* ---- Edit meeting agenda item helpers ---- */

    const addEditAgendaItem = () => {
        editMeetingForm.setData('agenda_items', [
            ...editMeetingForm.data.agenda_items,
            { title: '', notes: '' },
        ]);
    };

    const removeEditAgendaItem = (idx: number) => {
        editMeetingForm.setData(
            'agenda_items',
            editMeetingForm.data.agenda_items.filter((_, i) => i !== idx),
        );
    };

    const updateEditAgendaItem = (idx: number, field: 'title' | 'notes', value: string) => {
        const updated = [...editMeetingForm.data.agenda_items];
        updated[idx] = { ...updated[idx], [field]: value };
        editMeetingForm.setData('agenda_items', updated);
    };

    /* ---- Complete meeting action item helpers ---- */

    const addCompleteMeetingActionItem = () => {
        completeMeetingForm.setData('action_items', [
            ...completeMeetingForm.data.action_items,
            { description: '', assigned_to: '', due_date: '', status: 'open' },
        ]);
    };

    const removeCompleteMeetingActionItem = (idx: number) => {
        completeMeetingForm.setData(
            'action_items',
            completeMeetingForm.data.action_items.filter((_, i) => i !== idx),
        );
    };

    const updateCompleteMeetingActionItem = (
        idx: number,
        field: 'description' | 'assigned_to' | 'due_date' | 'status',
        value: string,
    ) => {
        const updated = [...completeMeetingForm.data.action_items];
        updated[idx] = { ...updated[idx], [field]: value };
        completeMeetingForm.setData('action_items', updated);
    };

    /* ---- Stat card click ---- */

    const scrollToTab = (tab: string) => {
        setActiveTab(tab);
        tabsRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    /* ---- Submit helpers ---- */

    const submitRep = () => {
        repForm.post('/health-safety/worker-participation/representatives', {
            preserveScroll: true,
            onSuccess: () => {
                setRepOpen(false);
                repForm.reset();
            },
        });
    };

    const submitMeeting = () => {
        if (committees.length === 0) return;
        const committeeId = meetingForm.data.committee_id || (committees[0]?.id ?? 0);
        const attendeeCount = meetingForm.data.attendees.length;
        meetingForm.post(`/health-safety/worker-participation/committees/${committeeId}/meetings`, {
            preserveScroll: true,
            onSuccess: () => {
                setMeetingOpen(false);
                setMeetingSuccessMessage(
                    `Meeting scheduled. Calendar events created for ${attendeeCount} attendee${attendeeCount !== 1 ? 's' : ''}.`,
                );
                meetingForm.reset();
                setMeetingLocationMode('site');
                setTimeout(() => setMeetingSuccessMessage(null), 5000);
            },
        });
    };

    const submitConsultation = () => {
        consultationForm.post('/health-safety/worker-participation/consultations', {
            preserveScroll: true,
            onSuccess: () => {
                setConsultationOpen(false);
                consultationForm.reset();
            },
        });
    };

    const submitFeedback = (consultationId: number) => {
        feedbackForm.post(`/health-safety/worker-participation/consultations/${consultationId}/status`, {
            preserveScroll: true,
            onSuccess: () => {
                setFeedbackDialogId(null);
                feedbackForm.reset();
            },
        });
    };

    const submitOutcome = (consultationId: number) => {
        outcomeForm.post(`/health-safety/worker-participation/consultations/${consultationId}/status`, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                setOutcomeDialogId(null);
                outcomeForm.reset();
            },
        });
    };

    const submitClose = (consultationId: number) => {
        router.post(
            `/health-safety/worker-participation/consultations/${consultationId}/status`,
            { status: 'closed' },
            {
                preserveScroll: true,
                onSuccess: () => setCloseDialogId(null),
            },
        );
    };

    const submitConsultDocUpload = (consultationId: number) => {
        consultDocForm.post(`/health-safety/worker-participation/consultations/${consultationId}/documents`, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                setConsultDocUploadId(null);
                consultDocForm.reset();
            },
        });
    };

    const submitCompleteMeeting = (meetingId: number) => {
        completeMeetingForm.post(`/health-safety/worker-participation/meetings/${meetingId}/complete`, {
            preserveScroll: true,
            onSuccess: () => {
                setCompleteMeetingId(null);
                completeMeetingForm.reset();
            },
        });
    };

    const submitCancelMeeting = (meetingId: number) => {
        router.put(
            `/health-safety/worker-participation/meetings/${meetingId}/cancel`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => setCancelMeetingId(null),
            },
        );
    };

    const submitManageMembers = (meetingId: number) => {
        membersForm.post(`/health-safety/worker-participation/meetings/${meetingId}/attendees`, {
            preserveScroll: true,
            onSuccess: () => {
                setManageMembersId(null);
                membersForm.reset();
            },
        });
    };

    const submitMinutesUpload = (meetingId: number) => {
        minutesForm.post(`/health-safety/worker-participation/meetings/${meetingId}/minutes`, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                setMinutesUploadId(null);
                minutesForm.reset();
            },
        });
    };

    const submitCommittee = () => {
        committeeForm.post('/health-safety/worker-participation/committees', {
            preserveScroll: true,
            onSuccess: () => {
                setCommitteeDialogOpen(false);
                committeeForm.reset();
            },
        });
    };

    const submitEditConsultation = (consultationId: number) => {
        editConsultationForm.put(`/health-safety/worker-participation/consultations/${consultationId}`, {
            preserveScroll: true,
            onSuccess: () => {
                setEditingConsultation(null);
                editConsultationForm.reset();
            },
        });
    };

    const submitEditMeeting = (meetingId: number) => {
        editMeetingForm.put(`/health-safety/worker-participation/meetings/${meetingId}`, {
            preserveScroll: true,
            onSuccess: () => {
                setEditingMeeting(null);
                editMeetingForm.reset();
            },
        });
    };

    /* ---- Training progress ---- */

    const trainingProgress = (days: number) => {
        const max = 5; // HSWA allows up to 5 paid days
        const pct = Math.min((days / max) * 100, 100);
        return { pct, max };
    };

    /* ---- Render ---- */

    const statCards = [
        {
            label: 'Active Representatives',
            value: stats.active_reps,
            icon: Users,
            bg: 'bg-blue-50',
            iconColor: 'text-blue-600',
            borderColor: stats.active_reps > 0 ? 'border-blue-200' : 'border-slate-200',
            tab: 'representatives',
        },
        {
            label: 'Active Committees',
            value: stats.active_committees,
            icon: Building2,
            bg: 'bg-green-50',
            iconColor: 'text-green-600',
            borderColor: stats.active_committees > 0 ? 'border-green-200' : 'border-slate-200',
            tab: 'meetings',
        },
        {
            label: 'Meetings This Month',
            value: stats.meetings_this_month,
            icon: CalendarDays,
            bg: 'bg-amber-50',
            iconColor: 'text-amber-600',
            borderColor: stats.meetings_this_month > 0 ? 'border-amber-200' : 'border-slate-200',
            tab: 'meetings',
        },
        {
            label: 'Open Consultations',
            value: stats.open_consultations,
            icon: MessageSquare,
            bg: 'bg-purple-50',
            iconColor: 'text-purple-600',
            borderColor: stats.open_consultations > 0 ? 'border-purple-200' : 'border-slate-200',
            tab: 'consultations',
        },
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                { title: 'Worker Participation', href: '/health-safety/worker-participation' },
            ]}
        >
            <Head title="Worker Participation" />

            <div className="flex flex-col gap-6 p-6">
                {/* Hero Header */}
                <FleetHero
                    title="Worker Participation"
                    description="Manage H&S representatives, committee meetings, and worker consultations under HSWA 2015"
                    icon={<Users className="h-7 w-7 text-white" />}
                    stats={statCards.map((s) => ({ label: s.label, value: s.value }))}
                />

                {/* ---- Stats Row ---- */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {statCards.map((s) => {
                        const Icon = s.icon;
                        return (
                            <button
                                key={s.label}
                                type="button"
                                className="text-left"
                                onClick={() => scrollToTab(s.tab)}
                            >
                                <Card className={`border transition-shadow hover:shadow-md cursor-pointer ${s.borderColor}`}>
                                    <CardContent className="flex items-center gap-3 pt-6">
                                        <div className={`rounded-lg p-2.5 ${s.bg}`}>
                                            <Icon className={`h-5 w-5 ${s.iconColor}`} />
                                        </div>
                                        <div>
                                            <div className="text-2xl font-bold">{s.value}</div>
                                            <div className="text-xs text-muted-foreground">{s.label}</div>
                                        </div>
                                    </CardContent>
                                </Card>
                            </button>
                        );
                    })}
                </div>

                {/* ---- Tabs ---- */}
                <div ref={tabsRef}>
                    <TabsRoot value={activeTab} onValueChange={setActiveTab}>
                        <TabsList>
                            <TabsTrigger value="representatives">
                                <ShieldCheck className="mr-1.5 h-4 w-4" />
                                H&S Representatives
                            </TabsTrigger>
                            <TabsTrigger value="meetings">
                                <CalendarDays className="mr-1.5 h-4 w-4" />
                                Committee Meetings
                            </TabsTrigger>
                            <TabsTrigger value="consultations">
                                <Megaphone className="mr-1.5 h-4 w-4" />
                                Consultations
                            </TabsTrigger>
                        </TabsList>

                        {/* ============================================================ */}
                        {/*  REPRESENTATIVES TAB                                         */}
                        {/* ============================================================ */}
                        <TabsContent value="representatives">
                            <Card>
                                <CardHeader>
                                    <div className="flex items-center justify-between">
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <ShieldCheck className="h-5 w-5 text-blue-600" />
                                            H&S Representatives
                                        </CardTitle>
                                        <Button size="sm" onClick={() => setRepOpen(true)}>
                                            <UserPlus className="mr-1.5 h-4 w-4" />
                                            Add Representative
                                        </Button>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    {representatives.length === 0 ? (
                                        <div className="flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-slate-200 py-12 text-center">
                                            <div className="flex h-14 w-14 items-center justify-center rounded-full bg-blue-50 mb-4">
                                                <ShieldCheck className="h-7 w-7 text-blue-500" />
                                            </div>
                                            <h3 className="text-sm font-semibold text-slate-800">
                                                No H&S representatives yet
                                            </h3>
                                            <p className="mt-1.5 max-w-sm text-sm text-muted-foreground">
                                                Health and safety representatives are elected or appointed workers
                                                who represent their colleagues on H&S matters. Under HSWA, workers
                                                can request to elect an H&S rep at any time.
                                            </p>
                                            <Button size="sm" className="mt-4" onClick={() => setRepOpen(true)}>
                                                <UserPlus className="mr-1.5 h-4 w-4" />
                                                Add Your First Representative
                                            </Button>
                                        </div>
                                    ) : (
                                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                            {representatives.map((rep) => {
                                                const { pct, max } = trainingProgress(rep.training_days);
                                                return (
                                                    <Card key={rep.id} className="border">
                                                        <CardContent className="pt-5 space-y-3">
                                                            <div className="flex items-start justify-between">
                                                                <div className="flex items-center gap-3">
                                                                    <div className="flex h-10 w-10 items-center justify-center rounded-full bg-blue-100 text-sm font-semibold text-blue-700">
                                                                        {(rep.user?.name ?? 'U').charAt(0).toUpperCase()}
                                                                    </div>
                                                                    <div>
                                                                        <div className="font-medium text-sm">
                                                                            {rep.user?.name ?? 'Unknown'}
                                                                        </div>
                                                                        <div className="text-xs text-muted-foreground">
                                                                            {rep.site?.name ?? 'No site assigned'}
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                {statusBadge(rep.status)}
                                                            </div>

                                                            <div className="grid grid-cols-2 gap-2 text-xs">
                                                                <div>
                                                                    <span className="text-muted-foreground">Work Group</span>
                                                                    <div className="font-medium">{rep.work_group ?? '-'}</div>
                                                                </div>
                                                                <div>
                                                                    <span className="text-muted-foreground">Method</span>
                                                                    <div className="font-medium capitalize">
                                                                        {rep.election_method ?? '-'}
                                                                    </div>
                                                                </div>
                                                                <div>
                                                                    <span className="text-muted-foreground">Elected</span>
                                                                    <div className="font-medium">
                                                                        {rep.elected_date ? formatDate(rep.elected_date) : '-'}
                                                                    </div>
                                                                </div>
                                                            </div>

                                                            {/* Training progress */}
                                                            <div>
                                                                <div className="flex items-center justify-between text-xs mb-1">
                                                                    <span className="flex items-center gap-1 text-muted-foreground">
                                                                        <GraduationCap className="h-3.5 w-3.5" />
                                                                        Training days
                                                                    </span>
                                                                    <span className="font-medium">
                                                                        {rep.training_days}/{max} days
                                                                    </span>
                                                                </div>
                                                                <div className="h-1.5 w-full rounded-full bg-slate-100">
                                                                    <div
                                                                        className={`h-1.5 rounded-full transition-all ${
                                                                            pct >= 100
                                                                                ? 'bg-green-500'
                                                                                : pct >= 60
                                                                                  ? 'bg-blue-500'
                                                                                  : 'bg-amber-500'
                                                                        }`}
                                                                        style={{ width: `${pct}%` }}
                                                                    />
                                                                </div>
                                                            </div>
                                                        </CardContent>
                                                    </Card>
                                                );
                                            })}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </TabsContent>

                        {/* ============================================================ */}
                        {/*  MEETINGS TAB                                                */}
                        {/* ============================================================ */}
                        <TabsContent value="meetings">
                            <Card>
                                <CardHeader>
                                    <div className="flex items-center justify-between">
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <CalendarDays className="h-5 w-5 text-amber-600" />
                                            Committee Meetings
                                        </CardTitle>
                                        <Button
                                            size="sm"
                                            onClick={() => {
                                                if (committees.length === 0) {
                                                    setCommitteeDialogOpen(true);
                                                } else {
                                                    setMeetingOpen(true);
                                                }
                                            }}
                                        >
                                            <Plus className="mr-1.5 h-4 w-4" />
                                            Schedule Meeting
                                        </Button>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    {/* Success notification */}
                                    {meetingSuccessMessage && (
                                        <div className="mb-4 flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800">
                                            <CheckCircle2 className="h-4 w-4 shrink-0" />
                                            {meetingSuccessMessage}
                                            <button
                                                type="button"
                                                className="ml-auto text-green-600 hover:text-green-800"
                                                onClick={() => setMeetingSuccessMessage(null)}
                                            >
                                                <XCircle className="h-4 w-4" />
                                            </button>
                                        </div>
                                    )}

                                    {/* No committee warning */}
                                    {committees.length === 0 && (
                                        <div className="mb-4 flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-amber-200 bg-amber-50 py-8 text-center">
                                            <div className="flex h-14 w-14 items-center justify-center rounded-full bg-amber-100 mb-4">
                                                <Building2 className="h-7 w-7 text-amber-600" />
                                            </div>
                                            <h3 className="text-sm font-semibold text-slate-800">
                                                You need to create an H&S Committee before scheduling meetings
                                            </h3>
                                            <p className="mt-1.5 max-w-sm text-sm text-muted-foreground">
                                                An H&S committee is required under the HSWA to facilitate meetings
                                                between workers and management on health and safety matters.
                                            </p>
                                            <Button size="sm" className="mt-4" onClick={() => setCommitteeDialogOpen(true)}>
                                                <Plus className="mr-1.5 h-4 w-4" />
                                                Create Committee
                                            </Button>
                                        </div>
                                    )}

                                    {committees.length > 0 && meetings.length === 0 ? (
                                        <div className="flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-slate-200 py-12 text-center">
                                            <div className="flex h-14 w-14 items-center justify-center rounded-full bg-amber-50 mb-4">
                                                <CalendarDays className="h-7 w-7 text-amber-500" />
                                            </div>
                                            <h3 className="text-sm font-semibold text-slate-800">
                                                No meetings scheduled
                                            </h3>
                                            <p className="mt-1.5 max-w-sm text-sm text-muted-foreground">
                                                H&S committee meetings are where representatives and management
                                                discuss workplace health and safety issues, review incidents, and
                                                plan improvements.
                                            </p>
                                            <Button
                                                size="sm"
                                                className="mt-4"
                                                onClick={() => setMeetingOpen(true)}
                                            >
                                                <Plus className="mr-1.5 h-4 w-4" />
                                                Schedule Your First Meeting
                                            </Button>
                                        </div>
                                    ) : (
                                        <div className="space-y-4">
                                            {meetings.map((meeting) => (
                                                <Card
                                                    key={meeting.id}
                                                    className={`border border-l-4 ${meetingStatusBorder(meeting.status)}`}
                                                >
                                                    <CardContent className="pt-5 space-y-4">
                                                        {/* Header row */}
                                                        <div className="flex items-start justify-between">
                                                            <div>
                                                                <div className="font-medium text-sm">
                                                                    {meeting.committee_name}
                                                                </div>
                                                                <div className="flex items-center gap-3 mt-1 text-xs text-muted-foreground">
                                                                    <span className="flex items-center gap-1">
                                                                        <Clock className="h-3.5 w-3.5" />
                                                                        {formatDateTime(meeting.meeting_date)}
                                                                    </span>
                                                                    {meeting.location && (
                                                                        <span className="flex items-center gap-1">
                                                                            <MapPin className="h-3.5 w-3.5" />
                                                                            {meeting.location}
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            </div>
                                                            <div className="flex items-center gap-2">
                                                                {meeting.status === 'scheduled' && (
                                                                    <Button
                                                                        size="sm"
                                                                        variant="ghost"
                                                                        className="h-7 w-7 p-0 text-muted-foreground hover:text-foreground"
                                                                        onClick={() => setEditingMeeting(meeting)}
                                                                    >
                                                                        <Pencil className="h-3.5 w-3.5" />
                                                                    </Button>
                                                                )}
                                                                {statusBadge(meeting.status)}
                                                            </div>
                                                        </div>

                                                        {/* Attendees section */}
                                                        {meeting.confirmed_attendees && meeting.confirmed_attendees.length > 0 && (
                                                            <div className="space-y-2">
                                                                <div className="flex items-center justify-between">
                                                                    <h4 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                                                        Attendees
                                                                    </h4>
                                                                </div>
                                                                <div className="flex flex-wrap gap-2">
                                                                    {meeting.confirmed_attendees.map((att) => (
                                                                        <div
                                                                            key={att.id}
                                                                            className="flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
                                                                        >
                                                                            {att.confirmed ? (
                                                                                <CheckCircle2 className="h-3 w-3 text-green-500" />
                                                                            ) : (
                                                                                <Clock className="h-3 w-3 text-amber-500" />
                                                                            )}
                                                                            {att.name}
                                                                        </div>
                                                                    ))}
                                                                </div>
                                                            </div>
                                                        )}

                                                        {/* Minutes document */}
                                                        {meeting.minutes_document_name && (
                                                            <div className="flex items-center gap-2 rounded-lg border bg-slate-50 p-2.5 text-xs">
                                                                <FileText className="h-4 w-4 text-blue-500 shrink-0" />
                                                                <span className="font-medium truncate flex-1">
                                                                    {meeting.minutes_document_name}
                                                                </span>
                                                                <a
                                                                    href={`/health-safety/worker-participation/meetings/${meeting.id}/minutes/download`}
                                                                    className="flex items-center gap-1 text-blue-600 hover:text-blue-800 font-medium shrink-0"
                                                                >
                                                                    <Download className="h-3.5 w-3.5" />
                                                                    Download
                                                                </a>
                                                            </div>
                                                        )}

                                                        {/* Action items */}
                                                        {meeting.action_items && meeting.action_items.length > 0 && (
                                                            <div className="space-y-2">
                                                                <h4 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                                                    Action Items
                                                                </h4>
                                                                <div className="space-y-1.5">
                                                                    {meeting.action_items.map((item, idx) => (
                                                                        <div
                                                                            key={item.id ?? idx}
                                                                            className="flex items-start gap-2 rounded-lg border bg-white p-2.5 text-xs"
                                                                        >
                                                                            <CheckCircle2 className={`h-3.5 w-3.5 mt-0.5 shrink-0 ${
                                                                                item.status === 'completed' ? 'text-green-500' : 'text-slate-300'
                                                                            }`} />
                                                                            <div className="flex-1 min-w-0">
                                                                                <div className="font-medium">{item.description}</div>
                                                                                <div className="flex items-center gap-3 mt-1 text-muted-foreground">
                                                                                    {item.assignee_name && (
                                                                                        <span className="flex items-center gap-1">
                                                                                            <Users className="h-3 w-3" />
                                                                                            {item.assignee_name}
                                                                                        </span>
                                                                                    )}
                                                                                    {item.due_date && (
                                                                                        <span className="flex items-center gap-1">
                                                                                            <Calendar className="h-3 w-3" />
                                                                                            {formatDate(item.due_date)}
                                                                                        </span>
                                                                                    )}
                                                                                </div>
                                                                            </div>
                                                                            {statusBadge(item.status)}
                                                                        </div>
                                                                    ))}
                                                                </div>
                                                            </div>
                                                        )}

                                                        {/* Footer with counts + actions */}
                                                        <div className="flex items-center justify-between border-t pt-3">
                                                            <div className="flex items-center gap-4 text-xs text-muted-foreground">
                                                                {typeof meeting.attendees_count === 'number' && (
                                                                    <span className="flex items-center gap-1">
                                                                        <Users className="h-3.5 w-3.5" />
                                                                        {meeting.attendees_count} attendee{meeting.attendees_count !== 1 ? 's' : ''}
                                                                    </span>
                                                                )}
                                                                <span className="flex items-center gap-1">
                                                                    <CheckCircle2 className="h-3.5 w-3.5" />
                                                                    {meeting.action_items_count} action item{meeting.action_items_count !== 1 ? 's' : ''}
                                                                </span>
                                                            </div>
                                                            <div className="flex items-center gap-2">
                                                                {meeting.status === 'scheduled' && (
                                                                    <>
                                                                        <Button
                                                                            size="sm"
                                                                            variant="outline"
                                                                            onClick={() => setManageMembersId(meeting.id)}
                                                                        >
                                                                            <Users className="mr-1.5 h-3.5 w-3.5" />
                                                                            Manage Members
                                                                        </Button>
                                                                        <Button
                                                                            size="sm"
                                                                            variant="outline"
                                                                            onClick={() => setMinutesUploadId(meeting.id)}
                                                                        >
                                                                            <Upload className="mr-1.5 h-3.5 w-3.5" />
                                                                            Upload Minutes
                                                                        </Button>
                                                                        <Button
                                                                            size="sm"
                                                                            variant="outline"
                                                                            className="text-red-600 hover:text-red-700 hover:bg-red-50"
                                                                            onClick={() => setCancelMeetingId(meeting.id)}
                                                                        >
                                                                            <XCircle className="mr-1.5 h-3.5 w-3.5" />
                                                                            Cancel
                                                                        </Button>
                                                                        <Button
                                                                            size="sm"
                                                                            onClick={() => {
                                                                                completeMeetingForm.reset();
                                                                                const attendeeIds = (meeting.confirmed_attendees ?? []).map(a => a.user_id);
                                                                                completeMeetingForm.setData('confirmed_attendees', attendeeIds);
                                                                                setCompleteMeetingId(meeting.id);
                                                                            }}
                                                                        >
                                                                            <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" />
                                                                            Complete Meeting
                                                                        </Button>
                                                                    </>
                                                                )}
                                                                {meeting.status === 'completed' && !meeting.minutes_document_name && (
                                                                    <Button
                                                                        size="sm"
                                                                        variant="outline"
                                                                        onClick={() => setMinutesUploadId(meeting.id)}
                                                                    >
                                                                        <Upload className="mr-1.5 h-3.5 w-3.5" />
                                                                        Upload Minutes
                                                                    </Button>
                                                                )}
                                                            </div>
                                                        </div>
                                                    </CardContent>
                                                </Card>
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </TabsContent>

                        {/* ============================================================ */}
                        {/*  CONSULTATIONS TAB                                           */}
                        {/* ============================================================ */}
                        <TabsContent value="consultations">
                            <Card>
                                <CardHeader>
                                    <div className="flex items-center justify-between">
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <Megaphone className="h-5 w-5 text-purple-600" />
                                            Worker Consultations
                                        </CardTitle>
                                        <Button size="sm" onClick={() => setConsultationOpen(true)}>
                                            <Plus className="mr-1.5 h-4 w-4" />
                                            New Consultation
                                        </Button>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    {consultations.length === 0 ? (
                                        <div className="flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-slate-200 py-12 text-center">
                                            <div className="flex h-14 w-14 items-center justify-center rounded-full bg-purple-50 mb-4">
                                                <Megaphone className="h-7 w-7 text-purple-500" />
                                            </div>
                                            <h3 className="text-sm font-semibold text-slate-800">
                                                No consultations recorded
                                            </h3>
                                            <p className="mt-1.5 max-w-sm text-sm text-muted-foreground">
                                                Record consultations with workers about health and safety matters
                                                such as hazard identification, risk assessments, and changes to
                                                procedures or equipment.
                                            </p>
                                            <Button
                                                size="sm"
                                                className="mt-4"
                                                onClick={() => setConsultationOpen(true)}
                                            >
                                                <Plus className="mr-1.5 h-4 w-4" />
                                                Record First Consultation
                                            </Button>
                                        </div>
                                    ) : (
                                        <div className="space-y-4">
                                            {consultations.map((c) => (
                                                <Card key={c.id} className="border">
                                                    <CardContent className="pt-5 space-y-4">
                                                        {/* Header row */}
                                                        <div className="flex items-start justify-between gap-2">
                                                            <div>
                                                                <div className="font-medium text-sm leading-snug">
                                                                    {c.title}
                                                                </div>
                                                                <div className="flex items-center gap-2 mt-1.5">
                                                                    <Badge
                                                                        className={
                                                                            consultationTypeColor[c.consultation_type] ??
                                                                            'bg-slate-100 text-slate-700 border-slate-200'
                                                                        }
                                                                    >
                                                                        {consultationTypeLabel[c.consultation_type] ??
                                                                            c.consultation_type}
                                                                    </Badge>
                                                                    <span className="text-xs text-muted-foreground flex items-center gap-1">
                                                                        <Clock className="h-3 w-3" />
                                                                        {formatDate(c.consultation_date)}
                                                                    </span>
                                                                    {c.site && (
                                                                        <span className="text-xs text-muted-foreground flex items-center gap-1">
                                                                            <MapPin className="h-3 w-3" />
                                                                            {c.site.name}
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            </div>
                                                            <div className="flex items-center gap-2">
                                                                <Button
                                                                    size="sm"
                                                                    variant="ghost"
                                                                    className="h-7 w-7 p-0 text-muted-foreground hover:text-foreground"
                                                                    onClick={() => setEditingConsultation(c)}
                                                                >
                                                                    <Pencil className="h-3.5 w-3.5" />
                                                                </Button>
                                                                {statusBadge(c.status)}
                                                            </div>
                                                        </div>

                                                        {/* Workflow progress bar */}
                                                        <ConsultationProgressBar status={c.status} />

                                                        {/* Description */}
                                                        {c.description && (
                                                            <div className="text-xs text-muted-foreground bg-slate-50 rounded-lg p-3">
                                                                <span className="font-semibold text-slate-700">Description: </span>
                                                                {c.description}
                                                            </div>
                                                        )}

                                                        {/* Worker feedback summary */}
                                                        {c.worker_feedback_summary && (
                                                            <div className="text-xs bg-indigo-50 rounded-lg p-3">
                                                                <span className="font-semibold text-indigo-700">Worker Feedback: </span>
                                                                <span className="text-indigo-900">{c.worker_feedback_summary}</span>
                                                            </div>
                                                        )}

                                                        {/* Outcome */}
                                                        {c.outcome && (
                                                            <div className="text-xs bg-teal-50 rounded-lg p-3">
                                                                <span className="font-semibold text-teal-700">Outcome: </span>
                                                                <span className="text-teal-900">{c.outcome}</span>
                                                            </div>
                                                        )}

                                                        {/* Changes made */}
                                                        {c.changes_made && (
                                                            <div className="text-xs bg-green-50 rounded-lg p-3">
                                                                <span className="font-semibold text-green-700">Changes Made: </span>
                                                                <span className="text-green-900">{c.changes_made}</span>
                                                            </div>
                                                        )}

                                                        {/* Documents section */}
                                                        <div className="space-y-2">
                                                            <h4 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground flex items-center gap-1">
                                                                <Paperclip className="h-3.5 w-3.5" />
                                                                Documents
                                                            </h4>
                                                            {!c.document_name && !c.outcome_document_name ? (
                                                                <div className="rounded-lg border-2 border-dashed border-slate-200 p-3 text-center text-xs text-muted-foreground">
                                                                    No documents yet
                                                                </div>
                                                            ) : (
                                                                <div className="space-y-2">
                                                                    {c.document_name && (
                                                                        <div className="flex items-center gap-3 rounded-lg border bg-slate-50 p-3">
                                                                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-100 shrink-0">
                                                                                <FileText className="h-4.5 w-4.5 text-blue-600" />
                                                                            </div>
                                                                            <div className="flex-1 min-w-0">
                                                                                <div className="text-xs font-medium truncate">{c.document_name}</div>
                                                                                <div className="text-[10px] text-muted-foreground">Supporting Document</div>
                                                                            </div>
                                                                            <a
                                                                                href={`/health-safety/worker-participation/consultations/${c.id}/documents/document`}
                                                                                className="flex items-center gap-1.5 rounded-md border bg-white px-3 py-1.5 text-xs font-medium text-blue-600 hover:text-blue-800 hover:bg-blue-50 transition-colors shrink-0"
                                                                            >
                                                                                <Download className="h-3.5 w-3.5" />
                                                                                Download
                                                                            </a>
                                                                        </div>
                                                                    )}
                                                                    {c.outcome_document_name && (
                                                                        <div className="flex items-center gap-3 rounded-lg border bg-teal-50 p-3">
                                                                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-teal-100 shrink-0">
                                                                                <FileText className="h-4.5 w-4.5 text-teal-600" />
                                                                            </div>
                                                                            <div className="flex-1 min-w-0">
                                                                                <div className="text-xs font-medium truncate">{c.outcome_document_name}</div>
                                                                                <div className="text-[10px] text-muted-foreground">Outcome Document</div>
                                                                            </div>
                                                                            <a
                                                                                href={`/health-safety/worker-participation/consultations/${c.id}/documents/outcome`}
                                                                                className="flex items-center gap-1.5 rounded-md border bg-white px-3 py-1.5 text-xs font-medium text-teal-600 hover:text-teal-800 hover:bg-teal-50 transition-colors shrink-0"
                                                                            >
                                                                                <Download className="h-3.5 w-3.5" />
                                                                                Download
                                                                            </a>
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            )}
                                                        </div>

                                                        {/* Footer: workers consulted + action buttons */}
                                                        <div className="flex items-center justify-between border-t pt-3">
                                                            <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                                                <Users className="h-3.5 w-3.5" />
                                                                {c.workers_consulted} worker{c.workers_consulted !== 1 ? 's' : ''} consulted
                                                            </div>
                                                            <div className="flex items-center gap-2">
                                                                {/* Upload document button */}
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    onClick={() => {
                                                                        consultDocForm.reset();
                                                                        consultDocForm.setData('type', 'document');
                                                                        setConsultDocUploadType('document');
                                                                        setConsultDocUploadId(c.id);
                                                                    }}
                                                                >
                                                                    <Upload className="mr-1.5 h-3.5 w-3.5" />
                                                                    Upload Document
                                                                </Button>

                                                                {/* Workflow action buttons */}
                                                                {c.status === 'open' && (
                                                                    <Button
                                                                        size="sm"
                                                                        onClick={() => {
                                                                            feedbackForm.reset();
                                                                            setFeedbackDialogId(c.id);
                                                                        }}
                                                                    >
                                                                        <MessageSquare className="mr-1.5 h-3.5 w-3.5" />
                                                                        Record Feedback
                                                                    </Button>
                                                                )}
                                                                {c.status === 'feedback_received' && (
                                                                    <Button
                                                                        size="sm"
                                                                        onClick={() => {
                                                                            outcomeForm.reset();
                                                                            setOutcomeDialogId(c.id);
                                                                        }}
                                                                    >
                                                                        <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" />
                                                                        Record Outcome
                                                                    </Button>
                                                                )}
                                                                {c.status === 'actioned' && (
                                                                    <Button
                                                                        size="sm"
                                                                        onClick={() => setCloseDialogId(c.id)}
                                                                    >
                                                                        <XCircle className="mr-1.5 h-3.5 w-3.5" />
                                                                        Close Consultation
                                                                    </Button>
                                                                )}
                                                            </div>
                                                        </div>
                                                    </CardContent>
                                                </Card>
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </TabsContent>
                    </TabsRoot>
                </div>
            </div>

            {/* ================================================================ */}
            {/*  ADD REPRESENTATIVE DIALOG                                       */}
            {/* ================================================================ */}
            <Dialog open={repOpen} onOpenChange={setRepOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-blue-100">
                                <UserPlus className="h-4 w-4 text-blue-600" />
                            </div>
                            Add H&S Representative
                        </DialogTitle>
                    </DialogHeader>

                    <div className="space-y-5">
                        {/* Section: Person */}
                        <div className="space-y-3">
                            <h4 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Person Details
                            </h4>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label>Staff Member <span className="text-red-500">*</span></Label>
                                    <Select
                                        value={repForm.data.user_id || '__none__'}
                                        onValueChange={(v) =>
                                            repForm.setData('user_id', v === '__none__' ? '' : v)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select staff member" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">Select...</SelectItem>
                                            {staff.map((s) => (
                                                <SelectItem key={s.id} value={String(s.id)}>
                                                    {s.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {repForm.errors.user_id && (
                                        <p className="text-xs text-red-600">{repForm.errors.user_id}</p>
                                    )}
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Site <span className="text-red-500">*</span></Label>
                                    <Select
                                        value={repForm.data.site_id || '__none__'}
                                        onValueChange={(v) =>
                                            repForm.setData('site_id', v === '__none__' ? '' : v)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select site" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">Select...</SelectItem>
                                            {sites.map((s) => (
                                                <SelectItem key={s.id} value={String(s.id)}>
                                                    {s.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {repForm.errors.site_id && (
                                        <p className="text-xs text-red-600">{repForm.errors.site_id}</p>
                                    )}
                                </div>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Work Group</Label>
                                <Input
                                    placeholder="e.g. Kitchen, Nursing, Maintenance"
                                    value={repForm.data.work_group}
                                    onChange={(e) => repForm.setData('work_group', e.target.value)}
                                />
                            </div>
                        </div>

                        {/* Section: Election */}
                        <div className="space-y-3">
                            <h4 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Election Information
                            </h4>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label>Election Method</Label>
                                    <Select
                                        value={repForm.data.election_method}
                                        onValueChange={(v) => repForm.setData('election_method', v)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="elected">Elected</SelectItem>
                                            <SelectItem value="appointed">Appointed</SelectItem>
                                            <SelectItem value="volunteered">Volunteered</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Date Elected / Appointed</Label>
                                    <Input
                                        type="date"
                                        value={repForm.data.elected_date}
                                        onChange={(e) => repForm.setData('elected_date', e.target.value)}
                                    />
                                </div>
                            </div>
                        </div>

                        {/* Section: Training */}
                        <div className="space-y-3">
                            <h4 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Training
                            </h4>
                            <div className="space-y-1.5">
                                <Label>Training Days Completed</Label>
                                <Input
                                    type="number"
                                    min={0}
                                    max={10}
                                    value={repForm.data.training_days}
                                    onChange={(e) =>
                                        repForm.setData('training_days', parseInt(e.target.value) || 0)
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    Under HSWA, H&S reps are entitled to up to 5 days paid training per year.
                                </p>
                            </div>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setRepOpen(false)}>
                            Cancel
                        </Button>
                        <Button disabled={repForm.processing} onClick={submitRep}>
                            <UserPlus className="mr-1.5 h-4 w-4" />
                            Add Representative
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ================================================================ */}
            {/*  SCHEDULE MEETING DIALOG                                         */}
            {/* ================================================================ */}
            <Dialog
                open={meetingOpen}
                onOpenChange={(open) => {
                    setMeetingOpen(open);
                    if (!open) {
                        meetingForm.reset();
                        setMeetingLocationMode('site');
                    }
                }}
            >
                <DialogContent className="sm:max-w-lg max-h-[85vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-amber-100">
                                <CalendarDays className="h-4 w-4 text-amber-600" />
                            </div>
                            Schedule Committee Meeting
                        </DialogTitle>
                    </DialogHeader>

                    <div className="space-y-5">
                        {committees.length === 0 ? (
                            <div className="flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-amber-200 bg-amber-50 py-8 text-center">
                                <div className="flex h-14 w-14 items-center justify-center rounded-full bg-amber-100 mb-4">
                                    <Building2 className="h-7 w-7 text-amber-600" />
                                </div>
                                <h3 className="text-sm font-semibold text-slate-800">
                                    Create a committee first before scheduling a meeting
                                </h3>
                                <p className="mt-1.5 max-w-sm text-sm text-muted-foreground">
                                    An H&S committee is required to schedule meetings.
                                </p>
                                <Button
                                    size="sm"
                                    className="mt-4"
                                    onClick={() => {
                                        setMeetingOpen(false);
                                        setCommitteeDialogOpen(true);
                                    }}
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Create Committee
                                </Button>
                            </div>
                        ) : (
                        <>
                        {/* Section: Meeting Details */}
                        <div className="space-y-3">
                            <h4 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Meeting Details
                            </h4>

                            {/* Committee select */}
                            <div className="space-y-1.5">
                                <Label>Committee <span className="text-red-500">*</span></Label>
                                <Select
                                    value={meetingForm.data.committee_id || '__none__'}
                                    onValueChange={(v) =>
                                        meetingForm.setData('committee_id', v === '__none__' ? '' : v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select committee" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">Select committee...</SelectItem>
                                        {committees.map((c) => (
                                            <SelectItem key={c.id} value={String(c.id)}>
                                                {c.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {meetingForm.errors.committee_id && (
                                    <p className="text-xs text-red-600">{meetingForm.errors.committee_id}</p>
                                )}
                            </div>

                            {/* Date/time */}
                            <div className="space-y-1.5">
                                <Label>Date & Time <span className="text-red-500">*</span></Label>
                                <Input
                                    type="datetime-local"
                                    value={meetingForm.data.meeting_date}
                                    onChange={(e) => meetingForm.setData('meeting_date', e.target.value)}
                                />
                                {meetingForm.errors.meeting_date && (
                                    <p className="text-xs text-red-600">{meetingForm.errors.meeting_date}</p>
                                )}
                            </div>

                            {/* Location */}
                            <div className="space-y-1.5">
                                <Label>Location <span className="text-red-500">*</span></Label>
                                <Select
                                    value={
                                        meetingLocationMode === 'custom'
                                            ? '__custom__'
                                            : meetingForm.data.location || '__none__'
                                    }
                                    onValueChange={(v) => {
                                        if (v === '__custom__') {
                                            setMeetingLocationMode('custom');
                                            meetingForm.setData('location', '');
                                        } else if (v === '__none__') {
                                            setMeetingLocationMode('site');
                                            meetingForm.setData('location', '');
                                        } else {
                                            setMeetingLocationMode('site');
                                            meetingForm.setData('location', v);
                                        }
                                    }}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select location" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">Select location...</SelectItem>
                                        {sites.map((s) => (
                                            <SelectItem key={s.id} value={s.name}>
                                                {s.name}
                                            </SelectItem>
                                        ))}
                                        <SelectItem value="__custom__">Other / Custom Location</SelectItem>
                                    </SelectContent>
                                </Select>

                                {meetingLocationMode === 'custom' && (
                                    <Input
                                        className="mt-2"
                                        placeholder="Enter custom location"
                                        value={meetingForm.data.location}
                                        onChange={(e) => meetingForm.setData('location', e.target.value)}
                                    />
                                )}

                                {meetingForm.errors.location && (
                                    <p className="text-xs text-red-600">{meetingForm.errors.location}</p>
                                )}
                            </div>
                        </div>

                        {/* Section: Agenda Items */}
                        <div className="space-y-3">
                            <div className="flex items-center justify-between">
                                <h4 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    Agenda Items
                                </h4>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={addAgendaItem}
                                >
                                    <Plus className="mr-1 h-3.5 w-3.5" />
                                    Add Item
                                </Button>
                            </div>

                            {meetingForm.data.agenda_items.length === 0 && (
                                <div className="rounded-lg border-2 border-dashed border-slate-200 p-4 text-center text-sm text-muted-foreground">
                                    No agenda items yet. Click "Add Item" to start building your agenda.
                                </div>
                            )}

                            <div className="space-y-3">
                                {meetingForm.data.agenda_items.map((item, idx) => (
                                    <div
                                        key={idx}
                                        className="rounded-lg border bg-slate-50/50 p-3 space-y-2"
                                    >
                                        <div className="flex items-center justify-between">
                                            <span className="text-xs font-medium text-muted-foreground">
                                                Item {idx + 1}
                                            </span>
                                            {meetingForm.data.agenda_items.length > 1 && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-7 w-7 p-0 text-red-500 hover:text-red-700 hover:bg-red-50"
                                                    onClick={() => removeAgendaItem(idx)}
                                                >
                                                    <Trash2 className="h-3.5 w-3.5" />
                                                </Button>
                                            )}
                                        </div>
                                        <Input
                                            placeholder="Agenda item title"
                                            value={item.title}
                                            onChange={(e) =>
                                                updateAgendaItem(idx, 'title', e.target.value)
                                            }
                                        />
                                        <Textarea
                                            placeholder="Notes or talking points (optional)"
                                            rows={2}
                                            value={item.notes}
                                            onChange={(e) =>
                                                updateAgendaItem(idx, 'notes', e.target.value)
                                            }
                                        />
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* Section: Attendees */}
                        <div className="space-y-3">
                            <h4 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Attendees
                            </h4>
                            <div className="space-y-2 max-h-48 overflow-y-auto">
                                {staff.map((s) => {
                                    const isChecked = meetingForm.data.attendees.includes(s.id);
                                    return (
                                        <label
                                            key={s.id}
                                            className="flex items-center gap-2 rounded-lg border p-2.5 cursor-pointer hover:bg-slate-50"
                                        >
                                            <Checkbox
                                                checked={isChecked}
                                                onCheckedChange={(checked) => {
                                                    const current = meetingForm.data.attendees;
                                                    if (checked) {
                                                        meetingForm.setData('attendees', [...current, s.id]);
                                                    } else {
                                                        meetingForm.setData('attendees', current.filter((id) => id !== s.id));
                                                    }
                                                }}
                                            />
                                            <span className="text-sm">{s.name}</span>
                                        </label>
                                    );
                                })}
                            </div>
                            {meetingForm.data.attendees.length > 0 && (
                                <p className="text-xs text-muted-foreground">
                                    {meetingForm.data.attendees.length} attendee{meetingForm.data.attendees.length !== 1 ? 's' : ''} selected
                                </p>
                            )}
                        </div>
                        </>
                        )}
                    </div>

                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => {
                                setMeetingOpen(false);
                                meetingForm.reset();
                                setMeetingLocationMode('site');
                            }}
                        >
                            Cancel
                        </Button>
                        <Button
                            disabled={meetingForm.processing || committees.length === 0}
                            onClick={submitMeeting}
                        >
                            <CalendarDays className="mr-1.5 h-4 w-4" />
                            Schedule Meeting
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ================================================================ */}
            {/*  NEW CONSULTATION DIALOG                                         */}
            {/* ================================================================ */}
            <Dialog open={consultationOpen} onOpenChange={setConsultationOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-purple-100">
                                <Megaphone className="h-4 w-4 text-purple-600" />
                            </div>
                            New Worker Consultation
                        </DialogTitle>
                    </DialogHeader>

                    <div className="space-y-5">
                        {/* Title */}
                        <div className="space-y-1.5">
                            <Label>Title <span className="text-red-500">*</span></Label>
                            <Input
                                placeholder="Brief description of the consultation topic"
                                value={consultationForm.data.title}
                                onChange={(e) => consultationForm.setData('title', e.target.value)}
                            />
                            {consultationForm.errors.title && (
                                <p className="text-xs text-red-600">{consultationForm.errors.title}</p>
                            )}
                        </div>

                        {/* Type: card buttons */}
                        <div className="space-y-1.5">
                            <Label>Type <span className="text-red-500">*</span></Label>
                            <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                {consultationTypes.map((ct) => {
                                    const Icon = ct.icon;
                                    const isSelected =
                                        consultationForm.data.consultation_type === ct.value;
                                    return (
                                        <button
                                            key={ct.value}
                                            type="button"
                                            className={`flex flex-col items-center gap-1.5 rounded-lg border-2 p-3 text-xs font-medium transition-all ${
                                                isSelected
                                                    ? `${ct.color} border-current ring-1 ring-current/20`
                                                    : 'border-slate-200 text-slate-600 hover:border-slate-300 hover:bg-slate-50'
                                            }`}
                                            onClick={() =>
                                                consultationForm.setData('consultation_type', ct.value)
                                            }
                                        >
                                            <Icon className="h-5 w-5" />
                                            {ct.label}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>

                        {/* Date & Site */}
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label>Date <span className="text-red-500">*</span></Label>
                                <Input
                                    type="date"
                                    value={consultationForm.data.consultation_date}
                                    onChange={(e) =>
                                        consultationForm.setData('consultation_date', e.target.value)
                                    }
                                />
                                {consultationForm.errors.consultation_date && (
                                    <p className="text-xs text-red-600">
                                        {consultationForm.errors.consultation_date}
                                    </p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Site / Location</Label>
                                <Select
                                    value={consultationForm.data.site_id || '__none__'}
                                    onValueChange={(v) =>
                                        consultationForm.setData('site_id', v === '__none__' ? '' : v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select site" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">All sites</SelectItem>
                                        {sites.map((s) => (
                                            <SelectItem key={s.id} value={String(s.id)}>
                                                {s.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        {/* Description */}
                        <div className="space-y-1.5">
                            <Label>Description</Label>
                            <Textarea
                                placeholder="Provide details about what was discussed and any outcomes..."
                                rows={4}
                                value={consultationForm.data.description}
                                onChange={(e) =>
                                    consultationForm.setData('description', e.target.value)
                                }
                            />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConsultationOpen(false)}>
                            Cancel
                        </Button>
                        <Button disabled={consultationForm.processing} onClick={submitConsultation}>
                            <Megaphone className="mr-1.5 h-4 w-4" />
                            Create Consultation
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ================================================================ */}
            {/*  RECORD FEEDBACK DIALOG (Consultation: open -> feedback_received) */}
            {/* ================================================================ */}
            <Dialog open={feedbackDialogId !== null} onOpenChange={(open) => !open && setFeedbackDialogId(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-100">
                                <MessageSquare className="h-4 w-4 text-indigo-600" />
                            </div>
                            Record Worker Feedback
                        </DialogTitle>
                    </DialogHeader>

                    <div className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Worker Feedback Summary <span className="text-red-500">*</span></Label>
                            <Textarea
                                placeholder="Summarise the feedback received from workers..."
                                rows={4}
                                value={feedbackForm.data.worker_feedback_summary}
                                onChange={(e) => feedbackForm.setData('worker_feedback_summary', e.target.value)}
                            />
                            {feedbackForm.errors.worker_feedback_summary && (
                                <p className="text-xs text-red-600">{feedbackForm.errors.worker_feedback_summary}</p>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Workers Consulted</Label>
                            <div className="space-y-2 max-h-48 overflow-y-auto">
                                {staff.map((s) => {
                                    const isChecked = feedbackForm.data.workers_consulted.includes(s.id);
                                    return (
                                        <label
                                            key={s.id}
                                            className="flex items-center gap-2 rounded-lg border p-2.5 cursor-pointer hover:bg-slate-50"
                                        >
                                            <Checkbox
                                                checked={isChecked}
                                                onCheckedChange={(checked) => {
                                                    const current = feedbackForm.data.workers_consulted;
                                                    if (checked) {
                                                        feedbackForm.setData('workers_consulted', [...current, s.id]);
                                                    } else {
                                                        feedbackForm.setData('workers_consulted', current.filter((id) => id !== s.id));
                                                    }
                                                }}
                                            />
                                            <span className="text-sm">{s.name}</span>
                                        </label>
                                    );
                                })}
                            </div>
                            {feedbackForm.data.workers_consulted.length > 0 && (
                                <p className="text-xs text-muted-foreground">
                                    {feedbackForm.data.workers_consulted.length} worker{feedbackForm.data.workers_consulted.length !== 1 ? 's' : ''} selected
                                </p>
                            )}
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setFeedbackDialogId(null)}>
                            Cancel
                        </Button>
                        <Button
                            disabled={feedbackForm.processing}
                            onClick={() => feedbackDialogId && submitFeedback(feedbackDialogId)}
                        >
                            <CheckCircle2 className="mr-1.5 h-4 w-4" />
                            Record Feedback
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ================================================================ */}
            {/*  RECORD OUTCOME DIALOG (Consultation: feedback_received -> actioned) */}
            {/* ================================================================ */}
            <Dialog open={outcomeDialogId !== null} onOpenChange={(open) => !open && setOutcomeDialogId(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-teal-100">
                                <CheckCircle2 className="h-4 w-4 text-teal-600" />
                            </div>
                            Record Outcome
                        </DialogTitle>
                    </DialogHeader>

                    <div className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Outcome <span className="text-red-500">*</span></Label>
                            <Textarea
                                placeholder="Describe the outcome of this consultation..."
                                rows={4}
                                value={outcomeForm.data.outcome}
                                onChange={(e) => outcomeForm.setData('outcome', e.target.value)}
                            />
                            {outcomeForm.errors.outcome && (
                                <p className="text-xs text-red-600">{outcomeForm.errors.outcome}</p>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Changes Made</Label>
                            <Textarea
                                placeholder="Describe any changes implemented as a result..."
                                rows={3}
                                value={outcomeForm.data.changes_made}
                                onChange={(e) => outcomeForm.setData('changes_made', e.target.value)}
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label>Outcome Document (optional)</Label>
                            <Input
                                type="file"
                                onChange={(e) => outcomeForm.setData('document', e.target.files?.[0] || null)}
                            />
                            <p className="text-xs text-muted-foreground">
                                Upload a supporting document for the outcome (e.g. updated procedure, risk assessment).
                            </p>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setOutcomeDialogId(null)}>
                            Cancel
                        </Button>
                        <Button
                            disabled={outcomeForm.processing}
                            onClick={() => outcomeDialogId && submitOutcome(outcomeDialogId)}
                        >
                            <CheckCircle2 className="mr-1.5 h-4 w-4" />
                            Record Outcome
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ================================================================ */}
            {/*  CLOSE CONSULTATION DIALOG (Consultation: actioned -> closed)     */}
            {/* ================================================================ */}
            <Dialog open={closeDialogId !== null} onOpenChange={(open) => !open && setCloseDialogId(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100">
                                <XCircle className="h-4 w-4 text-slate-600" />
                            </div>
                            Close Consultation
                        </DialogTitle>
                    </DialogHeader>

                    <p className="text-sm text-muted-foreground">
                        Are you sure you want to close this consultation? This indicates that all actions
                        have been completed and the consultation is finalised.
                    </p>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCloseDialogId(null)}>
                            Cancel
                        </Button>
                        <Button onClick={() => closeDialogId && submitClose(closeDialogId)}>
                            <CheckCircle2 className="mr-1.5 h-4 w-4" />
                            Confirm Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ================================================================ */}
            {/*  CONSULTATION DOCUMENT UPLOAD DIALOG                             */}
            {/* ================================================================ */}
            <Dialog open={consultDocUploadId !== null} onOpenChange={(open) => !open && setConsultDocUploadId(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-blue-100">
                                <Upload className="h-4 w-4 text-blue-600" />
                            </div>
                            Upload Document
                        </DialogTitle>
                    </DialogHeader>

                    <div className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Document Type</Label>
                            <Select
                                value={consultDocForm.data.type}
                                onValueChange={(v) => {
                                    consultDocForm.setData('type', v);
                                    setConsultDocUploadType(v as 'document' | 'outcome');
                                }}
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="document">Supporting Document</SelectItem>
                                    <SelectItem value="outcome">Outcome Document</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1.5">
                            <Label>File <span className="text-red-500">*</span></Label>
                            <Input
                                type="file"
                                onChange={(e) => consultDocForm.setData('document', e.target.files?.[0] || null)}
                            />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConsultDocUploadId(null)}>
                            Cancel
                        </Button>
                        <Button
                            disabled={consultDocForm.processing || !consultDocForm.data.document}
                            onClick={() => consultDocUploadId && submitConsultDocUpload(consultDocUploadId)}
                        >
                            <Upload className="mr-1.5 h-4 w-4" />
                            Upload
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ================================================================ */}
            {/*  COMPLETE MEETING DIALOG                                         */}
            {/* ================================================================ */}
            <Dialog open={completeMeetingId !== null} onOpenChange={(open) => !open && setCompleteMeetingId(null)}>
                <DialogContent className="sm:max-w-2xl max-h-[85vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-green-100">
                                <CheckCircle2 className="h-4 w-4 text-green-600" />
                            </div>
                            Complete Meeting
                        </DialogTitle>
                    </DialogHeader>

                    <div className="space-y-5">
                        {/* Actual Attendees */}
                        {(() => {
                            const meeting = meetings.find((m) => m.id === completeMeetingId);
                            const attendees = meeting?.confirmed_attendees ?? [];
                            if (attendees.length === 0) return null;
                            return (
                                <div className="space-y-3">
                                    <h4 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                        Actual Attendees
                                    </h4>
                                    <div className="space-y-2">
                                        {attendees.map((att) => {
                                            const isChecked = completeMeetingForm.data.confirmed_attendees.includes(att.user_id);
                                            return (
                                                <label
                                                    key={att.id}
                                                    className="flex items-center gap-2 rounded-lg border p-2.5 cursor-pointer hover:bg-slate-50"
                                                >
                                                    <Checkbox
                                                        checked={isChecked}
                                                        onCheckedChange={(checked) => {
                                                            const current = completeMeetingForm.data.confirmed_attendees;
                                                            if (checked) {
                                                                completeMeetingForm.setData('confirmed_attendees', [...current, att.user_id]);
                                                            } else {
                                                                completeMeetingForm.setData('confirmed_attendees', current.filter((id) => id !== att.user_id));
                                                            }
                                                        }}
                                                    />
                                                    <span className="text-sm">{att.name}</span>
                                                </label>
                                            );
                                        })}
                                    </div>
                                </div>
                            );
                        })()}

                        {/* Minutes */}
                        <div className="space-y-1.5">
                            <Label>Minutes</Label>
                            <Textarea
                                placeholder="Record the meeting minutes..."
                                rows={5}
                                value={completeMeetingForm.data.minutes}
                                onChange={(e) => completeMeetingForm.setData('minutes', e.target.value)}
                            />
                        </div>

                        {/* Action Items */}
                        <div className="space-y-3">
                            <div className="flex items-center justify-between">
                                <h4 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    Action Items
                                </h4>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={addCompleteMeetingActionItem}
                                >
                                    <Plus className="mr-1 h-3.5 w-3.5" />
                                    Add Action Item
                                </Button>
                            </div>

                            {completeMeetingForm.data.action_items.length === 0 && (
                                <div className="rounded-lg border-2 border-dashed border-slate-200 p-4 text-center text-sm text-muted-foreground">
                                    No action items. Click "Add Action Item" to add follow-up tasks.
                                </div>
                            )}

                            <div className="space-y-3">
                                {completeMeetingForm.data.action_items.map((item, idx) => (
                                    <div
                                        key={idx}
                                        className="rounded-lg border bg-slate-50/50 p-3 space-y-2"
                                    >
                                        <div className="flex items-center justify-between">
                                            <span className="text-xs font-medium text-muted-foreground">
                                                Action Item {idx + 1}
                                            </span>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="h-7 w-7 p-0 text-red-500 hover:text-red-700 hover:bg-red-50"
                                                onClick={() => removeCompleteMeetingActionItem(idx)}
                                            >
                                                <Trash2 className="h-3.5 w-3.5" />
                                            </Button>
                                        </div>
                                        <Input
                                            placeholder="Description of the action item"
                                            value={item.description}
                                            onChange={(e) =>
                                                updateCompleteMeetingActionItem(idx, 'description', e.target.value)
                                            }
                                        />
                                        <div className="grid gap-2 sm:grid-cols-2">
                                            <Select
                                                value={item.assigned_to || '__none__'}
                                                onValueChange={(v) =>
                                                    updateCompleteMeetingActionItem(idx, 'assigned_to', v === '__none__' ? '' : v)
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Assign to..." />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="__none__">Assign to...</SelectItem>
                                                    {staff.map((s) => (
                                                        <SelectItem key={s.id} value={String(s.id)}>
                                                            {s.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <Input
                                                type="date"
                                                value={item.due_date}
                                                onChange={(e) =>
                                                    updateCompleteMeetingActionItem(idx, 'due_date', e.target.value)
                                                }
                                            />
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCompleteMeetingId(null)}>
                            Cancel
                        </Button>
                        <Button
                            disabled={completeMeetingForm.processing}
                            onClick={() => completeMeetingId && submitCompleteMeeting(completeMeetingId)}
                        >
                            <CheckCircle2 className="mr-1.5 h-4 w-4" />
                            Complete Meeting
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ================================================================ */}
            {/*  CANCEL MEETING DIALOG                                           */}
            {/* ================================================================ */}
            <Dialog open={cancelMeetingId !== null} onOpenChange={(open) => !open && setCancelMeetingId(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-red-100">
                                <XCircle className="h-4 w-4 text-red-600" />
                            </div>
                            Cancel Meeting
                        </DialogTitle>
                    </DialogHeader>

                    <p className="text-sm text-muted-foreground">
                        Are you sure you want to cancel this meeting? This action cannot be undone.
                    </p>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCancelMeetingId(null)}>
                            Keep Meeting
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => cancelMeetingId && submitCancelMeeting(cancelMeetingId)}
                        >
                            <XCircle className="mr-1.5 h-4 w-4" />
                            Cancel Meeting
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ================================================================ */}
            {/*  MANAGE MEMBERS DIALOG                                           */}
            {/* ================================================================ */}
            <Dialog open={manageMembersId !== null} onOpenChange={(open) => !open && setManageMembersId(null)}>
                <DialogContent className="sm:max-w-lg max-h-[85vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-blue-100">
                                <Users className="h-4 w-4 text-blue-600" />
                            </div>
                            Manage Meeting Attendees
                        </DialogTitle>
                    </DialogHeader>

                    <div className="space-y-3">
                        <p className="text-sm text-muted-foreground">
                            Select staff members to add as attendees for this meeting.
                        </p>
                        <div className="space-y-2 max-h-64 overflow-y-auto">
                            {staff.map((s) => {
                                const isChecked = membersForm.data.user_ids.includes(String(s.id));
                                return (
                                    <label
                                        key={s.id}
                                        className="flex items-center gap-2 rounded-lg border p-2.5 cursor-pointer hover:bg-slate-50"
                                    >
                                        <Checkbox
                                            checked={isChecked}
                                            onCheckedChange={(checked) => {
                                                const current = membersForm.data.user_ids;
                                                if (checked) {
                                                    membersForm.setData('user_ids', [...current, String(s.id)]);
                                                } else {
                                                    membersForm.setData('user_ids', current.filter((id) => id !== String(s.id)));
                                                }
                                            }}
                                        />
                                        <span className="text-sm">{s.name}</span>
                                    </label>
                                );
                            })}
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setManageMembersId(null)}>
                            Cancel
                        </Button>
                        <Button
                            disabled={membersForm.processing || membersForm.data.user_ids.length === 0}
                            onClick={() => manageMembersId && submitManageMembers(manageMembersId)}
                        >
                            <Users className="mr-1.5 h-4 w-4" />
                            Save Attendees
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ================================================================ */}
            {/*  UPLOAD MINUTES DIALOG                                           */}
            {/* ================================================================ */}
            <Dialog open={minutesUploadId !== null} onOpenChange={(open) => !open && setMinutesUploadId(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-amber-100">
                                <FileText className="h-4 w-4 text-amber-600" />
                            </div>
                            Upload Meeting Minutes
                        </DialogTitle>
                    </DialogHeader>

                    <div className="space-y-1.5">
                        <Label>Minutes Document <span className="text-red-500">*</span></Label>
                        <Input
                            type="file"
                            onChange={(e) => minutesForm.setData('document', e.target.files?.[0] || null)}
                        />
                        <p className="text-xs text-muted-foreground">
                            Upload the meeting minutes document (PDF, Word, etc.).
                        </p>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setMinutesUploadId(null)}>
                            Cancel
                        </Button>
                        <Button
                            disabled={minutesForm.processing || !minutesForm.data.document}
                            onClick={() => minutesUploadId && submitMinutesUpload(minutesUploadId)}
                        >
                            <Upload className="mr-1.5 h-4 w-4" />
                            Upload Minutes
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ================================================================ */}
            {/*  CREATE COMMITTEE DIALOG                                         */}
            {/* ================================================================ */}
            <Dialog open={committeeDialogOpen} onOpenChange={setCommitteeDialogOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-green-100">
                                <Building2 className="h-4 w-4 text-green-600" />
                            </div>
                            Create H&S Committee
                        </DialogTitle>
                    </DialogHeader>

                    <div className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Committee Name <span className="text-red-500">*</span></Label>
                            <Input
                                placeholder="e.g. Health & Safety Committee"
                                value={committeeForm.data.name}
                                onChange={(e) => committeeForm.setData('name', e.target.value)}
                            />
                            {committeeForm.errors.name && (
                                <p className="text-xs text-red-600">{committeeForm.errors.name}</p>
                            )}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Site</Label>
                            <Select
                                value={committeeForm.data.site_id || '__none__'}
                                onValueChange={(v) =>
                                    committeeForm.setData('site_id', v === '__none__' ? '' : v)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select site (optional)" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">All sites</SelectItem>
                                    {sites.map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCommitteeDialogOpen(false)}>
                            Cancel
                        </Button>
                        <Button disabled={committeeForm.processing} onClick={submitCommittee}>
                            <Building2 className="mr-1.5 h-4 w-4" />
                            Create Committee
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ================================================================ */}
            {/*  EDIT CONSULTATION DIALOG                                        */}
            {/* ================================================================ */}
            <Dialog open={editingConsultation !== null} onOpenChange={(open) => !open && setEditingConsultation(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-purple-100">
                                <Pencil className="h-4 w-4 text-purple-600" />
                            </div>
                            Edit Consultation
                        </DialogTitle>
                    </DialogHeader>

                    <div className="space-y-5">
                        {/* Title */}
                        <div className="space-y-1.5">
                            <Label>Title <span className="text-red-500">*</span></Label>
                            <Input
                                placeholder="Brief description of the consultation topic"
                                value={editConsultationForm.data.title}
                                onChange={(e) => editConsultationForm.setData('title', e.target.value)}
                            />
                            {editConsultationForm.errors.title && (
                                <p className="text-xs text-red-600">{editConsultationForm.errors.title}</p>
                            )}
                        </div>

                        {/* Type */}
                        <div className="space-y-1.5">
                            <Label>Consultation Type <span className="text-red-500">*</span></Label>
                            <Select
                                value={editConsultationForm.data.consultation_type || '__none__'}
                                onValueChange={(v) =>
                                    editConsultationForm.setData('consultation_type', v === '__none__' ? '' : v)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">Select type...</SelectItem>
                                    {consultationTypes.map((ct) => (
                                        <SelectItem key={ct.value} value={ct.value}>
                                            {ct.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {editConsultationForm.errors.consultation_type && (
                                <p className="text-xs text-red-600">{editConsultationForm.errors.consultation_type}</p>
                            )}
                        </div>

                        {/* Description */}
                        <div className="space-y-1.5">
                            <Label>Description</Label>
                            <Textarea
                                placeholder="Provide details about what was discussed and any outcomes..."
                                rows={4}
                                value={editConsultationForm.data.description}
                                onChange={(e) =>
                                    editConsultationForm.setData('description', e.target.value)
                                }
                            />
                        </div>

                        {/* Date & Site */}
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label>Site / Location</Label>
                                <Select
                                    value={editConsultationForm.data.site_id || '__none__'}
                                    onValueChange={(v) =>
                                        editConsultationForm.setData('site_id', v === '__none__' ? '' : v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select site" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">All sites</SelectItem>
                                        {sites.map((s) => (
                                            <SelectItem key={s.id} value={String(s.id)}>
                                                {s.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Consultation Date <span className="text-red-500">*</span></Label>
                                <Input
                                    type="date"
                                    value={editConsultationForm.data.consultation_date}
                                    onChange={(e) =>
                                        editConsultationForm.setData('consultation_date', e.target.value)
                                    }
                                />
                                {editConsultationForm.errors.consultation_date && (
                                    <p className="text-xs text-red-600">
                                        {editConsultationForm.errors.consultation_date}
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setEditingConsultation(null)}>
                            Cancel
                        </Button>
                        <Button
                            disabled={editConsultationForm.processing}
                            onClick={() => editingConsultation && submitEditConsultation(editingConsultation.id)}
                        >
                            <CheckCircle2 className="mr-1.5 h-4 w-4" />
                            Save Changes
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ================================================================ */}
            {/*  EDIT MEETING DIALOG                                             */}
            {/* ================================================================ */}
            <Dialog
                open={editingMeeting !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setEditingMeeting(null);
                        setEditMeetingLocationMode('site');
                    }
                }}
            >
                <DialogContent className="sm:max-w-lg max-h-[85vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-amber-100">
                                <Pencil className="h-4 w-4 text-amber-600" />
                            </div>
                            Edit Meeting
                        </DialogTitle>
                    </DialogHeader>

                    <div className="space-y-5">
                        {/* Date/Time */}
                        <div className="space-y-1.5">
                            <Label>Date & Time <span className="text-red-500">*</span></Label>
                            <Input
                                type="datetime-local"
                                value={editMeetingForm.data.meeting_date}
                                onChange={(e) => editMeetingForm.setData('meeting_date', e.target.value)}
                            />
                            {editMeetingForm.errors.meeting_date && (
                                <p className="text-xs text-red-600">{editMeetingForm.errors.meeting_date}</p>
                            )}
                        </div>

                        {/* Location */}
                        <div className="space-y-1.5">
                            <Label>Location <span className="text-red-500">*</span></Label>
                            <Select
                                value={
                                    editMeetingLocationMode === 'custom'
                                        ? '__custom__'
                                        : editMeetingForm.data.location || '__none__'
                                }
                                onValueChange={(v) => {
                                    if (v === '__custom__') {
                                        setEditMeetingLocationMode('custom');
                                        editMeetingForm.setData('location', '');
                                    } else if (v === '__none__') {
                                        setEditMeetingLocationMode('site');
                                        editMeetingForm.setData('location', '');
                                    } else {
                                        setEditMeetingLocationMode('site');
                                        editMeetingForm.setData('location', v);
                                    }
                                }}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select location" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">Select location...</SelectItem>
                                    {sites.map((s) => (
                                        <SelectItem key={s.id} value={s.name}>
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                    <SelectItem value="__custom__">Other / Custom Location</SelectItem>
                                </SelectContent>
                            </Select>

                            {editMeetingLocationMode === 'custom' && (
                                <Input
                                    className="mt-2"
                                    placeholder="Enter custom location"
                                    value={editMeetingForm.data.location}
                                    onChange={(e) => editMeetingForm.setData('location', e.target.value)}
                                />
                            )}

                            {editMeetingForm.errors.location && (
                                <p className="text-xs text-red-600">{editMeetingForm.errors.location}</p>
                            )}
                        </div>

                        {/* Agenda Items */}
                        <div className="space-y-3">
                            <div className="flex items-center justify-between">
                                <h4 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    Agenda Items
                                </h4>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={addEditAgendaItem}
                                >
                                    <Plus className="mr-1 h-3.5 w-3.5" />
                                    Add Item
                                </Button>
                            </div>

                            {editMeetingForm.data.agenda_items.length === 0 && (
                                <div className="rounded-lg border-2 border-dashed border-slate-200 p-4 text-center text-sm text-muted-foreground">
                                    No agenda items yet. Click "Add Item" to start building your agenda.
                                </div>
                            )}

                            <div className="space-y-3">
                                {editMeetingForm.data.agenda_items.map((item, idx) => (
                                    <div
                                        key={idx}
                                        className="rounded-lg border bg-slate-50/50 p-3 space-y-2"
                                    >
                                        <div className="flex items-center justify-between">
                                            <span className="text-xs font-medium text-muted-foreground">
                                                Item {idx + 1}
                                            </span>
                                            {editMeetingForm.data.agenda_items.length > 1 && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-7 w-7 p-0 text-red-500 hover:text-red-700 hover:bg-red-50"
                                                    onClick={() => removeEditAgendaItem(idx)}
                                                >
                                                    <Trash2 className="h-3.5 w-3.5" />
                                                </Button>
                                            )}
                                        </div>
                                        <Input
                                            placeholder="Agenda item title"
                                            value={item.title}
                                            onChange={(e) =>
                                                updateEditAgendaItem(idx, 'title', e.target.value)
                                            }
                                        />
                                        <Textarea
                                            placeholder="Notes or talking points (optional)"
                                            rows={2}
                                            value={item.notes}
                                            onChange={(e) =>
                                                updateEditAgendaItem(idx, 'notes', e.target.value)
                                            }
                                        />
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => {
                                setEditingMeeting(null);
                                setEditMeetingLocationMode('site');
                            }}
                        >
                            Cancel
                        </Button>
                        <Button
                            disabled={editMeetingForm.processing}
                            onClick={() => editingMeeting && submitEditMeeting(editingMeeting.id)}
                        >
                            <CheckCircle2 className="mr-1.5 h-4 w-4" />
                            Save Changes
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
