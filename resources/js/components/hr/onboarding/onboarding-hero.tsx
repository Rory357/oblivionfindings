/* eslint-disable no-restricted-syntax -- The onboarding hero mirrors the golden
 * HR hero band from the redesign prototype: a gradient banner with stat
 * buttons, action buttons, "needs you" chips and a donut/ring progress rail.
 * Every colour is a semantic design token. */
import { Download, Mail, Plus, UserPlus } from 'lucide-react';
import { useState, type ReactNode } from 'react';

export interface OnboardingSummary {
    pending: number;
    in_progress: number;
    active: number;
    completed: number;
    completed_30d: number;
    overdue: number;
    due_this_week: number;
    avg_completion: number;
    total: number;
}

interface HeroNeed {
    label: string;
    onClick: () => void;
}

const STAT_CARD =
    'flex flex-col items-start gap-0.5 rounded-[10px] border-none bg-transparent px-3.5 py-2 text-left transition-colors hover:bg-white/10';

function HeroStat({
    label,
    value,
    amber,
    onClick,
}: {
    label: string;
    value: ReactNode;
    amber?: boolean;
    onClick?: () => void;
}) {
    return (
        <button type="button" onClick={onClick} className={STAT_CARD}>
            <span className="text-[10px] font-bold tracking-[0.09em] whitespace-nowrap text-white/60 uppercase">
                {label}
            </span>
            <span
                className="text-[22px] font-bold tabular-nums"
                style={{ color: amber ? 'var(--hr-amber)' : 'inherit' }}
            >
                {value}
            </span>
        </button>
    );
}

/** SVG status donut (or completion ring) for the hero rail. */
function HeroRail({ summary }: { summary: OnboardingSummary }) {
    const [mode, setMode] = useState<'status' | 'completion'>('status');
    const r = 54;
    const c = 2 * Math.PI * r;

    const statusSegs = [
        {
            label: 'Completed',
            value: summary.completed,
            color: 'var(--status-success)',
        },
        {
            label: 'In progress',
            value: summary.in_progress,
            color: 'var(--hr-amber)',
        },
        {
            label: 'Pending',
            value: summary.pending,
            color: 'rgba(255,255,255,.55)',
        },
        {
            label: 'Overdue',
            value: summary.overdue,
            color: 'var(--status-critical)',
        },
    ].filter((s) => s.value > 0);

    let arcs: { color: string; dash: string; offset: number }[] = [];
    let legend: { label: string; value: ReactNode; color: string }[];
    let center: ReactNode;
    let centerLabel: string;

    if (mode === 'status') {
        const total = statusSegs.reduce((a, s) => a + s.value, 0) || 1;
        let accum = 0;
        arcs = statusSegs.map((s) => {
            const len = (s.value / total) * c;
            const seg = {
                color: s.color,
                dash: `${len.toFixed(1)} ${(c - len).toFixed(1)}`,
                offset: -accum,
            };
            accum += len;
            return seg;
        });
        legend = statusSegs;
        center = summary.total;
        centerLabel = 'checklists';
    } else {
        const pct = summary.avg_completion;
        arcs = [
            {
                color: 'var(--hr-amber)',
                dash: `${((pct / 100) * c).toFixed(1)} ${c.toFixed(1)}`,
                offset: 0,
            },
        ];
        legend = [
            {
                label: 'Avg completion',
                value: `${pct}%`,
                color: 'var(--hr-amber)',
            },
            {
                label: 'Completed (30d)',
                value: summary.completed_30d,
                color: 'rgba(255,255,255,.5)',
            },
        ];
        center = `${pct}%`;
        centerLabel = 'complete';
    }

    const toggleBtn = (active: boolean) =>
        `h-6 rounded-md px-2.5 text-[11px] font-bold ${active ? 'bg-primary-foreground text-primary' : 'text-white/80'}`;

    return (
        <div className="flex w-[340px] flex-none flex-col justify-center border-l border-white/15 bg-black/10 px-6 py-5">
            <div className="mb-1.5 flex items-center justify-between">
                <span className="text-[10px] font-bold tracking-[0.1em] text-white/55 uppercase">
                    Progress
                </span>
                <div className="inline-flex gap-0.5 rounded-lg bg-white/10 p-0.5">
                    <button
                        type="button"
                        className={toggleBtn(mode === 'status')}
                        onClick={() => setMode('status')}
                    >
                        Status
                    </button>
                    <button
                        type="button"
                        className={toggleBtn(mode === 'completion')}
                        onClick={() => setMode('completion')}
                    >
                        Completion
                    </button>
                </div>
            </div>
            <div className="mt-2 flex items-center gap-4">
                <div className="relative flex-none">
                    <svg
                        width="116"
                        height="116"
                        viewBox="0 0 140 140"
                        style={{ transform: 'rotate(-90deg)' }}
                    >
                        <circle
                            cx="70"
                            cy="70"
                            r={r}
                            fill="none"
                            stroke="rgba(255,255,255,.16)"
                            strokeWidth="18"
                        />
                        {arcs.map((a, i) => (
                            <circle
                                key={i}
                                cx="70"
                                cy="70"
                                r={r}
                                fill="none"
                                stroke={a.color}
                                strokeWidth="18"
                                strokeDasharray={a.dash}
                                strokeDashoffset={a.offset}
                            />
                        ))}
                    </svg>
                    <div className="absolute inset-0 flex flex-col items-center justify-center">
                        <span className="text-[26px] leading-none font-extrabold tabular-nums">
                            {center}
                        </span>
                        <span className="text-[10px] font-semibold text-white/60">
                            {centerLabel}
                        </span>
                    </div>
                </div>
                <div className="flex min-w-0 flex-col gap-1.5">
                    {legend.map((l) => (
                        <div
                            key={l.label}
                            className="flex items-center gap-2 text-[11.5px] text-white/85"
                        >
                            <span
                                className="h-2.5 w-2.5 flex-none rounded-[3px]"
                                style={{ background: l.color }}
                            />
                            <span className="flex-1 whitespace-nowrap">
                                {l.label}
                            </span>
                            <span className="font-bold tabular-nums">
                                {l.value}
                            </span>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

export function OnboardingHero({
    summary,
    canManage,
    onStart,
    onNewTemplate,
    onEmails,
    onExport,
    onStat,
    needs,
}: {
    summary: OnboardingSummary;
    canManage: boolean;
    onStart: () => void;
    onNewTemplate: () => void;
    onEmails: () => void;
    onExport: () => void;
    onStat: (
        key: 'active' | 'in_progress' | 'overdue' | 'due_this_week' | 'avg',
    ) => void;
    needs: HeroNeed[];
}) {
    const subtitle = `${summary.active} active ${summary.active === 1 ? 'checklist' : 'checklists'}${
        summary.overdue > 0 ? ` · ${summary.overdue} overdue` : ''
    }${summary.due_this_week > 0 ? ` · ${summary.due_this_week} due this week` : ''}`;

    const actionBtn =
        'inline-flex h-[34px] items-center gap-2 rounded-[9px] border border-white/25 bg-white/10 px-3.5 text-[12.5px] font-semibold text-primary-foreground transition-colors hover:bg-white/20';

    return (
        <div
            className="relative overflow-hidden rounded-3xl text-primary-foreground"
            style={{
                background:
                    'linear-gradient(120deg, color-mix(in oklch, var(--category-hr) 72%, black 22%), var(--category-hr) 58%, color-mix(in oklch, var(--category-hr) 90%, white 8%))',
                boxShadow:
                    'var(--shadow-hero, 0 24px 60px -22px rgba(60,40,10,.45))',
            }}
        >
            <div className="pointer-events-none absolute -top-20 right-[22%] h-60 w-60 rounded-full bg-white/5" />
            <div className="relative flex flex-wrap items-stretch">
                <div className="min-w-0 flex-1 basis-[440px] px-9 py-8">
                    <div className="flex items-center gap-4">
                        <span className="grid h-[54px] w-[54px] flex-none place-items-center rounded-2xl border border-white/20 bg-white/15">
                            <UserPlus className="h-6 w-6" />
                        </span>
                        <div className="min-w-0">
                            <h1 className="text-[28px] leading-[1.05] font-bold tracking-tight">
                                Onboarding
                            </h1>
                            <p className="mt-1 text-[13px] font-medium text-white/75">
                                {subtitle}
                            </p>
                        </div>
                    </div>

                    <div className="mt-4 -ml-3 flex flex-wrap gap-0.5">
                        <HeroStat
                            label="Active"
                            value={summary.active}
                            onClick={() => onStat('active')}
                        />
                        <HeroStat
                            label="In progress"
                            value={summary.in_progress}
                            onClick={() => onStat('in_progress')}
                        />
                        <HeroStat
                            label="Overdue"
                            value={summary.overdue}
                            amber={summary.overdue > 0}
                            onClick={() => onStat('overdue')}
                        />
                        <HeroStat
                            label="Due this week"
                            value={summary.due_this_week}
                            amber={summary.due_this_week > 0}
                            onClick={() => onStat('due_this_week')}
                        />
                        <HeroStat
                            label="Avg completion"
                            value={`${summary.avg_completion}%`}
                            onClick={() => onStat('avg')}
                        />
                    </div>

                    {canManage && (
                        <div className="mt-4 flex flex-wrap gap-2">
                            <button
                                type="button"
                                onClick={onStart}
                                className="inline-flex h-[34px] items-center gap-2 rounded-[9px] bg-primary-foreground px-3.5 text-[12.5px] font-bold text-primary shadow-sm transition-transform hover:scale-[1.02]"
                            >
                                <Plus className="h-[15px] w-[15px]" />
                                Start onboarding
                            </button>
                            <button
                                type="button"
                                onClick={onNewTemplate}
                                className={actionBtn}
                            >
                                New template
                            </button>
                            <button
                                type="button"
                                onClick={onEmails}
                                className={actionBtn}
                            >
                                <Mail className="h-[14px] w-[14px]" /> Email
                                templates
                            </button>
                            <button
                                type="button"
                                onClick={onExport}
                                className={actionBtn}
                            >
                                <Download className="h-[14px] w-[14px]" />{' '}
                                Export
                            </button>
                        </div>
                    )}

                    {needs.length > 0 && (
                        <div className="mt-4 flex flex-wrap items-center gap-2">
                            <span className="text-[10px] font-bold tracking-[0.1em] text-white/50 uppercase">
                                Needs you
                            </span>
                            {needs.map((n) => (
                                <button
                                    key={n.label}
                                    type="button"
                                    onClick={n.onClick}
                                    className="inline-flex items-center gap-2 rounded-[9px] border border-white/25 bg-white/[0.13] py-1.5 pr-3 pl-2.5 text-[12px] font-semibold text-primary-foreground transition-colors hover:bg-white/25"
                                >
                                    <span
                                        className="h-1.5 w-1.5 flex-none rounded-full"
                                        style={{
                                            background: 'var(--hr-amber)',
                                            boxShadow:
                                                '0 0 0 3px color-mix(in oklch, var(--hr-amber) 32%, transparent)',
                                        }}
                                    />
                                    {n.label}
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                <HeroRail summary={summary} />
            </div>
        </div>
    );
}

export default OnboardingHero;
