import { BarChart, DonutChart, OpsStatCard } from '@/components/ops-stat-card';
import PageShell from '@/components/page-shell';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    Calendar,
    Download,
    FileText,
    Flag,
    Lock,
    Search,
} from 'lucide-react';
import { useState } from 'react';

const ANY = '__ANY__';

type ClientNote = {
    id: number;
    type: string;
    subject: string | null;
    body: string;
    is_flagged: boolean;
    flagged_reason: string | null;
    is_private: boolean;
    reviewed_at: string | null;
    reviewer: { id: number; name: string } | null;
    created_at: string;
    user: { id: number; name: string } | null;
    client: { id: number; first_name: string; last_name: string } | null;
    shift: { id: number; starts_at: string; ends_at: string } | null;
};

type Props = {
    stats: {
        total: number;
        today: number;
        this_week: number;
        flagged: number;
        shifts_without_notes: number;
    };
    chart_by_type: Record<string, number>;
    chart_daily: Array<{ label: string; value: number }>;
    notes: {
        data: ClientNote[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    clients: Array<{ id: number; first_name: string; last_name: string }>;
    staff: Array<{ id: number; name: string }>;
    filters: {
        q?: string;
        type?: string;
        client_id?: string;
        author_id?: string;
        date_from?: string;
        date_to?: string;
        flagged?: string;
        private?: string;
    };
};

const TYPE_COLORS: Record<string, string> = {
    shift_note: 'border-l-slate-400',
    progress_note: 'border-l-indigo-400',
    handover: 'border-l-blue-400',
    incident: 'border-l-red-400',
    note: 'border-l-emerald-400',
};

const TYPE_BADGE_VARIANTS: Record<string, string> = {
    shift_note: 'default',
    progress_note: 'secondary',
    handover: 'outline',
    incident: 'destructive',
    note: 'outline',
};

const TYPE_LABELS: Record<string, string> = {
    shift_note: 'Shift Note',
    progress_note: 'Progress Note',
    handover: 'Handover',
    incident: 'Incident',
    note: 'General',
};

const DONUT_COLORS: Record<string, string> = {
    shift_note: '#64748b',
    progress_note: '#6366f1',
    handover: '#3b82f6',
    incident: '#ef4444',
    note: '#10b981',
};

function formatRelativeTime(iso: string): string {
    const d = new Date(iso);
    const now = new Date();
    const diff = Math.floor((now.getTime() - d.getTime()) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;
    return d.toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function formatShiftTime(shift: {
    starts_at: string;
    ends_at: string;
}): string {
    const start = new Date(shift.starts_at);
    const end = new Date(shift.ends_at);
    return `${start.toLocaleDateString('en-NZ', { weekday: 'short', day: 'numeric', month: 'short' })}, ${start.toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' })} \u2013 ${end.toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' })}`;
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
    });
}

function getInitials(name: string): string {
    return name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

function ExpandableText({
    text,
    maxLength = 300,
}: {
    text: string;
    maxLength?: number;
}) {
    const [expanded, setExpanded] = useState(false);
    if (!text || text.length <= maxLength)
        return <p className="text-sm whitespace-pre-wrap">{text}</p>;
    return (
        <div>
            <p className="text-sm whitespace-pre-wrap">
                {expanded ? text : text.slice(0, maxLength) + '...'}
            </p>
            <Button
                type="button"
                variant="link"
                size="sm"
                className="mt-1 h-auto p-0 text-xs"
                onClick={() => setExpanded(!expanded)}
            >
                {expanded ? 'Show less' : 'Show more'}
            </Button>
        </div>
    );
}

export default function ShiftNotesIndex({
    stats = {} as any,
    chart_by_type = {} as any,
    chart_daily = [],
    notes = { data: [], links: [], current_page: 1, last_page: 1, total: 0 },
    clients = [],
    staff = [],
    filters = {} as any,
}: Props) {
    const { labels } = usePage().props as any;
    const clientLabel = labels?.['client.singular'] ?? 'Client';

    const updateFilters = (key: string, value: string | null) => {
        router.get(
            '/operations/shift-notes',
            { ...filters, [key]: value },
            { preserveState: true, replace: true },
        );
    };

    const hasActiveFilters =
        filters?.q ||
        filters?.type ||
        filters?.client_id ||
        filters?.author_id ||
        filters?.date_from ||
        filters?.date_to ||
        filters?.flagged ||
        filters?.private;

    const donutSegments = Object.entries(chart_by_type ?? {}).map(
        ([key, value]) => ({
            label: TYPE_LABELS[key] ?? key,
            value: value as number,
            color: DONUT_COLORS[key] ?? '#94a3b8',
        }),
    );

    const cleanFilters: Record<string, string> = {};
    if (filters?.q) cleanFilters.q = filters.q;
    if (filters?.type) cleanFilters.type = filters.type;
    if (filters?.client_id) cleanFilters.client_id = filters.client_id;
    if (filters?.author_id) cleanFilters.author_id = filters.author_id;
    if (filters?.date_from) cleanFilters.date_from = filters.date_from;
    if (filters?.date_to) cleanFilters.date_to = filters.date_to;
    if (filters?.flagged) cleanFilters.flagged = filters.flagged;
    if (filters?.private) cleanFilters.private = filters.private;

    const exportUrl =
        '/operations/shift-notes/export?' +
        new URLSearchParams(cleanFilters).toString();

    return (
        <AppLayout>
            <Head title="Shift Notes" />
            <PageHero variant="compact"
                title="Shift Notes"
                description="Manager dashboard for shift documentation and audit trail."
                backHref="/operations"
            />
            <PageShell>
                {/* Stats Row */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-5">
                    <OpsStatCard
                        label="Total Notes"
                        value={stats?.total ?? 0}
                        icon={FileText}
                        color="indigo"
                    />
                    <OpsStatCard
                        label="Today"
                        value={stats?.today ?? 0}
                        icon={Calendar}
                        color="blue"
                    />
                    <OpsStatCard
                        label="This Week"
                        value={stats?.this_week ?? 0}
                        icon={BarChart3}
                        color="emerald"
                    />
                    <OpsStatCard
                        label="Flagged"
                        value={stats?.flagged ?? 0}
                        icon={Flag}
                        color="amber"
                    />
                    <OpsStatCard
                        label="Shifts w/o Notes"
                        value={stats?.shifts_without_notes ?? 0}
                        icon={AlertTriangle}
                        color="red"
                    />
                </div>

                {/* Charts Row */}
                <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                Notes by Type
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex justify-center pb-4">
                            <DonutChart
                                segments={donutSegments}
                                centerLabel="Total"
                                centerValue={stats?.total ?? 0}
                                size={140}
                            />
                        </CardContent>
                    </Card>
                    <Card className="lg:col-span-2">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                Daily Trend (7 days)
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <BarChart data={chart_daily ?? []} height={120} />
                        </CardContent>
                    </Card>
                </div>

                {/* Filter Bar */}
                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <div className="relative flex-1">
                        <Search className="absolute top-2.5 left-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input
                            placeholder="Search notes..."
                            className="h-9 pl-8 text-sm"
                            defaultValue={filters?.q ?? ''}
                            onChange={(e) =>
                                updateFilters('q', e.target.value || null)
                            }
                        />
                    </div>
                    <Select
                        value={filters?.type ?? ANY}
                        onValueChange={(v) =>
                            updateFilters('type', v === ANY ? null : v)
                        }
                    >
                        <SelectTrigger className="h-9 w-[140px] text-xs">
                            <SelectValue placeholder="Type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Types</SelectItem>
                            <SelectItem value="shift_note">
                                Shift Note
                            </SelectItem>
                            <SelectItem value="progress_note">
                                Progress Note
                            </SelectItem>
                            <SelectItem value="handover">Handover</SelectItem>
                            <SelectItem value="incident">Incident</SelectItem>
                            <SelectItem value="note">General Note</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters?.client_id ?? ANY}
                        onValueChange={(v) =>
                            updateFilters('client_id', v === ANY ? null : v)
                        }
                    >
                        <SelectTrigger className="h-9 w-[160px] text-xs">
                            <SelectValue placeholder={`All ${clientLabel}s`} />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>
                                All {clientLabel}s
                            </SelectItem>
                            {(clients ?? []).map((c) => (
                                <SelectItem key={c.id} value={String(c.id)}>
                                    {c.first_name} {c.last_name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters?.author_id ?? ANY}
                        onValueChange={(v) =>
                            updateFilters('author_id', v === ANY ? null : v)
                        }
                    >
                        <SelectTrigger className="h-9 w-[150px] text-xs">
                            <SelectValue placeholder="All Staff" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Staff</SelectItem>
                            {(staff ?? []).map((s) => (
                                <SelectItem key={s.id} value={String(s.id)}>
                                    {s.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Input
                        type="date"
                        className="h-9 w-[140px] text-xs"
                        value={filters?.date_from ?? ''}
                        onChange={(e) =>
                            updateFilters('date_from', e.target.value || null)
                        }
                    />
                    <Input
                        type="date"
                        className="h-9 w-[140px] text-xs"
                        value={filters?.date_to ?? ''}
                        onChange={(e) =>
                            updateFilters('date_to', e.target.value || null)
                        }
                    />
                    <Button
                        size="sm"
                        variant={filters?.flagged ? 'default' : 'outline'}
                        className="h-9 gap-1 text-xs"
                        onClick={() =>
                            updateFilters(
                                'flagged',
                                filters?.flagged ? null : '1',
                            )
                        }
                    >
                        <Flag className="h-3.5 w-3.5" />
                        Flagged
                    </Button>
                    <Button
                        asChild
                        size="sm"
                        variant="outline"
                        className="h-9 gap-1 text-xs"
                    >
                        <a href={exportUrl}>
                            <Download className="h-3.5 w-3.5" />
                            Export CSV
                        </a>
                    </Button>
                </div>

                {/* Notes List */}
                <div className="mt-4 space-y-2">
                    {(notes?.data ?? []).length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <FileText className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">
                                    No Shift Notes Found
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">
                                    {hasActiveFilters
                                        ? 'Try adjusting your filters.'
                                        : 'Notes will appear here as support workers document their shifts.'}
                                </p>
                            </CardContent>
                        </Card>
                    )}
                    {(notes?.data ?? []).map((note) => {
                        const authorName = note.user?.name ?? 'Unknown';
                        const initials = getInitials(authorName);

                        return (
                            <Card
                                key={note.id}
                                className={`border-l-4 ${TYPE_COLORS[note.type] ?? 'border-l-slate-300'} transition-all hover:shadow-sm`}
                            >
                                <CardContent className="p-4">
                                    {/* Header row */}
                                    <div className="flex items-start justify-between">
                                        <div className="flex items-center gap-3">
                                            <Avatar className="h-8 w-8">
                                                <AvatarFallback className="bg-muted text-xs">
                                                    {initials}
                                                </AvatarFallback>
                                            </Avatar>
                                            <div>
                                                <div className="flex items-center gap-2">
                                                    <span className="text-sm font-semibold">
                                                        {authorName}
                                                    </span>
                                                    <Badge
                                                        variant={
                                                            (TYPE_BADGE_VARIANTS[
                                                                note.type
                                                            ] as any) ??
                                                            'outline'
                                                        }
                                                    >
                                                        {TYPE_LABELS[
                                                            note.type
                                                        ] ?? note.type}
                                                    </Badge>
                                                    {note.is_private && (
                                                        <Badge
                                                            variant="outline"
                                                            className="gap-1"
                                                        >
                                                            <Lock className="h-3 w-3" />{' '}
                                                            Private
                                                        </Badge>
                                                    )}
                                                    {note.is_flagged && (
                                                        <Badge
                                                            variant="destructive"
                                                            className="gap-1"
                                                        >
                                                            <Flag className="h-3 w-3" />{' '}
                                                            Flagged
                                                        </Badge>
                                                    )}
                                                </div>
                                                <div className="flex gap-3 text-xs text-muted-foreground">
                                                    {note.shift && (
                                                        <span>
                                                            Shift:{' '}
                                                            {formatShiftTime(
                                                                note.shift,
                                                            )}
                                                        </span>
                                                    )}
                                                    {note.client && (
                                                        <span>
                                                            {clientLabel}:{' '}
                                                            {
                                                                note.client
                                                                    .first_name
                                                            }{' '}
                                                            {
                                                                note.client
                                                                    .last_name
                                                            }
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                        <span className="text-xs text-muted-foreground">
                                            {formatRelativeTime(
                                                note.created_at,
                                            )}
                                        </span>
                                    </div>

                                    {/* Note body */}
                                    <div className="mt-3">
                                        <ExpandableText
                                            text={note.body}
                                            maxLength={300}
                                        />
                                    </div>

                                    {/* Footer */}
                                    <div className="mt-3 flex items-center justify-between border-t pt-2">
                                        <div className="text-xs text-muted-foreground">
                                            {note.reviewed_at
                                                ? `Reviewed by ${note.reviewer?.name ?? 'Unknown'} on ${formatDate(note.reviewed_at)}`
                                                : ''}
                                        </div>
                                        <div className="flex gap-1">
                                            {!note.reviewed_at && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    className="h-7 text-xs"
                                                    onClick={() =>
                                                        router.patch(
                                                            `/operations/shift-notes/${note.id}/review`,
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Mark Reviewed
                                                </Button>
                                            )}
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                className="h-7 text-xs"
                                                onClick={() =>
                                                    router.patch(
                                                        `/operations/shift-notes/${note.id}/flag`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {note.is_flagged
                                                    ? 'Unflag'
                                                    : 'Flag'}
                                            </Button>
                                            {note.shift && (
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="ghost"
                                                    className="h-7 text-xs"
                                                >
                                                    <Link
                                                        href={`/operations/shifts/${note.shift.id}`}
                                                    >
                                                        View Shift &rarr;
                                                    </Link>
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                {/* Pagination */}
                {(notes?.last_page ?? 1) > 1 && (
                    <div className="mt-4 flex flex-col items-center gap-2">
                        <div className="flex items-center justify-center gap-1">
                            {(notes?.links ?? []).map(
                                (link: any, i: number) => (
                                    <Button
                                        key={i}
                                        size="sm"
                                        variant={
                                            link.active ? 'default' : 'outline'
                                        }
                                        className="h-7 min-w-[28px] px-2 text-xs"
                                        disabled={!link.url}
                                        onClick={() =>
                                            link.url &&
                                            router.get(
                                                link.url,
                                                {},
                                                { preserveState: true },
                                            )
                                        }
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                ),
                            )}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Showing page {notes?.current_page ?? 1} of{' '}
                            {notes?.last_page ?? 1} ({notes?.total ?? 0} notes)
                        </p>
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
