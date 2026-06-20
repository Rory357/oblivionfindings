import { PhotoUploadButton } from '@/components/hr';
import { PageHero, type PageHeroBadge, type PageHeroMetaItem } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ApplicableProceduresPanel, type ApplicableProcedure } from '@/components/health-safety/applicable-procedures-panel';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    TabsRoot as Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    Award,
    BookOpen,
    Briefcase,
    Calendar,
    Car,
    Check,
    CheckCircle2,
    ChevronRight,
    Clock,
    FileText,
    Flame,
    FolderOpen,
    Heart,
    Laptop,
    Mail,
    MapPin,
    MessageSquare,
    Pencil,
    Shield,
    ShieldAlert,
    Star,
    Target,
    User,
    UserCheck,
    Users,
    X,
} from 'lucide-react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

interface PersonRef {
    id: number;
    name: string;
    position_title: string | null;
    profile_photo_path?: string | null;
}
interface Document {
    id: number;
    title: string;
    category: string | null;
    original_name: string;
    created_at: string;
    expires_at: string | null;
    signed_by_employee: boolean;
}
interface Profile {
    id: number;
    employee_number: string | null;
    position_title: string;
    employment_type: string;
    contract_type: string | null;
    department: string | null;
    team: string | null;
    is_active: boolean;
    start_date: string | null;
    end_date: string | null;
    probation_end_date: string | null;
    hours_per_week: number | null;
    pay_rate: number | null;
    pay_frequency: string | null;
    bio: string | null;
    preferred_name: string | null;
    profile_photo_path: string | null;
    is_first_aider: boolean;
    is_fire_warden: boolean;
    can_drive_clients: boolean;
    work_rights_status: string | null;
    visa_type: string | null;
    visa_expires_at: string | null;
    notes: string | null;
    emergency_contact_name: string | null;
    emergency_contact_phone: string | null;
    emergency_contact_relationship: string | null;
    user: { id: number; name: string; email: string };
    primary_site: { id: number; name: string } | null;
    documents: Document[];
}

interface ComplianceStatus {
    id: number;
    requirement_name: string;
    requirement_type: string;
    status: string;
    expiry_date: string | null;
    completed_date: string | null;
}
interface ComplianceSummary {
    compliant: number;
    expiring_soon: number;
    expired: number;
    not_started: number;
    total: number;
}
interface LeaveBalance {
    id: number;
    leave_type: string;
    accrued_hours: number;
    used_hours: number;
    balance_hours: number;
    as_at_date: string;
}
interface LeaveRequest {
    id: number;
    leave_type: string;
    status: string;
    starts_at: string | null;
    ends_at: string | null;
    hours_requested: number;
}
interface OnboardingChecklist {
    id: number;
    name: string;
    status: string;
    due_date: string | null;
    started_at: string | null;
    completed_at: string | null;
    tasks: OnboardingTask[];
}
interface OnboardingTask {
    id: number;
    category: string;
    title: string;
    description: string | null;
    is_required: boolean;
    status: string;
    assigned_to_role: string | null;
    sign_off_required: boolean;
    completed_at: string | null;
}
interface PerformanceReview {
    id: number;
    review_type: string;
    status: string;
    overall_rating: number | null;
    period_start: string | null;
    period_end: string | null;
    reviewer_name: string | null;
    next_review_date: string | null;
    employee_signed_off: boolean;
    manager_signed_off: boolean;
}
interface ProbationReview {
    id: number;
    review_number: number;
    review_date: string | null;
    status: string;
    recommendation: string | null;
    reviewer_name: string | null;
    extension_weeks: number | null;
}
interface Pip {
    id: number;
    title: string;
    status: string;
    reason: string | null;
    start_date: string | null;
    end_date: string | null;
    outcome: string | null;
    milestones: Array<{
        id: number;
        title: string;
        due_date: string | null;
        status: string;
        outcome: string | null;
    }>;
}
interface DevGoal {
    id: number;
    title: string;
    status: string;
    progress_percent: number;
    due_date: string | null;
    category: string | null;
    competency_area: string | null;
}
interface PerformanceSummary {
    latest_rating: number | null;
    next_review_date: string | null;
    active_goals_count: number;
    active_goals_avg: number;
    has_active_pip: boolean;
}
interface CourseEnrollment {
    id: number;
    course_name: string | null;
    category: string | null;
    status: string;
    enrolled_at: string | null;
    completed_at: string | null;
    score: number | null;
}
interface EmployeeSkill {
    id: number;
    skill_name: string | null;
    category: string | null;
    proficiency_level: number | null;
    self_assessed: boolean;
}
interface CompetencyAssessment {
    id: number;
    competency_name: string | null;
    category: string | null;
    proficiency_level: number | null;
    target_level: number | null;
    assessment_date: string | null;
}
interface DriverEligibility {
    id: number;
    status: string;
    licence_number: string;
    licence_class: string;
    licence_endorsements: string[] | null;
    licence_expires_at: string | null;
    can_drive_clients: boolean;
    incident_free_since: string | null;
    next_review_at: string | null;
}
interface BackgroundCheck {
    id: number;
    check_type: string;
    status: string;
    provider: string | null;
    reference_number: string | null;
    check_date: string | null;
    expires_at: string | null;
    risk_decision: string | null;
}
interface SupervisionNote {
    id: number;
    session_date: string | null;
    session_type: string | null;
    duration_minutes: number | null;
    supervisor_name: string | null;
    topics_discussed: string | null;
    actions_agreed: string[] | null;
    next_session_date: string | null;
}
interface HrCase {
    id: number;
    case_number: string;
    case_type: string;
    severity: string;
    status: string;
    title: string;
    opened_at: string | null;
    closed_at: string | null;
    assigned_to_name: string | null;
}
interface AssetAssignment {
    id: number;
    asset_name: string | null;
    asset_tag: string | null;
    category: string | null;
    serial_number: string | null;
    assigned_at: string | null;
    returned_at: string | null;
    condition: string | null;
}
interface PolicyAttestation {
    id: number;
    policy_name: string | null;
    attested_at: string | null;
}

interface Props {
    profile: Profile;
    tenure: { years: number; months: number } | null;
    manager: PersonRef | null;
    directReports: PersonRef[];
    complianceStatuses: ComplianceStatus[];
    complianceSummary: ComplianceSummary;
    leaveBalances: LeaveBalance[];
    recentLeaveRequests: LeaveRequest[];
    onboardingChecklists: OnboardingChecklist[];
    performanceReviews: PerformanceReview[];
    probationReviews: ProbationReview[];
    pips: Pip[];
    developmentGoals: DevGoal[];
    performanceSummary: PerformanceSummary;
    courseEnrollments: CourseEnrollment[];
    employeeSkills: EmployeeSkill[];
    competencyAssessments: CompetencyAssessment[];
    driverEligibility: DriverEligibility | null;
    backgroundChecks: BackgroundCheck[];
    supervisionNotes: SupervisionNote[];
    cases: HrCase[];
    assetAssignments: AssetAssignment[];
    policyAttestations: PolicyAttestation[];
    safeWorkProcedures?: ApplicableProcedure[];
    can: { manage: boolean; viewSensitive: boolean };
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const AVATAR_COLORS = [
    'bg-status-info text-primary-foreground',
    'bg-primary text-primary-foreground',
    'bg-status-success text-primary-foreground',
    'bg-status-warning text-primary-foreground',
    'bg-status-critical text-primary-foreground',
    'bg-status-info text-primary-foreground',
    'bg-status-critical text-primary-foreground',
    'bg-primary text-primary-foreground',
];

function getInitials(name: string) {
    return name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}
function getAvatarColor(id: number) {
    return AVATAR_COLORS[id % AVATAR_COLORS.length];
}

function formatDate(v?: string | null): string {
    if (!v) return '\u2014';
    const d = new Date(v);
    return isNaN(d.getTime())
        ? v
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
}

function formatLabel(s: string) {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function daysUntil(date: string): number {
    return Math.ceil(
        (new Date(date).getTime() - Date.now()) / (1000 * 60 * 60 * 24),
    );
}

function visaExpiryTone(date: string): string {
    const days = daysUntil(date);
    if (days < 0) return 'font-medium text-status-critical';
    if (days <= 90) return 'font-medium text-status-warning';
    return '';
}

function visaExpiryNote(date: string): string {
    const days = daysUntil(date);
    if (days < 0) return ' (expired)';
    if (days <= 90) return ` (${days}d)`;
    return '';
}

function StatusBadge({ status }: { status: string }) {
    const map: Record<string, string> = {
        compliant:
            'border-status-success/30 bg-status-success-bg text-status-success',
        active: 'border-status-success/30 bg-status-success-bg text-status-success',
        eligible:
            'border-status-success/30 bg-status-success-bg text-status-success',
        clear: 'border-status-success/30 bg-status-success-bg text-status-success',
        completed:
            'border-status-success/30 bg-status-success-bg text-status-success',
        approved:
            'border-status-success/30 bg-status-success-bg text-status-success',
        expiring_soon:
            'border-status-warning/30 bg-status-warning-bg text-status-warning',
        pending:
            'border-status-warning/30 bg-status-warning-bg text-status-warning',
        pending_review:
            'border-status-warning/30 bg-status-warning-bg text-status-warning',
        in_progress: 'border-status-info/30 bg-status-info-bg text-status-info',
        enrolled: 'border-status-info/30 bg-status-info-bg text-status-info',
        open: 'border-status-info/30 bg-status-info-bg text-status-info',
        expired:
            'border-status-critical/30 bg-status-critical-bg text-status-critical',
        suspended:
            'border-status-critical/30 bg-status-critical-bg text-status-critical',
        adverse:
            'border-status-critical/30 bg-status-critical-bg text-status-critical',
        flagged:
            'border-status-warning/30 bg-status-warning-bg text-status-warning',
        not_started: 'border-border bg-muted text-muted-foreground',
        draft: 'border-border bg-muted text-muted-foreground',
        closed: 'border-border bg-muted text-muted-foreground',
        cancelled: 'border-border bg-muted text-muted-foreground',
        rejected:
            'border-status-critical/30 bg-status-critical-bg text-status-critical',
        high: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
        critical:
            'border-status-critical/30 bg-status-critical-bg text-status-critical',
        medium: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
        low: 'border-border bg-muted text-muted-foreground',
    };
    return (
        <Badge
            variant="outline"
            className={
                map[status] || 'border-border bg-muted text-muted-foreground'
            }
        >
            {formatLabel(status)}
        </Badge>
    );
}

function EmptyState({
    icon: Icon,
    label,
}: {
    icon: React.ElementType;
    label: string;
}) {
    return (
        <div className="py-12 text-center">
            <Icon className="mx-auto mb-2 h-8 w-8 text-muted-foreground/30" />
            <p className="text-sm text-muted-foreground">{label}</p>
        </div>
    );
}

function InfoRow({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex justify-between py-2.5 text-sm">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right font-medium">{value || '\u2014'}</span>
        </div>
    );
}

function DonutChart({
    data,
    size = 120,
}: {
    data: Array<{ value: number; color: string }>;
    size?: number;
}) {
    const total = data.reduce((s, d) => s + d.value, 0) || 1;
    const r = (size - 12) / 2;
    const circ = 2 * Math.PI * r;
    let offset = 0;
    return (
        <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
            <circle
                cx={size / 2}
                cy={size / 2}
                r={r}
                fill="none"
                stroke="currentColor"
                strokeWidth={10}
                className="text-muted/20"
            />
            {data
                .filter((d) => d.value > 0)
                .map((d, i) => {
                    const pct = d.value / total;
                    const dashArray = `${pct * circ} ${circ}`;
                    const dashOffset = -offset * circ;
                    offset += pct;
                    return (
                        <circle
                            key={i}
                            cx={size / 2}
                            cy={size / 2}
                            r={r}
                            fill="none"
                            stroke={d.color}
                            strokeWidth={10}
                            strokeDasharray={dashArray}
                            strokeDashoffset={dashOffset}
                            strokeLinecap="round"
                            transform={`rotate(-90 ${size / 2} ${size / 2})`}
                        />
                    );
                })}
        </svg>
    );
}

function ProgressBar({
    value,
    max,
    color = 'bg-primary',
}: {
    value: number;
    max: number;
    color?: string;
}) {
    const pct = max > 0 ? Math.min(100, (value / max) * 100) : 0;
    return (
        <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
            <div
                className={`h-full rounded-full transition-all ${color}`}
                style={{ width: `${pct}%` }}
            />
        </div>
    );
}

function TabCount({ count }: { count: number }) {
    if (count === 0) return null;
    return (
        <span className="ml-1.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-muted px-1.5 text-[10px] font-semibold">
            {count}
        </span>
    );
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

const baseBreadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/people' },
    { title: 'People', href: '/hr/people' },
];

export default function EmployeeShow({
    profile: p,
    tenure = null,
    manager = null,
    directReports = [],
    complianceStatuses = [],
    complianceSummary = {
        compliant: 0,
        expiring_soon: 0,
        expired: 0,
        not_started: 0,
        total: 0,
    },
    leaveBalances = [],
    recentLeaveRequests = [],
    onboardingChecklists = [],
    performanceReviews = [],
    probationReviews = [],
    pips = [],
    developmentGoals = [],
    performanceSummary = {
        latest_rating: null,
        next_review_date: null,
        active_goals_count: 0,
        active_goals_avg: 0,
        has_active_pip: false,
    },
    courseEnrollments = [],
    employeeSkills = [],
    competencyAssessments = [],
    driverEligibility = null,
    backgroundChecks = [],
    supervisionNotes = [],
    cases = [],
    assetAssignments = [],
    policyAttestations = [],
    safeWorkProcedures = [],
    can,
}: Props) {
    const breadcrumbs = [
        ...baseBreadcrumbs,
        { title: p.user.name, href: `/hr/people/${p.id}` },
    ];
    const complianceRate =
        complianceSummary?.total > 0
            ? Math.round(
                  (complianceSummary.compliant / complianceSummary.total) * 100,
              )
            : 100;

    const onboardingTasksByCategory = (tasks: OnboardingTask[]) => {
        const groups: Record<string, OnboardingTask[]> = {};
        tasks.forEach((t) => {
            (groups[t.category || 'General'] ??= []).push(t);
        });
        return Object.entries(groups);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={p.user.name} />

            <div className="flex flex-col gap-6 p-6">
                {/* ============================================================ */}
                {/*  HERO HEADER                                                  */}
                {/* ============================================================ */}
                {(() => {
                    const heroBadges: PageHeroBadge[] = [
                        {
                            label: p.is_active ? 'Active' : 'Inactive',
                            tone: p.is_active ? 'success' : 'critical',
                        },
                        { label: formatLabel(p.employment_type) },
                    ];
                    if (p.department) heroBadges.push({ label: p.department, icon: Briefcase });
                    if (p.team) heroBadges.push({ label: p.team, icon: Users });
                    if (p.primary_site)
                        heroBadges.push({ label: p.primary_site.name, icon: MapPin });
                    if (p.is_first_aider)
                        heroBadges.push({ label: 'First Aider', icon: Heart, tone: 'success' });
                    if (p.is_fire_warden)
                        heroBadges.push({ label: 'Fire Warden', icon: Flame, tone: 'warning' });
                    if (p.can_drive_clients)
                        heroBadges.push({ label: 'Driver', icon: Car, tone: 'info' });

                    const heroMeta: PageHeroMetaItem[] = [];
                    if (p.preferred_name && p.preferred_name !== p.user.name)
                        heroMeta.push({ label: `Goes by ${p.preferred_name}` });
                    if (tenure)
                        heroMeta.push({
                            icon: Clock,
                            label: `${
                                tenure.years > 0
                                    ? `${tenure.years} year${tenure.years !== 1 ? 's' : ''}, `
                                    : ''
                            }${tenure.months} month${tenure.months !== 1 ? 's' : ''} at the organisation`,
                        });

                    const leaveTotal = leaveBalances
                        .reduce((s, l) => s + l.balance_hours, 0)
                        .toFixed(0);

                    return (
                        <PageHero category="hr"
                            avatar={{
                                src: p.profile_photo_path ?? undefined,
                                fallback: getInitials(p.user.name),
                            }}
                            title={p.user.name}
                            description={p.position_title}
                            meta={heroMeta}
                            badges={heroBadges}
                            stats={[
                                {
                                    label: 'Tenure',
                                    value: tenure
                                        ? tenure.years > 0
                                            ? `${tenure.years}y`
                                            : `${tenure.months}m`
                                        : '\u2014',
                                },
                                { label: 'Compliance', value: `${complianceRate}%` },
                                { label: 'Leave Bal.', value: `${leaveTotal}h` },
                            ]}
                            actions={
                                <>
                                    <a href={`mailto:${p.user.email}`}>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                        >
                                            <Mail className="mr-1.5 h-3.5 w-3.5" />
                                            Email
                                        </Button>
                                    </a>
                                    {can.manage && (
                                        <>
                                            <PhotoUploadButton
                                                profileId={p.id}
                                                onDark
                                            />
                                            <Link href={`/hr/people/${p.id}/edit`}>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                                >
                                                    <Pencil className="mr-1.5 h-3.5 w-3.5" />
                                                    Edit
                                                </Button>
                                            </Link>
                                        </>
                                    )}
                                </>
                            }
                        />
                    );
                })()}

                {/* ============================================================ */}
                {/*  TABS                                                         */}
                {/* ============================================================ */}
                <Tabs defaultValue="overview" className="w-full">
                    <TabsList className="flex h-auto w-full flex-wrap gap-1">
                        <TabsTrigger value="overview">
                            <User className="mr-1.5 h-3.5 w-3.5" />
                            Overview
                        </TabsTrigger>
                        <TabsTrigger value="documents">
                            <FileText className="mr-1.5 h-3.5 w-3.5" />
                            Documents
                            <TabCount count={p.documents.length} />
                        </TabsTrigger>
                        <TabsTrigger value="performance">
                            <Star className="mr-1.5 h-3.5 w-3.5" />
                            Performance
                            <TabCount count={performanceReviews.length} />
                        </TabsTrigger>
                        <TabsTrigger value="training">
                            <BookOpen className="mr-1.5 h-3.5 w-3.5" />
                            Training
                            <TabCount count={courseEnrollments.length} />
                        </TabsTrigger>
                        <TabsTrigger value="driver">
                            <Car className="mr-1.5 h-3.5 w-3.5" />
                            Driver
                        </TabsTrigger>
                        <TabsTrigger value="vetting">
                            <Shield className="mr-1.5 h-3.5 w-3.5" />
                            Vetting
                            <TabCount count={backgroundChecks.length} />
                        </TabsTrigger>
                        <TabsTrigger value="compliance">
                            <ShieldAlert className="mr-1.5 h-3.5 w-3.5" />
                            Compliance
                            <TabCount count={complianceSummary.total} />
                        </TabsTrigger>
                        <TabsTrigger value="leave">
                            <Calendar className="mr-1.5 h-3.5 w-3.5" />
                            Leave
                        </TabsTrigger>
                        <TabsTrigger value="onboarding">
                            <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" />
                            Onboarding
                            <TabCount count={onboardingChecklists.length} />
                        </TabsTrigger>
                        <TabsTrigger value="supervision">
                            <UserCheck className="mr-1.5 h-3.5 w-3.5" />
                            Supervision
                            <TabCount count={supervisionNotes.length} />
                        </TabsTrigger>
                        <TabsTrigger value="cases">
                            <FolderOpen className="mr-1.5 h-3.5 w-3.5" />
                            Cases
                            <TabCount count={cases.length} />
                        </TabsTrigger>
                        <TabsTrigger value="assets">
                            <Laptop className="mr-1.5 h-3.5 w-3.5" />
                            Assets
                            <TabCount
                                count={
                                    assetAssignments.filter(
                                        (a) => !a.returned_at,
                                    ).length
                                }
                            />
                        </TabsTrigger>
                    </TabsList>

                    {/* ======== OVERVIEW TAB ======== */}
                    <TabsContent value="overview">
                        <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
                            <div className="space-y-6">
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">
                                            Personal Information
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="divide-y">
                                        <InfoRow
                                            label="Email"
                                            value={
                                                <a
                                                    href={`mailto:${p.user.email}`}
                                                    className="text-primary hover:underline"
                                                >
                                                    {p.user.email}
                                                </a>
                                            }
                                        />
                                        <InfoRow
                                            label="Employee #"
                                            value={p.employee_number}
                                        />
                                        <InfoRow
                                            label="Start Date"
                                            value={formatDate(p.start_date)}
                                        />
                                        {p.end_date && (
                                            <InfoRow
                                                label="End Date"
                                                value={formatDate(p.end_date)}
                                            />
                                        )}
                                        {p.probation_end_date && (
                                            <InfoRow
                                                label="Probation Ends"
                                                value={formatDate(
                                                    p.probation_end_date,
                                                )}
                                            />
                                        )}
                                        {p.work_rights_status && (
                                            <InfoRow
                                                label="Work Rights"
                                                value={formatLabel(
                                                    p.work_rights_status,
                                                )}
                                            />
                                        )}
                                        {p.visa_type && (
                                            <InfoRow
                                                label="Visa"
                                                value={p.visa_type}
                                            />
                                        )}
                                        {p.visa_expires_at && (
                                            <InfoRow
                                                label="Visa Expiry"
                                                value={
                                                    <span
                                                        className={
                                                            visaExpiryTone(
                                                                p.visa_expires_at,
                                                            )
                                                        }
                                                    >
                                                        {formatDate(
                                                            p.visa_expires_at,
                                                        )}
                                                        {visaExpiryNote(
                                                            p.visa_expires_at,
                                                        )}
                                                    </span>
                                                }
                                            />
                                        )}
                                    </CardContent>
                                </Card>
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">
                                            Employment Details
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="divide-y">
                                        <InfoRow
                                            label="Position"
                                            value={p.position_title}
                                        />
                                        <InfoRow
                                            label="Department"
                                            value={p.department}
                                        />
                                        <InfoRow label="Team" value={p.team} />
                                        <InfoRow
                                            label="Type"
                                            value={formatLabel(
                                                p.employment_type,
                                            )}
                                        />
                                        {p.contract_type && (
                                            <InfoRow
                                                label="Contract"
                                                value={formatLabel(
                                                    p.contract_type,
                                                )}
                                            />
                                        )}
                                        <InfoRow
                                            label="Hours/Week"
                                            value={p.hours_per_week?.toString()}
                                        />
                                        <InfoRow
                                            label="Site"
                                            value={p.primary_site?.name}
                                        />
                                        <InfoRow
                                            label="Manager"
                                            value={
                                                manager ? (
                                                    <Link
                                                        href={`/hr/people/${manager.id}`}
                                                        className="text-primary hover:underline"
                                                    >
                                                        {manager.name}
                                                    </Link>
                                                ) : null
                                            }
                                        />
                                    </CardContent>
                                </Card>
                                {can.viewSensitive &&
                                    (p.pay_rate || p.pay_frequency) && (
                                        <Card>
                                            <CardHeader>
                                                <CardTitle className="text-base">
                                                    Financial
                                                </CardTitle>
                                            </CardHeader>
                                            <CardContent className="divide-y">
                                                <InfoRow
                                                    label="Pay Rate"
                                                    value={
                                                        p.pay_rate
                                                            ? `$${Number(p.pay_rate).toFixed(2)}`
                                                            : null
                                                    }
                                                />
                                                <InfoRow
                                                    label="Pay Frequency"
                                                    value={
                                                        p.pay_frequency
                                                            ? formatLabel(
                                                                  p.pay_frequency,
                                                              )
                                                            : null
                                                    }
                                                />
                                            </CardContent>
                                        </Card>
                                    )}
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">
                                            Emergency Contact
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="divide-y">
                                        <InfoRow
                                            label="Name"
                                            value={p.emergency_contact_name}
                                        />
                                        <InfoRow
                                            label="Phone"
                                            value={p.emergency_contact_phone}
                                        />
                                        <InfoRow
                                            label="Relationship"
                                            value={
                                                p.emergency_contact_relationship
                                            }
                                        />
                                    </CardContent>
                                </Card>
                                {p.notes && (
                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="text-base">
                                                Notes
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <p className="text-sm whitespace-pre-line text-muted-foreground">
                                                {p.notes}
                                            </p>
                                        </CardContent>
                                    </Card>
                                )}
                            </div>
                            <div className="space-y-6">
                                {p.bio && (
                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="text-base">
                                                About
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <p className="text-sm whitespace-pre-line text-muted-foreground">
                                                {p.bio}
                                            </p>
                                        </CardContent>
                                    </Card>
                                )}
                                {manager && (
                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="text-base">
                                                Manager
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <Link
                                                href={`/hr/people/${manager.id}`}
                                                className="flex items-center gap-3 rounded-lg p-2 transition-colors hover:bg-muted/50"
                                            >
                                                <div
                                                    className={`flex h-10 w-10 items-center justify-center rounded-full text-xs font-semibold ${getAvatarColor(manager.id)}`}
                                                >
                                                    {getInitials(manager.name)}
                                                </div>
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate font-medium">
                                                        {manager.name}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {manager.position_title}
                                                    </p>
                                                </div>
                                                <ChevronRight className="h-4 w-4 text-muted-foreground" />
                                            </Link>
                                        </CardContent>
                                    </Card>
                                )}
                                {directReports.length > 0 && (
                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="text-base">
                                                Direct Reports (
                                                {directReports.length})
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-1">
                                            {directReports.map((r) => (
                                                <Link
                                                    key={r.id}
                                                    href={`/hr/people/${r.id}`}
                                                    className="flex items-center gap-3 rounded-lg p-2 transition-colors hover:bg-muted/50"
                                                >
                                                    <div
                                                        className={`flex h-8 w-8 items-center justify-center rounded-full text-xs font-semibold ${getAvatarColor(r.id)}`}
                                                    >
                                                        {getInitials(r.name)}
                                                    </div>
                                                    <div className="min-w-0 flex-1">
                                                        <p className="truncate text-sm font-medium">
                                                            {r.name}
                                                        </p>
                                                        <p className="truncate text-xs text-muted-foreground">
                                                            {r.position_title}
                                                        </p>
                                                    </div>
                                                </Link>
                                            ))}
                                        </CardContent>
                                    </Card>
                                )}
                                {(p.is_first_aider ||
                                    p.is_fire_warden ||
                                    p.can_drive_clients) && (
                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="text-base">
                                                Safety Roles
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-2">
                                            {p.is_first_aider && (
                                                <div className="flex items-center gap-2 text-sm">
                                                    <Heart className="h-4 w-4 text-status-success" />
                                                    First Aider
                                                </div>
                                            )}
                                            {p.is_fire_warden && (
                                                <div className="flex items-center gap-2 text-sm">
                                                    <Flame className="h-4 w-4 text-status-warning" />
                                                    Fire Warden
                                                </div>
                                            )}
                                            {p.can_drive_clients && (
                                                <div className="flex items-center gap-2 text-sm">
                                                    <Car className="h-4 w-4 text-status-info" />
                                                    Can Drive Clients
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                )}
                            </div>
                        </div>
                    </TabsContent>

                    {/* ======== DOCUMENTS ======== */}
                    <TabsContent value="documents">
                        <div className="space-y-4">
                            <div className="flex items-center justify-between">
                                <p className="text-sm text-muted-foreground">
                                    {p.documents.length} document
                                    {p.documents.length !== 1 ? 's' : ''}
                                </p>
                                <Link href={`/hr/people/${p.id}/documents`}>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="gap-1.5"
                                    >
                                        <FolderOpen className="h-3.5 w-3.5" />
                                        Manage Documents
                                    </Button>
                                </Link>
                            </div>
                            <Card>
                                <CardContent className="p-0">
                                    {p.documents.length === 0 ? (
                                        <EmptyState
                                            icon={FileText}
                                            label="No documents uploaded"
                                        />
                                    ) : (
                                        <table className="w-full text-sm">
                                            <thead className="border-b bg-muted/50">
                                                <tr>
                                                    <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                        Title
                                                    </th>
                                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase sm:table-cell">
                                                        Category
                                                    </th>
                                                    <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                        Uploaded
                                                    </th>
                                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase md:table-cell">
                                                        Expires
                                                    </th>
                                                    <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                        Signed
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {p.documents.map((d) => (
                                                    <tr
                                                        key={d.id}
                                                        className="hover:bg-muted/30"
                                                    >
                                                        <td className="px-4 py-3 font-medium">
                                                            {d.title}
                                                        </td>
                                                        <td className="hidden px-4 py-3 sm:table-cell">
                                                            <Badge variant="outline">
                                                                {d.category
                                                                    ? formatLabel(
                                                                          d.category,
                                                                      )
                                                                    : 'Other'}
                                                            </Badge>
                                                        </td>
                                                        <td className="px-4 py-3 text-muted-foreground">
                                                            {formatDate(
                                                                d.created_at,
                                                            )}
                                                        </td>
                                                        <td className="hidden px-4 py-3 text-muted-foreground md:table-cell">
                                                            {formatDate(
                                                                d.expires_at,
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            {d.signed_by_employee ? (
                                                                <Check className="h-4 w-4 text-status-success" />
                                                            ) : (
                                                                <X className="h-4 w-4 text-muted-foreground/30" />
                                                            )}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    {/* ======== PERFORMANCE ======== */}
                    <TabsContent value="performance">
                        <div className="space-y-6">
                            {/* Quick Actions */}
                            {can.manage && (
                                <div className="flex flex-wrap gap-2">
                                    <Link
                                        href={`/hr/performance/reviews/create?employee=${p.user.id}`}
                                    >
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="gap-1.5"
                                        >
                                            <Star className="h-3.5 w-3.5" />
                                            Create Review
                                        </Button>
                                    </Link>
                                    <Link
                                        href={`/hr/feedback/request?employee=${p.user.id}`}
                                    >
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="gap-1.5"
                                        >
                                            <MessageSquare className="h-3.5 w-3.5" />
                                            Request 360 Feedback
                                        </Button>
                                    </Link>
                                    <Link
                                        href="/hr/goals"
                                    >
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="gap-1.5"
                                        >
                                            <Target className="h-3.5 w-3.5" />
                                            Add Goal
                                        </Button>
                                    </Link>
                                </div>
                            )}

                            {/* Summary Cards */}
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <div className="rounded-xl border bg-status-warning-bg p-3 text-center">
                                    {performanceSummary.latest_rating ? (
                                        <div className="flex items-center justify-center gap-0.5">
                                            {Array.from({ length: 5 }).map(
                                                (_, i) => (
                                                    <Star
                                                        key={i}
                                                        className={`h-4 w-4 ${i < performanceSummary.latest_rating! ? 'fill-amberx text-status-warning' : 'text-status-warning'}`}
                                                    />
                                                ),
                                            )}
                                        </div>
                                    ) : (
                                        <div className="text-xl font-bold text-status-warning">
                                            &mdash;
                                        </div>
                                    )}
                                    <div className="mt-1 text-[10px] tracking-wider text-status-warning uppercase">
                                        Latest Rating
                                    </div>
                                </div>
                                <div
                                    className={`rounded-xl border p-3 text-center ${performanceSummary.next_review_date && new Date(performanceSummary.next_review_date) < new Date() ? 'bg-status-critical-bg' : ''}`}
                                >
                                    <div
                                        className={`text-sm font-bold ${performanceSummary.next_review_date && new Date(performanceSummary.next_review_date) < new Date() ? 'text-status-critical' : 'text-foreground'}`}
                                    >
                                        {performanceSummary.next_review_date
                                            ? formatDate(
                                                  performanceSummary.next_review_date,
                                              )
                                            : 'Not scheduled'}
                                    </div>
                                    <div className="mt-1 text-[10px] tracking-wider text-muted-foreground uppercase">
                                        Next Review
                                    </div>
                                </div>
                                <div className="rounded-xl border bg-primary/10 p-3 text-center">
                                    <div className="text-xl font-bold text-status-info">
                                        {performanceSummary.active_goals_count}
                                    </div>
                                    <div className="mt-0.5 text-[10px] tracking-wider text-status-info uppercase">
                                        Active Goals
                                    </div>
                                    {performanceSummary.active_goals_count >
                                        0 && (
                                        <div className="mt-1.5">
                                            <ProgressBar
                                                value={
                                                    performanceSummary.active_goals_avg
                                                }
                                                max={100}
                                                color="bg-status-info"
                                            />
                                        </div>
                                    )}
                                </div>
                                <div
                                    className={`rounded-xl border p-3 text-center ${performanceSummary.has_active_pip ? 'bg-status-critical-bg' : ''}`}
                                >
                                    <div
                                        className={`text-xl font-bold ${performanceSummary.has_active_pip ? 'text-status-critical' : 'text-status-success'}`}
                                    >
                                        {performanceSummary.has_active_pip
                                            ? 'Active'
                                            : 'None'}
                                    </div>
                                    <div className="mt-1 text-[10px] tracking-wider text-muted-foreground uppercase">
                                        PIP Status
                                    </div>
                                </div>
                            </div>

                            {/* Performance Reviews */}
                            <Card>
                                <CardHeader className="flex flex-row items-center justify-between">
                                    <CardTitle className="text-base">
                                        Performance Reviews
                                    </CardTitle>
                                    {performanceReviews.length > 0 && (
                                        <Link
                                            href={`/hr/performance/reviews?employee=${p.user.id}`}
                                            className="text-xs text-primary hover:underline"
                                        >
                                            View All
                                        </Link>
                                    )}
                                </CardHeader>
                                <CardContent className="p-0">
                                    {performanceReviews.length === 0 ? (
                                        <EmptyState
                                            icon={Star}
                                            label="No performance reviews"
                                        />
                                    ) : (
                                        <table className="w-full text-sm">
                                            <thead className="border-b bg-muted/50">
                                                <tr>
                                                    <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                        Type
                                                    </th>
                                                    <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                        Period
                                                    </th>
                                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase sm:table-cell">
                                                        Rating
                                                    </th>
                                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase md:table-cell">
                                                        Reviewer
                                                    </th>
                                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase lg:table-cell">
                                                        Sign-off
                                                    </th>
                                                    <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                        Status
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {performanceReviews.map((r) => (
                                                    <tr
                                                        key={r.id}
                                                        className="cursor-pointer hover:bg-muted/30"
                                                        onClick={() =>
                                                            router.visit(
                                                                `/hr/performance/reviews/${r.id}`,
                                                            )
                                                        }
                                                        role="link"
                                                        tabIndex={0}
                                                        onKeyDown={(e) =>
                                                            e.key === 'Enter' &&
                                                            router.visit(
                                                                `/hr/performance/reviews/${r.id}`,
                                                            )
                                                        }
                                                    >
                                                        <td className="px-4 py-3 font-medium">
                                                            {formatLabel(
                                                                r.review_type,
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-3 text-muted-foreground">
                                                            {formatDate(
                                                                r.period_start,
                                                            )}{' '}
                                                            &ndash;{' '}
                                                            {formatDate(
                                                                r.period_end,
                                                            )}
                                                        </td>
                                                        <td className="hidden px-4 py-3 sm:table-cell">
                                                            {r.overall_rating ? (
                                                                <div className="flex gap-0.5">
                                                                    {Array.from(
                                                                        {
                                                                            length: 5,
                                                                        },
                                                                    ).map(
                                                                        (
                                                                            _,
                                                                            i,
                                                                        ) => (
                                                                            <Star
                                                                                key={
                                                                                    i
                                                                                }
                                                                                className={`h-3.5 w-3.5 ${i < r.overall_rating! ? 'fill-amberx text-status-warning' : 'text-muted-foreground/20'}`}
                                                                            />
                                                                        ),
                                                                    )}
                                                                </div>
                                                            ) : (
                                                                '\u2014'
                                                            )}
                                                        </td>
                                                        <td className="hidden px-4 py-3 text-muted-foreground md:table-cell">
                                                            {r.reviewer_name ||
                                                                '\u2014'}
                                                        </td>
                                                        <td className="hidden px-4 py-3 lg:table-cell">
                                                            <div className="flex items-center gap-1.5">
                                                                <span
                                                                    title={
                                                                        r.employee_signed_off
                                                                            ? 'Employee signed off'
                                                                            : 'Employee not signed'
                                                                    }
                                                                >
                                                                    {r.employee_signed_off ? (
                                                                        <CheckCircle2 className="h-3.5 w-3.5 text-status-success" />
                                                                    ) : (
                                                                        <X className="h-3.5 w-3.5 text-muted-foreground/30" />
                                                                    )}
                                                                </span>
                                                                <span
                                                                    title={
                                                                        r.manager_signed_off
                                                                            ? 'Manager signed off'
                                                                            : 'Manager not signed'
                                                                    }
                                                                >
                                                                    {r.manager_signed_off ? (
                                                                        <CheckCircle2 className="h-3.5 w-3.5 text-status-success" />
                                                                    ) : (
                                                                        <X className="h-3.5 w-3.5 text-muted-foreground/30" />
                                                                    )}
                                                                </span>
                                                            </div>
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <StatusBadge
                                                                status={
                                                                    r.status
                                                                }
                                                            />
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Development Goals */}
                            <Card>
                                <CardHeader className="flex flex-row items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <CardTitle className="text-base">
                                            Development Goals
                                        </CardTitle>
                                        {developmentGoals.length > 0 && (
                                            <Badge
                                                variant="secondary"
                                                className="text-[10px]"
                                            >
                                                {developmentGoals.length}
                                            </Badge>
                                        )}
                                    </div>
                                    <div className="flex items-center gap-3">
                                        {developmentGoals.length > 0 && (
                                            <Link
                                                href="/hr/goals/development"
                                                className="text-xs text-primary hover:underline"
                                            >
                                                View All
                                            </Link>
                                        )}
                                        {can.manage && (
                                            <Link
                                                href="/hr/goals/development"
                                            >
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    className="h-7 gap-1 text-xs"
                                                >
                                                    <Target className="h-3 w-3" />
                                                    Add
                                                </Button>
                                            </Link>
                                        )}
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    {developmentGoals.length === 0 ? (
                                        <EmptyState
                                            icon={Target}
                                            label="No development goals"
                                        />
                                    ) : (
                                        developmentGoals.map((g) => (
                                            <div
                                                key={g.id}
                                                className="cursor-pointer space-y-2 rounded-lg border p-3 transition-colors hover:border-primary/30"
                                                onClick={() =>
                                                    router.visit(
                                                        `/hr/goals/${g.id}`,
                                                    )
                                                }
                                                role="link"
                                                tabIndex={0}
                                                onKeyDown={(e) =>
                                                    e.key === 'Enter' &&
                                                    router.visit(
                                                        `/hr/goals/${g.id}`,
                                                    )
                                                }
                                            >
                                                <div className="flex items-center justify-between">
                                                    <div>
                                                        <p className="text-sm font-medium">
                                                            {g.title}
                                                        </p>
                                                        <div className="mt-1 flex gap-1">
                                                            {g.category && (
                                                                <Badge
                                                                    variant="outline"
                                                                    className="px-1.5 py-0 text-[9px]"
                                                                >
                                                                    {formatLabel(
                                                                        g.category,
                                                                    )}
                                                                </Badge>
                                                            )}
                                                            {g.competency_area && (
                                                                <Badge
                                                                    variant="outline"
                                                                    className="bg-primary/10 px-1.5 py-0 text-[9px] text-primary"
                                                                >
                                                                    {
                                                                        g.competency_area
                                                                    }
                                                                </Badge>
                                                            )}
                                                        </div>
                                                    </div>
                                                    <StatusBadge
                                                        status={g.status}
                                                    />
                                                </div>
                                                <ProgressBar
                                                    value={g.progress_percent}
                                                    max={100}
                                                    color="bg-status-info"
                                                />
                                                <div className="flex justify-between text-xs text-muted-foreground">
                                                    <span>
                                                        {g.progress_percent}%
                                                    </span>
                                                    {g.due_date && (
                                                        <span>
                                                            Due{' '}
                                                            {formatDate(
                                                                g.due_date,
                                                            )}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        ))
                                    )}
                                </CardContent>
                            </Card>

                            {/* Competency Snapshot */}
                            <Card>
                                <CardHeader className="flex flex-row items-center justify-between">
                                    <CardTitle className="text-base">
                                        Competency Snapshot
                                    </CardTitle>
                                    {competencyAssessments.length > 0 && (
                                        <Link
                                            href={`/hr/performance/competencies?employee=${p.id}`}
                                            className="text-xs text-primary hover:underline"
                                        >
                                            Full Profile
                                        </Link>
                                    )}
                                </CardHeader>
                                <CardContent>
                                    {competencyAssessments.length === 0 ? (
                                        <EmptyState
                                            icon={Award}
                                            label="No competency assessments"
                                        />
                                    ) : (
                                        (() => {
                                            const grouped: Record<
                                                string,
                                                CompetencyAssessment[]
                                            > = {};
                                            competencyAssessments.forEach(
                                                (a) => {
                                                    const cat =
                                                        a.category || 'General';
                                                    (grouped[cat] ??= []).push(
                                                        a,
                                                    );
                                                },
                                            );
                                            return (
                                                <div className="space-y-4">
                                                    {Object.entries(
                                                        grouped,
                                                    ).map(([cat, items]) => (
                                                        <div key={cat}>
                                                            <p className="mb-2 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                                                                {cat}
                                                            </p>
                                                            <div className="space-y-2">
                                                                {items.map(
                                                                    (a) => {
                                                                        const current =
                                                                            a.proficiency_level ??
                                                                            0;
                                                                        const target =
                                                                            a.target_level ??
                                                                            5;
                                                                        const meetsTarget =
                                                                            current >=
                                                                            target;
                                                                        return (
                                                                            <div
                                                                                key={
                                                                                    a.id
                                                                                }
                                                                            >
                                                                                <div className="mb-1 flex items-center justify-between text-xs">
                                                                                    <span className="font-medium">
                                                                                        {
                                                                                            a.competency_name
                                                                                        }
                                                                                    </span>
                                                                                    <span className="text-muted-foreground">
                                                                                        {
                                                                                            current
                                                                                        }
                                                                                        /
                                                                                        {
                                                                                            target
                                                                                        }
                                                                                    </span>
                                                                                </div>
                                                                                <div className="relative h-2 rounded-full bg-muted">
                                                                                    <div
                                                                                        className="absolute inset-y-0 left-0 rounded-full bg-primary/15"
                                                                                        style={{
                                                                                            width: `${(target / 5) * 100}%`,
                                                                                        }}
                                                                                    />
                                                                                    <div
                                                                                        className={`absolute inset-y-0 left-0 rounded-full ${meetsTarget ? 'bg-status-success' : 'bg-primary'}`}
                                                                                        style={{
                                                                                            width: `${(current / 5) * 100}%`,
                                                                                        }}
                                                                                    />
                                                                                </div>
                                                                            </div>
                                                                        );
                                                                    },
                                                                )}
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>
                                            );
                                        })()
                                    )}
                                </CardContent>
                            </Card>

                            {/* Probation Reviews */}
                            {probationReviews.length > 0 && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">
                                            Probation Reviews
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="relative space-y-4 pl-6 before:absolute before:top-2 before:left-[7px] before:h-[calc(100%-16px)] before:w-0.5 before:bg-muted">
                                            {probationReviews.map((r) => {
                                                const dotColor =
                                                    r.status === 'completed' ||
                                                    r.status === 'passed'
                                                        ? 'bg-status-success'
                                                        : r.status ===
                                                                'in_progress' ||
                                                            r.status ===
                                                                'scheduled'
                                                          ? 'bg-status-warning'
                                                          : r.status ===
                                                              'failed'
                                                            ? 'bg-status-critical'
                                                            : 'bg-muted';
                                                return (
                                                    <div
                                                        key={r.id}
                                                        className="relative"
                                                    >
                                                        <div
                                                            className={`absolute top-1.5 -left-6 h-3.5 w-3.5 rounded-full border-2 border-white ${dotColor}`}
                                                        />
                                                        <div className="flex items-center justify-between">
                                                            <div>
                                                                <p className="text-sm font-medium">
                                                                    Review #
                                                                    {
                                                                        r.review_number
                                                                    }
                                                                </p>
                                                                <p className="text-xs text-muted-foreground">
                                                                    {formatDate(
                                                                        r.review_date,
                                                                    )}{' '}
                                                                    &middot;{' '}
                                                                    {
                                                                        r.reviewer_name
                                                                    }
                                                                </p>
                                                                {r.extension_weeks && (
                                                                    <p className="text-xs text-status-warning">
                                                                        Extended
                                                                        by{' '}
                                                                        {
                                                                            r.extension_weeks
                                                                        }{' '}
                                                                        weeks
                                                                    </p>
                                                                )}
                                                            </div>
                                                            <div className="flex items-center gap-2">
                                                                {r.recommendation && (
                                                                    <Badge variant="outline">
                                                                        {formatLabel(
                                                                            r.recommendation,
                                                                        )}
                                                                    </Badge>
                                                                )}
                                                                <StatusBadge
                                                                    status={
                                                                        r.status
                                                                    }
                                                                />
                                                            </div>
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

                            {/* PIPs */}
                            {pips.length > 0 && (
                                <Card>
                                    <CardHeader className="flex flex-row items-center justify-between">
                                        <CardTitle className="text-base">
                                            Performance Improvement Plans
                                        </CardTitle>
                                        <Link
                                            href="/hr/performance/pips"
                                            className="text-xs text-primary hover:underline"
                                        >
                                            View All
                                        </Link>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        {pips.map((pip) => (
                                            <div
                                                key={pip.id}
                                                className="cursor-pointer space-y-3 rounded-lg border p-4 transition-colors hover:border-primary/30"
                                                onClick={() =>
                                                    router.visit(
                                                        `/hr/performance/pips/${pip.id}`,
                                                    )
                                                }
                                                role="link"
                                                tabIndex={0}
                                                onKeyDown={(e) =>
                                                    e.key === 'Enter' &&
                                                    router.visit(
                                                        `/hr/performance/pips/${pip.id}`,
                                                    )
                                                }
                                            >
                                                <div className="flex items-center justify-between">
                                                    <div>
                                                        <p className="font-medium">
                                                            {pip.title}
                                                        </p>
                                                        {pip.reason && (
                                                            <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">
                                                                {pip.reason}
                                                            </p>
                                                        )}
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        <StatusBadge
                                                            status={pip.status}
                                                        />
                                                        <ChevronRight className="h-4 w-4 text-muted-foreground" />
                                                    </div>
                                                </div>
                                                <p className="text-xs text-muted-foreground">
                                                    {formatDate(pip.start_date)}{' '}
                                                    &ndash;{' '}
                                                    {formatDate(pip.end_date)}
                                                </p>
                                                {pip.milestones.length > 0 && (
                                                    <div className="space-y-2 border-l-2 border-muted pl-4">
                                                        {pip.milestones.map(
                                                            (m) => (
                                                                <div
                                                                    key={m.id}
                                                                    className="flex items-center justify-between text-sm"
                                                                >
                                                                    <div>
                                                                        <p className="font-medium">
                                                                            {
                                                                                m.title
                                                                            }
                                                                        </p>
                                                                        <p className="text-xs text-muted-foreground">
                                                                            Due{' '}
                                                                            {formatDate(
                                                                                m.due_date,
                                                                            )}
                                                                        </p>
                                                                    </div>
                                                                    <StatusBadge
                                                                        status={
                                                                            m.status
                                                                        }
                                                                    />
                                                                </div>
                                                            ),
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        ))}
                                    </CardContent>
                                </Card>
                            )}
                        </div>
                    </TabsContent>

                    {/* ======== TRAINING ======== */}
                    <TabsContent value="training">
                        <div className="space-y-6">
                            {/* Quick Actions */}
                            {can.manage && (
                                <div className="flex flex-wrap gap-2">
                                    <Link href="/hr/training/catalog">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="gap-1.5"
                                        >
                                            <BookOpen className="h-3.5 w-3.5" />
                                            Course Catalog
                                        </Button>
                                    </Link>
                                </div>
                            )}

                            {/* Safe Work Procedures applicable to this employee's role(s) */}
                            {safeWorkProcedures.length > 0 ? (
                                <ApplicableProceduresPanel
                                    procedures={safeWorkProcedures}
                                    subtitle={`Applicable to ${p.user.name.split(' ')[0]}'s role(s) — acknowledgement status`}
                                    ackReadonly
                                />
                            ) : null}

                            {/* Summary Stats */}
                            {(() => {
                                const completedCount = courseEnrollments.filter(
                                    (e) => e.status === 'completed',
                                ).length;
                                const inProgressCount =
                                    courseEnrollments.filter(
                                        (e) =>
                                            e.status === 'enrolled' ||
                                            e.status === 'in_progress',
                                    ).length;
                                return (
                                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                        <div className="rounded-xl border bg-primary/10 p-3 text-center">
                                            <div className="text-xl font-bold text-primary">
                                                {courseEnrollments.length}
                                            </div>
                                            <div className="text-[10px] tracking-wider text-primary uppercase">
                                                Enrolments
                                            </div>
                                        </div>
                                        <div className="rounded-xl border bg-status-success-bg p-3 text-center">
                                            <div className="text-xl font-bold text-status-success">
                                                {completedCount}
                                            </div>
                                            <div className="text-[10px] tracking-wider text-status-success uppercase">
                                                Completed
                                            </div>
                                        </div>
                                        <div className="rounded-xl border bg-primary/10 p-3 text-center">
                                            <div className="text-xl font-bold text-status-info">
                                                {inProgressCount}
                                            </div>
                                            <div className="text-[10px] tracking-wider text-status-info uppercase">
                                                In Progress
                                            </div>
                                        </div>
                                        <div className="rounded-xl border bg-status-warning-bg p-3 text-center">
                                            <div className="text-xl font-bold text-status-warning">
                                                {employeeSkills.length}
                                            </div>
                                            <div className="text-[10px] tracking-wider text-status-warning uppercase">
                                                Skills
                                            </div>
                                        </div>
                                    </div>
                                );
                            })()}

                            {/* Course Enrolments */}
                            <Card>
                                <CardHeader className="flex flex-row items-center justify-between">
                                    <CardTitle className="text-base">
                                        Course Enrolments
                                    </CardTitle>
                                    {courseEnrollments.length > 0 && (
                                        <Link
                                            href="/hr/training/catalog"
                                            className="text-xs text-primary hover:underline"
                                        >
                                            View Catalog
                                        </Link>
                                    )}
                                </CardHeader>
                                <CardContent className="p-0">
                                    {courseEnrollments.length === 0 ? (
                                        <EmptyState
                                            icon={BookOpen}
                                            label="No course enrolments"
                                        />
                                    ) : (
                                        <table className="w-full text-sm">
                                            <thead className="border-b bg-muted/50">
                                                <tr>
                                                    <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                        Course
                                                    </th>
                                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase sm:table-cell">
                                                        Category
                                                    </th>
                                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase md:table-cell">
                                                        Enrolled
                                                    </th>
                                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase md:table-cell">
                                                        Completed
                                                    </th>
                                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase lg:table-cell">
                                                        Score
                                                    </th>
                                                    <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                        Status
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {courseEnrollments.map((e) => (
                                                    <tr
                                                        key={e.id}
                                                        className="hover:bg-muted/30"
                                                    >
                                                        <td className="px-4 py-3 font-medium">
                                                            {e.course_name ||
                                                                '\u2014'}
                                                        </td>
                                                        <td className="hidden px-4 py-3 sm:table-cell">
                                                            {e.category ? (
                                                                <Badge
                                                                    variant="outline"
                                                                    className="text-[10px]"
                                                                >
                                                                    {formatLabel(
                                                                        e.category,
                                                                    )}
                                                                </Badge>
                                                            ) : (
                                                                '\u2014'
                                                            )}
                                                        </td>
                                                        <td className="hidden px-4 py-3 text-muted-foreground md:table-cell">
                                                            {formatDate(
                                                                e.enrolled_at,
                                                            )}
                                                        </td>
                                                        <td className="hidden px-4 py-3 text-muted-foreground md:table-cell">
                                                            {formatDate(
                                                                e.completed_at,
                                                            )}
                                                        </td>
                                                        <td className="hidden px-4 py-3 lg:table-cell">
                                                            {e.score != null ? (
                                                                <span className="font-medium">
                                                                    {e.score}%
                                                                </span>
                                                            ) : (
                                                                '\u2014'
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <StatusBadge
                                                                status={
                                                                    e.status
                                                                }
                                                            />
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Skills */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Skills
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {employeeSkills.length === 0 ? (
                                        <EmptyState
                                            icon={Target}
                                            label="No skills recorded"
                                        />
                                    ) : (
                                        <div className="flex flex-wrap gap-2">
                                            {employeeSkills.map((s) => (
                                                <div
                                                    key={s.id}
                                                    className="flex items-center gap-2 rounded-lg border px-3 py-2 transition-colors hover:bg-muted/50"
                                                >
                                                    <div className="flex h-6 w-6 items-center justify-center rounded-md bg-status-warning-bg">
                                                        <Target className="h-3 w-3 text-status-warning" />
                                                    </div>
                                                    <span className="text-sm font-medium">
                                                        {s.skill_name}
                                                    </span>
                                                    {s.proficiency_level && (
                                                        <Badge
                                                            variant="secondary"
                                                            className="text-[9px]"
                                                        >
                                                            Lv.
                                                            {
                                                                s.proficiency_level
                                                            }
                                                        </Badge>
                                                    )}
                                                    {s.self_assessed && (
                                                        <span className="text-[9px] text-muted-foreground">
                                                            (self)
                                                        </span>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Competency Assessments */}
                            <Card>
                                <CardHeader className="flex flex-row items-center justify-between">
                                    <CardTitle className="text-base">
                                        Competency Assessments
                                    </CardTitle>
                                    {competencyAssessments.length > 0 && (
                                        <Link
                                            href={`/hr/performance/competencies?employee=${p.id}`}
                                            className="text-xs text-primary hover:underline"
                                        >
                                            Full Profile
                                        </Link>
                                    )}
                                </CardHeader>
                                <CardContent>
                                    {competencyAssessments.length === 0 ? (
                                        <EmptyState
                                            icon={Award}
                                            label="No competency assessments"
                                        />
                                    ) : (
                                        <div className="space-y-3">
                                            {competencyAssessments.map((a) => {
                                                const current =
                                                    a.proficiency_level ?? 0;
                                                const target =
                                                    a.target_level ?? 5;
                                                const meetsTarget =
                                                    current >= target;
                                                return (
                                                    <div
                                                        key={a.id}
                                                        className="rounded-lg border p-3 transition-colors hover:bg-muted/30"
                                                    >
                                                        <div className="mb-1.5 flex items-center justify-between text-sm">
                                                            <div className="flex items-center gap-2">
                                                                <span className="font-medium">
                                                                    {
                                                                        a.competency_name
                                                                    }
                                                                </span>
                                                                {a.category && (
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="text-[9px]"
                                                                    >
                                                                        {
                                                                            a.category
                                                                        }
                                                                    </Badge>
                                                                )}
                                                            </div>
                                                            <div className="flex items-center gap-2">
                                                                <span
                                                                    className={`text-xs font-medium ${meetsTarget ? 'text-status-success' : 'text-status-warning'}`}
                                                                >
                                                                    {current}/
                                                                    {target}
                                                                </span>
                                                                <span className="text-[10px] text-muted-foreground">
                                                                    {formatDate(
                                                                        a.assessment_date,
                                                                    )}
                                                                </span>
                                                            </div>
                                                        </div>
                                                        <div className="relative h-2 rounded-full bg-muted">
                                                            <div
                                                                className="absolute inset-y-0 left-0 rounded-full bg-primary/15"
                                                                style={{
                                                                    width: `${(target / 5) * 100}%`,
                                                                }}
                                                            />
                                                            <div
                                                                className={`absolute inset-y-0 left-0 rounded-full ${meetsTarget ? 'bg-status-success' : 'bg-primary'}`}
                                                                style={{
                                                                    width: `${(current / 5) * 100}%`,
                                                                }}
                                                            />
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    {/* ======== DRIVER ======== */}
                    <TabsContent value="driver">
                        <Card>
                            {!driverEligibility ? (
                                <CardContent>
                                    <EmptyState
                                        icon={Car}
                                        label="No driver eligibility record"
                                    />
                                </CardContent>
                            ) : (
                                <>
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-3 text-base">
                                            <Car className="h-5 w-5" />
                                            Driver Eligibility
                                            <StatusBadge
                                                status={
                                                    driverEligibility.status
                                                }
                                            />
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="divide-y">
                                        <InfoRow
                                            label="Licence Number"
                                            value={
                                                driverEligibility.licence_number
                                            }
                                        />
                                        <InfoRow
                                            label="Licence Class"
                                            value={
                                                driverEligibility.licence_class
                                            }
                                        />
                                        {driverEligibility.licence_endorsements
                                            ?.length ? (
                                            <InfoRow
                                                label="Endorsements"
                                                value={driverEligibility.licence_endorsements.join(
                                                    ', ',
                                                )}
                                            />
                                        ) : null}
                                        <InfoRow
                                            label="Licence Expires"
                                            value={formatDate(
                                                driverEligibility.licence_expires_at,
                                            )}
                                        />
                                        <InfoRow
                                            label="Can Drive Clients"
                                            value={
                                                driverEligibility.can_drive_clients ? (
                                                    <Badge className="border-status-success/30 bg-status-success-bg text-status-success">
                                                        Yes
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="outline">
                                                        No
                                                    </Badge>
                                                )
                                            }
                                        />
                                        <InfoRow
                                            label="Incident Free Since"
                                            value={formatDate(
                                                driverEligibility.incident_free_since,
                                            )}
                                        />
                                        <InfoRow
                                            label="Next Review"
                                            value={formatDate(
                                                driverEligibility.next_review_at,
                                            )}
                                        />
                                    </CardContent>
                                </>
                            )}
                        </Card>
                    </TabsContent>

                    {/* ======== VETTING ======== */}
                    <TabsContent value="vetting">
                        <Card>
                            <CardContent className="p-0">
                                {backgroundChecks.length === 0 ? (
                                    <EmptyState
                                        icon={Shield}
                                        label="No background checks recorded"
                                    />
                                ) : (
                                    <table className="w-full text-sm">
                                        <thead className="border-b bg-muted/50">
                                            <tr>
                                                <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                    Check Type
                                                </th>
                                                <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase sm:table-cell">
                                                    Provider
                                                </th>
                                                <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                    Check Date
                                                </th>
                                                <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase md:table-cell">
                                                    Expires
                                                </th>
                                                <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                    Status
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {backgroundChecks.map((c) => (
                                                <tr
                                                    key={c.id}
                                                    className="hover:bg-muted/30"
                                                >
                                                    <td className="px-4 py-3 font-medium">
                                                        {formatLabel(
                                                            c.check_type,
                                                        )}
                                                    </td>
                                                    <td className="hidden px-4 py-3 text-muted-foreground sm:table-cell">
                                                        {c.provider || '\u2014'}
                                                    </td>
                                                    <td className="px-4 py-3 text-muted-foreground">
                                                        {formatDate(
                                                            c.check_date,
                                                        )}
                                                    </td>
                                                    <td className="hidden px-4 py-3 text-muted-foreground md:table-cell">
                                                        {formatDate(
                                                            c.expires_at,
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <StatusBadge
                                                            status={c.status}
                                                        />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* ======== COMPLIANCE ======== */}
                    <TabsContent value="compliance">
                        <div className="space-y-6">
                            <div className="flex flex-col items-center gap-6 sm:flex-row">
                                <DonutChart
                                    data={[
                                        {
                                            value: complianceSummary.compliant,
                                            color: '#22c55e',
                                        },
                                        {
                                            value: complianceSummary.expiring_soon,
                                            color: '#f59e0b',
                                        },
                                        {
                                            value: complianceSummary.expired,
                                            color: '#ef4444',
                                        },
                                        {
                                            value: complianceSummary.not_started,
                                            color: '#94a3b8',
                                        },
                                    ]}
                                />
                                <div className="grid flex-1 grid-cols-2 gap-3 sm:grid-cols-4">
                                    {[
                                        {
                                            label: 'Compliant',
                                            value: complianceSummary.compliant,
                                            cls: 'border-status-success/30 bg-status-success-bg text-status-success',
                                        },
                                        {
                                            label: 'Expiring Soon',
                                            value: complianceSummary.expiring_soon,
                                            cls: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
                                        },
                                        {
                                            label: 'Expired',
                                            value: complianceSummary.expired,
                                            cls: 'border-status-critical/30 bg-status-critical-bg text-status-critical',
                                        },
                                        {
                                            label: 'Not Started',
                                            value: complianceSummary.not_started,
                                            cls: 'border-border bg-muted text-foreground',
                                        },
                                    ].map((s) => (
                                        <div
                                            key={s.label}
                                            className={`rounded-lg border p-3 text-center ${s.cls}`}
                                        >
                                            <p className="text-2xl font-bold">
                                                {s.value}
                                            </p>
                                            <p className="text-xs">{s.label}</p>
                                        </div>
                                    ))}
                                </div>
                            </div>
                            <Card>
                                <CardContent className="p-0">
                                    {complianceStatuses.length === 0 ? (
                                        <EmptyState
                                            icon={ShieldAlert}
                                            label="No compliance requirements"
                                        />
                                    ) : (
                                        <table className="w-full text-sm">
                                            <thead className="border-b bg-muted/50">
                                                <tr>
                                                    <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                        Requirement
                                                    </th>
                                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase sm:table-cell">
                                                        Type
                                                    </th>
                                                    <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                        Status
                                                    </th>
                                                    <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                        Expiry
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {complianceStatuses.map((s) => (
                                                    <tr
                                                        key={s.id}
                                                        className="hover:bg-muted/30"
                                                    >
                                                        <td className="px-4 py-3 font-medium">
                                                            {s.requirement_name}
                                                        </td>
                                                        <td className="hidden px-4 py-3 text-muted-foreground sm:table-cell">
                                                            {s.requirement_type
                                                                ? formatLabel(
                                                                      s.requirement_type,
                                                                  )
                                                                : '\u2014'}
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <StatusBadge
                                                                status={
                                                                    s.status
                                                                }
                                                            />
                                                        </td>
                                                        <td className="px-4 py-3 text-muted-foreground">
                                                            {formatDate(
                                                                s.expiry_date,
                                                            )}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    )}
                                </CardContent>
                            </Card>
                            {policyAttestations.length > 0 && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">
                                            Policy Attestations
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="p-0">
                                        <table className="w-full text-sm">
                                            <thead className="border-b bg-muted/50">
                                                <tr>
                                                    <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                        Policy
                                                    </th>
                                                    <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                        Attested
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {policyAttestations.map((a) => (
                                                    <tr
                                                        key={a.id}
                                                        className="hover:bg-muted/30"
                                                    >
                                                        <td className="px-4 py-3 font-medium">
                                                            {a.policy_name}
                                                        </td>
                                                        <td className="px-4 py-3 text-muted-foreground">
                                                            {formatDate(
                                                                a.attested_at,
                                                            )}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </CardContent>
                                </Card>
                            )}
                        </div>
                    </TabsContent>

                    {/* ======== LEAVE ======== */}
                    <TabsContent value="leave">
                        <div className="space-y-6">
                            {leaveBalances.length > 0 && (
                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    {leaveBalances.map((lb) => {
                                        const colors: Record<string, string> = {
                                            annual: 'bg-status-info',
                                            sick: 'bg-status-critical',
                                            personal: 'bg-primary',
                                            bereavement:
                                                'bg-muted-foreground/80',
                                            family_violence:
                                                'bg-status-critical',
                                            parental: 'bg-status-critical',
                                            alternative: 'bg-status-success',
                                        };
                                        return (
                                            <Card key={lb.id}>
                                                <CardContent className="pt-4">
                                                    <div className="mb-2 flex items-center justify-between">
                                                        <p className="text-sm font-medium">
                                                            {formatLabel(
                                                                lb.leave_type,
                                                            )}
                                                        </p>
                                                        <p
                                                            className={`text-lg font-bold ${lb.balance_hours < 0 ? 'text-status-critical' : ''}`}
                                                        >
                                                            {lb.balance_hours.toFixed(
                                                                1,
                                                            )}
                                                            h
                                                        </p>
                                                    </div>
                                                    <ProgressBar
                                                        value={lb.used_hours}
                                                        max={lb.accrued_hours}
                                                        color={
                                                            colors[
                                                                lb.leave_type
                                                            ] || 'bg-primary'
                                                        }
                                                    />
                                                    <div className="mt-1.5 flex justify-between text-xs text-muted-foreground">
                                                        <span>
                                                            Used:{' '}
                                                            {lb.used_hours.toFixed(
                                                                1,
                                                            )}
                                                            h
                                                        </span>
                                                        <span>
                                                            Accrued:{' '}
                                                            {lb.accrued_hours.toFixed(
                                                                1,
                                                            )}
                                                            h
                                                        </span>
                                                    </div>
                                                </CardContent>
                                            </Card>
                                        );
                                    })}
                                </div>
                            )}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Recent Leave Requests
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="p-0">
                                    {recentLeaveRequests.length === 0 ? (
                                        <EmptyState
                                            icon={Calendar}
                                            label="No leave requests"
                                        />
                                    ) : (
                                        <table className="w-full text-sm">
                                            <thead className="border-b bg-muted/50">
                                                <tr>
                                                    <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                        Type
                                                    </th>
                                                    <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                        From
                                                    </th>
                                                    <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                        To
                                                    </th>
                                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase sm:table-cell">
                                                        Hours
                                                    </th>
                                                    <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                        Status
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {recentLeaveRequests.map(
                                                    (r) => (
                                                        <tr
                                                            key={r.id}
                                                            className="hover:bg-muted/30"
                                                        >
                                                            <td className="px-4 py-3 font-medium">
                                                                {formatLabel(
                                                                    r.leave_type,
                                                                )}
                                                            </td>
                                                            <td className="px-4 py-3 text-muted-foreground">
                                                                {formatDate(
                                                                    r.starts_at,
                                                                )}
                                                            </td>
                                                            <td className="px-4 py-3 text-muted-foreground">
                                                                {formatDate(
                                                                    r.ends_at,
                                                                )}
                                                            </td>
                                                            <td className="hidden px-4 py-3 sm:table-cell">
                                                                {r.hours_requested.toFixed(
                                                                    1,
                                                                )}
                                                            </td>
                                                            <td className="px-4 py-3">
                                                                <StatusBadge
                                                                    status={
                                                                        r.status
                                                                    }
                                                                />
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    {/* ======== ONBOARDING ======== */}
                    <TabsContent value="onboarding">
                        {onboardingChecklists.length === 0 ? (
                            <Card>
                                <CardContent>
                                    <EmptyState
                                        icon={CheckCircle2}
                                        label="No onboarding checklists assigned"
                                    />
                                </CardContent>
                            </Card>
                        ) : (
                            <div className="space-y-6">
                                {onboardingChecklists.map((cl) => {
                                    const done = cl.tasks.filter(
                                        (t) => t.status === 'completed',
                                    ).length;
                                    const total = cl.tasks.length;
                                    const pct =
                                        total > 0
                                            ? Math.round((done / total) * 100)
                                            : 0;
                                    return (
                                        <Card key={cl.id}>
                                            <CardHeader>
                                                <div className="flex items-center justify-between">
                                                    <CardTitle className="text-base">
                                                        {cl.name}
                                                    </CardTitle>
                                                    <div className="flex items-center gap-2">
                                                        <StatusBadge
                                                            status={cl.status}
                                                        />
                                                        <span className="text-sm font-medium">
                                                            {pct}%
                                                        </span>
                                                    </div>
                                                </div>
                                                <ProgressBar
                                                    value={done}
                                                    max={total}
                                                    color={
                                                        pct === 100
                                                            ? 'bg-status-success'
                                                            : 'bg-primary'
                                                    }
                                                />
                                                <div className="mt-1 flex gap-4 text-xs text-muted-foreground">
                                                    <span>
                                                        {done}/{total} tasks
                                                    </span>
                                                    {cl.due_date && (
                                                        <span>
                                                            Due:{' '}
                                                            {formatDate(
                                                                cl.due_date,
                                                            )}
                                                        </span>
                                                    )}
                                                    {cl.started_at && (
                                                        <span>
                                                            Started:{' '}
                                                            {formatDate(
                                                                cl.started_at,
                                                            )}
                                                        </span>
                                                    )}
                                                </div>
                                            </CardHeader>
                                            <CardContent className="space-y-4">
                                                {onboardingTasksByCategory(
                                                    cl.tasks,
                                                ).map(([category, tasks]) => (
                                                    <div key={category}>
                                                        <p className="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                            {category}
                                                        </p>
                                                        <div className="space-y-1.5">
                                                            {tasks.map((t) => (
                                                                <div
                                                                    key={t.id}
                                                                    className={`flex items-start gap-3 rounded-lg border p-3 ${t.status === 'completed' ? 'border-status-success/30 bg-status-success-bg dark:border-status-success/20' : ''}`}
                                                                >
                                                                    {t.status ===
                                                                    'completed' ? (
                                                                        <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-status-success" />
                                                                    ) : (
                                                                        <div className="mt-0.5 h-4 w-4 shrink-0 rounded-full border-2 border-muted-foreground/30" />
                                                                    )}
                                                                    <div className="min-w-0 flex-1">
                                                                        <div className="flex items-center gap-2">
                                                                            <p
                                                                                className={`text-sm font-medium ${t.status === 'completed' ? 'text-muted-foreground line-through' : ''}`}
                                                                            >
                                                                                {
                                                                                    t.title
                                                                                }
                                                                            </p>
                                                                            {t.is_required && (
                                                                                <Badge
                                                                                    variant="outline"
                                                                                    className="border-status-critical/30 bg-status-critical-bg text-[10px] text-status-critical"
                                                                                >
                                                                                    Required
                                                                                </Badge>
                                                                            )}
                                                                            {t.sign_off_required && (
                                                                                <Badge
                                                                                    variant="outline"
                                                                                    className="text-[10px]"
                                                                                >
                                                                                    Sign-off
                                                                                </Badge>
                                                                            )}
                                                                        </div>
                                                                        {t.description && (
                                                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                                                {
                                                                                    t.description
                                                                                }
                                                                            </p>
                                                                        )}
                                                                        <div className="mt-1 flex gap-3 text-xs text-muted-foreground">
                                                                            {t.assigned_to_role && (
                                                                                <span>
                                                                                    Assigned:{' '}
                                                                                    {formatLabel(
                                                                                        t.assigned_to_role,
                                                                                    )}
                                                                                </span>
                                                                            )}
                                                                            {t.completed_at && (
                                                                                <span>
                                                                                    Completed:{' '}
                                                                                    {formatDate(
                                                                                        t.completed_at,
                                                                                    )}
                                                                                </span>
                                                                            )}
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            ))}
                                                        </div>
                                                    </div>
                                                ))}
                                            </CardContent>
                                        </Card>
                                    );
                                })}
                            </div>
                        )}
                    </TabsContent>

                    {/* ======== SUPERVISION ======== */}
                    <TabsContent value="supervision">
                        <Card>
                            <CardContent className="p-0">
                                {supervisionNotes.length === 0 ? (
                                    <EmptyState
                                        icon={UserCheck}
                                        label="No supervision notes"
                                    />
                                ) : (
                                    <div className="divide-y">
                                        {supervisionNotes.map((n) => (
                                            <div
                                                key={n.id}
                                                className="p-4 hover:bg-muted/30"
                                            >
                                                <div className="mb-2 flex items-center justify-between">
                                                    <div className="flex items-center gap-2">
                                                        <p className="text-sm font-medium">
                                                            {formatDate(
                                                                n.session_date,
                                                            )}
                                                        </p>
                                                        {n.session_type && (
                                                            <Badge variant="outline">
                                                                {formatLabel(
                                                                    n.session_type,
                                                                )}
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    <span className="text-xs text-muted-foreground">
                                                        {n.duration_minutes
                                                            ? `${n.duration_minutes} min`
                                                            : ''}{' '}
                                                        &middot;{' '}
                                                        {n.supervisor_name}
                                                    </span>
                                                </div>
                                                {n.topics_discussed && (
                                                    <p className="mb-2 text-sm text-muted-foreground">
                                                        {n.topics_discussed}
                                                    </p>
                                                )}
                                                {n.actions_agreed &&
                                                    n.actions_agreed.length >
                                                        0 && (
                                                        <div className="mt-2">
                                                            <p className="mb-1 text-xs font-semibold text-muted-foreground">
                                                                Actions Agreed:
                                                            </p>
                                                            <ul className="list-disc space-y-0.5 pl-5 text-xs text-muted-foreground">
                                                                {n.actions_agreed.map(
                                                                    (a, i) => (
                                                                        <li
                                                                            key={
                                                                                i
                                                                            }
                                                                        >
                                                                            {a}
                                                                        </li>
                                                                    ),
                                                                )}
                                                            </ul>
                                                        </div>
                                                    )}
                                                {n.next_session_date && (
                                                    <p className="mt-2 text-xs text-muted-foreground">
                                                        Next session:{' '}
                                                        {formatDate(
                                                            n.next_session_date,
                                                        )}
                                                    </p>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* ======== CASES ======== */}
                    <TabsContent value="cases">
                        <Card>
                            <CardContent className="p-0">
                                {cases.length === 0 ? (
                                    <EmptyState
                                        icon={FolderOpen}
                                        label="No cases on record"
                                    />
                                ) : (
                                    <table className="w-full text-sm">
                                        <thead className="border-b bg-muted/50">
                                            <tr>
                                                <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                    Case #
                                                </th>
                                                <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                    Type
                                                </th>
                                                <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase sm:table-cell">
                                                    Severity
                                                </th>
                                                <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                    Title
                                                </th>
                                                <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase md:table-cell">
                                                    Opened
                                                </th>
                                                <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                    Status
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {cases.map((c) => (
                                                <tr
                                                    key={c.id}
                                                    className="hover:bg-muted/30"
                                                >
                                                    <td className="px-4 py-3 font-mono text-xs">
                                                        {c.case_number}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <Badge variant="outline">
                                                            {formatLabel(
                                                                c.case_type,
                                                            )}
                                                        </Badge>
                                                    </td>
                                                    <td className="hidden px-4 py-3 sm:table-cell">
                                                        <StatusBadge
                                                            status={c.severity}
                                                        />
                                                    </td>
                                                    <td className="px-4 py-3 font-medium">
                                                        {c.title}
                                                    </td>
                                                    <td className="hidden px-4 py-3 text-muted-foreground md:table-cell">
                                                        {formatDate(
                                                            c.opened_at,
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <StatusBadge
                                                            status={c.status}
                                                        />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* ======== ASSETS ======== */}
                    <TabsContent value="assets">
                        <Card>
                            <CardContent className="p-0">
                                {assetAssignments.length === 0 ? (
                                    <EmptyState
                                        icon={Laptop}
                                        label="No assets assigned"
                                    />
                                ) : (
                                    <table className="w-full text-sm">
                                        <thead className="border-b bg-muted/50">
                                            <tr>
                                                <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                    Asset
                                                </th>
                                                <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase sm:table-cell">
                                                    Tag
                                                </th>
                                                <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase md:table-cell">
                                                    Category
                                                </th>
                                                <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                    Assigned
                                                </th>
                                                <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                    Status
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {assetAssignments.map((a) => (
                                                <tr
                                                    key={a.id}
                                                    className="hover:bg-muted/30"
                                                >
                                                    <td className="px-4 py-3 font-medium">
                                                        {a.asset_name ||
                                                            '\u2014'}
                                                    </td>
                                                    <td className="hidden px-4 py-3 font-mono text-xs text-muted-foreground sm:table-cell">
                                                        {a.asset_tag ||
                                                            '\u2014'}
                                                    </td>
                                                    <td className="hidden px-4 py-3 text-muted-foreground md:table-cell">
                                                        {a.category
                                                            ? formatLabel(
                                                                  a.category,
                                                              )
                                                            : '\u2014'}
                                                    </td>
                                                    <td className="px-4 py-3 text-muted-foreground">
                                                        {formatDate(
                                                            a.assigned_at,
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        {a.returned_at ? (
                                                            <Badge
                                                                variant="outline"
                                                                className="border-border bg-muted text-muted-foreground"
                                                            >
                                                                Returned
                                                            </Badge>
                                                        ) : (
                                                            <Badge
                                                                variant="outline"
                                                                className="border-status-success/30 bg-status-success-bg text-status-success"
                                                            >
                                                                Active
                                                            </Badge>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}
