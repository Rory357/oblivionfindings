/* eslint-disable no-restricted-syntax -- Overview board per the redesign
 * handoff (tabs-core.jsx OverviewTab): About tiles, goal progress rows and
 * tone tiles are styled-native surfaces on semantic tokens. */
/* Design composition for the Overview tab: LEFT — About / Recent daily notes /
 * Goals path; RIGHT — Care snapshot ring / Risk register donut / Medication /
 * Upcoming / Support team. All bound to real page props; the legacy depth
 * widgets (house coverage, health summary, behaviour insights…) render below
 * this grid in show.tsx so nothing is lost. */
import {
    DailyNoteEntry,
    type ClientDailyNote,
} from '@/components/daily-note-entry';
import { DonutChart } from '@/components/ops-stat-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Ring } from '@/components/wizard/primitives';
import {
    AlertOctagon,
    Calendar,
    CalendarClock,
    Check,
    CheckCircle2,
    ChevronRight,
    Circle,
    CircleAlert,
    ClipboardList,
    Flag,
    HandHeart,
    Heart,
    MessageSquare,
    Pencil,
    Pill,
    ShieldAlert,
    Sparkles,
    Stethoscope,
    Target,
    UserPlus,
    Users,
} from 'lucide-react';
import type { ComponentType, ReactNode } from 'react';

type IconType = ComponentType<{ className?: string }>;

const TONE_TILE: Record<string, string> = {
    info: 'bg-status-info-bg text-status-info',
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    neutral: 'bg-muted text-muted-foreground',
};

function CardHead({
    icon: Icon,
    title,
    sub,
    action,
}: {
    icon: IconType;
    title: string;
    sub?: string;
    action?: ReactNode;
}) {
    return (
        <div className="flex items-start justify-between gap-3 px-5 pt-4">
            <div className="flex min-w-0 items-center gap-2.5">
                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-accent text-primary">
                    <Icon className="h-4 w-4" />
                </span>
                <div className="min-w-0">
                    <h3 className="truncate text-[15px] leading-tight font-semibold">
                        {title}
                    </h3>
                    {sub ? (
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {sub}
                        </p>
                    ) : null}
                </div>
            </div>
            {action}
        </div>
    );
}

function GhostLink({
    children,
    onClick,
}: {
    children: ReactNode;
    onClick: () => void;
}) {
    return (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={onClick}
            className="text-muted-foreground hover:text-foreground"
        >
            {children}
            <ChevronRight className="ml-0.5 h-3.5 w-3.5" />
        </Button>
    );
}

export type OverviewGoal = {
    id: number;
    title?: string | null;
    status?: string | null;
    progress_percentage?: number | null;
    category?: string | null;
};

export type OverviewRisk = {
    id: number;
    label?: string | null;
    severity?: string | null;
    review_date?: string | null;
    active?: boolean;
};

export type OverviewEvent = {
    id: string | number;
    title?: string | null;
    start?: string | null;
    extendedProps?: { type?: string | null } | null;
};

export type OverviewTeamMember = {
    id: number;
    name?: string | null;
};

export function OverviewDesignGrid({
    preferredName,
    aboutTiles,
    notes,
    goals,
    risks,
    activePlan,
    reviewDays,
    emarSummary,
    events,
    team,
    keyWorkerId,
    keyWorkerName,
    riskLevelControl,
    canEdit,
    onTab,
    onEditAbout,
    onRecordDose,
    onManageWorkers,
}: {
    preferredName: string;
    aboutTiles: { key: string; icon: IconType; title: string; body: string; tone: string }[];
    notes: ClientDailyNote[];
    goals: OverviewGoal[];
    risks: OverviewRisk[];
    activePlan: { title?: string | null; reviewed_at?: string | null; next_review_at?: string | null } | null;
    reviewDays: number | null;
    emarSummary: {
        active_medications_count?: number;
        pending_alerts_count?: number;
        next_review_date?: string | null;
    } | null;
    events: OverviewEvent[];
    team: OverviewTeamMember[];
    keyWorkerId: number | null;
    keyWorkerName: string | null;
    /** The existing quick-update risk-level Select — preserved feature. */
    riskLevelControl?: ReactNode;
    canEdit: boolean;
    onTab: (key: string) => void;
    onEditAbout: () => void;
    onRecordDose: () => void;
    onManageWorkers: () => void;
}) {
    const goalsDone = goals.filter((g) => g.status === 'completed').length;
    const goalsPct = goals.length
        ? Math.round((goalsDone / goals.length) * 100)
        : 0;

    const activeRisks = risks.filter((r) => r.active !== false);
    const riskCount = (sev: string) =>
        activeRisks.filter((r) => (r.severity ?? '').toLowerCase() === sev)
            .length;
    const riskSegments = [
        { label: 'Critical', value: riskCount('critical'), color: 'var(--status-critical)' },
        { label: 'High', value: riskCount('high'), color: 'oklch(0.55 0.18 50)' },
        { label: 'Medium', value: riskCount('medium'), color: 'var(--status-warning)' },
        { label: 'Low', value: riskCount('low'), color: 'var(--status-success)' },
    ].filter((s) => s.value > 0);
    const overdueRisk = activeRisks.find(
        (r) => r.review_date && new Date(r.review_date).getTime() < Date.now(),
    );

    const upcoming = events
        .filter((e) => e.start && new Date(e.start).getTime() >= Date.now())
        .sort(
            (a, b) =>
                new Date(a.start ?? 0).getTime() -
                new Date(b.start ?? 0).getTime(),
        )
        .slice(0, 3);

    return (
        <div className="grid grid-cols-12 gap-4">
            {/* ── LEFT column ── */}
            <div className="col-span-12 space-y-4 lg:col-span-8">
                {/* About */}
                <Card className="gap-0 py-0">
                    <CardHead
                        icon={Heart}
                        title={`About ${preferredName}`}
                        sub="Person-centred summary"
                        action={
                            canEdit ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={onEditAbout}
                                    className="text-muted-foreground hover:text-foreground"
                                >
                                    <Pencil className="mr-1 h-3.5 w-3.5" />
                                    Edit
                                </Button>
                            ) : null
                        }
                    />
                    <CardContent className="px-5 pt-3 pb-5">
                        {aboutTiles.length ? (
                            <div className="grid gap-3 sm:grid-cols-2">
                                {aboutTiles.map((tile) => {
                                    const Icon = tile.icon;
                                    return (
                                        <div
                                            key={tile.key}
                                            className="rounded-xl border border-border bg-muted/30 p-3.5"
                                        >
                                            <div className="mb-1.5 flex items-center gap-2">
                                                <span
                                                    className={`flex h-6 w-6 items-center justify-center rounded-md ${TONE_TILE[tile.tone] ?? TONE_TILE.neutral}`}
                                                >
                                                    <Icon className="h-[13px] w-[13px]" />
                                                </span>
                                                <span className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                                    {tile.title}
                                                </span>
                                            </div>
                                            <p className="text-sm leading-relaxed text-foreground/90">
                                                {tile.body}
                                            </p>
                                        </div>
                                    );
                                })}
                            </div>
                        ) : (
                            <p className="rounded-xl border border-dashed py-6 text-center text-sm text-muted-foreground">
                                No person-centred summary yet — add “About me”
                                details from the care plan or profile.
                            </p>
                        )}
                    </CardContent>
                </Card>

                {/* Recent daily notes */}
                <Card className="gap-0 py-0">
                    <CardHead
                        icon={ClipboardList}
                        title="Recent daily notes"
                        sub="Latest from the floor"
                        action={
                            <GhostLink onClick={() => onTab('progress_notes')}>
                                View all
                            </GhostLink>
                        }
                    />
                    <CardContent className="space-y-2.5 px-5 pt-3 pb-5">
                        {notes.length ? (
                            notes
                                .slice(0, 2)
                                .map((note) => (
                                    <DailyNoteEntry
                                        key={note.id}
                                        note={note}
                                        compact
                                    />
                                ))
                        ) : (
                            <p className="rounded-xl border border-dashed py-6 text-center text-sm text-muted-foreground">
                                No daily notes yet — add the first from the
                                hero or the Daily Notes tab.
                            </p>
                        )}
                    </CardContent>
                </Card>

                {/* Goals path */}
                <Card className="gap-0 py-0">
                    <CardHead
                        icon={Flag}
                        title="Goals path"
                        sub={
                            goals.length
                                ? `${goalsDone} of ${goals.length} achieved`
                                : 'No goals yet'
                        }
                        action={
                            <GhostLink onClick={() => onTab('goals_path')}>
                                Open
                            </GhostLink>
                        }
                    />
                    <CardContent className="space-y-3 px-5 pt-3 pb-5">
                        {goals.length ? (
                            goals.slice(0, 4).map((goal) => {
                                const pct = goal.progress_percentage ?? 0;
                                const done = goal.status === 'completed';
                                return (
                                    <div
                                        key={goal.id}
                                        className="flex items-center gap-3"
                                    >
                                        <span
                                            className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full ${
                                                done
                                                    ? 'bg-status-success-bg text-status-success'
                                                    : 'bg-muted text-muted-foreground'
                                            }`}
                                        >
                                            {done ? (
                                                <Check className="h-3.5 w-3.5" />
                                            ) : (
                                                <Circle className="h-3.5 w-3.5" />
                                            )}
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center justify-between gap-2">
                                                <span className="truncate text-sm font-medium">
                                                    {goal.title ?? 'Goal'}
                                                </span>
                                                <span className="shrink-0 text-xs text-muted-foreground">
                                                    {pct}%
                                                </span>
                                            </div>
                                            <div className="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className="h-full rounded-full transition-all duration-700"
                                                    style={{
                                                        width: `${Math.min(100, Math.max(0, pct))}%`,
                                                        background: done
                                                            ? 'var(--status-success)'
                                                            : 'var(--primary)',
                                                    }}
                                                />
                                            </div>
                                        </div>
                                    </div>
                                );
                            })
                        ) : (
                            <p className="rounded-xl border border-dashed py-6 text-center text-sm text-muted-foreground">
                                No goals on the path yet.
                            </p>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* ── RIGHT column ── */}
            <div className="col-span-12 space-y-4 lg:col-span-4">
                {/* Care snapshot */}
                <Card className="gap-0 overflow-hidden py-0">
                    <CardHead icon={Target} title="Care snapshot" />
                    <CardContent className="flex items-center gap-4 px-5 pt-3 pb-5">
                        <Ring pct={goalsPct} size={84} />
                        <div className="space-y-1.5 text-sm">
                            <div className="flex items-center gap-2">
                                <CheckCircle2
                                    className={`h-3.5 w-3.5 ${activePlan ? 'text-status-success' : 'text-muted-foreground'}`}
                                />
                                {activePlan
                                    ? 'Plan active'
                                    : 'No active plan'}
                            </div>
                            <div className="flex items-center gap-2">
                                <Flag className="h-3.5 w-3.5 text-primary" />
                                {goalsDone}/{goals.length} goals achieved
                            </div>
                            <div className="flex items-center gap-2">
                                <CalendarClock className="h-3.5 w-3.5 text-muted-foreground" />
                                {reviewDays == null
                                    ? 'No review scheduled'
                                    : reviewDays < 0
                                      ? `Review ${Math.abs(reviewDays)}d overdue`
                                      : `Review in ${reviewDays}d`}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Risk register */}
                <Card className="gap-0 py-0">
                    <CardHead
                        icon={ShieldAlert}
                        title="Risk register"
                        sub={`${activeRisks.length} active risk${activeRisks.length === 1 ? '' : 's'}`}
                        action={
                            <GhostLink onClick={() => onTab('risk_management')}>
                                Open
                            </GhostLink>
                        }
                    />
                    <CardContent className="px-5 pt-3 pb-4">
                        {activeRisks.length ? (
                            <div className="flex items-center gap-4">
                                <DonutChart
                                    segments={riskSegments}
                                    size={88}
                                    strokeWidth={13}
                                    centerLabel="risks"
                                    centerValue={activeRisks.length}
                                />
                                <div className="flex-1 space-y-1.5">
                                    {riskSegments.map((segment) => (
                                        <div
                                            key={segment.label}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <span
                                                className="h-2.5 w-2.5 rounded-full"
                                                style={{
                                                    background: segment.color,
                                                }}
                                            />
                                            <span className="text-muted-foreground">
                                                {segment.label}
                                            </span>
                                            <span className="ml-auto font-semibold">
                                                {segment.value}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ) : (
                            <p className="rounded-xl border border-dashed py-4 text-center text-sm text-muted-foreground">
                                No active risks recorded.
                            </p>
                        )}
                        {overdueRisk ? (
                            <div className="mt-3 flex items-center gap-2 rounded-lg bg-status-critical-bg px-3 py-2 text-xs text-status-critical">
                                <AlertOctagon className="h-3.5 w-3.5 shrink-0" />
                                <span className="truncate font-medium">
                                    {overdueRisk.label ?? 'Risk'} review
                                    overdue
                                </span>
                            </div>
                        ) : null}
                        {riskLevelControl ? (
                            <div className="mt-3 border-t pt-3">
                                <p className="mb-1 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                                    Overall risk level
                                </p>
                                {riskLevelControl}
                            </div>
                        ) : null}
                    </CardContent>
                </Card>

                {/* Medication */}
                <Card className="gap-0 py-0">
                    <CardHead
                        icon={Pill}
                        title="Medication"
                        sub="eMAR summary"
                        action={
                            <GhostLink onClick={() => onTab('mar')}>
                                MAR
                            </GhostLink>
                        }
                    />
                    <CardContent className="px-5 pt-3 pb-5">
                        {(emarSummary?.active_medications_count ?? 0) > 0 ? (
                            <div
                                className={`flex items-center gap-3 rounded-xl px-3.5 py-3 ${
                                    (emarSummary?.pending_alerts_count ?? 0) > 0
                                        ? 'bg-status-warning-bg'
                                        : 'bg-muted/40'
                                }`}
                            >
                                <span
                                    className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${
                                        (emarSummary?.pending_alerts_count ??
                                            0) > 0
                                            ? 'bg-status-warning/15 text-status-warning'
                                            : 'bg-accent text-primary'
                                    }`}
                                >
                                    <Pill className="h-[18px] w-[18px]" />
                                </span>
                                <div className="min-w-0 leading-tight">
                                    <div className="text-sm font-semibold">
                                        {emarSummary?.active_medications_count}{' '}
                                        active medication
                                        {(emarSummary?.active_medications_count ??
                                            0) === 1
                                            ? ''
                                            : 's'}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {(emarSummary?.pending_alerts_count ??
                                            0) > 0
                                            ? `${emarSummary?.pending_alerts_count} open alert${(emarSummary?.pending_alerts_count ?? 0) === 1 ? '' : 's'}`
                                            : 'No open medication alerts'}
                                    </div>
                                </div>
                                <Button
                                    type="button"
                                    size="sm"
                                    className="ml-auto shrink-0"
                                    onClick={onRecordDose}
                                >
                                    Sign
                                </Button>
                            </div>
                        ) : (
                            <p className="rounded-xl border border-dashed py-4 text-center text-sm text-muted-foreground">
                                No active medications.
                            </p>
                        )}
                    </CardContent>
                </Card>

                {/* Upcoming */}
                <Card className="gap-0 py-0">
                    <CardHead
                        icon={Calendar}
                        title="Upcoming"
                        sub="Appointments & schedule"
                        action={
                            <GhostLink onClick={() => onTab('calendar')}>
                                All
                            </GhostLink>
                        }
                    />
                    <CardContent className="space-y-2 px-5 pt-3 pb-5">
                        {upcoming.length ? (
                            upcoming.map((event) => {
                                const type =
                                    event.extendedProps?.type ?? 'event';
                                const isShift = type === 'shift';
                                return (
                                    <div
                                        key={event.id}
                                        className="flex items-center gap-3 rounded-lg border border-border px-3 py-2"
                                    >
                                        <span
                                            className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${
                                                isShift
                                                    ? TONE_TILE.success
                                                    : TONE_TILE.info
                                            }`}
                                        >
                                            {isShift ? (
                                                <Users className="h-[15px] w-[15px]" />
                                            ) : (
                                                <Stethoscope className="h-[15px] w-[15px]" />
                                            )}
                                        </span>
                                        <div className="min-w-0 flex-1 leading-tight">
                                            <div className="truncate text-sm font-medium">
                                                {event.title ?? 'Event'}
                                            </div>
                                            <div className="text-[11px] text-muted-foreground capitalize">
                                                {String(type).replace(
                                                    /_/g,
                                                    ' ',
                                                )}
                                            </div>
                                        </div>
                                        <span className="shrink-0 text-xs font-medium text-muted-foreground">
                                            {event.start
                                                ? new Date(
                                                      event.start,
                                                  ).toLocaleDateString(
                                                      'en-NZ',
                                                      {
                                                          weekday: 'short',
                                                          day: 'numeric',
                                                          month: 'short',
                                                      },
                                                  )
                                                : ''}
                                        </span>
                                    </div>
                                );
                            })
                        ) : (
                            <p className="rounded-xl border border-dashed py-4 text-center text-sm text-muted-foreground">
                                Nothing scheduled.
                            </p>
                        )}
                    </CardContent>
                </Card>

                {/* Support team */}
                <Card className="gap-0 py-0">
                    <CardHead
                        icon={Users}
                        title="Support team"
                        action={
                            canEdit ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Manage workers"
                                    onClick={onManageWorkers}
                                    className="h-8 w-8 text-muted-foreground hover:text-foreground"
                                >
                                    <UserPlus className="h-4 w-4" />
                                </Button>
                            ) : null
                        }
                    />
                    <CardContent className="space-y-1 px-5 pt-3 pb-5">
                        {team.length ? (
                            team.slice(0, 6).map((member) => {
                                const isKey = member.id === keyWorkerId;
                                return (
                                    <div
                                        key={member.id}
                                        className="flex items-center gap-2.5 py-1"
                                    >
                                        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent text-xs font-semibold text-primary">
                                            {(member.name ?? '?')
                                                .split(/\s+/)
                                                .slice(0, 2)
                                                .map((w) => w[0])
                                                .join('')
                                                .toUpperCase()}
                                        </span>
                                        <div className="min-w-0 leading-tight">
                                            <div className="truncate text-sm font-medium">
                                                {member.name}
                                            </div>
                                            <div className="text-[11px] text-muted-foreground">
                                                {isKey
                                                    ? 'Key Worker'
                                                    : 'Support Worker'}
                                            </div>
                                        </div>
                                        {isKey ? (
                                            <span className="ml-auto text-[10px] font-semibold text-primary uppercase">
                                                Key
                                            </span>
                                        ) : null}
                                    </div>
                                );
                            })
                        ) : keyWorkerName ? (
                            <div className="flex items-center gap-2.5 py-1">
                                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent text-xs font-semibold text-primary">
                                    {keyWorkerName
                                        .split(/\s+/)
                                        .slice(0, 2)
                                        .map((w) => w[0])
                                        .join('')
                                        .toUpperCase()}
                                </span>
                                <div className="leading-tight">
                                    <div className="text-sm font-medium">
                                        {keyWorkerName}
                                    </div>
                                    <div className="text-[11px] text-muted-foreground">
                                        Key Worker
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <p className="rounded-xl border border-dashed py-4 text-center text-sm text-muted-foreground">
                                No workers assigned yet.
                            </p>
                        )}
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}

/** Build the design's four About tiles from plan content + profile fields. */
export function buildAboutTiles(
    aboutMe: Record<string, unknown>,
    client: {
        interests_hobbies?: string | null;
        strengths_abilities?: string | null;
        cognitive_needs?: string | null;
    },
): { key: string; icon: IconType; title: string; body: string; tone: string }[] {
    const str = (v: unknown) => String(v ?? '').trim();
    const tiles = [
        {
            key: 'matters',
            icon: Sparkles,
            title: 'What matters to me',
            body:
                str(aboutMe.important_to_me) ||
                str(aboutMe.dreams) ||
                str(client.interests_hobbies),
            tone: 'info',
        },
        {
            key: 'support',
            icon: HandHeart,
            title: 'How to support me',
            body:
                str(aboutMe.how_to_support) ||
                str(aboutMe.important_for_me) ||
                str(client.strengths_abilities),
            tone: 'success',
        },
        {
            key: 'communicate',
            icon: MessageSquare,
            title: 'How I communicate',
            body: str(aboutMe.communication) || str(client.cognitive_needs),
            tone: 'neutral',
        },
        {
            key: 'avoid',
            icon: CircleAlert,
            title: 'What to avoid',
            body: str(aboutMe.dislikes) || str(aboutMe.avoid),
            tone: 'warning',
        },
    ];
    return tiles.filter((t) => t.body);
}
