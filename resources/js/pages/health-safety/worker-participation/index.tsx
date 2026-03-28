import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { Head, useForm } from '@inertiajs/react';
import { useState, useRef } from 'react';
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
    Vote,
    Megaphone,
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

type Meeting = {
    id: number;
    committee_name: string;
    committee_id?: number;
    meeting_date: string;
    location: string | null;
    status: string;
    action_items_count: number;
    attendees_count?: number;
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
    }>({
        committee_id: '',
        meeting_date: '',
        location: '',
        agenda_items: [{ title: '', notes: '' }],
    });

    const consultationForm = useForm({
        title: '',
        consultation_type: 'hazard_identified',
        consultation_date: '',
        site_id: '',
        description: '',
    });

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
        const committeeId = meetingForm.data.committee_id || (committees[0]?.id ?? 0);
        meetingForm.post(`/health-safety/worker-participation/committees/${committeeId}/meetings`, {
            preserveScroll: true,
            onSuccess: () => {
                setMeetingOpen(false);
                meetingForm.reset();
                setMeetingLocationMode('site');
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

            <div className="space-y-6">
                {/* ---- Header ---- */}
                <div className="flex items-start gap-4">
                    <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-blue-100">
                        <Users className="h-6 w-6 text-blue-600" />
                    </div>
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">Worker Participation</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Under the Health and Safety at Work Act 2015, PCBUs must engage with workers and their
                            representatives on health and safety matters. Manage your H&S reps, committee meetings,
                            and worker consultations here.
                        </p>
                    </div>
                </div>

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
                                        <Button size="sm" onClick={() => setMeetingOpen(true)}>
                                            <Plus className="mr-1.5 h-4 w-4" />
                                            Schedule Meeting
                                        </Button>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    {meetings.length === 0 ? (
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
                                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                            {meetings.map((meeting) => (
                                                <Card
                                                    key={meeting.id}
                                                    className={`border border-l-4 ${meetingStatusBorder(meeting.status)}`}
                                                >
                                                    <CardContent className="pt-5 space-y-3">
                                                        <div className="flex items-start justify-between">
                                                            <div className="font-medium text-sm">
                                                                {meeting.committee_name}
                                                            </div>
                                                            {statusBadge(meeting.status)}
                                                        </div>

                                                        <div className="space-y-1.5 text-xs text-muted-foreground">
                                                            <div className="flex items-center gap-1.5">
                                                                <Clock className="h-3.5 w-3.5" />
                                                                {formatDateTime(meeting.meeting_date)}
                                                            </div>
                                                            {meeting.location && (
                                                                <div className="flex items-center gap-1.5">
                                                                    <MapPin className="h-3.5 w-3.5" />
                                                                    {meeting.location}
                                                                </div>
                                                            )}
                                                        </div>

                                                        <div className="flex items-center gap-4 border-t pt-3 text-xs">
                                                            {typeof meeting.attendees_count === 'number' && (
                                                                <div className="flex items-center gap-1 text-muted-foreground">
                                                                    <Users className="h-3.5 w-3.5" />
                                                                    {meeting.attendees_count} attendee
                                                                    {meeting.attendees_count !== 1 ? 's' : ''}
                                                                </div>
                                                            )}
                                                            <div className="flex items-center gap-1 text-muted-foreground">
                                                                <CheckCircle2 className="h-3.5 w-3.5" />
                                                                {meeting.action_items_count} action item
                                                                {meeting.action_items_count !== 1 ? 's' : ''}
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
                                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                            {consultations.map((c) => (
                                                <Card key={c.id} className="border">
                                                    <CardContent className="pt-5 space-y-3">
                                                        <div className="flex items-start justify-between gap-2">
                                                            <div className="font-medium text-sm leading-snug">
                                                                {c.title}
                                                            </div>
                                                            {statusBadge(c.status)}
                                                        </div>

                                                        <div className="flex flex-wrap gap-1.5">
                                                            <Badge
                                                                className={
                                                                    consultationTypeColor[c.consultation_type] ??
                                                                    'bg-slate-100 text-slate-700 border-slate-200'
                                                                }
                                                            >
                                                                {consultationTypeLabel[c.consultation_type] ??
                                                                    c.consultation_type}
                                                            </Badge>
                                                        </div>

                                                        <div className="space-y-1.5 text-xs text-muted-foreground">
                                                            <div className="flex items-center gap-1.5">
                                                                <Clock className="h-3.5 w-3.5" />
                                                                {formatDate(c.consultation_date)}
                                                            </div>
                                                            {c.site && (
                                                                <div className="flex items-center gap-1.5">
                                                                    <MapPin className="h-3.5 w-3.5" />
                                                                    {c.site.name}
                                                                </div>
                                                            )}
                                                        </div>

                                                        <div className="flex items-center gap-1.5 border-t pt-3 text-xs text-muted-foreground">
                                                            <Users className="h-3.5 w-3.5" />
                                                            {c.workers_consulted} worker
                                                            {c.workers_consulted !== 1 ? 's' : ''} consulted
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
                        {/* Section: Meeting Details */}
                        <div className="space-y-3">
                            <h4 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Meeting Details
                            </h4>

                            {/* Committee select */}
                            {committees.length > 0 && (
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
                            )}

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
                        <Button disabled={meetingForm.processing} onClick={submitMeeting}>
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
        </AppLayout>
    );
}
