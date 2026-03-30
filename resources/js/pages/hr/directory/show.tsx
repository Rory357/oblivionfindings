import { DonutChart } from '@/components/dashboard/donut-chart';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Textarea } from '@/components/ui/textarea';
import { type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    Award,
    Briefcase,
    Calendar,
    ChevronRight,
    Clock,
    Flame,
    Heart,
    Lightbulb,
    Mail,
    MapPin,
    MessageSquare,
    Phone,
    Rocket,
    Shield,
    Sparkles,
    Star,
    Target,
    Trophy,
    Users,
    UserCheck,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

interface Employee {
    id: number;
    user_id: number;
    name: string;
    full_name: string;
    email: string | null;
    phone: string | null;
    position_title: string | null;
    department: string | null;
    team: string | null;
    site: string | null;
    profile_photo_path: string | null;
    bio: string | null;
    start_date: string | null;
    employment_type: string | null;
    is_first_aider: boolean;
    is_fire_warden: boolean;
}

interface PersonRef {
    id: number;
    name: string;
    position_title: string | null;
    profile_photo_path: string | null;
}

interface KudosItem {
    id: number;
    from_name: string;
    category: string;
    message: string;
    created_at: string;
}

interface GoalItem {
    id: number;
    title: string;
    status: string;
    progress_percent: number;
}

interface Props {
    employee: Employee;
    tenure: { years: number; months: number; display: string } | null;
    manager: PersonRef | null;
    directReports: PersonRef[];
    kudosReceived: KudosItem[];
    kudosCount: number;
    complianceSummary: { compliant: number; expiring_soon: number; expired: number; not_started: number; total: number } | null;
    goals: GoalItem[] | null;
    kudosCategories: Record<string, string>;
    canManage: boolean;
    authUserId: number;
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Directory', href: '/hr/directory' },
    { title: 'Profile', href: '#' },
];

function getInitials(name: string): string {
    return name.split(' ').map((n) => n[0]).join('').toUpperCase().slice(0, 2);
}

function formatDate(d: string | null): string {
    if (!d) return '\u2014';
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'long', year: 'numeric' });
}

function formatEmploymentType(t: string | null): string {
    if (!t) return '\u2014';
    return t.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

const KUDOS_ICONS: Record<string, typeof Star> = {
    teamwork: Users,
    innovation: Lightbulb,
    leadership: Trophy,
    customer_focus: Heart,
    going_above: Rocket,
    other: Star,
};

const KUDOS_COLORS: Record<string, string> = {
    teamwork: 'bg-blue-500/10 text-blue-600 border-blue-500/30',
    innovation: 'bg-amber-500/10 text-amber-600 border-amber-500/30',
    leadership: 'bg-violet-500/10 text-violet-600 border-violet-500/30',
    customer_focus: 'bg-pink-500/10 text-pink-600 border-pink-500/30',
    going_above: 'bg-emerald-500/10 text-emerald-600 border-emerald-500/30',
    other: 'bg-slate-500/10 text-slate-600 border-slate-500/30',
};

const GOAL_STATUS: Record<string, { label: string; color: string }> = {
    not_started: { label: 'Not Started', color: '#94a3b8' },
    in_progress: { label: 'In Progress', color: '#3b82f6' },
    blocked: { label: 'Blocked', color: '#ef4444' },
};

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

export default function DirectoryShow({
    employee,
    tenure,
    manager,
    directReports,
    kudosReceived,
    kudosCount,
    complianceSummary,
    goals,
    kudosCategories,
    canManage,
    authUserId,
}: Props) {
    const [kudosDialogOpen, setKudosDialogOpen] = useState(false);
    const [kudosCategory, setKudosCategory] = useState('teamwork');
    const [kudosMessage, setKudosMessage] = useState('');
    const [kudosSending, setKudosSending] = useState(false);

    const isSelf = authUserId === employee.user_id;
    const complianceRate = complianceSummary && complianceSummary.total > 0
        ? Math.round((complianceSummary.compliant / complianceSummary.total) * 100)
        : null;

    function sendKudos() {
        if (!kudosMessage.trim()) return;
        setKudosSending(true);
        router.post('/hr/feed/kudos', {
            to_user_id: employee.user_id,
            category: kudosCategory,
            message: kudosMessage.trim(),
        }, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(`Kudos sent to ${employee.name}!`);
                setKudosDialogOpen(false);
                setKudosMessage('');
            },
            onError: () => toast.error('Failed to send kudos'),
            onFinish: () => setKudosSending(false),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${employee.name} - Directory`} />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                {/* Back link */}
                <Link href="/hr/directory" className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground w-fit">
                    <ArrowLeft className="h-4 w-4" />
                    Back to Directory
                </Link>

                <div className="grid gap-6 lg:grid-cols-[340px_1fr]">
                    {/* ========== LEFT COLUMN ========== */}
                    <div className="flex flex-col gap-4">
                        {/* Profile Hero */}
                        <Card className="overflow-hidden">
                            <div className="h-24 bg-gradient-to-br from-primary/30 via-primary/15 to-transparent" />
                            <CardContent className="-mt-14 flex flex-col items-center px-5 pb-6 text-center">
                                <Avatar className="h-28 w-28 border-4 border-background shadow-lg">
                                    <AvatarImage src={employee.profile_photo_path ? `/storage/${employee.profile_photo_path}` : undefined} />
                                    <AvatarFallback className="bg-primary/10 text-primary text-3xl font-bold">
                                        {getInitials(employee.name)}
                                    </AvatarFallback>
                                </Avatar>

                                <h1 className="mt-4 text-xl font-bold">{employee.name}</h1>
                                {employee.full_name !== employee.name && (
                                    <p className="text-xs text-muted-foreground">({employee.full_name})</p>
                                )}
                                {employee.position_title && (
                                    <p className="mt-1 text-sm text-muted-foreground">{employee.position_title}</p>
                                )}

                                {/* Badges */}
                                <div className="mt-3 flex flex-wrap justify-center gap-1.5">
                                    {employee.department && (
                                        <Badge variant="secondary" className="text-xs">{employee.department}</Badge>
                                    )}
                                    {employee.team && (
                                        <Badge variant="outline" className="text-xs">{employee.team}</Badge>
                                    )}
                                    {employee.is_first_aider && (
                                        <Badge variant="outline" className="text-xs border-emerald-500/30 text-emerald-600 bg-emerald-500/10">
                                            <Heart className="mr-1 h-3 w-3" /> First Aider
                                        </Badge>
                                    )}
                                    {employee.is_fire_warden && (
                                        <Badge variant="outline" className="text-xs border-orange-500/30 text-orange-600 bg-orange-500/10">
                                            <Flame className="mr-1 h-3 w-3" /> Fire Warden
                                        </Badge>
                                    )}
                                </div>

                                {/* Tenure */}
                                {tenure && (
                                    <div className="mt-4 flex items-center gap-1.5 text-xs text-muted-foreground">
                                        <Clock className="h-3.5 w-3.5" />
                                        {tenure.years > 0 ? `${tenure.years}y ${tenure.months}m` : `${tenure.months} months`} at the organisation
                                    </div>
                                )}

                                {/* Bio */}
                                {employee.bio && (
                                    <p className="mt-4 text-sm leading-relaxed text-muted-foreground border-t pt-4 whitespace-pre-line">
                                        {employee.bio}
                                    </p>
                                )}

                                {/* Send Kudos */}
                                {!isSelf && (
                                    <Button
                                        onClick={() => setKudosDialogOpen(true)}
                                        className="mt-5 w-full gap-2 rounded-full bg-gradient-to-r from-amber-500 to-orange-500 text-white hover:from-amber-600 hover:to-orange-600 shadow-md"
                                    >
                                        <Sparkles className="h-4 w-4" />
                                        Send Kudos
                                    </Button>
                                )}
                            </CardContent>
                        </Card>

                        {/* Reporting Structure */}
                        {(manager || directReports.length > 0) && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-2 text-sm">
                                        <Users className="h-4 w-4" />
                                        Reporting Structure
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    {manager && (
                                        <div>
                                            <p className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground mb-1.5">Reports to</p>
                                            <Link href={`/hr/directory/${manager.id}`} className="flex items-center gap-3 rounded-lg p-2 transition-colors hover:bg-muted/50">
                                                <Avatar className="h-9 w-9">
                                                    <AvatarImage src={manager.profile_photo_path ? `/storage/${manager.profile_photo_path}` : undefined} />
                                                    <AvatarFallback className="bg-primary/10 text-primary text-xs font-semibold">
                                                        {getInitials(manager.name)}
                                                    </AvatarFallback>
                                                </Avatar>
                                                <div className="min-w-0">
                                                    <p className="text-sm font-medium truncate">{manager.name}</p>
                                                    {manager.position_title && (
                                                        <p className="text-[11px] text-muted-foreground truncate">{manager.position_title}</p>
                                                    )}
                                                </div>
                                                <ChevronRight className="ml-auto h-4 w-4 shrink-0 text-muted-foreground/50" />
                                            </Link>
                                        </div>
                                    )}

                                    {directReports.length > 0 && (
                                        <div>
                                            <p className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground mb-1.5">
                                                Direct Reports ({directReports.length})
                                            </p>
                                            <div className="space-y-0.5">
                                                {directReports.map((r) => (
                                                    <Link key={r.id} href={`/hr/directory/${r.id}`} className="flex items-center gap-3 rounded-lg p-2 transition-colors hover:bg-muted/50">
                                                        <Avatar className="h-8 w-8">
                                                            <AvatarImage src={r.profile_photo_path ? `/storage/${r.profile_photo_path}` : undefined} />
                                                            <AvatarFallback className="bg-muted text-xs font-semibold">
                                                                {getInitials(r.name)}
                                                            </AvatarFallback>
                                                        </Avatar>
                                                        <div className="min-w-0">
                                                            <p className="text-sm truncate">{r.name}</p>
                                                            {r.position_title && (
                                                                <p className="text-[11px] text-muted-foreground truncate">{r.position_title}</p>
                                                            )}
                                                        </div>
                                                    </Link>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    {/* ========== RIGHT COLUMN ========== */}
                    <div className="flex flex-col gap-4">
                        {/* Stat Cards */}
                        <div className="grid gap-4 grid-cols-3">
                            <Card className="overflow-hidden">
                                <div className="h-1 bg-blue-500" />
                                <CardContent className="p-4 text-center">
                                    <Clock className="mx-auto h-5 w-5 text-blue-500 mb-1" />
                                    <p className="text-2xl font-bold">
                                        {tenure ? (tenure.years > 0 ? `${tenure.years}.${tenure.months}` : tenure.months) : '\u2014'}
                                    </p>
                                    <p className="text-[11px] text-muted-foreground">{tenure && tenure.years > 0 ? 'Years' : 'Months'}</p>
                                </CardContent>
                            </Card>

                            <Card className="overflow-hidden">
                                <div className="h-1 bg-amber-500" />
                                <CardContent className="p-4 text-center">
                                    <Award className="mx-auto h-5 w-5 text-amber-500 mb-1" />
                                    <p className="text-2xl font-bold">{kudosCount}</p>
                                    <p className="text-[11px] text-muted-foreground">Kudos (30d)</p>
                                </CardContent>
                            </Card>

                            <Card className="overflow-hidden">
                                <div className="h-1 bg-emerald-500" />
                                <CardContent className="p-4 text-center">
                                    <Shield className="mx-auto h-5 w-5 text-emerald-500 mb-1" />
                                    <p className="text-2xl font-bold">{complianceRate != null ? `${complianceRate}%` : '\u2014'}</p>
                                    <p className="text-[11px] text-muted-foreground">Compliance</p>
                                </CardContent>
                            </Card>
                        </div>

                        {/* Contact & Details */}
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm">Contact & Details</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    {employee.email && (
                                        <a href={`mailto:${employee.email}`} className="flex items-center gap-3 rounded-lg p-3 bg-muted/30 hover:bg-muted/60 transition-colors">
                                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-500/10">
                                                <Mail className="h-4 w-4 text-blue-600" />
                                            </div>
                                            <div className="min-w-0">
                                                <p className="text-[10px] text-muted-foreground">Email</p>
                                                <p className="text-sm font-medium truncate">{employee.email}</p>
                                            </div>
                                        </a>
                                    )}
                                    {employee.phone && (
                                        <a href={`tel:${employee.phone}`} className="flex items-center gap-3 rounded-lg p-3 bg-muted/30 hover:bg-muted/60 transition-colors">
                                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-500/10">
                                                <Phone className="h-4 w-4 text-emerald-600" />
                                            </div>
                                            <div>
                                                <p className="text-[10px] text-muted-foreground">Phone</p>
                                                <p className="text-sm font-medium">{employee.phone}</p>
                                            </div>
                                        </a>
                                    )}
                                    {employee.site && (
                                        <div className="flex items-center gap-3 rounded-lg p-3 bg-muted/30">
                                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-500/10">
                                                <MapPin className="h-4 w-4 text-violet-600" />
                                            </div>
                                            <div>
                                                <p className="text-[10px] text-muted-foreground">Site</p>
                                                <p className="text-sm font-medium">{employee.site}</p>
                                            </div>
                                        </div>
                                    )}
                                    <div className="flex items-center gap-3 rounded-lg p-3 bg-muted/30">
                                        <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-amber-500/10">
                                            <Calendar className="h-4 w-4 text-amber-600" />
                                        </div>
                                        <div>
                                            <p className="text-[10px] text-muted-foreground">Start Date</p>
                                            <p className="text-sm font-medium">{formatDate(employee.start_date)}</p>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-3 rounded-lg p-3 bg-muted/30">
                                        <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-cyan-500/10">
                                            <Briefcase className="h-4 w-4 text-cyan-600" />
                                        </div>
                                        <div>
                                            <p className="text-[10px] text-muted-foreground">Employment</p>
                                            <p className="text-sm font-medium">{formatEmploymentType(employee.employment_type)}</p>
                                        </div>
                                    </div>
                                    {employee.department && (
                                        <div className="flex items-center gap-3 rounded-lg p-3 bg-muted/30">
                                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-pink-500/10">
                                                <Users className="h-4 w-4 text-pink-600" />
                                            </div>
                                            <div>
                                                <p className="text-[10px] text-muted-foreground">Department</p>
                                                <p className="text-sm font-medium">{employee.department}</p>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Kudos Received */}
                        <Card>
                            <CardHeader className="pb-2">
                                <div className="flex items-center justify-between">
                                    <CardTitle className="flex items-center gap-2 text-sm">
                                        <Sparkles className="h-4 w-4 text-amber-500" />
                                        Recognition
                                        {kudosReceived.length > 0 && (
                                            <Badge variant="secondary" className="ml-1">{kudosReceived.length}</Badge>
                                        )}
                                    </CardTitle>
                                    {!isSelf && (
                                        <Button variant="ghost" size="sm" onClick={() => setKudosDialogOpen(true)} className="text-xs">
                                            <Sparkles className="mr-1 h-3 w-3" /> Send Kudos
                                        </Button>
                                    )}
                                </div>
                            </CardHeader>
                            <CardContent>
                                {kudosReceived.length > 0 ? (
                                    <div className="space-y-3">
                                        {kudosReceived.map((k) => {
                                            const Icon = KUDOS_ICONS[k.category] ?? Star;
                                            const colorClass = KUDOS_COLORS[k.category] ?? KUDOS_COLORS.other;
                                            return (
                                                <div key={k.id} className="flex items-start gap-3 rounded-lg border p-3">
                                                    <div className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${colorClass.split(' ')[0]}`}>
                                                        <Icon className={`h-4 w-4 ${colorClass.split(' ')[1]}`} />
                                                    </div>
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex items-center gap-2">
                                                            <span className="text-sm font-medium">{k.from_name}</span>
                                                            <Badge variant="outline" className={`text-[10px] ${colorClass}`}>
                                                                {kudosCategories[k.category] ?? k.category}
                                                            </Badge>
                                                        </div>
                                                        <p className="mt-1 text-sm text-muted-foreground line-clamp-2">{k.message}</p>
                                                        <p className="mt-1 text-[10px] text-muted-foreground">{formatDate(k.created_at)}</p>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                ) : (
                                    <div className="flex flex-col items-center gap-2 py-8 text-center">
                                        <Award className="h-8 w-8 text-muted-foreground/30" />
                                        <p className="text-sm text-muted-foreground">No kudos received yet</p>
                                        {!isSelf && (
                                            <Button variant="outline" size="sm" onClick={() => setKudosDialogOpen(true)}>
                                                Be the first to recognise {employee.name.split(' ')[0]}
                                            </Button>
                                        )}
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Compliance (manager-only) */}
                        {canManage && complianceSummary && complianceSummary.total > 0 && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-2 text-sm">
                                        <Shield className="h-4 w-4" />
                                        Compliance Overview
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="flex items-center gap-6">
                                        <DonutChart
                                            data={[
                                                { label: 'Compliant', value: complianceSummary.compliant, color: '#10b981' },
                                                { label: 'Expiring', value: complianceSummary.expiring_soon, color: '#f59e0b' },
                                                { label: 'Expired', value: complianceSummary.expired, color: '#ef4444' },
                                                { label: 'Not Started', value: complianceSummary.not_started, color: '#94a3b8' },
                                            ]}
                                            size={100}
                                            thickness={14}
                                            centerValue={`${complianceRate}%`}
                                        />
                                        <div className="flex-1 space-y-2">
                                            {complianceSummary.compliant > 0 && (
                                                <div className="flex items-center justify-between text-sm">
                                                    <span className="flex items-center gap-2"><span className="h-2.5 w-2.5 rounded-full bg-emerald-500" />Compliant</span>
                                                    <span className="font-medium">{complianceSummary.compliant}</span>
                                                </div>
                                            )}
                                            {complianceSummary.expiring_soon > 0 && (
                                                <div className="flex items-center justify-between text-sm">
                                                    <span className="flex items-center gap-2"><span className="h-2.5 w-2.5 rounded-full bg-amber-500" />Expiring Soon</span>
                                                    <span className="font-medium text-amber-600">{complianceSummary.expiring_soon}</span>
                                                </div>
                                            )}
                                            {complianceSummary.expired > 0 && (
                                                <div className="flex items-center justify-between text-sm">
                                                    <span className="flex items-center gap-2"><span className="h-2.5 w-2.5 rounded-full bg-red-500" />Expired</span>
                                                    <span className="font-medium text-red-600">{complianceSummary.expired}</span>
                                                </div>
                                            )}
                                            {complianceSummary.not_started > 0 && (
                                                <div className="flex items-center justify-between text-sm">
                                                    <span className="flex items-center gap-2"><span className="h-2.5 w-2.5 rounded-full bg-slate-400" />Not Started</span>
                                                    <span className="font-medium">{complianceSummary.not_started}</span>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {/* Goals (manager-only) */}
                        {canManage && goals && goals.length > 0 && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-2 text-sm">
                                        <Target className="h-4 w-4" />
                                        Active Goals
                                        <Badge variant="secondary" className="ml-1">{goals.length}</Badge>
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-3">
                                        {goals.map((g) => {
                                            const statusCfg = GOAL_STATUS[g.status] ?? GOAL_STATUS.not_started;
                                            return (
                                                <div key={g.id} className="rounded-lg border p-3">
                                                    <div className="flex items-center justify-between mb-2">
                                                        <p className="text-sm font-medium">{g.title}</p>
                                                        <Badge variant="outline" className="text-[10px]" style={{ borderColor: statusCfg.color, color: statusCfg.color }}>
                                                            {statusCfg.label}
                                                        </Badge>
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        <Progress value={g.progress_percent} className="h-1.5 flex-1" />
                                                        <span className="text-xs text-muted-foreground w-8 text-right">{g.progress_percent}%</span>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>

                {/* ========== SEND KUDOS DIALOG ========== */}
                <Dialog open={kudosDialogOpen} onOpenChange={setKudosDialogOpen}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2">
                                <Sparkles className="h-5 w-5 text-amber-500" />
                                Send Kudos to {employee.name.split(' ')[0]}
                            </DialogTitle>
                        </DialogHeader>
                        <div className="space-y-4">
                            <div>
                                <Label className="text-xs font-medium">Category</Label>
                                <RadioGroup value={kudosCategory} onValueChange={setKudosCategory} className="mt-2 grid grid-cols-2 gap-2">
                                    {Object.entries(kudosCategories).map(([key, label]) => {
                                        const Icon = KUDOS_ICONS[key] ?? Star;
                                        const isSelected = kudosCategory === key;
                                        return (
                                            <label
                                                key={key}
                                                className={`flex cursor-pointer items-center gap-2 rounded-lg border p-3 text-sm transition-all ${
                                                    isSelected ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'hover:bg-muted/50'
                                                }`}
                                            >
                                                <RadioGroupItem value={key} className="sr-only" />
                                                <Icon className={`h-4 w-4 ${isSelected ? 'text-primary' : 'text-muted-foreground'}`} />
                                                <span className={isSelected ? 'font-medium' : ''}>{label}</span>
                                            </label>
                                        );
                                    })}
                                </RadioGroup>
                            </div>
                            <div>
                                <Label htmlFor="kudos-msg" className="text-xs font-medium">
                                    Message <span className="text-red-500">*</span>
                                </Label>
                                <Textarea
                                    id="kudos-msg"
                                    value={kudosMessage}
                                    onChange={(e) => setKudosMessage(e.target.value)}
                                    placeholder={`Tell ${employee.name.split(' ')[0]} why they're great...`}
                                    rows={3}
                                    className="mt-1"
                                    maxLength={2000}
                                />
                                <p className="mt-1 text-right text-[10px] text-muted-foreground">{kudosMessage.length}/2000</p>
                            </div>
                        </div>
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setKudosDialogOpen(false)}>Cancel</Button>
                            <Button
                                onClick={sendKudos}
                                disabled={!kudosMessage.trim() || kudosSending}
                                className="gap-2 bg-gradient-to-r from-amber-500 to-orange-500 text-white hover:from-amber-600 hover:to-orange-600"
                            >
                                <Sparkles className="h-4 w-4" />
                                {kudosSending ? 'Sending...' : 'Send Kudos'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
