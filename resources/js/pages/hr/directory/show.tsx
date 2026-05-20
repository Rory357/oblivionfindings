import { DonutChart } from '@/components/dashboard/donut-chart';
import { PageHero, type PageHeroBadge, type PageHeroMetaItem } from '@/components/page';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
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
    MessageCircle,
    MessageSquare,
    Phone,
    Rocket,
    Shield,
    Sparkles,
    Star,
    Target,
    Trophy,
    UserCheck,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

function Loader2({ className }: { className?: string }) {
    return (
        <svg
            className={`animate-spin ${className ?? ''}`}
            xmlns="http://www.w3.org/2000/svg"
            width="24"
            height="24"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M21 12a9 9 0 1 1-6.219-8.56" />
        </svg>
    );
}

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
    work_phone: string | null;
    cellphone: string | null;
    personal_email: string | null;
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
    complianceSummary: {
        compliant: number;
        expiring_soon: number;
        expired: number;
        not_started: number;
        total: number;
    } | null;
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
    return name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

function formatDate(d: string | null): string {
    if (!d) return '\u2014';
    return new Date(d).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
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
    teamwork: 'bg-status-info-bg text-status-info border-status-info/30',
    innovation:
        'bg-status-warning-bg text-status-warning border-status-warning/30',
    leadership: 'bg-primary/10 text-primary border-primary/30',
    customer_focus:
        'bg-status-critical-bg text-status-critical border-status-critical/30',
    going_above:
        'bg-status-success-bg text-status-success border-status-success/30',
    other: 'bg-muted-foreground/80/10 text-muted-foreground border-border/30',
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
    const [messageSending, setMessageSending] = useState(false);

    const isSelf = authUserId === employee.user_id;
    const complianceRate =
        complianceSummary && complianceSummary.total > 0
            ? Math.round(
                  (complianceSummary.compliant / complianceSummary.total) * 100,
              )
            : null;

    function startConversation() {
        setMessageSending(true);
        router.post(
            '/operations/messages/create',
            {
                participant_ids: [employee.user_id],
            },
            {
                onSuccess: () => {
                    // Controller redirects back with selected_conversation_id in session
                    router.visit('/operations/messages');
                },
                onError: () => {
                    toast.error('Failed to start conversation');
                    setMessageSending(false);
                },
            },
        );
    }

    function sendKudos() {
        if (!kudosMessage.trim()) return;
        setKudosSending(true);
        router.post(
            '/hr/feed/kudos',
            {
                to_user_id: employee.user_id,
                category: kudosCategory,
                message: kudosMessage.trim(),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(`Kudos sent to ${employee.name}!`);
                    setKudosDialogOpen(false);
                    setKudosMessage('');
                },
                onError: () => toast.error('Failed to send kudos'),
                onFinish: () => setKudosSending(false),
            },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${employee.name} - Directory`} />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                {/* ========== HERO BANNER ========== */}
                {(() => {
                    const heroBadges: PageHeroBadge[] = [];
                    if (employee.department)
                        heroBadges.push({ label: employee.department });
                    if (employee.team) heroBadges.push({ label: employee.team });
                    if (employee.site)
                        heroBadges.push({ label: employee.site, icon: MapPin });
                    if (employee.is_first_aider)
                        heroBadges.push({
                            label: 'First Aider',
                            icon: Heart,
                            tone: 'success',
                        });
                    if (employee.is_fire_warden)
                        heroBadges.push({
                            label: 'Fire Warden',
                            icon: Flame,
                            tone: 'warning',
                        });

                    const heroMeta: PageHeroMetaItem[] = [];
                    if (employee.full_name !== employee.name)
                        heroMeta.push({ label: `(${employee.full_name})` });
                    if (tenure)
                        heroMeta.push({
                            icon: Clock,
                            label: `${
                                tenure.years > 0
                                    ? `${tenure.years} year${tenure.years !== 1 ? 's' : ''} ${tenure.months} month${tenure.months !== 1 ? 's' : ''}`
                                    : `${tenure.months} month${tenure.months !== 1 ? 's' : ''}`
                            } at the organisation`,
                        });

                    const heroStats = [
                        {
                            label: 'Years',
                            value: tenure ? `${tenure.years}.${tenure.months}` : '\u2014',
                        },
                        { label: 'Kudos', value: kudosCount },
                    ];
                    if (complianceRate != null)
                        heroStats.push({ label: 'Compliant', value: `${complianceRate}%` });

                    return (
                        <PageHero
                            backHref="/hr/directory"
                            backLabel="Back to Directory"
                            avatar={{
                                src: employee.profile_photo_path
                                    ? `/storage/${employee.profile_photo_path}`
                                    : undefined,
                                fallback: getInitials(employee.name),
                            }}
                            title={employee.name}
                            description={employee.position_title ?? undefined}
                            meta={heroMeta}
                            badges={heroBadges}
                            stats={heroStats}
                            actions={
                                <>
                                    {!isSelf && (
                                        <Button
                                            onClick={() => setKudosDialogOpen(true)}
                                            size="sm"
                                            className="gap-2 rounded-full bg-white font-semibold text-primary shadow-md hover:bg-primary-foreground/90"
                                        >
                                            <Sparkles className="h-4 w-4" />
                                            Send Kudos
                                        </Button>
                                    )}
                                    {employee.email && (
                                        <Button
                                            asChild
                                            size="sm"
                                            variant="outline"
                                            className="gap-1.5 rounded-full border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                        >
                                            <a href={`mailto:${employee.email}`}>
                                                <Mail className="h-3.5 w-3.5" /> Email
                                            </a>
                                        </Button>
                                    )}
                                    {employee.phone && (
                                        <Button
                                            asChild
                                            size="sm"
                                            variant="outline"
                                            className="gap-1.5 rounded-full border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                        >
                                            <a href={`tel:${employee.phone}`}>
                                                <Phone className="h-3.5 w-3.5" /> Call
                                            </a>
                                        </Button>
                                    )}
                                    {!isSelf && (
                                        <Button
                                            onClick={startConversation}
                                            disabled={messageSending}
                                            size="sm"
                                            variant="outline"
                                            className="gap-1.5 rounded-full border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                        >
                                            {messageSending ? (
                                                <Loader2 className="h-3.5 w-3.5" />
                                            ) : (
                                                <MessageCircle className="h-3.5 w-3.5" />
                                            )}
                                            Message
                                        </Button>
                                    )}
                                </>
                            }
                        />
                    );
                })()}

                {/* Mobile stat cards (hidden on lg) */}
                <div className="grid grid-cols-3 gap-3 lg:hidden">
                    <Card className="overflow-hidden">
                        <div className="h-1 bg-status-info" />
                        <CardContent className="p-3 text-center">
                            <p className="text-xl font-bold">
                                {tenure
                                    ? `${tenure.years}.${tenure.months}`
                                    : '\u2014'}
                            </p>
                            <p className="text-[10px] text-muted-foreground">
                                Years
                            </p>
                        </CardContent>
                    </Card>
                    <Card className="overflow-hidden">
                        <div className="h-1 bg-status-warning" />
                        <CardContent className="p-3 text-center">
                            <p className="text-xl font-bold">{kudosCount}</p>
                            <p className="text-[10px] text-muted-foreground">
                                Kudos (30d)
                            </p>
                        </CardContent>
                    </Card>
                    <Card className="overflow-hidden">
                        <div className="h-1 bg-status-success" />
                        <CardContent className="p-3 text-center">
                            <p className="text-xl font-bold">
                                {complianceRate != null
                                    ? `${complianceRate}%`
                                    : '\u2014'}
                            </p>
                            <p className="text-[10px] text-muted-foreground">
                                Compliance
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
                    {/* ========== MAIN COLUMN ========== */}
                    <div className="order-2 flex flex-col gap-4 lg:order-1">
                        {/* Contact & Details */}
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm">
                                    Contact & Details
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    {employee.email && (
                                        <a
                                            href={`mailto:${employee.email}`}
                                            className="flex items-center gap-3 rounded-lg bg-muted/30 p-3 transition-colors hover:bg-muted/60"
                                        >
                                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-status-info">
                                                <Mail className="h-4 w-4 text-status-info" />
                                            </div>
                                            <div className="min-w-0">
                                                <p className="text-[10px] text-muted-foreground">
                                                    Email
                                                </p>
                                                <p className="truncate text-sm font-medium">
                                                    {employee.email}
                                                </p>
                                            </div>
                                        </a>
                                    )}
                                    {employee.phone && (
                                        <a
                                            href={`tel:${employee.phone}`}
                                            className="flex items-center gap-3 rounded-lg bg-muted/30 p-3 transition-colors hover:bg-muted/60"
                                        >
                                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-status-success">
                                                <Phone className="h-4 w-4 text-status-success" />
                                            </div>
                                            <div>
                                                <p className="text-[10px] text-muted-foreground">
                                                    Phone
                                                </p>
                                                <p className="text-sm font-medium">
                                                    {employee.phone}
                                                </p>
                                            </div>
                                        </a>
                                    )}
                                    {employee.site && (
                                        <div className="flex items-center gap-3 rounded-lg bg-muted/30 p-3">
                                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10">
                                                <MapPin className="h-4 w-4 text-primary" />
                                            </div>
                                            <div>
                                                <p className="text-[10px] text-muted-foreground">
                                                    Site
                                                </p>
                                                <p className="text-sm font-medium">
                                                    {employee.site}
                                                </p>
                                            </div>
                                        </div>
                                    )}
                                    <div className="flex items-center gap-3 rounded-lg bg-muted/30 p-3">
                                        <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-status-warning">
                                            <Calendar className="h-4 w-4 text-status-warning" />
                                        </div>
                                        <div>
                                            <p className="text-[10px] text-muted-foreground">
                                                Start Date
                                            </p>
                                            <p className="text-sm font-medium">
                                                {formatDate(
                                                    employee.start_date,
                                                )}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-3 rounded-lg bg-muted/30 p-3">
                                        <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-status-info">
                                            <Briefcase className="h-4 w-4 text-status-info" />
                                        </div>
                                        <div>
                                            <p className="text-[10px] text-muted-foreground">
                                                Employment
                                            </p>
                                            <p className="text-sm font-medium">
                                                {formatEmploymentType(
                                                    employee.employment_type,
                                                )}
                                            </p>
                                        </div>
                                    </div>
                                    {employee.department && (
                                        <div className="flex items-center gap-3 rounded-lg bg-muted/30 p-3">
                                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-status-critical">
                                                <Users className="h-4 w-4 text-status-critical" />
                                            </div>
                                            <div>
                                                <p className="text-[10px] text-muted-foreground">
                                                    Department
                                                </p>
                                                <p className="text-sm font-medium">
                                                    {employee.department}
                                                </p>
                                            </div>
                                        </div>
                                    )}
                                    {employee.cellphone &&
                                        employee.cellphone !==
                                            employee.phone && (
                                            <a
                                                href={`tel:${employee.cellphone}`}
                                                className="flex items-center gap-3 rounded-lg bg-muted/30 p-3 transition-colors hover:bg-muted/60"
                                            >
                                                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-status-info">
                                                    <Phone className="h-4 w-4 text-status-info" />
                                                </div>
                                                <div>
                                                    <p className="text-[10px] text-muted-foreground">
                                                        Mobile
                                                    </p>
                                                    <p className="text-sm font-medium">
                                                        {employee.cellphone}
                                                    </p>
                                                </div>
                                            </a>
                                        )}
                                    {!isSelf && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={startConversation}
                                            disabled={messageSending}
                                            className="h-auto justify-start gap-3 rounded-lg border border-primary/20 bg-primary/5 p-3 text-left hover:bg-primary/10"
                                        >
                                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10">
                                                {messageSending ? (
                                                    <Loader2 className="h-4 w-4 text-primary" />
                                                ) : (
                                                    <MessageCircle className="h-4 w-4 text-primary" />
                                                )}
                                            </div>
                                            <div>
                                                <p className="text-[10px] text-muted-foreground">
                                                    Direct Message
                                                </p>
                                                <p className="text-sm font-medium text-primary">
                                                    {messageSending
                                                        ? 'Opening...'
                                                        : `Message ${employee.name.split(' ')[0]}`}
                                                </p>
                                            </div>
                                        </Button>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Kudos Received */}
                        <Card>
                            <CardHeader className="pb-2">
                                <div className="flex items-center justify-between">
                                    <CardTitle className="flex items-center gap-2 text-sm">
                                        <Sparkles className="h-4 w-4 text-status-warning" />
                                        Recognition
                                        {kudosReceived.length > 0 && (
                                            <Badge
                                                variant="secondary"
                                                className="ml-1"
                                            >
                                                {kudosReceived.length}
                                            </Badge>
                                        )}
                                    </CardTitle>
                                    {!isSelf && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setKudosDialogOpen(true)
                                            }
                                            className="text-xs"
                                        >
                                            <Sparkles className="mr-1 h-3 w-3" />{' '}
                                            Send Kudos
                                        </Button>
                                    )}
                                </div>
                            </CardHeader>
                            <CardContent>
                                {kudosReceived.length > 0 ? (
                                    <div className="space-y-3">
                                        {kudosReceived.map((k) => {
                                            const Icon =
                                                KUDOS_ICONS[k.category] ?? Star;
                                            const colorClass =
                                                KUDOS_COLORS[k.category] ??
                                                KUDOS_COLORS.other;
                                            return (
                                                <div
                                                    key={k.id}
                                                    className="flex items-start gap-3 rounded-lg border p-3"
                                                >
                                                    <div
                                                        className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${colorClass.split(' ')[0]}`}
                                                    >
                                                        <Icon
                                                            className={`h-4 w-4 ${colorClass.split(' ')[1]}`}
                                                        />
                                                    </div>
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex items-center gap-2">
                                                            <span className="text-sm font-medium">
                                                                {k.from_name}
                                                            </span>
                                                            <Badge
                                                                variant="outline"
                                                                className={`text-[10px] ${colorClass}`}
                                                            >
                                                                {kudosCategories[
                                                                    k.category
                                                                ] ?? k.category}
                                                            </Badge>
                                                        </div>
                                                        <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">
                                                            {k.message}
                                                        </p>
                                                        <p className="mt-1 text-[10px] text-muted-foreground">
                                                            {formatDate(
                                                                k.created_at,
                                                            )}
                                                        </p>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                ) : (
                                    <div className="flex flex-col items-center gap-2 py-8 text-center">
                                        <Award className="h-8 w-8 text-muted-foreground/30" />
                                        <p className="text-sm text-muted-foreground">
                                            No kudos received yet
                                        </p>
                                        {!isSelf && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    setKudosDialogOpen(true)
                                                }
                                            >
                                                Be the first to recognise{' '}
                                                {employee.name.split(' ')[0]}
                                            </Button>
                                        )}
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Compliance (manager-only) */}
                        {canManage &&
                            complianceSummary &&
                            complianceSummary.total > 0 && (
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
                                                    {
                                                        label: 'Compliant',
                                                        value: complianceSummary.compliant,
                                                        color: '#10b981',
                                                    },
                                                    {
                                                        label: 'Expiring',
                                                        value: complianceSummary.expiring_soon,
                                                        color: '#f59e0b',
                                                    },
                                                    {
                                                        label: 'Expired',
                                                        value: complianceSummary.expired,
                                                        color: '#ef4444',
                                                    },
                                                    {
                                                        label: 'Not Started',
                                                        value: complianceSummary.not_started,
                                                        color: '#94a3b8',
                                                    },
                                                ]}
                                                size={100}
                                                thickness={14}
                                                centerValue={`${complianceRate}%`}
                                            />
                                            <div className="flex-1 space-y-2">
                                                {complianceSummary.compliant >
                                                    0 && (
                                                    <div className="flex items-center justify-between text-sm">
                                                        <span className="flex items-center gap-2">
                                                            <span className="h-2.5 w-2.5 rounded-full bg-status-success" />
                                                            Compliant
                                                        </span>
                                                        <span className="font-medium">
                                                            {
                                                                complianceSummary.compliant
                                                            }
                                                        </span>
                                                    </div>
                                                )}
                                                {complianceSummary.expiring_soon >
                                                    0 && (
                                                    <div className="flex items-center justify-between text-sm">
                                                        <span className="flex items-center gap-2">
                                                            <span className="h-2.5 w-2.5 rounded-full bg-status-warning" />
                                                            Expiring Soon
                                                        </span>
                                                        <span className="font-medium text-status-warning">
                                                            {
                                                                complianceSummary.expiring_soon
                                                            }
                                                        </span>
                                                    </div>
                                                )}
                                                {complianceSummary.expired >
                                                    0 && (
                                                    <div className="flex items-center justify-between text-sm">
                                                        <span className="flex items-center gap-2">
                                                            <span className="h-2.5 w-2.5 rounded-full bg-status-critical" />
                                                            Expired
                                                        </span>
                                                        <span className="font-medium text-status-critical">
                                                            {
                                                                complianceSummary.expired
                                                            }
                                                        </span>
                                                    </div>
                                                )}
                                                {complianceSummary.not_started >
                                                    0 && (
                                                    <div className="flex items-center justify-between text-sm">
                                                        <span className="flex items-center gap-2">
                                                            <span className="h-2.5 w-2.5 rounded-full bg-muted" />
                                                            Not Started
                                                        </span>
                                                        <span className="font-medium">
                                                            {
                                                                complianceSummary.not_started
                                                            }
                                                        </span>
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
                                        <Badge
                                            variant="secondary"
                                            className="ml-1"
                                        >
                                            {goals.length}
                                        </Badge>
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-3">
                                        {goals.map((g) => {
                                            const statusCfg =
                                                GOAL_STATUS[g.status] ??
                                                GOAL_STATUS.not_started;
                                            return (
                                                <div
                                                    key={g.id}
                                                    className="rounded-lg border p-3"
                                                >
                                                    <div className="mb-2 flex items-center justify-between">
                                                        <p className="text-sm font-medium">
                                                            {g.title}
                                                        </p>
                                                        <Badge
                                                            variant="outline"
                                                            className="text-[10px]"
                                                            style={{
                                                                borderColor:
                                                                    statusCfg.color,
                                                                color: statusCfg.color,
                                                            }}
                                                        >
                                                            {statusCfg.label}
                                                        </Badge>
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        <Progress
                                                            value={
                                                                g.progress_percent
                                                            }
                                                            className="h-1.5 flex-1"
                                                        />
                                                        <span className="w-8 text-right text-xs text-muted-foreground">
                                                            {g.progress_percent}
                                                            %
                                                        </span>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    {/* ========== RIGHT SIDEBAR ========== */}
                    <div className="order-1 flex flex-col gap-4 lg:order-2">
                        {/* About */}
                        {employee.bio && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-2 text-sm">
                                        <MessageSquare className="h-4 w-4" />
                                        About
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-sm leading-relaxed whitespace-pre-line text-muted-foreground">
                                        {employee.bio}
                                    </p>
                                </CardContent>
                            </Card>
                        )}

                        {/* Employment Details */}
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center gap-2 text-sm">
                                    <Briefcase className="h-4 w-4" />
                                    Employment
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-muted-foreground">
                                        Position
                                    </span>
                                    <span className="font-medium">
                                        {employee.position_title ?? '\u2014'}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-muted-foreground">
                                        Department
                                    </span>
                                    <span className="font-medium">
                                        {employee.department ?? '\u2014'}
                                    </span>
                                </div>
                                {employee.team && (
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="text-muted-foreground">
                                            Team
                                        </span>
                                        <span className="font-medium">
                                            {employee.team}
                                        </span>
                                    </div>
                                )}
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-muted-foreground">
                                        Type
                                    </span>
                                    <span className="font-medium">
                                        {formatEmploymentType(
                                            employee.employment_type,
                                        )}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-muted-foreground">
                                        Start Date
                                    </span>
                                    <span className="font-medium">
                                        {formatDate(employee.start_date)}
                                    </span>
                                </div>
                                {employee.site && (
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="text-muted-foreground">
                                            Site
                                        </span>
                                        <span className="font-medium">
                                            {employee.site}
                                        </span>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Reporting Structure */}
                        {(manager || directReports.length > 0) && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-2 text-sm">
                                        <UserCheck className="h-4 w-4" />
                                        Team
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    {manager && (
                                        <div>
                                            <p className="mb-1.5 text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                                                Reports to
                                            </p>
                                            <Link
                                                href={`/hr/directory/${manager.id}`}
                                                className="flex items-center gap-3 rounded-lg p-2 transition-colors hover:bg-muted/50"
                                            >
                                                <Avatar className="h-9 w-9">
                                                    <AvatarImage
                                                        src={
                                                            manager.profile_photo_path
                                                                ? `/storage/${manager.profile_photo_path}`
                                                                : undefined
                                                        }
                                                    />
                                                    <AvatarFallback className="bg-primary/10 text-xs font-semibold text-primary">
                                                        {getInitials(
                                                            manager.name,
                                                        )}
                                                    </AvatarFallback>
                                                </Avatar>
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-medium">
                                                        {manager.name}
                                                    </p>
                                                    {manager.position_title && (
                                                        <p className="truncate text-[11px] text-muted-foreground">
                                                            {
                                                                manager.position_title
                                                            }
                                                        </p>
                                                    )}
                                                </div>
                                                <ChevronRight className="ml-auto h-4 w-4 shrink-0 text-muted-foreground/50" />
                                            </Link>
                                        </div>
                                    )}

                                    {directReports.length > 0 && (
                                        <div>
                                            <p className="mb-1.5 text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                                                Direct Reports (
                                                {directReports.length})
                                            </p>
                                            <div className="space-y-0.5">
                                                {directReports.map((r) => (
                                                    <Link
                                                        key={r.id}
                                                        href={`/hr/directory/${r.id}`}
                                                        className="flex items-center gap-3 rounded-lg p-2 transition-colors hover:bg-muted/50"
                                                    >
                                                        <Avatar className="h-8 w-8">
                                                            <AvatarImage
                                                                src={
                                                                    r.profile_photo_path
                                                                        ? `/storage/${r.profile_photo_path}`
                                                                        : undefined
                                                                }
                                                            />
                                                            <AvatarFallback className="bg-muted text-xs font-semibold">
                                                                {getInitials(
                                                                    r.name,
                                                                )}
                                                            </AvatarFallback>
                                                        </Avatar>
                                                        <div className="min-w-0">
                                                            <p className="truncate text-sm">
                                                                {r.name}
                                                            </p>
                                                            {r.position_title && (
                                                                <p className="truncate text-[11px] text-muted-foreground">
                                                                    {
                                                                        r.position_title
                                                                    }
                                                                </p>
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
                </div>

                {/* ========== SEND KUDOS DIALOG ========== */}
                <Dialog
                    open={kudosDialogOpen}
                    onOpenChange={setKudosDialogOpen}
                >
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2">
                                <Sparkles className="h-5 w-5 text-status-warning" />
                                Send Kudos to {employee.name.split(' ')[0]}
                            </DialogTitle>
                        </DialogHeader>
                        <div className="space-y-4">
                            <div>
                                <Label className="text-xs font-medium">
                                    Category
                                </Label>
                                <RadioGroup
                                    value={kudosCategory}
                                    onValueChange={setKudosCategory}
                                    className="mt-2 grid grid-cols-2 gap-2"
                                >
                                    {Object.entries(kudosCategories).map(
                                        ([key, label]) => {
                                            const Icon =
                                                KUDOS_ICONS[key] ?? Star;
                                            const isSelected =
                                                kudosCategory === key;
                                            return (
                                                <label
                                                    key={key}
                                                    className={`flex cursor-pointer items-center gap-2 rounded-lg border p-3 text-sm transition-all ${
                                                        isSelected
                                                            ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                                            : 'hover:bg-muted/50'
                                                    }`}
                                                >
                                                    <RadioGroupItem
                                                        value={key}
                                                        className="sr-only"
                                                    />
                                                    <Icon
                                                        className={`h-4 w-4 ${isSelected ? 'text-primary' : 'text-muted-foreground'}`}
                                                    />
                                                    <span
                                                        className={
                                                            isSelected
                                                                ? 'font-medium'
                                                                : ''
                                                        }
                                                    >
                                                        {label}
                                                    </span>
                                                </label>
                                            );
                                        },
                                    )}
                                </RadioGroup>
                            </div>
                            <div>
                                <Label
                                    htmlFor="kudos-msg"
                                    className="text-xs font-medium"
                                >
                                    Message{' '}
                                    <span className="text-status-critical">
                                        *
                                    </span>
                                </Label>
                                <Textarea
                                    id="kudos-msg"
                                    value={kudosMessage}
                                    onChange={(e) =>
                                        setKudosMessage(e.target.value)
                                    }
                                    placeholder={`Tell ${employee.name.split(' ')[0]} why they're great...`}
                                    rows={3}
                                    className="mt-1"
                                    maxLength={2000}
                                />
                                <p className="mt-1 text-right text-[10px] text-muted-foreground">
                                    {kudosMessage.length}/2000
                                </p>
                            </div>
                        </div>
                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => setKudosDialogOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                onClick={sendKudos}
                                disabled={!kudosMessage.trim() || kudosSending}
                                className="gap-2 bg-gradient-to-r from-status-warning to-status-warning text-primary-foreground hover:from-status-warning hover:to-status-warning"
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
