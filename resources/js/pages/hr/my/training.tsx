import { DonutChart } from '@/components/dashboard/donut-chart';
import { MyHrShell, type MyHrShellData } from '@/components/hr';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    BookOpen,
    Calendar,
    CheckCircle2,
    Clock,
    FileCheck,
    Filter,
    Shield,
    ShieldAlert,
    ShieldCheck,
    ShieldX,
    XCircle,
} from 'lucide-react';
import { useMemo, useState } from 'react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

interface ComplianceStatus {
    id: number;
    status: 'compliant' | 'expiring_soon' | 'expired' | 'not_started';
    expiry_date: string | null;
    completed_at: string | null;
    days_until_expiry: number | null;
    evidence_type: string | null;
    requirement: {
        id: number;
        name: string;
        category: string;
        description: string | null;
        validity_months: number | null;
        check_type: string | null;
    };
}

interface TrainingAssignment {
    id: number;
    course_title: string;
    course_category: string | null;
    delivery_method: string | null;
    due_date: string | null;
    status: string;
    overdue: boolean;
    assigned_at: string | null;
}

interface TrainingEnrolment {
    id: number;
    course_title: string;
    course_category: string | null;
    delivery_method: string | null;
    session_date: string | null;
    enrolled_at: string | null;
}

interface Props {
    myHr: MyHrShellData;
    assignments: TrainingAssignment[];
    enrolments: TrainingEnrolment[];
    complianceStatuses: ComplianceStatus[];
    can: { viewCatalog: boolean };
}

/* ------------------------------------------------------------------ */
/*  Config                                                             */
/* ------------------------------------------------------------------ */

const STATUS_CONFIG = {
    compliant: {
        label: 'Compliant',
        color: 'var(--status-success)',
        bg: 'bg-status-success',
        text: 'text-status-success dark:text-status-success',
        border: 'border-status-success/30',
        icon: ShieldCheck,
    },
    expiring_soon: {
        label: 'Expiring Soon',
        color: 'var(--status-warning)',
        bg: 'bg-status-warning',
        text: 'text-status-warning dark:text-status-warning',
        border: 'border-status-warning/30',
        icon: AlertTriangle,
    },
    expired: {
        label: 'Expired',
        color: 'var(--status-critical)',
        bg: 'bg-status-critical',
        text: 'text-status-critical dark:text-status-critical',
        border: 'border-status-critical/30',
        icon: ShieldX,
    },
    not_started: {
        label: 'Not Started',
        color: 'var(--muted-foreground)',
        bg: 'bg-muted-foreground/10',
        text: 'text-muted-foreground',
        border: 'border-border/30',
        icon: Clock,
    },
} as const;

type StatusKey = keyof typeof STATUS_CONFIG;

function formatDate(dateStr: string | null): string {
    if (!dateStr) return '\u2014';
    return new Date(dateStr).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function formatCategory(cat: string): string {
    return cat.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

export default function MyTraining({
    myHr,
    assignments = [],
    enrolments = [],
    complianceStatuses,
    can,
}: Props) {
    const [activeFilter, setActiveFilter] = useState<StatusKey | 'all'>('all');

    const summary = useMemo(() => {
        const counts = {
            compliant: 0,
            expiring_soon: 0,
            expired: 0,
            not_started: 0,
        };
        complianceStatuses.forEach((cs) => {
            if (cs.status in counts) counts[cs.status as StatusKey]++;
        });
        return counts;
    }, [complianceStatuses]);

    const total = complianceStatuses.length;
    const complianceRate =
        total > 0 ? Math.round((summary.compliant / total) * 100) : 0;

    const categories = useMemo(() => {
        const cats = new Set<string>();
        complianceStatuses.forEach((cs) => cats.add(cs.requirement.category));
        return Array.from(cats).sort();
    }, [complianceStatuses]);

    const filtered = useMemo(() => {
        if (activeFilter === 'all') return complianceStatuses;
        return complianceStatuses.filter((cs) => cs.status === activeFilter);
    }, [complianceStatuses, activeFilter]);

    // Urgency items (expired + expiring)
    const urgentItems = complianceStatuses.filter(
        (cs) => cs.status === 'expired' || cs.status === 'expiring_soon',
    );

    return (
        <MyHrShell active="training" myHr={myHr} title="Training · My HR">
            {can.viewCatalog ? (
                <div className="flex justify-end">
                    <Link
                        href="/hr/training/catalog"
                        className="inline-flex items-center gap-1.5 rounded-[10px] border border-border bg-card px-4 py-2 text-[13px] font-semibold text-primary transition-colors hover:bg-accent"
                    >
                        <BookOpen className="h-4 w-4" />
                        Browse training courses
                    </Link>
                </div>
            ) : null}
            {/* Active training — assigned courses + in-progress enrolments */}
            {(assignments.length > 0 || enrolments.length > 0) && (
                <div>
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="flex items-center gap-2 text-base font-semibold">
                            <BookOpen className="h-4 w-4" />
                            My training to complete
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            {assignments.length + enrolments.length} item
                            {assignments.length + enrolments.length !== 1
                                ? 's'
                                : ''}
                        </p>
                    </div>
                    <div className="space-y-3">
                        {assignments.map((a) => (
                            <Card
                                key={`assignment-${a.id}`}
                                className="overflow-hidden transition-all hover:shadow-sm"
                            >
                                <div
                                    className="h-0.5"
                                    style={{
                                        backgroundColor: a.overdue
                                            ? 'var(--status-critical)'
                                            : 'var(--status-info)',
                                    }}
                                />
                                <CardContent className="p-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <h3 className="text-sm font-semibold">
                                                {a.course_title}
                                            </h3>
                                            <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                                {a.course_category && (
                                                    <span className="flex items-center gap-1">
                                                        <Filter className="h-3 w-3" />
                                                        {formatCategory(
                                                            a.course_category,
                                                        )}
                                                    </span>
                                                )}
                                                {a.assigned_at && (
                                                    <span className="flex items-center gap-1">
                                                        <Clock className="h-3 w-3" />
                                                        Assigned{' '}
                                                        {formatDate(
                                                            a.assigned_at,
                                                        )}
                                                    </span>
                                                )}
                                                {a.due_date && (
                                                    <span
                                                        className={`flex items-center gap-1 ${
                                                            a.overdue
                                                                ? 'font-medium text-status-critical'
                                                                : ''
                                                        }`}
                                                    >
                                                        <Calendar className="h-3 w-3" />
                                                        {a.overdue
                                                            ? `Was due ${formatDate(a.due_date)}`
                                                            : `Due ${formatDate(a.due_date)}`}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                        <Badge
                                            variant="outline"
                                            className={
                                                a.overdue
                                                    ? 'shrink-0 border-status-critical/30 bg-status-critical-bg text-status-critical'
                                                    : 'shrink-0 border-status-info/30 bg-status-info-bg text-status-info'
                                            }
                                        >
                                            {a.overdue
                                                ? 'Overdue'
                                                : a.status === 'in_progress'
                                                  ? 'In progress'
                                                  : 'Assigned'}
                                        </Badge>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                        {enrolments.map((e) => (
                            <Card
                                key={`enrolment-${e.id}`}
                                className="overflow-hidden transition-all hover:shadow-sm"
                            >
                                <div
                                    className="h-0.5"
                                    style={{ backgroundColor: 'var(--primary)' }}
                                />
                                <CardContent className="p-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <h3 className="text-sm font-semibold">
                                                {e.course_title}
                                            </h3>
                                            <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                                {e.course_category && (
                                                    <span className="flex items-center gap-1">
                                                        <Filter className="h-3 w-3" />
                                                        {formatCategory(
                                                            e.course_category,
                                                        )}
                                                    </span>
                                                )}
                                                {e.enrolled_at && (
                                                    <span className="flex items-center gap-1">
                                                        <Clock className="h-3 w-3" />
                                                        Enrolled{' '}
                                                        {formatDate(
                                                            e.enrolled_at,
                                                        )}
                                                    </span>
                                                )}
                                                {e.session_date && (
                                                    <span className="flex items-center gap-1">
                                                        <Calendar className="h-3 w-3" />
                                                        Session{' '}
                                                        {formatDate(
                                                            e.session_date,
                                                        )}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                        <Badge
                                            variant="outline"
                                            className="shrink-0 border-primary/30 bg-primary/10 text-primary"
                                        >
                                            Enrolled
                                        </Badge>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                </div>
            )}

            {/* Urgency Banner */}
                {urgentItems.length > 0 && (
                    <div className="rounded-xl border border-status-critical/30 bg-status-critical-bg p-4 dark:border-status-critical/50">
                        <div className="flex items-start gap-3">
                            <ShieldAlert className="mt-0.5 h-5 w-5 shrink-0 text-status-critical" />
                            <div>
                                <p className="font-semibold text-status-critical dark:text-status-critical">
                                    {urgentItems.length} item
                                    {urgentItems.length !== 1 ? 's' : ''} need
                                    {urgentItems.length === 1 ? 's' : ''} your
                                    attention
                                </p>
                                <p className="mt-0.5 text-sm text-status-critical dark:text-status-critical">
                                    {summary.expired > 0 &&
                                        `${summary.expired} expired`}
                                    {summary.expired > 0 &&
                                        summary.expiring_soon > 0 &&
                                        ' and '}
                                    {summary.expiring_soon > 0 &&
                                        `${summary.expiring_soon} expiring soon`}
                                    . Please complete these as soon as possible.
                                </p>
                            </div>
                        </div>
                    </div>
                )}

                {/* Top Row: Compliance Score + Donut + Summary Cards */}
                <div className="grid gap-4 lg:grid-cols-[1fr_2fr]">
                    {/* Left: Compliance Score + Donut */}
                    <Card>
                        <CardContent className="flex flex-col items-center gap-4 pt-6">
                            {total > 0 ? (
                                <>
                                    <DonutChart
                                        data={[
                                            {
                                                label: 'Compliant',
                                                value: summary.compliant,
                                                color: 'var(--status-success)',
                                            },
                                            {
                                                label: 'Expiring',
                                                value: summary.expiring_soon,
                                                color: 'var(--status-warning)',
                                            },
                                            {
                                                label: 'Expired',
                                                value: summary.expired,
                                                color: 'var(--status-critical)',
                                            },
                                            {
                                                label: 'Not Started',
                                                value: summary.not_started,
                                                color: 'var(--muted-foreground)',
                                            },
                                        ]}
                                        size={160}
                                        thickness={22}
                                        centerValue={`${complianceRate}%`}
                                        centerLabel="compliant"
                                    />
                                    <div className="text-center">
                                        <p className="text-sm text-muted-foreground">
                                            {summary.compliant} of {total}{' '}
                                            requirements met
                                        </p>
                                    </div>
                                </>
                            ) : (
                                <div className="flex flex-col items-center gap-3 py-6">
                                    <Shield className="h-12 w-12 text-muted-foreground/30" />
                                    <p className="text-sm text-muted-foreground">
                                        No compliance items assigned
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Right: Status Cards */}
                    <div className="grid gap-3 sm:grid-cols-2">
                        {(Object.keys(STATUS_CONFIG) as StatusKey[]).map(
                            (key) => {
                                const config = STATUS_CONFIG[key];
                                const Icon = config.icon;
                                const count = summary[key] || 0;
                                const isActive = activeFilter === key;

                                return (
                                    <Card
                                        key={key}
                                        role="button"
                                        tabIndex={0}
                                        onClick={() =>
                                            setActiveFilter(
                                                isActive ? 'all' : key,
                                            )
                                        }
                                        onKeyDown={(event) => {
                                            if (
                                                event.key === 'Enter' ||
                                                event.key === ' '
                                            ) {
                                                event.preventDefault();
                                                setActiveFilter(
                                                    isActive ? 'all' : key,
                                                );
                                            }
                                        }}
                                        className={`group relative overflow-hidden p-4 text-left transition-all hover:shadow-md ${
                                            isActive
                                                ? `${config.border} ring-2 ring-offset-2`
                                                : 'hover:border-primary/30'
                                        }`}
                                        style={
                                            isActive
                                                ? {
                                                      borderColor: config.color,
                                                      boxShadow: `0 0 0 2px ${config.color}`,
                                                  }
                                                : undefined
                                        }
                                    >
                                        <div className="flex items-center justify-between">
                                            <div>
                                                <p className="text-xs font-medium text-muted-foreground">
                                                    {config.label}
                                                </p>
                                                <p
                                                    className="mt-1 text-3xl font-bold"
                                                    style={{
                                                        color: config.color,
                                                    }}
                                                >
                                                    {count}
                                                </p>
                                            </div>
                                            <div
                                                className={`flex h-11 w-11 items-center justify-center rounded-xl ${config.bg} transition-transform group-hover:scale-110`}
                                            >
                                                <Icon
                                                    className={`h-5 w-5 ${config.text}`}
                                                />
                                            </div>
                                        </div>
                                        {total > 0 && (
                                            <div className="mt-3 h-1 w-full overflow-hidden rounded-full bg-muted/40">
                                                <div
                                                    className="h-full rounded-full transition-all duration-500"
                                                    style={{
                                                        width: `${(count / total) * 100}%`,
                                                        backgroundColor:
                                                            config.color,
                                                    }}
                                                />
                                            </div>
                                        )}
                                        {isActive && (
                                            <p className="mt-2 text-[10px] text-muted-foreground">
                                                Click to clear filter
                                            </p>
                                        )}
                                    </Card>
                                );
                            },
                        )}
                    </div>
                </div>

                {/* Category Progress */}
                {categories.length > 0 && (
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <BookOpen className="h-4 w-4" />
                                Compliance by Category
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-4">
                                {categories.map((cat) => {
                                    const items = complianceStatuses.filter(
                                        (cs) => cs.requirement.category === cat,
                                    );
                                    const compliantCount = items.filter(
                                        (cs) => cs.status === 'compliant',
                                    ).length;
                                    const pct =
                                        items.length > 0
                                            ? Math.round(
                                                  (compliantCount /
                                                      items.length) *
                                                      100,
                                              )
                                            : 0;

                                    return (
                                        <div key={cat}>
                                            <div className="mb-1.5 flex items-center justify-between text-sm">
                                                <span className="font-medium">
                                                    {formatCategory(cat)}
                                                </span>
                                                <span className="text-muted-foreground">
                                                    {compliantCount}/
                                                    {items.length} complete
                                                </span>
                                            </div>
                                            <div className="flex items-center gap-3">
                                                <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted/40">
                                                    <div
                                                        className="h-full rounded-full transition-all duration-700"
                                                        style={{
                                                            width: `${pct}%`,
                                                            backgroundColor:
                                                                pct === 100
                                                                    ? 'var(--status-success)'
                                                                    : pct >= 50
                                                                      ? 'var(--status-warning)'
                                                                      : 'var(--status-critical)',
                                                        }}
                                                    />
                                                </div>
                                                <span className="w-10 text-right text-xs font-semibold">
                                                    {pct}%
                                                </span>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Requirements List */}
                <div>
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="flex items-center gap-2 text-base font-semibold">
                            <FileCheck className="h-4 w-4" />
                            Requirements
                            {activeFilter !== 'all' && (
                                <Badge variant="secondary" className="ml-1">
                                    {STATUS_CONFIG[activeFilter].label}
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Clear filter"
                                        onClick={() => setActiveFilter('all')}
                                        className="ml-1.5 h-4 w-4 p-0"
                                    >
                                        <XCircle className="h-3 w-3" />
                                    </Button>
                                </Badge>
                            )}
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            {filtered.length} item
                            {filtered.length !== 1 ? 's' : ''}
                        </p>
                    </div>

                    {filtered.length > 0 ? (
                        <div className="space-y-3">
                            {filtered.map((cs) => {
                                const config =
                                    STATUS_CONFIG[cs.status] ??
                                    STATUS_CONFIG.not_started;
                                const Icon = config.icon;

                                return (
                                    <Card
                                        key={cs.id}
                                        className="overflow-hidden transition-all hover:shadow-sm"
                                    >
                                        <div
                                            className="h-0.5"
                                            style={{
                                                backgroundColor: config.color,
                                            }}
                                        />
                                        <CardContent className="p-4">
                                            <div className="flex items-start gap-4">
                                                {/* Status icon */}
                                                <div
                                                    className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${config.bg}`}
                                                >
                                                    <Icon
                                                        className={`h-5 w-5 ${config.text}`}
                                                    />
                                                </div>

                                                {/* Content */}
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-start justify-between gap-2">
                                                        <div>
                                                            <h3 className="text-sm font-semibold">
                                                                {
                                                                    cs
                                                                        .requirement
                                                                        .name
                                                                }
                                                            </h3>
                                                            {cs.requirement
                                                                .description && (
                                                                <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">
                                                                    {
                                                                        cs
                                                                            .requirement
                                                                            .description
                                                                    }
                                                                </p>
                                                            )}
                                                        </div>
                                                        <Badge
                                                            variant="outline"
                                                            className={`shrink-0 ${config.border} ${config.text} ${config.bg}`}
                                                        >
                                                            {config.label}
                                                        </Badge>
                                                    </div>

                                                    {/* Meta row */}
                                                    <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-muted-foreground">
                                                        <span className="flex items-center gap-1">
                                                            <Filter className="h-3 w-3" />
                                                            {formatCategory(
                                                                cs.requirement
                                                                    .category,
                                                            )}
                                                        </span>

                                                        {cs.completed_at && (
                                                            <span className="flex items-center gap-1">
                                                                <CheckCircle2 className="h-3 w-3 text-status-success" />
                                                                Completed{' '}
                                                                {formatDate(
                                                                    cs.completed_at,
                                                                )}
                                                            </span>
                                                        )}

                                                        {cs.expiry_date && (
                                                            <span
                                                                className={`flex items-center gap-1 ${
                                                                    cs.status ===
                                                                    'expired'
                                                                        ? 'font-medium text-status-critical'
                                                                        : cs.status ===
                                                                            'expiring_soon'
                                                                          ? 'font-medium text-status-warning'
                                                                          : ''
                                                                }`}
                                                            >
                                                                <Calendar className="h-3 w-3" />
                                                                {cs.status ===
                                                                'expired'
                                                                    ? `Expired ${formatDate(cs.expiry_date)}`
                                                                    : cs.status ===
                                                                            'expiring_soon' &&
                                                                        cs.days_until_expiry !=
                                                                            null
                                                                      ? `Expires in ${cs.days_until_expiry} day${cs.days_until_expiry !== 1 ? 's' : ''}`
                                                                      : `Expires ${formatDate(cs.expiry_date)}`}
                                                            </span>
                                                        )}

                                                        {cs.requirement
                                                            .validity_months && (
                                                            <span className="flex items-center gap-1">
                                                                <Clock className="h-3 w-3" />
                                                                Valid for{' '}
                                                                {
                                                                    cs
                                                                        .requirement
                                                                        .validity_months
                                                                }{' '}
                                                                months
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>
                    ) : (
                        <Card>
                            <CardContent className="flex flex-col items-center gap-3 py-12">
                                <Shield className="h-10 w-10 text-muted-foreground/30" />
                                <div className="text-center">
                                    <p className="font-medium">
                                        {activeFilter !== 'all'
                                            ? `No ${STATUS_CONFIG[activeFilter].label.toLowerCase()} requirements`
                                            : 'No compliance requirements assigned'}
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {activeFilter !== 'all'
                                            ? 'Try clearing the filter to see all items'
                                            : 'Requirements will appear here once assigned by your manager'}
                                    </p>
                                </div>
                                {activeFilter !== 'all' && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setActiveFilter('all')}
                                    >
                                        Clear Filter
                                    </Button>
                                )}
                            </CardContent>
                        </Card>
                    )}
                </div>
        </MyHrShell>
    );
}
