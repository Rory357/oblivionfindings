import {
    OfferRespondDialog,
    OfferWizardDialog,
    type OfferRole,
    type OfferSite,
} from '@/components/hr';
import { PageHero, type PageHeroBadge, type PageHeroMetaItem } from '@/components/page';
import { ActivityItem } from '@/components/recruitment/activity-item';
import { PipelineStepper } from '@/components/recruitment/pipeline-stepper';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
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
import {
    TabsRoot as Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    Briefcase,
    Calendar,
    CheckCircle2,
    ChevronDown,
    ChevronUp,
    Clock,
    Download,
    ExternalLink,
    File,
    FileImage,
    FileText,
    FolderOpen,
    Gift,
    Mail,
    MessageSquare,
    Phone,
    Send,
    Shield,
    Star,
    StickyNote,
    Trash2,
    Upload,
    UserCheck,
} from 'lucide-react';
import { useRef, useState } from 'react';

interface Interview {
    id: number;
    type: string;
    scheduled_at: string;
    interviewer_name: string | null;
    status: 'scheduled' | 'completed' | 'cancelled' | 'no_show';
    outcome: string | null;
    notes: string | null;
    scores: Array<{
        id: number;
        criteria_scores: Array<{
            label: string;
            score: number;
            weight?: number | null;
        }>;
        overall_score: number | null;
        recommendation: string | null;
        notes: string | null;
        submitted_at: string | null;
        interviewer_name: string | null;
    }>;
}

interface ReferenceCheck {
    id: number;
    referee_name: string;
    referee_relationship: string;
    referee_phone: string | null;
    referee_email: string | null;
    status: 'pending' | 'requested' | 'contacted' | 'completed';
    outcome: string | null;
    checked_at: string | null;
    notes: string | null;
}

interface Offer {
    id: number;
    position_title: string;
    position_role: string | null;
    employment_type: string;
    proposed_start_date: string;
    hours_per_week: number | null;
    hourly_rate: number | null;
    annual_salary: number | null;
    approval_status: string;
    approved_at: string | null;
    approved_by: string | null;
    sent_at: string | null;
    portal_expires_at: string | null;
    response: 'accepted' | 'declined' | 'withdrawn' | null;
    response_at: string | null;
    response_notes: string | null;
    signed_full_name: string | null;
    signed_at: string | null;
    portal_url: string | null;
    offer_letter_name: string | null;
    offer_letter_id: number | null;
    offer_letter_generated?: boolean;
    primary_site: { id: number; name: string } | null;
}

interface JobPosting {
    id: number;
    title: string;
    slug: string;
    department: string | null;
    location: string | null;
}

interface Application {
    id: number;
    position_title: string;
    position_role: string | null;
    stage: string;
    status: 'active' | 'offered' | 'hired' | 'rejected' | 'withdrawn';
    interview_kit: {
        id: number;
        name: string;
        role: string | null;
        criteria: Array<{ label: string; weight?: number }>;
    } | null;
    applied_at: string;
    target_site: { id: number; name: string } | null;
    interviews: Interview[];
    reference_checks: ReferenceCheck[];
    offer: Offer | null;
    job_posting: JobPosting | null;
    cover_letter: string | null;
    screening_answers: Record<string, string> | null;
    cv_original_name: string | null;
}

interface ActivityEntry {
    type: 'status_change' | 'interview' | 'offer' | 'note' | 'application';
    description: string;
    timestamp: string;
    actor?: string;
}

interface CandidateDocument {
    id: number;
    original_name: string;
    category: string;
    category_label?: string | null;
    formatted_size: string;
    mime_type?: string | null;
    is_expired?: boolean;
    uploaded_by: string | null;
    created_at: string;
    expires_at: string | null;
    notes: string | null;
}

interface Candidate {
    id: number;
    first_name: string;
    last_name: string;
    preferred_name: string | null;
    personal_email: string;
    personal_phone: string | null;
    source: string;
    source_detail: string | null;
    notes: string | null;
    created_at: string;
    applications: Application[];
}

interface Props {
    candidate: Candidate;
    activityLog?: ActivityEntry[];
    totalDaysInPipeline?: number;
    can: { manage: boolean };
    documents: CandidateDocument[];
    documentCategories: Record<string, string>;
    stages?: string[];
    offerSites?: OfferSite[];
    offerRoles?: OfferRole[];
}

const interviewStatusVariants: Record<
    string,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    scheduled: 'outline',
    completed: 'default',
    cancelled: 'secondary',
    no_show: 'destructive',
};

const recColors: Record<string, string> = {
    strong_yes: 'text-status-success',
    yes: 'text-status-success',
    maybe: 'text-status-warning',
    neutral: 'text-status-warning',
    no: 'text-status-warning',
    strong_no: 'text-status-critical',
};

function formatNZDate(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('en-NZ', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function formatNZDateTime(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('en-NZ', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function isExpired(dateStr: string | null): boolean {
    if (!dateStr) return false;
    return new Date(dateStr) < new Date();
}

export default function CandidateShow({
    candidate,
    activityLog,
    totalDaysInPipeline,
    can,
    documents,
    documentCategories,
    stages,
    offerSites = [],
    offerRoles = [],
}: Props) {
    const fullName = `${candidate.first_name} ${candidate.last_name}`;
    const initials = (
        (candidate.first_name?.[0] ?? '') + (candidate.last_name?.[0] ?? '')
    ).toUpperCase();
    const [noteText, setNoteText] = useState('');
    const [offerWizard, setOfferWizard] = useState<{
        open: boolean;
        applicationId: number;
        positionTitle: string;
        positionRole: string | null;
    }>({ open: false, applicationId: 0, positionTitle: '', positionRole: null });
    const [respondOffer, setRespondOffer] = useState<{
        open: boolean;
        offerId: number;
    }>({ open: false, offerId: 0 });
    const [expandedCoverLetters, setExpandedCoverLetters] = useState<
        Record<number, boolean>
    >({});
    const [expandedScreening, setExpandedScreening] = useState<
        Record<number, boolean>
    >({});
    const [showUploadDialog, setShowUploadDialog] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr/people' },
        { title: 'Recruitment', href: '/hr/recruitment' },
        { title: fullName, href: `/hr/recruitment/candidates/${candidate.id}` },
    ];

    const currentStatus = candidate.applications[0]?.stage ?? 'new';

    // Document upload form
    const documentForm = useForm<{
        file: File | null;
        category: string;
        notes: string;
        expires_at: string;
    }>({
        file: null,
        category: '',
        notes: '',
        expires_at: '',
    });

    // Interview scheduling form
    const interviewForm = useForm({
        interview_type: 'phone',
        scheduled_at: '',
        duration_minutes: 45,
        location: '',
    });

    // Reference form
    const referenceForm = useForm({
        referee_name: '',
        referee_email: '',
        referee_relationship: 'professional',
    });

    function advanceStage(applicationId: number) {
        router.post(
            `/hr/recruitment/applications/${applicationId}/advance`,
            {},
            { preserveScroll: true },
        );
    }
    const [confirmState, setConfirmState] = useState<{
        title: string;
        description: string;
        confirmLabel: string;
        destructive?: boolean;
        action: () => void;
    } | null>(null);

    function rejectApplication(applicationId: number) {
        setConfirmState({
            title: 'Reject application?',
            description: 'This moves the candidate out of the active pipeline. You can re-activate them later from the talent pool.',
            confirmLabel: 'Reject',
            destructive: true,
            action: () =>
                router.post(
                    `/hr/recruitment/applications/${applicationId}/reject`,
                    {},
                    { preserveScroll: true },
                ),
        });
    }
    function updateInterviewStatus(
        interviewId: number,
        status: 'completed' | 'cancelled' | 'no_show',
    ) {
        router.put(
            `/hr/recruitment/interviews/${interviewId}`,
            { status },
            { preserveScroll: true },
        );
    }
    function updateReferenceStatus(
        referenceId: number,
        status: 'contacted' | 'completed',
    ) {
        router.put(
            `/hr/recruitment/references/${referenceId}`,
            { status },
            { preserveScroll: true },
        );
    }
    function approveOffer(offerId: number) {
        router.post(
            `/hr/recruitment/offers/${offerId}/approve`,
            {},
            { preserveScroll: true },
        );
    }
    function sendOffer(offerId: number) {
        router.post(
            `/hr/recruitment/offers/${offerId}/send`,
            {},
            { preserveScroll: true },
        );
    }
    function convertOffer(offerId: number) {
        router.post(
            `/hr/recruitment/offers/${offerId}/convert`,
            {},
            { preserveScroll: true },
        );
    }

    function handleDocumentUpload(e: React.FormEvent) {
        e.preventDefault();
        if (!documentForm.data.file || !documentForm.data.category) return;
        documentForm.post(
            `/hr/recruitment/candidates/${candidate.id}/documents`,
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => {
                    documentForm.reset();
                    if (fileInputRef.current) {
                        fileInputRef.current.value = '';
                    }
                },
            },
        );
    }

    function deleteDocument(docId: number) {
        setConfirmState({
            title: 'Delete document?',
            description: 'This permanently removes the document from the candidate record.',
            confirmLabel: 'Delete',
            destructive: true,
            action: () =>
                router.delete(`/hr/recruitment/documents/${docId}`, {
                    preserveScroll: true,
                }),
        });
    }

    function handleScheduleInterview(applicationId: number) {
        interviewForm.post(
            `/hr/recruitment/applications/${applicationId}/interviews`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    interviewForm.reset();
                },
            },
        );
    }

    function handleAddReference(applicationId: number) {
        referenceForm.post(
            `/hr/recruitment/applications/${applicationId}/references`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    referenceForm.reset();
                },
            },
        );
    }

    const formatCurrency = (amount: number | null) => {
        if (!amount) return '-';
        return new Intl.NumberFormat('en-NZ', {
            style: 'currency',
            currency: 'NZD',
        }).format(amount);
    };

    const { flash } = usePage().props as any;

    // Group documents by category
    const groupedDocuments = (documents ?? []).reduce<
        Record<string, CandidateDocument[]>
    >((acc, doc) => {
        const cat = doc.category || 'uncategorised';
        if (!acc[cat]) acc[cat] = [];
        acc[cat].push(doc);
        return acc;
    }, {});

    // Get the primary (first) application for workflow
    const primaryApp = candidate.applications[0] ?? null;
    const upcomingInterview =
        primaryApp?.interviews.find((i) => i.status === 'scheduled') ?? null;
    const allReferencesComplete = primaryApp
        ? primaryApp.reference_checks.length > 0 &&
          primaryApp.reference_checks.every((r) => r.status === 'completed')
        : false;

    const totalInterviews = candidate.applications.reduce(
        (sum, app) => sum + app.interviews.length,
        0,
    );

    function toggleCoverLetter(appId: number) {
        setExpandedCoverLetters((prev) => ({ ...prev, [appId]: !prev[appId] }));
    }

    function toggleScreening(appId: number) {
        setExpandedScreening((prev) => ({ ...prev, [appId]: !prev[appId] }));
    }

    /** Render the stage-aware "Next Steps" card content for a given application */
    function renderNextSteps(app: Application) {
        if (!can.manage || app.status !== 'active') return null;

        return (
            <Card className="h-full">
                <CardHeader className="pb-2">
                    <CardTitle className="flex items-center gap-2 text-sm">
                        <ArrowRight className="h-4 w-4" />
                        Next Steps
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                    {/* Stage: new */}
                    {app.stage === 'new' && (
                        <div className="space-y-2">
                            <p className="text-xs text-muted-foreground">
                                Review the application and move to screening
                                when ready.
                            </p>
                            <Button
                                size="sm"
                                className="w-full"
                                onClick={() => advanceStage(app.id)}
                            >
                                <ArrowRight className="mr-1 h-3.5 w-3.5" /> Move
                                to Screening
                            </Button>
                        </div>
                    )}

                    {/* Stage: screening */}
                    {app.stage === 'screening' && (
                        <div className="space-y-3">
                            <p className="text-xs text-muted-foreground">
                                Schedule an interview to proceed.
                            </p>
                            <div className="space-y-2 rounded-lg border p-3">
                                <h5 className="text-xs font-semibold">
                                    Schedule Interview
                                </h5>
                                <div className="space-y-2">
                                    <div className="space-y-1">
                                        <Label
                                            htmlFor={`interview-type-${app.id}`}
                                            className="text-xs"
                                        >
                                            Type
                                        </Label>
                                        <Select
                                            value={
                                                interviewForm.data
                                                    .interview_type
                                            }
                                            onValueChange={(value) =>
                                                interviewForm.setData(
                                                    'interview_type',
                                                    value,
                                                )
                                            }
                                        >
                                            <SelectTrigger
                                                id={`interview-type-${app.id}`}
                                                className="h-8 text-xs"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="phone">
                                                    Phone
                                                </SelectItem>
                                                <SelectItem value="video">
                                                    Video
                                                </SelectItem>
                                                <SelectItem value="in_person">
                                                    In Person
                                                </SelectItem>
                                                <SelectItem value="panel">
                                                    Panel
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-1">
                                        <Label
                                            htmlFor={`interview-datetime-${app.id}`}
                                            className="text-xs"
                                        >
                                            Date & Time
                                        </Label>
                                        <Input
                                            id={`interview-datetime-${app.id}`}
                                            type="datetime-local"
                                            className="h-8 text-xs"
                                            value={
                                                interviewForm.data.scheduled_at
                                            }
                                            onChange={(e) =>
                                                interviewForm.setData(
                                                    'scheduled_at',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label
                                            htmlFor={`interview-location-${app.id}`}
                                            className="text-xs"
                                        >
                                            Location
                                        </Label>
                                        <Input
                                            id={`interview-location-${app.id}`}
                                            type="text"
                                            className="h-8 text-xs"
                                            placeholder="e.g. Office, Zoom link..."
                                            value={interviewForm.data.location}
                                            onChange={(e) =>
                                                interviewForm.setData(
                                                    'location',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <Button
                                        size="sm"
                                        className="h-7 w-full text-xs"
                                        disabled={
                                            !interviewForm.data.scheduled_at ||
                                            interviewForm.processing
                                        }
                                        onClick={() =>
                                            handleScheduleInterview(app.id)
                                        }
                                    >
                                        {interviewForm.processing
                                            ? 'Scheduling...'
                                            : 'Schedule Interview'}
                                    </Button>
                                    {interviewForm.errors.scheduled_at && (
                                        <p className="text-xs text-status-critical">
                                            {interviewForm.errors.scheduled_at}
                                        </p>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Stage: interview_scheduled */}
                    {app.stage === 'interview_scheduled' &&
                        (() => {
                            const upcoming =
                                app.interviews.find(
                                    (i) => i.status === 'scheduled',
                                ) ?? null;
                            return (
                                <div className="space-y-2">
                                    {upcoming ? (
                                        <div className="space-y-2 rounded-lg border p-3">
                                            <p className="text-xs font-semibold">
                                                Upcoming Interview
                                            </p>
                                            <div className="space-y-1 text-xs text-muted-foreground">
                                                <p className="capitalize">
                                                    {upcoming.type.replace(
                                                        '_',
                                                        ' ',
                                                    )}
                                                </p>
                                                <p>
                                                    {formatNZDateTime(
                                                        upcoming.scheduled_at,
                                                    )}
                                                </p>
                                                {upcoming.interviewer_name && (
                                                    <p>
                                                        with{' '}
                                                        {
                                                            upcoming.interviewer_name
                                                        }
                                                    </p>
                                                )}
                                            </div>
                                            <div className="flex gap-1 pt-1">
                                                <Button
                                                    size="sm"
                                                    className="h-7 flex-1 text-xs"
                                                    onClick={() =>
                                                        updateInterviewStatus(
                                                            upcoming.id,
                                                            'completed',
                                                        )
                                                    }
                                                >
                                                    <CheckCircle2 className="mr-1 h-3 w-3" />{' '}
                                                    Mark Completed
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="destructive"
                                                    className="h-7 text-xs"
                                                    onClick={() =>
                                                        updateInterviewStatus(
                                                            upcoming.id,
                                                            'no_show',
                                                        )
                                                    }
                                                >
                                                    No Show
                                                </Button>
                                            </div>
                                        </div>
                                    ) : (
                                        <p className="text-xs text-muted-foreground">
                                            No upcoming interviews found. Check
                                            the interview list below.
                                        </p>
                                    )}
                                </div>
                            );
                        })()}

                    {/* Stage: interview_completed */}
                    {app.stage === 'interview_completed' && (
                        <div className="space-y-3">
                            <p className="text-xs text-muted-foreground">
                                Add reference checks to proceed.
                            </p>
                            <div className="space-y-2 rounded-lg border p-3">
                                <h5 className="text-xs font-semibold">
                                    Add Reference
                                </h5>
                                <div className="space-y-2">
                                    <div className="space-y-1">
                                        <Label
                                            htmlFor={`ref-name-${app.id}`}
                                            className="text-xs"
                                        >
                                            Referee Name
                                        </Label>
                                        <Input
                                            id={`ref-name-${app.id}`}
                                            type="text"
                                            className="h-8 text-xs"
                                            placeholder="Full name"
                                            value={
                                                referenceForm.data.referee_name
                                            }
                                            onChange={(e) =>
                                                referenceForm.setData(
                                                    'referee_name',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label
                                            htmlFor={`ref-email-${app.id}`}
                                            className="text-xs"
                                        >
                                            Email
                                        </Label>
                                        <Input
                                            id={`ref-email-${app.id}`}
                                            type="email"
                                            className="h-8 text-xs"
                                            placeholder="referee@example.com"
                                            value={
                                                referenceForm.data.referee_email
                                            }
                                            onChange={(e) =>
                                                referenceForm.setData(
                                                    'referee_email',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label
                                            htmlFor={`ref-relationship-${app.id}`}
                                            className="text-xs"
                                        >
                                            Relationship
                                        </Label>
                                        <Select
                                            value={
                                                referenceForm.data
                                                    .referee_relationship
                                            }
                                            onValueChange={(value) =>
                                                referenceForm.setData(
                                                    'referee_relationship',
                                                    value,
                                                )
                                            }
                                        >
                                            <SelectTrigger
                                                id={`ref-relationship-${app.id}`}
                                                className="h-8 text-xs"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="professional">
                                                    Professional
                                                </SelectItem>
                                                <SelectItem value="manager">
                                                    Manager
                                                </SelectItem>
                                                <SelectItem value="colleague">
                                                    Colleague
                                                </SelectItem>
                                                <SelectItem value="academic">
                                                    Academic
                                                </SelectItem>
                                                <SelectItem value="personal">
                                                    Personal
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <Button
                                        size="sm"
                                        className="h-7 w-full text-xs"
                                        disabled={
                                            !referenceForm.data.referee_name ||
                                            !referenceForm.data.referee_email ||
                                            referenceForm.processing
                                        }
                                        onClick={() =>
                                            handleAddReference(app.id)
                                        }
                                    >
                                        {referenceForm.processing
                                            ? 'Adding...'
                                            : 'Add Reference'}
                                    </Button>
                                    {referenceForm.errors.referee_name && (
                                        <p className="text-xs text-status-critical">
                                            {referenceForm.errors.referee_name}
                                        </p>
                                    )}
                                    {referenceForm.errors.referee_email && (
                                        <p className="text-xs text-status-critical">
                                            {referenceForm.errors.referee_email}
                                        </p>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Stage: reference_check */}
                    {app.stage === 'reference_check' && (
                        <div className="space-y-2">
                            <p className="text-xs font-semibold">
                                Reference Progress
                            </p>
                            {app.reference_checks.length === 0 ? (
                                <p className="text-xs text-muted-foreground">
                                    No references added yet.
                                </p>
                            ) : (
                                <div className="space-y-1.5">
                                    {app.reference_checks.map((ref) => (
                                        <div
                                            key={ref.id}
                                            className="flex items-center gap-2 text-xs"
                                        >
                                            {ref.status === 'completed' ? (
                                                <CheckCircle2 className="h-3.5 w-3.5 shrink-0 text-status-success" />
                                            ) : (
                                                <Clock className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                            )}
                                            <span
                                                className={
                                                    ref.status === 'completed'
                                                        ? 'text-muted-foreground line-through'
                                                        : ''
                                                }
                                            >
                                                {ref.referee_name}
                                            </span>
                                            <Badge
                                                variant={
                                                    ref.status === 'completed'
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                className="ml-auto text-[10px] capitalize"
                                            >
                                                {ref.status}
                                            </Badge>
                                        </div>
                                    ))}
                                </div>
                            )}
                            {allReferencesComplete &&
                                app.id === primaryApp?.id && (
                                    <Button
                                        size="sm"
                                        className="mt-2 w-full"
                                        onClick={() => advanceStage(app.id)}
                                    >
                                        <Gift className="mr-1 h-3.5 w-3.5" />{' '}
                                        Prepare Offer
                                    </Button>
                                )}
                            {!allReferencesComplete &&
                                app.reference_checks.length > 0 && (
                                    <p className="text-xs text-status-warning">
                                        Complete all references to prepare an
                                        offer.
                                    </p>
                                )}
                        </div>
                    )}

                    {/* Stage: offer_pending */}
                    {app.stage === 'offer_pending' && (
                        <div className="space-y-2">
                            <p className="text-xs text-muted-foreground">
                                Create an offer for this candidate.
                            </p>
                            <Button
                                size="sm"
                                className="w-full"
                                onClick={() =>
                                    setOfferWizard({
                                        open: true,
                                        applicationId: app.id,
                                        positionTitle: app.position_title,
                                        positionRole: app.position_role,
                                    })
                                }
                            >
                                <Gift className="mr-1 h-3.5 w-3.5" /> Create Offer
                            </Button>
                        </div>
                    )}

                    {/* Stage: offer_sent */}
                    {app.stage === 'offer_sent' && (
                        <div className="space-y-2">
                            <div className="rounded-lg border p-3 text-center">
                                <Send className="mx-auto mb-1.5 h-6 w-6 text-primary" />
                                <p className="text-xs font-medium">
                                    Awaiting Response
                                </p>
                                {app.offer?.sent_at && (
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        Sent on{' '}
                                        {formatNZDate(app.offer.sent_at)}
                                    </p>
                                )}
                            </div>
                        </div>
                    )}

                    {/* Stage: offer_accepted */}
                    {app.stage === 'offer_accepted' && (
                        <div className="space-y-2">
                            <p className="text-xs text-muted-foreground">
                                Offer accepted. Convert to employee record.
                            </p>
                            {app.offer && (
                                <Button
                                    size="sm"
                                    className="w-full"
                                    onClick={() => convertOffer(app.offer!.id)}
                                >
                                    <UserCheck className="mr-1 h-3.5 w-3.5" />{' '}
                                    Convert to Employee
                                </Button>
                            )}
                        </div>
                    )}

                    {/* Persistent action links */}
                    {candidate.personal_email && (
                        <div className="space-y-2 border-t pt-3">
                            <Button
                                variant="outline"
                                size="sm"
                                className="w-full justify-start"
                                asChild
                            >
                                <a href={`mailto:${candidate.personal_email}`}>
                                    <Mail className="mr-2 h-3.5 w-3.5" /> Email
                                    Candidate
                                </a>
                            </Button>
                        </div>
                    )}
                </CardContent>
            </Card>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={fullName} />
            <div className="flex flex-col gap-6 p-6">
                {flash?.success && (
                    <div className="rounded-lg border border-status-success/30 bg-status-success-bg p-3 text-sm text-status-success">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="rounded-lg border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical">
                        {flash.error}
                    </div>
                )}

                {/* Hero Header - Gradient Banner */}
                {(() => {
                    const heroBadges: PageHeroBadge[] = [
                        { label: currentStatus.replace(/_/g, ' ') },
                        { label: candidate.source.replace(/_/g, ' ') },
                    ];
                    if (candidate.source_detail)
                        heroBadges.push({ label: candidate.source_detail });

                    const heroMeta: PageHeroMetaItem[] = [];
                    if (candidate.preferred_name)
                        heroMeta.push({ label: `Goes by "${candidate.preferred_name}"` });
                    heroMeta.push({
                        icon: Mail,
                        label: candidate.personal_email,
                        href: `mailto:${candidate.personal_email}`,
                    });
                    if (candidate.personal_phone)
                        heroMeta.push({
                            icon: Phone,
                            label: candidate.personal_phone,
                            href: `tel:${candidate.personal_phone}`,
                        });

                    const daysInPipeline =
                        totalDaysInPipeline ??
                        Math.round(
                            (Date.now() - new Date(candidate.created_at).getTime()) /
                                86400000,
                        );

                    return (
                        <PageHero category="hr"
                            avatar={{ fallback: initials }}
                            title={fullName}
                            meta={heroMeta}
                            badges={heroBadges}
                            stats={[
                                { label: 'Days in Pipeline', value: daysInPipeline },
                                { label: 'Applications', value: candidate.applications.length },
                                { label: 'Interviews', value: totalInterviews },
                            ]}
                            actions={
                                can.manage &&
                                candidate.applications[0]?.status === 'active' ? (
                                    <>
                                        <Button
                                            size="sm"
                                            className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                            variant="outline"
                                            onClick={() =>
                                                advanceStage(candidate.applications[0].id)
                                            }
                                        >
                                            Advance{' '}
                                            <ArrowRight className="ml-1 h-3.5 w-3.5" />
                                        </Button>
                                        <Button
                                            size="sm"
                                            className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                            variant="outline"
                                            onClick={() =>
                                                rejectApplication(
                                                    candidate.applications[0].id,
                                                )
                                            }
                                        >
                                            Reject
                                        </Button>
                                    </>
                                ) : undefined
                            }
                        />
                    );
                })()}

                {/* Tab bar - Full width, no sidebar */}
                <Tabs defaultValue="applications" className="space-y-4">
                    <TabsList className="flex h-auto w-full flex-wrap gap-1">
                        <TabsTrigger value="applications" className="gap-1.5">
                            <Briefcase className="h-4 w-4" />
                            Applications
                            <span className="ml-1.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-muted px-1.5 text-[10px] font-semibold">
                                {candidate.applications.length}
                            </span>
                        </TabsTrigger>
                        <TabsTrigger value="documents" className="gap-1.5">
                            <FolderOpen className="h-4 w-4" />
                            Documents
                            <span className="ml-1.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-muted px-1.5 text-[10px] font-semibold">
                                {(documents ?? []).length}
                            </span>
                        </TabsTrigger>
                        <TabsTrigger value="timeline" className="gap-1.5">
                            <Activity className="h-4 w-4" />
                            Timeline
                            <span className="ml-1.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-muted px-1.5 text-[10px] font-semibold">
                                {(activityLog ?? []).length}
                            </span>
                        </TabsTrigger>
                        <TabsTrigger value="notes" className="gap-1.5">
                            <StickyNote className="h-4 w-4" />
                            Notes
                        </TabsTrigger>
                    </TabsList>

                    {/* Applications Tab */}
                    <TabsContent value="applications" className="space-y-6">
                        {candidate.applications.length === 0 ? (
                            <Card>
                                <CardContent className="py-8 text-center text-muted-foreground">
                                    <Briefcase className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                    <p>No applications yet.</p>
                                </CardContent>
                            </Card>
                        ) : (
                            candidate.applications.map((app) => (
                                <Card key={app.id} className="overflow-hidden">
                                    <CardHeader className="border-b bg-muted/30">
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <CardTitle className="flex items-center gap-2 text-base">
                                                    <Briefcase className="h-4 w-4" />
                                                    {app.position_title}
                                                    {app.position_role && (
                                                        <Badge
                                                            variant="outline"
                                                            className="text-xs"
                                                        >
                                                            {app.position_role}
                                                        </Badge>
                                                    )}
                                                </CardTitle>
                                                {/* Job posting link */}
                                                {app.job_posting ? (
                                                    <div className="mt-1.5 flex flex-wrap items-center gap-2 text-sm">
                                                        <Link
                                                            href={`/hr/job-postings/${app.job_posting.id}`}
                                                            className="font-medium text-primary hover:underline"
                                                        >
                                                            {
                                                                app.job_posting
                                                                    .title
                                                            }
                                                        </Link>
                                                        {app.job_posting
                                                            .department && (
                                                            <Badge
                                                                variant="secondary"
                                                                className="text-xs"
                                                            >
                                                                {
                                                                    app
                                                                        .job_posting
                                                                        .department
                                                                }
                                                            </Badge>
                                                        )}
                                                        {app.job_posting
                                                            .location && (
                                                            <span className="text-xs text-muted-foreground">
                                                                {
                                                                    app
                                                                        .job_posting
                                                                        .location
                                                                }
                                                            </span>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <div className="mt-1.5">
                                                        <Badge
                                                            variant="outline"
                                                            className="text-xs"
                                                        >
                                                            Direct Application
                                                        </Badge>
                                                    </div>
                                                )}
                                                <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                                    <span className="flex items-center gap-1">
                                                        <Calendar className="h-3 w-3" />
                                                        {app.applied_at}
                                                    </span>
                                                    {app.target_site && (
                                                        <span>
                                                            Site:{' '}
                                                            {
                                                                app.target_site
                                                                    .name
                                                            }
                                                        </span>
                                                    )}
                                                    {app.interview_kit && (
                                                        <span>
                                                            Kit:{' '}
                                                            {
                                                                app
                                                                    .interview_kit
                                                                    .name
                                                            }
                                                        </span>
                                                    )}
                                                    {app.cv_original_name && (
                                                        <span className="flex items-center gap-1">
                                                            <FileText className="h-3 w-3" />
                                                            {
                                                                app.cv_original_name
                                                            }
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <Badge
                                                    variant={
                                                        app.status === 'active'
                                                            ? 'default'
                                                            : app.status ===
                                                                'rejected'
                                                              ? 'destructive'
                                                              : 'secondary'
                                                    }
                                                    className="capitalize"
                                                >
                                                    {app.status}
                                                </Badge>
                                                {can.manage &&
                                                    app.status === 'active' && (
                                                        <>
                                                            {app.stage ===
                                                                'offer_pending' &&
                                                                !app.offer && (
                                                                    <Button
                                                                        size="sm"
                                                                        variant="outline"
                                                                        onClick={() =>
                                                                            setOfferWizard({
                                                                                open: true,
                                                                                applicationId: app.id,
                                                                                positionTitle: app.position_title,
                                                                                positionRole: app.position_role,
                                                                            })
                                                                        }
                                                                    >
                                                                        <Gift className="mr-1 h-3 w-3" />{' '}
                                                                        Create Offer
                                                                    </Button>
                                                                )}
                                                        </>
                                                    )}
                                            </div>
                                        </div>
                                    </CardHeader>
                                    <CardContent className="space-y-5 p-5">
                                        {/* Pipeline + Next Steps side by side */}
                                        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                            <div>
                                                <PipelineStepper
                                                    currentStage={app.stage}
                                                />
                                            </div>
                                            <div>{renderNextSteps(app)}</div>
                                        </div>

                                        {/* Cover Letter (collapsible) */}
                                        {app.cover_letter && (
                                            <div className="rounded-lg border">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        toggleCoverLetter(
                                                            app.id,
                                                        )
                                                    }
                                                    className="h-auto w-full justify-between rounded-none px-4 py-2.5 text-sm font-medium"
                                                >
                                                    <span className="flex items-center gap-1.5">
                                                        <FileText className="h-3.5 w-3.5" />
                                                        Cover Letter
                                                    </span>
                                                    {expandedCoverLetters[
                                                        app.id
                                                    ] ? (
                                                        <ChevronUp className="h-4 w-4" />
                                                    ) : (
                                                        <ChevronDown className="h-4 w-4" />
                                                    )}
                                                </Button>
                                                {expandedCoverLetters[
                                                    app.id
                                                ] && (
                                                    <div className="border-t px-4 py-3 text-sm whitespace-pre-wrap text-muted-foreground">
                                                        {app.cover_letter}
                                                    </div>
                                                )}
                                            </div>
                                        )}

                                        {/* Screening Answers (collapsible) */}
                                        {app.screening_answers &&
                                            Object.keys(app.screening_answers)
                                                .length > 0 && (
                                                <div className="rounded-lg border">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            toggleScreening(
                                                                app.id,
                                                            )
                                                        }
                                                        className="h-auto w-full justify-between rounded-none px-4 py-2.5 text-sm font-medium"
                                                    >
                                                        <span className="flex items-center gap-1.5">
                                                            <MessageSquare className="h-3.5 w-3.5" />
                                                            Screening Answers (
                                                            {
                                                                Object.keys(
                                                                    app.screening_answers,
                                                                ).length
                                                            }
                                                            )
                                                        </span>
                                                        {expandedScreening[
                                                            app.id
                                                        ] ? (
                                                            <ChevronUp className="h-4 w-4" />
                                                        ) : (
                                                            <ChevronDown className="h-4 w-4" />
                                                        )}
                                                    </Button>
                                                    {expandedScreening[
                                                        app.id
                                                    ] && (
                                                        <div className="space-y-3 border-t px-4 py-3">
                                                            {Object.entries(
                                                                app.screening_answers,
                                                            ).map(
                                                                ([
                                                                    question,
                                                                    answer,
                                                                ]) => (
                                                                    <div
                                                                        key={
                                                                            question
                                                                        }
                                                                    >
                                                                        <p className="text-xs font-medium text-muted-foreground">
                                                                            {
                                                                                question
                                                                            }
                                                                        </p>
                                                                        <p className="mt-0.5 text-sm">
                                                                            {
                                                                                answer
                                                                            }
                                                                        </p>
                                                                    </div>
                                                                ),
                                                            )}
                                                        </div>
                                                    )}
                                                </div>
                                            )}

                                        {/* Interviews */}
                                        {app.interviews.length > 0 && (
                                            <div>
                                                <h4 className="mb-3 flex items-center gap-1.5 text-sm font-semibold">
                                                    <UserCheck className="h-4 w-4" />
                                                    Interviews (
                                                    {app.interviews.length})
                                                </h4>
                                                <div className="space-y-2">
                                                    {app.interviews.map(
                                                        (interview) => (
                                                            <div
                                                                key={
                                                                    interview.id
                                                                }
                                                                className="rounded-lg border p-3 transition-colors hover:bg-muted/30"
                                                            >
                                                                <div className="flex items-start justify-between gap-3">
                                                                    <div className="min-w-0">
                                                                        <div className="flex items-center gap-2">
                                                                            <span className="text-sm font-medium capitalize">
                                                                                {interview.type.replace(
                                                                                    '_',
                                                                                    ' ',
                                                                                )}
                                                                            </span>
                                                                            <Badge
                                                                                variant={
                                                                                    interviewStatusVariants[
                                                                                        interview
                                                                                            .status
                                                                                    ] ||
                                                                                    'outline'
                                                                                }
                                                                                className="text-xs capitalize"
                                                                            >
                                                                                {interview.status.replace(
                                                                                    '_',
                                                                                    ' ',
                                                                                )}
                                                                            </Badge>
                                                                        </div>
                                                                        <div className="mt-1 text-xs text-muted-foreground">
                                                                            <span>
                                                                                {
                                                                                    interview.scheduled_at
                                                                                }
                                                                            </span>
                                                                            {interview.interviewer_name && (
                                                                                <span>
                                                                                    {' '}
                                                                                    with{' '}
                                                                                    {
                                                                                        interview.interviewer_name
                                                                                    }
                                                                                </span>
                                                                            )}
                                                                        </div>
                                                                        {interview
                                                                            .scores
                                                                            .length >
                                                                            0 && (
                                                                            <div className="mt-2 flex flex-wrap gap-2">
                                                                                {interview.scores.map(
                                                                                    (
                                                                                        score,
                                                                                    ) => (
                                                                                        <div
                                                                                            key={
                                                                                                score.id
                                                                                            }
                                                                                            className="inline-flex items-center gap-1.5 rounded-md bg-muted/50 px-2 py-1 text-xs"
                                                                                        >
                                                                                            <Star className="h-3 w-3 fill-amberx text-status-warning" />
                                                                                            <span>
                                                                                                {score.overall_score ??
                                                                                                    '-'}
                                                                                            </span>
                                                                                            {score.recommendation && (
                                                                                                <span
                                                                                                    className={
                                                                                                        recColors[
                                                                                                            score
                                                                                                                .recommendation
                                                                                                        ] ??
                                                                                                        'text-muted-foreground'
                                                                                                    }
                                                                                                >
                                                                                                    {score.recommendation.replace(
                                                                                                        '_',
                                                                                                        ' ',
                                                                                                    )}
                                                                                                </span>
                                                                                            )}
                                                                                            {score.interviewer_name && (
                                                                                                <span className="text-muted-foreground">
                                                                                                    -{' '}
                                                                                                    {
                                                                                                        score.interviewer_name
                                                                                                    }
                                                                                                </span>
                                                                                            )}
                                                                                        </div>
                                                                                    ),
                                                                                )}
                                                                            </div>
                                                                        )}
                                                                    </div>
                                                                    {can.manage && (
                                                                        <div className="flex shrink-0 items-center gap-1">
                                                                            {interview.status ===
                                                                                'scheduled' && (
                                                                                <>
                                                                                    <Button
                                                                                        size="sm"
                                                                                        variant="outline"
                                                                                        className="h-7 text-xs"
                                                                                        onClick={() =>
                                                                                            updateInterviewStatus(
                                                                                                interview.id,
                                                                                                'completed',
                                                                                            )
                                                                                        }
                                                                                    >
                                                                                        Complete
                                                                                    </Button>
                                                                                    <Button
                                                                                        size="sm"
                                                                                        variant="ghost"
                                                                                        className="h-7 text-xs"
                                                                                        onClick={() =>
                                                                                            updateInterviewStatus(
                                                                                                interview.id,
                                                                                                'no_show',
                                                                                            )
                                                                                        }
                                                                                    >
                                                                                        No
                                                                                        Show
                                                                                    </Button>
                                                                                </>
                                                                            )}
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        ),
                                                    )}
                                                </div>
                                            </div>
                                        )}

                                        {/* References */}
                                        {app.reference_checks.length > 0 && (
                                            <div>
                                                <h4 className="mb-3 flex items-center gap-1.5 text-sm font-semibold">
                                                    <Shield className="h-4 w-4" />
                                                    Reference Checks (
                                                    {
                                                        app.reference_checks
                                                            .length
                                                    }
                                                    )
                                                </h4>
                                                <div className="space-y-2">
                                                    {app.reference_checks.map(
                                                        (ref) => (
                                                            <div
                                                                key={ref.id}
                                                                className="flex items-start justify-between gap-3 rounded-lg border p-3"
                                                            >
                                                                <div>
                                                                    <div className="flex items-center gap-2">
                                                                        <span className="text-sm font-medium">
                                                                            {
                                                                                ref.referee_name
                                                                            }
                                                                        </span>
                                                                        <Badge
                                                                            variant={
                                                                                ref.status ===
                                                                                'completed'
                                                                                    ? 'default'
                                                                                    : 'outline'
                                                                            }
                                                                            className="text-xs capitalize"
                                                                        >
                                                                            {
                                                                                ref.status
                                                                            }
                                                                        </Badge>
                                                                    </div>
                                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                                        {
                                                                            ref.referee_relationship
                                                                        }
                                                                        {ref.referee_phone && (
                                                                            <span>
                                                                                {' '}
                                                                                -{' '}
                                                                                {
                                                                                    ref.referee_phone
                                                                                }
                                                                            </span>
                                                                        )}
                                                                    </div>
                                                                </div>
                                                                {can.manage &&
                                                                    ref.status !==
                                                                        'completed' && (
                                                                        <div className="flex shrink-0 gap-1">
                                                                            <Button
                                                                                size="sm"
                                                                                variant="outline"
                                                                                className="h-7 text-xs"
                                                                                onClick={() =>
                                                                                    updateReferenceStatus(
                                                                                        ref.id,
                                                                                        'contacted',
                                                                                    )
                                                                                }
                                                                            >
                                                                                Contacted
                                                                            </Button>
                                                                            <Button
                                                                                size="sm"
                                                                                className="h-7 text-xs"
                                                                                onClick={() =>
                                                                                    updateReferenceStatus(
                                                                                        ref.id,
                                                                                        'completed',
                                                                                    )
                                                                                }
                                                                            >
                                                                                Complete
                                                                            </Button>
                                                                        </div>
                                                                    )}
                                                            </div>
                                                        ),
                                                    )}
                                                </div>
                                            </div>
                                        )}

                                        {/* Offer */}
                                        {app.offer && (
                                            <div className="rounded-xl border-2 border-status-success/20 bg-status-success-bg p-4">
                                                <h4 className="mb-3 flex items-center gap-2 text-sm font-semibold">
                                                    <Gift className="h-4 w-4 text-status-success" />
                                                    Employment Offer
                                                </h4>
                                                <div className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                                    <div>
                                                        <span className="block text-xs text-muted-foreground">
                                                            Position
                                                        </span>
                                                        {
                                                            app.offer
                                                                .position_title
                                                        }
                                                    </div>
                                                    <div>
                                                        <span className="block text-xs text-muted-foreground">
                                                            Type
                                                        </span>
                                                        <span className="capitalize">
                                                            {app.offer.employment_type.replace(
                                                                '_',
                                                                ' ',
                                                            )}
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <span className="block text-xs text-muted-foreground">
                                                            Start Date
                                                        </span>
                                                        {
                                                            app.offer
                                                                .proposed_start_date
                                                        }
                                                    </div>
                                                    {app.offer
                                                        .annual_salary && (
                                                        <div>
                                                            <span className="block text-xs text-muted-foreground">
                                                                Annual Salary
                                                            </span>
                                                            {formatCurrency(
                                                                app.offer
                                                                    .annual_salary,
                                                            )}
                                                        </div>
                                                    )}
                                                    {app.offer.hourly_rate && (
                                                        <div>
                                                            <span className="block text-xs text-muted-foreground">
                                                                Hourly Rate
                                                            </span>
                                                            {formatCurrency(
                                                                app.offer
                                                                    .hourly_rate,
                                                            )}
                                                        </div>
                                                    )}
                                                    {app.offer
                                                        .hours_per_week && (
                                                        <div>
                                                            <span className="block text-xs text-muted-foreground">
                                                                Hours/Week
                                                            </span>
                                                            {
                                                                app.offer
                                                                    .hours_per_week
                                                            }
                                                            h
                                                        </div>
                                                    )}
                                                    {app.offer.primary_site && (
                                                        <div>
                                                            <span className="block text-xs text-muted-foreground">
                                                                Site
                                                            </span>
                                                            {
                                                                app.offer
                                                                    .primary_site
                                                                    .name
                                                            }
                                                        </div>
                                                    )}
                                                </div>
                                                <div className="mt-3 flex flex-wrap items-center gap-2">
                                                    <Badge
                                                        variant={
                                                            app.offer
                                                                .approval_status ===
                                                            'approved'
                                                                ? 'default'
                                                                : 'secondary'
                                                        }
                                                        className="capitalize"
                                                    >
                                                        {
                                                            app.offer
                                                                .approval_status
                                                        }
                                                    </Badge>
                                                    {app.offer.sent_at && (
                                                        <Badge variant="outline">
                                                            <Send className="mr-1 h-3 w-3" />
                                                            Sent{' '}
                                                            {app.offer.sent_at}
                                                        </Badge>
                                                    )}
                                                    {app.offer.response && (
                                                        <Badge
                                                            variant={
                                                                app.offer
                                                                    .response ===
                                                                'accepted'
                                                                    ? 'default'
                                                                    : 'destructive'
                                                            }
                                                            className="capitalize"
                                                        >
                                                            {app.offer.response}
                                                        </Badge>
                                                    )}
                                                    {app.offer.portal_url && (
                                                        <a
                                                            href={
                                                                app.offer
                                                                    .portal_url
                                                            }
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="inline-flex items-center gap-1 text-xs text-primary hover:underline"
                                                        >
                                                            <ExternalLink className="h-3 w-3" />{' '}
                                                            Candidate Portal
                                                        </a>
                                                    )}
                                                    {app.offer
                                                        .offer_letter_name && (
                                                        <a
                                                            href={`/hr/recruitment/offers/${app.offer.id}/letter`}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="inline-flex items-center gap-1 text-xs text-primary hover:underline"
                                                        >
                                                            <FileText className="h-3 w-3" />{' '}
                                                            {app.offer
                                                                .offer_letter_generated
                                                                ? 'Offer letter (generated)'
                                                                : app.offer
                                                                      .offer_letter_name}
                                                        </a>
                                                    )}
                                                </div>
                                                {can.manage && (
                                                    <div className="mt-3 flex flex-wrap gap-2">
                                                        {app.offer
                                                            .approval_status !==
                                                            'approved' && (
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() =>
                                                                    approveOffer(
                                                                        app
                                                                            .offer!
                                                                            .id,
                                                                    )
                                                                }
                                                            >
                                                                Approve
                                                            </Button>
                                                        )}
                                                        {app.offer
                                                            .approval_status ===
                                                            'approved' &&
                                                            !app.offer
                                                                .sent_at && (
                                                                <Button
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        sendOffer(
                                                                            app
                                                                                .offer!
                                                                                .id,
                                                                        )
                                                                    }
                                                                >
                                                                    <Send className="mr-1 h-3.5 w-3.5" />
                                                                    Send Offer
                                                                </Button>
                                                            )}
                                                        {app.offer.sent_at &&
                                                            !app.offer
                                                                .response && (
                                                                <Button
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        setRespondOffer(
                                                                            {
                                                                                open: true,
                                                                                offerId:
                                                                                    app
                                                                                        .offer!
                                                                                        .id,
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    Record Response
                                                                </Button>
                                                            )}
                                                        {app.offer.response ===
                                                            'accepted' && (
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() =>
                                                                    convertOffer(
                                                                        app
                                                                            .offer!
                                                                            .id,
                                                                    )
                                                                }
                                                            >
                                                                <UserCheck className="mr-1 h-3.5 w-3.5" />{' '}
                                                                Convert to
                                                                Employee
                                                            </Button>
                                                        )}
                                                    </div>
                                                )}
                                                {app.offer.signed_full_name && (
                                                    <p className="mt-2 text-xs text-muted-foreground">
                                                        Signed by:{' '}
                                                        {
                                                            app.offer
                                                                .signed_full_name
                                                        }{' '}
                                                        {app.offer.signed_at &&
                                                            `on ${app.offer.signed_at}`}
                                                    </p>
                                                )}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            ))
                        )}
                    </TabsContent>

                    {/* Documents Tab */}
                    <TabsContent value="documents" className="space-y-4">
                        {/* Header with upload button */}
                        <div className="flex items-center justify-between">
                            <div>
                                <h3 className="text-lg font-semibold">
                                    Documents
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    {documents.length} document
                                    {documents.length !== 1 ? 's' : ''} on file
                                </p>
                            </div>
                            {can.manage && (
                                <Button
                                    onClick={() => setShowUploadDialog(true)}
                                    className="gap-1.5"
                                >
                                    <Upload className="h-4 w-4" /> Upload
                                    Document
                                </Button>
                            )}
                        </div>

                        {/* Summary stat cards */}
                        {documents.length > 0 && (
                            <div className="grid grid-cols-3 gap-3">
                                <Card>
                                    <CardContent className="pt-4 text-center">
                                        <p className="text-2xl font-bold text-primary">
                                            {documents.length}
                                        </p>
                                        <p className="text-xs tracking-wider text-muted-foreground uppercase">
                                            Total
                                        </p>
                                    </CardContent>
                                </Card>
                                <Card>
                                    <CardContent className="pt-4 text-center">
                                        <p className="text-2xl font-bold text-status-warning">
                                            {
                                                documents.filter(
                                                    (d) =>
                                                        d.expires_at &&
                                                        !isExpired(
                                                            d.expires_at,
                                                        ) &&
                                                        new Date(
                                                            d.expires_at,
                                                        ) <=
                                                            new Date(
                                                                Date.now() +
                                                                    30 *
                                                                        86400000,
                                                            ),
                                                ).length
                                            }
                                        </p>
                                        <p className="text-xs tracking-wider text-muted-foreground uppercase">
                                            Expiring
                                        </p>
                                    </CardContent>
                                </Card>
                                <Card>
                                    <CardContent className="pt-4 text-center">
                                        <p className="text-2xl font-bold text-status-critical">
                                            {
                                                documents.filter(
                                                    (d) => d.is_expired,
                                                ).length
                                            }
                                        </p>
                                        <p className="text-xs tracking-wider text-muted-foreground uppercase">
                                            Expired
                                        </p>
                                    </CardContent>
                                </Card>
                            </div>
                        )}

                        {/* Document Grid */}
                        {documents.length === 0 ? (
                            <Card>
                                <CardContent className="py-12 text-center text-muted-foreground">
                                    <FolderOpen className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                    <p className="font-medium">
                                        No documents uploaded yet
                                    </p>
                                    <p className="mt-1 text-sm">
                                        Upload CVs, qualifications, police
                                        vetting, and other documents.
                                    </p>
                                    {can.manage && (
                                        <Button
                                            onClick={() =>
                                                setShowUploadDialog(true)
                                            }
                                            variant="outline"
                                            size="sm"
                                            className="mt-4 gap-1.5"
                                        >
                                            <Upload className="h-4 w-4" />{' '}
                                            Upload Document
                                        </Button>
                                    )}
                                </CardContent>
                            </Card>
                        ) : (
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                                {documents.map((doc) => {
                                    const docExpired = isExpired(
                                        doc.expires_at,
                                    );
                                    const iconClass = doc.mime_type?.includes(
                                        'pdf',
                                    )
                                        ? 'text-status-critical'
                                        : doc.mime_type?.includes('image')
                                          ? 'text-status-info'
                                          : 'text-muted-foreground';
                                    const IconComp = doc.mime_type?.includes(
                                        'pdf',
                                    )
                                        ? FileText
                                        : doc.mime_type?.includes('image')
                                          ? FileImage
                                          : File;
                                    return (
                                        <Card
                                            key={doc.id}
                                            className="group relative flex flex-col items-center gap-2 p-4 transition-colors hover:bg-muted/50"
                                        >
                                            <IconComp
                                                className={`h-8 w-8 ${iconClass}`}
                                            />
                                            <p className="w-full truncate text-center text-xs font-medium">
                                                {doc.original_name}
                                            </p>
                                            <div className="flex flex-wrap justify-center gap-1">
                                                <Badge
                                                    variant="outline"
                                                    className="text-[10px]"
                                                >
                                                    {doc.category_label}
                                                </Badge>
                                                {docExpired && (
                                                    <Badge
                                                        variant="outline"
                                                        className="border-status-critical/30 bg-status-critical-bg text-[10px] text-status-critical"
                                                    >
                                                        Expired
                                                    </Badge>
                                                )}
                                            </div>
                                            <p className="text-[10px] text-muted-foreground">
                                                {doc.formatted_size}
                                            </p>
                                            {doc.notes && (
                                                <p className="w-full truncate text-center text-[10px] text-muted-foreground italic">
                                                    {doc.notes}
                                                </p>
                                            )}
                                            {/* Hover actions */}
                                            <div className="absolute top-1 right-1 flex gap-0.5 opacity-0 transition-opacity group-hover:opacity-100">
                                                <a
                                                    href={`/hr/recruitment/documents/${doc.id}/download`}
                                                >
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="h-7 w-7 p-0"
                                                    >
                                                        <Download className="h-3 w-3" />
                                                    </Button>
                                                </a>
                                                {can.manage && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="h-7 w-7 p-0 text-status-critical"
                                                        onClick={() =>
                                                            deleteDocument(
                                                                doc.id,
                                                            )
                                                        }
                                                    >
                                                        <Trash2 className="h-3 w-3" />
                                                    </Button>
                                                )}
                                            </div>
                                        </Card>
                                    );
                                })}
                            </div>
                        )}

                        {/* Upload Dialog */}
                        <Dialog
                            open={showUploadDialog}
                            onOpenChange={(v) =>
                                !v && setShowUploadDialog(false)
                            }
                        >
                            <DialogContent className="sm:max-w-lg">
                                <DialogHeader>
                                    <DialogTitle>Upload Document</DialogTitle>
                                    <DialogDescription>
                                        Upload a document for {fullName}.
                                        Accepted formats: PDF, DOC, DOCX, JPG,
                                        PNG (max 20MB).
                                    </DialogDescription>
                                </DialogHeader>
                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        handleDocumentUpload(e);
                                        setShowUploadDialog(false);
                                    }}
                                    className="space-y-4"
                                >
                                    <div className="space-y-2">
                                        <Label>File *</Label>
                                        <div className="rounded-lg border-2 border-dashed border-muted-foreground/25 p-4 text-center transition-colors hover:border-primary/50">
                                            <Upload className="mx-auto mb-2 h-8 w-8 text-muted-foreground/50" />
                                            <Input
                                                ref={fileInputRef}
                                                type="file"
                                                accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                                                className="mx-auto max-w-xs"
                                                onChange={(e) =>
                                                    documentForm.setData(
                                                        'file',
                                                        e.target.files?.[0] ??
                                                            null,
                                                    )
                                                }
                                            />
                                        </div>
                                        {documentForm.errors.file && (
                                            <p className="text-xs text-status-critical">
                                                {documentForm.errors.file}
                                            </p>
                                        )}
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label>Category *</Label>
                                            <Select
                                                value={
                                                    documentForm.data.category
                                                }
                                                onValueChange={(value) =>
                                                    documentForm.setData(
                                                        'category',
                                                        value,
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select category" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {Object.entries(
                                                        documentCategories ??
                                                            {},
                                                    ).map(([key, label]) => (
                                                        <SelectItem
                                                            key={key}
                                                            value={key}
                                                        >
                                                            {label as string}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            {documentForm.errors.category && (
                                                <p className="text-xs text-status-critical">
                                                    {
                                                        documentForm.errors
                                                            .category
                                                    }
                                                </p>
                                            )}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Expiry Date</Label>
                                            <Input
                                                type="date"
                                                value={
                                                    documentForm.data.expires_at
                                                }
                                                onChange={(e) =>
                                                    documentForm.setData(
                                                        'expires_at',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Notes</Label>
                                        <Input
                                            placeholder="Brief description or context..."
                                            value={documentForm.data.notes}
                                            onChange={(e) =>
                                                documentForm.setData(
                                                    'notes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <DialogFooter>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() =>
                                                setShowUploadDialog(false)
                                            }
                                        >
                                            Cancel
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={
                                                !documentForm.data.file ||
                                                !documentForm.data.category ||
                                                documentForm.processing
                                            }
                                        >
                                            {documentForm.processing
                                                ? 'Uploading...'
                                                : 'Upload'}
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>
                    </TabsContent>

                    {/* Timeline Tab */}
                    <TabsContent value="timeline" className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Activity Timeline
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {activityLog && activityLog.length > 0 ? (
                                    <div className="space-y-0">
                                        {activityLog.map((entry, i) => (
                                            <ActivityItem
                                                key={i}
                                                type={entry.type}
                                                description={entry.description}
                                                timestamp={entry.timestamp}
                                                actor={entry.actor}
                                            />
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        No activity recorded yet.
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Notes Tab */}
                    <TabsContent value="notes" className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Candidate Notes
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {candidate.notes && (
                                    <div className="rounded-lg bg-muted/50 p-4 text-sm whitespace-pre-wrap">
                                        {candidate.notes}
                                    </div>
                                )}
                                {can.manage && (
                                    <div className="space-y-2">
                                        <Textarea
                                            placeholder="Add a note..."
                                            value={noteText}
                                            onChange={(e) =>
                                                setNoteText(e.target.value)
                                            }
                                            rows={3}
                                        />
                                        <Button
                                            size="sm"
                                            disabled={!noteText.trim()}
                                            onClick={() => {
                                                router.put(
                                                    `/hr/recruitment/candidates/${candidate.id}`,
                                                    {
                                                        notes:
                                                            (candidate.notes
                                                                ? candidate.notes +
                                                                  '\n\n'
                                                                : '') +
                                                            noteText.trim(),
                                                    },
                                                    {
                                                        preserveScroll: true,
                                                        onSuccess: () =>
                                                            setNoteText(''),
                                                    },
                                                );
                                            }}
                                        >
                                            <MessageSquare className="mr-1 h-3.5 w-3.5" />{' '}
                                            Add Note
                                        </Button>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </div>

            {can.manage && offerWizard.applicationId > 0 && (
                <OfferWizardDialog
                    open={offerWizard.open}
                    onClose={() =>
                        setOfferWizard((s) => ({ ...s, open: false }))
                    }
                    applicationId={offerWizard.applicationId}
                    positionTitle={offerWizard.positionTitle}
                    positionRole={offerWizard.positionRole}
                    sites={offerSites}
                    roles={offerRoles}
                />
            )}

            {can.manage && respondOffer.offerId > 0 && (
                <OfferRespondDialog
                    open={respondOffer.open}
                    onClose={() =>
                        setRespondOffer((s) => ({ ...s, open: false }))
                    }
                    offerId={respondOffer.offerId}
                />
            )}

            <AlertDialog open={confirmState !== null} onOpenChange={(o) => !o && setConfirmState(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{confirmState?.title}</AlertDialogTitle>
                        <AlertDialogDescription>{confirmState?.description}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            className={confirmState?.destructive ? 'bg-status-critical text-white hover:bg-status-critical/90' : undefined}
                            onClick={() => {
                                confirmState?.action();
                                setConfirmState(null);
                            }}
                        >
                            {confirmState?.confirmLabel ?? 'Confirm'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
