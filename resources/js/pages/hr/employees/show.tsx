import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TabsRoot as Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    Award, BookOpen, Briefcase, Calendar, Car, Check, CheckCircle2, ChevronRight,
    Clock, FileText, Flame, FolderOpen, Heart, Laptop, Mail, MapPin, MessageSquare, Pencil,
    Shield, ShieldAlert, Star, Target, User, UserCheck, Users, X,
} from 'lucide-react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

interface PersonRef { id: number; name: string; position_title: string | null; profile_photo_path?: string | null }
interface Document { id: number; title: string; category: string | null; original_name: string; created_at: string; expires_at: string | null; signed_by_employee: boolean }
interface Profile {
    id: number; employee_number: string | null; position_title: string; employment_type: string;
    contract_type: string | null; department: string | null; team: string | null;
    is_active: boolean; start_date: string | null; end_date: string | null; probation_end_date: string | null;
    hours_per_week: number | null; pay_rate: number | null; pay_frequency: string | null;
    bio: string | null; preferred_name: string | null; profile_photo_path: string | null;
    is_first_aider: boolean; is_fire_warden: boolean; can_drive_clients: boolean;
    notes: string | null; emergency_contact_name: string | null; emergency_contact_phone: string | null;
    emergency_contact_relationship: string | null;
    user: { id: number; name: string; email: string };
    primary_site: { id: number; name: string } | null;
    documents: Document[];
}

interface ComplianceStatus { id: number; requirement_name: string; requirement_type: string; status: string; expiry_date: string | null; completed_date: string | null }
interface ComplianceSummary { compliant: number; expiring_soon: number; expired: number; not_started: number; total: number }
interface LeaveBalance { id: number; leave_type: string; accrued_hours: number; used_hours: number; balance_hours: number; as_at_date: string }
interface LeaveRequest { id: number; leave_type: string; status: string; starts_at: string | null; ends_at: string | null; hours_requested: number }
interface OnboardingChecklist { id: number; name: string; status: string; due_date: string | null; started_at: string | null; completed_at: string | null; tasks: OnboardingTask[] }
interface OnboardingTask { id: number; category: string; title: string; description: string | null; is_required: boolean; status: string; assigned_to_role: string | null; sign_off_required: boolean; completed_at: string | null }
interface PerformanceReview { id: number; review_type: string; status: string; overall_rating: number | null; period_start: string | null; period_end: string | null; reviewer_name: string | null; next_review_date: string | null; employee_signed_off: boolean; manager_signed_off: boolean }
interface ProbationReview { id: number; review_number: number; review_date: string | null; status: string; recommendation: string | null; reviewer_name: string | null; extension_weeks: number | null }
interface Pip { id: number; title: string; status: string; reason: string | null; start_date: string | null; end_date: string | null; outcome: string | null; milestones: Array<{ id: number; title: string; due_date: string | null; status: string; outcome: string | null }> }
interface DevGoal { id: number; title: string; status: string; progress_percent: number; due_date: string | null; category: string | null; competency_area: string | null }
interface PerformanceSummary { latest_rating: number | null; next_review_date: string | null; active_goals_count: number; active_goals_avg: number; has_active_pip: boolean }
interface CourseEnrollment { id: number; course_name: string | null; category: string | null; status: string; enrolled_at: string | null; completed_at: string | null; score: number | null }
interface EmployeeSkill { id: number; skill_name: string | null; category: string | null; proficiency_level: number | null; self_assessed: boolean }
interface CompetencyAssessment { id: number; competency_name: string | null; category: string | null; proficiency_level: number | null; target_level: number | null; assessment_date: string | null }
interface DriverEligibility { id: number; status: string; licence_number: string; licence_class: string; licence_endorsements: string[] | null; licence_expires_at: string | null; can_drive_clients: boolean; incident_free_since: string | null; next_review_at: string | null }
interface BackgroundCheck { id: number; check_type: string; status: string; provider: string | null; reference_number: string | null; check_date: string | null; expires_at: string | null; risk_decision: string | null }
interface SupervisionNote { id: number; session_date: string | null; session_type: string | null; duration_minutes: number | null; supervisor_name: string | null; topics_discussed: string | null; actions_agreed: string[] | null; next_session_date: string | null }
interface HrCase { id: number; case_number: string; case_type: string; severity: string; status: string; title: string; opened_at: string | null; closed_at: string | null; assigned_to_name: string | null }
interface AssetAssignment { id: number; asset_name: string | null; asset_tag: string | null; category: string | null; serial_number: string | null; assigned_at: string | null; returned_at: string | null; condition: string | null }
interface PolicyAttestation { id: number; policy_name: string | null; attested_at: string | null }

interface Props {
    profile: Profile; tenure: { years: number; months: number } | null; manager: PersonRef | null; directReports: PersonRef[];
    complianceStatuses: ComplianceStatus[]; complianceSummary: ComplianceSummary;
    leaveBalances: LeaveBalance[]; recentLeaveRequests: LeaveRequest[];
    onboardingChecklists: OnboardingChecklist[];
    performanceReviews: PerformanceReview[]; probationReviews: ProbationReview[]; pips: Pip[]; developmentGoals: DevGoal[]; performanceSummary: PerformanceSummary;
    courseEnrollments: CourseEnrollment[]; employeeSkills: EmployeeSkill[]; competencyAssessments: CompetencyAssessment[];
    driverEligibility: DriverEligibility | null; backgroundChecks: BackgroundCheck[];
    supervisionNotes: SupervisionNote[]; cases: HrCase[]; assetAssignments: AssetAssignment[]; policyAttestations: PolicyAttestation[];
    can: { manage: boolean; viewSensitive: boolean };
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const AVATAR_COLORS = [
    'bg-blue-500 text-white', 'bg-primary text-white', 'bg-emerald-500 text-white', 'bg-amber-500 text-white',
    'bg-pink-500 text-white', 'bg-cyan-500 text-white', 'bg-rose-500 text-white', 'bg-primary text-white',
];

function getInitials(name: string) { return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2); }
function getAvatarColor(id: number) { return AVATAR_COLORS[id % AVATAR_COLORS.length]; }

function formatDate(v?: string | null): string {
    if (!v) return '\u2014';
    const d = new Date(v);
    return isNaN(d.getTime()) ? v : d.toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });
}

function formatLabel(s: string) { return s.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()); }

function StatusBadge({ status }: { status: string }) {
    const map: Record<string, string> = {
        compliant: 'border-emerald-200 bg-emerald-50 text-emerald-700', active: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        eligible: 'border-emerald-200 bg-emerald-50 text-emerald-700', clear: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        completed: 'border-emerald-200 bg-emerald-50 text-emerald-700', approved: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        expiring_soon: 'border-amber-200 bg-amber-50 text-amber-700', pending: 'border-amber-200 bg-amber-50 text-amber-700',
        pending_review: 'border-amber-200 bg-amber-50 text-amber-700', in_progress: 'border-blue-200 bg-blue-50 text-blue-700',
        enrolled: 'border-blue-200 bg-blue-50 text-blue-700', open: 'border-blue-200 bg-blue-50 text-blue-700',
        expired: 'border-red-200 bg-red-50 text-red-700', suspended: 'border-red-200 bg-red-50 text-red-700',
        adverse: 'border-red-200 bg-red-50 text-red-700', flagged: 'border-orange-200 bg-orange-50 text-orange-700',
        not_started: 'border-border bg-muted text-muted-foreground', draft: 'border-border bg-muted text-muted-foreground',
        closed: 'border-border bg-muted text-muted-foreground', cancelled: 'border-border bg-muted text-muted-foreground',
        rejected: 'border-red-200 bg-red-50 text-red-700', high: 'border-orange-200 bg-orange-50 text-orange-700',
        critical: 'border-red-200 bg-red-50 text-red-700', medium: 'border-amber-200 bg-amber-50 text-amber-700',
        low: 'border-border bg-muted text-muted-foreground',
    };
    return <Badge variant="outline" className={map[status] || 'border-border bg-muted text-muted-foreground'}>{formatLabel(status)}</Badge>;
}

function EmptyState({ icon: Icon, label }: { icon: React.ElementType; label: string }) {
    return <div className="py-12 text-center"><Icon className="mx-auto mb-2 h-8 w-8 text-muted-foreground/30" /><p className="text-sm text-muted-foreground">{label}</p></div>;
}

function InfoRow({ label, value }: { label: string; value: React.ReactNode }) {
    return <div className="flex justify-between py-2.5 text-sm"><span className="text-muted-foreground">{label}</span><span className="font-medium text-right">{value || '\u2014'}</span></div>;
}

function DonutChart({ data, size = 120 }: { data: Array<{ value: number; color: string }>; size?: number }) {
    const total = data.reduce((s, d) => s + d.value, 0) || 1;
    const r = (size - 12) / 2;
    const circ = 2 * Math.PI * r;
    let offset = 0;
    return (
        <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
            <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="currentColor" strokeWidth={10} className="text-muted/20" />
            {data.filter(d => d.value > 0).map((d, i) => {
                const pct = d.value / total;
                const dashArray = `${pct * circ} ${circ}`;
                const dashOffset = -offset * circ;
                offset += pct;
                return <circle key={i} cx={size / 2} cy={size / 2} r={r} fill="none" stroke={d.color} strokeWidth={10} strokeDasharray={dashArray} strokeDashoffset={dashOffset} strokeLinecap="round" transform={`rotate(-90 ${size / 2} ${size / 2})`} />;
            })}
        </svg>
    );
}

function ProgressBar({ value, max, color = 'bg-primary' }: { value: number; max: number; color?: string }) {
    const pct = max > 0 ? Math.min(100, (value / max) * 100) : 0;
    return <div className="h-2 w-full overflow-hidden rounded-full bg-muted"><div className={`h-full rounded-full transition-all ${color}`} style={{ width: `${pct}%` }} /></div>;
}

function TabCount({ count }: { count: number }) {
    if (count === 0) return null;
    return <span className="ml-1.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-muted px-1.5 text-[10px] font-semibold">{count}</span>;
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

const baseBreadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/people' },
    { title: 'People', href: '/hr/people' },
];

export default function EmployeeShow({
    profile: p, tenure = null, manager = null, directReports = [],
    complianceStatuses = [], complianceSummary = { compliant: 0, expiring_soon: 0, expired: 0, not_started: 0, total: 0 },
    leaveBalances = [], recentLeaveRequests = [],
    onboardingChecklists = [],
    performanceReviews = [], probationReviews = [], pips = [], developmentGoals = [],
    performanceSummary = { latest_rating: null, next_review_date: null, active_goals_count: 0, active_goals_avg: 0, has_active_pip: false },
    courseEnrollments = [], employeeSkills = [], competencyAssessments = [],
    driverEligibility = null, backgroundChecks = [],
    supervisionNotes = [], cases = [], assetAssignments = [], policyAttestations = [],
    can,
}: Props) {
    const breadcrumbs = [...baseBreadcrumbs, { title: p.user.name, href: `/hr/people/${p.id}` }];
    const complianceRate = complianceSummary?.total > 0 ? Math.round((complianceSummary.compliant / complianceSummary.total) * 100) : 100;

    const onboardingTasksByCategory = (tasks: OnboardingTask[]) => {
        const groups: Record<string, OnboardingTask[]> = {};
        tasks.forEach(t => { (groups[t.category || 'General'] ??= []).push(t); });
        return Object.entries(groups);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={p.user.name} />

            <div className="flex flex-col gap-6 p-6">
                {/* ============================================================ */}
                {/*  HERO HEADER                                                  */}
                {/* ============================================================ */}
                <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary/90 via-primary to-primary/80 p-6 text-white md:p-8">
                    <div className="pointer-events-none absolute -right-16 -top-16 h-64 w-64 rounded-full bg-white/5" />
                    <div className="pointer-events-none absolute -bottom-20 -left-20 h-48 w-48 rounded-full bg-white/5" />
                    <div className="pointer-events-none absolute right-1/3 top-1/4 h-24 w-24 rounded-full bg-white/5" />

                    <div className="relative flex flex-col items-center gap-6 md:flex-row md:items-start">
                        <div className={`flex h-24 w-24 shrink-0 items-center justify-center rounded-2xl border-4 border-white/20 text-2xl font-bold shadow-xl md:h-28 md:w-28 md:text-3xl ${getAvatarColor(p.id)}`}>
                            {getInitials(p.user.name)}
                        </div>

                        <div className="flex-1 text-center md:text-left">
                            <h1 className="text-2xl font-bold md:text-3xl">{p.user.name}</h1>
                            {p.preferred_name && p.preferred_name !== p.user.name && (
                                <p className="text-sm text-white/70">Goes by {p.preferred_name}</p>
                            )}
                            <p className="mt-1 text-lg text-white/80">{p.position_title}</p>

                            <div className="mt-3 flex flex-wrap items-center justify-center gap-2 md:justify-start">
                                <Badge className={p.is_active ? 'bg-emerald-400/20 text-emerald-100 border-emerald-300/30' : 'bg-red-400/20 text-red-100 border-red-300/30'}>
                                    {p.is_active ? 'Active' : 'Inactive'}
                                </Badge>
                                <Badge className="bg-white/10 text-white/90 border-white/20">{formatLabel(p.employment_type)}</Badge>
                                {p.department && <Badge className="bg-white/10 text-white/90 border-white/20"><Briefcase className="mr-1 h-3 w-3" />{p.department}</Badge>}
                                {p.team && <Badge className="bg-white/10 text-white/90 border-white/20"><Users className="mr-1 h-3 w-3" />{p.team}</Badge>}
                                {p.primary_site && <Badge className="bg-white/10 text-white/90 border-white/20"><MapPin className="mr-1 h-3 w-3" />{p.primary_site.name}</Badge>}
                                {p.is_first_aider && <Badge className="bg-emerald-400/20 text-emerald-100 border-emerald-300/30"><Heart className="mr-1 h-3 w-3" />First Aider</Badge>}
                                {p.is_fire_warden && <Badge className="bg-orange-400/20 text-orange-100 border-orange-300/30"><Flame className="mr-1 h-3 w-3" />Fire Warden</Badge>}
                                {p.can_drive_clients && <Badge className="bg-blue-400/20 text-blue-100 border-blue-300/30"><Car className="mr-1 h-3 w-3" />Driver</Badge>}
                            </div>

                            {tenure && (
                                <p className="mt-2 flex items-center justify-center gap-1.5 text-sm text-white/60 md:justify-start">
                                    <Clock className="h-3.5 w-3.5" />
                                    {tenure.years > 0 ? `${tenure.years} year${tenure.years !== 1 ? 's' : ''}, ` : ''}{tenure.months} month{tenure.months !== 1 ? 's' : ''} at the organisation
                                </p>
                            )}
                        </div>

                        <div className="flex flex-col items-center gap-3 md:items-end">
                            <div className="flex gap-2">
                                <a href={`mailto:${p.user.email}`}>
                                    <Button size="sm" variant="outline" className="border-white/20 bg-white/10 text-white hover:bg-white/20"><Mail className="mr-1.5 h-3.5 w-3.5" />Email</Button>
                                </a>
                                {can.manage && (
                                    <Link href={`/hr/people/${p.id}/edit`}>
                                        <Button size="sm" variant="outline" className="border-white/20 bg-white/10 text-white hover:bg-white/20"><Pencil className="mr-1.5 h-3.5 w-3.5" />Edit</Button>
                                    </Link>
                                )}
                            </div>
                            <div className="hidden gap-6 text-center md:flex">
                                <div><p className="text-2xl font-bold">{tenure ? (tenure.years > 0 ? `${tenure.years}y` : `${tenure.months}m`) : '\u2014'}</p><p className="text-xs text-white/50">Tenure</p></div>
                                <div><p className="text-2xl font-bold">{complianceRate}%</p><p className="text-xs text-white/50">Compliance</p></div>
                                <div><p className="text-2xl font-bold">{leaveBalances.reduce((s, l) => s + l.balance_hours, 0).toFixed(0)}h</p><p className="text-xs text-white/50">Leave Bal.</p></div>
                            </div>
                        </div>
                    </div>
                </div>

                {/* ============================================================ */}
                {/*  TABS                                                         */}
                {/* ============================================================ */}
                <Tabs defaultValue="overview" className="w-full">
                    <TabsList className="flex flex-wrap h-auto gap-1 w-full">
                        <TabsTrigger value="overview"><User className="mr-1.5 h-3.5 w-3.5" />Overview</TabsTrigger>
                        <TabsTrigger value="documents"><FileText className="mr-1.5 h-3.5 w-3.5" />Documents<TabCount count={p.documents.length} /></TabsTrigger>
                        <TabsTrigger value="performance"><Star className="mr-1.5 h-3.5 w-3.5" />Performance<TabCount count={performanceReviews.length} /></TabsTrigger>
                        <TabsTrigger value="training"><BookOpen className="mr-1.5 h-3.5 w-3.5" />Training<TabCount count={courseEnrollments.length} /></TabsTrigger>
                        <TabsTrigger value="driver"><Car className="mr-1.5 h-3.5 w-3.5" />Driver</TabsTrigger>
                        <TabsTrigger value="vetting"><Shield className="mr-1.5 h-3.5 w-3.5" />Vetting<TabCount count={backgroundChecks.length} /></TabsTrigger>
                        <TabsTrigger value="compliance"><ShieldAlert className="mr-1.5 h-3.5 w-3.5" />Compliance<TabCount count={complianceSummary.total} /></TabsTrigger>
                        <TabsTrigger value="leave"><Calendar className="mr-1.5 h-3.5 w-3.5" />Leave</TabsTrigger>
                        <TabsTrigger value="onboarding"><CheckCircle2 className="mr-1.5 h-3.5 w-3.5" />Onboarding<TabCount count={onboardingChecklists.length} /></TabsTrigger>
                        <TabsTrigger value="supervision"><UserCheck className="mr-1.5 h-3.5 w-3.5" />Supervision<TabCount count={supervisionNotes.length} /></TabsTrigger>
                        <TabsTrigger value="cases"><FolderOpen className="mr-1.5 h-3.5 w-3.5" />Cases<TabCount count={cases.length} /></TabsTrigger>
                        <TabsTrigger value="assets"><Laptop className="mr-1.5 h-3.5 w-3.5" />Assets<TabCount count={assetAssignments.filter(a => !a.returned_at).length} /></TabsTrigger>
                    </TabsList>

                    {/* ======== OVERVIEW TAB ======== */}
                    <TabsContent value="overview">
                        <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
                            <div className="space-y-6">
                                <Card><CardHeader><CardTitle className="text-base">Personal Information</CardTitle></CardHeader>
                                    <CardContent className="divide-y">
                                        <InfoRow label="Email" value={<a href={`mailto:${p.user.email}`} className="text-primary hover:underline">{p.user.email}</a>} />
                                        <InfoRow label="Employee #" value={p.employee_number} />
                                        <InfoRow label="Start Date" value={formatDate(p.start_date)} />
                                        {p.end_date && <InfoRow label="End Date" value={formatDate(p.end_date)} />}
                                        {p.probation_end_date && <InfoRow label="Probation Ends" value={formatDate(p.probation_end_date)} />}
                                    </CardContent>
                                </Card>
                                <Card><CardHeader><CardTitle className="text-base">Employment Details</CardTitle></CardHeader>
                                    <CardContent className="divide-y">
                                        <InfoRow label="Position" value={p.position_title} />
                                        <InfoRow label="Department" value={p.department} />
                                        <InfoRow label="Team" value={p.team} />
                                        <InfoRow label="Type" value={formatLabel(p.employment_type)} />
                                        {p.contract_type && <InfoRow label="Contract" value={formatLabel(p.contract_type)} />}
                                        <InfoRow label="Hours/Week" value={p.hours_per_week?.toString()} />
                                        <InfoRow label="Site" value={p.primary_site?.name} />
                                        <InfoRow label="Manager" value={manager ? <Link href={`/hr/people/${manager.id}`} className="text-primary hover:underline">{manager.name}</Link> : null} />
                                    </CardContent>
                                </Card>
                                {can.viewSensitive && (p.pay_rate || p.pay_frequency) && (
                                    <Card><CardHeader><CardTitle className="text-base">Financial</CardTitle></CardHeader>
                                        <CardContent className="divide-y">
                                            <InfoRow label="Pay Rate" value={p.pay_rate ? `$${Number(p.pay_rate).toFixed(2)}` : null} />
                                            <InfoRow label="Pay Frequency" value={p.pay_frequency ? formatLabel(p.pay_frequency) : null} />
                                        </CardContent>
                                    </Card>
                                )}
                                <Card><CardHeader><CardTitle className="text-base">Emergency Contact</CardTitle></CardHeader>
                                    <CardContent className="divide-y">
                                        <InfoRow label="Name" value={p.emergency_contact_name} />
                                        <InfoRow label="Phone" value={p.emergency_contact_phone} />
                                        <InfoRow label="Relationship" value={p.emergency_contact_relationship} />
                                    </CardContent>
                                </Card>
                                {p.notes && (
                                    <Card><CardHeader><CardTitle className="text-base">Notes</CardTitle></CardHeader>
                                        <CardContent><p className="whitespace-pre-line text-sm text-muted-foreground">{p.notes}</p></CardContent>
                                    </Card>
                                )}
                            </div>
                            <div className="space-y-6">
                                {p.bio && (
                                    <Card><CardHeader><CardTitle className="text-base">About</CardTitle></CardHeader>
                                        <CardContent><p className="whitespace-pre-line text-sm text-muted-foreground">{p.bio}</p></CardContent>
                                    </Card>
                                )}
                                {manager && (
                                    <Card><CardHeader><CardTitle className="text-base">Manager</CardTitle></CardHeader>
                                        <CardContent>
                                            <Link href={`/hr/people/${manager.id}`} className="flex items-center gap-3 rounded-lg p-2 transition-colors hover:bg-muted/50">
                                                <div className={`flex h-10 w-10 items-center justify-center rounded-full text-xs font-semibold ${getAvatarColor(manager.id)}`}>{getInitials(manager.name)}</div>
                                                <div className="min-w-0 flex-1"><p className="truncate font-medium">{manager.name}</p><p className="truncate text-xs text-muted-foreground">{manager.position_title}</p></div>
                                                <ChevronRight className="h-4 w-4 text-muted-foreground" />
                                            </Link>
                                        </CardContent>
                                    </Card>
                                )}
                                {directReports.length > 0 && (
                                    <Card><CardHeader><CardTitle className="text-base">Direct Reports ({directReports.length})</CardTitle></CardHeader>
                                        <CardContent className="space-y-1">
                                            {directReports.map(r => (
                                                <Link key={r.id} href={`/hr/people/${r.id}`} className="flex items-center gap-3 rounded-lg p-2 transition-colors hover:bg-muted/50">
                                                    <div className={`flex h-8 w-8 items-center justify-center rounded-full text-xs font-semibold ${getAvatarColor(r.id)}`}>{getInitials(r.name)}</div>
                                                    <div className="min-w-0 flex-1"><p className="truncate text-sm font-medium">{r.name}</p><p className="truncate text-xs text-muted-foreground">{r.position_title}</p></div>
                                                </Link>
                                            ))}
                                        </CardContent>
                                    </Card>
                                )}
                                {(p.is_first_aider || p.is_fire_warden || p.can_drive_clients) && (
                                    <Card><CardHeader><CardTitle className="text-base">Safety Roles</CardTitle></CardHeader>
                                        <CardContent className="space-y-2">
                                            {p.is_first_aider && <div className="flex items-center gap-2 text-sm"><Heart className="h-4 w-4 text-emerald-500" />First Aider</div>}
                                            {p.is_fire_warden && <div className="flex items-center gap-2 text-sm"><Flame className="h-4 w-4 text-orange-500" />Fire Warden</div>}
                                            {p.can_drive_clients && <div className="flex items-center gap-2 text-sm"><Car className="h-4 w-4 text-blue-500" />Can Drive Clients</div>}
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
                                <p className="text-sm text-muted-foreground">{p.documents.length} document{p.documents.length !== 1 ? 's' : ''}</p>
                                <Link href={`/hr/people/${p.id}/documents`}>
                                    <Button variant="outline" size="sm" className="gap-1.5"><FolderOpen className="h-3.5 w-3.5" />Manage Documents</Button>
                                </Link>
                            </div>
                            <Card><CardContent className="p-0">
                                {p.documents.length === 0 ? <EmptyState icon={FileText} label="No documents uploaded" /> : (
                                    <table className="w-full text-sm"><thead className="border-b bg-muted/50"><tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Title</th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground sm:table-cell">Category</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Uploaded</th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground md:table-cell">Expires</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Signed</th>
                                    </tr></thead><tbody className="divide-y">
                                        {p.documents.map(d => (
                                            <tr key={d.id} className="hover:bg-muted/30">
                                                <td className="px-4 py-3 font-medium">{d.title}</td>
                                                <td className="hidden px-4 py-3 sm:table-cell"><Badge variant="outline">{d.category ? formatLabel(d.category) : 'Other'}</Badge></td>
                                                <td className="px-4 py-3 text-muted-foreground">{formatDate(d.created_at)}</td>
                                                <td className="hidden px-4 py-3 text-muted-foreground md:table-cell">{formatDate(d.expires_at)}</td>
                                                <td className="px-4 py-3">{d.signed_by_employee ? <Check className="h-4 w-4 text-emerald-500" /> : <X className="h-4 w-4 text-muted-foreground/30" />}</td>
                                            </tr>
                                        ))}
                                    </tbody></table>
                                )}
                            </CardContent></Card>
                        </div>
                    </TabsContent>

                    {/* ======== PERFORMANCE ======== */}
                    <TabsContent value="performance">
                        <div className="space-y-6">
                            {/* Quick Actions */}
                            {can.manage && (
                                <div className="flex flex-wrap gap-2">
                                    <Link href={`/hr/performance/reviews/create?employee=${p.user.id}`}><Button variant="outline" size="sm" className="gap-1.5"><Star className="h-3.5 w-3.5" />Create Review</Button></Link>
                                    <Link href={`/hr/feedback/request?employee=${p.user.id}`}><Button variant="outline" size="sm" className="gap-1.5"><MessageSquare className="h-3.5 w-3.5" />Request 360 Feedback</Button></Link>
                                    <Link href={`/hr/goals/create?employee=${p.user.id}`}><Button variant="outline" size="sm" className="gap-1.5"><Target className="h-3.5 w-3.5" />Add Goal</Button></Link>
                                </div>
                            )}

                            {/* Summary Cards */}
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <div className="rounded-xl border bg-gradient-to-br from-amber-50 to-orange-50 p-3 text-center">
                                    {performanceSummary.latest_rating ? (
                                        <div className="flex items-center justify-center gap-0.5">{Array.from({ length: 5 }).map((_, i) => <Star key={i} className={`h-4 w-4 ${i < performanceSummary.latest_rating! ? 'fill-amber-400 text-amber-400' : 'text-amber-200'}`} />)}</div>
                                    ) : <div className="text-xl font-bold text-amber-400">&mdash;</div>}
                                    <div className="mt-1 text-[10px] uppercase tracking-wider text-amber-600">Latest Rating</div>
                                </div>
                                <div className={`rounded-xl border p-3 text-center ${performanceSummary.next_review_date && new Date(performanceSummary.next_review_date) < new Date() ? 'bg-gradient-to-br from-red-50 to-rose-50' : ''}`}>
                                    <div className={`text-sm font-bold ${performanceSummary.next_review_date && new Date(performanceSummary.next_review_date) < new Date() ? 'text-red-600' : 'text-foreground'}`}>
                                        {performanceSummary.next_review_date ? formatDate(performanceSummary.next_review_date) : 'Not scheduled'}
                                    </div>
                                    <div className="mt-1 text-[10px] uppercase tracking-wider text-muted-foreground">Next Review</div>
                                </div>
                                <div className="rounded-xl border bg-gradient-to-br from-blue-50 to-indigo-50 p-3 text-center">
                                    <div className="text-xl font-bold text-blue-700">{performanceSummary.active_goals_count}</div>
                                    <div className="mt-0.5 text-[10px] uppercase tracking-wider text-blue-500">Active Goals</div>
                                    {performanceSummary.active_goals_count > 0 && (
                                        <div className="mt-1.5"><ProgressBar value={performanceSummary.active_goals_avg} max={100} color="bg-blue-500" /></div>
                                    )}
                                </div>
                                <div className={`rounded-xl border p-3 text-center ${performanceSummary.has_active_pip ? 'bg-gradient-to-br from-red-50 to-rose-50' : ''}`}>
                                    <div className={`text-xl font-bold ${performanceSummary.has_active_pip ? 'text-red-600' : 'text-emerald-600'}`}>
                                        {performanceSummary.has_active_pip ? 'Active' : 'None'}
                                    </div>
                                    <div className="mt-1 text-[10px] uppercase tracking-wider text-muted-foreground">PIP Status</div>
                                </div>
                            </div>

                            {/* Performance Reviews */}
                            <Card><CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle className="text-base">Performance Reviews</CardTitle>
                                {performanceReviews.length > 0 && <Link href={`/hr/performance/reviews?employee=${p.user.id}`} className="text-xs text-primary hover:underline">View All</Link>}
                            </CardHeader>
                                <CardContent className="p-0">
                                    {performanceReviews.length === 0 ? <EmptyState icon={Star} label="No performance reviews" /> : (
                                        <table className="w-full text-sm"><thead className="border-b bg-muted/50"><tr>
                                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Type</th>
                                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Period</th>
                                            <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground sm:table-cell">Rating</th>
                                            <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground md:table-cell">Reviewer</th>
                                            <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground lg:table-cell">Sign-off</th>
                                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Status</th>
                                        </tr></thead><tbody className="divide-y">
                                            {performanceReviews.map(r => (
                                                <tr key={r.id} className="hover:bg-muted/30 cursor-pointer" onClick={() => router.visit(`/hr/performance/reviews/${r.id}`)} role="link" tabIndex={0} onKeyDown={e => e.key === 'Enter' && router.visit(`/hr/performance/reviews/${r.id}`)}>
                                                    <td className="px-4 py-3 font-medium">{formatLabel(r.review_type)}</td>
                                                    <td className="px-4 py-3 text-muted-foreground">{formatDate(r.period_start)} &ndash; {formatDate(r.period_end)}</td>
                                                    <td className="hidden px-4 py-3 sm:table-cell">{r.overall_rating ? <div className="flex gap-0.5">{Array.from({ length: 5 }).map((_, i) => <Star key={i} className={`h-3.5 w-3.5 ${i < r.overall_rating! ? 'fill-amber-400 text-amber-400' : 'text-muted-foreground/20'}`} />)}</div> : '\u2014'}</td>
                                                    <td className="hidden px-4 py-3 text-muted-foreground md:table-cell">{r.reviewer_name || '\u2014'}</td>
                                                    <td className="hidden px-4 py-3 lg:table-cell">
                                                        <div className="flex items-center gap-1.5">
                                                            <span title={r.employee_signed_off ? 'Employee signed off' : 'Employee not signed'}>{r.employee_signed_off ? <CheckCircle2 className="h-3.5 w-3.5 text-emerald-500" /> : <X className="h-3.5 w-3.5 text-muted-foreground/30" />}</span>
                                                            <span title={r.manager_signed_off ? 'Manager signed off' : 'Manager not signed'}>{r.manager_signed_off ? <CheckCircle2 className="h-3.5 w-3.5 text-emerald-500" /> : <X className="h-3.5 w-3.5 text-muted-foreground/30" />}</span>
                                                        </div>
                                                    </td>
                                                    <td className="px-4 py-3"><StatusBadge status={r.status} /></td>
                                                </tr>
                                            ))}
                                        </tbody></table>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Development Goals */}
                            <Card><CardHeader className="flex flex-row items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <CardTitle className="text-base">Development Goals</CardTitle>
                                    {developmentGoals.length > 0 && <Badge variant="secondary" className="text-[10px]">{developmentGoals.length}</Badge>}
                                </div>
                                <div className="flex items-center gap-3">
                                    {developmentGoals.length > 0 && <Link href={`/hr/goals?employee=${p.user.id}`} className="text-xs text-primary hover:underline">View All</Link>}
                                    {can.manage && <Link href={`/hr/goals/create?employee=${p.user.id}`}><Button variant="outline" size="sm" className="h-7 gap-1 text-xs"><Target className="h-3 w-3" />Add</Button></Link>}
                                </div>
                            </CardHeader>
                                <CardContent className="space-y-3">
                                    {developmentGoals.length === 0 ? <EmptyState icon={Target} label="No development goals" /> : developmentGoals.map(g => (
                                        <div key={g.id} className="space-y-2 rounded-lg border p-3 cursor-pointer transition-colors hover:border-primary/30" onClick={() => router.visit(`/hr/goals/${g.id}`)} role="link" tabIndex={0} onKeyDown={e => e.key === 'Enter' && router.visit(`/hr/goals/${g.id}`)}>
                                            <div className="flex items-center justify-between">
                                                <div>
                                                    <p className="text-sm font-medium">{g.title}</p>
                                                    <div className="mt-1 flex gap-1">
                                                        {g.category && <Badge variant="outline" className="text-[9px] px-1.5 py-0">{formatLabel(g.category)}</Badge>}
                                                        {g.competency_area && <Badge variant="outline" className="text-[9px] px-1.5 py-0 bg-primary/10 text-primary">{g.competency_area}</Badge>}
                                                    </div>
                                                </div>
                                                <StatusBadge status={g.status} />
                                            </div>
                                            <ProgressBar value={g.progress_percent} max={100} color="bg-blue-500" />
                                            <div className="flex justify-between text-xs text-muted-foreground"><span>{g.progress_percent}%</span>{g.due_date && <span>Due {formatDate(g.due_date)}</span>}</div>
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>

                            {/* Competency Snapshot */}
                            <Card><CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle className="text-base">Competency Snapshot</CardTitle>
                                {competencyAssessments.length > 0 && <Link href={`/hr/performance/competencies?employee=${p.id}`} className="text-xs text-primary hover:underline">Full Profile</Link>}
                            </CardHeader>
                                <CardContent>
                                    {competencyAssessments.length === 0 ? <EmptyState icon={Award} label="No competency assessments" /> : (() => {
                                        const grouped: Record<string, CompetencyAssessment[]> = {};
                                        competencyAssessments.forEach(a => { const cat = a.category || 'General'; (grouped[cat] ??= []).push(a); });
                                        return (
                                            <div className="space-y-4">
                                                {Object.entries(grouped).map(([cat, items]) => (
                                                    <div key={cat}>
                                                        <p className="mb-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{cat}</p>
                                                        <div className="space-y-2">
                                                            {items.map(a => {
                                                                const current = a.proficiency_level ?? 0;
                                                                const target = a.target_level ?? 5;
                                                                const meetsTarget = current >= target;
                                                                return (
                                                                    <div key={a.id}>
                                                                        <div className="mb-1 flex items-center justify-between text-xs">
                                                                            <span className="font-medium">{a.competency_name}</span>
                                                                            <span className="text-muted-foreground">{current}/{target}</span>
                                                                        </div>
                                                                        <div className="relative h-2 rounded-full bg-muted">
                                                                            <div className="absolute inset-y-0 left-0 rounded-full bg-primary/15" style={{ width: `${(target / 5) * 100}%` }} />
                                                                            <div className={`absolute inset-y-0 left-0 rounded-full ${meetsTarget ? 'bg-emerald-500' : 'bg-primary'}`} style={{ width: `${(current / 5) * 100}%` }} />
                                                                        </div>
                                                                    </div>
                                                                );
                                                            })}
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        );
                                    })()}
                                </CardContent>
                            </Card>

                            {/* Probation Reviews */}
                            {probationReviews.length > 0 && (
                                <Card><CardHeader><CardTitle className="text-base">Probation Reviews</CardTitle></CardHeader>
                                    <CardContent>
                                        <div className="relative space-y-4 pl-6 before:absolute before:left-[7px] before:top-2 before:h-[calc(100%-16px)] before:w-0.5 before:bg-muted">
                                            {probationReviews.map(r => {
                                                const dotColor = r.status === 'completed' || r.status === 'passed' ? 'bg-emerald-500' : r.status === 'in_progress' || r.status === 'scheduled' ? 'bg-amber-500' : r.status === 'failed' ? 'bg-red-500' : 'bg-slate-300';
                                                return (
                                                    <div key={r.id} className="relative">
                                                        <div className={`absolute -left-6 top-1.5 h-3.5 w-3.5 rounded-full border-2 border-white ${dotColor}`} />
                                                        <div className="flex items-center justify-between">
                                                            <div>
                                                                <p className="font-medium text-sm">Review #{r.review_number}</p>
                                                                <p className="text-xs text-muted-foreground">{formatDate(r.review_date)} &middot; {r.reviewer_name}</p>
                                                                {r.extension_weeks && <p className="text-xs text-amber-600">Extended by {r.extension_weeks} weeks</p>}
                                                            </div>
                                                            <div className="flex items-center gap-2">
                                                                {r.recommendation && <Badge variant="outline">{formatLabel(r.recommendation)}</Badge>}
                                                                <StatusBadge status={r.status} />
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
                                <Card><CardHeader className="flex flex-row items-center justify-between">
                                    <CardTitle className="text-base">Performance Improvement Plans</CardTitle>
                                    <Link href="/hr/performance/pips" className="text-xs text-primary hover:underline">View All</Link>
                                </CardHeader>
                                    <CardContent className="space-y-4">
                                        {pips.map(pip => (
                                            <div key={pip.id} className="rounded-lg border p-4 space-y-3 cursor-pointer transition-colors hover:border-primary/30" onClick={() => router.visit(`/hr/performance/pips/${pip.id}`)} role="link" tabIndex={0} onKeyDown={e => e.key === 'Enter' && router.visit(`/hr/performance/pips/${pip.id}`)}>
                                                <div className="flex items-center justify-between">
                                                    <div>
                                                        <p className="font-medium">{pip.title}</p>
                                                        {pip.reason && <p className="mt-0.5 text-xs text-muted-foreground line-clamp-1">{pip.reason}</p>}
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        <StatusBadge status={pip.status} />
                                                        <ChevronRight className="h-4 w-4 text-muted-foreground" />
                                                    </div>
                                                </div>
                                                <p className="text-xs text-muted-foreground">{formatDate(pip.start_date)} &ndash; {formatDate(pip.end_date)}</p>
                                                {pip.milestones.length > 0 && (
                                                    <div className="space-y-2 pl-4 border-l-2 border-muted">
                                                        {pip.milestones.map(m => (
                                                            <div key={m.id} className="flex items-center justify-between text-sm">
                                                                <div><p className="font-medium">{m.title}</p><p className="text-xs text-muted-foreground">Due {formatDate(m.due_date)}</p></div>
                                                                <StatusBadge status={m.status} />
                                                            </div>
                                                        ))}
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
                                    <Link href="/hr/training/catalog"><Button variant="outline" size="sm" className="gap-1.5"><BookOpen className="h-3.5 w-3.5" />Course Catalog</Button></Link>
                                </div>
                            )}

                            {/* Summary Stats */}
                            {(() => {
                                const completedCount = courseEnrollments.filter(e => e.status === 'completed').length;
                                const inProgressCount = courseEnrollments.filter(e => e.status === 'enrolled' || e.status === 'in_progress').length;
                                return (
                                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                        <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-3 text-center">
                                            <div className="text-xl font-bold text-primary">{courseEnrollments.length}</div>
                                            <div className="text-[10px] uppercase tracking-wider text-primary">Enrolments</div>
                                        </div>
                                        <div className="rounded-xl border bg-gradient-to-br from-emerald-50 to-green-50 p-3 text-center">
                                            <div className="text-xl font-bold text-emerald-700">{completedCount}</div>
                                            <div className="text-[10px] uppercase tracking-wider text-emerald-500">Completed</div>
                                        </div>
                                        <div className="rounded-xl border bg-gradient-to-br from-blue-50 to-indigo-50 p-3 text-center">
                                            <div className="text-xl font-bold text-blue-700">{inProgressCount}</div>
                                            <div className="text-[10px] uppercase tracking-wider text-blue-500">In Progress</div>
                                        </div>
                                        <div className="rounded-xl border bg-gradient-to-br from-amber-50 to-yellow-50 p-3 text-center">
                                            <div className="text-xl font-bold text-amber-700">{employeeSkills.length}</div>
                                            <div className="text-[10px] uppercase tracking-wider text-amber-500">Skills</div>
                                        </div>
                                    </div>
                                );
                            })()}

                            {/* Course Enrolments */}
                            <Card><CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle className="text-base">Course Enrolments</CardTitle>
                                {courseEnrollments.length > 0 && <Link href="/hr/training/catalog" className="text-xs text-primary hover:underline">View Catalog</Link>}
                            </CardHeader>
                                <CardContent className="p-0">
                                    {courseEnrollments.length === 0 ? <EmptyState icon={BookOpen} label="No course enrolments" /> : (
                                        <table className="w-full text-sm"><thead className="border-b bg-muted/50"><tr>
                                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Course</th>
                                            <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground sm:table-cell">Category</th>
                                            <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground md:table-cell">Enrolled</th>
                                            <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground md:table-cell">Completed</th>
                                            <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground lg:table-cell">Score</th>
                                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Status</th>
                                        </tr></thead><tbody className="divide-y">
                                            {courseEnrollments.map(e => (
                                                <tr key={e.id} className="hover:bg-muted/30">
                                                    <td className="px-4 py-3 font-medium">{e.course_name || '\u2014'}</td>
                                                    <td className="hidden px-4 py-3 sm:table-cell">{e.category ? <Badge variant="outline" className="text-[10px]">{formatLabel(e.category)}</Badge> : '\u2014'}</td>
                                                    <td className="hidden px-4 py-3 text-muted-foreground md:table-cell">{formatDate(e.enrolled_at)}</td>
                                                    <td className="hidden px-4 py-3 text-muted-foreground md:table-cell">{formatDate(e.completed_at)}</td>
                                                    <td className="hidden px-4 py-3 lg:table-cell">{e.score != null ? <span className="font-medium">{e.score}%</span> : '\u2014'}</td>
                                                    <td className="px-4 py-3"><StatusBadge status={e.status} /></td>
                                                </tr>
                                            ))}
                                        </tbody></table>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Skills */}
                            <Card><CardHeader><CardTitle className="text-base">Skills</CardTitle></CardHeader>
                                <CardContent>
                                    {employeeSkills.length === 0 ? <EmptyState icon={Target} label="No skills recorded" /> : (
                                        <div className="flex flex-wrap gap-2">
                                            {employeeSkills.map(s => (
                                                <div key={s.id} className="flex items-center gap-2 rounded-lg border px-3 py-2 transition-colors hover:bg-muted/50">
                                                    <div className="flex h-6 w-6 items-center justify-center rounded-md bg-amber-100"><Target className="h-3 w-3 text-amber-600" /></div>
                                                    <span className="text-sm font-medium">{s.skill_name}</span>
                                                    {s.proficiency_level && <Badge variant="secondary" className="text-[9px]">Lv.{s.proficiency_level}</Badge>}
                                                    {s.self_assessed && <span className="text-[9px] text-muted-foreground">(self)</span>}
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Competency Assessments */}
                            <Card><CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle className="text-base">Competency Assessments</CardTitle>
                                {competencyAssessments.length > 0 && <Link href={`/hr/performance/competencies?employee=${p.id}`} className="text-xs text-primary hover:underline">Full Profile</Link>}
                            </CardHeader>
                                <CardContent>
                                    {competencyAssessments.length === 0 ? <EmptyState icon={Award} label="No competency assessments" /> : (
                                        <div className="space-y-3">
                                            {competencyAssessments.map(a => {
                                                const current = a.proficiency_level ?? 0;
                                                const target = a.target_level ?? 5;
                                                const meetsTarget = current >= target;
                                                return (
                                                    <div key={a.id} className="rounded-lg border p-3 transition-colors hover:bg-muted/30">
                                                        <div className="mb-1.5 flex items-center justify-between text-sm">
                                                            <div className="flex items-center gap-2">
                                                                <span className="font-medium">{a.competency_name}</span>
                                                                {a.category && <Badge variant="outline" className="text-[9px]">{a.category}</Badge>}
                                                            </div>
                                                            <div className="flex items-center gap-2">
                                                                <span className={`text-xs font-medium ${meetsTarget ? 'text-emerald-600' : 'text-amber-600'}`}>{current}/{target}</span>
                                                                <span className="text-[10px] text-muted-foreground">{formatDate(a.assessment_date)}</span>
                                                            </div>
                                                        </div>
                                                        <div className="relative h-2 rounded-full bg-muted">
                                                            <div className="absolute inset-y-0 left-0 rounded-full bg-primary/15" style={{ width: `${(target / 5) * 100}%` }} />
                                                            <div className={`absolute inset-y-0 left-0 rounded-full ${meetsTarget ? 'bg-emerald-500' : 'bg-primary'}`} style={{ width: `${(current / 5) * 100}%` }} />
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
                        <Card>{!driverEligibility ? <CardContent><EmptyState icon={Car} label="No driver eligibility record" /></CardContent> : (
                            <><CardHeader><CardTitle className="flex items-center gap-3 text-base"><Car className="h-5 w-5" />Driver Eligibility<StatusBadge status={driverEligibility.status} /></CardTitle></CardHeader>
                            <CardContent className="divide-y">
                                <InfoRow label="Licence Number" value={driverEligibility.licence_number} />
                                <InfoRow label="Licence Class" value={driverEligibility.licence_class} />
                                {driverEligibility.licence_endorsements?.length ? <InfoRow label="Endorsements" value={driverEligibility.licence_endorsements.join(', ')} /> : null}
                                <InfoRow label="Licence Expires" value={formatDate(driverEligibility.licence_expires_at)} />
                                <InfoRow label="Can Drive Clients" value={driverEligibility.can_drive_clients ? <Badge className="border-emerald-200 bg-emerald-50 text-emerald-700">Yes</Badge> : <Badge variant="outline">No</Badge>} />
                                <InfoRow label="Incident Free Since" value={formatDate(driverEligibility.incident_free_since)} />
                                <InfoRow label="Next Review" value={formatDate(driverEligibility.next_review_at)} />
                            </CardContent></>
                        )}</Card>
                    </TabsContent>

                    {/* ======== VETTING ======== */}
                    <TabsContent value="vetting">
                        <Card><CardContent className="p-0">
                            {backgroundChecks.length === 0 ? <EmptyState icon={Shield} label="No background checks recorded" /> : (
                                <table className="w-full text-sm"><thead className="border-b bg-muted/50"><tr>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Check Type</th>
                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground sm:table-cell">Provider</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Check Date</th>
                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground md:table-cell">Expires</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Status</th>
                                </tr></thead><tbody className="divide-y">
                                    {backgroundChecks.map(c => (
                                        <tr key={c.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3 font-medium">{formatLabel(c.check_type)}</td>
                                            <td className="hidden px-4 py-3 text-muted-foreground sm:table-cell">{c.provider || '\u2014'}</td>
                                            <td className="px-4 py-3 text-muted-foreground">{formatDate(c.check_date)}</td>
                                            <td className="hidden px-4 py-3 text-muted-foreground md:table-cell">{formatDate(c.expires_at)}</td>
                                            <td className="px-4 py-3"><StatusBadge status={c.status} /></td>
                                        </tr>
                                    ))}
                                </tbody></table>
                            )}
                        </CardContent></Card>
                    </TabsContent>

                    {/* ======== COMPLIANCE ======== */}
                    <TabsContent value="compliance">
                        <div className="space-y-6">
                            <div className="flex flex-col items-center gap-6 sm:flex-row">
                                <DonutChart data={[
                                    { value: complianceSummary.compliant, color: '#22c55e' },
                                    { value: complianceSummary.expiring_soon, color: '#f59e0b' },
                                    { value: complianceSummary.expired, color: '#ef4444' },
                                    { value: complianceSummary.not_started, color: '#94a3b8' },
                                ]} />
                                <div className="grid flex-1 grid-cols-2 gap-3 sm:grid-cols-4">
                                    {[
                                        { label: 'Compliant', value: complianceSummary.compliant, cls: 'border-emerald-200 bg-emerald-50 text-emerald-700' },
                                        { label: 'Expiring Soon', value: complianceSummary.expiring_soon, cls: 'border-amber-200 bg-amber-50 text-amber-700' },
                                        { label: 'Expired', value: complianceSummary.expired, cls: 'border-red-200 bg-red-50 text-red-700' },
                                        { label: 'Not Started', value: complianceSummary.not_started, cls: 'border-border bg-muted text-foreground' },
                                    ].map(s => (
                                        <div key={s.label} className={`rounded-lg border p-3 text-center ${s.cls}`}>
                                            <p className="text-2xl font-bold">{s.value}</p><p className="text-xs">{s.label}</p>
                                        </div>
                                    ))}
                                </div>
                            </div>
                            <Card><CardContent className="p-0">
                                {complianceStatuses.length === 0 ? <EmptyState icon={ShieldAlert} label="No compliance requirements" /> : (
                                    <table className="w-full text-sm"><thead className="border-b bg-muted/50"><tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Requirement</th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground sm:table-cell">Type</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Status</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Expiry</th>
                                    </tr></thead><tbody className="divide-y">
                                        {complianceStatuses.map(s => (
                                            <tr key={s.id} className="hover:bg-muted/30">
                                                <td className="px-4 py-3 font-medium">{s.requirement_name}</td>
                                                <td className="hidden px-4 py-3 text-muted-foreground sm:table-cell">{s.requirement_type ? formatLabel(s.requirement_type) : '\u2014'}</td>
                                                <td className="px-4 py-3"><StatusBadge status={s.status} /></td>
                                                <td className="px-4 py-3 text-muted-foreground">{formatDate(s.expiry_date)}</td>
                                            </tr>
                                        ))}
                                    </tbody></table>
                                )}
                            </CardContent></Card>
                            {policyAttestations.length > 0 && (
                                <Card><CardHeader><CardTitle className="text-base">Policy Attestations</CardTitle></CardHeader>
                                    <CardContent className="p-0">
                                        <table className="w-full text-sm"><thead className="border-b bg-muted/50"><tr>
                                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Policy</th>
                                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Attested</th>
                                        </tr></thead><tbody className="divide-y">
                                            {policyAttestations.map(a => (
                                                <tr key={a.id} className="hover:bg-muted/30">
                                                    <td className="px-4 py-3 font-medium">{a.policy_name}</td>
                                                    <td className="px-4 py-3 text-muted-foreground">{formatDate(a.attested_at)}</td>
                                                </tr>
                                            ))}
                                        </tbody></table>
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
                                    {leaveBalances.map(lb => {
                                        const colors: Record<string, string> = { annual: 'bg-blue-500', sick: 'bg-red-500', personal: 'bg-primary', bereavement: 'bg-slate-500', parental: 'bg-pink-500' };
                                        return (
                                            <Card key={lb.id}><CardContent className="pt-4">
                                                <div className="flex items-center justify-between mb-2">
                                                    <p className="text-sm font-medium">{formatLabel(lb.leave_type)}</p>
                                                    <p className={`text-lg font-bold ${lb.balance_hours < 0 ? 'text-red-600' : ''}`}>{lb.balance_hours.toFixed(1)}h</p>
                                                </div>
                                                <ProgressBar value={lb.used_hours} max={lb.accrued_hours} color={colors[lb.leave_type] || 'bg-primary'} />
                                                <div className="mt-1.5 flex justify-between text-xs text-muted-foreground"><span>Used: {lb.used_hours.toFixed(1)}h</span><span>Accrued: {lb.accrued_hours.toFixed(1)}h</span></div>
                                            </CardContent></Card>
                                        );
                                    })}
                                </div>
                            )}
                            <Card><CardHeader><CardTitle className="text-base">Recent Leave Requests</CardTitle></CardHeader>
                                <CardContent className="p-0">
                                    {recentLeaveRequests.length === 0 ? <EmptyState icon={Calendar} label="No leave requests" /> : (
                                        <table className="w-full text-sm"><thead className="border-b bg-muted/50"><tr>
                                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Type</th>
                                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">From</th>
                                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">To</th>
                                            <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground sm:table-cell">Hours</th>
                                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Status</th>
                                        </tr></thead><tbody className="divide-y">
                                            {recentLeaveRequests.map(r => (
                                                <tr key={r.id} className="hover:bg-muted/30">
                                                    <td className="px-4 py-3 font-medium">{formatLabel(r.leave_type)}</td>
                                                    <td className="px-4 py-3 text-muted-foreground">{formatDate(r.starts_at)}</td>
                                                    <td className="px-4 py-3 text-muted-foreground">{formatDate(r.ends_at)}</td>
                                                    <td className="hidden px-4 py-3 sm:table-cell">{r.hours_requested.toFixed(1)}</td>
                                                    <td className="px-4 py-3"><StatusBadge status={r.status} /></td>
                                                </tr>
                                            ))}
                                        </tbody></table>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    {/* ======== ONBOARDING ======== */}
                    <TabsContent value="onboarding">
                        {onboardingChecklists.length === 0 ? <Card><CardContent><EmptyState icon={CheckCircle2} label="No onboarding checklists assigned" /></CardContent></Card> : (
                            <div className="space-y-6">
                                {onboardingChecklists.map(cl => {
                                    const done = cl.tasks.filter(t => t.status === 'completed').length;
                                    const total = cl.tasks.length;
                                    const pct = total > 0 ? Math.round((done / total) * 100) : 0;
                                    return (
                                        <Card key={cl.id}>
                                            <CardHeader>
                                                <div className="flex items-center justify-between"><CardTitle className="text-base">{cl.name}</CardTitle><div className="flex items-center gap-2"><StatusBadge status={cl.status} /><span className="text-sm font-medium">{pct}%</span></div></div>
                                                <ProgressBar value={done} max={total} color={pct === 100 ? 'bg-emerald-500' : 'bg-primary'} />
                                                <div className="flex gap-4 text-xs text-muted-foreground mt-1">
                                                    <span>{done}/{total} tasks</span>
                                                    {cl.due_date && <span>Due: {formatDate(cl.due_date)}</span>}
                                                    {cl.started_at && <span>Started: {formatDate(cl.started_at)}</span>}
                                                </div>
                                            </CardHeader>
                                            <CardContent className="space-y-4">
                                                {onboardingTasksByCategory(cl.tasks).map(([category, tasks]) => (
                                                    <div key={category}>
                                                        <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">{category}</p>
                                                        <div className="space-y-1.5">
                                                            {tasks.map(t => (
                                                                <div key={t.id} className={`flex items-start gap-3 rounded-lg border p-3 ${t.status === 'completed' ? 'bg-emerald-50/50 border-emerald-200 dark:bg-emerald-500/5 dark:border-emerald-500/20' : ''}`}>
                                                                    {t.status === 'completed' ? <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-500" /> : <div className="mt-0.5 h-4 w-4 shrink-0 rounded-full border-2 border-muted-foreground/30" />}
                                                                    <div className="min-w-0 flex-1">
                                                                        <div className="flex items-center gap-2">
                                                                            <p className={`text-sm font-medium ${t.status === 'completed' ? 'line-through text-muted-foreground' : ''}`}>{t.title}</p>
                                                                            {t.is_required && <Badge variant="outline" className="text-[10px] border-red-200 bg-red-50 text-red-600">Required</Badge>}
                                                                            {t.sign_off_required && <Badge variant="outline" className="text-[10px]">Sign-off</Badge>}
                                                                        </div>
                                                                        {t.description && <p className="mt-0.5 text-xs text-muted-foreground">{t.description}</p>}
                                                                        <div className="mt-1 flex gap-3 text-xs text-muted-foreground">
                                                                            {t.assigned_to_role && <span>Assigned: {formatLabel(t.assigned_to_role)}</span>}
                                                                            {t.completed_at && <span>Completed: {formatDate(t.completed_at)}</span>}
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
                        <Card><CardContent className="p-0">
                            {supervisionNotes.length === 0 ? <EmptyState icon={UserCheck} label="No supervision notes" /> : (
                                <div className="divide-y">
                                    {supervisionNotes.map(n => (
                                        <div key={n.id} className="p-4 hover:bg-muted/30">
                                            <div className="flex items-center justify-between mb-2">
                                                <div className="flex items-center gap-2"><p className="font-medium text-sm">{formatDate(n.session_date)}</p>{n.session_type && <Badge variant="outline">{formatLabel(n.session_type)}</Badge>}</div>
                                                <span className="text-xs text-muted-foreground">{n.duration_minutes ? `${n.duration_minutes} min` : ''} &middot; {n.supervisor_name}</span>
                                            </div>
                                            {n.topics_discussed && <p className="text-sm text-muted-foreground mb-2">{n.topics_discussed}</p>}
                                            {n.actions_agreed && n.actions_agreed.length > 0 && (
                                                <div className="mt-2"><p className="text-xs font-semibold text-muted-foreground mb-1">Actions Agreed:</p>
                                                    <ul className="list-disc pl-5 text-xs text-muted-foreground space-y-0.5">{n.actions_agreed.map((a, i) => <li key={i}>{a}</li>)}</ul>
                                                </div>
                                            )}
                                            {n.next_session_date && <p className="mt-2 text-xs text-muted-foreground">Next session: {formatDate(n.next_session_date)}</p>}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent></Card>
                    </TabsContent>

                    {/* ======== CASES ======== */}
                    <TabsContent value="cases">
                        <Card><CardContent className="p-0">
                            {cases.length === 0 ? <EmptyState icon={FolderOpen} label="No cases on record" /> : (
                                <table className="w-full text-sm"><thead className="border-b bg-muted/50"><tr>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Case #</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Type</th>
                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground sm:table-cell">Severity</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Title</th>
                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground md:table-cell">Opened</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Status</th>
                                </tr></thead><tbody className="divide-y">
                                    {cases.map(c => (
                                        <tr key={c.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3 font-mono text-xs">{c.case_number}</td>
                                            <td className="px-4 py-3"><Badge variant="outline">{formatLabel(c.case_type)}</Badge></td>
                                            <td className="hidden px-4 py-3 sm:table-cell"><StatusBadge status={c.severity} /></td>
                                            <td className="px-4 py-3 font-medium">{c.title}</td>
                                            <td className="hidden px-4 py-3 text-muted-foreground md:table-cell">{formatDate(c.opened_at)}</td>
                                            <td className="px-4 py-3"><StatusBadge status={c.status} /></td>
                                        </tr>
                                    ))}
                                </tbody></table>
                            )}
                        </CardContent></Card>
                    </TabsContent>

                    {/* ======== ASSETS ======== */}
                    <TabsContent value="assets">
                        <Card><CardContent className="p-0">
                            {assetAssignments.length === 0 ? <EmptyState icon={Laptop} label="No assets assigned" /> : (
                                <table className="w-full text-sm"><thead className="border-b bg-muted/50"><tr>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Asset</th>
                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground sm:table-cell">Tag</th>
                                    <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground md:table-cell">Category</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Assigned</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Status</th>
                                </tr></thead><tbody className="divide-y">
                                    {assetAssignments.map(a => (
                                        <tr key={a.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3 font-medium">{a.asset_name || '\u2014'}</td>
                                            <td className="hidden px-4 py-3 font-mono text-xs text-muted-foreground sm:table-cell">{a.asset_tag || '\u2014'}</td>
                                            <td className="hidden px-4 py-3 text-muted-foreground md:table-cell">{a.category ? formatLabel(a.category) : '\u2014'}</td>
                                            <td className="px-4 py-3 text-muted-foreground">{formatDate(a.assigned_at)}</td>
                                            <td className="px-4 py-3">{a.returned_at ? <Badge variant="outline" className="border-border bg-muted text-muted-foreground">Returned</Badge> : <Badge variant="outline" className="border-emerald-200 bg-emerald-50 text-emerald-700">Active</Badge>}</td>
                                        </tr>
                                    ))}
                                </tbody></table>
                            )}
                        </CardContent></Card>
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}
